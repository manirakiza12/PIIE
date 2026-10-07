# Phase 2.2 — PesaPal API adapter

Baseline: `feature/pesapal-api-adapter`, HEAD/origin/main
`5ddcb8d89ed4472de1f9729f312e5cf73503605a`. Scope is transport/configuration/DTOs
and HTTP-fake tests only. No controllers/routes, checkout, callback/IPN handling,
settlement writes, migrations, MarzPay retirement, UI or deployment changes.

## Official API 3.0 contract checked 2026-10-07

Fixed bases: sandbox `https://cybqa.pesapal.com/pesapalv3`, live
`https://pay.pesapal.com/v3`. All requests send Accept/Content-Type application/json.
All operations except authentication send Authorization: Bearer followed by token.
Responses require HTTP 2xx, valid JSON, provider status 200 and no populated error.
Provider error bodies/messages are never surfaced or retained. Successful DTOs
retain response status; transaction error metadata is normalized to null.

| Operation | Method and path | Request | Response used |
|---|---|---|---|
| RequestToken | POST `/api/Auth/RequestToken` | consumer_key, consumer_secret | token, expiryDate, error, status |
| RegisterIPN | POST `/api/URLSetup/RegisterIPN` | url, ipn_notification_type GET/POST | ipn_id, url, created_date, notification_type, ipn_notification_type_description, ipn_status, ipn_status_description, error, status |
| GetIpnList | GET `/api/URLSetup/GetIpnList` | none | array: ipn_id, url, created_date, error, status; optional method/active fields |
| SubmitOrderRequest | POST `/api/Transactions/SubmitOrderRequest` | id, currency, amount, description, callback_url, notification_id, billing_address | order_tracking_id, merchant_reference, redirect_url, error, status |
| GetTransactionStatus | GET `/api/Transactions/GetTransactionStatus` | query orderTrackingId | merchant_reference, amount, currency, status_code, payment_status_description, payment_method, confirmation_code, error, status |

Sources (official PesaPal only):
- [Authentication](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/authentication)
- [RegisterIPN](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/registeripnurl)
- [GetIpnList](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/getregisteredipn)
- [SubmitOrderRequest](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/submitorderrequest)
- [GetTransactionStatus](https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/gettransactionstatus)

Token lifetime is at most five minutes, expiryDate UTC (including documented
seven-digit fractional examples). IPN registration is required before submitting
orders and returns the notification_id. Registration is only an explicit setup
operation, never automatic during ordinary service use; it returns the new ID
without saving it or overwriting existing configuration. GetIpnList lists the
merchant account's registrations and may legitimately return an empty array.

Merchant references are 1–50 permitted alphanumeric/dash/underscore/dot/colon
characters; description maximum 100 characters. Billing requires phone or email.
Supported optional order fields: cancellation_url, redirect_mode, branch.
Billing fields: email_address, phone_number, country_code, first_name, middle_name,
last_name, line_1, line_2, city, state, postal_code, zip_code.

PesaPal's order_tracking_id is the unique provider order identifier queried by
GetTransactionStatus. The status response need not repeat this ID: the DTO binds
the requested GUID to the authenticated response, checking it if repeated.
GUIDs are validated and returned in lowercase for stable future initiation.
No historical IDs are rewritten. Financial values remain the future caller's
responsibility; the adapter validates transport shape and returns exact decimal
amounts, not an internal settlement authorization.

Documented status codes: 0 INVALID, 1 COMPLETED, 2 FAILED, 3 REVERSED. Code/name
must agree for a known classification. Pending, future codes/names and inconsistent
code/name combinations return UNKNOWN while preserving the observed fields.
No nonfailed-to-paid shortcut exists. Order creation is not payment proof;
callback/IPN parameters require server-side verification in a later phase.

## Configuration and isolation

`PesaPalService::forSchool($schoolId)` reads exactly one active `payment_methods`
row with name `pesapal`, explicitly scoped by school. JSON `payment_keys` contains
consumer_key, consumer_secret, environment (`sandbox` or `live`), optional
notification_id. A missing notification ID does not prevent auth/list/setup;
submitOrder requires an explicit GUID. Environment in this JSON is authoritative;
legacy row mode does not silently override it. No configuration UI is added.
Duplicate active configurations, unsupported environments and a base_url override
fail closed. Normal school configuration cannot choose arbitrary API endpoints.
Credential/configuration access remains server-side; no frontend exposure is added.

