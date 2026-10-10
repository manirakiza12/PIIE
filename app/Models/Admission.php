<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Admission extends Model
{
    use HasFactory;

    /**
     * The application lifecycle.
     *
     * 'draft' exists only in the applicant portal — a draft has never been
     * seen by staff and is invisible to the admissions queue by default.
     * 'needs_correction' is a submitted application handed back to the
     * applicant for fixes; it re-enters the queue as 'submitted' when they
     * resubmit, so no work is ever silently lost on either side.
     */
    public const STATUS_DRAFT            = 'draft';
    public const STATUS_SUBMITTED        = 'submitted';
    public const STATUS_UNDER_REVIEW     = 'under_review';
    public const STATUS_NEEDS_CORRECTION = 'needs_correction';
    public const STATUS_ACCEPTED         = 'accepted';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_ENROLLED         = 'enrolled';
    public const STATUS_WITHDRAWN        = 'withdrawn';
    /**
     * Closed automatically because the intake's application period ended while
     * the fee was still outstanding. Distinct from 'withdrawn': the applicant
     * did not withdraw, the period simply ran out.
     */
    public const STATUS_EXPIRED           = 'expired';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_NEEDS_CORRECTION,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_ENROLLED,
        self::STATUS_WITHDRAWN,
        self::STATUS_EXPIRED,
    ];

    /**
     * Statuses a reviewer may still act on. An expired application is terminal:
     * staff can read it but cannot move it forward, and the applicant is not
     * expected to return to it.
     */
    public const ACTIVE_REVIEW_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_NEEDS_CORRECTION,
        self::STATUS_ACCEPTED,
    ];

    /** Statuses staff can set directly from the review screen. */
    public const STAFF_SETTABLE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_NEEDS_CORRECTION,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_ENROLLED,
        self::STATUS_WITHDRAWN,
    ];

    /** Statuses in which the applicant may still edit their own answers. */
    public const APPLICANT_EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_NEEDS_CORRECTION,
    ];

    public const FEE_UNPAID  = 'unpaid';
    public const FEE_PENDING = 'pending';
    public const FEE_PAID    = 'paid';
    public const FEE_WAIVED  = 'waived';

    protected $fillable = [
        'school_id', 'applicant_id', 'app_number', 'intake_session_id', 'programme_id',
        'second_choice_programme_id', 'study_mode', 'how_did_you_hear',
        'title', 'first_name', 'middle_name', 'last_name', 'email', 'phone', 'dob', 'gender',
        'marital_status', 'religion', 'nationality', 'country_of_residence',
        'national_id_no', 'passport_no', 'physical_address', 'city',
        'has_disability', 'disability_details',
        'nok_name', 'nok_relationship', 'nok_phone', 'nok_email', 'nok_address',
        'sponsor_type', 'sponsor_name', 'sponsor_phone', 'sponsor_email',
        'qualifications', 'documents', 'status', 'source',
        'current_step', 'completed_steps', 'submitted_at', 'declaration_accepted_at',
        'fee_status', 'offer_date', 'agent_id', 'reviewed_by', 'notes',
        'correction_note', 'decision_note', 'decided_at',
    ];

    protected $casts = [
        'application_fee_amount'   => 'decimal:2',
        'documents'               => 'array',
        'completed_steps'         => 'array',
        'has_disability'          => 'boolean',
        'submitted_at'            => 'datetime',
        'declaration_accepted_at' => 'datetime',
        'decided_at'              => 'datetime',
        'dob'                     => 'date',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'applicant_id');
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function secondChoiceProgramme()
    {
        return $this->belongsTo(Programme::class, 'second_choice_programme_id');
    }

    public function intakeSession()
    {
        return $this->belongsTo(IntakeSession::class, 'intake_session_id');
    }

    public function agent()
    {
        return $this->belongsTo(AdmissionAgent::class, 'agent_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function uploadedDocuments()
    {
        return $this->hasMany(AdmissionDocument::class, 'admission_id');
    }

    public function educationHistory()
    {
        return $this->hasMany(AdmissionQualification::class, 'admission_id');
    }

    public function statusEvents()
    {
        return $this->hasMany(AdmissionStatusEvent::class, 'admission_id');
    }

    public function payments()
    {
        return $this->hasMany(ApplicationPayment::class, 'admission_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /**
     * Excludes drafts. The staff admissions queue uses this everywhere:
     * a half-filled form the applicant has not submitted is not an
     * application anyone should be reviewing, counting or exporting.
     */
    public function scopeSubmittedOnly($query)
    {
        return $query->where('status', '!=', self::STATUS_DRAFT);
    }

    // ── Derived state ────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isEditableByApplicant(): bool
    {
        return in_array($this->status, self::APPLICANT_EDITABLE_STATUSES, true);
    }

    public function isFeeSettled(): bool
    {
        return in_array($this->fee_status, [self::FEE_PAID, self::FEE_WAIVED], true);
    }

    public function statusLabel(): string
    {
        return ucwords(str_replace('_', ' ', (string) $this->status));
    }

    /**
     * Bootstrap colour suffix for the status pill, so the portal, the admin
     * queue and the review screen never drift apart on what "accepted" looks
     * like.
     */
    public function statusColor(): string
    {
        return [
            self::STATUS_DRAFT            => 'secondary',
            self::STATUS_SUBMITTED        => 'info',
            self::STATUS_UNDER_REVIEW     => 'primary',
            self::STATUS_NEEDS_CORRECTION => 'warning',
            self::STATUS_ACCEPTED         => 'success',
            self::STATUS_REJECTED         => 'danger',
            self::STATUS_ENROLLED         => 'success',
            self::STATUS_WITHDRAWN        => 'dark',
            self::STATUS_EXPIRED           => 'dark',
        ][$this->status] ?? 'secondary';
    }
}
