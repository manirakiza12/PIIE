<?php

namespace App\Http\Controllers\Applicant;

use App\Models\Admission;
use App\Models\AdmissionQualification;
use App\Models\IntakeSession;
use App\Models\Programme;
use App\Support\Admissions\ApplicationDocuments;
use App\Support\Admissions\ApplicationFee;
use App\Support\Admissions\ApplicationProgress;
use App\Support\Admissions\ApplicationWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The multi-step application form.
 *
 * Steps are addressable and independently saveable — an applicant can jump
 * straight to the one they have information for and come back for the rest.
 * Nothing is gated on completing the previous step, because forcing a linear
 * path is what makes people abandon an application they cannot finish in one
 * sitting; the submit button is what enforces completeness, once, at the end.
 */
class ApplicationController extends BaseApplicantController
{
    private const GENDERS         = ['Male', 'Female', 'Others'];
    private const MARITAL_STATUS  = ['Single', 'Married', 'Divorced', 'Widowed'];
    private const SPONSOR_TYPES   = ['Self', 'Parent/Guardian', 'Employer', 'Scholarship', 'Other'];
    private const HEARD_ABOUT     = ['Website', 'Social Media', 'Friend or Family', 'Radio or TV', 'Newspaper', 'Education Fair', 'Agent', 'Other'];

    public function index()
    {
        $admission = $this->currentApplication();
        $next      = ApplicationProgress::nextStep($admission);

        return redirect()->route('applicant.application.step', $next['key'] ?? ApplicationProgress::STEP_PERSONAL);
    }

    public function step(string $step)
    {
        if (! in_array($step, ApplicationProgress::ORDER, true)) {
            abort(404);
        }

        $admission = $this->currentApplication();
        $admission->load(['programme', 'intakeSession', 'educationHistory', 'uploadedDocuments', 'payments']);

        // Remembering where they were is what makes "Continue Application"
        // land somewhere useful after a break.
        if ($admission->isEditableByApplicant() && $admission->current_step !== $step) {
            $admission->forceFill(['current_step' => $step])->save();
        }

        $shared = [
            'admission' => $admission,
            'step'      => $step,
            'steps'     => ApplicationProgress::applicableSteps($admission),
            'percent'   => ApplicationProgress::percent($admission),
            'readOnly'  => ! $admission->isEditableByApplicant(),
        ];

        switch ($step) {
            case ApplicationProgress::STEP_PERSONAL:
                [$phoneCode, $phoneNumber] = self::splitPhone($admission->phone);

                return view('applicant.application.personal', $shared + [
                    'genders'        => self::GENDERS,
                    'maritalStatus'  => self::MARITAL_STATUS,
                    'countries'      => config('countries'),
                    'religions'      => config('religions'),
                    'phoneCode'      => $phoneCode,
                    'phoneNumber'    => $phoneNumber,
                ]);

            case ApplicationProgress::STEP_PROGRAMME:
                $selection = session('public_programme_selection', []);
                $selectedProgrammeId = ($selection['school_id'] ?? null) == $admission->school_id
                    ? Programme::published()->where('school_id', $admission->school_id)
                        ->whereKey($selection['programme_id'] ?? 0)->value('id') : null;
                return view('applicant.application.programme', $shared + [
                    'selectedProgrammeId' => $selectedProgrammeId,
                    'programmes'   => Programme::where('school_id', $admission->school_id)->where('is_active', 1)->orderBy('name')->get(),
                    'intakes'      => IntakeSession::where('school_id', $admission->school_id)->where('is_open', 1)->orderByDesc('id')->get(),
                    'modes'        => array_merge(Programme::MODES, Programme::MODES_LEGACY),
                    'sponsorTypes' => self::SPONSOR_TYPES,
                    'heardAbout'   => self::HEARD_ABOUT,
                ]);

            case ApplicationProgress::STEP_EDUCATION:
                return view('applicant.application.education', $shared + [
                    'qualifications' => $admission->educationHistory,
                ]);

            case ApplicationProgress::STEP_DOCUMENTS:
                return view('applicant.application.documents', $shared + [
                    'checklist' => ApplicationDocuments::checklist($admission),
                    'maxMb'     => ApplicationDocuments::MAX_FILE_MB,
                ]);

            case ApplicationProgress::STEP_PAYMENT:
                return view('applicant.application.payment', $shared + [
                    'blockers'    => ApplicationProgress::blockers($admission),
                    'canSubmit'   => ApplicationProgress::canSubmit($admission),
                    'amount'      => ApplicationFee::amountFor($admission),
                    'methods'     => ApplicationFee::availableMethods($admission->school_id),
                    'bankDetails' => ApplicationFee::bankInstructions(),
                    'payments'    => $admission->payments()->latest('id')->get(),
                ]);

            case ApplicationProgress::STEP_REVIEW:
            default:
                return view('applicant.application.review', $shared + [
                    'blockers'      => ApplicationProgress::blockers($admission),
                    'canSubmit'     => ApplicationProgress::canSubmit($admission),
                    'docChecklist'  => ApplicationDocuments::checklist($admission),
                    'feeAmount'     => ApplicationFee::amountFor($admission),
                ]);
        }
    }

