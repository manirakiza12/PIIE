<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\CourseOffering\SystemTesterAccess;
use App\Support\LiveClasses\LiveClassNotifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The real student journey, exactly as Kyeyune Amos walked it on Live Class #41.
 *
 * The defect this pins: a notification's "View Live Class" action pointed at
 * /student/live-classes/{id}/join. A student who read "your Live Class is
 * scheduled" and clicked View was immediately told "Joining is not available
 * for this meeting right now" - which reads as a broken class rather than a
 * class that has not started yet. Viewing a class and entering its meeting are
 * two different intentions and now have two different destinations.
 */
class LiveClassStudentExperienceTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;

    private int $otherSchool;

    private int $year;

    private int $period;

    private int $subject;

    private CourseOffering $offering;

    private User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->bootAdmissionsTestSchema();
        $this->schema();
        if (! Schema::hasColumn('users', 'staff_status')) {
            Schema::table('users', function (Blueprint $t): void {
                $t->string('staff_status')->nullable();
            });
        }

        $this->school = $this->makeSchool();
        $this->otherSchool = $this->makeSchool();

        $this->year = (int) DB::table('academic_years')->insertGetId([
            'school_id' => $this->school, 'label' => '2026/2027',
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);
        $this->period = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $this->school, 'academic_year_id' => $this->year, 'type' => 'semester',
            'label' => 'Semester 1', 'sequence' => 1,
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);
        $this->subject = (int) DB::table('subjects')->insertGetId([
            'school_id' => $this->school, 'name' => 'Business Mathematics', 'code' => 'BBIT1103',
        ]);
        $this->offering = $this->makeOffering($this->school, 'BBIT1103-2026-S1');
        $this->lecturer = $this->makeLecturer('Daniel Okello');
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $this->lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function schema(): void
    {
        Schema::create('academic_years', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('label');
            $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps();
        });
        Schema::create('academic_periods', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('academic_year_id');
            $t->string('type'); $t->string('label'); $t->unsignedSmallInteger('sequence');
            $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('academic_year_id'); $t->unsignedBigInteger('academic_period_id');
            $t->string('reference', 50)->nullable(); $t->string('status', 20)->default('draft'); $t->timestamps();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
            $t->unsignedBigInteger('user_id'); $t->string('role', 32); $t->date('starts_on');
            $t->date('ends_on')->nullable(); $t->string('status', 16)->default('planned'); $t->timestamps();
        });
        Schema::create('course_registrations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('session_id')->nullable();
            $t->unsignedBigInteger('course_offering_id'); $t->string('status'); $t->timestamps();
        });
        Schema::create('user_permissions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
            $t->string('permission', 100); $t->timestamps();
            $t->unique(['user_id', 'permission']);
        });
        Schema::create('staff_roles', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name', 100);
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('staff_role_permissions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('staff_role_id'); $t->string('permission', 100); $t->timestamps();
        });
        Schema::create('user_staff_roles', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('staff_role_id'); $t->timestamps();
        });
        Schema::create('teacher_programme_assignments', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('programme_id')->nullable(); $t->timestamps();
        });
        Schema::create('live_class_notifications', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->string('type', 30); $t->unsignedInteger('recipient_count')->default(0);
            $t->timestamp('sent_at'); $t->timestamps();
            $t->unique(['live_class_id', 'type'], 'live_class_notifications_live_class_id_type_unique');
        });
        Schema::create('live_class_attendances', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->unsignedBigInteger('user_id'); $t->unsignedSmallInteger('role_id')->nullable();
            $t->timestamp('joined_at')->nullable(); $t->timestamp('left_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable(); $t->timestamps();
        });
        Schema::create('live_classes', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('title');
            $t->text('description')->nullable(); $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('course_offering_id')->nullable(); $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('programme_id')->nullable(); $t->unsignedBigInteger('academic_session_id')->nullable();
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->enum('platform', ['jitsi', 'google_meet', 'zoom', 'bigbluebutton', 'custom'])->default('jitsi');
            $t->string('meeting_url', 500)->nullable(); $t->string('meeting_id', 150)->nullable();
            $t->string('meeting_password', 150)->nullable();
            $t->date('start_date')->nullable(); $t->time('start_time')->nullable(); $t->time('end_time')->nullable();
            $t->string('timezone', 64)->default('UTC');
            $t->datetime('scheduled_at')->nullable(); $t->datetime('ends_at')->nullable();
            $t->enum('status', ['draft', 'scheduled', 'live', 'ended', 'cancelled'])->default('draft');
            $t->boolean('is_published')->default(false);
            $t->boolean('attendance_enabled')->default(false);
            $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable();
            $t->string('recording_url', 500)->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->unsignedBigInteger('started_by')->nullable();
            $t->unsignedBigInteger('ended_by')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->string('recording_status', 20)->default('none');
        $t->timestamps();

        });
    }

    private function makeOffering(int $school, string $reference): CourseOffering
    {
        $offering = app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->createDraft($school, $this->subject, $this->year, $this->period, $reference);
        DB::table('course_offerings')->where('id', $offering->id)->update(['status' => 'in_progress']);

        return $offering->fresh();
    }

    private function makeLecturer(string $name, int $school = null): User
    {
        $school ??= $this->school;
        $lecturer = User::factory()->create([
            'name' => $name, 'email' => str_replace([' ', '.'], ['.', ''], strtolower($name)).'.'.$school.'@example.test',
            'role_id' => 3, 'school_id' => $school, 'account_status' => 'active', 'staff_status' => 'active',
            'password' => Hash::make('Lecturer#2026'),
        ]);
        DB::table('user_permissions')->insert([
            'school_id' => $school, 'user_id' => $lecturer->id,
            'permission' => 'live_classes.view', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $lecturer;
    }

    private function makeStudent(string $name, ?CourseOffering $offering, string $status = 'confirmed', ?int $school = null): User
    {
        $school ??= $this->school;
        $student = User::factory()->create([
            'name' => $name, 'email' => str_replace([' ', '.'], ['.', ''], strtolower($name)).'.'.$school.'@example.test',
            'role_id' => 7, 'school_id' => $school, 'account_status' => 'active',
            'password' => Hash::make('Student#2026'),
        ]);
        if ($offering) {
            DB::table('course_registrations')->insert([
                'school_id' => $school, 'student_id' => $student->id, 'subject_id' => $offering->subject_id,
                'course_offering_id' => $offering->id, 'status' => $status,
            ]);
        }

        return $student;
    }

    /**
     * A class in the shape of the real #41: an Offering-backed, published,
     * scheduled class starting three days from now.
     */
    private function makeClass(array $overrides = []): LiveClass
    {
        $class = new LiveClass();
        $class->forceFill(array_merge([
            'school_id' => $this->school,
            'title' => 'Business Mathematics — Introduction',
            'description' => 'Introduction to Business Mathematics and overview of the first learning topics.',
            'subject_id' => $this->subject,
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->lecturer->id,
            'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/piie-secret-room-abc123',
            'meeting_id' => 'piie-secret-room-abc123',
            'meeting_password' => 'SuperSecret#42',
            'timezone' => 'UTC',
            'scheduled_at' => now()->addDays(3)->setTime(10, 0),
            'ends_at' => now()->addDays(3)->setTime(12, 0),
            'start_date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'status' => LiveClass::STATUS_SCHEDULED,
            'is_published' => true,
            'attendance_enabled' => false,
        ], $overrides))->save();

        return $class->fresh();
    }

    // ══ NOTIFICATION_VIEW_ROUTE: the CTA is the detail page, not join ══

    public function test_the_notification_points_at_the_detail_page_not_the_join_endpoint(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        LiveClassNotifier::announcePublished($class);

        $url = DB::table('user_notifications')->where('user_id', $student->id)->value('url');
        $this->assertSame(route('student.live_classes.show', $class->id), $url);
        $this->assertStringNotContainsString('/join', $url,
            'a "View Live Class" action must not attempt to join');
    }

    public function test_every_lifecycle_notification_uses_the_detail_route(): void
    {
        $class = $this->makeClass();
        $this->makeStudent('Kyeyune Amos', $this->offering);
        LiveClassNotifier::announcePublished($class);

        $class->forceFill(['status' => LiveClass::STATUS_CANCELLED])->save();
        LiveClassNotifier::announceCancelled($class->fresh());

        foreach (DB::table('user_notifications')->get() as $row) {
            $this->assertStringNotContainsString('/join', $row->url);
            $this->assertStringContainsString('/student/live-classes/'.$class->id, $row->url);
        }
    }

    // ══ STUDENT_DETAIL_PAGE: VIEW works before the join window ══

    public function test_a_confirmed_student_reaches_the_detail_page(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('Business Mathematics — Introduction')
            ->assertSee('BBIT1103')
            ->assertSee('Daniel Okello')
            ->assertSee('Semester 1')
            ->assertSee('2026/2027')
            ->assertSee('Jitsi')
            // Three days out the class is viewable but not joinable, so the
            // join action is deliberately absent and the reason is stated
            // instead. Showing an active-looking button was the defect.
            ->assertSee('Upcoming')
            ->assertSee('Join opens at')
            ->assertDontSee(route('student.live_classes.join', $class->id), false);
    }

    public function test_the_student_can_view_before_the_join_window(): void
    {
        // Two days out, so the join window is definitively shut.
        $class = $this->makeClass([
            'scheduled_at' => now()->addDays(2)->setTime(10, 0),
            'ends_at' => now()->addDays(2)->setTime(12, 0),
        ]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk();
    }

    // ══ BEFORE_WINDOW_JOIN: explained, not a dead end ══

    public function test_before_the_window_join_is_explained_rather_than_silently_refused(): void
    {
        $starts = now()->addDays(2)->setTime(10, 0);
        $class = $this->makeClass([
            'scheduled_at' => $starts,
            'ends_at' => $starts->copy()->addHours(2),
        ]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $response = $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id));

        $response->assertOk();
        $response->assertSee('Join opens at', false);
        $response->assertSee($starts->copy()->subMinutes(15)->format('g:i A'));
        // The join action is not offered at all, and the control that is shown
        // answers a click rather than ignoring it.
        $response->assertDontSee(route('student.live_classes.join', $class->id), false);
        $response->assertSee('data-join-explains', false);
    }

    public function test_within_fifteen_minutes_of_the_start_join_is_available(): void
    {
        $starts = now()->addMinutes(5);
        $class = $this->makeClass([
            'scheduled_at' => $starts,
            'ends_at' => $starts->copy()->addHours(2),
        ]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee(route('student.live_classes.join', $class->id), false)
            ->assertSee('You can join now');
    }

    public function test_during_the_window_join_is_authorised(): void
    {
        $class = $this->makeClass([
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
        ]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.join', $class->id))
            ->assertRedirect();
    }

    public function test_after_the_class_the_page_reads_as_finished_not_broken(): void
    {
        $class = $this->makeClass([
            'scheduled_at' => now()->subHours(3),
            'ends_at' => now()->subHours(1),
            'status' => LiveClass::STATUS_ENDED,
        ]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('Class Completed')
            ->assertSee('finished');
    }

    /**
     * A cancelled class is withdrawn from students entirely, not shown as a
     * "cancelled" page. canStudentViewClass() already requires is_published AND
     * a non-cancelled status, so the page 404s - the same non-disclosure an
     * unpublished draft gets. This pins that: a student must not be able to keep
     * a bookmark that reveals a cancelled class still exists.
     */
    /**
     * Superseded by test_a_cancelled_class_stays_readable_with_no_join_action.
     *
     * This previously asserted a 404. The user reversed that decision: a
     * cancellation notification pointed at a page that returned "not found", so
     * the student who had just been told their class was cancelled was the only
     * person unable to see it. Cancellation withdraws the JOIN, not the RECORD.
     */
    public function test_a_cancelled_class_stays_readable_with_no_join_action(): void
    {
        $class = $this->makeClass(['status' => LiveClass::STATUS_CANCELLED]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $this->assertTrue(
            $access->canStudentViewClass($student, $class->fresh()),
            'a confirmed registrant keeps read access to a cancelled class'
        );
        $this->assertFalse(
            $access->canStudentJoin($student, $class->fresh()),
            'but can never join it'
        );

        $response = $this->actingAs($student)->get(route('student.live_classes.show', $class->id));
        $response->assertOk();
        $response->assertSee('was cancelled', false);
        $this->assertStringNotContainsString(
            'Join Live Class',
            (string) $response->getContent(),
            'a retained cancelled page must never offer a Join action'
        );
    }

    /**
     * The join-window explanation for a class that is still scheduled and
     * published, exercised on a state students CAN see, so the "no join yet"
     * message is proven rather than inferred.
     */
    public function test_a_visible_scheduled_class_explains_that_joining_is_not_open_yet(): void
    {
        $starts = now()->addDays(2)->setTime(10, 0);
        $class = $this->makeClass(['scheduled_at' => $starts, 'ends_at' => $starts->copy()->addHours(2)]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('Join opens at '.$starts->copy()->subMinutes(15)->format('g:i A'))
            ->assertDontSee(':time', false)
            ->assertDontSee(route('student.live_classes.join', $class->id), false);
    }

    // ══ access control on the new page ══

    public function test_an_unconfirmed_registration_cannot_see_the_detail_page(): void
    {
        $class = $this->makeClass();
        $pending = $this->makeStudent('Pending Student', $this->offering, 'pending');

        $this->actingAs($pending)
            ->get(route('student.live_classes.show', $class->id))
            ->assertNotFound();
    }

    public function test_a_registration_for_another_offering_cannot_see_it(): void
    {
        $class = $this->makeClass();
        $other = $this->makeStudent('Other Offering', $this->makeOffering($this->school, 'OTHER-1'));

        $this->actingAs($other)
            ->get(route('student.live_classes.show', $class->id))
            ->assertNotFound();
    }

    public function test_a_cross_tenant_student_gets_not_found(): void
    {
        $class = $this->makeClass();
        $foreign = $this->makeStudent('Foreign Student', null, 'confirmed', $this->otherSchool);

        $this->actingAs($foreign)
            ->get(route('student.live_classes.show', $class->id))
            ->assertNotFound();
    }

    public function test_an_unregistered_student_cannot_see_it(): void
    {
        $class = $this->makeClass();
        $random = $this->makeStudent('Random Student', null);

        $this->actingAs($random)
            ->get(route('student.live_classes.show', $class->id))
            ->assertNotFound();
    }

    public function test_an_unpublished_class_cannot_be_confirmed_by_guessing_the_url(): void
    {
        $class = $this->makeClass(['is_published' => false, 'status' => LiveClass::STATUS_DRAFT]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertNotFound();
    }

    // ══ PROVIDER_SECRET_EXPOSED=NO ══

    public function test_no_provider_secret_reaches_the_student_detail_page(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $response = $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id));

        $response->assertOk();
        $html = $response->getContent();
        foreach ([
            $class->meeting_url,
            $class->meeting_id,
            $class->meeting_password,
            'SuperSecret#42',
            'piie-secret-room-abc123',
            'meet.jit.si',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $html,
                'a provider secret must never be rendered on the student page');
        }
    }

    public function test_no_provider_secret_reaches_a_notification(): void
    {
        $class = $this->makeClass();
        $this->makeStudent('Kyeyune Amos', $this->offering);

        LiveClassNotifier::announcePublished($class);

        foreach (DB::table('user_notifications')->get() as $row) {
            $haystack = $row->title.' '.$row->body.' '.$row->url;
            $this->assertStringNotContainsString($class->meeting_url, $haystack);
            $this->assertStringNotContainsString('SuperSecret#42', $haystack);
            $this->assertStringNotContainsString('piie-secret-room-abc123', $haystack);
        }
    }

    // ══ the lecturer detail page ══

    public function test_the_lecturer_detail_page_derives_the_course_offering_context(): void
    {
        $this->makeStudent('Kyeyune Amos', $this->offering);
        $this->makeStudent('Second Student', $this->offering);
        $class = $this->makeClass();

        $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('Course Context')
            ->assertSee('BBIT1103-2026-S1')
            ->assertSee('Business Mathematics')
            ->assertSee('2026/2027')
            ->assertSee('Semester 1')
            ->assertSee('2');
    }

    public function test_the_lecturer_detail_page_hides_the_legacy_attendance_flag(): void
    {
        $class = $this->makeClass();

        $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))
            ->assertOk()
            ->assertDontSee('Attendance enabled');
    }

    public function test_the_lecturer_detail_page_offers_no_hard_delete_for_an_offering_class(): void
    {
        $class = $this->makeClass();

        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id));

        $response->assertOk()
            ->assertSee('cannot be deleted')
            ->assertSee(route('teacher.live_classes.cancel', $class->id), false);

        // Cancel remains the governed way to end it. Asserted positively,
        // because "no delete" is only meaningful if something replaced it.
        $html = (string) $response->getContent();
        $this->assertStringContainsString(route('teacher.live_classes.cancel', $class->id), $html);
        $this->assertStringNotContainsString(
            route('teacher.live_classes.destroy', $class->id).'"',
            $html,
            'no form may post a hard delete for an Offering-backed class'
        );
        $this->assertStringNotContainsString('_method', $html, 'no DELETE method spoof at all');
    }

    public function test_hard_delete_is_still_refused_by_the_endpoint(): void
    {
        $class = $this->makeClass();

        $this->actingAs($this->lecturer)
            ->delete(route('teacher.live_classes.destroy', $class->id))
            ->assertForbidden();

        $this->assertDatabaseHas('live_classes', ['id' => $class->id]);
    }

    public function test_a_legacy_k12_class_keeps_its_programme_and_delete(): void
    {
        $programmeId = (int) DB::table('programmes')->insertGetId([
            'school_id' => $this->school, 'name' => 'Senior Secondary Arts', 'is_active' => 1,
        ]);
        $class = new LiveClass();
        $class->forceFill([
            'school_id' => $this->school, 'title' => 'K12 Revision',
            'programme_id' => $programmeId, 'teacher_id' => $this->lecturer->id,
            'platform' => 'jitsi', 'meeting_url' => 'https://meet.jit.si/k12room',
            'timezone' => 'UTC', 'scheduled_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(10, 0),
            'start_date' => now()->addDay()->toDateString(),
            'start_time' => '09:00:00', 'end_time' => '10:00:00',
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => true,
        ])->save();
        $class = $class->fresh();

        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id));

        $response->assertOk()
            ->assertSee('Senior Secondary Arts')
            ->assertSee('Attendance enabled')
            ->assertSee(route('teacher.live_classes.destroy', $class->id), false);
    }

    // ══ published class must not silently revert to draft ══

    public function test_viewing_a_published_class_never_changes_its_state(): void
    {
        $class = $this->makeClass();
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($student)->get(route('student.live_classes.show', $class->id))->assertOk();
            $this->actingAs($student)->get(route('student.live_classes.join', $class->id));
        }

        $class->refresh();
        $this->assertTrue((bool) $class->is_published, 'reading a class must never unpublish it');
        $this->assertSame(LiveClass::STATUS_SCHEDULED, $class->status);
    }

    public function test_running_the_reminder_command_does_not_change_publication(): void
    {
        $class = $this->makeClass([
            'scheduled_at' => now()->addHours(24),
            'ends_at' => now()->addHours(25),
        ]);
        $this->makeStudent('Kyeyune Amos', $this->offering);
        Mail::fake();

        $this->artisan('live-classes:send-reminders')->assertExitCode(0);

        $class->refresh();
        $this->assertTrue((bool) $class->is_published, 'a reminder must never unpublish a class');
        $this->assertNotSame(LiveClass::STATUS_DRAFT, $class->status);
    }

    public function test_an_explicit_unpublish_is_still_allowed_and_audited(): void
    {
        $class = $this->makeClass();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.publish', $class->id))
            ->assertRedirect();

        $class->refresh();
        $this->assertFalse((bool) $class->is_published);
        $this->assertSame(LiveClass::STATUS_DRAFT, $class->status, 'unpublish returns it to draft');

        $this->assertDatabaseHas('audit_logs', ['action' => 'update']);
    }

    // ══ the pre-start student academic case ══

    public function test_a_confirmed_student_can_view_a_prestart_class_on_an_early_started_offering(): void
    {
        // The Offering is in progress and deliberately early-started, while the
        // official Academic Period has not begun. The student is a confirmed
        // registration, so viewing must work; joining is still windowed.
        $futurePeriod = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $this->school, 'academic_year_id' => $this->year, 'type' => 'semester',
            'label' => 'Semester 2', 'sequence' => 2,
            'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonths(6)->toDateString(),
            'status' => 'active',
        ]);
        $offering = $this->makeOffering($this->school, 'BBIT1103-2026-S2');
        DB::table('course_offerings')->where('id', $offering->id)
            ->update(['academic_period_id' => $futurePeriod]);

        $class = $this->makeClass([
            'course_offering_id' => $offering->id,
            'scheduled_at' => now()->addDays(2)->setTime(10, 0),
            'ends_at' => now()->addDays(2)->setTime(12, 0),
        ]);
        $student = $this->makeStudent('Kyeyune Amos', $offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('Semester 2');
    }
}
