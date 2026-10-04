<?php

namespace Tests\Feature;

use App\Mail\PlatformMailTestMessage;
use App\Mail\GenericStaffPasswordSetupMail;
use App\Models\User;
use App\Support\Mail\PlatformMailIdentity;
use App\Support\Mail\SmtpPasswordSecret;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class SmtpSettingsAdministrationTest extends TestCase
{
    use AdmissionsTestHelper;

    private User $superAdmin;
    private User $schoolAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::create('payment_history', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->nullable();
        });
        if (!Schema::hasTable('password_resets')) {
            Schema::create('password_resets', function (Blueprint $table): void {
                $table->string('email')->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
        $school = $this->makeSchool(['title' => 'SMTP Test School']);
        $this->superAdmin = $this->makeSuperAdmin($school);
        $this->schoolAdmin = $this->makeAdminUser($school);
        Mail::fake();
    }

    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'smtp_protocol' => 'smtp',
            'smtp_crypto' => 'tls',
            'smtp_host' => 'mail.piie.ac.ug',
            'smtp_port' => '587',
            'smtp_user' => 'mailer@piie.ac.ug',
            'smtp_pass' => 'Test-Only-SMTP-Secret-987!',
            'from_email' => 'notifications@piie.ac.ug',
            'from_name' => 'PIIE Notifications',
        ], $overrides);
    }

    private function configureExistingPassword(string $password = 'Old-Test-SMTP-Secret-876!'): string
    {
        $stored = SmtpPasswordSecret::protect($password);
        DB::table('global_settings')->insert([
            ['key' => 'smtp_protocol', 'value' => 'smtp'],
            ['key' => 'smtp_crypto', 'value' => 'tls'],
            ['key' => 'smtp_host', 'value' => 'mail.old.test'],
            ['key' => 'smtp_port', 'value' => '587'],
            ['key' => 'smtp_user', 'value' => 'old-mailer@example.test'],
            ['key' => 'smtp_pass', 'value' => $stored],
            ['key' => 'system_email', 'value' => 'old-from@example.test'],
            ['key' => 'system_title', 'value' => 'Old Platform'],
        ]);

        return $stored;
    }

    public function test_only_super_admin_can_view_or_save_smtp_settings(): void
    {
        $this->actingAs($this->superAdmin)->get(route('superadmin.smtp_settings'))->assertOk();
        $this->actingAs($this->schoolAdmin)->get(route('superadmin.smtp_settings'))->assertRedirect();
        $this->actingAs($this->schoolAdmin)->post(route('superadmin.smtp.update'), $this->validSettings())->assertRedirect();
        auth()->logout();
        $this->post(route('superadmin.smtp.update'), $this->validSettings())->assertRedirect(route('login'));
    }

    public function test_smtp_tls_port_587_configuration_saves_to_runtime_global_settings_atomically(): void
    {
        $response = $this->actingAs($this->superAdmin)->from(route('superadmin.smtp_settings'))
            ->post(route('superadmin.smtp.update'), $this->validSettings())
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHas('message');

        $this->assertSame('smtp', DB::table('global_settings')->where('key', 'smtp_protocol')->value('value'));
        $this->assertSame('tls', DB::table('global_settings')->where('key', 'smtp_crypto')->value('value'));
        $this->assertSame('mail.piie.ac.ug', DB::table('global_settings')->where('key', 'smtp_host')->value('value'));
        $this->assertSame('587', DB::table('global_settings')->where('key', 'smtp_port')->value('value'));
        $this->assertSame('mailer@piie.ac.ug', DB::table('global_settings')->where('key', 'smtp_user')->value('value'));
        $storedPassword = DB::table('global_settings')->where('key', 'smtp_pass')->value('value');
        $this->assertTrue(SmtpPasswordSecret::isProtected($storedPassword));
        $this->assertSame('Test-Only-SMTP-Secret-987!', SmtpPasswordSecret::reveal($storedPassword));
        $this->assertSame('notifications@piie.ac.ug', DB::table('global_settings')->where('key', 'system_email')->value('value'));
        $this->assertSame('PIIE Notifications', DB::table('global_settings')->where('key', 'system_title')->value('value'));
        $this->assertFileDoesNotExist(base_path('config/config.json'));
        $this->assertStringNotContainsString('Test-Only-SMTP-Secret-987!', $response->getContent());
        Mail::assertNothingOutgoing();
    }

    public function test_first_configuration_requires_password_and_validation_is_human_and_secret_safe(): void
    {
        $payload = $this->validSettings(['smtp_pass' => 'Validation-Only-Secret-456!', 'smtp_port' => '70000']);
        unset($payload['smtp_pass']);

        $response = $this->actingAs($this->superAdmin)->from(route('superadmin.smtp_settings'))
            ->from(route('superadmin.smtp_settings'))
            ->post(route('superadmin.smtp.update'), $payload)
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHasErrors(['smtp_pass', 'smtp_port'])
            ->assertSessionMissing('_old_input.smtp_pass');

        $messages = implode(' ', session('errors')->all());
        $this->assertStringContainsString('Enter the SMTP password for the first configuration.', $messages);
        $this->assertStringContainsString('between 1 and 65535', $messages);
        $this->assertStringNotContainsString('smtp_port', $messages);
        $this->assertStringNotContainsString('Validation-Only-Secret-456!', $response->getContent());
        $this->assertSame(0, DB::table('global_settings')->whereIn('key', ['smtp_host', 'smtp_pass'])->count());
    }

    public function test_invalid_protocol_crypto_host_port_username_and_sender_are_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin)->from(route('superadmin.smtp_settings'))
            ->from(route('superadmin.smtp_settings'))
            ->post(route('superadmin.smtp.update'), $this->validSettings([
                'smtp_protocol' => 'sendmail',
                'smtp_crypto' => 'plain',
                'smtp_host' => '',
                'smtp_port' => 'zero',
                'smtp_user' => 'not-a-mailbox',
                'from_email' => 'invalid-sender',
            ]))
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHasErrors(['smtp_protocol', 'smtp_crypto', 'smtp_host', 'smtp_port', 'smtp_user', 'from_email']);

        $messages = implode(' ', session('errors')->all());
        $this->assertStringContainsString('The mail protocol must be SMTP.', $messages);
        $this->assertStringContainsString('Select TLS or SSL encryption.', $messages);
        $this->assertStringContainsString('Enter the SMTP host.', $messages);
        $this->assertStringContainsString('Enter a valid SMTP mailbox email address.', $messages);
        $this->assertStringContainsString('Enter a valid platform sender email address.', $messages);
        $this->assertStringNotContainsString('smtp_host', $messages);
        $this->assertStringNotContainsString('Test-Only-SMTP-Secret-987!', $response->getContent());
    }

    public function test_saved_password_is_never_rendered_and_blank_password_preserves_it_on_edit(): void
    {
        $storedBefore = $this->configureExistingPassword();
        $secret = SmtpPasswordSecret::reveal($storedBefore);

        $page = $this->actingAs($this->superAdmin)->get(route('superadmin.smtp_settings'))->assertOk()
            ->assertSee('SMTP password is configured.')
            ->assertSee('type="password"', false)
            ->assertDontSee($secret)
            ->assertDontSee($storedBefore);
        $this->assertStringContainsString('name="smtp_pass" type="password" class="form-control" value=""', $page->getContent());

        $response = $this->post(route('superadmin.smtp.update'), $this->validSettings(['smtp_pass' => '']))
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHas('message');

        $storedAfter = DB::table('global_settings')->where('key', 'smtp_pass')->value('value');
        $this->assertSame($storedBefore, $storedAfter);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        Mail::assertNothingOutgoing();
    }

    public function test_replacement_password_is_protected_and_never_returned_to_the_browser(): void
    {
        $this->configureExistingPassword();
        $newSecret = 'Replacement-Test-Secret-654!';

        $response = $this->actingAs($this->superAdmin)->from(route('superadmin.smtp_settings'))
            ->post(route('superadmin.smtp.update'), $this->validSettings(['smtp_pass' => $newSecret]))
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHas('message');

        $stored = DB::table('global_settings')->where('key', 'smtp_pass')->value('value');
        $this->assertNotSame($newSecret, $stored);
        $this->assertSame($newSecret, SmtpPasswordSecret::reveal($stored));
        $this->assertStringNotContainsString($newSecret, $response->getContent());
        $this->assertStringNotContainsString($newSecret, json_encode(session()->all()));
        $page = $this->get(route('superadmin.smtp_settings'))->assertOk();
        $this->assertStringNotContainsString($newSecret, $page->getContent());
        Mail::assertNothingOutgoing();
    }

    public function test_storage_failure_rolls_back_and_returns_safe_human_response(): void
    {
        DB::statement("CREATE TRIGGER reject_smtp_settings BEFORE INSERT ON global_settings BEGIN SELECT RAISE(ABORT, 'private smtp storage failure'); END");
        $secret = 'No-Leak-Storage-Secret-123!';
        Log::spy();

        $response = $this->actingAs($this->superAdmin)
            ->from(route('superadmin.smtp_settings'))
            ->post(route('superadmin.smtp.update'), $this->validSettings(['smtp_pass' => $secret]))
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHasErrors('mail_settings');

        $this->assertSame(
            "We couldn't save the email settings. Please review the highlighted fields and try again.",
            session('errors')->first('mail_settings')
        );
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString('private smtp storage failure', $response->getContent());
        $this->assertStringNotContainsString($secret, json_encode(session()->all()));
        $this->assertSame(0, DB::table('global_settings')->whereIn('key', ['smtp_host', 'smtp_pass'])->count());
        Log::shouldHaveReceived('error')->once()->with('Platform mail settings operation failed', Mockery::on(function (array $context) use ($secret): bool {
            $serialized = json_encode($context);

            return ($context['operation'] ?? null) === 'save'
                && isset($context['exception'])
                && !str_contains($serialized, $secret)
                && !str_contains($serialized, 'private smtp storage failure');
        }));
        Mail::assertNothingOutgoing();
    }

    public function test_sender_resolution_prefers_platform_identity_and_uses_only_valid_fallbacks(): void
    {
        $this->configureExistingPassword();
        $response = $this->actingAs($this->superAdmin)->from(route('superadmin.smtp_settings'))
            ->post(route('superadmin.smtp.test-email'), ['recipient_email' => 'test-recipient@example.test'])
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHas('message');

        Mail::assertSent(PlatformMailTestMessage::class);
        $testMail = Mail::sent(PlatformMailTestMessage::class)->first();
        $testMail->build();
        $this->assertTrue($testMail->hasTo('test-recipient@example.test'));
        $this->assertSame('old-from@example.test', $testMail->from[0]['address']);
        $setupMail = new GenericStaffPasswordSetupMail('Test Staff', 'https://example.test/setup/not-a-real-token');
        $setupMail->build();
        $this->assertSame('old-from@example.test', $setupMail->from[0]['address']);
        $this->assertStringNotContainsString(SmtpPasswordSecret::reveal(DB::table('global_settings')->where('key', 'smtp_pass')->value('value')), $response->getContent());
        $this->assertSame(0, DB::table('password_resets')->count());
    }

    public function test_sender_fallbacks_use_only_configured_valid_addresses(): void
    {
        $this->configureExistingPassword();
        DB::table('global_settings')->where('key', 'system_email')->delete();
        config(['mail.from.address' => 'configured-fallback@example.test']);
        $this->assertSame('configured-fallback@example.test', PlatformMailIdentity::fromAddress());

        config(['mail.from.address' => '']);
        DB::table('global_settings')->where('key', 'smtp_user')->update(['value' => 'not-a-valid-sender']);
        $this->assertNull(PlatformMailIdentity::fromAddress());

        DB::table('global_settings')->where('key', 'smtp_user')->update(['value' => 'valid-smtp-user@example.test']);
        $this->assertSame('valid-smtp-user@example.test', PlatformMailIdentity::fromAddress());
    }

    public function test_test_email_validation_authorization_and_delivery_failure_are_safe(): void
    {
        $this->actingAs($this->schoolAdmin)->post(route('superadmin.smtp.test-email'), [
            'recipient_email' => 'test-recipient@example.test',
        ])->assertRedirect();

        $this->actingAs($this->superAdmin)->from(route('superadmin.smtp_settings'))
            ->post(route('superadmin.smtp.test-email'), ['recipient_email' => 'not-an-email'])
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHasErrors('recipient_email');

        $secret = 'Failure-Test-Secret-321!';
        $syntheticToken = 'Synthetic-Reset-Token-123';
        $syntheticUrl = 'https://example.test/password/reset/'.$syntheticToken;
        Mail::shouldReceive('to')->once()->with('test-recipient@example.test')
            ->andThrow(new TransportException('private transport diagnostic '.$secret.' '.$syntheticUrl));
        DB::table('global_settings')->insert([
            ['key' => 'smtp_host', 'value' => 'mail.failure.test'],
            ['key' => 'smtp_pass', 'value' => SmtpPasswordSecret::protect($secret)],
            ['key' => 'system_email', 'value' => 'platform@example.test'],
            ['key' => 'system_title', 'value' => 'PIIE Tests'],
        ]);

        $response = $this->from(route('superadmin.smtp_settings'))->post(route('superadmin.smtp.test-email'), ['recipient_email' => 'test-recipient@example.test'])
            ->assertRedirect(route('superadmin.smtp_settings'))
            ->assertSessionHas('error');

        $this->assertStringContainsString("couldn't send the test email", strtolower(session('error')));
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString('private transport diagnostic', $response->getContent());
        foreach ([$secret, $syntheticToken, $syntheticUrl, 'private transport diagnostic'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, json_encode(session()->all()));
        }
        $this->assertSame(0, DB::table('password_resets')->count());
    }
}
