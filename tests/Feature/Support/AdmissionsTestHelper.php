<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait AdmissionsTestHelper
{
    use ActiveSchoolSubscriptionFixture;
    protected function bootAdmissionsTestSchema(?string $isolatedMysqlDatabase = null): void
    {
        if ($isolatedMysqlDatabase !== null) {
            $connection = config('database.connections.mysql');
            if (! preg_match('/\Apiie_module1_test_[a-f0-9]{16}\z/', $isolatedMysqlDatabase)
                || ! app()->environment('testing') || ($connection['host'] ?? null) !== '127.0.0.1'
                || (int) ($connection['port'] ?? 0) !== 3307) { throw new \RuntimeException('Unsafe test database connection refused.'); }
            Config::set('database.default', 'mysql');
            Config::set('database.connections.mysql.database', $isolatedMysqlDatabase);
            DB::purge('mysql'); DB::reconnect('mysql');
            $server = DB::selectOne('SELECT @@port AS p, @@datadir AS d');
            if ((int) $server->p !== 3307 || ! str_contains(strtolower(str_replace('\\', '/', $server->d)), '/piie-dev-db/')) {
                throw new \RuntimeException('Test server identity refused.');
            }
        } else {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->unsignedInteger('role_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('code')->nullable();
            $table->longText('user_information')->nullable();
            $table->longText('student_info')->nullable();
            $table->longText('documents')->nullable();
            $table->integer('status')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('designation')->nullable();
            $table->string('language')->nullable();
            $table->unsignedInteger('school_role')->nullable();
            $table->string('account_status')->default('active');
            $table->text('menu_permission')->nullable();
            $table->boolean('force_password_change')->default(false);
            $table->timestamps();
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedInteger('running_session')->nullable();
            $table->integer('status')->nullable();
            $table->timestamps();
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

        Schema::create('programmes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('code', 20)->nullable();
            $table->string('name');
            $table->string('level')->default('Degree');
            $table->string('duration')->nullable();
            $table->string('mode')->nullable();
            $table->decimal('tuition_fee', 15, 2)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            // Catalogue publication metadata (migration
            // 2026_10_04_000003). Part of this fixture rather than added by
            // each caller, because ProgrammeController writes these columns
            // unconditionally: a fixture without them would fail on every
            // create/update for a reason that has nothing to do with the test.
            $table->string('cover_image_path', 255)->nullable();
            $table->string('cover_image_name', 191)->nullable();
            $table->string('cover_image_mime', 100)->nullable();
            $table->unsignedBigInteger('cover_image_size')->nullable();
            $table->dateTime('cover_image_updated_at')->nullable();
            $table->string('tuition_currency', 10)->nullable();
            $table->string('tuition_fee_basis', 32)->nullable();
            $table->tinyInteger('is_published')->default(0);
            $table->integer('website_sort_order')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('website_item_id')->nullable();
        });

        Schema::create('intake_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('name', 100);
            $table->date('open_date')->nullable();
            $table->date('close_date')->nullable();
            $table->decimal('application_fee', 10, 2)->default(0);
            $table->tinyInteger('is_open')->default(1);
            $table->timestamps();
        });

        Schema::create('admissions', function (Blueprint $table) {
            $table->decimal('application_fee_amount', 12, 2)->nullable();
            $table->string('application_fee_currency', 10)->nullable();
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('applicant_id')->nullable()->index();
            $table->string('app_number', 40)->unique();
            $table->unsignedBigInteger('intake_session_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('second_choice_programme_id')->nullable();
            $table->string('study_mode', 30)->nullable();
            $table->string('how_did_you_hear', 100)->nullable();
            $table->string('title', 10)->nullable();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('email', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->date('dob')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->string('religion', 50)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('country_of_residence', 80)->nullable();
            $table->string('national_id_no', 50)->nullable();
            $table->string('passport_no', 50)->nullable();
            $table->text('physical_address')->nullable();
            $table->string('city', 80)->nullable();
            $table->boolean('has_disability')->default(0);
            $table->text('disability_details')->nullable();
            $table->string('nok_name', 150)->nullable();
            $table->string('nok_relationship', 60)->nullable();
            $table->string('nok_phone', 30)->nullable();
            $table->string('nok_email', 150)->nullable();
            $table->text('nok_address')->nullable();
            $table->string('sponsor_type', 30)->nullable();
            $table->string('sponsor_name', 150)->nullable();
            $table->string('sponsor_phone', 30)->nullable();
            $table->string('sponsor_email', 150)->nullable();
            $table->text('qualifications')->nullable();
            $table->json('documents')->nullable();
            $table->string('status')->default('submitted');
            $table->string('source')->default('staff_entry');
            $table->string('current_step', 40)->nullable();
            $table->json('completed_steps')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('declaration_accepted_at')->nullable();
            $table->string('fee_status', 20)->default('unpaid');
            $table->date('offer_date')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('notes')->nullable();
            $table->text('correction_note')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // ── Applicant portal tables ──────────────────────────────────────

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 150);
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_token', 64)->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedBigInteger('converted_user_id')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'email']);
        });

        Schema::create('applicant_password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('admission_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('key', 60);
            $table->string('label', 150);
            $table->string('description', 500)->nullable();
            $table->boolean('is_required')->default(1);
            $table->boolean('allow_multiple')->default(0);
            $table->json('applies_to_levels')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(1);
            $table->timestamps();
            $table->unique(['school_id', 'key']);
        });

        Schema::create('admission_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('admission_id')->index();
            $table->string('requirement_key', 60)->nullable();
            $table->string('label', 150)->nullable();
            $table->string('original_name', 255);
            $table->string('stored_name', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('uploaded_by_applicant_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('admission_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('admission_id')->index();
            $table->string('institution', 200);
            $table->string('award', 150)->nullable();
            $table->string('subject', 150)->nullable();
            $table->string('grade', 60)->nullable();
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->string('country', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('admission_status_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('admission_id')->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('title', 150);
            $table->text('note')->nullable();
            $table->string('actor_type', 20)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 150)->nullable();
            $table->boolean('is_visible_to_applicant')->default(1);
            $table->timestamps();
        });

        Schema::create('application_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('admission_id')->index();
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 10)->nullable();
            $table->string('method', 30)->default('offline');
            $table->string('status', 20)->default('pending');
            $table->string('reference', 191)->nullable();
            $table->string('gateway_txn_id', 191)->nullable();
            $table->json('gateway_payload')->nullable();
            $table->string('proof_file', 255)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('intake_session_id')->nullable();
            $table->unsignedTinyInteger('year_of_study')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('national_id_or_passport', 50)->nullable();
            $table->text('next_of_kin_address')->nullable();
            $table->string('next_of_kin_contact', 30)->nullable();
            $table->string('additional_image')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('exam_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->integer('timestamp')->nullable();
            $table->timestamps();
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('grade_point')->nullable();
            $table->decimal('gpa_points', 3, 2)->nullable();
            $table->string('classification', 50)->nullable();
            $table->integer('mark_from');
            $table->integer('mark_upto');
            $table->unsignedBigInteger('school_id')->index();
            $table->integer('total_marks')->nullable();
            $table->timestamps();
        });

        Schema::create('gradebooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_id')->default(0);
            $table->unsignedBigInteger('section_id')->default(0);
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('exam_category_id');
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('intake_session_id')->nullable();
            $table->text('marks')->nullable();
            $table->string('comment')->nullable();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->integer('timestamp')->nullable();
            $table->timestamps();
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('name', 150);
            $table->string('fee_type')->default('tuition');
            $table->decimal('amount', 15, 2);
            $table->tinyInteger('is_mandatory')->default(1);
            $table->tinyInteger('per_semester')->default(1);
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->timestamps();
        });

        Schema::create('student_fee_managers', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('total_amount');
            $table->decimal('amount', 10, 2)->nullable();
            $table->decimal('discounted_price', 10, 2)->nullable();
            $table->integer('class_id');
            // Added by 2026_07_29_070000_add_programme_id_to_student_fee_managers_table
            // and written by StudentFeeInvoiceGenerator; the helper had not
            // been updated to match, so every test that generated an HEI
            // invoice failed on a missing column rather than on its subject.
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('student_id');
            $table->unsignedBigInteger('fee_structure_id')->nullable()->index();
            $table->string('payment_method');
            $table->integer('paid_amount');
            $table->string('status');
            $table->integer('school_id');
            $table->integer('session_id')->nullable();
            $table->integer('timestamp')->nullable();
            // Added by 2026_09_14_180000_add_gateway_tracking_to_fee_tables
            $table->string('gateway_reference', 191)->nullable()->index();
            $table->json('gateway_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('question');
            $table->string('type')->default('mcq');
            $table->text('option_a')->nullable();
            $table->text('option_b')->nullable();
            $table->text('option_c')->nullable();
            $table->text('option_d')->nullable();
            $table->string('correct_ans', 5)->nullable();
            $table->tinyInteger('marks')->default(1);
            $table->string('difficulty')->default('medium');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('payment_keys')->nullable();
            $table->string('image')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->string('mode')->nullable();
            $table->string('currency')->nullable();
            $table->string('currency_position')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('global_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
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

        Schema::create('language', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phrase');
            $table->text('translated')->nullable();
        });

        // Legacy messaging tables queried unconditionally by admin/navigation.blade.php
        // (the shared layout every full admin page renders through) — not
        // created by any Laravel migration in this repo (pre-dates the
        // migration-based tables), so the minimal columns the layout reads
        // are recreated here rather than left missing.
        Schema::create('message_thrades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('reciver_id')->nullable();
            $table->timestamps();
        });

        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_thrade')->nullable();
            $table->unsignedBigInteger('reciver_id')->nullable();
            $table->tinyInteger('read_status')->default(0);
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_title')->nullable();
            $table->integer('status')->nullable();
            $table->integer('school_id')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('school_id');
            $table->timestamps();
        });

        // Queried by CommonController::get_student_details_by_id() — the
        // canonical class-based student lookup Attendance/Timetable/
        // Subjects/Marks all read through. Shape matches the real
        // 2022_05_16_051816_create_roles_table migration (id('role_id') as
        // the primary key), plus a school_id column several tests seed
        // defensively even though the real table doesn't have one — kept
        // here so those inserts don't need to change.
        Schema::create('roles', function (Blueprint $table) {
            $table->id('role_id');
            $table->string('name');
            $table->unsignedBigInteger('school_id')->default(0);
            $table->timestamps();
        });

        // School-scoped tables the (school) Admin dashboard reads from —
        // see AdminController::schoolDashboardData().
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('school_id');
            $table->timestamps();
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('unique_identifier')->unique();
            $table->string('status')->default('0');
            $table->timestamps();
        });

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

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('class_id');
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->unsignedTinyInteger('credits')->nullable();
            $table->string('course_type', 20)->nullable();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedTinyInteger('pass_mark')->default(50);
            $table->timestamps();
        });

        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('section_id');
            $table->unsignedBigInteger('subject_id');
            $table->integer('starting_hour');
            $table->integer('ending_hour');
            $table->integer('starting_minute');
            $table->integer('ending_minute');
            $table->string('day');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('school_id');
            $table->timestamps();
        });

        Schema::create('daily_attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_id')->index();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('student_id')->index();
            $table->integer('status');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('school_id')->index();
            $table->integer('timestamp')->nullable();
            $table->timestamps();
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('author');
            $table->integer('copies');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('session_id');
            $table->integer('timestamp');
            $table->timestamps();
        });

        Schema::create('book_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('book_id');
            $table->unsignedBigInteger('class_id');
            $table->unsignedBigInteger('student_id');
            $table->string('issue_date');
            $table->integer('status');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('session_id');
            $table->integer('timestamp');
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

        // Queried unconditionally by the online_exam.notifications partial
        // every admin/student/teacher navigation layout now @includes (see
        // the online-exams governed-workflow merge) — without this, any
        // test that renders one of those layouts 500s on a missing table.
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
            $table->unique(['school_id', 'user_id', 'event_key'], 'exam_notification_event_unique');
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

        Schema::create('frontend_events', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->integer('timestamp')->nullable();
            $table->integer('status')->default(1);
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('academic_calendar', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('title');
            $table->string('event_type')->default('other');
            $table->date('event_date');
            $table->date('end_date')->nullable();
            $table->string('color', 10)->default('#1a3a6b');
            $table->text('description')->nullable();
            $table->tinyInteger('is_public')->default(1);
            $table->timestamps();
        });

        // Platform-wide tables — only superadmin.dashboard reads these.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->integer('package_id');
            $table->integer('school_id');
            $table->float('paid_amount');
            $table->string('payment_method')->nullable();
            $table->longText('transaction_keys')->nullable();
            $table->integer('expire_date')->nullable();
            $table->integer('date_added')->nullable();
            $table->integer('active')->default(0);
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->float('price')->default(0);
            $table->string('package_type')->nullable();
            $table->string('interval')->nullable();
            $table->integer('days')->nullable();
            $table->integer('status')->default(1);
            $table->string('description')->nullable();
            $table->text('features')->default('[]');
            $table->timestamps();
        });
    }

    protected function makeSuperAdmin(int $schoolId): User
    {
        return User::factory()->create([
            'role_id' => 1,
            'school_id' => $schoolId,
            'account_status' => 'active',
        ]);
    }

    protected function makeSchool(array $overrides = []): int
    {
        $school = (int) DB::table('schools')->insertGetId(array_merge([
            'title' => 'Test School',
            'running_session' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
        $this->grantActiveFixtureSubscription($school);
        return $school;
    }

    protected function makeAdminUser(int $schoolId): User
    {
        return User::factory()->create([
            'role_id' => 2,
            'school_id' => $schoolId,
            'account_status' => 'active',
        ]);
    }

    protected function makeProgramme(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('programmes')->insertGetId(array_merge([
            'school_id' => $schoolId,
            'code' => 'PRG',
            'name' => 'Test Programme',
            'level' => 'Degree',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeIntakeSession(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('intake_sessions')->insertGetId(array_merge([
            'school_id' => $schoolId,
            'name' => 'January Intake',
            'is_open' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeClass(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('classes')->insertGetId(array_merge([
            'name' => 'Class A',
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeDepartment(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('departments')->insertGetId(array_merge([
            'school_id'  => $schoolId,
            'name'       => 'Test Department',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeSection(int $classId, array $overrides = []): int
    {
        return (int) DB::table('sections')->insertGetId(array_merge([
            'class_id'   => $classId,
            'name'       => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeExamCategory(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('exam_categories')->insertGetId(array_merge([
            'name' => 'Final Exam',
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeGrade(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('grades')->insertGetId(array_merge([
            'name' => 'A',
            'grade_point' => '5.0',
            'gpa_points' => 5.0,
            'classification' => 'Distinction',
            'mark_from' => 80,
            'mark_upto' => 100,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeGradebook(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('gradebooks')->insertGetId(array_merge([
            'class_id' => 0,
            'section_id' => 0,
            'student_id' => 0,
            'exam_category_id' => 0,
            'marks' => json_encode([]),
            'comment' => '',
            'school_id' => $schoolId,
            'session_id' => 1,
            'timestamp' => time(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeAcademicSession(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('sessions')->insertGetId(array_merge([
            'school_id'     => $schoolId,
            'session_title' => '2026/2027',
            'status'        => 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $overrides));
    }

    protected function makeFeeStructure(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('fee_structures')->insertGetId(array_merge([
            'school_id' => $schoolId,
            'name' => 'Tuition Fee',
            'fee_type' => 'tuition',
            'amount' => 1000,
            'is_mandatory' => 1,
            'per_semester' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * Portal-activation email sending is gated on DB-stored settings
     * (get_settings('smtp_*')), not .env — seed them so tests can assert
     * the activation email guard actually lets a send through.
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

    protected function makeAdmission(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('admissions')->insertGetId(array_merge([
            'school_id' => $schoolId,
            'app_number' => 'APP-' . strtoupper(uniqid()),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe.' . uniqid() . '@example.com',
            'phone' => '0700000000',
            'status' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * An applicant-portal account. Password defaults to something valid under
     * the portal's 8-character minimum so tests can sign in without restating
     * it every time.
     */
    protected function makeApplicant(int $schoolId, array $overrides = []): \App\Models\Applicant
    {
        return \App\Models\Applicant::create(array_merge([
            'school_id'  => $schoolId,
            'first_name' => 'Alice',
            'last_name'  => 'Applicant',
            'email'      => 'alice.' . uniqid() . '@example.com',
            'phone'      => '0700111222',
            'password'   => \Illuminate\Support\Facades\Hash::make('secret-password'),
            'is_active'  => 1,
        ], $overrides));
    }

    /**
     * Fills in every answer the wizard requires, so a test about submission
     * doesn't have to restate the whole form. Documents and fee are handled
     * separately because those are what most of these tests are about.
     */
    protected function completeApplicationFields(\App\Models\Admission $admission, array $overrides = []): \App\Models\Admission
    {
        $admission->update(array_merge([
            'dob'              => '2000-01-15',
            'gender'           => 'Female',
            'nationality'      => 'Ugandan',
            'physical_address' => 'Plot 1, Kampala',
            'nok_name'         => 'Mary Doe',
            'nok_relationship' => 'Mother',
            'nok_phone'        => '0700333444',
            'qualifications'   => 'UACE 2018',
        ], $overrides));

        return $admission->fresh();
    }

    /** Valid submitted fixture for decision/conversion tests, including real document requirements. */
    protected function completeAdmissionForDecision(\App\Models\Admission $admission): \App\Models\Admission
    {
        $existing = array_filter(array_intersect_key($admission->getAttributes(), array_flip([
            'dob', 'gender', 'nationality', 'physical_address', 'nok_name', 'nok_relationship', 'nok_phone', 'qualifications',
        ])), fn ($value) => filled($value));
        $admission = $this->completeApplicationFields($admission, array_merge([
            'submitted_at' => $admission->submitted_at ?: now(),
            'programme_id' => $admission->programme_id ?: $this->makeProgramme($admission->school_id),
            'intake_session_id' => $admission->intake_session_id ?: $this->makeIntakeSession($admission->school_id, ['application_fee' => 0]),
        ], $existing));
        foreach (\App\Support\Admissions\ApplicationDocuments::requirementsFor($admission) as $requirement) {
            if (! $requirement->is_required) { continue; }
            DB::table('admission_documents')->insert([
                'school_id' => $admission->school_id, 'admission_id' => $admission->id,
                'requirement_key' => $requirement->key, 'original_name' => 'isolated-fixture.pdf',
                'stored_name' => 'isolated-fixture.pdf', 'status' => 'verified', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $admission->fresh();
    }
}
