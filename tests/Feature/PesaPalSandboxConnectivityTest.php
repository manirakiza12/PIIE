<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use PiieSandbox\OutboundPolicy;
use PiieSandbox\ConnectivitySettings;
use Illuminate\Http\Request;
require_once __DIR__.'/../../scripts/sandbox/OutboundPolicy.php';
require_once __DIR__.'/../../scripts/sandbox/ConnectivitySettings.php';
require_once __DIR__.'/../../scripts/sandbox/TrustFilteringProxy.php';

final class PesaPalSandboxConnectivityTest extends TestCase
{
    public function test_read_only_ipn_scope_excludes_registration_and_all_transactions(): void
    {
        $base='https://cybqa.pesapal.com/pesapalv3/api/';
        foreach ([['registration'=>false,'transactions'=>false],['registration'=>true,'transactions'=>true]] as $settings) {
            foreach (['POST Auth/RequestToken'=>true,'GET URLSetup/GetIpnList'=>true,
                'POST URLSetup/RegisterIPN'=>false,'POST Transactions/SubmitOrderRequest'=>false,
                'GET Transactions/GetTransactionStatus?orderTrackingId=aaaaaaaa-883e-440f-a63e-e1105bbfadc3'=>false,
                'POST URLSetup/GetIpnList'=>false,'GET URLSetup/GetIpnList?url=anything'=>false] as $operation=>$allowed) {
                [$method,$path]=explode(' ',$operation,2);
                try { OutboundPolicy::assertAllowed($method,$base.$path,$settings,true); $this->assertTrue($allowed); }
                catch (\RuntimeException) { $this->assertFalse($allowed); }
            }
        }
        try { OutboundPolicy::assertAllowed('GET',$base.'URLSetup/GetIpnList',['registration'=>false,'transactions'=>false]); $this->fail('Default scope widened.'); }
        catch (\RuntimeException) { $this->addToAssertionCount(1); }
    }

