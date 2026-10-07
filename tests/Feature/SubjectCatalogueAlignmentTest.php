<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CurriculumController;
use App\Models\Programme;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubjectCatalogueAlignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
        $this->school(1, 'higher_ed', 'tertiary');
        $this->school(2, 'k12', 'secondary');
        $this->school(3, 'mixed', 'mixed');
        DB::table('users')->insert([
            ['id' => 1, 'school_id' => 1, 'language' => 'english'],
            ['id' => 2, 'school_id' => 2, 'language' => 'english'],
            ['id' => 3, 'school_id' => 3, 'language' => 'english'],
        ]);
        DB::table('programmes')->insert([
            ['id' => 10, 'school_id' => 1, 'code' => 'BBIT', 'name' => 'Bachelor of Business IT', 'is_active' => 1],
            ['id' => 20, 'school_id' => 2, 'code' => 'FOREIGN', 'name' => 'Foreign Programme', 'is_active' => 1],
            ['id' => 30, 'school_id' => 3, 'code' => 'MIXED', 'name' => 'Mixed Institution Programme', 'is_active' => 1],
        ]);
        DB::table('classes')->insert([
            ['id' => 30, 'school_id' => 2, 'name' => 'Year 7'],
            ['id' => 31, 'school_id' => 1, 'name' => 'Legacy class'],
            ['id' => 32, 'school_id' => 3, 'name' => 'Mixed Institution Class'],
        ]);
        DB::table('sessions')->insert(['id' => 40, 'school_id' => 2]);
        DB::table('schools')->where('id', 2)->update(['running_session' => 40]);
    }

    public function test_school_classification_does_not_survive_a_replaced_database(): void
    {
        // Reproduce an earlier test rendering navigation with school 1 as K-12.
        DB::table('schools')->where('id', 1)->update(['education_level' => 'secondary', 'school_type' => 'k12']);
        $this->assertSame('Subjects', academic_term('subjects', 1));

        // A later test/application sees the same ID in a fresh tenant database.
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->createSchema();
        $this->school(1, 'higher_ed', 'tertiary');
        $this->assertSame('tertiary', academic_education_level(1));
        $this->assertSame('Course Units', academic_term('subjects', 1));
    }

    public function test_school_classification_reflects_current_explicit_level_and_legacy_fallback(): void
    {
        $this->assertSame('tertiary', academic_education_level(1));
        DB::table('schools')->where('id', 1)->update(['education_level' => 'vocational']);
        $this->assertSame('Instructor', academic_term('teacher', 1));
        DB::table('schools')->where('id', 1)->update(['education_level' => null]);
        $this->assertSame('tertiary', academic_education_level(1));
        DB::table('schools')->where('id', 1)->update(['school_type' => 'k12']);
        $this->assertSame('Subjects', academic_term('subjects', 1));
    }

    public function test_higher_education_catalogue_uses_course_unit_terms_and_tenant_programme_filter(): void
    {
        $this->assertSame('Course Units', academic_term('subjects', 1));
        $this->assertSame('Class', academic_term('class', 1));

        $subject = Subject::create(['name' => 'Business Systems', 'code' => 'BBIT1101', 'school_id' => 1, 'programme_id' => 10]);
        Subject::create(['name' => 'Other Tenant Unit', 'code' => 'OTHER1', 'school_id' => 2, 'class_id' => 30, 'session_id' => 40]);

        $request = $this->authenticatedRequest(1, '/admin/subject?search=BBIT&programme_id=10');
        $view = app(AdminController::class)->subjectList($request);

        $this->assertSame('admin.subject.subject_list', $view->name());
        $this->assertTrue($view->getData()['isHigherEducation']);
        $this->assertSame(['Business Systems'], $view->getData()['subjects']->getCollection()->pluck('name')->all());

        DB::table('curricula')->insert(['id' => 500, 'school_id' => 1, 'programme_id' => 10]);
        $searchRequest = $this->authenticatedRequest(1, '/admin/curricula/500/subjects/search?search=BBIT');
        $searchResponse = app(CurriculumController::class)->searchSubjects($searchRequest, 500);
        $this->assertSame([$subject->id], collect($searchResponse->getData(true)['data'])->pluck('id')->all());

        $create = app(AdminController::class)->createSubject();
        $this->assertSame('admin.subject.add_subject', $create->name());
        $this->assertTrue($create->getData()['isHigherEducation']);
        $this->assertSame(['BBIT'], $create->getData()['programmes']->pluck('code')->all());
    }

    public function test_higher_education_creation_requires_tenant_programme_name_and_unique_code(): void
    {
        $controller = app(AdminController::class);
        $request = $this->authenticatedRequest(1, '/admin/subject', [
            'name' => 'Business Systems', 'code' => 'BBIT1101', 'programme_id' => 10,
        ], 'POST');
        $response = $controller->subjectCreate($request);
        $this->assertSame(1, Subject::where('school_id', 1)->where('programme_id', 10)->count());
        $this->assertSame(0, DB::table('curriculum_memberships')->count());
        $this->assertStringEndsWith('/admin/subject', $response->getTargetUrl());

        try {
            $controller->subjectCreate($this->authenticatedRequest(1, '/admin/subject', [
                'name' => 'Duplicate', 'code' => 'BBIT1101', 'programme_id' => 10,
            ], 'POST'));
            $this->fail('Duplicate tenant Course Unit code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        foreach ([['code' => 'BBIT1102', 'programme_id' => 10], ['name' => 'Missing code', 'programme_id' => 10]] as $invalid) {
            try {
                $controller->subjectCreate($this->authenticatedRequest(1, '/admin/subject', $invalid, 'POST'));
                $this->fail('Missing Course Unit name/code was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        try {
            $controller->subjectCreate($this->authenticatedRequest(1, '/admin/subject', [
                'name' => 'Foreign programme', 'code' => 'BBIT1103', 'programme_id' => 20,
            ], 'POST'));
            $this->fail('Cross-tenant Programme association was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('programme_id', $exception->errors());
        }

        try {
            $controller->subjectCreate($this->authenticatedRequest(1, '/admin/subject', [
                'name' => 'Unassociated Course Unit', 'code' => 'BBIT1104',
            ], 'POST'));
            $this->fail('Higher-education Course Unit without a Programme was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('programme_id', $exception->errors());
        }

        try {
            $controller->subjectCreate($this->authenticatedRequest(1, '/admin/subject', [
                'name' => 'Foreign Class', 'code' => 'BBIT1105', 'programme_id' => 10, 'class_id' => 30,
            ], 'POST'));
            $this->fail('Cross-tenant Class association was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('class_id', $exception->errors());
        }

    }

    public function test_k12_subject_creation_keeps_class_association_and_mixed_terms_do_not_say_cohort(): void
    {
        $this->assertSame('Subjects', academic_term('subjects', 2));
        $this->assertSame('Class', academic_term('class', 2));
        $this->assertSame('Classes', academic_term('classes', 3));

        app(AdminController::class)->subjectCreate($this->authenticatedRequest(2, '/admin/subject', [
            'name' => 'Mathematics', 'code' => 'MATH7', 'class_id' => 30,
        ], 'POST'));

        $subject = Subject::where('school_id', 2)->firstOrFail();
        $this->assertSame(30, (int) $subject->class_id);
        $this->assertNull($subject->programme_id);

        try {
            app(AdminController::class)->subjectCreate($this->authenticatedRequest(2, '/admin/subject', [
                'name' => 'Unassociated Subject', 'code' => 'MATH8',
            ], 'POST'));
            $this->fail('K-12 Subject without a Class was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('class_id', $exception->errors());
        }

        try {
            app(AdminController::class)->subjectCreate($this->authenticatedRequest(3, '/admin/subject', [
                'name' => 'Ambiguous Association', 'code' => 'MIXED101', 'class_id' => 32, 'programme_id' => 30,
            ], 'POST'));
            $this->fail('Mixed institution accepted both Class and Programme associations.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('class_id', $exception->errors());
        }
    }

    private function authenticatedRequest(int $userId, string $uri, array $data = [], string $method = 'GET'): Request
    {
        $user = User::findOrFail($userId);
        Auth::setUser($user);
        $request = Request::create($uri, $method, $data);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function school(int $id, string $type, string $level): void
    {
        DB::table('schools')->insert([
            'id' => $id, 'school_type' => $type, 'education_level' => $level, 'running_session' => null,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('schools', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('school_type')->nullable();
            $table->string('education_level')->nullable();
            $table->unsignedBigInteger('running_session')->nullable();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('language')->nullable();
        });
        Schema::create('language', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phrase')->nullable();
            $table->text('translated')->nullable();
        });
        Schema::create('global_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('programmes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('classes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('name');
        });
        Schema::create('sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
        });
        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('code', 30)->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'code']);
        });
        Schema::create('curricula', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('programme_id');
        });
        Schema::create('curriculum_memberships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('subject_id');
        });
    }
}
