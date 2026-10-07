#!/usr/bin/env bash
# Unit tests for deny-ext.php -- the security-assertion parser itself.
#
# A security test is only as trustworthy as its parser. If deny-ext.php silently
# returned nothing, every ".htaccess denies *.x" assertion in
# security-hardening-sandbox.sh would vacuously pass or fail. So the parser is
# tested directly, including the two false-negative traps that motivated it.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
P="$ROOT/deploy/tests/deny-ext.php"
S="$(mktemp -d)"; trap 'rm -rf "$S"' EXIT
fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }

echo "deny-ext.php"
t "syntax is valid" "php -l $P >/dev/null 2>&1"

# ── the bare-alternative trap ───────────────────────────────────────────────
# This is the exact shape that made `grep -q '\.log'` fail: `log` has no dot
# before it. The parser must still report it.
cat > "$S/a.conf" <<'EOF'
<FilesMatch "(?i)\.(sql|sql\.gz|dump|log|key|pem)$">
EOF
t "finds a bare alternative (log)"  "php $P $S/a.conf | grep -qx 'log'"
t "finds a bare alternative (key)"  "php $P $S/a.conf | grep -qx 'key'"
t "finds a plain member (dump)"     "php $P $S/a.conf | grep -qx 'dump'"
t "un-escapes a compound ext"       "[ \"\$(php $P $S/a.conf | grep -x 'sql.gz')\" = 'sql.gz' ]"
t "does NOT emit a partial 'sql' twice for sql.gz" \
  "[ \"\$(php $P $S/a.conf | grep -cx 'sql')\" = 1 ]"
t "emits exactly 6 extensions"      "[ \"\$(php $P $S/a.conf | wc -l | tr -d ' ')\" = 6 ]"

# ── nginx location form ─────────────────────────────────────────────────────
cat > "$S/n.conf" <<'EOF'
    location ~* \.(sql|sql\.gz|dump|dmp|log|key|pem)$ {
EOF
t "parses the nginx location form"  "[ \"\$(php $P $S/n.conf | wc -l | tr -d ' ')\" = 7 ]"

# ── dedupe + ordering ───────────────────────────────────────────────────────
cat > "$S/d.conf" <<'EOF'
RewriteRule "(?i)\.(sql|dump)$" - [F,L]
<FilesMatch "(?i)\.(sql|dump|key)$">
EOF
t "dedupes across two rules"         "[ \"\$(php $P $S/d.conf | grep -cx 'sql')\" = 1 ]"
t "output is sorted"                 "[ \"\$(php $P $S/d.conf | tr '\n' ',')\" = 'dump,key,sql,' ]"

# ── negative cases: must NOT produce a misleading success ───────────────────
cat > "$S/empty.conf" <<'EOF'
# a config with no extension deny-list at all
location / { try_files $uri /index.php; }
EOF
php "$P" "$S/empty.conf" >/dev/null 2>&1
t "empty deny-list EXITS NON-ZERO (never a silent pass)" "[ $? -ne 0 ]"

cat > "$S/unanchored.conf" <<'EOF'
<FilesMatch "\.(sql|dump)">
EOF
php "$P" "$S/unanchored.conf" >/dev/null 2>&1
t "an UNANCHORED pattern is rejected (not a real deny)" "[ $? -ne 0 ]"

cat > "$S/keyword.conf" <<'EOF'
# a passing mention of the words, with no deny-list
# this config also denies monkey and payload for good measure
EOF
php "$P" "$S/keyword.conf" >/dev/null 2>&1
t "a keyword mention is NOT extracted" "[ $? -ne 0 ]"

t "missing file exits non-zero"      "[ ! -e $S/nope.conf ] && ! php $P $S/nope.conf >/dev/null 2>&1"
t "no args exits 2 with usage"       "[ \$(php $P >/dev/null 2>&1; echo \$?) = 2 ]"

echo
[ $fails -eq 0 ] && echo "ALL PARSER CHECKS PASSED" || echo "$fails PARSER CHECK(S) FAILED"
exit $((fails>0))
