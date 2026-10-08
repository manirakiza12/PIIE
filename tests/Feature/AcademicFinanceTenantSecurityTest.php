<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;

/**
 * Security Phase 2E — academic + finance tenant boundaries.
 *
 * Every academic or finance record reached by an id (class, section,
 * enrollment, fee/invoice, hostel fee) must belong to the caller's school,
 * and portal (parent/student) finance actions must belong to the caller's
 * own family — the same rule their fee list already uses. Which staff roles
 * may use these modules is deliberately unchanged (future permission
 * phase); this is tenant/ownership isolation only.
 *
 * All uploads go to a temp public path; nothing touches the real public/.
 */
class AcademicFinanceTenantSecurityTest extends TestCase
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
        Schema::table('student_fee_managers', function (Blueprint $t) {
            $t->string('document_image')->nullable(); // present in the live schema
        });
        Mail::fake();

        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-phase2e-' . uniqid();
        File::ensureDirectoryExists($this->publicDir);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);

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
        $section = DB::table('sections')->insertGetId(['name' => "Section{$tag}Secret", 'class_id' => $class, 'created_at' => now(), 'updated_at' => now()]);
        $parent = User::factory()->create(['role_id' => 6, 'school_id' => $school, 'name' => "Parent{$tag}Secret"]);
        $student = User::factory()->create(['role_id' => 7, 'school_id' => $school, 'parent_id' => $parent->id, 'name' => "Student{$tag}Secret"]);
        $enroll = DB::table('enrollment')->insertGetId(['user_id' => $student->id, 'class_id' => $class, 'section_id' => $section, 'school_id' => $school, 'session_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return compact('school', 'class', 'section', 'parent', 'student', 'enroll');
    }

    private function staff(int $roleId, array $world): User
    {
        return User::factory()->create(['role_id' => $roleId, 'school_id' => $world['school'], 'account_status' => 'active']);
    }

    private function fee(array $w, array $overrides = []): int
    {
        return DB::table('student_fee_managers')->insertGetId(array_merge([
            'title' => 'Fee' . (++$this->n) . 'Secret', 'total_amount' => 100, 'class_id' => $w['class'], 'parent_id' => $w['parent']->id,
            'student_id' => $w['student']->id, 'payment_method' => '', 'paid_amount' => 0, 'status' => 'unpaid', 'school_id' => $w['school'],
            'session_id' => 1, 'timestamp' => time(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function feeRow(int $id): ?object
    {
        return DB::table('student_fee_managers')->where('id', $id)->first();
    }

    /** Runs a GET whose controller echoes instead of returning, and returns everything written. */
    private function echoedGet(string $url): string
    {
        ob_start();
        $response = $this->get($url);

        return ob_get_clean() . $response->getContent();
    }

    // ── Academic ─────────────────────────────────────────────────────────────

    public function test_class_and_section_lookups_do_not_cross_schools(): void
    {
        $admin = $this->staff(2, $this->A);

        $this->actingAs($admin);
        $this->assertStringContainsString('StudentASecret', $this->echoedGet(route('admin.class_wise_student', $this->A['class'])));
        $this->assertStringNotContainsString('StudentBSecret', $this->echoedGet(route('admin.class_wise_student', $this->B['class'])));
        $this->assertStringNotContainsString('StudentBSecret', $this->echoedGet(route('admin.class_wise_student_invoice', $this->B['section'])));

        foreach ([$admin, $this->A['parent'], $this->A['student']] as $actor) {
            $this->actingAs($actor);
            $this->assertStringContainsString('SectionASecret', $this->echoedGet(route('class_wise_sections', $this->A['class'])));
            $this->assertStringNotContainsString('SectionBSecret', $this->echoedGet(route('class_wise_sections', $this->B['class'])));
            $this->assertStringNotContainsString('StudentBSecret', $this->echoedGet(route('class_wise_student', $this->B['class'])));
        }
    }

    public function test_promotion_only_moves_own_school_students_into_own_school_classes(): void
    {
        $admin = $this->staff(2, $this->A);
        $classA2 = $this->makeClass($this->A['school'], ['name' => 'ClassA2']);
        $sectionA2 = DB::table('sections')->insertGetId(['name' => 'SecA2', 'class_id' => $classA2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 7, 'session_title' => '2027', 'school_id' => $this->A['school'], 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // School B's enrollment cannot be touched.
        $this->actingAs($admin)->get(route('admin.promotion.promote', "{$this->B['enroll']}-{$classA2}-{$sectionA2}-7"));
        $this->assertSame((int) $this->B['class'], (int) DB::table('enrollment')->where('id', $this->B['enroll'])->value('class_id'));

        // Own student cannot be moved into School B's class.
        $this->actingAs($admin)->get(route('admin.promotion.promote', "{$this->A['enroll']}-{$this->B['class']}-{$this->B['section']}-1"));
        $this->assertSame((int) $this->A['class'], (int) DB::table('enrollment')->where('id', $this->A['enroll'])->value('class_id'));

        // Legitimate same-school promotion still works.
        $this->actingAs($admin)->get(route('admin.promotion.promote', "{$this->A['enroll']}-{$classA2}-{$sectionA2}-7"));
        $row = DB::table('enrollment')->where('id', $this->A['enroll'])->first();
        $this->assertSame([$classA2, $sectionA2, 7], [(int) $row->class_id, (int) $row->section_id, (int) $row->session_id]);
    }

    public function test_programme_and_intake_must_belong_to_the_callers_school(): void
    {
        $admin = $this->staff(2, $this->A);
        $foreignProgramme = DB::table('programmes')->insertGetId(['school_id' => $this->B['school'], 'name' => 'Foreign', 'code' => 'FX', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $foreignIntake = DB::table('intake_sessions')->insertGetId(['school_id' => $this->B['school'], 'name' => 'Foreign intake', 'is_open' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $base = ['gender' => 'male', 'blood_group' => 'O+', 'birthday' => '2000-01-01', 'phone' => '1', 'address' => 'a', 'class_id' => $this->A['class']];

        $this->actingAs($admin)->post(route('admin.student.create'), $base + ['name' => 'P', 'email' => 'p@example.test', 'programme_id' => $foreignProgramme])->assertSessionHasErrors('programme_id');
        $this->actingAs($admin)->post(route('admin.student.create'), $base + ['name' => 'I', 'email' => 'i@example.test', 'intake_session_id' => $foreignIntake])->assertSessionHasErrors('intake_session_id');
        $this->assertFalse(User::whereIn('email', ['p@example.test', 'i@example.test'])->exists());

        $student = $this->A['student'];
        $this->actingAs($admin)->post(route('admin.student.update', $student->id), $base + ['name' => $student->name, 'email' => $student->email, 'programme_id' => $foreignProgramme])->assertSessionHasErrors('programme_id');
        $this->assertNull(DB::table('student_profiles')->where('user_id', $student->id)->value('programme_id'));
    }

    // ── Finance: staff side ──────────────────────────────────────────────────

    public function test_staff_fee_actions_never_reach_another_schools_fees(): void
    {
        foreach (['admin' => 2, 'accountant' => 4, 'bursar' => 4] as $prefix => $roleId) {
            $actor = $this->staff($roleId, $this->A);
            $foreignFee = $this->fee($this->B, ['status' => 'pending']);
            $before = (array) $this->feeRow($foreignFee);

            $this->actingAs($actor)->get(route("{$prefix}.edit.fee_manager", $foreignFee))->assertNotFound();
            $this->actingAs($actor)->get(route("{$prefix}.studentFeeinvoice", $foreignFee))->assertNotFound();
            $this->actingAs($actor)->post(route("{$prefix}.fee_manager.update", $foreignFee), [
                'title' => 'Hijacked', 'total_amount' => 1, 'paid_amount' => 0, 'status' => 'unpaid',
                'class_id' => $this->A['class'], 'student_id' => $this->A['student']->id, 'payment_method' => 'cash',
            ])->assertNotFound();
            $this->actingAs($actor)->get(route("{$prefix}.update_offline_payment", ['id' => $foreignFee, 'status' => 'approve']))->assertNotFound();
            $this->actingAs($actor)->get(route("{$prefix}.fee_manager.delete", $foreignFee))->assertNotFound();

            $this->assertEquals($before, (array) $this->feeRow($foreignFee), "{$prefix}: School B fee untouched");
        }
    }

    public function test_a_fee_cannot_be_reassigned_to_another_schools_student(): void
    {
        $admin = $this->staff(2, $this->A);
        $ownFee = $this->fee($this->A);

        $this->actingAs($admin)->post(route('admin.fee_manager.update', $ownFee), [
            'title' => 'Reassigned', 'total_amount' => 100, 'paid_amount' => 0, 'status' => 'unpaid',
            'class_id' => $this->A['class'], 'student_id' => $this->B['student']->id, 'payment_method' => 'cash',
        ]);
        $this->assertSame($this->A['student']->id, (int) $this->feeRow($ownFee)->student_id);

        $this->actingAs($admin)->post(route('admin.create.fee_manager', 'single'), [
            'title' => 'ForeignCreate', 'total_amount' => 10, 'paid_amount' => 0, 'status' => 'unpaid',
            'class_id' => $this->A['class'], 'student_id' => $this->B['student']->id, 'payment_method' => 'cash',
        ]);
        $this->assertFalse(DB::table('student_fee_managers')->where('title', 'ForeignCreate')->exists());
    }

    public function test_same_school_fee_management_is_unchanged(): void
    {
        $admin = $this->staff(2, $this->A);
        $fee = $this->fee($this->A, ['status' => 'pending']);

        $this->actingAs($admin)->get(route('admin.update_offline_payment', ['id' => $fee, 'status' => 'approve']));
        $this->assertSame('paid', $this->feeRow($fee)->status);

        $this->actingAs($admin)->post(route('admin.fee_manager.update', $fee), [
            'title' => 'Renamed fee', 'total_amount' => 100, 'paid_amount' => 100, 'status' => 'paid',
            'class_id' => $this->A['class'], 'student_id' => $this->A['student']->id, 'payment_method' => 'cash',
        ]);
        $this->assertSame('Renamed fee', $this->feeRow($fee)->title);
        $this->assertSame($this->A['school'], (int) $this->feeRow($fee)->school_id);

        $this->actingAs($admin)->get(route('admin.fee_manager.delete', $fee));
        $this->assertNull($this->feeRow($fee));
    }

    public function test_hostel_payment_decisions_stay_in_school(): void
    {
        $foreign = DB::table('hostel_fees')->insertGetId(['school_id' => $this->B['school'], 'student_id' => $this->B['student']->id, 'title' => 'H', 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->staff(2, $this->A))->get(route('admin.accept.offline.payment.hostel', $foreign))->assertNotFound();
        $this->actingAs($this->staff(2, $this->A))->get(route('admin.reject.offline.payment.hostel', $foreign))->assertNotFound();
        $this->actingAs($this->staff(10, $this->A))->get(route('warden.accept.offline.payment.hostel', $foreign))->assertNotFound();
        $this->actingAs($this->staff(10, $this->A))->get(route('warden.reject.offline.payment.hostel', $foreign))->assertNotFound();

        $this->assertSame(0, (int) DB::table('hostel_fees')->where('id', $foreign)->value('status'));
    }

    // ── Finance: parent / student portals ────────────────────────────────────

    public function test_portal_fee_pages_only_show_the_callers_own_family_fees(): void
    {
        // A second family in School A (same school, different family).
        $otherParent = User::factory()->create(['role_id' => 6, 'school_id' => $this->A['school']]);
        $otherStudent = User::factory()->create(['role_id' => 7, 'school_id' => $this->A['school'], 'parent_id' => $otherParent->id, 'name' => 'NeighbourSecret']);
        $neighbourFee = $this->fee(['class' => $this->A['class'], 'parent' => $otherParent, 'student' => $otherStudent, 'school' => $this->A['school']]);
        $foreignFee = $this->fee($this->B);
        $ownFee = $this->fee($this->A);

        foreach (['parent' => $this->A['parent'], 'student' => $this->A['student']] as $portal => $actor) {
            foreach ([$neighbourFee, $foreignFee] as $fee) {
                $this->actingAs($actor)->get(route("{$portal}.studentFeeinvoice", $fee))->assertNotFound();
                $this->actingAs($actor)->get(route($portal === 'parent' ? 'parent.FeePayment' : 'student.FeePayment', $fee))->assertNotFound();
            }
            $this->actingAs($actor)->get(route("{$portal}.studentFeeinvoice", $ownFee))->assertOk();
        }
    }

    public function test_offline_payment_only_on_own_fee_and_only_safe_proof_files(): void
    {
        $foreignFee = $this->fee($this->B);
        $ownFee = $this->fee($this->A);

        foreach (['parent.offline_payment' => $this->A['parent'], 'student.offline_payment' => $this->A['student']] as $route => $actor) {
            $this->actingAs($actor)->post(route($route, $foreignFee), ['amount' => 100, 'document_image' => UploadedFile::fake()->create('p.pdf', 5, 'application/pdf')])->assertNotFound();
            $this->assertSame('unpaid', $this->feeRow($foreignFee)->status, $route);
        }

        $php = tempnam(sys_get_temp_dir(), 'p2e');
        file_put_contents($php, "<?php echo 'owned';");
        $this->actingAs($this->A['student'])->post(route('student.offline_payment', $ownFee), ['amount' => 100, 'document_image' => new UploadedFile($php, 'proof.php', null, null, true)]);
        $this->assertSame('unpaid', $this->feeRow($ownFee)->status);
        $this->assertSame([], File::exists($this->publicDir . '/assets/uploads/offline_payment') ? File::files($this->publicDir . '/assets/uploads/offline_payment') : []);

        // A genuine image whose name ends in .php must not be stored as .php either.
        $this->actingAs($this->A['parent'])->post(route('parent.offline_payment', $ownFee), ['amount' => 100, 'document_image' => UploadedFile::fake()->image('receipt.php')]);
        $this->assertSame('unpaid', $this->feeRow($ownFee)->status);
        $this->assertSame([], File::exists($this->publicDir . '/assets/uploads/offline_payment') ? File::files($this->publicDir . '/assets/uploads/offline_payment') : []);

        $this->actingAs($this->A['student'])->post(route('student.offline_payment', $ownFee), ['amount' => 100, 'document_image' => UploadedFile::fake()->image('receipt.jpg')]);
        $row = $this->feeRow($ownFee);
        $this->assertSame('pending', $row->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.jpg$/', (string) $row->document_image);
        $this->assertFileExists($this->publicDir . '/assets/uploads/offline_payment/' . $row->document_image);
    }

    // ── Profile photos ───────────────────────────────────────────────────────

    public function test_profile_photos_accept_only_real_jpg_or_png_images(): void
    {
        $admin = $this->staff(2, $this->A);
        $imagesDir = $this->publicDir . '/assets/uploads/user-images';
        File::ensureDirectoryExists($imagesDir);
        $payload = fn (User $u, $photo) => ['name' => 'P', 'email' => $u->email, 'gender' => 'M', 'blood_group' => 'o+', 'birthday' => '01/01/1980', 'phone' => '1', 'address' => 'a', 'photo' => $photo];

        foreach (['avatar.jpg' => '<html><script>alert(document.cookie)</script></html>',
                  'avatar.png' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                  'avatar.gif' => 'plain text'] as $name => $content) {
            $parent = User::factory()->create(['role_id' => 6, 'school_id' => $this->A['school'], 'user_information' => json_encode(['photo' => 'old.png'])]);
            $path = tempnam(sys_get_temp_dir(), 'ph');
            file_put_contents($path, $content);

            $this->actingAs($admin)->post(route('admin.parent.update', $parent->id), $payload($parent, new UploadedFile($path, $name, null, null, true)));

            $this->assertSame([], File::files($imagesDir), "{$name}: nothing written");
            $this->assertSame('old.png', json_decode($parent->fresh()->user_information, true)['photo'] ?? null, "{$name}: photo unchanged");
        }

        foreach (['me.png' => 'png', 'me.jpg' => 'jpg'] as $name => $ext) {
            $parent = User::factory()->create(['role_id' => 6, 'school_id' => $this->A['school'], 'user_information' => json_encode(['photo' => ''])]);
            $this->actingAs($admin)->post(route('admin.parent.update', $parent->id), $payload($parent, UploadedFile::fake()->image($name)));

            $stored = json_decode($parent->fresh()->user_information, true)['photo'] ?? '';
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}\.' . $ext . '$/', $stored);
            $this->assertFileExists($imagesDir . '/' . $stored);
        }
    }

    // ── Warden documents route ───────────────────────────────────────────────

    public function test_warden_documents_page_resolves_same_school_wardens_only(): void
    {
        $admin = $this->staff(2, $this->A);
        $warden = $this->staff(10, $this->A);
        $foreignWarden = $this->staff(10, $this->B);

        $this->actingAs($admin)->get(route('admin.warden.documents', $warden->id))->assertOk();
        $this->actingAs($admin)->get(route('admin.warden.documents', $foreignWarden->id))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.warden.documents', $this->A['student']->id))->assertNotFound();
    }
}
