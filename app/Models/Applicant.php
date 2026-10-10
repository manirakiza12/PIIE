<?php

namespace App\Models;

use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A prospective student, authenticated on the "applicant" guard.
 *
 * Intentionally not a `users` row — see the create_applicants_table
 * migration for why. An applicant becomes a User only at enrolment, via
 * AdmissionsController::createStudentFromAdmission(); `converted_user_id`
 * records that hand-off so the portal can point them at the student login
 * instead of leaving them on a finished application forever.
 */
class Applicant extends Authenticatable implements CanResetPasswordContract
{
    use HasFactory, Notifiable, CanResetPassword;

    protected $fillable = [
        'school_id', 'first_name', 'last_name', 'email', 'phone', 'password',
        'email_verified_at', 'email_verification_token', 'is_active',
        'last_login_at', 'converted_user_id',
    ];

    protected $hidden = ['password', 'remember_token', 'email_verification_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'is_active'         => 'boolean',
    ];

    public function admissions()
    {
        return $this->hasMany(Admission::class, 'applicant_id');
    }

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(substr($this->first_name, 0, 1) . substr($this->last_name, 0, 1));
    }

    /**
     * The application an applicant lands on when they open the portal: the
     * one still in progress if there is one, otherwise their most recent.
     * Applicants may hold several over the years (a rejected 2025 attempt and
     * a live 2026 one), so "current" is never just "the only one".
     */
    public function currentAdmission(): ?Admission
    {
        return $this->admissions()
            ->where('school_id', $this->school_id)
            ->orderByRaw("CASE WHEN status IN ('draft','needs_correction') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();
    }
}
