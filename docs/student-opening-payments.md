# Prior payments for existing students

After adding/importing an existing student, issue or sync their applicable fee invoices. Open **Student fees → Payments / prior payments** on the invoice for the correct academic session. Finance staff with **Approve payments** permission can record the amount already received, original payment date, receipt/reference, and verification reason. This is for verified money received before the student joined the system, never a promise to pay or a discount.

For fees of 1,000,000 and a prior payment of 600,000, the invoice shows 600,000 paid and 400,000 payable. PesaPal charges the outstanding balance, and verified online installments add to the paid amount. The prior payment also counts toward the 20% requirement for the invoice's current academic session. Fully paid invoices require no further payment. Fee structures and other invoices are unchanged.

Prior payments are retained separately in `student_opening_payments`. `paid_amount` includes both historical payments and new verified money; `opening_paid_amount` contains the active historical portion. New collection totals exclude the historical portion. Invoice balances and eligibility use the combined paid amount. The finance dashboard displays prior payments separately, and historical-only entries do not appear as recent new collections. Printable invoices, invoice emails, student and parent payment screens show receipt details.

Duplicate receipt references are blocked institution-wide, ignoring case and whitespace. One receipt must have one invoice allocation; reconcile/split a multi-invoice receipt into documented allocation references before entry. Record only the amount allocated to this invoice. Existing payments are preserved, and an entry cannot exceed the remaining balance. Overpayments/refunds require separate finance reconciliation.

Pending offline claims, pending gateway orders, and failed provider orders with tracking IDs block entry/reversal because their eventual settlement can change the balance. Finance must reconcile those cases first. The feature does not cancel provider orders, initiate refunds, or automatically guess undocumented historical payments.

An incorrect entry can be reversed with a reason. Reversal restores the balance without deleting the original approval or changing actual gateway receipts. For a corrected entry, use a documented correction reference; receipt references cannot be reused even after reversal. Approval/reversal retain the staff member, name, time and reason. Generic invoice edit/delete is blocked whenever prior-payment history exists.

Requires migration `2026_10_21_060000_create_student_opening_payments.php`. Existing invoices receive an opening paid amount of zero, so the migration does not infer payments or change balances. Rollback refuses to remove active prior-payment records. The bursary/scholarship feature is independent; this branch starts from main and does not include the unmerged award changes.
