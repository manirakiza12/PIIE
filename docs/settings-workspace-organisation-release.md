# Settings workspace organisation

Institution administration has a dedicated SETTINGS sidebar section and one
shared, permission-filtered list of destinations: General Settings,
Notifications, Teaching Platforms, Advanced Permissions, Backup and API Access.
The page navigation uses the same definition and indicates the current page.
Legacy per-user menu restrictions and existing route permissions still apply.

Grading Scales remains under Academics. Its page no longer carries Settings
tabs or teaching-platform controls. The original platform options and Jitsi URL
are available on the new Teaching Platforms GET page with the same settings.school
permission; saves still use the existing protected academic settings POST route.

The new forms explicitly identify their section. Grading saves preserve platform
configuration, and platform saves preserve grades. Requests from the original
combined form remain supported. Grade fields, calculations, provider options,
notification keys, existing access rules and storage scope are unchanged.

Notifications retains all six email and three SMS preferences. The form uses
separate responsive channel cards, labelled switches, descriptions and a single
save action. On/Off text updates immediately; preferences are saved only when
the existing form is submitted. No message delivery engine is added or changed.
SMS still requires the existing gateway integration described on the page.

Advanced Permissions retains the existing legacy matrix and its explanatory
warning. Staff Roles & Permissions remains its distinct existing workflow.
Backup and API controls, API keys, SMTP configuration and payment logic are
unchanged. No migrations or seeders are required.

## Local validation

- 15 targeted tests passed, 90 assertions. 21 LiveClassModuleTest cases skipped
  because their required schema is absent in this local SQLite suite; full CI
  runs against its disposable MySQL database.
- Checks cover separate editors and settings sections, independent grade/platform
  saves, legacy combined requests, notification preference persistence, existing
  legacy permission saves, role/menu restrictions and Blade syntax.
- 16 Chrome page/viewport checks passed at 320, 375, 768 and 1366px, using the
  actual settings URL patterns and real Laravel-rendered synthetic fixtures.
  No JavaScript errors or clipped controls. No browser forms are submitted.
- Evidence: output/settings-verified-tests.txt and output/settings-browser/.

Full CI and production evidence will be appended after activation.
