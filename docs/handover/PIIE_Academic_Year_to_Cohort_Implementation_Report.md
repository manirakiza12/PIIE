# PIIE — Academic Year to Cohort-Based Academic Management: Implementation Report

**Website:** https://piie.ac.ug
**Deployed commit:** `dce371293c06d78a6e8661fca78a226c3db3c93d`
**Deployment run:** PIIE Deploy #41792999327 (success)
**Backup:** `/home/piie/deployments/piie/backups/20261104-073358-dce371293c06`
**Scope:** Academic Year → Cohort transition and directly related academic functionality only.

---

## 1. Executive Summary

### What the Academic Year–based system did previously

PIIE originally managed academic progression, course enrolment, and reporting primarily around an `AcademicYear` (and, in places, a `Semester`). A student's programme, intake, and curriculum were tied to loose, per-student fields on `StudentProfile` (`programme_id`, `intake_session_id`, `year_of_study`, `curriculum_id`) and to `AcademicYear` records. Academic periods (semesters/terms) referenced `academic_year_id` and `semester_id` but were not grouped by cohort.

### Limitations and operational challenges

- No durable grouping of students who entered the same programme/intake together; grouping was implicit via `programme_id` + `intake_session_id` on each student.
- Curriculum assignment was per-student and mutable, with no cohort-level study plan.
- Academic periods could not be scoped to a cohort, so two intakes sharing a programme shared one calendar.
- Progression, exemptions, and credit transfers were either absent or handled ad-hoc; there was no auditable decision record.
- Reporting and status changes for deferred/transferred/withdrawn students were not first-class.

### Why Cohort-based management was introduced

To model a real-world intake as a first-class entity (`ProgrammeCohort`) that owns a programme, an intake session, an entry academic year, and an approved curriculum (study plan), and to attach students to it through immutable membership history. This makes intake, progression, and reporting explicit and auditable.

### What has now been implemented

Seven phases, merged to `main` and deployed:

- **Phase 2** (`56529fd`, `7358137`, `58acd5c`): additive DB foundation — `student_academic_exemptions`, `student_academic_exemption_items`, `student_credit_transfers`, `student_progression_decisions`, academic-status fields on `student_curriculum_assignments`, cohort FK on `academic_periods`.
- **Phase 3** (`eb1c9f8`): cohort-specific academic periods (`programme_cohort_id` on `academic_periods`).
- **Phase 5** (`aa1fcdd`): progression admin UI (controller, routes, views).
- **Phase 6** (`5b21c4d`): exemptions module (models, service, controller, routes, views) + progression service.
- **Phase 7A** (`219e76a`): student registration integration (auto cohort assignment on enrollment + `cohort:backfill-students` command).
- **Phase 7B (this deployment)** (`dce3712`): migration hardening for pretend-mode, tenant-safe FKs, required-tables preflight, deploy capture.

### What remains dependent on academic years or semesters

Cohort-based management does **not** eliminate academic years. In the code, a `ProgrammeCohort` still requires an `entry_academic_year_id` (FK to `academic_years`), and `StudentCurriculumAssignment` retains `entry_academic_year_id` and `effective_from_academic_year_id`. Academic periods still carry `academic_year_id` and `semester_id`. Academic years remain the time anchor; cohorts are the grouping/intake anchor layered on top.

### Current implementation and deployment status

Deployed to production on `dce3712`. All six pending migrations ran successfully. Public smoke checks pass. The module is operational but partially verified (see §11).

---

## 2. Before vs After: Academic Year to Cohort

