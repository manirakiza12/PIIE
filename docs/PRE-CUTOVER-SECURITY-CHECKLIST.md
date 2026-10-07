# Pre-cutover security remediation checklist

Two live-site findings, both pre-existing and neither caused by the PIIE release work.
Both must be closed **before** first cutover. Neither involves the application code.

Scope and non-scope, stated up front so nothing here is ambiguous:

- Nothing in this document touches the database, the document root, or the deploy gate.
- No command here deletes a file. Step 1 *relocates* the dump; the bytes remain on disk.
- No command prints credentials, `.env` contents, private keys or student records.
  Verification is by checksum, byte count, permission bits and key *count* only.
- Nothing here is executed by the release pipeline. These are one-time host actions.

## Status of the permanent guards (shipped in the repository)

Three of the recommendations below are **no longer pending** — they are implemented and
tested, so only the host-side steps remain:

| Guard | Where | Test |
|---|---|---|
| `.sql`/`.dump`/`.env`/key files refused by the web server | `public/.htaccess`, `deploy/nginx/piie.conf` | `deploy/tests/security-hardening-sandbox.sh` |
| Preflight **fails** on a group/world-readable `.env`, and on a `.env` inside the docroot | `deploy/remote/preflight.sh` | `deploy/tests/security-hardening-sandbox.sh` |
| `shared/` structure seeded with correct, runtime-writable permissions | `deploy/remote/seed-shared.sh` | `deploy/tests/security-hardening-sandbox.sh` |

Two further facts about the tracked dump, for completeness:

- The repository copy `public/assets/install.sql` was **schema and reference data only** —
  55 `CREATE TABLE` statements and 6 seed tables (`roles`, `language`, `currency`, `faq`,
  `global_settings`, `payment_methods`). No user, student, staff or admission rows. Every
  credential-shaped value in it was a `xxxxx` placeholder. It has been moved to
  `database/legacy-install.sql` and excluded from the release artefact.
- The dump addressed by Step 1 is a **different and far more serious file**:
  `public_html/fresh_install_piie_ac_ug/piie_ac_ug_full_dump.sql` is a real production
  dump containing student records. It is not in Git, no repository change reaches it, and
  Step 1 below is still entirely required.

Run every block on the Linux SSH terminal as the `piie` user, one block at a time, and
report each block's output before starting the next. If any verification fails, stop.

```bash
BASE=/home/piie/deployments/piie
DOCROOT=/home/piie/domains/piie.ac.ug/public_html
PHP=/usr/local/php83/bin/php
DUMP="$DOCROOT/fresh_install_piie_ac_ug/piie_ac_ug_full_dump.sql"
umask 077
```

---

## Step 0 — Preflight (read-only, changes nothing)

```bash
whoami; hostname

for p in "$BASE" "$DOCROOT" "$DOCROOT/.env" "$DUMP"; do
  [ -e "$p" ] && echo "OK   $p" || echo "MISS $p"
done

df -Pk "$BASE" | awk 'NR==2{printf "free %.1f GB of %.1f GB\n", $4/1048576, $2/1048576}'

curl -s -o /dev/null -w 'GET /      -> %{http_code}\n' --max-time 20 https://piie.ac.ug/
curl -s -o /dev/null -w 'GET /login -> %{http_code}\n' --max-time 20 https://piie.ac.ug/login

stat -c '%a %U:%G %n' "$DOCROOT/.env"
stat -c 'mode=%a bytes=%s' "$DUMP"
sha256sum "$DUMP" | awk '{print "dump sha256="$1}'
```

**Do not continue unless:** the user is `piie`, all four paths exist, free space is over
2 GB, and both HTTP checks return 200.

---

## Step 1 — Move the SQL dump out of the web root

**Finding.** `public_html/fresh_install_piie_ac_ug/piie_ac_ug_full_dump.sql` is a complete
database dump — schema plus student records — sitting inside the served document root. It
is currently unreachable only because root `.htaccess` rules deny it. One rule edit, or a
request path those rules do not cover, exposes every record in the database. No deploy
script references the file, so nothing protects it automatically.

