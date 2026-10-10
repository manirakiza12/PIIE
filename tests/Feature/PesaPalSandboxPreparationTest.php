<?php

namespace Tests\Feature;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/sandbox/IsolatedConfiguration.php';

class PesaPalSandboxPreparationTest extends TestCase
{
    private function connection(): array
    {
        return ['driver'=>'mysql', 'host'=>'127.0.0.1', 'port'=>3307,
            'database'=>'piie_sandbox_0123456789abcdef', 'username'=>'piie_sb_0123456789abcdef', 'password'=>'synthetic-only'];
    }

    public static function unsafe(): array
    {
        return [[['port'=>3306]], [['host'=>'production.example.test']], [['database'=>'piie_main']],
            [['username'=>'root']], [['password'=>'']], [['url'=>'mysql://production.example.test']], [['unix_socket'=>'synthetic-socket']]];
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_database_settings_fail_before_connecting(array $change): void
    {
        $this->expectException(\RuntimeException::class);
        \PiieSandbox\IsolatedConfiguration::overrides(array_replace($this->connection(),$change),'synthetic-storage','https://sandbox.example.test');
    }

    public function test_sessions_cache_logs_and_uploads_have_separate_paths_and_connections(): void
    {
        $settings=\PiieSandbox\IsolatedConfiguration::overrides($this->connection(),'synthetic-storage','http://127.0.0.1:8002');
        $this->assertSame(['mysql'],array_keys($settings['database.connections']));
        $this->assertSame('local',$settings['app.env']);
        $this->assertFalse($settings['app.debug']);
        $this->assertFalse($settings['app.bypass_subscription']);
        $this->assertSame('piie_sandbox_session',$settings['session.cookie']);
        foreach (['session.files','view.compiled','logging.channels.emergency.path'] as $key) $this->assertStringStartsWith('synthetic-storage/',$settings[$key]);
        $this->assertSame('array',$settings['mail.default']);
        $this->assertSame('null',$settings['logging.default']);
    }

    public function test_http_origins_and_userinfo_cannot_be_used_for_public_urls(): void
    {
        foreach (['http://production.example.test','http://127.0.0.1:8000','http://127.0.0.1:8001','https://user@sandbox.example.test','https://sandbox.example.test/path'] as $origin) {
            try { \PiieSandbox\IsolatedConfiguration::overrides($this->connection(),'synthetic-storage',$origin); $this->fail('Unsafe origin accepted'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_mail_veto_http_block_and_https_signed_urls_are_applied_only_to_test_application(): void
    {
        config(['app.url'=>'https://sandbox.example.test']);
        \PiieSandbox\IsolatedConfiguration::protectOutbound($this->app);
        $this->assertSame('array',config('mail.default'));
        config(['mail.default'=>'smtp']); // Even a later SMTP override cannot remove the event veto.
        $this->assertFalse(app('events')->until(new MessageSending((new Email)->text('Synthetic only'))));
        $link=URL::temporarySignedRoute('applicant.pesapal.invitation',now()->addMinutes(5),['admission'=>101]);
        $this->assertTrue(str_starts_with($link,'https://sandbox.example.test/payments/application/101?'));
        $this->assertSame('https://sandbox.example.test/payments/pesapal/ipn',route('applicant.pesapal.ipn'));
        try { Http::post('https://cybqa.pesapal.com/pesapalv3/api/Auth/RequestToken',[]); $this->fail('External HTTP not blocked'); }
        catch (\RuntimeException $e) { $this->assertSame('External requests disabled in sandbox preparation.',$e->getMessage()); }
    }
}
