<?php
namespace Tests\Feature;

use App\Models\Admission;
use App\Models\ApplicationPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class PesaPalApplicantPaymentUiTest extends TestCase
{
    use AdmissionsTestHelper;

    public function test_production_domain_routes_and_safe_payment_states_render_without_provider_calls(): void
    {
        $this->bootAdmissionsTestSchema();$school=$this->makeSchool();
        config(['app.url'=>'https://piie.ac.ug','sandbox.connectivity'=>null]);
        URL::forceRootUrl('https://piie.ac.ug');URL::forceScheme('https');
        Http::preventStrayRequests();
        foreach (['applicant.login'=>'/applicant/login','applicant.pesapal.callback'=>'/payments/pesapal/callback',
            'applicant.pesapal.ipn'=>'/payments/pesapal/ipn'] as $route=>$path) {
            $this->assertSame('https://piie.ac.ug'.$path,route($route));
        }
        $admission=Admission::findOrFail($this->makeAdmission($school,['app_number'=>'PIIE-2627-S-P9001','status'=>'submitted','submitted_at'=>now(),
            'intake_session_id'=>$this->makeIntakeSession($school,['application_fee'=>'50000.00'])]));
        foreach ([['FAILED','failed','EXPIRED','Expired'],['FAILED','failed',null,'Failed'],
            ['COMPLETED','paid',null,'Successful'],['UNKNOWN','pending',null,'Awaiting payment'],
            [null,'pending',null,'Awaiting payment']] as [$classification,$status,$reason,$label]) {
            $payment=new ApplicationPayment(['method'=>'pesapal','status'=>$status,'amount'=>'50000','reference'=>'TEST-ONLY',
                'gateway_txn_id'=>'7e6b62d9-883e-440f-a63e-e1105bbfadc3',
                'gateway_payload'=>['classification'=>$classification,'verified_failure_reason'=>$reason]]);
            $payment->id=123;$payment->created_at=now();
            $html=view('applicant.partials._payment',['admission'=>$admission,'amount'=>50000,'methods'=>[
                ['key'=>'pesapal','label'=>'PesaPal','icon'=>'bi-credit-card','configured'=>true]], 'payments'=>collect([$payment])])->render();
            $this->assertStringContainsString($label,$html);
            $this->assertStringContainsString('MTN Mobile Money',$html);
            $this->assertStringContainsString('Airtel Money',$html);
            $this->assertStringContainsString('Payment Registration Number (PRN)', $html);
            $this->assertStringContainsString('PRN-PIIE2627SP9001', $html);
            $this->assertStringContainsString('bank transfer',$html);
            $this->assertStringNotContainsString('Bank Deposit / Mobile Money',$html);
            $this->assertStringNotContainsString('Submit Payment Details',$html);
            if ($status === 'pending') {
                $this->assertStringContainsString('name="confirmation_code"', $html);
                $this->assertStringContainsString('A typed code alone never marks a fee as paid.', $html);
            }
            $this->assertStringNotContainsString('Sandbox checkout is blocked',$html);
            if($classification==='FAILED'&&$reason===null)$this->assertStringContainsString('did not provide a specific failure reason',$html);
        }
        $this->be($this->makeApplicant($school), 'applicant');
        $final = view('applicant.application.payment', [
            'errors' => new \Illuminate\Support\ViewErrorBag(),
            'admission' => $admission, 'amount' => 50000,
            'methods' => [['key' => 'pesapal', 'label' => 'PesaPal', 'icon' => 'bi-credit-card', 'configured' => true]],
            'payments' => collect(), 'readOnly' => true,
            'step' => 'payment', 'steps' => \App\Support\Admissions\ApplicationProgress::applicableSteps($admission),
            'percent' => \App\Support\Admissions\ApplicationProgress::percent($admission),
            'canSubmit' => true, 'blockers' => [],
        ])->render();
        $dom = new \DOMDocument();
        @$dom->loadHTML($final);
        $xpath = new \DOMXPath($dom);
        $buttons = $xpath->query('//form[@action="'.route('applicant.payment.gateway.start', 'pesapal').'"]//button[@type="submit" and not(@disabled)]');
        $this->assertSame(1, $buttons->length, 'The final step keeps checkout enabled for a submitted unpaid application.');
        $this->assertStringContainsString('Pay and Submit', $final);
        $this->assertStringNotContainsString('name="declaration"', $final);
        Http::assertNothingSent();
        $this->assertSame(0,$admission->payments()->count());
    }
}
