# Final local system check — 14 October 2026

Completed against the local working tree. No production deployment, real provider payment or real email delivery was performed. Tests use isolated databases, fake mail and fake payment responses; the public HTTP crawl is read-only against the existing local server.

## Results

| Check | Result |
|---|---|
| Full backend run | 4,161 tests exercised, 35,140 assertions; 8 failures found, 26 tests skipped. Run took 24m32s. |
| All eight original failures after corrections | PASS: 8 tests, 79 assertions. |
| Role dashboards, librarian messaging, safe PDF downloads, extension flags and cache protection | PASS: 13 new smoke cases, 40 assertions. |
| Staff/student exports, navigation and affected existing pages | PASS: 64 tests, 304 assertions. |
| Finance security, offline payment decisions, numbering and smoke checks | PASS: 38 tests, 155 assertions. |
| Public pages/catalogue/contact/identity checks | Initial affected rerun passed 206 of 207; the remaining old Login-label assertion was corrected and passed in the final focused reruns. |
| JavaScript suites | PASS: 33 tests plus 14 standalone exam-revision tests. |
| Route and source inventory | PASS: 1,324 registered routes; 744 templates compile and parse; no invalid controller actions, duplicate names, missing active template routes or missing required URL parameters. |
| Public HTTP crawl | PASS: nine public entry pages, 27 distinct internal links and 23 distinct media files; canonical redirects followed. |
| Static public markup | PASS: no structural/accessibility findings from the markup checks. |
| Dependency advisory checks | PASS: Composer lock audit and npm audit report zero advisories/vulnerabilities. |
| Isolated migration compatibility probe | PASS. |
| Local migration state | No pending migrations after applying the missing offline payment proof column. |
| Scheduled jobs | Application notification retries, invoice email retries and PesaPal reconciliation are registered. Server cron execution has not been verified. |

The full 4,161-test run recorded the initial failures. After fixes, all eight failed cases and affected suites were rerun successfully; the entire long suite was not rerun a second time. The 26 skipped cases remain unexecuted and are not reported as passes.

## Corrections made

- HR, receptionist and examinations shortcuts now use existing route names and required parameters.
- Dashboard counters use current admission, fee and exam states; staff counts include Registrar, Director and generic staff roles.
- Programme application-link normalization derives both canonical and legacy paths from registered routes.
- Staff and student profile PDF filenames sanitize registration numbers containing slashes; displayed registration numbers remain unchanged.
- Student detail lookup reads the correct school's title.
- Librarian conversation routes were missing. Restored them with school/participant checks, recipient validation, bounded messages and escaped search names. Empty conversations render without dereferencing a missing last message; conversation views read only the selected thread.
- Legacy addon menu entries require their actual extension routes, preventing rendering failures from enabled-but-uninstalled flags. Native system modules remain available.
- `/clear-cache` is now POST-only, authenticated, Super Admin-only and throttled. Its tests mock the cache operation; no cache clear was executed by these tests.
- The existing additive `document_image` migration was applied only to the verified local database so offline payment proof submissions have their required column.
- Public-site fixtures explicitly configure their school, and assertions reflect the requested Login label and new staff numbering.
- CI now includes route/template auditing and publishes its JSON inventory. This workflow change has not yet run on GitHub.

The inventory retains 52 informational references in retired gateway/addon templates or route-guarded extension menus. They are not missing active navigation routes. Their dormant workflows were not enabled or presented as working installed modules.

## Remaining release checks

1. Supply the semester start date for the intake named **Year one**. It currently cannot generate a correct registration year/month; the system deliberately requires the date rather than guessing.
2. Complete real PesaPal acceptance before enabling live payments. The last recorded sandbox acceptance returned INVALID/status 0 despite hosted success; this audit did not retry or settle that transaction.
3. Verify actual inbox receipt for SMTP, admission letters and invoice notifications. Automated mail behavior passed with fakes, and local SMTP fields are configured, but real delivery is unverified.
4. Run CI on the final committed release, verify current production server access, backups, migrations, HTTPS/IPN configuration and scheduled job execution. Earlier server access failed; this audit did not make a new SSH connection or verify production state.

Evidence is in `output/system-check/`, including the initial JUnit/log, eight-case rerun, smoke/finance/navigation logs, public crawl, route inventory, dependency audits and migration reports. The working tree also contains earlier uncommitted task changes; release packaging must use a reviewed final commit.
