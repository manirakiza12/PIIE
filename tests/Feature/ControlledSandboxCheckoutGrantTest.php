<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use PiieSandbox\CheckoutGrant;
require_once __DIR__.'/../../scripts/sandbox/CheckoutGrant.php';
final class ControlledSandboxCheckoutGrantTest extends TestCase
{
    private string $root;
    private CheckoutGrant $grant;
    protected function setUp(): void {$this->root=sys_get_temp_dir().'/piie-grant-'.bin2hex(random_bytes(8));mkdir($this->root);$this->grant=new CheckoutGrant($this->root,'checkout-grant-visa');$this->grant->mutate(fn()=>['version'=>2,'application_id'=>7,'application_number'=>'PSS-2627-S-P0005','database'=>'piie_sandbox_7e0cae6b2d2eaa4e','port'=>3307,'single_use'=>true,'max_provider_orders'=>1,'max_payment_attempts'=>1,'school_id'=>1,'applicant_id'=>9,'amount'=>'50000.00','currency'=>'UGX','expires_at'=>time()+120,'heartbeat_at'=>time(),'state'=>'armed']);}
    protected function tearDown(): void {foreach(glob($this->root.'/*') as $f)unlink($f);rmdir($this->root);}
    public function test_wrong_owner_and_failed_preflight_do_not_consume_grant(): void
    {
        foreach([8,9] as $owner){try{$this->grant->consume($owner,fn()=>throw new \RuntimeException());$this->fail();}catch(\RuntimeException){$this->assertSame('armed',$this->grant->read()['state']);}}
    }
    public function test_only_one_consumer_and_one_provider_submission_are_permitted(): void
    {
        $this->grant->consume(9,fn()=>null);$this->assertSame('consumed',$this->grant->read()['state']);
        try{$this->grant->consume(9,fn()=>null);$this->fail();}catch(\RuntimeException){$this->addToAssertionCount(1);}
        $this->grant->reserveSubmission();$this->assertTrue($this->grant->read()['submission_used']);
        $this->expectException(\RuntimeException::class);$this->grant->reserveSubmission();
    }
    public function test_expired_revoked_wrong_amount_and_dead_supervisor_grants_are_denied(): void
    {
        $g=$this->grant->read();
        foreach([['expires_at'=>time()-1],['heartbeat_at'=>time()-46],['state'=>'revoked'],['state'=>'prepared'],['application_id'=>1],['application_id'=>6],['school_id'=>2],['database'=>'piie_main'],['port'=>3306],['amount'=>'1.00'],['currency'=>'USD'],['max_provider_orders'=>2]] as $change)$this->assertFalse(CheckoutGrant::live(array_replace($g,$change)));
    }
    public function test_concurrent_consumption_has_exactly_one_winner(): void
    {
        $helper=$this->root.'/child.php';$library=realpath(__DIR__.'/../../scripts/sandbox/CheckoutGrant.php');
        file_put_contents($helper,'<?php require '.var_export($library,true).'; try {(new \\PiieSandbox\\CheckoutGrant($argv[1],"checkout-grant-visa"))->consume(9,fn()=>null); exit(0);} catch(Throwable $e){exit(3);}');
        $children=[];for($i=0;$i<5;$i++)$children[]=proc_open([PHP_BINARY,$helper,$this->root],[0=>['file','NUL','r'],1=>['file','NUL','a'],2=>['file','NUL','a']],$pipes);
        $codes=array_map(fn($p)=>proc_close($p),$children);sort($codes);$this->assertSame([0,3,3,3,3],$codes);
    }
}