| Area | Before (Academic-Year-centric) | After (Cohort-based) |
|---|---|---|
| Student admission/intake | `Admission` carried `programme_id` + `intake_session_id`; student profile copied these. | `ProgrammeCohort` is created per programme+intake; students assigned via `ProgrammeCohortMembership`. |
| Student grouping | Implicit via `programme_id`/`intake_session_id` on `User`/`StudentProfile`. | Explicit durable group via `programme_cohorts` + `programme_cohort_memberships`. |
| Programme enrolment | Per-student `StudentProfile.programme_id`. | Cohort owns `programme_id`; membership links student→cohort. |
| Curriculum assignment | Per-student `StudentProfile.curriculum_id`/`curriculum_stage_id`. | Cohort owns `curriculum_id`; `StudentCurriculumAssignment` created at placement with `programme_cohort_membership_id`. |
| Academic periods | `academic_periods` keyed by `academic_year_id`+`semester_id` only. | Optional `programme_cohort_id` FK added (additive, nullable). |
| Semesters/levels | `year_of_study` on profile. | `year_of_study` still on profile (set at placement); academic status fields added to `student_curriculum_assignments`. |
| Progression | No dedicated decision record. | `student_progression_decisions` + `ProgressionService`. |
| Course registration | Unchanged (course/subject enrolment not cohort-keyed in this phase). | No change — still course/subject based. |
| Assessment/results | Unchanged. | No change. |
| Exemptions/credit transfers | Not implemented. | `StudentAcademicExemption` + items + `StudentCreditTransfer` + `ExemptionService`. |
| Reporting | Academic-year/semester based. | Cohort-aware reporting not yet implemented (cohort data available for future reports). |

**Transitioned to cohort-based:** intake grouping, curriculum assignment, progression decisions, exemptions, credit transfers, academic-period scoping (optional), student membership lifecycle (defer/transfer/withdraw/complete).

**Still academic-year/semester based:** course/subject enrolment, assessment, results, academic calendar core, reporting, `year_of_study`.

---

## 3. What Was Actually Implemented

### Phase 2 — DB foundation (`56529fd`, `7358137`, `58acd5c`)

- `2026_11_03_000001_create_student_academic_exemptions_table.php` — creates `student_academic_exemptions` (school, student, programme, curriculum, exemption_type, status, institution fields, decision fields, soft deletes) with tenant composite FKs.
- `2026_11_03_000002_create_student_academic_exemption_items_table.php` — creates `student_academic_exemption_items` (school, exemption, curriculum, curriculum_membership, subject, status, notes).
- `2026_11_03_000003_create_student_credit_transfers_table.php` — creates `student_credit_transfers`.
- `2026_11_03_000004_create_student_progression_decisions_table.php` — creates `student_progression_decisions`.
- `2026_11_03_000005_add_academic_status_fields_to_student_curriculum_assignments.php` — adds `current_academic_level`, `expected_graduation_date`, `graduation_eligibility_status` to `student_curriculum_assignments`.
- `2026_11_04_000001_add_cohort_to_academic_periods.php` — adds `programme_cohort_id` (nullable) + composite FK to `academic_periods`.

Tenant-safe FK corrections (`caae9fc`): curricula uses `unique(school_id, id)` not `(school_id, programme_id, id)`; subjects uses composite `(school_id, id)`.

### Phase 3 — cohort-specific academic periods (`eb1c9f8`)

- `2026_09_26_000002_create_programme_cohorts_table.php`, `2026_09_26_000003_create_programme_cohort_memberships_table.php` — created earlier (`e9c1fb6`).
- `academic_periods.programme_cohort_id` added (nullable) so a period can be scoped to a cohort.

### Phase 5 — progression admin UI (`aa1fcdd`)

- `app/Http/Controllers/StudentProgressionController.php`, routes `admin/progression`, views.

### Phase 6 — exemptions module (`5b21c4d`)

- `app/Models/StudentAcademicExemption.php`, `StudentAcademicExemptionItem.php`, `StudentProgressionDecision.php`, `app/Support/Exemptions/ExemptionService.php`, `app/Support/Progression/ProgressionService.php`, `app/Http/Controllers/StudentExemptionController.php`, routes `admin/exemptions`, views.

### Phase 7A — registration integration (`219e76a`)

- `app/Console/Commands/CohortBackfillStudents.php` — dry-run/apply backfill.
- Auto cohort assignment on enrollment (service integration).

### Phase 7B — deployment hardening (`dce3712`)

