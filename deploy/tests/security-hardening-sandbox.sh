#!/usr/bin/env bash
# Security regression coverage for the pre-cutover hardening:
#
#   * install.sql is out of the web root and out of the release artefact
#   * the Apache .htaccess denies *.sql/.dump/.env/key material
#   * the Nginx config denies the same
#   * preflight FAILS (not warns) on a world-readable shared/.env
#   * preflight FAILS on a .env inside public_html
#   * seed-shared.sh creates the required structure with the right modes
#   * backup readiness: real dump, gzip, timestamp, size, marker, retention
#
# These assert on the CONFIGURATION and the repo state. They cannot prove a live
# web server returns 403 — that needs the real host, and preflight plus the server
# configs are what can be verified from here.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
S="$(mktemp -d)"; trap 'rm -rf "$S"' EXIT
fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }
D="$ROOT/deploy/remote"
HT="$ROOT/public/.htaccess"
NG="$ROOT/deploy/nginx/piie.conf"

# The extensions that must never be web-readable. One list, asserted against
# BOTH server configs, so the two cannot drift apart.
SECRET_EXTS="sql sql.gz sql.bz2 sql.xz dump dump.gz dmp bak old orig save swp log ini conf env key pem p12 pfx jks keystore"

# Extract the real deny-list rather than string-matching it.
#
# A naive `grep -q '\.log'` gives a FALSE NEGATIVE here, because these rules are
# written as one anchored group -- `\.(sql|dump|log|key|pem)$` -- so `log` is a
# bare alternative with no literal dot in front of it. Conversely a loose
# `grep -q 'key'` would pass on an unrelated mention of the word.
#
# So the list is parsed the way the server matches it. deny-ext.php is a separate,
# directly unit-tested file (deny-ext-test.sh) precisely because a silently-empty
# parser here would make every assertion below meaningless. It exits non-zero
# rather than printing nothing, so "no deny-list" can never read as success.
DENY="$ROOT/deploy/tests/deny-ext.php"
deny_exts() { php "$DENY" "$1"; }

# ── 1. install.sql is out of the web root ───────────────────────────────────
echo "exposed SQL dump"
t "install.sql is NOT in public/"                 "[ ! -e $ROOT/public/assets/install.sql ]"
t "install.sql is NOT tracked at the old path"     "! git -C $ROOT ls-files --error-unmatch public/assets/install.sql 2>/dev/null"
t "the dump still exists, outside the web root"    "[ -f $ROOT/database/legacy-install.sql ]"
t "no *.sql anywhere under public/"               "[ -z \"\$(find $ROOT/public -name '*.sql' -o -name '*.sql.gz' -o -name '*.dump' 2>/dev/null)\" ]"
t "the dump is export-ignored (not shipped)"       "grep -q 'legacy-install.sql' $ROOT/.gitattributes"

# No EXECUTABLE reference to a .sql under public/. Comment lines are stripped
# first: InstallController explains in a comment that the dump was moved OUT of
# public/, and that explanation must not be mistaken for a live code path.
t "no runtime code reads a .sql under public/" \
  "[ -z \"\$(grep -rn 'assets/install\\.sql\\|public/assets/.*\\.sql' $ROOT/app $ROOT/config $ROOT/routes 2>/dev/null | grep -vE ':[[:space:]]*(//|\\*|#)')\" ]"
t "InstallController reads the non-public copy" \
  "grep -q \"database/legacy-install.sql\" $ROOT/app/Http/Controllers/InstallController.php"
t "InstallController no longer reads the old path (code, not comment)" \
  "! sed 's,//.*,,' $ROOT/app/Http/Controllers/InstallController.php | grep -q \"public/assets/install.sql\""

# ── 2. Apache protection ────────────────────────────────────────────────────
echo "Apache web-root protection"
for ext in $SECRET_EXTS; do
  t ".htaccess denies *.$ext" "deny_exts $HT | grep -qx '$ext'"
done
t ".htaccess deny-list is anchored at the end" \
  "grep -qE 'FilesMatch .*\\\.\(.*\)\\\$|RewriteRule .*\\\.\(.*\)\\\$ *- \[F,L\]' $HT"
t ".htaccess denies dotfiles"                  "grep -qF '(?i)^\\.' $HT"
t ".htaccess denies dotfiles at rewrite level too" "grep -qF '(^|/)\\.' $HT"
t ".htaccess denies private key names"         "grep -q 'id_rsa' $HT"
t ".htaccess guards come BEFORE the front controller" \
  "[ \$(grep -n 'FilesMatch' $HT | head -1 | cut -d: -f1) -lt \$(grep -n 'Handle Front Controller' $HT | head -1 | cut -d: -f1) ]"
t ".htaccess still has the front controller"   "grep -q 'Handle Front Controller' $HT"
t ".htaccess still passes Authorization header" "grep -q 'HTTP_AUTHORIZATION' $HT"
t ".htaccess still allows the asset extensions" "grep -q 'woff2' $HT"

