<?php

namespace Tests\Feature;

use App\Models\LiveClass;
use App\Models\User;
use App\Support\LiveClasses\LiveClassAssetStorage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

class LiveClassPrivateAssetFoundationTest extends TestCase
{
    use LiveClassTestHelper;

    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        $this->testRoot = storage_path('framework/testing/live-class-private-assets');
        Config::set('filesystems.disks.local.root', $this->testRoot);
        Storage::disk('local')->deleteDirectory('live-class-private');

        Schema::table('live_classes', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_offering_id')->nullable();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('status');
        });
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_offering_id')->nullable();
            $table->string('status');
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('course_offering_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->string('status');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('live-class-private');
        if (is_dir($this->testRoot)) {
            $this->removeDirectory($this->testRoot);
        }
        parent::tearDown();
    }

    public function test_private_upload_uses_random_relative_key_outside_public_root_and_resolves_only_inside_private_root(): void
    {
        $storage = app(LiveClassAssetStorage::class);
        $class = $this->offeringClass();
        $file = UploadedFile::fake()->createWithContent('original-not-a-path.pdf', "%PDF-1.4\nprivate fixture\n");

        $first = $storage->store($file, $class, 'resource', 'pdf');
        $second = $storage->store(UploadedFile::fake()->createWithContent('other.pdf', "%PDF-1.4\nsecond\n"), $class, 'resource', 'pdf');

        $this->assertMatchesRegularExpression('#^live-class-private/live-classes/11/'.$class->id.'/materials/[a-f0-9]{64}\.pdf$#', $first);
        $this->assertNotSame($first, $second);
        $this->assertFileExists(Storage::disk('local')->path($first));
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR, Storage::disk('local')->path($first));
        $this->assertSame(realpath(Storage::disk('local')->path($first)), realpath($storage->resolvePrivatePath($first, $class, 'resource')));
        $this->assertNull($storage->resolvePrivatePath('lcm12_legacy.pdf', $class, 'resource'));
        $this->assertNull($storage->resolvePrivatePath(str_replace('/materials/', '/../materials/', $first), $class, 'resource'));
        $this->assertTrue($storage->deletePrivate($first, $class, 'resource'));
        $this->assertTrue($storage->deletePrivate($second, $class, 'resource'));
    }

    public function test_missing_or_wrong_scope_private_key_fails_closed(): void
    {
        $storage = app(LiveClassAssetStorage::class);
        $class = $this->offeringClass();
        $validShape = 'live-class-private/live-classes/11/'.$class->id.'/materials/'.str_repeat('a', 64).'.pdf';
        $otherClass = $this->offeringClass(99);

        $this->assertTrue($storage->isPrivateKey($validShape, $class, 'resource'));
        $this->assertNull($storage->resolvePrivatePath($validShape, $class, 'resource'));
        $this->assertFalse($storage->isPrivateKey($validShape, $otherClass, 'resource'));
        $this->assertFalse($storage->isPrivateKey($validShape, $class, 'recording'));
        $this->assertFalse($storage->isPrivateKey('../'.$validShape, $class, 'resource'));
    }

    public function test_confirmed_student_downloads_only_material_nested_under_the_exact_offering_class(): void
    {
        $class = $this->offeringClass();
        $student = User::create(['name' => 'Registered learner', 'email' => 'asset-student@example.test', 'role_id' => 7, 'school_id' => 11, 'status' => 1]);
        $this->grantStudyEligibilityFixture($student);
        DB::table('course_registrations')->insert(['school_id' => 11, 'student_id' => $student->id, 'course_offering_id' => 701, 'status' => 'confirmed']);

        $storage = app(LiveClassAssetStorage::class);
        $key = $storage->store(UploadedFile::fake()->createWithContent('lecture.pdf', "%PDF-1.4\nasset\n"), $class, 'resource', 'pdf');
        $materialId = DB::table('live_class_materials')->insertGetId([
            'school_id' => 11, 'live_class_id' => $class->id, 'type' => 'file', 'category' => 'resource',
            'title' => 'Lecture notes', 'original_name' => 'Lecture notes.pdf', 'stored_name' => $key,
            'mime_type' => 'application/pdf', 'size_bytes' => 16,
        ]);

        $response = $this->actingAs($student)->get(route('live_classes.materials.access', [$class->id, $materialId]));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringNotContainsString($key, $response->getContent() ?: '');
        $storage->deletePrivate($key, $class, 'resource');
    }

    public function test_unconfirmed_student_and_offering_legacy_basename_are_denied_without_path_disclosure(): void
    {
        $class = $this->offeringClass();
        $student = User::create(['name' => 'Pending learner', 'email' => 'pending-asset@example.test', 'role_id' => 7, 'school_id' => 11, 'status' => 1]);
        $this->grantStudyEligibilityFixture($student);
        DB::table('course_registrations')->insert(['school_id' => 11, 'student_id' => $student->id, 'course_offering_id' => 701, 'status' => 'registered']);
        $materialId = DB::table('live_class_materials')->insertGetId([
            'school_id' => 11, 'live_class_id' => $class->id, 'type' => 'file', 'category' => 'resource',
            'title' => 'Old file', 'original_name' => 'old.pdf', 'stored_name' => 'lcm'.$class->id.'_legacy.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 20,
        ]);

        $response = $this->actingAs($student)->get(route('live_classes.materials.access', [$class->id, $materialId]));
        $response->assertForbidden();
        $this->assertStringNotContainsString('lcm'.$class->id.'_legacy.pdf', $response->getContent() ?: '');

        DB::table('course_registrations')->where('student_id', $student->id)->update(['status' => 'confirmed']);
        $unavailable = $this->actingAs($student)->get(route('live_classes.materials.access', [$class->id, $materialId]));
        $unavailable->assertNotFound();
    }

    public function test_allocated_lecturer_upload_writes_only_private_file_and_keeps_original_name_as_metadata(): void
    {
        $class = $this->offeringClass();
        $lecturer = User::create(['name' => 'Allocated lecturer', 'email' => 'allocated@example.test', 'role_id' => 3, 'school_id' => 11, 'status' => 1]);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => 11, 'course_offering_id' => 701, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer', 'status' => 'active', 'starts_on' => now()->toDateString(),
        ]);

        $response = $this->actingAs($lecturer)->post(route('teacher.live_classes.materials.store', $class->id), [
            'type' => 'file', 'category' => 'resource', 'title' => 'Private handout',
            'file' => UploadedFile::fake()->createWithContent('Institutional notes.pdf', "%PDF-1.4\nprotected upload\n"),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('live_class_materials', ['live_class_id' => $class->id, 'original_name' => 'Institutional notes.pdf']);
        $material = \App\Models\LiveClassMaterial::query()->where('live_class_id', $class->id)->firstOrFail();
        $this->assertStringStartsWith('live-class-private/live-classes/11/'.$class->id.'/materials/', $material->stored_name);
        $this->assertFileExists(Storage::disk('local')->path($material->stored_name));
        $this->assertFalse(is_file(public_path($material->uploadDir().'/'.$material->stored_name)));
        $this->assertStringContainsString('/live-classes/'.$class->id.'/materials/'.$material->id.'/access', $material->url);
        app(LiveClassAssetStorage::class)->deletePrivate($material->stored_name, $class, 'resource');
    }

    public function test_private_upload_rejects_unapproved_extensions_and_over_limit_resource_files(): void
    {
        $class = $this->offeringClass();
        $lecturer = User::create(['name' => 'Allocated lecturer', 'email' => 'upload-validation@example.test', 'role_id' => 3, 'school_id' => 11, 'status' => 1]);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => 11, 'course_offering_id' => 701, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer', 'status' => 'active', 'starts_on' => now()->toDateString(),
        ]);

        $this->actingAs($lecturer)->post(route('teacher.live_classes.materials.store', $class->id), [
            'type' => 'file', 'category' => 'resource', 'title' => 'Executable',
            'file' => UploadedFile::fake()->createWithContent('bad.php', '<?php echo "no";'),
        ])->assertSessionHasErrors('file');

        $this->post(route('teacher.live_classes.materials.store', $class->id), [
            'type' => 'file', 'category' => 'resource', 'title' => 'MIME spoof',
            'file' => UploadedFile::fake()->createWithContent('spoofed.pdf', '<?php echo "not a PDF";'),
        ])->assertSessionHasErrors('file');

        $this->post(route('teacher.live_classes.materials.store', $class->id), [
            'type' => 'file', 'category' => 'resource', 'title' => 'Too large',
            'file' => UploadedFile::fake()->create('large.pdf', 20 * 1024 + 1, 'application/pdf'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('live_class_materials', 0);
    }

    public function test_external_material_and_offering_recording_redirect_only_after_exact_registration_check(): void
    {
        $class = $this->offeringClass();
        DB::table('live_classes')->where('id', $class->id)->update(['recording_url' => 'https://recordings.example.test/lecture']);
        $student = User::create(['name' => 'Confirmed learner', 'email' => 'redirect-learner@example.test', 'role_id' => 7, 'school_id' => 11, 'status' => 1]);
        $this->grantStudyEligibilityFixture($student);
        $materialId = DB::table('live_class_materials')->insertGetId([
            'school_id' => 11, 'live_class_id' => $class->id, 'type' => 'link', 'category' => 'recording',
            'title' => 'External resource', 'link_url' => 'https://resources.example.test/notes',
        ]);

        $this->actingAs($student)->get(route('live_classes.materials.access', [$class->id, $materialId]))->assertForbidden();
        $this->get(route('live_classes.recording.access', $class->id))->assertForbidden();

        DB::table('course_registrations')->insert(['school_id' => 11, 'student_id' => $student->id, 'course_offering_id' => 701, 'status' => 'confirmed']);
        $this->get(route('live_classes.materials.access', [$class->id, $materialId]))
            ->assertRedirect('https://resources.example.test/notes');
        $this->get(route('live_classes.recording.access', $class->id))
            ->assertRedirect('https://recordings.example.test/lecture');

        $class->refresh();
        $this->assertSame(route('live_classes.recording.access', $class->id), $class->safe_recording_url);
    }

    public function test_legacy_asset_accessor_keeps_its_public_compatibility_url(): void
    {
        DB::table('schools')->insertOrIgnore(['id' => 11, 'title' => 'Asset tenant']);
        DB::table('live_classes')->insertOrIgnore([
            'id' => 55, 'school_id' => 11, 'title' => 'Legacy class', 'status' => 'scheduled',
            'is_published' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacy = LiveClass::query()->findOrFail(55);
        $material = new \App\Models\LiveClassMaterial([
            'live_class_id' => $legacy->id, 'type' => 'file', 'category' => 'resource', 'stored_name' => 'legacy-file.pdf',
        ]);
        $material->setRelation('liveClass', $legacy);

        $this->assertStringContainsString('/assets/uploads/live_class_materials/legacy-file.pdf', $material->url);
        $this->assertSame(public_path('assets/uploads/live_class_materials/legacy-file.pdf'), $material->absolute_path);
    }

    public function test_exact_offering_allocation_controls_lecturer_delivery_and_ta_cannot_manage_assets(): void
    {
        $class = $this->offeringClass();
        $storage = app(LiveClassAssetStorage::class);
        $key = $storage->store(UploadedFile::fake()->createWithContent('slides.pdf', "%PDF-1.4\nslides\n"), $class, 'resource', 'pdf');
        $materialId = DB::table('live_class_materials')->insertGetId([
            'school_id' => 11, 'live_class_id' => $class->id, 'type' => 'file', 'category' => 'resource',
            'title' => 'Slides', 'original_name' => 'Slides.pdf', 'stored_name' => $key, 'mime_type' => 'application/pdf',
        ]);
        $primary = User::create(['name' => 'Primary', 'email' => 'primary-delivery@example.test', 'role_id' => 3, 'school_id' => 11, 'status' => 1]);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => 11, 'course_offering_id' => 701, 'user_id' => $primary->id,
            'role' => 'primary_lecturer', 'status' => 'active', 'starts_on' => now()->toDateString(),
        ]);
        $this->actingAs($primary)->get(route('live_classes.materials.access', [$class->id, $materialId]))->assertOk();

        $assistant = User::create(['name' => 'Assistant', 'email' => 'assistant-delivery@example.test', 'role_id' => 3, 'school_id' => 11, 'status' => 1]);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => 11, 'course_offering_id' => 701, 'user_id' => $assistant->id,
            'role' => 'teaching_assistant', 'status' => 'active', 'starts_on' => now()->toDateString(),
        ]);
        $this->actingAs($assistant)->get(route('live_classes.materials.access', [$class->id, $materialId]))->assertOk();
        $this->post(route('teacher.live_classes.materials.store', $class->id), [
            'type' => 'file', 'category' => 'resource', 'title' => 'TA upload',
            'file' => UploadedFile::fake()->createWithContent('ta.pdf', "%PDF-1.4\nnot allowed\n"),
        ])->assertForbidden();

        $unrelated = User::create(['name' => 'Unrelated', 'email' => 'unrelated-delivery@example.test', 'role_id' => 3, 'school_id' => 11, 'status' => 1]);
        DB::table('course_offerings')->insert(['id' => 702, 'school_id' => 11, 'status' => 'open']);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => 11, 'course_offering_id' => 702, 'user_id' => $unrelated->id,
            'role' => 'primary_lecturer', 'status' => 'active', 'starts_on' => now()->toDateString(),
        ]);
        $this->actingAs($unrelated)->get(route('live_classes.materials.access', [$class->id, $materialId]))->assertForbidden();
        $storage->deletePrivate($key, $class, 'resource');
    }

    public function test_asset_route_is_tenant_scoped_and_admin_bypass_does_not_cross_tenants(): void
    {
        $class = $this->offeringClass();
        $key = app(LiveClassAssetStorage::class)->store(UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\nnotes\n"), $class, 'resource', 'pdf');
        $materialId = DB::table('live_class_materials')->insertGetId([
            'school_id' => 11, 'live_class_id' => $class->id, 'type' => 'file', 'category' => 'resource',
            'title' => 'Notes', 'original_name' => 'Notes.pdf', 'stored_name' => $key, 'mime_type' => 'application/pdf',
        ]);

        $admin = User::create(['name' => 'Tenant admin', 'email' => 'tenant-admin-asset@example.test', 'role_id' => 2, 'school_id' => 11, 'status' => 1]);
        $this->actingAs($admin)->get(route('live_classes.materials.access', [$class->id, $materialId]))->assertOk();

        $foreignAdmin = User::create(['name' => 'Foreign admin', 'email' => 'foreign-admin-asset@example.test', 'role_id' => 2, 'school_id' => 22, 'status' => 1]);
        $this->actingAs($foreignAdmin)->get(route('live_classes.materials.access', [$class->id, $materialId]))->assertNotFound();
        $this->assertStringNotContainsString($key, $this->get(route('live_classes.materials.access', [$class->id, $materialId]))->getContent() ?: '');
        app(LiveClassAssetStorage::class)->deletePrivate($key, $class, 'resource');
    }

    private function offeringClass(int $id = 1): LiveClass
    {
        DB::table('schools')->insertOrIgnore(['id' => 11, 'title' => 'Asset tenant']);
        DB::table('course_offerings')->insertOrIgnore(['id' => 701, 'school_id' => 11, 'status' => 'open']);
        DB::table('live_classes')->insertOrIgnore([
            'id' => $id, 'school_id' => 11, 'title' => 'Protected class', 'course_offering_id' => 701,
            'status' => 'scheduled', 'is_published' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return LiveClass::query()->findOrFail($id);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->isDir()) $this->removeDirectory($entry->getPathname());
            else @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
