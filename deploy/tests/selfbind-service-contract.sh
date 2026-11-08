#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
# Offline parsing only: no units are loaded, enabled, started, stopped or installed.
systemd-analyze --man=no verify "$ROOT"/deploy/systemd/*.service "$ROOT"/deploy/systemd/*.timer
python3 - "$ROOT" <<'PY'
import configparser, pathlib, sys
root = pathlib.Path(sys.argv[1])
units = {}
for path in (root / "deploy/systemd").iterdir():
    unit = configparser.ConfigParser(interpolation=None, strict=False)
    unit.read(path); units[path.name] = unit
pin = "piie-deployment-pin.service"
for name in ["piie-runtime.service", "piie-scheduler.service", "piie-scheduler.timer"]:
    assert pin in units[name]["Unit"]["Requires"].split()
    assert pin in units[name]["Unit"]["After"].split()
for name, unit in units.items():
    for field in ["Requires", "Wants", "Before", "After"]:
        for dep in unit["Unit"].get(field, "").split():
            assert dep.startswith("piie-") or dep in {"docker.service", "local-fs.target"}, (name, dep)
            if dep == "docker.service": assert field == "After"  # order only, never activate/stop Docker
    assert "ExecStop" not in unit["Service"] if "Service" in unit else True
assert units[pin]["Service"]["RemainAfterExit"] == "yes"
assert "--privileged" not in (root / "deploy/remote/runtime-supervisor.sh").read_text()
assert "docker start piie-app" in (root / "deploy/remote/runtime-supervisor.sh").read_text()
assert "docker wait piie-app" in (root / "deploy/remote/runtime-supervisor.sh").read_text()
assert 'restart: "no"' in (root / "deploy/container/compose.selfbind.override.yaml").read_text()
print("PASS: offline systemd verification; PIIE-only requirements/order; no shared-daemon activation or stop hooks")
PY
