<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentQuestion;
use App\Models\AssignmentSubmissionItem;
use App\Models\User;
use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything a lecturer does to the QUESTIONS of an assignment.
 *
 * AUTHORITY IS NOT DECIDED HERE
 *
 * Every method begins by resolving the assignment through `AssignmentAccess` for
 * the actor, which proves a current lecturer allocation (or the academic office's
 * capability) on the exact Offering in the URL. Nothing in this class reads an
 * offering id from a request, and nothing trusts a question id on its own: a
 * question is always re-resolved INSIDE its own assignment, so a question from
 * another tenant, another Offering or another assignment cannot be reached by
 * substituting its id.
 *
 * THE FREEZE RULE, WHICH IS THE HEART OF THIS CLASS
 *
 * Once ANY real submission exists for the assignment, its questions are FROZEN:
 *
 *   add      refused
 *   edit     refused
 *   delete   refused
 *   reorder  refused
 *
 * WHY, AND WHY IT IS A REFUSAL RATHER THAN A WARNING
 *
 * A question is a promise about what the student was asked. Editing one after
 * somebody has handed work in changes what the work is an answer to: a marker
 * reading "5 marks for an explanation" against a student who wrote five marks'
 * worth of algebra is not grading, they are guessing. Reordering is the same
 * problem in a smaller way - it changes the numbering a student's feedback refers
 * to. Adding a question is the worst case, because the student's attempt then
 * has no answer to a question they were never shown, and every downstream total
 * is quietly short.
 *
 * So it is refused outright, with an explanation naming the alternative. A
 * lecturer who needs to change the paper after students have worked writes a new
 * assignment - which is the same decision PIIE already forces when an assignment
 * is closed.
 *
 * Marking is NOT frozen, and the distinction is deliberate. Giving a different
 * mark to a question is GRADING, which is the whole point of the second half of
 * the lifecycle and is expected to change. Changing what the question is worth,
 * or what it asked, is AUTHORING, and is what is locked. The question's own
 * maximum cannot move once work exists, which is what keeps a recorded mark
 * within bounds afterwards.
 *
 * SEQUENCE NUMBERS ARE REWRITTEN, NOT INCREMENTED
 *
 * Reordering rewrites the whole set to 1..n inside one transaction. Incrementing
 * instead would accumulate gaps and a "move to position 2" would have to renumber
 * everything anyway, so the set ends up dense either way - but a dense set is one
 * the marking screen can render as "Question 1, 2, 3" without holes a reader would
 * stop to puzzle over. There is deliberately NO unique index on sequence: a
 * lecturer part-way through a drag would otherwise hit a constraint violation on
 * an intermediate state.
 */
