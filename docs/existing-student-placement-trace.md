# Existing-student creation and academic placement

## Finding

`AdminController::studentCreate()` explicitly removes class, section, session and department before calling `Students\StudentProvisioner::provision()`. This is intentional portal-only onboarding, shared with Import Existing Students. It is not a curriculum-placement operation. A programme or intake on a student profile is not evidence that a student has been placed or registered academically.

The production dropdown query excludes **every student with any same-school curriculum assignment whose `ended_at` is null**, even if its Programme Year is missing. It does not filter by the profile programme, intake, invoice, fee session, payment, or year of study. Consequently an invoice's blank Class/Section cannot explain which particular student is excluded. Check the assignment, not the invoice. Previously All Student Placements also omitted every student with no assignment because it began with an inner-joined assignment query.

No authenticated production database/browser session was available during this investigation. The three reported dropdown names and the actual assignment of every production student have not been independently verified. This change provides the read-only inventory needed to establish those facts without modifying students. Do not infer individual assignments from their absence in a screenshot.

## Records at each step

| Step | Created or linked | Not created |
| --- | --- | --- |
| Add Existing Student | One `users` row (student role 7, same school, existing registration number in `code`); one `student_profiles` row keyed by `user_id`, with `was_existing_at_portal_creation=true`; selected existing `programmes` and `intake_sessions` IDs and optional `year_of_study` on the profile | Applicant, admission, `enrollment`, class, section, department, programme group membership, curriculum assignment, course registrations, attendance records, timetable or syllabus records |
| Optional finance setup in that form | One `student_fee_managers` invoice for the selected fee structure/session and agreed fee, with `programme_id` from the profile and `class_id=0`; optional `student_financial_setup_reviews` containing pending prior payment, award and study waiver requests, plus audit entry | Confirmation of the captured payment/award/waiver; academic placement or registration |
| Save Student Placement | One `student_curriculum_assignments` row for the same existing user, chosen approved curriculum/programme, entry Programme Year (`curriculum_stages`), entry and effective Academic Year; profile programme and year synchronized through `StudentCurriculumAssignmentService`; audit entry | Another user/profile, invoice changes, legacy class/section enrollment, optional programme group, or course registration |
| Register / confirm Course Allocation | `course_registrations` for the exact student/offering/curriculum membership, with recorded credits/classification and registration status | Automatic registration on every course of the programme, or automatic promotion to later academic years |

The saved intake (`intake_sessions`) is admissions/profile context. The fee session (`sessions`) determines billing and the running-session fee threshold. Curriculum placement uses `academic_years` and offerings use `academic_periods` within those years. These IDs are not interchangeable. Profile year alone does not select a curriculum stage or authorize progression.

Email is globally checked and registration number is checked case-insensitively within the school during provisioning. The school row is locked during creation. Placement locks and validates the existing student/profile and rejects a second active assignment. None of this change creates or repairs production records automatically.

## Why Class/Section can be blank

`admin/student_fee_manager/list.blade.php` calls `CommonController::get_student_details_by_id()`. That helper reads the student's first legacy `enrollment` row; without it, `class_name` and `section_name` are blank. Add Existing Student intentionally creates no enrollment, and a direct tertiary curriculum placement also creates no enrollment. Thus blanks are expected for a valid programme student too. They establish neither placement nor lack of placement.

The printable invoice templates and shared invoice partials are unchanged. Academic status is shown on the placement pages, not inserted into the printable invoice.

## Module student sources

| Module | Source and scope |
| --- | --- |
| Admin > Students | Same-school `users`, role 7, including students with no enrollment. Applying class/section filters requires a matching legacy enrollment. |
| Student Fee Manager | Same-school invoices by `student_id`, independently of academic placement. Financial review/confirmation is independent of curriculum placement. |
| Placement dropdown | Same-school role-7 users without an active same-school curriculum assignment. This change additionally aligns the dropdown with the placement service: disabled accounts and missing same-school profiles are excluded and explained in the inventory. |
| Course Allocations / Not Registered | `CourseOfferingRoster::review()` selects candidates from current curriculum assignments linked to the offering's study plan, or current optional group memberships. `CourseOfferingEligibility` requires valid assignment, matching programme/curriculum/stage/period and exact entry Academic Year. Missing progression for a later year is denied. Financial eligibility is checked separately. |
| Student Course Units / My Courses | `StudentCourseOfferingDiscovery` uses the same eligibility service and current school Academic Year/period; existing registrations remain visible by student/offering. The programme student's legacy Subjects URL redirects to My Courses. |
| Teaching attendance | `CourseOfferingAttendanceService::rosterFor()` uses **confirmed registrations for the exact offering**, not every student profile, group or class. Student attendance shows only that student's confirmed-registration records in finalized sessions. |
| Legacy daily attendance | `admin/attendance/student.blade.php` selects `enrollment` by school, running session, class and section. This is a different pathway from tertiary offering attendance. |
| Programme Routine | `StudentController::routine()` uses the active curriculum assignment's programme (profile programme fallback), school and running `sessions` ID; it selects programme routines without class/section. It does not infer course registration or filter by curriculum stage/academic period. |
| Legacy Routine / Subjects / Syllabus | Current-session legacy enrollment supplies class/section; subjects/syllabus are school/class/session scoped and syllabus also requires section. |
| Programme Syllabus | Redirects to My Courses for published materials on registered Course Allocations. A profile programme does not enroll a student on materials. |
| Assignments | `Assignments\AssignmentAccess` checks confirmed registration for the exact offering and same school, plus publication/access rules. |
| Live Classes | `LiveClasses\LiveClassAccessService` requires the student's confirmed registration for the exact offering and same school, plus lifecycle/access rules. |
| Offering assessments | `CourseExams\CourseOfferingAssessments` reuses the confirmed-offering registration rule, then assessment publication/lifecycle and attempt rules. |

## Review workflow after the change

1. After Add Existing Student, the destination remains **that student's Documents page**, with its exact user ID/registration number and links to that account's placement/status. The form and save message explicitly state placement is still required.
2. On Direct Placement or All Student Placements, search by name, email, registration number or ID. All same-school students are shown, including accounts without assignments. Filter Add / Import Existing Student using the stored provenance marker. Older unmarked accounts remain discoverable under All students; the marker cannot distinguish hand entry from CSV import or reconstruct old provenance.
3. Review profile programme/year/intake separately from actual curriculum, Programme Year and entry Academic Year. Review optional group linked to the placement, latest legacy enrollment, historical placement count, and confirmed course registration count across all years. These counts do not imply current-year teaching eligibility.
4. Each row explains its dropdown eligibility. Active (including incomplete) assignments are excluded to prevent duplicates. Disabled accounts and missing profiles require review of the existing account. Historical-only placements do not block a new placement. Foreign-school accounts never appear.
5. Save an explicit approved curriculum, Programme Year and Academic Year. The redirect is scoped to that student; the success message names the exact programme, curriculum version, Programme Year and Academic Year. Continue to matching Course Allocations to register and confirm, subject to the existing fee threshold/authorized waiver and other rules.

This is a visibility/navigation fix, not a bulk placement or data migration. It makes no production database writes and does not guess placements from billing or admissions fields.
