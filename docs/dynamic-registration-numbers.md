# Dynamic student and staff numbers

New programme students receive `YYYY/MM/PROGRAMME/NNN`, for example `2026/05/MPAM/001`. Year/month come from the selected intake's semester start date; programme abbreviation comes from its configured programme code. A legacy intake named explicitly like “May 2026 Intake” is also understood when its start date is blank. Application opening dates are never substituted. Missing/ambiguous academic information produces a validation message before account creation.

New staff receive `YYYY/MM/ROLE/NNN/`, using account creation year/month. Role abbreviations: ADM administrator, TRS teacher, ACC accountant, LIB librarian, WDN warden, STF other staff. This employee number is distinct from professional registration/licence numbers.

Numbers are assigned once in `users.code`. Existing students/staff retain their current numbers when edited, re-enrolled or assigned another role/programme. Student ID cards, admission letters and activation messages use the saved number. Older class-only admissions retain their existing generator because no programme/intake is available.

Persistent, locked sequence counters are global per prefix, preventing collisions across schools sharing a programme abbreviation. Separate programme/month/role prefixes restart at 001; numbers expand past 999. Deleted accounts do not free their numbers. Existing imported numbers, with or without a legacy trailing slash, are skipped. Account creation and counter advancement share the provisioning transaction.

Students cannot edit their name, login email, date of birth or registration number in the student portal. These identity fields are displayed read-only and ignored by the self-service update endpoint, including forged requests. Corrections must be made by authorized institutional staff. Phone, address, gender, blood group and passport/photo remain editable.

CSV student imports now accept `intake_name`; this is required for programme students and validated during preview without allocating numbers. It must match exactly one intake in the current school. Download a fresh template before importing programme students.

Release migration: `2026_10_14_100000_create_identity_sequences_table.php`. Applied only to the verified local database. Check intake start dates and programme abbreviations before release. `php scripts/verify-registration-number-installation.php` reports local installation readiness without creating accounts.

Production deployment and payment-provider acceptance are separate release checks. No live deployment or real emails/payments were performed for this change.

Validation: 207 related feature tests passed (1,447 assertions), covering admission conversion/letters, bulk imports, student ID cards, staff creation, professional records and next of kin. Additional coverage accepts both “May 2026 Intake” and “2026 September Intake” without a configured start date. The local intake named “Year one” needs a semester start date; its year/month cannot be inferred safely.