**Why this order.** A verified protected copy is created and checksummed *before* the
original is moved, so there is no instant at which only one copy exists.

### 1a — Create the protected area and a verified compressed copy

```bash
QUAR="$BASE/backups/legacy-dumps"
mkdir -p "$QUAR"
chmod 700 "$BASE/backups" "$QUAR"

cp -a "$DUMP" "$QUAR/$(basename "$DUMP")"

gzip -9 -c "$QUAR/$(basename "$DUMP")" > "$QUAR/piie_ac_ug_full_dump.sql.gz"
gzip -t "$QUAR/piie_ac_ug_full_dump.sql.gz" && echo "gzip integrity OK"
sha256sum "$QUAR/piie_ac_ug_full_dump.sql.gz" > "$QUAR/piie_ac_ug_full_dump.sql.gz.sha256"
```

### 1b — Verify the copy is byte-identical BEFORE touching the original

```bash
a=$(sha256sum "$DUMP"                     | cut -d' ' -f1)
b=$(sha256sum "$QUAR/$(basename "$DUMP")" | cut -d' ' -f1)
[ "$a" = "$b" ] && echo "VERIFIED byte-identical" || { echo "MISMATCH - STOP"; }
stat -c '%s' "$DUMP" "$QUAR/$(basename "$DUMP")"
```

**Stop if the digests differ.** Do not run 1c.

### 1c — Relocate the original out of the served tree

A `mv` is a rename within one filesystem: the file still exists, it simply no longer sits
where the web server can reach it. Nothing is deleted.

```bash
mv "$DUMP" "$QUAR/piie_ac_ug_full_dump.sql.original"
chmod 600 "$QUAR"/*.sql "$QUAR"/*.gz "$QUAR"/*.sha256 2>/dev/null

find "$DOCROOT" -maxdepth 3 -name '*.sql' -print 2>/dev/null | sed 's/^/STILL PRESENT: /'
stat -c 'mode=%a bytes=%s %n' "$QUAR"/*dump*
```

Leave the now-empty `fresh_install_piie_ac_ug/` directory in place. Removing it is a
separate decision and is not required for the risk to be closed.

### 1d — Verify recoverability

```bash
c=$(sha256sum "$QUAR/piie_ac_ug_full_dump.sql.original" | cut -d' ' -f1)
[ "$c" = "$a" ] && echo "PRESERVED: original intact at 600" || echo "ERROR: original changed"

zcat "$QUAR/piie_ac_ug_full_dump.sql.gz" | sha256sum | cut -d' ' -f1 \
  | { read d; [ "$d" = "$a" ] && echo "RESTORABLE: .gz expands to identical bytes" \
                          || echo "ERROR: .gz does not restore identically"; }

curl -s -o /dev/null -w 'GET / -> %{http_code}\n' --max-time 20 https://piie.ac.ug/
```

**Recovery.** `mv "$QUAR/piie_ac_ug_full_dump.sql.original" "$DUMP"` restores the original
path exactly. The `.gz` plus its `.sha256` is an independent second copy.

### 1e — Permanent guards (already implemented)

The dump was reachable because nothing checked for it. Two guards now ship, so this
finding cannot silently recur. **No host action is required for either** — they are in the
repository and take effect with the next release.

1. **Web-server refusal.** `public/.htaccess` and `deploy/nginx/piie.conf` both deny
   `*.sql`, `*.sql.gz`, `*.dump`, `.env*`, dotfiles and key material, before the
   front-controller rules can serve an existing file. Note that `deploy/nginx/piie.conf`
   is the production server block and is **installed manually**; it is deliberately never
   applied by a deploy script.

2. **Preflight fails closed.** `deploy/remote/preflight.sh` scans the document root and
   reports any `*.sql`, `*.sql.gz`, `*.dump` or `.env*` as a **FAILURE**, not a warning,
   and prints the path and byte count of what it found. It does not delete anything.

Verify the guards are present in the release you are about to cut over:

```bash
grep -c 'FilesMatch' "$BASE/current/public/.htaccess" 2>/dev/null \
  || echo "bin/ or current/ not present yet - expected before first cutover"
```

