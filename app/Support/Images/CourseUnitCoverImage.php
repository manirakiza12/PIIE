<?php

namespace App\Support\Images;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Private catalogue artwork; reading it still requires course access. */
final class CourseUnitCoverImage
{
    public function exists(?Subject $subject): bool
    {
        return $subject && $this->validPath($subject->cover_image_path)
            && Storage::disk('local')->exists($subject->cover_image_path);
    }

    public function save(Request $request, Subject $subject): void
    {
        $file = $request->file('cover_image');
        if (! $file && ! $request->boolean('remove_cover_image')) {
            return;
        }

        $previous = $subject->cover_image_path;
        $path = null;
        if ($file) {
            try {
                $image = app(ImageOptimizer::class)->process($file);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['cover_image' => $exception->errors()['image'] ?? ['Choose a valid cover image.']]);
            }
            $path = 'course-unit-covers/'.$subject->school_id.'/'.$subject->id.'/'.bin2hex(random_bytes(16)).'.'.$image['extension'];
            if (! Storage::disk('local')->put($path, $image['bytes'])) {
                throw ValidationException::withMessages(['cover_image' => 'The cover could not be stored. Please try again.']);
            }
        }

        try {
            $subject->forceFill(['cover_image_path' => $path])->save();
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        if ($this->validPath($previous)) {
            DB::afterCommit(fn () => Storage::disk('local')->delete($previous));
        }
    }

    public function response(Subject $subject)
    {
        abort_unless($this->exists($subject), 404);

        return Storage::disk('local')->response($subject->cover_image_path, 'course-unit-cover.'.pathinfo($subject->cover_image_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function validPath(?string $path): bool
    {
        return is_string($path) && preg_match('~^course-unit-covers/[0-9]+/[0-9]+/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$~D', $path) === 1;
    }
}
