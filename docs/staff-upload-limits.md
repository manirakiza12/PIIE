# Staff creation upload budget

## Incident and evidence limits

The reported body was 43,057,596 bytes (41.06 MiB); PHP's reported
`post_max_size` was 25,165,824 bytes (24 MiB). PHP discards oversized POST data
before Laravel validation. Laravel's global ValidatePostSize then produces 413
before sessions, CSRF and controller validation. Raising a controller rule cannot
fix that boundary.

The lecturer and teacher form has `photo` (4 MiB) and
`documents[i][file]` (5 MiB each, up to ten). Their previously permitted combined
size was 54 MiB, with multipart overhead on top. The document repeater is the
aggregate risk, not an unbounded hidden/base64 field. Even nine individually
valid documents can explain a 41 MiB request. A single invalid large photo or
document can also explain it. **The exact incident file/category cannot be
identified from Content-Length alone.** No incident multipart payload or safe
per-field byte inventory was supplied. Do not log files, NIN or personal data;
ask the reporter for field labels and byte sizes only, never file contents.

## Application policy and audited forms

- All seven `/admin/staff/create/{type}` forms (admin, lecturer, teacher,
  accountant, librarian, warden, staff): retain JPG/PNG photo max 4 MiB.
- Lecturer and teacher: retain up to ten private PDF/JPG/PNG documents, each
  5 MiB by default (`piie.staff_documents.max_kb`). Combined files, including
  photo, now max 20 MiB. Add more documents later through the existing profile.
- Browser checks run on file selection and submit, including newly added rows;
  server aggregate validation precedes provisioning and file storage.
- The old `/admin/staff/add/other` form has no multipart encoding or upload
  inputs: no new upload policy needed. Legacy per-role create handlers delegate
  photo storage to StaffProvisioningService/ProfilePhoto (4 MiB, content checked).
  Those service checks remain intact. Staff profile editing uses the same photo
  and document stores; it is not converted into a new creation workflow.
- PHP-level oversize responses now explain recovery in HTML or JSON with HTTP
  413. There is no redirect loop or attempt to flash a body PHP discarded.
  Proxy-generated 413 cannot be handled by Laravel.

## Authorized operator: verify effective limits before rollout

No production configuration has been inspected or changed in this task. The
incident establishes PHP POST=24 MiB, but NOT its per-file limit or the active
proxy/Apache configuration. The repository Nginx template has
`client_max_body_size 32M`; it is not proof that the active host uses it.
The deployment runtime can use Docker/Apache or the legacy host. Inspect every
active ingress/CDN, Nginx virtual host/location, Apache vhost/directory/.htaccess,
and PHP web-SAPI configuration (container overrides, conf.d, pool settings and
.user.ini). CLI php.ini alone is insufficient. Keep paths/secrets private.

Recommended compatibility settings, reviewed then applied only by the operator:

The inert reference file is `deploy/php/uploads.ini`; no workflow installs it.

```ini
; Effective WEB PHP config (not committed production .env):
post_max_size = 24M
upload_max_filesize = 20M
max_file_uploads = 20
```

20 MiB per-file PHP allowance preserves existing course/library 20 MiB upload
requirements; staff still enforces 4/5 MiB. Do not globally lower this to 5 MiB.
Retain the existing 32 MiB Nginx ceiling. For Apache or another proxy, configure
at least 24 MiB plus compatibility headroom (32 MiB = 33,554,432 bytes is
consistent with the Nginx template), subject to other application requirements.
Do not raise any body limit to admit the reported 41 MiB staff request. If any
active layer is lower than these requirements, review capacity and scope before
changing it. Keep PHP POST greater than its single-file limit.

Budget: at most 20 MiB staff file data, 4 MiB remaining POST headroom for fields
and multipart boundaries, eleven expected uploads (ten documents plus photo).
Oversized text/forged bodies still fail closed. No upload is buffered as base64
by this fix. Check request buffering/temp-disk capacity for concurrent workers
(N simultaneous bodies may consume roughly N x ingress body limit in temporary
space), available disk/inodes, timeouts and worker memory before rollout. No
memory_limit/timeout increase is proposed. Reload the relevant service only after
configuration validation under the normal approved operational process.

Verify effective values through an authenticated, allowlisted diagnostic or
operator console without publishing phpinfo. Reproduce with synthetic files:
4 MiB photo, 5 MiB document, combined boundary, over-budget documents, and an
over-24-MiB body. Confirm no staff/files are created on failure. A proxy 413
needs its own generic recovery page (never echo submitted fields); test that
response at the actual ingress separately from the Laravel 413 regression.
