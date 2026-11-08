#!/usr/bin/env bash
# Source from a test already running in a private mount namespace as real root.
fixture_init() {
  TESTROOT=$(mktemp -d /var/lib/piie-secure-test.XXXXXXXX)
  trap fixture_cleanup EXIT
  chmod 755 "$TESTROOT"
  S="$TESTROOT/home/piie/deployments/piie"
  mkdir -p "$S"/{incoming,releases,shared/storage/app/public,shared/storage/logs} "$TESTROOT"/{etc/piie,scripts,bin,container/srv/piie}
  chmod 755 "$TESTROOT/home"
  chown nobody:nogroup "$TESTROOT/home/piie"; chmod 750 "$TESTROOT/home/piie"
  chown nobody:root "$S/releases"
  chown nobody:nogroup "$S/incoming"; chmod 750 "$S/incoming"
  chown root:www-data "$S/shared"; chmod 750 "$S/shared"
  mkdir -p "$S/.secure/releases" "$S/.secure/private"
  chmod 700 "$S/.secure/private"
  for path in "$ROOT"/deploy/remote/*.sh "$ROOT"/deploy/remote/*.py; do
    sed "s|/home/piie|$TESTROOT/home/piie|g; s|/etc/piie|$TESTROOT/etc/piie|g; s|PATH=/usr/sbin:/usr/bin:/sbin:/bin|PATH=$TESTROOT/bin:/usr/sbin:/usr/bin:/sbin:/bin|g" "$path" > "$TESTROOT/scripts/$(basename "$path")"
  done
  SCRIPTS="$TESTROOT/scripts"
  mkdir -p "$S/bin"
  cp "$ROOT/deploy/tests/support/container-docker.sh" "$TESTROOT/bin/docker"
  chmod +x "$TESTROOT/bin/docker"
  export PATH="$TESTROOT/bin:$PATH" FIXTURE_BASE="$S" FIXTURE_LOG="$S/docker.log"
  mount --bind "$S" "$TESTROOT/container/srv/piie"
  python3 -c 'import os,sys,time; os.chroot(sys.argv[1]); time.sleep(3600)' "$TESTROOT/container" &
  FIXTURE_PID=$!; export FIXTURE_PID
  local tries=0
  until [ -d "/proc/$FIXTURE_PID/root/srv/piie" ]; do
    tries=$((tries+1)); [ "$tries" -lt 100 ] || return 1; sleep 0.02
  done
  bash "$SCRIPTS/pin-deployments.sh" enroll
  bash "$SCRIPTS/pin-deployments.sh" start
  SHA=f23cff8aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
  export EXPECTED_RELEASE_SHA="$SHA"
  mkdir -p "$S/fixture/vendor/bin" "$S/fixture/bootstrap/cache" "$S/fixture/public/assets/uploads"
  printf '%s\n' "$SHA" > "$S/fixture/RELEASE_SHA"
  printf 'autoload\n' > "$S/fixture/vendor/autoload.php"
  printf '#!/bin/sh\nexit 0\n' > "$S/fixture/vendor/bin/tool"; chmod 755 "$S/fixture/vendor/bin/tool"
  printf baseline > "$S/fixture/public/assets/uploads/keep.txt"
  cp -a "$S/fixture" "$S/releases/f23cff8"
  ln -s ../../shared/.env "$S/releases/f23cff8/.env"
  ln -s ../../shared/storage "$S/releases/f23cff8/storage"
  ln -s ../../../shared/storage/app/public "$S/releases/f23cff8/public/storage"
  ln -s releases/f23cff8 "$S/current"
  printf preserved > "$S/shared/.env"; chown root:www-data "$S/shared/.env"; chmod 640 "$S/shared/.env"
  printf 'DocumentRoot /srv/piie/current/public\n' > "$S/apache.conf"
  printf 'persistent student upload\n' > "$S/shared/storage/app/public/student-upload.pdf"
  printf 'changing scheduler log\n' > "$S/shared/storage/logs/scheduler.log"
  tar -czf "$S/incoming/piie-release-fixture.tar.gz" -C "$S/fixture" .
  SUM=$(sha256sum "$S/incoming/piie-release-fixture.tar.gz" | cut -d' ' -f1)
  bash "$SCRIPTS/preserve-baseline.sh" "$S/incoming/piie-release-fixture.tar.gz" "$SUM" "$SHA"
  printf live-upload > "$S/releases/f23cff8/public/assets/uploads/keep.txt"
}
fixture_cleanup() {
  local status=$?
  if [ "$status" != 0 ]; then
    for log in "$S"/*.log; do [ ! -f "$log" ] || { echo "--- $log"; tail -n 25 "$log"; }; done
  fi
  exec 9>&-
  if [ -n "${FIXTURE_PID:-}" ]; then
    kill "$FIXTURE_PID" 2>/dev/null || :
    wait "$FIXTURE_PID" 2>/dev/null || :
  fi
  if mountpoint -q "$TESTROOT/home/piie/deployments"; then umount "$TESTROOT/home/piie/deployments"; fi
  if mountpoint -q "$TESTROOT/container/srv/piie"; then umount "$TESTROOT/container/srv/piie"; fi
  case "$TESTROOT" in /var/lib/piie-secure-test.*) rm -rf -- "$TESTROOT";; esac
  return "$status"
}
