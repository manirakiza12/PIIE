# Admissions accountability emails

Student and office notifications now use separate deliveries. Configure the institution's Admissions email address in Settings > Notifications. The setting is scoped by school ID; there is no fallback to another institution, public contact address or SMTP account. A blank setting disables office copies. The owner has been asked to supply the official address; none has been assumed or saved.

Applicant notices (welcome, submission, review decisions, corrections, document review and application-fee messages) produce an office copy. Enrolment notices retain their admission-letter attachment and delivery-time school/status/recipient checks. Course registration creation, confirmation and withdrawal now notify the student and the configured office; repeated no-op registration/confirmation does not send another notice. Activation produces a separate office status notice without the student's password. Password resets remain student-only; office copies omit personal payment, access and portal action URLs.

With the existing applicant_notification_deliveries table installed, office emails have separate encrypted retry records. Rollback discards both notifications, and retrying a failed office email does not resend the student email. An accepted mail transport does not prove inbox receipt. Without that table, the existing immediate mail path is used.

Validation: related applicant, activation, admission-letter, notification settings, durable delivery, course registration batch and eligibility suites passed (76 tests), plus the registration-transition notification test and two further office retry/link-privacy tests. All mail tests use fakes or mocked transport; no real emails were sent. No migrations are required by this change. Changes are local, not deployed.

## Production release verification — 2026-10-16

PR #29 merged as `28ff2172839cc3619b2cf22e3c8c19b229bebb30`. Exact-release CI `38870964587` passed: 4,322 tests, 36,986 assertions, 26 skipped, zero failures/errors. Protected staging `38872476905` and activation `38872912569` succeeded. The new code is now deployed; the earlier local-only status above describes pre-release validation.

Production SMTP connected and authenticated without sending a message. Cron is active and runs the existing notification retry schedule every five minutes. Authenticated Notification Settings and Course Registrations returned HTTP 200 with the expected new content. A transaction-wrapped MailFake check created and exercised two separate encrypted deliveries, verified student and office recipients and omission of personal links from the office copy, then rolled back all probe writes. All 30 protected table counts and record digests match the pre-release baseline.

Configuration remains incomplete pending the owner's recipient choice. The live public contact setting is `info@piie.ac.ug`; an older setup script records `primeinternationalinstitute@gmail.com`. Neither was assumed to be the admissions recipient. The `admissions_notification_email_1` setting remains absent and office copies remain disabled. No real test messages were sent and inbox delivery is unverified. The live database currently has zero Course Offerings and zero Course Registrations; no academic or student records were fabricated.

Evidence: `output/admissions-notification-production-before.json`, `output/admissions-notification-production-after.json`, `output/admissions-accountability-live-smoke.json`, `output/admissions-smtp-connectivity.json`. The prepared configuration script requires the confirmed address and updates only the institution-specific notification setting.

## Recipient configured

The owner subsequently confirmed `primeinternationalinstitute@gmail.com`. It was saved to the production institution's `admissions_notification_email_1` setting on 2026-10-16 at 08:34:35 UTC. Live authenticated checks confirm the configured address is displayed in the editable Notification Settings input; both simulated notification deliveries still pass. Admissions copies are now enabled for new applicable notices. No real test messages were sent. Administrators can change this address under Settings > Notifications > Admissions email address and save; no code change or deployment is required. Evidence: `output/admissions-copy-configuration.json` and updated `output/admissions-accountability-live-smoke.json`.
