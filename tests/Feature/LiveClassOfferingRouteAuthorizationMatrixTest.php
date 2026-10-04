<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

class LiveClassOfferingRouteAuthorizationMatrixTest extends TestCase
{
    use LiveClassTestHelper;

    private int $subjectA;
    private int $subjectB;
    private int $offeringA;
    private int $parallelOffering;
    private int $foreignOffering;

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
            $table->unsignedBigInteger('course_offering_id')->nullable(); $table->string('status'); $table->timestamps();
        });
        Schema::create('addons', function (Blueprint $table): void {
            $table->id(); $table->string('unique_identifier')->nullable(); $table->string('status')->nullable();
        });
        // Needed only by the System Testing student-boundary tests below: the RBAC
        // grant table and the Academic Period, so "before the period starts" is a
        // real condition rather than an assumed one.
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id');
            $table->string('permission', 100); $table->timestamps();
            $table->unique(['user_id', 'permission']);
        });
        Schema::create('academic_periods', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('academic_year_id');
            $table->string('label'); $table->date('start_date'); $table->date('end_date'); $table->string('status');
        });
        DB::table('schools')->insert([['id' => 1, 'title' => 'Tenant A'], ['id' => 2, 'title' => 'Tenant B']]);
        $this->subjectA = $this->subject(1, 'Course A');
        $this->subjectB = $this->subject(1, 'Course B');
        $foreignSubject = $this->subject(2, 'Foreign course');
        $this->offeringA = $this->offering(1, $this->subjectA);
        $this->parallelOffering = $this->offering(1, $this->subjectA);
        $this->foreignOffering = $this->offering(2, $foreignSubject);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_show_and_edit_http_routes_apply_allocation_role_and_historical_view_rules(): void
    {
        $class = $this->liveClass(1, 1, $this->offeringA, ['title' => 'Authorized class']);
        foreach ([
            CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER => [true, true],
            CourseOfferingLecturerAllocation::ROLE_CO_LECTURER => [true, true],
            CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT => [true, false],
            CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR => [true, false],
            CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER => [true, false],
        ] as $role => [$canView, $canManage]) {
            $user = $this->user($role.'-show@example.test', 3, 1);
            $this->allocation($user, $this->offeringA, $role);
            $this->actingAs($user)->get(route('teacher.live_classes.show', $class->id))
                ->assertOk()->assertSee('Authorized class')->assertDontSee('https://meet.example.test/private-secret');
            $this->get(route('teacher.live_classes.materials', $class->id))->assertOk();
            $edit = $this->get(route('teacher.live_classes.edit', $class->id));
            $canManage ? $edit->assertOk() : $edit->assertForbidden();
        }

        $unrelated = $this->user('unrelated-show@example.test', 3, 1);
        $this->actingAs($unrelated)->get(route('teacher.live_classes.show', $class->id))
            ->assertForbidden()->assertDontSee('Authorized class')->assertDontSee('private-secret');
        $ordinaryStaff = $this->user('ordinary-staff-view-only@example.test', 4, 1);
        $this->actingAs($ordinaryStaff)->get(route('admin.live_classes.show', $class->id))
            ->assertForbidden()->assertDontSee('Authorized class')->assertDontSee('private-secret');

        DB::table('course_offerings')->where('id', $this->offeringA)->update(['status' => CourseOffering::STATUS_COMPLETED]);
        DB::table('live_classes')->where('id', $class->id)->update([
            'status' => LiveClass::STATUS_ENDED, 'scheduled_at' => '2026-09-22 10:00:00',
            'ends_at' => '2026-09-22 11:00:00', 'start_date' => '2026-09-22',
        ]);
        $historical = $this->user('historical-route@example.test', 3, 1);
        $this->allocation($historical, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_ENDED, '2026-09-20', '2026-09-23');
        $this->actingAs($historical)->get(route('teacher.live_classes.show', $class->id))->assertOk();
        $this->get(route('teacher.live_classes.edit', $class->id))->assertForbidden();
        $this->put(route('teacher.live_classes.update', $class->id), $this->updatePayload('Historical unauthorized mutation'))
            ->assertForbidden();
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'title' => 'Authorized class']);
    }

    public function test_offering_manage_routes_deny_non_managing_allocations_without_mutation_or_audit(): void
    {
        foreach ([
            CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT,
            CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR,
            CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER,
        ] as $index => $role) {
            $class = $this->liveClass($index + 1, 1, $this->offeringA, [
                'title' => 'Protected title', 'is_published' => 0, 'status' => LiveClass::STATUS_DRAFT,
            ]);
            $materialId = $this->material($class, 'Protected existing resource');
            $user = $this->user("{$role}-manage@example.test", 3, 1);
            $this->allocation($user, $this->offeringA, $role);
            $auditBefore = DB::table('audit_logs')->count();
            $notificationBefore = DB::table('user_notifications')->count();

            $this->actingAs($user)->put(route('teacher.live_classes.update', $class->id), $this->updatePayload())->assertForbidden();
            $this->post(route('teacher.live_classes.publish', $class->id))->assertForbidden();
            $this->post(route('teacher.live_classes.cancel', $class->id))->assertForbidden();
            $this->delete(route('teacher.live_classes.destroy', $class->id))->assertForbidden();
            $this->get(route('teacher.live_classes.attendance', $class->id))->assertForbidden();
            $this->get(route('teacher.live_classes.attendance_export', $class->id))->assertForbidden();
            $this->post(route('teacher.live_classes.materials.store', $class->id), [
                'type' => 'link', 'title' => 'Unauthorized resource', 'link_url' => 'https://example.test/resource',
            ])->assertForbidden();
            $this->delete(route('teacher.live_classes.materials.destroy', $materialId))->assertForbidden();

            $this->assertDatabaseHas('live_classes', [
                'id' => $class->id, 'title' => 'Protected title', 'is_published' => 0,
                'status' => LiveClass::STATUS_DRAFT,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertDatabaseHas('live_class_materials', ['id' => $materialId, 'title' => 'Protected existing resource']);
            $this->assertSame($notificationBefore, DB::table('user_notifications')->count());
        }
    }

    public function test_primary_and_same_tenant_admin_can_manage_publish_cancel_and_attendance(): void
    {
        $primary = $this->user('primary-actions@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $class = $this->liveClass(1, 1, $this->offeringA, ['is_published' => 1]);
        $updated = $this->actingAs($primary)->put(route('teacher.live_classes.update', $class->id), $this->updatePayload());
        $updated->assertRedirect();
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'title' => 'Updated by primary']);
        $this->post(route('teacher.live_classes.publish', $class->id))->assertRedirect();
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'is_published' => 0]);
        $this->post(route('teacher.live_classes.cancel', $class->id))->assertRedirect();
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'status' => LiveClass::STATUS_CANCELLED]);

        $co = $this->user('co-actions@example.test', 3, 1);
        $this->allocation($co, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER);
        $coClass = $this->liveClass(3, 1, $this->offeringA, ['is_published' => 1]);
        $this->actingAs($co)->put(route('teacher.live_classes.update', $coClass->id), $this->updatePayload('Updated by co'))->assertRedirect();
        $this->post(route('teacher.live_classes.publish', $coClass->id))->assertRedirect();
        $this->post(route('teacher.live_classes.cancel', $coClass->id))->assertRedirect();

        $admin = $this->user('tenant-admin-actions@example.test', 2, 1);
        $adminClass = $this->liveClass(2, 1, $this->offeringA, ['is_published' => 1]);
        $this->actingAs($admin)->get(route('admin.live_classes.attendance', $adminClass->id))->assertOk();
        $this->put(route('admin.live_classes.update', $adminClass->id), $this->updatePayload('Updated by admin'))->assertRedirect();
        $this->post(route('admin.live_classes.publish', $adminClass->id))->assertRedirect();
        $this->post(route('admin.live_classes.cancel', $adminClass->id))->assertRedirect();
    }

    public function test_offering_update_cannot_assign_an_unallocated_facilitator(): void
    {
        $primary = $this->user('primary-update-facilitator@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $unrelated = $this->user('unallocated-update-facilitator@example.test', 3, 1);
        $class = $this->liveClass(1, 1, $this->offeringA, ['teacher_id' => $primary->id]);

        $payload = $this->updatePayload('Attempted facilitator reassignment');
        $payload['teacher_id'] = $unrelated->id;
        $response = $this->actingAs($primary)->put(route('teacher.live_classes.update', $class->id), $payload);
        $response->assertSessionHasErrors('teacher_id');
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'teacher_id' => $primary->id, 'title' => 'Offering class 1']);
    }

    public function test_primary_co_and_same_tenant_admin_can_manage_materials(): void
    {
        foreach ([
            CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            CourseOfferingLecturerAllocation::ROLE_CO_LECTURER,
        ] as $index => $role) {
            $class = $this->liveClass($index + 1, 1, $this->offeringA);
            $lecturer = $this->user($role.'-material-manager@example.test', 3, 1);
            $this->allocation($lecturer, $this->offeringA, $role);
            $this->actingAs($lecturer)->post(route('teacher.live_classes.materials.store', $class->id), [
                'type' => 'link', 'title' => 'Allowed '.$role.' resource', 'link_url' => 'https://assets.example.test/allowed',
            ])->assertRedirect();
            $materialId = (int) DB::table('live_class_materials')->where('title', 'Allowed '.$role.' resource')->value('id');
            $this->assertGreaterThan(0, $materialId);
            $this->delete(route('teacher.live_classes.materials.destroy', $materialId))->assertRedirect();
            $this->assertDatabaseMissing('live_class_materials', ['id' => $materialId]);
        }

        $admin = $this->user('admin-material-manager@example.test', 2, 1);
        $adminClass = $this->liveClass(3, 1, $this->offeringA);
        $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $adminClass->id), [
            'type' => 'link', 'title' => 'Allowed admin resource', 'link_url' => 'https://assets.example.test/admin-allowed',
        ])->assertRedirect();
        $adminMaterialId = (int) DB::table('live_class_materials')->where('title', 'Allowed admin resource')->value('id');
        $this->delete(route('admin.live_classes.materials.destroy', $adminMaterialId))->assertRedirect();
        $this->assertDatabaseMissing('live_class_materials', ['id' => $adminMaterialId]);
    }

    public function test_students_cannot_manage_materials_or_attendance(): void
    {
        $class = $this->liveClass(1, 1, $this->offeringA);
        $materialId = $this->material($class, 'Student-protected material');
        $student = $this->user('student-no-management@example.test', 7, 1);
        $this->registration($student, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);

        $upload = $this->actingAs($student)->post(route('admin.live_classes.materials.store', $class->id), [
            'type' => 'link', 'title' => 'Student injected material', 'link_url' => 'https://assets.example.test/student-injected',
        ]);
        $this->assertContains($upload->getStatusCode(), [302, 403]);
        $delete = $this->delete(route('admin.live_classes.materials.destroy', $materialId));
        $this->assertContains($delete->getStatusCode(), [302, 403]);
        $attendance = $this->get(route('admin.live_classes.attendance', $class->id));
        $this->assertContains($attendance->getStatusCode(), [302, 403]);
        $this->assertDatabaseHas('live_class_materials', ['id' => $materialId, 'title' => 'Student-protected material']);
        $this->assertDatabaseMissing('live_class_materials', ['title' => 'Student injected material']);
    }

    public function test_offering_backed_delete_is_denied_even_to_primary_and_admin(): void
    {
        $class = $this->liveClass(1, 1, $this->offeringA);
        $primary = $this->user('primary-delete@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $before = DB::table('audit_logs')->where('description', 'like', '%Deleted live class%')->count();
        $this->actingAs($primary)->delete(route('teacher.live_classes.destroy', $class->id))->assertForbidden();
        $admin = $this->user('admin-delete@example.test', 2, 1);
        $this->actingAs($admin)->delete(route('admin.live_classes.destroy', $class->id))->assertForbidden();
        $this->assertDatabaseHas('live_classes', ['id' => $class->id]);
        $this->assertSame($before, DB::table('audit_logs')->where('description', 'like', '%Deleted live class%')->count());
    }

    public function test_lecturer_join_routes_grant_host_only_to_primary_and_co(): void
    {
        DB::table('global_settings')->insert(['key' => 'live_class_jitsi_base_url', 'value' => 'https://meet.example.test']);
        $class = $this->liveClass(1, 1, $this->offeringA, [
            'platform' => 'jitsi', 'meeting_url' => 'https://meet.example.test/room-1',
            'scheduled_at' => '2026-09-25 10:00:00', 'ends_at' => '2026-09-25 11:00:00',
        ]);
        foreach ([
            CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER => true,
            CourseOfferingLecturerAllocation::ROLE_CO_LECTURER => true,
            CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT => false,
            CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR => false,
            CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER => false,
        ] as $role => $isHostRole) {
            $lecturer = $this->user($role.'-join@example.test', 3, 1);
            $this->allocation($lecturer, $this->offeringA, $role);
            $response = $this->actingAs($lecturer)->get(route('teacher.live_classes.join', $class->id));
            $response->assertOk()
                // PIIE authority: may this person open the classroom at all?
                ->assertViewHas('piiAuthorisedHost', $isHostRole)
                // Provider authority: can PIIE PROVE to Jitsi that this person is
                // a moderator? Not on a public room with no JWT secret - nobody
                // is, including a Primary Lecturer. Asserting the opposite is
                // what used to happen, and it told a lecturer they controlled a
                // room they could not actually moderate.
                ->assertViewHas('isModerator', false)
                ->assertViewHas('jitsiConfigured', false);
        }

        // With a real signed token configured, the claim becomes TRUE and only
        // then - which is the honest causal chain: provider credentials, not a
        // PIIE role, make someone a moderator.
        config([
            'services.jitsi.algorithm' => 'HS256',
            'services.jitsi.app_id' => 'vpaas-magic-cookie-abc',
            'services.jitsi.app_secret' => str_repeat('s', 32),
        ]);
        $this->assertTrue(\App\Support\LiveClasses\JitsiTokenService::isConfigured());
        $primary = $this->user('primary-moderator@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->actingAs($primary)->get(route('teacher.live_classes.join', $class->id))
            ->assertOk()
            ->assertViewHas('piiAuthorisedHost', true)
            ->assertViewHas('isModerator', true)
            ->assertViewHas('jitsiConfigured', true);

        // And a Teaching Assistant is still not a moderator even WITH the token.
        $ta = $this->user('ta-moderator@example.test', 3, 1);
        $this->allocation($ta, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT);
        $this->actingAs($ta)->get(route('teacher.live_classes.join', $class->id))
            ->assertOk()
            ->assertViewHas('piiAuthorisedHost', false)
            ->assertViewHas('isModerator', false);
        config(['services.jitsi.algorithm' => 'RS256', 'services.jitsi.app_id' => '', 'services.jitsi.app_secret' => '']);

        $wrongOffering = $this->user('parallel-join@example.test', 3, 1);
        $this->allocation($wrongOffering, $this->parallelOffering, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $denied = $this->actingAs($wrongOffering)->get(route('teacher.live_classes.join', $class->id));
        $this->assertNotSame('https://meet.example.test/room-1', $denied->headers->get('Location'));
        $this->assertStringNotContainsString('room-1', $denied->getContent() ?: '');
    }

    public function test_student_participation_and_material_routes_require_confirmed_exact_registration(): void
    {
        $class = $this->liveClass(1, 1, $this->offeringA, ['title' => 'Exact offering class']);
        $material = $this->material($class, 'Protected material B');
        foreach ([
            CourseRegistration::STATUS_CONFIRMED => true,
            CourseRegistration::STATUS_REGISTERED => false,
            CourseRegistration::STATUS_DROPPED => false,
        ] as $status => $allowed) {
            $student = $this->user($status.'-student@example.test', 7, 1);
            $this->registration($student, $this->offeringA, $status);
            $listResponse = $this->actingAs($student)->get(route('student.live_classes.materials', $class->id));
            $materialResponse = $this->get(route('live_classes.materials.access', [$class->id, $material]));
            $joinResponse = $this->get(route('student.live_classes.join', $class->id));
            if ($allowed) {
                $listResponse->assertOk()->assertSee('Protected material B');
                $this->assertSame(302, $materialResponse->getStatusCode());
                $this->assertSame('https://assets.example.test/class-notes', $materialResponse->headers->get('Location'));
                $this->assertSame('https://meet.example.test/private-secret', $joinResponse->headers->get('Location'));
            } else {
                $listResponse->assertForbidden()->assertDontSee('Protected material B');
                $materialResponse->assertForbidden()->assertDontSee('assets.example.test')->assertDontSee('Protected material B');
                $this->assertNotSame('https://meet.example.test/private-secret', $joinResponse->headers->get('Location'));
                $this->assertStringNotContainsString('private-secret', $joinResponse->getContent() ?: '');
            }
        }
        $unregistered = $this->user('unregistered-student@example.test', 7, 1);
        $this->actingAs($unregistered)->get(route('student.live_classes.materials', $class->id))->assertForbidden();
        $disabled = $this->user('disabled-student@example.test', 7, 1);
        $this->registration($disabled, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        DB::table('users')->where('id', $disabled->id)->update(['account_status' => 'disable']);
        $disabled->refresh();
        $disabledResponse = $this->actingAs($disabled)->get(route('student.live_classes.materials', $class->id));
        $this->assertContains($disabledResponse->getStatusCode(), [302, 403]);
        $disabledResponse->assertDontSee('Protected material B');

        $foreignStudent = $this->user('foreign-student-route@example.test', 7, 2);
        $this->registration($foreignStudent, $this->foreignOffering, CourseRegistration::STATUS_CONFIRMED);
        $this->actingAs($foreignStudent)->get(route('student.live_classes.materials', $class->id))->assertNotFound();
        $this->get(route('student.live_classes.join', $class->id))->assertNotFound();
    }

    public function test_material_object_idor_is_blocked_for_access_and_delete(): void
    {
        $classA = $this->liveClass(1, 1, $this->offeringA);
        $classB = $this->liveClass(2, 1, $this->parallelOffering);
        $materialB = $this->material($classB, 'Other Offering secret', 'https://assets.example.test/other-secret');
        $primary = $this->user('material-id-or@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);

        $access = $this->actingAs($primary)->get(route('live_classes.materials.access', [$classA->id, $materialB]));
        $access->assertNotFound()->assertDontSee('Other Offering secret')->assertDontSee('other-secret');
        $this->delete(route('teacher.live_classes.materials.destroy', $materialB))->assertForbidden();
        $this->assertDatabaseHas('live_class_materials', ['id' => $materialB]);
        $this->assertDatabaseHas('live_classes', ['id' => $classA->id]);
        $student = $this->user('material-idor-student@example.test', 7, 1);
        $this->registration($student, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $wrongMaterialResponse = $this->actingAs($student)->get(route('live_classes.materials.access', [$classA->id, $materialB]));
        $this->assertSame(404, $wrongMaterialResponse->getStatusCode(), 'Class A + Material B response Location: '.(string) $wrongMaterialResponse->headers->get('Location'));
        $wrongMaterialResponse->assertDontSee('Other Offering secret')->assertDontSee('other-secret');

        $foreignClass = $this->liveClass(3, 2, $this->foreignOffering, ['title' => 'Foreign tenant confidential']);
        $foreignMaterial = $this->material($foreignClass, 'Foreign material secret');
        $admin = $this->user('foreign-object-local-admin@example.test', 2, 1);
        $this->actingAs($admin)->get(route('admin.live_classes.show', $foreignClass->id))->assertNotFound()->assertDontSee('Foreign tenant confidential');
        $this->get(route('live_classes.materials.access', [$foreignClass->id, $foreignMaterial]))
            ->assertNotFound()->assertDontSee('Foreign material secret');
    }

    public function test_recording_routes_preserve_eligible_access_and_deny_unregistered_and_parallel_students(): void
    {
        $class = $this->liveClass(1, 1, $this->offeringA, [
            'status' => LiveClass::STATUS_ENDED, 'scheduled_at' => '2026-09-25 08:00:00',
            'ends_at' => '2026-09-25 09:00:00', 'recording_url' => 'https://recordings.example.test/secret-recording',
        ]);
        $confirmed = $this->user('recording-confirmed-route@example.test', 7, 1);
        $this->registration($confirmed, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $this->actingAs($confirmed)->get(route('live_classes.recording.access', $class->id))
            ->assertRedirect('https://recordings.example.test/secret-recording');

        foreach ([null, $this->parallelOffering] as $offeringId) {
            $student = $this->user('recording-denied-'.($offeringId ?? 'none').'@example.test', 7, 1);
            if ($offeringId !== null) $this->registration($student, $offeringId, CourseRegistration::STATUS_CONFIRMED);
            $response = $this->actingAs($student)->get(route('live_classes.recording.access', $class->id));
            $response->assertForbidden()->assertDontSee('recordings.example.test');
        }

        $lecturer = $this->user('recording-lecturer-route@example.test', 3, 1);
        $this->allocation($lecturer, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_ENDED, '2026-09-20', '2026-09-26');
        $this->actingAs($lecturer)->get(route('live_classes.recording.access', $class->id))
            ->assertRedirect('https://recordings.example.test/secret-recording');

        $foreignClass = $this->liveClass(2, 2, $this->foreignOffering, [
            'status' => LiveClass::STATUS_ENDED, 'recording_url' => 'https://recordings.example.test/foreign-secret',
        ]);
        $this->actingAs($confirmed)->get(route('live_classes.recording.access', $foreignClass->id))
            ->assertNotFound()->assertDontSee('foreign-secret');
    }

    public function test_attendance_routes_are_exact_offering_manager_only_and_tenant_scoped(): void
    {
        $class = $this->liveClass(1, 1, $this->offeringA);
        $ta = $this->user('attendance-ta@example.test', 3, 1);
        $this->allocation($ta, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT);
        $this->actingAs($ta)->get(route('teacher.live_classes.attendance', $class->id))
            ->assertForbidden()->assertDontSee('attendance-secret@example.test');
        $this->get(route('teacher.live_classes.attendance_export', $class->id))->assertForbidden();

        $primary = $this->user('attendance-primary@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->actingAs($primary)->get(route('teacher.live_classes.attendance', $class->id))->assertOk();
        $this->get(route('teacher.live_classes.attendance_export', $class->id))->assertOk();

        $foreignClass = $this->liveClass(2, 2, $this->foreignOffering, ['title' => 'Foreign attendance class']);
        $attendanceId = DB::table('live_class_attendances')->insertGetId([
            'school_id' => 2, 'live_class_id' => $foreignClass->id, 'user_id' => $primary->id,
            'role_id' => 3, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($primary)->get(route('teacher.live_classes.attendance', $foreignClass->id))
            ->assertNotFound()->assertDontSee('Foreign attendance class');
        $this->post(route('teacher.live_classes.attendance_leave', $foreignClass->id), ['attendance_id' => $attendanceId])->assertNotFound();
        $this->assertDatabaseHas('live_class_attendances', ['id' => $attendanceId, 'left_at' => null]);
    }

    public function test_disabled_lecturer_cannot_use_stale_allocation_for_view_or_mutation(): void
    {
        $class = $this->liveClass(1, 1, $this->offeringA, ['title' => 'Disabled user secret class']);
        $disabled = $this->user('disabled-lecturer@example.test', 3, 1);
        $this->allocation($disabled, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        DB::table('users')->where('id', $disabled->id)->update(['account_status' => 'disable']);
        $disabled->refresh();
        $view = $this->actingAs($disabled)->get(route('teacher.live_classes.show', $class->id));
        $this->assertContains($view->getStatusCode(), [302, 403]);
        $view->assertDontSee('Disabled user secret class');
        $mutation = $this->put(route('teacher.live_classes.update', $class->id), $this->updatePayload());
        $this->assertContains($mutation->getStatusCode(), [302, 403]);
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'title' => 'Disabled user secret class']);

        $suspended = $this->user('suspended-lecturer@example.test', 3, 1);
        $this->allocation($suspended, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        DB::table('users')->where('id', $suspended->id)->update(['staff_status' => 'suspended']);
        $suspended->refresh();
        $suspendedView = $this->actingAs($suspended)->get(route('teacher.live_classes.show', $class->id));
        $this->assertContains($suspendedView->getStatusCode(), [302, 403]);
        $suspendedView->assertDontSee('Disabled user secret class');
        $suspendedUpdate = $this->put(route('teacher.live_classes.update', $class->id), $this->updatePayload());
        $this->assertContains($suspendedUpdate->getStatusCode(), [302, 403]);
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'title' => 'Disabled user secret class']);
    }

    public function test_tenant_admin_and_parallel_offering_idor_denials_hide_class_details(): void
    {
        $localClass = $this->liveClass(1, 1, $this->offeringA);
        $parallelClass = $this->liveClass(2, 1, $this->parallelOffering, ['title' => 'Parallel confidential title']);
        $foreignClass = $this->liveClass(3, 2, $this->foreignOffering, ['title' => 'Foreign confidential title']);
        $primary = $this->user('only-offering-a@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->actingAs($primary)->get(route('teacher.live_classes.show', $parallelClass->id))
            ->assertForbidden()->assertDontSee('Parallel confidential title')->assertDontSee('private-secret');
        $this->get(route('teacher.live_classes.show', $foreignClass->id))->assertNotFound()->assertDontSee('Foreign confidential title');

        $admin = $this->user('local-admin-id-or@example.test', 2, 1);
        $this->actingAs($admin)->get(route('admin.live_classes.show', $foreignClass->id))
            ->assertNotFound()->assertDontSee('Foreign confidential title');
        $this->get(route('admin.live_classes.show', $localClass->id))->assertOk();
    }

    /**
     * System Testing is a staff capability only. A Student must never be granted
     * one, and there is deliberately no student pre-start permission, because no
     * student-side pre-start date gate exists to relax.
     */
    public function test_system_testing_never_reaches_students(): void
    {
        $permissions = app(\App\Support\Permissions\PermissionService::class);

        // No student testing permission is registered at all.
        $this->assertFalse($permissions->exists('system.testing.prestart_student'));
        $this->assertTrue($permissions->exists('system.testing.prestart_lecturer'),
            'the lecturer capability exists');

        // Even with a physical row, the non-staff boundary denies a Student.
        $student = $this->user('tester-student@example.test', 7, 1);
        DB::table('user_permissions')->insert([
            'school_id' => 1, 'user_id' => $student->id,
            'permission' => 'system.testing.prestart_lecturer',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student->refresh();

        $this->assertFalse($permissions->allows($student, 'system.testing.prestart_lecturer'));
        $this->assertFalse($permissions->hasGrant($student, 'system.testing.prestart_lecturer'));
        $this->assertSame([], $permissions->grantedPermissions($student),
            'a Student holds no delegated permissions at all');
    }

    /**
     * A confirmed Student already reaches student functionality before the
     * Academic Period begins: there is no student pre-start date gate, so no
     * testing permission is needed or used. Ownership rules are unchanged.
     */
    public function test_confirmed_student_access_is_unchanged_before_the_academic_period_starts(): void
    {
        // Put the Offering in progress while its Academic Period is still ahead.
        $period = DB::table('academic_periods')->insertGetId([
            'school_id' => 1, 'academic_year_id' => 1, 'label' => 'Semester 1',
            'start_date' => Carbon::parse('2026-10-01'), 'end_date' => Carbon::parse('2027-01-25'),
            'status' => 'active',
        ]);
        DB::table('course_offerings')->where('id', $this->offeringA)
            ->update(['status' => 'in_progress', 'academic_period_id' => $period]);
        $this->assertTrue(
            Carbon::parse('2026-10-01')->isFuture(),
            'the Academic Period really has not begun yet'
        );

        $class = $this->liveClass(1, 1, $this->offeringA, ['title' => 'Pre-semester student class']);
        $this->material($class, 'Pre-semester material');

        $confirmed = $this->user('pre-semester-confirmed@example.test', 7, 1);
        $this->registration($confirmed, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);

        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $liveClass = LiveClass::find($class->id);

        $this->assertTrue($access->canStudentViewClass($confirmed, $liveClass),
            'a confirmed registration is sufficient before the Academic Period begins');
        $this->actingAs($confirmed)->get(route('student.live_classes.materials', $class->id))
            ->assertOk()->assertSee('Pre-semester material');

        // Ownership is unchanged: another student, a dropped registration and a
        // cross-tenant student all remain refused.
        $other = $this->user('pre-semester-other@example.test', 7, 1);
        $dropped = $this->user('pre-semester-dropped@example.test', 7, 1);
        $this->registration($dropped, $this->offeringA, CourseRegistration::STATUS_DROPPED);
        $foreign = $this->user('pre-semester-foreign@example.test', 7, 2);

        foreach ([$other, $dropped, $foreign] as $student) {
            $response = $this->actingAs($student)->get(route('student.live_classes.materials', $class->id));
            $this->assertContains($response->getStatusCode(), [403, 404],
                'ownership still refuses: another student, a dropped registration and a cross-tenant student');
        }
    }

    private function liveClass(int $id, int $schoolId, int $offeringId, array $attributes = []): LiveClass
    {
        $row = array_merge([
            'id' => $id, 'school_id' => $schoolId, 'course_offering_id' => $offeringId,
            'title' => 'Offering class '.$id, 'subject_id' => $schoolId === 1 ? $this->subjectA : null,
            'platform' => 'custom', 'meeting_url' => 'https://meet.example.test/private-secret',
            'meeting_id' => 'private-meeting-id', 'meeting_password' => 'private-password',
            'scheduled_at' => '2026-09-25 10:00:00', 'ends_at' => '2026-09-25 11:00:00',
            'start_date' => '2026-09-25', 'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => 1, 'attendance_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes);
        DB::table('live_classes')->insert($row);
        return LiveClass::query()->findOrFail($id);
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

    private function allocation(User $user, int $offeringId, string $role, string $status = 'active', string $startsOn = '2026-09-01', ?string $endsOn = null): void
    {
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $user->school_id, 'course_offering_id' => $offeringId, 'user_id' => $user->id,
            'role' => $role, 'status' => $status, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
        ]);
    }

    private function registration(User $student, int $offeringId, string $status): void
    {
        DB::table('course_registrations')->insert([
            'school_id' => $student->school_id, 'student_id' => $student->id,
            'course_offering_id' => $offeringId, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function material(LiveClass $class, string $title, string $url = 'https://assets.example.test/class-notes'): int
    {
        return (int) DB::table('live_class_materials')->insertGetId([
            'school_id' => $class->school_id, 'live_class_id' => $class->id, 'type' => 'link',
            'category' => 'resource', 'title' => $title, 'link_url' => $url, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function updatePayload(string $title = 'Updated by primary'): array
    {
        return [
            'title' => $title, 'platform' => 'jitsi', 'meeting_url' => 'https://meet.example.test/updated-room',
            'start_date' => '2026-09-25', 'start_time' => '11:00', 'end_time' => '12:00', 'timezone' => 'UTC',
            'status' => 'scheduled', 'is_published' => 1,
        ];
    }
}
