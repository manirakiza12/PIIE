# Unified Course Unit setup

## Existing architecture and scope

Production schema audit: institutions are `schools` (one record); academic divisions are `departments` (five records). The 51 programmes point to departments. Course Units are `subjects`; there is no separate `courses`, `course_units`, `faculties` or `institutions` table. No Course Units or Study Plans existed at the audit baseline.

The course catalogue has code/name and a Programme association. Study Plan credit values, compulsory/elective classification, stage and relative period belong to `curriculum_memberships`; prerequisites are edges between those memberships in `curriculum_prerequisites`. These are intentionally plan-specific: the same Course Unit can be used by different plans without making another Subject. Existing catalogue credits, course-type/level and assessment metadata are not overwritten or made a second source of Study Plan values. There are no existing Subject description/objectives columns to migrate or repurpose.

## Resulting workflow

Academic Structure → Course Units → Create/Edit opens the full-page setup at `/admin/course-unit/setup/{id?}`. It reuses the existing catalogue forms and POST routes. Select an existing draft Study Plan, stage, credits, classification, relative period/order and prerequisite Course Units by name. Saving creates or updates the existing membership through `CurriculumFoundationService` in the same transaction as the catalogue record. Clearing the optional plan selector leaves all placements unchanged; it never deletes them. Only the selected plan is changed. Existing memberships are reused, including on repeated edits. Invalid prerequisites/stages and cycles roll back both catalogue and placement changes.

The Study Plan overview and approval/retirement/successor workflows remain. Staff with both catalogue and curriculum management use setup links instead of duplicate inline placement/prerequisite editors. Bulk selection of existing Course Units moved to the catalogue in Study Plan context; it uses one shared picker partial and the original endpoint/search/options. Staff who only have curriculum permissions retain their original controls, preventing a permission regression or an automatic new catalogue grant. Draft placements can still be removed from the unified edit page using the original removal endpoint. Approved/history records remain protected by the existing service.

No draft or stage is generated automatically. Where none exists, setup explains this and links to the existing Study Plan creation page; users can still save the original catalogue-only record. An existing inactive Programme remains selectable on edit. Form fields recover old input. Institution names and Programme/stage/unit names are displayed; IDs remain internal option values. K-12 catalogue links keep their original modal workflow. No unrelated institution, admissions, exams, results, student or payment forms were changed.

## Verification and release

Local regression: 86 tests, 506 assertions passed, covering curriculum governance, Course Unit create/edit, sharing/duplicate prevention, tenant/permission rejection, transactional rollback, prerequisites/cycles, student registrations and navigation. Chrome synthetic edit-form checks passed at 320, 375, 768 and 1366 pixels, restored existing Programme/stage/credit values, and verified optional placement toggling. No JavaScript exceptions, failed document loads or form submissions. A narrow-phone header overflow on this new page was corrected with page-scoped styling.

Browser reproduction: set `PIIE_CAPTURE_UNIT_FORM=1` while running `CourseUnitSetupTest`, then run `node scripts/course-unit-setup-browser-verify.mjs`. Evidence is in `output/course-unit-setup-browser/`.

Read-only pre-release hashes, including academic tables and protected records: `output/course-unit-setup-release/production-before.json`. No migrations or seeders are introduced. CI, deployment and final production verification are pending.
