#!/usr/bin/env bash
# Assert a release artefact is complete and contains nothing it must not.
# Usage: deploy/verify-release.sh <artefact.tar.gz>
set -euo pipefail
A="${1:?artefact path required}"
LIST="$(mktemp)"; trap 'rm -f "$LIST"' EXIT
tar -tzf "$A" | sed 's#^\./##' > "$LIST"
fail=0

must_have() { grep -qx "$1" "$LIST" || { echo "MISSING: $1"; fail=1; }; }
must_not()  { if grep -E "$1" "$LIST" >/dev/null; then echo "FORBIDDEN ($2):"; grep -E "$1" "$LIST" | head -5; fail=1; fi; }

for f in artisan composer.json RELEASE_SHA vendor/autoload.php public/index.php bootstrap/app.php; do must_have "$f"; done
for f in public/css/piie-site.css public/css/piie-blocks.css \
  public/frontend/assets/css/bootstrap.min.css public/frontend/assets/css/style.css \
  public/frontend/assets/js/jquery-3.6.1.min.js public/frontend/assets/js/bootstrap.bundle.min.js \
  public/assets/css/main.css public/assets/css/style.css public/assets/css/typography.css \
  public/assets/css/workspace-ui.css public/assets/js/workspace-ui.js \
  public/assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css \
  public/assets/vendors/bootstrap-5.1.3/js/bootstrap.bundle.min.js \
  public/assets/vendors/jquery/jquery-3.6.0.min.js; do must_have "$f"; done

must_not '^\.env($|\.)'                'environment file'
must_not '^tests/'                     'tests'
must_not '^node_modules/'              'node_modules'
must_not '^\.git(/|$)'                 'git metadata'
must_not '^\.github/'                  'CI config'
must_not '^storage/(app|logs|framework)/' 'shared storage'
must_not '\.(sqlite|sqlite3|db)$'      'local database'
must_not '\.sql$'                      'SQL dump'
must_not '(^|/)id_(rsa|ed25519)'       'private key'
if grep -v '^vendor/' "$LIST" | grep -E '\.(pem|key)$' >/dev/null; then echo "FORBIDDEN (key material):"; grep -v '^vendor/' "$LIST" | grep -E '\.(pem|key)$' | head -5; fail=1; fi
must_not '^vendor/(phpunit|mockery|fakerphp)/' 'dev dependencies'
must_not '^storage/app/google/'        'OAuth client secret'

[ "$fail" = 0 ] && echo "verify-release: OK ($(wc -l < "$LIST") entries)" || { echo "verify-release: FAILED"; exit 1; }
