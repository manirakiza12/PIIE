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

    public function reconciliationIndex(Request $request)
    {
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.payments'), 403);

        $user = $request->user();
        $schoolId = $user->school_id;

        $validated = array_merge([
            'status' => null,
            'date_from' => null,
            'date_to' => null,
            'student_search' => null,
            'reference_search' => null,
            'environment' => null,
            'discrepancy' => false,
        ], $request->validate([
            'status' => 'nullable|in:pending,paid,failed,reversed,archived',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'student_search' => 'nullable|string|max:191',
            'reference_search' => 'nullable|string|max:191',
            'environment' => 'nullable|in:sandbox,live',
            'discrepancy' => 'nullable|boolean',
        ]));

        $query = TuitionPayment::where('school_id', $schoolId)
            ->where('method', 'pesapal')
            ->with(['fee.student', 'resolution'])
            ->latest('id');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }
        if (!empty($validated['environment'])) {
            $query->where(function ($q) use ($validated) {
                $q->whereRaw("JSON_EXTRACT(gateway_payload, '$.environment') = ?", [$validated['environment']]);
            });
        }
        if (!empty($validated['student_search'])) {
            $query->whereHas('fee.student', function ($q) use ($validated) {
                $q->where('name', 'like', '%' . $validated['student_search'] . '%')
                  ->orWhere('code', 'like', '%' . $validated['student_search'] . '%');
            });
        }
        if (!empty($validated['reference_search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('reference', 'like', '%' . $validated['reference_search'] . '%')
                  ->orWhere('gateway_txn_id', 'like', '%' . $validated['reference_search'] . '%');
            });
        }
        if (!empty($validated['discrepancy'])) {
            $query->where(function ($q) {
                $q->where('status', 'pending')
                  ->whereHas('fee', function ($feeQ) {
                      $feeQ->whereRaw('total_amount > paid_amount');
                  });
            });
        }

        $payments = $query->paginate(30)->withQueryString();

        // Calculate reconciliation summary
        $summary = [
            'total_pending' => TuitionPayment::where('school_id', $schoolId)->where('method', 'pesapal')->where('status', 'pending')->count(),
            'total_paid' => TuitionPayment::where('school_id', $schoolId)->where('method', 'pesapal')->where('status', 'paid')->count(),
            'total_failed' => TuitionPayment::where('school_id', $schoolId)->where('method', 'pesapal')->where('status', 'failed')->count(),
            'total_reversed' => TuitionPayment::where('school_id', $schoolId)->where('method', 'pesapal')->where('status', 'reversed')->count(),
            'total_archived' => TuitionPayment::where('school_id', $schoolId)->where('method', 'pesapal')->where('status', 'archived')->count(),
            'stale_pending' => TuitionPayment::where('school_id', $schoolId)->where('method', 'pesapal')->where('status', 'pending')->where('updated_at', '<', now()->subDays(7))->count(),
            'unverified_failed' => TuitionPayment::where('school_id', $schoolId)->where('method', 'pesapal')->where('status', 'failed')
                ->where(function ($q) {
                    $q->whereNull('gateway_payload->classification')
                      ->orWhere('gateway_payload->classification', '!=', 'FAILED');
                })->count(),
        ];

        // Get invoices with balance discrepancies
        $discrepancyInvoices = StudentFeeManager::where('school_id', $schoolId)
            ->whereRaw('total_amount > paid_amount')
            ->whereHas('pesapalPayments', function ($q) {
                $q->where('method', 'pesapal')->where('status', 'pending');
            })
            ->with(['pesapalPayments' => function ($q) { $q->where('method', 'pesapal')->where('status', 'pending'); }])
            ->limit(20)
            ->get();

        return view('admin.student_fee_manager.pesapal_reconciliation', compact(
            'payments', 'summary', 'discrepancyInvoices', 'validated'
        ));
    }

    public function runReconciliation(Request $request)
    {
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.payments'), 403);

        $schoolId = $request->user()->school_id;

        $payments = TuitionPayment::where('school_id', $schoolId)
            ->where('method', 'pesapal')
            ->where('status', 'pending')
            ->whereNotNull('gateway_txn_id')
            ->orderBy('updated_at')
            ->limit(100)
            ->get();

        $results = [
            'verified_paid' => 0,
            'verified_failed' => 0,
            'unavailable' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($payments as $payment) {
            try {
                $classification = TuitionPesaPalPayment::reconcile($payment);
                if ($classification === 'paid') {
                    $results['verified_paid']++;
                } elseif ($classification === 'failed') {
                    $results['verified_failed']++;
                } elseif ($classification === 'invoice_missing') {
                    $results['skipped']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\App\Support\Payments\PesaPalException $exception) {
                $results['unavailable']++;
            } catch (\Throwable $exception) {
                $results['skipped']++;
                $results['errors'][] = [
                    'payment_id' => $payment->id,
                    'reference' => $payment->reference,
                    'error' => get_class($exception) . ': ' . $exception->getMessage(),
                ];
                \Illuminate\Support\Facades\Log::warning('tuition:run-reconciliation skipped payment', [
                    'payment_id' => $payment->id,
                    'exception' => get_class($exception),
                ]);
            }
        }

        $message = "Manual reconciliation completed: {$results['verified_paid']} paid, {$results['verified_failed']} failed, {$results['unavailable']} provider unavailable, {$results['skipped']} skipped.";
        if (!empty($results['errors'])) {
            $message .= ' Errors: ' . count($results['errors']) . ' payments had exceptions (see logs).';
        }

        return back()->with(empty($results['errors']) ? 'success' : 'warning', $message);
    }

    public function verifyPesapalPayment(Request $request, int $paymentId)
    {
        $user = $request->user();
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($user, 'finance.payments'), 403);

        $payment = TuitionPayment::where('id', $paymentId)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        if (! in_array($payment->status, ['pending', 'failed'], true)) {
            return back()->with('error', 'Only pending or failed payments can be verified.');
        }

        if (empty($payment->gateway_txn_id)) {
            return back()->with('error', 'No PesaPal tracking ID found. The order was never submitted to PesaPal.');
        }

        // Verify Sandbox environment
        $payload = $payment->gateway_payload ?? [];
        if (($payload['environment'] ?? null) !== 'sandbox') {
            return back()->with('error', 'Only Sandbox test payments can be verified.');
        }

        try {
            $classification = TuitionPesaPalPayment::reconcile($payment, $payment->gateway_txn_id);
            $payment->refresh();
            if ($classification === 'invoice_missing') {
                return back()->with('error', 'PesaPal verification complete. Provider status: ' . ($payment->gateway_payload['classification'] ?? 'UNKNOWN') . '. The invoice record is missing or inaccessible, so no financial update was made.');
            }
            $newStatus = $payment->status;
            return back()->with('success', "PesaPal verification complete. Provider status: {$classification}. Payment status: {$newStatus}.");
        } catch (\App\Support\Payments\PesaPalException $e) {
            $paymentEnvironment = $payment->gateway_payload['environment'] ?? null;
            if ($paymentEnvironment === 'sandbox' && $paymentEnvironment !== 'live') {
                // Old Sandbox orders must never be verified with Live credentials.
                return back()->with('error', 'This Sandbox payment cannot be verified with the current Live PesaPal configuration. Restore the original Sandbox credentials/configuration or leave it pending.');
            }
            return back()->with('error', 'PesaPal verification failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'PesaPal verification failed: ' . $e->getMessage());
        }
    }

    public function archiveTestPayment(Request $request, int $paymentId)
    {
        $user = $request->user();
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($user, 'finance.payments'), 403);

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        DB::transaction(function () use ($user, $paymentId, $validated) {
            $payment = TuitionPayment::where('id', $paymentId)
                ->where('school_id', $user->school_id)
                ->lockForUpdate()->firstOrFail();

            if ($payment->isResolved()) {
                throw ValidationException::withMessages(['payment' => 'This payment has already been resolved.']);
            }

            // Must be a verified failed payment (reconciled by PesaPal)
            if ($payment->status !== 'failed') {
                throw ValidationException::withMessages(['payment' => 'Only payments confirmed as FAILED by PesaPal can be archived. Run "Verify with PesaPal" first.']);
            }

            // Verify Sandbox environment
            $payload = $payment->gateway_payload ?? [];
            if (($payload['environment'] ?? null) !== 'sandbox') {
                throw ValidationException::withMessages(['payment' => 'Only Sandbox test payments can be archived.']);
            }

            // Verify classification from reconcile
            if (($payload['classification'] ?? null) !== 'FAILED') {
                throw ValidationException::withMessages(['payment' => 'Payment must have classification=FAILED from PesaPal verification.']);
            }

            // Verify no settlement records
            $hasSettlement = \App\Models\AuditLog::where('record_type', TuitionPayment::class)
                ->where('record_id', $payment->id)
                ->where('action', 'update')
                ->where('description', 'like', '%paid%')
                ->exists();
            if ($hasSettlement) {
                throw ValidationException::withMessages(['payment' => 'Cannot archive: this payment has an existing settlement record.']);
            }

            // Verify fee paid_amount is not inflated
            $fee = StudentFeeManager::where('id', $payment->fee_id)->where('school_id', $user->school_id)->first();
            if ($fee) {
                $otherPaidCount = TuitionPayment::where('fee_id', $payment->fee_id)
                    ->where('school_id', $user->school_id)
                    ->where('status', 'paid')
                    ->where('id', '!=', $payment->id)
                    ->count();
                if ($otherPaidCount === 0 && DecimalAmount::minorUnits($fee->paid_amount) > 0) {
                    throw ValidationException::withMessages(['payment' => 'Cannot archive: invoice has unexplained payment credits.']);
                }
            }

            // Create immutable audit record
            $resolution = TuitionPaymentResolution::create([
                'payment_id' => $payment->id,
                'school_id' => $user->school_id,
                'resolved_by' => $user->id,
                'resolved_by_name' => $user->name,
                'reason' => $validated['reason'],
                'resolution_type' => 'test_payment_archived',
                'payment_snapshot' => $payment->toArray(),
                'verification_evidence' => [
                    'provider_status' => 'failed',
                    'provider_evidence' => 'PesaPal verified FAILED',
                    'gateway_txn_id' => $payment->gateway_txn_id,
                    'environment' => $payload['environment'] ?? null,
                    'classification' => $payload['classification'] ?? null,
                    'last_checked_at' => $payload['last_checked_at'] ?? null,
                    'resolved_at' => now()->toIso8601String(),
                ],
                'resolved_at' => now(),
            ]);

            // Archive the payment
            $payment->update([
                'resolved_at' => now(),
                'resolution_id' => $resolution->id,
                'status' => 'archived',
            ]);

            // Audit log
            \App\Models\AuditLog::record('resolve', 'Tuition Payments',
                'Test payment archived #' . $payment->id . ': ' . $validated['reason'],
                ['school_id' => $user->school_id, 'event_type' => 'DATA', 'record_type' => TuitionPayment::class, 'record_id' => $payment->id]);
        });

        return back()->with('success', 'Test payment verified and archived. It will no longer block configuration changes.');
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

        // Read payment outside transaction for validation
        $payment = TuitionPayment::where('id', $paymentId)
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        // 1. Must be pending or failed (not paid, reversed, or archived)
        if (! in_array($payment->status, ['pending', 'failed'])) {
            throw ValidationException::withMessages(['payment' => 'Only pending or failed payments can be resolved as test payments.']);
        }

        // 2. Must not already be resolved
        if ($payment->isResolved()) {
            throw ValidationException::withMessages(['payment' => 'This payment has already been resolved.']);
        }

        // 3. For pending payments, only review_only is available (no archiving of unreconciled payments)
        if ($payment->status === 'pending' && ! $isReviewOnly) {
            throw ValidationException::withMessages(['payment' => 'Pending payments cannot be archived. Use review_only to record a note, or reconcile the payment first.']);
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

        // 6. review_only requires evidence
        if ($isReviewOnly && empty($validated['provider_evidence'])) {
            throw ValidationException::withMessages(['provider_evidence' => 'Provider evidence is required for review-only resolution.']);
        }

        // 7. Verify no settlement/credit/reversal for THIS specific payment
        $hasSettlement = \App\Models\AuditLog::where('record_type', TuitionPayment::class)
            ->where('record_id', $payment->id)
            ->where('action', 'update')
            ->where('description', 'like', '%paid%')
            ->exists();
        if ($hasSettlement) {
            throw ValidationException::withMessages(['payment' => 'Cannot resolve: this payment has an existing settlement record.']);
        }

        // 8. Verify fee paid_amount is not inflated by this payment
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

        // Write operations in transaction
        try {
            DB::transaction(function () use ($user, $paymentId, $validated, $isReviewOnly, $payment) {
            // Re-lock and re-verify
            $lockedPayment = TuitionPayment::where('id', $paymentId)
                ->where('school_id', $user->school_id)
                ->lockForUpdate()->firstOrFail();

            if (! in_array($lockedPayment->status, ['pending', 'failed'])) {
                throw ValidationException::withMessages(['payment' => 'Payment status changed during processing. Reload and retry.']);
            }

            if ($lockedPayment->isResolved()) {
                throw ValidationException::withMessages(['payment' => 'This payment has already been resolved.']);
            }

            // Create immutable audit record
            $resolution = TuitionPaymentResolution::create([
                'payment_id' => $lockedPayment->id,
                'school_id' => $user->school_id,
                'resolved_by' => $user->id,
                'resolved_by_name' => $user->name,
                'reason' => $validated['reason'],
                'resolution_type' => $isReviewOnly ? 'admin_review' : 'test_payment_archived',
                'payment_snapshot' => $lockedPayment->toArray(),
                'verification_evidence' => [
                    'provider_status' => $validated['provider_status'],
                    'provider_evidence' => $validated['provider_evidence'] ?? null,
                    'gateway_txn_id' => $lockedPayment->gateway_txn_id,
                    'environment' => $lockedPayment->gateway_payload['environment'] ?? null,
                    'classification' => $lockedPayment->gateway_payload['classification'] ?? null,
                    'last_checked_at' => $lockedPayment->gateway_payload['last_checked_at'] ?? null,
                    'resolved_at' => now()->toIso8601String(),
                ],
                'resolved_at' => now(),
            ]);

            // Only archive (mark resolved) if not review_only
            if (! $isReviewOnly) {
                $lockedPayment->update([
                    'resolved_at' => now(),
                    'resolution_id' => $resolution->id,
                    'status' => 'archived',
                ]);
            }

            // Audit log
            \App\Models\AuditLog::record('resolve', 'Tuition Payments',
                ($isReviewOnly ? 'Admin review' : 'Test payment archived') . ' #' . $lockedPayment->id . ': ' . $validated['reason'],
                ['school_id' => $user->school_id, 'event_type' => 'DATA', 'record_type' => TuitionPayment::class, 'record_id' => $lockedPayment->id]);
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
