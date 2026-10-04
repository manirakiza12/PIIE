<?php

namespace App\Support\Staff;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Private storage for staff documents (national ID, CV, certificates,
 * contracts…). Files live under storage/app/staff-documents — outside public/,
 * never web-served — at a server-generated key:
 *
 *     {school_id}/{user_id}/{40 random hex}.{ext}
 *
 * The client's filename is kept only as sanitized metadata. Accepted: PDF,
 * JPG/JPEG, PNG, checked three ways — extension allow-list, server-side content
 * sniffing (finfo), and the file signature — plus a size limit
 * (config 'piie.staff_documents.max_kb', default 5 MB). Double extensions that
 * hide an executable/script type (cv.php.pdf) are refused.
 *
 * Pre-existing staff files in public/assets/uploads/user-docs are untouched.
 */
final class StaffDocumentStorage
{
    public const DEFAULT_MAX_KB = 5120;

    /** extension => the content types finfo may report for it */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** Types that must never hide inside a filename, even before the final extension. */
    private const DANGEROUS_SEGMENTS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht', 'inc', 'exe', 'com', 'bat', 'cmd', 'sh', 'bash',
        'ps1', 'vbs', 'js', 'mjs', 'jar', 'msi', 'dll', 'scr', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'html',
        'htm', 'xhtml', 'svg', 'xml', 'htaccess', 'shtml',
    ];

    /** Private root (config 'piie.staff_documents.root'; default storage/app/staff-documents — never under public/). */
    public static function root(): string
    {
        return rtrim((string) config('piie.staff_documents.root', storage_path('app/staff-documents')), '/\\');
    }

    public static function maxKb(): int
    {
        return (int) config('piie.staff_documents.max_kb', self::DEFAULT_MAX_KB);
    }

    /**
     * Checks a file against every rule the private store applies, WITHOUT
     * writing anything: name, extension allow-list, dangerous double
     * extensions, size, and the server-side content sniff.
     *
     * Exposed so a form with several document fields can report the problem
     * against the exact row the administrator filled in, instead of a single
     * generic message for the whole request. store() uses the same rules.
     *
     * @return string|null a staff-facing message, or null when the file is acceptable
     */
    public static function validate(UploadedFile $file): ?string
    {
        if (!$file->isValid()) {
            return 'The document could not be uploaded.';
        }

        $original = self::sanitizeName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!array_key_exists($extension, self::ALLOWED)) {
            return 'Only '.self::acceptedLabel().' documents are accepted.';
        }
        foreach (array_slice(explode('.', strtolower($original)), 1, -1) as $segment) {
            if (in_array($segment, self::DANGEROUS_SEGMENTS, true)) {
                return 'This file name is not allowed.';
            }
        }

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::maxKb() * 1024) {
            return 'Documents must be at most '.round(self::maxKb() / 1024, 1).' MB.';
        }

        $path = $file->getRealPath();
        $mime = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if (!$mime || !in_array($mime, self::ALLOWED[$extension], true) || !self::signatureMatches($path, $extension)) {
            return 'The file content does not match a '.self::acceptedLabel().' document.';
        }

        return null;
    }

    /** "PDF, JPG or PNG" - the accepted types as staff-facing wording. */
    public static function acceptedLabel(): string
    {
        return 'PDF, JPG or PNG';
    }

    /**
     * Validates and stores $file privately for ($schoolId, $userId).
     *
     * @return array{storage_key: string, original_name: string, mime_type: string, size_bytes: int}
     * @throws StaffRecordException
     */
    public static function store(UploadedFile $file, int $schoolId, int $userId): array
    {
        if ($problem = self::validate($file)) {
            throw new StaffRecordException($problem);
        }

        $original = self::sanitizeName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        // Read before the move: afterwards the source path no longer exists.
        $size = (int) $file->getSize();

        $directory = $schoolId . '/' . $userId;
        $name = bin2hex(random_bytes(20)) . '.' . $extension;
        $target = self::root() . '/' . $directory;
        if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
            throw new StaffRecordException('The document could not be stored.');
        }
        $file->move($target, $name);

        return ['storage_key' => $directory . '/' . $name, 'original_name' => $original, 'mime_type' => $mime, 'size_bytes' => $size];
    }

    /** Absolute path of a stored key, or null if the key would escape the private root. */
    public static function path(string $storageKey): ?string
    {
        if (!preg_match('#^\d+/\d+/[a-f0-9]{40}\.(pdf|jpg|jpeg|png)$#', $storageKey)) {
            return null;
        }

        return self::root() . '/' . $storageKey;
    }

    public static function delete(string $storageKey): void
    {
        $path = self::path($storageKey);
        if ($path && is_file($path)) {
            @unlink($path);
        }
    }

    /** Keeps a readable, harmless basename for display/download only. */
    public static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w\s.\-()]+/u', '_', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name), " .");

        return Str::limit($name !== '' ? $name : 'document', 200, '');
    }

    private static function signatureMatches(string $path, string $extension): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        return match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png' => $head === "\x89PNG\r\n\x1A\n",
            default => false,
        };
    }
}
