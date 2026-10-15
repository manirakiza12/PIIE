<?php

namespace App\Support\Curriculum;

use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** Presents and saves the existing Study Plan relationships from Course Unit setup. */
final class CourseUnitSetup
{
    public function __construct(private CurriculumFoundationService $curricula) {}

    public function formData(?Subject $subject = null): ?array
    {
        $user = auth()->user();
        if (!$user?->hasPermission('academic.curriculum.manage') || !Schema::hasTable('curriculum_stages')) {
            return null;
        }
        $schoolId = (int) $user->school_id;
        $plans = Curriculum::where('school_id', $schoolId)->where('status', 'draft')
            ->with(['programme', 'stages', 'memberships.stage', 'memberships.subject', 'memberships.prerequisites'])->orderBy('id')->get();
        return ['plans' => $plans, 'subjectId' => $subject?->id];
    }

    public function savePlacement(Request $request, Subject $subject): void
    {
        if (!$request->filled('placement_curriculum_id')) {
            return; // Leaving the optional selector empty never deletes existing placements.
        }
        abort_unless($request->user()->hasPermission('academic.curriculum.manage'), 403);
        $schoolId = (int) $request->user()->school_id;
        abort_unless((int) $subject->school_id === $schoolId, 403);
        $data = $request->validate([
            'placement_curriculum_id' => ['required', 'integer', Rule::exists('curricula', 'id')->where('school_id', $schoolId)],
            'placement_stage_id' => ['required', 'integer'],
            'placement_classification' => ['required', Rule::in(CurriculumMembership::CLASSIFICATIONS)],
            'placement_credits' => ['required', 'numeric', 'min:0', 'max:9999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'placement_period_type' => ['nullable', Rule::in(['semester', 'term'])],
            'placement_period_sequence' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'placement_sequence' => ['required', 'integer', 'min:0', 'max:65535'],
            'placement_prerequisites' => ['nullable', 'array'],
            'placement_prerequisites.*' => ['integer', 'distinct'],
        ]);
        // Approval and structural changes serialize on the same existing parent row.
        $plan = Curriculum::where('school_id', $schoolId)->lockForUpdate()->findOrFail($data['placement_curriculum_id']);
        $attributes = [
            'curriculum_stage_id' => (int) $data['placement_stage_id'],
            'classification' => $data['placement_classification'], 'credits' => $data['placement_credits'],
            'period_type' => $data['placement_period_type'] ?? null,
            'period_sequence' => $data['placement_period_sequence'] ?? null,
            'sequence' => $data['placement_sequence'],
        ];
        $membership = CurriculumMembership::where('school_id', $schoolId)->where('curriculum_id', $plan->id)
            ->where('subject_id', $subject->id)->first();
        $membership = $membership
            ? $this->curricula->updateMembership($plan, $membership, $attributes)
            : $this->curricula->addMembership($plan, (int) $subject->id, (int) $data['placement_stage_id'], $attributes);

        if ($request->boolean('placement_prerequisites_present')) {
            $wanted = array_map('intval', $data['placement_prerequisites'] ?? []);
            $existing = $membership->prerequisites()->pluck('curriculum_memberships.id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff($existing, $wanted) as $id) {
                $this->curricula->removePrerequisite($plan, (int) $membership->id, $id);
            }
            foreach (array_diff($wanted, $existing) as $id) {
                $this->curricula->addPrerequisite($plan, (int) $membership->id, $id);
            }
        }
    }
}
