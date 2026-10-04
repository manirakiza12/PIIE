<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionItem;
use App\Support\Assignments\QuestionResponseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * WHAT A BROWSER CANNOT DO: a file input is not repopulated on a page load.
 *
 * ── WHY THIS NEEDS ITS OWN SUITE ──────────────────────────────────────────
 * The answer-contract tests post payloads they build, so they can include a file on
 * every request. A real browser cannot.
 *
 * The student saves a draft with a photograph, a document and two browser
 * recordings. They come back later - or simply press Submit - and the browser
 * re-posts the page: the textareas arrive, the URL box arrives, and EVERY file
 * slot arrives EMPTY, because the browser has no way to put a file back into an
 * input. A recording is worse: the Blob that held it died with the page.
 *
 * So the naive reading of an empty slot - "there is nothing here" - destroys the
 * work on the very button the student used to hand it in. The first real manual
 * test of this feature hit exactly that, having already produced two genuine
 * `browser_recording` items on its draft.
 *
 * ── THE RULE THESE TESTS PIN ──────────────────────────────────────────────
 *
 *   AN UNUSED SLOT IS A STATEMENT OF NO CHANGE.
 *
 * Not of emptiness. The stored evidence survives a Submit, a page reload, and a
 * failed validation. It is removed only when the student ticks the Withdraw box
 * the form renders beside an attached file - because a file input cannot express
 * removal, and the product therefore has to offer a control that can.
 */
class AssignmentEvidenceCarryForwardTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    private const K = AssignmentSubmissionItem::class;

    protected function setUp(): void
    {
        $this->assignmentSetUp();
        Storage::fake('local');
    }

    /**
     * The paper from the real manual test: 4 questions, 5 marks each, and a mix of
     * accepted kinds that is not uniform.
     *
     * @return array{0: Assignment, 1: list<\App\Models\AssignmentQuestion>}
     */
    /**
     * @param  array<string, mixed>  $assignmentOverrides
     * @return array{0: Assignment, 1: list<\App\Models\AssignmentQuestion>}
     */
    private function paper(array $assignmentOverrides = []): array
    {
        $k = self::K;

        $assignment = $this->publishedAssignment(array_merge([
            'title' => 'Module 1 Assessment',
            'max_marks' => 20,
            'due_date' => now()->addWeek(),
            'allowed_attempts' => 1,
        ], $assignmentOverrides));

        return [$assignment, [
            $this->questionAcceptingAnyOf($assignment, [$k::KIND_TEXT, $k::KIND_DOCUMENT], ['marks' => 5]),
            $this->questionAcceptingAnyOf($assignment, [$k::KIND_TEXT, $k::KIND_IMAGE], ['marks' => 5]),
            $this->questionAccepting($assignment, $k::KIND_AUDIO, ['marks' => 5]),
            $this->questionAccepting($assignment, $k::KIND_VIDEO, ['marks' => 5]),
        ]];
    }

    /**
     * The first request: every question answered properly, all four evidence
     * items attached, saved as a DRAFT.
     */
    private function saveAFullDraft(Assignment $assignment, array $questions): AssignmentSubmission
    {
        $k = self::K;

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => [
                        'text' => '<p>Gross profit is 4,200.</p>',
                        'items' => [[
                            'kind' => $k::KIND_DOCUMENT,
                            'file' => UploadedFile::fake()->create('working.pdf', 10, 'application/pdf'),
                        ]],
                    ],
                    (string) $questions[1]->id => [
                        'items' => [[
                            'kind' => $k::KIND_IMAGE,
                            'file' => UploadedFile::fake()->image('working.png'),
                        ]],
                    ],
                    (string) $questions[2]->id => [
                        'items' => [[
                            'kind' => $k::KIND_AUDIO,
                            'file' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
                            'capture_method' => $k::CAPTURE_BROWSER,
                        ]],
                    ],
                    (string) $questions[3]->id => [
                        'items' => [[
                            'kind' => $k::KIND_VIDEO,
                            'file' => UploadedFile::fake()->create('recording.webm', 40, 'video/webm'),
                            'capture_method' => $k::CAPTURE_BROWSER,
                        ]],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $draft = AssignmentSubmission::query()->where('is_draft', true)->firstOrFail();

        $this->assertSame(4, AssignmentSubmissionItem::query()->count());

        return $draft;
    }

    /**
     * The SECOND request, exactly as a browser sends it on Submit: the textareas
     * and the hidden `kind` inputs, and NOT ONE FILE.
     *
     * @return array<string, mixed>
     */
    private function resubmitWithoutFiles(Assignment $assignment, array $questions): array
    {
        $answers = [];

        foreach ($questions as $question) {
            $payload = ['text' => null, 'items' => []];

            if ($question->acceptsWrittenResponse()) {
                $payload['text'] = '<p>Gross profit is 4,200.</p>';
            }

            // The hidden `kind` input, which the form ALWAYS renders. Its presence
            // is the whole bug: it cannot distinguish "I used this slot" from "this
            // slot exists".
            foreach ($question->acceptedFileKinds() as $kind) {
                $payload['items'][] = ['kind' => $kind];
            }

            $answers[(string) $question->id] = $payload;
        }

        return $answers;
    }

    // ══════════════════════════════════════════════════════════════════════

    public function test_a_submit_with_no_files_resent_keeps_every_saved_evidence_item(): void
    {
        [$assignment, $questions] = $this->paper();
        $this->saveAFullDraft($assignment, $questions);

        $before = AssignmentSubmissionItem::query()->orderBy('id')->get()
            ->map(fn ($item) => [$item->kind, $item->stored_path])->all();

        // The Submit. No files, exactly as a browser sends it.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => $this->resubmitWithoutFiles($assignment, $questions),
                'idempotency_key' => 'k1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $submission = AssignmentSubmission::query()->where('is_draft', false)->firstOrFail();

        $after = AssignmentSubmissionItem::query()->orderBy('id')->get()
            ->map(fn ($item) => [$item->kind, $item->stored_path])->all();

        // EVERY ITEM SURVIVED, with its ORIGINAL stored path - so the file was not
        // rewritten, only left alone.
        $this->assertEquals($before, $after);

        // The two recordings are still recordings, and still have their bytes.
        $recordings = AssignmentSubmissionItem::query()
            ->whereIn('kind', [self::K::KIND_AUDIO, self::K::KIND_VIDEO])
            ->get();

        $this->assertCount(2, $recordings);
        foreach ($recordings as $recording) {
            $this->assertTrue($recording->wasRecordedInBrowser());
            Storage::disk('local')->assertExists($recording->stored_path);
        }

        // One attempt, and it is a real one.
        $this->assertSame(1, AssignmentSubmission::query()->where('is_draft', false)->count());
        $this->assertSame(1, (int) $submission->attempt_no);
    }

    public function test_a_saved_recording_alone_satisfies_its_question_on_submit(): void
    {
        // The one-question version of the same rule, so a failure here points at
        // the question rather than at the paper.
        $k = self::K;

        $assignment = $this->publishedAssignment([
            'max_marks' => 5, 'instructions' => '<p>Do the work.</p>',
            'due_date' => now()->addWeek(), 'allowed_attempts' => 1,
        ]);
        $question = $this->questionAccepting($assignment, $k::KIND_AUDIO);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [[
                            'kind' => $k::KIND_AUDIO,
                            'file' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
                            'capture_method' => $k::CAPTURE_BROWSER,
                        ]],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The student records, saves, and then presses Submit. The page re-posts an
        // EMPTY audio slot, and the student must NOT be asked to record again.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => [
                    (string) $question->id => ['items' => [['kind' => $k::KIND_AUDIO]]],
                ],
                'idempotency_key' => 'k1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AssignmentSubmission::query()->where('is_draft', false)->count());
        $this->assertTrue(AssignmentSubmissionItem::query()->first()->wasRecordedInBrowser());
    }

    public function test_a_page_reload_does_not_lose_the_draft(): void
    {
        [$assignment, $questions] = $this->paper();
        $this->saveAFullDraft($assignment, $questions);

        // Reading the page, three times, must be completely inert. This is the
        // property that makes "open, go to lunch, come back" safe.
        for ($visit = 0; $visit < 3; $visit++) {
            $this->actingAs($this->student)
                ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
                ->assertOk();
        }

        $this->assertSame(4, AssignmentSubmissionItem::query()->count());
        $this->assertSame(0, AssignmentSubmission::query()->where('is_draft', false)->count());
    }

    public function test_choosing_a_different_file_replaces_the_stored_one_and_deletes_its_bytes(): void
    {
        $k = self::K;

        $assignment = $this->publishedAssignment([
            'max_marks' => 5, 'instructions' => '<p>Do the work.</p>', 'due_date' => now()->addWeek(),
        ]);
        $question = $this->questionAccepting($assignment, $k::KIND_IMAGE);

        $first = $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [[
                            'kind' => $k::KIND_IMAGE,
                            'file' => UploadedFile::fake()->image('first.png'),
                        ]],
                    ],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $original = AssignmentSubmissionItem::query()->first();
        $originalPath = $original->stored_path;
        Storage::disk('local')->assertExists($originalPath);

        // The student comes back and swaps the photograph.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [[
                            'kind' => $k::KIND_IMAGE,
                            'file' => UploadedFile::fake()->image('second.png'),
                        ]],
                    ],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $items = AssignmentSubmissionItem::query()->get();

        $this->assertCount(1, $items, 'one photograph, not two');
        $this->assertNotSame($originalPath, $items[0]->stored_path);
        Storage::disk('local')->assertExists($items[0]->stored_path);

        // The SUPERSEDED file's bytes go. A withdrawn photograph of a student's
        // working must not outlive the choice to replace it.
        Storage::disk('local')->assertMissing($originalPath);
        $this->assertNull(AssignmentSubmissionItem::query()->find($original->id));
    }

    public function test_the_withdraw_control_removes_the_file_and_its_bytes(): void
    {
        $k = self::K;

        $assignment = $this->publishedAssignment([
            'max_marks' => 5, 'instructions' => '<p>Do the work.</p>', 'due_date' => now()->addWeek(),
        ]);

        // OPTIONAL, and deliberately so. Withdrawing the only answer to a REQUIRED
        // question genuinely does leave the paper unsubmittable, and the service is
        // right to refuse - which is a different and equally correct outcome. An
        // OPTIONAL question is the case where withdrawing is a coherent act with a
        // coherent result: the file is gone and the draft saves cleanly.
        $question = $this->questionAccepting($assignment, $k::KIND_IMAGE);
        $question->update(['is_required' => false]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [[
                            'kind' => $k::KIND_IMAGE,
                            'file' => UploadedFile::fake()->image('mistake.png'),
                        ]],
                    ],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $stored = AssignmentSubmissionItem::query()->first();
        $path = $stored->stored_path;
        Storage::disk('local')->assertExists($path);

        // The student ticks "Withdraw this image from my answer". A file input
        // cannot express this, which is exactly why the control exists.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [['kind' => $k::KIND_IMAGE, 'remove' => '1']],
                    ],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, AssignmentSubmissionItem::query()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_withdrawing_the_only_answer_to_a_REQUIRED_question_leaves_it_unsubmittable(): void
    {
        // The other half of the distinction, and it matters: withdrawing a REQUIRED
        // answer really does make the paper unsubmittable, and the service says so
        // rather than quietly saving a draft that could never be handed in.
        $k = self::K;

        $assignment = $this->publishedAssignment([
            'max_marks' => 5, 'instructions' => '<p>Do the work.</p>', 'due_date' => now()->addWeek(),
        ]);
        $question = $this->questionAccepting($assignment, $k::KIND_IMAGE);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [[
                            'kind' => $k::KIND_IMAGE,
                            'file' => UploadedFile::fake()->image('working.png'),
                        ]],
                    ],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [['kind' => $k::KIND_IMAGE, 'remove' => '1']],
                    ],
                ],
            ])
            ->assertSessionHasErrors('questions');

        // The withdrawal itself did not happen, because the draft was refused - so
        // the file is still there and the student has not lost their work.
        $this->assertSame(1, AssignmentSubmissionItem::query()->count());
    }

    public function test_the_withdraw_control_is_offered_only_when_something_is_attached(): void
    {
        $k = self::K;

        $assignment = $this->publishedAssignment([
            'max_marks' => 5, 'instructions' => '<p>Do the work.</p>', 'due_date' => now()->addWeek(),
        ]);
        $question = $this->questionAccepting($assignment, $k::KIND_IMAGE);

        $url = "/student/courses/{$this->offering->id}/assignments/{$assignment->id}";

        // Nothing attached: the control is not offered, because there is nothing
        // to withdraw and asking would be noise.
        $this->actingAs($this->student)->get($url)
            ->assertOk()
            ->assertDontSee('as-question-remove-'.$k::KIND_IMAGE.'-'.$question->id, false);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [[
                            'kind' => $k::KIND_IMAGE,
                            'file' => UploadedFile::fake()->image('working.png'),
                        ]],
                    ],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->student)->get($url)
            ->assertOk()
            ->assertSee('as-question-remove-'.$k::KIND_IMAGE.'-'.$question->id, false);
    }

    public function test_a_second_attempt_never_inherits_the_first_attempts_evidence(): void
    {
        $k = self::K;

        [$assignment, $questions] = $this->paper(['allowed_attempts' => 2]);

        $this->saveAFullDraft($assignment, $questions);
        $this->saveAFullDraft($assignment, $questions);   // the draft, saved again

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => $this->resubmitWithoutFiles($assignment, $questions),
                'idempotency_key' => 'k1',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $attempt1 = AssignmentSubmission::query()->where('attempt_no', 1)->firstOrFail();

        $this->assertSame(4, AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $attempt1->id)->count(),
            'attempt 1 keeps its own four items');

        // Now a SECOND attempt, with a fresh draft carrying no evidence of its own.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => ['text' => '<p>Redone from scratch.</p>', 'items' => []],
                    (string) $questions[1]->id => ['text' => '<p>Redone from scratch.</p>', 'items' => []],
                    (string) $questions[2]->id => ['items' => [[
                        'kind' => $k::KIND_AUDIO,
                        'file' => UploadedFile::fake()->create('second.webm', 20, 'audio/webm'),
                    ]]],
                    (string) $questions[3]->id => ['items' => [[
                        'kind' => $k::KIND_VIDEO,
                        'file' => UploadedFile::fake()->create('second.webm', 40, 'video/webm'),
                    ]]],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => [
                    (string) $questions[0]->id => ['text' => '<p>Redone.</p>', 'items' => []],
                    (string) $questions[1]->id => ['text' => '<p>Redone.</p>', 'items' => []],
                    (string) $questions[2]->id => ['items' => [['kind' => $k::KIND_AUDIO]]],
                    (string) $questions[3]->id => ['items' => [['kind' => $k::KIND_VIDEO]]],
                ],
                'idempotency_key' => 'k2',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $attempt2 = AssignmentSubmission::query()->where('attempt_no', 2)->firstOrFail();

        // Attempt 2 holds ONLY what it was given. Nothing crossed over.
        $this->assertSame(2, AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $attempt2->id)->count());
        $this->assertSame(
            [self::K::KIND_AUDIO, self::K::KIND_VIDEO],
            AssignmentSubmissionItem::query()
                ->where('assignment_submission_id', $attempt2->id)
                ->orderBy('id')->pluck('kind')->all(),
            'attempt 2 must not inherit attempt 1\'s document or photograph'
        );
    }

    public function test_submitting_nothing_at_all_hands_in_what_was_already_saved(): void
    {
        // REVEALED BY A TEST THAT WAS TRYING TO DO SOMETHING ELSE, and worth keeping.
        //
        // An empty `questions` payload is not a request to hand in nothing - it is a
        // request that changes nothing, because that is what an absent value means
        // under this contract. The evidence already on the draft IS the answer, so
        // this succeeds. A student who saved their work and then pressed Submit
        // without touching anything gets their work handed in, which is what they
        // meant.
        [$assignment, $questions] = $this->paper();
        $this->saveAFullDraft($assignment, $questions);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => [],
                'idempotency_key' => 'k1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AssignmentSubmission::query()->where('is_draft', false)->count());
        $this->assertSame(4, AssignmentSubmissionItem::query()->count());
    }

    public function test_a_refusal_costs_the_student_none_of_their_saved_work(): void
    {
        // `require_all` is the honest instrument for a failure that CANNOT be
        // satisfied: this question demands BOTH a written response and a document,
        // and only the document was saved. So the submission is refused - and the
        // refusal must cost nothing.
        $k = self::K;

        $assignment = $this->publishedAssignment([
            'max_marks' => 5, 'instructions' => '<p>Do the work.</p>', 'due_date' => now()->addWeek(),
        ]);
        $question = $this->questionAcceptingAnyOf($assignment, [$k::KIND_TEXT, $k::KIND_DOCUMENT], [
            'require_all' => true,
        ]);

        // The DRAFT is complete: a written response AND a document, as the question
        // demands. A partial draft is refused outright now that `require_all` is
        // enforced, and that is the rule working rather than a nuisance.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'text' => '<p>My working.</p>',
                        'items' => [[
                            'kind' => $k::KIND_DOCUMENT,
                            'file' => UploadedFile::fake()->create('working.pdf', 10, 'application/pdf'),
                        ]],
                    ],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $before = AssignmentSubmissionItem::query()->orderBy('id')->pluck('stored_path', 'kind')->all();
        $this->assertNotEmpty($before);

        // The SUBMIT drops the written response and resends no file, so the
        // document is carried forward and the question is one part short.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => [
                    (string) $question->id => [
                        'text' => '',
                        'items' => [['kind' => $k::KIND_DOCUMENT]],
                    ],
                ],
                'idempotency_key' => 'failing',
            ])
            ->assertSessionHasErrors('questions');

        // No attempt spent...
        $this->assertSame(0, AssignmentSubmission::query()->where('is_draft', false)->count());

        // ...and the document they did save is untouched, still on disk, still on
        // the record. A validation failure must never cost a student their work.
        $this->assertSame(
            $before,
            AssignmentSubmissionItem::query()->orderBy('id')->pluck('stored_path', 'kind')->all()
        );

        foreach ($before as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }
}
