# Library management

Open **Library** from the Admin or Librarian menu. The student menu opens the student’s library workspace.

## Prepare the collection

1. An administrator reviews **Library settings**. Defaults are 14 days, three open loans, one renewal, seven-day reservations, blocking new borrowing while overdue, PDF uploads, authenticated downloads and zero fines. Configure the library name, opening hours, module status, download access, EPUB allowance and fine rules.
2. Use **Add title** for title, author, ISBN, subject/category, publisher, edition, year, language and shelf/call number. Add a description and authorised HTTPS digital-resource link when available.
3. Set the number of physical copies. Each copy receives an accession such as `LIB-12-0001`. In the title’s **Copy register**, replace it with an existing institutional accession if needed and record condition and inventory notes.
4. Mark reference-only, damaged, lost or withdrawn copies in the copy register. They cannot be issued. Restore a repaired/recovered copy to circulating after reviewing its condition.

The total registered count retains every accession, including withdrawn/lost stock. Available stock excludes unavailable copies and active loans. Increase the registered count to add copies; do not reduce it to erase accession history.

## Operate the issue and return desks

- **Issue a book:** find an active student or staff member by name, member code or email; find the title by name, ISBN or accession; select the physical accession or assign the next available copy. The system checks borrower limits, overdue rules, duplicate loans, stock and reservation order before committing.
- **Return:** open the loan, review borrower/title/accession, select the condition and confirm. Good/fair returns restore availability; damaged/lost returns close the loan and keep that copy out of circulation. Returns record time and operator.
- **Renew:** available for current loans with a due date, within the renewal limit and with no waiting reservation. The new period extends the recorded due date. Students can renew only their own loans.
- **Reserve:** students reserve from a catalogue title; staff reserve through the issue desk after finding the borrower. Requests are served oldest first for each title and expire after the configured validity period. Checkout fulfils the borrower’s first valid reservation automatically. Students cancel only their own reservations.

## Monitor and reconcile

**Overview** shows title/copy totals, current loans and overdue counts. **Loans & returns** filters active, overdue, returned, lost, legacy and complete history. Catalogue search covers title, author, ISBN, subject, publisher, shelf and accession; availability and category filters narrow the results.

**Reports** provides catalogue, circulation and overdue CSV exports, accession inventory, popular titles and an activity trail of changes. Exports are institution scoped and neutralise spreadsheet formulas. No fees or payments are created by returning a book; any institutional charges require the normal authorised finance workflow.

## Existing data and access

Existing titles and issue records are retained. Accession records are generated on a title’s first save or checkout, and existing open loans are assigned available accessions where stock permits. An inconsistent legacy stock count does not manufacture additional copies or allow further over-issuing.

Legacy loans without a due date are explicitly shown as **Due date not recorded**. They cannot be renewed. Review these at the library desk, then return/reissue where appropriate. Closed legacy records retain their original issue dates/statuses and do not acquire invented return dates.

Old catalogue links remain usable. Old return/delete GET links are review forms; changes require authenticated POST and CSRF protection. Catalogue titles are archived rather than deleting history, and open loans/reservations must be closed first. Direct legacy loan edits are refused.

Daily duties use `library.view`, `library.manage_books`, `library.issue`, `library.digital`, `library.archive`, `library.reports`, `library.notices` and `library.fines`. `library.configure` and `library.override` stay with School/Super Administrators and cannot be delegated. The legacy library menu restriction is respected. Students and lecturers see only their own loans/reservations/fines and cannot alter stock, policies or other members’ records. Every query and mutation is scoped to the selected institution.

## Responsibility and digital resources

Super Admin opens **Settings → Library Administration**, chooses the school and supervises its library. School Admin uses **Library → Staff access** to assign/remove Library Manager or Assistant Librarian roles using the existing audited permission engine. Generic Staff accounts receive exactly the chosen bundle. Assistants cannot archive catalogue titles, approve readings, change policy, assign roles or waive charges. A built-in Librarian account has manager privileges from its base identity; disable the account or change its base role through authorised staff administration when offboarding.

Private PDF/EPUB uploads are stored outside public uploads. The application verifies format, size, institution, resource publication, download audience and global download rules before serving an attachment. Direct public storage URLs are never generated. EPUB uploads require explicit administrator enablement. Librarians upload resources; lecturers submit readings privately through **Recommendations**, and a librarian approves or rejects them. Students can recommend titles without uploading files.

Lecturers can link approved catalogue resources to offerings where their current allocation permits teaching. **Course readings** shows only offerings the lecturer may manage or the student may access through confirmed registration. Linked resources retain their publication/download restrictions.

Library notices support all-reader or staff-only audiences and optional expiry. The overdue desk posts borrower-specific portal notifications, limited to one per loan/day; it does not send external email.

## Optional fines and supervised exceptions

Fine rate, grace period, cap, currency and title replacement cost are snapshotted at checkout. Return/lost closure assesses overdue and replacement charges once; legacy zero-rate loans are not charged retrospectively. Readers see their own estimates/ledger. Librarians may record receipt of a payment with a verified reference; this is a library ledger entry, not a payment gateway transaction or school-fee payment. Only an administrator may waive a disputed charge, manage library borrowing blocks or override a borrowing-policy restriction with a reason. Overrides never enable a disabled school account, invent stock or bypass reservation order. These decisions are audited.

## Release validation

Workflow tests use an isolated synthetic database and fake mail/HTTP services. Browser fixtures use synthetic titles and members; they never populate the production catalogue. Production rollout uses the existing exact-commit CI, protected staging, backup, additive migration, activation and smoke-check workflow.
