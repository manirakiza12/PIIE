# Programme CRUD and student registration flow

Student Placement is a standalone module. Staff create an account under Students,
then place that student in an approved Programme Curriculum, Programme Year and
entry Academic Year. An open Course Allocation linked to the matching curriculum
course unit and teaching period reviews the student automatically. Placement does
not itself create a course registration.

Course Registrations opens on Not Registered. Eligible candidates can be registered
individually or in bulk; failed placement or fee checks appear under Needs Attention.
Registration moves the student to Registered Students awaiting confirmation.
Confirmation activates course access. Withdrawn registrations remain in History.
Registration and confirmation recheck placement and the current-session 20% fee
threshold, including the existing authorised waiver rules.

## CRUD controls

| Module | Create / read / update / delete behavior |
| --- | --- |
| Programme Curriculum | Create and view; explicit list Edit and Delete for drafts. Approved curricula retain version history. |
| Programme Years | Create, view course-unit details, edit and delete draft years. Approved curriculum structure remains immutable. |
| Course Units | Existing create/edit/delete, with an explicit disabled View form. Curriculum placements retain draft protections. |
| Student Placement | Create, list/view details, correct an unused initial direct placement with a reason, delete an unused direct placement. Student accounts and admission profiles are retained. |
| Course Allocation | Create and view; explicit list Edit/Delete for unused drafts. Used allocations retain historical identity. |
| Lecturer Assignments | Create, view, update planned assignments, delete planned assignments. End/cancel preserves active assignment history. |
| Course Registrations | Register, view, confirm/withdraw, delete an unconfirmed pending registration while the allocation is open. Deleted pending students return to candidates. Confirmed history and related records cannot be deleted. |

Placement correction and deletion lock the student and placement, enforce tenant
and administrative actor boundaries, reject group-linked or historical placements,
and reject students with course registration history. Correction requires one
initial placement, a matching approved curriculum/year, and an audit reason; it
also synchronises the profile programme and year of study. Mutations write audit
records. All new routes use the existing academic permission registry.

No database migration is needed. Existing standalone navigation and guidance are
preserved; no cross-module quick links are added.
