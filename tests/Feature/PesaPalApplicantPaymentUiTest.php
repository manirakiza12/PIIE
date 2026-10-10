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
        $admission=Admission::findOrFail($this->makeAdmission($school,['status'=>'submitted','submitted_at'=>now(),
            'intake_session_id'=>$this->makeIntakeSession($school,['application_fee'=>'50000.00'])]));
        foreach ([['FAILED','failed','EXPIRED','Expired'],['FAILED','failed',null,'Failed'],
            ['COMPLETED','paid',null,'Successful'],['UNKNOWN','pending',null,'Status unknown'],
            [null,'pending',null,'Pending verification']] as [$classification,$status,$reason,$label]) {
            $payment=new ApplicationPayment(['method'=>'pesapal','status'=>$status,'amount'=>'50000','reference'=>'TEST-ONLY',
                'gateway_payload'=>['classification'=>$classification,'verified_failure_reason'=>$reason]]);
            $payment->id=123;$payment->created_at=now();
            $html=view('applicant.partials._payment',['admission'=>$admission,'amount'=>50000,'methods'=>[
                ['key'=>'pesapal','label'=>'PesaPal','icon'=>'bi-credit-card']], 'bankDetails'=>null,'payments'=>collect([$payment])])->render();
            $this->assertStringContainsString($label,$html);
            $this->assertStringContainsString('MTN Mobile Money',$html);
            $this->assertStringContainsString('Airtel Money',$html);
            $this->assertStringContainsString('bank card',$html);
            $this->assertStringNotContainsString('Sandbox checkout is blocked',$html);
            if($classification==='FAILED'&&$reason===null)$this->assertStringContainsString('did not provide a specific failure reason',$html);
        }
        Http::assertNothingSent();
        $this->assertSame(0,$admission->payments()->count());
    }
}
