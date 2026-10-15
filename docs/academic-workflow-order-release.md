# Academic workflow order and Teaching Groups

The live academic navigation is ordered by practical dependencies. Every entry
retains its existing permission and menu allow-list checks; unavailable entries
are omitted without changing the relative order of the remaining entries.

1. Schools, Faculties & Departments
2. Programmes
3. Academic Years
4. Semesters (or the institution's configured Terms)
5. Grading Scales
6. Programme Curricula, including Programme Years & Stages
7. Course Units
8. Teaching Groups
9. Programme Student Groups
10. Lecturer Assignments
11. Course Registrations
12. Timetable
13. Live Classes
14. Attendance
15. Question Bank
16. Online Exams
17. Marks & Results

Legacy sessions and assignments remain available to the school types that
already used them. Admissions Intakes remain in Admissions, where they are
managed, and must exist before an intake-based Programme Student Group can be
created. No duplicate intake editor or duplicate Programme Years menu is added.

This is a recommended workflow order, not a new restriction: existing catalogue
creation without a curriculum remains possible. Saving credits, stages and
prerequisites together uses the existing draft curriculum relationship.

## Terminology and repeated controls

- The existing classes table groups students for attendance, teacher access and
  exam eligibility. For tertiary institutions its visible name is Teaching
  Groups, with Subgroups for the existing sections. IDs and tables are retained.
- Teaching Groups are distinct from intake-based Programme Student Groups.
- Primary, secondary, mixed and vocational terminology remains unchanged.
- Academic admin, lecturer and student selectors use the existing terminology
  helper for these labels. Timetable screens use Timetable rather than Routines.
- Class creation/editing and duplicate-name feedback use the same terminology.
- Lecturer Assignments has separately labelled, expandable Teaching Group Access
  and Programme Access. Both existing permission editors and AJAX handlers stay.
- Programme Year/Stage creation has one heading and one editor; editing is
  expandable. A duplicated Year / Year / Stage label is corrected.

No database migrations, record deletions, eligibility rules, calculations,
payment operations, approval rules, role assignments or foreign keys change.

## Verification

- 30 targeted tests passed, 177 assertions: terminology by school type, student
  class assignment, lecturer permission flags, orphan handling, Blade syntax,
  stage creation/editing, permission boundaries and rendered navigation order.
- 36 Chrome page/viewport checks passed with no JavaScript errors, at 320, 375,
  768 and 1366px. Includes Teaching Groups, lecturer access, approved and draft
  curriculum pages. No browser form submissions occur.
- Full CI and production verification will be recorded after activation.
