<?php

namespace App\Support\Admissions;

use App\Models\Admission;
use App\Models\AdmissionStatusEvent;
use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place an application changes status.
 *
 * Every transition has to do four things together — write the status, append
 * a timeline entry the applicant can see, write an audit record for staff,
 * and notify the applicant. Splitting those across the controllers that
 * trigger them is how a status ends up changed with no timeline entry, or an
 * applicant left uninformed. Callers state intent; this decides the rest.
 */
class ApplicationWorkflow
{
    public static function canTransition(Admission $admission, string $target): bool
    {
        $allowed = [
            'draft' => [],
            'submitted' => ['under_review', 'needs_correction', 'accepted', 'rejected', 'withdrawn'],
            'under_review' => ['submitted', 'needs_correction', 'accepted', 'rejected', 'withdrawn'],
            'needs_correction' => ['withdrawn'],
            'accepted' => ['enrolled', 'withdrawn'],
            'enrolled' => [], 'rejected' => [], 'withdrawn' => [], 'expired' => [],
        ];
        if ($admission->status !== $target && ! in_array($target, $allowed[$admission->status] ?? [], true)) { return false; }
        if (in_array($target, ['under_review', 'accepted', 'enrolled'], true)) {
            ApplicationFee::refreshStatus($admission);
            return filled($admission->submitted_at) && ApplicationProgress::canSubmit($admission) && ApplicationFee::isSettled($admission);
        }
        return true;
    }

    private static function atomic(Admission $admission, callable $operation): bool
    {
        return DB::transaction(function () use ($admission, $operation) {
            $locked = Admission::whereKey($admission->id)->where('school_id', $admission->school_id)->lockForUpdate()->firstOrFail();
            $result = $operation($locked);
            $admission->refresh();
            return $result;
        });
    }

    /**
     * Applicant submits (or resubmits after corrections).
     *
     * Returns false when the application is not actually complete — the
     * caller is expected to have checked, but submission is the one action
     * that must not be able to slip through a stale page or a double post.
     */
    public static function submit(Admission $admission, Applicant $applicant): bool
    {
        return self::atomic($admission, fn ($locked) => self::submitLocked($locked, $applicant));
    }

    private static function submitLocked(Admission $admission, Applicant $applicant): bool
    {
        if ((int) $admission->applicant_id !== (int) $applicant->id || (int) $admission->school_id !== (int) $applicant->school_id) { return false; }
        if (! $admission->isEditableByApplicant() || ! ApplicationProgress::canSubmit($admission)) {
            return false;
        }

        $wasCorrection = $admission->status === Admission::STATUS_NEEDS_CORRECTION;
        ApplicationFee::freezeObligation($admission);
        $fromStatus    = $admission->status;

        $admission->fill([
            'status'                  => Admission::STATUS_SUBMITTED,
            'submitted_at'            => now(),
            'declaration_accepted_at' => $admission->declaration_accepted_at ?: now(),
            'current_step'            => ApplicationProgress::STEP_REVIEW,
            'correction_note'         => null,
        ]);

        $admission->save();

        AdmissionStatusEvent::create([
            'school_id'    => $admission->school_id,
            'admission_id' => $admission->id,
            'from_status'  => $fromStatus,
            'to_status'    => Admission::STATUS_SUBMITTED,
            'title'        => $wasCorrection
                ? get_phrase('Application resubmitted after corrections')
                : get_phrase('Application submitted'),
            'note'         => $wasCorrection
                ? get_phrase('You made the requested changes and sent your application back for review.')
                : get_phrase('Your application was received by the admissions office.'),
            'actor_type'   => 'applicant',
            'actor_id'     => $applicant->id,
            'actor_name'   => $applicant->full_name,
        ]);

        AuditLog::record('create', 'Admissions', "Application {$admission->app_number} submitted by applicant {$applicant->email}.", [
            'event_type'  => 'DATA',
            'record_type' => Admission::class,
            'record_id'   => $admission->id,
            'school_id'   => $admission->school_id,
        ]);

        ApplicantNotifier::submitted($admission);

        return true;
    }

    /**
     * Staff submits a staff-entry application on the candidate's behalf.
     *
     * Same completeness gate and resulting state as submit() — an
     * administrator-entered application must clear exactly the same bar
     * before it can reach the review queue — the only difference is the
     * timeline entry's actor, since there is no Applicant to attribute it to
     * for a staff-entry admission (applicant_id is null).
     */
    public static function submitByStaff(Admission $admission, User $staff): bool
    {
        return self::atomic($admission, fn ($locked) => self::submitByStaffLocked($locked, $staff));
    }

    private static function submitByStaffLocked(Admission $admission, User $staff): bool
    {
        if ((int) $admission->school_id !== (int) $staff->school_id || ! $admission->isEditableByApplicant() || ! ApplicationProgress::canSubmit($admission)) {
            return false;
        }

        $wasCorrection = $admission->status === Admission::STATUS_NEEDS_CORRECTION;
        ApplicationFee::freezeObligation($admission);
        $fromStatus    = $admission->status;

        $admission->fill([
            'status'                  => Admission::STATUS_SUBMITTED,
            'submitted_at'            => now(),
            'declaration_accepted_at' => $admission->declaration_accepted_at ?: now(),
            'current_step'            => ApplicationProgress::STEP_REVIEW,
            'correction_note'         => null,
        ]);

        $admission->save();

        AdmissionStatusEvent::create([
            'school_id'    => $admission->school_id,
            'admission_id' => $admission->id,
            'from_status'  => $fromStatus,
            'to_status'    => Admission::STATUS_SUBMITTED,
            'title'        => $wasCorrection
                ? get_phrase('Application resubmitted after corrections')
                : get_phrase('Application submitted'),
            'note'         => get_phrase('Submitted by an administrator on the candidate\'s behalf.'),
            'actor_type'   => 'staff',
            'actor_id'     => $staff->id,
            'actor_name'   => $staff->name,
        ]);

        AuditLog::record('create', 'Admissions', "Application {$admission->app_number} submitted by staff {$staff->name}.", [
            'event_type'  => 'DATA',
            'record_type' => Admission::class,
            'record_id'   => $admission->id,
            'school_id'   => $admission->school_id,
        ]);

        ApplicationFee::refreshStatus($admission);
        ApplicantNotifier::submitted($admission);

        return true;
    }

