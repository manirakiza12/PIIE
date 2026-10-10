<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\AdmissionAgent;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentRequirement;
use App\Models\ApplicationPayment;
use App\Models\AuditLog;
use App\Models\Classes;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\IntakeSession;
use App\Models\Programme;
use App\Models\School;
use App\Models\Session;
use App\Models\User;
use App\Support\Admissions\ApplicantNotifier;
use App\Support\Admissions\ApplicantPortalAccess;
use App\Support\Admissions\ApplicationDocuments;
use App\Support\Admissions\ApplicationFee;
use App\Support\Admissions\ApplicationProgress;
use App\Support\Admissions\ApplicationReference;
use App\Support\Admissions\ApplicationWorkflow;
use App\Support\StudentPortalActivation;
use App\Support\StudentProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use PDF;

class AdmissionsController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;

            // Admissions/Intake Sessions/Agents only apply to the one school
            // the public Apply Now portal currently belongs to — block
            // direct route access for every other school, not just hide the
            // nav item (see resources/views/admin/navigation.blade.php and
            // layouts/app.blade.php for the matching nav-level gate).
            if (! is_primary_school($this->school_id)) {
                return redirect()->route('admin.dashboard')->with('error', get_phrase('Admissions is not available for your school.'));
            }

            return $next($request);
        });
    }

    // ── Applications ──────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $search               = $request->search ?? '';
        $status               = $request->status ?? '';
        $session_id           = $request->session_id ?? '';
        $source               = $request->source ?? '';
        $fee_status           = $request->fee_status ?? '';
        $has_pending_documents = $request->boolean('has_pending_documents');

        $admissions = Admission::where('school_id', $this->school_id)
            // Drafts are applications nobody has submitted yet. They are
            // excluded unless explicitly asked for, so the queue reflects
            // work to be done rather than half-typed forms.
            ->when($status !== Admission::STATUS_DRAFT, fn($q) => $q->submittedOnly())
            ->when($search, fn($q) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%$search%")
                  ->orWhere('last_name', 'like', "%$search%")
                  ->orWhere('app_number', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%");
            }))
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($session_id, fn($q) => $q->where('intake_session_id', $session_id))
            ->when($source, fn($q) => $q->where('source', $source))
            ->when($fee_status, fn($q) => $q->where('fee_status', $fee_status))
            ->when($has_pending_documents, fn($q) => $q->whereHas('uploadedDocuments', fn($d) => $d->where('status', AdmissionDocument::STATUS_PENDING)))
            ->with(['programme', 'intakeSession'])
            ->latest()
            ->paginate(20);

        $sessions  = IntakeSession::where('school_id', $this->school_id)->orderByDesc('id')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();

        // Derived portal-activation status per row (no schema duplication):
        // looked up by email against this school's student accounts only.
        $applicantEmails = $admissions->pluck('email')->filter()->unique()->values();
        $studentsByEmail = User::where('school_id', $this->school_id)
            ->where('role_id', 7)
            ->whereIn('email', $applicantEmails)
            ->get(['id', 'email', 'force_password_change'])
            ->keyBy(fn ($u) => strtolower($u->email));

        // Headline counts are for the whole school, not the current page —
        // "12 under review" must not change when a filter is applied.
        $counts = Admission::where('school_id', $this->school_id)
            ->submittedOnly()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $draftCount = Admission::where('school_id', $this->school_id)
            ->where('status', Admission::STATUS_DRAFT)
            ->count();

        return view('admin.admissions.index', compact(
            'admissions', 'sessions', 'programmes', 'search', 'status',
            'session_id', 'source', 'fee_status', 'studentsByEmail', 'counts', 'draftCount'
        ));
    }

    // ── Application review ────────────────────────────────────────────────

    /**
     * The full review screen: everything the applicant supplied, the document
     * checklist with per-file verify/reject, the fee, and the decision
     * controls — on one page, so a reviewer never has to reconstruct an
     * application from a list row and a modal.
     */
    public function review($id)
    {
        $admission = Admission::where('school_id', $this->school_id)
            ->with([
                'programme', 'secondChoiceProgramme', 'intakeSession', 'applicant',
                'educationHistory', 'uploadedDocuments', 'payments', 'reviewer',
            ])
            ->findOrFail($id);

        // Already-enrolled applications show their existing academic
        // assignment read-only instead of the Step 6 form — the conversion
        // already happened, so this is what actually landed, not a proposal.
        $enrolledStudent   = User::where('school_id', $this->school_id)->where('email', $admission->email)->where('role_id', 7)->first();
        $existingEnrolment = $enrolledStudent ? Enrollment::where('user_id', $enrolledStudent->id)->first() : null;
        $cohortPlacementPending = false;
        if ($enrolledStudent && Schema::hasTable('programme_cohort_memberships')) {
            $cohortPlacementPending = ! DB::table('programme_cohort_memberships')
                ->where('school_id', $this->school_id)
                ->where('student_id', $enrolledStudent->id)
                ->where('admission_id', $admission->id)
                ->whereNull('ended_at')
                ->exists();
        }

        $feeAmount = ApplicationFee::amountFor($admission);
        $feePaid   = (float) $admission->payments->whereIn('status', [ApplicationPayment::STATUS_PAID, ApplicationPayment::STATUS_WAIVED])->sum('amount');

        return view('admin.admissions.review', [
            'admission'         => $admission,
            'docChecklist'      => ApplicationDocuments::checklist($admission),
            'timeline'          => $admission->statusEvents()->orderBy('created_at')->orderBy('id')->get(),
            'feeAmount'         => $feeAmount,
            'feePaid'           => $feePaid,
            'feeOutstanding'    => max(0, $feeAmount - $feePaid),
            'progress'          => ApplicationProgress::percent($admission),
            'blockers'          => ApplicationProgress::blockers($admission),
            'statuses'          => Admission::STAFF_SETTABLE_STATUSES,
            'cohortPlacementPending' => $cohortPlacementPending,
            // Step 6 — Admission & Enrollment (academic assignment).
            'classes'           => Classes::where('school_id', $this->school_id)->orderBy('name')->get(),
            'departments'       => Department::where('school_id', $this->school_id)->orderBy('name')->get(),
            'academicSessions'  => Session::where('school_id', $this->school_id)->orderByDesc('id')->get(),
            'existingEnrolment' => $existingEnrolment,
        ]);
    }

    /**
     * Verify or reject a single uploaded document.
     *
     * Rejection requires a reason: an applicant told only "not accepted" has
     * no way to fix it, and will simply re-upload the same file.
     */
    public function reviewDocument(Request $request, $id)
    {
        $document = AdmissionDocument::where('school_id', $this->school_id)->findOrFail($id);

        $validated = $request->validate([
            'status'      => ['required', Rule::in([AdmissionDocument::STATUS_VERIFIED, AdmissionDocument::STATUS_REJECTED, AdmissionDocument::STATUS_PENDING])],
            'review_note' => 'nullable|string|max:500',
        ]);

        if ($validated['status'] === AdmissionDocument::STATUS_REJECTED && blank($validated['review_note'] ?? null)) {
            return back()->with('error', get_phrase('Please say why the document was not accepted so the applicant knows what to fix.'));
        }

        $document->update([
            'status'      => $validated['status'],
            'review_note' => $validated['review_note'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        AuditLog::record('update', 'Admissions', "Document '{$document->original_name}' marked {$document->status} on application #{$document->admission_id}.", [
            'event_type'  => 'DATA',
            'record_type' => AdmissionDocument::class,
            'record_id'   => $document->id,
            'school_id'   => $this->school_id,
        ]);

        if ($validated['status'] === AdmissionDocument::STATUS_REJECTED) {
            ApplicantNotifier::documentRejected($document->admission, $document);
        }

        return back()->with('success', get_phrase('Document updated.'));
    }

    /** Hands a submitted application back to the applicant for fixes. */
    public function requestCorrection(Request $request, $id)
    {
        $admission = Admission::where('school_id', $this->school_id)->submittedOnly()->findOrFail($id);

        $validated = $request->validate([
            'correction_note' => 'required|string|max:2000',
        ], [
            'correction_note.required' => get_phrase('Explain what the applicant needs to change.'),
        ]);

        $admission->load(['programme', 'intakeSession']);

        ApplicationWorkflow::requestCorrection($admission, Auth::user(), $validated['correction_note']);

        return redirect()->route('admin.hei_admissions.review', $admission->id)
            ->with('success', get_phrase('The application has been returned to the applicant.'));
    }

    /** Internal notes — never shown to the applicant. */
    public function saveNotes(Request $request, $id)
    {
        $admission = Admission::where('school_id', $this->school_id)->findOrFail($id);

        $validated = $request->validate(['notes' => 'nullable|string|max:5000']);

        $admission->update(['notes' => $validated['notes'] ?? null]);

        AuditLog::record('update', 'Admissions', "Internal notes updated on application {$admission->app_number}.", [
            'record_type' => Admission::class,
            'record_id'   => $admission->id,
            'school_id'   => $this->school_id,
        ]);

        return back()->with('success', get_phrase('Notes saved.'));
    }

    // ── Application fee ───────────────────────────────────────────────────

    /**
     * Confirm, reject or waive an application-fee payment.
     *
     * The admission's fee_status is never written here directly — it is
     * recomputed from the payment rows by ApplicationFee::refreshStatus() so
     * the cached column can't disagree with the ledger it summarises.
     */
    public function paymentProof($id)
    {
        $payment = ApplicationPayment::where('school_id', $this->school_id)->findOrFail($id);
        abort_unless($payment->admission()->where('school_id', $this->school_id)->exists(), 404);
        return \App\Support\Payments\ApplicationPaymentProof::download($payment);
    }

    public function reviewPayment(Request $request, $id)
    {
        $payment = ApplicationPayment::where('school_id', $this->school_id)->findOrFail($id);
        abort_unless($payment->method === 'offline', 422, 'Online payments require provider verification. Use the explicit fee waiver action for waivers.');

        $validated = $request->validate([
            'status' => ['required', Rule::in([ApplicationPayment::STATUS_PAID, ApplicationPayment::STATUS_REJECTED])],
            'note'   => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($payment, $validated) {
        $payment = ApplicationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
        abort_unless($payment->status === ApplicationPayment::STATUS_PENDING, 422, 'Only a pending offline payment can be reviewed.');
        $admission = Admission::whereKey($payment->admission_id)->lockForUpdate()->firstOrFail();
        abort_if($admission->payments()->where('status', 'pending')->whereIn('method', ['pesapal', 'marzpay', 'stripe', 'flutterwave'])->exists(), 422, 'Resolve the outstanding online payment first.');
        $payment->update([
            'status'       => $validated['status'],
            'note'         => $validated['note'] ?? $payment->note,
            'confirmed_by' => Auth::id(),
            'paid_at'      => in_array($validated['status'], [ApplicationPayment::STATUS_PAID, ApplicationPayment::STATUS_WAIVED], true) ? now() : null,
        ]);

        $admission = $payment->admission;
        ApplicationFee::refreshStatus($admission);

        AuditLog::record('update', 'Admissions', "Application fee payment #{$payment->id} marked {$payment->status} for {$admission->app_number}.", [
            'event_type'  => 'DATA',
            'record_type' => ApplicationPayment::class,
            'record_id'   => $payment->id,
            'school_id'   => $this->school_id,
        ]);

        if ($validated['status'] === ApplicationPayment::STATUS_REJECTED) {
            ApplicantNotifier::paymentRejected($admission, $payment);
        } else {
            ApplicantNotifier::paymentReceived($admission, $payment);
        }
        });

        return back()->with('success', get_phrase('Payment updated.'));
    }

    /**
     * "Send Payment Request" / "Resend Payment Instructions" — the one
     * action that gets a staff-entry candidate (or any candidate who has
     * not yet acted on their own payment step) into the applicant portal to
     * pay. Never creates a payment record itself and never touches
     * fee_status; it only issues (or reissues) portal access and emails the
     * same reference/amount every time. Reusable for online applicants too
     * — e.g. one who has not checked their email — not just staff-entry.
     */
    public function sendPaymentRequest($id)
    {
        $admission = Admission::where('school_id', $this->school_id)->findOrFail($id);
        if (! \App\Support\Payments\ApplicantPesaPalPayment::eligible($admission)
            || ! ApplicationFee::gatewayIsConfigured('pesapal', $this->school_id)) {
            return back()->with('error', 'Submit the application and configure PesaPal before sending a payment link.');
        }

        if (! ApplicationFee::isRequired($admission)) {
            return back()->with('error', get_phrase('No application fee is payable for this intake.'));
        }

        if ($admission->isFeeSettled()) {
            return back()->with('error', get_phrase('This application fee is already settled.'));
        }

        if (blank($admission->email)) {
            return back()->with('error', get_phrase('This application has no email address yet — add one on the Personal Information step first.'));
        }

        // Rate-limited per admission, not globally, so one applicant
        // hammering "resend" cannot affect anyone else's ability to send a
        // request, and a genuinely different reviewer sending it for a
        // different application is never blocked by someone else's resend.
        $throttleKey = 'admission-payment-request:' . $admission->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->with('error', get_phrase('Payment instructions were already sent recently. Please wait') . ' ' . ceil($seconds / 60) . ' ' . get_phrase('minute(s) before resending.'));
        }

        // Existing applicant_id means this is at least the second time
        // access has been issued — that is what makes the email a
        // "reminder" rather than a first "an application has been created
        // for you" notice, regardless of which admin triggers it.
        $isReminder = (bool) $admission->applicant_id;

        $applicant = ApplicantPortalAccess::ensureLinked($admission);
        $portalUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('applicant.pesapal.invitation', now()->addHour(), ['admission' => $admission->id]);

        $sent = ApplicantNotifier::staffEntryPaymentRequest($admission, $portalUrl, $isReminder, ApplicantPortalAccess::paymentLinkFor($applicant));

        RateLimiter::hit($throttleKey, 120); // 2 minutes

        AuditLog::record('update', 'Admissions', ($isReminder ? "Payment reminder resent" : "Payment request sent") . " for {$admission->app_number}.", [
            'event_type'  => 'ACTION',
            'record_type' => Admission::class,
            'record_id'   => $admission->id,
            'school_id'   => $this->school_id,
        ]);

        return back()->with($sent ? 'success' : 'error', $sent
            ? get_phrase('Payment instructions sent to') . ' ' . $admission->email . '.'
            : get_phrase('Portal access was created, but the email could not be sent — check the mail configuration under Super Admin > Settings.'));
    }

    public function checkPesaPalPayment(Request $request, $id)
    {
        $payment = ApplicationPayment::where('school_id', $this->school_id)->where('method', 'pesapal')->findOrFail($id);
        $request->validate(['order_tracking_id' => 'nullable|uuid']);
        try {
            $result = \App\Support\Payments\ApplicantPesaPalPayment::reconcile($payment, $request->input('order_tracking_id'));
            return back()->with('success', 'PesaPal verification: ' . $result);
        } catch (\App\Support\Payments\PesaPalException $exception) {
            return back()->with('error', 'PesaPal verification is unavailable or the evidence did not match. No credit was assigned.');
        }
    }

    /**
     * Option D — an authorized user records a payment that happened
     * entirely outside the system (cash at the front desk, a bank transfer
     * with no applicant-submitted proof). Always creates a new, fully
     * attributed ApplicationPayment row — fee_status is never hand-set,
     * only ever derived by ApplicationFee::refreshStatus() from rows like
     * this one, exactly as every other payment path already works.
     */
    public function recordPayment(Request $request, $id)
    {
        $admission = Admission::where('school_id', $this->school_id)->findOrFail($id);

        $validated = $request->validate([
            'amount'                => 'required|numeric|min:0.01',
            'method'                => ['required', Rule::in(['offline', 'cash', 'bank', 'bank_transfer', 'mobile_money', 'cheque', 'other'])],
            'external_reference'    => 'nullable|string|max:191',
            'paid_at'               => 'required|date',
            'note'                  => 'nullable|string|max:500',
        ]);

        $payment = DB::transaction(function () use ($admission, $validated) {
        $admission = Admission::whereKey($admission->id)->where('school_id', $this->school_id)->lockForUpdate()->firstOrFail();
        ApplicationFee::refreshStatus($admission);
        abort_if($admission->isFeeSettled(), 422, 'This fee is already settled.');
        abort_if($admission->payments()->where('status', 'pending')->whereIn('method', ['pesapal', 'marzpay', 'stripe', 'flutterwave'])->exists(), 422, 'Resolve the outstanding online payment before recording an offline payment.');
        $payment = ApplicationPayment::create([
            'school_id'       => $this->school_id,
            'admission_id'    => $admission->id,
            'applicant_id'    => $admission->applicant_id,
            'amount'          => $validated['amount'],
            'currency'        => ApplicationFee::currencyFor($admission),
            'method'          => $validated['method'],
            'status'          => ApplicationPayment::STATUS_PAID,
            'reference'       => 'MANUAL-' . $admission->id . '-' . strtoupper(\Illuminate\Support\Str::random(6)),
            'gateway_txn_id'  => $validated['external_reference'] ?? null,
            'note'            => $validated['note'] ?? null,
            'confirmed_by'    => Auth::id(),
            'paid_at'         => $validated['paid_at'],
        ]);
        ApplicationFee::refreshStatus($admission);

        AuditLog::record('create', 'Admissions', "Manual application-fee payment recorded for {$admission->app_number} ({$validated['method']}, " . ApplicationFee::format((float) $validated['amount']) . ").", [
            'event_type'  => 'DATA',
            'record_type' => ApplicationPayment::class,
            'record_id'   => $payment->id,
            'school_id'   => $this->school_id,
        ]);

        ApplicantNotifier::paymentReceived($admission, $payment);
        return $payment;
        });

        return back()->with('success', get_phrase('Payment recorded.'));
    }

    /**
     * Option E — an explicit fee waiver. Distinguishable from a payment at
     * every layer: its own ApplicationPayment row (method='waived', no
     * amount collected) so fee_status=waived vs fee_status=paid never
     * collapse into the same evidence trail, and a mandatory reason on the
     * audit record — "waived" with no explanation is not an audit record,
     * it is just a status flip.
     */
    public function waiveFee(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ], [
            'reason.required' => get_phrase('Please state why this fee is being waived.'),
        ]);

        // Locked for the duration of the check-then-create so two
        // near-simultaneous submissions (a double-click, or one admin
        // waiving while another records a payment) can't both pass the
        // "not already settled" check before either has written — the
        // second request always sees the first one's result.
        $payment = \Illuminate\Support\Facades\DB::transaction(function () use ($id, $validated) {
            $admission = Admission::where('school_id', $this->school_id)->lockForUpdate()->findOrFail($id);

            if ($admission->isFeeSettled()) {
                return null;
            }
            abort_if($admission->payments()->where('status', 'pending')->whereIn('method', ['pesapal', 'marzpay', 'stripe', 'flutterwave'])->exists(), 422, 'Resolve the outstanding online payment before waiving this fee.');

            $payment = ApplicationPayment::create([
                'school_id'    => $this->school_id,
                'admission_id' => $admission->id,
                'applicant_id' => $admission->applicant_id,
                'amount'       => ApplicationFee::amountFor($admission),
                'currency'     => ApplicationFee::currencyFor($admission),
                'method'       => 'waived',
                'status'       => ApplicationPayment::STATUS_WAIVED,
                'reference'    => 'WAIVER-' . $admission->id . '-' . strtoupper(\Illuminate\Support\Str::random(6)),
                'note'         => $validated['reason'],
                'confirmed_by' => Auth::id(),
                'paid_at'      => now(),
            ]);

            ApplicationFee::refreshStatus($admission);

            return $payment;
        });

        if (! $payment) {
            return back()->with('error', get_phrase('This application fee is already settled.'));
        }

        AuditLog::record('create', 'Admissions', "Application fee waived for {$payment->admission->app_number}: {$validated['reason']}", [
            'event_type'  => 'DATA',
            'record_type' => ApplicationPayment::class,
            'record_id'   => $payment->id,
            'school_id'   => $this->school_id,
        ]);

        return back()->with('success', get_phrase('Application fee waived.'));
    }

    // ── Document requirements ─────────────────────────────────────────────

    public function documentRequirements()
    {
        $requirements = AdmissionDocumentRequirement::where('school_id', $this->school_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.admissions.document_requirements', [
            'requirements' => $requirements,
            'levels'       => array_merge(Programme::LEVELS, Programme::LEVELS_LEGACY),
            'usingDefaults'=> $requirements->isEmpty(),
        ]);
    }

    public function storeDocumentRequirement(Request $request, $id = null)
    {
        $requirement = $id
            ? AdmissionDocumentRequirement::where('school_id', $this->school_id)->findOrFail($id)
            : null;

        $validated = $request->validate([
            // The key is what existing uploads are filed under, so it is set
            // once at creation and never editable afterwards.
            'key' => [
                $requirement ? 'nullable' : 'required',
                'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('admission_document_requirements', 'key')
                    ->where(fn ($q) => $q->where('school_id', $this->school_id))
                    ->ignore($requirement?->id),
            ],
            'label'              => 'required|string|max:150',
            'description'        => 'nullable|string|max:500',
            'applies_to_levels'  => 'nullable|array',
            'applies_to_levels.*'=> 'string|max:40',
            'sort_order'         => 'nullable|integer|min:0|max:999',
        ], [
            'key.regex' => get_phrase('The key may only contain lowercase letters, numbers and underscores.'),
        ]);

        $payload = [
            'school_id'         => $this->school_id,
            'label'             => $validated['label'],
            'description'       => $validated['description'] ?? null,
            'applies_to_levels' => empty($validated['applies_to_levels']) ? null : array_values($validated['applies_to_levels']),
            'sort_order'        => $validated['sort_order'] ?? 0,
            'is_required'       => $request->boolean('is_required'),
            'allow_multiple'    => $request->boolean('allow_multiple'),
            'is_active'         => $request->boolean('is_active'),
        ];

        if ($requirement) {
            $requirement->update($payload);
        } else {
            $requirement = AdmissionDocumentRequirement::create($payload + ['key' => $validated['key']]);
        }

        AuditLog::record($id ? 'update' : 'create', 'Admissions', "Document requirement '{$requirement->label}' saved.", [
            'record_type' => AdmissionDocumentRequirement::class,
            'record_id'   => $requirement->id,
            'school_id'   => $this->school_id,
        ]);

        return back()->with('success', get_phrase('Document requirement saved.'));
    }

    public function destroyDocumentRequirement($id)
    {
        $requirement = AdmissionDocumentRequirement::where('school_id', $this->school_id)->findOrFail($id);

        // Files already filed under this key are kept and still shown on the
        // review screen (see ApplicationDocuments::checklist) — deleting the
        // requirement must not destroy evidence an applicant supplied.
        $requirement->delete();

        AuditLog::record('delete', 'Admissions', "Document requirement '{$requirement->label}' removed.", [
            'record_type' => AdmissionDocumentRequirement::class,
            'record_id'   => $requirement->id,
            'school_id'   => $this->school_id,
        ]);

        return back()->with('success', get_phrase('Document requirement removed.'));
    }

    /** Installs the built-in requirement set for schools that have none. */
    public function restoreDefaultDocumentRequirements()
    {
        foreach (AdmissionDocumentRequirement::DEFAULTS as $index => $definition) {
            AdmissionDocumentRequirement::updateOrCreate(
                ['school_id' => $this->school_id, 'key' => $definition['key']],
                array_merge($definition, ['school_id' => $this->school_id, 'sort_order' => $index, 'is_active' => true])
            );
        }

        AuditLog::record('create', 'Admissions', 'Default document requirements restored.', [
            'school_id' => $this->school_id,
        ]);

        return back()->with('success', get_phrase('Default document requirements have been installed.'));
    }

    public function openModal(Request $request)
    {
        $id         = $request->id;
        $admission  = $id ? Admission::where('school_id', $this->school_id)->findOrFail($id) : null;
        $sessions   = IntakeSession::where('school_id', $this->school_id)->where('is_open', 1)->orderByDesc('id')->get();
        $programmes = Programme::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        $agents     = AdmissionAgent::where('school_id', $this->school_id)->where('is_active', 1)->orderBy('name')->get();
        return view('admin.admissions.modal', compact('admission', 'sessions', 'programmes', 'agents'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'         => 'required|max:100',
            'last_name'          => 'required|max:100',
            'email'              => 'nullable|email|max:150',
            'phone'              => 'nullable|max:20',
            'intake_session_id'  => ['nullable', Rule::exists('intake_sessions', 'id')->where('school_id', $this->school_id)],
            'programme_id'       => ['nullable', Rule::exists('programmes', 'id')->where('school_id', $this->school_id)],
            'gender'             => 'nullable|max:10',
            'dob'                => 'nullable|date',
            'nationality'        => 'nullable|max:80',
            'qualifications'     => 'nullable|string',
            'agent_id'           => ['nullable', Rule::exists('admissions_agents', 'id')->where('school_id', $this->school_id)],
        ]);

        $validated['school_id']    = $this->school_id;
        $validated['app_number']   = ApplicationReference::generate($this->school_id, 'staff_entry', $validated['intake_session_id'] ?? null);
        $validated['status']       = Admission::STATUS_DRAFT;
        $validated['source']       = 'staff_entry';
        $validated['submitted_at'] = null;

        $admission = DB::transaction(function () use ($validated) {
            $admission = Admission::create($validated);
            ApplicationFee::refreshStatus($admission);
            ApplicationWorkflow::submitByStaff($admission, Auth::user());
            return $admission;
        });
        AuditLog::record('create', 'Admissions', "New application: {$admission->app_number} — {$admission->first_name} {$admission->last_name}");
        return response()->json(['status' => 'success', 'message' => get_phrase('Application created successfully')]);
    }

    public function updateStatus(Request $request, $id)
    {
        $admission = Admission::where('school_id', $this->school_id)->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', Rule::in(Admission::STAFF_SETTABLE_STATUSES)],
            // Shown to the applicant alongside the new status.
            'decision_note' => 'nullable|required_if:status,needs_correction|string|max:2000',
            // Optional administrator-chosen portal password when enrolling —
            // if omitted, a password is generated automatically.
            'password' => 'nullable|string|min:6',
            // Academic assignment (Step 6 — Admission & Enrollment). Admin-only,
            // never applicant-supplied. class_id/section_id/session_id are
            // required together; department_id is deliberately excluded from
            // that group — it falls back to the chosen programme's
            // department below when omitted, so a reviewer only has to
            // override it, not always supply it.
            'class_id'      => ['nullable', 'required_with:section_id,session_id', Rule::exists('classes', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id))],
            'section_id'    => ['nullable', 'required_with:class_id,session_id', Rule::exists('sections', 'id')->where(fn ($q) => $q->where('class_id', $request->class_id))],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id))],
            'session_id'    => ['nullable', 'required_with:class_id,section_id', Rule::exists('sessions', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id))],
        ]);

        return DB::transaction(function () use ($admission, $request, $validated) {
        $admission = Admission::whereKey($admission->id)->where('school_id', $this->school_id)->lockForUpdate()->firstOrFail();

        $admission->load(['programme', 'intakeSession']);

        // The workflow writes the status, the applicant-visible timeline entry
        // and the audit record together, and sends the notification — see
        // App\Support\Admissions\ApplicationWorkflow for why that is one step.
        abort_unless(ApplicationWorkflow::canTransition($admission, $request->status), 422, 'This admission transition is not eligible. Complete the application and settle its fee before acceptance or enrolment.');

        // If enrolled, convert the application into a student account.
        // Provision first within this transaction. Retrying an already-enrolled
        // application repairs historical partial conversion idempotently.
        if ($request->status === Admission::STATUS_ENROLLED) {
            $this->createStudentFromAdmission($admission, $request->password, [
                'class_id'      => $validated['class_id'] ?? null,
                'section_id'    => $validated['section_id'] ?? null,
                // Falls back to the chosen programme's department when the
                // reviewer didn't override it — the common case is "confirm",
                // not "hunt for a department" on every enrolment.
                'department_id' => $validated['department_id'] ?? optional($admission->programme)->department_id,
                'session_id'    => $validated['session_id'] ?? null,
            ]);
        }
        ApplicationWorkflow::transitionByStaff($admission, $request->status, Auth::user(), $request->decision_note);

        return redirect()->back()->with('success', get_phrase('Status updated'));
        });
    }

    public function destroy($id)
    {
        $admission = Admission::where('school_id', $this->school_id)->findOrFail($id);
        AuditLog::record('delete', 'Admissions', "Deleted application: {$admission->app_number}");
        $admission->delete();
        return redirect()->back()->with('success', get_phrase('Application deleted'));
    }

    public function printOfferLetter($id)
    {
        $admission  = Admission::where('school_id', $this->school_id)->with(['programme', 'intakeSession'])->findOrFail($id);
        $school     = School::find($this->school_id);
        $pdf        = PDF::loadView('admin.admissions.offer_letter', compact('admission', 'school'));
        return $pdf->download("OfferLetter_{$admission->app_number}.pdf");
    }

    // ── Intake Sessions ────────────────────────────────────────────────────

    public function sessions(Request $request)
    {
        $sessions = IntakeSession::where('school_id', $this->school_id)->orderByDesc('id')->paginate(20);
        return view('admin.admissions.sessions', compact('sessions'));
    }

    public function openSessionModal(Request $request)
    {
        $id      = $request->id;
        $session = $id ? IntakeSession::where('school_id', $this->school_id)->findOrFail($id) : null;
        return view('admin.admissions.session_modal', compact('session'));
    }

    public function storeSession(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|max:100',
            'open_date'       => 'nullable|date',
            'close_date'      => 'nullable|date',
            'application_fee' => 'nullable|numeric|min:0',
        ]);
        $validated['school_id'] = $this->school_id;
        $validated['is_open']   = $request->has('is_open') ? 1 : 0;
        IntakeSession::create($validated);
        AuditLog::record('create', 'Admissions', "Created intake session: {$validated['name']}");
        return redirect()->back()->with('success', get_phrase('Intake session created'));
    }

    public function updateSession(Request $request, $id)
    {
        $session   = IntakeSession::where('school_id', $this->school_id)->findOrFail($id);
        $validated = $request->validate([
            'name'            => 'required|max:100',
            'open_date'       => 'nullable|date',
            'close_date'      => 'nullable|date',
            'application_fee' => 'nullable|numeric|min:0',
        ]);
        $validated['is_open'] = $request->has('is_open') ? 1 : 0;
        $old = $session->only(array_keys($validated));
        $session->update($validated);
        AuditLog::record('update', 'Admissions', "Updated intake session: {$session->name}", [
            'record_type' => IntakeSession::class,
            'record_id'   => $session->id,
            'old_values'  => $old,
            'new_values'  => $session->only(array_keys($validated)),
        ]);
        return redirect()->back()->with('success', get_phrase('Session updated'));
    }

    public function destroySession($id)
    {
        $session = IntakeSession::where('school_id', $this->school_id)->findOrFail($id);
        AuditLog::record('delete', 'Admissions', "Deleted intake session: {$session->name}", [
            'record_type' => IntakeSession::class,
            'record_id'   => $session->id,
        ]);
        $session->delete();
        return redirect()->back()->with('success', get_phrase('Session deleted'));
    }

    // ── Agents ────────────────────────────────────────────────────────────

    public function agents(Request $request)
    {
        $agents = AdmissionAgent::where('school_id', $this->school_id)->orderBy('name')->paginate(20);
        return view('admin.admissions.agents', compact('agents'));
    }

    public function openAgentModal(Request $request)
    {
        $id    = $request->id;
        $agent = $id ? AdmissionAgent::where('school_id', $this->school_id)->findOrFail($id) : null;
        return view('admin.admissions.agent_modal', compact('agent'));
    }

    public function storeAgent(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|max:150',
            'email'          => 'nullable|email|max:150',
            'phone'          => 'nullable|max:20',
            'commission_pct' => 'nullable|numeric|min:0|max:100',
        ]);
        $validated['school_id'] = $this->school_id;
        $validated['is_active'] = 1;
        $agent = AdmissionAgent::create($validated);
        AuditLog::record('create', 'Agents', "Created admissions agent: {$agent->name}", [
            'record_type' => AdmissionAgent::class,
            'record_id'   => $agent->id,
            'new_values'  => $validated,
        ]);
        return response()->json(['status' => 'success', 'message' => get_phrase('Agent created')]);
    }

    public function updateAgent(Request $request, $id)
    {
        $agent     = AdmissionAgent::where('school_id', $this->school_id)->findOrFail($id);
        $validated = $request->validate([
            'name'           => 'required|max:150',
            'email'          => 'nullable|email|max:150',
            'phone'          => 'nullable|max:20',
            'commission_pct' => 'nullable|numeric|min:0|max:100',
        ]);
        $validated['is_active'] = $request->has('is_active') ? 1 : 0;
        $old = $agent->only(array_keys($validated));
        $agent->update($validated);
        AuditLog::record('update', 'Agents', "Updated admissions agent: {$agent->name}", [
            'record_type' => AdmissionAgent::class,
            'record_id'   => $agent->id,
            'old_values'  => $old,
            'new_values'  => $agent->only(array_keys($validated)),
        ]);
        return response()->json(['status' => 'success', 'message' => get_phrase('Agent updated')]);
    }

    public function destroyAgent($id)
    {
        $agent = AdmissionAgent::where('school_id', $this->school_id)->findOrFail($id);
        AuditLog::record('delete', 'Agents', "Deleted admissions agent: {$agent->name}", [
            'record_type' => AdmissionAgent::class,
            'record_id'   => $agent->id,
        ]);
        $agent->delete();
        return redirect()->back()->with('success', get_phrase('Agent deleted'));
    }

    // ── Exports ────────────────────────────────────────────────────────────

    public function exportApplicationsCsv(Request $request)
    {
        $search     = $request->search ?? '';
        $status     = $request->status ?? '';
        $session_id = $request->session_id ?? '';
        $source     = $request->source ?? '';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="admissions_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($search, $status, $session_id, $source) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'App number', 'Name', 'Email', 'Phone', 'Programme', 'Intake session', 'Status', 'Source', 'Offer date']);
            Admission::where('school_id', $this->school_id)
                ->when($search, fn($q) => $q->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%$search%")
                      ->orWhere('last_name', 'like', "%$search%")
                      ->orWhere('app_number', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%");
                }))
                ->when($status, fn($q) => $q->where('status', $status))
                ->when($session_id, fn($q) => $q->where('intake_session_id', $session_id))
                ->when($source, fn($q) => $q->where('source', $source))
                ->with(['programme', 'intakeSession'])
                ->latest()
                ->get()
                ->each(function ($a, $i) use ($out) {
                    fputcsv($out, [
                        $i+1,
                        $a->app_number,
                        trim("{$a->first_name} {$a->last_name}"),
                        $a->email,
                        $a->phone,
                        optional($a->programme)->name,
                        optional($a->intakeSession)->name,
                        ucfirst(str_replace('_', ' ', $a->status)),
                        $a->source === 'public' ? 'Public Application' : 'Staff Entry',
                        $a->offer_date,
                    ]);
                });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportSessionsCsv()
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="intake_sessions_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'Name', 'Open date', 'Close date', 'Application fee', 'Status']);
            IntakeSession::where('school_id', $this->school_id)
                ->orderByDesc('id')
                ->get()
                ->each(function ($s, $i) use ($out) {
                    fputcsv($out, [$i+1, $s->name, $s->open_date, $s->close_date, $s->application_fee, $s->is_open ? 'Open' : 'Closed']);
                });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportAgentsCsv()
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="admission_agents_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'Name', 'Email', 'Phone', 'Commission %', 'Status']);
            AdmissionAgent::where('school_id', $this->school_id)
                ->orderBy('name')
                ->get()
                ->each(function ($a, $i) use ($out) {
                    fputcsv($out, [$i+1, $a->name, $a->email, $a->phone, $a->commission_pct, $a->is_active ? 'Active' : 'Inactive']);
                });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Converts an accepted/enrolled Admission into a portal student account.
     *
     * Delegates the actual provisioning to StudentProvisioningService — the
     * one place shared with the staff-entry/admin wizard's own enrolment
     * step, so an application never produces a differently-shaped student
     * merely because of who entered it. This method's job is just to map
     * an Admission's columns onto the service's input shape and translate
     * the result back into this controller's audit/notification behaviour.
     *
     * @param array{class_id: ?int, section_id: ?int, department_id: ?int, session_id: ?int} $academicAssignment
     */
    private function createStudentFromAdmission(Admission $admission, ?string $chosenPassword = null, array $academicAssignment = []): void
    {
        $existing = User::where('email', $admission->email)->first();
        if ($existing && (int) $existing->school_id !== (int) $this->school_id) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'This email belongs to another school. Enrolment has not been completed.']);
        }
        $result = StudentProvisioningService::provision([
            'school_id'         => $this->school_id,
            'first_name'        => $admission->first_name,
            'last_name'         => $admission->last_name,
            'email'             => $admission->email,
            'password'          => $chosenPassword,
            'gender'            => $admission->gender,
            'phone'             => $admission->phone,
            'address'           => $admission->physical_address,
            'class_id'          => $academicAssignment['class_id'] ?? null,
            'section_id'        => $academicAssignment['section_id'] ?? null,
            'department_id'     => $academicAssignment['department_id'] ?? null,
            'session_id'        => $academicAssignment['session_id'] ?? null,
            'programme_id'      => $admission->programme_id,
            'intake_session_id' => $admission->intake_session_id,
            'nationality'       => $admission->nationality,
            'applicant_id'      => $admission->applicant_id,
        ]);

        if ($result['error'] === 'email_taken_by_non_student') {
            // Do not touch the existing account automatically; leave for an
            // admin to resolve manually.
            AuditLog::record('error', 'Admissions', "Could not convert application {$admission->app_number} to a student — email {$admission->email} already belongs to a non-student account.");
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'This email belongs to an incompatible account. Enrolment has not been completed.']);
        }

        $student = $result['student'];

        if ($result['created']) {
            AuditLog::record('create', 'Admissions', "Student portal account created for {$student->name} (#{$student->id}) from application {$admission->app_number}.");
        }

        if ($result['enrollment']) {
            AuditLog::record('create', 'Admissions', "Enrolment created for {$student->name} (#{$student->id}) from application {$admission->app_number}.", [
                'event_type'  => 'DATA',
                'record_type' => Enrollment::class,
                'record_id'   => $result['enrollment']->id,
                'school_id'   => $this->school_id,
            ]);
        }

        // StudentProvisioningService only creates a real Enrollment when a
        // complete academic assignment (Step 6) was given. This is the
        // fallback for every other case — no-op if a row already exists
        // (see EnrollmentDefaults's own docblock for why a present-but-
        // class-less row still behaves correctly downstream, and still
        // resolves the student's current session for session-scoped
        // Online Exam visibility, which an entirely absent row would not).
        \App\Support\EnrollmentDefaults::ensureRow($student->id, $this->school_id);

        if ($result['plain_password'] !== null) {
            $sent = StudentPortalActivation::sendActivationEmail($student, $result['plain_password'], $admission->programme_id, $admission->intake_session_id, true);

            if ($sent) {
                AuditLog::record('update', 'Admissions', "Student portal activation notification accepted for delivery (#{$student->id}).");
            }
        }
    }
}