- Removed throwing `Schema::hasTable` guards from six pending migrations (pretend-mode returns empty).
- Added `app/Console/Commands/CheckRequiredTables.php` (`db:check-required-tables`).
- Added `deploy/remote/check-required-tables.sh` preflight against candidate release.
- Updated `deploy/remote/container-deploy.sh`.

**Status:** Fully operational: cohort CRUD, membership lifecycle, placement, backfill command. Code implemented but no dedicated automated tests: exemptions, credit transfers, progression decisions. Partially implemented: academic-period cohort scoping (DB column only, not wired to model/controller/UI). Unverified: end-to-end UI demonstration, multi-cohort same-year scenarios in production.

---

## 4. Cohort Architecture and Relationships

### Core entities

- `schools` (tenant root) → `programmes` → `programme_cohorts` → `programme_cohort_memberships` → `users` (students, role_id=7).
- `programme_cohorts.curriculum_id` → `curricula` (approved study plan).
- `programme_cohorts.intake_session_id` → `intake_sessions`.
- `programme_cohorts.entry_academic_year_id` → `academic_years`.
- `programme_cohort_memberships` → `student_curriculum_assignments` (via `programme_cohort_membership_id`).
- `academic_periods.programme_cohort_id` → `programme_cohorts` (optional).

### Cohort relationship diagram (text)

```
School
  └─ Programme
       └─ ProgrammeCohort (programme_id, intake_session_id, entry_academic_year_id, curriculum_id, name, code, status)
            ├─ ProgrammeCohortMembership (student_id, status: active/deferred/transferred/withdrawn/completed, started_at, ended_at)
            │     └─ StudentCurriculumAssignment (student_id, curriculum_id, entry_curriculum_stage_id, programme_cohort_membership_id, entry_academic_year_id, effective_from_academic_year_id, ended_at)
            └─ AcademicPeriod (optional programme_cohort_id, academic_year_id, semester_id)
Student (User, role_id=7) ── StudentProfile (programme_id, intake_session_id, year_of_study, curriculum_id)
```

### Student academic lifecycle

1. Admission → 2. Cohort assignment (`assign`/`assignFromAdmission`) → 3. Placement (`placeStudent`: stage + year_of_study) → 4. Active membership → 5. Progression decisions / exemptions / credit transfers → 6. Completion/transfer/withdrawal → 7. Curriculum assignment ended.

### Tenant isolation

Every cohort-related table carries `school_id`. Services use `actorSchool($actor)` and scope all queries by `school_id`. Models enforce domain-write gating (DomainException unless via service). FKs are composite tenant keys where the referenced table has a unique `(school_id, id)`.

---

## 5. End-to-End Cohort Workflow

Routes (admin, RBAC):
- `admin/programme-cohorts` (index/create/store/edit/update/show/lifecycle/members/placement)
- `admin/exemptions` (index/create/store/show/approve/reject/cancel/curriculumStages/curriculumMemberships)
- `admin/progression` (index/show/decide)

Steps:

1. **Configure programmes and curricula** — Admin. Create approved `Curriculum` for programme. Required: programme, stages. DB: `programmes`, `curricula`, `curriculum_stages`.
2. **Create cohort** — Admin, `academic.programme_cohort.manage`. Required: programme, intake session, entry academic year, curriculum, name, code. DB: `programme_cohorts` (status=draft).
3. **Activate cohort** — Admin, `academic.programme_cohort.lifecycle`. Requires approved curriculum, references valid. DB: status→active.
4. **Assign students** — Admin, `academic.programme_cohort.membership`. Duplicate current membership blocked. DB: `programme_cohort_memberships`.
5. **Placement** — Admin. Requires active membership + cohort; stage from curriculum; year_of_study (1–255). DB: `student_curriculum_assignments`, `student_profiles` updated.
6. **Academic periods** — (optional cohort scoping) `academic_periods.programme_cohort_id`. DB: `academic_periods`.
7. **Teaching/assessment** — unchanged (course/subject based).
8. **Progression decisions** — Admin, `admin/progression`. DB: `student_progression_decisions`.
9. **Exemptions/credit transfers** — Admin, `admin/exemptions`. DB: `student_academic_exemptions`, items, `student_credit_transfers`.
10. **Membership lifecycle** — defer/resume/withdraw/complete/transfer. DB: membership status + ended_at; assignment ended.

