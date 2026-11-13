#!/usr/bin/env bash
# CI-REQUIRES-ROOT: isolated synthetic filesystem; never calls Docker or production.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
[ "$(id -u)" = 0 ] || { echo 'Run in an isolated Linux root environment' >&2; exit 1; }
S=$(mktemp -d)
cleanup() { local status=$?; if [ "$status" != 0 ]; then cat "$S/last.log" "$S/rejected.log" 2>/dev/null || true; fi; rm -rf -- "$S"; }
trap cleanup EXIT
mkdir -p "$S/lib/bin" "$S/base/incoming"
# Rewrite fixed paths only in the test copy. Production has no test-mode switches.
sed "s|/usr/local/lib/piie|$S/lib|g; s|/home/piie/deployments/piie|$S/base|g; s|/var/log/piie-deploy-gate.log|$S/gate.log|g" \
  "$ROOT/deploy/remote/piie-deploy-gate.sh" > "$S/gate.sh"
for helper in container-preflight.sh container-deploy.sh container-rollback.sh container-app.sh; do
  cat > "$S/lib/bin/$helper" <<'SH'
#!/usr/bin/env bash
set -eu
[ -z "${CALLER_POISON:-}" ]
fail() { echo "ERROR: $*" >&2; exit 1; }
printf '%s|%s|%s|%s|%s\n' "${0##*/}" "$*" "${EXPECTED_RELEASE_SHA:-}" "${PIIE_CODE_ONLY:-}" "${ALLOW_DESTRUCTIVE_MIGRATIONS:-}"
SH
done
# Production uses the real mount/identity guard. This isolated gate fixture uses
# its real ownership checks, with only the fixture's mount check stubbed locally.
sed "s|/home/piie/deployments/piie|$S/base|g" "$ROOT/deploy/remote/deployment-guard.sh" > "$S/lib/bin/deployment-guard.sh"
printf '\nsecure_layout() { :; }\n' >> "$S/lib/bin/deployment-guard.sh"
printf '\nverify_runtime_mount() { :; }\n' >> "$S/lib/bin/deployment-guard.sh"
cp "$ROOT/deploy/remote/secure-handoff.sh" "$S/lib/bin/secure-handoff.sh"
# Exercise the real helper's locked authorization boundary in the gate fixture;
# Docker/application execution remains mocked after that boundary.
sed '/^printf /,$d' "$S/lib/bin/container-deploy.sh" > "$S/helper-prefix"
cat >> "$S/helper-prefix" <<'SH'
if [ "$4" = activate ]; then
  source "$(dirname "$0")/secure-handoff.sh"
  deployment_lock
  SUM=$3
  authorize_activation
fi
printf '%s|%s|%s|%s|%s\n' "${0##*/}" "$*" "${EXPECTED_RELEASE_SHA:-}" "${PIIE_CODE_ONLY:-}" "${ALLOW_DESTRUCTIVE_MIGRATIONS:-}"
SH
cp "$S/helper-prefix" "$S/lib/bin/container-deploy.sh"
printf '# fixture helper\n' > "$S/lib/bin/helper.py"
chmod 755 "$S" "$S/lib" "$S/lib/bin" "$S/lib/bin/"*
SHA=abcdef123456aaaaaaaaaaaaaaaaaaaaaaaaaaaa
OLD=f23cff88f15a35fd3d7606df929c26cd951ee288
RID=20261109-123456-abcdef123456
SUM=$(printf 'b%.0s' {1..64})
ART="$S/base/incoming/piie-release-abcdef123456.tar.gz"
touch "$ART"
mkdir -p "$S/base/.secure/releases/f23cff8" "$S/base/.secure/private"
printf '%s\n' "$OLD" > "$S/base/.secure/releases/f23cff8/RELEASE_SHA"
printf '%s\n%s\n' "$OLD" "$SUM" > "$S/base/.secure/private/f23cff8.approved"
ln -s .secure/releases/f23cff8 "$S/base/current"
gate() { bash "$S/gate.sh" "$@" | tee "$S/last.log"; }
reject() {
  if gate "$@" > "$S/rejected.log" 2>&1; then
    cat "$S/rejected.log"; echo "FAIL: accepted $*"; exit 1
  fi
}
gate preflight | grep 'preflight: ok'
gate deploy-stage "$RID" "$ART" "$SUM" "$SHA" | grep "|$SHA||"
# A caller-provided SHA/checksum is an integrity assertion, not release approval.
# This must fail before any deployment helper runs when no root approval exists.
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" '' '' 1 0
mkdir -p "$S/base/.secure/private/authorized"
chmod 700 "$S/base/.secure/private"
RECEIPT="$S/base/.secure/private/authorized/$SHA.approved"
printf '%s\n' piie-deployment-approval-v1 "$SHA" "$SUM" "$OLD" f23cff8 1 0 > "$RECEIPT"
chmod 600 "$RECEIPT"
gate deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0 |
  grep "|$SHA|1|0"
for baseline in "$OLD" auto ''; do
  for rollback in f23cff8 auto ''; do
    gate deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$baseline" "$rollback" 1 0 |
      grep "DEPLOYED=$OLD ROLLBACK=f23cff8"
  done
