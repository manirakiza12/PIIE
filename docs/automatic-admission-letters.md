# Automatic PIIE admission letters

Implemented locally on 13 October 2026.

## Applicant journey

1. A public programme's Apply action carries its programme ID into the application journey. The applicant creates or signs into their applicant account and completes that programme's application.
2. Submission records the application and sends the existing application confirmation. A required application fee must be settled through verified online payment or the existing authorized offline/waiver workflow.
3. An administrator reviews and accepts the application, then enrols the student. Existing completeness, payment, school ownership and account-conflict checks remain in force.
4. Successful enrolment provisions the student and academic records. Within the same transaction, one personalised letter is archived with an encrypted snapshot of its student, programme, fee, date and branding details.
5. After commit, the enrolment confirmation email receives the letter as a PDF attachment. New student credentials continue to arrive through the existing separate activation email. Repeated enrolment requests, including note updates, do not issue another letter or confirmation.
6. Applicant and administrator offer-letter downloads use the same archived snapshot after enrolment. The student's sidebar also offers an Admission Letter download scoped to their own school and student identity.

## Document

The two-page A4 letter uses the verified local PIIE crest as its letterhead and repeated faint watermark. It includes the Academic Registrar heading, name, nationality, real registration number, application reference, issue date, programme, mode, duration, intake and configured academic dates. The official contact details requested for PIIE are `info@piie.ac.ug` and `https://piie.ac.ug/`; physical address and telephone details come from institutional configuration.

Applicable fee structures are scoped to the school, programme, academic session and class. A configured programme tuition amount is used only when its payment basis is stated and no tuition structure exists. Other institutions' fees, addresses, academic dates and refund/NCHE policies are not adopted from the supplied format. Missing dates and fees are referred to the Registrar/Finance Office rather than invented.

Set semester commencement, orientation start/end and registration deadline in **Intake Sessions**. These are separate from the application opening and closing dates. Issued letters retain their original snapshot when fees, dates or programme names are edited later.

## Delivery and privacy

Attachments are generated from the archived snapshot without public document URLs or external image requests. Mail transport configuration is preserved, with the letter's support address used as Reply-To. Deferred admission-letter notifications re-check the admission's school, enrolled status and recipient before delivery. A changed recipient is not sent the old letter. Notification data and letter snapshots are encrypted at rest.

SMTP failure leaves the enrolment intact and the durable notification pending. The existing `applications:retry-notifications` schedule and authorized retry action handle failures. The host must run Laravel's scheduler for deferred automatic retries. Local SMTP settings are configured; tests used fake mail transport and did not send test messages to real students.

## Verification

- 98 broader admission, applicant, notification and activation tests passed with 432 assertions.
- Final letter suite: 10 tests, 53 assertions covering attachment generation, branding, registration numbers, immutable snapshots, duplicate requests, transaction rollback, student-only access, failed-delivery retry, recipient changes, fee scoping, unpaid enrolment refusal and intake-date validation.
- Both pages of the actual generated PDF were visually reviewed in Chrome's PDF viewer. Header, watermark, fee table, footer and pagination render correctly.
- The two targeted additive migrations were applied to the verified local database. Runtime verification confirms archive storage, academic-date columns, the PIIE logo, configured SMTP and the student download route.

The preview at `output/pdf/piie-admission-letter-sample.pdf` is marked SAMPLE and uses synthetic student details, dates and fees. It is a design preview, not an issued letter.

## Release files

Apply only these migrations when deploying this feature to another installation:

- `2026_10_13_120000_create_admission_letters_table.php`
- `2026_10_13_121000_add_admission_letter_dates_to_intake_sessions.php`

No existing applications were enrolled or retrospectively emailed during implementation. Production deployment has not been performed.
