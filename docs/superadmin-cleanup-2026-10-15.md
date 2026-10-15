# Super Admin workspace cleanup — 15 October 2026

Production inventory at d608679c75bd87b5a991ebab257ffb5f7c715c49: one institution; zero packages, subscriptions, subscription payment-history rows, addons, legacy FAQs and frontend features. Subscription enforcement is disabled. Current CMS has seven pages, 35 sections and 106 items (51 legacy programme items are unowned/global).

## Removed from the current workspace

- Subscription report, expired subscriptions, pending subscription requests and packages: omitted when enforcement is disabled and all three billing tables are empty. Existing records or enabled enforcement restore these controls automatically.
- Addons: omitted while no addons are installed. Existing addon records restore the menu, including inactive addons that may need management.
- Duplicate Create School sidebar shortcut: institution creation remains available from the institution list.
- Legacy Website Settings and Manage FAQ sidebar shortcuts: the public website uses Website Management sections/items for FAQs and current content. The legacy forms and endpoints remain available for compatibility, including old global settings.
- Vendor About shortcut: this is software licensing/update information, not PIIE's About Us page. No license or updater code was removed.
- Empty subscription dashboard metrics/chart: replaced by nine working administration links in the same unused-billing state. The existing billing dashboard remains for installations with billing records or enforcement.

The Schools menu is now labelled Institutions. Its routes and tenant management behavior are unchanged. No academic school/faculty was created.

## Preserved dependencies

Payment settings, SMTP, branding, languages, Google Meet, library administration, audit logs, enquiries, institution administrators and all routes remain. Addon identifiers still govern optional route registration in RouteServiceProvider; subscription infrastructure still governs configured access checks. Neither can safely be deleted as dead code merely because its current tables are empty.

No CMS rows were deleted. Current templates explicitly consume multiple sections and shared item groups; the programme catalogue also retains CMS fallback cards and footer programme-category links. An empty section or a section absent from one page is not proof of disuse. Public content, programme/admission relationships and seeders are unchanged.

## Verification

Read-only inventory: scripts/audit-superadmin-sections.php; production baseline: output/superadmin-cleanup/inventory-before.json and protected-before.json. Local targeted verification: 41 tests, 291 assertions, including the actual Super Admin dashboard response and retained operational links. The state tests cover enabled enforcement, records in each billing table, installed addons and absent optional tables. Database inventory uses a read-only transaction; no personal records or credentials are printed.

Deployment and final production evidence will be recorded after CI.
