<?php

namespace App\Http\Controllers;

use App\Models\{StudentFeeManager, TuitionPayment, TuitionPaymentResolution, User};
use App\Support\Payments\{PesaPalConfiguration, PesaPalException, PesaPalService, TuitionPesaPalPayment};
use App\Support\Payments\DecimalAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Validation\ValidationException;

final class TuitionPesaPalController extends Controller
{
    public function payments(Request $request)
    {
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.view'), 403);
        $status = $request->validate(['status' => 'nullable|in:pending,paid,failed,reversed,archived'])['status'] ?? null;
        $payments = TuitionPayment::where('school_id', $request->user()->school_id)
            ->when($status, fn ($q) => $q->where('status', $status))->with('fee.student')->latest('id')->paginate(30)->withQueryString();
        return view('admin.student_fee_manager.pesapal_payments', compact('payments', 'status'));
    }

    public function resolveTestPayment(Request $request, int $paymentId)
    {
        $user = $request->user();
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($user, 'finance.payments'), 403);

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
            'provider_status' => 'required|in:failed,expired,cancelled,review_only',
            'provider_evidence' => 'nullable|string|max:1000',
        ]);

        // review_only records an audit note but does NOT archive or change status
        $isReviewOnly = $validated['provider_status'] === 'review_only';

        try {
            DB::transaction(function () use ($user, $paymentId, $validated, $isReviewOnly) {
            $payment = TuitionPayment::where('id', $paymentId)
                ->where('school_id', $user->school_id)
                ->lockForUpdate()->firstOrFail();

            // 1. Must be pending (not paid, failed, or reversed)
            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'Only pending payments can be resolved as test payments.']);
            }

            // 2. Must not already be resolved
            if ($payment->isResolved()) {
                throw ValidationException::withMessages(['payment' => 'This payment has already been resolved.']);
            }

            // 3. Race condition check: re-verify still pending after lock
            $freshPayment = TuitionPayment::where('id', $paymentId)->lockForUpdate()->first();
            if ($freshPayment->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'Payment status changed during processing. Reload and retry.']);
            }

            // 4. Verify Sandbox environment — must be a Sandbox test transaction
            $payload = $payment->gateway_payload ?? [];
            $environment = $payload['environment'] ?? null;
            if ($environment !== 'sandbox') {
                throw ValidationException::withMessages(['payment' => 'Only Sandbox test payments can be resolved. Live payments are not eligible.']);
            }

            // 5. Terminal statuses (failed/expired/cancelled) require INDEPENDENT provider verification.
            //    The admin's selection alone does NOT authorize archiving.
            //    The payment's gateway_payload must contain classification=FAILED with a last_checked_at
            //    timestamp from a prior PesaPal reconciliation (a trusted provider verification event).
            //    Arbitrary stored data without a reconcile timestamp is not trusted.
            $terminalStatuses = ['failed', 'expired', 'cancelled'];
            if (in_array($validated['provider_status'], $terminalStatuses, true)) {
                $classification = $payload['classification'] ?? null;
                $lastCheckedAt = $payload['last_checked_at'] ?? null;
                if ($classification !== 'FAILED' || ! $lastCheckedAt) {
                    throw ValidationException::withMessages(['provider_status' => 'Provider status not independently verified. Run PesaPal reconciliation or use "Check payment status" first. The payment gateway payload must contain classification=FAILED with a last_checked_at timestamp from a trusted provider verification.']);
                }
                // Additional: require provider evidence for terminal statuses
                if (empty($validated['provider_evidence'])) {
                    throw ValidationException::withMessages(['provider_evidence' => 'Provider evidence (tracking ID, dashboard screenshot reference) is required for terminal status resolution.']);
                }
            }

            // 7. review_only requires evidence
            if ($isReviewOnly && empty($validated['provider_evidence'])) {
                throw ValidationException::withMessages(['provider_evidence' => 'Provider evidence is required for review-only resolution.']);
            }

            // 7. Verify no settlement/credit/reversal for THIS specific payment
            // Check AuditLog for any settlement record referencing this payment
            $hasSettlement = \App\Models\AuditLog::where('record_type', TuitionPayment::class)
                ->where('record_id', $payment->id)
                ->where('action', 'update')
                ->where('description', 'like', '%paid%')
                ->exists();
            if ($hasSettlement) {
                throw ValidationException::withMessages(['payment' => 'Cannot resolve: this payment has an existing settlement record.']);
            }

            // 8. Verify fee paid_amount is not inflated by this payment
            // A pending payment should never have contributed to paid_amount.
            // But verify: if no other paid payments exist for this fee and paid_amount > 0,
            // something is inconsistent.
            $fee = StudentFeeManager::where('id', $payment->fee_id)->where('school_id', $user->school_id)->first();
            if ($fee) {
                $otherPaidCount = TuitionPayment::where('fee_id', $payment->fee_id)
                    ->where('school_id', $user->school_id)
                    ->where('status', 'paid')
                    ->where('id', '!=', $payment->id)
                    ->count();
                if ($otherPaidCount === 0 && DecimalAmount::minorUnits($fee->paid_amount) > 0) {
                    throw ValidationException::withMessages(['payment' => 'Cannot resolve: invoice has unexplained payment credits.']);
                }
            }

            // 9. Create immutable audit record
            $resolution = TuitionPaymentResolution::create([
                'payment_id' => $payment->id,
                'school_id' => $user->school_id,
                'resolved_by' => $user->id,
                'resolved_by_name' => $user->name,
                'reason' => $validated['reason'],
                'resolution_type' => $isReviewOnly ? 'admin_review' : 'test_payment_archived',
                'payment_snapshot' => $payment->toArray(),
'verification_evidence' => [
                    'provider_status' => $validated['provider_status'],
                    'provider_evidence' => $validated['provider_evidence'] ?? null,
                    'gateway_txn_id' => $payment->gateway_txn_id,
                    'environment' => $environment,
                    'classification' => $payload['classification'] ?? null,
                    'last_checked_at' => $payload['last_checked_at'] ?? null,
                    'resolved_at' => now()->toIso8601String(),
                ],
                'resolved_at' => now(),
            ]);

            // 10. Only archive (mark resolved) if provider status is definitively terminal
            // review_only records the note but does NOT archive or change status
            if (! $isReviewOnly) {
                $payment->update([
                    'resolved_at' => now(),
                    'resolution_id' => $resolution->id,
                    'status' => 'archived',
                ]);
            }

            // 11. Audit log
            \App\Models\AuditLog::record('resolve', 'Tuition Payments',
                ($isReviewOnly ? 'Admin review' : 'Test payment archived') . ' #' . $payment->id . ': ' . $validated['reason'],
                ['school_id' => $user->school_id, 'event_type' => 'DATA', 'record_type' => TuitionPayment::class, 'record_id' => $payment->id]);
        });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        if ($isReviewOnly) {
            return back()->with('success', 'Admin review recorded. The payment remains pending and will still block configuration changes until definitively resolved.');
        }

        return back()->with('success', 'Test payment resolved and archived. It will no longer block configuration changes.');
    }
    private function fee(Request $request, int $id): StudentFeeManager
    {
        $user = $request->user();
        $query = StudentFeeManager::whereKey($id)->where('school_id', $user->school_id);
        if ($request->routeIs('student.*')) { $query->where('student_id', $user->id); }
        elseif ($request->routeIs('parent.*')) { $query->where('parent_id', $user->id); }
        else { abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($user, 'finance.view'), 403); }
        return $query->firstOrFail();
    }

    public function index(Request $request, int $id)
    {
        $fee = $this->fee($request, $id);
        $student = \App\Models\User::whereKey($fee->student_id)->where('school_id', $fee->school_id)->firstOrFail();
        $eligibility = app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->summary($student);
        $isAdminAddedExisting = app(\App\Support\Payments\ExistingStudentPriorPaymentEligibility::class)
            ->allows($student, (int) $fee->school_id);
        $canManageWaiver = app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'academic.course_registration.fee_waiver');
        $area = $request->routeIs('parent.*') ? 'parent' : ($request->routeIs('student.*') ? 'student' : ($request->routeIs('accountant.*') ? 'accountant' : 'admin'));
        $setupReview = in_array($area, ['admin', 'accountant'], true)
            && \Illuminate\Support\Facades\Schema::hasTable('student_financial_setup_reviews')
            ? \App\Models\StudentFinancialSetupReview::where('school_id', $fee->school_id)->where('fee_id', $fee->id)
                ->where('student_id', $student->id)->where('session_id', $fee->session_id)->first()
            : null;
        $financialSetup = app(\App\Support\Billing\ExistingStudentFinancialSetup::class);
        $sessionLabels = \App\Models\Session::where('school_id', $fee->school_id)
            ->whereIn('id', array_filter([$fee->session_id, $eligibility['session_id']]))->pluck('session_title', 'id');
        $school = \App\Models\School::find($fee->school_id);
        return response()->view('shared.tuition_payment', ['fee' => $fee, 'eligibility' => $eligibility, 'canManageWaiver' => $canManageWaiver,
            'student' => $student, 'sessionLabels' => $sessionLabels, 'currencyLabel' => $school->school_currency ?? '',
            'openingPayments' => $isAdminAddedExisting && \Illuminate\Support\Facades\Schema::hasTable('student_opening_payments') ? $fee->openingPayments()->latest('id')->get() : collect(),
            'isAdminAddedExisting' => $isAdminAddedExisting,
            'canManageOpeningPayments' => $isAdminAddedExisting && app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.payments'),
            'awards' => \Illuminate\Support\Facades\Schema::hasTable('student_fee_awards') ? $fee->awards()->latest('id')->get() : collect(),
            'canManageAwards' => app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.invoices'),
            'area' => $area,
            'financialSetupReview' => $setupReview,
            'projectedBalance' => $financialSetup->projectedBalance($fee, $setupReview) / 100,
            'canConfirmFinancialSetup' => [
                'prior_payment' => $financialSetup->mayConfirm($request->user(), 'prior_payment'),
                'award' => $financialSetup->mayConfirm($request->user(), 'award'),
                'study_waiver' => $financialSetup->mayConfirm($request->user(), 'study_waiver'),
            ],
            'payments' => TuitionPayment::where('fee_id', $fee->id)->where('school_id', $fee->school_id)->latest('id')->get()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function confirmFinancialSetup(Request $request, int $id, string $item)
    {
        abort_unless(in_array($item, ['prior_payment', 'award', 'study_waiver'], true), 404);
        app(\App\Support\Billing\ExistingStudentFinancialSetup::class)->confirm($request->user(), $id, $item);

        return back()->with('success', ucfirst(str_replace('_', ' ', $item)) . ' confirmed and applied using its existing audited workflow.');
    }

    public function start(Request $request, int $id)
    {
        $fee = $this->fee($request, $id);
        $data = $request->validate(['payer_phone' => ['nullable', 'string', 'max:20', 'regex:/\A\+?[0-9()\s-]{7,20}\z/'],
            'payment_option' => 'nullable|in:minimum,balance,custom', 'payment_amount' => 'nullable|string|max:30']);
        try {
            $payment = TuitionPesaPalPayment::start($fee, $data['payer_phone'] ?? null, $data['payment_option'] ?? 'balance', $data['payment_amount'] ?? null);
            $url = $payment->gateway_payload['checkout_url'] ?? null;
            if ($url !== null) {
                return redirect()->away((new PesaPalService(PesaPalConfiguration::forPayment($payment)))->checkoutUrl($url));
            }
            return back()->with('error', 'An order is awaiting verification. Do not pay again; contact finance with your PRN.');
        } catch (PesaPalException $exception) { return back()->with('error', 'PesaPal is temporarily unavailable. Check payment status before retrying.'); }
    }

    public function check(Request $request, int $id, int $payment)
    {
        $fee = $this->fee($request, $id);
        $attempt = TuitionPayment::whereKey($payment)->where('fee_id', $fee->id)->where('school_id', $fee->school_id)->firstOrFail();
        $data = $request->validate(['confirmation_code' => 'nullable|string|max:100', 'order_tracking_id' => 'nullable|uuid']);
        try {
            $result = TuitionPesaPalPayment::reconcile($attempt, $data['order_tracking_id'] ?? null, $data['confirmation_code'] ?? null);
            return back()->with('success', 'Payment status: ' . $result);
        } catch (PesaPalException $exception) { return back()->with('error', 'Payment could not be verified. Do not pay again; contact finance.'); }
    }

    public function openingPayment(Request $request, int $id)
    {
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.payments'), 403);
        $data = $request->validate([
            'action' => 'required|in:record,reverse',
            'amount' => ['required_if:action,record', 'nullable', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'paid_on' => 'required_if:action,record|nullable|date_format:Y-m-d|before_or_equal:today',
            'method' => 'required_if:action,record|nullable|in:cash,bank_transfer,mobile_money,card,cheque,other',
            'reference' => 'required_if:action,record|nullable|string|max:150',
            'reason' => 'required|string|min:10|max:500',
            'opening_payment_id' => 'required_if:action,reverse|nullable|integer|min:1',
        ]);
        app(\App\Support\Payments\StudentOpeningPayments::class)->change($request->user(), $id, $data);
        return back()->with('success', 'Prior payment updated. The student balance has been recalculated; this is not a new collection.');
    }

    public function award(Request $request, int $id)
    {
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.invoices'), 403);
        $data = $request->validate([
            'action' => 'required|in:grant,revoke',
            'type' => 'required_if:action,grant|nullable|in:bursary,scholarship',
            'amount' => ['required_if:action,grant', 'nullable', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'],
            'reference' => 'nullable|string|max:150',
            'reason' => 'required|string|min:10|max:500',
        ]);
        app(\App\Support\Billing\StudentFeeAwards::class)->change($request->user(), $id, $data);
        return back()->with('success', 'Fee award updated. The invoice balance has been recalculated; payments received are unchanged.');
    }

    public function waiver(Request $request, int $id)
    {
        $fee = $this->fee($request, $id);
        $data = $request->validate(['reason' => 'required|string|min:10|max:500', 'action' => 'required|in:grant,revoke']);
        $service = app(\App\Support\CourseRegistration\CourseRegistrationFeeWaiverService::class);
        try {
            if ($data['action'] === 'grant') { $service->grantForStudent($request->user(), (int) $fee->student_id, (int) $fee->school_id, $data['reason']); }
            else { $service->revoke($request->user(), (int) $fee->student_id, (int) $fee->school_id, $data['reason']); }
        } catch (\DomainException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['waiver' => $exception->getMessage()]);
        }
        return back()->with('success', $data['action'] === 'grant' ? '20% study requirement waived for this student for the current fee session. The fee balance remains payable.' : 'Study fee waiver revoked. The 20% payment rule applies again.');
    }

    public static function notification(Request $request): string
    {
        $data = $request->validate(['OrderTrackingId' => 'required|uuid', 'OrderMerchantReference' => 'required|string|max:191']);
        // Unknown and ambiguous references are client errors, not server
        // faults. Never reconcile a row the provider did not identify exactly.
        $payments = TuitionPayment::where('reference', $data['OrderMerchantReference'])->limit(2)->get();
        if ($payments->count() !== 1) { abort(404); }
        return TuitionPesaPalPayment::reconcile($payments->first(), $data['OrderTrackingId']);
    }

    public function callback(Request $request)
    {
        try { $result = self::notification($request); }
        catch (PesaPalException $exception) { $result = 'verification unavailable; do not pay again'; }
        return redirect()->route('login')->with('success', 'School fee payment: ' . $result . '. Sign in to view your invoice.');
    }
}