**Unverified:** cohort-specific academic period UI usage; reporting by cohort.

---

## 6. Practical Examples for the System Owner

**Programme:** Diploma in Information Technology
**Cohort:** DIT-2026-September (code `PC-DIT-...`)
**Academic period:** Semester 1, 2026/2027

- Students admitted in September 2026 form one cohort with one approved curriculum. Two cohorts (Sept 2026 and Jan 2027) can follow different curriculum stages in the same academic year because each cohort carries its own `curriculum_id` and `entry_academic_year_id`.
- Repeaters/deferred: membership status `deferred` (resume later); transferred: new membership created, old ended.
- Exemptions/credit transfers: recorded in `student_academic_exemptions` / `student_credit_transfers`, affecting placement/progression.

Illustrative only; production verification pending UAT.

---

## 7. Impact Matrix

| Module | Previous | Cohort-based | DB impact | Dependencies | Users | Risk | Verification |
|---|---|---|---|---|---|---|---|
| Admissions | programme/intake on admission | drives cohort matching | `admissions.programme_id/intake_session_id` | Cohort must exist | Registrar | Low | Partial |
| Programme management | programme only | programme + cohort | `programme_cohorts` | curriculum approved | Admin | Low | Operational |
| Curriculum | per-student | cohort-owned study plan | `curricula`, `curriculum_stages` | approval gate | Admin/Academic | Low | Operational |
| Academic calendar | year/semester only | optional cohort scoping (DB column only; model/controller/UI not wired) | `academic_periods.programme_cohort_id` (nullable, unused by app) | none | Registrar | Low | Not usable |
| Registration | course/subject | unchanged (cohort_id explicitly prohibited in registration) | none new | none | Student/Registrar | None | Unchanged (not cohort-based) |
| Assessment/results | unchanged | unchanged | none | none | Lecturer | None | Unchanged |
| Progression | none | decision records | `student_progression_decisions` | placement | Admin/Registrar | Low | Operational |
| Exemptions | none | full module | 3 new tables | curriculum stages | Admin/Registrar | Low | Operational |
| Reporting | year/semester | cohort data available, reports not yet cohort-aware | none | future work | Admin | Low | Not implemented |

---

## 8. Data Migration and Existing Student Records

- Existing `StudentProfile` fields (`programme_id`, `intake_session_id`, `year_of_study`, `curriculum_id`) remain as legacy summary; `StudentCurriculumAssignment` is authoritative.
- Historical academic-year data remains unchanged (additive migrations only).
- Existing students are **not** automatically assigned to cohorts. `cohort:backfill-students` (dry-run by default, `--apply`) matches via `Admission` email and requires exactly one active cohort per programme+intake; unresolved cases reported.
- Duplicate current membership prevented by `whereNull('ended_at')` check in `assign()`.
- Existing reports remain compatible (no schema removals).
- Risk: students without matching admission/cohort remain unresolved; manual assignment required.

---

## 9. Technical Implementation for the Developer

**Migrations:** `database/migrations/2026_09_26_000002_*.php`, `000003_*.php`, `2026_11_03_000001..000005`, `2026_11_04_000001`.

**Models:** `app/Models/ProgrammeCohort.php`, `ProgrammeCohortMembership.php`, `StudentAcademicExemption.php`, `StudentAcademicExemptionItem.php`, `StudentProgressionDecision.php`, `StudentCurriculumAssignment.php`.

**Services:** `app/Support/ProgrammeCohorts/ProgrammeCohortService.php` (createDraft, updateDraft, transition, assign, defer, resume, withdraw, completeMembership, transfer, placeStudent, assignFromAdmission), `app/Support/Exemptions/ExemptionService.php`, `app/Support/Progression/ProgressionService.php`.

