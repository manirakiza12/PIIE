<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;

/**
 * RBAC / security Phase 2D.
 *
 * A. Student account tenant + role isolation. Every student action must
 *    resolve its target as a student (role 7) in the caller's own school
 *    before touching the account or any related record. Which staff roles
 *    may administer students is deliberately unchanged (future permission
 *    phase); this is tenant/target isolation only.
 *
 * B. School Administrator survivability. A school must keep at least one
 *    viable School Administrator — role 2, account_status not 'disable' and
 *    staff_status not suspended/inactive, i.e. exactly what AdminMiddleware
 *    requires. Admins cannot delete, disable or suspend themselves, and a
 *    non-primary admin cannot do so to the primary admin (school_role = 1).
 *
 * All uploads go to a temp public path; nothing touches the real public/.
 */
class StudentAccountAndAdminSurvivabilitySecurityTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $schoolA;
    private int $schoolB;
    private int $classA;
    private string $publicDir;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        foreach (['payments', 'payment_history'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('password_resets', function (Blueprint $t) {
            $t->string('email')->index();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
        Mail::fake();

        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-phase2d-' . uniqid();
        File::ensureDirectoryExists($this->publicDir);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);

        $this->schoolA = $this->makeSchool(['title' => 'School A', 'status' => 1]);
        $this->schoolB = $this->makeSchool(['title' => 'School B', 'status' => 1]);
        $this->classA = $this->makeClass($this->schoolA);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        parent::tearDown();
    }

    private function user(int $roleId, ?int $schoolId, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $roleId,
            'school_id' => $roleId === 1 ? null : $schoolId,
            'account_status' => 'active',
            'name' => 'Person ' . (++$this->n),
            'user_information' => json_encode(['phone' => '1', 'photo' => '', 'gender' => 'male', 'blood_group' => 'O+', 'birthday' => 0, 'address' => 'a']),
        ], $overrides));
    }

    private function studentPayload(User $target, array $overrides = []): array
    {
        return array_merge([
            'name' => $target->name, 'email' => $target->email, 'gender' => 'male', 'blood_group' => 'O+',
            'birthday' => '2000-01-01', 'phone' => '1', 'address' => 'a', 'class_id' => $this->classA,
        ], $overrides);
    }

    private function adminPayload(User $admin, array $overrides = []): array
    {
        return array_merge([
            'email' => $admin->email, 'first_name' => 'Ada', 'last_name' => 'Admin', 'gender' => 'Female',
            'blood_group' => 'o+', 'birthday' => '01/01/1985', 'phone' => '1', 'address' => 'a',
        ], $overrides);
    }

    /** Seeds one row in every table studentDelete cascades into. */
    private function seedStudentGraph(User $student): void
    {
        $now = ['created_at' => now(), 'updated_at' => now()];
        DB::table('enrollment')->insert(['user_id' => $student->id, 'class_id' => $this->classA, 'section_id' => 0, 'school_id' => $student->school_id, 'session_id' => 1] + $now);
        DB::table('student_profiles')->insert(['user_id' => $student->id, 'school_id' => $student->school_id] + $now);
        foreach (['student_fee_managers', 'daily_attendances', 'book_issues', 'gradebooks'] as $table) {
            $row = ['student_id' => $student->id, 'school_id' => $student->school_id] + $now;
            // Fill any other NOT NULL column without a default with a neutral value.
            foreach (DB::select("PRAGMA table_info({$table})") as $column) {
                if ($column->notnull && $column->dflt_value === null && !$column->pk && !array_key_exists($column->name, $row)) {
                    $row[$column->name] = str_contains(strtolower((string) $column->type), 'int') ? 0 : 'x';
                }
            }
            DB::table($table)->insert(array_intersect_key($row, array_flip(Schema::getColumnListing($table))));
        }
        DB::table('payments')->insert(['user_id' => $student->id] + $now);
        DB::table('payment_history')->insert(['user_id' => $student->id] + $now);
    }

    private function graphCounts(User $user): array
    {
        $counts = [];
        foreach (['enrollment' => 'user_id', 'student_profiles' => 'user_id', 'student_fee_managers' => 'student_id', 'daily_attendances' => 'student_id',
                  'book_issues' => 'student_id', 'gradebooks' => 'student_id', 'payments' => 'user_id', 'payment_history' => 'user_id'] as $table => $column) {
            $counts[$table] = DB::table($table)->where($column, $user->id)->count();
        }
        $counts['users'] = User::whereKey($user->id)->count();

        return $counts;
    }

    // ── A. Student takeover ──────────────────────────────────────────────────

    public function test_student_update_cannot_take_over_the_super_admin(): void
    {
        Notification::fake();
        $teacher = $this->user(3, $this->schoolA);
        $superAdmin = $this->user(1, null, ['email' => 'root@platform.test']);

        $this->actingAs($teacher)->post(route('admin.student.update', $superAdmin->id), $this->studentPayload($superAdmin, ['email' => 'attacker@evil.test']))->assertNotFound();
        $this->assertSame('root@platform.test', $superAdmin->fresh()->email);

        auth()->logout();
        $this->post(route('password.email'), ['email' => 'attacker@evil.test']);
        Notification::assertNotSentTo($superAdmin->fresh(), ResetPassword::class);
    }

    public function test_student_routes_never_resolve_non_student_accounts(): void
    {
        $teacher = $this->user(3, $this->schoolA);
        $victims = [
            'same-school admin' => $this->user(2, $this->schoolA),
            'other-school admin' => $this->user(2, $this->schoolB),
            'super admin' => $this->user(1, null),
            'parent' => $this->user(6, $this->schoolA),
            'teacher' => $this->user(3, $this->schoolA),
            'accountant' => $this->user(4, $this->schoolA),
            'librarian' => $this->user(5, $this->schoolA),
            'warden' => $this->user(10, $this->schoolA),
        ];

        foreach ($victims as $label => $victim) {
            $this->actingAs($teacher)->post(route('admin.student.update', $victim->id), $this->studentPayload($victim, ['email' => "x{$victim->id}@evil.test", 'name' => 'Renamed']))->assertNotFound();
            $this->assertSame($victim->email, $victim->fresh()->email, "{$label}: email");
            $this->assertNotSame('Renamed', $victim->fresh()->name, "{$label}: name");
            $this->assertSame(0, DB::table('student_profiles')->where('user_id', $victim->id)->count(), "{$label}: no student profile created");
            $this->assertSame(0, DB::table('enrollment')->where('user_id', $victim->id)->count(), "{$label}: no enrollment created");

            foreach (['admin.student_edit_modal', 'admin.student.student_profile', 'admin.student.id_card', 'admin.student.profile_pdf', 'admin.student.profile_excel', 'admin.student.reset_password', 'admin.student.resend_activation'] as $routeName) {
                $this->actingAs($teacher)->get(route($routeName, $victim->id))->assertNotFound();
            }
        }
    }

    // ── A. Student tenant isolation / read privacy ──────────────────────────

    public function test_another_schools_student_cannot_be_read_or_changed(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $foreign = $this->user(7, $this->schoolB, ['name' => 'Foreign Secret Name']);
        DB::table('student_profiles')->insert(['user_id' => $foreign->id, 'school_id' => $this->schoolB, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['admin.student_edit_modal', 'admin.student.student_profile', 'admin.student.id_card', 'admin.student.profile_pdf', 'admin.student.profile_excel'] as $routeName) {
            $response = $this->actingAs($admin)->get(route($routeName, $foreign->id));
            $response->assertNotFound();
            $this->assertStringNotContainsString('Foreign Secret Name', (string) $response->getContent(), $routeName);
        }

        $this->actingAs($admin)->post(route('admin.student.update', $foreign->id), $this->studentPayload($foreign, ['name' => 'Moved', 'email' => 'moved@evil.test']))->assertNotFound();
        $this->assertSame('Foreign Secret Name', $foreign->fresh()->name);
        $this->assertNotSame('moved@evil.test', $foreign->fresh()->email);
        $this->assertSame($this->schoolB, (int) DB::table('student_profiles')->where('user_id', $foreign->id)->value('school_id'));
    }

    public function test_same_school_student_management_is_unchanged(): void
    {
        foreach ([2, 3] as $roleId) {
            $actor = $this->user($roleId, $this->schoolA);
            $student = $this->user(7, $this->schoolA);

            $this->actingAs($actor)->get(route('admin.student.student_profile', $student->id))->assertOk();
            $this->actingAs($actor)->get(route('admin.student.id_card', $student->id))->assertOk();

            $this->actingAs($actor)->post(route('admin.student.update', $student->id), $this->studentPayload($student, ['name' => 'Updated Name', 'email' => "updated{$roleId}@example.test"]));
            $this->assertSame('Updated Name', $student->fresh()->name, "role {$roleId}");
            $this->assertSame("updated{$roleId}@example.test", $student->fresh()->email, "role {$roleId}");
        }
    }

    public function test_request_supplied_school_and_role_do_not_move_a_student(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $student = $this->user(7, $this->schoolA);

        $this->actingAs($admin)->post(route('admin.student.update', $student->id), $this->studentPayload($student, ['school_id' => $this->schoolB, 'role_id' => 2, 'school_role' => 1]));

        $fresh = $student->fresh();
        $this->assertSame($this->schoolA, (int) $fresh->school_id);
        $this->assertSame(7, (int) $fresh->role_id);
        $this->assertNull($fresh->school_role);
    }

    public function test_student_email_changes_must_stay_unique(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']); // the live users table has no unique index on email
        });
        $admin = $this->user(2, $this->schoolA, ['email' => 'head@school.test']);
        $student = $this->user(7, $this->schoolA);
        $original = $student->email;

        $this->actingAs($admin)->post(route('admin.student.update', $student->id), $this->studentPayload($student, ['email' => 'head@school.test', 'name' => 'Should Not Save']));

        $this->assertSame($original, $student->fresh()->email);
        $this->assertNotSame('Should Not Save', $student->fresh()->name);
        $this->assertSame(1, User::where('email', 'head@school.test')->count());
    }

    // ── A. Student delete safety ─────────────────────────────────────────────

    public function test_delete_cannot_touch_another_schools_student_or_a_non_student(): void
    {
        $teacher = $this->user(3, $this->schoolA);
        $admin = $this->user(2, $this->schoolA);
        $foreignStudent = $this->user(7, $this->schoolB);
        $foreignAdmin = $this->user(2, $this->schoolB);
        $sameSchoolTeacher = $this->user(3, $this->schoolA);
        foreach ([$foreignStudent, $foreignAdmin, $sameSchoolTeacher] as $victim) {
            $this->seedStudentGraph($victim);
        }

        foreach ([$foreignStudent, $foreignAdmin, $sameSchoolTeacher] as $victim) {
            $before = $this->graphCounts($victim);
            $this->actingAs($teacher)->delete(route('admin.student.delete', $victim->id), ['confirmation' => 'DELETE'])->assertNotFound();
            if ($victim->is($foreignStudent)) {
                $this->actingAs($admin)->delete(route('admin.student.delete', $victim->id), ['confirmation' => 'DELETE'])->assertNotFound();
            }
            $this->assertSame($before, $this->graphCounts($victim), "user #{$victim->id} and related records untouched");
        }
    }

    public function test_same_school_student_delete_still_removes_the_full_graph(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $student = $this->user(7, $this->schoolA);
        $bystander = $this->user(7, $this->schoolA);
        $this->seedStudentGraph($student);
        $this->seedStudentGraph($bystander);

        $this->actingAs($admin)
            ->delete(route('admin.student.delete', $student->id), ['confirmation' => 'DELETE'])
            ->assertRedirect()
            ->assertSessionHas('message', 'Student removed successfully. Financial invoices with recorded payment, award, or provider history were retained for reconciliation.');

        $expected = array_fill_keys(['enrollment', 'student_profiles', 'daily_attendances', 'book_issues', 'gradebooks', 'users'], 0);
        $expected['student_fee_managers'] = 1; // financial invoice retained with its historical payment rows
        $expected['payments'] = 1;
        $expected['payment_history'] = 1;
        $this->assertSame($expected, $this->graphCounts($student));
        $this->assertSame(1, $this->graphCounts($bystander)['users']);
        $this->assertSame(1, $this->graphCounts($bystander)['payments']);
        $audit = DB::table('audit_logs')->where('action', 'student.delete')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, (int) $audit->user_id);
        $this->assertSame('Student account permanently deleted.', $audit->description);
        $this->assertNull($audit->record_id);
        $this->assertNull($audit->url);
        $this->assertNull($audit->ip_address);
    }

    public function test_restrictive_academic_history_is_deleted_before_student_and_does_not_block_delete(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $student = $this->user(7, $this->schoolA);
        $this->seedStudentGraph($student);

        // This models the restrictive student reference introduced for
        // academic history: deleting a student with a curriculum assignment
        // must be refused, while all other student records remain intact.
        Schema::create('student_curriculum_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->foreign('student_id', 'test_sca_student_fk')
                ->references('id')->on('users')->onDelete('restrict');
        });
        DB::table('student_curriculum_assignments')->insert(['student_id' => $student->id]);

        $before = $this->graphCounts($student);

        $this->actingAs($admin)
            ->delete(route('admin.student.delete', $student->id), ['confirmation' => 'DELETE'])
            ->assertRedirect()
            ->assertSessionHas('message', 'Student removed successfully.');

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('student_curriculum_assignments', ['student_id' => $student->id]);
        $this->assertSame(0, DB::table('enrollment')->where('user_id', $student->id)->count());
        $this->assertGreaterThan(0, array_sum($before));
    }

    public function test_delete_requires_explicit_confirmation_and_makes_no_changes_without_it(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $student = $this->user(7, $this->schoolA);
        $this->seedStudentGraph($student);
        $before = $this->graphCounts($student);

        $this->actingAs($admin)
            ->delete(route('admin.student.delete', $student->id), ['confirmation' => 'yes'])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame($before, $this->graphCounts($student));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'student.delete')->count());
    }

    public function test_staff_without_student_delete_permission_cannot_submit_the_action(): void
    {
        $viewer = $this->user(20, $this->schoolA);
        $student = $this->user(7, $this->schoolA);
        $this->seedStudentGraph($student);
        $before = $this->graphCounts($student);

        $this->actingAs($viewer)
            ->delete(route('admin.student.delete', $student->id), ['confirmation' => 'DELETE'])
            ->assertRedirect();

        $this->assertSame($before, $this->graphCounts($student));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'student.delete')->count());
    }

    public function test_cleanup_failure_rolls_back_every_delete_and_the_audit_entry(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $student = $this->user(7, $this->schoolA);
        $this->seedStudentGraph($student);
        Schema::create('student_curriculum_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->foreign('student_id', 'test_student_restrict_fk')->references('id')->on('users')->onDelete('restrict');
        });
        DB::table('student_curriculum_assignments')->insert(['student_id' => $student->id]);
        Schema::create('student_cleanup_blocker', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->foreign('student_id', 'test_cleanup_blocker_fk')->references('id')->on('users')->onDelete('restrict');
        });
        DB::table('student_cleanup_blocker')->insert(['student_id' => $student->id]);
        $before = $this->graphCounts($student);

        $this->actingAs($admin)
            ->delete(route('admin.student.delete', $student->id), ['confirmation' => 'DELETE'])
            ->assertRedirect()
            ->assertSessionHas('error', 'Student deletion could not be completed. No records were changed.');

        $this->assertSame($before, $this->graphCounts($student));
        $this->assertDatabaseHas('student_curriculum_assignments', ['student_id' => $student->id]);
        $this->assertDatabaseHas('student_cleanup_blocker', ['student_id' => $student->id]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'student.delete')->count());
    }

    public function test_full_academic_workflow_graph_cascades_and_preserves_shared_and_other_student_rows(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $student = $this->user(7, $this->schoolA);
        $peer = $this->user(7, $this->schoolA);
        $this->createFullDeletionGraphSchema();
        $ownedOnlyTables = [
            'graduation_applications', 'hostel_room_allocations', 'hostel_applications', 'hostel_fees',
            'club_members', 'appraisal_submits', 'feedback', 'student_requests',
            'student_fee_registration_waivers', 'student_financial_setup_reviews',
        ];
        foreach ($ownedOnlyTables as $table) {
            DB::table($table)->insert([['student_id' => $student->id], ['student_id' => $peer->id]]);
        }

        $targetRegistration = DB::table('course_registrations')->insertGetId(['student_id' => $student->id]);
        $peerRegistration = DB::table('course_registrations')->insertGetId(['student_id' => $peer->id]);
        DB::table('course_offering_attendance_records')->insert([
            ['student_id' => $student->id, 'course_registration_id' => $targetRegistration],
            ['student_id' => $peer->id, 'course_registration_id' => $peerRegistration],
        ]);
        DB::table('course_offering_lesson_progress')->insert([
            ['student_id' => $student->id], ['student_id' => $peer->id],
        ]);

        $targetExam = DB::table('online_exam_submissions')->insertGetId(['student_id' => $student->id]);
        $peerExam = DB::table('online_exam_submissions')->insertGetId(['student_id' => $peer->id]);
        foreach (['online_exam_answers', 'online_exam_proctoring_events', 'online_exam_user_notifications'] as $table) {
            DB::table($table)->insert([['submission_id' => $targetExam], ['submission_id' => $peerExam]]);
        }

        $targetAssignment = DB::table('assignment_submissions')->insertGetId(['student_id' => $student->id]);
        $peerAssignment = DB::table('assignment_submissions')->insertGetId(['student_id' => $peer->id]);
        foreach (['assignment_submission_items' => 'assignment_submission_id', 'assignment_question_responses' => 'assignment_submission_id', 'assignment_notifications' => 'submission_id'] as $table => $column) {
            DB::table($table)->insert([[$column => $targetAssignment], [$column => $peerAssignment]]);
        }

        DB::table('student_curriculum_assignments')->insert([
            ['student_id' => $student->id, 'assigned_by' => $admin->id],
            ['student_id' => $peer->id, 'assigned_by' => $student->id],
        ]);
        DB::table('programme_cohort_memberships')->insert([
            ['student_id' => $student->id, 'assigned_by' => $admin->id],
            ['student_id' => $peer->id, 'assigned_by' => $student->id],
        ]);
        $candidate = DB::table('election_candidates')->insertGetId(['student_id' => $student->id, 'manifesto' => 'private manifesto']);
        $peerCandidate = DB::table('election_candidates')->insertGetId(['student_id' => $peer->id, 'manifesto' => 'peer manifesto']);
        DB::table('election_votes')->insert([
            ['candidate_id' => $peerCandidate, 'voter_id' => $student->id],
            ['candidate_id' => $candidate, 'voter_id' => $peer->id],
        ]);
        DB::table('programme_cohorts')->insert(['created_by' => $student->id, 'name' => 'shared cohort']);
        DB::table('curricula')->insert(['name' => 'shared curriculum']);
        DB::table('subjects')->insert(['name' => 'shared course', 'school_id' => $this->schoolA, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)
            ->delete(route('admin.student.delete', $student->id), ['confirmation' => 'DELETE'])
            ->assertRedirect()
            ->assertSessionHas('message', 'Student removed successfully.');

        foreach ([
            'course_registrations' => 'student_id', 'course_offering_attendance_records' => 'student_id',
            'course_offering_lesson_progress' => 'student_id', 'online_exam_submissions' => 'student_id',
            'assignment_submissions' => 'student_id', 'student_curriculum_assignments' => 'student_id',
            'programme_cohort_memberships' => 'student_id',
        ] as $table => $column) {
            $this->assertDatabaseMissing($table, [$column => $student->id]);
            $this->assertDatabaseHas($table, [$column => $peer->id]);
        }
        foreach ($ownedOnlyTables as $table) {
            $this->assertDatabaseMissing($table, ['student_id' => $student->id]);
            $this->assertDatabaseHas($table, ['student_id' => $peer->id]);
        }
        foreach (['online_exam_answers', 'online_exam_proctoring_events', 'online_exam_user_notifications'] as $table) {
            $this->assertDatabaseMissing($table, ['submission_id' => $targetExam]);
            $this->assertDatabaseHas($table, ['submission_id' => $peerExam]);
        }
        foreach (['assignment_submission_items', 'assignment_question_responses'] as $table) {
            $this->assertDatabaseMissing($table, ['assignment_submission_id' => $targetAssignment]);
            $this->assertDatabaseHas($table, ['assignment_submission_id' => $peerAssignment]);
        }
        $this->assertDatabaseMissing('assignment_notifications', ['submission_id' => $targetAssignment]);
        $this->assertDatabaseHas('assignment_notifications', ['submission_id' => $peerAssignment]);
        $this->assertDatabaseHas('student_curriculum_assignments', ['student_id' => $peer->id, 'assigned_by' => null]);
        $this->assertDatabaseHas('programme_cohort_memberships', ['student_id' => $peer->id, 'assigned_by' => null]);
        $this->assertDatabaseHas('election_candidates', ['id' => $candidate, 'student_id' => null, 'manifesto' => null]);
        $this->assertDatabaseHas('election_votes', ['candidate_id' => $candidate, 'voter_id' => $peer->id]);
        $this->assertDatabaseMissing('election_votes', ['voter_id' => $student->id]);
        $this->assertDatabaseHas('programme_cohorts', ['name' => 'shared cohort', 'created_by' => null]);
        $this->assertDatabaseHas('curricula', ['name' => 'shared curriculum']);
        $this->assertDatabaseHas('subjects', ['name' => 'shared course']);
    }

    private function createFullDeletionGraphSchema(): void
    {
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('student_id');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('restrict');
        });
        foreach ([
            'graduation_applications', 'hostel_room_allocations', 'hostel_applications', 'hostel_fees',
            'club_members', 'appraisal_submits', 'feedback', 'student_requests',
            'student_fee_registration_waivers', 'student_financial_setup_reviews',
        ] as $name) {
            Schema::create($name, function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('student_id'); });
        }
        Schema::create('course_offering_attendance_records', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('course_registration_id');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('course_registration_id')->references('id')->on('course_registrations')->onDelete('restrict');
        });
        Schema::create('course_offering_lesson_progress', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('student_id');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('restrict');
        });
        Schema::create('online_exam_submissions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('student_id');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('restrict');
        });
        foreach (['online_exam_answers', 'online_exam_proctoring_events', 'online_exam_user_notifications'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id(); $table->unsignedBigInteger('submission_id');
                $table->foreign('submission_id')->references('id')->on('online_exam_submissions')->onDelete('restrict');
            });
        }
        Schema::create('assignment_submissions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('student_id');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('restrict');
        });
        foreach (['assignment_submission_items', 'assignment_question_responses'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id(); $table->unsignedBigInteger('assignment_submission_id');
                $table->foreign('assignment_submission_id')->references('id')->on('assignment_submissions')->onDelete('restrict');
            });
        }
        Schema::create('assignment_notifications', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('submission_id');
            $table->foreign('submission_id')->references('id')->on('assignment_submissions')->onDelete('restrict');
        });
        foreach (['student_curriculum_assignments', 'programme_cohort_memberships'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id(); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('assigned_by')->nullable();
                $table->foreign('student_id')->references('id')->on('users')->onDelete('restrict');
                $table->foreign('assigned_by')->references('id')->on('users')->onDelete('restrict');
            });
        }
        Schema::create('election_candidates', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('student_id')->nullable(); $table->text('manifesto')->nullable();
        });
        Schema::create('election_votes', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('candidate_id'); $table->unsignedBigInteger('voter_id');
            $table->foreign('candidate_id')->references('id')->on('election_candidates')->onDelete('restrict');
            $table->foreign('voter_id')->references('id')->on('users')->onDelete('restrict');
        });
        Schema::create('programme_cohorts', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
        });
        Schema::create('curricula', function (Blueprint $table): void { $table->id(); $table->string('name'); });
    }

    // ── A. Documents / password / status targeting (regression) ──────────────

    public function test_student_documents_password_and_status_stay_tenant_scoped(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $student = $this->user(7, $this->schoolA);
        $foreign = $this->user(7, $this->schoolB);

        $this->actingAs($admin)->post(route('admin.documents.upload', $student->id), ['file_name' => 'transcript', 'file' => UploadedFile::fake()->create('t.pdf', 10, 'application/pdf')]);
        $this->assertArrayHasKey('transcript', json_decode((string) $student->fresh()->documents, true) ?: []);
        $this->actingAs($admin)->post(route('admin.documents.upload', $foreign->id), ['file_name' => 'x', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertNotFound();
        $this->actingAs($admin)->get(route('admin.student.documents', $foreign->id))->assertNotFound();

        $hash = $foreign->password;
        $this->actingAs($admin)->post(route('admin.user_password'), ['user_id' => $foreign->id, 'password' => 'owned-123']);
        $this->actingAs($admin)->get(route('admin.student.reset_password', $foreign->id))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.student.resend_activation', $foreign->id))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.account_disable', $foreign->id));
        $this->assertSame($hash, $foreign->fresh()->password);
        $this->assertSame('active', $foreign->fresh()->account_status);

        $this->actingAs($admin)->get(route('admin.account_disable', $student->id));
        $this->assertSame('disable', $student->fresh()->account_status);
    }

    // ── A. Student / parent linking ──────────────────────────────────────────

    public function test_bulk_admission_only_links_same_school_parents(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $ownParent = $this->user(6, $this->schoolA);
        $foreignParent = $this->user(6, $this->schoolB);
        $sameSchoolTeacher = $this->user(3, $this->schoolA);

        $this->actingAs($admin)->post(route('admin.offline_admission.bulk_create'), [
            'class_id' => $this->classA, 'section_id' => 0, 'department_id' => 0,
            'name' => ['Own', 'Foreign', 'Teacher'],
            'email' => ['own.kid@example.test', 'foreign.kid@example.test', 'teacher.kid@example.test'],
            'password' => ['secret12', 'secret12', 'secret12'],
            'gender' => ['male', 'female', 'male'],
            'parent_id' => [$ownParent->id, $foreignParent->id, $sameSchoolTeacher->id],
        ]);

        $this->assertSame($ownParent->id, (int) User::where('email', 'own.kid@example.test')->value('parent_id'));
        $this->assertNull(User::where('email', 'foreign.kid@example.test')->value('parent_id'));
        $this->assertNull(User::where('email', 'teacher.kid@example.test')->value('parent_id'));
        $this->assertSame($this->schoolA, (int) User::where('email', 'foreign.kid@example.test')->value('school_id'));
    }

    // ── B. School Administrator survivability ────────────────────────────────

    public function test_an_admin_cannot_delete_disable_or_suspend_themselves(): void
    {
        $admin = $this->user(2, $this->schoolA, ['school_role' => 1]);
        $this->user(2, $this->schoolA); // another viable admin exists — self-protection is independent of that

        $this->actingAs($admin)->get(route('admin.admin.delete', $admin->id));
        $this->assertTrue(User::whereKey($admin->id)->exists(), 'self delete');

        $this->actingAs($admin)->get(route('admin.account_disable', $admin->id));
        $this->assertSame('active', $admin->fresh()->account_status, 'self disable');

        foreach (['suspended', 'inactive'] as $status) {
            $this->actingAs($admin)->post(route('admin.update', $admin->id), $this->adminPayload($admin, ['staff_status' => $status]));
            $this->assertNotSame($status, $admin->fresh()->staff_status, "self {$status}");
        }

        // Ordinary self-edits still work.
        $this->actingAs($admin)->post(route('admin.update', $admin->id), $this->adminPayload($admin, ['phone' => '4242']));
        $this->assertStringContainsString('4242', (string) $admin->fresh()->user_information);
    }

    public function test_the_last_viable_admin_cannot_be_removed(): void
    {
        // The only viable admin is the acting one; the other admin is already disabled.
        $admin = $this->user(2, $this->schoolA, ['school_role' => 1]);
        $this->user(2, $this->schoolA, ['account_status' => 'disable']);

        $this->actingAs($admin)->get(route('admin.admin.delete', $admin->id));
        $this->actingAs($admin)->get(route('admin.account_disable', $admin->id));
        $this->actingAs($admin)->post(route('admin.update', $admin->id), $this->adminPayload($admin, ['staff_status' => 'suspended']));

        $this->assertSame(1, User::where('school_id', $this->schoolA)->where('role_id', 2)->where('account_status', '!=', 'disable')
            ->where(fn ($q) => $q->whereNull('staff_status')->orWhereNotIn('staff_status', ['suspended', 'inactive']))->count());
    }

    public function test_a_non_primary_admin_cannot_remove_the_primary_admin(): void
    {
        $primary = $this->user(2, $this->schoolA, ['school_role' => 1]);
        $secondary = $this->user(2, $this->schoolA);

        $this->actingAs($secondary)->get(route('admin.account_disable', $primary->id));
        $this->assertSame('active', $primary->fresh()->account_status, 'disable primary');

        $this->actingAs($secondary)->post(route('admin.update', $primary->id), $this->adminPayload($primary, ['staff_status' => 'suspended']));
        $this->assertNotSame('suspended', $primary->fresh()->staff_status, 'suspend primary');

        $this->actingAs($secondary)->get(route('admin.admin.delete', $primary->id));
        $this->assertTrue(User::whereKey($primary->id)->exists(), 'delete primary');
    }

    public function test_legitimate_admin_management_between_several_active_admins_still_works(): void
    {
        $primary = $this->user(2, $this->schoolA, ['school_role' => 1]);
        $secondary = $this->user(2, $this->schoolA);
        $third = $this->user(2, $this->schoolA);
        $fourth = $this->user(2, $this->schoolA);

        // Primary manages another admin.
        $this->actingAs($primary)->post(route('admin.update', $secondary->id), $this->adminPayload($secondary, ['staff_status' => 'suspended']));
        $this->assertSame('suspended', $secondary->fresh()->staff_status);
        $this->actingAs($primary)->get(route('admin.account_disable', $third->id));
        $this->assertSame('disable', $third->fresh()->account_status);
        $this->actingAs($primary)->get(route('admin.account_enable', $third->id));
        $this->assertSame('enable', $third->fresh()->account_status);

        // A non-primary admin manages another non-primary admin.
        $this->actingAs($third)->get(route('admin.admin.delete', $fourth->id));
        $this->assertFalse(User::whereKey($fourth->id)->exists());
    }
}
