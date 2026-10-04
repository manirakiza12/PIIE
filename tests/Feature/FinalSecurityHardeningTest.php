<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Security Phase 2F — final hardening before RBAC.
 *
 *  - CSV exports are streamed straight to the requester; nothing is left on
 *    disk (they used to be written to the working directory — i.e. public/
 *    under a web server — under a predictable name and never deleted).
 *  - Hostel offline-payment proofs and self-service profile photos follow
 *    the same safe-upload rules as the tuition proofs / ProfilePhoto helper.
 *  - Self-service profile updates cannot claim another account's login email.
 *  - Promotion, enrollment changes, offline-payment submission/decision and
 *    hostel payment decisions are recorded through AuditLog::record().
 *
 * Uploads go to a temp public path; exports are checked against both the
 * project root (the test process's working directory) and that path.
 */
class FinalSecurityHardeningTest extends TestCase
{
    use StaffModuleTestHelper;

    private string $publicDir;
    private array $A;
    private array $B;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Schema::table('student_fee_managers', fn (Blueprint $t) => $t->string('document_image')->nullable());
        foreach (['amount' => 'decimal', 'document_image' => 'string'] as $column => $type) {
            if (!Schema::hasColumn('hostel_fees', $column)) { // present in the live schema
                Schema::table('hostel_fees', fn (Blueprint $t) => $type === 'decimal' ? $t->decimal($column, 10, 2)->nullable() : $t->string($column)->nullable());
            }
        }
        Mail::fake();

        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-phase2f-' . uniqid();
        File::ensureDirectoryExists($this->publicDir);
        $this->app->instance('path.public', $this->publicDir);

        $this->A = $this->world('A');
        $this->B = $this->world('B');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        parent::tearDown();
    }

    private function world(string $tag): array
    {
        $school = $this->makeSchool(['title' => "School {$tag}", 'status' => 1]);
        $class = $this->makeClass($school, ['name' => "Class{$tag}"]);
        $section = DB::table('sections')->insertGetId(['name' => "Sec{$tag}", 'class_id' => $class, 'created_at' => now(), 'updated_at' => now()]);
        $parent = User::factory()->create(['role_id' => 6, 'school_id' => $school, 'name' => "Parent{$tag}"]);
        $student = User::factory()->create(['role_id' => 7, 'school_id' => $school, 'parent_id' => $parent->id, 'name' => "Student{$tag}Secret"]);
        $enroll = DB::table('enrollment')->insertGetId(['user_id' => $student->id, 'class_id' => $class, 'section_id' => $section, 'school_id' => $school, 'session_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return compact('school', 'class', 'section', 'parent', 'student', 'enroll');
    }

    private function staff(int $roleId, array $w): User
    {
        return User::factory()->create(['role_id' => $roleId, 'school_id' => $w['school'], 'account_status' => 'active']);
    }

    private function fee(array $w, array $o = []): int
    {
        return DB::table('student_fee_managers')->insertGetId(array_merge([
            'title' => "Fee{$w['student']->name}" . (++$this->n), 'total_amount' => 100, 'class_id' => $w['class'], 'parent_id' => $w['parent']->id,
            'student_id' => $w['student']->id, 'payment_method' => '', 'paid_amount' => 0, 'status' => 'unpaid', 'school_id' => $w['school'],
            'session_id' => 1, 'timestamp' => time(), 'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    /** Every *.csv sitting in the project root or the (temp) public dir. */
    private function strayCsvFiles(): array
    {
        return array_merge(glob(base_path('*.csv')) ?: [], glob($this->publicDir . DIRECTORY_SEPARATOR . '*.csv') ?: []);
    }

    private function phpFile(string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'p2f');
        file_put_contents($path, "<?php echo 'owned';");

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function textFile(string $clientName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'p2f');
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    // ── 2. Exports ───────────────────────────────────────────────────────────

    public function test_staff_fee_exports_are_streamed_scoped_and_leave_no_file(): void
    {
        $this->fee($this->A);
        $this->fee($this->B);
        $before = $this->strayCsvFiles();

        foreach (['admin' => 2, 'accountant' => 4, 'bursar' => 4] as $prefix => $roleId) {
            $response = $this->actingAs($this->staff($roleId, $this->A))->get(route("{$prefix}.fee_manager.export", [
                'date_from' => 0, 'date_to' => time() + 3600, 'selected_class' => 'all', 'selected_status' => 'all',
            ]));

            $response->assertOk();
            $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'), $prefix);
            $this->assertSame(
                'attachment; filename="student_fee-01-01-1970-' . date('d-m-Y', time() + 3600) . '-all-all.csv"',
                $response->headers->get('Content-Disposition'),
                "{$prefix}: same download filename as before"
            );
            $this->assertStringStartsWith('Invoice No, Student, Class, Invoice Title, Total Amount, Created At, Paid Amount, Status', $response->getContent(), "{$prefix}: same CSV header row");
            $this->assertStringContainsString('StudentASecret', $response->getContent(), $prefix);
            $this->assertStringNotContainsString('StudentBSecret', $response->getContent(), $prefix);
        }

        $this->assertSame($before, $this->strayCsvFiles(), 'no export file left on disk');
    }

    public function test_portal_exports_are_streamed_own_family_only_and_leave_no_file(): void
    {
        $this->fee($this->A);
        $this->fee($this->B);
        $otherFamily = User::factory()->create(['role_id' => 7, 'school_id' => $this->A['school'], 'name' => 'NeighbourSecret']);
        $this->fee(['class' => $this->A['class'], 'parent' => $this->B['parent'], 'student' => $otherFamily, 'school' => $this->A['school']]);
        $before = $this->strayCsvFiles();
        $args = ['date_from' => 0, 'date_to' => time() + 3600, 'selected_status' => 'all'];

        foreach (['parent' => $this->A['parent'], 'student' => $this->A['student']] as $portal => $actor) {
            $response = $this->actingAs($actor)->get(route("{$portal}.fee_manager.export", $args));
            $response->assertOk();
            $this->assertStringNotContainsString('StudentBSecret', $response->getContent(), $portal);
            $this->assertStringNotContainsString('NeighbourSecret', $response->getContent(), $portal);
        }

        $this->actingAs($this->A['student'])->get(route('student.hostel_fee_manager.export', $args))->assertOk();
        $this->assertSame($before, $this->strayCsvFiles(), 'no export file left on disk');
    }

    public function test_attendance_exports_leave_no_file(): void
    {
        $before = $this->strayCsvFiles();
        $query = '?' . date('M') . '-' . date('Y') . '-7=';

        $this->actingAs($this->staff(2, $this->A))->get(route('admin.dailyAttendanceFilter_csv') . $query)->assertOk();
        $this->actingAs($this->staff(3, $this->A))->get(route('teacher.dailyAttendanceFilter_csv') . $query)->assertOk();

        $this->assertSame($before, $this->strayCsvFiles());
    }

    // ── 3. Hostel offline-payment proof ──────────────────────────────────────

    private function hostelProof(UploadedFile $file)
    {
        DB::table('hostel_applications')->updateOrInsert(
            ['student_id' => $this->A['student']->id],
            ['hostel_id' => 1, 'status' => 1, 'school_id' => $this->A['school'], 'created_at' => now(), 'updated_at' => now()]
        );

        return $this->actingAs($this->A['student'])
            ->withSession(['hostel_fee_year' => 2026, 'hostel_fee_month' => 9])
            ->post(route('student.offline.payment.hostel'), ['amount' => 50, 'document_image' => $file]);
    }

    private function hostelProofDir(): string
    {
        return $this->publicDir . '/assets/uploads/hostel_fees';
    }

    public function test_hostel_offline_payment_proof_rejects_unsafe_files(): void
    {
        $unsafe = [
            'php content'           => $this->phpFile('receipt.php'),
            'php content as .jpg'   => $this->phpFile('receipt.jpg'),
            'real image as .php'    => UploadedFile::fake()->image('receipt.php'),
            'svg'                   => $this->textFile('receipt.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'html'                  => $this->textFile('receipt.html', '<html><script>alert(1)</script></html>'),
            'plain text'            => $this->textFile('receipt.txt', 'hello'),
        ];

        foreach ($unsafe as $label => $file) {
            $this->hostelProof($file);
            $this->assertSame([], File::exists($this->hostelProofDir()) ? File::files($this->hostelProofDir()) : [], "{$label}: nothing written");
            $this->assertSame(0, DB::table('hostel_fees')->count(), "{$label}: no payment row");
        }
    }

    public function test_hostel_offline_payment_proof_accepts_jpg_png_pdf_under_generated_names(): void
    {
        foreach (['receipt.jpg' => UploadedFile::fake()->image('receipt.jpg'),
                  'receipt.png' => UploadedFile::fake()->image('receipt.png'),
                  'receipt.pdf' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')] as $name => $file) {
            DB::table('hostel_fees')->delete();
            $this->hostelProof($file);

            $stored = (string) DB::table('hostel_fees')->value('document_image');
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.' . $ext . '$/', $stored, $name);
            $this->assertFileExists($this->hostelProofDir() . '/' . $stored);
        }
    }

    // ── 4. Self-service profile photos + email ───────────────────────────────

    private function selfProfile(User $actor, string $route, array $overrides = [])
    {
        return $this->actingAs($actor)->post(route($route), array_merge([
            'name' => $actor->name, 'email' => $actor->email, 'designation' => 'x', 'eDefaultDateRange' => '01/01/1990',
            'birthday' => '01/01/1990', 'gender' => 'male', 'blood_group' => 'o+', 'phone' => '1', 'address' => 'a', 'old_photo' => 'old.png',
        ], $overrides));
    }

    public function test_self_profile_photos_accept_only_real_images(): void
    {
        $imagesDir = $this->publicDir . '/assets/uploads/user-images';
        File::ensureDirectoryExists($imagesDir);
        $portals = ['teacher.profile.update' => 3, 'parent.profile.update' => 6, 'student.profile.update' => 7,
                    'accountant.profile.update' => 4, 'librarian.profile.update' => 5, 'warden.profile.update' => 10];

        foreach ($portals as $route => $roleId) {
            foreach (['html' => $this->textFile('me.png', '<html><script>alert(1)</script></html>'),
                      'svg'  => $this->textFile('me.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
                      'text' => $this->textFile('me.png', 'just text')] as $label => $file) {
                $actor = $this->staff($roleId, $this->A);
                $this->selfProfile($actor, $route, ['photo' => $file]);
                $this->assertSame([], File::files($imagesDir), "{$route} {$label}: nothing written");
            }

            foreach (['me.jpg' => 'jpg', 'me.png' => 'png'] as $name => $ext) {
                $actor = $this->staff($roleId, $this->A);
                $this->selfProfile($actor, $route, ['photo' => UploadedFile::fake()->image($name)]);
                $photo = json_decode((string) $actor->fresh()->user_information, true)['photo'] ?? '';
                $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.' . $ext . '$/', $photo, "{$route} {$name}");
                $this->assertFileExists($imagesDir . '/' . $photo);
            }
            File::cleanDirectory($imagesDir);
        }
    }

    public function test_self_profile_update_cannot_claim_another_accounts_login_email(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropUnique(['email'])); // the live users table has no unique index on email
        $portals = ['teacher.profile.update' => 3, 'parent.profile.update' => 6, 'student.profile.update' => 7,
                    'accountant.profile.update' => 4, 'librarian.profile.update' => 5, 'warden.profile.update' => 10,
                    'admin.profile.update' => 2, 'superadmin.profile.update' => 1];
        $actorFor = fn (int $roleId) => $roleId === 1
            ? User::factory()->create(['role_id' => 1, 'school_id' => null, 'account_status' => 'active'])
            : $this->staff($roleId, $this->A);

        foreach ($portals as $route => $roleId) {
            $actor = $actorFor($roleId);
            $victim = User::factory()->create(['role_id' => 2, 'school_id' => $this->A['school'], 'email' => "victim{$roleId}@school.test"]);
            $original = $actor->email;

            $this->selfProfile($actor, $route, ['email' => $victim->email, 'name' => 'Should Not Save']);

            $this->assertSame($original, $actor->fresh()->email, "{$route}: email not claimed");
            $this->assertNotSame('Should Not Save', $actor->fresh()->name, "{$route}: rejected as a whole");
            $this->assertSame(1, User::where('email', $victim->email)->count(), "{$route}: no duplicate login email");

            // Keeping your own existing email while editing the rest of the profile still works.
            $this->selfProfile($actor, $route, ['email' => $original, 'name' => "Renamed {$roleId}"]);
            $this->assertSame("Renamed {$roleId}", $actor->fresh()->name, "{$route}: own email kept, profile saved");
            $this->assertSame($original, $actor->fresh()->email, "{$route}: own email unchanged");
        }

        // A normal self email change to an unused address still works.
        $teacher = $this->staff(3, $this->A);
        $this->selfProfile($teacher, 'teacher.profile.update', ['email' => 'fresh.teacher@school.test']);
        $this->assertSame('fresh.teacher@school.test', $teacher->fresh()->email);
    }

    // ── 6. Remaining upload sites (SafeUpload) ───────────────────────────────

    public function test_safe_upload_refuses_scripts_and_keeps_legitimate_formats(): void
    {
        $dir = $this->publicDir . '/safe-upload';

        $refused = [
            'php'                  => [$this->phpFile('x.php'), null],
            'php named .docx'      => [$this->phpFile('x.docx'), null],
            'real image as .php'   => [UploadedFile::fake()->image('x.php'), null],
            'svg'                  => [$this->textFile('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), null],
            'html'                 => [$this->textFile('x.html', '<html></html>'), null],
            'svg on image field'   => [$this->textFile('x.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), \App\Support\SafeUpload::IMAGES],
            'pdf on image field'   => [UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'), \App\Support\SafeUpload::IMAGES],
        ];
        foreach ($refused as $label => [$file, $allowed]) {
            $this->assertNull(\App\Support\SafeUpload::store($file, $dir, $allowed), $label);
        }
        $this->assertSame([], File::exists($dir) ? File::files($dir) : []);

        foreach (['x.docx' => [UploadedFile::fake()->create('x.docx', 5, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), null],
                  'x.pdf'  => [UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'), null],
                  'x.png'  => [UploadedFile::fake()->image('x.png'), \App\Support\SafeUpload::IMAGES]] as $name => [$file, $allowed]) {
            $stored = \App\Support\SafeUpload::store($file, $dir, $allowed);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.' . pathinfo($name, PATHINFO_EXTENSION) . '$/', (string) $stored, $name);
            $this->assertFileExists($dir . '/' . $stored);
        }
    }

    public function test_teacher_syllabus_and_student_assignment_uploads_refuse_scripts(): void
    {
        Schema::create('syllabuses', function (Blueprint $t) {
            $t->id(); $t->string('title')->nullable(); $t->integer('class_id')->nullable(); $t->integer('section_id')->nullable();
            $t->integer('subject_id')->nullable(); $t->string('file')->nullable(); $t->integer('school_id')->nullable(); $t->integer('session_id')->nullable(); $t->timestamps();
        });
        // `course_offering_id` is declared because the production table has it
        // (added by the Course Offering Assignments migration) and the legacy
        // controller now scopes its reads with `->k12()`. It is NULL for every row
        // in this test on purpose: these ARE the legacy K12 assignments the test is
        // about, and the domain partition must not exclude them. Every assertion
        // in the test is unchanged.
        Schema::create('assignments', function (Blueprint $t) {
            $t->id(); $t->integer('school_id'); $t->boolean('is_published')->default(1); $t->dateTime('due_date')->nullable();
            $t->unsignedBigInteger('course_offering_id')->nullable(); $t->timestamps();
        });
        Schema::create('assignment_submissions', function (Blueprint $t) {
            $t->id(); $t->integer('assignment_id'); $t->integer('student_id'); $t->dateTime('submitted_at')->nullable(); $t->string('status')->nullable();
            $t->string('file_path')->nullable(); $t->text('submission')->nullable();
            $t->unsignedBigInteger('course_offering_id')->nullable(); $t->timestamps();
        });
        $teacher = $this->staff(3, $this->A);
        $syllabusDir = $this->publicDir . '/assets/uploads/syllabus';
        $assignmentDir = $this->publicDir . '/assets/uploads/assignments';
        $syllabus = ['title' => 't', 'class_id' => $this->A['class'], 'section_id' => $this->A['section'], 'subject_id' => 1];

        $this->actingAs($teacher)->post(route('teacher.show_syllabus_modal_post'), $syllabus + ['syllabus_file' => $this->phpFile('shell.php')]);
        $this->assertSame([], File::exists($syllabusDir) ? File::files($syllabusDir) : []);
        $this->actingAs($teacher)->post(route('teacher.show_syllabus_modal_post'), $syllabus + ['syllabus_file' => UploadedFile::fake()->create('scheme of work.pdf', 10, 'application/pdf')]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.pdf$/', (string) DB::table('syllabuses')->value('file'));

        $assignment = DB::table('assignments')->insertGetId(['school_id' => $this->A['school'], 'is_published' => 1, 'due_date' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->A['student'])->post(route('student.assignments.submit', $assignment), ['file' => $this->phpFile('shell.php')]);
        $this->assertSame([], File::exists($assignmentDir) ? File::files($assignmentDir) : []);
        $this->assertSame(0, DB::table('assignment_submissions')->count());

        $this->actingAs($this->A['student'])->post(route('student.assignments.submit', $assignment), ['file' => UploadedFile::fake()->create('essay.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]);
        $this->assertMatchesRegularExpression('#^assets/uploads/assignments/[a-f0-9]{40}\.docx$#', (string) DB::table('assignment_submissions')->value('file_path'));
    }

    /** A genuine PNG (real image bytes) carrying whatever client filename is given. */
    private function realPngNamed(string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    public function test_admission_documents_keep_only_allowed_extensions(): void
    {
        $admission = \App\Models\Admission::forceCreate([
            'school_id' => $this->A['school'], 'app_number' => 'P-2F', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.test', 'status' => 'draft',
        ]);
        $dir = $this->publicDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, \App\Models\AdmissionDocument::UPLOAD_DIR);

        foreach (['passport.html', 'passport.svg', 'passport.shtml', 'passport.js'] as $name) {
            try {
                \App\Support\Admissions\ApplicationDocuments::store($admission, $this->realPngNamed($name), 'passport_photo');
                $this->fail("{$name} must be refused");
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('files', $e->errors());
            }
            $this->assertSame([], File::exists($dir) ? File::files($dir) : [], "{$name}: nothing written");
        }

        $document = \App\Support\Admissions\ApplicationDocuments::store($admission, $this->realPngNamed('passport.png'), 'passport_photo');
        $this->assertStringEndsWith('.png', $document->stored_name);
    }

    public function test_website_cms_images_keep_only_allowed_extensions_and_keep_the_old_image_on_refusal(): void
    {
        $dir = $this->publicDir . '/assets/uploads/website/';
        File::ensureDirectoryExists($dir);
        file_put_contents($dir . 'old.png', 'old image');
        $controller = app(\App\Http\Controllers\WebsiteManagementController::class);
        $saveImage = new \ReflectionMethod($controller, 'saveImage');
        $saveImage->setAccessible(true);

        foreach (['banner.html', 'banner.svg', 'banner.php'] as $name) {
            $request = \Illuminate\Http\Request::create('/', 'POST', [], [], ['image' => $this->realPngNamed($name)]);
            try {
                $saveImage->invoke($controller, $request, 'image', 'old.png');
                $this->fail("{$name} must be refused");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
            $this->assertSame(['old.png'], array_map(fn ($f) => $f->getFilename(), File::files($dir)), "{$name}: old image kept, nothing new written");
        }

        $request = \Illuminate\Http\Request::create('/', 'POST', [], [], ['image' => $this->realPngNamed('banner.png')]);
        $stored = $saveImage->invoke($controller, $request, 'image', 'old.png');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.png$/', $stored);
        $this->assertSame([$stored], array_map(fn ($f) => $f->getFilename(), File::files($dir)), 'new image stored, old one replaced');
    }

    // ── 10. Shared lookup routes (any authenticated role) ────────────────────

    public function test_shared_user_lookup_routes_stay_in_school(): void
    {
        foreach ([$this->staff(3, $this->A), $this->A['student'], $this->A['parent']] as $actor) {
            ob_start();
            $own = $this->actingAs($actor)->get(route('id_wise_user_name', $this->A['student']->id));
            $ownEchoed = ob_get_clean();
            $this->assertStringContainsString('StudentASecret', $ownEchoed . $own->getContent());

            ob_start();
            $foreign = $this->actingAs($actor)->get(route('id_wise_user_name', $this->B['student']->id));
            $this->assertStringNotContainsString('StudentBSecret', ob_get_clean() . $foreign->getContent());

            ob_start();
            $this->actingAs($actor)->get(route('student_wise_parent', $this->B['student']->id))->assertNotFound();
            $this->assertStringNotContainsString('ParentB', (string) ob_get_clean());
        }

        ob_start();
        $this->actingAs($this->staff(3, $this->A))->get(route('student_wise_parent', $this->A['student']->id));
        $this->assertStringContainsString('ParentA', (string) ob_get_clean());
    }

    // ── 5. Audit coverage ────────────────────────────────────────────────────

    private function lastAudit(): ?AuditLog
    {
        return AuditLog::orderByDesc('id')->first();
    }

    /** No Phase 2F state-change entry may carry secrets, credentials or uploaded-file references. */
    private function assertPhase2fAuditsCarryNoSecrets(): void
    {
        $forbidden = ['password', 'remember_token', 'token', 'api_key', 'secret', 'transaction_keys', 'gateway_payload',
                      'payment_keys', 'document_image', 'file', 'file_path', 'stored_name', 'original_name'];
        $logs = AuditLog::whereIn('record_type', [\App\Models\StudentFeeManager::class, \App\Models\HostelFee::class, \App\Models\Enrollment::class])->get();
        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            foreach ([(array) $log->old_values, (array) $log->new_values] as $values) {
                $this->assertSame([], array_values(array_intersect(array_keys($values), $forbidden)), "audit #{$log->id} carries a sensitive key");
            }
            $this->assertStringNotContainsStringIgnoringCase('password', (string) $log->description);
        }
    }

    public function test_promotion_is_audited(): void
    {
        $admin = $this->staff(2, $this->A);
        $classA2 = $this->makeClass($this->A['school'], ['name' => 'ClassA2']);
        $sectionA2 = DB::table('sections')->insertGetId(['name' => 'SecA2', 'class_id' => $classA2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 7, 'session_title' => '2027', 'school_id' => $this->A['school'], 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->get(route('admin.promotion.promote', "{$this->A['enroll']}-{$classA2}-{$sectionA2}-7"));

        $log = AuditLog::where('record_type', \App\Models\Enrollment::class)->where('record_id', $this->A['enroll'])->latest('id')->firstOrFail();
        $this->assertSame($admin->id, (int) $log->user_id);
        $this->assertSame($this->A['school'], (int) $log->school_id);
        $this->assertEquals($this->A['class'], $log->old_values['class_id']);
        $this->assertEquals($classA2, $log->new_values['class_id']);
        $this->assertEquals($this->A['student']->id, $log->new_values['student_id']);
        $this->assertPhase2fAuditsCarryNoSecrets();
    }

    public function test_enrollment_change_on_student_edit_is_audited(): void
    {
        $admin = $this->staff(2, $this->A);
        $classA2 = $this->makeClass($this->A['school'], ['name' => 'ClassA2']);
        $student = $this->A['student'];

        $this->actingAs($admin)->post(route('admin.student.update', $student->id), [
            'name' => $student->name, 'email' => $student->email, 'gender' => 'male', 'blood_group' => 'O+',
            'birthday' => '2000-01-01', 'phone' => '1', 'address' => 'a', 'class_id' => $classA2,
        ]);

        $log = AuditLog::where('record_type', \App\Models\Enrollment::class)->where('record_id', $this->A['enroll'])->latest('id')->firstOrFail();
        $this->assertEquals($this->A['class'], $log->old_values['class_id']);
        $this->assertEquals($classA2, $log->new_values['class_id']);
    }

    public function test_offline_payment_submission_and_decisions_are_audited(): void
    {
        $fee = $this->fee($this->A);

        $this->actingAs($this->A['student'])->post(route('student.offline_payment', $fee), ['amount' => 100, 'document_image' => UploadedFile::fake()->image('r.jpg')]);
        $log = $this->lastAudit();
        $this->assertSame(\App\Models\StudentFeeManager::class, $log->record_type);
        $this->assertSame($fee, (int) $log->record_id);
        $this->assertSame('unpaid', $log->old_values['status']);
        $this->assertSame('pending', $log->new_values['status']);
        $this->assertStringNotContainsString('document_image', json_encode($log->new_values));

        $parentFee = $this->fee($this->A);
        $this->actingAs($this->A['parent'])->post(route('parent.offline_payment', $parentFee), ['amount' => 100, 'document_image' => UploadedFile::fake()->image('r.jpg')]);
        $this->assertSame($parentFee, (int) $this->lastAudit()->record_id);
        $this->assertSame('pending', $this->lastAudit()->new_values['status']);

        foreach (['admin' => 2, 'accountant' => 4] as $prefix => $roleId) {
            foreach (['approve' => 'paid', 'decline' => 'unpaid'] as $decision => $status) {
                $pending = $this->fee($this->A, ['status' => 'pending']);
                $actor = $this->staff($roleId, $this->A);
                $this->actingAs($actor)->get(route("{$prefix}.update_offline_payment", ['id' => $pending, 'status' => $decision]));

                $log = AuditLog::where('record_type', \App\Models\StudentFeeManager::class)->where('record_id', $pending)->latest('id')->firstOrFail();
                $this->assertSame($actor->id, (int) $log->user_id, "{$prefix} {$decision}");
                $this->assertSame('pending', $log->old_values['status'], "{$prefix} {$decision}");
                $this->assertSame($status, $log->new_values['status'], "{$prefix} {$decision}");
                $this->assertEquals($this->A['student']->id, $log->new_values['student_id'], "{$prefix} {$decision}");
            }
        }

        $this->assertPhase2fAuditsCarryNoSecrets();
    }

    public function test_hostel_payment_decisions_are_audited(): void
    {
        foreach (['admin.accept.offline.payment.hostel' => [2, 1], 'admin.reject.offline.payment.hostel' => [2, 2],
                  'warden.accept.offline.payment.hostel' => [10, 1], 'warden.reject.offline.payment.hostel' => [10, 2]] as $route => [$roleId, $status]) {
            $hostelFee = DB::table('hostel_fees')->insertGetId(['school_id' => $this->A['school'], 'student_id' => $this->A['student']->id, 'title' => 'H', 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $actor = $this->staff($roleId, $this->A);
            $this->actingAs($actor)->get(route($route, $hostelFee));

            $log = AuditLog::where('record_type', \App\Models\HostelFee::class)->where('record_id', $hostelFee)->latest('id')->firstOrFail();
            $this->assertSame($actor->id, (int) $log->user_id, $route);
            $this->assertEquals(0, $log->old_values['status'], $route);
            $this->assertEquals($status, $log->new_values['status'], $route);
        }

        $this->assertPhase2fAuditsCarryNoSecrets();
    }
}
