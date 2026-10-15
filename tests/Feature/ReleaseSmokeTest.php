<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\MessageThrade;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ReleaseSmokeTest extends TestCase
{
    use AdmissionsTestHelper;
    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        foreach (['assets', 'asset_categories', 'procurement_requests', 'payment_history', 'online_exam_submissions', 'exams'] as $table) {
            Schema::create($table, function (Blueprint $schema) {
                $schema->id(); $schema->unsignedBigInteger('school_id');
                $schema->string('status')->nullable(); $schema->integer('timestamp')->nullable();
                $schema->string('starting_time')->nullable();
            });
        }
        Schema::create('leavelists', function (Blueprint $schema) {
            $schema->id(); $schema->unsignedBigInteger('school_id'); $schema->string('status');
            $schema->date('from_date')->nullable(); $schema->date('to_date')->nullable();
        });
        Schema::table('message_thrades', fn (Blueprint $schema) => $schema->unsignedBigInteger('school_id')->nullable());
        Schema::table('chats', function (Blueprint $schema) {
            $schema->unsignedBigInteger('school_id')->nullable(); $schema->unsignedBigInteger('sender_id')->nullable(); $schema->text('message')->nullable();
        });
        (require database_path('migrations/2026_10_15_050000_add_messaging_safety_and_delivery_tables.php'))->up();
    }

    private function actor(int $role, ?int $school = null): User
    {
        return User::create(['name' => 'Role '.$role, 'email' => uniqid('role'). '@example.test', 'school_id' => $school ?? $this->school,
            'role_id' => $role, 'account_status' => 'active', 'user_information' => json_encode(['photo' => ''])]);
    }

    public static function dashboards(): array
    {
        return [[14, 'Director Dashboard'], [15, 'HR Manager Dashboard'], [16, 'Procurement Dashboard'],
            [17, 'Store Keeper Dashboard'], [18, 'Receptionist Dashboard'], [19, 'Examinations Officer Dashboard']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dashboards')]
    public function test_role_dashboard_renders_with_real_quick_action_urls(int $role, string $heading): void
    {
        $this->actingAs($this->actor($role))->get(route('admin.dashboard'))->assertOk()->assertSee($heading);
    }

    public function test_librarian_can_open_an_empty_conversation_and_send_a_message(): void
    {
        $librarian = $this->actor(5); $recipient = $this->actor(3);
        $this->actingAs($librarian)->get(route('librarian.message.messagethrades', $recipient->id))->assertRedirect();
        $this->assertSame(0, MessageThrade::count(), 'GET links must not create conversations');
        $this->post(route('messaging.start'), ['recipient_id' => $recipient->id])->assertRedirect();
        $thread = MessageThrade::firstOrFail();
        $this->get(route('librarian.message.all_message', $thread->id))->assertOk();
        $this->post(route('librarian.message.chat_save'), ['message_thrade' => $thread->id, 'reciver_id' => $recipient->id, 'message' => 'Example message'])->assertRedirect();
        $this->get(route('librarian.message.all_message', $thread->id))->assertOk()->assertSee('Example message');
    }

    public function test_librarian_cannot_read_or_write_another_conversation(): void
    {
        $librarian = $this->actor(5); $sender = $this->actor(3); $recipient = $this->actor(7);
        $thread = MessageThrade::create(['school_id' => $this->school, 'sender_id' => $sender->id, 'reciver_id' => $recipient->id]);
        $this->actingAs($librarian)->get(route('librarian.message.all_message', $thread->id))->assertNotFound();
        $this->post(route('librarian.message.chat_save'), ['message_thrade' => $thread->id, 'reciver_id' => $recipient->id, 'message' => 'Blocked'])->assertNotFound();
        $this->get(route('librarian.message.messagethrades', $this->actor(3, $this->makeSchool())->id))->assertNotFound();
        $this->assertSame(0, Chat::count());
    }

    public function test_librarian_cannot_change_the_recipient_of_an_owned_thread(): void
    {
        $librarian = $this->actor(5); $recipient = $this->actor(3); $other = $this->actor(7);
        $thread = MessageThrade::create(['school_id' => $this->school, 'sender_id' => $librarian->id, 'reciver_id' => $recipient->id]);
        $this->actingAs($librarian)->post(route('librarian.message.chat_save'), ['message_thrade' => $thread->id, 'reciver_id' => $other->id, 'message' => 'Blocked'])->assertUnprocessable();
        $this->assertSame(0, Chat::count());
    }

    public function test_enabled_addon_flags_do_not_crash_navigation_when_extension_routes_are_absent(): void
    {
        foreach (['hr_management', 'online_courses', 'assignments', 'inventory_manager', 'transport'] as $addon) {
            DB::table('addons')->insert(['unique_identifier' => $addon, 'status' => '1']);
        }
        foreach ([3 => 'teacher', 4 => 'accountant', 5 => 'librarian', 6 => 'parent', 7 => 'student'] as $role => $portal) {
            $this->actingAs($this->actor($role));
            $html = view($portal.'.navigation')->render();
            $this->assertStringContainsString('home-section', $html);
        }
    }

    public function test_student_profile_pdf_supports_slashes_and_uses_the_students_school(): void
    {
        $otherSchool = $this->makeSchool(['title' => 'Student School']);
        $student = $this->actor(7, $otherSchool);
        $student->update(['code' => '2026/05/MPAM/001/']);
        $details = (new \App\Http\Controllers\CommonController())->get_student_details_by_id($student->id);
        $this->assertSame('Student School', $details['school_name']);
        $this->actingAs($this->makeAdminUser($otherSchool))->get(route('admin.student.profile_pdf', $student->id))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_cache_clear_is_never_available_to_guests_or_school_staff(): void
    {
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')->never();
        $this->get(route('clear.cache'))->assertStatus(405);
        $this->post(route('clear.cache'))->assertRedirect(route('login'));
        $this->actingAs($this->actor(2))->post(route('clear.cache'))->assertRedirect();
    }

    public function test_super_admin_can_clear_caches_using_post(): void
    {
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')->once()->with('optimize:clear')->andReturn(0);
        $this->actingAs($this->actor(1))->post(route('clear.cache'))->assertOk()->assertSee('Cache cleared');
    }
}
