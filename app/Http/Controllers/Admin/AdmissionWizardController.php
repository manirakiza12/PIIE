<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\AdmissionQualification;
use App\Models\AdmissionStatusEvent;
use App\Models\AuditLog;
use App\Models\IntakeSession;
use App\Models\Programme;
use App\Support\Admissions\ApplicationDocuments;
use App\Support\Admissions\ApplicationFee;
use App\Support\Admissions\ApplicationProgress;
use App\Support\Admissions\ApplicationReference;
use App\Support\Admissions\ApplicationWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The staff-entry counterpart of Applicant\ApplicationController — same
 * Admission model, same ApplicationProgress/ApplicationDocuments/
 * AdmissionQualification, same validation shape, so an application never
 * ends up a different shape merely because an administrator typed it in on
 * a candidate's behalf rather than the candidate typing it in themselves.
 *
 * The one structural difference from the applicant portal: an applicant
 * always works on "their" one current application (resolved implicitly by
 * applicant_id), but an administrator manages many candidates' applications
 * at once, so every route here is scoped by an explicit {admission} rather
 * than an implicit "current" one. Every lookup is additionally scoped to
 * this school and to source=staff_entry, so this wizard can never be used to
 * silently rewrite an application a candidate submitted through the public
 * portal — that stays the review screen's job.
 *
 * There is no payment step here (unlike the public wizard): an administrator
 * entering an application on a candidate's behalf is not the one paying the
 * application fee, so ApplicationProgress::ORDER's payment step is skipped
 * in favour of this controller's own, shorter step order. "Admission &
 * Enrollment" (academic assignment) is deliberately not a wizard step
 * either — it already lives on the existing admin/admissions/review screen,
 * shared with online-application decisions, so it is reached from Review &
 * Submit rather than duplicated here.
 */
class AdmissionWizardController extends Controller
{
    private $school_id;

    public const STEP_ORDER = ['personal', 'programme', 'education', 'documents', 'review'];

    private const GENDERS        = ['Male', 'Female', 'Others'];
    private const MARITAL_STATUS = ['Single', 'Married', 'Divorced', 'Widowed'];
    private const SPONSOR_TYPES  = ['Self', 'Parent/Guardian', 'Employer', 'Scholarship', 'Other'];
    private const HEARD_ABOUT    = ['Website', 'Social Media', 'Friend or Family', 'Radio or TV', 'Newspaper', 'Education Fair', 'Agent', 'Other'];

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;

            abort_unless($this->school_id, 403);

