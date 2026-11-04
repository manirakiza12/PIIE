<?php

namespace App\Support\Exemptions;

use App\Models\CurriculumMembership;
use App\Models\StudentAcademicExemption;
use App\Models\StudentAcademicExemptionItem;
use App\Models\StudentCurriculumAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExemptionService
{
    public function submit(array $data): StudentAcademicExemption
    {
        return DB::transaction(function () use ($data): StudentAcademicExemption {
            $exemption = StudentAcademicExemption::createThroughDomainService([
                'school_id' => $data['school_id'],
                'student_id' => $data['student_id'],
                'programme_id' => $data['programme_id'],
                'curriculum_id' => $data['curriculum_id'],
                'exemption_type' => $data['exemption_type'],
                'target_curriculum_stage_id' => $data['target_curriculum_stage_id'] ?? null,
                'status' => 'pending',
                'previous_institution' => $data['previous_institution'] ?? null,
                'previous_qualification' => $data['previous_qualification'] ?? null,
                'previous_results' => $data['previous_results'] ?? null,
                'reason' => $data['reason'] ?? null,
                'evidence' => $data['evidence'] ?? null,
                'requested_by' => $data['requested_by'],
            ]);

            foreach ($data['items'] ?? [] as $item) {
                StudentAcademicExemptionItem::create([
                    'school_id' => $data['school_id'],
                    'exemption_id' => $exemption->id,
                    'curriculum_id' => $data['curriculum_id'],
                    'curriculum_membership_id' => $item['curriculum_membership_id'] ?? null,
                    'subject_id' => $item['subject_id'] ?? null,
                    'status' => $data['exemption_type'] === 'level' ? 'exempted' : 'exempted',
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $exemption;
        });
    }

    public function approve(StudentAcademicExemption $exemption, int $deciderId, ?string $notes = null): void
    {
        DB::transaction(function () use ($exemption, $deciderId, $notes): void {
            $exemption->updateThroughDomainService([
                'status' => 'approved',
                'decided_by' => $deciderId,
                'decided_at' => now(),
                'decision_notes' => $notes,
            ]);
            // Apply exemption to student's academic requirements.
            $this->applyApprovedExemption($exemption);
        });
    }

    public function reject(StudentAcademicExemption $exemption, int $deciderId, ?string $notes = null): void
    {
        $exemption->updateThroughDomainService([
            'status' => 'rejected',
            'decided_by' => $deciderId,
            'decided_at' => now(),
            'decision_notes' => $notes,
        ]);
    }

    public function cancel(StudentAcademicExemption $exemption): void
    {
        if ($exemption->status !== 'pending') {
            throw new \DomainException('Only pending exemptions can be cancelled.');
        }
        $exemption->updateThroughDomainService(['status' => 'cancelled']);
    }

    protected function applyApprovedExemption(StudentAcademicExemption $exemption): void
    {
        // Mark the student's assignment with the exemption record. Actual requirement
        // filtering happens at registration time via the exemption items. We record the
        // approval on the assignment's progression history indirectly; here we only
        // ensure no fabricated grades are created.
        // (Intentionally no grade/exam record creation.)
    }

    /**
     * Returns the set of curriculum membership IDs that are exempted for a student.
     */
    public function exemptedMembershipIds(int $schoolId, int $studentId): array
    {
        return StudentAcademicExemptionItem::query()
            ->join('student_academic_exemptions as e', 'e.id', '=', 'student_academic_exemption_items.exemption_id')
            ->where('student_academic_exemption_items.school_id', $schoolId)
            ->where('e.student_id', $studentId)
            ->where('e.status', 'approved')
            ->whereNotNull('student_academic_exemption_items.curriculum_membership_id')
            ->pluck('student_academic_exemption_items.curriculum_membership_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
