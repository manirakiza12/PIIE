# Student PesaPal checkout investigation

## Entry points and provider handoff

- Student Fee Manager `Pay Now` is an ordinary link to `student.FeePayment` (`GET student/fee_manager/payment/{id}`). It does not submit an order.
- The invoice payment page is rendered by `TuitionPesaPalController::index` using `shared/tuition_payment.blade.php`. Its button submits a normal CSRF-protected POST to `student.payment.pesapal.start`. There is no AJAX handler, popup, or new-window target.
- `TuitionPesaPalController::start` validates the phone and amount choice, calls `TuitionPesaPalPayment::start`, and responds with a 302 to the provider's validated `redirect_url`. Errors return to the invoice with a session error. Unknown submission outcomes retain the pending attempt and prevent another order.
- The parent entry point uses the same controller and service, with parent ownership checks. Applicant portal and signed administrator-created application invitations use `ApplicantPesaPalPayment` and the same `PesaPalConfiguration` / `PesaPalService` transport. The applicant invitation already normalizes formatted phone numbers.
- `payment_option` chooses minimum, balance, or custom **amount**, not a network or card. The phone is only `billing_address.phone_number`. No payment-method restriction is included in the order request.

PesaPal owns the method selection page. Its [SubmitOrderRequest documentation](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/submitorderrequest) says to redirect the customer to the returned URL to display the available methods. MTN Mobile Money, Airtel Money, and Visa must be enabled/supported by the configured merchant checkout; this change does not invent options or promise that every method is available in every environment.

## Saved configuration

The existing administrator settings route is protected by `auth`, `admin`, `rbac`, and `school_admin`. It writes the school's active `payment_methods` PesaPal row. `PaymentMethods::saving` encrypts credentials with `PesaPalCredentialStorage`, including tenant binding; model serialization hides payment keys. The page receives configuration metadata and booleans, never credentials.

All new orders load the active row through `PesaPalConfiguration::forSchool`. `environment=sandbox` selects `https://cybqa.pesapal.com/pesapalv3`; `live` selects `https://pay.pesapal.com/v3`. Token-cache keys include school, configuration ID, environment, and a credential fingerprint. Saving changed credentials therefore cannot reuse the previous token. New credentials need their own IPN registration. No separate student settings or environment override was found.

Outstanding orders block credential replacement. After resolved orders, replacement archives the old row so verification uses the payment's original `configuration_id` and environment. This protects historical payments from being verified against a different merchant.

The settings screen previously decoded raw JSON for its IPN indicator, ignoring supported `encrypted_notification_id` storage. It could incorrectly show "IPN not registered" although the shared service correctly loaded the registration. The screen now reads through the existing storage abstraction, without exposing credentials.

## Reproduced defects and fix

Reproduction used PHP 8.3, SQLite `:memory:`, synthetic credentials, Laravel `Http::fake`, and `Http::preventStrayRequests`; no real merchant settings or charges were used.

1. A fresh form submission receives a valid mocked checkout URL and returns a same-window 302. The original fresh-order handoff works. The screenshots alone do not establish a failure after clicking.
2. On an existing submitted order, a mocked 503 from `GetTransactionStatus` previously sent the student back to the invoice with "PesaPal is temporarily unavailable", even with a valid stored checkout URL. The resume button never reached checkout. The fix revalidates that URL against the original configuration and permits resuming the same pending order when status verification is unavailable. It neither submits a replacement nor credits a payment. Verified terminal status still prevents resumption.
3. Invoice ID `123456` produced a 51-character reference (`TUITION-{id}-{uuid}`). The adapter rejects references longer than 50 **before requesting a token or order**, producing "Payment was not submitted". New references are `TUITION-{uuid}` (44 characters), preserving the shared IPN dispatch prefix and uniqueness. Existing references remain valid for historical verification.
4. Formatted student phone numbers were forwarded unchanged, unlike the applicant invitation. The shared tuition initiation now removes formatting characters while retaining the leading plus. This does not select a mobile network.
5. Initiation previously changed invoice status to `processing`. Now the pending `TuitionPayment` reserves the order while invoice status, paid amount, and payment method remain unchanged until verified settlement. Existing invoice edit/delete guards also check payment activity.

Browser automation inventory returned no available browsers, so an interactive click/console inspection could not be completed in this environment. The reproduction above exercises rendered pages, real application handlers, mocked HTTP requests, session errors, and redirect responses. It does not claim to reproduce the unidentified screenshots' exact post-click result or visually verify the merchant's hosted method list. Provider-enabled methods still need a sandbox merchant UI check before release.

## Regression coverage

