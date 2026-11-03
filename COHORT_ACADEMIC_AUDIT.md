# PIIE Cohort-Based Academic Management — Phase 1 Audit Report

## 1. Executive summary

The PIIE codebase already contains a substantial cohort/curriculum foundation built on top of the legacy Academic Year/Semester model. The work required by the master prompt is therefore **integration, completion and hardening**, not a greenfield build. The most significant gaps are: **no Exemption/Credit-Transfer data model or workflow**, **no standalone Student Progression engine**, and **no safe migration plan for legacy students** whose cohort cannot be inferred.

No code changes have been made in this phase. This report is the Phase 1 deliverable.

## 2. Existing academic foundation (already present)

| Concept | Implementation | Key files |
|---|---|---|
| Academic calendar | `AcademicYear`, `AcademicPeriod` (semester/term), `AcademicCalendar` | `app/Models/AcademicYear.php`, `AcademicPeriod.php` |
| Legacy sessions | `Session` (linked to `AcademicYear`) | `app/Models/Session.php` |
| Centralized curriculum | `Curriculum`, `CurriculumStage`, `CurriculumMembership`, `CurriculumPrerequisite` per programme | `app/Models/Curriculum*.php` |
| Cohort | `ProgrammeCohort`, `ProgrammeCohortMembership` (immutable history) | `app/Models/ProgrammeCohort*.php` |
| Student placement | `StudentCurriculumAssignment` (programme, curriculum, entry/effective academic year, cohort membership) | `app/Models/StudentCurriculumAssignment.php` |
| Course delivery | `CourseOffering`, `CourseOfferingCurriculumMembership`, `CourseRegistration` | `app/Models/CourseOffering*.php`, `CourseRegistration.php` |
| Cohort service | `ProgrammeCohortService` (create/activate/assign/transfer/defer/complete) | `app/Support/ProgrammeCohorts/` |
| Academic context | `AcademicContextManager` (current year/period, transitions) | `app/Support/AcademicContextManager.php` |
| Curriculum setup | `CourseUnitSetup`, `CurriculumFoundation` | `app/Support/Curriculum/` |
| Progression (content-level) | `ProgressionGate` (topic gating) | `app/Support/CourseContent/ProgressionGate.php` |

Cohort and curriculum management controllers and views exist: `ProgrammeCohortController`, `admin/programme_cohorts/*`.

## 3. Academic Year / Semester dependency map

### AcademicYear is referenced by
- `School.current_academic_year_id` (institutional pointer)
- `Session.academic_year_id` (legacy finance sessions)
- `Curriculum.effective_academic_year_id`
- `ProgrammeCohort.entry_academic_year_id`
- `StudentCurriculumAssignment.entry_academic_year_id`, `effective_from_academic_year_id`
- `CourseOffering.academic_year_id`
- `AcademicPeriod.academic_year_id`
- Models: `Exam`, `Gradebook`, `FeeStructure`, `StudentFeeManager`, `Routine`, `Syllabus`, `OnlineExam`, `QuestionBank`, `Noticeboard`, `Book`, `BookIssue`, `Expense`, `Feedback`, `FrontendEvent`, `ExamCategory` — all carry `session_id` (legacy) which maps to an academic year via `Session.academic_year_id`.

### AcademicPeriod / Semester is referenced by
- `CourseOffering.academic_period_id`
- `School.current_academic_period_id`
- `AcademicPeriod` drives `SemesterCourseOfferings::reconcile()` and content gating.

### Key observation
The new architecture already treats Academic Year as an **institutional calendar** and Academic Period as a **teaching/assessment period** — exactly the master prompt's intent. The remaining work is to ensure student-level progression and exemptions are not implicitly tied to the global current year.

## 4. Gaps vs. master prompt requirements

| Requirement | Status | Notes |
|---|---|---|
| Cohort management (create/edit/archive/transfer) | ✅ Exists | `ProgrammeCohortController`, service, views |
| Centralized curriculum (all programmes) | ✅ Exists | `Curriculum` per programme, shared controller |
| Curriculum versions / levels / course units / semester mapping | ✅ Exists | `CurriculumStage`, `CurriculumMembership` |
| Cohort-specific curriculum assignment | ✅ Exists | `ProgrammeCohort.curriculum_id` |
| Student cohort assignment & history | ✅ Exists | `ProgrammeCohortMembership` (immutable) |
| Cohort-specific semester schedules | ⚠️ Partial | `AcademicPeriod` is school-wide; cohort-specific periods not modeled |
| Student academic profile (level, registered/completed/outstanding/exempted) | ⚠️ Partial | `StudentCurriculumAssignment` covers placement; completed/outstanding derived, no exempted concept |
| Exemptions (entire level + individual course unit) | ❌ Missing | No tables, models, or workflows |
| Credit transfers | ❌ Missing | No table/model |
| Exemption approval workflow + audit | ❌ Missing | — |
| Student progression engine (individual, rule-based) | ❌ Missing | Only content-level `ProgressionGate`; no academic-level progression evaluator |
| Exempted status distinct from pass | ❌ Missing | — |
| Safe migration of legacy students to cohorts | ❌ Missing | No migration/backfill script; `ProgrammeCohortMembership` exists but backfill unverified |
| Cohort-specific reports/dashboards | ⚠️ Partial | Cohort show page exists; broader stats not verified |
| Permissions for new actions | ⚠️ Partial | Cohort membership permission exists; exemption/progression permissions missing |
| Tests for new capabilities | ❌ Missing | No exemption/progression tests |

