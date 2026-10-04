# Online Exams — Stabilisation Report

Exam 20 / Submission 14 (Business Mathematics, Course Offering #5, Daniel Okello,
Kyeyune Amos). Everything below was measured against the live database and the real
rendered pages, not inferred from the brief.

---

## 1. Root cause of missing question statements

**The question text was never stored. It was lost at AUTHORING time, not at render
time.**

Read directly from `online_exam_questions` for exam 20:

```
id 47  mcq         <p><br></p>
id 48  true_false  <p><br></p>
id 49  essay       <p><br></p>
id 50  short       <p><br></p>
```

`<p><br></p>` is Summernote's **empty document**. `HtmlSanitizer::sanitize()` was
verified not at fault — it round-trips `<p><br></p>` faithfully and returns real text
unchanged.

The defect is the authoring rule. `StoreOnlineExamQuestionRequest` and
`UpdateOnlineExamQuestionRequest` both declared:

```php
'question' => ['required', 'string'],
```

`required` asks "is this a non-empty string?" `<p><br></p>` is eleven characters, so
it passes. Nothing on the exam-question path ever asked whether an empty rich-text
document contains a question.

`HtmlSanitizer::hasMeaningfulText()` — which answers exactly that question — already
existed and was used by lesson bodies (`CourseContentService`), assignment
instructions (`AssignmentService`), assignment questions (`QuestionService`) and
submission validation (`SubmissionService`). **The online-exam question prompt was the
one academic content field that was never wired to it.**

Two surfaces then rendered the stored emptiness as if it were content:

- the student attempt page: `{!! prosePrompt() !!}` produced a card with only a number
  and a mark allocation;
- the lecturer's results page: `Str::limit(strip_tags($question), 90)` produced an
  **empty string** — a genuinely blank label, which is the reported symptom.

### Fixed

`App\Support\OnlineExams\QuestionPrompt` is the one definition of "does this question
have text", applied at authoring time (so new rows cannot be created) and at read time
(so historic rows are visible as a fault rather than silently blank).

---

## 2. Root cause of Q4 Short Answer not rendering

**Short Answer was rendered through the same rich-text editor as Essay, and an
exception raised inside the editor's initialisation left the field with nothing to type
into.**

Reproduced against the *real* vendored `summernote-lite.min.js` (v0.8.18) and the real
`public/js/academic-editor.js`, in a real DOM:

```
editable? true                 ← the student sees a working rich-text editor
ready:    null                 ← data-piie-editor-ready was never set
bridge-ready: null             ← the autosave bridge was never bound
coverage: {"total":1,"wired":0,"missing":["49"],"unmounted":[],"unbridged":["49"]}
```

The mechanism, confirmed by reproduction:

1. `academic-editor.css` contains
   `html.piie-js .piie-editor-shell > textarea.piie-editor-source { display: none; }`
2. `start()` adds `piie-js` to `<html>` **before** any field is attached.
3. `attach()` ran `$(textarea).summernote(options)` **without a try/catch**. When it
   threw, `data-piie-editor-ready` was never set, `bindBridge()` was never called, and
   **no fallback ran** — so the source textarea was hidden with nothing in its place.
4. Worse, the exception unwound `attachAll()`'s loop, so **every field after the failing
   one was never attempted at all.**

One uncaught vendor exception therefore produced both reported symptoms at once:

| Symptom | Field | Mechanism |
|---|---|---|
| Q3 typed, said "Saved", stored NULL | essay | editor mounted; bridge never bound → the `input` event that autosave listens for never fired |
| Q4 empty card, nothing to type | short | its `attach()` was never reached, or failed the same way → hidden textarea, no editor, no notice |

Supporting evidence from the database — Q3 and Q4 answer rows:

```
q49  selected_option=NULL answer_text=NULL awarded_marks=0.00 marked_by=101 rev=0
q50  selected_option=NULL answer_text=NULL awarded_marks=0.00 marked_by=101 rev=0
```

`answer_revision = 0` and `created_at = submitted_at` (05:21:49): these rows were
created by `submitBySubmission()`, not by autosave. **Autosave never persisted them.**

A related false claim in the code was corrected: `callbacks.onCreateEditor` was
documented as "the authoritative moment" for binding the bridge. The string
`onCreateEditor` **does not occur in the vendored build** — it had never once fired in
this application.

### Fixed

- `attach()` isolates the vendor call. If the editable **is** present the field is
  marked ready and the bridge is bound (the editor the student can see keeps working
  and becomes savable). If it is **absent**, the field is revealed as a working plain
  textarea with a visible notice.
- `attachAll()` isolates each field, so one failure costs one field, not the paper.
- `bridgeCoverage()` distinguishes `unmounted` (safe — the fallback field works) from
  `unbridged` (dangerous — looks perfect, records nothing).
