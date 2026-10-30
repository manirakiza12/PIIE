<?php

namespace App\Support\CourseContent;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LearningMaterialUpload
{
    public function validate(?UploadedFile $file): void
    {
        if (! $file || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Choose a valid file to attach.']);
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), config('course_content.allowed_extensions'), true)) {
            throw ValidationException::withMessages(['file' => 'This file type is not supported for learning materials.']);
        }
        if (in_array(strtolower((string) $file->getMimeType()), \App\Support\SafeUpload::BLOCKED_MIME, true)
            || in_array(strtolower((string) $file->guessExtension()), \App\Support\SafeUpload::BLOCKED, true)) {
            throw ValidationException::withMessages(['file' => 'The file contains an unsupported executable or active document type.']);
        }
        if ($file->getSize() > config('course_content.max_upload_kb', 20480) * 1024) {
            throw ValidationException::withMessages(['file' => 'The file exceeds the configured learning-material upload limit.']);
        }
    }

    public function store(UploadedFile $file, string $path): void
    {
        $this->validate($file);
        try {
            $written = Storage::disk('local')->put($path, file_get_contents($file->getRealPath()), ['visibility' => 'private']);
        } catch (\Throwable $exception) {
            report($exception);
            $written = false;
        }
        if (! $written) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Please try again.']);
        }
    }
}
