# Student visibility and fee tracking investigation

## Baseline verified before changes

- PR #89 (existing-student financial setup): merged at `b9974604ca32bb6ec326f0dc9ce20ab2b1bdfd58` on 2026-10-23 at 05:02:44 UTC.
- PR #90 (academic placement visibility): open, head `1c5276f9110892460b5ee5708cf50c2d8cf83f38`. Its CI run [39915902206](https://github.com/manirakiza12/PIIE/actions/runs/39915902206) passed. None of its changes are copied into this fix.
- Main: `b9974604ca32bb6ec326f0dc9ce20ab2b1bdfd58`.
- Latest protected production activation [39911566646](https://github.com/manirakiza12/PIIE/actions/runs/39911566646) succeeded. Its logs report `MODE: activate`, deployment of that exact main SHA, read back the active `RELEASE_SHA` as that SHA, and passed 25 public asset checks. This is the last workflow-verified live SHA, not a fresh authenticated server/database read. There is no authenticated production browser or SSH session available for an independent present-time census.

This work does not dispatch a deployment, merge a PR, or write production student, placement, invoice, payment, award or review records.

## What the screenshot counts can and cannot establish

The reported screenshots show nine Admin > Students records, three Direct Placement options, and three Fee Manager invoice rows for October 1–31, 2026, with Paid Amount zero. These are different populations, not three ways of counting one table. They are evidence of what was displayed, not proof of present database contents or a particular student's ledger.

| Screen | Current-main query and limits |
| --- | --- |
| Admin > Students | Same-school role-7 `users`, optional name/email and class/section filters; server pagination is 10. Includes students with no enrollment, placement or invoice. Enabled/disabled accounts are not excluded by the default student list. Nine displayed records need not be a complete unfiltered database census. |
| Direct Placement | Same-school role-7 users **without** a same-school `student_curriculum_assignments` row whose `ended_at` is null. It does not depend on an invoice, payment, fee session, profile intake, or balance. An incomplete active assignment also excludes the student. Options are loaded with `get()`, not a three-row server limit. PR #90 separately explains these exclusions and inventories placements. |
| Fee Manager | `student_fee_managers`, not users. Originally filtered to the school's `running_session` and invoice `timestamp` within the current month by default. Explicit date/class/programme/status filters further narrow it. Invoice dates are not payment dates or student creation dates. No invoice means no row. Invoices outside October or in a different session do not appear in the October/running-session report. Default status is all; there was no default positive-balance predicate. The invoice table's browser pagination/search can further narrow displayed rows. |

Zero `paid_amount` is not evidence that a historical cash payment was lost. Under PR #89, captured prior cash, awards and waiver requests stay in `student_financial_setup_reviews` until an authorized confirmation. They do not yet update `paid_amount`, `opening_paid_amount`, awards, or eligibility. A screenshot alone cannot determine whether a zero-paid invoice has a pending packet, an unrecorded receipt, a reversed receipt, or no payment at all. That requires reviewing the exact school/student/session/invoice IDs.

Class/Section on the invoice report comes from the legacy enrollment helper. Portal-only Add Existing Student creates no class/section enrollment; tertiary curriculum placement also does not create one. Blank labels neither establish nor invalidate tertiary placement. Placement must not block finance discovery.

The old controller also treated **any query parameter** as an invoice date-filter submission and immediately accessed `eDateRange`, `class` and `status`. Session/search/pagination-only URLs could therefore fail. The revised filter parser supplies safe defaults, validates real calendar dates and keeps report filters separate from the student inventory.

## Smallest independent fix

`Billing\StudentFeeOverview` is a read-only, paginated inventory shared by the Admin and Accountant/Bursar fee-manager entry points. It starts with enabled same-school student accounts (`role_id=7`, `account_status` not `disable`, including null under the existing account rule), then loads invoices for those exact student IDs and the selected same-school fee session. It requires no academic enrollment, placement, intake or profile. A profile's study status does not hide an enabled account's finances.

Every such student is searchable by name, email or registration number and visible as owing, fully settled, or needing invoice review. No positive-balance, invoice-status or invoice-date filter controls this inventory. No-invoice amounts are unknown and are not labeled paid. The session selector applies to both the inventory and the invoice report. The invoice report remains separately labeled and retains its date/class/programme/status filters. Same-school student-link validation prevents a malformed cross-school invoice from disclosing another school's student in the on-screen report.

The inventory uses one row per student and groups invoice records before aggregating, avoiding multiplication from payment/award/review history joins. Multiple invoices remain individually identifiable and linked to their existing audited review screen. Page size is 25 with deterministic name/ID ordering, total/range labels, preserved session/search parameters and no missing-date failure on page 2. Disabled accounts remain outside the enabled-student inventory; their historical invoices can still be reviewed through the existing invoice report.

No GET generates an invoice, creates a student, marks a payment paid, approves a packet, or changes a placement. Staff must use the existing invoice/review services; repeated viewing cannot introduce duplicate students or invoices. An absent invoice is a prompt to review whether billing applies, not automatic authority to bill every student in every historical session.

## Accounting shown

All overview calculations use integer minor units and the existing invoice balance fields maintained by audited services:

- **Base fee / discount:** stored `amount` (legacy fallback `total_amount`) and `discounted_price`.
- **Awards:** same-school, non-revoked `student_fee_awards`, shown separately from cash.
- **Net fee:** stored `total_amount`; awards already reduce this, so they are not subtracted a second time.
- **Verified prior payments:** `opening_paid_amount`, credited by the existing original-receipt verification service. These are historical pre-PIIE cash/receipts, not new collections or fabricated gateway transactions. Existing original methods, references, approval and reversal audit history are retained.
- **New payments:** `max(0, paid_amount - opening_paid_amount)`, the existing new-collection definition. Legacy amounts without provenance are not reclassified or repaired by this page.
- **Balance:** sum of each invoice's `max(0, total_amount - paid_amount)`. Overpayment on one invoice cannot erase another invoice's debt. A prior total exceeding total paid is flagged for reconciliation rather than rewritten.
- **Pending confirmation:** same-school/student/session/invoice-scoped captured prior cash, awards and waivers, plus pending provider payments for the exact invoice. They are visible separately and are never added to paid totals or subtracted from balance before confirmation.

The invoice report's `paid_amount` column intentionally remains the combined credited cash total; its explanatory text now points to the separate components above. The Admin report's old “Payment date” label used `updated_at`, which can change without a payment; it is correctly labeled “Last invoice update.” Printable invoice views, shared invoice partials and PDF invoice layouts are unchanged.

## Regression coverage

The dedicated visibility tests reproduce nine students/three October invoices; new and existing accounts; uninvoiced and zero-fee students; partial verified pre-PIIE cash; bursary reduction; pending gateway payment and later provider-confirmed settlement with a repeated callback; pending captured setup and explicit confirmation; exact session filters; paid/unpaid report filters; wrong-school and malformed invoice links; search and two-page traversal; multiple invoices and receipt history without multiplication; and repeated read-only requests without new student/invoice/audit records. Existing prior-payment, awards, onboarding, provider and printable-invoice suites remain part of validation.
