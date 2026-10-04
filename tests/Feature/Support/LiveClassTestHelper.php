<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait LiveClassTestHelper
{
    protected function bootLiveClassTestSchema(): void
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
            $table->string('staff_status')->nullable();
            $table->longText('menu_permission')->nullable();
            $table->boolean('force_password_change')->default(false);
            $table->timestamps();
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedInteger('running_session')->nullable();
            $table->timestamps();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('school_id');
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_title')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->unsignedBigInteger('school_id')->nullable();
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
            $table->string('name');
            $table->string('level')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('teacher_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teacher_programme_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->timestamps();
        });

        // Matches the combined shape of both live_classes migrations.
        Schema::create('live_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('academic_session_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('platform')->default('jitsi');
            $table->string('meeting_url', 500)->nullable();
            $table->string('meeting_id', 150)->nullable();
            $table->string('meeting_password', 150)->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->date('start_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('status')->default('draft');
            $table->boolean('is_published')->default(false);
            $table->boolean('attendance_enabled')->default(false);
            $table->string('recording_url', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('started_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->unsignedBigInteger('started_by')->nullable();
                $table->unsignedBigInteger('ended_by')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('recording_status', 20)->default('none');
$table->timestamps();
        });

        Schema::create('live_class_attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('live_class_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedTinyInteger('role_id')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
        });

        Schema::create('live_class_materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('live_class_id')->index();
            $table->string('type', 10)->default('file');
            $table->string('category', 20)->default('resource');
            $table->string('title', 200);
            $table->string('original_name', 255)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('live_class_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('live_class_id')->index();
            $table->string('type', 30);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['live_class_id', 'type']);
        });

        Schema::create('live_class_meet_guests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('email', 191);
            $table->string('label', 191)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'email']);
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
        // every admin/student/teacher navigation layout now @includes.
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

        Schema::create('language', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phrase');
            $table->text('translated')->nullable();
        });

        // Legacy messaging tables the admin/teacher/student layouts read
        // unconditionally — see AdmissionsTestHelper for the same note.
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
    }

    protected function makeSchool(array $overrides = []): int
    {
        return (int) DB::table('schools')->insertGetId(array_merge([
            'title' => 'Test School',
            'running_session' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeStaffUser(int $schoolId, int $roleId = 2, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $roleId,
            'school_id' => $schoolId,
            'account_status' => 'active',
        ], $overrides));
    }

    protected function makeStudentUser(int $schoolId, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => 7,
            'school_id' => $schoolId,
            'account_status' => 'active',
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

    protected function enroll(int $userId, int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('enrollment')->insertGetId(array_merge([
            'user_id' => $userId,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function makeLiveClass(int $schoolId, array $overrides = []): \App\Models\LiveClass
    {
        return \App\Models\LiveClass::create(array_merge([
            'school_id' => $schoolId,
            'title' => 'Test Class',
            'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/test-room-' . uniqid(),
            'start_date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'timezone' => 'UTC',
            'scheduled_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(10, 0),
            'status' => 'scheduled',
            'is_published' => 1,
            'attendance_enabled' => 1,
        ], $overrides));
    }

    protected function enableSmtpSettings(): void
    {
        foreach ([
            'smtp_user' => 'noreply@example.test',
            'smtp_pass' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => '587',
            'system_title' => 'Test School',
        ] as $key => $value) {
            DB::table('global_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }
    }
}
