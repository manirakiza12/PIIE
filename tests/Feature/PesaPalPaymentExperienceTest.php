<?php
namespace Tests\Feature;

use App\Models\ApplicationPayment;
use App\Support\Payments\PesaPalPaymentExperience;
use PHPUnit\Framework\TestCase;

class PesaPalPaymentExperienceTest extends TestCase
{
    public function test_verified_states_and_local_status_must_agree(): void
    {
        foreach ([['COMPLETED','paid',null,'successful'],['COMPLETED','pending',null,'pending'],
            ['FAILED','failed',null,'failed'],['FAILED','failed','EXPIRED','expired'],
            ['FAILED','pending','EXPIRED','pending'],['INVALID','pending',null,'unknown'],
            ['UNKNOWN','pending',null,'unknown'],['REVERSED','reversed',null,'reversed'],
            [null,'paid',null,'unknown'],[null,'pending',null,'pending']] as [$classification,$status,$reason,$expected]) {
            $payment=new ApplicationPayment();
            $payment->status=$status;
            $payment->gateway_payload=['classification'=>$classification,'verified_failure_reason'=>$reason];
            $this->assertSame($expected,PesaPalPaymentExperience::forPayment($payment)['state']);
        }
    }

    public function test_only_explicit_supported_reasons_are_shown(): void
    {
        $this->assertSame('EXPIRED',PesaPalPaymentExperience::failureReason('Reason: Expired'));
        $this->assertSame('INSUFFICIENT_FUNDS',PesaPalPaymentExperience::failureReason('Insufficient funds'));
        foreach ([null,'FAILED','Request processed successfully','Phone prompt sent','Possibly insufficient funds',
            '<script>insufficient funds</script>',str_repeat('x',1001)] as $description) {
            $this->assertNull(PesaPalPaymentExperience::failureReason($description));
        }
        $payment=new ApplicationPayment();$payment->status='failed';
        $payment->gateway_payload=['classification'=>'FAILED'];
        $this->assertSame('PesaPal did not provide a specific failure reason.',PesaPalPaymentExperience::forPayment($payment)['reason']);
    }

    public function test_pending_order_is_resumed(): void
    {
        $payment=new ApplicationPayment();$payment->status='pending';
        $this->assertSame('Resume existing PesaPal checkout',PesaPalPaymentExperience::checkoutLabel(collect([$payment])));
        $payment->status='failed';
        $this->assertSame('Continue to PesaPal',PesaPalPaymentExperience::checkoutLabel(collect([$payment])));
    }
}
