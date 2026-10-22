<?php

namespace App\Http\Controllers;

use App\Models\{StudentFeeManager, TuitionPayment};
use App\Support\Payments\{PesaPalException, TuitionPesaPalPayment};
use Illuminate\Http\Request;

final class TuitionPesaPalController extends Controller
{
    public function payments(Request $request)
    {
        abort_unless(app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.view'), 403);
        $status = $request->validate(['status' => 'nullable|in:pending,paid,failed,reversed'])['status'] ?? null;
        $payments = TuitionPayment::where('school_id', $request->user()->school_id)
            ->when($status, fn ($q) => $q->where('status', $status))->with('fee.student')->latest('id')->paginate(30)->withQueryString();
        return view('admin.student_fee_manager.pesapal_payments', compact('payments', 'status'));
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
        $isAdminAddedExisting = \App\Models\StudentProfile::where('user_id', $student->id)->where('school_id', $fee->school_id)
            ->where('was_existing_at_portal_creation', true)->exists();
        $canManageWaiver = app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'academic.course_registration.fee_waiver');
        return response()->view('shared.tuition_payment', ['fee' => $fee, 'eligibility' => $eligibility, 'canManageWaiver' => $canManageWaiver,
            'openingPayments' => $isAdminAddedExisting && \Illuminate\Support\Facades\Schema::hasTable('student_opening_payments') ? $fee->openingPayments()->latest('id')->get() : collect(),
            'isAdminAddedExisting' => $isAdminAddedExisting,
            'canManageOpeningPayments' => $isAdminAddedExisting && app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.payments'),
            'awards' => \Illuminate\Support\Facades\Schema::hasTable('student_fee_awards') ? $fee->awards()->latest('id')->get() : collect(),
            'canManageAwards' => app(\App\Support\Permissions\PermissionService::class)->allows($request->user(), 'finance.invoices'),
            'area' => $request->routeIs('parent.*') ? 'parent' : ($request->routeIs('student.*') ? 'student' : ($request->routeIs('accountant.*') ? 'accountant' : 'admin')),
            'payments' => TuitionPayment::where('fee_id', $fee->id)->where('school_id', $fee->school_id)->latest('id')->get()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, int $id)
    {
        $fee = $this->fee($request, $id);
        $data = $request->validate(['payer_phone' => ['nullable', 'string', 'max:20', 'regex:/\A\+?[0-9()\s-]{7,20}\z/'],
            'payment_option' => 'nullable|in:minimum,balance,custom', 'payment_amount' => 'nullable|string|max:30']);
        try {
            $payment = TuitionPesaPalPayment::start($fee, $data['payer_phone'] ?? null, $data['payment_option'] ?? 'balance', $data['payment_amount'] ?? null);
            $url = $payment->gateway_payload['checkout_url'] ?? null;
            return $url ? redirect()->away($url) : back()->with('error', 'An order is awaiting verification. Do not pay again; contact finance with your PRN.');
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
        $payment = TuitionPayment::where('reference', $data['OrderMerchantReference'])->sole();
        return TuitionPesaPalPayment::reconcile($payment, $data['OrderTrackingId']);
    }

    public function callback(Request $request)
    {
        try { $result = self::notification($request); }
        catch (PesaPalException $exception) { $result = 'verification unavailable; do not pay again'; }
        return redirect()->route('login')->with('success', 'School fee payment: ' . $result . '. Sign in to view your invoice.');
    }
}
