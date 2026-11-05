# PIIE — Cohort-First Transition: Gap Analysis and Implementation Plan

**Status:** Analysis and plan only. No code, schema, or production changes.
**Scope:** Academic Year / Programme Year / Semester → Cohort-First transition.

## 1. Current vs Intended Architecture

### Current
- Direct placement (`DirectAcademicPlacementController::store`) requires `curriculum_id`, `entry_curriculum_stage_id`, `academic_year_id` (AcademicYear mandatory).
- `StudentCurriculumAssignment` identity includes `entry_academic_year_id`, `effective_from_academic_year_id` (immutable).
- `ProgrammeCohort` already exists and owns programme/intake/entry academic year/curriculum, but placement does not consume it.
- Course registration explicitly prohibits `programme_cohort_id` (`CourseOfferingController` validation).
- Academic periods carry optional `programme_cohort_id` column but `AcademicPeriod` model `$fillable` omits it; no controller/UI uses it.
- Assessment, results, reporting: no cohort references found.

### Intended (cohort-first)
Institution → Programme → Cohort → Curriculum → Curriculum Stage → Student Placement → Course Registration → Assessment → Progression.
Cohorts become the primary operational grouping. Academic years/calendar periods remain internally for dates, reporting, exams, history but should not drive placement.

## 2. Existing Dependencies on Academic Year / Programme Year / Semester

| Dependency | Location | Nature |
|---|---|---|
| Direct placement form | `DirectAcademicPlacementController::edit/store` | `academic_year_id` required; `years` (AcademicYear) passed to view |
| Assignment identity | `StudentCurriculumAssignment` | `entry_academic_year_id`, `effective_from_academic_year_id` immutable |
| Curriculum | `Curriculum.effective_academic_year_id` | FK to AcademicYear |
| Academic periods | `AcademicPeriod.academic_year_id`, `semester_id` | required |
| Course offerings | `CourseOffering.academic_period_id` | FK to AcademicPeriod |
| Progression service | `placeStudent` sets `entry_academic_year_id` from cohort's `entry_academic_year_id` | cohort already carries entry year |
| Cohort model | `ProgrammeCohort.entry_academic_year_id` required | cohort retains year as anchor |

No "Programme Year" table exists; "programme year" is represented by `year_of_study` on `StudentProfile` and `entry_curriculum_stage_id`.

## 3. Redesign of Student Direct Placement

