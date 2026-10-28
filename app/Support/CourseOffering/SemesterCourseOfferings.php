<?php

namespace App\Support\CourseOffering;

use App\Models\CourseOffering;
use DomainException;
use Illuminate\Support\Facades\{DB, Schema};

/** Required curriculum deliveries for an opened period; lecturer allocation is independent. */
final class SemesterCourseOfferings
{
    public function __construct(private CourseOfferingService $offerings) {}

    public function reconcile(int $schoolId, int $periodId, bool $apply = false, bool $requireCurrentContext = false): array
    {
        foreach (['schools', 'academic_years', 'academic_periods', 'programmes', 'curricula', 'curriculum_stages',
            'curriculum_memberships', 'subjects', 'course_offerings', 'course_offering_curriculum_memberships'] as $table) {
            if (! Schema::hasTable($table)) { return []; }
        }
        return DB::transaction(function () use ($schoolId, $periodId, $apply, $requireCurrentContext): array {
            $school = DB::table('schools')->where('id', $schoolId)->lockForUpdate()->first();
            $period = DB::table('academic_periods')->where('school_id', $schoolId)->where('id', $periodId)->lockForUpdate()->first();
            $year = $period ? DB::table('academic_years')->where('school_id', $schoolId)
                ->where('id', $period->academic_year_id)->lockForUpdate()->first() : null;
            $today = now(($school->timezone ?? null) ?: config('app.timezone'))->toDateString();
            if (! $school || ! $period || ! $year || $year->status !== 'active' || $period->status !== 'active'
                || (! empty($period->end_date) && substr($period->end_date, 0, 10) < $today)
                || (! empty($year->end_date) && substr($year->end_date, 0, 10) < $today)) {
                return [['entity' => 'offering', 'result' => 'blocked', 'reason' => 'period_not_open']];
            }
            if ($requireCurrentContext && ((int) $school->current_academic_year_id !== (int) $year->id
                || (int) $school->current_academic_period_id !== $periodId)) {
                return [['entity' => 'offering', 'result' => 'blocked', 'reason' => 'current_period_mismatch']];
            }
            $memberships = DB::table('curriculum_memberships as m')
                ->join('curricula as c', fn ($j) => $j->on('c.id', '=', 'm.curriculum_id')->on('c.school_id', '=', 'm.school_id'))
                ->join('programmes as p', fn ($j) => $j->on('p.id', '=', 'c.programme_id')->on('p.school_id', '=', 'c.school_id'))
                ->join('curriculum_stages as st', fn ($j) => $j->on('st.id', '=', 'm.curriculum_stage_id')->on('st.curriculum_id', '=', 'm.curriculum_id')->on('st.school_id', '=', 'm.school_id'))
                ->join('subjects as s', fn ($j) => $j->on('s.id', '=', 'm.subject_id')->on('s.school_id', '=', 'm.school_id'))
                ->leftJoin('academic_years as effective', fn ($j) => $j->on('effective.id', '=', 'c.effective_academic_year_id')->on('effective.school_id', '=', 'c.school_id'))
                ->where('m.school_id', $schoolId)->where('c.status', 'approved')->where('m.classification', 'compulsory')
                ->where('m.period_type', $period->type)->where('m.period_sequence', $period->sequence)
                ->where(fn ($q) => $q->whereNull('c.effective_academic_year_id')->orWhere('effective.start_date', '<=', $year->start_date))
                ->when(Schema::hasColumn('curriculum_stages', 'archived_at'), fn ($q) => $q->whereNull('st.archived_at'))
                ->when(Schema::hasColumn('curriculum_memberships', 'archived_at'), fn ($q) => $q->whereNull('m.archived_at'))
                ->orderBy('m.subject_id')->orderBy('m.id')
                ->get(['m.id', 'm.subject_id', 'm.curriculum_id', 'p.code as programme_code', 'st.label as stage_label', 's.code as unit_code']);
            $outcomes = [];
            foreach ($memberships->groupBy('subject_id') as $subjectId => $entries) {
                try {
                    $outcomes[] = DB::transaction(function () use ($schoolId, $period, $year, $subjectId, $entries, $apply, $today): array {
                        $row = ['entity' => 'offering', 'subject_id' => (int) $subjectId,
                            'curriculum_membership_ids' => $entries->pluck('id')->all(),
                            'course_unit_code' => $entries->first()->unit_code,
                            'academic_year' => $year->label, 'academic_period' => $period->label,
                            'programme_years' => $entries->pluck('stage_label')->unique()->values()->all(),
                            'programme_codes' => $entries->pluck('programme_code')->unique()->values()->all()];
                        $existing = CourseOffering::where('school_id', $schoolId)->where('subject_id', $subjectId)
                            ->where('academic_year_id', $year->id)->where('academic_period_id', $period->id)
                            ->orderBy('id')->lockForUpdate()->get();
                        $operational = $existing->whereIn('status', ['open', 'in_progress']);
                        $candidates = $operational->isNotEmpty() ? $operational : $existing->where('status', 'draft');
                        if ($candidates->count() > 1) {
                            // Existing parallel deliveries remain authoritative; never guess a section.
                            $linked = DB::table('course_offering_curriculum_memberships')->where('school_id', $schoolId)
                                ->whereIn('course_offering_id', $existing->whereIn('status', ['open', 'in_progress'])->pluck('id'))
                                ->whereIn('curriculum_membership_id', $entries->pluck('id'))->distinct()->pluck('curriculum_membership_id');
                            return $row + ['result' => $linked->count() === $entries->count() ? 'preserved' : 'blocked', 'reason' => 'parallel_allocations'];
                        }
                        $offering = $candidates->first() ?? $existing->first();
                        if ($offering && ! in_array($offering->status, ['draft', 'open', 'in_progress'], true)) {
                            return $row + ['course_offering_id' => $offering->id, 'result' => 'preserved', 'reason' => 'allocation_' . $offering->status];
                        }
                        $linked = $offering ? DB::table('course_offering_curriculum_memberships')->where('school_id', $schoolId)
                            ->where('course_offering_id', $offering->id)->whereIn('curriculum_membership_id', $entries->pluck('id'))->count() : 0;
                        $result = ! $offering ? 'create' : ($offering->status === 'draft' ? 'open' : ($linked < $entries->count() ? 'link' : 'reuse'));
                        if ($apply) {
                            $offering ??= $this->offerings->createDraft($schoolId, (int) $subjectId, (int) $year->id, (int) $period->id);
                            foreach ($entries as $entry) {
                                $this->offerings->ensureSemesterApplicability($schoolId, (int) $offering->id, (int) $entry->id);
                            }
                            if ($offering->status === 'draft') {
                                $offering = $this->offerings->open($schoolId, (int) $offering->id);
                            }
                        }
                        $hasLecturer = $offering && Schema::hasTable('course_offering_lecturer_allocations')
                            && DB::table('course_offering_lecturer_allocations')->where('school_id', $schoolId)
                                ->where('course_offering_id', $offering->id)->where('status', 'active')
                                ->whereDate('starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))->exists();
                        return $row + ['course_offering_id' => $offering?->id,
                            'result' => $result === 'reuse' ? 'preserved' : ($apply ? ['create' => 'created', 'open' => 'opened', 'link' => 'linked'][$result] : 'would_' . $result),
                            'reason' => 'required_curriculum_unit', 'warnings' => $hasLecturer ? [] : ['lecturer_missing']];
                    });
                } catch (DomainException $exception) {
                    $outcomes[] = ['entity' => 'offering', 'subject_id' => (int) $subjectId,
                        'result' => 'blocked', 'reason' => 'academic_setup_review', 'message' => $exception->getMessage()];
                }
            }
            return $outcomes;
        }, 3);
    }
}