Tokens use Laravel cache, encrypted with the existing app key. Cache key includes
school ID, payment configuration ID, environment and an HMAC credential fingerprint,
so rotation invalidates reuse. Expiry is bounded by provider UTC expiry and five
minutes from request start, minus a 30-second safety margin. Cached expiry is
checked on every reuse. Expired/corrupt entries never authenticate a request.
The database cache driver is bypassed entirely: no bearer token is stored in DB.
No permanent bearer database field is introduced. Configuration/token debug views
redact secrets; no raw provider payloads or error messages are retained in DTOs.

## Transport and response behavior

HTTP connect timeout 5s; total timeout 15s. Redirect following is disabled, avoiding
credential forwarding through a provider redirect. Transport/HTTP/JSON/provider
errors become one generic PesaPalException with no previous exception, response
body, credentials or bearer token. There are no automatic retries: particularly
order submission/registration are not repeated after an ambiguous timeout.
An auth rejection fails closed; it does not silently resend a mutation.

Redirect URLs must be HTTPS on the exact environment host, with no userinfo,
fragment, whitespace/control/backslash or nonstandard port. The adapter returns
the URL; it never redirects an applicant. Setup/callback URLs require HTTPS.
Transaction amounts use existing two-decimal ledger parsing and malformed values
are rejected rather than rounded. Unknown statuses cannot authorize settlement.

Immutable value objects: configuration, token, order, transaction status. IPN
registration/listing return small normalized arrays. No settlement imports or
payment/configuration writes exist in the adapter. Existing MarzPay, settlement
boundary and Phase 2.1 constraint files remain unchanged.

## Verification and remaining gates

All adapter tests use Laravel Http::fake and prevent stray requests. Fixtures
are local SQLite only. Tests create fixture rows, but adapter operations leave
payment/configuration snapshots unchanged. No real PesaPal API calls were made.
Focused adapter result is recorded below after final verification.

Remaining gates: review and feature CI, then explicit approval for checkout and
callback/IPN orchestration. Sandbox merchant acceptance and operational setup
remain future authorized work. App-key/cache availability is required; cache or
encryption failures fail closed. Merchant reference/amount/currency/tenant binding
and reversal reconciliation still belong to future orchestration, not this adapter.
No production readiness or settlement concurrency claim is made for this phase.

## Final local results

- Focused adapter: 84 tests, 524 assertions, all pass.
- Payment/admissions/adapter regressions: 335 tests, 1,694 assertions, all pass.
  The initial combined run exhausted the default 128 MB PHP limit. The final
  run used the inventory runner's 768 MB limit; no tests were omitted or skipped.
- Complete deterministic fresh-process inventory: 3,469 discovered/executed,
  3,443 passed, zero failed, 26 existing skips, 31,528 assertions. Eight shards
  exited 0; duplicate/missing/unexpected tests 0/0/0. Existing skips are 21
  LiveClassModuleTest schema cases and five CourseOfferingAcceptancePreparationTest
  live acceptance authorization cases. No failure was converted to a skip.
- Evidence: `storage/logs/phase11-suite-20261007-140904/summary.json` and ignored
  `local-reports/phase22/payment-regression.xml`/`.log`.
- PHP syntax and new-file whitespace checks pass; git diff --check passes.

Phase 2.2 consists of six new app/Support/Payments files (PesaPalService,
PesaPalConfiguration, PesaPalException, PesaPalToken, PesaPalOrder,
PesaPalTransactionStatus), PesaPalServiceTest.php and this document. No existing
tracked files changed; new files therefore do not appear in git diff --stat.
All 27 unrelated pre-existing untracked files remain untouched. Nothing staged,
committed or pushed. No production action, real PesaPal API call, database write
by the adapter or migration occurred. Ready for review/commit and feature CI;
checkout, callback/IPN settlement, MarzPay retirement, tuition and deployment
remain unapproved future work.