done
reject deploy-activate "$RID" "$ART" "$(printf 'c%.0s' {1..64})" "$SHA" auto auto 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto unrelated 1 0
# A new valid protected production baseline must invalidate the old receipt.
mkdir "$S/base/.secure/releases/changed"
printf '%s\n' "$SHA" > "$S/base/.secure/releases/changed/RELEASE_SHA"
printf '%s\n%s\n' "$SHA" "$SUM" > "$S/base/.secure/private/changed.approved"
ln -sfn .secure/releases/changed "$S/base/current"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0
ln -sfn .secure/releases/../changed "$S/base/current"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
ln -sfn .secure/releases/f23cff8 "$S/base/current"
# Simulate a baseline change between gate precheck and the helper's lock.
cp "$S/lib/bin/container-deploy.sh" "$S/helper.good"
sed '/  deployment_lock/a\  ln -sfn .secure/releases/changed "$BASE/current"' "$S/helper.good" > "$S/lib/bin/container-deploy.sh"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
grep -q 'Live baseline changed' "$S/rejected.log"
cp "$S/helper.good" "$S/lib/bin/container-deploy.sh"
ln -sfn .secure/releases/f23cff8 "$S/base/current"
# Receipt revocation between precheck and locked helper must also fail.
sed '/  deployment_lock/a\  rm -- "$BASE/.secure/private/authorized/$EXPECTED_RELEASE_SHA.approved"' "$S/helper.good" > "$S/lib/bin/container-deploy.sh"
cp "$RECEIPT" "$S/receipt.saved"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
grep -q 'Unsafe file:' "$S/rejected.log"
cp "$S/receipt.saved" "$RECEIPT"
cp "$S/helper.good" "$S/lib/bin/container-deploy.sh"
chmod 600 "$RECEIPT"
# Exact bytes: malformed/trailing/missing lines cannot be interpreted as approval.
cp "$RECEIPT" "$S/receipt.good"
printf 'extra\n' >> "$RECEIPT"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
printf 'piie-deployment-approval-v1\n' > "$RECEIPT"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
cp "$S/receipt.good" "$RECEIPT"
truncate -s -1 "$RECEIPT"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
cp "$S/receipt.good" "$RECEIPT"
chown nobody "$RECEIPT"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
chown root "$RECEIPT"
chmod 600 "$RECEIPT"
echo 'PASS: explicit, auto, empty and mixed inputs require the exact receipt for protected live identities'
reject deploy-activate "$RID" "$ART" "$(printf 'c%.0s' {1..64})" "$SHA" "$OLD" f23cff8 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 0 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 1
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$SHA" f23cff8 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" another-release 1 0
chmod 644 "$RECEIPT"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" auto auto 1 0
chmod 600 "$RECEIPT"
mv "$RECEIPT" "$RECEIPT.saved"
ln -s "$RECEIPT.saved" "$RECEIPT"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0
rm "$RECEIPT"
ln "$RECEIPT.saved" "$RECEIPT"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0
rm "$RECEIPT"
mv "$RECEIPT.saved" "$RECEIPT"
chmod 777 "$S/base/.secure/private/authorized"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0
chmod 755 "$S/base/.secure/private/authorized"
echo 'PASS: independent approval required; altered digest, baseline, rollback, flags and unsafe receipts refused'
gate rollback "$SHA" f23cff8 "$OLD" | grep 'rollback: ok'
gate app-run /srv/piie/current php artisan course-content:audit | grep 'app-run: ok'
echo 'PASS: all fixed operations and asymmetric activation flags'
CALLER_POISON=must-not-propagate gate preflight | grep 'preflight: ok'
PIIE_DEPLOY_GATE_BASE=/untrusted PIIE_DEPLOY_GATE_LIB=/untrusted \
  PIIE_DEPLOY_GATE_LOG=/untrusted PIIE_DEPLOY_GATE_TEST_MODE=1 \
  gate preflight | grep 'preflight: ok'
echo 'PASS: caller environment cannot redirect helpers, paths, logs or child execution'
reject invalid-subcommand
reject preflight extra
reject deploy-stage "$RID" "$ART" "$SUM"
reject deploy-stage bad-rid "$ART" "$SUM" "$SHA"
reject deploy-stage "$RID" "$ART" "$SUM" invalid "$SHA"
reject deploy-stage "$RID" /tmp/outside.tar.gz "$SUM" "$SHA"
reject deploy-stage "$RID" "$ART" "$SUM" "$OLD"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 2 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" ../escape 0 0
reject rollback "$SHA" ../escape "$OLD"
reject app-run /srv/piie/current /bin/bash -c id
reject app-run /srv/piie/current php -r 'echo 1;'
reject app-run /srv/piie/current php artisan migrate:fresh
reject app-run /srv/piie/current php artisan course-content:audit extra
reject app-run /untrusted php artisan course-content:audit
echo 'PASS: malformed identities, paths, shell execution and unapproved app commands refused'
chmod 777 "$S/lib/bin/container-preflight.sh"
reject preflight
chmod 755 "$S/lib/bin/container-preflight.sh"
mv "$S/lib/bin/container-preflight.sh" "$S/helper"
ln -s "$S/helper" "$S/lib/bin/container-preflight.sh"
reject preflight
rm "$S/lib/bin/container-preflight.sh"
mv "$S/helper" "$S/lib/bin/container-preflight.sh"
chmod 777 "$S/lib/bin"
reject preflight
chmod 755 "$S/lib/bin"
if runuser -u nobody -- env PIIE_DEPLOY_GATE_TEST_MODE=1 bash "$S/gate.sh" preflight > "$S/nonroot.log" 2>&1; then
  echo 'FAIL: non-root bypass accepted'; exit 1
fi
grep 'root execution required' "$S/nonroot.log"
echo 'PASS: unsafe installation and non-root test-mode bypass refused'