    // ── Step handlers ────────────────────────────────────────────────────

    public function savePersonal(Request $request)
    {
        $admission = $this->currentApplication();

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

        return $this->advance($request, $admission, ApplicationProgress::STEP_PERSONAL);
    }

    /**
     * Splits a stored "<dial code> <number>" phone value back into its two
     * parts for pre-filling the form's dial-code select + number input.
     * Historical values saved before this split existed (plain free-text
     * phone numbers) won't match any known dial code — they fall back to no
     * code selected and the whole stored value shown in the number field,
     * so nothing is silently dropped.
     */
    private static function splitPhone(?string $phone): array
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

    public function saveProgramme(Request $request)
    {
        $admission = $this->currentApplication();

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
            'study_mode'       => 'nullable|string|max:30',
            'sponsor_type'     => ['nullable', Rule::in(self::SPONSOR_TYPES)],
            'sponsor_name'     => 'nullable|string|max:150',
            'sponsor_phone'    => 'nullable|string|max:30',
            'sponsor_email'    => 'nullable|email|max:150',
            'how_did_you_hear' => ['nullable', Rule::in(self::HEARD_ABOUT)],
        ], [
            'second_choice_programme_id.different' => get_phrase('Your second choice must be a different programme.'),
            'intake_session_id.exists'             => get_phrase('That intake is no longer open for applications.'),
        ]);

        $intakeChanged = (int) $admission->intake_session_id !== (int) $validated['intake_session_id'];

        $admission->update($validated);

        // Changing intake can change the fee, and therefore whether the
        // payment step applies at all — recompute rather than leave the
        // cached fee status describing the old intake.
        if ($intakeChanged) {
            ApplicationFee::refreshStatus($admission->fresh());
        }

        return $this->advance($request, $admission, ApplicationProgress::STEP_PROGRAMME);
    }

    public function saveEducation(Request $request)
    {
        $admission = $this->currentApplication();

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

        // Replace wholesale: the form posts the complete list every time, so
        // diffing rows would only add a way for a deleted entry to survive.
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

        return $this->advance($request, $admission->fresh(), ApplicationProgress::STEP_EDUCATION);
    }

    // ── Submission ───────────────────────────────────────────────────────

    public function submit(Request $request)
    {
        $admission = $this->currentApplication();

        if ($redirect = $this->guardEditable($admission)) {
            return $redirect;
        }

        $request->validate([
            'declaration' => 'accepted',
        ], [
            'declaration.accepted' => get_phrase('Please confirm the declaration before submitting.'),
        ]);

        $admission->load(['programme', 'intakeSession']);

        if (! ApplicationWorkflow::submit($admission, $this->applicant())) {
            return back()->with('error', get_phrase('Your application is not complete yet. Please resolve the outstanding items listed below.'));
        }

        // Keep fee-paying applicants on the final step, where submission
        // unlocks the existing provider checkout without changing its rules.
        if (ApplicationFee::isRequired($admission) && ! ApplicationFee::isSettled($admission)) {
            return redirect()->route('applicant.application.step', ApplicationProgress::STEP_PAYMENT)
                ->with('success', get_phrase('Your application has been submitted. Complete your application fee payment below.'));
        }

        return redirect()->route('applicant.dashboard')
            ->with('success', get_phrase('Your application has been submitted. You can follow its progress under Track My Application.'));
    }

    /**
     * Where to go after a step saves: the next incomplete step when the
     * applicant clicked "Save & Continue", or back to the same step when they
     * clicked "Save". Draft-only — a submitted application returns to its
     * summary instead of walking the wizard again.
     */
    private function advance(Request $request, Admission $admission, string $currentStep)
    {
        $message = get_phrase('Saved.');

        if ($request->input('action') !== 'continue') {
            return back()->with('success', $message);
        }

        $admission = $admission->fresh();
        $steps     = ApplicationProgress::applicableSteps($admission);
        $keys      = array_column($steps, 'key');
        $position  = array_search($currentStep, $keys, true);

        $nextKey = $position !== false && isset($keys[$position + 1])
            ? $keys[$position + 1]
            : ApplicationProgress::STEP_REVIEW;

        return redirect()->route('applicant.application.step', $nextKey)->with('success', $message);
    }
}
