# Student assignment delivery and submission

## Recovered state

Continued the existing uncommitted implementation in `.codex-worktrees/student-assignment-submission`, branch `fix/student-assignment-submission`, based on production/main `d2fa0a15c61618f996d1f0bfc1c786745eba52bd`. No student assignment PR existed at recovery. The primary checkout and all other worktrees were preserved.

The previous session had already consolidated the response form, added explicit all/any requirements and optional evidence, retained draft uploads, and added submission retry protection, synthetic integration tests and mobile fixtures. Its recorded expanded run passed 299 tests. This continuation reviewed and completed that work.

## Causes and behavior

- Two independent draft/final forms repeated the same question and evidence controls. There is now one response form with Save Draft and Review and submit actions.
- Generic assignment summaries said “or” while the service demanded every selected kind. Lecturers now explicitly choose all selected methods or any one primary method, with separate optional supporting attachments. Existing NULL modes retain their previous all-selected requirement; no published assignment is silently reconfigured.
- Question-based assignments use the lecturer's ordered question records and each question's own accepted methods. Text in assignment instructions is not converted into invented question records. A protected read-only inspection reports the actual question/configuration state of assignment 2 in offering 30.
- Incomplete drafts were subjected to final requirements; blank upload controls could replace stored generic evidence. Drafts can remain incomplete and retain saved files. Failed final requirement validation saves valid partial work without consuming an attempt.
- Autosave had no matching question hooks, omitted CSRF, and could finish a flush before an in-flight answer. The form now exposes the hooks, synchronizes rich-text values, sends CSRF and serializes pending answers before final submission.
- Exhausted attempts hid the questions, and the attempt detail page omitted structured answers/evidence. Questions remain readable, and each attempt links to its saved answers and protected attachments.
- Returned question feedback was sourced from a new draft when one existed. It now belongs to the returned submission independently of the draft being edited.
- Service locks and idempotency tokens protect final retries and attempts. Existing enrollment, institution, release, deadline, attempt and grading authorization remains in force.

## Data and deployment

The migration adds nullable `submission_requirement_mode` and `optional_submission_kinds` to assignments. It performs no backfill or changes to questions, instructions, answers, attachments, marks or attempts. Deployment must use the migration-capable path (`code_only=false`) and the exact-main CI-built artifact.

Production readiness recognizes the independently verified lesson-release baseline from activation 40987512197. Unknown release identities are still rejected. Student-assignment verification runs through the existing production environment gate, reads metadata in a read-only database transaction, verifies migrations, authentication, application health and live asset bytes, and never impersonates a student or submits real work.

## Verification and limits

Synthetic PHP tests cover lecturer configuration, ordered delivery, alternative and required responses, optional attachments, validation, draft retention, final retries, deadlines, tenant authorization, grading and returned feedback. DOM tests and headless Chrome cover the response selector, one form, draft/final submission, autosave synchronization and 390/1440px layouts. Full required CI runs on MySQL before merge and again on the exact main commit.

Authenticated production browser testing remains necessary for real account navigation, rich-text entry, recording permissions and end-to-end interaction. No real student submission is modified for testing. If the reported assignment has no structured question records, or still requires multiple media kinds under its historical configuration, its lecturer must deliberately correct that configuration; this release does not infer changes from instructions.
