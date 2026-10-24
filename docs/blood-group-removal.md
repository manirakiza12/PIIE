# Blood Group field removal

PIIE does not have a dedicated `blood_group` database column. Historical values
were stored as a key in the `users.user_information` JSON document. The
application no longer asks for, validates, writes, displays, or exports that
value. Profile updates preserve unrecognized JSON keys without reading them, so
existing records remain intact and an application rollback does not lose data.

No data migration is needed or included. The legacy JSON key is intentionally
retained in existing records; deleting it would require a separate, explicitly
reviewed data migration and a recovery plan for rollback.

The offline-admission CSV template omits the field. The importer continues to
accept older CSVs containing a `BloodGroup` column, ignores that column, and
maps the remaining values by header so existing templates do not shift gender,
date-of-birth, or address data.
