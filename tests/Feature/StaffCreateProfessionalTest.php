<?php

namespace Tests\Feature;

use App\Models\StaffDocument;
use App\Models\StaffProfessionalRegistration;
use App\Models\StaffProfile;
use App\Models\StaffQualification;
use App\Models\User;
use App\Support\Staff\StaffDocumentStorage;
use App\Support\Staff\StaffQualificationLevel;
use App\Support\Staff\StaffTitle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Create Staff: the controlled personal title, the Lecturer-only academic and
 * professional information written through the EXISTING professional-record
 * architecture, the supporting-document repeater, the role-aware form, and the
 * post-creation screen.
 *
 * No new column is involved anywhere here: the title reuses
 * staff_profiles.title, the qualification reuses staff_qualifications, the
 * professional registration reuses staff_professional_registrations and the
 * documents reuse staff_documents and the private StaffDocumentStorage.
 */
class StaffCreateProfessionalTest extends TestCase
{
    use StaffModuleTestHelper;

    private User $admin;
    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->school = $this->makeSchool();
        $this->createStaffTables();
        $this->makeDesignation($this->school);
        $this->makeDepartment($this->school);
        (require base_path('database/migrations/2014_10_12_100000_create_password_resets_table.php'))->up();

        // The RBAC tables the Staff Directory and the account-access screen read.
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require base_path('database/migrations/2026_09_23_000004_add_is_active_to_staff_roles.php'))->up();

        $this->useRealPrivateStore();

        // A school admin holds the staff permissions by role; nothing to grant.
        $this->admin = User::factory()->create([
            'name' => 'HR Admin', 'role_id' => 2, 'school_id' => $this->school,
            'account_status' => 'active', 'staff_status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->purgePrivateStore();
        parent::tearDown();
    }

    // ====================================================== title dropdown

    public function test_the_title_is_a_controlled_dropdown_with_the_expected_options(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.staff.create', 'lecturer'))->assertOk()->getContent();

        $this->assertStringContainsString('<select id="title" name="title"', $html, 'The title is a selection, not free text.');
        $this->assertStringNotContainsString('<input id="title" name="title"', $html);

        foreach (['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof', 'Rev', 'Fr', 'Sr', 'Eng', 'Other'] as $option) {
            $this->assertStringContainsString('<option value="'.$option.'"', $html, "The {$option} title is offered.");
        }
        // A responsibility is not a personal title.
        foreach (['Head of Department', 'HOD'] as $notATitle) {
            $this->assertStringNotContainsString('<option value="'.$notATitle.'"', $html);
        }
    }

    public function test_no_new_column_was_added_for_the_title_dropdown(): void
    {
        $columns = Schema::getColumnListing('staff_profiles');

        // The only title columns are the two that already existed.
        $this->assertContains('title', $columns);
        $this->assertContains('academic_title', $columns);
        $this->assertSame(
            ['title', 'academic_title'],
            array_values(array_filter($columns, fn ($c) => str_ends_with($c, 'title') || $c === 'title'))
        );
    }

    public function test_a_chosen_title_is_stored_in_the_existing_profile_title_column(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'accountant'), $this->payload(['title' => 'Dr', 'email' => 'title.dr@example.com']))
            ->assertRedirect();

        $profile = $this->profileOf('title.dr@example.com');
        $this->assertSame('Dr', $profile->title, 'The existing staff_profiles.title column is reused as-is.');
        $this->assertContains('title', StaffProfile::first()->getFillable());
    }

    public function test_other_title_requires_a_custom_title_and_stores_it_in_the_same_column(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'librarian'), $this->payload(['title' => 'Other', 'title_other' => 'Hon', 'email' => 'title.other@example.com']))
            ->assertRedirect();

        $profile = $this->profileOf('title.other@example.com');
        $this->assertSame('Other: Hon', $profile->title, 'The custom title is folded into the existing column.');
        $this->assertSame('Hon', StaffTitle::otherDetail($profile->title));
        $this->assertSame('Other', StaffTitle::base($profile->title));
    }

    public function test_other_without_a_custom_title_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'librarian'), $this->payload(['title' => 'Other', 'title_other' => '']))
            ->assertSessionHasErrors('title_other');
    }

    public function test_a_custom_title_is_refused_when_another_title_is_chosen(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'librarian'), $this->payload(['title' => 'Mr', 'title_other' => 'Hon']))
            ->assertSessionHasErrors('title_other');
    }

    public function test_an_unknown_title_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'librarian'), $this->payload(['title' => 'HOD']))
            ->assertSessionHasErrors('title');
    }

    public function test_a_missing_title_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'librarian'), $this->payload(['title' => '']))
            ->assertSessionHasErrors('title');
    }

    // ======================================== lecturer academic information

    public function test_the_lecturer_form_has_the_academic_and_documents_sections(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.staff.create', 'lecturer'))->assertOk()->getContent();

        $this->assertStringContainsString('id="sec-academic"', $html);
        $this->assertStringContainsString('id="sec-documents"', $html);
        foreach (['name="qualification_level"', 'name="field_of_study"', 'name="institution"', 'name="completion_year"', 'name="professional_body"', 'name="registration_number"', 'name="years_teaching_experience"'] as $field) {
            $this->assertStringContainsString($field, $html, "The Lecturer form offers {$field}.");
        }
        foreach (['Professional Information', 'Supporting Documents', 'Highest Qualification', 'Field of Study', 'Awarding Institution', 'Year Awarded'] as $label) {
            $this->assertStringContainsString($label, $html, "The Lecturer form is labelled {$label}.");
        }
        // get_phrase() HTML-escapes the apostrophe in the rendered option.
        foreach (['Certificate', 'Diploma', 'Postgraduate Diploma', 'Master&#039;s Degree', 'Doctorate / PhD'] as $level) {
            $this->assertStringContainsString('<option value="'.$level.'"', $html);
        }
        // The document repeater starts with one usable row and an "add another",
        // rather than three permanent native file inputs (presentation change
        // only: the repeater still submits documents[0..n] either way).
        $this->assertSame(1, substr_count($html, 'class="sc-doc-row document-row"'));
        $this->assertStringContainsString('id="add-document"', $html);
        foreach (['cv', 'academic_certificate', 'national_id', 'professional_certificate', 'appointment_letter', 'other'] as $category) {
            $this->assertStringContainsString('<option value="'.$category.'"', $html, "Document type {$category} is offered.");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredAcademicField')]
    public function test_the_lecturer_academic_required_fields_are_enforced(string $field): void
    {
        $payload = $this->lecturerPayload();
        unset($payload[$field]);

        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $payload)
            ->assertSessionHasErrors($field);
    }

    public static function requiredAcademicField(): array
    {
        return [
            'highest qualification' => ['qualification_level'],
            'field of study' => ['field_of_study'],
            'awarding institution' => ['institution'],
        ];
    }

    public function test_a_lecturer_qualification_is_saved_through_the_existing_qualification_table(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
                'qualification_level' => "Master's Degree",
                'field_of_study' => 'Software Engineering',
                'institution' => 'Makerere University',
                'completion_year' => '2016',
                'years_teaching_experience' => '9',
            ]))
            ->assertRedirect();

        $user = User::where('email', 'lecturer@example.com')->firstOrFail();

        $qualification = StaffQualification::where('user_id', $user->id)->firstOrFail();
        $this->assertSame("Master's Degree", $qualification->qualification_level);
        $this->assertSame('Software Engineering', $qualification->specialisation, 'The existing specialisation column is reused.');
        $this->assertSame('Makerere University', $qualification->institution);
        $this->assertSame(2016, $qualification->completion_year);
        $this->assertSame($this->school, (int) $qualification->school_id);
        $this->assertSame($this->admin->id, (int) $qualification->created_by);

        // Years of experience lives on the existing profile column.
        $this->assertSame(9, (int) $this->profileOf('lecturer@example.com')->years_teaching_experience);
    }

    public function test_another_qualification_level_is_saved_and_stays_comparable(): void
    {
        Mail::fake();
        foreach ([StaffQualificationLevel::CERTIFICATE, StaffQualificationLevel::BACHELORS, StaffQualificationLevel::DOCTORATE] as $i => $level) {
            $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
                'qualification_level' => $level,
                'email' => "level{$i}@example.com",
            ]))->assertRedirect();
        }

        $levels = StaffQualification::orderBy('id')->pluck('qualification_level')->all();
        $this->assertSame([StaffQualificationLevel::CERTIFICATE, StaffQualificationLevel::BACHELORS, StaffQualificationLevel::DOCTORATE], $levels);
    }

    public function test_other_highest_qualification_requires_a_description(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload(['qualification_level' => 'Other', 'qualification_level_other' => '']))
            ->assertSessionHasErrors('qualification_level_other');

        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'qualification_level' => 'Other',
            'qualification_level_other' => 'Postgraduate Diploma in Education',
            'email' => 'qual.other@example.com',
        ]))->assertRedirect();

        $this->assertSame(
            'Other: Postgraduate Diploma in Education',
            StaffQualification::where('user_id', User::where('email', 'qual.other@example.com')->value('id'))->value('qualification_level')
        );
    }

    public function test_a_future_year_awarded_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload(['completion_year' => (string) ((int) date('Y') + 20)]))
            ->assertSessionHasErrors('completion_year');
    }

    public function test_a_professional_body_and_number_use_the_existing_registration_table(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'professional_body' => 'Uganda Institution of Civil Engineers',
            'registration_number' => 'UICE-4471',
        ]))->assertRedirect();

        $registration = StaffProfessionalRegistration::where('user_id', User::where('email', 'lecturer@example.com')->value('id'))->firstOrFail();
        $this->assertSame('Uganda Institution of Civil Engineers', $registration->professional_body);
        $this->assertSame('UICE-4471', $registration->registration_number);
    }

    public function test_no_registration_row_is_created_without_a_professional_body(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload())->assertRedirect();
        $this->assertSame(0, StaffProfessionalRegistration::count());
    }

    public function test_the_qualification_is_one_of_many_and_does_not_become_a_single_permanent_field(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload())->assertRedirect();
        $user = User::where('email', 'lecturer@example.com')->firstOrFail();

        // A further qualification can still be added afterwards through the
        // existing professional-record service.
        app(\App\Support\Staff\StaffRecordService::class)->addQualification($this->admin, $user, [
            'qualification_level' => StaffQualificationLevel::DOCTORATE,
            'qualification_name' => 'Doctorate',
            'institution' => 'University of Cape Town',
        ], false);

        $this->assertSame(2, StaffQualification::where('user_id', $user->id)->count(), 'A lecturer still holds many qualifications.');
    }

    // ============================================== supporting documents

    public function test_a_lecturer_can_upload_a_document_and_it_is_stored_privately(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'cv', 'file' => $this->pdf('cv.pdf')]],
        ]))->assertRedirect();

        $user = User::where('email', 'lecturer@example.com')->firstOrFail();
        $document = StaffDocument::where('user_id', $user->id)->firstOrFail();

        $this->assertSame('cv', $document->category);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertGreaterThan(0, $document->size_bytes);
        $this->assertStringStartsWith("{$this->school}/{$user->id}/", $document->storage_key);

        // The file is on disk outside the web root, under a random key.
        $path = StaffDocumentStorage::path($document->storage_key);
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertDoesNotMatchRegularExpression('#'.preg_quote(StaffDocumentStorage::root(), '#').'\.\./#', $path);
        $this->assertStringNotContainsString('public', str_replace('\\', '/', $path));
    }

    public function test_a_lecturer_can_upload_several_documents_in_one_submission(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [
                0 => ['category' => 'cv', 'file' => $this->pdf('cv.pdf')],
                1 => ['category' => 'academic_certificate', 'file' => $this->pdf('degree.pdf')],
                2 => ['category' => 'national_id', 'file' => $this->pdf('passport.pdf')],
            ],
        ]))->assertRedirect();

        $user = User::where('email', 'lecturer@example.com')->firstOrFail();
        $this->assertSame(3, StaffDocument::where('user_id', $user->id)->count());

        $categories = StaffDocument::where('user_id', $user->id)->orderBy('id')->pluck('category')->all();
        $this->assertSame(['cv', 'academic_certificate', 'national_id'], $categories);

        // Each file got its own random key; none shares a path.
        $keys = StaffDocument::where('user_id', $user->id)->pluck('storage_key')->all();
        $this->assertCount(3, array_unique($keys));
    }

    public function test_a_jpg_and_a_png_document_are_accepted(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [
                0 => ['category' => 'national_id', 'file' => $this->jpg('passport.jpg')],
                1 => ['category' => 'other', 'file' => $this->png('scan.png')],
            ],
        ]))->assertRedirect();

        $this->assertSame(2, StaffDocument::count());
    }

    public function test_an_uploaded_certificate_becomes_the_evidence_for_the_qualification(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'academic_certificate', 'file' => $this->pdf('degree.pdf')]],
        ]))->assertRedirect();

        $user = User::where('email', 'lecturer@example.com')->firstOrFail();
        $qualification = StaffQualification::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(
            StaffDocument::where('user_id', $user->id)->value('id'),
            (int) $qualification->evidence_document_id,
            'The existing evidence_document_id link is used.'
        );
    }

    public function test_untouched_repeater_rows_are_ignored(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [
                0 => ['category' => 'cv', 'file' => $this->pdf('cv.pdf')],
                1 => ['category' => '', 'file' => ''],
                2 => ['category' => '', 'file' => ''],
            ],
        ]))->assertRedirect();

        $this->assertSame(1, StaffDocument::count());
    }

    public function test_a_document_type_without_a_file_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'cv']],
        ]))->assertSessionHasErrors('documents.0.file');
        $this->assertSame(0, StaffDocument::count());
    }

    public function test_more_documents_than_the_limit_are_refused(): void
    {
        $documents = [];
        for ($i = 0; $i < 12; $i++) {
            $documents[$i] = ['category' => 'other', 'file' => $this->pdf("file{$i}.pdf")];
        }

        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload(['documents' => $documents]))
            ->assertSessionHasErrors('documents');
        $this->assertSame(0, StaffDocument::count());
    }

    public function test_an_invalid_document_type_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'medical_record', 'file' => $this->pdf('x.pdf')]],
        ]))->assertSessionHasErrors('documents.0.category');

        $this->assertSame(0, StaffDocument::count());
        $this->assertSame(0, StaffQualification::count(), 'Nothing is created when a document is invalid.');
    }

    public function test_a_document_with_no_type_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => '', 'file' => $this->pdf('x.pdf')]],
        ]))->assertSessionHasErrors('documents.0.category');

        $this->assertSame(0, StaffDocument::count());
    }

    public function test_an_unsupported_file_type_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'cv', 'file' => $this->textFile('notes.txt')]],
        ]))->assertSessionHasErrors('documents.0.file');

        $this->assertStringContainsString('PDF, JPG or PNG', session('errors')->first('documents.0.file'));
        $this->assertSame(0, StaffDocument::count());
    }

    public function test_a_file_that_disguises_its_type_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'cv', 'file' => $this->textFileNamed('cv.pdf')]],
        ]))->assertSessionHasErrors('documents.0.file');
        $this->assertSame(0, StaffDocument::count());
    }

    public function test_an_oversized_document_is_rejected(): void
    {
        config(['piie.staff_documents.max_kb' => 8]);
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'cv', 'file' => $this->pdf('big.pdf', 40 * 1024)]],
        ]))->assertSessionHasErrors('documents.0.file');

        $this->assertStringContainsString('MB', session('errors')->first('documents.0.file'));
        $this->assertSame(0, StaffDocument::count());
    }

    public function test_a_document_cannot_be_reached_at_a_public_predictable_url(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'cv', 'file' => $this->pdf('cv.pdf')]],
        ]))->assertRedirect();

        $document = StaffDocument::firstOrFail();
        $key = $document->storage_key;

        // Nothing serves the file: it is not under public/, and the only route
        // that reads it resolves the record by database id.
        $this->assertFileDoesNotExist(public_path($key));
        $this->assertFileDoesNotExist(storage_path('app/public/'.$key));
        $this->assertSame(['storage_key'], (new \ReflectionClass(StaffDocument::class))->getDefaultProperties()['hidden']);
    }

    public function test_a_document_downloads_for_the_own_school_and_is_denied_across_tenants(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [0 => ['category' => 'cv', 'file' => $this->pdf('cv.pdf')]],
        ]))->assertRedirect();
        $document = StaffDocument::firstOrFail();

        $response = $this->actingAs($this->admin)->get(route('admin.staff.documents.download', $document->id));
        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Another institution's administrator gets a plain 404: no name, no
        // metadata, no file.
        $otherSchool = $this->makeSchool();
        $otherAdmin = User::factory()->create([
            'name' => 'Other HR', 'role_id' => 2, 'school_id' => $otherSchool,
            'account_status' => 'active', 'staff_status' => 'active',
        ]);
        $this->actingAs($otherAdmin)->get(route('admin.staff.documents.download', $document->id))->assertNotFound();
        $this->assertFalse(StaffDocument::where('school_id', $otherSchool)->exists());
    }

    public function test_a_failed_creation_leaves_no_orphaned_private_file(): void
    {
        Mail::fake();
        // A duplicate email fails after the document has been written.
        User::factory()->create(['email' => 'lecturer@example.com', 'school_id' => $this->school]);

        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'documents' => [
                0 => ['category' => 'cv', 'file' => $this->pdf('cv.pdf')],
                1 => ['category' => 'other', 'file' => $this->pdf('other.pdf')],
            ],
        ]))->assertRedirect();

        $this->assertSame(0, StaffDocument::count());
        $this->assertSame([], $this->privateFiles(), 'No file survives a failed creation.');
    }

    // ================================================= role-aware form

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAcademicType')]
    public function test_a_non_lecturer_form_keeps_the_common_fields_and_omits_the_academic_ones(string $type): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.staff.create', $type))->assertOk()->getContent();

        foreach (['Personal Information', 'Contact Information', 'Employment Information', 'Next of Kin', 'Portal Access'] as $common) {
            $this->assertStringContainsString($common, $html, "The common sections stay for {$type}.");
        }
        foreach (['id="sec-academic"', 'id="sec-documents"', 'name="qualification_level"', 'name="field_of_study"', 'name="institution"'] as $lecturerOnly) {
            $this->assertStringNotContainsString($lecturerOnly, $html, "{$lecturerOnly} is not forced on {$type}.");
        }
    }

    public static function nonAcademicType(): array
    {
        return [
            'admin' => ['admin'],
            'accountant' => ['accountant'],
            'librarian' => ['librarian'],
            'warden' => ['warden'],
            'other staff' => ['staff'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAcademicType')]
    public function test_a_non_lecturer_is_still_created_without_any_academic_data(string $type): void
    {
        Mail::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', $type), $this->payload(['email' => "{$type}@example.com"]))
            ->assertRedirect();

        $user = User::where('email', "{$type}@example.com")->firstOrFail();
        $this->assertNotNull($user);
        $this->assertSame(0, StaffQualification::where('user_id', $user->id)->count());
        $this->assertSame(0, StaffDocument::where('user_id', $user->id)->count());
    }

    public function test_a_teacher_type_shares_the_lecturer_role_and_academic_section(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.staff.create', 'teacher'))->assertOk()->getContent();
        $this->assertStringContainsString('id="sec-academic"', $html);
        $this->assertStringContainsString('id="sec-documents"', $html);
    }

    // ============================================ regressions from step NOK

    public function test_the_date_of_birth_is_still_blank_by_default(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.staff.create', 'lecturer'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="birthday"[^>]*value=""/', $html);
        $this->assertStringNotContainsString('value="'.date('m/d/Y').'"', $html);
        $this->assertStringNotContainsString('value="'.date('Y-m-d').'"', $html);
    }

    public function test_the_next_of_kin_is_still_required_for_every_role(): void
    {
        foreach (['emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_email', 'emergency_contact_phone'] as $field) {
            $payload = $this->payload(['email' => "nok.{$field}@example.com"]);
            unset($payload[$field]);
            $this->actingAs($this->admin)
                ->post(route('admin.staff.create.store', 'warden'), $payload)
                ->assertSessionHasErrors($field);
        }
    }

    public function test_the_entered_values_are_still_preserved_after_a_validation_failure(): void
    {
        $this->from(route('admin.staff.create', 'lecturer'))
            ->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
                'first_name' => 'Kept',
                'title' => 'Dr',
                'field_of_study' => 'Public Health',
                'emergency_contact_email' => 'broken',
            ]))
            ->assertRedirect(route('admin.staff.create', 'lecturer'))
            ->assertSessionHasInput('first_name', 'Kept')
            ->assertSessionHasInput('title', 'Dr')
            ->assertSessionHasInput('field_of_study', 'Public Health');
    }

    public function test_the_staff_account_access_workflow_is_unchanged_and_no_email_is_sent(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'staff'), $this->payload(['email' => 'generic@example.com']))->assertRedirect();

        // Creation itself sends nothing: the administrator chooses to send a link.
        Mail::assertNothingSent();

        $user = User::where('email', 'generic@example.com')->firstOrFail();
        $this->assertSame('active', $user->account_status);
        $this->assertSame(20, (int) $user->role_id);
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $user->id))->assertOk();
    }

    public function test_the_staff_directory_and_the_created_profile_still_load(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload())->assertRedirect();
        $user = User::where('email', 'lecturer@example.com')->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()->assertSee('lecturer@example.com');
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.show', $user->id))->assertOk();
    }

    public function test_the_existing_test_registrar_is_untouched(): void
    {
        $registrar = User::factory()->create([
            'name' => 'Test Registrar', 'email' => 'registrar@school.test', 'role_id' => 20,
            'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active',
            'code' => 'STF-2026-5734-2619',
        ]);
        $before = (array) $registrar->fresh();
        $profilesBefore = StaffProfile::where('user_id', $registrar->id)->count();

        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload())->assertRedirect();

        $this->assertSame($before, (array) $registrar->fresh(), 'No field of the Test Registrar changed.');
        $this->assertSame($profilesBefore, StaffProfile::where('user_id', $registrar->id)->count());
        $this->assertSame('Test Registrar', $registrar->fresh()->name);
    }

    // ============================================ success / next step UX

    public function test_the_success_screen_names_the_role_the_staff_number_and_setup_required(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload())
            ->assertRedirect(route('admin.staff.created'))
            ->assertSessionHas('staff_created_id');

        $user = User::where('email', 'lecturer@example.com')->firstOrFail();
        $html = $this->actingAs($this->admin)->get(route('admin.staff.created'))->getContent();

        $this->assertStringContainsString('Lecturer', $html);
        $this->assertStringContainsString($user->code, $html, 'The staff number is shown.');
        $this->assertMatchesRegularExpression('/Setup\s*required/i', $html);

        // The three next actions, reusing the existing account-access workflow.
        $this->assertStringContainsString(route('admin.rbac.staff.account-access', $user->id), $html);
        $this->assertStringContainsString(route('admin.rbac.staff.show', $user->id), $html);
        $this->assertStringContainsString(route('admin.rbac.staff.index'), $html);

        // Nothing was emailed on the way.
        Mail::assertNothingSent();
    }

    public function test_the_success_screen_is_one_shot_and_never_exposes_another_staff_record(): void
    {
        Mail::fake();
        $other = User::factory()->create(['name' => 'Someone Else', 'email' => 'other@school.test', 'role_id' => 3, 'school_id' => $this->school]);

        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'librarian'), $this->payload(['email' => 'lib@example.com']))
            ->assertRedirect(route('admin.staff.created'));
        $this->actingAs($this->admin)->get(route('admin.staff.created'))->assertOk()->assertSee('lib@example.com');

        // A second visit has nothing to show and returns to the launcher, so the
        // screen cannot be walked to any other staff record.
        $this->actingAs($this->admin)->get(route('admin.staff.created'))->assertRedirect(route('admin.staff.add'));
        $this->actingAs($this->admin)->get(route('admin.staff.created'))->assertRedirect(route('admin.staff.add'));
        $this->assertStringNotContainsString('Someone Else', $this->actingAs($this->admin)->get(route('admin.staff.add'))->getContent());
        $this->assertNotNull($other->fresh());
    }

    public function test_the_success_screen_of_another_tenants_staff_is_not_reachable(): void
    {
        $otherSchool = $this->makeSchool();
        $otherStaff = User::factory()->create(['name' => 'Foreign Lecturer', 'email' => 'foreign@other.test', 'role_id' => 3, 'school_id' => $otherSchool]);
        session(['staff_created_id' => $otherStaff->id]);

        $this->actingAs($this->admin)->get(route('admin.staff.created'))->assertNotFound();
    }

    // ======================================================= helpers

    /**
     * The real professional-record schema, built by the same migrations that
     * produced piie_main, in the same order. The title, the qualification, the
     * registration and the documents are therefore exercised against the actual
     * columns rather than a hand-rolled approximation.
     */
    private function createStaffTables(): void
    {
        (require base_path('database/migrations/2026_09_24_000001_create_staff_professional_records_tables.php'))->up();
        (require base_path('database/migrations/2026_09_28_000002_add_next_of_kin_email_to_staff_profiles.php'))->up();

        $this->assertTrue(Schema::hasTable('staff_qualifications'));
        $this->assertTrue(Schema::hasTable('staff_professional_registrations'));
        $this->assertTrue(Schema::hasTable('staff_documents'));
        $this->assertTrue(Schema::hasColumn('staff_profiles', 'title'));
        $this->assertTrue(Schema::hasColumn('staff_profiles', 'emergency_contact_email'));
    }

    private function profileOf(string $email): StaffProfile
    {
        return StaffProfile::where('user_id', User::where('email', $email)->value('id'))->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Common',
            'last_name' => 'Staffer',
            'title' => 'Mr',
            'gender' => 'Female',
            'birthday' => '',
            'email' => 'common@example.com',
            'phone' => '+256 712 000 111',
            'designation_id' => DB::table('designations')->where('school_id', $this->school)->value('id'),
            'department_id' => DB::table('departments')->where('school_id', $this->school)->value('id'),
            'employment_type' => 'Full Time',
            'staff_status' => 'active',
            'emergency_contact_name' => 'Grace Nok',
            'emergency_contact_relationship' => 'Sibling',
            'emergency_contact_email' => 'nok@example.com',
            'emergency_contact_phone' => '+256 712 345 678',
        ], $overrides);
    }

    private function lecturerPayload(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'first_name' => 'Grace',
            'last_name' => 'Lecturer',
            'title' => 'Dr',
            'email' => 'lecturer@example.com',
            'qualification_level' => "Master's Degree",
            'field_of_study' => 'Software Engineering',
            'institution' => 'Makerere University',
        ], $overrides));
    }

    private function pdf(string $name, int $bytes = 0): UploadedFile
    {
        $body = "%PDF-1.4\n".str_repeat('A', max($bytes, 512));

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function jpg(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xFF\xD8\xFF".str_repeat('B', 512));
    }

    private function png(string $name): UploadedFile
    {
        // A real 1x1 PNG: the private store sniffs the content, and a file with
        // only the 8-byte signature is not a PNG to finfo.
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        ));
    }

    private function textFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'plain text, not a document');
    }

    private function textFileNamed(string $name): UploadedFile
    {
        return $this->textFile($name);
    }

    /** The real private document root, so the assertions test the real store. */
    private function useRealPrivateStore(): void
    {
        config(['piie.staff_documents.root' => storage_path('app/staff-documents')]);
        File::ensureDirectoryExists(StaffDocumentStorage::root());
    }

    private function purgePrivateStore(): void
    {
        $root = StaffDocumentStorage::root();
        if (! is_dir($root)) {
            return;
        }
        foreach (glob($root.'/*/*/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @return string[] */
    private function privateFiles(): array
    {
        return glob(StaffDocumentStorage::root().'/*/*/*') ?: [];
    }
}
