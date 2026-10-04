<?php

namespace App\Support\CourseRegistration;

use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\StudentCurriculumAssignment;

/** Outcome of CourseOfferingEligibility; messages are written for administrators. */
final class CourseOfferingEligibilityResult
{
    private function __construct(
        public readonly bool $eligible,
        public readonly string $code,
        public readonly string $message,
        public readonly ?StudentCurriculumAssignment $assignment = null,
        public readonly ?Curriculum $curriculum = null,
        public readonly ?CurriculumMembership $membership = null,
    ) {
    }

    public static function eligible(StudentCurriculumAssignment $assignment, Curriculum $curriculum, ?CurriculumMembership $membership = null): self
    {
        return new self(true, 'eligible', 'Eligible.', $assignment, $curriculum, $membership);
    }

    public static function denied(string $code, string $message, ?StudentCurriculumAssignment $assignment = null): self
    {
        return new self(false, $code, $message, $assignment);
    }

    /** True when the failure concerns the student's placement rather than one Offering. */
    public function isPlacementFailure(): bool
    {
        return in_array($this->code, CourseOfferingEligibility::PLACEMENT_CODES, true);
    }
}
