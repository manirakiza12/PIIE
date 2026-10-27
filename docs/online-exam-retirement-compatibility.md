# Online exam compatibility during academic-group retirement

PR #112 retires class targeting for new tertiary exams. It does not delete or automatically convert existing papers, submissions, answers or marks.

## Supported behavior

- Valid school-wide, programme and Course Allocation papers remain in authorized lecturer lists and marking queues. Missing `course_offering_id` alone never hides an exam.
- Staff retain the original tenant, ownership, permission and historical teaching-assignment checks when reviewing or marking existing papers. Retired class permissions are used for historical authority, not for new tertiary subject selection.
- A tertiary student with an existing legacy class-targeted submission can find its history and resume its in-progress attempt even after old class enrolment links disappear. The exception requires both submission ownership and matching exam/submission tenant, and applies only to retired class-targeted legacy papers.
- Existing deadlines, timeout submission, cancellation/publication checks, answer validation and result publication policy still apply. Retirement does not extend time or reveal withheld marks.
- No new tertiary class-targeted attempt is admitted, including an extra attempt by a previous candidate. New candidates cannot discover the retired paper merely through old class enrolment.
- An existing tertiary class-targeted exam cannot be retargeted through the generic update endpoint. In particular, omitting `class_id` cannot silently convert it into a school-wide paper. Use a new Course Allocation exam for new delivery.
- Tertiary shared question-bank subjects derive from lecturer Course Allocations. Secondary legacy subjects retain their class-based rules.

## Test separation

`OnlineExamTestHelper` retains its tertiary default. Legacy secondary suites explicitly call `bootSecondaryOnlineExamTestSchema()`; mixed suites change both institution fields only within their K12 cases. Tertiary acceptance, end-to-end, answer-persistence, editor and integrity suites use `CourseOfferingExamFixture`, lecturer allocations and confirmed student registrations. Authoring helpers assert successful responses and persisted question types before reading IDs.

`OnlineExamRetirementCompatibilityTest` covers owned history, active and expired attempts, denial of extra attempts, cross-student/tenant isolation, withheld and published results, historical markers, targeting immutability, creation rejection, form separation and allocation-based bank visibility. The existing school-wide marking tests retain their substantive assertions.

## Live inventory and remaining decisions

No live database connection was available during this fix. Old source comments and previous deployment logs are not a current production inventory. Production counts and affected live exam IDs remain unverified.

An operator with existing read access can run `deploy/remote/exam-retirement-inventory.php` with the live release path as its first argument, using the host/container PHP that can reach that release's database. It loads configuration without booting Laravel providers, starts a read-only transaction and rolls it back. Output contains exam IDs, institution type, targeting, workflow/windows and aggregate attempt counts; it does not return student identities, answers or credentials. Its stored-deadline counts are not an eligibility decision: the application's effective deadline can be earlier. The script has not been uploaded to or executed against production by this change.

The following require an explicit academic policy decision and are not enabled automatically:

1. Admitting candidates who had not started an old class-targeted paper, or granting further attempts on it. The current safe behavior refuses new attempts; create a new allocation paper for future delivery.
2. Reopening cancelled/unpublished papers or extending expired attempts. Existing engine restrictions remain in force.
3. Migrating or relinking historical papers, classes, registrations or submissions. This change performs no data migration.
4. Continuing an allocation-based attempt after its confirmed Course Registration is withdrawn. The existing registration gate is retained; the legacy-history exception does not bypass it. Owned result endpoints retain their existing publication and ownership checks.

None of these decisions blocks reviewing historical records or completing a still-valid owned legacy attempt under its existing deadline.
