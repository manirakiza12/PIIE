<?php

namespace App\Support\Progression;

use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\CurriculumStage;
use App\Models\StudentAcademicExemption;
use App\Models\StudentAcademicExemptionItem;
use App\Models\StudentCurriculumAssignment;
use App\Models\StudentProgressionDecision;
use App\Support\Exemptions\ExemptionService;
use Illuminate\Support\Facades\DB;

class ProgressionService
{
    public function __construct(private ExemptionService $exemptions) {}

    /**
     * Evaluate a student's progression eligibility for their current curriculum assignment.
     *
     * Returns an array with keys: assignment, programme, curriculum, current_stage,
     * next_stage, completed_count, outstanding_count, exempted_count, failed_count,
     * can_progress (bool), reason (string).
     */
    public function evaluate(int $schoolId, int $studentId): array
    {
        $assignment = StudentCurriculumAssignment::query()
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->latest('id')
            ->first();

        if (! $assignment) {
            return ['error' => 'No curriculum assignment found for this student.'];
        }

        $curriculum = Curriculum::query()->where('school_id', $schoolId)->find($assignment->curriculum_id);
        $stages = $curriculum ? $curriculum->stages()->orderBy('sequence')->get() : collect();
        $currentStage = $assignment->entryCurriculumStage
            ?: ($stages->firstWhere('id', $assignment->current_academic_level ? null : null));

        $exemptedIds = $this->exemptions->exemptedMembershipIds($schoolId, $studentId);

        // Completed/outstanding are derived from course registrations + exemptions.
        $completedCount = count($exemptedIds);
        $totalMemberships = $curriculum ? $curriculum->memberships()->count() : 0;
        $outstandingCount = max(0, $totalMemberships - $completedCount);

        $nextStage = null;
        if ($currentStage) {
            $nextStage = $stages->firstWhere('sequence', '>', $currentStage->sequence);
        } elseif ($stages->isNotEmpty()) {
            $nextStage = $stages->first();
        }

        $canProgress = $nextStage !== null && $outstandingCount === 0;

        return [
            'assignment' => $assignment,
            'programme_id' => $assignment->programme_id,
            'curriculum' => $curriculum,
            'stages' => $stages,
            'current_stage' => $currentStage,
            'next_stage' => $nextStage,
            'completed_count' => $completedCount,
            'outstanding_count' => $outstandingCount,
            'exempted_count' => count($exemptedIds),
            'exempted_membership_ids' => $exemptedIds,
            'can_progress' => $canProgress,
            'reason' => $canProgress
                ? 'All requirements for the current level are satisfied.'
                : ($nextStage ? 'Outstanding course units remain.' : 'No further level available.'),
        ];
    }

    public function recordDecision(int $schoolId, int $studentId, string $decision, int $deciderId, ?string $notes = null): StudentProgressionDecision
    {
        $eval = $this->evaluate($schoolId, $studentId);
        if (isset($eval['error'])) {
            throw new \DomainException($eval['error']);
        }

        return DB::transaction(function () use ($eval, $decision, $deciderId, $notes, $schoolId, $studentId): StudentProgressionDecision {
            $record = StudentProgressionDecision::createThroughDomainService([
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'programme_id' => $eval['programme_id'],
                'curriculum_id' => $eval['curriculum']->id,
                'programme_cohort_membership_id' => $eval['assignment']->programme_cohort_membership_id,
                'from_level' => $eval['current_stage']?->name,
                'to_level' => $decision === 'promote' ? $eval['next_stage']?->name : $eval['current_stage']?->name,
                'decision' => $decision,
                'basis' => [
                    'completed_count' => $eval['completed_count'],
                    'outstanding_count' => $eval['outstanding_count'],
                    'exempted_count' => $eval['exempted_count'],
                ],
                'notes' => $notes,
                'decided_by' => $deciderId,
                'decided_at' => now(),
            ]);

            // Apply promotion to the assignment's current level when approved.
            if ($decision === 'promote' && $eval['next_stage']) {
                $eval['assignment']->updateThroughDomainService([
                    'current_academic_level' => $eval['next_stage']->name,
                    'entry_curriculum_stage_id' => $eval['next_stage']->id,
                ]);
            } elseif (in_array($decision, ['retain', 'conditional'], true) && $eval['current_stage']) {
                $eval['assignment']->updateThroughDomainService([
                    'current_academic_level' => $eval['current_stage']->name,
                ]);
            }

            return $record;
        });
    }
}
