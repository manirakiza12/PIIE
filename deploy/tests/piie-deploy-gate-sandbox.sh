#!/usr/bin/env bash
# CI-REQUIRES-ROOT: isolated synthetic filesystem; never calls Docker or production.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
[ "$(id -u)" = 0 ] || { echo 'Run in an isolated Linux root environment' >&2; exit 1; }
S=$(mktemp -d)
cleanup() { local status=$?; if [ "$status" != 0 ]; then cat "$S/last.log" 2>/dev/null || true; fi; rm -rf -- "$S"; }
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
printf '%s|%s|%s|%s|%s\n' "${0##*/}" "$*" "${EXPECTED_RELEASE_SHA:-}" "${PIIE_CODE_ONLY:-}" "${ALLOW_DESTRUCTIVE_MIGRATIONS:-}"
SH
done
# Production uses the real mount/identity guard. This isolated gate fixture uses
# its real ownership checks, with only the fixture's mount check stubbed locally.
sed "s|/home/piie/deployments/piie|$S/base|g" "$ROOT/deploy/remote/deployment-guard.sh" > "$S/lib/bin/deployment-guard.sh"
printf '\nsecure_layout() { :; }\n' >> "$S/lib/bin/deployment-guard.sh"
printf '# fixture helper\n' > "$S/lib/bin/helper.py"
chmod 755 "$S" "$S/lib" "$S/lib/bin" "$S/lib/bin/"*
SHA=abcdef123456aaaaaaaaaaaaaaaaaaaaaaaaaaaa
OLD=f23cff88f15a35fd3d7606df929c26cd951ee288
RID=20261109-123456-abcdef123456
SUM=$(printf 'b%.0s' {1..64})
ART="$S/base/incoming/piie-release-abcdef123456.tar.gz"
touch "$ART"
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
mkdir -p "$S/base/.secure/private/authorized"
chmod 700 "$S/base/.secure/private"
RECEIPT="$S/base/.secure/private/authorized/$SHA.approved"
printf '%s\n' piie-deployment-approval-v1 "$SHA" "$SUM" "$OLD" f23cff8 1 0 > "$RECEIPT"
chmod 600 "$RECEIPT"
gate deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0 |
  grep "|$SHA|1|0"
reject deploy-activate "$RID" "$ART" "$(printf 'c%.0s' {1..64})" "$SHA" "$OLD" f23cff8 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 0 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 1
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$SHA" f23cff8 1 0
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" another-release 1 0
chmod 644 "$RECEIPT"
reject deploy-activate "$RID" "$ART" "$SUM" "$SHA" "$OLD" f23cff8 1 0
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