`TuitionPesaPalJourneyTest` covers the Fee Manager link and invoice form, formatted phone/custom amount handoff, fresh and resumed redirects, status outages, oversized invoice IDs, invalid fresh and persisted checkout URLs, saved sandbox/live credentials and token-cache invalidation across student/parent/applicant starts, encrypted IPN display, and mocked MTN/Airtel/Visa confirmations. Existing tests cover ambiguous timeouts, duplicate prevention, ownership, mismatched provider amount/reference/currency, IPN idempotency, reversals, and configuration access/security.

Provider-selected method tests use authenticated mocked status observations; they verify settlement and channel recording, not availability of methods on a real merchant account. A browser-supplied `status=paid` hint cannot credit the invoice.

Run with the project's installed PHP 8.3 dependencies:

```sh
php vendor/bin/phpunit --filter 'PesaPal|StudentFeeManagerViewTest|StudentFeeVisibilityTest|SchoolFeeOfflinePaymentsDisabledTest|StudentFeeAwardsTest|StudentOpeningPaymentsTest|ExistingStudentFinancialSetupTest'
```

PHP syntax lint and `git diff --check` cover the changed files. There is no configured PHP static type checker or TypeScript project in this repository. The change introduces no JavaScript bundle.

Deployment is outside this PR. No production credentials were modified, no live payment requests were sent, and nothing was merged or deployed.

## Follow-up: hosted-checkout acceptance for PR #93

At the start of this follow-up, GitHub reported PR #93 open, ready for review (no longer draft), unmerged, with head `353fc95fb4b23fd7d0c3a1efb26b3582a35b13ec`. Node/browser-logic, Composer audit, and deployment-script sandbox checks passed; PHP/MySQL CI was still running. The browser inventory was checked again and returned an empty list. There is no interactive browser available to follow the redirect or inspect console errors. Neither the uploaded screenshots described in the request nor the mocked redirect establishes what appears on the merchant-hosted page.

### Verified request boundary

The strengthened mock test follows the Fee Manager link, renders the invoice form, posts a custom amount/phone, receives the mocked SubmitOrderRequest response, and checks the exact 302 `Location`. It does **not** load that URL in a browser or contact the merchant's real sandbox account.

| Item | Verified in the application / isolated fixture | Merchant sandbox observation |
| --- | --- | --- |
| Environment | Saved `sandbox`; shared API base `https://cybqa.pesapal.com/pesapalv3` | Actual saved merchant account not inspected |
| Currency | Read from `schools.school_currency`, trimmed and uppercased; fixture sends `UGX`. A separate synthetic `usd` fixture sends `USD` | Merchant-supported currency combinations not verified |
| Country | Student request omits `billing_address.country_code`; API documents it as optional | Checkout's default/selected country and any effect on visible methods not observed |
| Billing | Exactly email, normalized phone (or blank if not supplied), and student's name in `first_name` | Hosted billing display not observed |
| Amount | Custom `500.00` or outstanding balance; `payment_option` is not sent to PesaPal | No real sandbox order created in this follow-up |
| Other order fields | Unique reference, description, HTTPS tuition callback, saved configuration's notification ID | Actual registration/account validity not tested |
| Redirect | Mocked provider HTTPS URL, validated sandbox host, exact same-window 302 handoff | Browser arrival and hosted HTTP response not verified |
| Method selector | No field requests MTN, Airtel, Visa, or any specific method | **MTN Mobile Money: unverified. Airtel Money: unverified. Visa: unverified.** |

The country omission is not established as a defect: PesaPal API 3 documents that field as optional. Do not hard-code `UG`, infer country from `UGX`, or claim that adding country enables merchant methods. The server sends the name as one `first_name` string; this is existing billing behavior, not evidence of a method restriction. The USD fixture tests currency propagation only, not whether this merchant accepts USD or offers mobile money for it.

No new runtime defect was established in this follow-up, so it changes only regression tests and this investigation. The actual saved merchant credentials, environment, enabled methods, and account configuration were not queried from a production database or copied into a test fixture. The existing saved-configuration tests use synthetic settings through the real administrator save handler; that is distinct from inspecting this merchant's sandbox account.

### Exact manual sandbox procedure before merge