- **Short Answer no longer uses the editor at all.** It is a plain, labelled
  `<textarea>` that cannot fail to mount, is keyboard accessible with no scripting,
  is announced correctly by assistive technology, and is found by the same autosave
  selector. Essay keeps the editor, where the formatting is worth the dependency.
- Autosave no longer depends on the bridge at all: `captureEditorChanges()` reads each
  answer control **directly** through the editor API every 3 seconds and compares it
  with the last server-acknowledged value. A dead bridge can no longer lose an answer.

---

## 3. Root cause of Q3 rich-text autosave loss

Two independent causes, both now closed.

**Cause A — the bridge never bound** (above). Correctness of a whole paper rested on a
single `input` listener. Now the page reads the control directly, so correctness does
not rest on that listener.

**Cause B — the acknowledgement path could mark unsaved work as saved.** The revision
was only bumped by DOM events, so content typed while a request was in flight arrived
back as a `200` for the *older* revision and was recorded as `Saved`. Now:

- the revision is bumped when the live content differs from the last acknowledged
  content, **including while a request is in flight**, so the stale response is
  recognised and the newer text is re-sent;
- each request carries a sequence number and a superseded response is discarded whole;
- a non-JSON error body (gateway timeout, login redirect) is a failed save, not an
  unhandled rejection that leaves the field stuck on "Saving…" forever;
- `Saved` is reached from exactly one place: a `200` for that exact request.

Also fixed, and found by testing rather than by reading: **the warning and the
persistent banner silently stopped working whenever the incident report could not be
sent**, because both were derived from the report counter. A student on a flaky
connection was warned about nothing. Recording and warning are now separate.

---

## 4. Integrity controls implemented, and the browser's real limits

### Implemented

| Control | Status |
|---|---|
| copy / cut / paste / drag refused in the exam area | **Fixed.** Was scoped to a list of element *types*; the question statement is a `<p>` and was outside the protected set, so selecting the question and using the browser's own context menu worked. Now a containment test against `#examTakeRoot`. |
| context menu refused in the exam area | Fixed, same root cause. |
| Ctrl/Cmd + C/X/V, Shift+Insert, middle-click paste | Blocked. |
| Ctrl/Cmd + P, F5, Ctrl+R, Ctrl+W, Alt/Back/Forward | Blocked, recorded. |
| `beforeprint` | Reported. Cannot be prevented; the record says so rather than claiming a refusal that did not happen. |
| visibility change, window blur/focus | Recorded, with a grace window so one Alt+Tab is one incident. |
| fullscreen exit | Recorded (was only wired when `fullscreen_required`). |
| attempted navigation | Warned via `beforeunload`, exempting the exam's own submit paths. |
| incident logging | Extended event types, each meaning something distinct and accepted server-side by the same list. |
| configurable thresholds | `config/online_exam_integrity.php` — warning threshold, persistent-banner threshold, focus grace window. |
| approved accommodations | `online_exams.integrity_accommodation` — `clipboard_exempt`, `read_only_enforced`, `off`. Selectable on the exam form, validated against the configured allow-list. An **unknown value falls back to full protection**, never to "off". |
| recovery, not punishment | Nothing ends an attempt, removes a mark, or fails a paper. Asserted by test for every event type. |
| scope | Installs only when the page is an authenticated active attempt. `install()` is idempotent. |

### What no web page can do — stated plainly

The attempt page tells the student this in as many words, because a control implying a
guarantee it cannot keep discredits everything else the page says.

A browser **cannot** prevent:

- minimising the window, or Alt+Tab to another application;
- an operating-system screenshot, a phone camera, or a second device;
- a second copy of the exam on another machine;
- developer tools (Ctrl+Shift+I/J/C is deliberately **not** blocked — it is theatre,
  trivially re-enabled, and it breaks legitimate accessibility tooling);
- **Ctrl+T, Ctrl+N, Ctrl+Tab, Ctrl+W in some browsers** — not interceptable from page
  script at all.

What is implemented is **deterrence and evidence**: the casual routes are closed and
every attempt is recorded for a human.

**Genuine lockdown requires a dedicated secure browser or a desktop application that
owns the screen.** That is a separate programme of work and is not faked here.

---

## 5. Files changed

**New**
- `app/Support/OnlineExams/QuestionPrompt.php`
- `config/online_exam_integrity.php`
- `database/migrations/2026_10_02_000002_add_integrity_accommodation_to_online_exams.php`
- `tests/Feature/OnlineExamAnswerPersistenceTest.php`
- `tests/Feature/OnlineExamIntegrityControlsTest.php`
- `tests/Feature/OnlineExamAcceptanceWorkflowTest.php`
- `tests/js/online-exam-answer-integrity.test.js`

