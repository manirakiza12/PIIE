<?php

namespace Tests\Feature;

use App\Mail\LiveClassReminderEmail;
use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\LiveClassNotification;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

class LiveClassOfferingNotificationSecurityTest extends TestCase
{
    use LiveClassTestHelper;

    private int $subjectA;
    private int $offeringA;
    private int $parallelOffering;
    private int $foreignOffering;
    private User $primary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00', 'UTC'));
        Schema::table('live_classes', fn (Blueprint $table) => $table->unsignedBigInteger('course_offering_id')->nullable());
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable(); $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->string('reference')->nullable(); $table->string('status'); $table->timestamps();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id');
            $table->unsignedBigInteger('user_id'); $table->string('role'); $table->string('status');
            $table->date('starts_on'); $table->date('ends_on')->nullable();
        });
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_offering_id')->nullable(); $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable(); $table->string('status'); $table->timestamps();
        });

        DB::table('schools')->insert([
            ['id' => 1, 'title' => 'Tenant A'], ['id' => 2, 'title' => 'Tenant B'],
        ]);
        $this->grantActiveFixtureSubscription(1);
        $this->grantActiveFixtureSubscription(2);
        $this->subjectA = $this->subject(1, 'Shared Subject');
        $foreignSubject = $this->subject(2, 'Shared Subject');
        $this->offeringA = $this->offering(1, $this->subjectA);
        $this->parallelOffering = $this->offering(1, $this->subjectA);
        $this->foreignOffering = $this->offering(2, $foreignSubject);
        $this->primary = $this->user('notification-primary@example.test', 3, 1);
        $this->allocation($this->primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_http_publish_notifies_only_confirmed_students_of_the_exact_offering(): void
    {
        $confirmed = $this->student('confirmed-A@example.test', 1);
        $unconfirmed = $this->student('unconfirmed-B@example.test', 1);
        $dropped = $this->student('dropped-C@example.test', 1);
        $unregistered = $this->student('unregistered-D@example.test', 1);
        $parallel = $this->student('parallel-E@example.test', 1);
        $foreign = $this->student('foreign-F@example.test', 2);
        $disabled = $this->student('disabled-G@example.test', 1);
        $this->registration($confirmed, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($unconfirmed, $this->offeringA, CourseRegistration::STATUS_REGISTERED);
        $this->registration($dropped, $this->offeringA, CourseRegistration::STATUS_DROPPED);
        $this->registration($parallel, $this->parallelOffering, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($foreign, $this->foreignOffering, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($disabled, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        DB::table('users')->where('id', $disabled->id)->update(['account_status' => 'disable']);
        foreach ([
            CourseOfferingLecturerAllocation::ROLE_CO_LECTURER,
            CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT,
            CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR,
            CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER,
        ] as $index => $role) {
            $staffRecipient = $this->user($role.'-not-notified-'.$index.'@example.test', 3, 1);
            $this->allocation($staffRecipient, $this->offeringA, $role);
        }
        $class = $this->liveClass(1, $this->offeringA, [
            'is_published' => 0, 'status' => LiveClass::STATUS_DRAFT,
        ]);

        $this->actingAs($this->primary)->post(route('teacher.live_classes.publish', $class->id))->assertRedirect();

        $this->assertSame([$confirmed->id], DB::table('user_notifications')
            ->where('type', 'live_class_published')->orderBy('user_id')->pluck('user_id')->all());
        $notification = DB::table('user_notifications')->where('user_id', $confirmed->id)->first();
        $this->assertSame(1, (int) $notification->school_id);
        $this->assertSame(route('student.live_classes.show', $class->id), $notification->url);
        foreach (['provider-room-secret', 'meeting-password-secret', 'meeting-id-secret', 'recording-provider-secret', 'private-storage-key'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($notification));
        }
        $this->assertSame(0, DB::table('noticeboard')->count());
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'is_published' => 1]);
    }

    public function test_failed_offering_publication_rolls_back_state_and_recipient_notifications(): void
    {
        $student = $this->student('failed-publish-target@example.test', 1);
        $this->registration($student, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $class = $this->liveClass(1, $this->offeringA, ['is_published' => 0, 'status' => LiveClass::STATUS_DRAFT]);
        DB::unprepared("CREATE TRIGGER reject_live_class_notifications BEFORE INSERT ON user_notifications BEGIN SELECT RAISE(ABORT, 'injected notification write failure'); END");

        $response = $this->actingAs($this->primary)->post(route('teacher.live_classes.publish', $class->id));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'is_published' => 0, 'status' => LiveClass::STATUS_DRAFT]);
        $this->assertSame(0, DB::table('user_notifications')->count());
        $this->assertSame(0, DB::table('noticeboard')->count());
    }

    public function test_non_managing_and_parallel_offering_lecturers_cannot_publish_or_notify(): void
    {
        $student = $this->student('publish-target@example.test', 1);
        $this->registration($student, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $class = $this->liveClass(1, $this->offeringA, ['is_published' => 0, 'status' => LiveClass::STATUS_DRAFT]);
        foreach ([
            [CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT, $this->offeringA],
            [CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR, $this->offeringA],
            [CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER, $this->offeringA],
            [CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, $this->parallelOffering],
        ] as [$role, $offeringId]) {
            $lecturer = $this->user($role.'-unauthorized-publish-'.$offeringId.'@example.test', 3, 1);
            $this->allocation($lecturer, $offeringId, $role);
            $this->actingAs($lecturer)->post(route('teacher.live_classes.publish', $class->id))->assertForbidden();
        }
        $foreignLecturer = $this->user('foreign-offering-publisher@example.test', 3, 2);
        $this->allocation($foreignLecturer, $this->foreignOffering, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->actingAs($foreignLecturer)->post(route('teacher.live_classes.publish', $class->id))->assertNotFound();
        $foreignAdmin = $this->user('foreign-tenant-admin-publisher@example.test', 2, 2);
        $this->actingAs($foreignAdmin)->post(route('admin.live_classes.publish', $class->id))->assertNotFound();
        $this->assertSame(0, DB::table('user_notifications')->count());
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'is_published' => 0, 'status' => LiveClass::STATUS_DRAFT]);
    }

    /**
     * RESCHEDULED, CANCELLED, and the security properties around both.
     *
     * This test previously asserted that an update and a cancel emit NOTHING,
     * under the name "...do_not_emit_unimplemented_notifications". That was an
     * accurate description of a gap: a student told "your class is on the 26th"
     * had no way to learn it moved, and no way to learn it was called off. Both
     * events are now implemented and governed, so the test is rewritten to pin
     * the required behaviour rather than the missing behaviour. The security
     * intent is unchanged and still asserted here: recipient-scoped delivery,
     * no school-wide Noticeboard for an Offering-backed class, and no provider
     * secret in the payload.
     */
    public function test_offering_reschedule_and_cancel_notify_only_the_confirmed_student(): void
    {
        $student = $this->student('update-cancel-target@example.test', 1);
        $this->registration($student, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $unconfirmed = $this->student('update-cancel-unconfirmed@example.test', 1);
        $this->registration($unconfirmed, $this->offeringA, CourseRegistration::STATUS_REGISTERED);
        $parallel = $this->student('update-cancel-parallel@example.test', 1);
        $this->registration($parallel, $this->parallelOffering, CourseRegistration::STATUS_CONFIRMED);
        $class = $this->liveClass(1, $this->offeringA, ['is_published' => 1]);

        $this->actingAs($this->primary)->put(route('teacher.live_classes.update', $class->id), [
            'title' => 'Rescheduled class', 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.example.test/provider-room-secret',
            'start_date' => '2026-09-26', 'start_time' => '11:00', 'end_time' => '12:00', 'timezone' => 'UTC',
            'status' => 'scheduled', 'is_published' => 1,
        ])->assertRedirect();

        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_rescheduled')->count());
        $this->assertSame(
            [(int) $student->id],
            DB::table('user_notifications')->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
            'only the confirmed registration for THIS Offering is told'
        );

        $this->post(route('teacher.live_classes.cancel', $class->id))->assertRedirect();

        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_cancelled')->count());

        // A cancelled class is no longer manageable, so a repeat cancel is
        // refused outright - and in any case cannot re-notify, because the
        // dedup key was already claimed.
        $this->post(route('teacher.live_classes.cancel', $class->id))->assertForbidden();
        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_cancelled')->count());

        $this->assertSame(0, DB::table('noticeboard')->count(),
            'an Offering-backed class is never announced school-wide');

        foreach (DB::table('user_notifications')->get() as $notification) {
            $haystack = $notification->title.' '.$notification->body.' '.$notification->url;
            $this->assertStringNotContainsString('provider-room-secret', $haystack);
            $this->assertStringNotContainsString('meet.example.test', $haystack);
        }
    }

    public function test_real_reminder_command_uses_exact_recipients_and_deduplicates(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();
        $confirmed = $this->student('reminder-confirmed@example.test', 1);
        $unconfirmed = $this->student('reminder-unconfirmed@example.test', 1);
        $dropped = $this->student('reminder-dropped@example.test', 1);
        $parallel = $this->student('reminder-parallel@example.test', 1);
        $foreign = $this->student('reminder-foreign@example.test', 2);
        $this->registration($confirmed, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($unconfirmed, $this->offeringA, CourseRegistration::STATUS_REGISTERED);
        $this->registration($dropped, $this->offeringA, CourseRegistration::STATUS_DROPPED);
        $this->registration($parallel, $this->parallelOffering, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($foreign, $this->foreignOffering, CourseRegistration::STATUS_CONFIRMED);

        $inside = $this->liveClass(1, $this->offeringA, [
            'scheduled_at' => now()->addMinutes(50), 'ends_at' => now()->addMinutes(110),
        ]);
        $upperBoundary = $this->liveClass(5, $this->offeringA, [
            'scheduled_at' => now()->addMinutes(70), 'ends_at' => now()->addMinutes(130),
        ]);
        $tooEarly = $this->liveClass(2, $this->offeringA, [
            'scheduled_at' => now()->addMinutes(71), 'ends_at' => now()->addMinutes(131),
        ]);
        $cancelled = $this->liveClass(3, $this->offeringA, [
            'status' => LiveClass::STATUS_CANCELLED,
            'scheduled_at' => now()->addMinutes(58), 'ends_at' => now()->addMinutes(118),
        ]);
        $ended = $this->liveClass(4, $this->offeringA, [
            'status' => LiveClass::STATUS_ENDED,
            'scheduled_at' => now()->addMinutes(58), 'ends_at' => now()->addMinutes(118),
        ]);

        Artisan::call('live-classes:send-reminders');
        Artisan::call('live-classes:send-reminders');

        $this->assertSame([$inside->id, $upperBoundary->id], LiveClassNotification::query()->where('type', LiveClassNotification::TYPE_REMINDER_1H)
            ->orderBy('live_class_id')->pluck('live_class_id')->all());
        $this->assertDatabaseMissing('live_class_notifications', ['live_class_id' => $tooEarly->id]);
        $this->assertDatabaseMissing('live_class_notifications', ['live_class_id' => $cancelled->id]);
        $this->assertDatabaseMissing('live_class_notifications', ['live_class_id' => $ended->id]);
        $this->assertSame([$confirmed->id, $confirmed->id], DB::table('user_notifications')->where('type', 'live_class_reminder')
            ->orderBy('url')->pluck('user_id')->all());
        $this->assertSame([
            route('student.live_classes.show', $inside->id),
            route('student.live_classes.show', $upperBoundary->id),
        ], DB::table('user_notifications')->where('type', 'live_class_reminder')->orderBy('url')->pluck('url')->all());
        $this->assertSame(2, DB::table('user_notifications')->where('type', 'live_class_reminder')->count());
        $this->assertSame(0, DB::table('noticeboard')->count());
        Mail::assertSent(LiveClassReminderEmail::class, 2);
        Mail::assertSent(LiveClassReminderEmail::class, function (LiveClassReminderEmail $mail) use ($confirmed): bool {
            return $mail->hasTo($confirmed->email)
                && str_contains($mail->data['join_url'], '/student/live-classes/');
        });
        $mail = Mail::sent(LiveClassReminderEmail::class)->first();
        foreach (['provider-room-secret', 'meeting-password-secret', 'meeting-id-secret', 'recording-provider-secret', 'private-storage-key'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($mail->data));
        }
        $renderedMail = view('email.liveClassReminder', ['data' => $mail->data])->render();
        $this->assertStringContainsString(route('student.live_classes.show', $inside->id), $renderedMail);
        foreach (['provider-room-secret', 'meeting-password-secret', 'meeting-id-secret', 'recording-provider-secret', 'private-storage-key'] as $secret) {
            $this->assertStringNotContainsString($secret, $renderedMail);
        }
    }

    public function test_notification_route_rechecks_access_after_registration_is_dropped(): void
    {
        $student = $this->student('drop-after-notice@example.test', 1);
        $registrationId = $this->registration($student, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $class = $this->liveClass(1, $this->offeringA, ['is_published' => 0, 'status' => LiveClass::STATUS_DRAFT]);

        $this->actingAs($this->primary)->post(route('teacher.live_classes.publish', $class->id))->assertRedirect();
        $notice = DB::table('user_notifications')->where('user_id', $student->id)->first();
        $this->assertNotNull($notice);
        $this->assertSame(route('student.live_classes.show', $class->id), $notice->url);

        DB::table('course_registrations')->where('id', $registrationId)->update(['status' => CourseRegistration::STATUS_DROPPED]);
        // A 404, not a soft redirect: once the registration is dropped the class
        // is not merely un-joinable, it is no longer disclosed to this student
        // at all, so a notification link cannot confirm the class still exists.
        $this->actingAs($student)->get($notice->url)->assertNotFound();
        $this->assertDatabaseHas('user_notifications', ['id' => $notice->id, 'user_id' => $student->id]);
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'is_published' => 1]);

        $disabledStudent = $this->student('disabled-after-notice@example.test', 1);
        $this->registration($disabledStudent, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $secondClass = $this->liveClass(2, $this->offeringA, ['is_published' => 0, 'status' => LiveClass::STATUS_DRAFT]);
        $this->actingAs($this->primary)->post(route('teacher.live_classes.publish', $secondClass->id))->assertRedirect();
        $disabledNotice = DB::table('user_notifications')->where('user_id', $disabledStudent->id)->first();
        $this->assertNotNull($disabledNotice);
        DB::table('users')->where('id', $disabledStudent->id)->update(['account_status' => 'disable']);
        $disabledStudent->refresh();
        $denied = $this->actingAs($disabledStudent)->get($disabledNotice->url);
        $this->assertContains($denied->getStatusCode(), [302, 403]);
        $this->assertStringNotContainsString('provider-room-secret', $denied->getContent() ?: '');
        $this->assertDatabaseHas('user_notifications', ['id' => $disabledNotice->id, 'user_id' => $disabledStudent->id]);
    }

    private function subject(int $schoolId, string $name): int
    {
        return (int) DB::table('subjects')->insertGetId([
            'school_id' => $schoolId, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function offering(int $schoolId, int $subjectId): int
    {
        return (int) DB::table('course_offerings')->insertGetId([
            'school_id' => $schoolId, 'subject_id' => $subjectId, 'status' => CourseOffering::STATUS_OPEN,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $email, int $roleId, int $schoolId): User
    {
        return User::create([
            'name' => $email, 'email' => $email, 'role_id' => $roleId,
            'school_id' => $schoolId, 'status' => 1, 'account_status' => 'active',
        ]);
    }

    private function student(string $email, int $schoolId): User
    {
        return $this->user($email, 7, $schoolId);
    }

    private function allocation(User $lecturer, int $offeringId, string $role): void
    {
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $lecturer->school_id, 'course_offering_id' => $offeringId, 'user_id' => $lecturer->id,
            'role' => $role, 'status' => CourseOfferingLecturerAllocation::STATUS_ACTIVE,
            'starts_on' => '2026-09-01', 'ends_on' => null,
        ]);
    }

    private function registration(User $student, int $offeringId, string $status): int
    {
        return (int) DB::table('course_registrations')->insertGetId([
            'school_id' => $student->school_id, 'student_id' => $student->id, 'course_offering_id' => $offeringId,
            'subject_id' => $student->school_id === 1 ? $this->subjectA : null,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function liveClass(int $id, int $offeringId, array $overrides = []): LiveClass
    {
        $values = array_merge([
            'id' => $id, 'school_id' => 1, 'course_offering_id' => $offeringId, 'subject_id' => $this->subjectA,
            'title' => 'Offering reminder class '.$id, 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.example.test/provider-room-secret', 'meeting_id' => 'meeting-id-secret',
            'meeting_password' => 'meeting-password-secret', 'recording_url' => 'https://video.example.test/recording-provider-secret',
            'scheduled_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'start_date' => now()->addDay()->format('Y-m-d'), 'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'timezone' => 'UTC', 'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => 1,
            'attendance_enabled' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
        DB::table('live_classes')->insert($values);
        return LiveClass::query()->findOrFail($id);
    }
}