**Controllers:** `app/Http/Controllers/ProgrammeCohortController.php`, `StudentExemptionController.php`, `StudentProgressionController.php`.

**Routes:** `routes/web.php` lines ~1083–1116.

**Backfill:** `app/Console/Commands/CohortBackfillStudents.php` (`cohort:backfill-students`).

**Tests:** `tests/Feature/*Cohort*` (if present), deployment sandbox `deploy/tests/container-runtime.sh`.

**Known limitations:** cohort-aware reporting not implemented; academic-period cohort scoping UI unverified; `Schema::hasTable` guards removed from pending migrations (pretend-mode); `Schema::hasColumn` guards retained (may show extra DDL in pretend but safe).

---

## 10. Owner Demonstration and Training Guide

Demonstrate:
1. Previous approach: student profile fields, no cohort grouping.
2. New: Admin → Programme Cohorts → Create (draft) → Activate.
3. Assign student → Membership created.
4. Placement → stage + year of study.
5. Defer/transfer/complete membership.
6. Exemptions: Admin → Exemptions → Create → Approve/Reject.
7. Progression: Admin → Progression → Decide.
8. Backfill: `php artisan cohort:backfill-students` (dry-run).

**Cannot yet demonstrate:** cohort-specific academic period UI, cohort-based reporting (not implemented).

---

## 11. Findings and Recommendations

**Completed & deployed:** cohort CRUD, membership lifecycle, placement, exemptions, credit transfers, progression decisions, backfill command, migration hardening, preflight.

**Tested:** unit/feature tests in CI (PHP 8.3/MySQL), deployment sandbox (15/15), production smoke checks.

**Requires UAT:** end-to-end UI flows, multi-cohort same-year, reporting.

**Known limitations:** reporting not cohort-aware; academic-period cohort scoping UI unverified; backfill requires manual resolution of unmatched students.

**Recommended next steps:** cohort-aware reporting, cohort academic period UI, UAT, expand tests.

**Owner acceptance checklist:**
- [ ] Create and activate a cohort
- [ ] Assign and place a student
- [ ] Defer/resume/transfer a membership
- [ ] Create and approve an exemption
- [ ] Record a progression decision
- [ ] Run backfill dry-run and review unresolved
- [ ] Verify existing student data unchanged
- [ ] Verify reports still generate

## 12. What the Owner Can Use Today vs What Still Needs Development

### Usable today (verified in code/routes/views)
- Programme Cohort CRUD (draft/active/completed/cancelled) - admin/programme-cohorts, ProgrammeCohortController, ProgrammeCohortService.
- Membership lifecycle (assign, defer, resume, withdraw, complete, transfer) - programme_cohort_memberships routes + controller.
- Academic placement (entry stage + year of study) - placement/place routes.
- Exemptions module (create/approve/reject/cancel) - admin/exemptions, StudentExemptionController, ExemptionService.
- Progression decisions - admin/progression, StudentProgressionController, ProgressionService.
- Backfill command (dry-run by default) - php artisan cohort:backfill-students.

### Not usable / not wired
- Academic period to cohort assignment: DB column academic_periods.programme_cohort_id exists but AcademicPeriod model fillable omits it and no controller/view uses it.
- Cohort-aware academic reporting: not implemented.
- Course registration / assessment / results: explicitly not cohort-based (programme_cohort_id prohibited in registration).

### Existing students
- No migration auto-assigns existing students. Evidence: no migration inserts into programme_cohort_memberships; cohort:backfill-students is dry-run by default and matches via Admission.email. Production assignment status cannot be verified from the repository; run the command in --dry-run (read-only) to assess.

### Incomplete / unverified
- Exemptions, credit transfers, progression: no dedicated automated feature tests (only domain tests for ProgrammeCohort).
- End-to-end UI demonstration and multi-cohort same-year scenarios: not verified in production.
- Academic-period cohort scoping UI: not implemented.
