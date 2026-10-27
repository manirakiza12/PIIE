<?php

namespace Tests\Feature;

use App\Http\Middleware\RetireAcademicGroups;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class AcademicGroupsRetirementTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        \Illuminate\Support\Facades\Schema::table('schools', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->string('education_level')->nullable());
    }

    private function requestFor(string $controller, string $method = 'GET', bool $tertiary = true): Request
    {
        $school = $this->makeSchool(['education_level' => $tertiary ? 'tertiary' : 'secondary']);
        $request = Request::create('/retired-workspace', $method);
        $request->setUserResolver(fn () => new User(['school_id' => $school]));
        $route = new Route([$method], '/retired-workspace', ['uses' => 'App\\Http\\Controllers\\'.$controller, 'controller' => 'App\\Http\\Controllers\\'.$controller]);
        $request->setRouteResolver(fn () => $route);
        return $request;
    }

    public function test_retired_workspaces_redirect_without_running_the_controller(): void
    {
        foreach ([
            'ProgrammeCohortController@show' => 'admin.academic.direct_placement',
            'AdminController@classList' => 'admin.course_offerings.index',
            'AdminController@teacherPermission' => 'admin.course_offerings.index',
            'AssignmentController@index' => 'admin.course_offerings.index',
            'TeacherController@takeAttendance' => 'teacher.course_offerings.index',
            'TeacherController@marks' => 'teacher.course_offerings.index',
        ] as $action => $destination) {
            $response = (new RetireAcademicGroups)->handle($this->requestFor($action), function () {
                $this->fail('The retired controller must not execute.');
            });
            $this->assertSame(route($destination), $response->getTargetUrl());
        }
    }

    public function test_retired_mutations_are_rejected(): void
    {
        foreach (['ProgrammeCohortController@member', 'AdminController@classCreate', 'TeacherController@attendanceTake'] as $action) {
            try {
                (new RetireAcademicGroups)->handle($this->requestFor($action, 'POST'), fn () => $this->fail('Mutation executed.'));
                $this->fail('Expected the retired endpoint to be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(410, $exception->getStatusCode());
            }
        }
    }

    public function test_school_classes_and_course_workspaces_continue_to_work(): void
    {
        foreach ([['AdminController@classList', false], ['TeacherCourseOfferingController@index', true]] as [$action, $tertiary]) {
            $response = (new RetireAcademicGroups)->handle($this->requestFor($action, tertiary: $tertiary), fn () => 'available');
            $this->assertSame('available', $response);
        }
    }
}
