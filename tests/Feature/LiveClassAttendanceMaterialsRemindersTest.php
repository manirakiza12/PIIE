<?php

namespace Tests\Feature;

use App\Console\Commands\SendLiveClassReminders;
use App\Mail\LiveClassReminderEmail;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use App\Models\LiveClassMaterial;
use App\Models\LiveClassNotification;
use App\Support\LiveClasses\LiveClassEligibility;
use App\Support\LiveClasses\LiveClassNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

/**
 * Covers the genuinely new pieces added to the Live Classes module:
 * attendance recording, class materials, and the 24h/1h reminder job. The
 * pre-existing create/update/cancel/publish flows are already covered by
 * LiveClassModuleTest (which runs against a fully-migrated real database via
 * its own guardRequiredSchema() skip-if-missing pattern); this file uses a
 * dedicated hand-rolled sqlite schema instead so it runs fast and in
 * isolation regardless of what's migrated where.
 */
class LiveClassAttendanceMaterialsRemindersTest extends TestCase
{
    use LiveClassTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    // ── Attendance ───────────────────────────────────────────────────────

    public function test_a_student_joining_an_attendance_enabled_class_gets_an_attendance_row(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudentUser($schoolId);
        $this->enroll($student->id, $schoolId);
        $liveClass = $this->makeLiveClass($schoolId, [
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
            'attendance_enabled' => 1,
        ]);

        $this->actingAs($student)->get(route('student.live_classes.join', $liveClass->id));

        $this->assertDatabaseHas('live_class_attendances', [
            'live_class_id' => $liveClass->id,
            'user_id' => $student->id,
            'role_id' => 7,
        ]);
    }

    public function test_joining_does_not_record_attendance_when_disabled_on_the_class(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudentUser($schoolId);
        $this->enroll($student->id, $schoolId);
        $liveClass = $this->makeLiveClass($schoolId, [
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
            'attendance_enabled' => 0,
        ]);

        $this->actingAs($student)->get(route('student.live_classes.join', $liveClass->id));

        $this->assertSame(0, LiveClassAttendance::count());
    }

    public function test_a_lecturer_opening_their_own_class_is_not_recorded_as_attendance(): void
    {
        $schoolId = $this->makeSchool();
        $teacher = $this->makeStaffUser($schoolId, 3);
        $liveClass = $this->makeLiveClass($schoolId, [
            'teacher_id' => $teacher->id,
            'created_by' => $teacher->id,
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
        ]);

        $this->actingAs($teacher)->get(route('teacher.live_classes.join', $liveClass->id));

        $this->assertSame(0, LiveClassAttendance::count());
    }

    public function test_the_leave_beacon_sets_left_at_and_computes_duration(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudentUser($schoolId);
        $liveClass = $this->makeLiveClass($schoolId, [
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
        ]);

        $attendance = LiveClassAttendance::create([
            'school_id' => $schoolId,
            'live_class_id' => $liveClass->id,
            'user_id' => $student->id,
            'role_id' => 7,
            'joined_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($student)->post(route('student.live_classes.attendance_leave', $liveClass->id), [
            'attendance_id' => $attendance->id,
        ]);

        $attendance->refresh();
        $this->assertNotNull($attendance->left_at);
        $this->assertGreaterThanOrEqual(590, $attendance->duration_seconds);
        $this->assertTrue($attendance->hasKnownDuration());
    }

    public function test_attendance_report_is_denied_to_a_teacher_who_does_not_own_the_class(): void
    {
        $schoolId = $this->makeSchool();
        $owner = $this->makeStaffUser($schoolId, 3);
        $otherTeacher = $this->makeStaffUser($schoolId, 3);
        $liveClass = $this->makeLiveClass($schoolId, ['teacher_id' => $owner->id, 'created_by' => $owner->id]);

        $response = $this->actingAs($otherTeacher)->get(route('teacher.live_classes.attendance', $liveClass->id));

        $response->assertStatus(403);
    }