class QuestionService
{
    public function __construct(
        private readonly AssignmentAccess $access,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    // ── reading ────────────────────────────────────────────────────────────

    /**
     * This assignment's questions, in reading order.
     *
     * @return \Illuminate\Support\Collection<int, AssignmentQuestion>
     */
    public function questionsFor(Assignment $assignment)
    {
        return $assignment->questions()->inReadingOrder()->get();
    }

    /**
     * Can this assignment's questions be changed at all?
     *
     * The single place that answers it, so the lecturer screen's "locked" notice
     * and the refusal that actually fires are the same fact.
     */
    public function questionsAreFrozen(Assignment $assignment): bool
    {
        return $assignment->submissions()->where('is_draft', false)->exists();
    }

    /**
     * Why the questions are frozen, in one sentence, or null when they are not.
     */
    public function freezeReason(Assignment $assignment): ?string
    {
        if (! $this->questionsAreFrozen($assignment)) {
            return null;
        }

        $count = $assignment->submissions()->where('is_draft', false)->count();

        return $count.' student'.($count === 1 ? ' has' : 's have')
            .' already submitted work for this assignment, so its questions are locked: '
            .'changing a question would change what their work is an answer to. '
            .'Write a new assignment instead. Marking and returning feedback are still available.';
    }

    // ── writing ────────────────────────────────────────────────────────────

    /**
     * Add one question.
     *
     * The marks are NOT applied to `assignment.max_marks`. The lecturer is told
     * the new total and offered a one-click adoption, because silently changing
     * an assignment's total - on a PUBLISHED assignment, a student's view of what
     * the work is worth would change under them - is not a decision a service
     * should make silently.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function add(User $actor, Assignment $assignment, array $attributes): AssignmentQuestion
    {
        $target = $this->resolveForManager($actor, $assignment);
        $this->assertNotFrozen($target);

        return DB::transaction(function () use ($actor, $target, $attributes) {
            // The new question goes LAST, so adding one never reorders what a
            // lecturer has already arranged.
            $next = (int) AssignmentQuestion::query()
                ->where('assignment_id', $target->id)
                ->max('sequence');

            $question = new AssignmentQuestion();
            $question->school_id = (int) $target->school_id;
            $question->course_offering_id = $target->course_offering_id;
            $question->assignment_id = $target->id;
            $question->created_by = $actor->id;
            $question->updated_by = $actor->id;
            $question->sequence = $next + 1;

            $this->apply($question, $attributes);
            $question->save();

            return $question->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Assignment $assignment, AssignmentQuestion $question, array $attributes): AssignmentQuestion
    {
        $target = $this->resolveForManager($actor, $assignment);
        $this->assertNotFrozen($target);
        $row = $this->resolveQuestionIn($target, $question);

        return DB::transaction(function () use ($actor, $row, $attributes) {
            $this->apply($row, $attributes);
            $row->updated_by = $actor->id;
            $row->save();

            return $row->fresh();
        });
    }

    public function delete(User $actor, Assignment $assignment, AssignmentQuestion $question): void
    {
        $target = $this->resolveForManager($actor, $assignment);
        $this->assertNotFrozen($target);
        $row = $this->resolveQuestionIn($target, $question);

        DB::transaction(function () use ($target, $row) {
            // The bytes go with the question. A file a lecturer deleted is evidence
            // a student was told they could hand in and did, and retaining it
            // after they removed the question would be a retention decision
            // nobody made.
            $items = AssignmentSubmissionItem::query()
                ->where('assignment_question_id', $row->id)
                ->get();

            foreach ($items as $item) {
                if ($item->stored_path) {
                    \Illuminate\Support\Facades\Storage::disk('local')->delete($item->stored_path);
                }
            }

            $items->each->delete();

            // `assignment_question_responses` rows belong to the submission, not the
            // question, so they are NOT cascaded here - but with no submissions in
            // existence there cannot be any, and the foreign keys have no
            // ON DELETE clause to paper over a wrong answer.
            $row->delete();

            $this->renumber($target);
        });
    }

    /**
     * Put the questions in a new order, given as an ordered list of question ids.
     *
     * Every id must belong to THIS assignment. An id from elsewhere is refused
     * rather than skipped: a reorder that silently ignored an unknown id would
     * report success while leaving the list in a state the lecturer did not ask
     * for.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(User $actor, Assignment $assignment, array $orderedIds): void
    {
        $target = $this->resolveForManager($actor, $assignment);
        $this->assertNotFrozen($target);

        $existing = AssignmentQuestion::query()
            ->where('assignment_id', $target->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $wanted = array_values(array_unique(array_map('intval', $orderedIds)));

        $unknown = array_diff($wanted, $existing);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'questions' => 'That order includes question'.(count($unknown) === 1 ? '' : 's')
                    .' which do not belong to this assignment.',
            ]);
        }

        // A partial list means "the rest keep their relative order", which is what a
        // lecturer who moved one question and submitted the whole list means.
        $rest = array_values(array_diff($existing, $wanted));
        $final = array_merge($wanted, $rest);

        DB::transaction(function () use ($target, $final) {
            foreach ($final as $index => $id) {
                AssignmentQuestion::query()
                    ->where('id', $id)
                    ->where('assignment_id', $target->id)
                    ->update(['sequence' => $index + 1, 'updated_at' => now()]);
            }
        });
    }

    /**
     * Set `max_marks` to the sum of the questions.
     *
     * The one-click that makes mark integrity achievable without a lecturer adding
     * up the questions they just wrote. REFUSED once work exists, because on a
     * published assignment with submissions the total is the thing students are
     * being assessed against, and moving it silently would change what has already
     * been handed in.
     */
    public function adoptMarksTotal(User $actor, Assignment $assignment): Assignment
    {
        $target = $this->resolveForManager($actor, $assignment);

        if (! $target->isQuestionBased()) {
            throw ValidationException::withMessages([
                'max_marks' => 'This assignment has no questions yet, so there is no question total to adopt.',
            ]);
        }

        if ($this->questionsAreFrozen($target)) {
            throw ValidationException::withMessages([
                'max_marks' => $this->freezeReason($target),
            ]);
        }

        $total = $target->adoptQuestionMarksTotal();
        $target->updated_by = $actor->id;
        $target->save();

        return $target->fresh(['questions']);
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * Apply and validate a question's own fields.
     *
     * A draft question may be incomplete - that is what a draft is for. The
     * publication-time requirements live in `Assignment::questionReadinessProblems`
     * and are applied when the lecturer tries to PUBLISH, not when they save a
     * question into a draft. Refusing a half-written question at save time would
     * make it impossible to build a paper up over several sittings.
     */
    private function apply(AssignmentQuestion $question, array $attributes): void
    {
        $question->heading = trim((string) ($attributes['heading'] ?? '')) ?: null;

        $prompt = $this->sanitizer->sanitize($attributes['prompt'] ?? null);

        // `hasMeaningfulText()`, not `toText() === ''`: a document that is a
        // heading and a typeface around a zero-width space has no text in it, and
        // `trim()` does not remove U+FEFF. See HtmlSanitizer::hasMeaningfulText().
        if (! $this->sanitizer->hasMeaningfulText($prompt)) {
            throw ValidationException::withMessages([
                'prompt' => 'Write the question. A question with no text is not a question.',
            ]);
        }

        // The SHARED sanitizer, the same one lesson bodies, assignment
        // instructions and written student responses pass through. Two rich-text
        // stores with two allowlists is how one of them ends up with an XSS hole.
        $question->prompt = $prompt;

        // Marks are stored even at zero rather than refused, because a lecturer
        // building a paper may not have decided the split yet. The
        // "every question is worth marks" rule is enforced at PUBLICATION, which
        // is the moment it actually matters.
        $marks = $attributes['marks'] ?? null;
        $question->marks = is_numeric($marks) ? round(max(0, (float) $marks), 2) : 0.0;

        $question->is_required = $this->normaliseRequired($attributes['is_required'] ?? true);

        $question->response_kinds = $this->normaliseKinds($attributes['response_kinds'] ?? null);

        // "All of them" is only meaningful once something is chosen, and it is
        // opt-in because the common case is any-one-of.
        $question->require_all = (bool) ($attributes['require_all'] ?? false)
            && $question->response_kinds !== null;
    }

    /**
     * `is_required` defaults to TRUE, because a question somebody bothered to
     * write is presumed to be one they want answered - and because the default can
     * only ever block an INCOMPLETE answer to a question the student can see,
     * never hide one.
     */
    private function normaliseRequired($value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * The accepted response kinds, as a comma-separated set.
     *
     * Only kinds PIIE accepts, in the canonical order and deduplicated, so the
     * stored value is stable and a hand-edited request cannot introduce a kind the
     * validator would refuse. An empty selection is stored as NULL - "not
     * configured" - rather than as an empty string, so the two remain
     * distinguishable and the publication check can say which one it is looking at.
     */
    private function normaliseKinds($kinds): ?string
    {
        if ($kinds === null || $kinds === '') {
            return null;
        }

        $list = is_array($kinds) ? $kinds : explode(',', (string) $kinds);

        $clean = array_values(array_intersect(
            AssignmentSubmissionItem::CONFIGURABLE_KINDS,
            array_filter(array_map('trim', $list))
        ));

        return $clean === [] ? null : implode(',', $clean);
    }

    /**
     * Re-resolve the assignment for this actor, INSIDE the Offering it belongs to.
     */
    private function resolveForManager(User $actor, Assignment $assignment): Assignment
    {
        if ($assignment->course_offering_id === null) {
            // A legacy K12 assignment has no Offering and therefore no lecturer
            // allocation to check. Questions are a Course Offering feature; the K12
            // screens keep their own single-form flow untouched.
            throw ValidationException::withMessages([
                'questions' => 'This is a legacy K12 assignment, which does not use questions.',
            ]);
        }

        return $this->access->resolveForManager(
            $actor,
            (int) $assignment->course_offering_id,
            (int) $assignment->id
        );
    }

    /**
     * Re-resolve a question INSIDE its own assignment.
     *
     * A question id from another assignment, another Offering or another tenant is
     * a 404. Never "found and edited anyway": the parent is the authority, and
     * trusting a bare id would let a lecturer reach a question they cannot manage.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    private function resolveQuestionIn(Assignment $assignment, AssignmentQuestion $question): AssignmentQuestion
    {
        $row = AssignmentQuestion::query()
            ->where('assignment_id', $assignment->id)
            ->whereKey($question->id)
            ->first();

        if (! $row) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(404, 'Question not found.');
        }

        return $row;
    }

    private function assertNotFrozen(Assignment $assignment): void
    {
        $reason = $this->freezeReason($assignment);

        if ($reason !== null) {
            throw ValidationException::withMessages(['questions' => $reason]);
        }
    }

    /**
     * Rewrite this assignment's question sequences to 1..n.
     */
    private function renumber(Assignment $assignment): void
    {
        $ids = AssignmentQuestion::query()
            ->where('assignment_id', $assignment->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids->values() as $index => $id) {
            AssignmentQuestion::query()
                ->where('id', $id)
                ->update(['sequence' => $index + 1, 'updated_at' => now()]);
        }
    }
}
