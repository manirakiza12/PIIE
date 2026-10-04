<?php

namespace App\Support\CourseOffering;

use App\Models\CourseOffering;
use App\Models\School;
use App\Models\User;
use App\Support\CourseContent\HtmlSanitizer;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The optional cover image on a Course Offering.
 *
 * CAPABILITY ONLY - NO INTERFACE
 *
 * The visual card design is explicitly deferred, so nothing here renders. What is
 * added is the DATA RELATIONSHIP and the SAFE READ PATH, because a cover image
 * that is stored unsafely would have to be migrated later - and a cover image is
 * institution material, so "safely" matters from the first commit rather than as
 * a follow-up.
 *
 * When the card interface is designed, the image will already exist and will
 * already be served through an authorising route, with no data migration and no
 * move of existing bytes.
 *
 * WHY THE FILE IS OUTSIDE THE WEB ROOT
 *
 * A path under `public/` would make the image readable by anyone who guessed it,
 * at any time, forever. That is the same failure as publishing it. The stored
 * name is generated, the client's filename never becomes the path, and the only
 * way to read the bytes is a route that re-checks that the viewer is entitled to
 * see this Offering - a confirmed registration for a student, an allocation for a
 * lecturer, or the academic office.
 *
 * REPLACING A COVER DISPOSES OF THE OLD BYTES
 *
 * Setting a new cover deletes the file the previous one used. A cover is
 * cosmetic, institution-owned material, and retaining every superseded version
 * would accumulate files nobody intends to keep - and would keep a file readable
 * that the institution has since withdrawn.
 */
