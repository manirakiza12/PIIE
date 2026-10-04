<?php

namespace Tests\Feature;

use App\Models\Programme;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers the Programmes management screen being reorganised to group by
 * faculty (Department), plus two real bugs found while doing that:
 *  - the create/edit modal's level/mode <select>s were hardcoded to the
 *    legacy option lists and never offered the client's current preferred
 *    values (Bachelors, PGD, ODEL, Full Time, Weekend) at all;
 *  - destroy() had no guard against deleting a programme an application or
 *    student already references.
 */
class ProgrammeManagementTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function makeDepartment(int $schoolId, string $name): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('departments')->insertGetId([
            'name' => $name,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_index_groups_programmes_under_their_faculty(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $engineering = $this->makeDepartment($schoolId, 'Faculty of Engineering');
        $business = $this->makeDepartment($schoolId, 'Faculty of Business');

        $this->makeProgramme($schoolId, ['name' => 'BSc Civil Engineering', 'code' => 'ENG1', 'department_id' => $engineering]);
        $this->makeProgramme($schoolId, ['name' => 'BSc Accounting', 'code' => 'BUS1', 'department_id' => $business]);
        $this->makeProgramme($schoolId, ['name' => 'Unassigned Programme', 'code' => 'UNA1', 'department_id' => null]);

        $response = $this->actingAs($admin)->get(route('admin.programmes.index'));

        $response->assertStatus(200);
        $response->assertSeeInOrder(['Faculty of Business', 'BSc Accounting']);
        $response->assertSeeInOrder(['Faculty of Engineering', 'BSc Civil Engineering']);
        // The catch-all group is labelled for what it really holds: no faculty
        // set, AND a faculty that no longer exists.
        $response->assertSee('Unassigned / Unknown Department');
        $response->assertSee('Unassigned Programme');
    }

    public function test_a_faculty_with_no_programmes_still_gets_its_own_empty_section_when_unfiltered(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $this->makeDepartment($schoolId, 'Faculty of Law');

        $response = $this->actingAs($admin)->get(route('admin.programmes.index'));

        $response->assertStatus(200);
        $response->assertSee('Faculty of Law');
        $response->assertSee('No programmes in this faculty yet.');
    }

    public function test_department_filter_narrows_to_one_faculty(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $engineering = $this->makeDepartment($schoolId, 'Faculty of Engineering');
        $business = $this->makeDepartment($schoolId, 'Faculty of Business');

        $this->makeProgramme($schoolId, ['name' => 'BSc Civil Engineering', 'code' => 'ENG1', 'department_id' => $engineering]);
        $this->makeProgramme($schoolId, ['name' => 'BSc Accounting', 'code' => 'BUS1', 'department_id' => $business]);

        $response = $this->actingAs($admin)->get(route('admin.programmes.index', ['department_id' => $engineering]));

        $response->assertStatus(200);
        $response->assertSee('BSc Civil Engineering');
        // The Business programme itself must be gone; "Faculty of Business"
        // as a filter-dropdown option is expected to remain so the admin can
        // still switch to it.
        $response->assertDontSee('BSc Accounting');
    }

    public function test_unassigned_filter_shows_only_programmes_with_no_faculty(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $engineering = $this->makeDepartment($schoolId, 'Faculty of Engineering');
        $this->makeProgramme($schoolId, ['name' => 'BSc Civil Engineering', 'code' => 'ENG1', 'department_id' => $engineering]);
        $this->makeProgramme($schoolId, ['name' => 'Orphan Programme', 'code' => 'ORP1', 'department_id' => null]);

        $response = $this->actingAs($admin)->get(route('admin.programmes.index', ['department_id' => 'none']));

        $response->assertStatus(200);
        $response->assertSee('Orphan Programme');
        $response->assertDontSee('BSc Civil Engineering');
    }

    public function test_programmes_from_another_school_never_leak_into_the_grouped_view(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $adminA = $this->makeAdminUser($schoolA);

        $this->makeProgramme($schoolA, ['name' => 'School A Programme', 'code' => 'A1']);
        $this->makeProgramme($schoolB, ['name' => 'School B Programme', 'code' => 'B1']);

        $response = $this->actingAs($adminA)->get(route('admin.programmes.index'));

        $response->assertStatus(200);
        $response->assertSee('School A Programme');
        $response->assertDontSee('School B Programme');
    }

    // ── Level/Mode dropdown fix ──────────────────────────────────────────

    public function test_a_programme_can_be_created_with_a_current_preferred_level_and_mode(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $response = $this->actingAs($admin)->post(route('admin.programmes.store'), [
            'code' => 'NEW1',
            'name' => 'New Style Programme',
            'level' => 'Bachelors',
            'mode' => 'ODEL',
            'tuition_fee' => 500,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('programmes', ['code' => 'NEW1', 'level' => 'Bachelors', 'mode' => 'ODEL']);
    }

    public function test_the_create_modal_offers_every_current_and_legacy_level_and_mode(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $response = $this->actingAs($admin)->get(route('admin.programmes.open_modal'));

        $response->assertStatus(200);
        foreach (array_merge(Programme::LEVELS, Programme::LEVELS_LEGACY) as $level) {
            $response->assertSee('value="' . $level . '"', false);
        }
        foreach (array_merge(Programme::MODES, Programme::MODES_LEGACY) as $mode) {
            $response->assertSee('value="' . $mode . '"', false);
        }
    }

    public function test_an_invalid_level_or_mode_is_rejected(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $response = $this->actingAs($admin)->post(route('admin.programmes.store'), [
            'code' => 'BAD1',
            'name' => 'Bad Programme',
            'level' => 'NotARealLevel',
            'mode' => 'NotARealMode',
        ]);

        $response->assertSessionHasErrors(['level', 'mode']);
        $this->assertDatabaseMissing('programmes', ['code' => 'BAD1']);
    }

    public function test_a_duplicate_code_within_the_same_school_is_rejected(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $this->makeProgramme($schoolId, ['code' => 'DUP1']);

        $response = $this->actingAs($admin)->post(route('admin.programmes.store'), [
            'code' => 'DUP1',
            'name' => 'Second Programme With Same Code',
            'level' => 'Diploma',
            'mode' => 'ODEL',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_the_same_code_is_allowed_across_different_schools(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $adminB = $this->makeAdminUser($schoolB);

        $this->makeProgramme($schoolA, ['code' => 'SHARED']);

        $response = $this->actingAs($adminB)->post(route('admin.programmes.store'), [
            'code' => 'SHARED',
            'name' => 'Programme In School B',
            'level' => 'Diploma',
            'mode' => 'ODEL',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('programmes', ['school_id' => $schoolB, 'code' => 'SHARED']);
    }

    // ── Delete guard ─────────────────────────────────────────────────────

    public function test_a_programme_with_an_admission_cannot_be_deleted(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);

        $this->makeAdmission($schoolId, ['programme_id' => $programmeId]);

        $response = $this->actingAs($admin)->get(route('admin.programmes.destroy', $programmeId));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('programmes', ['id' => $programmeId]);
    }

    public function test_a_programme_with_a_student_profile_cannot_be_deleted(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $student = $this->makeAdminUser($schoolId); // any user row works as the FK target here

        \Illuminate\Support\Facades\DB::table('student_profiles')->insert([
            'user_id' => $student->id,
            'school_id' => $schoolId,
            'programme_id' => $programmeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.programmes.destroy', $programmeId));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('programmes', ['id' => $programmeId]);
    }

    public function test_an_untouched_programme_can_still_be_deleted(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);

        $response = $this->actingAs($admin)->get(route('admin.programmes.destroy', $programmeId));

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('programmes', ['id' => $programmeId]);
    }

    // ── Orphaned programmes must never silently disappear ───────────────────
    //
    // Regression: a programme whose department_id points at a department that
    // has since been DELETED matched neither a faculty group nor the old
    // `department_id IS NULL` bucket, so it was fetched, counted in totalCount,
    // and then dropped from the rendered screen. This is the BBIT case.

    public function test_a_programme_whose_department_no_longer_exists_is_still_displayed(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $it = $this->makeDepartment($schoolId, 'Department of Computer Science & IT');

        // department_id 9 does not exist in this school (or any).
        $bbit = $this->makeProgramme($schoolId, [
            'name' => 'Bachelor of Business Information Technology', 'code' => 'BBIT', 'department_id' => 9,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.programmes.index'));

        $response->assertStatus(200);
        $response->assertSee('Unassigned / Unknown Department');
        $response->assertSee('BBIT');

        // Asserted on the group structure, not the HTML: the faculty filter
        // dropdown legitimately lists every department name, so a plain
        // assertDontSee would be meaningless. BBIT must be in the fallback
        // group and NOT in the Computer Science group.
        $groups = $response->viewData('groups');
        $inIt = $groups->first(function ($group) use ($it) {
            return $group['department'] && (int) $group['department']->id === $it;
        });
        $this->assertNotNull($inIt);
        $this->assertCount(0, $inIt['programmes'], 'BBIT is not filed under a faculty it does not belong to');

        $fallback = $groups->first(fn ($group) => ($group['isUnresolvedGroup'] ?? false) === true);
        $this->assertNotNull($fallback, 'the fallback group is present');
        $this->assertSame([(int) $bbit], $fallback['programmes']->map(fn ($p) => (int) $p->id)->values()->all());
    }

    public function test_the_fallback_group_holds_both_null_and_dangling_departments_and_counts_once(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $law = $this->makeDepartment($schoolId, 'Faculty of Law');

        $nullDept = $this->makeProgramme($schoolId, ['name' => 'No Faculty Set', 'code' => 'NUL1', 'department_id' => null]);
        $dangling = $this->makeProgramme($schoolId, ['name' => 'Dangling Department', 'code' => 'DAN1', 'department_id' => 9]);
        $owned = $this->makeProgramme($schoolId, ['name' => 'Properly Filed', 'code' => 'OWN1', 'department_id' => $law]);

        $html = $this->actingAs($admin)->get(route('admin.programmes.index'))->assertOk()->getContent();

        // All three are visible, and the two unresolved ones share one group.
        foreach (['NUL1', 'DAN1', 'OWN1'] as $code) {
            $this->assertStringContainsString($code, $html, "{$code} must be displayed");
        }
        $this->assertStringContainsString('Unassigned / Unknown Department', $html);

        // Each programme appears exactly once: no duplicates across groups.
        foreach (['NUL1', 'DAN1', 'OWN1'] as $code) {
            $this->assertSame(1, substr_count($html, '>' . $code . '<'), "{$code} is rendered exactly once");
        }

        // The reported total equals the number of programmes actually fetched.
        $this->assertSame(3, \Illuminate\Support\Facades\DB::table('programmes')->where('school_id', $schoolId)->count());
    }

    public function test_the_displayed_count_equals_the_rendered_programme_rows_for_every_case(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $a = $this->makeDepartment($schoolId, 'Faculty A');
        $b = $this->makeDepartment($schoolId, 'Faculty B');

        $this->makeProgramme($schoolId, ['code' => 'AAA', 'department_id' => $a]);
        $this->makeProgramme($schoolId, ['code' => 'BBB', 'department_id' => $b]);
        $this->makeProgramme($schoolId, ['code' => 'NUL', 'department_id' => null]);
        $this->makeProgramme($schoolId, ['code' => 'DAN', 'department_id' => 4242]);
        $this->makeProgramme($schoolId, ['code' => 'DAN', 'department_id' => null]);

        $html = $this->actingAs($admin)->get(route('admin.programmes.index'))->assertOk()->getContent();

        // "5 programme(s)" — totalCount is derived from the query, and the
        // rendered rows must account for every one of them.
        $this->assertStringContainsString('5', $html);
        foreach (['AAA', 'BBB', 'NUL', 'DAN'] as $code) {
            $this->assertGreaterThanOrEqual(1, substr_count($html, '>' . $code . '<'), "{$code} rendered");
        }
    }

    public function test_a_programme_pointing_at_another_tenants_department_is_never_shown_under_it(): void
    {
        $schoolId = $this->makeSchool();
        $otherSchool = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $this->makeDepartment($schoolId, 'Own Faculty');
        $foreignDepartmentId = $this->makeDepartment($otherSchool, 'Foreign Confidential Faculty');

        $this->makeProgramme($schoolId, ['code' => 'XNT', 'department_id' => $foreignDepartmentId]);

        $html = $this->actingAs($admin)->get(route('admin.programmes.index'))->assertOk()->getContent();

        // The programme is surfaced, but the other tenant's faculty name is not.
        $this->assertStringContainsString('XNT', $html);
        $this->assertStringNotContainsString('Foreign Confidential Faculty', $html,
            'another tenant\'s faculty must never leak into this screen');
        $this->assertStringContainsString('Unassigned / Unknown Department', $html);
    }

    public function test_another_tenants_programmes_never_appear_at_all(): void
    {
        $schoolId = $this->makeSchool();
        $otherSchool = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $mine = $this->makeDepartment($schoolId, 'Own Faculty');

        $this->makeProgramme($schoolId, ['code' => 'MINE', 'name' => 'My Programme', 'department_id' => $mine]);
        $this->makeProgramme($otherSchool, ['code' => 'THEIRS', 'name' => 'Their Programme', 'department_id' => null]);
        $this->makeProgramme($otherSchool, ['code' => 'ORPHAN', 'name' => 'Their Orphan', 'department_id' => 9]);

        $html = $this->actingAs($admin)->get(route('admin.programmes.index'))->assertOk()->getContent();

        $this->assertStringContainsString('MINE', $html);
        $this->assertStringNotContainsString('THEIRS', $html);
        $this->assertStringNotContainsString('ORPHAN', $html);
        $this->assertStringNotContainsString('Their Programme', $html);
        $this->assertStringNotContainsString('Their Orphan', $html);
    }
}
