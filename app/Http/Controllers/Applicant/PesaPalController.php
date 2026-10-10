<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Support\Admissions\ApplicationFee;
use App\Support\Payments\ApplicantPesaPalPayment;
use App\Support\Payments\ApplicationPaymentSettlement;
use App\Support\Payments\PesaPalException;
use App\Support\Payments\PesaPalService;
use Illuminate\Http\Request;

final class PesaPalController extends Controller
{
    /** Expiring signed invitation identifies exactly one application. GET never charges. */
    public function invitation(Request $request, int $admission)
    {
        $application = Admission::findOrFail($admission);
        if ($request->isMethod('post')) {
            try {
                if ($request->input('action') === 'check') {
                    $payment = $application->payments()->where('school_id', $application->school_id)->where('method', 'pesapal')->latest('id')->firstOrFail();
                    ApplicantPesaPalPayment::reconcile($payment);
                    return back()->with('success', 'Payment status checked with PesaPal.');
                }
                $payment = ApplicantPesaPalPayment::start($application);
                $url = $payment->gateway_payload['checkout_url'] ?? null;
                if (! $url) { return back()->with('error', 'Payment is awaiting verification. Contact finance before retrying.'); }
                return redirect()->away($url);
            } catch (PesaPalException $exception) {
                return back()->with('error', 'PesaPal verification is temporarily unavailable. Do not start another payment.');
            }
        }
        ApplicationFee::refreshStatus($application);
        return response()->view('applicant.pesapal_invitation', ['admission' => $application,
            'amount' => ApplicantPesaPalPayment::outstanding($application),
            'payment' => $application->payments()->where('school_id', $application->school_id)->where('method', 'pesapal')->latest('id')->first()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** Callback/IPN parameters are lookup hints only; the provider is always queried. */
    private function verify(Request $request): string
    {
        $data = $request->validate(['OrderTrackingId' => 'required|string|max:36', 'OrderMerchantReference' => 'required|string|max:191']);
        if (! PesaPalService::isGuid($data['OrderTrackingId'])) { abort(422); }
        $payments = ApplicationPayment::where('method', 'pesapal')->where('reference', $data['OrderMerchantReference'])->get();
        if ($payments->count() !== 1) { abort(404); }
        return ApplicantPesaPalPayment::reconcile($payments->first(), $data['OrderTrackingId']);
    }

    public function callback(Request $request)
    {
        try {
            $result = $this->verify($request);
            $message = in_array($result, [ApplicationPaymentSettlement::SETTLED, ApplicationPaymentSettlement::ALREADY_SETTLED], true)
                ? 'Payment verified. Sign in to view your application.' : 'Payment has not been confirmed successful. Sign in to check its status.';
            $payment = ApplicationPayment::where('method', 'pesapal')->where('reference', $request->input('OrderMerchantReference'))->sole();
            return redirect()->to(\Illuminate\Support\Facades\URL::temporarySignedRoute('applicant.pesapal.invitation', now()->addHour(), ['admission' => $payment->admission_id]))->with('success', $message);
        } catch (PesaPalException $exception) {
            return redirect()->route('applicant.login')->with('error', 'Payment verification is unavailable. Do not pay again; check your application or contact finance.');
        }
    }

    public function ipn(Request $request)
    {
        try {
            $result = $this->verify($request);
            if ($result === ApplicationPaymentSettlement::REJECTED) { return response()->json(['status' => 422], 422); }
            return response()->json(['orderNotificationType' => $request->input('OrderNotificationType'),
                'orderTrackingId' => $request->input('OrderTrackingId'), 'orderMerchantReference' => $request->input('OrderMerchantReference'), 'status' => 200]);
        } catch (PesaPalException $exception) {
            return response()->json(['status' => 503], 503);
        }
    }
}
