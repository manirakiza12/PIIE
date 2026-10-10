<?php
namespace App\Support\Payments;
use App\Models\ApplicationPayment;

/** Applicant-safe copy derived from authenticated observations, never browser claims. */
final class PesaPalPaymentExperience
{
    public static function failureReason(?string $description): ?string
    {
        if($description===null||strlen($description)>1000)return null;
        $text=strtolower(trim($description));
        if(preg_match('/\breason\s*:\s*(.+)$/i',$text,$m))$text=trim($m[1]);
        $text=rtrim($text," .!");
        return match($text) {
            'expired','payment expired','request expired','transaction expired','payment has expired','request has expired','timed out','payment timed out','request timed out'=>'EXPIRED',
            'insufficient funds','insufficient balance','insufficient funds in account'=>'INSUFFICIENT_FUNDS',
            'cancelled','canceled','payment cancelled','payment canceled'=>'CANCELLED',
            'unable to authorize transaction','unable to authorise transaction','authorization declined','authorisation declined'=>'AUTHORIZATION_DECLINED',
            default=>null,
        };
    }
    public static function forPayment(ApplicationPayment $payment): array
    {
        $p=$payment->gateway_payload??[];$classification=$p['classification']??null;
        // Existing reconciled classifications are verified; a raw status flag alone is insufficient.
        $state=match(true) {
            $classification==='REVERSED'=>'reversed',
            $classification==='COMPLETED'&&$payment->status==='paid'=>'successful',
            $classification==='FAILED'&&$payment->status==='failed'&&($p['verified_failure_reason']??null)==='EXPIRED'=>'expired',
            $classification==='FAILED'&&$payment->status==='failed'=>'failed',
            in_array($classification,['INVALID','UNKNOWN'],true)=>'unknown',
            $payment->status==='pending'=>'pending',
            default=>'unknown',
        };
        [$label,$tone,$message]=match($state) {
            'successful'=>['Successful','success','PesaPal verified this payment as completed. Your fee balance reflects the verified payment.'],
            'expired'=>['Expired','warning','PesaPal verified that this payment expired. Your fee remains unpaid unless another approved payment settles it. Check the existing payment before trying again.'],
            'failed'=>['Failed','danger','PesaPal verified that this payment failed. Your fee remains unpaid unless another approved payment settles it.'],
            'pending'=>['Pending verification','primary','This payment has not been confirmed successful. Check this payment or resume the same checkout; do not start another order while it is unresolved.'],
            'reversed'=>['Reversed','danger','PesaPal reported a reversal. This payment no longer contributes to your fee balance. Contact finance.'],
            default=>['Status unknown','secondary','PesaPal has not confirmed a successful payment. Do not pay again while the existing order is unresolved; check its status or contact finance.'],
        };
        $reason=$classification==='FAILED'?match($p['verified_failure_reason']??null){
            'EXPIRED'=>'The payment approval expired.',
            'INSUFFICIENT_FUNDS'=>'The provider reported insufficient funds.',
            'CANCELLED'=>'The provider reported that the payment was cancelled.',
            'AUTHORIZATION_DECLINED'=>'The provider declined authorization.',
            default=>'PesaPal did not provide a specific failure reason.',
        }:null;
        return compact('state','label','tone','message','reason');
    }
    public static function checkoutLabel($payments): string
    {
        return $payments->contains(fn($p)=>$p->status==='pending')?'Resume existing PesaPal checkout':'Continue to PesaPal';
    }
}
