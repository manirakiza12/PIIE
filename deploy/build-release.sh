#!/usr/bin/env bash
# Build the release artefact from the EXACT committed tree (HEAD), never from a
# working directory. Usage: deploy/build-release.sh [output-dir]
set -euo pipefail

OUT="${1:-dist}"
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

if [ -n "${PIIE_REQUIRE_CLEAN:-}" ] && [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "ERROR: tracked files have uncommitted changes; the artefact would not match the commit." >&2
  exit 1
fi

SHA="$(git rev-parse HEAD)"
SHORT="${SHA:0:12}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

echo "==> Exporting tracked tree of $SHORT (honours .gitattributes export-ignore)"
git archive --format=tar HEAD | tar -x -C "$STAGE"

cd "$STAGE"

echo "==> Composer (production dependencies only, from composer.lock)"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader ${PIIE_COMPOSER_FLAGS:-}

# The repo ships compiled assets and has no webpack.mix.js, so there is nothing
# to build. If one is added, Node runs HERE (CI), never on the host.
if [ -f webpack.mix.js ] || [ -f webpack.mix.cjs ]; then
  echo "==> Mix config found: building assets with Node $(node -v)"
  # devDependencies are required for the build; node_modules is removed after.
  git -C "$ROOT" show HEAD:package.json > package.json
  git -C "$ROOT" show HEAD:package-lock.json > package-lock.json
  npm ci && npm run production
  rm -rf node_modules package.json package-lock.json
else
  echo "==> No Mix config; committed public/ assets are used as-is"
fi

# Writable dirs must exist so the symlink to shared/storage has a valid shape.
rm -rf storage/app storage/logs storage/framework
mkdir -p bootstrap/cache
rm -f bootstrap/cache/*.php
rm -f .env

printf '%s\n' "$SHA" > RELEASE_SHA

ARTEFACT="$OUT/piie-release-$SHORT.tar.gz"
tar --sort=name --owner=0 --group=0 --numeric-owner -czf "$ARTEFACT" .
( cd "$OUT" && sha256sum "$(basename "$ARTEFACT")" > "$(basename "$ARTEFACT").sha256" )

echo "==> Verifying artefact"
bash "$ROOT/deploy/verify-release.sh" "$ARTEFACT"

echo "ARTEFACT=$ARTEFACT"
