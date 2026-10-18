<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\LiveClasses\LiveClassService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

class LiveClassOfferingFoundationTest extends TestCase
{
    use LiveClassTestHelper;

    private LiveClassService $service;
    private User $actor;
    private int $subjectOne;
    private int $subjectTwo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();

        Schema::table('live_classes', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_offering_id')->nullable()->after('subject_id');
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('label');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('academic_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->string('type');
            $table->string('label');
            $table->unsignedInteger('sequence');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->string('reference')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->unique(['school_id', 'id']);
        });

        foreach ([1, 2] as $schoolId) {
            DB::table('schools')->insert(['id' => $schoolId, 'title' => "School {$schoolId}"]);
            DB::table('academic_years')->insert([
                'id' => $schoolId,
                'school_id' => $schoolId,
                'label' => '2026/27',
                'start_date' => '2026-09-01',
                'end_date' => '2027-08-31',
                'status' => 'active',
            ]);
            DB::table('academic_periods')->insert([
                'id' => $schoolId,
                'school_id' => $schoolId,
                'academic_year_id' => $schoolId,
                'type' => 'semester',
                'label' => 'Semester 1',
                'sequence' => 1,
                'start_date' => '2026-09-01',
                'end_date' => '2027-01-31',
                'status' => 'active',
            ]);
        }

