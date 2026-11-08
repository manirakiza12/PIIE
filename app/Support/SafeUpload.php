<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Security Phase 2F: the one place ad-hoc uploads into web-served
 * directories get their stored filename.
 *
 * Previously many forms moved an upload into public/ under the client's
 * own filename (or its client-supplied extension), so a `.php`, `.html` or
 * `.svg` file — or a real image simply *named* `x.php` — landed in a
 * web-served folder. This keeps each flow's existing directory and format
 * reach, but:
 *
 *  - the stored name is always application-generated (random, no client name);
 *  - script / web-executable / script-capable extensions are refused, by both
 *    the client extension and the file's detected content;
 *  - where a flow has a known format set, it is enforced as an allow-list.
 *
 * Returns the stored filename, or null when the file must be refused.
 */
class SafeUpload
{
    /** Image fields (logos, signatures, notice/club images). SVG deliberately excluded: it can carry script. */
    public const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Never stored, whatever the flow: server-executed, browser-executed or script-capable. */
    public const BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'cfm',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'js', 'mjs',
        'htaccess', 'htpasswd', 'exe', 'bat', 'cmd', 'com', 'dll', 'msi', 'vbs', 'ps1', 'jar', 'swf',
    ];

    /** Detected content types refused under any name (guessExtension() has no mapping for some, e.g. PHP). */
    public const BLOCKED_MIME = [
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/html', 'application/xhtml+xml',
        'image/svg+xml', 'text/javascript', 'application/javascript', 'application/x-sh', 'text/x-shellscript',
    ];

    private const EQUIVALENT = ['jpeg' => 'jpg', 'txt' => 'csv'];

    /**
     * @param array|null $allowed null = any extension that is not BLOCKED (flows whose legitimate
     *                              formats are open-ended, e.g. assignments); otherwise an allow-list.
     */
    public static function store(UploadedFile $file, string $directory, ?array $allowed = null, ?int $maxKb = null): ?string
    {
        if (!$file->isValid() || ($maxKb !== null && $file->getSize() > $maxKb * 1024)) {
            return null;
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $detected  = strtolower((string) $file->guessExtension());

        if ($extension === '' || in_array($extension, self::BLOCKED, true) || in_array($detected, self::BLOCKED, true)
            || in_array(strtolower((string) $file->getMimeType()), self::BLOCKED_MIME, true)) {
            return null;
        }

        if ($allowed !== null) {
            $normalise = fn (string $ext) => self::EQUIVALENT[$ext] ?? $ext;
            $allowedNormalised = array_map($normalise, $allowed);
            if (!in_array($extension, $allowed, true) || !in_array($normalise($detected), $allowedNormalised, true)) {
                return null;
            }
        }

        $name = bin2hex(random_bytes(20)) . '.' . $extension;
        \Illuminate\Support\Facades\File::ensureDirectoryExists($directory, 0775, true);
        $file->move($directory, $name);

        return $name;
    }
}
