<?php

namespace App\Support\Admissions;

use App\Models\Admission;
use App\Models\AdmissionLetter;
use App\Models\FeeStructure;
use App\Models\School;
use App\Models\User;
use App\Support\PublicTenantResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One private, immutable letter per enrolled application; shared by email and downloads. */
final class AdmissionLetterDocument
{
    public static function issue(Admission $admission): ?AdmissionLetter
    {
        if ($admission->status !== Admission::STATUS_ENROLLED || ! Schema::hasTable('admission_letters')) return null;
        $student = self::student($admission);
        if (! $student) return null;
        return AdmissionLetter::firstOrCreate(
            ['admission_id' => $admission->id, 'school_id' => $admission->school_id],
            ['student_id' => $student->id, 'snapshot' => self::snapshot($admission), 'issued_at' => now()],
        );
    }

    public static function forAdmission(Admission $admission): array
    {
        if (Schema::hasTable('admission_letters')) {
            $letter = AdmissionLetter::where('school_id', $admission->school_id)->where('admission_id', $admission->id)->first();
            if ($letter) return $letter->snapshot;
        }
        return self::snapshot($admission);
    }

    private static function student(Admission $admission): ?User
    {
        return User::where('school_id', $admission->school_id)->where('role_id', 7)->where('email', $admission->email)->first();
    }

    public static function snapshot(Admission $admission): array
    {
        $school = School::findOrFail($admission->school_id);
        // Always re-read relationships under the letter's tenant boundary.
        $programme = \App\Models\Programme::where('school_id', $school->id)->find($admission->programme_id);
        $intake = \App\Models\IntakeSession::where('school_id', $school->id)->find($admission->intake_session_id);
        $student = self::student($admission);
        $piie = (int) PublicTenantResolver::resolveSchoolId() === (int) $school->id
            || preg_match('/prime international|\bpiie\b/i', (string) $school->title);
        $settings = [];
        if ($piie && Schema::hasTable('website_settings')) {
            $query = DB::table('website_settings')->where('status', 1);
            if (Schema::hasColumn('website_settings', 'school_id')) $query->where(fn ($q) => $q->where('school_id', $school->id)->orWhereNull('school_id'));
            $settings = $query->pluck('value', 'key')->all();
        }
        $fees = [];
        if ($programme && Schema::hasTable('fee_structures')) {
            $sessionId = $school->running_session;
            $classId = $student ? \App\Models\Enrollment::where('school_id', $school->id)->where('user_id', $student->id)->value('class_id') : null;
            $rows = FeeStructure::where('school_id', $school->id)
                ->where(fn ($q) => $q->whereNull('programme_id')->orWhere('programme_id', $programme->id))
                ->where(fn ($q) => $q->whereNull('session_id')->orWhere('session_id', $sessionId))
                ->where(fn ($q) => $q->whereNull('class_id')->orWhere('class_id', 0)->when($classId, fn ($q) => $q->orWhere('class_id', $classId)))
                ->orderBy('id')->get();
            foreach ($rows as $fee) {
                $fees[] = ['name' => $fee->name, 'amount' => (string) $fee->amount,
                    'basis' => $fee->per_semester ? 'Per semester' : 'As configured',
                    'mandatory' => (bool) $fee->is_mandatory, 'type' => $fee->fee_type];
            }
        }
        if (! collect($fees)->contains(fn ($fee) => $fee['type'] === 'tuition') && $programme && (float) $programme->tuition_fee > 0) {
            $basis = \App\Support\ProgrammeCatalogue\ProgrammePrice::BASIS_LABELS[$programme->tuition_fee_basis] ?? null;
            if ($basis) array_unshift($fees, ['name' => 'Tuition', 'amount' => (string) $programme->tuition_fee, 'basis' => ucfirst($basis), 'mandatory' => true, 'type' => 'tuition', 'currency' => $programme->tuition_currency]);
        }
        $currency = $school->school_currency;
        if (! is_string($currency) || ! preg_match('/^[A-Za-z]{3}$/', $currency)) $currency = null;
        $logo = $piie ? self::piieLogo() : null;
        $phone = trim((string) $school->phone);
        if (! preg_match('/[1-9]/', $phone)) {
            $phones = $settings['contact_phone'] ?? '';
            $decoded = json_decode($phones, true);
            $phone = is_array($decoded) ? implode(' | ', $decoded) : $phones;
        }
        return [
            'admission_id' => $admission->id, 'school_id' => $school->id, 'student_id' => $student?->id,
            'institution' => $piie ? 'Prime International Institute of Excellence (PIIE)' : $school->title,
            'address' => trim((string) $school->address), 'phone' => $phone,
            'email' => $piie ? 'info@piie.ac.ug' : $school->email,
            'website' => $piie ? 'https://piie.ac.ug/' : null,
            'portal_url' => route('login'), 'logo' => $logo,
            'name' => $admission->full_name, 'nationality' => $admission->nationality,
            'application_number' => $admission->app_number, 'registration_number' => $student?->code,
            'issued_on' => ($admission->status === Admission::STATUS_ENROLLED ? now() : \Carbon\Carbon::parse($admission->offer_date ?: now()))->format('d F Y'),
            'programme' => $programme?->name ?: 'Programme confirmed by the Admissions Office',
            'programme_code' => $programme?->code, 'duration' => $programme?->duration,
            'mode' => self::mode($admission->study_mode ?: $programme?->mode), 'intake' => $intake?->name,
            'start_date' => self::date($intake?->start_date), 'orientation_start_date' => self::date($intake?->orientation_start_date),
            'orientation_end_date' => self::date($intake?->orientation_end_date), 'registration_deadline' => self::date($intake?->registration_deadline),
            'currency' => strtoupper($currency ?? ''), 'fees' => array_map(function ($fee) use ($currency) {
                $code = $fee['currency'] ?? $currency;
                $fee['currency'] = is_string($code) && preg_match('/^[A-Za-z]{3}$/', $code) ? strtoupper($code) : 'Currency to be confirmed:';
                return $fee;
            }, $fees),
            'decision_note' => $admission->decision_note,
            'registrar_name' => $settings['academic_registrar_name'] ?? null,
        ];
    }

    private static function mode(?string $mode): string
    {
        return match (strtolower(trim($mode ?? ''))) {
            'odel', 'online', 'online_distance_learning' => 'Online Distance Learning',
            'fulltime', 'full_time', 'full time' => 'Full Time',
            'parttime', 'part_time' => 'Part Time',
            'weekend' => 'Weekend', 'blended' => 'Blended Learning',
            default => $mode ?: 'As confirmed by the Admissions Office',
        };
    }

    private static function date(?string $date): ?string
    {
        return filled($date) ? \Carbon\Carbon::parse($date)->format('d F Y') : null;
    }

    private static function piieLogo(): ?string
    {
        // The verified PIIE crest, embedded locally: PDFs make no external image requests.
        $path = base_path('public/assets/uploads/logo/logo.png');
        return is_file($path) ? 'data:image/png;base64,'.base64_encode(file_get_contents($path)) : null;
    }

    public static function pdf(array $letter): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('admin.admissions.offer_letter', ['letter' => $letter])
            ->setPaper('a4')->setOption('isRemoteEnabled', false)->setOption('isPhpEnabled', false);
    }

    public static function filename(array $letter): string
    {
        return 'PIIE_Admission_Letter_'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $letter['application_number']).'.pdf';
    }
}
