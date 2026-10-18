# Standalone programme workflow

The academic workspace now exposes programme setup and delivery as independent modules. Staff no longer have to open a Programme Curriculum to edit Programme Years, or a Course Allocation to find lecturer assignments and student registrations. Existing Bootstrap styles, responsive tables and application navigation are retained.

## Navigation and terminology

The tertiary order is Schools / Faculties, Departments, Programmes, Academic Years, Semesters / Terms, Grading Scales, Programme Curriculum, Programme Years, Course Units, Direct Student Placement, Course Allocation, Lecturer Assignments, Course Registrations, Timetable, Live Classes, Attendance, Question Bank, Online Exams and Marks & Results. Entries continue to respect permissions and existing menu restrictions.

- Programme Curricula is labelled Programme Curriculum.
- Curriculum stages are presented as Programme Years. Internal field names and historical labels are preserved.
- Course Offering is presented as Course Allocation across related administrator, lecturer and student screens and messages. Existing route names and database tables remain compatible.
- Teaching Groups and Programme Student Groups are absent from the tertiary navigation and setup guide. Registration screens no longer offer student-group filters or group-based registration controls. Direct placement and individual/bulk selected-student registration remain available.
- Non-tertiary class navigation and existing historical group records remain intact.

## Independent modules

Programme Years has a standalone `/admin/programme-years` page with curriculum selection, listing, creation, editing and deletion. The service rejects changes to approved/retired structures and deletion of occupied years.

Course Allocation retains its own list, creation form, identity/applicability editor and lifecycle controls. Its detail page no longer embeds lecturer, live-class or registration modules. An optional approved curriculum placement can be selected during creation; incompatible placements roll back the entire creation transaction.

Lecturer Assignments at `/admin/lecturer-assignments` and Course Registrations at `/admin/course-registrations` each provide their own allocation selector and access to the existing authoritative management workflows. Neither requires access to the Course Allocation overview. Tenant and role checks remain in force.

Course Unit catalogue staff keep the unified Course Unit setup form. Staff with curriculum permissions but no catalogue permissions use a standalone placement page at `/admin/curriculum-course-units`. They retain placement, credit, classification and prerequisite editing without gaining catalogue privileges. The navigation displays one applicable Course Units entry.

Programme Curriculum retains its details, read-only structural review, approval, retirement and successor-version controls. Programme Years, Course Unit and allocation shortcuts/editors were removed from it. Programme-list shortcuts to Departments, curricula and allocations were removed. Related Course Unit, lecturer and tertiary examination shortcuts were removed too. Local list/detail/create/cancel actions remain within each module.

The guide remains available on relevant tertiary screens. Its ordered explanation is plain text rather than a second set of module quick links.

## Reuse across Academic Years

The curriculum stores the reusable structure. Its effective Academic Year is a starting floor, not an expiry year. Dated delivery belongs to Course Allocations. A curriculum approved from 2026 can therefore be linked to compatible allocations in 2026, 2027 and later without cloning or modifying its years, units, credits or prerequisites.

The curriculum list's Academic Year filter now means available by that year and compares dates, rather than requiring the curriculum's effective year to equal the selected year. Allocation creation filters placement choices by course unit and semester/term position, and server-side checks enforce approval, tenant, effective date and compatible period.

Existing student placement and progression rules remain authoritative; this change does not invent automatic promotion or rewrite academic history.

## CRUD and history

| Module | Available operations |
| --- | --- |
| Programmes | Existing create, view/preview, edit, activate/deactivate and guarded delete |
| Programme Curriculum | Create, view, edit draft, delete unreferenced draft and its structure; approve, retire and create successor |
| Programme Years | Create, view, edit draft year, delete empty draft year |
| Course Units | Existing catalogue CRUD plus draft placement/prerequisite CRUD, with existing permission boundaries |
| Course Allocation | Create, view, edit draft, delete unused draft; open/start/complete/cancel with existing lifecycle rules |
| Lecturer Assignments | Existing assign, view, edit, activate, end, cancel and replace operations |
| Course Registrations | Existing individual/selected-student registration, view, confirmation and withdrawal; historical records retained |

Allocation deletion checks references before removing the unused draft and its applicability links. Lecturer, registration and delivery history blocks physical deletion. Referenced or approved curricula cannot be deleted. All new operations use existing RBAC and tenant boundaries; draft deletion is transactional and audited.

## Verification

- Programme, curriculum, years, units, allocation, registration and placement regression: **153 tests, 1,372 assertions passed**.
- Connected programme, allocation, lecturer, reference-integrity, tenant and navigation checks: **122 tests, 982 assertions passed**.
- Total: **275 targeted tests, 2,354 assertions**. The full unrelated application suite was not completed.
- All **794 Blade templates** compiled and parsed with PHP 8.3.35; changed PHP files passed syntax checks.
- **56 Chrome page/viewport checks** across 320, 375, 768 and 1366 pixels passed with no clipped controls or JavaScript exceptions. Includes guide visibility, absence of guide links, curriculum-placement filtering, generated allocation reference and clearing stale selections.
- Browser fixtures are rendered from isolated SQLite test data. No production database, `.env`, migration or seeder was changed. Existing protected records were not deleted.

Evidence: `output/programme-regression.xml`, `output/programme-connected.xml`, `output/programme-blade-final.log` and `output/programme-workspace-browser/browser-report.json` with screenshots. Browser reproduction: run the administration tests with `PIIE_CAPTURE_PROGRAMME_WORKSPACE=1`, then `node scripts/programme-workspace-browser-verify.mjs`.

These are local source changes. Deployment and verification on the hosted application are not included.
