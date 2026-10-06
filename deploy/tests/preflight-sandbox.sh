#!/usr/bin/env bash
# Verify the corrected preflight.sh detects an exposed install.sql in the docroot,
# and reports clean when the docroot is tidy. Read-only; touches no real host.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
S="$(mktemp -d)"; trap 'rm -rf "$S"' EXIT
B="$S/dep"; DOC="$S/public_html"; fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }

mkdir -p "$B"/{bin,backups,releases,shared} "$DOC/assets/uploads" "$S/bin"
echo APP_KEY=x > "$B/shared/.env"
printf '#!/bin/sh\nexit 0\n' > "$S/bin/flock"; chmod +x "$S/bin/flock"

export PATH="$S/bin:$PATH" PIIE_BASE="$B" PIIE_PHP="$(command -v php || command -v php8)" \
  PIIE_DOCROOT="$DOC" PIIE_HEALTH_URL="http://sandbox/"
D="$ROOT/deploy/remote"

echo "preflight.sh — exposed-file detection"

# 1. A docroot containing a schema dump must be reported.
printf 'CREATE TABLE users (...);\n' > "$DOC/assets/install.sql"
printf 'DB_PASSWORD=hunter2\n' > "$DOC/.env"
bash "$D/preflight.sh" > "$S/p1.log" 2>&1
t "flags install.sql in the docroot"          "grep -q 'assets/install.sql' $S/p1.log"
t "flags .env in the docroot"                 "grep -q '\.env' $S/p1.log"
# Asserted on BEHAVIOUR (non-zero exit + a FAIL line), not on wording: the message
# text is a human-facing string that has been reworded before, and a test that
# pins it breaks for no useful reason.
t "reports FAIL for exposed files"            "bash $D/preflight.sh >/dev/null 2>&1; [ \$? -ne 0 ] && grep -q 'FAIL' $S/p1.log"

# 2. A tidy docroot must not be flagged.
rm -f "$DOC/assets/install.sql" "$DOC/.env"
printf 'body{}\n' > "$DOC/assets/style.css"
# The .env the app itself needs must exist for preflight's env checks to pass; it
# lives in shared/, not in the document root.
printf 'APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=x\nDB_HOST=db\nDB_DATABASE=piie\nDB_USERNAME=piie\n' > "$B/shared/.env"
bash "$D/preflight.sh" > "$S/p2.log" 2>&1
t "clean docroot reports ok"                  "grep -q 'no .sql/.dump/.env files served' $S/p2.log"
# Scoped to the exposed-files section only. Asserting "no FAIL anywhere" would be
# wrong: this fixture deliberately has no releases/shared layout, so preflight
# legitimately FAILs on those, and that has nothing to do with exposed files.
t "clean docroot reports no FAIL for exposed files" \
  "! sed -n '/Exposed data files/,\$p' $S/p2.log | grep -q 'FAIL'"
t "clean docroot reports no FAIL for public uploads" \
  "! sed -n '/Persistent public uploads/,/Release routing/p' $S/p2.log | grep -q 'FAIL'"

# 3. It stays read-only: the dump is still there afterwards.
printf 'CREATE TABLE x (...);\n' > "$DOC/assets/install.sql"
bash "$D/preflight.sh" > /dev/null 2>&1
t "preflight did NOT delete the dump"         "[ -f $DOC/assets/install.sql ]"

# 4. mktemp is now checked, because docroot.sh needs it.
t "preflight checks for mktemp"               "grep -q 'mktemp' $D/preflight.sh"

echo
[ $fails -eq 0 ] && echo "ALL PREFLIGHT CHECKS PASSED" || echo "$fails PREFLIGHT CHECK(S) FAILED"
exit $((fails>0))