#!/usr/bin/env bash
# Runner-only encryption. The recipient PRIVATE key must never enter Actions.
set +x
set -euo pipefail
umask 077
fail() { echo "::error::$1" >&2; exit 1; }
[ "$#" = 1 ] || fail 'Expected prepare or encrypt'
[ -n "${RUNNER_TEMP:-}" ] || fail 'RUNNER_TEMP is missing'
D="$RUNNER_TEMP/piie-inventory-evidence"
case "$1" in
  prepare)
    [ -n "${PIIE_INVENTORY_RECIPIENT_CERT:-}" ] || fail 'Approved public recipient certificate is missing'
    [[ "${PIIE_INVENTORY_RECIPIENT_SHA256:-}" =~ ^[0-9a-f]{64}$ ]] || fail 'Approved certificate SHA256 must be 64 lowercase hex characters'
    mkdir "$D"
    printf '%s\n' "$PIIE_INVENTORY_RECIPIENT_CERT" > "$D/recipient.pem"
    ! grep -q 'PRIVATE KEY' "$D/recipient.pem" || fail 'Only a public certificate is allowed'
    openssl x509 -in "$D/recipient.pem" -noout -checkend 86400 >/dev/null 2>&1 || fail 'Recipient certificate is invalid or expires within 24 hours'
    ACTUAL=$(openssl x509 -in "$D/recipient.pem" -outform DER 2>/dev/null | sha256sum | awk '{print $1}')
    [ "$ACTUAL" = "$PIIE_INVENTORY_RECIPIENT_SHA256" ] || fail 'Recipient certificate fingerprint mismatch'
    # Fail before SSH if this certificate cannot encrypt with the required AEAD.
    printf 'synthetic encryption probe\n' | openssl cms -encrypt -binary -aes-256-gcm -outform DER -out "$D/probe.cms" "$D/recipient.pem" 2>/dev/null || fail 'Recipient cannot encrypt using CMS AES-256-GCM'
    rm -f "$D/probe.cms"
    echo 'Approved inventory encryption recipient validated'
    ;;
  encrypt)
    [ -s "$D/recipient.pem" ] && [ -f production-inventory.log ] || fail 'Prepared recipient or inventory report is missing'
    if ! openssl cms -encrypt -binary -aes-256-gcm -outform DER -in production-inventory.log -out production-inventory.cms "$D/recipient.pem" 2>/dev/null; then
      rm -f production-inventory.cms
      fail 'Inventory encryption failed; no artifact may be published'
    fi
    echo 'Inventory encrypted; plaintext is excluded from artifact upload'
    ;;
  *) fail 'Expected prepare or encrypt' ;;
esac
