#!/usr/bin/env bash
# Disposable synthetic keys/data only; no production access.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
S=$(mktemp -d "${TMPDIR:-/tmp}/piie-encryption.XXXXXX")
cleanup() { case "$S" in "${TMPDIR:-/tmp}"/piie-encryption.*) rm -rf -- "$S";; esac; }
trap cleanup EXIT
cd "$S"
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=synthetic-inventory-fixture -keyout fixture.key -out fixture.pem >/dev/null 2>&1
export PIIE_INVENTORY_RECIPIENT_CERT="$(cat fixture.pem)"
export PIIE_INVENTORY_RECIPIENT_SHA256="$(openssl x509 -in fixture.pem -outform DER | sha256sum | awk '{print $1}')"
fresh() { RUNNER_TEMP="$S/run-$RANDOM-$RANDOM"; mkdir "$RUNNER_TEMP"; export RUNNER_TEMP; }
SCRIPT="$ROOT/deploy/diagnostics/inventory-evidence.sh"
fresh
bash "$SCRIPT" prepare > prepare.log 2>&1
printf 'synthetic-sensitive-evidence\nsecond line\n' > production-inventory.log
bash "$SCRIPT" encrypt > encrypt.log 2>&1
openssl cms -decrypt -binary -inform DER -in production-inventory.cms -recip fixture.pem -inkey fixture.key -out decrypted.log 2>/dev/null
cmp production-inventory.log decrypted.log
! grep -q 'synthetic-sensitive-evidence' production-inventory.cms
! grep -Eq 'synthetic-sensitive-evidence|PRIVATE KEY' prepare.log encrypt.log
echo 'PASS authenticated encryption round trip preserves exact report without logging plaintext'
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=wrong-fixture -keyout wrong.key -out wrong.pem >/dev/null 2>&1
if openssl cms -decrypt -binary -inform DER -in production-inventory.cms -recip wrong.pem -inkey wrong.key -out wrong.log 2>/dev/null; then echo 'FAIL wrong recipient decrypted evidence'; exit 1; fi
echo 'PASS unrelated private key cannot decrypt evidence'
reject() { fresh; if "$@" > rejected.log 2>&1; then echo 'FAIL invalid recipient accepted'; exit 1; fi; }
reject env PIIE_INVENTORY_RECIPIENT_CERT= bash "$SCRIPT" prepare
reject env PIIE_INVENTORY_RECIPIENT_SHA256="$(printf '%064d' 0)" bash "$SCRIPT" prepare
reject env PIIE_INVENTORY_RECIPIENT_CERT="$(cat fixture.key)" bash "$SCRIPT" prepare
echo 'PASS missing recipient, mismatched fingerprint and private-key input fail before collection'
# The public workflow must never upload plaintext or print SSH/report output.
WORKFLOW="$ROOT/.github/workflows/piie-readonly-inventory.yml"
grep -q 'path: production-inventory.cms' "$WORKFLOW"
! grep -q 'path: production-inventory.log' "$WORKFLOW"
grep -q 'production-inventory.log 2>&1' "$WORKFLOW"
echo 'PASS workflow publishes ciphertext only and captures remote stderr privately'