---

## Step 2 — Restrict `.env` to 600

**Finding.** The live `.env` is mode 644, world-readable. On a shared host any local user
or any misconfigured process can read `APP_KEY` and the database credentials. `APP_KEY`
leakage is the more serious half: it allows forging signed cookies and `encrypted:` values.

```bash
chmod 600 "$DOCROOT/.env"
stat -c 'mode=%a owner=%U:%G %n' "$DOCROOT/.env"

# Digest only — never print contents.
sha256sum "$DOCROOT/.env" | awk '{print "sha256="$1}'

find "$DOCROOT" -maxdepth 1 -name '.env*' -perm /077 -printf 'WORLD-READABLE: %m %p\n' 2>/dev/null
echo "permission check complete"

curl -s -o /dev/null -w 'GET / -> %{http_code}\n' --max-time 20 https://piie.ac.ug/
```

No restart is required: PHP reads `.env` per request.

**Recovery.** `chmod 644 "$DOCROOT/.env"` restores the previous mode.

---

## Step 3 — Seed `shared/` (prerequisite for every deployment)

`deploy-release.sh` refuses to run without `shared/.env` and `shared/storage`, and
deliberately never creates `.env` itself — generating one could rotate `APP_KEY` and
invalidate every existing session and encrypted column.

**Use the script rather than the hand-typed list.** `deploy/remote/seed-shared.sh` reads
the same path names the deploy scripts use, so it cannot drift from the code the way a
documented list does, and it is read-only unless told otherwise:

```bash
# Read-only. Reports exactly what is missing or wrongly permissioned.
bash "$BASE/bin/seed-shared.sh" check

# Creates what is missing and sets the modes it owns. Never deletes, never
# touches existing content.
bash "$BASE/bin/seed-shared.sh" apply
```

It creates and permission-corrects:

| Path | Mode | Why |
|---|---|---|
| `shared/storage` | 2775 | parent of everything below |
| `shared/storage/app` | 2775 | **uploads served through `/storage`; irreplaceable** |
| `shared/storage/app/public` | 2775 | public half, linked to `public/storage` |
| `shared/storage/framework/cache/data` | 2775 | file cache |
| `shared/storage/framework/sessions` | 2775 | **sessions live here — nobody can log in if unwritable** |
| `shared/storage/framework/views` | 2775 | compiled Blade views |
| `shared/storage/logs` | 2775 | **Laravel writes here every request; unwritable means a 500** |
| `shared/public-uploads` | 2775 | everything served from `public/assets/uploads` |
| `shared/.env` | **600** | `DB_PASSWORD`, `APP_KEY`, every API credential |

2775 is setgid: the group is inherited by anything created later, so it stays
group-writable by construction rather than by repeated `chmod`.

The group must be the PHP-FPM runtime group (`www-data`, overridable via
`PIIE_RUNTIME_GROUP`). `seed-shared.sh` **warns** when it does not match, because fixing
it needs root:

```bash
# ONE-TIME, needs root. Deliberately not done by any deploy script, and not
# recursive over pre-existing production files.
chown -R piie:www-data "$BASE/shared"
```

`.env` still has to be created by hand, exactly once:

```bash
# Refuse to clobber an existing one.
[ -e "$BASE/shared/.env" ] && echo "shared/.env exists - STOP, do not overwrite" \
                          || cp -a "$DOCROOT/.env" "$BASE/shared/.env"
chmod 600 "$BASE/shared/.env"

# Verify without printing anything.
x=$(sha256sum "$DOCROOT/.env"     | cut -d' ' -f1)
y=$(sha256sum "$BASE/shared/.env" | cut -d' ' -f1)
[ "$x" = "$y" ] && echo "identical (APP_KEY preserved)" || echo "MISMATCH"

grep -cE '^[A-Z_]+=' "$BASE/shared/.env" | sed 's/^/keys present: /'
for k in APP_KEY DB_DATABASE DB_USERNAME; do
  grep -qE "^$k=." "$BASE/shared/.env" && echo "set      $k" || echo "MISSING  $k"
done
grep -E '^APP_DEBUG=' "$BASE/shared/.env" | grep -q 'false' \
  && echo "APP_DEBUG is false (preflight requires this)" \
  || echo "WARNING: APP_DEBUG not false - preflight will FAIL"

stat -c '%a %U:%G %n' "$BASE/shared/.env" "$DOCROOT/.env"
```

