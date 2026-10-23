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
