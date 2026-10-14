# Full-screen workspace UI

Implemented locally on 13 October 2026.

The original left sidebar and its role-specific module groups have been restored after the user reported missing navigation. The header menu button retains its original desktop collapse and mobile drawer behavior. Sidebar links remain accessible on dedicated forms and settings pages as well. The sidebar is covered only while a full-screen dialog is open and returns when that dialog closes.

The shared `rightModal`, `largeModal`, and `showAjaxModal` entry points now open full-screen surfaces. Their existing IDs and form endpoints are retained. Dedicated create/edit, profile, settings, password, application, and wizard pages also use full width and provide a return link.

Long conventional forms use Previous/Next steps. Grouped controls stay together, values remain in their original forms, and native validation reveals a missing required field before attempting to focus it. Smaller forms remain continuous. Long rich content and unusually short screens retain vertical scrolling so content is accessible; the implementation does not promise that every possible form fits every viewport without scrolling.

Programme editing now deliberately keeps every section together in one continuous form: details and tuition, cover, two gallery images, catalogue visibility, and display order. Images can be selected during creation, previewed before saving, replaced, or selected for removal. One save commits the details, media and publication together. Uploads are decoded before changes; database failures roll back the changes and remove newly written files while retaining original images. Image-only endpoints remain available for existing clients.

The combined programme operation is covered by tests for creation with three images, replacement, unchanged-cover preservation, invalid uploads, foreign image removal, rollback after a publication failure, and publishing/unpublishing. Chrome also checked image previews and that all three uploaded files are submitted by the same form. Updated screenshots and `programme-editor-report.json` are in the evidence directory.

Shared assets are included in admin, superadmin, teacher, student, parent, accountant/bursar, librarian, warden, applicant, the common app layout, and the public frontend layout. Static form dialogs, including student assignments, use the same screen treatment. Inputs, spacing, focus states, mobile columns, table wrappers, and close controls are shared. The teacher creation form also had an extra closing `div` removed to keep its controls inside the form.

## Verification

- All 736 Blade templates compiled and passed PHP syntax checks.
- Backend regression suites passed: 54 tests, 499 assertions (`PublicProgrammeCategoriesTest`, `StaffEntryPointTest`, `ApplicantPesaPalAdminUiTest`).
- Browser verification used actual Laravel-rendered programme, teacher, student, department, and intake forms with an isolated in-memory SQLite database.
- Chrome passed 35 form/width combinations at 320, 390, 576, 768, 1024, 1440, and 1920 pixels. Checks covered full-screen geometry, no horizontal overflow, navigation visibility, connected submit buttons, preserved submitted controls, the three shared AJAX entry points, and permission errors.
- The actual admin shell is checked at 320, 390, 768, and 1440 pixels, including original sidebar links and mobile drawer controls. No replacement module menu is created.
- JavaScript regression tests cover step navigation, submitted values and CSRF controls, hidden required-field validation, permitted module links and search, AJAX insertion, responsive tables, and applicant forms using Bootstrap rows.

Screenshots and the Chrome report are saved in `docs/ui-review/workspace/`.

## Reproduce

```powershell
node --test tests/js/workspace-ui.test.js
php scripts/workspace-browser-fixtures.php
# Pass the temporary directory printed by the preceding command:
node scripts/workspace-browser-verify.mjs <temporary-fixture-directory>
```

Browser fixtures and submissions are synthetic. These checks do not create live students, staff, programmes, payments, or provider operations. No deployment or database migrations are required for these UI assets.
