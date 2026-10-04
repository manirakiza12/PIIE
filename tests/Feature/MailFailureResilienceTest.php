<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Mail\SafeMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Stability repair — a mail provider that fails (SMTP down, wrong credentials)
 * must not turn an already-completed business action into HTTP 500. The action
 * is kept, the failure is logged server-side (no address, no content), and the
 * existing resend actions remain the retry path.
 */
class MailFailureResilienceTest extends TestCase
{
    use StaffModuleTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();

        Mail::extend('failing', fn () => new class extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection could not be established with host smtp.example.test :stream_socket_client(): unable to connect');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing',
            'mail.from' => ['address' => 'noreply@example.test', 'name' => 'PIIE Test']]);
    }

    public function test_staff_creation_survives_a_failing_mail_provider(): void
    {
        $this->enableSmtpSettings();
        Log::spy();
        $school = $this->makeSchool();
        $admin = $this->makeAdminUser($school);

        $response = $this->actingAs($admin)->from('/admin/teacher')->post(route('admin.teacher.create'), [
            'email' => 'mail-outage@example.test', 'first_name' => 'Mail', 'last_name' => 'Outage', 'gender' => 'Male',
            'blood_group' => 'o+', 'birthday' => '01/01/1990', 'phone' => '0700', 'address' => 'x',
        ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertRedirect('/admin/teacher')->assertSessionHas('message');
        $this->assertTrue(User::where('email', 'mail-outage@example.test')->exists(), 'the account was created and kept');
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_contains($message, 'Mail delivery failed')
            && $context['purpose'] === 'staff-credentials' && $context['exception'] === TransportException::class
            && !str_contains(json_encode($context), 'mail-outage@example.test'))->once();
    }

    public function test_safe_mail_only_absorbs_transport_failures(): void
    {
        $this->actingAs($this->makeAdminUser($this->makeSchool()));   // the e-mail template reads the signed-in user's school
        $mailable = new \App\Mail\NewUserEmail(['name' => 'X', 'email' => 'x@example.test', 'password' => 'secret']);
        $this->assertFalse(SafeMail::send('x@example.test', $mailable, 'test'));

        config(['mail.default' => 'array']);
        $this->assertTrue(SafeMail::send('x@example.test', $mailable, 'test'));
    }

    public function test_no_post_transaction_mail_send_is_left_unguarded(): void
    {
        $unguarded = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'SafeMail.php')) {
                continue;
            }
            $lines = file($file->getPathname());
            foreach ($lines as $i => $line) {
                if (preg_match('/Mail::to\(.*\)->send\(/', $line)) {
                    $before = implode('', array_slice($lines, max(0, $i - 12), 12));
                    if (!preg_match('/try\s*\{/', $before)) {
                        $unguarded[] = str_replace(app_path(), 'app', $file->getPathname()) . ':' . ($i + 1);
                    }
                }
            }
        }
        $this->assertSame([], $unguarded, 'send through App\\Support\\Mail\\SafeMail or handle transport failures');
    }
}
