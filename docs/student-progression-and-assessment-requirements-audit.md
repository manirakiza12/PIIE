# Student Progression & Assessment Requirements — Codebase Audit

Date: 2026-10-11
Commit audited: `a67658d`
Status: **read-only investigation. No files were modified.**

> ## ⚠️ The "Exists?" column below describes `a67658d`, NOT TODAY
>
> This document was a point-in-time gap analysis. Every row marked **No** or
> **Partly** below has since been built. It is kept as the record of *why* each
> thing was built the way it was — the reasoning in each section is the design
> rationale, and several of those decisions are load-bearing and non-obvious — but
> **do not read the table as the current state of the system.**
>
> Current state as of `56ac0dd` (CI green, 4,042 tests / 34,516 assertions):
>
> | # | Requirement | State today | Built in |
> |---|---|---|---|
> | 1 | Every unit has a lecturer-set assessment | Done — empty module refused at publish; a missing required assessment is **warned**, not refused, because a reading-only topic is legitimate | `6f6611d` |
> | 2 | Next content unreadable until current is finished | Done — `ProgressionGate`, enforced server-side in `CourseContentAccess::resolveLessonForStudent()` | `6f6611d` |
> | 3 | Teacher-set pass mark | Done — `pass_mark`, `retry_policy`, `attempts_cycle`, `attempt_cycle` | `6f6611d` |
> | 4 | Three attempts | Was already done | — |
> | 5 | 48-hour rest after three failures | Done — `RestPeriod`, per-student and per-cycle, derived so it expires without a job | `10c8ce1` |
> | 6 | Answers auto-save | Done — `assignment-autosave.js` + `autosaveAnswer()`, lifted from the exam engine | `63a1db6` |
> | 7 | Teacher sees each student's progress | Done — `CourseProgressGrid`, student × topic, with a "needs support" column | `6aec911` |
> | 8 | Student sees their own progress | Was already done | — |
> | 9 | Congratulation popup | Done — `PassCelebration`, returns `null` unless passed, so it *cannot* fire for a non-pass | `ecbf928` |
> | 10 | Microphone / on-screen keyboard | Both done — audio recording already existed; the on-screen keyboard is `on-screen-keyboard.js` | `f61c185` |
> | 11 | Content must not appear with no assignment | Done — the publish guard refuses it | `6f6611d` |
>
> Two deliberate decisions that are easy to mistake for unfinished work:
>
> - **Gating is OFF for every Course Offering.** The switch now exists
>   (`Content → How students move through this course`) and is owned by the
>   lecturer, because switching it on in bulk would change what paying students
>   can reach without anybody having decided that.
> - **A missing *required* assessment warns rather than refuses**, on purpose. A
>   module of pure reading with no assessment is a legitimate thing to teach.

---

## Summary table (as of `a67658d` — superseded, see above)

| # | Requirement | Exists? | Effort |
|---|---|---|---|
| 1 | Every course unit has an assignment/assessment set by the lecturer | **Partly** — machinery exists, nothing enforces it | Medium |
| 2 | Student cannot read the next content until the current is finished | **No** — zero gating of any kind | **Large** |
| 3 | Teacher-set pass mark per assignment | **No** — pass mark exists on exams only | Medium |
| 4 | Student gets 3 attempts | **Yes** — `allowed_attempts`, enforced at 3 layers | Done |
| 5 | After 3 failed attempts: "take a breath, read again, come back in 2 days" | **No** — nothing of the kind | **Large** |
| 6 | Answers auto-saved | **No** on assignments; **yes** on exams (reusable) | Medium |
| 7 | Teacher sees each student's progress | **No** — roster has no progress column | Medium |
| 8 | Student sees their own progress | **Yes** — 3 dimensions, deliberately unblended | Done |
| 9 | Dynamic congratulation popup | **No** — nothing congratulates, by design | Medium |
| 10 | Microphone / keyboard on short answers | **Mic yes** (question path only); **keyboard no** | Small–Medium |
| 11 | Fix: content appears with no assignment | Diagnostic — see §12 | — |