1. Use a dedicated non-production installation of the PR #93 head with an isolated database, storage, mail, sessions, and cache. Use synthetic students and two unpaid invoices. Keep outgoing provider traffic restricted to PesaPal's sandbox host. Do not connect the test installation to the production database or copy production credentials.
2. As an authorized school administrator, open `/admin/applicant-pesapal-settings` on that installation. Confirm the selected environment is **Sandbox (test payments)**, credentials show configured, and the sandbox IPN shows registered. Do not reveal password fields or include this screen in screenshots. If sandbox configuration is absent, an authorized administrator must enter the sandbox account credentials privately through this existing form and register its public HTTPS IPN. Do not change live settings. Resolve outstanding test orders before replacing settings, as the application requires.
3. Confirm the test school's configured currency is the sandbox merchant's intended currency (for the Uganda scenario, verify `UGX` with the merchant administrator). Do not assume currency selects country or enables methods. Use only synthetic billing name/email and a provider-approved sandbox phone fixture; avoid a real subscriber's number. Record only whether each billing field is present and correctly formatted, not its value.
4. Sign in as the synthetic student. Open `/student/fee_manager`. Record both test invoices' unpaid status and balances. In browser developer tools, open Network with Preserve log enabled, and Console. Do not export an unredacted HAR, cookies, or request headers.
5. Click the first invoice's **Pay Now**. Confirm a successful GET to `/student/fee_manager/payment/{id}` and that it opens the invoice form without a provider order request. Select an amount choice; for custom, enter a valid amount within the outstanding balance. Enter the sandbox phone fixture if needed. Click **Pay with PesaPal / resume checkout** once.
6. Inspect the POST `/student/fee_manager/payment/{id}/pesapal/start`. Expect a **302** whose `Location` exactly matches the provider response's `redirect_url`, uses HTTPS, and has host **`cybqa.pesapal.com`**. Stop immediately if the target is the live host `pay.pesapal.com`. The browser should navigate in the same tab. Provider API token and SubmitOrderRequest calls are server-side and will not appear as browser API requests. If server-side diagnostics are needed, capture only allowlisted response metadata; never enable full HTTP-body/header logging.
7. On the hosted sandbox checkout, record the final host, document HTTP status, visible country/currency if displayed, and **the exact visible method names**. Inspect all method tabs/accordions if present. Explicitly mark each of MTN Mobile Money, Airtel Money, and Visa as visible, absent, or not assessable. Capture a cropped method-list screenshot with name, phone, email, order references, and payment details masked. Do not press a final payment/authorization control; viewing the method list is sufficient for this investigation.
8. Return to the invoice without authorizing payment and click **resume checkout**. Confirm the same sandbox order tracking ID/URL is reused and no second SubmitOrderRequest occurs. Repeat from the second invoice's direct `/student/fee_manager/payment/{id}` page to cover the other starting point. Confirm both invoice balances and statuses remain unchanged without verified completion.
9. If the redirect fails, capture the POST result and sanitized session error, and correlate it with the server's generic error at the same UTC timestamp. For a successful redirect followed by a failed hosted page, capture the hosted document status, visible error text, and browser console error **after redacting query values, personal details, cookies, tokens, and credentials**. Do not attribute a provider page failure to the local amount selector without evidence.
10. If a method is absent, first check the sandbox checkout's country/currency controls, then ask the merchant administrator/PesaPal support to confirm enabled methods for that **sandbox** account and currency. Do not add an application method selector to compensate. Record the confirmation separately from what was visibly observed. Keep the PR unmerged until acceptance is reviewed. Any later sandbox settlement test must use PesaPal-approved test instruments and the existing authenticated callback/IPN reconciliation; browser return parameters alone must never credit an invoice.

Non-sensitive diagnostic record (keep order tracking IDs in the restricted test record, not a public screenshot):

```text
PR head SHA / UTC time / sandbox installation hostname:
Entry point: Fee Manager Pay Now | direct invoice page | resume
Configuration: sandbox; credentials configured yes/no; IPN registered yes/no
Currency: ____; country_code sent: omitted; hosted country if visible: ____
Billing fields present/formatted: email yes/no; phone yes/no; name yes/no
Token API HTTP status / SubmitOrderRequest HTTP status and API status: ____
Student POST HTTP status: ____; redirect scheme/host/path: ____
Exact provider URL equals Location: yes/no (compare privately)
Sandbox order tracking ID / test PRN (restricted test record): ____
Hosted document HTTP status / final host: ____
Visible method names: ____
MTN: visible/absent/unverified; Airtel: visible/absent/unverified; Visa: visible/absent/unverified
Sanitized hosted/browser/server error and correlation time, if any: ____
Resume reused same order: yes/no; invoice balance/status unchanged: yes/no
```

Do not attach token responses, Authorization/CSRF headers, consumer key/secret, credential ciphertext, session cookies, full request bodies, or unredacted billing information. A sandbox order tracking ID is diagnostic metadata, not proof of payment.
