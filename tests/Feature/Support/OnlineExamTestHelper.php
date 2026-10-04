<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

trait OnlineExamTestHelper
{
    protected function bootOnlineExamTestSchema(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->unsignedInteger('role_id')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('language')->nullable();
            $table->string('account_status')->default('active');
            $table->text('menu_permission')->nullable();
            $table->timestamps();
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('school_type')->nullable();
            $table->string('education_level')->nullable();
            $table->timestamps();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_title')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('name')->nullable();
        });

        Schema::create('programmes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->string('status')->default('active');
        });

        Schema::create('teacher_programme_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('programme_id');
            $table->unsignedBigInteger('school_id');
            $table->tinyInteger('marks')->default(0);
            $table->tinyInteger('attendance')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('enrollment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teacher_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->tinyInteger('marks')->default(0);
            $table->tinyInteger('attendance')->default(0);
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('message_thrades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('reciver_id')->nullable();
            $table->timestamps();
        });

        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_thrade')->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('reciver_id')->nullable();
            $table->tinyInteger('read_status')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('online_exams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('title');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable()->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            // The Course Offering delivery context, mirroring the additive migration
            // 2026_09_30_140000. Nullable, so every legacy Class/Section exam above
            // and every existing test in this suite keeps meaning exactly what it
            // meant - and the engine's own null-guards in CourseOfferingExamAccess
            // are exercised by virtue of this being NULL for all of them.
            $table->unsignedBigInteger('course_offering_id')->nullable()->index();
            $table->string('exam_type')->default('cat');
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('end_datetime')->nullable();
            $table->integer('duration_mins')->default(60);
            $table->integer('total_marks')->default(100);
            $table->integer('pass_mark')->default(50);
            $table->unsignedTinyInteger('max_attempts')->default(1);
            $table->text('instructions')->nullable();
            $table->tinyInteger('is_published')->default(0);
            $table->tinyInteger('auto_submit')->default(1);
            $table->string('workflow_state', 30)->default('draft')->index();
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->boolean('allow_previous_navigation')->default(true);
            $table->string('result_release_policy', 30)->default('immediate');
            $table->boolean('webcam_required')->default(false);
            $table->boolean('fullscreen_required')->default(false);
            // Added by 2026_10_02_000002. NULL is the default and means "full
            // restrictions", so every exam in a test schema behaves as before.
            $table->string('integrity_accommodation', 32)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->unsignedBigInteger('updater_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('online_exam_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('online_exam_id')->index();
            $table->unsignedBigInteger('question_bank_id')->nullable();
            $table->text('question')->nullable();
            $table->string('type')->default('mcq');
            $table->text('option_a')->nullable();
            $table->text('option_b')->nullable();
            $table->text('option_c')->nullable();
            $table->text('option_d')->nullable();
            $table->string('correct_ans', 255)->nullable();
            $table->unsignedTinyInteger('question_schema_version')->nullable();
            $table->text('question_config')->nullable();
            $table->text('marking_config')->nullable();
            $table->tinyInteger('marks')->default(1);
            $table->integer('sort_order')->default(0);
        });

        Schema::create('online_exam_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('online_exam_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->json('answers')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->string('submitted_via', 20)->nullable();
            $table->string('status', 40)->default('in_progress');
            $table->string('result_review_state', 30)->nullable();

            /**
             * PUBLICATION AUDIT, MIRRORING THE REAL MIGRATION.
             *
             * This hand-built schema has to carry every column the production table has,
             * or a test proves nothing about the real thing: with these absent, the
             * publish action raised "no such column: published_at" instead of exercising
             * the write it was written to verify.
             */
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('timeout_at')->nullable();
            $table->integer('total_marks_snapshot')->nullable();
            $table->decimal('objective_score', 8, 2)->nullable();
            $table->decimal('manual_score', 8, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->dateTime('camera_consent_at')->nullable();
            $table->boolean('camera_permission_granted')->default(false);
            $table->dateTime('camera_ready_at')->nullable();
            $table->dateTime('fullscreen_started_at')->nullable();
            $table->string('browser_session_token', 80)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('online_exam_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('submission_id')->index();
            $table->unsignedBigInteger('question_id')->index();
            $table->string('selected_option', 10)->nullable();
            $table->text('answer_text')->nullable();
            $table->unsignedTinyInteger('answer_schema_version')->nullable();
            $table->text('answer_payload')->nullable();
            $table->decimal('awarded_marks', 8, 2)->nullable();
            $table->boolean('is_correct')->nullable();
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->dateTime('marked_at')->nullable();
            $table->text('teacher_comment')->nullable();
            $table->timestamps();
            $table->unique(['submission_id', 'question_id']);
        });

        (require database_path('migrations/2026_09_19_000004_add_answer_revision_to_online_exam_answers.php'))->up();

        Schema::create('online_exam_proctoring_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('submission_id')->index();
            $table->string('event_type', 50);
            $table->dateTime('event_time')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('review_status', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable()->index();
            $table->unsignedBigInteger('session_id')->nullable()->index();
            $table->unsignedBigInteger('topic_id')->nullable()->index();
            $table->unsignedBigInteger('subtopic_id')->nullable()->index();
            $table->text('question');
            $table->string('type', 30)->default('mcq');
            $table->text('option_a')->nullable();
            $table->text('option_b')->nullable();
            $table->text('option_c')->nullable();
            $table->text('option_d')->nullable();
            $table->string('correct_ans', 255)->nullable();
            $table->unsignedTinyInteger('question_schema_version')->nullable();
            $table->text('question_config')->nullable();
            $table->text('marking_config')->nullable();
            $table->integer('marks')->default(1);
            $table->string('difficulty', 20)->default('easy');
            $table->string('topic', 150)->nullable();
            $table->string('subtopic', 150)->nullable();
            $table->string('bloom_level', 40)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('question_topics', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('school_id')->index(); $table->unsignedBigInteger('subject_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index(); $table->string('name', 150); $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
        });
        Schema::create('question_tags', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('school_id')->index(); $table->string('name', 100); $table->string('normalized_name', 100);
            $table->boolean('is_active')->default(true); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamps();
            $table->unique(['school_id', 'normalized_name']);
        });
        Schema::create('question_bank_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('question_bank_id'); $table->unsignedBigInteger('question_tag_id');
            $table->unique(['question_bank_id', 'question_tag_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->unsignedTinyInteger('role_id')->nullable();
            $table->string('role_name', 60)->nullable();
            $table->string('action');
            $table->string('event_type', 20)->default('ACTION');
            $table->string('module');
            $table->string('route_name', 150)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->text('description');
            $table->string('record_type', 100)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('platform', 60)->nullable();
            $table->string('status', 20)->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('global_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('language', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phrase');
            $table->text('translated')->nullable();
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('unique_identifier')->unique();
            $table->string('status')->default('0');
            $table->timestamps();
        });

        // Queried unconditionally by student/navigation.blade.php's hostel
        // sidebar item — needed by any test here that renders that layout.
        Schema::create('hostel_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('hostel_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->text('note')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamps();
        });

        Schema::create('hostel_fees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('hostel_id')->nullable();
            $table->unsignedBigInteger('room_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('title')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->date('fee_payment_date')->nullable();
            $table->dateTime('payment_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('gateway_reference', 191)->nullable();
            $table->text('gateway_payload')->nullable();
            $table->unsignedInteger('status')->default(0);
            $table->timestamps();
        });

        Schema::create('noticeboard', function (Blueprint $table) {
            $table->id();
            $table->longText('notice_title');
            $table->longText('notice');
            $table->string('start_date');
            $table->string('start_time');
            $table->string('end_date');
            $table->string('end_time');
            $table->integer('status');
            $table->integer('show_on_website');
            $table->string('image')->nullable();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('session_id');
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('type', 40)->default('general');
            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('online_exam_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('online_exam_id')->index();
            $table->string('type', 30);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['online_exam_id', 'type']);
        });

        Schema::create('online_exam_user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('online_exam_id')->nullable()->index();
            $table->unsignedBigInteger('submission_id')->nullable()->index();
            $table->string('type', 60);
            $table->string('title', 190);
            $table->text('message');
            $table->string('action_url', 500)->nullable();
            $table->dateTime('read_at')->nullable();
            $table->string('event_key', 190)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'event_key']);
        });

        DB::table('global_settings')->insert([
            ['key' => 'role_perm_2', 'value' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'role_perm_4', 'value' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'role_perm_7', 'value' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'role_perm_19', 'value' => json_encode([]), 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('language')->insert([
            'name' => null,
            'phrase' => 'Exam Result',
            'translated' => 'Exam Result',
        ]);

        DB::table('addons')->insert([
            'unique_identifier' => 'transport',
            'status' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('schools')->insert([
            'id' => 1,
            'title' => 'Test School',
            'school_type' => 'higher_ed',
            'education_level' => 'tertiary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  string  $status  The ACCOUNT status (`active`, `disabled`, …).
     * @param  string|null  $name  A real name.
     *
     * The name is a parameter because a tenant-isolation test that asserts on
     * display strings is not testing tenants. Two people can share a name, and a
     * fixture that auto-generated one would produce exactly that collision - which
     * is how a legitimate colleague in the home institution once got reported as a
     * cross-tenant leak.
     */
    protected function makeUser(int $roleId, int $schoolId, string $status = 'active', ?string $name = null): User
    {
        $user = User::factory()->create([
            'role_id' => $roleId,
            'school_id' => $schoolId,
            'account_status' => $status,
            'menu_permission' => null,
        ]);

        if ($name !== null) {
            $user->forceFill(['name' => $name])->save();
        }

        return $user;
    }

    protected function makeClass(int $schoolId): int
    {
        return (int) DB::table('classes')->insertGetId([
            'school_id' => $schoolId,
            'name' => 'Class ' . $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeSubject(int $schoolId, ?int $classId = null): int
    {
        return (int) DB::table('subjects')->insertGetId([
            'school_id' => $schoolId,
            'class_id' => $classId,
            'name' => 'Subject ' . $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function enrollStudent(int $userId, int $schoolId, int $classId): void
    {
        DB::table('enrollment')->insert([
            'user_id' => $userId,
            'class_id' => $classId,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeExam(array $overrides = []): int
    {
        $defaults = [
            'school_id' => 1,
            'title' => 'Exam',
            'subject_id' => null,
            'class_id' => null,
            'exam_type' => 'quiz',
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHour(),
            'duration_mins' => 30,
            'total_marks' => 10,
            'pass_mark' => 5,
            'max_attempts' => 1,
            'instructions' => 'Read carefully',
            'is_published' => 1,
            'workflow_state' => 'published',
            'result_release_policy' => 'immediate',
            'auto_submit' => 1,
            'created_by' => null,
            'creator_id' => null,
            'updater_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return (int) DB::table('online_exams')->insertGetId(array_merge($defaults, $overrides));
    }

    protected function makeQuestion(int $examId, array $overrides = []): int
    {
        $defaults = [
            'online_exam_id' => $examId,
            'question' => 'Question text',
            'type' => 'mcq',
            'option_a' => 'A',
            'option_b' => 'B',
            'option_c' => 'C',
            'option_d' => 'D',
            'correct_ans' => 'A',
            'marks' => 5,
            'sort_order' => 1,
        ];

        return (int) DB::table('online_exam_questions')->insertGetId(array_merge($defaults, $overrides));
    }

    protected function makeSubmission(array $overrides = []): int
    {
        $defaults = [
            'online_exam_id' => 1,
            'student_id' => 1,
            'school_id' => 1,
            'attempt_no' => 1,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(20),
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return (int) DB::table('online_exam_submissions')->insertGetId(array_merge($defaults, $overrides));
    }

    /**
     * A REFUSED HANDOVER IS EXPLAINED, NOT A 422 ERROR PAGE.
     *
     * ── WHY THIS HELPER EXISTS ──────────────────────────────────────────────
     *
     * Both finalize endpoints — the lecturer's handover and the administrator's — used
     * to let `finalizeSubmission()`'s 422 reach the browser as a bare
     * `422 Unprocessable Content` page. Eight tests asserted that status directly, and
     * the brief records it as a defect: an ordinary workflow rejection must return to
     * the relevant screen with an actionable message, because a lecturer who has
     * finished their marking cannot tell "one question is undecided" from "this
     * endpoint is broken".
     *
     * So the contract is asserted in ONE place and it is STRICTER than the status
     * code it replaces:
     *
     *   - a redirect back to a screen, not an error document;
     *   - an error against `result`, i.e. a named field the views already render;
     *   - a non-empty explanation the lecturer can act on;
     *   - and, where the caller cares, that nothing was written.
     *
     * A test that only checked `assertStatus(422)` would have passed while the
     * message was missing. These four would not.
     */
    protected function assertHandoverRefusedWithExplanation($response, ?string $expectedFragment = null): void
    {
        $response->assertRedirect();
        $response->assertSessionHasErrors('result');

        $message = (string) session('errors')->first('result');

        $this->assertNotSame('', trim($message),
            'a refused handover must explain itself, not return a bare rejection');

        if ($expectedFragment !== null) {
            $this->assertStringContainsString($expectedFragment, $message);
        }
    }

    /**
     * Notifier sends are gated on DB-stored settings (get_settings('smtp_*')),
     * not .env — seed them so reminder-related tests can assert the send
     * actually goes through instead of being silently skipped.
     */
    protected function enableSmtpSettings(): void
    {
        foreach ([
            'smtp_user'    => 'noreply@example.test',
            'smtp_pass'    => 'secret',
            'smtp_host'    => 'smtp.example.test',
            'smtp_port'    => '587',
            'system_title' => 'Test School',
        ] as $key => $value) {
            DB::table('global_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }
    }
}