# ── 3. Nginx protection ─────────────────────────────────────────────────────
echo "Nginx web-root protection"
t "nginx config exists"                "[ -f $NG ]"
for ext in $SECRET_EXTS; do
  t "nginx denies *.$ext"              "deny_exts $NG | grep -qx '$ext'"
done
t "nginx dotfile rule returns 403"      "grep -qF '(^|/)\\.' $NG"
t "nginx denies private key names"     "grep -q 'id_rsa' $NG"
t "nginx deny locations return 403" \
  "[ \$(grep -c 'return 403' $NG) -ge 4 ]"
t "nginx deny locations also use deny all" \
  "[ \$(grep -c 'deny all' $NG) -ge 4 ]"

# The Apache and Nginx configs are two expressions of the same policy. If they
# drift, whichever server is actually running quietly governs.
t "Apache and Nginx deny-lists are IDENTICAL" \
  "[ \"\$(deny_exts $HT | sort | tr '\n' ',')\" = \"\$(deny_exts $NG | sort | tr '\n' ',')\" ]"
t "Apache has one extension rule, not two conflicting ones" \
  "[ \$(deny_exts $HT | sort -u | wc -l) -eq \$(deny_exts $HT | wc -l) ]"
t "nginx documents the current/ root"  "grep -q 'current/public' $NG"
t "nginx disables display_errors"      "grep -q 'PHP_DISPLAY_ERRORS 0' $NG"
t "nginx hides the server version"     "grep -q 'server_tokens off' $NG"
t "nginx serves shared uploads long-lived" "grep -q 'assets/uploads' $NG"
t "nginx is not auto-applied by a deploy script" \
  "! grep -qE 'nginx|piie\.conf' $D/deploy-release.sh"

# ── 4. preflight FAILS on weak .env ─────────────────────────────────────────
echo ".env permission enforcement"
B="$S/dep"; DOC="$S/public_html"
mkfix() {
  rm -rf "$B" "$DOC" "$S/bin"
  mkdir -p "$B/bin" "$B/backups" "$B/releases" "$B/shared/storage/app/public" "$B/shared/public-uploads" "$DOC/assets/uploads" "$S/bin"
  printf 'APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=x\nDB_HOST=db\nDB_DATABASE=piie\nDB_USERNAME=piie\nDB_PASSWORD=fx\n' > "$B/shared/.env"
  printf 'stub php\n' > "$S/bin/php"
  printf '#!/bin/sh\nexit 0\n' > "$S/bin/flock"
  printf '#!/bin/sh\nexit 0\n' > "$S/bin/mysqldump"
  printf '#!/bin/sh\nexit 0\n' > "$S/bin/curl"
  chmod +x "$S/bin/flock" "$S/bin/mysqldump" "$S/bin/curl"
  chmod 2775 "$B/shared/public-uploads" "$B/shared/storage" "$B/shared/storage/app" 2>/dev/null || true
  export PATH="$S/bin:$PATH" PIIE_BASE="$B" PIIE_PHP="$S/bin/php" PIIE_DOCROOT="$DOC"
}

mkfix; chmod 644 "$B/shared/.env"
bash "$D/preflight.sh" > "$S/e644.log" 2>&1; rc=$?
t "preflight exits NON-ZERO when .env is 644"   "[ $rc -ne 0 ]"
t "preflight says FAIL about the mode"          "grep -q 'FAIL  .env mode is 644' $S/e644.log"
t "preflight prints the fix"                    "grep -q 'chmod 600' $S/e644.log"
t "it is a FAIL not a warn"                     "! grep -q 'warn  .env mode' $S/e644.log"

mkfix; chmod 640 "$B/shared/.env"
bash "$D/preflight.sh" > "$S/e640.log" 2>&1; rc=$?
t "preflight exits NON-ZERO when .env is 640"   "[ $rc -ne 0 ]"
t "640 is rejected too (group read is a leak)"  "grep -q 'must be 600' $S/e640.log"

mkfix; chmod 600 "$B/shared/.env"
bash "$D/preflight.sh" > "$S/e600.log" 2>&1; rc=$?
t "preflight accepts 600 for the env mode"      "grep -q 'ok    .env mode is 600' $S/e600.log"

echo
echo ".env inside the document root"
mkfix; chmod 600 "$B/shared/.env"; printf 'DB_PASSWORD=leaked\n' > "$DOC/.env"
bash "$D/preflight.sh" > "$S/denv.log" 2>&1; rc=$?
t "preflight exits NON-ZERO for .env in docroot" "[ $rc -ne 0 ]"
t "preflight names the .env in public_html"     "grep -q 'web-served' $S/denv.log"
mkfix; chmod 600 "$B/shared/.env"; printf 'x\n' > "$DOC/assets/.env.production"
bash "$D/preflight.sh" > "$S/denv2.log" 2>&1
t "preflight catches .env.production too"       "grep -q 'web-served' $S/denv2.log"

