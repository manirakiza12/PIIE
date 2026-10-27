# Academic group retirement

Teaching Groups are retired from tertiary workflows. Programme Student Groups are retired as an operational workspace. Existing database records remain intact for historical integrity; no production data is deleted.

## Active workflow

Students receive direct Programme Curriculum, Programme Year and Academic Year placement. Course Allocation eligibility and rosters use that placement, academic period, curriculum applicability and financial eligibility. Retired cohort membership, status and names no longer control registration or discovery. Group-based bulk registration is rejected; staff select individual eligible students.

Lecturers work through My Course Allocations for students, attendance, schedules, coursework and assessments. Tertiary navigation omits legacy class-based academic pages. Bookmarked legacy teaching-group screens redirect to Course Allocations, and their mutation/AJAX endpoints return HTTP 410 before executing the controller. Programme cohort screens redirect to direct placement, and cohort mutations return HTTP 410.

Tertiary exam creation uses Course Allocations for lecturers. Class selectors are absent from exam forms, lists and monitoring, and class-targeting submissions are rejected. School classes remain available for primary/secondary institutions.

The student directory does not show historical class/section enrollment for tertiary students. Historical group links do not prevent correction of otherwise unused academic placements; ended placements and course registration history remain protected.

Existing online-exam history and in-progress legacy attempts remain available under their original tenant, permission, deadline and publication checks. New tertiary class-targeted delivery remains blocked. See [exam compatibility and policy decisions](online-exam-retirement-compatibility.md).

## Verification

Regression coverage includes retired endpoint redirects and mutation rejection, placement-based eligibility, selected-student registration, teacher workspace/navigation, and course exam creation. Blade templates must compile successfully. Tests use in-memory SQLite and a temporary test encryption key.

Latest checks: 76 placement/lecturer/exam/retirement tests and 24 bulk-registration tests passed (100 total). The retirement test was re-run after adding protection for the legacy assignment workspace. PHP syntax checks and Blade compilation passed.

The broader run also identified an unrelated parallel-allocation checkbox assertion in CourseOfferingRegistrationBatchTest. StudentLearningPagesTest has separate fixture/schema and CSV Content-Type assertion failures. These are not reported as passing. Logs are under output/group-removal-verified.log, output/group-free-bulk-registration.log, output/academic-groups-final-tests.log and output/academic-groups-retirement-tests.log.