    /**
     * Staff-driven status change.
     *
     * `$note` becomes the decision note the applicant sees; internal-only
     * commentary belongs in `admissions.notes`, which this never touches.
     */
    public static function transitionByStaff(Admission $admission, string $newStatus, User $staff, ?string $note = null): bool
    {
        return self::atomic($admission, fn ($locked) => self::transitionLocked($locked, $newStatus, $staff, $note));
    }

    private static function transitionLocked(Admission $admission, string $newStatus, User $staff, ?string $note): bool
    {
        if ($newStatus === Admission::STATUS_NEEDS_CORRECTION) {
            return self::correctionLocked($admission, $staff, (string) $note);
        }
        if ((int) $admission->school_id !== (int) $staff->school_id || ! in_array($newStatus, Admission::STAFF_SETTABLE_STATUSES, true)
            || ! self::canTransition($admission, $newStatus)) {
            return false;
        }

        $fromStatus = $admission->status;

        if ($fromStatus === $newStatus && blank($note)) {
            return false;
        }

        $admission->fill([
            'status'        => $newStatus,
            'reviewed_by'   => $staff->id,
            'decision_note' => $note ?: $admission->decision_note,
            'decided_at'    => in_array($newStatus, [Admission::STATUS_ACCEPTED, Admission::STATUS_REJECTED], true) ? now() : $admission->decided_at,
            'offer_date'    => $newStatus === Admission::STATUS_ACCEPTED ? now()->toDateString() : $admission->offer_date,
        ]);

        $admission->save();

        AdmissionStatusEvent::create([
            'school_id'    => $admission->school_id,
            'admission_id' => $admission->id,
            'from_status'  => $fromStatus,
            'to_status'    => $newStatus,
            'title'        => self::titleFor($newStatus),
            'note'         => $note,
            'actor_type'   => 'staff',
            'actor_id'     => $staff->id,
            'actor_name'   => $staff->name,
            // A move back into the queue between reviewers is noise to the
            // applicant; decisions are not.
            'is_visible_to_applicant' => $newStatus !== Admission::STATUS_SUBMITTED,
        ]);

        AuditLog::record('update', 'Admissions', "Application {$admission->app_number} status: {$fromStatus} → {$newStatus}", [
            'event_type'  => 'DATA',
            'record_type' => Admission::class,
            'record_id'   => $admission->id,
            'school_id'   => $admission->school_id,
            'old_values'  => ['status' => $fromStatus],
            'new_values'  => ['status' => $newStatus],
        ]);

        ApplicantNotifier::statusChanged($admission, $fromStatus);

        return true;
    }

    /**
     * Hands a submitted application back to the applicant for fixes.
     *
     * Distinct from a plain status change because the note is not optional —
     * "needs correction" with no explanation is a dead end for the applicant
     * — and because it reopens editing.
     */
    public static function requestCorrection(Admission $admission, User $staff, string $note): bool
    {
        return self::atomic($admission, fn ($locked) => self::correctionLocked($locked, $staff, $note));
    }

    private static function correctionLocked(Admission $admission, User $staff, string $note): bool
    {
        if ((int) $admission->school_id !== (int) $staff->school_id || blank($note) || ! self::canTransition($admission, Admission::STATUS_NEEDS_CORRECTION)) { return false; }
        $fromStatus = $admission->status;

        $admission->fill([
            'status'          => Admission::STATUS_NEEDS_CORRECTION,
            'correction_note' => $note,
            'reviewed_by'     => $staff->id,
        ]);

        $admission->save();

        AdmissionStatusEvent::create([
            'school_id'    => $admission->school_id,
            'admission_id' => $admission->id,
            'from_status'  => $fromStatus,
            'to_status'    => Admission::STATUS_NEEDS_CORRECTION,
            'title'        => get_phrase('Corrections requested'),
            'note'         => $note,
            'actor_type'   => 'staff',
            'actor_id'     => $staff->id,
            'actor_name'   => $staff->name,
        ]);

        AuditLog::record('update', 'Admissions', "Corrections requested on application {$admission->app_number}.", [
            'event_type'  => 'DATA',
            'record_type' => Admission::class,
            'record_id'   => $admission->id,
            'school_id'   => $admission->school_id,
        ]);

        ApplicantNotifier::correctionRequested($admission, $note);

        return true;
    }

    /** Applicant-visible wording for each status. */
    public static function titleFor(string $status): string
    {
        return [
            Admission::STATUS_DRAFT            => get_phrase('Application started'),
            Admission::STATUS_SUBMITTED        => get_phrase('Application submitted'),
            Admission::STATUS_UNDER_REVIEW     => get_phrase('Under review by the admissions committee'),
            Admission::STATUS_NEEDS_CORRECTION => get_phrase('Corrections requested'),
            Admission::STATUS_ACCEPTED         => get_phrase('Offer of admission made'),
            Admission::STATUS_REJECTED         => get_phrase('Application unsuccessful'),
            Admission::STATUS_ENROLLED         => get_phrase('Enrolment completed'),
            Admission::STATUS_WITHDRAWN        => get_phrase('Application withdrawn'),
        ][$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}
