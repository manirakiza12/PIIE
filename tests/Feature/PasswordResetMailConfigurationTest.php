<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class PasswordResetMailConfigurationTest extends TestCase
{
    use StaffModuleTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        if (! \Illuminate\Support\Facades\Schema::hasTable('password_resets')) {
            \Illuminate\Support\Facades\Schema::create('password_resets', function ($table) {
                $table->string('email')->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
        Notification::fake();
        Mail::fake();
    }

    public function test_password_reset_notification_is_dispatched_with_configured_sender(): void
    {
        $user = User::factory()->create(['role_id' => 2, 'school_id' => $this->makeSchool(['status' => 1]), 'account_status' => 'active']);

        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);

        $this->assertNotEmpty(config('mail.from.address'));
        $this->assertNotSame('hello@example.com', config('mail.from.address'));
    }
}
