# Invoice billing and email

Student fee invoices now display the student's name, registration number, billing address, email and phone in one shared billing section. Admin, accountant/bursar, student and parent invoices use it; the student hostel invoice also uses the same billing lookup. Issue dates use invoice creation dates instead of the day the invoice is viewed.

Contact details come from the canonical student account, including older double-encoded profile JSON. Missing address/phone can fall back to the latest enrolled application with the same student email in the same school. Missing details remain labelled as missing and should be completed in the student profile. The lookup never borrows another school's details or substitutes next-of-kin contacts.

Finance users have three choices:

- **Email invoice / Resend invoice email** on an existing invoice.
- **Email invoice to the student automatically when saved** on single/mass creation forms.
- **Automatically email all new invoices** in Student Fee Manager. This school preference starts off, applies only to future invoices and includes enrolment-generated and synced invoices. Save the preference to enable it; when enabled, creation forms explain that school automation applies.

Messages include billing details, invoice amounts/status, balance and a link to the existing authenticated student invoice page for viewing/printing/payment options. They are HTML emails, with no PDF attachment. Delivery status appears next to the send action. Recipients come from the student's account; requests cannot choose an arbitrary recipient. Admin send/settings routes require `finance.invoices`; accountant/bursar routes retain their existing portal guards. All invoice lookups are school-scoped, with CSRF and send throttling.

Automatic requests are recorded in the invoice transaction and delivered after commit. Rollback sends nothing. A unique delivery per invoice prevents duplicate initial notifications. Transport errors retain the invoice and are tracked for retry. The scheduled `invoices:retry-emails` command runs every five minutes, waits ten minutes between attempts and stops after five attempts. Manual resend starts a new bounded attempt series. The production server must run `php artisan schedule:run` every minute. SMTP must be configured and a real inbox acceptance check remains necessary before claiming production delivery.

Migrate `2026_10_14_110000_create_invoice_email_tables.php` before enabling emailing. Applied only to the verified local database. `php scripts/verify-invoice-email-installation.php` checks local readiness without sending messages. No existing invoices are bulk-emailed by the migration or preference change.

Validation: 55 related feature tests passed (256 assertions), including 11 new billing/email tests, fee generation/sync, finance tenant security, invoice lists and admission-letter delivery. Mail tests use fakes or simulated transport failure; no real recipient was contacted.
