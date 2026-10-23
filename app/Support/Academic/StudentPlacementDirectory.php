<?php

namespace App\Support\Academic;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only inventory: profiles are not evidence of academic placement. */
final class StudentPlacementDirectory
{
    public function query(int $schoolId): Builder
    {
        return DB::table('users as u')
            ->leftJoin('student_profiles as sp', fn ($q) => $q->on('sp.user_id', '=', 'u.id')->where('sp.school_id', $schoolId))
            ->leftJoin('programmes as profile_programme', fn ($q) => $q->on('profile_programme.id', '=', 'sp.programme_id')->where('profile_programme.school_id', $schoolId))
            ->leftJoin('intake_sessions as intake', fn ($q) => $q->on('intake.id', '=', 'sp.intake_session_id')->where('intake.school_id', $schoolId))
            ->leftJoin('student_curriculum_assignments as p', function ($q) use ($schoolId) {
                $q->on('p.student_id', '=', 'u.id')->where('p.school_id', $schoolId)->whereNull('p.ended_at')
                    ->where('p.id', '=', function ($latest) use ($schoolId) {
                        $latest->from('student_curriculum_assignments as latest')->selectRaw('MAX(latest.id)')
                            ->whereColumn('latest.student_id', 'u.id')->where('latest.school_id', $schoolId)->whereNull('latest.ended_at');
                    });
            })
            ->leftJoin('programmes as pr', fn ($q) => $q->on('pr.id', '=', 'p.programme_id')->where('pr.school_id', $schoolId))
            ->leftJoin('curricula as c', fn ($q) => $q->on('c.id', '=', 'p.curriculum_id')->where('c.school_id', $schoolId)->on('c.programme_id', '=', 'p.programme_id'))
            ->leftJoin('curriculum_stages as stage', fn ($q) => $q->on('stage.id', '=', 'p.entry_curriculum_stage_id')->where('stage.school_id', $schoolId)->on('stage.curriculum_id', '=', 'p.curriculum_id'))
            ->leftJoin('academic_years as ay', fn ($q) => $q->on('ay.id', '=', 'p.entry_academic_year_id')->where('ay.school_id', $schoolId))
            ->leftJoin('programme_cohort_memberships as pcm', fn ($q) => $q->on('pcm.id', '=', 'p.programme_cohort_membership_id')->on('pcm.student_id', '=', 'u.id')->where('pcm.school_id', $schoolId))
            ->leftJoin('programme_cohorts as pc', fn ($q) => $q->on('pc.id', '=', 'pcm.programme_cohort_id')->where('pc.school_id', $schoolId))
            ->leftJoin('enrollment as e', function ($q) use ($schoolId) {
                $q->on('e.user_id', '=', 'u.id')->where('e.school_id', $schoolId)
                    ->where('e.id', '=', function ($latest) use ($schoolId) {
                        $latest->from('enrollment as latest_enrollment')->selectRaw('MAX(latest_enrollment.id)')
                            ->whereColumn('latest_enrollment.user_id', 'u.id')->where('latest_enrollment.school_id', $schoolId);
                    });
            })
            ->leftJoin('classes as cl', fn ($q) => $q->on('cl.id', '=', 'e.class_id')->where('cl.school_id', $schoolId))
            ->leftJoin('sections as sec', fn ($q) => $q->on('sec.id', '=', 'e.section_id')->on('sec.class_id', '=', 'cl.id'))
            ->leftJoin('sessions as legacy_session', fn ($q) => $q->on('legacy_session.id', '=', 'e.session_id')->where('legacy_session.school_id', $schoolId))
            ->where('u.school_id', $schoolId)->where('u.role_id', 7)
            ->select('u.id', 'u.name', 'u.email', 'u.code', 'u.account_status', 'sp.id as profile_id',
                'sp.year_of_study as profile_year', 'pc.name as group_name', 'pcm.status as group_status',
                'cl.name as class_name', 'sec.name as section_name', 'legacy_session.session_title as legacy_session',
                'profile_programme.name as profile_programme', 'intake.name as intake_name',
                'p.id as placement_id', 'pr.name as programme_name', 'c.version', 'stage.label as programme_year', 'ay.label as academic_year')
            ->selectRaw(Schema::hasColumn('student_profiles', 'was_existing_at_portal_creation')
                ? 'sp.was_existing_at_portal_creation' : '0 as was_existing_at_portal_creation')
            ->selectSub(fn ($q) => $q->from('student_curriculum_assignments as active')->selectRaw('COUNT(*)')
                ->where('active.school_id', $schoolId)->whereColumn('active.student_id', 'u.id')->whereNull('active.ended_at'), 'active_placements')
            ->selectSub(fn ($q) => $q->from('student_curriculum_assignments as history')->selectRaw('COUNT(*)')
                ->where('history.school_id', $schoolId)->whereColumn('history.student_id', 'u.id')->whereNotNull('history.ended_at'), 'historical_placements')
            ->selectSub(fn ($q) => $q->from('course_registrations as cr')->selectRaw('COUNT(*)')
                ->where('cr.school_id', $schoolId)->whereColumn('cr.student_id', 'u.id')->where('cr.status', 'confirmed'), 'confirmed_registrations');
    }

    public function reason(object $student): string
    {
        if ($student->active_placements > 1) return 'Excluded: multiple active placements need Academic Office review.';
        if ($student->placement_id) return 'Excluded: already has an active placement. Review its placement history; do not add another student.';
        if ($student->account_status === 'disable') return 'Excluded: student account is disabled.';
        if (! $student->profile_id) return 'Excluded: institution student profile is missing. Review the existing account; do not create a duplicate.';
        return 'Available in the placement dropdown. Choose an approved curriculum, Programme Year and Academic Year; registration follows separately.';
    }
}