    public function test_attendance_report_lists_who_joined_and_when(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $student = $this->makeStudentUser($schoolId, ['name' => 'Alice Attendee']);
        $liveClass = $this->makeLiveClass($schoolId);

        LiveClassAttendance::create([
            'school_id' => $schoolId,
            'live_class_id' => $liveClass->id,
            'user_id' => $student->id,
            'role_id' => 7,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.live_classes.attendance', $liveClass->id));

        $response->assertStatus(200);
        $response->assertSee('Alice Attendee');
    }

    public function test_attendance_csv_export_contains_the_recorded_rows(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $student = $this->makeStudentUser($schoolId, ['name' => 'Bob Exportee']);
        $liveClass = $this->makeLiveClass($schoolId);

        LiveClassAttendance::create([
            'school_id' => $schoolId,
            'live_class_id' => $liveClass->id,
            'user_id' => $student->id,
            'role_id' => 7,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.live_classes.attendance_export', $liveClass->id));

        $response->assertStatus(200);
        $this->assertStringContainsString('Bob Exportee', $response->streamedContent());
    }

    // ── Materials ────────────────────────────────────────────────────────

    public function test_staff_can_upload_a_file_material(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);

        $response = $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
            'type' => 'file',
            'title' => 'Lecture Slides',
            'file' => UploadedFile::fake()->create('slides.pdf', 100, 'application/pdf'),
        ]);

        $response->assertSessionHasNoErrors();

        $material = LiveClassMaterial::first();
        $this->assertNotNull($material);
        $this->assertSame('Lecture Slides', $material->title);
        $this->assertTrue($material->isFile());
        $this->assertNotSame('slides.pdf', $material->stored_name);

        @unlink($material->absolute_path);
    }

    public function test_staff_can_add_a_link_material(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);

        $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
            'type' => 'link',
            'title' => 'Reading List',
            'link_url' => 'https://example.test/reading',
        ]);

        $material = LiveClassMaterial::first();
        $this->assertNotNull($material);
        $this->assertFalse($material->isFile());
        $this->assertSame('https://example.test/reading', $material->link_url);
    }

    public function test_a_student_can_view_materials_for_a_published_class(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudentUser($schoolId);
        $liveClass = $this->makeLiveClass($schoolId, ['is_published' => 1]);

        LiveClassMaterial::create([
            'school_id' => $schoolId,
            'live_class_id' => $liveClass->id,
            'type' => 'link',
            'title' => 'Visible Resource',
            'link_url' => 'https://example.test/x',
        ]);

        $response = $this->actingAs($student)->get(route('student.live_classes.materials', $liveClass->id));

        $response->assertStatus(200);
        $response->assertSee('Visible Resource');
    }

    public function test_a_student_cannot_view_materials_for_an_unpublished_class(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudentUser($schoolId);
        $liveClass = $this->makeLiveClass($schoolId, ['is_published' => 0, 'status' => 'draft']);

        $response = $this->actingAs($student)->get(route('student.live_classes.materials', $liveClass->id));

        $response->assertStatus(403);
    }

    public function test_deleting_a_material_removes_its_file_from_disk(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);

        $destination = public_path(LiveClassMaterial::UPLOAD_DIR);
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        $storedName = 'test_' . uniqid() . '.pdf';
        file_put_contents($destination . '/' . $storedName, 'dummy');

        $material = LiveClassMaterial::create([
            'school_id' => $schoolId,
            'live_class_id' => $liveClass->id,
            'type' => 'file',
            'title' => 'Doomed File',
            'original_name' => 'doomed.pdf',
            'stored_name' => $storedName,
        ]);

        $this->actingAs($admin)->delete(route('admin.live_classes.materials.destroy', $material->id));

        $this->assertDatabaseMissing('live_class_materials', ['id' => $material->id]);
        $this->assertFileDoesNotExist($destination . '/' . $storedName);
    }

    // ── Recordings ───────────────────────────────────────────────────────

    public function test_staff_can_upload_a_recording_file(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);

        $response = $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
            'category' => 'recording',
            'type' => 'file',
            'title' => 'Session Recording',
            'file' => UploadedFile::fake()->create('session.mp4', 2048, 'video/mp4'),
        ]);

        $response->assertSessionHasNoErrors();

        $material = LiveClassMaterial::first();
        $this->assertNotNull($material);
        $this->assertTrue($material->isRecording());
        $this->assertTrue($material->isFile());
        $this->assertStringContainsString('live_class_recordings', $material->url);

        @unlink($material->absolute_path);
    }

    public function test_staff_can_add_a_recording_link(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);

        $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
            'category' => 'recording',
            'type' => 'link',
            'title' => 'Full Lecture',
            'link_url' => 'https://example.test/recording',
        ]);

        $material = LiveClassMaterial::first();
        $this->assertNotNull($material);
        $this->assertTrue($material->isRecording());
        $this->assertSame('https://example.test/recording', $material->link_url);
    }

    public function test_a_recording_upload_rejects_a_document_extension(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);

        $response = $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
            'category' => 'recording',
            'type' => 'file',
            'title' => 'Not A Video',
            'file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertNull(LiveClassMaterial::first());
    }

    public function test_resources_and_recordings_are_kept_in_separate_lists(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId, ['is_published' => 1]);

        LiveClassMaterial::create([
            'school_id' => $schoolId,
            'live_class_id' => $liveClass->id,
            'type' => 'link',
            'category' => 'resource',
            'title' => 'Slides Link',
            'link_url' => 'https://example.test/slides',
        ]);

        LiveClassMaterial::create([
            'school_id' => $schoolId,
            'live_class_id' => $liveClass->id,
            'type' => 'link',
            'category' => 'recording',
            'title' => 'Recording Link',
            'link_url' => 'https://example.test/recording',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.live_classes.materials', $liveClass->id));

        $response->assertStatus(200);
        $response->assertSee('Slides Link');
        $response->assertSee('Recording Link');
    }

    // ── Eligibility + reminders ──────────────────────────────────────────

    public function test_eligibility_resolves_students_enrolled_in_the_classs_class(): void
    {
        $schoolId = $this->makeSchool();
        $classId = $this->makeClass($schoolId);
        $inClass = $this->makeStudentUser($schoolId);
        $notInClass = $this->makeStudentUser($schoolId);
        $this->enroll($inClass->id, $schoolId, ['class_id' => $classId]);
        $this->enroll($notInClass->id, $schoolId, ['class_id' => $classId + 999]);

        $liveClass = $this->makeLiveClass($schoolId, ['class_id' => $classId]);

        $eligible = LiveClassEligibility::eligibleStudentUserIds($liveClass);

        $this->assertTrue($eligible->contains($inClass->id));
        $this->assertFalse($eligible->contains($notInClass->id));
    }

    public function test_send_reminder_emails_eligible_students_and_writes_a_notice(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $schoolId = $this->makeSchool();
        $classId = $this->makeClass($schoolId);
        $student = $this->makeStudentUser($schoolId, ['email' => 'student@example.test']);
        $this->enroll($student->id, $schoolId, ['class_id' => $classId]);

        $liveClass = $this->makeLiveClass($schoolId, ['class_id' => $classId]);
        $liveClass->meeting_url = 'https://meet.jit.si/private-legacy-reminder-room';
        $liveClass->save();

        $sent = LiveClassNotifier::sendReminder($liveClass, LiveClassNotification::TYPE_REMINDER_1H);

        $this->assertSame(1, $sent);
        Mail::assertSent(LiveClassReminderEmail::class, function ($mail) {
            return $mail->hasTo('student@example.test');
        });
        $this->assertDatabaseHas('noticeboard', ['school_id' => $schoolId]);
        $notice = DB::table('noticeboard')->where('school_id', $schoolId)->value('notice');
        $this->assertStringNotContainsString('private-legacy-reminder-room', $notice);
        $this->assertStringContainsString(route('student.live_classes.show', $liveClass->id), $notice);
    }

    public function test_send_reminder_does_not_email_when_smtp_is_not_configured(): void
    {
        Mail::fake();

        $schoolId = $this->makeSchool();
        $classId = $this->makeClass($schoolId);
        $student = $this->makeStudentUser($schoolId);
        $this->enroll($student->id, $schoolId, ['class_id' => $classId]);

        $liveClass = $this->makeLiveClass($schoolId, ['class_id' => $classId]);

        LiveClassNotifier::sendReminder($liveClass, LiveClassNotification::TYPE_REMINDER_1H);

        Mail::assertNothingSent();
    }

    public function test_reminder_command_sends_once_per_window_and_records_a_dedupe_row(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $schoolId = $this->makeSchool();
        $classId = $this->makeClass($schoolId);
        $student = $this->makeStudentUser($schoolId, ['email' => 'reminded@example.test']);
        $this->enroll($student->id, $schoolId, ['class_id' => $classId]);

        // scheduled ~1 hour from now — inside the 1h reminder window.
        $liveClass = $this->makeLiveClass($schoolId, [
            'class_id' => $classId,
            'scheduled_at' => now()->addMinutes(58),
            'ends_at' => now()->addMinutes(118),
        ]);

        Artisan::call('live-classes:send-reminders');

        $this->assertDatabaseHas('live_class_notifications', [
            'live_class_id' => $liveClass->id,
            'type' => LiveClassNotification::TYPE_REMINDER_1H,
        ]);
        Mail::assertSent(LiveClassReminderEmail::class, 1);
    }

    public function test_reminder_command_does_not_resend_within_the_same_window_on_a_second_run(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $schoolId = $this->makeSchool();
        $classId = $this->makeClass($schoolId);
        $student = $this->makeStudentUser($schoolId);
        $this->enroll($student->id, $schoolId, ['class_id' => $classId]);

        $liveClass = $this->makeLiveClass($schoolId, [
            'class_id' => $classId,
            'scheduled_at' => now()->addMinutes(58),
            'ends_at' => now()->addMinutes(118),
        ]);

        Artisan::call('live-classes:send-reminders');
        Artisan::call('live-classes:send-reminders');

        $this->assertSame(1, LiveClassNotification::where('live_class_id', $liveClass->id)->count());
        Mail::assertSent(LiveClassReminderEmail::class, 1);
    }

    public function test_reminder_command_skips_cancelled_classes(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $schoolId = $this->makeSchool();
        $liveClass = $this->makeLiveClass($schoolId, [
            'status' => LiveClass::STATUS_CANCELLED,
            'scheduled_at' => now()->addMinutes(58),
            'ends_at' => now()->addMinutes(118),
        ]);

        Artisan::call('live-classes:send-reminders');

        $this->assertSame(0, LiveClassNotification::where('live_class_id', $liveClass->id)->count());
    }

    // ── Model helpers ────────────────────────────────────────────────────

    public function test_duration_minutes_is_computed_from_scheduled_and_end_times(): void
    {
        $liveClass = new LiveClass([
            'scheduled_at' => '2026-01-01 09:00:00',
            'ends_at' => '2026-01-01 11:15:00',
        ]);

        $this->assertSame(135, $liveClass->duration_minutes);
    }

    public function test_google_meet_over_60_minutes_is_flagged_but_jitsi_is_not(): void
    {
        $googleMeet = new LiveClass([
            'platform' => 'google_meet',
            'scheduled_at' => '2026-01-01 09:00:00',
            'ends_at' => '2026-01-01 10:30:00',
        ]);
        $jitsi = new LiveClass([
            'platform' => 'jitsi',
            'scheduled_at' => '2026-01-01 09:00:00',
            'ends_at' => '2026-01-01 10:30:00',
        ]);

        $this->assertTrue($googleMeet->exceedsGoogleMeetFreeTierLimit());
        $this->assertFalse($jitsi->exceedsGoogleMeetFreeTierLimit());
    }

    // ── Index quick-view filtering ───────────────────────────────────────

    public function test_default_index_view_hides_completed_classes_until_asked_for(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);

        $upcoming = $this->makeLiveClass($schoolId, [
            'title' => 'Upcoming Class',
            'scheduled_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $completed = $this->makeLiveClass($schoolId, [
            'title' => 'Finished Class',
            'status' => LiveClass::STATUS_ENDED,
            'scheduled_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
        ]);

        $default = $this->actingAs($admin)->get(route('admin.live_classes.index'));
        $default->assertSee('Upcoming Class');
        $default->assertDontSee('Finished Class');

        $all = $this->actingAs($admin)->get(route('admin.live_classes.index', ['view' => 'all']));
        $all->assertSee('Upcoming Class');
        $all->assertSee('Finished Class');
    }
}
