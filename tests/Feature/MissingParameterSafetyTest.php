<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Stability repair M1 + M3 — filter / list / export endpoints that are normally
 * reached through a submitted filter form crashed with HTTP 500 ("Undefined array
 * key") when opened without their query parameters (reload, bookmark, direct URL).
 * They must answer with a controlled response instead: back-redirect with a
 * validation error (browser) or 422 (AJAX / JSON). No ids are invented.
 */
class MissingParameterSafetyTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->school = $this->makeSchool();
    }

    private function persona(int $role, array $extra = []): User
    {
        return User::factory()->create($extra + ['role_id' => $role, 'school_id' => $this->school, 'account_status' => 'active']);
    }

    public static function endpoints(): array
    {
        return [
            'admin admit card filter' => [2, 'admin.examination.admitCardFilter'],
            'admin attendance student list' => [2, 'admin.attendance.student'],
            'admin attendance filter' => [2, 'admin.daily_attendance.filter'],
            'admin attendance csv' => [2, 'admin.dailyAttendanceFilter_csv'],
            'admin routine list' => [2, 'admin.routine.routine_list'],
            'admin syllabus list' => [2, 'admin.syllabus.syllabus_list'],
            'admin gradebook list' => [2, 'admin.gradebook.list'],
            'admin marks list' => [2, 'admin.marks.list'],
            'admin promotion list' => [2, 'admin.promotion.promotion_list'],
            'teacher attendance student list' => [3, 'teacher.attendance.student'],
            'teacher attendance filter' => [3, 'teacher.daily_attendance.filter'],
            'teacher attendance csv' => [3, 'teacher.dailyAttendanceFilter_csv'],
            'teacher marks list' => [3, 'teacher.marks.list'],
            'teacher routine list' => [3, 'teacher.routine.routine_list'],
            'teacher gradebook list' => [3, 'teacher.gradebook.list'],
            'teacher syllabus sections' => [3, 'teacher.class_wise_section_for_syllabus'],
            'teacher syllabus details' => [3, 'teacher.syllabus_details'],
            'student attendance csv' => [7, 'student.dailyAttendanceFilter_csv'],
            'parent attendance csv' => [6, 'parent.dailyAttendanceFilter_csv'],
            'parent feedback list' => [6, 'parent.feedback.feedback_list'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function test_missing_parameters_give_a_controlled_response(int $role, string $route): void
    {
        $user = $this->persona($role);

        $browser = $this->actingAs($user)->from('/previous-page')->get(route($route));
        $this->assertNotSame(500, $browser->getStatusCode(), "{$route} must not 500");
        $this->assertContains($browser->getStatusCode(), [302, 404, 422], $route);

        $ajax = $this->actingAs($user)->getJson(route($route));
        $this->assertContains($ajax->getStatusCode(), [404, 422], "{$route} (AJAX)");
    }

    public function test_parent_feedback_list_only_shows_the_parents_own_child(): void
    {
        (require base_path('database/migrations/2026_07_26_150005_create_faq_feedbacks_and_frontend_features_tables.php'))->up();
        $parent = $this->persona(6);
        $ownChild = $this->persona(7, ['parent_id' => $parent->id]);
        $otherChild = $this->persona(7, ['parent_id' => $this->persona(6)->id]);

        $this->actingAs($parent)->get(route('parent.feedback.feedback_list', ['student_id' => $ownChild->id]))->assertOk();
        $this->actingAs($parent)->get(route('parent.feedback.feedback_list', ['student_id' => $otherChild->id]))->assertNotFound();
    }

    public function test_pay_fee_deep_link_without_or_with_malformed_credentials_redirects_to_login(): void
    {
        $this->get(route('webRedirectToPayFee'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->get(route('webRedirectToPayFee', ['auth' => 'Basic ' . base64_encode('only-one-part'), 'fee_id' => 1]))
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->get(route('webRedirectToPayFee', ['auth' => 'garbage']))->assertRedirect(route('login'));
    }
}
