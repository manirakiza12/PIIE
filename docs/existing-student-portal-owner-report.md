# Existing student portal access — owner report

Date: 15 October 2026

## Management summary

Existing-student portal onboarding has been implemented in the local application. Staff can add students individually or import a CSV while retaining each student's existing registration number. Both existing students and students converted from successful applications use the same student role, portal activation email and mandatory first-login password change.

This is portal onboarding only. It does not create or modify enrolments, class assignments, course registrations, admissions, application payments or fee invoices. Programme, intake and year of study can be recorded on the student profile without enrolling the student.

## How the two groups match

| Item | Existing students | New applicants |
|---|---|---|
| Registration number | Staff supply the existing number; its spelling, slashes and leading zeroes are retained after trimming surrounding spaces | Existing admissions numbering rules continue to apply |
| Student account | Created directly by authorised school staff | Created through the admissions conversion workflow |
| Portal activation | Shared activation email and temporary password | Same activation email and temporary password |
| First login | Must set a new password | Must set a new password |
| Enrolment and fees | Deferred; onboarding creates neither | Existing admissions rules remain in effect |

Existing numbers should not be reformatted to resemble newly generated numbers: preserving the institution's historical identifiers is the agreed requirement. The portal experience is shared even when registration number formats differ.

## Staff procedure

1. Open Students and choose **Add Existing Student** or **Import Existing Students**.
2. Enter the student's name, unique login email and existing registration number. For CSV import, download the updated template; the required columns are `name`, `email` and `registration_number`.
3. Preview the CSV and check names, emails, registration numbers and validation messages before confirming. The confirmation rechecks current records.
4. The system creates the portal account and profile and attempts the activation email. It requires a password change before portal use.
5. If the student already has an account, use **Send Portal Activation** from their existing student record instead of adding another account. This action reissues credentials and preserves their number and enrolments.
6. If an email is reported as unsent, check the mail configuration and resend activation. Passwords are not displayed in the import report.

Duplicate login emails and registration numbers already held within the school are rejected. Registration-number duplicate checks ignore letter case. School ownership checks remain in place. Account and profile creation are transactional, so a profile-write failure rolls back that student's account.

## Scope and availability

Portal access does not grant class or course membership. Class-specific learning materials, exams and attendance continue to depend on the relevant academic assignments, which will be handled separately when enrolment work is authorised.

The Students page now links to the existing-student onboarding forms. Historical offline-admission screens and the new-applicant admissions workflow are separate workflows; this change does not convert them into existing-student onboarding.

## Verification and release status

Automated checks cover manual creation, CSV import and confirmation, retained historical registration numbers, duplicate detection and rechecking, unchanged enrolments and invoices, profile-write rollback, activation credentials, first-login setup, school boundaries, applicant conversion, generated registration numbers and the student dashboard regression suite. Tests use isolated fixtures rather than live student records.

Result: **69 tests passed, with 364 assertions**, across StudentBulkImportTest, StudentPortalActivationTest, AcademicFinanceTenantSecurityTest, AdmissionConversionTest, RegistrationNumberTest and StudentDashboardTest.

Implementation is local and has not been deployed. No live student accounts have been imported or activated and no production data or enrolments have been changed. No database migration is required by this change. Email delivery depends on working mail configuration. Browser layout and a real inbox delivery check remain release acceptance checks.

Suggested message to the owner:

> We have implemented existing-student portal onboarding while preserving their current registration numbers. Staff can add students individually or by CSV. Existing students receive the same portal activation and first-login password setup as admitted applicants. No enrolment or fee records are created or changed during this step. The changes have been checked locally; deployment and live activation are still pending.
