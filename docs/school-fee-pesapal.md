# School fees through PesaPal

School fee invoices now use the same PesaPal API 3.0 transport, encrypted school credentials, environment selection and registered IPN as application fees. The ledgers remain separate: application payments belong to admissions; school fee attempts belong to an invoice in `tuition_payments`.

## Access

- Student / parent: Student Fees → select an invoice → Pay with PesaPal. Choose a payment toward the 20% study minimum, the full invoice balance, or a custom amount no greater than that invoice's outstanding balance. Returning from checkout does not itself prove payment.
- Administrator: FINANCE → Fee Payments shows PesaPal payment attempts, PRNs, amounts, status, tracking IDs and provider confirmation codes. View / verify opens an invoice's history and queries PesaPal directly. Student Fees also has a Payment tracking action.
- Existing `finance.view` permission grants tracking and provider verification. Invoice creation/editing still requires `finance.invoices`. Verification cannot manually approve a payment.

## 20% study eligibility and waivers

Students must pay at least 20% of the sum of their current fee-session invoices, or have an active current-session study fee waiver. Payments from older sessions and other students do not count; overpayments are capped at each invoice's face value for eligibility. The threshold uses exact minor units rounded up. For example, fees of 500,000 require 100,000 paid; if 30,000 is already paid, the minimum option collects another 70,000. When the chosen invoice has less outstanding than the shortfall, it collects that invoice's remaining balance and the student must continue with the other invoices.

PesaPal credits only the verified installment. Reaching 20% grants financial eligibility but does not mark the whole invoice paid. The remaining balance is still due. Fee reversals and waiver revocation are rechecked on subsequent learning access. Course registration and confirmation continue to enforce academic placement and all existing academic requirements.

Administrators can use Student Fees → Payment tracking (or Fee Payments → View / verify) → Waive 20% study requirement. A reason of 10–500 characters is required. The waiver applies to one student, new or continuing, for the current fee session. It records the grantor, reason, time and any revocation. It does not post money, cancel debt or waive application fees. The existing `academic.course_registration.fee_waiver` permission remains restricted to administrators; it does not become a general finance-view capability. The same waiver is visible in Course Allocation student eligibility screens.

Learning access includes course pages/content/files/assignments, live class details/join/materials, and exam entry/resume. Billing, account management and saving/submitting an already-started exam remain accessible. The student fee and My Courses pages show eligibility and the remaining amount required. No current-session invoice means eligibility cannot be established; Finance must issue the invoice before collecting the minimum or granting a waiver.

The shared PesaPal IPN dispatches `TUITION-` references to school fee verification. It fetches status from PesaPal and checks the stored reference, transaction ID, amount, currency and original configuration before posting credit. Duplicate callbacks are idempotent. Verified reversals remove the corresponding credit once. Only a confirmed failed attempt can be replaced; unknown initiation outcomes remain reserved for recovery.

## Offline retirement

Student and parent proof submissions and administrator/accountant/bursar offline approval URLs have been removed. New MarzPay tuition starts are removed; status verification for historical MarzPay orders remains available. New invoices start unpaid. Staff cannot enter a paid balance or manually mark an invoice paid. Invoices with payment activity cannot be edited or deleted.

Historical offline records are preserved. An invoice already awaiting offline review is blocked from a new checkout to avoid duplicate collection. Finance must investigate those historical claims before a separately reviewed data reconciliation; this release does not silently mark them paid, erase claims or provide an offline approval bypass. Hostel payments, payroll and platform subscription billing are separate workflows.

## Rollout

1. Deploy the code and run `php artisan migrate --force` to create the payment-attempt ledger and ensure the existing student fee registration waiver migration has run before enabling these routes.
2. Confirm the existing PesaPal configuration, registered IPN and school's ISO currency (for example `UGX`). The existing `/payments/pesapal/ipn` registration handles both applications and school fees; no second IPN registration is needed.
3. Refresh application route/view caches through the normal deployment process.
4. Exercise one school fee checkout in the configured sandbox environment, check its callback/IPN and verify Finance → Fee Payments and the student's balance. Production money movement must be verified separately in the live environment.

Credential replacement is blocked while either ledger has pending orders. Previously used configurations are retained so historical attempts can still be verified with their original credentials/environment.

If submission times out, do not create another order. Finance can recover the order through the invoice's View / verify screen by entering the PesaPal order tracking ID; the server still verifies reference, amount and currency with PesaPal. A provider confirmation code is optional corroborating input, never evidence by itself.
