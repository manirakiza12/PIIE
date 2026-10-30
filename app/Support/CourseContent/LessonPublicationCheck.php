<?php

namespace App\Support\CourseContent;

use App\Models\Assignment;
use App\Models\CourseOfferingLesson;
use Illuminate\Validation\ValidationException;

final class LessonPublicationCheck
{
    public function hasContent(CourseOfferingLesson $lesson, ?string $body): bool
    {
        if (app(HtmlSanitizer::class)->hasMeaningfulText($body)) { return true; }
        foreach ($lesson->resources()->where('school_id', $lesson->school_id)
            ->where('course_offering_id', $lesson->course_offering_id)->get() as $resource) {
            if ($resource->isFile() && $resource->stored_name && \Illuminate\Support\Facades\Storage::disk('local')->exists($resource->stored_name)) { return true; }
            if (!$resource->isFile() && filter_var($resource->link_url, FILTER_VALIDATE_URL)
                && in_array(strtolower(parse_url($resource->link_url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) { return true; }
        }
        return false;
    }

    public function assignmentProblem(CourseOfferingLesson $lesson, bool $forPublication = false): ?string
    {
        if (!$lesson->assignment_id) {
            return 'Link a valid assignment to this specific lesson before publishing. Create one from the lesson form, or keep the lesson as a draft while it is incomplete.';
        }
        $assignment = Assignment::whereKey($lesson->assignment_id)
            ->where('school_id', $lesson->school_id)
            ->where('course_offering_id', $lesson->course_offering_id)
            ->when($forPublication, fn ($query) => $query->lockForUpdate())->first();
        if (!$assignment) {
            return 'Choose an assignment from this lesson’s Course Offering and institution.';
        }
        if ($assignment->course_offering_module_id && (int) $assignment->course_offering_module_id !== (int) $lesson->course_offering_module_id) {
            return 'The linked assignment belongs to another module. Choose an assignment for this lesson module.';
        }
        if (!in_array($assignment->status, [Assignment::STATUS_PUBLISHED, Assignment::STATUS_SCHEDULED], true)
            || !$assignment->due_date || $assignment->max_marks <= 0
            || !app(HtmlSanitizer::class)->hasMeaningfulText($assignment->instructions)
            || $assignment->questionReadinessProblems() !== []) {
            return 'Finish and publish the linked assignment first: it needs instructions, a due date, positive marks and valid questions when question-based.';
        }
        return null;
    }

    public function assertPublishable(CourseOfferingLesson $lesson): void
    {
        if (!$this->hasContent($lesson, $lesson->body)) {
            throw ValidationException::withMessages(['body' => 'Add written content or an accessible file or link before publishing this lesson.']);
        }
        if ($problem = $this->assignmentProblem($lesson, true)) {
            throw ValidationException::withMessages(['assignment_id' => $problem]);
        }
    }
}
