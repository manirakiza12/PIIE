# Lesson editor and publication remediation

Scope: Lecturer Course Content lesson authoring, including offering 30. Existing local work was preserved in the original checkout; implementation uses the isolated `fix/lesson-publishing-editor` worktree.

## Reproduced cause

The actual vendored Summernote 0.8.18 bundle creates layout DOM before constructing plugins. PIIE plugins called `context.addButton`, which that version does not implement. The standalone indent/outdent toolbar configuration also passed a context object into a renderer expecting a jQuery parent (`t.append is not a function`). The catch path treated orphan layout DOM as a ready editor even though the vendor context had never been registered.

The lesson form's direct `summernote('code')` recovery/preview calls then constructed another editor on every read. In a controlled reproduction against deployed baseline `9a5f3b05b3fbafcb10ccda36a6b48d567cf78529`, editor counts grew **1, 2, 3, 4, 5**, with no registered context. After correction, counts remain **1, 1, 1, 1, 1**, with a registered context and no errors. Shared insertion also incorrectly addressed the generated `.note-editable` div rather than the owning source textarea; that could initialize a nested instance.

## Correction

- Register custom controls through the vendor's supported `context.memo('button.…')` and `context.ui.button` APIs. Keep native table, subscript and superscript controls, and use the working native paragraph dropdown for alignment and indentation.
- Invoke vendor commands only on the source textarea. Failed construction preserves source content, removes orphan layout DOM and leaves a usable plain-text fallback without repeated initialization.
- Route lesson recovery, preview, resource-form transfer and submission through the shared editor API. Prevent native paste from inserting a second fragment. Formatting and XSS sanitization remain governed by the existing server sanitizer.
- Use a 280px editing height with scoped responsive geometry. No duplicate editor is concealed with CSS.
- Keep explicit Save Draft and Publish controls; show processing state and suppress repeated form submissions. Serialize creates with row locks and a unique creation key, verify its request fingerprint, and lock existing rows for deliberate edits/republishing. Replaying changed content against an already-used creation key produces a validation error and preserves the submitted text instead of silently discarding it.

## Publication rule

`LessonPublicationCheck` requires meaningful sanitized text or an accessible, supported saved resource, and an explicit lesson `assignment_id`. The assignment must belong to the same institution and Course Offering (and the same module when module-associated), be published or scheduled, have meaningful instructions, a due date and positive marks, and pass the existing question-readiness checks when question-based. An assignment linked to another lesson cannot be reused; the database also enforces unique association.

Drafts need neither content nor an assignment. The lesson form offers assignment selection and a create-assignment link for saved drafts. Student readers expose the linked assignment only through the existing student assignment authorization and release rules.

Create/update service paths enforce the rule, including direct service callers. Module publication/readiness and module edit publication paths cannot expose published lessons that fail the rule. There is currently no separate lesson-publication API implementation to bypass these services. No fixed-time student completion requirement or new progression gate is introduced.

## Historical data and deployment

The additive migration creates nullable assignment/key/fingerprint columns and uniqueness indexes. It does not publish, unpublish, delete, backfill or rewrite lesson bodies, assignments, progress or attachment records. Existing published lessons remain readable under their existing release and enrollment rules; a deliberate subsequent publication must satisfy the new requirements.

Protected preflight diagnostics report existing published/unlinked lesson IDs without editing them. After activation, `course-content:audit` reports all published lessons requiring assignment/content remediation. Remediation findings are informational and do not silently change historical visibility. The deployment workflow verifies the exact live release SHA, checks the publicly served editor bytes against the verified artifact, and runs the read-only publication audit.

## Regression evidence

- Real vendored-library DOM tests: repeated initialization, typing/formatting markup, step changes, local recovery, validation redisplay, preview, resource transfer, submission and plugin-failure fallback.
- Real headless Chrome: native bold/italic, headings, lists, tables, links, repeated shared insertion, one editor, submitted HTML equality and compact editing geometry.
- PHP integration: incomplete drafts/reopening, content preservation after failed publication, valid/invalid/unrelated/cross-course/cross-school assignments, assignment uniqueness, duplicate creates, changed-key retries, existing-record edits, file-only lessons, retained attachments, scheduled release, self-paced progress and historical audit preservation.
- Two-process MySQL regression runs only against the existing disposable CI database and verifies concurrent create/publish and republish requests retain one lesson and one assignment.

Full CI, protected PR review, deployment identity and the live historical audit are recorded in the delivery report once completed. The interactive browser host is unavailable in this session; the automated Chrome tests use an isolated temporary profile and no production credentials.