        $this->subjectOne = $this->createSubject(1, 'Course A');
        $this->subjectTwo = $this->createSubject(1, 'Course B');
        $this->createSubject(2, 'Foreign Course');
        $this->actor = User::create([
            'name' => 'Allocation facilitator',
            'email' => 'facilitator@example.test',
            'role_id' => 3,
            'school_id' => 1,
            'status' => 1,
        ]);
        Auth::login($this->actor);
        $this->service = new LiveClassService();
    }

    public function test_legacy_class_without_offering_remains_valid_and_unchanged(): void
    {
        $legacy = LiveClass::create([
            'school_id' => 1,
            'title' => 'Legacy K12 lesson',
            'subject_id' => $this->subjectOne,
            'class_id' => 5,
            'academic_session_id' => 8,
            'programme_id' => 12,
            'teacher_id' => $this->actor->id,
        ]);

        $this->assertNull($legacy->course_offering_id);
        $this->assertSame(5, (int) $legacy->class_id);
        $this->assertSame(8, (int) $legacy->academic_session_id);
        $this->assertSame(12, (int) $legacy->programme_id);
    }

    public function test_creation_derives_offering_context_and_keeps_creator_separate_from_optional_facilitator(): void
    {
        $offeringId = $this->createOffering(1, $this->subjectOne, CourseOffering::STATUS_OPEN, 'SHARED-1');
        $class = $this->service->createForOffering($this->actor, $offeringId, [
            'title' => 'Allocation meeting',
            'timezone' => 'Africa/Lagos',
        ]);

        $this->assertSame($offeringId, (int) $class->course_offering_id);
        $this->assertSame(1, (int) $class->school_id);
        $this->assertSame($this->subjectOne, (int) $class->subject_id);
        $this->assertNull($class->programme_id);
        $this->assertNull($class->academic_session_id);
        $this->assertSame($this->actor->id, (int) $class->created_by);
        $this->assertNull($class->teacher_id);
        $this->assertSame('2026/27', $class->courseOffering->academicYear->label);
        $this->assertSame('Semester 1', $class->courseOffering->academicPeriod->label);
        $this->assertSame('Africa/Lagos', $class->timezone);
    }

    public function test_only_open_and_in_progress_offerings_allow_new_classes(): void
    {
        foreach ([
            CourseOffering::STATUS_DRAFT => false,
            CourseOffering::STATUS_OPEN => true,
            CourseOffering::STATUS_IN_PROGRESS => true,
            CourseOffering::STATUS_COMPLETED => false,
            CourseOffering::STATUS_CANCELLED => false,
        ] as $status => $allowed) {
            $offeringId = $this->createOffering(1, $this->subjectOne, $status);
            if ($allowed) {
                $this->assertSame($offeringId, (int) $this->service->createForOffering($this->actor, $offeringId, ['title' => $status])->course_offering_id);
            } else {
                try {
                    $this->service->createForOffering($this->actor, $offeringId, ['title' => $status]);
                    $this->fail("{$status} Allocation must not accept a new operational Live Class.");
                } catch (DomainException $exception) {
                    $this->assertStringContainsString('open or in-progress', $exception->getMessage());
                }
            }
        }
    }

    public function test_tenant_and_subject_consistency_are_enforced_and_parallel_offerings_stay_distinct(): void
    {
        $foreignOffering = $this->createOffering(2, $this->createSubject(2, 'Foreign'), CourseOffering::STATUS_OPEN);
        $localOffering = $this->createOffering(1, $this->subjectOne, CourseOffering::STATUS_OPEN);
        $parallelOffering = $this->createOffering(1, $this->subjectOne, CourseOffering::STATUS_OPEN);

        $this->assertDomainFailure(fn () => $this->service->createForOffering($this->actor, $foreignOffering, ['title' => 'Cross tenant']));
        $this->assertDomainFailure(fn () => $this->service->createForOffering($this->actor, $localOffering, ['title' => 'Mismatch', 'subject_id' => $this->subjectTwo]));
        $this->assertDomainFailure(fn () => $this->service->createForOffering($this->actor, $localOffering, ['title' => 'Programme inference', 'programme_id' => 33]));

        $first = $this->service->createForOffering($this->actor, $localOffering, ['title' => 'First delivery']);
        $second = $this->service->createForOffering($this->actor, $parallelOffering, ['title' => 'Parallel delivery']);
        $this->assertSame($this->subjectOne, (int) $first->subject_id);
        $this->assertSame($this->subjectOne, (int) $second->subject_id);
        $this->assertNotSame((int) $first->course_offering_id, (int) $second->course_offering_id);
        $this->assertNull($first->programme_id, 'One shared Allocation is not duplicated by Programme.');
        $this->assertNull($second->programme_id);
    }

    public function test_context_mutation_is_blocked_and_offering_lifecycle_changes_preserve_history(): void
    {
        $offeringId = $this->createOffering(1, $this->subjectOne, CourseOffering::STATUS_IN_PROGRESS);
        $class = $this->service->createForOffering($this->actor, $offeringId, ['title' => 'Preserved meeting']);

        $this->assertDomainFailure(fn () => $this->service->updateOfferingMeeting($this->actor, $class->id, ['subject_id' => $this->subjectTwo]));
        $this->assertDomainFailure(fn () => $class->update(['subject_id' => $this->subjectTwo]));
        $this->assertDomainFailure(fn () => $class->update(['school_id' => 2]));
        $this->assertDomainFailure(fn () => $class->delete());

        DB::table('course_offerings')->where('id', $offeringId)->update(['status' => CourseOffering::STATUS_COMPLETED]);
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'course_offering_id' => $offeringId]);
        $this->assertDomainFailure(fn () => $this->service->createForOffering($this->actor, $offeringId, ['title' => 'Too late']));

        DB::table('course_offerings')->where('id', $offeringId)->update(['status' => CourseOffering::STATUS_CANCELLED]);
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'course_offering_id' => $offeringId]);
        $this->assertSame(LiveClass::STATUS_CANCELLED, $this->service->cancelOfferingMeeting($this->actor, $class->id)->status);
        $this->assertDatabaseHas('live_classes', ['id' => $class->id, 'course_offering_id' => $offeringId, 'status' => LiveClass::STATUS_CANCELLED]);
    }

    private function createSubject(int $schoolId, string $name): int
    {
        return (int) DB::table('subjects')->insertGetId([
            'name' => $name,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOffering(int $schoolId, int $subjectId, string $status, ?string $reference = null): int
    {
        return (int) DB::table('course_offerings')->insertGetId([
            'school_id' => $schoolId,
            'subject_id' => $subjectId,
            'academic_year_id' => $schoolId,
            'academic_period_id' => $schoolId,
            'reference' => $reference,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the Allocation-backed Live Class domain guard to reject the operation.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