class CourseCoverImage
{
    /**
     * 4 MB. Generous for a cover image, and deliberately far below the 20 MB
     * allowed for a student's submitted work: this is institution material, not
     * student evidence.
     */
    public const MAX_KB = 4 * 1024;

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly LecturerCourseOfferingAccess $lecturerAccess,
    ) {}

    /**
     * Is this Offering allowed to carry a cover image at all?
     *
     * The relationship is OPTIONAL, so the absence of one is a normal state for
     * every Offering, forever. Nothing should ever treat a missing cover as a
     * problem to be fixed.
     */
    public function hasCover(CourseOffering $offering): bool
    {
        return filled($offering->cover_image_path);
    }

    /**
     * Set or replace the cover image for an Offering.
     *
     * Authority is the LECTURER'S OWN ALLOCATION on this exact Offering, re-checked
     * here rather than trusted from the page that linked the form.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function set(User $actor, CourseOffering $offering, UploadedFile $file): CourseOffering
    {
        $this->assertMayManage($actor, $offering);

        if (! $file->isValid()) {
            throw ValidationException::withMessages(['cover_image' => 'Choose an image to upload.']);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        // An explicit allowlist, not "anything not blocked": an unexpected type is
        // refused by default rather than accepted by omission. Images only - a
        // document or an executable is not a cover.
        if ($extension === '' || ! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'cover_image' => 'A cover image must be one of: '.implode(', ', self::EXTENSIONS).'.',
            ]);
        }

        if ($file->getSize() > self::MAX_KB * 1024) {
            throw ValidationException::withMessages([
                'cover_image' => 'A cover image is limited to 4 MB. Choose a smaller one.',
            ]);
        }

        $previous = $offering->cover_image_path;

        $storedName = 'course-covers/'
            .$offering->id.'/'
            .bin2hex(random_bytes(16)).'.'.$extension;

        DB::transaction(function () use ($offering, $file, $storedName, $previous) {
            Storage::disk('local')->put($storedName, file_get_contents($file->getRealPath()));

            // Written the way CourseOfferingService writes, because
            // CourseOffering deliberately refuses a direct save() so that an
            // Offering is only ever mutated by a service. The guard's intent is
            // respected rather than switched off.
            //
            // No `updated_by`: course_offerings has no such column. The cover's own
            // timestamp is the only "when" a cosmetic asset needs.
            DB::table('course_offerings')
                ->where('school_id', (int) $offering->school_id)
                ->where('id', (int) $offering->id)
                ->update([
                    'cover_image_path' => $storedName,
                    'cover_image_name' => (string) $file->getClientOriginalName(),
                    'cover_image_mime' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
                    'cover_image_size' => (int) $file->getSize(),
                    'cover_image_updated_at' => now(),
                    'updated_at' => now(),
                ]);

            // The superseded file is removed AFTER the row has moved. Doing it the
            // other way round risks the row pointing at bytes that no longer
            // exist, which is a broken cover rather than a stale one.
            if ($previous && $previous !== $storedName) {
                $this->discard($previous);
            }
        });

        return $offering->fresh();
    }

    /**
     * Remove the cover image.
     *
     * Disposing of it is a legitimate end state: the relationship is optional, so
     * "no cover" must be as reachable as "has one".
     */
    public function clear(User $actor, CourseOffering $offering): CourseOffering
    {
        $this->assertMayManage($actor, $offering);

        $previous = $offering->cover_image_path;

        // As in set(): DB::table, the mechanism CourseOfferingService itself uses,
        // so the model's "only a service may change me" guard still stands.
        DB::table('course_offerings')
            ->where('school_id', (int) $offering->school_id)
            ->where('id', (int) $offering->id)
            ->update([
                'cover_image_path' => null,
                'cover_image_name' => null,
                'cover_image_mime' => null,
                'cover_image_size' => null,
                'cover_image_updated_at' => null,
                'updated_at' => now(),
            ]);

        $this->discard($previous);

        return $offering->fresh();
    }

    /**
     * May this actor READ the cover of this Offering?
     *
     * Three groups, and no more:
     *   - a lecturer with a current allocation on this exact Offering;
     *   - a student with a CONFIRMED registration on this exact Offering;
     *   - the academic office, on the same Course Offering capabilities every
     *     other administrative surface uses.
     *
     * Anyone else - another tenant, an unallocated lecturer, a student registered
     * elsewhere - is refused. A cover image is not public marketing material; it
     * is a viewer's own course.
     */
    public function assertMayView(User $actor, CourseOffering $offering): void
    {
        if ((int) $actor->school_id !== (int) $offering->school_id) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(404, 'Course Offering not found.');
        }

        $lecturerResolved = $this->lecturerAccess->resolveForLecturer($actor, (int) $offering->id);

        if ($lecturerResolved && $this->lecturerAccess->teachingActionsAllowed($lecturerResolved)) {
            return;
        }

        $permissions = app(\App\Support\Permissions\PermissionService::class);

        if ($permissions->allowsAny($actor, [
            'academic.course_offering.manage',
            'academic.course_offering.lifecycle',
        ])) {
            return;
        }

        // A confirmed registration, and only a confirmed one. The same authority
        // Course Content and the Assignment reader use.
        $registered = \App\Models\CourseRegistration::query()
            ->where('school_id', (int) $offering->school_id)
            ->where('course_offering_id', (int) $offering->id)
            ->where('student_id', $actor->id)
            ->where('status', \App\Models\CourseRegistration::STATUS_CONFIRMED)
            ->exists();

        if ($registered) {
            return;
        }

        throw new \Symfony\Component\HttpKernel\Exception\HttpException(404, 'Course Offering not found.');
    }

    /**
     * May this actor CHANGE the cover?
     *
     * A confirmed student may VIEW their course's cover but may not change it. The
     * two are separate questions and conflating them would let a student decorate
     * their own course unit.
     */
    private function assertMayManage(User $actor, CourseOffering $offering): void
    {
        if ((int) $actor->school_id !== (int) $offering->school_id) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(404, 'Course Offering not found.');
        }

        $lecturerResolved = $this->lecturerAccess->resolveForLecturer($actor, (int) $offering->id);

        if ($lecturerResolved && $this->lecturerAccess->teachingActionsAllowed($lecturerResolved)) {
            return;
        }

        $permissions = app(\App\Support\Permissions\PermissionService::class);

        if ($permissions->allowsAny($actor, [
            'academic.course_offering.manage',
            'academic.course_offering.lifecycle',
        ])) {
            return;
        }

        throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'You are not authorized to change this Course Offering.');
    }

    private function discard(?string $storedName): void
    {
        if ($storedName) {
            Storage::disk('local')->delete($storedName);
        }
    }
}
