<?php

namespace Tests\Feature\Support;

use App\Models\Assignment;
use App\Models\AssignmentResource;
use App\Models\AssignmentSubmission;
use App\Models\CourseOffering;
use App\Models\CourseOfferingModule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course-Offering Assignments fixture.
 *
 * BUILT ON THE EXISTING CHAIN, NOT A NEW ONE
 *
 * An assignment is only meaningful inside a Course Offering, so every test needs
 * an institution, an in-progress Offering, an allocated lecturer and a confirmed
 * student. CourseContentFixture already builds exactly that, so this composes it
 * rather than rebuilding a subtly different chain.
 *
 * THE TABLES ARE DECLARED WITH BOTH THE LEGACY AND THE COURSE-OFFERING COLUMNS
 *
 * The real `assignments` table carries Subject/Class/Teacher columns alongside
 * the new Course Offering ones. A fixture declaring only the new columns would
 * test a schema PIIE does not run, and - worse - would hide any query that still
 * reaches for a legacy column. The legacy `is_published` flag and the
 * `submission_type` / `status` enums are reproduced with their REAL definitions,
 * including the legacy `('submitted','late','graded')` enum on submissions, which
 * the K12 screens still read.
 */
trait AssignmentFixture
{
    use CourseContentFixture {
        // Alias, so the parent's setUp stays reachable and this trait's runs
        // after it rather than shadowing it.
        setUp as protected courseContentSetUp;
    }

    protected function setUp(): void
    {
        $this->courseContentSetUp();

        $this->assignmentSchema();
    }

    private function assignmentSchema(): void
    {
        if (! Schema::hasTable('assignments')) {
            Schema::create('assignments', function (Blueprint $t): void {
                // ── legacy K12 shape, exactly as production ──
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->string('title', 255);
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->unsignedBigInteger('class_id')->nullable();
                $t->unsignedBigInteger('teacher_id')->nullable();
                $t->text('instructions')->nullable();
                $t->dateTime('due_date')->nullable();
                $t->integer('max_marks')->default(100);
                // The REAL production enum, including 'link' - which the Course
                // Offering surfaces deliberately never offer.
                $t->enum('submission_type', ['file', 'text', 'link', 'any'])->default('any');
                $t->boolean('is_published')->default(true);
                $t->timestamps();

                // ── Course Offering shape, as added by the migration ──
                $t->unsignedBigInteger('course_offering_id')->nullable()->index('asg_offering_idx');
                $t->text('learning_objectives')->nullable();
                $t->string('status', 20)->default('draft');
                $t->dateTime('released_at')->nullable();
                $t->dateTime('closes_at')->nullable();
                $t->unsignedSmallInteger('allowed_attempts')->nullable();
                $t->string('late_policy', 20)->default('allow');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->dateTime('closed_at')->nullable();
                $t->unsignedBigInteger('closed_by')->nullable();

                // ── Module/chapter tasks, with the PRODUCTION defaults ──
                // 'optional' is the safe default and the whole point: no
                // assignment may start gating a student because a schema was
                // declared. A fixture with no default would hide that.
                $t->unsignedBigInteger('course_offering_module_id')->nullable()->index('asg_module_idx');
                $t->string('requirement_role', 20)->default('optional');
                $t->string('completion_rule', 30)->default('submission');
                $t->string('submission_kinds', 120)->nullable();
            });

            Schema::table('assignments', function (Blueprint $t): void {
                $t->index(['course_offering_id', 'status'], 'asg_offering_status_idx');
                $t->index(['course_offering_module_id', 'requirement_role'], 'asg_module_role_idx');
            });
        }

        if (! Schema::hasTable('assignment_submissions')) {
            Schema::create('assignment_submissions', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('assignment_id');
                $t->unsignedBigInteger('student_id');
                $t->text('submission')->nullable();
                $t->string('file_path', 255)->nullable();
                $t->string('link', 255)->nullable();
                $t->dateTime('submitted_at')->nullable();
                $t->decimal('marks_awarded', 6, 2)->nullable();
                $t->text('feedback')->nullable();
                // The REAL legacy enum, still read by the K12 screens.
                $t->enum('status', ['submitted', 'late', 'graded'])->default('submitted');
                $t->timestamps();

                // ── Course Offering shape ──
                $t->unsignedBigInteger('course_offering_id')->nullable()->index('asub_offering_idx');
                $t->unsignedSmallInteger('attempt_no')->default(1);
                $t->boolean('is_draft')->default(false);
                $t->string('file_name', 191)->nullable();
                $t->unsignedBigInteger('file_size')->nullable();
                $t->string('file_mime', 100)->nullable();
                $t->dateTime('graded_at')->nullable();
                $t->unsignedBigInteger('graded_by')->nullable();
                $t->dateTime('returned_at')->nullable();
                $t->unsignedBigInteger('returned_by')->nullable();
                $t->dateTime('marks_released_at')->nullable();
                $t->string('idempotency_key', 64)->nullable();
                // The canonical rich-text response for a Course Offering
                // submission. The legacy `submission` TEXT column is left exactly
                // as production has it, for the K12 screens.
                $t->longText('text_response')->nullable();
            });

            Schema::table('assignment_submissions', function (Blueprint $t): void {
                $t->unique(['assignment_id', 'student_id', 'attempt_no'], 'asub_attempt_unique');
                $t->unique('idempotency_key', 'asub_idempotency_unique');
                $t->index(['course_offering_id', 'student_id'], 'asub_offering_student_idx');
            });
        }

