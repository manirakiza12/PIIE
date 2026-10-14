# Library management

Open **Library** from the Admin or Librarian menu. The student menu opens the student’s library workspace.

## Prepare the collection

1. Review **Borrowing rules**. Defaults are 14 days, three open loans, one renewal, seven-day reservations, and blocking new borrowing while overdue. Adjust these to the institution’s policy.
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

Existing `library.view`, `library.manage_books` and `library.issue` permissions govern staff access. The legacy library menu restriction is respected. Students see only their own loans/reservations and cannot alter stock, policies or other members’ records. Every query and mutation is scoped to the signed-in institution.

## Release validation

Workflow tests use an isolated synthetic database and fake mail/HTTP services. Browser fixtures use synthetic titles and members; they never populate the production catalogue. Production rollout uses the existing exact-commit CI, protected staging, backup, additive migration, activation and smoke-check workflow.
