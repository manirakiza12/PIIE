<?php

namespace App\Support\Admissions;

use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentRequirement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Everything the portal and the review screen need to answer "which
 * documents does this application still need, and are the ones supplied
 * acceptable?".
 *
 * Requirements live in the DB per school. When a school has none configured
 * (a fresh install, or an upgrade where the seeder hasn't run) the built-in
 * defaults are materialised in memory instead — an unconfigured institution
 * should still be able to take applications, just with the standard list.
 */
class ApplicationDocuments
{
    public const MAX_FILE_MB = 5;
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    /**
     * Requirements that apply to this application, in display order.
     * Filtered by the programme level the applicant has actually chosen, so a
     * Certificate applicant is never blocked on a degree transcript.
     */
    public static function requirementsFor(Admission $admission): Collection
    {
        $configured = AdmissionDocumentRequirement::where('school_id', $admission->school_id)
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($configured->isEmpty()) {
            $configured = self::defaultRequirements($admission->school_id);
        }

        $level = optional($admission->programme)->level;

        return $configured->filter(fn ($requirement) => $requirement->appliesToLevel($level))->values();
    }

    /**
     * Unsaved models representing the built-in defaults. Not persisted here:
     * writing rows as a side effect of someone opening a page would make the
     * seeder and the admin "restore defaults" action fight each other.
     */
    public static function defaultRequirements(int $schoolId): Collection
    {
        return collect(AdmissionDocumentRequirement::DEFAULTS)
            ->values()
            ->map(function ($definition, $index) use ($schoolId) {
                $requirement = new AdmissionDocumentRequirement(array_merge($definition, [
                    'school_id'  => $schoolId,
                    'sort_order' => $index,
                    'is_active'  => true,
                ]));

                return $requirement;
            });
    }

    /**
     * Requirements paired with what has been uploaded against each, plus a
     * single derived state per row that the views can render directly:
     *
     *   missing  — required, nothing uploaded
     *   rejected — a reviewer sent it back; the applicant must replace it
     *   pending  — uploaded, awaiting review
     *   verified — accepted by a reviewer
     *   optional — not required and not supplied
     */
    public static function checklist(Admission $admission): array
    {
        $uploads = $admission->relationLoaded('uploadedDocuments')
            ? $admission->uploadedDocuments
            : $admission->uploadedDocuments()->latest('id')->get();

        $byRequirement = $uploads->groupBy('requirement_key');
        $rows          = [];

        foreach (self::requirementsFor($admission) as $requirement) {
            $files = $byRequirement->get($requirement->key, collect());

            $rows[] = [
                'requirement' => $requirement,
                'files'       => $files,
                'state'       => self::stateFor($requirement, $files),
            ];
        }

        // Files uploaded against a requirement that has since been removed or
        // disabled are still shown — the applicant supplied them in good
        // faith and a reviewer should be able to see them.
        $knownKeys = collect($rows)->pluck('requirement.key')->all();

        foreach ($byRequirement as $key => $files) {
            if ($key !== null && ! in_array($key, $knownKeys, true)) {
                $rows[] = [
                    'requirement' => new AdmissionDocumentRequirement([
                        'school_id'   => $admission->school_id,
                        'key'         => $key,
                        'label'       => $files->first()->label ?: ucwords(str_replace('_', ' ', (string) $key)),
                        'is_required' => false,
                        'description' => null,
                    ]),
                    'files' => $files,
                    'state' => 'optional-supplied',
                ];
            }
        }

        return $rows;
    }

    private static function stateFor(AdmissionDocumentRequirement $requirement, Collection $files): string
    {
        if ($files->isEmpty()) {
            return $requirement->is_required ? 'missing' : 'optional';
        }

        if ($files->contains(fn ($f) => $f->status === AdmissionDocument::STATUS_REJECTED)) {
            return 'rejected';
        }

        if ($files->every(fn ($f) => $f->status === AdmissionDocument::STATUS_VERIFIED)) {
            return 'verified';
        }

        return 'pending';
    }

    /**
     * Human-readable labels of everything blocking submission on the
     * documents step: required-but-absent, and anything a reviewer rejected.
     */
    public static function outstanding(Admission $admission): array
    {
        $blocking = [];

        foreach (self::checklist($admission) as $row) {
            if ($row['state'] === 'missing') {
                $blocking[] = $row['requirement']->label;
            }

            if ($row['state'] === 'rejected') {
                $blocking[] = $row['requirement']->label . ' ' . get_phrase('(rejected — please re-upload)');
            }
        }

        return $blocking;
    }

    public static function isComplete(Admission $admission): bool
    {
        return empty(self::outstanding($admission));
    }

    /**
     * Stores an upload and files it against a requirement.
     *
     * Filenames are always regenerated. The original name is kept in the DB
     * for display only — writing an applicant-supplied filename to disk is
     * how you end up serving "cv.php" out of a public directory.
     */
    public static function store(
        Admission $admission,
        UploadedFile $file,
        ?string $requirementKey,
        ?string $label = null,
        array $attribution = []
    ): AdmissionDocument {
        $destination = public_path(AdmissionDocument::UPLOAD_DIR);

        if (! is_dir($destination)) {
            @mkdir($destination, 0775, true);
        }
        \Illuminate\Support\Facades\File::ensureDirectoryExists($destination, 0775, true);

        $extension = strtolower($file->getClientOriginalExtension());
        // Security Phase 2F: callers' mimes rule checks the *content*; the stored name keeps the
        // client extension, so it must itself be one of the allowed types (a real PNG named
        // x.html / x.svg would otherwise be stored as HTML/SVG in a web-served folder).
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['files' => 'Only PDF, JPG and PNG files are accepted.']);
        }
        $storedAs  = 'app' . $admission->id . '_' . uniqid() . '.' . $extension;

        $size = $file->getSize();
        $mime = $file->getMimeType();

        $file->move($destination, $storedAs);

        return AdmissionDocument::create(array_merge([
            'school_id'       => $admission->school_id,
            'admission_id'    => $admission->id,
            'requirement_key' => $requirementKey,
            'label'           => $label,
            'original_name'   => mb_substr($file->getClientOriginalName(), 0, 255),
            'stored_name'     => $storedAs,
            'mime_type'       => $mime,
            'size_bytes'      => $size ?: 0,
            'status'          => AdmissionDocument::STATUS_PENDING,
        ], $attribution));
    }

    /**
     * Removes the row and its file. The file is deleted after the row so a
     * failed unlink (a locked file on Windows, say) leaves no record pointing
     * at a file the applicant can no longer replace.
     */
    public static function delete(AdmissionDocument $document): void
    {
        $path = $document->absolute_path;

        $document->delete();

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