**Server**
- `app/Http/Controllers/OnlineExamController.php` — 422 handling on both finalize
  endpoints; resolved integrity policy passed to the page; marking-queue backlog split;
  marking breakdown on the published student result.
- `app/Http/Requests/OnlineExam/StoreOnlineExamQuestionRequest.php`
- `app/Http/Requests/OnlineExam/UpdateOnlineExamQuestionRequest.php`
- `app/Http/Requests/OnlineExam/StoreOnlineExamRequest.php`
- `app/Http/Requests/OnlineExam/UpdateOnlineExamRequest.php`
- `app/Models/OnlineExam.php` — `integritySettings()`
- `app/Models/OnlineExamQuestion.php` — `hasPrompt()`, `promptLabel()`,
  `prosePromptOrEmptyLabel()`
- `app/Models/OnlineExamSubmission.php` — human-readable review state
- `app/Models/OnlineExamProctoringEvent.php` — four new event types

**Browser**
- `public/js/academic-editor.js` — per-field isolation, two-way failure recovery,
  `bridgeCoverage` buckets, corrected `onCreateEditor` note.
- `public/js/exam-restricted-mode.js` — protected-area containment, configured
  policy, accommodations, thresholds, recording decoupled from warning, idempotent
  install.

**Views**
- `resources/views/student/online_exam/take.blade.php`
- `resources/views/student/online_exam/result.blade.php`
- `resources/views/teacher/online_exam/results.blade.php`
- `resources/views/teacher/online_exam/marking.blade.php`
- `resources/views/teacher/online_exam/_form.blade.php`
- `resources/views/admin/online_exam/results.blade.php`

**Tests updated (expectations changed deliberately, each documented in place)**
- `OnlineExamQuestionRenderingTest`, `StudentExamMultiEditorAnswerTest`,
  `OnlineExamBatch1RegressionTest`, `OnlineExamBatch2CMarkingTest`,
  `OnlineExamCoordinatedRepairTest`, `OnlineExamLifecycleGovernanceTest`,
  `OnlineExamStudentFrontendTest`, `online-exam-revisions.test.cjs`,
  `Support/OnlineExamTestHelper.php`
- `package.json` — `jsdom` as a declared dev dependency (the new browser tests need a
  real DOM).

---

## 6. Test commands and results

```
php artisan test --filter=OnlineExam                    → 392 passed
php artisan test                                        → 2919 passed, 14 failed, 21 skipped
node --test tests/online-exam-revisions.test.cjs        → 14 passed
node --test "tests/js/*.test.js"                        → 27 passed
```

`PHP_INI_SCAN_DIR` was pointed at a directory enabling `pdo_sqlite` and a raised
`memory_limit`; the vendored PHP build had neither, and the suite cannot run at all
without them.

### The 14 failures are pre-existing and unrelated

Verified by stashing **only the files this work touched** and re-running the same four
classes:

| | with these changes | without them (baseline) |
|---|---|---|
| CourseOfferingAdministrationTest | 10 failed | 10 failed |
| LiveClassCertificationTest | 1 failed | 1 failed |
| MultinationalTimezoneTest | 2 failed | 2 failed |
| TenantTimezoneTest | 1 failed | 1 failed |
| **Total** | **14 failed, 124 passed** | **14 failed, 124 passed** |

