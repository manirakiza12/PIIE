# Programme Years owner CRUD

Programme Years exposes Create, View, Edit and Delete directly for institution
owners, including years in approved and retired curricula. The create selector
includes all curriculum statuses for full-access roles; ordinary staff retain
draft-only controls.

An owner mutation on an official curriculum requires a reason and uses the
existing audited owner correction service to reopen that curriculum as Draft.
The reopening and year mutation share one transaction. If validation or deletion
fails, the curriculum status and year data both remain unchanged. Successful
changes show a reminder to approve the curriculum again after corrections.

Deletion is blocked for years containing course-unit placements or referenced by
student placements. The interface displays a disabled Delete control and explains
course-unit dependencies. Tenant, permission, duplicate sequence and model guards
remain authoritative. No database migration is needed.
