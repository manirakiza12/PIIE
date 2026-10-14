<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminMiddleware;
use App\Mail\NewUserEmail;
use App\Models\StaffDocument;
use App\Models\StaffExperience;
use App\Models\StaffProfessionalRegistration;
use App\Models\StaffProfile;
use App\Models\StaffQualification;
use App\Models\User;
use App\Support\Permissions\PermissionAssignmentService;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Staff\StaffDirectory;
use App\Support\Staff\StaffDocumentStorage;
use App\Support\Staff\StaffNin;
use App\Support\Staff\StaffProvisioningService;
use App\Support\Staff\StaffRecordException;
use App\Support\Staff\StaffRecordService;
use App\Support\Staff\StaffStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Staff Management backend foundation (Steps 2–4): additive schema, the
 * professional records (profile, qualifications, registrations, experience,
 * protected documents), NIN protection, granular permissions, tenant isolation
 * and transactional professional provisioning.
 */
class StaffProfessionalRecordsTest extends TestCase
{
    use StaffModuleTestHelper;

    private const MIGRATION = 'database/migrations/2026_09_24_000001_create_staff_professional_records_tables.php';
    private const NIN = 'CM90012345ABCD';

    private int $schoolA;
    private int $schoolB;
    private User $adminA;
    private User $adminB;
    private User $teacherA;
    private User $teacherB;
    private StaffRecordService $records;
    private string $docRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require base_path('database/migrations/2026_09_23_000004_add_is_active_to_staff_roles.php'))->up();
        (require base_path(self::MIGRATION))->up();

        // Private document root for this test only (never the real storage/app/staff-documents).
        $this->docRoot = storage_path('framework/testing/staff-documents-' . uniqid());
        config(['piie.staff_documents.root' => $this->docRoot]);

        $this->schoolA = $this->makeSchool(['title' => 'School A']);
        $this->schoolB = $this->makeSchool(['title' => 'School B']);
        $this->adminA = $this->user(2, $this->schoolA);
        $this->adminB = $this->user(2, $this->schoolB);
        $this->teacherA = $this->user(3, $this->schoolA, ['name' => 'Tina Teacher', 'code' => 'STF-2020-1111-2222']);
        $this->teacherB = $this->user(3, $this->schoolB, ['name' => 'Bob Foreign']);
        $this->records = app(StaffRecordService::class);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->docRoot);
        parent::tearDown();
    }

    private function user(int $role, int $school, array $extra = []): User
    {
        return User::factory()->create($extra + ['role_id' => $role, 'school_id' => $school, 'account_status' => 'active', 'staff_status' => 'active']);
    }

    private function delegate(array $permissions): User
    {
        $user = $this->user(17, $this->schoolA);
        $this->actingAs($this->adminA);
        app(PermissionAssignmentService::class)->grantMany($this->adminA, $user, $permissions);

        return $user->fresh();
    }

    private function pdf(string $name = 'cv.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function document(User $owner, User $admin, string $category = 'cv'): StaffDocument
    {
        $this->actingAs($admin);

        return $this->records->uploadDocument($admin, $owner, $this->pdf(), $category);
    }

    private function expectRefused(callable $attempt, string $exception): void
    {
        try {
            $attempt();
            $this->fail("expected {$exception}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e, $e->getMessage());
        }
    }

    // ── Schema ──────────────────────────────────────────────────────────────

    public function test_migration_is_additive_reversible_and_leaves_existing_staff_untouched(): void
    {
        $usersColumns = Schema::getColumnListing('users');
        $before = DB::table('users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $migration = require base_path(self::MIGRATION);
        $migration->down();
        foreach (['staff_profiles', 'staff_qualifications', 'staff_professional_registrations', 'staff_experiences', 'staff_documents'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $migration->up();
        $migration->up();   // idempotent
        foreach (['staff_profiles', 'staff_qualifications', 'staff_professional_registrations', 'staff_experiences', 'staff_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumn($table, 'school_id'), "{$table}.school_id");
        }

        $this->assertSame($usersColumns, Schema::getColumnListing('users'), 'users table structure unchanged');
        $this->assertEquals($before, DB::table('users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'staff rows, role ids, codes unchanged');
        $this->assertSame('STF-2020-1111-2222', $this->teacherA->fresh()->code);
        $this->assertSame(3, (int) $this->teacherA->fresh()->role_id);
    }

    public function test_existing_staff_work_without_a_profile(): void
    {
        $teacher = $this->teacherA->fresh();
        $this->assertNull($teacher->staffProfile);
        foreach (['staffQualifications', 'staffProfessionalRegistrations', 'staffExperiences', 'staffDocuments'] as $relation) {
            $this->assertCount(0, $teacher->{$relation});
        }
        $this->actingAs($this->adminA);
        $this->assertNull($this->records->profileOf($this->adminA, $teacher));
        $this->assertSame('Not provided', $this->records->ninDisplay($this->adminA, $teacher));
        $this->assertFalse($teacher->isStaffPortalBlocked());

        // A profile can be added later WITHOUT a NIN (historical staff).
        $profile = $this->records->saveProfile($this->adminA, $teacher, ['title' => 'Ms', 'city' => 'Kampala']);
        $this->assertFalse($profile->hasNin());
        $this->assertSame('Kampala', $teacher->fresh()->staffProfile->city);
    }

    public function test_a_user_holds_zero_to_many_qualifications_registrations_experiences_and_documents(): void
    {
        $this->actingAs($this->adminA);
        $t = $this->teacherA;
        foreach (['Bachelor of IT', 'Master of Computing'] as $i => $name) {
            $this->records->addQualification($this->adminA, $t, ['qualification_level' => $i ? 'Masters' : 'Bachelors', 'qualification_name' => $name,
                'institution' => 'Makerere University', 'country' => 'Uganda', 'start_year' => 2018 + $i * 4, 'completion_year' => 2021 + $i * 4]);
            $this->records->addRegistration($this->adminA, $t, ['professional_body' => "Body {$i}", 'registration_number' => "R-{$i}"]);
            $this->records->addExperience($this->adminA, $t, ['employer' => "Employer {$i}", 'position' => 'Lecturer', 'start_date' => '2019-01-01', 'end_date' => '2020-01-01']);
            $this->document($t, $this->adminA, $i ? 'academic_certificate' : 'cv');
        }

        $t = $t->fresh();
        $this->assertCount(2, $t->staffQualifications);
        $this->assertCount(2, $t->staffProfessionalRegistrations);
        $this->assertCount(2, $t->staffExperiences);
        $this->assertCount(2, $t->staffDocuments);
        $this->assertSame(0, StaffQualification::where('user_id', $this->teacherB->id)->count());
    }

    // ── Validation ──────────────────────────────────────────────────────────

    public function test_dates_and_years_must_be_in_logical_order(): void
    {
        $this->actingAs($this->adminA);
        $q = ['qualification_level' => 'Bachelors', 'qualification_name' => 'BIT', 'institution' => 'MUK'];

        $this->expectRefused(fn () => $this->records->addQualification($this->adminA, $this->teacherA, $q + ['start_year' => 2021, 'completion_year' => 2018]), StaffRecordException::class);
        $this->records->addQualification($this->adminA, $this->teacherA, $q + ['completion_year' => 2018]);            // start optional
        $this->records->addQualification($this->adminA, $this->teacherA, $q + ['start_year' => 2018, 'completion_year' => 2018]);

        $this->expectRefused(fn () => $this->records->addRegistration($this->adminA, $this->teacherA, ['professional_body' => 'X', 'issue_date' => '2024-01-01', 'expiry_date' => '2023-01-01']), StaffRecordException::class);
        $this->records->addRegistration($this->adminA, $this->teacherA, ['professional_body' => 'X', 'expiry_date' => '2030-01-01']);

        $e = ['employer' => 'E', 'position' => 'P', 'start_date' => '2020-05-01'];
        $this->expectRefused(fn () => $this->records->addExperience($this->adminA, $this->teacherA, $e + ['end_date' => '2019-01-01']), StaffRecordException::class);
        $current = $this->records->addExperience($this->adminA, $this->teacherA, $e + ['currently_working' => true]);
        $this->assertTrue($current->currently_working);
        $this->assertNull($current->end_date);

        $this->expectRefused(fn () => $this->records->addQualification($this->adminA, $this->teacherA, ['institution' => 'MUK']), StaffRecordException::class);
        $this->assertSame(2, StaffQualification::count());
    }

    // ── NIN ─────────────────────────────────────────────────────────────────

    public function test_nin_is_normalized_encrypted_and_hashed_with_a_keyed_hmac(): void
    {
        $this->actingAs($this->adminA);
        $profile = $this->records->saveProfile($this->adminA, $this->teacherA, ['nin' => ' cm-9001 2345 abcd ']);
        $raw = DB::table('staff_profiles')->where('id', $profile->id)->first();

        $this->assertStringNotContainsString(self::NIN, $raw->nin_encrypted);
        $this->assertStringNotContainsString('90012345', $raw->nin_encrypted);
        $this->assertSame(self::NIN, StaffNin::decrypt($profile->fresh()));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $raw->nin_hash);
        $this->assertSame(StaffNin::hash('CM 9001-2345-ABCD'), $raw->nin_hash, 'normalized before hashing');
        $this->assertNotSame(hash('sha256', self::NIN), $raw->nin_hash, 'keyed, not a plain hash');
        $this->assertSame('**********ABCD', StaffNin::masked($profile->fresh()));
    }

    public function test_duplicate_nin_is_refused_within_a_school_without_echoing_it(): void
    {
        $this->actingAs($this->adminA);
        $this->records->saveProfile($this->adminA, $this->teacherA, ['nin' => self::NIN]);
        $other = $this->user(4, $this->schoolA);

        try {
            $this->records->saveProfile($this->adminA, $other, ['nin' => 'cm90012345abcd']);
            $this->fail('duplicate NIN accepted');
        } catch (StaffRecordException $e) {
            $this->assertStringNotContainsString('90012345', $e->getMessage() . json_encode($e->errors()));
        }
        $this->assertNull(StaffProfile::where('user_id', $other->id)->value('nin_hash'));

        // The same person may be recorded in another school (school-scoped rule).
        $this->actingAs($this->adminB);
        $this->records->saveProfile($this->adminB, $this->teacherB, ['nin' => self::NIN]);
        $this->assertTrue($this->teacherB->fresh()->staffProfile->hasNin());

        try {
            $this->records->saveProfile($this->adminA, $other, ['nin' => 'abc']);
            $this->fail('invalid NIN accepted');
        } catch (StaffRecordException $e) {
            $this->assertStringNotContainsString('abc', strtolower($e->getMessage()));
        }
    }

    public function test_full_nin_never_leaks_into_serialization_audit_or_directory(): void
    {
        $this->actingAs($this->adminA);
        $profile = $this->records->saveProfile($this->adminA, $this->teacherA, ['nin' => self::NIN, 'emergency_contact_name' => 'Next Of Kin']);
        $this->records->revealNin($this->adminA, $this->teacherA);
        $encrypted = DB::table('staff_profiles')->where('id', $profile->id)->value('nin_encrypted');
        $hash = DB::table('staff_profiles')->where('id', $profile->id)->value('nin_hash');

        $outputs = [
            json_encode($profile->fresh()),
            json_encode($this->teacherA->fresh()->load('staffProfile')),
            json_encode(DB::table('audit_logs')->get()),
            json_encode(StaffDirectory::query($this->adminA)->get()),
        ];
        foreach ($outputs as $i => $output) {
            foreach ([self::NIN, $encrypted, $hash, 'nin_encrypted', 'nin_hash'] as $secret) {
                $this->assertStringNotContainsString($secret, $output, "output {$i}");
            }
        }
        $this->assertStringNotContainsString('Next Of Kin', $outputs[3], 'directory has no emergency contact');
        $this->assertTrue(DB::table('audit_logs')->where('action', 'STAFF_NIN_RECORDED')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'STAFF_NIN_VIEWED')->exists());
    }

    public function test_nin_view_and_manage_need_their_own_permissions(): void
    {
        $this->actingAs($this->adminA);
        $this->records->saveProfile($this->adminA, $this->teacherA, ['nin' => self::NIN]);

        $viewer = $this->delegate(['staff.view', 'staff.edit']);
        $this->assertSame('NIN recorded', $this->records->ninDisplay($viewer, $this->teacherA));
        $this->expectRefused(fn () => $this->records->revealNin($viewer, $this->teacherA), AuthorizationException::class);
        $this->expectRefused(fn () => $this->records->saveProfile($viewer, $this->teacherA, ['nin' => 'ZZ12345678']), AuthorizationException::class);
        $this->records->saveProfile($viewer, $this->teacherA, ['city' => 'Gulu']);   // ordinary edit still fine
        $this->assertSame(self::NIN, StaffNin::decrypt($this->teacherA->fresh()->staffProfile));

        $ninOfficer = $this->delegate(['staff.nin.view', 'staff.nin.manage']);
        $this->assertSame('**********ABCD', $this->records->ninDisplay($ninOfficer, $this->teacherA));
        $this->assertSame(self::NIN, $this->records->revealNin($ninOfficer, $this->teacherA));
        $this->records->saveProfile($this->adminA, $this->teacherA, ['nin' => 'ZZ12345678']);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'STAFF_NIN_REPLACED')->exists());
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    public function test_new_staff_permissions_are_sensitive_and_never_implied_by_staff_view(): void
    {
        $keys = ['staff.documents.view', 'staff.documents.upload', 'staff.documents.verify', 'staff.qualifications.verify',
            'staff.nin.view', 'staff.nin.manage', 'staff.export', 'staff.audit.view'];
        $viewer = $this->delegate(['staff.view']);
        $perms = app(\App\Support\Permissions\PermissionService::class);

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, PermissionRegistry::permissions());
            $this->assertTrue($perms->isSensitive($key), $key);
            $this->assertFalse($perms->allows($viewer, $key), "staff.view must not imply {$key}");
            $this->assertTrue($perms->allows($this->adminA, $key));
            $this->assertFalse($perms->allows($this->teacherA, $key), "teacher base role must not hold {$key}");
            $this->assertFalse($perms->allows($this->user(15, $this->schoolA), $key), "HR base role gets {$key} only by explicit grant");
        }
    }

    // ── Documents ───────────────────────────────────────────────────────────

    public function test_documents_are_stored_privately_with_random_keys(): void
    {
        $doc = $this->document($this->teacherA, $this->adminA);
        $raw = DB::table('staff_documents')->where('id', $doc->id)->first();
        $path = StaffDocumentStorage::path($raw->storage_key);

        $this->assertFileExists($path);
        $this->assertStringStartsWith($this->docRoot, $path);
        $this->assertStringStartsNotWith(public_path(), realpath($path));
        $this->assertStringStartsNotWith(public_path(), StaffDocumentStorage::root() === $this->docRoot ? storage_path('app/staff-documents') : '');
        $this->assertMatchesRegularExpression('#^' . $this->schoolA . '/' . $this->teacherA->id . '/[a-f0-9]{40}\.pdf$#', $raw->storage_key);
        $this->assertSame('cv.pdf', $raw->original_name);
        $this->assertSame('application/pdf', $raw->mime_type);
        $this->assertArrayNotHasKey('storage_key', $doc->toArray());
        $this->assertStringNotContainsString($raw->storage_key, json_encode(DB::table('audit_logs')->get()));
    }

    public function test_upload_rejects_bad_types_disguises_mismatched_content_oversize_and_traversal(): void
    {
        $this->actingAs($this->adminA);
        $upload = fn (UploadedFile $f) => $this->records->uploadDocument($this->adminA, $this->teacherA, $f, 'cv');

        $this->expectRefused(fn () => $upload(UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')), StaffRecordException::class);
        $this->expectRefused(fn () => $upload(UploadedFile::fake()->createWithContent('cv.php.pdf', "%PDF-1.4\n%%EOF")), StaffRecordException::class);
        $this->expectRefused(fn () => $upload(UploadedFile::fake()->createWithContent('cv.pdf', '<?php system($_GET[1]);')), StaffRecordException::class);
        $this->expectRefused(fn () => $upload(UploadedFile::fake()->createWithContent('photo.png', "%PDF-1.4\n%%EOF")), StaffRecordException::class);
        $this->expectRefused(fn () => $upload(UploadedFile::fake()->createWithContent('page.html', '<html></html>')), StaffRecordException::class);
        $this->expectRefused(fn () => $this->records->uploadDocument($this->adminA, $this->teacherA, $this->pdf(), 'passwords'), StaffRecordException::class);

        config(['piie.staff_documents.max_kb' => 1]);
        $this->expectRefused(fn () => $upload(UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n" . str_repeat('A', 4096))), StaffRecordException::class);
        config(['piie.staff_documents.max_kb' => StaffDocumentStorage::DEFAULT_MAX_KB]);
        $this->assertSame(0, StaffDocument::count(), 'nothing stored for rejected files');

        // Path traversal in the client name is reduced to a harmless basename; the key stays server-generated.
        $doc = $upload(UploadedFile::fake()->createWithContent('../../../evil.pdf', "%PDF-1.4\n%%EOF"));
        $this->assertSame('evil.pdf', $doc->original_name);
        $this->assertNull(StaffDocumentStorage::path('../../.env'));
        $this->assertNull(StaffDocumentStorage::path($this->schoolA . '/' . $this->teacherA->id . '/../../x.pdf'));

        $png = $upload(UploadedFile::fake()->image('scan.png'));
        $jpg = $upload(UploadedFile::fake()->image('scan.jpg'));
        $this->assertSame(['image/png', 'image/jpeg'], [$png->mime_type, $jpg->mime_type]);
    }

    public function test_document_download_requires_permission_and_same_school(): void
    {
        $doc = $this->document($this->teacherA, $this->adminA);
        $url = route('admin.staff.documents.download', $doc->id);

        $ok = $this->actingAs($this->adminA)->get($url)->assertOk();
        $this->assertStringContainsString('attachment', $ok->headers->get('content-disposition'));
        $this->assertSame('nosniff', $ok->headers->get('x-content-type-options'));
        $this->assertStringStartsWith('%PDF-', file_get_contents($ok->baseResponse->getFile()->getPathname()));

        $this->assertSame(403, $this->actingAs($this->delegate(['staff.view', 'staff.edit']))->get($url)->getStatusCode());
        $this->assertSame(403, $this->actingAs($this->teacherA)->get($url)->getStatusCode(), 'even the document owner needs the permission');
        $this->actingAs($this->delegate(['staff.documents.view']))->get($url)->assertOk();
        foreach ([$this->user(7, $this->schoolA), $this->user(6, $this->schoolA)] as $outsider) {
            $this->assertNotSame(200, $this->actingAs($outsider)->get($url)->getStatusCode());
        }
        $this->assertTrue(DB::table('audit_logs')->where('action', 'STAFF_DOCUMENT_DOWNLOADED')->exists());

        auth()->logout();
        $this->assertNotSame(200, $this->get($url)->getStatusCode());
        $this->get('/storage/staff-documents/' . $doc->fresh()->storage_key)->assertNotFound();
    }

    public function test_verification_needs_its_own_permission_and_is_audited(): void
    {
        $doc = $this->document($this->teacherA, $this->adminA);
        $this->actingAs($this->adminA);
        $qual = $this->records->addQualification($this->adminA, $this->teacherA, ['qualification_level' => 'Bachelors', 'qualification_name' => 'BIT', 'institution' => 'MUK', 'evidence_document_id' => $doc->id]);
        $this->assertSame($doc->id, $qual->evidence_document_id);

        $viewer = $this->delegate(['staff.view', 'staff.documents.view', 'staff.edit']);
        $this->expectRefused(fn () => $this->records->verifyDocument($viewer, $doc->id, 'verified'), AuthorizationException::class);
        $this->expectRefused(fn () => $this->records->verifyQualification($viewer, $qual->id, 'verified'), AuthorizationException::class);

        $verifier = $this->delegate(['staff.documents.verify', 'staff.qualifications.verify']);
        $this->assertSame('verified', $this->records->verifyDocument($verifier, $doc->id, 'verified', 'Checked original')->verification_status);
        $this->assertSame('rejected', $this->records->verifyQualification($verifier, $qual->id, 'rejected')->verification_status);
        $this->assertSame($verifier->id, (int) $doc->fresh()->verified_by);
        $this->expectRefused(fn () => $this->records->verifyDocument($verifier, $doc->id, 'approved!'), StaffRecordException::class);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'STAFF_DOCUMENT_VERIFIED')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'STAFF_QUALIFICATION_VERIFIED')->exists());
    }

    // ── Tenant isolation & ID substitution ──────────────────────────────────

    public function test_school_a_cannot_reach_any_school_b_staff_record_by_id(): void
    {
        $this->actingAs($this->adminB);
        $this->records->saveProfile($this->adminB, $this->teacherB, ['nin' => self::NIN, 'city' => 'Mbarara']);
        $docB = $this->records->uploadDocument($this->adminB, $this->teacherB, $this->pdf('secret-contract.pdf'), 'employment_contract');
        $qualB = $this->records->addQualification($this->adminB, $this->teacherB, ['qualification_level' => 'PhD', 'qualification_name' => 'PhD', 'institution' => 'X']);
        $regB = $this->records->addRegistration($this->adminB, $this->teacherB, ['professional_body' => 'Secret Body']);
        $expB = $this->records->addExperience($this->adminB, $this->teacherB, ['employer' => 'Secret Employer', 'position' => 'P', 'start_date' => '2020-01-01']);

        $this->actingAs($this->adminA);
        $a = $this->adminA;
        foreach ([
            fn () => $this->records->staffInSchool($a, $this->teacherB->id),
            fn () => $this->records->profileOf($a, $this->teacherB),
            fn () => $this->records->revealNin($a, $this->teacherB),
            fn () => $this->records->saveProfile($a, $this->teacherB, ['city' => 'Hijacked']),
            fn () => $this->records->addQualification($a, $this->teacherB, ['qualification_level' => 'X', 'qualification_name' => 'X', 'institution' => 'X']),
            fn () => $this->records->addRegistration($a, $this->teacherB, ['professional_body' => 'X']),
            fn () => $this->records->addExperience($a, $this->teacherB, ['employer' => 'X', 'position' => 'X', 'start_date' => '2020-01-01']),
            fn () => $this->records->uploadDocument($a, $this->teacherB, $this->pdf(), 'cv'),
            fn () => $this->records->verifyDocument($a, $docB->id, 'verified'),
            fn () => $this->records->verifyQualification($a, $qualB->id, 'verified'),
            fn () => $this->records->documentForDownload($a, $docB->id),
        ] as $i => $attempt) {
            $this->expectRefused($attempt, ModelNotFoundException::class);
        }

        // ID substitution over HTTP: same 404 as a missing id, with nothing revealed.
        $response = $this->actingAs($a)->get(route('admin.staff.documents.download', $docB->id));
        $response->assertNotFound();
        $missing = $this->actingAs($a)->get(route('admin.staff.documents.download', 999999));
        $this->assertSame($missing->getStatusCode(), $response->getStatusCode());
        foreach (['secret-contract', 'employment_contract', $docB->fresh()->storage_key, 'Bob Foreign', '%PDF'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
        }

        // A School A qualification can never cite a School B document as evidence.
        $this->expectRefused(fn () => $this->records->addQualification($a, $this->teacherA, ['qualification_level' => 'X', 'qualification_name' => 'X', 'institution' => 'X', 'evidence_document_id' => $docB->id]), StaffRecordException::class);

        // School B untouched.
        $this->assertSame('Mbarara', $this->teacherB->fresh()->staffProfile->city);
        $this->assertSame('pending', $docB->fresh()->verification_status);
        $this->assertSame('pending', $qualB->fresh()->verification_status);
        $this->assertSame(1, StaffProfessionalRegistration::where('user_id', $this->teacherB->id)->count());
        $this->assertSame(1, StaffExperience::where('user_id', $this->teacherB->id)->count());
        $this->assertSame(0, StaffQualification::where('user_id', $this->teacherA->id)->count());
        $this->assertNotNull($regB->fresh());
        $this->assertNotNull($expB->fresh());
    }

    public function test_records_are_only_for_staff_never_students_or_parents(): void
    {
        $this->actingAs($this->adminA);
        foreach ([$this->user(7, $this->schoolA), $this->user(6, $this->schoolA), $this->user(1, $this->schoolA)] as $outsider) {
            $this->expectRefused(fn () => $this->records->saveProfile($this->adminA, $outsider, ['city' => 'X']), ModelNotFoundException::class);
            $this->expectRefused(fn () => $this->records->staffInSchool($this->adminA, $outsider->id), ModelNotFoundException::class);
        }
    }

    // ── Transactional professional provisioning ─────────────────────────────

    private function professional(): array
    {
        return [
            ['first_name' => 'Sarah', 'last_name' => 'Nakato', 'email' => 'sarah@a.test', 'phone' => '0700', 'gender' => 'Female',
                'birthday' => '1990-01-01', 'address' => 'Kampala', 'employment_type' => 'Permanent'],
            ['nin' => self::NIN, 'title' => 'Dr', 'date_joined' => '2024-01-08', 'academic_title' => 'Senior Lecturer'],
        ];
    }

    public function test_professional_creation_commits_everything_together_then_emails(): void
    {
        $this->enableSmtpSettings();
        [$account, $profile] = $this->professional();

        $user = app(StaffProvisioningService::class)->provisionProfessional($this->adminA, 3, $account, $profile,
            [['qualification_level' => 'Bachelors', 'qualification_name' => 'BIT', 'institution' => 'MUK', 'start_year' => 2018, 'completion_year' => 2021, 'evidence_document_ref' => 'degree']],
            [['professional_body' => 'Uganda Teachers Council', 'registration_number' => 'UTC-1']],
            [['employer' => 'Old School', 'position' => 'Teacher', 'start_date' => '2019-01-01', 'currently_working' => false, 'end_date' => '2023-12-31']],
            [['category' => 'academic_certificate', 'file' => $this->pdf('degree.pdf'), 'ref' => 'degree'], ['category' => 'national_id', 'file' => UploadedFile::fake()->image('id.jpg')]]);

        $this->assertSame(3, (int) $user->role_id);
        $this->assertSame($this->schoolA, (int) $user->school_id);
        $this->assertSame(now()->format('Y/m').'/TRS/001/', $user->code);
        $this->assertSame(self::NIN, StaffNin::decrypt($user->staffProfile));
        $this->assertCount(1, $user->staffQualifications);
        $this->assertSame($user->staffDocuments->firstWhere('category', 'academic_certificate')->id, $user->staffQualifications->first()->evidence_document_id);
        $this->assertCount(1, $user->staffProfessionalRegistrations);
        $this->assertCount(1, $user->staffExperiences);
        $this->assertCount(2, $user->staffDocuments);
        Mail::assertSent(NewUserEmail::class, 1);
    }

    public function test_a_failure_inside_creation_rolls_back_everything_deletes_files_and_sends_no_email(): void
    {
        $this->enableSmtpSettings();
        [$account, $profile] = $this->professional();
        $usersBefore = User::count();

        // The evidence reference is only resolvable after the user and documents exist → fails mid-transaction.
        $this->expectRefused(fn () => app(StaffProvisioningService::class)->provisionProfessional($this->adminA, 3, $account, $profile,
            [['qualification_level' => 'X', 'qualification_name' => 'X', 'institution' => 'X', 'evidence_document_ref' => 'missing']], [], [],
            [['category' => 'cv', 'file' => $this->pdf()]]), StaffRecordException::class);

        $this->assertSame($usersBefore, User::count(), 'no user');
        foreach ([StaffProfile::class, StaffDocument::class, StaffQualification::class] as $model) {
            $this->assertSame(0, $model::count(), $model);
        }
        $this->assertSame([], File::isDirectory($this->docRoot) ? File::allFiles($this->docRoot) : [], 'stored files removed');
        Mail::assertNothingSent();

        // A duplicate NIN (detected inside the transaction) also leaves nothing behind.
        $this->actingAs($this->adminA);
        $this->records->saveProfile($this->adminA, $this->teacherA, ['nin' => self::NIN]);
        $this->expectRefused(fn () => app(StaffProvisioningService::class)->provisionProfessional($this->adminA, 3, $account, $profile), StaffRecordException::class);
        $this->assertFalse(User::where('email', 'sarah@a.test')->exists());
        Mail::assertNothingSent();
    }

    public function test_professional_creation_requires_a_nin_and_the_right_to_create_that_role(): void
    {
        [$account, $profile] = $this->professional();
        $service = app(StaffProvisioningService::class);

        $this->expectRefused(fn () => $service->provisionProfessional($this->adminA, 3, $account, ['title' => 'Dr']), StaffRecordException::class);
        $this->expectRefused(fn () => $service->provisionProfessional($this->teacherA, 3, $account, $profile), AuthorizationException::class);
        $this->expectRefused(fn () => $service->provisionProfessional($this->user(15, $this->schoolA), 2, $account, $profile), AuthorizationException::class);
        $this->assertFalse(User::where('email', 'sarah@a.test')->exists());

        // HR Manager may create the four HR-authorized base roles (initial records included).
        $user = $service->provisionProfessional($this->user(15, $this->schoolA), 4, $account, $profile);
        $this->assertSame(4, (int) $user->role_id);
        $this->assertTrue($user->staffProfile->hasNin());
    }

    // ── Employment status ───────────────────────────────────────────────────

    public function test_employment_statuses_keep_existing_semantics_and_terminated_blocks_login(): void
    {
        $portal = function (User $user): bool {
            $this->actingAs($user);
            $route = (new \Illuminate\Routing\Route('GET', 'admin/dashboard', []))->name('admin.dashboard');
            $request = Request::create('/admin/dashboard', 'GET');
            $request->setRouteResolver(fn () => $route);

            return (new AdminMiddleware())->handle($request, fn () => new \Illuminate\Http\Response('reached'))->getContent() === 'reached';
        };

        foreach ([StaffStatus::ACTIVE => true, StaffStatus::ON_LEAVE => true, StaffStatus::SUSPENDED => false, StaffStatus::INACTIVE => false, StaffStatus::TERMINATED => false] as $status => $allowed) {
            $this->assertSame($allowed, $portal($this->user(4, $this->schoolA, ['staff_status' => $status])), $status);
        }
        $this->assertTrue($portal($this->user(4, $this->schoolA, ['staff_status' => null])), 'legacy NULL never blocked');
    }

    public function test_admin_lockout_protection_treats_terminated_like_suspended(): void
    {
        $admin = $this->user(2, $this->schoolA, ['email' => 'only.admin@a.test', 'school_role' => 1]);
        $payload = fn (string $email, string $status) => ['email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'gender' => 'Male',
            'blood_group' => 'o+', 'birthday' => '01/01/1990', 'phone' => '0700', 'address' => 'x', 'staff_status' => $status];

        // An administrator can never terminate themselves (same guard as suspending/disabling).
        $this->actingAs($admin)->from('/back')->post(route('admin.update', $admin->id), $payload('only.admin@a.test', StaffStatus::TERMINATED))
            ->assertSessionHas('error', 'You cannot delete, disable or suspend your own administrator account.');
        $this->assertSame(StaffStatus::ACTIVE, $admin->fresh()->staff_status);

        // Terminating another administrator is allowed while a viable one remains; a terminated admin is then locked out.
        $this->actingAs($admin)->post(route('admin.update', $this->adminA->id), $payload($this->adminA->email, StaffStatus::TERMINATED));
        $this->assertSame(StaffStatus::TERMINATED, $this->adminA->fresh()->staff_status);
        $this->assertTrue($this->adminA->fresh()->isStaffPortalBlocked());
    }

    // ── Directory ───────────────────────────────────────────────────────────

    public function test_directory_query_is_staff_view_gated_school_scoped_and_selects_safe_columns_only(): void
    {
        $this->actingAs($this->adminA);
        $this->records->saveProfile($this->adminA, $this->teacherA, ['nin' => self::NIN, 'emergency_contact_phone' => '0799']);
        $this->user(7, $this->schoolA, ['name' => 'Stu Student']);

        $rows = StaffDirectory::query($this->adminA)->get();
        $this->assertContains('Tina Teacher', $rows->pluck('name')->all());
        $this->assertNotContains('Bob Foreign', $rows->pluck('name')->all());
        $this->assertNotContains('Stu Student', $rows->pluck('name')->all());
        $allowed = ['id', 'name', 'email', 'code', 'role_id', 'department_id', 'designation_id', 'employment_type', 'staff_status', 'account_status', 'department_name', 'designation_name'];
        foreach ($rows as $row) {
            $this->assertSame([], array_diff(array_keys($row->getAttributes()), $allowed));
        }
        $this->assertStringNotContainsString('0799', $rows->toJson());

        $this->assertSame(['Tina Teacher'], StaffDirectory::query($this->adminA, ['base_role' => 3, 'staff_status' => 'active'])->pluck('name')->all());
        $this->expectRefused(fn () => StaffDirectory::query($this->user(17, $this->schoolA)), AuthorizationException::class);

        // Governed by staff.view, not by being a School Admin: a delegate sees the same directory.
        $viewer = $this->delegate(['staff.view']);
        $this->assertEqualsCanonicalizing(StaffDirectory::query($this->adminA)->pluck('id')->all(), StaffDirectory::query($viewer)->pluck('id')->all());
    }
}
