# Academic module naming and navigation

The academic workspace had three nested navigation groups and used Study Plan,
Cohort and Stage without explaining their different purposes. This update gives
existing workflows direct navigation and clearer labels.

## Existing workflows and public names

| Existing implementation | Visible name / access |
| --- | --- |
| academic_years | Academic Years |
| academic_periods | Semesters, or Terms for term-based institutions |
| departments | Schools, Faculties & Departments: one existing division editor |
| programmes | Programmes |
| subjects | Course Units, preserving tenant terminology |
| curricula | Programme Curricula |
| curriculum_stages | Programme Years & Stages, inside the selected curriculum |
| programme_cohorts | Programme Student Groups |
| course_offerings and course_registrations | Course Registrations; select an offering, then Students |
| gradebook | Marks & Results |
| academic settings | Grading Scales |
| routine | Timetable |

Grade Appeals is not implemented as a distinct grade review workflow. Existing
Student Affairs requests cover transfers, complaints and fee-discount appeals;
they are not relabelled as grade appeals. Faculty/department hierarchy is not
present in the current flat division table, so no duplicate faculty editor or
unverified division names are introduced.

## Presentation changes

- One sidebar entry per workflow, retaining each existing role and menu check.
- Academic Years and Semesters are separate views of the existing calendar,
  reached through the same authorized route with a section parameter.
- Create Year and Create Semester use compact Bootstrap dialogs; the same forms,
  request fields, CSRF tokens, validation and POST routes are retained.
- Compact dialogs explicitly opt out of the shared full-screen editor adapter.
- Current-period choices are filtered on initial display and year changes.
- Calendar selectors show dates to distinguish existing same-name years; a
  warning flags duplicate labels without deleting or merging historical data.
- Programme years/stages remain curriculum-specific; short stage creation is
  collapsed until needed. Foundation stages remain supported.
- Curriculum and Student Group naming is applied throughout related admin views.
- Academic-year queries for curriculum selectors include the displayed dates.
- Small-screen admin header and registration table containment are corrected.

No database migration, deletion, academic lifecycle rule, calculation,
registration rule, payment operation, approval permission or stored identifier
is changed. Long curriculum and group forms remain dedicated pages so dependent
selections and validation remain usable.

## Verification

Pending final checks. Evidence is written to output/academic-ui-verified-tests.txt
and output/academic-navigation-browser/.

Local verification completed: 147 targeted tests passed (1,187 assertions),
followed by 93 tests (927 assertions) after final label/query refinements.
Chrome checks: 24 page/viewport combinations passed at 320, 375, 768 and 1366px;
no JavaScript errors. Compact dialogs received focus, exposed clickable fields,
closed with Escape and retained the existing forms. Viewport checks allow
intentional horizontal scrolling within tables while rejecting clipped controls.
