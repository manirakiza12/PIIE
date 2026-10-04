<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\LiveClasses\LiveClassAccessService;
use App\Support\LiveClasses\LiveClassAssetStorage;
use App\Support\LiveClasses\LiveClassEligibility;
use App\Support\LiveClasses\LiveClassNotifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

class LiveClassOfferingAuthorizationTest extends TestCase
{
    use LiveClassTestHelper;

    private LiveClassAccessService $access;
    private string $privateTestRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00', 'UTC'));
        Schema::table('live_classes', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_offering_id')->nullable();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_offering_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('course_offering_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->string('status');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
        });
        Schema::create('addons', function (Blueprint $table): void {
            $table->id();
            $table->string('unique_identifier')->nullable();
            $table->string('status')->nullable();
        });

        DB::table('schools')->insert([
            ['id' => 1, 'title' => 'Tenant one'],
            ['id' => 2, 'title' => 'Tenant two'],
        ]);
        $this->offering(101, 1, CourseOffering::STATUS_OPEN);
        $this->offering(102, 1, CourseOffering::STATUS_OPEN);
        $this->offering(201, 2, CourseOffering::STATUS_OPEN);
        $this->access = app(LiveClassAccessService::class);
        $this->privateTestRoot = storage_path('framework/testing/live-class-s3-assets');
        Config::set('filesystems.disks.local.root', $this->privateTestRoot);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('live-class-private');
        if (is_dir($this->privateTestRoot)) $this->removeDirectory($this->privateTestRoot);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_student_discovery_uses_only_published_confirmed_exact_offerings(): void
    {
        $student = $this->user('discover@example.test', 7, 1);
        $confirmedClass = $this->liveClass(1, 1, 101, ['title' => 'Confirmed offering class']);
        $this->liveClass(2, 1, 102, ['title' => 'Parallel offering class']);
        $this->liveClass(3, 2, 201, ['title' => 'Foreign tenant class']);
        $this->registration($student, 1, 101, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($student, 1, 102, CourseRegistration::STATUS_REGISTERED);

        $response = $this->actingAs($student)->get(route('student.live_classes.index'));
        $response->assertOk()->assertSee('Confirmed offering class')
            ->assertDontSee('Parallel offering class')->assertDontSee('Foreign tenant class');
        $this->assertNotNull($confirmedClass->id);
    }

    public function test_student_join_requires_exact_confirmed_registration_and_reauthorizes_stale_links(): void
    {
        $student = $this->user('join-exact@example.test', 7, 1);
        $class = $this->liveClass(1, 1, 101);
        $this->registration($student, 1, 102, CourseRegistration::STATUS_CONFIRMED);

        $wrongOffering = $this->actingAs($student)->get(route('student.live_classes.join', $class->id));
        $wrongOffering->assertRedirect();
        $this->assertNotSame('https://meet.example.test/secret-room', $wrongOffering->headers->get('Location'));
        $this->assertStringNotContainsString('secret-room', $wrongOffering->getContent() ?: '');

        DB::table('course_registrations')->where('student_id', $student->id)->where('course_offering_id', 102)->delete();
        $this->registration($student, 1, 101, CourseRegistration::STATUS_CONFIRMED);
        $allowed = $this->get(route('student.live_classes.join', $class->id));
        $allowed->assertRedirect('https://meet.example.test/secret-room');

        DB::table('course_registrations')->where('student_id', $student->id)->update(['status' => CourseRegistration::STATUS_DROPPED]);
        $stale = $this->get(route('student.live_classes.join', $class->id));
        $stale->assertRedirect();
        $this->assertNotSame('https://meet.example.test/secret-room', $stale->headers->get('Location'));
    }

    public function test_unconfirmed_unpublished_cancelled_and_non_operational_offerings_cannot_join(): void
    {
        $student = $this->user('join-states@example.test', 7, 1);
        $class = $this->liveClass(1, 1, 101);
        $this->registration($student, 1, 101, CourseRegistration::STATUS_REGISTERED);
        $this->assertFalse($this->access->canStudentJoin($student, $class));

        $this->registration($student, 1, 101, CourseRegistration::STATUS_CONFIRMED);
        $class->is_published = false;
        $this->assertFalse($this->access->canStudentJoin($student, $class));
        $class->is_published = true;
        $class->status = LiveClass::STATUS_CANCELLED;
        $this->assertFalse($this->access->canStudentJoin($student, $class));
        $class->status = LiveClass::STATUS_SCHEDULED;

        foreach ([CourseOffering::STATUS_DRAFT, CourseOffering::STATUS_CANCELLED, CourseOffering::STATUS_COMPLETED] as $status) {
            DB::table('course_offerings')->where('id', 101)->update(['status' => $status]);
            $this->assertFalse($this->access->canStudentJoin($student, $class), $status.' Offering must not permit operational join.');
        }
    }

    public function test_unconfirmed_student_cannot_load_material_metadata_or_access_external_materials(): void
    {
        $student = $this->user('materials-unconfirmed@example.test', 7, 1);
        $class = $this->liveClass(1, 1, 101);
        $this->registration($student, 1, 101, CourseRegistration::STATUS_REGISTERED);
        $materialId = DB::table('live_class_materials')->insertGetId([
            'school_id' => 1,
            'live_class_id' => $class->id,
            'type' => 'link',
            'category' => 'resource',
            'title' => 'SECRET unauthorized notes',
            'original_name' => 'secret-notes.pdf',
            'stored_name' => 'private/offering-101/random-key.pdf',
            'mime_type' => 'application/pdf',
            'link_url' => 'https://external.example.test/secret-material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)->get(route('student.live_classes.materials', $class->id))
            ->assertForbidden()
            ->assertDontSee('SECRET unauthorized notes')
            ->assertDontSee('secret-notes.pdf')
            ->assertDontSee('random-key.pdf')
            ->assertDontSee('external.example.test');
        $this->get(route('live_classes.materials.access', [$class->id, $materialId]))
            ->assertForbidden()
            ->assertDontSee('external.example.test');
    }

    public function test_confirmed_exact_student_can_open_material_list_and_authorized_external_material(): void
    {
        $student = $this->user('materials-confirmed@example.test', 7, 1);
        $class = $this->liveClass(1, 1, 101, ['status' => LiveClass::STATUS_ENDED]);
        $this->registration($student, 1, 101, CourseRegistration::STATUS_CONFIRMED);
        $materialId = DB::table('live_class_materials')->insertGetId([
            'school_id' => 1,
            'live_class_id' => $class->id,
            'type' => 'link',
            'category' => 'resource',
            'title' => 'Authorized offering notes',
            'link_url' => 'https://external.example.test/authorized-material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('student.live_classes.materials', $class->id))
            ->assertOk()
            ->assertSee('Authorized offering notes');
        $this->get(route('live_classes.materials.access', [$class->id, $materialId]))
            ->assertRedirect('https://external.example.test/authorized-material');
    }

    public function test_offering_material_serialization_hides_private_key_and_external_url(): void
    {
        $class = $this->liveClass(1, 1, 101);
        $materialId = DB::table('live_class_materials')->insertGetId([
            'school_id' => 1,
            'live_class_id' => $class->id,
            'type' => 'link',
            'category' => 'resource',
            'title' => 'Serialized protected item',
            'stored_name' => 'offering-101/private-random-key.pdf',
            'link_url' => 'https://external.example.test/secret-material',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $materialArray = \App\Models\LiveClassMaterial::query()->findOrFail($materialId)->toArray();
        $this->assertArrayNotHasKey('stored_name', $materialArray);
        $this->assertArrayNotHasKey('link_url', $materialArray);

        $classArray = LiveClass::query()->findOrFail($class->id)->toArray();
        foreach (['meeting_url', 'meeting_id', 'meeting_password', 'recording_url'] as $field) {
            $this->assertArrayNotHasKey($field, $classArray);
        }
    }

    public function test_join_window_boundaries_are_inclusive_and_incomplete_times_fail_closed(): void
    {
        $student = $this->user('window@example.test', 7, 1);
        $class = $this->liveClass(1, 1, 101, [
            'scheduled_at' => '2026-09-25 10:00:00',
            'ends_at' => '2026-09-25 11:00:00',
        ]);
        $this->registration($student, 1, 101, CourseRegistration::STATUS_CONFIRMED);

        foreach ([
            ['2026-09-25 09:44:59', false],
            ['2026-09-25 09:45:00', true],
            ['2026-09-25 10:00:00', true],
            ['2026-09-25 11:00:00', true],
            ['2026-09-25 11:15:00', true],
            ['2026-09-25 11:15:01', false],
        ] as [$time, $expected]) {
            Carbon::setTestNow(Carbon::parse($time, 'UTC'));
            $this->assertSame($expected, $this->access->canStudentJoin($student, $class), "Join decision at {$time}.");
        }

        $class->scheduled_at = null;
        $this->assertFalse($this->access->canStudentJoin($student, $class));
        $class->scheduled_at = Carbon::parse('2026-09-25 10:00:00', 'UTC');
        $class->ends_at = null;
        $this->assertFalse($this->access->canStudentJoin($student, $class));
    }

    public function test_join_window_http_route_enforces_inclusive_edges_without_url_leak_on_denial(): void
    {
        $student = $this->user('window-http@example.test', 7, 1);
        $class = $this->liveClass(1, 1, 101);
        $this->registration($student, 1, 101, CourseRegistration::STATUS_CONFIRMED);

        foreach ([
            ['2026-09-25 09:44:59', false],
            ['2026-09-25 09:45:00', true],
            ['2026-09-25 10:00:00', true],
            ['2026-09-25 11:00:00', true],
            ['2026-09-25 11:15:00', true],
            ['2026-09-25 11:15:01', false],
        ] as [$time, $expected]) {
            Carbon::setTestNow(Carbon::parse($time, 'UTC'));
            $response = $this->actingAs($student)->get(route('student.live_classes.join', $class->id));
            $location = (string) $response->headers->get('Location');
            if ($expected) {
                $this->assertSame('https://meet.example.test/secret-room', $location, "HTTP join at {$time}.");
            } else {
                $this->assertNotSame('https://meet.example.test/secret-room', $location, "Denied HTTP join at {$time} leaked provider URL.");
                $this->assertStringNotContainsString('secret-room', $response->getContent() ?: '');
            }
        }
    }

    public function test_lecturer_allocation_role_matrix_separates_view_join_manage_and_host(): void
    {
        $class = $this->liveClass(1, 1, 101);
        foreach ([
            CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER => [true, true, true, true],
            CourseOfferingLecturerAllocation::ROLE_CO_LECTURER => [true, true, true, true],
            CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT => [true, true, false, false],
            CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR => [true, true, false, false],
            CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER => [true, true, false, false],
        ] as $role => [$view, $join, $manage, $host]) {
            $lecturer = $this->user($role.'@example.test', 3, 1);
            $this->allocation($lecturer, 1, 101, $role, CourseOfferingLecturerAllocation::STATUS_ACTIVE, '2026-09-25');
            $this->assertSame($view, $this->access->canLecturerView($lecturer, $class), $role.' view');
            $this->assertSame($join, $this->access->canLecturerJoin($lecturer, $class), $role.' join');
            $this->assertSame($manage, $this->access->canLecturerManage($lecturer, $class), $role.' manage');
            $this->assertSame($host, $this->access->canLecturerHost($lecturer, $class), $role.' host');
        }
    }

    public function test_historical_ended_allocation_views_only_on_effective_meeting_date(): void
    {
        DB::table('course_offerings')->where('id', 101)->update(['status' => CourseOffering::STATUS_COMPLETED]);
        $class = $this->liveClass(1, 1, 101, [
            'scheduled_at' => '2026-09-22 10:00:00',
            'ends_at' => '2026-09-22 11:00:00',
            'start_date' => '2026-09-22',
        ]);
        $lecturer = $this->user('historical@example.test', 3, 1);
        $this->allocation($lecturer, 1, 101, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_ENDED, '2026-09-20', '2026-09-23');

        $this->assertTrue($this->access->canLecturerView($lecturer, $class));
        $this->assertFalse($this->access->canLecturerManage($lecturer, $class));
        $this->assertFalse($this->access->canLecturerHost($lecturer, $class));
    }

    public function test_wrong_offering_future_planned_expired_and_missing_rbac_allocations_are_denied(): void
    {
        $class = $this->liveClass(1, 1, 101);
        $wrongOffering = $this->user('wrong-allocation@example.test', 3, 1);
        $this->allocation($wrongOffering, 1, 102, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_ACTIVE, '2026-09-25');
        $this->assertFalse($this->access->canLecturerView($wrongOffering, $class));

        $future = $this->user('future-allocation@example.test', 3, 1);
        $this->allocation($future, 1, 101, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_PLANNED, '2026-09-26');
        $this->assertFalse($this->access->canLecturerManage($future, $class));

        $expired = $this->user('expired-allocation@example.test', 3, 1);
        $this->allocation($expired, 1, 101, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_ACTIVE, '2026-09-20', '2026-09-24');
        $this->assertFalse($this->access->canLecturerManage($expired, $class));

        $noCapability = $this->user('no-capability@example.test', 6, 1);
        $this->allocation($noCapability, 1, 101, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_ACTIVE, '2026-09-25');
        $this->assertFalse($this->access->canLecturerView($noCapability, $class));
    }

    public function test_admin_authority_is_tenant_scoped_and_registration_is_exact_for_private_recording_access(): void
    {
        $class = $this->liveClass(1, 1, 101, [
            'status' => LiveClass::STATUS_ENDED,
            'scheduled_at' => '2026-09-25 08:00:00',
            'ends_at' => '2026-09-25 09:00:00',
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);
        $admin = $this->user('hei-admin@example.test', 2, 1);
        $foreignAdmin = $this->user('foreign-hei-admin@example.test', 2, 2);
        $this->assertTrue($this->access->canTenantAdmin($admin, $class, 'live_classes.manage_all'));
        $this->assertFalse($this->access->canTenantAdmin($foreignAdmin, $class, 'live_classes.manage_all'));

        $student = $this->user('recording-participant@example.test', 7, 1);
        $this->registration($student, 1, 101, CourseRegistration::STATUS_CONFIRMED);
        DB::table('live_classes')->where('id', 1)->update(['recording_url' => 'https://recording.example.test/lecture']);
        $recordingUrl = route('live_classes.recording.access', $class->id);
        $this->actingAs($student)->get($recordingUrl)->assertRedirect('https://recording.example.test/lecture');
        DB::table('course_registrations')->where('student_id', $student->id)->update(['status' => CourseRegistration::STATUS_DROPPED]);
        $this->get($recordingUrl)->assertForbidden();
    }

    public function test_offering_reminder_targets_only_confirmed_exact_tenant_recipients_and_uses_internal_link(): void
    {
        Mail::fake();
        $class = $this->liveClass(1, 1, 101);
        $confirmed = $this->user('notice-confirmed@example.test', 7, 1);
        $pending = $this->user('notice-pending@example.test', 7, 1);
        $dropped = $this->user('notice-dropped@example.test', 7, 1);
        $parallel = $this->user('notice-parallel@example.test', 7, 1);
        $foreign = $this->user('notice-foreign@example.test', 7, 2);
        $disabled = $this->user('notice-disabled@example.test', 7, 1);
        $this->registration($confirmed, 1, 101, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($pending, 1, 101, CourseRegistration::STATUS_REGISTERED);
        $this->registration($dropped, 1, 101, CourseRegistration::STATUS_DROPPED);
        $this->registration($parallel, 1, 102, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($foreign, 2, 101, CourseRegistration::STATUS_CONFIRMED);
        $this->registration($disabled, 1, 101, CourseRegistration::STATUS_CONFIRMED);
        DB::table('users')->where('id', $disabled->id)->update(['account_status' => 'disable']);

        $this->assertEqualsCanonicalizing([$confirmed->id], LiveClassEligibility::eligibleStudentUserIds($class)->all());
        LiveClassNotifier::sendReminder($class, 'reminder_1h');
        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_reminder')->count());
        $notification = DB::table('user_notifications')->where('type', 'live_class_reminder')->first();
        $this->assertSame($confirmed->id, (int) $notification->user_id);
        // A reminder targets the student's DETAIL page, not the join endpoint: a
        // 1-hour reminder lands well outside the 15-minute join window, so
        // pointing it at /join would greet every recipient with a join refusal.
        $this->assertSame(route('student.live_classes.show', $class->id), $notification->url);
        $this->assertStringNotContainsString('/join', $notification->url);
        $this->assertStringNotContainsString('meet.example.test', $notification->body ?: '');
        $this->assertSame(0, DB::table('noticeboard')->count(), 'HEI reminders must not use a school-wide Noticeboard entry.');
    }

    private function offering(int $id, int $schoolId, string $status): void
    {
        DB::table('course_offerings')->insert([
            'id' => $id, 'school_id' => $schoolId, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function liveClass(int $id, int $schoolId, int $offeringId, array $attributes = []): LiveClass
    {
        $defaults = [
            'id' => $id, 'school_id' => $schoolId, 'course_offering_id' => $offeringId,
            'title' => 'Offering class '.$id, 'platform' => 'custom',
            'meeting_url' => 'https://meet.example.test/secret-room',
            'scheduled_at' => '2026-09-25 10:00:00', 'ends_at' => '2026-09-25 11:00:00',
            'start_date' => '2026-09-25', 'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('live_classes')->insert(array_merge($defaults, $attributes));
        return LiveClass::query()->findOrFail($id);
    }

    private function user(string $email, int $roleId, int $schoolId): User
    {
        return User::create([
            'name' => $email, 'email' => $email, 'role_id' => $roleId,
            'school_id' => $schoolId, 'status' => 1, 'account_status' => 'active',
        ]);
    }

    private function registration(User $user, int $schoolId, int $offeringId, string $status): void
    {
        DB::table('course_registrations')->insert([
            'school_id' => $schoolId, 'student_id' => $user->id,
            'course_offering_id' => $offeringId, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function allocation(User $user, int $schoolId, int $offeringId, string $role, string $status, string $startsOn, ?string $endsOn = null): void
    {
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $schoolId, 'course_offering_id' => $offeringId, 'user_id' => $user->id,
            'role' => $role, 'status' => $status, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isDir()) $this->removeDirectory($entry->getPathname());
            else @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