---

## 1. Unit must have an assessment

**Exists, partially.** An assignment can already be attached to a module as a
*required task*:

- `assignments.course_offering_module_id` → `course_offering_modules`
- `requirement_role` = `optional` | `required`
- `completion_rule` = `submission` | `released_mark`

`ModuleCompletion::assessmentRow()` (`app/Support/CourseContent/ModuleCompletion.php:304-310`)
computes satisfaction per task:

```php
$satisfied = match ($task->completion_rule) {
    Assignment::RULE_SUBMISSION     => $state !== 'not_submitted',
    Assignment::RULE_RELEASED_MARK => $state === 'returned',
    default => false,
};
```

**The gap:** `is_complete` is a **reported boolean**. Nothing reads it to refuse
anything. There is no rule that a module *must* have a required assessment, and
a module with zero assessments is currently "complete" once its lessons are done.

Also note `Assignment::moduleTasks()` (`:254`) is a `hasMany` from an Assignment
to other Assignments and has **zero production call sites** — dead code. Real
code uses `CourseOfferingModule::tasks()`.

**To build:** a validation that a module cannot be published without ≥1 required
assessment, plus making `is_complete` actually gate. Both are contained.

---

## 2. Sequential gating — the big one

**Does not exist. Anywhere.** This is the most expensive item.

`CourseContentAccess::resolveLessonForStudent()` (`:268-295`) is the single gate
for every lesson read. It performs four checks:

1. tenant (`school_id`)
2. confirmed course registration
3. lesson released
4. module released

**Not one consults `CourseOfferingLessonProgress`.**

Confirmed consequences:

- A student can open lesson 12 having completed none of 1–11, by typing the URL.
- `CourseContentService::navigationFor()` (`:550-567`) builds previous/next with
  **no completion predicate**. `lesson.blade.php:181-207` renders "Next lesson"
  as a plain `<a href>`.
- `index.blade.php:337-400` renders **every** lesson in **every** module as an
  unconditional link. Per-lesson state is a cosmetic icon only.
- `isLocked` / `locked` / `unlock` / `prerequisite` **do not exist** as concepts
  for course content.
- A test currently *asserts* the ungated behaviour:
  `CourseContentReaderTest.php:541-562`.

**Prerequisites exist but are unrelated** — `curriculum_prerequisites` is an
admin authoring graph over curriculum memberships (`CurriculumFoundationService`),
used for approval blockers and cycle detection. **Never consulted at runtime.**

**Design note worth respecting:** the existing code deliberately does not store
module completion (`ModuleCompletion:28-40` — "computed, never stored") and
`Assignment`'s defaults are `optional`/`submission` specifically so that adding
the column could not silently start blocking existing students (`:116-122`). Any
gating work must preserve that safety property — new students gated, existing
enrolments never locked out.

---

## 3. Teacher-set pass mark

**No pass mark on assignments.** `Assignment` has `max_marks` and nothing else.

What exists:

| Where | Pass mark | Enforced? |
|---|---|---|
| `online_exams.pass_mark` | yes, default 50 | comparison only, **gates nothing** |
| `subjects.pass_mark` | yes, default 50 | **never read by any logic** |
| `assignments` | **absent** | — |

**This produces a real defect today.** `RULE_RELEASED_MARK` is satisfied by
`state === 'returned'`, i.e. `marks_released_at !== null`. So **a lecturer who
releases a mark of 0 out of 100 satisfies a required task and completes the
module.** There is no threshold anywhere in the assignment path.

**To build:** `pass_mark` column on `assignments`, teacher-editable, validated
`≤ max_marks`, plus satisfaction logic that compares the released mark.

---

## 4. Three attempts — already built

`allowed_attempts` exists and is enforced at three layers:

1. **UI** — submit form not rendered, refusal reason shown
   (`show.blade.php:263-266`)
2. **Pre-transaction** — `AssignmentLifecycle::refusalReason()` throws
   `DomainException`
3. **In-transaction under `lockForUpdate()`** — re-counts and throws, so two
   racing requests cannot both consume an attempt

Message for the 4th attempt: `You have used all 3 attempts for this assignment.`

Numbering: attempt = `count(non-draft rows) + 1`. Drafts consume **no** attempt
and are promoted in place on submit. Double-submits collapse via a UNIQUE
`idempotency_key`.

**No notion of "failure" exists** — grading, a zero mark, or a network error have
zero effect on `attemptsUsed()`. The student may always retry up to the limit.

---

## 5. Three strikes → rest period

**Nothing exists.** No cooldown, no lockout, no concept of a failed attempt.

This needs, at minimum:

- a per-(student, assignment) record of the **best** attempt's mark
- a `locked_until` timestamp
- a **scheduled** command to expire the lock (the pattern already exists —
  `admissions:expire-unpaid` runs daily with `--dry-run` support)
- a `DomainException` refusal so the existing 3-layer enforcement covers it

The "take a breath, read the notes again" copy is a natural fit for the existing
refusal-reason plumbing.

---

## 6. Auto-save

**Does not exist on assignments.** `student/course_assignments/show.blade.php`
is 349 lines with exactly one `<script>` tag, loading the recorder. No timer, no
blur handler, no `beforeunload`, no debounce, no `localStorage`.

`saveDraft` is a **manual button only** ("Save without submitting").

**This is the good news of the audit:** the exam engine already solved this
problem properly, in `student/online_exam/take.blade.php`:

| Line | Behaviour |
|---|---|
| 1521-1523 | **1500 ms debounce** on text inputs |
| 1500-1507 | radios/selects save immediately on `change` |
| 1558-1566 | **3000 ms safety-net sweep** re-reading every control |
| 1586-1616 | `flushPendingSaves()` — waits for server ack, 10 s deadline, **refuses submit on timeout** |
| 1539-1545 | offline/online retry |

Route: `POST student/online-exams/submissions/{submission}/save-answer`

This is a proven pattern to lift onto the assignment page, plus an equivalent
per-question endpoint.

---

## 7. Teacher progress view

**Does not exist.** `teacher/course_offerings/students.blade.php` is a 61-line
read-only roster:

```
Student Number | Student Name | Programme | Year of Study | Registration Status
```

No progress column. No route, controller or view aggregates progress across a
course.

A lecturer's only per-student view is **per-assignment**
(`submissions.blade.php:52-62`: Student | State | Submitted | Attempts | Mark |
Action).

Note `GradebookFeed` exists as a read model whose own docblock says: *"This is
NOT a gradebook. No page, no totals, no letter grades, no ranking."*

**Data is already available.** `ModuleCompletion::forCourseOffering()` and
`StudentCourseHome::progress()` compute everything needed; nothing renders it for
staff.

---

## 8. Student progress — already built

`StudentCourseHome::progress()` (`:327-390`) returns three separate dimensions:

```php
'lessons_percent', 'assessments_percent', 'modules_percent'
```

Rendered as three bars in `student/courses/show.blade.php:136-152`.

**Deliberately unblended.** The docblock at `:300-322` explains why:

> *"Folding '2 lessons + 1 assessment' into a single percentage requires a
> weighting — what share of a module is a lesson worth … PIIE has no governed
> model for that. Any number produced without one is invented arithmetic wearing
> the clothes of a grade."*

If you want a single "% complete", that is a **policy decision** about
weighting, not a bug fix — someone must state what a lesson is worth relative to
an assessment.

---

## 9. Congratulation popup

**Does not exist — and the codebase actively avoids it.**

| Search | Result |
|---|---|
| `congratul` in `app/` | **0 matches** |
| `congratul` in views | **1**, and it is a comment explaining why it is *not* done |
| `achievement` | **0 matches** |
| badge/certificate system | none (only a staff HR `certificate_number` column) |