        // The cover image capability. Declared here so the relationship can be
        // exercised; NOTHING renders it yet, because the visual card redesign is
        // explicitly deferred.
        if (! Schema::hasColumn('course_offerings', 'cover_image_path')) {
            Schema::table('course_offerings', function (Blueprint $t): void {
                $t->string('cover_image_path', 255)->nullable();
                $t->string('cover_image_name', 191)->nullable();
                $t->string('cover_image_mime', 100)->nullable();
                $t->unsignedBigInteger('cover_image_size')->nullable();
                $t->dateTime('cover_image_updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('assignment_resources')) {
            Schema::create('assignment_resources', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('course_offering_id')->nullable();
                $t->unsignedBigInteger('assignment_id');
                $t->string('title', 191);
                $t->string('type', 20)->default('link');
                $t->string('link_url', 500)->nullable();
                $t->string('original_name', 191)->nullable();
                $t->string('stored_name', 255)->nullable();
                $t->string('mime_type', 100)->nullable();
                $t->unsignedBigInteger('size_bytes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('assignment_submission_items')) {
            Schema::create('assignment_submission_items', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('assignment_submission_id')->index('asubitem_submission_idx');
                $t->unsignedBigInteger('assignment_id')->index();
                $t->unsignedBigInteger('course_offering_id')->nullable()->index();
                // A varchar, NOT an enum: production keeps the kind set open so
                // in-browser recording is an additive change rather than a MySQL
                // table rebuild. The allowed set is enforced in the model and by
                // validation, exactly as here.
                $t->string('kind', 20);
                $t->string('label', 191)->nullable();
                $t->string('stored_path', 255)->nullable();
                $t->string('original_name', 191)->nullable();
                $t->string('mime_type', 100)->nullable();
                $t->unsignedBigInteger('size_bytes')->nullable();
                $t->string('url', 500)->nullable();
                $t->text('note')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        // ── Which question this evidence answers, and where the bytes came from ──
        //
        // Both NULLABLE, and that is the point: NULL is what every pre-existing
        // row and every K12 row carries, and it is what a GENERIC assignment's
        // evidence is. A fixture that defaulted these would test a schema PIIE
        // does not run, and would hide the compatibility path entirely.
        if (Schema::hasTable('assignment_submission_items')) {
            Schema::table('assignment_submission_items', function (Blueprint $t): void {
                if (! Schema::hasColumn('assignment_submission_items', 'assignment_question_id')) {
                    $t->unsignedBigInteger('assignment_question_id')->nullable()->index('asubitem_question_idx');
                }
                if (! Schema::hasColumn('assignment_submission_items', 'capture_method')) {
                    $t->string('capture_method', 20)->nullable();
                }
            });
        }

        $this->questionSchema();
    }

    /**
     * The two question tables, matching the production migration.
     */
    private function questionSchema(): void
    {
        if (! Schema::hasTable('assignment_questions')) {
            Schema::create('assignment_questions', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('school_id')->index();
                $t->unsignedBigInteger('course_offering_id')->nullable()->index();
                $t->unsignedBigInteger('assignment_id')->index('asgq_assignment_idx');
                $t->longText('prompt');
                $t->string('heading', 191)->nullable();
                $t->unsignedInteger('sequence')->default(1);
                $t->decimal('marks', 6, 2)->default(0);
                // Required within the assignment. TRUE by default, unlike
                // `requirement_role`, because this can only ever block an
                // INCOMPLETE answer to a question the student can see.
                $t->boolean('is_required')->default(true);
                // NULL means "not configured", NOT "accepts anything".
                $t->string('response_kinds', 120)->nullable();
                // false = ANY ONE of the listed kinds satisfies the question, which
                // is what an oral or demonstration question means.
                $t->boolean('require_all')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });

            Schema::table('assignment_questions', function (Blueprint $t): void {
                // Deliberately NOT unique on sequence: a lecturer part-way through a
                // drag would otherwise hit a constraint violation on an
                // intermediate state.
                $t->index(['assignment_id', 'sequence'], 'asgq_assignment_seq_idx');
            });
        }

        if (! Schema::hasTable('assignment_question_responses')) {
            Schema::create('assignment_question_responses', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('school_id')->index();
                $t->unsignedBigInteger('assignment_question_id')->index('asgres_question_idx');
                $t->unsignedBigInteger('assignment_id')->index();
                $t->unsignedBigInteger('assignment_submission_id')->index('asgres_submission_idx');
                $t->unsignedBigInteger('course_offering_id')->nullable()->index();
                $t->longText('text_response')->nullable();
                $t->decimal('marks_awarded', 6, 2)->nullable();
                $t->text('feedback')->nullable();
                $t->dateTime('graded_at')->nullable();
                $t->unsignedBigInteger('graded_by')->nullable();
                $t->timestamps();
            });

            Schema::table('assignment_question_responses', function (Blueprint $t): void {
                // THE idempotency guarantee for draft saving: five saves of one
                // draft is still one row per question, decided by the database
                // rather than by a read-then-write check.
                $t->unique(
                    ['assignment_submission_id', 'assignment_question_id'],
                    'asgres_submission_question_unq'
                );
            });
        }

        if (! Schema::hasTable('assignment_notifications')) {
            Schema::create('assignment_notifications', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('assignment_id');
                $t->unsignedBigInteger('submission_id')->nullable();
                $t->string('dedup_key', 191);
                $t->string('type', 40);
                $t->unsignedInteger('recipient_count')->default(0);
                $t->timestamp('sent_at');
                $t->timestamps();
                $t->unique('dedup_key', 'asn_dedup_unique');
            });
        }
    }

    // ── builders ──────────────────────────────────────────────────────────

    /**
     * A Course Offering assignment, draft by default.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function assignment(array $attributes = []): Assignment
    {
        return Assignment::query()->create(array_merge([
            'school_id' => $this->school,
            'title' => 'Assignment 1 — Business Ratios',
            'course_offering_id' => $this->offering->id,
            'instructions' => '<p>Answer question 1 to 5.</p>',
            'max_marks' => 20,
            'submission_type' => Assignment::SUBMISSION_FILE_AND_TEXT,
            'status' => Assignment::STATUS_DRAFT,
            'released_at' => Carbon::now()->subDay(),
            'due_date' => Carbon::now()->addWeek(),
            'late_policy' => Assignment::LATE_ALLOWED,
            'allowed_attempts' => 1,
            'is_published' => false,
            'created_by' => $this->lecturer->id,
        ], $attributes));
    }

    /** A published assignment students can currently open and submit to. */
    protected function publishedAssignment(array $attributes = []): Assignment
    {
        return $this->assignment(array_merge([
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
        ], $attributes));
    }

    /**
     * A task attached to a module, OPTIONAL by default.
     *
     * Optional is the default in the builder as well as in the schema, so a test
     * that means "a required task" has to say so - which mirrors exactly what a
     * lecturer has to do.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function moduleTask(
        \App\Models\CourseOfferingModule $module,
        array $attributes = []
    ): Assignment {
        // PUBLISHED and RELEASED, because a task a student cannot open cannot be
        // one they are expected to complete. A draft is available by passing
        // `status` explicitly, which is how the "invisible and non-blocking" rule
        // is tested rather than being a side effect of the fixture.
        return $this->assignment(array_merge([
            'course_offering_module_id' => $module->id,
            'requirement_role' => \App\Models\Assignment::ROLE_OPTIONAL,
            'title' => 'Module task — '.$module->title,
            'status' => \App\Models\Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
        ], $attributes));
    }

    /**
     * A task that GATES its module's completion.
     *
     * Defaults to the `submission` rule because that is the stricter assertion in
     * the sense that matters for a test: it is satisfied by a real attempt, so a
     * test must actually submit before it can claim satisfaction. `released_mark`
     * has its own explicit tests.
     */
    protected function requiredModuleTask(
        \App\Models\CourseOfferingModule $module,
        array $attributes = []
    ): Assignment {
        return $this->moduleTask($module, array_merge([
            'requirement_role' => \App\Models\Assignment::ROLE_REQUIRED,
            'completion_rule' => \App\Models\Assignment::RULE_SUBMISSION,
            'title' => 'Required task — '.$module->title,
        ], $attributes));
    }

    // ── Questions ───────────────────────────────────────────────────────────

    /**
     * One question on an assignment.
     *
     * Sequence is assigned by the caller or defaults to the next free slot, so
     * `question()` and `questionPaper()` produce a reading order that is correct
     * without the caller thinking about it.
     *
     * `response_kinds` DEFAULTS TO NULL - "not configured" - rather than to some
     * sensible default kind. A fixture that quietly configured an answer type
     * would mean the "at least one answer type chosen" publication rule could never
     * be tested.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function question(Assignment $assignment, array $attributes = []): \App\Models\AssignmentQuestion
    {
        $next = (int) \App\Models\AssignmentQuestion::query()
            ->where('assignment_id', $assignment->id)
            ->max('sequence');

        return \App\Models\AssignmentQuestion::query()->create(array_merge([
            'school_id' => $assignment->school_id,
            'course_offering_id' => $assignment->course_offering_id,
            'assignment_id' => $assignment->id,
            'prompt' => '<p>Answer this question.</p>',
            'sequence' => $next + 1,
            'marks' => 5,
            'is_required' => true,
            'response_kinds' => \App\Models\AssignmentSubmissionItem::KIND_TEXT,
            'created_by' => $this->lecturer->id,
        ], $attributes));
    }

    /**
     * A question asking for exactly one kind of evidence.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function questionAccepting(Assignment $assignment, string $kind, array $attributes = []): \App\Models\AssignmentQuestion
    {
        return $this->question($assignment, array_merge([
            'response_kinds' => $kind,
        ], $attributes));
    }

    /**
     * A question accepting a deliberate COMBINATION, where one of them is enough.
     *
     * `require_all` stays false, because "record audio OR upload audio" is the
     * ordinary reading and "do all three" is the unusual one a test has to ask for.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $kinds
     */
    protected function questionAcceptingAnyOf(Assignment $assignment, array $kinds, array $attributes = []): \App\Models\AssignmentQuestion
    {
        return $this->question($assignment, array_merge([
            'response_kinds' => implode(',', $kinds),
            'require_all' => false,
        ], $attributes));
    }

    /**
     * A WHOLE question-based paper, and nothing about the assignment's total.
     *
     * The four questions from the brief, in the lecturer's own order, each worth
     * five marks - so the paper totals 20 and a test can then deliberately break
     * the relationship in either direction and assert that publication is refused.
     *
     * A COLLECTION, not a plain array. It builds a list by appending, and a plain
     * array has no `pluck()`, `map()` or `values()` - so every test that treated
     * this as a collection of questions had to reach for `array_column`, and one of
     * them did not. A Collection is both the natural return type and the one the
     * domain uses everywhere else.
     *
     * @param  array<string, mixed>  $assignmentAttributes
     * @return \Illuminate\Support\Collection<int, \App\Models\AssignmentQuestion>
     */
    protected function questionPaper(Assignment $assignment, array $assignmentAttributes = []): \Illuminate\Support\Collection
    {
        $k = \App\Models\AssignmentSubmissionItem::class;

        return collect([
            $this->questionAccepting($assignment, $k::KIND_TEXT, [
                'heading' => 'Gross profit',
                'prompt' => '<p>Calculate the gross profit from the figures given.</p>',
                'marks' => 5,
            ]),
            $this->questionAccepting($assignment, $k::KIND_IMAGE, [
                'heading' => 'Photograph of working',
                'prompt' => '<p>Upload a photograph of your working.</p>',
                'marks' => 5,
            ]),
            $this->questionAcceptingAnyOf($assignment, [$k::KIND_AUDIO], [
                'heading' => 'Explain orally',
                'prompt' => '<p>Explain your reasoning orally.</p>',
                'marks' => 5,
            ]),
            $this->questionAcceptingAnyOf($assignment, [$k::KIND_VIDEO, $k::KIND_LINK], [
                'heading' => 'Demonstrate the solution',
                'prompt' => '<p>Demonstrate the solution.</p>',
                'marks' => 5,
            ]),
        ])->values();
    }

    /**
     * A student's answer to one question on one attempt.
     *
     * CREATED WHETHER OR NOT THE STUDENT ANSWERED - which is the design: the row's
     * existence carries no meaning and its columns do, so "not answered" is a
     * stored fact rather than an inference from a missing row that four different
     * screens would have to make identically.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function answer(
        \App\Models\AssignmentQuestion $question,
        AssignmentSubmission $submission,
        array $attributes = []
    ): \App\Models\AssignmentQuestionResponse {
        return \App\Models\AssignmentQuestionResponse::query()->create(array_merge([
            'school_id' => $question->school_id,
            'assignment_question_id' => $question->id,
            'assignment_id' => $question->assignment_id,
            'assignment_submission_id' => $submission->id,
            'course_offering_id' => $question->course_offering_id,
        ], $attributes));
    }

    /**
     * One piece of evidence filed against one question on one attempt.
     *
     * `assignment_question_id` is what makes it a question's evidence rather than
     * assignment-level evidence, and `capture_method` is how a recording is
     * distinguished from a chosen file - provenance only, never proof that
     * anything arrived.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function questionEvidence(
        \App\Models\AssignmentQuestion $question,
        AssignmentSubmission $submission,
        string $kind,
        array $attributes = []
    ): \App\Models\AssignmentSubmissionItem {
        // FULLY QUALIFIED, and not only in the return type. An earlier fix corrected
        // the signature and left `AssignmentSubmissionItem::query()` in the body,
        // which resolves against the TRAIT'S own namespace
        // (`Tests\Feature\Support\AssignmentSubmissionItem`) because this file
        // imports no such class. The signature looked right and the body was still
        // wrong - which is the shape of mistake a return type is supposed to
        // prevent rather than hide.
        return \App\Models\AssignmentSubmissionItem::query()->create(array_merge([
            'school_id' => $question->school_id,
            'assignment_submission_id' => $submission->id,
            'assignment_id' => $question->assignment_id,
            'course_offering_id' => $question->course_offering_id,
            'assignment_question_id' => $question->id,
            'kind' => $kind,
        ], $attributes));
    }

    protected function submission(Assignment $assignment, \App\Models\User $student, array $attributes = []): AssignmentSubmission
    {
        return AssignmentSubmission::query()->create(array_merge([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'course_offering_id' => $assignment->course_offering_id,
            'attempt_no' => 1,
            'is_draft' => false,
            'submitted_at' => Carbon::now()->subHour(),
            'submission' => 'My written answer.',
            'status' => 'submitted',
        ], $attributes));
    }

    protected function assignmentResource(Assignment $assignment, array $attributes = []): AssignmentResource
    {
        return AssignmentResource::query()->create(array_merge([
            'school_id' => $assignment->school_id,
            'course_offering_id' => $assignment->course_offering_id,
            'assignment_id' => $assignment->id,
            'title' => 'Dataset',
            'type' => AssignmentResource::TYPE_LINK,
            'link_url' => 'https://example.org/dataset',
            'created_by' => $this->lecturer->id,
        ], $attributes));
    }

    /**
     * A LEGACY K12 assignment: no course_offering_id, keyed on Subject/Class.
     *
     * Used to prove the domain partition is load-bearing and that K12 behaviour
     * is unchanged - these rows are what the legacy screens were built to serve.
     */
    protected function k12Assignment(array $attributes = []): Assignment
    {
        return Assignment::query()->create(array_merge([
            'school_id' => $this->school,
            'title' => 'K12 homework: fractions',
            'subject_id' => $this->subject,
            'class_id' => null,
            'teacher_id' => $this->lecturer->id,
            'instructions' => '<p>Page 4 questions 1 to 6.</p>',
            'due_date' => Carbon::now()->addWeek(),
            'max_marks' => 10,
            'submission_type' => 'any',
            'is_published' => true,
            // A legacy row has no HEI lifecycle: it is "published" purely by the
            // legacy flag, exactly as the K12 screens read it.
            'status' => Assignment::STATUS_DRAFT,
        ], $attributes));
    }

    /**
     * A WHOLE second tenant with its own Offering, lecturer, student, assignment
     * and submission.
     *
     * Cross-tenant and cross-Offering denial can only be tested against content
     * that genuinely belongs somewhere else, so this builds one.
     *
     * @return array{schoolId:int, offeringId:int, lecturer:\App\Models\User, student:\App\Models\User, assignmentId:int, submissionId:int}
     */
    protected function otherTenantAssignment(): array
    {
        $schoolId = (int) DB::table('schools')->insertGetId([
            'title' => 'Second Institution', 'school_type' => 'higher_ed', 'education_level' => 'tertiary',
            'timezone' => 'Africa/Nairobi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $subjectId = (int) DB::table('subjects')->insertGetId([
            'school_id' => $schoolId, 'name' => 'Business Mathematics', 'code' => 'BBIT1103',
        ]);

        $offeringId = (int) DB::table('course_offerings')->insertGetId([
            'school_id' => $schoolId, 'subject_id' => $subjectId,
            'academic_year_id' => $this->year, 'academic_period_id' => $this->period,
            'reference' => 'BBIT1103-2026-S1', 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lecturer = $this->tenantUser('Nabirye Sarah', 3, $schoolId);
        $student = $this->tenantUser('Okello Peter', 7, $schoolId);

        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $schoolId, 'course_offering_id' => $offeringId, 'user_id' => $lecturer->id,
            'role' => \App\Models\CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('course_registrations')->insert([
            'school_id' => $schoolId, 'student_id' => $student->id, 'subject_id' => $subjectId,
            'course_offering_id' => $offeringId, 'status' => 'confirmed',
        ]);

        $assignmentId = (int) DB::table('assignments')->insertGetId([
            'school_id' => $schoolId, 'title' => 'Other tenant assignment',
            'course_offering_id' => $offeringId, 'instructions' => '<p>Theirs.</p>',
            'max_marks' => 15, 'submission_type' => 'text', 'status' => 'published',
            'is_published' => 1, 'released_at' => now()->subDay(), 'due_date' => now()->addWeek(),
            'late_policy' => 'allow', 'created_by' => $lecturer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $submissionId = (int) DB::table('assignment_submissions')->insertGetId([
            'assignment_id' => $assignmentId, 'student_id' => $student->id,
            'course_offering_id' => $offeringId, 'attempt_no' => 1, 'is_draft' => 0,
            'submitted_at' => now()->subHour(), 'submission' => 'Their answer.',
            'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('schoolId', 'offeringId', 'lecturer', 'student', 'assignmentId', 'submissionId');
    }

    /**
     * A second Offering in the SAME school.
     *
     * Needed because cross-Offering denial is a different failure from
     * cross-tenant denial: both ids are valid, the tenant is the same, and only
     * the Offering differs. A second tenant alone would not prove the Offering
     * boundary, because the school check would catch the request anyway.
     */
    protected function secondOfferingAssignment(): array
    {
        $offeringId = (int) DB::table('course_offerings')->insertGetId([
            'school_id' => $this->school, 'subject_id' => $this->subject,
            'academic_year_id' => $this->year, 'academic_period_id' => $this->period,
            'reference' => 'BBIT1103-2026-S2', 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $assignmentId = (int) DB::table('assignments')->insertGetId([
            'school_id' => $this->school, 'title' => 'Other offering assignment',
            'course_offering_id' => $offeringId, 'instructions' => '<p>Second delivery.</p>',
            'max_marks' => 10, 'submission_type' => 'text', 'status' => 'published',
            'is_published' => 1, 'released_at' => now()->subDay(), 'due_date' => now()->addWeek(),
            'late_policy' => 'allow', 'created_by' => $this->lecturer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['offeringId' => $offeringId, 'assignmentId' => $assignmentId];
    }

    /**
     * A second CONFIRMED registrant on the SAME Offering.
     *
     * Shared rather than copied into each test class, because it encodes one
     * rule - a second confirmed student exists on the SAME Offering, in the same
     * tenant - and two private copies of the same rule will drift.
     *
     * It is needed for any test about ownership: a test that asserts one student
     * cannot read another's submission or evidence is asserting nothing unless
     * there IS another student to try.
     */
    protected function secondConfirmedStudent(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create([
            'name' => 'Wandera Grace',
            'email' => 'wandera.grace.'.uniqid().'@student.example.test',
            'role_id' => 7,
            'school_id' => $this->school,
            'account_status' => 'active',
        ]);

        DB::table('course_registrations')->insert([
            'school_id' => $this->school,
            'student_id' => $user->id,
            'subject_id' => $this->subject,
            'course_offering_id' => $this->offering->id,
            'status' => 'confirmed',
        ]);

        return $user->fresh();
    }
    private function tenantUser(string $name, int $role, int $schoolId): \App\Models\User
    {
        return \App\Models\User::factory()->create([
            'name' => $name,
            'email' => str_replace([' ', '.'], ['.', ''], strtolower($name))."{$role}.{$schoolId}@other.example.test",
            'role_id' => $role, 'school_id' => $schoolId, 'account_status' => 'active',
            'password' => bcrypt('User#2026'),
        ]);
    }
}
