<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Feature\Support\AdmissionsTestHelper;
use PiieSandbox\ControlledCheckout as Control;
use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use Illuminate\Support\Facades\Http;
require_once __DIR__.'/../../scripts/sandbox/ControlledCheckout.php';
require_once __DIR__.'/../../scripts/sandbox/ApplicantWorkflowGuard.php';
final class ControlledSandboxCheckoutBoundaryTest extends TestCase
{
    use AdmissionsTestHelper;
    private string $root;private string $oldRoot;private Admission $a;private int $configuration;
    private const GUID='aaaaaaaa-883e-440f-a63e-e1105bbfadc3';
    protected function setUp():void {
        parent::setUp();$this->bootAdmissionsTestSchema();$school=$this->makeSchool();$this->assertSame(1,$school);
        $owner=$this->makeApplicant(1,['email'=>'sandbox-journey-fixture@example.test']);$this->be($owner,'applicant');
        $this->a=Admission::findOrFail($this->makeAdmission(1,['id'=>6,'app_number'=>'PSS-2627-S-P0004','applicant_id'=>$owner->id,'status'=>'submitted','submitted_at'=>now(),'application_fee_amount'=>'50000.00','application_fee_currency'=>'UGX']));
        $c=PaymentMethods::create(['school_id'=>1,'name'=>'pesapal','status'=>1,'payment_keys'=>json_encode(['environment'=>'sandbox','consumer_key'=>'fixture','consumer_secret'=>'fixture','notification_id'=>self::GUID])]);$this->configuration=$c->id;
        $this->oldRoot=app()->environmentPath();$this->root=sys_get_temp_dir().'/piie-boundary-'.bin2hex(random_bytes(8));mkdir($this->root);app()->useEnvironmentPath($this->root);
        config(['app.url'=>'https://sandbox.example.test','sandbox.connectivity'=>['origin'=>'https://sandbox.example.test','registration'=>false,'transactions'=>true]]);
        Control::grant()->mutate(fn()=>['application_id'=>6,'school_id'=>1,'applicant_id'=>$owner->id,'amount'=>'50000.00','currency'=>'UGX','expires_at'=>time()+120,'heartbeat_at'=>time(),'state'=>'armed','configuration_id'=>$c->id,'origin'=>'https://sandbox.example.test','database'=>\Illuminate\Support\Facades\DB::connection()->getDatabaseName()]);
        Http::preventStrayRequests();
    }
    protected function tearDown():void {app()->useEnvironmentPath($this->oldRoot);foreach(glob($this->root.'/*') as $f)unlink($f);rmdir($this->root);parent::tearDown();}
    private function payment(int $admission=6):ApplicationPayment {
        return ApplicationPayment::create(['school_id'=>1,'admission_id'=>$admission,'applicant_id'=>$this->a->applicant_id,'method'=>'pesapal','status'=>'pending','amount'=>'50000.00','currency'=>'UGX','reference'=>'APPFEE-'.$admission.'-fixture','gateway_txn_id'=>self::GUID,'gateway_payload'=>['environment'=>'sandbox','configuration_id'=>$this->configuration]]);
    }
    public function test_owner_can_consume_once_and_retry_creates_no_payment():void {
        config(['sandbox.checkout.scope'=>'start']);$this->assertTrue(Control::buttonEnabled($this->a));Control::startGuard($this->a);
        $this->assertFalse(Control::buttonEnabled($this->a));
        try{Control::startGuard($this->a);$this->fail();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertSame(0,ApplicationPayment::count());Http::assertNothingSent();
    }
    public function test_existing_attempt_preflight_does_not_consume_or_create_another_attempt():void {
        $this->payment();config(['sandbox.checkout.scope'=>'start']);
        try{Control::startGuard($this->a);$this->fail();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertSame('armed',Control::grant()->read()['state']);$this->assertSame(1,ApplicationPayment::count());Http::assertNothingSent();
    }
    public function test_foreign_owner_cannot_consume_grant():void {
        $this->be($this->makeApplicant(1,['email'=>'sandbox-journey-foreign@example.test']),'applicant');config(['sandbox.checkout.scope'=>'start']);
        $this->assertFalse(Control::buttonEnabled($this->a));
        try{Control::startGuard($this->a);$this->fail();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertSame('armed',Control::grant()->read()['state']);Http::assertNothingSent();
    }
    public function test_provider_firewall_allows_one_exact_submission_and_no_registration_or_foreign_status():void {
        Control::grant()->consume((int)$this->a->applicant_id,fn()=>null);$p=$this->payment();config(['sandbox.checkout.scope'=>'start']);$calls=0;
        $send=(Control::transportMiddleware())(function()use(&$calls){$calls++;return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200));});
        $base='https://cybqa.pesapal.com/pesapalv3/api/';$body=['id'=>$p->reference,'amount'=>'50000.00','currency'=>'UGX','notification_id'=>self::GUID,'callback_url'=>'https://sandbox.example.test/payments/pesapal/callback'];
        foreach([['POST','URLSetup/RegisterIPN',[]],['POST','Transactions/SubmitOrderRequest',array_replace($body,['amount'=>'1.00'])]] as [$method,$path,$data]) {
            try{$send(new \GuzzleHttp\Psr7\Request($method,$base.$path,[],json_encode($data)),[]);$this->fail();}catch(\RuntimeException){$this->assertSame(0,$calls);}
        }
        $r=new \GuzzleHttp\Psr7\Request('POST',$base.'Transactions/SubmitOrderRequest',[],json_encode($body));$send($r,[])->wait();$this->assertSame(1,$calls);
        try{$send($r,[]);$this->fail();}catch(\RuntimeException){$this->assertSame(1,$calls);}
        config(['sandbox.checkout.scope'=>'verify']);
        try{$send(new \GuzzleHttp\Psr7\Request('GET',$base.'Transactions/GetTransactionStatus?orderTrackingId=bbbbbbbb-883e-440f-a63e-e1105bbfadc3'),[]);$this->fail();}catch(\RuntimeException){$this->assertSame(1,$calls);}
        $send(new \GuzzleHttp\Psr7\Request('GET',$base.'Transactions/GetTransactionStatus?orderTrackingId='.self::GUID),[])->wait();$this->assertSame(2,$calls);
    }
    public function test_callback_for_foreign_reference_never_reaches_existing_processor():void {
        Control::grant()->consume((int)$this->a->applicant_id,fn()=>null);$this->payment();$called=false;
        try{(new Control)->handle(\Illuminate\Http\Request::create('/payments/pesapal/ipn','GET',['OrderMerchantReference'=>'APPFEE-1-fixture','OrderTrackingId'=>self::GUID]),function()use(&$called){$called=true;});$this->fail();}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertFalse($called);Http::assertNothingSent();
    }
}
