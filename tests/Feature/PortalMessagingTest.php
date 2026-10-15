<?php

namespace Tests\Feature;

use App\Mail\PortalMessageReminder;
use App\Models\{Chat, MessageThrade, User};
use App\Support\Messaging\MessagingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Cache, DB, Mail, Schema};
use Illuminate\Support\Str;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class PortalMessagingTest extends TestCase
{
    use AdmissionsTestHelper;
    private int $school;
    private User $student;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('message_thrades', fn (Blueprint $t) => $t->unsignedBigInteger('school_id')->nullable());
        Schema::table('chats', function (Blueprint $t) {
            $t->unsignedBigInteger('school_id')->nullable();
            $t->unsignedBigInteger('sender_id')->nullable();
            $t->text('message')->nullable();
        });
        (require database_path('migrations/2026_10_15_050000_add_messaging_safety_and_delivery_tables.php'))->up();
        $this->school = $this->makeSchool();
        $this->student = $this->person(7, 'Student One');
        $this->teacher = $this->person(3, 'Lecturer One');
        Cache::flush();
    }

    private function person(int $role, string $name = 'Contact', ?int $school = null): User
    {
        return User::factory()->create(['name' => $name, 'role_id' => $role, 'school_id' => $school ?? $this->school,
            'account_status' => 'active', 'user_information' => json_encode(['photo' => ''])]);
    }

    private function thread(?User $a = null, ?User $b = null, ?int $school = null): MessageThrade
    {
        return MessageThrade::create(['school_id' => $school ?? $this->school, 'sender_id' => ($a ?? $this->student)->id, 'reciver_id' => ($b ?? $this->teacher)->id]);
    }

    private function send(MessageThrade $thread, string $text = 'Hello lecturer', ?string $id = null)
    {
        return $this->postJson(route('messaging.send', $thread->id), ['message' => $text, 'client_message_id' => $id ?? (string) Str::uuid()]);
    }

    public function test_mobile_directory_only_exposes_allowed_contacts_and_escapes_names(): void
    {
        $this->person(7, 'Hidden Student');
        $this->person(6, 'Hidden Parent');
        $this->person(1, 'Hidden Platform Admin');
        $this->person(20, 'Hidden Ungranted Support');
        $this->person(3, 'Hidden Foreign Teacher', $this->makeSchool());
        $disabled = $this->person(3, 'Hidden Disabled Teacher'); $disabled->update(['account_status' => 'disable']);
        $this->teacher->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($this->student)->get(route('student.message.chat_empty'))->assertOk()
            ->assertSee('Search contacts or your message history')->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Hidden Student')
            ->assertDontSee('Hidden Parent')->assertDontSee('Hidden Foreign Teacher')->assertDontSee('Hidden Disabled Teacher')
            ->assertDontSee('Hidden Platform Admin')->assertDontSee('Hidden Ungranted Support')->assertDontSee('d-none d-md-block');
    }

    public function test_start_is_csrf_protected_post_and_reuses_reverse_conversations(): void
    {
        $this->actingAs($this->student)->get(route('student.message.messagethrades', $this->teacher->id))->assertRedirect();
        $this->assertSame(0, MessageThrade::count());
        $this->post(route('messaging.start'), ['recipient_id' => $this->teacher->id])->assertRedirect();
        $thread = MessageThrade::firstOrFail();
        $this->actingAs($this->teacher)->post(route('messaging.start'), ['recipient_id' => $this->student->id])->assertRedirect(route('messaging.show', $thread->id));
        $this->assertSame(1, MessageThrade::count());
        $this->get(route('messaging.show', $thread->id))->assertOk()->assertSee('No messages yet');
    }

    public function test_students_cannot_start_peer_parent_self_or_foreign_conversations(): void
    {
        $this->actingAs($this->student);
        foreach ([$this->person(7), $this->person(6), $this->student] as $contact) {
            $this->postJson(route('messaging.start'), ['recipient_id' => $contact->id])->assertForbidden();
        }
        $foreign = $this->person(3, 'Foreign', $this->makeSchool());
        $this->postJson(route('messaging.start'), ['recipient_id' => $foreign->id])->assertNotFound();
        $this->assertSame(0, MessageThrade::count());
    }

    public function test_institution_staff_contacts_are_allowed_and_guardians_are_separate(): void
    {
        foreach ([2, 3, 4, 5, 10] as $role) {
            $staff = $this->person($role);
            $this->actingAs($this->student)->post(route('messaging.start'), ['recipient_id' => $staff->id])->assertRedirect();
        }
        $parent = $this->person(6);
        $this->actingAs($parent)->post(route('messaging.start'), ['recipient_id' => $this->teacher->id])->assertRedirect();
        $this->postJson(route('messaging.start'), ['recipient_id' => $this->student->id])->assertForbidden();
    }

    public function test_nonparticipants_cannot_read_poll_send_ack_archive_block_or_report(): void
    {
        $thread = $this->thread($this->person(7), $this->teacher);
        $this->actingAs($this->student);
        foreach (['messaging.show', 'messaging.poll'] as $route) $this->getJson(route($route, $thread->id))->assertNotFound();
        foreach (['messaging.read' => ['through' => 1], 'messaging.archive' => ['archived' => 1], 'messaging.block' => ['blocked' => 1],
            'messaging.report' => ['reason' => 'Attempt to read a different thread'], 'messaging.typing' => []] as $route => $body) {
            $this->postJson(route($route, $thread->id), $body)->assertNotFound();
        }
        $this->send($thread)->assertNotFound();
        $this->assertSame(0, Chat::count());
    }

    public function test_cross_school_and_corrupt_participant_threads_fail_closed(): void
    {
        $otherSchool = $this->makeSchool();
        $foreignTeacher = $this->person(3, 'Foreign Teacher', $otherSchool);
        $foreign = $this->thread($this->student, $foreignTeacher, $otherSchool);
        $corrupt = $this->thread($this->student, $foreignTeacher);
        $this->actingAs($this->student);
        foreach ([$foreign, $corrupt] as $thread) {
            $this->getJson(route('messaging.show', $thread->id))->assertNotFound();
            $this->send($thread)->assertNotFound();
        }
    }

    public function test_every_legacy_portal_enforces_participant_checks_and_recipient_integrity(): void
    {
        foreach ([2 => 'admin', 3 => 'teacher', 4 => 'accountant', 5 => 'librarian', 6 => 'parent', 7 => 'student', 10 => 'warden'] as $role => $prefix) {
            $actor = $this->person($role);
            $thread = $this->thread();
            $this->actingAs($actor)->getJson(route($prefix.'.message.all_message', $thread->id))->assertNotFound();
            $this->postJson(route($prefix.'.message.chat_save'), ['message_thrade' => $thread->id, 'reciver_id' => $this->teacher->id, 'message' => 'Forged'])->assertNotFound();
            $owned = $this->thread($actor, $this->teacher);
            $this->postJson(route($prefix.'.message.chat_save'), ['message_thrade' => $owned->id, 'reciver_id' => $this->student->id, 'message' => 'Forged'])->assertUnprocessable();
        }
        $this->assertSame(0, Chat::count());
    }

    public function test_persisted_messages_ignore_forged_sender_and_are_idempotent(): void
    {
        $thread = $this->thread(); $id = (string) Str::uuid();
        $this->actingAs($this->student)->postJson(route('messaging.send', $thread->id), [
            'message' => 'Private content', 'client_message_id' => $id, 'sender_id' => $this->teacher->id,
            'reciver_id' => $this->student->id, 'school_id' => $this->makeSchool(),
        ])->assertCreated()->assertJsonPath('message.text', 'Private content');
        $this->send($thread, 'Private content', $id)->assertCreated();
        $this->send($thread, 'Changed replay', $id)->assertUnprocessable();
        $chat = Chat::firstOrFail();
        $this->assertSame($this->student->id, (int) $chat->sender_id);
        $this->assertSame($this->teacher->id, (int) $chat->reciver_id);
        $this->assertSame($this->school, (int) $chat->school_id);
        $this->assertSame(1, Chat::count());
        $this->assertSame(1, DB::table('user_notifications')->where('type', 'portal_message')->count());
        $this->assertSame(1, DB::table('messaging_email_deliveries')->count());
        $this->assertStringNotContainsString('Private content', DB::table('audit_logs')->get()->toJson());
    }

    public function test_poll_acknowledges_only_recipient_messages_and_filters_forged_legacy_rows(): void
    {
        $thread = $this->thread();
        $this->actingAs($this->teacher); $this->send($thread, 'To the student')->assertCreated();
        $first = Chat::firstOrFail();
        $forged = Chat::create(['school_id' => $this->school, 'message_thrade' => $thread->id,
            'sender_id' => $this->person(3)->id, 'reciver_id' => $this->student->id, 'message' => 'Hidden forged row']);
        $this->actingAs($this->student)->getJson(route('messaging.poll', $thread->id))->assertOk()
            ->assertJsonCount(1, 'messages')->assertDontSee('Hidden forged row');
        $this->assertSame(0, (int) $first->fresh()->read_status, 'GET does not mark messages read');
        $this->postJson(route('messaging.read', $thread->id), ['through' => $first->id])->assertOk();
        $this->assertSame(1, (int) $first->fresh()->read_status);
        $this->assertSame(0, (int) $forged->fresh()->read_status);
        $this->getJson(route('messaging.unread'))->assertJsonPath('count', 0);
        $this->assertNotNull(DB::table('user_notifications')->where('user_id', $this->student->id)->value('read_at'));
    }

    public function test_blank_oversized_and_invalid_replay_ids_are_rejected(): void
    {
        $thread = $this->thread(); $this->actingAs($this->student);
        $this->send($thread, '  ')->assertUnprocessable();
        $this->send($thread, str_repeat('a', 5001))->assertUnprocessable();
        $this->send($thread, 'Hello', 'not-a-uuid')->assertUnprocessable();
        $this->assertSame(0, Chat::count());
    }

    public function test_block_stops_both_directions_but_keeps_history_and_can_be_removed_only_by_owner(): void
    {
        $thread = $this->thread();
        $this->actingAs($this->student); $this->send($thread, 'Historical message')->assertCreated();
        $this->post(route('messaging.block', $thread->id), ['blocked' => 1])->assertRedirect();
        $this->send($thread)->assertForbidden();
        $this->actingAs($this->teacher); $this->send($thread)->assertForbidden();
        $this->post(route('messaging.block', $thread->id), ['blocked' => 0])->assertRedirect();
        $this->send($thread)->assertForbidden();
        $this->get(route('messaging.show', $thread->id))->assertOk()->assertSee('Historical message');
        $this->actingAs($this->student)->post(route('messaging.block', $thread->id), ['blocked' => 0])->assertRedirect();
        $this->send($thread)->assertCreated();
    }

    public function test_personal_archive_is_searchable_and_a_new_message_restores_the_inbox(): void
    {
        $thread = $this->thread();
        $this->actingAs($this->student); $this->send($thread, 'Coursework deadline')->assertCreated();
        $this->post(route('messaging.archive', $thread->id), ['archived' => 1])->assertRedirect();
        $this->get(route('messaging.index'))->assertOk()->assertDontSee('Coursework deadline');
        $this->get(route('messaging.index', ['archive' => 1, 'q' => 'deadline']))->assertOk()->assertSee('Coursework deadline');
        $this->actingAs($this->teacher); $this->send($thread, 'Reply to coursework')->assertCreated();
        $this->actingAs($this->student)->get(route('messaging.index'))->assertOk()->assertSee('Reply to coursework');
        $this->assertSame(2, Chat::count());
    }

    public function test_reports_are_reviewable_only_by_authorised_same_school_staff_and_are_audited(): void
    {
        $thread = $this->thread();
        $this->actingAs($this->student); $this->send($thread, 'Evidence to preserve')->assertCreated();
        $this->post(route('messaging.report', $thread->id), ['reason' => 'Please investigate this conversation', 'chat_id' => Chat::first()->id])->assertRedirect();
        $id = DB::table('messaging_reports')->value('id');
        $this->getJson(route('messaging.moderation'))->assertForbidden();
        $this->actingAs($this->teacher)->getJson(route('messaging.moderation.show', $id))->assertForbidden();
        $foreignAdmin = $this->makeAdminUser($this->makeSchool());
        $this->actingAs($foreignAdmin)->getJson(route('messaging.moderation.show', $id))->assertNotFound();
        $admin = $this->makeAdminUser($this->school);
        $this->actingAs($admin)->get(route('messaging.moderation.show', $id))->assertOk()->assertSee('Evidence to preserve');
        $this->post(route('messaging.review', $id), ['status' => 'resolved', 'review_note' => 'Discussed concern with both participants'])->assertRedirect();
        $this->assertSame('resolved', DB::table('messaging_reports')->where('id', $id)->value('status'));
        $this->assertSame(1, Chat::count());
        $this->assertTrue(DB::table('audit_logs')->where('description', 'like', '%moderator accessed%')->exists());
    }

    public function test_unread_reminders_are_delayed_coalesced_and_contain_no_message_text(): void
    {
        Mail::fake(); $this->enableSmtpSettings(); $thread = $this->thread();
        $this->actingAs($this->student); $this->send($thread, 'Private first message')->assertCreated();
        $this->send($thread, 'Private second message')->assertCreated();
        $this->artisan('messages:send-reminders')->assertSuccessful(); Mail::assertNothingSent();
        $this->travel(3)->minutes();
        $this->artisan('messages:send-reminders')->assertSuccessful();
        Mail::assertSent(PortalMessageReminder::class, 1);
        Mail::assertSent(PortalMessageReminder::class, function ($mail) {
            $html = $mail->render();
            return $mail->hasTo($this->teacher->email) && !str_contains($html, 'Private') && !str_contains($html, $this->student->email);
        });
        $this->artisan('messages:send-reminders')->assertSuccessful(); Mail::assertSent(PortalMessageReminder::class, 1);
    }

    public function test_read_or_blocked_messages_do_not_generate_offline_mail(): void
    {
        Mail::fake(); $this->enableSmtpSettings(); $thread = $this->thread();
        $this->actingAs($this->student); $this->send($thread)->assertCreated();
        $this->actingAs($this->teacher)->postJson(route('messaging.read', $thread->id), ['through' => Chat::first()->id])->assertOk();
        $this->travel(3)->minutes(); $this->artisan('messages:send-reminders')->assertSuccessful();
        Mail::assertNothingSent();
        $this->actingAs($this->student); $this->send($thread)->assertCreated();
        $this->post(route('messaging.block', $thread->id), ['blocked' => 1])->assertRedirect();
        $this->travel(3)->minutes(); $this->artisan('messages:send-reminders')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_pending_email_survives_missing_smtp_and_active_readers_do_not_get_mail(): void
    {
        Mail::fake(); $thread = $this->thread(); $this->actingAs($this->student); $this->send($thread)->assertCreated();
        $this->travel(3)->minutes(); $this->artisan('messages:send-reminders')->assertSuccessful();
        $this->assertSame('pending', DB::table('messaging_email_deliveries')->value('status'));
        $this->enableSmtpSettings(); app(MessagingService::class)->presence($this->teacher);
        $this->artisan('messages:send-reminders')->assertSuccessful(); Mail::assertNothingSent();
    }

    public function test_forced_password_setup_and_disabled_users_cannot_bypass_portal_guards(): void
    {
        $this->student->update(['force_password_change' => true]);
        $this->actingAs($this->student)->getJson(route('messaging.index'))->assertForbidden();
        $this->student->update(['force_password_change' => false, 'account_status' => 'disable']);
        $this->getJson(route('messaging.index'))->assertForbidden();
        $this->actingAs($this->person(20))->getJson(route('messaging.index'))->assertForbidden();
        $this->actingAs($this->person(1))->getJson(route('messaging.index'))->assertForbidden();
    }

    public function test_support_access_requires_an_institution_scoped_grant_and_revocation_stops_sends(): void
    {
        (require database_path('migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        $support = $this->person(20, 'Support Officer');
        DB::table('user_permissions')->insert(['school_id' => $this->makeSchool(), 'user_id' => $support->id, 'permission' => 'messaging.use']);
        $this->actingAs($support)->getJson(route('messaging.index'))->assertForbidden();
        DB::table('user_permissions')->where('user_id', $support->id)->delete();
        DB::table('user_permissions')->insert(['school_id' => $this->school, 'user_id' => $support->id, 'permission' => 'messaging.use']);
        $this->post(route('messaging.start'), ['recipient_id' => $this->student->id])->assertRedirect();
        $thread = MessageThrade::firstOrFail();
        $this->send($thread, 'Support reply')->assertCreated();
        DB::table('user_permissions')->where('school_id', $this->school)->where('user_id', $support->id)->delete();
        $this->send($thread)->assertForbidden();
        $this->actingAs($this->student); $this->send($thread)->assertForbidden();
        $this->get(route('messaging.show', $thread->id))->assertOk()->assertSee('Support reply');
    }

    public function test_pagination_and_read_ack_do_not_touch_other_conversations(): void
    {
        $thread = $this->thread(); $unrelated = $this->thread($this->student, $this->person(3));
        for ($i = 1; $i <= 55; $i++) {
            Chat::create(['school_id' => $this->school, 'message_thrade' => $thread->id, 'sender_id' => $this->teacher->id,
                'reciver_id' => $this->student->id, 'message' => 'Message '.$i, 'read_status' => 0]);
        }
        $separate = Chat::create(['school_id' => $this->school, 'message_thrade' => $unrelated->id,
            'sender_id' => $unrelated->reciver_id, 'reciver_id' => $this->student->id, 'message' => 'Different conversation', 'read_status' => 0]);
        $this->actingAs($this->student);
        $this->getJson(route('messaging.poll', ['id' => $thread->id, 'before' => 6]))->assertOk()->assertJsonCount(5, 'messages');
        $this->getJson(route('messaging.poll', ['id' => $thread->id, 'after' => 50]))->assertOk()->assertJsonCount(5, 'messages');
        $this->postJson(route('messaging.read', $thread->id), ['through' => $separate->id])->assertUnprocessable();
        $this->postJson(route('messaging.read', $thread->id), ['through' => 55])->assertOk();
        $this->assertSame(0, (int) $separate->fresh()->read_status);
    }

    public function test_mail_transport_failure_is_retried_without_losing_the_message(): void
    {
        $this->enableSmtpSettings(); $thread = $this->thread();
        $this->actingAs($this->student); $this->send($thread)->assertCreated();
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('Synthetic mail failure'));
        $this->travel(3)->minutes();
        $this->artisan('messages:send-reminders')->assertSuccessful();
        $this->assertSame('pending', DB::table('messaging_email_deliveries')->value('status'));
        $this->travel(11)->minutes(); $this->artisan('messages:send-reminders')->assertSuccessful();
        $this->travel(11)->minutes(); $this->artisan('messages:send-reminders')->assertSuccessful();
        $this->assertSame('failed', DB::table('messaging_email_deliveries')->value('status'));
        $this->assertSame(3, DB::table('messaging_email_deliveries')->value('attempts'));
        $this->assertSame(1, Chat::count());
    }

    public function test_rate_limit_and_code_rollback_preserve_original_history(): void
    {
        $thread = $this->thread(); $this->actingAs($this->student);
        for ($i = 0; $i < 30; $i++) $this->send($thread)->assertCreated();
        $this->send($thread)->assertTooManyRequests();
        $before = Chat::orderBy('id')->get()->toJson();
        (require database_path('migrations/2026_10_15_050000_add_messaging_safety_and_delivery_tables.php'))->down();
        $this->assertSame($before, Chat::orderBy('id')->get()->toJson());
        $this->assertTrue(Schema::hasTable('messaging_reports'));
    }

    public function test_polling_and_typing_do_not_consume_the_send_rate_limit(): void
    {
        $thread = $this->thread(); $this->actingAs($this->student);
        for ($i = 0; $i < 35; $i++) $this->getJson(route('messaging.poll', $thread->id))->assertOk();
        for ($i = 0; $i < 10; $i++) $this->postJson(route('messaging.typing', $thread->id))->assertOk();
        $this->send($thread)->assertCreated();
    }
}
