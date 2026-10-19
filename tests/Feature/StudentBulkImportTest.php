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
 * the same portal account and profile as the single-add form — and must not be able to create a
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

    public function test_affiliated_student_keeps_both_institution_and_piie_identifiers(): void
    {
        (require base_path('database/migrations/2026_10_19_060000_add_affiliation_to_student_profiles.php'))->up();
        \Illuminate\Support\Facades\Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.student.create'), [
            'name' => 'Partner Student', 'email' => 'partner@example.test',
            'registration_number' => 'PIIE/OLD/0042', 'password_option' => 'auto',
            'gender' => '', 'blood_group' => '', 'birthday' => '2000-01-01', 'phone' => '', 'address' => '',
            'affiliated_institution_name' => 'Partner University', 'affiliated_student_id' => '0012/ABC',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $student = User::where('email', 'partner@example.test')->firstOrFail();
        $this->assertSame('PIIE/OLD/0042', $student->code);
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id,
            'affiliated_institution_name' => 'Partner University', 'affiliated_student_id' => '0012/ABC']);
        $this->assertSame(0, DB::table('admissions')->count());
        $this->assertSame(0, DB::table('enrollment')->count());
        $this->actingAs($this->admin)->post(route('admin.student.update', $student->id), [
            'name' => $student->name, 'email' => $student->email,
            'gender' => '', 'blood_group' => '', 'birthday' => '2000-01-01', 'phone' => '', 'address' => '',
            'class_id' => DB::table('classes')->where('school_id', $this->school)->value('id'),
            'affiliated_institution_name' => 'Updated University', 'affiliated_student_id' => '0005/EDIT',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id,
            'affiliated_institution_name' => 'Updated University', 'affiliated_student_id' => '0005/EDIT']);
        $this->assertSame(['affiliated_institution_name' => null, 'affiliated_student_id' => null],
            \App\Models\StudentProfile::affiliationAttributes(['affiliated_institution_name' => '', 'affiliated_student_id' => '']));
    }

    public function test_partner_student_id_requires_institution_and_rolls_back_account_creation(): void
    {
        (require base_path('database/migrations/2026_10_19_060000_add_affiliation_to_student_profiles.php'))->up();
        $this->actingAs($this->admin)->post(route('admin.student.create'), [
            'name' => 'Partner Student', 'email' => 'invalid-partner@example.test',
            'registration_number' => 'PIIE/OLD/0043', 'password_option' => 'auto',
            'gender' => '', 'blood_group' => '', 'birthday' => '2000-01-01', 'phone' => '', 'address' => '',
            'affiliated_student_id' => '0012/ABC',
        ])->assertSessionHasErrors('affiliated_institution_name');
        $this->assertFalse(User::where('email', 'invalid-partner@example.test')->exists());
    }

    public function test_csv_import_preserves_affiliation_fields(): void
    {
        (require base_path('database/migrations/2026_10_19_060000_add_affiliation_to_student_profiles.php'))->up();
        $path = tempnam(sys_get_temp_dir(), 'affiliation');
        $handle = fopen($path, 'w');
        fputcsv($handle, ['name', 'email', 'registration_number', 'affiliated_institution_name', 'affiliated_student_id']);
        fputcsv($handle, ['Partner Import', 'partner-import@example.test', 'PIIE/OLD/0044', 'Partner University', '0009/X']);
        fclose($handle);
        $this->actingAs($this->admin)->post(route('admin.student.import.run'), ['file' => $this->file($path)])->assertOk();
        $student = User::where('email', 'partner-import@example.test')->firstOrFail();
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id,
            'affiliated_institution_name' => 'Partner University', 'affiliated_student_id' => '0009/X']);
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
            'next_of_kin_contact', 'next_of_kin_address', 'status', 'intake_name', 'registration_number']);
        foreach ($rows as $row) {
            fputcsv($handle, array_merge(array_pad($row, 13, ''), ['LEGACY/' . strtoupper(strtok($row[1], '@')) . '/0042']));
        }
        fclose($handle);

        return $path;
    }

    private function file(string $path): UploadedFile
    {
        return new UploadedFile($path, 'students.csv', 'text/csv', null, true);
    }

    public function test_preview_confirmation_imports_without_reupload_and_cannot_be_replayed(): void
    {
        $path = $this->csv([['Preview Student', 'preview@x.test', '']]);
        $this->actingAs($this->admin)->post(route('admin.student.import.preview'), ['file' => $this->file($path)])
            ->assertOk()->assertSee('LEGACY/PREVIEW/0042');
        $this->post(route('admin.student.import.run'))->assertOk();
        $this->assertSame('LEGACY/PREVIEW/0042', User::where('email', 'preview@x.test')->value('code'));
        $this->post(route('admin.student.import.run'))->assertStatus(422);
        $this->assertSame(0, DB::table('enrollment')->count());
        $this->assertSame(0, DB::table('identity_sequences')->count());
    }

    public function test_single_add_uses_legacy_number_and_the_shared_activation_journey(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->enableSmtpSettings();
        $this->actingAs($this->admin)->post(route('admin.student.create'), [
            'name' => 'Continuing Student', 'email' => 'continuing@x.test',
            'registration_number' => 'PIIE/2018/0007', 'password_option' => 'auto',
            'gender' => 'Female', 'blood_group' => '', 'birthday' => '2000-01-01',
            'phone' => '0700000000', 'address' => 'Kampala',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $student = User::where('email', 'continuing@x.test')->firstOrFail();
        $this->assertSame('PIIE/2018/0007', $student->code);
        $this->assertTrue((bool) $student->force_password_change);
        $this->assertSame(0, DB::table('enrollment')->count());
        $this->assertSame(0, DB::table('student_fee_managers')->count());
        $this->assertSame(0, DB::table('admissions')->count());
        $this->assertSame(0, DB::table('identity_sequences')->count());
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\StudentPortalActivationEmail::class, function ($mail) use ($student) {
            return $mail->hasTo($student->email) && $mail->data['code'] === $student->code
                && \Illuminate\Support\Facades\Hash::check($mail->data['password'], $student->password);
        });
        $this->actingAs($student)->get(route('student.dashboard'))->assertRedirect(route('student.password', 'edit'));
    }

    public function test_missing_and_duplicate_registration_numbers_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), [
            'name' => 'No Number', 'email' => 'missing@x.test',
        ])->assertSessionHasErrors('registration_number');
        User::factory()->create(['school_id' => $this->school, 'role_id' => 7, 'code' => 'legacy/DUP/0042']);
        $path = $this->csv([['Duplicate', 'dup@x.test', '']]);
        $this->post(route('admin.student.import.preview'), ['file' => $this->file($path)])
            ->assertOk()->assertSee('registration number is already registered');
        $this->assertFalse(User::where('email', 'dup@x.test')->exists());
    }

    public function test_confirmation_rechecks_registration_numbers_added_since_preview(): void
    {
        $path = $this->csv([['Raced Student', 'raced@x.test', '']]);
        $this->actingAs($this->admin)->post(route('admin.student.import.preview'), ['file' => $this->file($path)])->assertOk();
        $existing = User::factory()->create(['school_id' => $this->school, 'role_id' => 7, 'code' => 'LEGACY/RACED/0042']);
        $before = $existing->fresh()->getRawOriginal();
        $this->post(route('admin.student.import.run'))->assertRedirect()->assertSessionHas('error');
        $this->assertFalse(User::where('email', 'raced@x.test')->exists());
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
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

    public function test_import_creates_a_student_profile_without_enrolment(): void
    {
        $path = $this->csv([['Alice Okello', 'alice@x.test', 'Class A', '', '', '', '1', 'Ugandan', '', '', '', 'active']]);

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $student = User::where('email', 'alice@x.test')->first();
        $this->assertNotNull($student);
        $this->assertSame('7', (string) $student->role_id);
        $this->assertSame($this->school, (int) $student->school_id);
        $this->assertSame('LEGACY/ALICE/0042', $student->code);
        $this->assertTrue((bool) $student->force_password_change);

        $this->assertSame(1, DB::table('student_profiles')->where('user_id', $student->id)->count());
        $this->assertSame(0, DB::table('enrollment')->where('user_id', $student->id)->count());
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

    public function test_programme_profile_import_does_not_create_placement_or_invoice(): void
    {
        $programmeId = $this->makeProgramme($this->school, ['name' => 'BSc Computer Science']);
        $this->makeIntakeSession($this->school, ['name' => 'May 2026 Intake']);
        $classId = DB::table('classes')->where('school_id', $this->school)->first()->id;
        $this->section('Section A1', $classId);

        $path = $this->csv([[
            'Carol Auma', 'carol@x.test', 'Class A', 'Section A1', 'BSc Computer Science',
            '', '2', 'Ugandan', 'CM-1', '+256700000000', 'Kampala', 'active', 'May 2026 Intake',
        ]]);

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $student = User::where('email', 'carol@x.test')->first();
        $this->assertNotNull($student);

        $profile = DB::table('student_profiles')->where('user_id', $student->id)->first();
        $this->assertSame($programmeId, (int) $profile->programme_id);
        $this->assertSame(2, (int) $profile->year_of_study);

        $this->assertSame(0, DB::table('enrollment')->count());
        $this->assertSame(0, DB::table('student_fee_managers')->count());
    }

    public function test_programme_import_without_intake_can_preserve_legacy_number(): void
    {
        $this->makeProgramme($this->school, ['name' => 'BSc Computer Science']);
        $path = $this->csv([['Carol Auma', 'carol@x.test', 'Class A', '', 'BSc Computer Science']]);
        $this->actingAs($this->admin)->post(route('admin.student.import.preview'), ['file' => $this->file($path)])
            ->assertOk()->assertSee('LEGACY/CAROL/0042');
        $this->assertNull(User::where('email', 'carol@x.test')->first());
        $this->assertSame(0, DB::table('identity_sequences')->count());
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

    /** A failure part-way must not leave a user without its profile. */
    public function test_profile_write_failure_rolls_back_the_portal_account(): void
    {
        $path = $this->csv([['Rollback Me', 'rollback@x.test', 'Class A', '', '', '', '1', '', '', '', '', '']]);

        DB::statement('DROP TABLE student_profiles');

        $this->actingAs($this->admin)
            ->post(route('admin.student.import.run'), ['file' => $this->file($path)])
            ->assertOk();

        $this->assertSame(0, User::where('email', 'rollback@x.test')->count(),
            'the user row must roll back with the failed profile');
        $this->assertSame(0, DB::table('enrollment')->count());
    }
}
