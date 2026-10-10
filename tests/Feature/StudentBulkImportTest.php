<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Bulk import of students who are already studying.
 *
 * These students never pass through admissions, so the import must provision
 * exactly what the single-add form provisions — and must not be able to create a
 * student belonging to another school, in a section that is not part of the
 * chosen class, or with an email that already exists.
 */
class StudentBulkImportTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdminUser($this->school);
        $this->makeClass($this->school, ['name' => 'Class A']);
    }

    /**
     * `sections` has no school_id in the real schema - it belongs to a class -
     * so the fixture mirrors that rather than inventing a column.
     */
    private function section(string $name, int $classId): int
    {
        return (int) DB::table('sections')->insertGetId([
            'class_id' => $classId, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ['name', 'email', 'class_name', 'section_name', 'programme_name',
            'department_name', 'year_of_study', 'nationality', 'national_id_or_passport',
            'next_of_kin_contact', 'next_of_kin_address', 'status']);
        foreach ($rows as $row) {
            fputcsv($handle, array_pad($row, 12, ''));
        }
        fclose($handle);

        return $path;
    }

    private function file(string $path): UploadedFile
    {
        return new UploadedFile($path, 'students.csv', 'text/csv', null, true);
    }

    public function test_template_downloads_with_a_header_and_example_row(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.student.import.template'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('class_name', $response->getContent());
    }

    public function test_preview_reports_rows_without_creating_anything(): void
    {
        $path = $this->csv([['Alice Okello', 'alice@x.test', 'Class A', '', '', '', '1', 'Ugandan', '', '', '', 'active']]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.student.import.preview'), ['file' => $this->file($path)]);

        $response->assertOk();
        $response->assertSee('alice@x.test');
        $this->assertSame(0, User::where('email', 'alice@x.test')->count(), 'preview must not write');
    }

    public function test_import_creates_a_student_profile_and_enrolment(): void
    {
        $path = $this->csv([['Alice Okello', 'alice@x.test', 'Class A', '', '', '', '1', 'Ugandan', '', '', '', 'active']]);

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $student = User::where('email', 'alice@x.test')->first();
        $this->assertNotNull($student);
        $this->assertSame('7', (string) $student->role_id);
        $this->assertSame($this->school, (int) $student->school_id);
        $this->assertNotEmpty($student->code);

        $this->assertSame(1, DB::table('student_profiles')->where('user_id', $student->id)->count());
        $this->assertSame(1, DB::table('enrollment')->where('user_id', $student->id)->count());
    }

    /** The whole point of this feature: continuing students are not applicants. */
    public function test_import_never_creates_an_admission_or_a_fee_obligation(): void
    {
        $before = DB::table('admissions')->count();
        $path = $this->csv([['Bob Kintu', 'bob@x.test', 'Class A', '', '', '', '2', 'Ugandan', '', '', '', 'active']]);

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $this->assertSame($before, DB::table('admissions')->count(), 'an existing student must not get an application');
        $this->assertSame(0, DB::table('application_payments')->count(), 'and must not be charged a fee');
    }

    public function test_import_with_a_programme_and_section_still_provisions(): void
    {
        $programmeId = $this->makeProgramme($this->school, ['name' => 'BSc Computer Science']);
        $classId = DB::table('classes')->where('school_id', $this->school)->first()->id;
        $this->section('Section A1', $classId);

        $path = $this->csv([[
            'Carol Auma', 'carol@x.test', 'Class A', 'Section A1', 'BSc Computer Science',
            '', '2', 'Ugandan', 'CM-1', '+256700000000', 'Kampala', 'active',
        ]]);

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $student = User::where('email', 'carol@x.test')->first();
        $this->assertNotNull($student);

        $profile = DB::table('student_profiles')->where('user_id', $student->id)->first();
        $this->assertSame($programmeId, (int) $profile->programme_id);
        $this->assertSame(2, (int) $profile->year_of_study);

        $enrolment = DB::table('enrollment')->where('user_id', $student->id)->first();
        $this->assertSame($classId, (int) $enrolment->class_id);
        $this->assertNotSame(0, (int) $enrolment->section_id, 'the section must be carried across');
    }

    public function test_duplicate_emails_are_reported_and_skipped(): void
    {
        User::create(['name' => 'Existing', 'email' => 'taken@x.test',
            'password' => bcrypt('x1234567'), 'role_id' => 7, 'school_id' => $this->school, 'status' => 1]);

        $path = $this->csv([
            ['Alice Okello', 'alice@x.test', 'Class A', '', '', '', '1', '', '', '', '', ''],
            ['Taken Person', 'taken@x.test', 'Class A', '', '', '', '1', '', '', '', '', ''],
            ['Alice Again', 'alice@x.test', 'Class A', '', '', '', '1', '', '', '', '', ''],
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $this->assertSame(1, User::where('email', 'alice@x.test')->count(), 'in-file duplicate blocked');
        $this->assertSame(1, User::where('email', 'taken@x.test')->count(), 'existing email untouched');
    }

    public function test_a_class_from_another_school_is_rejected(): void
    {
        $otherSchool = $this->makeSchool(['title' => 'Other School']);
        $this->makeClass($otherSchool, ['name' => 'Foreign Class']);

        $path = $this->csv([['Mallory X', 'mallory@x.test', 'Foreign Class', '', '', '', '1', '', '', '', '', '']]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.student.import.preview'), ['file' => $this->file($path)]);

        $response->assertOk();
        $response->assertSee('does not exist in this school');
        $this->assertSame(0, User::where('email', 'mallory@x.test')->count());
    }

    public function test_a_section_belonging_to_another_class_is_rejected(): void
    {
        $classId = DB::table('classes')->where('school_id', $this->school)->first()->id;
        $otherClass = $this->makeClass($this->school, ['name' => 'Class B']);
        $this->section('Wrong Section', $otherClass);

        $path = $this->csv([['Nina K', 'nina@x.test', 'Class A', 'Wrong Section', '', '', '1', '', '', '', '', '']]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.student.import.preview'), ['file' => $this->file($path)]);

        $response->assertOk();
        $response->assertSee('section does not belong');
        $this->assertSame(0, User::where('email', 'nina@x.test')->count());
    }

    public function test_one_bad_row_does_not_abort_the_good_rows(): void
    {
        $path = $this->csv([
            ['Good One', 'good1@x.test', 'Class A', '', '', '', '1', '', '', '', '', ''],
            ['Bad Row', 'not-an-email', 'Class A', '', '', '', '1', '', '', '', '', ''],
            ['Good Two', 'good2@x.test', 'Class A', '', '', '', '1', '', '', '', '', ''],
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $this->assertSame(1, User::where('email', 'good1@x.test')->count());
        $this->assertSame(1, User::where('email', 'good2@x.test')->count());
        $this->assertSame(0, User::where('email', 'not-an-email')->count());
    }

    /** A failure part-way must not leave a user without its enrolment. */
    public function test_a_row_failing_mid_write_leaves_no_half_created_student(): void
    {
        $path = $this->csv([['Rollback Me', 'rollback@x.test', 'Class A', '', '', '', '1', '', '', '', '', '']]);

        DB::statement('DROP TABLE enrollment');

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $this->assertSame(0, User::where('email', 'rollback@x.test')->count(),
            'the user row must roll back with the failed enrolment');
        $this->assertSame(0, DB::table('student_profiles')->where('user_id', '>', 0)->count(),
            'and no orphan profile may survive');
    }
}