The mechanism is ready: `AssignmentNotifier` → `user_notifications` → bell
dropdown (`notifications/_bell.blade.php`). Adding a congratulation type is
**small** — a new `TYPE_*` constant and a producer. It would be the first
celebratory notification, so it sets the tone for the others.

Note the existing notifier deliberately carries **no mark and no file** in its
payload (`:208-228`) — *"No mark, no file, no stored path."* A congratulation
naming a score changes that contract deliberately.

Two declared types are **never sent**: `TYPE_DUE_REMINDER` and
`TYPE_RETURNED`.

---

## 10. Microphone and keyboard

**Microphone: exists, on the question-based path only.**

`public/js/assignment-recorder.js` (428 lines) is a complete, well-built
recorder — `getUserMedia`, `MediaRecorder`, `preferredType()`, blob→`File`,
injected into the form's own input via `DataTransfer`, playback, and track
release. Guarded by `AssignmentQuestion::allowsRecording()`.

It is deliberately restricted to one call site inside a click handler — *"never
runs on load, never runs on scroll, never runs on a timer."*

**Two constraints to be aware of:**

1. **The generic (non-question) assignment path has no recorder** — file picker
   only. Two tests actively assert its absence:
   `CourseOfferingModuleTaskVisibilityTest.php:209`,
   `CourseOfferingModuleTaskTest.php:790`.
2. **Speech-to-text does not exist anywhere.** Zero matches for
   `SpeechRecognition` / `webkitSpeechRecognition`. The recorder produces a file,
   never text.

**Keyboard: does not exist.** No on-screen/virtual keyboard component anywhere.
Every `keyboard` match is unrelated (SweetAlert config, exam *restriction* mode,
focus management).

Two readings of the requirement — worth confirming:

- **voice-to-text dictation** on short answers → not built; needs
  `SpeechRecognition`, browser support is uneven, and it is not the same thing as
  the existing recorder
- **an on-screen keyboard** for tablet users → not built; a self-contained
  component, no backend work at all

Also relevant: `getUserMedia` requires a **secure context** (HTTPS or
localhost). Production is HTTPS, so fine.

---

## 11. Sequencing note

Requirements 2, 5, 9 and 11 all depend on **one shared concept**: a student's
per-activity outcome ("passed", "failed", "locked until X"). Building them
independently will produce four competing notions of the same fact.

Recommended order: **3 (pass mark) → 1 (required assessment) → 2 (gating) →
5 (rest period) → 9 (congratulations)**. Each makes the next one coherent, and
gating before pass mark would lock students out on marks that cannot yet fail.

---

## 12. Open question: "content appears with no assignment"

The report states a visible fault — *"some content appears with no assignment"*.
The audit found a plausible cause but **did not reproduce it**.

A module with no required assessment still completes once its lessons are done,
because `ModuleCompletion` computes satisfaction over whatever required tasks
exist — and an empty set is vacuously satisfied. Combined with the total absence
of gating, a module can present lessons and assessments inconsistently depending
on how the lecturer authored it.

**This needs a specific reproduction** before it is fixed: which course, which
module, and what the lecturer expected versus what the student saw. The fix is
probably item 1, but I do not want to guess at a symptom I have not seen.

---

## 13. What is genuinely ready

Worth stating plainly, so the effort is not misjudged:

- **3-attempt limit** — built, enforced at 3 layers, race-safe
- **Student progress bars** — built, three honest dimensions
- **Microphone recorder** — built and well made, on the question path
- **Exam autosave** — built, with ack-wait and timeout refusal; liftable
- **Module completion computation** — built, correct, just unused for gating
- **Notification delivery** — built, deduped by DB index; a new type is small

The expensive work is **gating** (#2) and the **rest-period state machine** (#5).
Everything else is wiring onto foundations that already exist.
