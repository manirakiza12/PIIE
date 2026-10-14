<?php

namespace App\Http\Controllers\Applicant;

use App\Models\AdmissionDocument;
use App\Models\School;
use App\Support\Admissions\ApplicationDocuments;
use App\Support\Admissions\ApplicationFee;
use App\Support\Admissions\ApplicationProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use PDF;

/**
 * The applicant's home in the portal: dashboard, application timeline,
 * account settings and the offer letter download.
 */
class PortalController extends BaseApplicantController
{
    public function dashboard()
    {
        $applicant = $this->applicant();
        $admission = $this->currentApplication();
        $admission->load(['programme', 'intakeSession', 'uploadedDocuments']);

        return view('applicant.dashboard', [
            'applicant'  => $applicant,
            'admission'  => $admission,
            'steps'      => ApplicationProgress::applicableSteps($admission),
            'percent'    => ApplicationProgress::percent($admission),
            'completed'  => ApplicationProgress::completedCount($admission),
            'total'      => ApplicationProgress::totalCount($admission),
            'nextStep'   => ApplicationProgress::nextStep($admission),
            'checklist'  => ApplicationProgress::checklist($admission),
            'recentDocs' => $admission->uploadedDocuments->sortByDesc('id')->take(5),
            'docCount'   => $admission->uploadedDocuments->count(),
            'feeAmount'  => ApplicationFee::amountFor($admission),
        ]);
    }

    /**
     * "Track My Application" — the applicant-visible slice of the timeline.
     * Internal transitions are filtered out here rather than at write time so
     * staff keep a complete history even when the applicant sees a summary.
     */
    public function track()
    {
        $admission = $this->currentApplication();
        $admission->load(['programme', 'intakeSession']);

        $events = $admission->statusEvents()
            ->where('is_visible_to_applicant', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $history = $this->applicant()->admissions()
            ->where('id', '!=', $admission->id)
            ->with('programme')
            ->orderByDesc('id')
            ->get();

        return view('applicant.track', [
            'admission' => $admission,
            'events'    => $events,
            'history'   => $history,
            'percent'   => ApplicationProgress::percent($admission),
        ]);
    }

    /**
     * Read-only rendering of everything submitted, for the applicant's own
     * records. Reuses the review-step partials so the summary can never drift
     * from what was actually sent.
     */
    public function summary()
    {
        $admission = $this->currentApplication();
        $admission->load(['programme', 'secondChoiceProgramme', 'intakeSession', 'educationHistory', 'uploadedDocuments', 'payments']);

        return view('applicant.summary', [
            'admission' => $admission,
            'checklist' => ApplicationDocuments::checklist($admission),
            'feeAmount' => ApplicationFee::amountFor($admission),
        ]);
    }

    /**
     * The offer letter, available to the applicant only once an offer has
     * actually been made. Rendered from the same Blade the admissions office
     * prints from, so both sides issue an identical document.
     */
    public function offerLetter()
    {
        $admission = $this->currentApplication();

        if (! in_array($admission->status, ['accepted', 'enrolled'], true)) {
            return redirect()->route('applicant.dashboard')
                ->with('error', get_phrase('Your offer letter will be available here once a decision has been made.'));
        }

        $admission->load(['programme', 'intakeSession']);
        $letter = \App\Support\Admissions\AdmissionLetterDocument::forAdmission($admission);
        return \App\Support\Admissions\AdmissionLetterDocument::pdf($letter)->download(
            \App\Support\Admissions\AdmissionLetterDocument::filename($letter));
    }

    /** Inline viewing of the applicant's own uploads. */
    public function viewDocument(int $id)
    {
        $admission = $this->currentApplication();

        $document = AdmissionDocument::where('admission_id', $admission->id)->findOrFail($id);

        if (! is_file($document->absolute_path)) {
            abort(404);
        }

        return response()->file($document->absolute_path);
    }

    // ── Account settings ─────────────────────────────────────────────────

    public function profile()
    {
        return view('applicant.profile', ['applicant' => $this->applicant()]);
    }

    public function updateProfile(Request $request)
    {
        $applicant = $this->applicant();

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => 'required|string|max:30',
            'email'      => [
                'required', 'email', 'max:150',
                Rule::unique('applicants', 'email')
                    ->where(fn ($q) => $q->where('school_id', $applicant->school_id))
                    ->ignore($applicant->id),
            ],
        ]);

        $applicant->update($validated);

        // A draft still mirrors the account details; a submitted application
        // is a record of what was sent and is left exactly as it was.
        $admission = $this->currentApplication();

        if ($admission->isDraft()) {
            $admission->update([
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $validated['email'],
                'phone'      => $validated['phone'],
            ]);
        }

        return back()->with('success', get_phrase('Your details have been updated.'));
    }

    public function updatePassword(Request $request)
    {
        $applicant = $this->applicant();

        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($request->current_password, $applicant->password)) {
            return back()->with('error', get_phrase('Your current password is not correct.'));
        }

        $applicant->forceFill(['password' => Hash::make($request->password)])->save();

        return back()->with('success', get_phrase('Your password has been changed.'));
    }
}
