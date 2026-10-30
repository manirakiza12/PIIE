<?php

namespace App\Support\Assignments;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The gate that runs BEFORE any file is moved.
 *
 * The order matters. PHP rejects the whole request when `post_max_size` is
 * exceeded, and that rejection is a raw PHP warning - not an exception, not
 * a validation error, nothing a controller can catch. So this class does not
 * try to catch the rejection; it PREVENTS it by refusing the request earlier,
 * at the point where the student's text is still available to be saved as a
 * draft.
 *
 * It is called from the controller, before `saveDraft` or `submit`, and it
 * throws a `ValidationException` - the same kind the existing per-file checks
 * throw - so the existing error-display path handles it without a new one.
 */
class UploadGate
{
    public function __construct(private UploadLimit $limit) {}

    /**
     * Refuse the request before any work is done, when the aggregate is over
     * the limit. The student's written answer survives because the controller
     * saves a draft on rejection.
     *
     * @throws ValidationException
     */
    public function assertWithinLimits(Request $request): void
    {
        if ($this->limit->requestTooLarge($request)) {
            throw ValidationException::withMessages(['files' => $this->limit->requestMessage()]);
        }

        $files = $this->uploadedFiles($request);

        if ($this->limit->tooManyFiles($files)) {
            throw ValidationException::withMessages(['files' => $this->limit->tooManyFilesMessage()]);
        }
    }

    /**
     * All uploaded files in the request, at any depth.
     *
     * A student's submission can nest files under `questions[0][items][0][file]`,
     * and `$request->file()` only walks the top level. This flattens the tree
     * so the count and the per-file checks see every file the student sent.
     *
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request): array
    {
        $files = $request->allFiles();
        $flat = [];

        foreach ($files as $file) {
            $this->collect($file, $flat);
        }

        return $flat;
    }

    private function collect(mixed $file, array &$out): void
    {
        if ($file instanceof UploadedFile) {
            $out[] = $file;

            return;
        }

        if (is_array($file)) {
            foreach ($file as $child) {
                $this->collect($child, $out);
            }
        }
    }
}