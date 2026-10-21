# Bursaries and scholarships

Finance staff with **Manage invoices** permission can open **Student fees → Payments / bursary / scholarship** for an invoice. Select bursary or scholarship, enter the amount covered, an optional approval reference, and a reason. For example, a 40% scholarship against a 1,000,000 invoice is entered as 400,000; the student owes 600,000 before payments.

This is an institution-funded reduction, scoped to that invoice and its academic session. It does not change the published fee structure, another student's bill, future invoices, or admissions application fees. Existing ordinary discounts are preserved. A sponsor who will pay the institution still owes that money: record the actual verified sponsor payment through the payment process rather than granting a reduction.

The net invoice total and balance are recalculated atomically. `paid_amount` and payment receipts remain unchanged. Awards cannot reduce the net fee below payments already received; refunds and overpayments require separate finance reconciliation. The 20% study requirement uses current-session net fees. Full coverage allows study without posting fictitious cash or bypassing academic requirements.

One award can be active on an invoice. Revoke it, with a reason, before replacing it. Revocation restores the exact amount and recalculates the balance and study eligibility. Approval and revocation retain the responsible staff member, name, timestamps, amount, reference, and reasons. Student and parent views show the reduction separately from cash; private approval reasons remain in the finance view. Printable school-fee invoices show the award breakdown.

Pending/offline claims and unresolved provider orders block changes. Failed provider orders with a tracking ID also block changes because they can settle late. Finance must reconcile these cases before an adjustment; this feature does not cancel provider orders or process refunds. Generic invoice edit/delete actions are blocked once award history exists, including after revocation, to preserve reconciliation history.

Deployment requires migration `2026_10_21_050000_create_student_fee_awards_table.php`. No existing invoices change until finance approves an award. Rollback refuses to remove the award table while active awards exist; revoke/reconcile them first. Test coverage lives in `StudentFeeAwardsTest`, alongside the existing tuition payment journey tests.