    public function test_tunnel_templates_parse_without_capture_or_extra_public_routes(): void
    {
        $root=dirname(__DIR__,2).'/scripts/sandbox/';
        $agent=\Symfony\Component\Yaml\Yaml::parseFile($root.'ngrok.example.yml');
        $this->assertSame(3,$agent['version']);
        foreach(['log','web_addr','console_ui','remote_management','update_check'] as $key) $this->assertFalse($agent['agent'][$key]);
        $this->assertSame(-1,$agent['agent']['inspect_db_size']);$this->assertSame('REPLACE_PRIVATELY',$agent['agent']['authtoken']);
        $policy=\Symfony\Component\Yaml\Yaml::parseFile($root.'traffic-policy.yml');
        $this->assertSame(['on_http_request'],array_keys($policy));$this->assertCount(1,$policy['on_http_request']);
        $rule=$policy['on_http_request'][0];$this->assertCount(1,$rule['actions']);$this->assertSame('custom-response',$rule['actions'][0]['type']);
        $this->assertSame(404,$rule['actions'][0]['config']['status_code']);
        $expression=$rule['expressions'][0];$this->assertStringContainsString('req.url.raw_path',$expression);$this->assertStringContainsString("req.url.scheme == 'https'",$expression);
        preg_match_all("~'/payments/[^']+'~",$expression,$paths);
        $this->assertSame(["'/payments/application/1'","'/payments/application/2'","'/payments/pesapal/callback'","'/payments/pesapal/ipn'"],$paths[0]);
        $this->assertStringNotContainsString("req.method in ['GET', 'POST']",$expression);
        $this->assertStringContainsString("req.method == 'POST'",$expression);
        $this->assertStringNotContainsString('/applicant/payment/pesapal/start',$expression);
    }
    public function test_explicit_phase_switches_and_exact_endpoint_matrix(): void
    {
        $base='https://cybqa.pesapal.com/pesapalv3/api/';
        foreach ([[false,false],[true,false],[false,true],[true,true]] as [$registration,$transactions]) {
            $settings=['registration'=>$registration,'transactions'=>$transactions];
            foreach (['POST Auth/RequestToken'=>($registration || $transactions),'POST URLSetup/RegisterIPN'=>$registration,
                'POST Transactions/SubmitOrderRequest'=>$transactions,'GET Transactions/GetTransactionStatus?orderTrackingId=aaaaaaaa-883e-440f-a63e-e1105bbfadc3'=>$transactions] as $operation=>$allowed) {
                [$method,$path]=explode(' ',$operation,2);
                try { OutboundPolicy::assertAllowed($method,$base.$path,$settings); $this->assertTrue($allowed); }
                catch (\RuntimeException) { $this->assertFalse($allowed); }
            }
        }
        foreach (['http://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken','https://pay.pesapal.com/v3/api/Auth/RequestToken',
            'https://127.0.0.1/pesapalv3/api/Auth/RequestToken','https://cybqa.pesapal.com.evil.test/pesapalv3/api/Auth/RequestToken',
            'https://secret@cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken','https://cybqa.pesapal.com:8443/pesapalv3/api/Auth/RequestToken',
            $base.'Auth/%52equestToken',$base.'Auth/../Auth/RequestToken',$base.'Auth/RequestToken?redirect=http://127.0.0.1',
            $base.'Auth/RequestToken#fragment',$base.'URLSetup/GetIpnList'] as $url) {
            try { OutboundPolicy::assertAllowed('POST',$url,['registration'=>true,'transactions'=>true]); $this->fail('Unsafe endpoint accepted'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
        foreach (['GET','HEAD','OPTIONS','PUT','DELETE'] as $method) {
            try { OutboundPolicy::assertAllowed($method,$base.'Auth/RequestToken',['registration'=>true,'transactions'=>true]); $this->fail('Unsafe method accepted'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_private_and_reserved_dns_answers_fail_closed(): void
    {
        foreach ([[],['127.0.0.1'],['10.0.0.1'],['169.254.169.254'],['192.168.1.1'],['100.64.0.1'],['::1'],['fe80::1'],['fc00::1'],['8.8.8.8','127.0.0.1']] as $addresses) {
            try { OutboundPolicy::publicAddresses($addresses); $this->fail('Non-public DNS accepted'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(['8.8.8.8'],OutboundPolicy::publicAddresses(['8.8.8.8','8.8.8.8']));
    }

    public function test_transport_overrides_cannot_weaken_tls_redirect_or_dns_policy(): void
    {
        $seen=null;
        $handler=function($request,$options)use(&$seen){$seen=$options;return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200));};
        $send=OutboundPolicy::middleware(['registration'=>true,'transactions'=>false],fn()=>['8.8.8.8'])($handler);
        $send(new \GuzzleHttp\Psr7\Request('POST','https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken'),[
            'verify'=>false,'allow_redirects'=>true,'proxy'=>'http://127.0.0.1','curl'=>[CURLOPT_CONNECT_TO=>['cybqa.pesapal.com:443:127.0.0.1:8000']]])->wait();
        $this->assertTrue($seen['verify']); $this->assertFalse($seen['allow_redirects']); $this->assertSame('',$seen['proxy']);
        $this->assertSame(['cybqa.pesapal.com:443:8.8.8.8'],$seen['curl'][CURLOPT_RESOLVE]); $this->assertArrayNotHasKey(CURLOPT_CONNECT_TO,$seen['curl']);
        $redirect=OutboundPolicy::middleware(['registration'=>true,'transactions'=>false],fn()=>['8.8.8.8'])(fn()=>\GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(302,['Location'=>'https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken'])));
        $this->expectException(\RuntimeException::class);
        $redirect(new \GuzzleHttp\Psr7\Request('POST','https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken'),[])->wait();
    }

    public function test_default_policy_never_resolves_dns_or_calls_transport(): void
    {
        $calls=0;
        $send=OutboundPolicy::middleware(['registration'=>false,'transactions'=>false],function()use(&$calls){$calls++;return ['8.8.8.8'];})(function()use(&$calls){$calls++;});
        try { $send(new \GuzzleHttp\Psr7\Request('POST','https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken'),[]); $this->fail('Default provider access allowed'); }
        catch (\RuntimeException) { $this->assertSame(0,$calls); }
    }

    public function test_invalid_phase_combinations_and_origins_are_refused(): void
    {
        foreach ([['origin'=>'http://127.0.0.1:8002','registration'=>true,'transactions'=>false],
            ['origin'=>'https://127.0.0.1','registration'=>false,'transactions'=>false],
            ['origin'=>'https://sandbox.example.test','registration'=>'true','transactions'=>false]] as $settings) {
            try { ConnectivitySettings::validate($settings); $this->fail('Unsafe settings accepted'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_only_authenticated_loopback_filter_can_validate_https_signatures(): void
    {
        $root=sys_get_temp_dir().'/piie_proxy_test_'.bin2hex(random_bytes(8)); mkdir($root);
        $token=str_repeat('a',64); file_put_contents($root.'/proxy-token',$token);
        $app=new \Illuminate\Foundation\Application(dirname(__DIR__,2)); $app->useEnvironmentPath($root);
        $app->instance('config',new \Illuminate\Config\Repository(['app'=>['url'=>'https://sandbox.example.test']]));
        \Illuminate\Support\Facades\Facade::clearResolvedInstances(); \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $routes=new \Illuminate\Routing\RouteCollection(); $route=new \Illuminate\Routing\Route(['GET','POST'],'payments/application/{admission}',fn()=>null); $route->name('synthetic.invitation'); $routes->add($route);
        $url=new \Illuminate\Routing\UrlGenerator($routes,Request::create('http://127.0.0.1:8002')); $url->setKeyResolver(fn()=>str_repeat('synthetic-key-',4)); $url->forceRootUrl('https://sandbox.example.test'); $url->forceScheme('https');
        try {
            foreach([1,2] as $id) {
                $link=$url->temporarySignedRoute('synthetic.invitation',time()+300,['admission'=>$id,'note'=>'space & percent %']); $parts=parse_url($link);
                $make=fn($tag,$peer='127.0.0.1')=>Request::create('http://sandbox.example.test'.$parts['path'].'?'.$parts['query'],'GET',[],[],[],['REMOTE_ADDR'=>$peer,'HTTP_X_FORWARDED_HOST'=>'sandbox.example.test','HTTP_X_FORWARDED_PROTO'=>'https','HTTP_X_PIIE_SANDBOX_PROXY'=>$tag]);
                $middleware=new \PiieSandbox\TrustFilteringProxy();
                $response=$middleware->handle($make($token),function($request)use($url){$this->assertTrue($url->hasValidSignature($request));$this->assertSame('https',$request->getScheme());$this->assertNull($request->header('X-PIIE-Sandbox-Proxy')); return new \Illuminate\Http\Response('okay');});
                $this->assertSame(200,$response->getStatusCode());
                foreach([[$token,'192.168.1.1'],[str_repeat('b',64),'127.0.0.1'],['','127.0.0.1']] as [$tag,$peer]) $this->assertSame(403,$middleware->handle($make($tag,$peer),fn()=>new \Illuminate\Http\Response('unsafe'))->getStatusCode());
            }
        } finally { unlink($root.'/proxy-token'); rmdir($root); Request::setTrustedProxies([],0); \Illuminate\Support\Facades\Facade::clearResolvedInstances(); }
    }
}