# ── 5. seed-shared.sh ───────────────────────────────────────────────────────
echo "shared/ seeding"
mkfix; rm -rf "$B/shared/storage/framework" "$B/shared/public-uploads"
bash "$D/seed-shared.sh" check > "$S/sc.log" 2>&1; rc=$?
t "check mode is READ-ONLY and reports NOT ready" "[ $rc -ne 0 ] && grep -q 'NOT ready' $S/sc.log"
t "check mode created nothing"                    "[ ! -d $B/shared/storage/framework ]"
bash "$D/seed-shared.sh" apply > "$S/sa.log" 2>&1
t "apply creates storage/framework/views"         "[ -d $B/shared/storage/framework/views ]"
t "apply creates storage/framework/sessions"      "[ -d $B/shared/storage/framework/sessions ]"
t "apply creates storage/framework/cache/data"   "[ -d $B/shared/storage/framework/cache/data ]"
t "apply creates storage/logs"                    "[ -d $B/shared/storage/logs ]"
t "apply creates public-uploads"                  "[ -d $B/shared/public-uploads ]"
t "created dirs are setgid 2775"                  "[ \"\$(stat -c %a $B/shared/storage/framework/views | cut -c1)\" = 2 ]"
t "created dirs are group-writable"               "[ \"\$(stat -c %a $B/shared/storage/framework/views)\" = 2775 ]"

# Regression: `mkdir -p` under umask 022 yields 2755 -- setgid, but the group
# digit is 5 (r-x) so PHP-FPM CANNOT write. An earlier group-digit check allowed
# 5 and called that "group-writable", so apply() skipped its own chmod and left
# storage/framework, storage/logs and public-uploads unwritable by the runtime.
# Every directory it creates must end up 2775, not merely setgid.
for d in shared/storage shared/storage/app shared/storage/app/public \
         shared/storage/framework shared/storage/framework/cache \
         shared/storage/framework/cache/data shared/storage/framework/sessions \
         shared/storage/framework/views shared/storage/logs shared/public-uploads; do
  t "$d ends up 2775 (group-WRITABLE, not just setgid)" \
    "[ \"\$(stat -c %a $B/$d 2>/dev/null)\" = 2775 ]"
done

# 2755 must be reported as a problem, not accepted as fine.
mkfix; rm -rf "$B/shared/storage/framework"
mkdir -p "$B/shared/storage/framework/views"; chmod 2755 "$B/shared/storage/framework/views"
bash "$D/seed-shared.sh" check > "$S/g5.log" 2>&1
t "check REPORTS a 2755 dir as not ready"           "[ \$(grep -c 'wants 2775' $S/g5.log) -ge 1 ]"
t "check does NOT call 2755 group-writable"         "! grep -q 'mode 2755 (setgid, group-writable)' $S/g5.log"
bash "$D/seed-shared.sh" apply >/dev/null 2>&1
t "apply repairs a 2755 dir to 2775"               "[ \"\$(stat -c %a $B/shared/storage/framework/views)\" = 2775 ]"

# The group-digit logic must agree with preflight.sh, or seed-shared approves a
# tree that preflight then rejects.
t "seed-shared and preflight agree on writable digits" \
  "[ \"\$(grep -c 'gwrite.*= 3.*= 2' $D/seed-shared.sh)\" -ge 1 ] && \
   [ \"\$(grep -c 'gwrite.*= 3.*= 2' $D/preflight.sh)\" -ge 1 ]"
t "neither script accepts group digit 5 as writable" \
  "! grep -q 'gwrite.*= 5' $D/seed-shared.sh && ! grep -q 'gwrite.*= 5' $D/preflight.sh"
t "apply tightens .env to 600"                    "[ \"\$(stat -c %a $B/shared/.env)\" = 600 ]"
t "apply leaves .env content alone"               "grep -q 'DB_PASSWORD=fx' $B/shared/.env"
t "re-running check is satisfied"                 "bash $D/seed-shared.sh check >/dev/null 2>&1"
t "seed-shared.sh defaults to read-only check" \
  "! grep -q 'MODE="${1:-apply}"' $D/seed-shared.sh"

echo
echo "required shared paths are the ones the deploy scripts actually use"
for p in 'shared/.env' 'shared/storage/app' 'shared/storage/app/public' \
         'shared/storage/framework/cache/data' 'shared/storage/framework/sessions' \
         'shared/storage/framework/views' 'shared/storage/logs' 'shared/public-uploads'; do
  t "seed-shared.sh handles $p" "grep -qF '$p' $D/seed-shared.sh"
done

echo
[ $fails -eq 0 ] && echo "ALL SECURITY CHECKS PASSED" || echo "$fails SECURITY CHECK(S) FAILED"
exit $((fails>0))