#!/usr/bin/env bash
# Run only as part of an approved activation, against the verified active release.
set -euo pipefail
BASE=/home/piie/deployments/piie
EXPECTED_SHA=${1:?expected release SHA required}
[[ "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]] || exit 1
[ "$(cat "$BASE/current/RELEASE_SHA")" = "$EXPECTED_SHA" ] || { echo 'Active release changed; reconciliation refused.'; exit 1; }
# Older releases do not carry the automatic-enrolment command.
[ -f "$BASE/current/app/Console/Commands/ReconcileCourseEnrolment.php" ] || exit 0
artisan() {
  if command -v docker >/dev/null && docker inspect piie-app >/dev/null 2>&1; then
    bash "$BASE/bin/container-app.sh" /srv/piie/current php artisan "$@"
  else
    /usr/local/php83/bin/php "$BASE/current/artisan" "$@"
  fi
}
echo 'Current-semester enrolment preview:'
artisan courses:reconcile-enrolment --json
echo 'Applying eligible current-semester registrations:'
artisan courses:reconcile-enrolment --apply --json
echo 'Verifying idempotence (no would_enrol rows expected):'
PREVIEW=$(artisan courses:reconcile-enrolment --json)
printf '%s\n' "$PREVIEW"
if printf '%s\n' "$PREVIEW" | grep -q '"result":"would_enrol"'; then
  echo 'Eligibility changed or reconciliation is incomplete; review the preview.'
  exit 1
fi