Proposed cohort-first form:
- Student
- Programme (or Cohort → programme resolved)
- Cohort (active cohorts for programme)
- Approved Curriculum (auto-resolved from cohort.curriculum_id)
- Curriculum Stage / Entry Level (from cohort's curriculum)
- Placement Date

Additional fields genuinely necessary: `academic_year_id` may be retained internally (resolved from cohort.entry_academic_year_id) for historical/audit; `year_of_study` retained on profile. No removal of existing fields until backward compat verified.

Backward compatibility: keep `entry_academic_year_id`/`effective_from_academic_year_id` on assignment, populated from cohort's entry academic year when placement is cohort-based. Direct (non-cohort) placement path retained during transition.

## 4. Admin Navigation

- Add Programme Cohorts under Academic Setup (already in `academic_navigation.blade.php` under Programmes).
- Retain Academic Years, Semesters, Programme Years (no removal) until replacement workflows verified.
- Do not hide until cohort-based period scoping is wired to UI.

## 5. End-to-End Integration Gaps

| Area | Current cohort awareness | Gap |
|---|---|---|
| Placement | None (year-driven) | Needs cohort selection + auto curriculum |
| Registration | Explicitly prohibited | Decide whether cohort should gate eligibility; currently no |
| Course eligibility | Not cohort-based | None planned |
| Progression | Decision records exist; placement cohort-aware via membership | Service ready, UI exists |
| Exemptions/credit transfers | Module exists; no cohort references | Review whether cohort should scope exemptions |
| Academic period selection | Column exists, unused | Wire model/UI/controller |
| Reporting | Not cohort-aware | New requirement |

## 6. Existing Students and Data Protection

- No migration auto-assigns existing students (verified: no inserts into `programme_cohort_memberships`).
- `cohort:backfill-students` exists, dry-run by default, matches via `Admission.email`, requires exactly one active cohort per programme+intake.
- Plan: dry-run reconciliation, unmatched-record report, rollback (memberships deletable? No — model forbids deletion; use transfer/withdraw). Never destructive.
- Preserve all existing records; additive only.

## 7. Database Changes Required (proposed, not applied)

- No new field needed on `StudentCurriculumAssignment`: existing `programme_cohort_membership_id` FK already links placement to cohort membership.
- Wire `AcademicPeriod.programme_cohort_id` into model `$fillable` + controller + UI (currently column-only).
- Consider `curricula.effective_academic_year_id` becoming nullable/derived from cohort (defer).
- No table deletions.

## 8. Screens/Forms Requiring Redesign

- `resources/views/admin/academic/direct_placement.blade.php` — add cohort selector, auto-resolve curriculum/stages.
- `DirectAcademicPlacementController::edit/store` — accept cohort, resolve curriculum/year.
- `admin/academic/placements` index — show cohort.
- Academic period create/edit forms — add optional cohort selector.

## 9. Affected Modules

Admissions (auto-assign on enrollment already), registration (no change planned), academic calendar (period scoping), placement, progression, exemptions, reporting.

## 10. Backward Compatibility Strategy

- Retain direct placement path (year-based) during transition.
- Cohort-based placement populates legacy year fields from cohort.
- Feature flags / optional cohort selection (cohort required only when active cohorts exist).
- No breaking changes to existing assignments.

## 11. Security and RBAC

- Reuse permissions: `academic.programme_cohort.manage`, `.lifecycle`, `.membership`.
- New permission possibly `academic.direct_placement.cohort` — or reuse existing direct placement permission.
- Tenant isolation via `school_id` preserved.

## 12. Phased Implementation Plan

- Phase A: Wire `AcademicPeriod.programme_cohort_id` to model/controller/UI + tests.
- Phase B: Cohort-first Direct Placement form (cohort selector, auto curriculum/stage/year) with backward-compat retention.
- Phase C: Reporting by cohort.
- Phase D: UAT + backfill dry-run on production.
- Phase E: (optional) make cohort required for new placements after UAT.

## 13. Automated Testing and UAT Criteria

- Unit: cohort resolution, year derivation, validation.
- Feature: placement with cohort, period scoping, registration unchanged.
- UAT: create cohort → assign → place → period → report.

## 14. Risks and Safeguards

- Risk: existing placements lack cohort; backfill may leave unresolved.
- Safeguard: dry-run, unmatched report, no deletion, rollback via membership transfer/withdrawal.
- Risk: UI complexity; mitigated by optional cohort.

## 15. Approval Required

No code/schema/production changes will be made until this plan is approved.

### 16.1 No module rebuilding
Reuse existing modules (ProgrammeCohortService, DirectAcademicPlacementController, CourseOfferingController, ExemptionService, ProgressionService). Extend only.

### 16.2 Cohort relationship
`StudentCurriculumAssignment.programme_cohort_membership_id` already links to `ProgrammeCohortMembership`. No redundant `programme_cohort_id` field on assignment.

### 16.3 Admin navigation root cause
`$showCohorts` is computed in `resources/views/admin/navigation.blade.php` but `admin.programme_cohorts.index` is absent from `$setupRoutes` (and `$workspaceMenuRoutes`) in `resources/views/admin/partials/academic_navigation.blade.php`. Thus the link is filtered out of every group and never renders. Fix: add the route to `$setupRoutes` (and workspace routes) — small, reversible. No duplicate menu.

### 16.4 Direct placement
Design cohort-first using `ProgrammeCohortService` and existing placement infra: resolve approved curriculum, stages, and entry academic year from selected cohort. Retain year-based path during transition.

### 16.5 Transition policy
Legacy placement workflows remain compatible. After successful migration + UAT, new placements use cohorts by default. Permitted exceptions: students without a matching active cohort (manual direct placement by authorized admin with reason). Authorization: existing direct-placement permission + admin role.

### 16.6 Course registration
`programme_cohort_id` is prohibited in `CourseOfferingController::registerBulk` to prevent client injection of an unimplemented cohort filter. Keep restriction until cohort-based eligibility is designed and tested. Registration remains student-list based.

### 16.7 Data safety
No destructive schema changes or production backfill. Dry-run backfill + unmatched report + rollback via membership transfer/withdrawal.

### 16.8 Implementation style
Small, reversible, testable changes with clear rollback procedures.

## 17. Phase-by-Phase Implementation Specification

- Phase A (low risk): Add `admin.programme_cohorts.index` to `$setupRoutes`/`$workspaceMenuRoutes` in `academic_navigation.blade.php`. Acceptance: cohorts menu visible for higher_ed admins with permission. Dependency: none.
- Phase B: Wire `AcademicPeriod.programme_cohort_id` into model `$fillable`, controller validation, create/edit views, tests. Acceptance: optional cohort on period; existing periods unchanged. Dependency: Phase A optional.
- Phase C: Cohort-first Direct Placement form (cohort selector → auto curriculum/stages/entry year) keeping year-based fallback. Acceptance: placement with cohort creates assignment with `programme_cohort_membership_id`; legacy path still works. Dependencies: ProgrammeCohortService, CurriculumStage.
- Phase D: Reporting by cohort (new views/queries). Acceptance: cohort-filtered reports. Dependency: Phase B/C.
- Phase E (after UAT): make cohort required for new placements; document exceptions.

Each phase: exact files, tests, rollback (revert commit; schema additive only).
