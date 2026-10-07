# Phase 1 payment foundation

This phase retains MarzPay, existing routes, settings, views and historical
records. It does not add PesaPal, tuition installments, an academic-access gate,
automatic semester billing, or a new admission-letter design.

## Application fee and submission

ApplicationProgress::blockers() continues to exclude payment. Draft and
needs_correction applications cannot start a new online transaction, and a
submission timestamp is required. Offline submission/review and status checking
of existing online attempts remain available.

ApplicationFee::refreshStatus() uses exact integer minor units for the existing
two-decimal ledger. It sums positive paid amounts belonging to the admission's
school and fee currency. Failed/rejected payments do not contribute. Explicit
waivers remain waivers. Historical rows without currency remain countable;
they are not rewritten. Duplicate historical online transaction IDs within an
application are counted once. A positive partial payment does not settle the fee.

The required fee still comes from the intake, and currency from the existing
active-currency setting. Immutable fee/currency snapshots remain later work.

## Online settlement contract

VerifiedApplicationPayment is an internal value object for evidence obtained by
server-side provider verification. Never construct it from a browser return or
webhook request's asserted payment data.

ApplicationPaymentSettlement validates payment ID, school, admission/applicant
ownership, provider, merchant reference, stored provider transaction identity,
expected/actual amount and currency, and current internal state. Paid replays
are no-ops. Rejected/waived payments cannot be reopened. A verified late success
may recover a failed attempt. Settlement never changes the application lifecycle.

All existing online application providers now use this boundary. Flutterwave
does not provide its numeric transaction ID when the hosted checkout is created;
its independently fetched verification must match the stored unique merchant
reference before the transaction ID is first bound. MarzPay and Stripe must
verify the transaction/session stored when initiation succeeded.

No migration is needed for this phase. Because the existing schema lacks a
unique provider/transaction constraint, the boundary locks existing payment
rows for the provider, in ID order, across schools before checking transaction
reuse. It then locks the admission, writes the payment/cache/audit atomically,
and sends the applicant notification only after commit. This deliberately
serializes application settlement per provider; it favors correctness over
throughput until a later approved schema phase can introduce a durable indexed
transaction identity. Do not bypass this boundary in a future online adapter.

SQLite tests verify sequential replay/state behavior; SQLite does not exercise
MySQL row-lock concurrency. MySQL concurrency/load verification remains a
release validation requirement. Direct database/manual changes are outside the
automatic-settlement guarantee.

## MarzPay evidence and limitations

The official API documents transaction.uuid, transaction.reference and
collection.amount.raw/currency on GET /collect-money/{uuid}:

- https://wallet.wearemarz.com/documentation/api
- https://wallet.wearemarz.com/documentation/collections

MarzPayService::verifyApplicationPayment() queries the stored transaction using
the owning school's configured credentials. Webhook metadata merely locates a
candidate; the posted UUID must first match that candidate's stored UUID. Posted
amounts/status/reference are not evidence. Unknown/missing provider evidence
fails closed. Missing verification returns 503 for application callbacks/polls
so an outage is not acknowledged as successful processing. Identity mismatches
do not settle or notify. Verified failure refreshes the application fee cache.

The response does not identify a PIIE school. School provenance is established
by the internal payment/admission relationship and the credential scope of the
server-side request, not an invented provider field. Historical pending attempts
missing stored transaction IDs or currencies cannot be automatically settled;
they remain readable for audited reconciliation. Do not substitute a merchant
reference for a missing MarzPay UUID or an expected amount for missing evidence.

Tuition/hostel/subscription accounting has not been redesigned in this phase.
The Accountant decline defect is corrected by clearing the unconfirmed amount
instead of crediting the invoice total. A separate tuition payment/allocation
ledger is still required before introducing installments safely.

## Final requirement reserved for later phases

Submitted application -> application fee -> verified payment -> admissions ->
student enrolment/activation -> login/account access -> applicable tuition
invoice -> academic access after cumulative verified tuition reaches at least
20% of that semester/programme tuition.

Login, password setup/change, appropriate profile/account functions, tuition
invoice, payment initiation/status and logout must remain accessible before
the tuition threshold. Academic access is restricted, not the entire account.
20% is a minimum: 25%, 50%, 100% and other higher payments are permitted. The
installment policy is 20% / 40% / 40%. Another semester's payments and unrelated
non-tuition charges must not count toward this threshold. None of these tuition
access/installment rules is implemented by Phase 1.