## 5. Academic Year dependency risk assessment

- **High risk**: `Session` (legacy) is still used by `Exam`, `Gradebook`, `FeeStructure`, `StudentFeeManager`, `Routine`, `Syllabus`, `OnlineExam`, `QuestionBank`, `Noticeboard`, `Book*`, `Expense`, `Feedback`, `FrontendEvent`, `ExamCategory`. These depend on `session_id`, which resolves to an academic year via `Session.academic_year_id`. Any change to the academic-year model must preserve this mapping or these modules break.
- **Medium risk**: `CourseOffering` carries both `academic_year_id` and `academic_period_id`. Exams/online exams/assignments reference `session_id` and/or `programme_id`. Results/transcripts aggregate across these.
- **Low risk (already decoupled)**: `StudentCurriculumAssignment` already keys on cohort membership + curriculum + academic years for entry/effective context, not on the global current year.

## 6. Proposed database additions (additive only, no destructive changes)

New tables (all tenant-scoped by `school_id`, FK-restricted, soft-delete where appropriate):

1. `student_academic_exemptions` — header: student, school, programme, curriculum, exemption_type (level|course_unit), requested_by, status (pending/approved/rejected), decided_by, decided_at, reason, previous_institution, previous_qualification, evidence (JSON/file refs), audit.
2. `student_academic_exemption_items` — per course unit: exemption_id, curriculum_membership_id/subject_id, scope (level|course_unit), status.
3. `student_credit_transfers` — student, source institution, qualification, credits, equivalent course units, approval.
4. `student_progression_decisions` — student, from_level, to_level, decision (promote/retain/conditional), decided_by, decided_at, basis (JSON snapshot), cohort_membership_id.
5. `student_academic_status` (or extend `StudentCurriculumAssignment`) — current_academic_level, current_period, expected_graduation_date, graduation_eligibility (computed/cached).

Extend existing:
- `StudentCurriculumAssignment`: add `current_academic_level` (already has `entry_curriculum_stage_id`), `expected_graduation_date`, `graduation_eligibility_status`. Consider adding `programme_cohort_id` shortcut (already via membership).
- `ProgrammeCohort`: already has `curriculum_id`, `entry_academic_year_id`. Add `expected_completion_months` maybe (currently derived).

No changes to `AcademicYear`, `Session`, `AcademicPeriod`, `Exam`, `Gradebook`, `StudentFeeManager`, etc. Their columns remain; they continue to function as calendar/scheduling/finance context.

## 7. Migration strategy for existing students

1. For each student with a `StudentCurriculumAssignment`, infer cohort from `programme_cohort_membership_id` if present; else from `Admission.intake_session_id` + `programme_id` matching an active `ProgrammeCohort`; else leave `cohort_assignment_status = 'unresolved'` and flag for admin review.
2. Never overwrite existing academic levels, results, fees, examinations.
3. Preserve all `session_id`/`academic_year_id` references.
4. Produce a report of unresolved students.
5. Make the migration reversible (down method drops new columns/tables only; does not touch legacy data).

## 8. Implementation phases (proposed)

- **Phase 1 (this report)**: audit complete.
- **Phase 2**: Database foundation — additive migrations for exemptions, credit transfers, progression decisions, status fields. No data writes.
- **Phase 3**: Cohort-specific academic periods (optional per cohort) — extend `AcademicPeriod` with optional `programme_cohort_id`; reuse school-wide periods where schedules match.
- **Phase 4**: Centralized curriculum — already exists; verify multi-programme isolation and versioning (no new code likely).
- **Phase 5**: Student progression engine service + admin UI (review/approve/retain).
- **Phase 6**: Exemptions & credit transfers module (request → approve/reject → update requirements) + document upload.
- **Phase 7**: Integration into registration, exams, results, transcripts, fees, reports, portals.
- **Phase 8**: Tests + migration dry-run + deployment plan.

## 9. Permissions to add (role-based, following existing pattern)

- `academic.exemption.submit`, `academic.exemption.approve`, `academic.exemption.reject`, `academic.exemption.view`
- `academic.progression.review`, `academic.progression.approve`, `academic.progression.exceptional`
- `academic.cohort.transfer`, `academic.credit_transfer.manage`
- `academic.records.view`

## 10. Open questions for the product owner

1. Should exemptions auto-place a student at the next level, or require explicit academic-officer approval of placement? (Recommended: approval required.)
2. Should an exempted course unit show as `EXEMPTED` only, or also carry a notional credit count? (Recommended: credit-bearing exemption recorded separately, no fabricated grade.)
3. Should cohort-specific semester schedules be fully independent, or default to the school calendar with overrides? (Recommended: default + override.)
4. Is there an existing "exemption" concept in paper records that must be backfilled? (Determines migration scope.)

## 11. Recommendation

Proceed to Phase 2 (additive database foundation) on a feature branch with a verified backup, then Phase 5/6 (services + UI). Do not touch existing academic-year/semester tables or data. All new functionality is additive and gated by new permissions.

**No changes have been made to the codebase in this phase.**
EOF