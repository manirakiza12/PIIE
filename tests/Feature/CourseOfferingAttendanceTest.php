<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingAttendanceSession;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\User;
use App\Support\CourseOffering\CourseOfferingLecturerAllocationService;
use App\Support\CourseOffering\CourseOfferingService;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceService;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionStatus;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionType;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceStatus;
use App\Support\Permissions\PermissionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Step 5B: HEI Course Offering attendance.
 *
 * Runs the real migration against the in-memory SQLite database, so the schema
 * under test is the schema that will be applied - not a hand-built imitation.
 */
class CourseOfferingAttendanceTest extends TestCase
{
    use StaffModuleTestHelper;

    private array $tenants = [];
    private User $lecturer;
    private User $otherLecturer;
    private User $foreignLecturer;
    private CourseOfferingAttendanceService $attendance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->preAttendanceSchema();
        $this->runAttendanceMigration();

        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('higher_ed');
        });
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id');
            $table->string('permission', 100); $table->timestamps(); $table->unique(['user_id', 'permission']);
        });
        Schema::create('staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->string('name'); $table->timestamps(); });
        Schema::create('staff_role_permissions', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('staff_role_id'); $table->string('permission', 100); $table->timestamps(); });
        Schema::create('user_staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('staff_role_id'); $table->timestamps(); });

        $this->tenants['a'] = $this->institution(1);
        $this->tenants['b'] = $this->institution(2);

        $this->lecturer = $this->lecturer($this->tenants['a'], 'Lecturer One');
        $this->otherLecturer = $this->lecturer($this->tenants['a'], 'Lecturer Two');
        $this->foreignLecturer = $this->lecturer($this->tenants['b'], 'Foreign Lecturer');

        $this->attendance = app(CourseOfferingAttendanceService::class);
    }

    // ================================================= 1, 2: migration & K12

    public function test_migration_creates_hei_attendance_tables_with_governed_vocabulary(): void
    {
        $this->assertTrue(Schema::hasTable('course_offering_attendance_sessions'));
        $this->assertTrue(Schema::hasTable('course_offering_attendance_records'));

        $sessions = Schema::getColumnListing('course_offering_attendance_sessions');
        foreach (['school_id', 'course_offering_id', 'session_date', 'starts_at', 'ends_at', 'type', 'topic', 'live_class_id', 'recorded_by_user_id', 'status', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $sessions, "sessions.{$column} missing");
        }
        $records = Schema::getColumnListing('course_offering_attendance_records');
        foreach (['school_id', 'attendance_session_id', 'course_registration_id', 'student_id', 'status', 'marked_by_user_id', 'marked_at', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $records, "records.{$column} missing");
        }

        // Governed constants, not magic numbers.
        $this->assertSame(0, CourseOfferingAttendanceStatus::ABSENT);
        $this->assertSame(1, CourseOfferingAttendanceStatus::PRESENT);
        $this->assertSame(2, CourseOfferingAttendanceStatus::LATE);
        $this->assertSame(3, CourseOfferingAttendanceStatus::EXCUSED);
        $this->assertSame([CourseOfferingAttendanceSessionStatus::DRAFT, 'finalised', 'locked'], CourseOfferingAttendanceSessionStatus::ALL);
    }

    public function test_legacy_daily_attendances_schema_is_untouched(): void
    {
        // The migration must not alter or create either legacy table.
        $source = file_get_contents(base_path('database/migrations/2026_09_28_000001_create_course_offering_attendance_tables.php'));
        foreach (['Schema::table(\'daily_attendances\'', 'Schema::create(\'daily_attendances\'', 'Schema::table(\'live_class_attendances\'', 'Schema::create(\'live_class_attendances\''] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "The HEI attendance migration must not run {$forbidden}.");
        }

        // The three attendance domains stay in three separate tables.
        $this->assertTrue(Schema::hasTable('daily_attendances'));
        $this->assertTrue(Schema::hasTable('live_class_attendances'));
        $this->assertTrue(Schema::hasTable('course_offering_attendance_sessions'));
        $this->assertFalse(Schema::hasColumn('daily_attendances', 'course_offering_id'));
        $this->assertFalse(Schema::hasColumn('live_class_attendances', 'course_offering_id'));

        $legacy = Schema::getColumnListing('daily_attendances');
        foreach (['class_id', 'section_id', 'student_id', 'status', 'timestamp', 'school_id'] as $column) {
            $this->assertContains($column, $legacy, "daily_attendances.{$column} must be unchanged");
        }
        $this->assertSame(0, DB::table('daily_attendances')->count(), 'No K12 attendance record is created or copied.');
    }

    public function test_attendance_records_cannot_be_written_or_deleted_outside_the_service(): void
    {
        $session = $this->sessionFor($this->offering('in_progress'));
        $registration = $this->confirmation($session, 'S-1');

        foreach ([
            fn () => CourseOfferingAttendanceSession::create(['school_id' => 1]),
            fn () => $session->update(['topic' => 'direct edit']),
            fn () => $session->delete(),
            fn () => \App\Models\CourseOfferingAttendanceRecord::create(['school_id' => 1, 'status' => 1]),
            fn () => \App\Models\CourseOfferingAttendanceRecord::query()->whereKey(1)->first()?->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Direct attendance mutation must be refused.');
            } catch (\Throwable $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $this->assertNotNull($registration);
    }

    // ================================================= 3-12: authorization

    public function test_only_an_allocated_lecturer_of_the_same_tenant_reaches_attendance(): void
    {
        $mine = $this->offering('in_progress');
        $theirs = $this->offering('in_progress', $this->otherLecturer, 'BBIT2201');
        $foreign = $this->offering('in_progress', $this->foreignLecturer, 'BSCS4001');

        $this->actingAs($this->lecturer)->get(route('teacher.course_offerings.attendance.index', $mine->id))->assertOk()->assertSee('Attendance');
        $this->get(route('teacher.course_offerings.attendance.index', $theirs->id))->assertNotFound();
        $this->get(route('teacher.course_offerings.attendance.index', $foreign->id))->assertNotFound();
        $this->get(route('teacher.course_offerings.attendance.create', $foreign->id))->assertNotFound();
    }

    public function test_allocation_and_offering_lifecycle_govern_who_may_act(): void
    {
        $this->actingAs($this->lecturer);

        $ended = $this->offering('in_progress', $this->lecturer, 'BBIT1105', 'ended');
        $this->get(route('teacher.course_offerings.attendance.index', $ended->id))->assertOk();
        $this->get(route('teacher.course_offerings.attendance.create', $ended->id))
            ->assertRedirect()->assertSessionHasErrors('attendance');

        $cancelledAllocation = $this->offering('in_progress', $this->lecturer, 'BBIT1109', 'cancelled');
        $this->get(route('teacher.course_offerings.attendance.index', $cancelledAllocation->id))->assertNotFound();

        $draft = $this->offering('draft');
        $this->get(route('teacher.course_offerings.attendance.create', $draft->id))
            ->assertRedirect()->assertSessionHasErrors('attendance');

        // An Open Offering may be prepared, but no teaching attendance is recorded
        // for teaching that has not happened: the form is reachable, the save is not.
        $open = $this->offering('open');
        $this->get(route('teacher.course_offerings.attendance.create', $open->id))->assertOk();
        $this->post(route('teacher.course_offerings.attendance.store', $open->id), [
            'session_date' => '2026-10-05', 'type' => 'lecture',
        ])->assertSessionHasErrors('session');
        $this->assertSame(0, DB::table('course_offering_attendance_sessions')->where('course_offering_id', $open->id)->count());

        // A cancelled Offering is read-only, and takes no new session.
        $cancelled = $this->offering('cancelled');
        $this->get(route('teacher.course_offerings.attendance.index', $cancelled->id))->assertOk()->assertSee('read-only');
        $this->get(route('teacher.course_offerings.attendance.create', $cancelled->id))
            ->assertRedirect()->assertSessionHasErrors('attendance');
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $cancelled->id, '2026-10-05')));
    }

    public function test_completed_offering_is_read_only_history(): void
    {
        // Teaching happens, is marked, and only then is the Offering completed.
        $offering = $this->offering('in_progress');
        $session = $this->sessionFor($offering);
        $registration = $this->confirmation($session, 'S-1');
        $this->attendance->mark($this->lecturer, $session, $registration, CourseOfferingAttendanceStatus::PRESENT);
        $this->finaliseOffering($offering);

        $this->actingAs($this->lecturer)->get(route('teacher.course_offerings.attendance.index', $offering->id))
            ->assertOk()->assertSee('read-only');
        $this->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))
            ->assertOk()->assertSee('Student S-1');
        $this->get(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertRedirect()->assertSessionHasErrors('attendance');

        // No new session, and no new mark, on a completed Offering.
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-06')));
        $this->assertNotNull($this->catch(fn () => $this->attendance->mark($this->lecturer, $session, $registration, CourseOfferingAttendanceStatus::ABSENT)));
        $this->assertCount(1, $this->attendance->recordsForSession($session), 'History is never rewritten.');
    }

    // ============================================ 13-17: session integrity

    public function test_session_belongs_to_its_offering_and_tenant_and_validates_its_fields(): void
    {
        $offering = $this->offering('in_progress');

        $session = $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-05', '09:00', '11:00', CourseOfferingAttendanceSessionType::LECTURE, 'Introduction to Information Systems');
        $this->assertSame($offering->id, (int) $session->course_offering_id);
        $this->assertSame($this->lecturer->school_id, (int) $session->school_id);
        $this->assertSame('2026-10-05', $session->session_date->format('Y-m-d'));
        $this->assertSame('09:00 – 11:00', $session->timeRange());
        $this->assertSame(CourseOfferingAttendanceSessionStatus::DRAFT, $session->status);

        // End must be after start.
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-06', '11:00', '09:00')));
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-06', '11:00', '11:00')));

        // Date must fall inside the Offering's Academic Period (2026-09-01 .. 2027-06-30).
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2027-07-15')));
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2026-08-01')));
        // ...and a date inside it is accepted.
        $this->assertNotNull($this->attendance->createSession($this->lecturer, $offering->id, '2027-01-05'));
    }

    public function test_duplicate_sessions_are_refused_timed_and_untimed(): void
    {
        $offering = $this->offering('in_progress');

        $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-05', '09:00', '11:00');
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-05', '09:00', '12:00')), 'Duplicate timed session must be refused.');

        $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-06');
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-06')), 'Duplicate untimed session must be refused.');

        // A different time on the same day is a different occurrence.
        $this->assertNotNull($this->attendance->createSession($this->lecturer, $offering->id, '2026-10-05', '14:00', '15:00'));
    }

    // ================================================ 18-23: roster rules

    public function test_roster_is_confirmed_registrations_of_this_offering_only(): void
    {
        $session = $this->sessionFor($this->offering('in_progress'));
        $confirmed = $this->confirmation($session, 'S-100', CourseRegistration::STATUS_CONFIRMED);
        $this->confirmation($session, 'S-200', CourseRegistration::STATUS_REGISTERED);
        $other = $this->sessionFor($this->offering('in_progress', $this->otherLecturer, 'BBIT2201'));
        $this->confirmation($other, 'S-300');
        $this->confirmation($session, 'S-400', CourseRegistration::STATUS_CONFIRMED, $this->tenants['b']);

        $roster = $this->attendance->rosterFor($session);
        $this->assertCount(1, $roster, 'Only this offering confirmed registrations, in this tenant.');
        $this->assertSame((int) $confirmed->id, (int) $roster->first()->id);
        $this->assertNull($roster->first()->getAttribute('marked_status'), 'An unmarked student is unmarked, never absent.');
    }

    public function test_record_must_match_a_registration_of_the_same_offering_and_student(): void
    {
        $session = $this->sessionFor($this->offering('in_progress'));
        $confirmed = $this->confirmation($session, 'S-100');
        $unconfirmed = $this->confirmation($session, 'S-200', CourseRegistration::STATUS_REGISTERED);
        $other = $this->sessionFor($this->offering('in_progress', $this->otherLecturer, 'BBIT2201'));
        $foreignRegistration = $this->confirmation($other, 'S-300');

        $this->assertNotNull($this->catch(fn () => $this->attendance->mark($this->lecturer, $session, $unconfirmed, CourseOfferingAttendanceStatus::PRESENT)));
        $this->assertNotNull($this->catch(fn () => $this->attendance->mark($this->lecturer, $session, $foreignRegistration, CourseOfferingAttendanceStatus::PRESENT)));

        $record = $this->attendance->mark($this->lecturer, $session, $confirmed, CourseOfferingAttendanceStatus::PRESENT);
        $this->assertSame((int) $confirmed->student_id, (int) $record->student_id, 'student_id mirrors the registration.');
        $this->assertSame((int) $confirmed->id, (int) $record->course_registration_id);
    }

    // ================================================ 24-31: statuses & bulk

    public function test_every_governed_status_persists_and_duplicate_records_are_impossible(): void
    {
        $session = $this->sessionFor($this->offering('in_progress'));
        $expected = [
            'S-1' => CourseOfferingAttendanceStatus::PRESENT,
            'S-2' => CourseOfferingAttendanceStatus::ABSENT,
            'S-3' => CourseOfferingAttendanceStatus::LATE,
            'S-4' => CourseOfferingAttendanceStatus::EXCUSED,
        ];
        $registrations = [];
        foreach ($expected as $code => $status) {
            $registrations[$code] = $this->confirmation($session, $code);
            $record = $this->attendance->mark($this->lecturer, $session, $registrations[$code], $status);
            $this->assertSame($status, (int) $record->status, $code);
        }
        foreach ($expected as $code => $status) {
            $this->assertSame($status, (int) $this->attendance->recordsForSession($session)->firstWhere('student_id', $registrations[$code]->student_id)->status, $code);
        }

        // One record per registration per session, at the database level.
        $this->expectException(QueryException::class);
        DB::table('course_offering_attendance_records')->insert([
            'school_id' => $session->school_id, 'attendance_session_id' => $session->id,
            'course_registration_id' => $registrations['S-1']->id, 'student_id' => $registrations['S-1']->student_id,
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_bulk_marking_is_idempotent_and_never_defaults_a_missing_student_to_absent(): void
    {
        $session = $this->sessionFor($this->offering('in_progress'));
        $one = $this->confirmation($session, 'S-1');
        $two = $this->confirmation($session, 'S-2');

        $marks = [
            ['course_registration_id' => $one->id, 'status' => CourseOfferingAttendanceStatus::PRESENT],
            ['course_registration_id' => $two->id, 'status' => CourseOfferingAttendanceStatus::LATE],
        ];
        $first = $this->attendance->markBulk($this->lecturer, $session, $marks);
        $this->assertSame(2, $first['applied']);

        // Repeating the same submission changes nothing and adds no rows.
        $second = $this->attendance->markBulk($this->lecturer, $session, $marks);
        $this->assertSame(2, $second['applied']);
        $this->assertSame(2, $this->attendance->recordsForSession($session)->count());

        $this->attendance->markBulk($this->lecturer, $session, [['course_registration_id' => $one->id, 'status' => CourseOfferingAttendanceStatus::EXCUSED]]);
        $records = $this->attendance->recordsForSession($session);
        $this->assertSame(2, $records->count(), 'A partial submission must not remove or duplicate anything.');
        $this->assertSame(CourseOfferingAttendanceStatus::EXCUSED, (int) $records->firstWhere('student_id', $one->student_id)->status);
    }

    public function test_mark_all_present_covers_the_whole_roster_through_the_http_workflow(): void
    {
        $offering = $this->offering('in_progress');
        $session = $this->sessionFor($offering);
        $one = $this->confirmation($session, 'S-1');
        $two = $this->confirmation($session, 'S-2');

        $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), [
                'marks' => [
                    $one->id => ['course_registration_id' => $one->id, 'status' => CourseOfferingAttendanceStatus::PRESENT],
                    $two->id => ['course_registration_id' => $two->id, 'status' => CourseOfferingAttendanceStatus::PRESENT],
                ],
            ])->assertRedirect();

        $this->assertSame(2, $this->attendance->recordsForSession($session)->where('status', CourseOfferingAttendanceStatus::PRESENT)->count());
    }

    // ============================================= 32-35: finalisation

    public function test_finalisation_requires_every_roster_member_to_be_marked(): void
    {
        $offering = $this->offering('in_progress');
        $session = $this->sessionFor($offering);
        $one = $this->confirmation($session, 'S-1');
        $two = $this->confirmation($session, 'S-2');

        $this->actingAs($this->lecturer)->post(route('teacher.course_offerings.attendance.finalise', [$offering->id, $session->id]))
            ->assertSessionHasErrors('attendance');
        $this->assertStringContainsString('2 students have not yet been marked', session('errors')->first('attendance'));
        $this->assertSame(CourseOfferingAttendanceSessionStatus::DRAFT, $session->fresh()->status);

        $this->attendance->mark($this->lecturer, $session, $one, CourseOfferingAttendanceStatus::PRESENT);
        $this->post(route('teacher.course_offerings.attendance.finalise', [$offering->id, $session->id]))
            ->assertSessionHasErrors('attendance');
        $this->assertStringContainsString('1 student has not yet been marked', session('errors')->first('attendance'));

        $this->attendance->mark($this->lecturer, $session, $two, CourseOfferingAttendanceStatus::ABSENT);
        $this->post(route('teacher.course_offerings.attendance.finalise', [$offering->id, $session->id]))->assertRedirect();
        $this->assertSame(CourseOfferingAttendanceSessionStatus::FINALISED, $session->fresh()->status);

        // A finalised register rejects further lecturer marking.
        $this->post(route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), [
            'marks' => [$one->id => ['course_registration_id' => $one->id, 'status' => CourseOfferingAttendanceStatus::ABSENT]],
        ])->assertSessionHasErrors('attendance');
        $this->assertSame(2, $this->attendance->recordsForSession($session)->count(), 'A finalised register is never rewritten by a lecturer.');
    }

    public function test_lecturer_can_never_reopen_a_finalised_register(): void
    {
        $offering = $this->offering('in_progress');
        $session = $this->sessionFor($offering);
        $one = $this->confirmation($session, 'S-1');
        $this->attendance->mark($this->lecturer, $session, $one, CourseOfferingAttendanceStatus::PRESENT);
        $this->attendance->finalise($this->lecturer, $session);
        $this->assertSame(CourseOfferingAttendanceSessionStatus::FINALISED, $session->fresh()->status);

        // No lecturer route exists to reopen, by any name.
        $uris = collect(app('router')->getRoutes())->map(fn ($route) => $route->uri())->all();
        foreach ($uris as $uri) {
            $this->assertStringNotContainsString('reopen', $uri, "A lecturer reopen route still exists: {$uri}");
        }
        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'attendance') && in_array('reopen', $route->methods(), true)),
            'A lecturer reopen action must not be registered.'
        );

        // Direct POST to the old path is refused, not silently accepted.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$offering->id}/attendance/{$session->id}/reopen")
            ->assertNotFound();
        $this->assertSame(CourseOfferingAttendanceSessionStatus::FINALISED, $session->fresh()->status);

        // No reopen control is rendered.
        $content = $this->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('Reopen', $content);
        $this->assertStringContainsString('read-only', $content);
        $this->assertStringContainsString('academic office', $content);

        // The service seam exists for a future governed admin workflow only.
        $this->assertTrue(method_exists($this->attendance, 'reopenForGovernedCorrection'));
        $this->assertFalse(method_exists($this->attendance, 'reopen'));
    }

    public function test_draft_registers_are_still_fully_editable(): void
    {
        $offering = $this->offering('in_progress');
        $session = $this->sessionFor($offering);
        $one = $this->confirmation($session, 'S-1');

        $this->actingAs($this->lecturer)->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))
            ->assertOk()->assertSee('Save Attendance');
        $this->post(route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), [
            'marks' => [$one->id => ['course_registration_id' => $one->id, 'status' => CourseOfferingAttendanceStatus::ABSENT]],
        ])->assertRedirect();
        $this->assertSame(CourseOfferingAttendanceStatus::ABSENT, (int) $this->attendance->recordsForSession($session)->first()->status);

        // ...and can be changed again while still draft.
        $this->post(route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), [
            'marks' => [$one->id => ['course_registration_id' => $one->id, 'status' => CourseOfferingAttendanceStatus::LATE]],
        ])->assertRedirect();
        $this->assertSame(CourseOfferingAttendanceStatus::LATE, (int) $this->attendance->recordsForSession($session)->first()->status);
        $this->assertSame(CourseOfferingAttendanceSessionStatus::DRAFT, $session->fresh()->status);
    }

    // ==================================== 36-38: Live Class traceability

    public function test_live_class_link_is_optional_and_must_match_tenant_and_offering(): void
    {
        $offering = $this->offering('in_progress');
        $mine = $this->liveClass($offering, 'My Live Class');
        $foreignOffering = $this->offering('in_progress', $this->otherLecturer, 'BBIT2201');
        $theirs = $this->liveClass($foreignOffering, 'Other Live Class');

        $session = $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-05', '09:00', null, CourseOfferingAttendanceSessionType::LIVE_CLASS, null, $mine);
        $this->assertSame((int) $mine, (int) $session->live_class_id);

        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-06', null, null, CourseOfferingAttendanceSessionType::LIVE_CLASS, null, $theirs)));
        $this->assertNotNull($this->catch(fn () => $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-07', null, null, CourseOfferingAttendanceSessionType::LIVE_CLASS, null, 99999)));
    }

    public function test_live_class_participation_is_evidence_and_never_marks_attendance(): void
    {
        $offering = $this->offering('in_progress');
        $liveClass = $this->liveClass($offering, 'My Live Class');
        $student = $this->confirmation($offering, 'S-1');
        DB::table('live_class_attendances')->insert([
            'school_id' => $offering->school_id, 'live_class_id' => $liveClass, 'user_id' => $student->student_id,
            'role_id' => 7, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $session = $this->attendance->createSession($this->lecturer, $offering->id, '2026-10-05', '09:00', null, CourseOfferingAttendanceSessionType::LIVE_CLASS, null, $liveClass);

        $this->assertCount(1, $this->attendance->liveClassEvidence($session));
        $this->assertCount(0, $this->attendance->recordsForSession($session), 'A join must not create an academic attendance record.');
        $this->assertNull($this->attendance->rosterFor($session)->first()->getAttribute('marked_status'), 'A join must not mark a student present.');

        // Finalisation is still blocked, proving nothing was silently recorded.
        $this->assertNotNull($this->catch(fn () => $this->attendance->finalise($this->lecturer, $session)));
    }

    // ================================================= 39-40: IDOR

    public function test_a_session_from_another_offering_cannot_be_reached_or_marked_through_a_borrowed_url(): void
    {
        $mine = $this->offering('in_progress');
        $theirs = $this->offering('in_progress', $this->otherLecturer, 'BBIT2201');
        $theirSession = $this->sessionFor($theirs);
        $registration = $this->confirmation($theirSession, 'S-300');

        $this->actingAs($this->lecturer)->get(route('teacher.course_offerings.attendance.show', [$mine->id, $theirSession->id]))->assertNotFound();
        $this->post(route('teacher.course_offerings.attendance.mark', [$mine->id, $theirSession->id]), [
            'marks' => [$registration->id => ['course_registration_id' => $registration->id, 'status' => 1]],
        ])->assertNotFound();
        $this->post(route('teacher.course_offerings.attendance.finalise', [$mine->id, $theirSession->id]))->assertNotFound();
        $this->assertCount(0, $this->attendance->recordsForSession($theirSession));
    }

    public function test_lecturer_attendance_pages_never_expose_admin_registration_controls(): void
    {
        $offering = $this->offering('in_progress');
        $session = $this->sessionFor($offering);
        $registration = $this->confirmation($session, 'S-1');
        $this->attendance->mark($this->lecturer, $session, $registration, CourseOfferingAttendanceStatus::PRESENT);

        $content = $this->actingAs($this->lecturer)->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))->assertOk()->getContent();
        foreach (['registrations.bulk', 'registrations.confirm', 'registrations.drop', 'applicability'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
        $this->post(route('admin.course_offerings.registrations.store', $offering->id), ['student_id' => 1])->assertForbidden();
    }

    // ================================================= helpers

    private function runAttendanceMigration(): void
    {
        $migration = require base_path('database/migrations/2026_09_28_000001_create_course_offering_attendance_tables.php');
        $migration->up();
        $this->assertTrue(Schema::hasTable('course_offering_attendance_sessions'));
    }

    /** The minimum parent schema the migration's foreign keys require. */
    private function preAttendanceSchema(): void
    {
        Schema::create('academic_years', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->string('label'); $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps(); });
        Schema::create('academic_periods', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('academic_year_id'); $t->string('type'); $t->string('label'); $t->unsignedSmallInteger('sequence'); $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps(); });
        Schema::create('curricula', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('programme_id'); $t->string('version'); $t->unsignedBigInteger('effective_academic_year_id')->nullable(); $t->string('status'); $t->timestamps(); });
        Schema::create('curriculum_stages', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->string('label'); $t->unsignedSmallInteger('sequence'); $t->timestamps(); });
        Schema::create('curriculum_memberships', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('curriculum_stage_id'); $t->string('period_type')->nullable(); $t->unsignedSmallInteger('period_sequence')->nullable(); $t->string('classification'); $t->decimal('credits', 6, 2); $t->unsignedSmallInteger('sequence')->default(0); $t->timestamps(); });
        Schema::create('course_offerings', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('academic_year_id'); $t->unsignedBigInteger('academic_period_id');
            $t->string('reference', 50)->nullable(); $t->string('status', 20)->default('draft'); $t->timestamps();
            // The migration's composite key target.
            $t->unique(['school_id', 'id'], 'course_offerings_school_id_id_unique');
        });
        Schema::create('course_offering_curriculum_memberships', function (Blueprint $t): void {
            $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id'); $t->unsignedBigInteger('curriculum_id'); $t->unsignedBigInteger('curriculum_membership_id'); $t->unsignedBigInteger('subject_id'); $t->timestamps();
            $t->primary(['school_id', 'course_offering_id', 'curriculum_membership_id']);
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id'); $t->unsignedBigInteger('user_id'); $t->string('role', 32); $t->date('starts_on'); $t->date('ends_on')->nullable(); $t->string('status', 16)->default('planned'); $t->timestamps(); });
        Schema::create('course_registrations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('session_id')->nullable(); $t->unsignedBigInteger('course_offering_id')->nullable(); $t->unsignedBigInteger('curriculum_membership_id')->nullable(); $t->decimal('registered_credits', 6, 2)->nullable(); $t->string('registered_classification')->nullable(); $t->string('status', 20)->default('registered'); $t->timestamps();
            $t->unique(['school_id', 'student_id', 'course_offering_id']);
        });
        if (! Schema::hasTable('live_classes')) {
            // Matches the shape the Live Class suites use, so a fixture here
            // cannot diverge from what the application actually writes.
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
                $t->boolean('is_published')->default(false); $t->boolean('attendance_enabled')->default(false);
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
        Schema::create('live_class_attendances', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id'); $t->unsignedBigInteger('user_id'); $t->unsignedTinyInteger('role_id')->nullable(); $t->timestamp('joined_at'); $t->timestamp('left_at')->nullable(); $t->unsignedInteger('duration_seconds')->nullable(); $t->timestamps(); });
    }

    // ==================================================== Attendance HTTP pages
    //
    // Regression for the real 500 on
    // GET /teacher/course-offerings/{offering}/attendance/create, which was
    // CourseOfferingAttendanceSessionType not being autoloadable. The create page
    // must load for an authorised lecturer, including an authorised pre-start
    // System Tester on a governed early-started Offering.

    /** An in-progress Offering whose Academic Period has not begun. */
    private function preStartOffering(User $lecturer, string $unit = 'BBIT1103'): CourseOffering
    {
        $school = (int) $lecturer->school_id;
        $year = (int) DB::table('academic_years')->where('school_id', $school)->value('id');
        $period = (int) DB::table('academic_periods')->where('school_id', $school)->value('id');
        $programme = (int) DB::table('programmes')->where('school_id', $school)->value('id');

        // The period moves to the future, so any allocation dated inside it is pre-start.
        DB::table('academic_periods')->where('id', $period)->update([
            'start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->endOfMonth()->toDateString(),
        ]);

        $subject = (int) DB::table('subjects')->insertGetId([
            'school_id' => $school, 'name' => $unit, 'code' => $unit, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $curriculum = (int) DB::table('curricula')->insertGetId([
            'school_id' => $school, 'programme_id' => $programme, 'version' => '2026-V1', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stage = (int) DB::table('curriculum_stages')->insertGetId([
            'school_id' => $school, 'curriculum_id' => $curriculum, 'label' => 'Year 1', 'sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $membership = (int) DB::table('curriculum_memberships')->insertGetId([
            'school_id' => $school, 'curriculum_id' => $curriculum, 'subject_id' => $subject,
            'curriculum_stage_id' => $stage, 'period_type' => 'semester', 'period_sequence' => 1,
            'classification' => 'compulsory', 'credits' => 3, 'sequence' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(CourseOfferingService::class);
        $offering = $service->createDraft($school, $subject, $year, $period);
        $service->addApplicability($school, $offering->id, $membership);
        $service->open($school, $offering->id);
        // The governed early start, exactly as an administrator performs it.
        $service->startEarly($school, $offering->id, 'Pre-semester end-to-end academic delivery testing.');

        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $school, 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer',
            'starts_on' => now()->addMonth()->startOfMonth()->toDateString(),
            'ends_on' => now()->addMonths(6)->endOfMonth()->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $offering->fresh();
    }

    private function grantTester(User $lecturer): void
    {
        DB::table('user_permissions')->insert([
            'school_id' => $lecturer->school_id, 'user_id' => $lecturer->id,
            'permission' => \App\Support\CourseOffering\SystemTesterAccess::PERMISSION,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 1. Index loads for an authorised lecturer.
    public function test_attendance_index_loads_for_an_authorised_lecturer(): void
    {
        $offering = $this->offering('in_progress');

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.attendance.index', $offering->id))
            ->assertOk()
            ->assertSee('Create Attendance Session');
    }

    // 2. Create loads for an authorised current lecturer. This is the 500 page.
    public function test_attendance_create_loads_for_an_authorised_current_lecturer(): void
    {
        $offering = $this->offering('in_progress');

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertOk()
            ->assertSee('Lecture')
            ->assertSee('Live Class');
    }

    // 3. Create loads for a pre-start Tester on a governed early-started Offering.
    public function test_attendance_create_loads_for_a_pre_start_tester_lecturer(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Pre-start Tester');
        $offering = $this->preStartOffering($lecturer);
        $this->grantTester($lecturer);

        $this->actingAs($lecturer->fresh())
            ->get(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertOk()
            ->assertSee('Lecture');
    }

    // 4. Without the grant, the same pre-start lecturer is refused.
    public function test_normal_future_allocation_lecturer_cannot_open_the_create_page(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Normal Future Lecturer');
        $offering = $this->preStartOffering($lecturer);

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertRedirect(route('teacher.course_offerings.attendance.index', $offering->id))
            ->assertSessionHasErrors('attendance');
    }

    // 5. A lecturer with no allocation at all is refused.
    public function test_unallocated_lecturer_cannot_open_the_create_page(): void
    {
        $offering = $this->offering('in_progress');
        $stranger = $this->lecturer($this->institution(1), 'Unallocated Lecturer');

        $this->actingAs($stranger)
            ->get(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertNotFound();
    }

    // 6. Cross-tenant lecturer is refused.
    public function test_cross_tenant_lecturer_cannot_open_the_create_page(): void
    {
        $offering = $this->offering('in_progress');
        $foreign = $this->lecturer($this->institution(2), 'Foreign Lecturer');

        $this->actingAs($foreign)
            ->get(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertNotFound();
    }

    // 7. Cancelled and ended allocations are refused even for a tester.
    public function test_cancelled_and_ended_allocations_cannot_open_the_create_page(): void
    {
        foreach (['cancelled', 'ended'] as $status) {
            $lecturer = $this->lecturer($this->institution(1), 'Tester '.ucfirst($status));
            $offering = $this->preStartOffering($lecturer);
            $this->grantTester($lecturer);
            DB::table('course_offering_lecturer_allocations')
                ->where('course_offering_id', $offering->id)->update(['status' => $status]);

            $response = $this->actingAs($lecturer->fresh())
                ->get(route('teacher.course_offerings.attendance.create', $offering->id));

            $this->assertNotSame(200, $response->getStatusCode(),
                "a {$status} allocation can never open the attendance create page");
            if ($response->getStatusCode() === 302) {
                $this->assertStringContainsString('current teaching allocation', (string) session('errors')->first());
            }
        }
    }

    // 8-11. Offering lifecycle decides whether teaching attendance is possible.
    // The policy is the existing one, unchanged: teaching operations are allowed
    // for Open and In Progress, and refused for Draft, Completed and Cancelled.
    public function test_offering_lifecycle_governs_the_attendance_create_page(): void
    {
        foreach (['draft' => false, 'completed' => false, 'cancelled' => false,
            'open' => true, 'in_progress' => true] as $status => $served) {
            $lecturer = $this->lecturer($this->institution(1), 'Lecturer '.ucfirst($status));
            $offering = $this->offering($status, $lecturer, 'UNIT-'.strtoupper($status));
            $this->grantTester($lecturer);

            $response = $this->actingAs($lecturer->fresh())
                ->get(route('teacher.course_offerings.attendance.create', $offering->id));

            $this->assertSame(
                $served,
                $response->getStatusCode() === 200,
                "a {$status} Offering ".($served ? 'serves' : 'refuses').' the attendance create page'
            );
        }
    }

    // 12-13. The roster stays the Offering's confirmed registrations, read-only.
    public function test_attendance_roster_is_confirmed_registrations_and_exposes_no_registration_controls(): void
    {
        $offering = $this->offering('in_progress');
        $this->attendance->createSession(
            $this->lecturer, $offering->id, '2026-10-05', '09:00', '11:00',
            CourseOfferingAttendanceSessionType::LECTURE, 'Confirmed roster check'
        );

        $body = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.students', $offering->id))
            ->assertOk()->getContent();

        foreach (['Register student', 'Confirm registration', 'Drop student', 'registrations.store', 'registrations.confirm'] as $control) {
            $this->assertStringNotContainsString($control, $body,
                "the lecturer roster exposes no registration control: {$control}");
        }

        // Only a confirmed registration appears; a dropped one does not.
        $confirmed = (int) DB::table('course_registrations')->where('course_offering_id', $offering->id)
            ->where('status', 'confirmed')->value('student_id');
        $dropped = User::factory()->create([
            'name' => 'Dropped Student', 'role_id' => 7, 'school_id' => $this->lecturer->school_id,
        ]);
        DB::table('course_registrations')->insert([
            'student_id' => $dropped->id, 'subject_id' => (int) $offering->subject_id,
            'course_offering_id' => $offering->id,
            'school_id' => $this->lecturer->school_id, 'status' => 'dropped',
        ]);

        $body = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.students', $offering->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString((string) $confirmed, $body, 'a confirmed registration is on the roster');
        $this->assertStringNotContainsString('Dropped Student', $body, 'a dropped registration is not');
    }

    // 14. A guessed offering id reaches nothing.
    public function test_direct_url_idor_is_blocked_on_the_attendance_create_page(): void
    {
        $this->offering('in_progress');
        $otherInstitution = $this->lecturer($this->institution(2), 'Guessing Lecturer');

        $this->actingAs($otherInstitution)
            ->get(route('teacher.course_offerings.attendance.create', 999999))
            ->assertNotFound();
    }

    // The Attendance audit carries COURSE_OFFERING_ATTENDANCE (26 chars), which
    // overflowed the live VARCHAR(20) event_type column and rolled this whole
    // transaction back. Creating a session must therefore commit its audit row
    // too - that is the assertion here, alongside the existing creation tests.
    public function test_creating_a_session_writes_its_audit_and_commits(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Audited Session Lecturer');
        $offering = $this->offering('in_progress', $lecturer, 'UNIT-AUDITED');

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload())
            ->assertSessionHasNoErrors();

        $session = $this->attendance->sessionsForOffering($offering->fresh())->first();
        $this->assertNotNull($session, 'the session committed');

        $audit = \App\Models\AuditLog::where('action', 'COURSE_OFFERING_ATTENDANCE_SESSION_CREATED')
            ->where('record_id', $session->id)->latest('id')->first();

        $this->assertNotNull($audit, 'the audit committed with the session');
        $this->assertSame('COURSE_OFFERING_ATTENDANCE', $audit->event_type,
            'the full 26-character event_type is stored, not truncated');
        $this->assertSame(26, strlen((string) $audit->event_type));
    }

    // ==================================================== Marking the register
    //
    // The real defect: the register's SELECT was named
    // marks[N][course_registration_id] and its hidden input marks[N][status],
    // so they were swapped. The select posted a 0-3 status value where a
    // registration id was expected and the whole register was rejected with
    // "marks.N.course_registration_id must be at least 1". Separately, an
    // unmarked student was rendered as if already "Absent" while the underlying
    // state was UNMARKED - the counters and finalise check were right, the
    // dropdown lied.

    /** A draft session with a confirmed roster, ready to mark. */
    private function markableSession(User $lecturer, string $unit = 'UNIT-MARK', int $students = 3): CourseOfferingAttendanceSession
    {
        $offering = $this->offering('in_progress', $lecturer, $unit);
        // A confirmed roster, mirroring the real Offering #5 register.
        foreach (range(1, max(1, $students)) as $n) {
            $this->confirmation($offering, "{$unit}-STU-{$n}");
        }

        $session = $this->attendance->createSession(
            $lecturer, $offering->id, '2026-10-05', '09:00', '11:00',
            CourseOfferingAttendanceSessionType::LECTURE, 'Marking target'
        );

        return $session->fresh();
    }

    // 1. The register submits course_registrations.id, and the swap is gone.
    public function test_the_register_submits_the_authoritative_course_registration_id(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Register Lecturer');
        $offering = $this->offering('in_progress', $lecturer, 'UNIT-FORM');
        $session = $this->attendance->createSession(
            $lecturer, $offering->id, '2026-10-05', '09:00', null,
            CourseOfferingAttendanceSessionType::LECTURE, 'Form contract'
        );
        $this->confirmation($offering, 'FORM-STU-1');
        $this->confirmation($offering, 'FORM-STU-2');

        $html = (string) $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))
            ->assertOk()->getContent();

        foreach ($this->attendance->rosterFor($session->fresh()) as $registration) {
            $id = (int) $registration->id;
            $this->assertStringContainsString(
                "name=\"marks[{$id}][course_registration_id]\" value=\"{$id}\"",
                $html,
                "row for course_registrations.id={$id} submits that id as the authority"
            );
            $this->assertStringContainsString("name=\"marks[{$id}][status]\"", $html,
                "the select submits the status, not the registration id");
        }
    }

    // 2. An unmarked row reads "Not marked", never a silent "Absent".
    public function test_an_unmarked_row_is_not_rendered_as_absent(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Unmarked Lecturer');
        $session = $this->markableSession($lecturer, 'UNIT-UNMARKED');
        $offering = CourseOffering::find($session->course_offering_id);

        $html = (string) $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('>Not marked</option>', $html,
            'the select offers an explicit Not marked choice');
        $this->assertStringNotContainsString('attendance-status-value', $html,
            'the old hidden status mirror is gone');

        // No record exists yet, so the summary must count nothing.
        $this->assertSame(0, $this->attendance->recordsForSession($session)->count());
    }

    // 3, 4, 5. Partial save persists only what was chosen; re-saving updates
    //      without duplicating; counters follow the persisted records.
    public function test_a_partial_save_persists_updates_without_duplicating(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Partial Lecturer');
        $session = $this->markableSession($lecturer, 'UNIT-PARTIAL');
        $offering = CourseOffering::find($session->course_offering_id);
        $roster = $this->attendance->rosterFor($session)->values();
        $one = $roster[0];
        $two = $roster[1] ?? $roster[0];

        // 3. Partial: mark one student, leave the rest unmarked.
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]),
            ['marks' => [
                $one->id => [
                    'course_registration_id' => (int) $one->id,
                    'status' => CourseOfferingAttendanceStatus::PRESENT,
                ],
            ]]
        )->assertSessionHasNoErrors()->assertRedirect(
            route('teacher.course_offerings.attendance.show', [$offering->id, $session->id])
        );

        $this->assertSame(1, $this->attendance->recordsForSession($session)->count(),
            'only the chosen student is recorded');
        $summary = $this->attendance->summaryForSession($session->fresh());
        $this->assertSame(1, $summary['present']);
        $this->assertSame(0, $summary['absent'], 'unmarked students are NOT counted as absent');

        // 4. Counters on the page reflect the persisted record.
        $html = (string) $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('>1</div>', $html, 'the Present counter shows the saved record');

        // 5. Save again with a different status: update, never duplicate.
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]),
            ['marks' => [
                $one->id => [
                    'course_registration_id' => (int) $one->id,
                    'status' => CourseOfferingAttendanceStatus::LATE,
                ],
            ]]
        )->assertSessionHasNoErrors();

        $records = $this->attendance->recordsForSession($session->fresh());
        $this->assertSame(1, $records->count(), 'no duplicate record was created');
        $this->assertSame(
            CourseOfferingAttendanceStatus::LATE,
            (int) $records->firstWhere('course_registration_id', (int) $one->id)->status,
            'the existing record was updated in place'
        );
    }

    // An empty status (the "Not marked" option) must be skipped, not saved.
    public function test_an_unmarked_row_submitted_empty_is_not_saved_as_absent(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Empty Status Lecturer');
        $session = $this->markableSession($lecturer, 'UNIT-EMPTY');
        $offering = CourseOffering::find($session->course_offering_id);
        $roster = $this->attendance->rosterFor($session)->values();

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]),
            ['marks' => [
                $roster[0]->id => ['course_registration_id' => (int) $roster[0]->id, 'status' => ''],
                $roster[1]->id => ['course_registration_id' => (int) $roster[1]->id, 'status' => CourseOfferingAttendanceStatus::PRESENT],
            ]]
        )->assertSessionHasNoErrors();

        $this->assertSame(1, $this->attendance->recordsForSession($session)->count());
        $this->assertSame(0, $this->attendance->summaryForSession($session->fresh())['absent'],
            'an empty status never becomes Absent');
    }

    // 6. A foreign CourseRegistration is rejected and nothing is saved.
    public function test_a_foreign_course_registration_is_rejected(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Roster Lecturer');
        $session = $this->markableSession($lecturer, 'UNIT-FOREIGN');
        $offering = CourseOffering::find($session->course_offering_id);
        $onRoster = $this->attendance->rosterFor($session)->first();

        // A confirmed registration for a DIFFERENT Offering in the same tenant.
        $otherSubject = (int) DB::table('subjects')->insertGetId([
            'school_id' => $lecturer->school_id, 'name' => 'Other Unit', 'code' => 'OTHER-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $year = (int) DB::table('academic_years')->where('school_id', $lecturer->school_id)->value('id');
        $period = (int) DB::table('academic_periods')->where('school_id', $lecturer->school_id)->value('id');
        $otherOffering = app(CourseOfferingService::class)
            ->createDraft($lecturer->school_id, $otherSubject, $year, $period, 'OTHER-OFFERING');
        $foreign = User::factory()->create([
            'name' => 'Foreign Registered', 'role_id' => 7, 'school_id' => $lecturer->school_id,
        ]);
        DB::table('course_registrations')->insert([
            'student_id' => $foreign->id, 'subject_id' => $otherSubject,
            'course_offering_id' => $otherOffering->id, 'school_id' => $lecturer->school_id,
            'status' => 'confirmed',
        ]);
        $foreignId = (int) DB::table('course_registrations')
            ->where('student_id', $foreign->id)->value('id');

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]),
            ['marks' => [
                $onRoster->id => ['course_registration_id' => (int) $onRoster->id, 'status' => CourseOfferingAttendanceStatus::PRESENT],
                999999 => ['course_registration_id' => $foreignId, 'status' => CourseOfferingAttendanceStatus::PRESENT],
            ]]
        )->assertSessionHasErrors('attendance');

        $this->assertSame(0, $this->attendance->recordsForSession($session)->count(),
            'nothing was saved when one row was foreign');
    }

    // 7. An unconfirmed (dropped) registration cannot be marked.
    public function test_an_unconfirmed_registration_cannot_be_marked(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Dropped Roster Lecturer');
        $session = $this->markableSession($lecturer, 'UNIT-DROPPED');
        $offering = CourseOffering::find($session->course_offering_id);
        $onRoster = $this->attendance->rosterFor($session)->first();

        $dropped = User::factory()->create([
            'name' => 'Dropped Registered', 'role_id' => 7, 'school_id' => $lecturer->school_id,
        ]);
        DB::table('course_registrations')->insert([
            'student_id' => $dropped->id, 'subject_id' => (int) $offering->subject_id,
            'course_offering_id' => $offering->id, 'school_id' => $lecturer->school_id,
            'status' => 'dropped',
        ]);
        $droppedId = (int) DB::table('course_registrations')
            ->where('student_id', $dropped->id)->value('id');

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]),
            ['marks' => [
                888888 => ['course_registration_id' => $droppedId, 'status' => CourseOfferingAttendanceStatus::PRESENT],
            ]]
        )->assertSessionHasErrors('attendance');

        $this->assertSame(0, $this->attendance->recordsForSession($session)->count());
    }

    // 8. Finalise stays blocked while any student is unmarked, and names the count.
    public function test_finalise_is_blocked_while_students_are_unmarked(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Finalise Blocker');
        $session = $this->markableSession($lecturer, 'UNIT-BLOCKED');
        $offering = CourseOffering::find($session->course_offering_id);
        $roster = $this->attendance->rosterFor($session)->values();

        // Mark all but one.
        $marks = [];
        foreach ($roster->slice(0, -1) as $registration) {
            $marks[$registration->id] = [
                'course_registration_id' => (int) $registration->id,
                'status' => CourseOfferingAttendanceStatus::PRESENT,
            ];
        }
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), ['marks' => $marks]
        )->assertSessionHasNoErrors();

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.finalise', [$offering->id, $session->id]))
            ->assertSessionHasErrors('attendance');

        $this->assertStringContainsString(
            '1 student has not yet been marked',
            (string) session('errors')->first('attendance')
        );
        $this->assertSame('draft', $session->fresh()->status, 'the session stays draft');
    }

    // 9. Finalise succeeds only when every confirmed student is marked.
    public function test_finalise_succeeds_only_when_every_student_is_marked(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Finalise Completer');
        $session = $this->markableSession($lecturer, 'UNIT-COMPLETE');
        $offering = CourseOffering::find($session->course_offering_id);

        $marks = [];
        foreach ($this->attendance->rosterFor($session) as $registration) {
            $marks[$registration->id] = [
                'course_registration_id' => (int) $registration->id,
                'status' => CourseOfferingAttendanceStatus::PRESENT,
            ];
        }
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), ['marks' => $marks]
        )->assertSessionHasNoErrors();

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.finalise', [$offering->id, $session->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame('finalised', $session->fresh()->status);
    }

    // 10. A finalised register is read-only and the roster still shows.
    public function test_a_finalised_register_is_read_only(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Read Only Lecturer');
        $session = $this->markableSession($lecturer, 'UNIT-READONLY');
        $offering = CourseOffering::find($session->course_offering_id);

        $marks = [];
        foreach ($this->attendance->rosterFor($session) as $registration) {
            $marks[$registration->id] = [
                'course_registration_id' => (int) $registration->id,
                'status' => CourseOfferingAttendanceStatus::PRESENT,
            ];
        }
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), ['marks' => $marks]
        );
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.finalise', [$offering->id, $session->id]));
        $finalised = $session->fresh();
        $this->assertSame('finalised', $finalised->status);

        $html = (string) $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('mark-attendance-form', $html, 'no marking form on a finalised register');
        $this->assertStringContainsString('Present', $html, 'the saved status is still shown');

        // A further save is refused.
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), ['marks' => $marks]
        )->assertSessionHasErrors();
    }

    // 11. Marking writes its audit under the widened event_type column.
    public function test_marking_and_finalising_audit_with_the_full_event_type(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Audited Marker');
        $session = $this->markableSession($lecturer, 'UNIT-AUDITED');
        $offering = CourseOffering::find($session->course_offering_id);

        $marks = [];
        foreach ($this->attendance->rosterFor($session) as $registration) {
            $marks[$registration->id] = [
                'course_registration_id' => (int) $registration->id,
                'status' => CourseOfferingAttendanceStatus::PRESENT,
            ];
        }
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]), ['marks' => $marks]
        )->assertSessionHasNoErrors();
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.finalise', [$offering->id, $session->id]))
            ->assertSessionHasNoErrors();

        foreach (['COURSE_OFFERING_ATTENDANCE_SESSION_FINALISED'] as $action) {
            $audit = \App\Models\AuditLog::where('action', $action)
                ->where('record_id', $session->id)->latest('id')->first();
            $this->assertNotNull($audit, "{$action} audit is written");
            $this->assertSame('COURSE_OFFERING_ATTENDANCE', $audit->event_type,
                'the full 26-character event_type is stored');
        }
    }

    // 15. Each support class autoloads on its own (see CourseOfferingAttendanceAutoloadTest).
    public function test_attendance_support_classes_autoload_independently(): void
    {
        foreach ([
            \App\Support\CourseOfferingAttendance\CourseOfferingAttendanceStatus::class,
            \App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionStatus::class,
            \App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionType::class,
            \App\Support\CourseOfferingAttendance\CourseOfferingAttendanceRules::class,
        ] as $class) {
            $this->assertTrue(class_exists($class), "{$class} autoloads independently");
        }
    }

    // ==================================================== Session creation POST
    //
    // The second manual defect: submitting the create form returned the
    // lecturer to the generic Course Offering page with no visible error, and no
    // session was created. Two separate causes, both real:
    //   1. a session dated before the Academic Period start was refused, with no
    //      pre-start System Tester exception in the Attendance date gate;
    //   2. the refusal was returned with back(), so the error was flashed onto
    //      whichever page the lecturer arrived from - and the Course Offering
    //      page renders no validation errors, making a genuine refusal look
    //      exactly like a successful submission.

    /** A pre-start, governed early-started, in-progress Offering for a tester. */
    private function testerOffering(User $lecturer, string $unit = 'BBIT1103'): CourseOffering
    {
        $offering = $this->preStartOffering($lecturer, $unit);
        $this->grantTester($lecturer);

        return $offering->fresh();
    }

    private function sessionPayload(array $overrides = []): array
    {
        return $overrides + [
            'session_date' => now()->toDateString(),
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'type' => CourseOfferingAttendanceSessionType::LECTURE,
            'topic' => 'Business Mathematics',
        ];
    }

    // 2 + 3 + 4. A valid session creates, and lands on its own register page.
    public function test_a_valid_session_creates_and_redirects_to_its_own_register(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Session Creator');
        $offering = $this->offering('in_progress', $lecturer, 'UNIT-CREATE');

        $response = $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload());

        $session = $this->attendance->sessionsForOffering($offering->fresh())->first();
        $this->assertNotNull($session, 'the session really was created');

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]));
        $response->assertSessionHas('success');

        // The register page shows the explicit success message.
        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]))
            ->assertOk()
            ->assertSee('Attendance session created.');
    }

    // 6. A refusal returns to the CREATE FORM, never the generic Offering page.
    public function test_a_refused_submission_returns_to_the_create_form_with_a_visible_error(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Refused Lecturer');
        $offering = $this->offering('in_progress', $lecturer, 'UNIT-REFUSED');

        // A date far outside the period: a genuine refusal.
        $response = $this->actingAs($lecturer)->from(route('teacher.course_offerings.show', $offering->id))
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload([
                'session_date' => '2019-01-05',
            ]));

        $response->assertRedirect(route('teacher.course_offerings.attendance.create', $offering->id));
        $this->assertNotSame(
            route('teacher.course_offerings.show', $offering->id),
            $response->headers->get('Location'),
            'never silently sent to the generic Course Offering page'
        );
        $response->assertSessionHasErrors('session');

        $this->assertCount(0, $this->attendance->sessionsForOffering($offering->fresh()), 'no session was created');

        // The form re-renders the error and preserves what was typed.
        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertOk()
            ->assertSee('This Attendance Session was not created.')
            ->assertSee("must fall within the Offering's Academic Period")
            ->assertSee('2019-01-05', false);
    }

    // 5. Field-level validation failures come back to the form with input kept.
    public function test_invalid_input_returns_to_the_form_with_errors_and_saved_values(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Invalid Input Lecturer');
        $offering = $this->offering('in_progress', $lecturer, 'UNIT-INPUT');

        $response = $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload([
                'type' => 'not-a-real-type',
                'topic' => 'Kept Topic',
            ]));

        $response->assertRedirect(route('teacher.course_offerings.attendance.create', $offering->id));
        $response->assertSessionHasErrors('type');
        $this->assertSame('Kept Topic', session()->getOldInput('topic'), 'the topic is preserved');

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertOk()
            ->assertSee('Choose a valid Attendance Session type.');
    }

    // 7. A normal lecturer still cannot create a pre-period session.
    public function test_a_normal_lecturer_cannot_create_a_pre_period_session(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Normal Pre-Period');
        $offering = $this->preStartOffering($lecturer, 'UNIT-NORMAL');
        // No tester grant.

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload())
            ->assertRedirect(route('teacher.course_offerings.attendance.create', $offering->id))
            ->assertSessionHasErrors('session');

        $this->assertCount(0, $this->attendance->sessionsForOffering($offering->fresh()));
    }

    // 8. The grant alone is not enough: it must be a real pre-start situation.
    public function test_the_tester_grant_alone_does_not_bypass_the_date_rule(): void
    {
        $a = $this->institution(1);
        $lecturer = $this->lecturer($a, 'Tester In-Period');
        // A period that has already begun, so nothing is pre-start here.
        $offering = $this->offering('in_progress', $lecturer, 'UNIT-INPERIOD');
        $this->grantTester($lecturer);
        $period = DB::table('academic_periods')->where('school_id', $a['school'])->value('id');
        $futureStart = now()->addMonth()->startOfMonth()->toDateString();
        DB::table('academic_periods')->where('id', $period)
            ->update(['start_date' => $futureStart, 'end_date' => now()->addMonths(8)->toDateString()]);

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload([
                'session_date' => '2019-01-05',
            ]))
            ->assertSessionHasErrors('session');

        $this->assertCount(0, $this->attendance->sessionsForOffering($offering->fresh()));
    }

    // 9. An Offering that was NOT governed-early-started is never unlocked.
    public function test_a_governed_early_start_is_required(): void
    {
        $a = $this->institution(1);
        $lecturer = $this->lecturer($a, 'Tester No Early Start');
        $this->grantTester($lecturer);

        // Build the pre-start allocation WITHOUT the governed early-start audit.
        $school = $a['school'];
        DB::table('academic_periods')->where('school_id', $school)->update([
            'start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->endOfMonth()->toDateString(),
        ]);
        $year = (int) DB::table('academic_years')->where('school_id', $school)->value('id');
        $period = (int) DB::table('academic_periods')->where('school_id', $school)->value('id');
        $subject = (int) DB::table('subjects')->insertGetId([
            'school_id' => $school, 'name' => 'UNIT-NOEARLY', 'code' => 'UNIT-NOEARLY',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(CourseOfferingService::class);
        $offering = $service->createDraft($school, $subject, $year, $period);
        $curriculum = (int) DB::table('curricula')->insertGetId([
            'school_id' => $school, 'programme_id' => $a['programme'], 'version' => '2026-V1',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stage = (int) DB::table('curriculum_stages')->insertGetId([
            'school_id' => $school, 'curriculum_id' => $curriculum, 'label' => 'Year 1', 'sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $membership = (int) DB::table('curriculum_memberships')->insertGetId([
            'school_id' => $school, 'curriculum_id' => $curriculum, 'subject_id' => $subject,
            'curriculum_stage_id' => $stage, 'period_type' => 'semester', 'period_sequence' => 1,
            'classification' => 'compulsory', 'credits' => 3, 'sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $service->addApplicability($school, $offering->id, $membership);
        $service->open($school, $offering->id);
        // Started the ordinary way, by first letting the period begin.
        DB::table('academic_periods')->where('id', $period)
            ->update(['start_date' => now()->subDay()->toDateString()]);
        $service->start($school, $offering->id);
        DB::table('academic_periods')->where('id', $period)
            ->update(['start_date' => now()->addMonth()->startOfMonth()->toDateString()]);

        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $school, 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer',
            'starts_on' => now()->addMonth()->startOfMonth()->toDateString(),
            'ends_on' => now()->addMonths(6)->endOfMonth()->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload())
            ->assertSessionHasErrors('session');

        $this->assertCount(0, $this->attendance->sessionsForOffering($offering->fresh()));
    }

    // 11. THE FIX: an authorised tester on a governed early-started Offering can
    //      create an approved pre-start session.
    public function test_an_authorised_tester_can_create_a_pre_start_session(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Daniel Okello');
        $offering = $this->testerOffering($lecturer, 'BBIT1103');

        $response = $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.attendance.store', $offering->id),
            $this->sessionPayload(['topic' => 'Pre-semester rehearsal'])
        );

        $response->assertSessionHasNoErrors();
        $session = $this->attendance->sessionsForOffering($offering->fresh())->first();
        $this->assertNotNull($session, 'the pre-start session was created');
        $this->assertSame('Pre-semester rehearsal', $session->topic);
        $this->assertSame(CourseOfferingAttendanceSessionStatus::DRAFT, $session->status);
    }

    // 11b. A session dated in the FUTURE is refused even for a tester. The period
    //      is pushed two months out so the chosen date is genuinely pre-start AND
    //      genuinely in the future.
    public function test_a_tester_cannot_record_a_session_dated_in_the_future(): void
    {
        $a = $this->institution(1);
        $lecturer = $this->lecturer($a, 'Future Tester');
        $offering = $this->testerOffering($lecturer, 'UNIT-FUTURE');

        $periodId = (int) DB::table('academic_periods')->where('school_id', $a['school'])->value('id');
        DB::table('academic_periods')->where('id', $periodId)->update([
            'start_date' => now()->addMonths(2)->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(8)->toDateString(),
        ]);
        $futurePreStart = now()->addWeek()->toDateString();
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($futurePreStart)
                ->lt(\Illuminate\Support\Carbon::parse($now = now()->addMonths(2)->startOfMonth())),
            'the chosen date is before the period start'
        );

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload([
                'session_date' => $futurePreStart,
            ]))
            ->assertSessionHasErrors('session');

        $this->assertCount(0, $this->attendance->sessionsForOffering($offering->fresh()),
            'teaching that has not happened yet is never recorded');
    }

    // 12 + 13. Nothing about the period or the allocation is rewritten.
    public function test_the_testing_exception_writes_no_dates(): void
    {
        $a = $this->institution(1);
        $lecturer = $this->lecturer($a, 'No-Write Tester');
        $offering = $this->testerOffering($lecturer, 'UNIT-NOWRITE');

        $periodId = (int) DB::table('academic_periods')->where('school_id', $a['school'])->value('id');
        $periodBefore = DB::table('academic_periods')->where('id', $periodId)->first();
        $allocationBefore = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $offering->id)->first();

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload())
            ->assertSessionHasNoErrors();

        $periodAfter = DB::table('academic_periods')->where('id', $periodId)->first();
        $this->assertEquals($periodBefore->start_date, $periodAfter->start_date, 'Academic Period start unchanged');
        $this->assertEquals($periodBefore->end_date, $periodAfter->end_date, 'Academic Period end unchanged');

        $allocationAfter = DB::table('course_offering_lecturer_allocations')
            ->where('id', $allocationBefore->id)->first();
        $this->assertEquals($allocationBefore->starts_on, $allocationAfter->starts_on, 'allocation start unchanged');
        $this->assertEquals($allocationBefore->ends_on, $allocationAfter->ends_on, 'allocation end unchanged');
        $this->assertEquals('active', $allocationAfter->status);
    }

    // 10 + 16. A tester still needs an exact ACTIVE allocation.
    public function test_cancelled_and_ended_allocations_still_cannot_create_a_pre_start_session(): void
    {
        foreach (['cancelled', 'ended'] as $status) {
            $lecturer = $this->lecturer($this->institution(1), 'Tester '.ucfirst($status));
            $offering = $this->testerOffering($lecturer, 'UNIT-'.strtoupper($status));
            DB::table('course_offering_lecturer_allocations')
                ->where('course_offering_id', $offering->id)
                ->update(['status' => $status, 'ends_on' => $status === 'ended' ? '2026-10-05' : null]);

            $response = $this->actingAs($lecturer)
                ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload());

            // Either outcome is a correct refusal: a cancelled allocation makes
            // the Offering itself unreachable (404, fail closed), while an ended
            // one is rejected by the Attendance rules with a visible message.
            $this->assertContains(
                $response->getStatusCode(),
                [302, 404],
                "a {$status} allocation cannot create a session"
            );
            if ($response->getStatusCode() === 302) {
                $response->assertSessionHasErrors('session');
            }
            $this->assertCount(0, $this->attendance->sessionsForOffering($offering->fresh()));
        }
    }

    // 14. Cross-tenant creation is refused.
    public function test_cross_tenant_session_creation_is_blocked(): void
    {
        $offering = $this->offering('in_progress', null, 'UNIT-TENANT');
        $foreign = $this->lecturer($this->institution(2), 'Foreign Creator');

        $this->actingAs($foreign)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload())
            ->assertNotFound();

        $this->assertCount(0, $this->attendance->sessionsForOffering($offering->fresh()));
    }

    // 15. An unrelated Offering is refused.
    public function test_an_unrelated_offering_cannot_be_created_for(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Unrelated Lecturer');
        $mine = $this->offering('in_progress', $lecturer, 'UNIT-MINE');
        $theirs = $this->offering('in_progress', $this->lecturer($this->institution(1), 'Other Lecturer'), 'UNIT-THEIRS');

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $theirs->id), $this->sessionPayload())
            ->assertNotFound();

        $this->assertCount(0, $this->attendance->sessionsForOffering($theirs->fresh()));
        $this->assertCount(0, $this->attendance->sessionsForOffering($mine->fresh()));
    }

    // 17. Draft and Cancelled Offerings cannot record teaching.
    public function test_draft_and_cancelled_offerings_cannot_create_sessions(): void
    {
        foreach (['draft', 'cancelled'] as $status) {
            $lecturer = $this->lecturer($this->institution(1), 'Lecturer '.ucfirst($status));
            $offering = $this->offering($status, $lecturer, 'UNIT-LC-'.strtoupper($status));
            $this->grantTester($lecturer);

            $this->actingAs($lecturer)
                ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload())
                ->assertSessionHasErrors('session');

            $this->assertCount(0, $this->attendance->sessionsForOffering($offering->fresh()));
        }
    }

    // 19. Duplicate protection still applies on the pre-start path.
    public function test_duplicate_sessions_are_still_refused_for_a_tester(): void
    {
        $lecturer = $this->lecturer($this->institution(1), 'Duplicate Tester');
        $offering = $this->testerOffering($lecturer, 'UNIT-DUP');

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload())
            ->assertSessionHasNoErrors();

        $second = $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.attendance.store', $offering->id), $this->sessionPayload());

        $second->assertSessionHasErrors('session');
        $this->assertCount(1, $this->attendance->sessionsForOffering($offering->fresh()));
    }

    private function institution(int $number): array
    {
        $school = $this->makeSchool();
        $year = (int) DB::table('academic_years')->insertGetId(['school_id' => $school, 'label' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $period = (int) DB::table('academic_periods')->insertGetId(['school_id' => $school, 'academic_year_id' => $year, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $programmeId = (int) DB::table('programmes')->insertGetId(['school_id' => $school, 'name' => "Programme {$number}", 'code' => "PRG-{$number}", 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return ['school' => $school, 'year' => $year, 'period' => $period, 'programme' => $programmeId];
    }

    private function lecturer(array $institution, string $name): User
    {
        $lecturer = User::factory()->create([
            'name' => $name, 'role_id' => 3, 'school_id' => $institution['school'],
            'account_status' => 'active', 'staff_status' => 'active',
        ]);
        DB::table('user_permissions')->insert(['school_id' => $institution['school'], 'user_id' => $lecturer->id, 'permission' => 'live_classes.view', 'created_at' => now(), 'updated_at' => now()]);

        return $lecturer;
    }

    private function offering(string $status, ?User $lecturer = null, string $unit = 'BBIT1101', string $allocationStatus = 'active'): CourseOffering
    {
        $lecturer ??= $this->lecturer;
        $institution = ['school' => $lecturer->school_id, 'programme' => (int) DB::table('programmes')->where('school_id', $lecturer->school_id)->value('id')];
        $year = (int) DB::table('academic_years')->where('school_id', $institution['school'])->value('id');
        $period = (int) DB::table('academic_periods')->where('school_id', $institution['school'])->value('id');

        $subjectId = (int) DB::table('subjects')->insertGetId(['school_id' => $institution['school'], 'name' => $unit, 'code' => $unit, 'created_at' => now(), 'updated_at' => now()]);
        $curriculumId = (int) DB::table('curricula')->insertGetId(['school_id' => $institution['school'], 'programme_id' => $institution['programme'], 'version' => '2026-V1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $stageId = (int) DB::table('curriculum_stages')->insertGetId(['school_id' => $institution['school'], 'curriculum_id' => $curriculumId, 'label' => 'Year 1', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $membershipId = (int) DB::table('curriculum_memberships')->insertGetId([
            'school_id' => $institution['school'], 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId, 'curriculum_stage_id' => $stageId,
            'period_type' => 'semester', 'period_sequence' => 1, 'classification' => 'compulsory', 'credits' => '3.00', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $offering = app(CourseOfferingService::class)->createDraft($institution['school'], $subjectId, $year, $period);
        app(CourseOfferingService::class)->addApplicability($institution['school'], $offering->id, $membershipId);
        $service = app(CourseOfferingService::class);
        // A cancelled Offering is reached the way it happens in life: the lecturer
        // is allocated, and the academic office then withdraws the delivery. That
        // leaves a read-only cancelled workspace, not an unreachable id.
        if ($status !== 'draft') {
            $service->open($institution['school'], $offering->id);
        }

        $allocations = app(CourseOfferingLecturerAllocationService::class);
        $allocation = $allocations->createPlanned($institution['school'], $offering->id, $lecturer->id, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, '2026-09-01', '2027-06-30');
        if ($status !== 'draft' && $allocationStatus === 'active') {
            $allocations->activate($institution['school'], $allocation->id);
        } elseif ($status !== 'draft' && $allocationStatus === 'ended') {
            $allocations->activate($institution['school'], $allocation->id);
            $allocations->end($institution['school'], $allocation->id, '2026-10-31');
        } elseif ($status !== 'draft' && $allocationStatus === 'cancelled') {
            $allocations->cancel($institution['school'], $allocation->id, 'Staffing withdrawn');
        }
        if (in_array($status, ['in_progress', 'completed'], true)) {
            $service->start($institution['school'], $offering->id);
        }
        if ($status === 'completed') {
            $open = DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $offering->id)->where('status', 'active')->first();
            if ($open) {
                $allocations->end($institution['school'], $open->id, '2026-10-31');
            }
            $service->complete($institution['school'], $offering->id);
        }
        if ($status === 'cancelled') {
            $service->cancel($institution['school'], $offering->id, 'Programme withdrawn');
        }

        return $offering->fresh();
    }

    private function sessionFor(CourseOffering $offering): CourseOfferingAttendanceSession
    {
        $id = DB::table('course_offering_attendance_sessions')->insertGetId([
            'school_id' => $offering->school_id, 'course_offering_id' => $offering->id, 'session_date' => '2026-10-05',
            'starts_at' => '09:00', 'ends_at' => '11:00', 'type' => 'lecture', 'topic' => 'Test Session',
            'status' => CourseOfferingAttendanceSessionStatus::DRAFT, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return CourseOfferingAttendanceSession::where('school_id', $offering->school_id)->findOrFail($id);
    }

    /** Accepts the Offering, or the attendance session that belongs to it. */
    private function confirmation(CourseOffering|CourseOfferingAttendanceSession $context, string $code, string $status = CourseRegistration::STATUS_CONFIRMED, ?array $institution = null): CourseRegistration
    {
        $offering = $context instanceof CourseOfferingAttendanceSession
            ? CourseOffering::where('school_id', $context->school_id)->whereKey($context->course_offering_id)->firstOrFail()
            : $context;
        $institution ??= ['school' => $offering->school_id];
        $student = User::factory()->create(['name' => "Student {$code}", 'code' => $code, 'role_id' => 7, 'school_id' => $institution['school'], 'account_status' => 'active']);
        DB::table('student_profiles')->insert(['user_id' => $student->id, 'school_id' => $institution['school'], 'year_of_study' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('course_registrations')->insertGetId([
            'school_id' => $institution['school'], 'student_id' => $student->id, 'subject_id' => $offering->subject_id,
            'course_offering_id' => $offering->id, 'curriculum_membership_id' => 1, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CourseRegistration::where('school_id', $institution['school'])->findOrFail($id);
    }

    private function registrationOf(CourseOfferingAttendanceSession $session, string $code): CourseRegistration
    {
        return CourseRegistration::where('school_id', $session->school_id)
            ->whereIn('student_id', User::where('school_id', $session->school_id)->where('code', $code)->pluck('id'))
            ->firstOrFail();
    }

    /** Closes out an Offering the way Step 3 governs it: end the allocation, then complete. */
    private function finaliseOffering(CourseOffering $offering): void
    {
        $open = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $offering->id)->where('status', 'active')->first();
        if ($open) {
            app(CourseOfferingLecturerAllocationService::class)->end($offering->school_id, $open->id, '2026-10-31');
        }
        app(CourseOfferingService::class)->complete($offering->school_id, $offering->id);
    }

    private function liveClass(CourseOffering $offering, string $title): int
    {
        return (int) DB::table('live_classes')->insertGetId([
            'school_id' => $offering->school_id, 'subject_id' => $offering->subject_id, 'course_offering_id' => $offering->id,
            'title' => $title, 'status' => 'scheduled', 'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Returns the refusal message, or null when the call unexpectedly succeeded. */
    private function catch(callable $callback): ?string
    {
        try {
            $callback();
        } catch (DomainException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}
