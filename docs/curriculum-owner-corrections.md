# Owner corrections to approved and retired curricula

Super Administrator and Institution Administrator accounts (the existing full-access
roles 1 and 2) can manage an approved or retired curriculum within their institution.
The curriculum list exposes **Edit / Restore**. Its details page exposes owner controls:

- **Reopen for Editing** changes the same version to Draft. Existing metadata,
  Programme Year, Course Unit, credit and prerequisite editing flows then apply.
  Approve the version again after corrections; no successor is required.
- **Restore to Approved** restores a retired curriculum after checking current
  approval rules. It becomes available for new placements and allocation links.

Each action requires a reason and records the previous curriculum, years, course
units and prerequisite structure in the audit log. The service enforces the owner
role, active account permissions and matching institution, independently of route
permissions. Delegated curriculum approval does not grant owner override.

Reopening does not change student registrations or existing allocations. While the
curriculum is Draft, new placements, allocation links and student registration or
confirmation require approval again.
Existing reference integrity checks remain in place when deleting linked records.
No schema migration is required.