**Uploads.** Copy the live uploads into the shared tree. Copy only — the docroot
originals stay exactly where they are, and `assets/uploads/` is never touched by a
release (`docroot.sh` keeps it a real directory and only symlinks the other children of
`assets/`):

```bash
[ -d "$DOCROOT/storage/app" ] && cp -a "$DOCROOT/storage/app/." "$BASE/shared/storage/app/" \
                             || echo "no $DOCROOT/storage/app to copy"

find "$BASE/shared/storage/app" -type f | wc -l | sed 's/^/shared upload files: /'
du -sh "$BASE/shared/storage/app" 2>/dev/null

# If the group is wrong, fix it ONCE with root (see above) and re-run the script:
#   chown -R piie:www-data "$BASE/shared"
#   bash "$BASE/bin/seed-shared.sh" apply
```

Live `storage` is 777 today; 2775 with the runtime group is deliberate. If the application
later reports write errors, this is the first thing to revisit.

**Do not `chmod 777`** and do not recursively `chown` files that already exist under
`shared/` — see the caveats in `docs/DEPLOYMENT.md`. `prepare_public_uploads()` merges
runtime files into a release and deliberately leaves every pre-existing file's mode
alone; it only sets modes on what it creates.

---

### 3a — What must survive every release

These are the only things a release does **not** replace. Everything else in the
repository is recreated from the release artefact on every deployment.

| Path | Holds | If lost |
|---|---|---|
| `shared/.env` | credentials, `APP_KEY` | **site down**, and regenerating rotates `APP_KEY`, invalidating sessions and encrypted columns |
| `shared/storage/app` | user uploads via `/storage` | **irreplaceable** — not in Git, not in the artefact |
| `shared/public-uploads` | everything served from `public/assets/uploads` | **irreplaceable** — the repository holds only ~85 baseline assets; runtime uploads are `.gitignore`d |
| `shared/storage/framework/{sessions,cache,views}` | sessions, cache, compiled views | regenerable, but unwritable means a 500 |
| `shared/storage/logs` | application logs | regenerable |

`shared/public-uploads` is merged into each release by `prepare_public_uploads()`, which
follows one rule throughout: **existing runtime files always win.** Nothing is ever
overwritten, no `rsync --delete` is used, and uploads symlinks are never followed.

### 3b — Verify the upload copy landed

```bash
echo "  shared upload files: $(find "$BASE/shared/storage/app" -type f | wc -l)"
echo "  docroot upload files: $(find "$DOCROOT/storage/app" -type f | wc -l)"
echo "  shared-uploads files: $(find "$BASE/shared/public-uploads" -type f 2>/dev/null | wc -l)"

# These two must be equal. If not, copy is incomplete and cutover must not proceed.
a=$(find "$DOCROOT/storage/app"        -type f 2>/dev/null | wc -l)
b=$(find "$BASE/shared/storage/app"    -type f 2>/dev/null | wc -l)
[ "$a" -eq "$b" ] && echo "  UPLOAD COPY COMPLETE ($a files)" \
                  || echo "  MISMATCH: docroot=$a shared=$b - STOP"
```

---

## Step 4 — Combined verification

