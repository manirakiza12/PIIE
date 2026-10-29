# Curriculum assignment: controlled first phase

Reference: verified main `0b1924ca54ed89c72400d08a3e80476888b1e719`.

## Scope and ownership

This adds human-reviewed orchestration around `StudentCurriculumAssignmentService::assignDirectPlacement()` and reuses `CurriculumFoundationService::approvalBlockers()`. It does not select a version by dates or names. No scheduler, admissions acceptance hook, automatic mapping approval, financial write, or direct course-enrolment call is added.

The academic navigation entry **Curriculum Assignment** opens `/admin/curriculum-assignment`. The existing placement and registration workflows remain available. No existing permission grant or institutional policy is installed by migration.

## Operator workflow

1. A permitted preparer drafts a mapping with an explicit Programme, Admissions Intake, approved Curriculum version, Entry Academic Year, Entry Programme Year and policy/Registrar reference. Entry Academic Year is also the placement effective year, matching the existing complete-placement service. Distinct effective years are outside this phase.
2. An authorized Registrar explicitly approves the mapping. At most one approved mapping may cover a programme/intake; approvals serialize on the institution row. Approved mappings are immutable in this workflow. Revoke and create a replacement to change them. Revocation preserves applied placements.
3. Select a linked accepted/enrolled new admission. Record the ordinary initial-entry evidence, including review of transfer, advanced-entry and credit-recognition information. These matters cannot be inferred from absence of a transfer request. Unsupported cases stay with the existing manual workflow.
4. Generate a pending proposal. The system validates the authoritative admission-to-student link, profile programme/intake, curriculum readiness and mapping provenance. An identical retry returns the existing decision, including a rejection.
5. Registrar reviews the exact proposed values and recorded evidence, gives a decision reason, and approves or rejects. Approval requires explicit acknowledgement of the existing enrolment consequence. Approval alone writes no placement.
6. An authorized Registrar with existing placement authority separately clicks **Apply approved placement** and acknowledges the consequence again. All business data, approvals and permissions are revalidated under locks. The existing complete-placement service writes the placement and profile summary atomically with decision provenance and the mandatory audit event.

**Consequence shown at approval and application:** existing automatic course enrolment may subsequently register/confirm eligible courses after its academic and Finance checks. This module creates neither course registrations nor financial obligations. The existing scheduler is unchanged.

## Permissions

- `academic.curriculum_assignment.view`: mappings, proposals and institution exceptions.
- `academic.curriculum_assignment.manage`: draft mappings and propose placements.
- `academic.curriculum_assignment.approve`: approve/revoke mappings and approve/reject proposals.
- Applying additionally requires `academic.programme_cohort.membership`, the existing complete-placement authority.

The existing school/super-admin bypass remains. Other authorized Registrars receive explicit grants through existing RBAC; no role number is guessed to mean Registrar. Neutral Generic Staff can call the placement service only with both approval and placement permissions. Preparation and approval are separate capabilities, but this phase does not mandate different people: the institution must decide any maker/checker rule.

## Storage and integrity

The additive migration creates `curriculum_applicability_mappings` and `curriculum_assignment_proposals`. Mapping approval retains approver/time and a content fingerprint plus snapshot. Proposals retain ordinary-entry evidence, the reviewed snapshot/fingerprint, creator, decision/reason/time, enrolment acknowledgement, applying actor/time and resulting placement ID. A unique institution/admission/fingerprint key deduplicates decisions; a unique placement reference prevents duplicate result attachment. Foreign keys restrict deletion and mapping references include institution identity. Other tenant relationships are validated under locks.

The institution lock serializes this module's mutations with existing automatic course enrolment/account preparation. Student and academic rows are locked while validating; the existing placement service retains its own student lock and active-assignment protection. Approval and apply use the same validation. Changes to the relevant curriculum graph, units, programme, intake, academic year, profile context or admission invalidate prior approval. Conservative fingerprints may require fresh approval after an otherwise harmless metadata edit. The mapping approver and proposal reviewer must retain approval authority at application time.

Curriculum deletion is refused when applicability history exists, because the existing deletion graph would otherwise discover and delete these governance records. Mapping revocation or proposal rejection never deletes business records.

## Exceptions and protected cases

Existing `AutomationExceptions` stores one institution/workflow/subject item with first/last observation, occurrence count, owner and resolution history. Curriculum proposal failures use `curriculum_assignment` keyed by admission; mapping approval failures use `curriculum_mapping` keyed by mapping. The review screen shows unresolved items. Existing `automation:exceptions --school=... --history` remains the full history entry point.

Missing/ambiguous mappings, inactive contexts, changed approvals, missing history schema, profile/identity conflicts, any placement (including ended history), any course registration (including withdrawn), legacy class enrolment, cohort history, transfer requests and pre-existing-student profiles block this path. Failed writes roll back placement/profile/decision together. Exceptions are recorded after a business validation rollback and resolve only after successful revalidation. Invalid draft form inputs are returned for correction before a mapping exists.

No exemption or prior-credit decision is fabricated. There is no dedicated governed credit-recognition ledger in the audited schema. The Registrar's mandatory ordinary-entry evidence is required; students with such claims must use manual review. Historic placement, transfer, registration, admission and Finance records are never rewritten by this module.

## Policies still requiring Registrar approval

- Which programme/intake/version/entry-year/stage combinations are institutionally approved (each mapping requires an actual reference).
- Whether placement is appropriate at acceptance or only after formal enrolment: this phase permits explicit review of either linked state and introduces no automatic trigger.
- Whether study mode/campus changes applicability. This phase supports one approved mapping per programme/intake; where finer distinctions apply, do not approve a broad mapping.
- How ordinary initial entry is established and evidenced, including transfers, advanced entry, exemptions and prior credit.
- Readmission, deferment, continuing students, progression, backfill, teach-out and version migration remain outside this phase.
- Delegated Registrar authority, any required separation of preparer/reviewer, and institutional acceptance of the existing automatic-enrolment consequence.

No approval of these policies is inferred from a successful technical test.

## Validation

`CurriculumAssignmentProposalTest` covers explicit approval/application, permissions and delegation, tenant boundaries, evidence/acknowledgement, duplicates and rejection, content changes, revoked approvals, identity/profile state, history/transfer exclusion, exception retries/resolution, mandatory-audit rollback, provenance deletion protection, read-only review and downstream Finance/progression safeguards.

`CurriculumProposalMysqlConcurrencyTest` uses the existing disposable `ci_test` database on GitHub CI and two actual PHP worker processes. It exercises competing mapping approvals, duplicate proposal creation and simultaneous application. It refuses a non-CI connection and skips on local SQLite; local SQLite results are not evidence of MySQL row-lock behaviour. The regular CI migrations exercise the real schema and foreign keys. Related assignment, curriculum, admission, registration, automatic enrolment and RBAC suites must also pass.

No production migration or business-data test is part of this change. PR review and a separate deployment authorization remain required.
