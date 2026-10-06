#!/usr/bin/env bash
# Proves preflight.sh's new persistent-public-uploads checks, including the case
# that matters most: an active release whose uploads are a real directory would
# silently lose every runtime upload on the next release switch.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
S="$(mktemp -d)"; trap 'rm -rf "$S"' EXIT
B="$S/dep"; DOC="$S/public_html"; fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }

D="$ROOT/deploy/remote"

# A fixture with everything preflight likes, so the ONLY failures under test are
# the uploads ones.
setup() {
  rm -rf "$B" "$DOC" "$S/bin"
  mkdir -p "$B"/{bin,backups,releases,shared/storage/app/public} "$DOC/assets/uploads" "$S/bin"
  printf 'APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=x\nDB_HOST=db\nDB_DATABASE=piie\nDB_USERNAME=piie\n' > "$B/shared/.env"
  printf 'stub php\n' > "$S/bin/php"
  printf '#!/bin/sh\nexit 0\n' > "$S/bin/flock"
  printf '#!/bin/sh\nexit 0\n' > "$S/bin/mysqldump"
  printf '#!/bin/sh\nexit 0\n' > "$S/bin/curl"
  chmod +x "$S/bin/flock" "$S/bin/mysqldump" "$S/bin/curl"
  export PATH="$S/bin:$PATH" PIIE_BASE="$B" PIIE_PHP="$S/bin/php" PIIE_DOCROOT="$DOC"
}

uploads_section() { sed -n '/Persistent public uploads/,/Release routing/p' "$1"; }

echo "preflight.sh — persistent public uploads"

# 1. No current release: a missing shared/public-uploads is a WARNING, not a
#    failure, so preflight still runs before the first activation.
setup
bash "$D/preflight.sh" > "$S/u1.log" 2>&1
t "missing shared/public-uploads before first activation is only a warning" \
  "grep -q 'warn  shared/public-uploads missing' $S/u1.log && ! uploads_section $S/u1.log | grep -q FAIL"

# 2. Shared directory present, no current release: ok.
setup
mkdir -p "$B/shared/public-uploads"
bash "$D/preflight.sh" > "$S/u2.log" 2>&1
t "existing shared/public-uploads reported ok" \
  "uploads_section $S/u2.log | grep -q 'ok    shared/public-uploads exists'"

# 3. Active release whose uploads is a proper symlink: ok.
#    The shared directory is created WITH the production permission model
#    (group-writable + setgid, OWNED BY THE RUNTIME GROUP) so this case tests
#    symlink resolution rather than permissions.
#
#    PIIE_RUNTIME_GROUP is pointed at a group this sandbox can actually create.
#    The container has no `www-data` group, and leaving the real name in place would
#    make every assertion fail for a reason unrelated to the behaviour under test.
#    The production default is asserted separately below, and the integration
#    sandbox exercises the real www-data default.
RTG="$(id -gn)"
export PIIE_RUNTIME_GROUP="$RTG"
setup
mkdir -p "$B/releases/20261006-000000-abcdef1/public/assets" "$B/shared/public-uploads"
chmod 2775 "$B/shared/public-uploads"
chgrp "$RTG" "$B/shared/public-uploads" 2>/dev/null || true
ln -s "$B/releases/20261006-000000-abcdef1" "$B/current"
ln -s "$B/shared/public-uploads" "$B/current/public/assets/uploads"
bash "$D/preflight.sh" > "$S/u3.log" 2>&1
t "active release with a correct uploads symlink passes" \
  "uploads_section $S/u3.log | grep -q 'ok    current uploads -> shared/public-uploads'"
t "correct-symlink fixture is group-writable" \
  "! uploads_section $S/u3.log | grep -q 'not group-writable'"
t "correct-symlink fixture has setgid" \
  "! uploads_section $S/u3.log | grep -q 'setgid bit is NOT set'"
t "correct-symlink fixture has the right group" \
  "! uploads_section $S/u3.log | grep -q \"expected '\""
t "active release with correct symlink reports no FAIL" \
  "! uploads_section $S/u3.log | grep -q 'FAIL'"
t "production default runtime group is www-data" \
  "grep -q 'PIIE_RUNTIME_GROUP:-www-data' $D/preflight.sh"

# 3b. Permissions that would silently break runtime uploads are reported.
setup
mkdir -p "$B/releases/20261006-000000-abcdef1/public/assets" "$B/shared/public-uploads"
chmod 755 "$B/shared/public-uploads"
ln -s "$B/releases/20261006-000000-abcdef1" "$B/current"
ln -s "$B/shared/public-uploads" "$B/current/public/assets/uploads"
bash "$D/preflight.sh" > "$S/u3b.log" 2>&1
t "mode 755 without setgid is FLAGGED" \
  "uploads_section $S/u3b.log | grep -q 'not group-writable' && uploads_section $S/u3b.log | grep -q 'setgid bit is NOT set'"
t "preflight exits non-zero for unusable permissions" \
  "bash $D/preflight.sh >/dev/null 2>&1; [ \$? -ne 0 ]"
t "wrong runtime group is reported, not glossed over" \
  "uploads_section $S/u3b.log | grep -q 'expected'"

# 4. THE BUG THIS PREVENTS: uploads is a real directory in the active release.
setup
mkdir -p "$B/shared/public-uploads" "$B/releases/20261006-000000-abcdef1/public/assets/uploads"
ln -s "$B/releases/20261006-000000-abcdef1" "$B/current"
printf 'runtime\n' > "$B/current/public/assets/uploads/whatever.pdf"
bash "$D/preflight.sh" > "$S/u4.log" 2>&1
t "real uploads directory in the active release is FLAGGED" \
  "uploads_section $S/u4.log | grep -q 'runtime uploads would be lost'"
t "preflight exits non-zero for a real uploads directory" \
  "bash $D/preflight.sh >/dev/null 2>&1; [ \$? -ne 0 ]"

# 5. Symlink pointing at the WRONG place is caught.
#    NOTE: do not mkdir the uploads path here. `mkdir -p .../assets/uploads`
#    creates a real directory, and a following `ln -s` then nests the symlink
#    INSIDE it, so the release would not be a symlink at all. Create only the
#    parent, then replace the path with the link.
setup
mkdir -p "$B/shared/public-uploads" "$B/elsewhere" "$B/releases/20261006-000000-abcdef1/public/assets"
ln -s "$B/releases/20261006-000000-abcdef1" "$B/current"
ln -s "$B/elsewhere" "$B/current/public/assets/uploads"
t "fixture really is a symlink (guard against a silently wrong test)" \
  "[ -L $B/current/public/assets/uploads ]"
bash "$D/preflight.sh" > "$S/u5.log" 2>&1
t "uploads symlink to the WRONG directory is flagged" \
  "uploads_section $S/u5.log | grep -q 'points at'"

# 6. A shared/public-uploads that is itself a symlink is refused outright.
setup
mkdir -p "$B/releases/20261006-000000-abcdef1/public/assets" "$B/elsewhere"
ln -s "$B/releases/20261006-000000-abcdef1" "$B/current"
ln -s "$B/elsewhere" "$B/shared/public-uploads"
bash "$D/preflight.sh" > "$S/u6.log" 2>&1
t "shared/public-uploads being a symlink is FLAGGED" \
  "uploads_section $S/u6.log | grep -q 'must be a real directory'"

echo
[ $fails -eq 0 ] && echo "ALL PREFLIGHT UPLOADS CHECKS PASSED" || echo "$fails PREFLIGHT UPLOAD(S) CHECK FAILED"
exit $((fails>0))