Identical. None touches the online-exam engine; they are Live Class lecturer
allocation ("Choose a current Primary or Co Lecturer allocated to this exact Offering
and meeting date") and timezone handling.

### Flake found and fixed

`OnlineExamStudentFrontendTest::test_question_order_is_stable_across_reloads` became
intermittent after the timer moved to a deadline, because its normalisation covered
only `var remainingSeconds` while the page began carrying
`var serverRemainingSeconds` too. It passed when both renders landed in the same second
and failed when they straddled one. **The normalisation now names every clock-derived
value.** The `OnlineExam` filter was run 5× consecutively to confirm stability.

---

## 7. Database preservation confirmation

Re-read after all work and after the migration:

```
submission 14  status=result_published  review=published  score=10.00
               objective=10.00  manual=0.00  passed=1
               published_at=2026-10-02 05:24:50  published_by=2

questions 47-50   all still '<p><br></p>'          ← historic fault left visible
answers  q47 sel='b'    text=NULL marks=5.00 by=NULL rev=1
          q48 sel='true' text=NULL marks=5.00 by=NULL rev=1
          q49 sel=NULL   text=NULL marks=0.00 by=101  rev=0
          q50 sel=NULL   text=NULL marks=0.00 by=101  rev=0

online_exams.integrity_accommodation (exam 20) = NULL
```

Nothing was rewritten, repaired, re-marked or re-published. No marks were manufactured.
The empty prompts remain empty **on purpose**: a historic paper that was faulty should
still show as faulty, so the examiner can see the record. What changed is that they are
now *named* instead of silently blank on every surface.

The one migration applied (`2026_10_02_000002`) adds a single **nullable** column and
rewrites no existing row.

---

## 8. End-to-end acceptance test outcome

`OnlineExamAcceptanceWorkflowTest` runs the whole governed chain through the real HTTP
endpoints on a **new** exam and a **new** attempt.

```
PASS  a full governed examination from authoring to published result
PASS  a student sees no marks before they are published
PASS  a lecturer may never publish an official result
PASS  this test does not touch exam twenty or submission fourteen
```

The chain, as asserted:

1. Lecturer authored 4 questions (MCQ 5, True/False 5, Essay 5, Short Answer 5) through
   `POST teacher.online_exams.questions.store`.
2. Administrator published the paper.
3. Student started, and all four types rendered their statement and a usable control.
4. Student answered all four. **Read from the database before submission** — all four
   present, `answer_revision > 0` for every one, essay formatting byte-identical
   (`<strong>`, `<ul>/<li>`, `data-latex`), short answer exact
   (`A = P(1 + r/n)^(nt)`).
5. Submitted → objective marks calculated automatically (10.00); the two written
   questions correctly **not** marked and listed as owing a decision.
6. **Values intact after submission** — formatting and notation unchanged.
7. Lecturer saw the exact question statements and the exact written responses; marked
   essay 4, short answer 5; handed over → `finalized` / `pending_review`,
   objective 10.00 + manual 9.00 = **19.00**, pass, `published_at` still null.
8. Administrator published → `result_published`, `published_at` and `published_by`
   recorded. **A second press did not duplicate notifications.**
9. Student saw 19/20 **and** the full per-question breakdown with their own answers and
   the marker's comments.
10. Refresh restoration verified mid-attempt.

A separate test asserts the student's *withheld* result page contains neither their
answers nor the marks, so requirement 25 is checked rather than assumed.

---

## 9. Remaining issues

1. **Browser automation was not available in this session.** The desktop browser tool
   reported no connected browser, so requirement 40's *browser-driven* end-to-end run
   could not be performed. Instead the shipped JS files are executed in a **real DOM**
   (`jsdom`) against real vendored Summernote and real rendered markup
   (`tests/js/online-exam-answer-integrity.test.js`, 27 tests), and the governance
   chain is driven through real HTTP routes. This is strong, but it is not a browser,
   and the report does not claim otherwise.

2. **P2 Live Monitor — implemented / missing.** Audited, not developed, per
   requirement 37.
   - *Genuinely implemented:* the database schema and columns
     (`camera_consent_at`, `camera_permission_granted`, `camera_ready_at`,
     `last_activity_at`, `ip_address`, `user_agent`, `browser_session_token`); an
     explicit opt-in camera consent with a real `getUserMedia` request **before** the
     Start button enables; server-side event recording with deduplication; a
     permission-scoped lecturer review screen; `webcam_required` and
     `fullscreen_required` exam settings; heartbeat and incident endpoints; per-exam
     live counts and ownership scoping.
   - *Missing:* **no `RTCPeerConnection` or `MediaStream` transport of any kind.** No
     WebRTC signalling server, no STUN/TURN configuration, no grid, no focus/zoom, no
     snapshot capture, no `snapshot_captured` / `snapshot_failed` producer. The
     `snapshot_*` event types exist and are accepted by the server but nothing emits
     them. The Live Monitor page lists ongoing exams with in-progress counts and links
     to per-attempt proctoring review — it is an **audit** view, not a live video view.
   - *Not attempted, deliberately:* the brief requires camera permission to be
     requested explicitly, feeds never exposed publicly, and no simulated streams. A
     real grid needs a signalling service and a TURN relay, which is infrastructure
     work, not a stabilisation fix. Building it half-way would have produced something
     that looked like monitoring and was not.

3. **Accommodations are exam-level, not student-level.** A paper either carries the
   adjustment or it does not. Per-student accommodations need an institutional
   decision about who may grant one, when it expires and how it is evidenced; the
   storage exists and that decision is deliberately not fabricated in an audit column.
   The column is *not* in the structural-lock list, so an adjustment can be granted
   mid-attempt — which is its main real use.

4. **Exam 20 remains a defective paper.** Its four questions have no text and its two
   written answers were never stored. The workflow is fixed for future submissions, and
   every surface now names the fault instead of rendering blank — but the historical
   10/20 cannot be made correct without fabricating content, so it stands.

5. **Auto-generated submission timestamps.** Answer rows are created by
   `submitBySubmission()` for questions that were never answered. That is what made
   `answer_revision = 0` the diagnostic signal in the first place, and it is preserved:
   a genuine blank and a failed autosave must stay distinguishable (requirement 23).