```bash
echo "===== WEB ROOT ====="
find "$DOCROOT" -maxdepth 3 -name '*.sql' 2>/dev/null | sed 's/^/  .sql: /'
stat -c '  %a %n' "$DOCROOT/.env"
find "$DOCROOT" -maxdepth 1 -name '.env*' -perm /077 -printf '  WORLD-READABLE %p\n' 2>/dev/null
stat -c '  %F %n' "$DOCROOT"

echo "===== SHARED ====="
stat -c '  %a %U:%G %n' "$BASE/shared/.env" "$BASE/shared/storage"
for d in app app/public logs framework/cache/data framework/sessions framework/views; do
  [ -d "$BASE/shared/storage/$d" ] && echo "  ok      storage/$d" || echo "  MISSING storage/$d"
done
[ -d "$BASE/shared/public-uploads" ] && echo "  ok      public-uploads" \
                                    || echo "  ok      public-uploads absent (normal before first release)"

# Every runtime directory must be group-WRITABLE, not merely setgid. The group
# digit is what PHP-FPM actually uses: 5 (r-x) means it cannot write, and
# `stat -c %a` returning 2755 is a real, non-obvious failure mode.
echo "  --- runtime writability ---"
for d in shared/storage shared/storage/app shared/storage/app/public \
         shared/storage/framework shared/storage/framework/cache \
         shared/storage/framework/cache/data shared/storage/framework/sessions \
         shared/storage/framework/views shared/storage/logs shared/public-uploads; do
  p="$BASE/$d"; [ -d "$p" ] || continue
  m=$(stat -c %a "$p"); g=$(stat -c %G "$p")
  printf '  %s  %-40s %s %s\n' "$m" "$d" "$g" \
    "$(case "$m" in
         2??? ) [ "${m:2:1}" = 7 ] || [ "${m:2:1}" = 6 ] && echo ok || echo 'NOT GROUP-WRITABLE' ;;
         2775|2770) echo ok ;;
         *) echo 'NOT SETGID' ;;
       esac)"
done

echo "===== SITE ====="
for u in / /login; do
  printf '  %-8s -> %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://piie.ac.ug$u)"
done

echo "===== DEPLOY GATE (must stay shut) ====="
ls -1 "$BASE/releases" 2>/dev/null | wc -l | sed 's/^/  releases staged: /'
ls -1 "$BASE/backups"/db-*.sql.gz 2>/dev/null | wc -l | sed 's/^/  db backups: /'
```

Expected: no `.sql` under the web root; both `.env` files at **600**; all six storage
directories present and **group-writable**; `/` and `/login` at 200; zero releases staged.

If the writability column shows `NOT GROUP-WRITABLE` or `NOT SETGID`, stop and run:

```bash
bash "$BASE/bin/seed-shared.sh" check      # diagnose
bash "$BASE/bin/seed-shared.sh" apply      # fix
```

A `2775` directory is correct. A `2755` directory is setgid but **not group-writable**,
which means PHP-FPM cannot write sessions, logs, compiled views or uploads — the site
will appear to deploy cleanly and then 500. This was a real bug in `seed-shared.sh`: its
check originally accepted group digit `5` as "group-writable" and skipped its own
`chmod`, so every directory it created was left at `2755`. It is fixed and regression-tested
in `deploy/tests/security-hardening-sandbox.sh`.

---

## Step 5 — Still open after this checklist

Closing the two findings above does **not** make the site ready. These remain:

1. **No backup has ever run against real MySQL.** `backup.sh` is sandbox-tested only
   (`deploy/tests/backup-readiness-sandbox.sh`, with `mysqldump` stubbed). That proves the
   shell logic — gzip validity, the size floor, the completion marker, atomicity,
   checksums, retention ordering, and rejection of empty/truncated/non-dump payloads — but
   **not** that MariaDB accepts the connection or that the dump is complete. Run it once
   manually, `sha256sum -c` the result, and restore it into a scratch database. Treat
   backups as real only after that.
2. **13 queued migrations need a human read** of the server's `migrate --pretend`
   output before activation.
3. **LiteSpeed symlink behaviour is unverified.** `docroot.sh apply` auto-reverts on a
   failed health check, but a staging-only symlink probe would de-risk it first.
4. **`PIIE_DEPLOY_ENABLED` must stay unset** until the cutover is explicitly approved.
5. **`deploy/nginx/piie.conf` is not applied by any script.** It is the intended Contabo
   server block and must be installed by hand. Until it is, the site is still being served
   by whatever is configured now — which is what made the tracked `install.sql`
   downloadable in the first place.
6. **The `shared/` permission model needs one root step** (Step 3). Until `shared/` is
   group-owned by `www-data`, PHP-FPM cannot write sessions, logs or uploads, and the site
   will 500.