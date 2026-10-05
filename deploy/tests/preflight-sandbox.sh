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
t "reports FAIL for exposed files"            "grep -q 'web-servable' $S/p1.log"

# 2. A tidy docroot must not be flagged.
rm -f "$DOC/assets/install.sql" "$DOC/.env"
printf 'body{}\n' > "$DOC/assets/style.css"
bash "$D/preflight.sh" > "$S/p2.log" 2>&1
t "clean docroot reports ok"                  "grep -q 'no .sql/.dump/.env files served' $S/p2.log"
t "clean docroot has no web-servable FAIL"    "! grep -q 'web-servable' $S/p2.log"

# 3. It stays read-only: the dump is still there afterwards.
printf 'CREATE TABLE x (...);\n' > "$DOC/assets/install.sql"
bash "$D/preflight.sh" > /dev/null 2>&1
t "preflight did NOT delete the dump"         "[ -f $DOC/assets/install.sql ]"

# 4. mktemp is now checked, because docroot.sh needs it.
t "preflight checks for mktemp"               "grep -q 'mktemp' $D/preflight.sh"

echo
[ $fails -eq 0 ] && echo "ALL PREFLIGHT CHECKS PASSED" || echo "$fails PREFLIGHT CHECK(S) FAILED"
exit $((fails>0))