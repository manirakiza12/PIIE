#!/usr/bin/env bash
# Static contract tests for the production Docker networking and SMTP settings.
#
# Docker is not required. These tests enforce the configuration that makes the
# Mailcow attachment survive container recreation and that keeps the existing
# public/private networks intact.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSE="$ROOT/deploy/container/compose.production.yaml"
ENVFILE="$ROOT/deploy/container/production.env.example"
DOC="$ROOT/docs/phase0/SMTP-NETWORK-CONTRACT.md"
D="$ROOT/deploy/remote"
fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }

# Extract one top-level service block (until the next two-space service key).
service_block() {
  awk -v name="  $1:" '
    $0 == name {inside=1; print; next}
    inside && /^  [A-Za-z0-9_.-]+:[[:space:]]*$/ {exit}
    inside {print}
  ' "$COMPOSE"
}

echo "production compose exists"
t "compose file exists"                 "[ -f $COMPOSE ]"
t "production env example exists"       "[ -f $ENVFILE ]"
t "SMTP contract document exists"       "[ -f $DOC ]"

echo
echo "app keeps all required networks"
APP="$(service_block app)"
DB="$(service_block db)"
t "app service block found"             "[ -n \"\$APP\" ]"
t "db service block found"              "[ -n \"\$DB\" ]"
t "app joins public"                    "printf '%s\\n' \"\$APP\" | grep -qx '      - public'"
t "app joins private"                   "printf '%s\\n' \"\$APP\" | grep -qx '      - private'"
t "app joins mailcow"                   "printf '%s\\n' \"\$APP\" | grep -qx '      - mailcow'"
t "db stays on private only"            "printf '%s\\n' \"\$DB\" | grep -qx '      - private' && ! printf '%s\\n' \"\$DB\" | grep -qE '^      - (public|mailcow)$'"

echo
echo "existing networks are preserved, not recreated"
t "public is external n8n_default" \
  "grep -A2 -x '  public:' $COMPOSE | grep -qx '    external: true' && grep -A2 -x '  public:' $COMPOSE | grep -qx '    name: n8n_default'"
t "private keeps its existing Docker name" \
  "grep -A2 -x '  private:' $COMPOSE | grep -qx '    name: piie-deploy_private'"
t "private remains internal" \
  "grep -A2 -x '  private:' $COMPOSE | grep -qx '    internal: true'"
t "mailcow is external" \
  "grep -A2 -x '  mailcow:' $COMPOSE | grep -qx '    external: true'"
t "mailcow uses the existing Mailcow network" \
  "grep -A2 -x '  mailcow:' $COMPOSE | grep -qx '    name: mailcowdockerized_mailcow-network'"
t "compose does not rename n8n_default" \
  "! grep -qE '^[[:space:]]+name:[[:space:]]+(piie|piie-deploy)_public' $COMPOSE"

echo
echo "deployment never relies on a temporary network attachment"
t "no docker network connect in deploy scripts" \
  "! grep -RqsE '(^|[^[:alnum:]_])docker[[:space:]]+network[[:space:]]+connect([[:space:]]|$)' $D"
t "no docker network disconnect in deploy scripts" \
  "! grep -RqsE '(^|[^[:alnum:]_])docker[[:space:]]+network[[:space:]]+disconnect([[:space:]]|$)' $D"
t "no network rm in deploy scripts" \
  "! grep -RqsE '(^|[^[:alnum:]_])docker[[:space:]]+network[[:space:]]+rm([[:space:]]|$)' $D"

echo
echo "production mail points at the internal postfix alias"
t "MAIL_HOST is postfix"                "grep -qx 'MAIL_HOST=postfix' $ENVFILE"
t "MAIL_PORT is the internal relay"     "grep -qx 'MAIL_PORT=25' $ENVFILE"
t "MAIL_ENCRYPTION is null on the private network" "grep -qx 'MAIL_ENCRYPTION=null' $ENVFILE"
t "MAIL_MAILER is smtp"                 "grep -qx 'MAIL_MAILER=smtp' $ENVFILE"
t "no dev mailhog host in production example" "! grep -q 'mailhog' $ENVFILE"
t "sender is the PIIE address"          "grep -qx 'MAIL_FROM_ADDRESS=noreply@piie.ac.ug' $ENVFILE"
t "database password placeholders stay empty" \
  "! grep -E '^PIIE_DB_(PASSWORD|ROOT_PASSWORD)=.+' $ENVFILE"

echo
echo "documentation covers the netfilter and rollback boundary"
t "documents Mailcow netfilter isolation" "grep -q 'DISABLE_NETFILTER_ISOLATION_RULE' $DOC"
t "documents the fallback submission port" "grep -q '587/TLS' $DOC"
t "documents that production is not verified" "grep -qi 'NOT VERIFIED' $DOC"
t "documents network rollback" "grep -q 'Rollback' $DOC"

echo
[ $fails -eq 0 ] && echo "ALL COMPOSE/NETWORK CHECKS PASSED" || echo "$fails COMPOSE/NETWORK CHECK(S) FAILED"
exit $((fails>0))
