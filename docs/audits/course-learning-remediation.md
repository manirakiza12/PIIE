# Course learning remediation audit (29 October 2026)

## Existing implementation

CourseOfferingContentController and StudentCourseContentController share
CourseContentService, CourseContentAccess, ProgressionGate and ModuleCompletion.
Access requires the same institution and a confirmed registration for the exact
offering. Lecturer authoring delegates to the established allocation authority.
Module and lesson publication are independent. Scheduled means `published` with a
future `released_at`; reads enforce that date, so release requires no queue job.
Draft and archived content are excluded from listings and direct access.

TeacherCourseOfferingAssignmentController uses AssignmentService, QuestionService,
QuestionResponseService, SubmissionService and grading/lifecycle services. Question
CRUD, ordering, response kinds, marks reconciliation, evidence carry-forward,
draft autosave, attempts, deadline enforcement and released feedback already exist.
Questions freeze when student work would be invalidated. Preserve these rules.
AssignmentResource is currently assignment-scoped, not question-scoped.

Academic HTML goes through HtmlSanitizer and the shared academic-editor Blade/JS
component. Formatting, tables, image policy and equation notation already exist.
The editor has submit synchronization and input bridging; mocked JS checks pass.
Real vendor integration still requires verification before declaring the reported
empty question and duplicate editor problems resolved.

Files use the private local disk under storage/app with authorized download
controllers. Lesson uploads accept any short extension and ignore a false storage
write result. Assignment upload restrictions exist but their storage writes also
ignore failure. Upload validation differs between new and saved assignments.
Lesson resource rules validate an explicitly null inactive link URL, matching the
reported local-file failure. Fix with conditional exclusion, keeping active inputs
required. Never repair or delete historical resources speculatively.

Progress is database-backed with a unique (lesson, student) index. Opening records
engagement, not completion. The current gate is module-only; add an opt-in lesson
sequence without changing existing module gating defaults. Completion must be
concurrency-safe, and completed, still-published lessons must remain revisitable.

## Release investigation

Course content parses datetime-local values in the application timezone, while
TenantTimezone already resolves the institution timezone. This can delay a release
entered as 17:32 Kampala until 20:32 Kampala when the app runs in UTC. Assignment
date parsing has the same mismatch. Normalize new inputs to the existing database
clock and render local inputs in the institution clock. Do not shift old data
without confirming the intended instant.

The exact BAAS002 offering 30 row, enrollment and stored release time have not yet
been inspected in production. A teacher's Scheduled label alone cannot establish
whether that lesson was legitimately unavailable. No production data correction
is authorized by an unverified assumption.

## Verification and deployment

Relevant tests cover content readers, publishing, file-only content, questions,
submissions, autosave, pass marks, progression and module completion. CI uses PHP
8.3 and MySQL; feature fixtures also use isolated SQLite schemas. The default local
PHP is 8.1; a workspace-local PHP 8.3 runtime is needed. Production deployment uses
the exact successful CI artifact and the protected production environment in
piie-deploy.yml. Existing local CI/deployment edits must be preserved. GitHub CLI
works outside the network sandbox; the initial sandbox-only failure did not
indicate an invalid credential.

## Changes and verification

The original reader suite passed (45 tests, 135 assertions). New tests cover
automatic release at 17:32 institution time, denied early access, draft parents,
saved completion over multiple days and a new session, same-day sequential
completion, flexible order, private local downloads, empty question HTML,
cross-assignment resource denial, frozen resources and failed disk writes.
The actual vendored Summernote bundle is exercised for repeat mount, source-field
synchronization, formatting and emptying the field. Plugin registration now
follows vendor readiness. Versioned editor assets refresh cached JS and CSS.

New date inputs use the institution clock and the existing database clock.
Existing stored dates are not rewritten. The optional `sequential_lessons` setting
defaults to false and leaves existing module gating unchanged. Completed lessons
remain revisitable while release checks still apply. The private disk and
historical paths remain unchanged. File extensions and
`COURSE_CONTENT_MAX_UPLOAD_KB` are configured in `config/course_content.php`.

A nullable question association supports per-question handouts using the existing
AssignmentResource model. Resources freeze with submitted questions. Removing an
unanswered question preserves its handouts at assignment scope. Question order
now has usable position controls and rejects duplicated choices.

For offering-specific release and missing-resource evidence, run the read-only
`php artisan course-content:audit 30` through the protected runtime. It does not
publish content, change enrollment, or repair/delete files.