            return $next($request);
        });
    }

    /** Scopes every lookup to this school and to staff-entered applications only. */
    private function findAdmission(int $id): Admission
    {
        return Admission::where('school_id', $this->school_id)
            ->where('source', 'staff_entry')
            ->findOrFail($id);
    }

    /**
     * Choose existing-student onboarding or a new application without
     * creating a draft merely by opening the page.
     */
    public function create(Request $request)
    {
        return view('admin.admissions.wizard.create');
    }

    /** Explicitly start a new applicant draft after the staff member chooses that path. */
    public function start(Request $request)
    {
        $admission = Admission::create([
            'school_id'    => $this->school_id,
            'app_number'   => ApplicationReference::generate($this->school_id, 'staff_entry'),
            'first_name'   => '',
            'last_name'    => '',
            'status'       => Admission::STATUS_DRAFT,
            'source'       => 'staff_entry',
            'current_step' => 'personal',
        ]);

        AdmissionStatusEvent::create([
            'school_id'    => $admission->school_id,
            'admission_id' => $admission->id,
            'from_status'  => null,
            'to_status'    => Admission::STATUS_DRAFT,
            'title'        => get_phrase('Application started by staff'),
            'note'         => get_phrase('This application was started by an administrator on the candidate\'s behalf.'),
            'actor_type'   => 'staff',
            'actor_id'     => Auth::id(),
            'actor_name'   => Auth::user()->name,
        ]);

        return redirect()->route('admin.hei_admissions.wizard.step', [$admission->id, 'personal']);
    }

    public function step(int $id, string $step)
    {
        if (! in_array($step, self::STEP_ORDER, true)) {
            abort(404);
        }

        $admission = $this->findAdmission($id);
        $admission->load(['programme', 'intakeSession', 'educationHistory', 'uploadedDocuments', 'payments']);

        if ($admission->current_step !== $step && $this->isEditable($admission)) {
            $admission->forceFill(['current_step' => $step])->save();
        }

        $shared = [
            'admission' => $admission,
            'step'      => $step,
            'steps'     => $this->stepList($admission),
            'percent'   => $this->percent($admission),
            'readOnly'  => ! $this->isEditable($admission),
        ];

        switch ($step) {
            case 'personal':
                [$phoneCode, $phoneNumber] = $this->splitPhone($admission->phone);

                return view('admin.admissions.wizard.personal', $shared + [
                    'genders'       => self::GENDERS,
                    'maritalStatus' => self::MARITAL_STATUS,
                    'countries'     => config('countries'),
                    'religions'     => config('religions'),
                    'phoneCode'     => $phoneCode,
                    'phoneNumber'   => $phoneNumber,
                ]);

            case 'programme':
                return view('admin.admissions.wizard.programme', $shared + [
                    'programmes'   => Programme::where('school_id', $admission->school_id)->where('is_active', 1)->orderBy('name')->get(),
                    'intakes'      => IntakeSession::where('school_id', $admission->school_id)->where('is_open', 1)->orderByDesc('id')->get(),
                    'modes'        => Programme::selectableModes($admission->study_mode),
                    'sponsorTypes' => self::SPONSOR_TYPES,
                    'heardAbout'   => self::HEARD_ABOUT,
                ]);

            case 'education':
                return view('admin.admissions.wizard.education', $shared + [
                    'qualifications' => $admission->educationHistory,
                ]);

            case 'documents':
                return view('admin.admissions.wizard.documents', $shared + [
                    'checklist' => ApplicationDocuments::checklist($admission),
                    'maxMb'     => ApplicationDocuments::MAX_FILE_MB,
                ]);

            case 'review':
            default:
                $feeAmount = ApplicationFee::amountFor($admission);
                $feePaid   = (float) $admission->payments->where('status', 'paid')->sum('amount');

                return view('admin.admissions.wizard.review', $shared + [
                    'blockers'       => $this->blockers($admission),
                    'canSubmit'      => empty($this->blockers($admission)),
                    'feeAmount'      => $feeAmount,
                    'feePaid'        => $feePaid,
                    'feeOutstanding' => max(0, $feeAmount - $feePaid),
                ]);
        }
    }

    // ── Step handlers (personal/programme/education mirror the applicant
    //    portal's ApplicationController one-for-one; see it for why each
    //    validation rule is shaped the way it is) ────────────────────────

    public function savePersonal(Request $request, int $id)
    {
        $admission = $this->findAdmission($id);

        if ($redirect = $this->guardEditable($admission)) {
            return $redirect;
        }

        $countryNames  = array_column(config('countries'), 'name');
        $nationalities = array_column(config('countries'), 'nationality');
        $dialCodes     = array_unique(array_filter(array_column(config('countries'), 'dial_code')));
        $religions     = config('religions');

        $validated = $request->validate([
            'title'                => 'nullable|string|max:10',
            'first_name'           => 'required|string|max:100',
            'middle_name'          => 'nullable|string|max:100',
            'last_name'            => 'required|string|max:100',
            'email'                => 'required|email|max:150',
            'phone_code'           => ['required', Rule::in($dialCodes)],
            'phone_number'         => 'required|string|max:20|regex:/^[0-9\s\-\(\)]+$/',
            'dob'                  => 'required|date|before:today',
            'gender'               => ['required', Rule::in(self::GENDERS)],
            'marital_status'       => ['nullable', Rule::in(self::MARITAL_STATUS)],
            'religion'             => ['nullable', Rule::in($religions)],
            'religion_other'       => 'nullable|string|max:50',
            'nationality'          => ['required', Rule::in($nationalities)],
            'country_of_residence' => ['nullable', Rule::in($countryNames)],
            'national_id_no'       => 'nullable|string|max:50',
            'passport_no'          => 'nullable|string|max:50',
            'physical_address'     => 'required|string|max:500',
            'city'                 => 'nullable|string|max:80',
            'has_disability'       => 'nullable|boolean',
            'disability_details'   => 'nullable|string|max:500',
            'nok_name'             => 'required|string|max:150',
            'nok_relationship'     => 'required|string|max:60',
            'nok_phone'            => 'required|string|max:30',
            'nok_email'            => 'nullable|email|max:150',
            'nok_address'          => 'nullable|string|max:500',
        ]);

        $validated['phone'] = trim($validated['phone_code'] . ' ' . $validated['phone_number']);
        unset($validated['phone_code'], $validated['phone_number']);

        if ((($validated['religion'] ?? null) === 'Other') && filled($validated['religion_other'] ?? null)) {
            $validated['religion'] = $validated['religion_other'];
        }
        unset($validated['religion_other']);

        $validated['has_disability'] = $request->boolean('has_disability');

        if (! $validated['has_disability']) {
            $validated['disability_details'] = null;
        }

        $admission->update($validated);

        return $this->advance($request, $admission, 'personal');
    }

    private function splitPhone(?string $phone): array
    {
        if (blank($phone)) {
            return [null, null];
        }

        if (preg_match('/^(\+\d{1,4})\s+(.+)$/', trim($phone), $m)) {
            $codes = array_column(config('countries'), 'dial_code');
            if (in_array($m[1], $codes, true)) {
                return [$m[1], $m[2]];
            }
        }

        return [null, $phone];
    }

    public function saveProgramme(Request $request, int $id)
    {
        $admission = $this->findAdmission($id);

        if ($redirect = $this->guardEditable($admission)) {
            return $redirect;
        }

        $schoolId = $admission->school_id;

        $validated = $request->validate([
            'programme_id' => [
                'required',
                Rule::exists('programmes', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)->where('is_active', 1)),
            ],
            'second_choice_programme_id' => [
                'nullable', 'different:programme_id',
                Rule::exists('programmes', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)->where('is_active', 1)),
            ],
            'intake_session_id' => [
                'required',
                Rule::exists('intake_sessions', 'id')->where(fn ($q) => $q->where('school_id', $schoolId)->where('is_open', 1)),
            ],
            'study_mode'       => ['nullable', 'string', 'max:30', Rule::in(Programme::selectableModes($admission->study_mode))],
            'sponsor_type'     => ['nullable', Rule::in(self::SPONSOR_TYPES)],
            'sponsor_name'     => 'nullable|string|max:150',
            'sponsor_phone'    => 'nullable|string|max:30',
            'sponsor_email'    => 'nullable|email|max:150',
            'how_did_you_hear' => ['nullable', Rule::in(self::HEARD_ABOUT)],
        ], [
            'second_choice_programme_id.different' => get_phrase('The second choice must be a different programme.'),
            'intake_session_id.exists'             => get_phrase('That intake is no longer open for applications.'),
        ]);

        $intakeChanged = (int) $admission->intake_session_id !== (int) $validated['intake_session_id'];

        $admission->update($validated);

        // Same rule as the online applicant wizard (Applicant\ApplicationController::
        // saveProgramme()): changing intake can change the fee, and therefore
        // fee_status, so it is recomputed rather than left stale.
        if ($intakeChanged) {
            ApplicationFee::refreshStatus($admission->fresh());
        }

        return $this->advance($request, $admission, 'programme');
    }

    public function saveEducation(Request $request, int $id)
    {
        $admission = $this->findAdmission($id);

        if ($redirect = $this->guardEditable($admission)) {
            return $redirect;
        }

        $validated = $request->validate([
            'qualifications'                => 'nullable|string|max:2000',
            'education'                     => 'nullable|array|max:10',
            'education.*.institution'       => 'required_with:education.*.award|nullable|string|max:200',
            'education.*.award'             => 'nullable|string|max:150',
            'education.*.subject'           => 'nullable|string|max:150',
            'education.*.grade'             => 'nullable|string|max:60',
            'education.*.start_year'        => 'nullable|integer|min:1950|max:' . (date('Y') + 1),
            'education.*.end_year'          => 'nullable|integer|min:1950|max:' . (date('Y') + 10),
            'education.*.country'           => 'nullable|string|max:80',
        ]);

        $rows = collect($validated['education'] ?? [])
            ->filter(fn ($row) => ! blank($row['institution'] ?? null))
            ->values();

        DB::transaction(function () use ($admission, $rows, $validated) {
            $admission->educationHistory()->delete();

            foreach ($rows as $row) {
                AdmissionQualification::create([
                    'school_id'    => $admission->school_id,
                    'admission_id' => $admission->id,
                    'institution'  => $row['institution'],
                    'award'        => $row['award'] ?? null,
                    'subject'      => $row['subject'] ?? null,
                    'grade'        => $row['grade'] ?? null,
                    'start_year'   => $row['start_year'] ?? null,
                    'end_year'     => $row['end_year'] ?? null,
                    'country'      => $row['country'] ?? null,
                ]);
            }

            $admission->update(['qualifications' => $validated['qualifications'] ?? null]);
        });

        return $this->advance($request, $admission->fresh(), 'education');
    }

    /**
     * Same storage/validation/requirement logic as the applicant portal's
     * DocumentController::store() — ApplicationDocuments::store() is
     * guard-agnostic, so the only difference is the attribution column:
     * uploaded_by_user_id here instead of uploaded_by_applicant_id, so a
     * reviewer can always tell whether a document came from the candidate
     * or was filed on their behalf by staff.
     */
    public function saveDocument(Request $request, int $id)
    {
        $admission = $this->findAdmission($id);

        if ($redirect = $this->guardEditable($admission)) {
            return $redirect;
        }

        $allowed = ApplicationDocuments::requirementsFor($admission)->pluck('key')->all();
        $maxKb   = ApplicationDocuments::MAX_FILE_MB * 1024;
        $mimes   = implode(',', ApplicationDocuments::ALLOWED_EXTENSIONS);

        $validated = $request->validate([
            'requirement_key' => 'required|string|max:60|in:' . implode(',', $allowed),
            'files'           => 'required|array|max:5',
            'files.*'         => "file|mimes:{$mimes}|max:{$maxKb}",
        ], [
            'files.*.mimes' => get_phrase('Only PDF, JPG and PNG files are accepted.'),
            'files.*.max'   => get_phrase('Each file must be no larger than') . ' ' . ApplicationDocuments::MAX_FILE_MB . 'MB.',
        ]);

        $requirement = ApplicationDocuments::requirementsFor($admission)
            ->firstWhere('key', $validated['requirement_key']);

        $files = $request->file('files');

        if ($requirement && ! $requirement->allow_multiple) {
            $files = [reset($files)];

            foreach ($admission->uploadedDocuments()->where('requirement_key', $requirement->key)->get() as $existing) {
                ApplicationDocuments::delete($existing);
            }
        }

        foreach ($files as $file) {
            ApplicationDocuments::store($admission, $file, $validated['requirement_key'], $requirement->label ?? null, [
                'uploaded_by_user_id' => Auth::id(),
            ]);
        }

        AuditLog::record('create', 'Admissions', "Staff uploaded documents for {$admission->app_number} ({$validated['requirement_key']}).", [
            'event_type'  => 'DATA',
            'record_type' => Admission::class,
            'record_id'   => $admission->id,
            'school_id'   => $this->school_id,
        ]);

        return back()->with('success', get_phrase('Document uploaded.'));
    }

    /** Same immutability rule as the portal: a verified document cannot be silently removed. */
    public function destroyDocument(int $id, int $documentId)
    {
        $admission = $this->findAdmission($id);

        if ($redirect = $this->guardEditable($admission)) {
            return $redirect;
        }

        $document = AdmissionDocument::where('admission_id', $admission->id)->findOrFail($documentId);

        if ($document->status === AdmissionDocument::STATUS_VERIFIED) {
            return back()->with('error', get_phrase('This document has already been verified and cannot be removed. Use the review screen if it needs to change.'));
        }

        ApplicationDocuments::delete($document);

        AuditLog::record('delete', 'Admissions', "Staff removed a document from {$admission->app_number}.", [
            'event_type'  => 'DATA',
            'record_type' => Admission::class,
            'record_id'   => $admission->id,
            'school_id'   => $this->school_id,
        ]);

        return back()->with('success', get_phrase('Document removed.'));
    }

    /**
     * Sends a complete staff-entry draft into the same admissions queue an
     * online application lands in — from here on, review/accept/reject/
     * enrol is entirely the existing admin.hei_admissions.review screen,
     * shared with online applications. There is no separate "staff decides
     * their own application" shortcut.
     */
    public function submit(Request $request, int $id)
    {
        $admission = $this->findAdmission($id);

        if ($redirect = $this->guardEditable($admission)) {
            return $redirect;
        }

        if (! ApplicationWorkflow::submitByStaff($admission, Auth::user())) {
            return back()->with('error', get_phrase('This application is not complete yet. Please resolve the outstanding items listed below.'));
        }

        return redirect()->route('admin.hei_admissions.review', $admission->id)
            ->with('success', get_phrase('Application submitted and sent to the admissions queue.'));
    }

    // ── Shared helpers ───────────────────────────────────────────────────

    /**
     * Staff may edit a staff-entry application right up to a final decision —
     * unlike the applicant portal, there is no "submitted, hands off" rule
     * here, because the whole point is an administrator entering/correcting
     * data on the candidate's behalf. Once accepted/rejected/enrolled/
     * withdrawn, further changes belong on the review screen's decision
     * workflow (correction/status), not this wizard.
     */
    private function isEditable(Admission $admission): bool
    {
        if (ApplicationWorkflow::hasBeenSubmitted($admission) && ! ApplicationWorkflow::staffProcessingAllowed($admission)) {
            return false;
        }

        return in_array($admission->status, [
            Admission::STATUS_DRAFT,
            Admission::STATUS_NEEDS_CORRECTION,
            Admission::STATUS_SUBMITTED,
            Admission::STATUS_UNDER_REVIEW,
        ], true);
    }

    private function guardEditable(Admission $admission)
    {
        if ($this->isEditable($admission)) {
            return null;
        }

        return redirect()->route('admin.hei_admissions.review', $admission->id)->with(
            'error',
            ApplicationWorkflow::hasBeenSubmitted($admission) && ! ApplicationFee::isSettled($admission)
                ? ApplicationWorkflow::staffProcessingMessage()
                : get_phrase('This application has already been decided and can no longer be edited here. Use Return for Corrections if something needs to change.')
        );
    }

    /** This controller's own step order — see the class docblock for why it excludes payment. */
    private function stepList(Admission $admission): array
    {
        $labels = [
            'personal'  => get_phrase('Personal Information'),
            'programme' => get_phrase('Programme Selection'),
            'education' => get_phrase('Education History'),
            'documents' => get_phrase('Supporting Documents'),
            'review'    => get_phrase('Review & Submit'),
        ];

        return array_map(fn ($key) => [
            'key'      => $key,
            'label'    => $labels[$key],
            'complete' => $this->isStepComplete($admission, $key),
            'url'      => route('admin.hei_admissions.wizard.step', [$admission->id, $key]),
        ], self::STEP_ORDER);
    }

    private function isStepComplete(Admission $admission, string $step): bool
    {
        return match ($step) {
            'personal'  => ApplicationProgress::isComplete($admission, ApplicationProgress::STEP_PERSONAL),
            'programme' => ApplicationProgress::isComplete($admission, ApplicationProgress::STEP_PROGRAMME),
            'education' => ApplicationProgress::isComplete($admission, ApplicationProgress::STEP_EDUCATION),
            'documents' => ApplicationDocuments::isComplete($admission),
            'review'    => ! blank($admission->submitted_at),
            default     => false,
        };
    }

    private function percent(Admission $admission): int
    {
        $steps = $this->stepList($admission);
        $countable = array_filter($steps, fn ($s) => $s['key'] !== 'review');

        if (empty($countable)) {
            return 0;
        }

        $complete = count(array_filter($countable, fn ($s) => $s['complete']));

        return (int) round(($complete / count($countable)) * 100);
    }

    /** Human-readable reasons submission is blocked — mirrors ApplicationProgress::blockers(). */
    private function blockers(Admission $admission): array
    {
        $blockers = [];

        foreach ($this->stepList($admission) as $step) {
            if ($step['key'] === 'review' || $step['complete']) {
                continue;
            }

            if ($step['key'] === 'documents') {
                foreach (ApplicationDocuments::outstanding($admission) as $item) {
                    $blockers[] = $item;
                }
                continue;
            }

            $blockers[] = $step['label'];
        }

        return $blockers;
    }

    /**
     * Where to go after a step saves: the next incomplete step on
     * "Save & Continue", or back to the same step on "Save" — same UX
     * contract as the applicant portal's advance().
     */
    private function advance(Request $request, Admission $admission, string $currentStep)
    {
        $message = get_phrase('Saved.');

        if ($request->input('action') !== 'continue') {
            return back()->with('success', $message);
        }

        $keys     = self::STEP_ORDER;
        $position = array_search($currentStep, $keys, true);
        $nextKey  = $position !== false && isset($keys[$position + 1]) ? $keys[$position + 1] : 'review';

        return redirect()->route('admin.hei_admissions.wizard.step', [$admission->id, $nextKey])->with('success', $message);
    }
}
