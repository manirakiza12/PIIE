#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FIXTURE_TMP=${TMPDIR:-/tmp}
S=$(mktemp -d "$FIXTURE_TMP/piie-db-evidence.XXXXXX")
cleanup() { case "$S" in "$FIXTURE_TMP"/piie-db-evidence.*) rm -rf -- "$S";; *) exit 1;; esac; }
trap cleanup EXIT
SCRIPT="$ROOT/deploy/diagnostics/production-readiness.sh"
bash -n "$SCRIPT"
sed -n '/^# HEALTH_SUMMARY_BEGIN$/,/^# HEALTH_SUMMARY_END$/p' "$SCRIPT" | sed '1d;$d' > "$S/summary.py"
python3 - "$S/summary.py" <<'PY'
import json, subprocess, sys
script = sys.argv[1]
cases = [
    ("OCI runtime exec failed: SetSSB requires libseccomp API level", "runtime_seccomp_failure"),
    ("OCI runtime exec failed: error executing setns", "runtime_exec_failure"),
    ("executable file not found", "probe_executable_missing"),
    ("Access denied for fixture user", "database_authentication_failure"),
    ("Can't connect: connection refused", "database_connection_failure"),
    ("permission denied", "probe_permission_failure"),
    ("unrecognized fixture error", "unclassified"),
]
for message, expected in cases:
    sensitive = "PRIVATE_KEY_FIXTURE fixture.invalid SECRET_FIXTURE SHA256:fixture"
    data = {"health": {"Log": [{"ExitCode": 128, "Output": message + sensitive}]},
        "test": ["CMD-SHELL", "healthcheck.sh --connect " + sensitive], "oom_killed": False, "restart_count": 2}
    result = subprocess.run([sys.executable, script], input=json.dumps(data), text=True, capture_output=True, check=True)
    output = json.loads(result.stdout)
    assert output["recent_checks"][0] == {"exit_code": 128, "category": expected}
    assert output["probe_type"] == "mariadb_builtin"
    assert output["restart_count"] == 2
    for value in sensitive.split():
        assert value not in result.stdout + result.stderr
bad = subprocess.run([sys.executable, script], input="bad secret fixture.invalid", text=True, capture_output=True)
assert bad.returncode != 0
assert "fixture.invalid" not in bad.stdout + bad.stderr
print("PASS: database health failures are classified without raw commands, logs, hosts or secrets")
PY
# Source only the evidence function into a fixture environment. No live Docker.
sed -n '/^database_health_evidence() (/,$p' "$SCRIPT" | sed '/^\[ "$(id -u)"/,$d' > "$S/function.sh"
mkdir "$S/bin"
cat > "$S/bin/docker" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
case "$*" in
  *'Config.Healthcheck.Test'*) printf '%s\n' '{"health":{"Log":[{"ExitCode":128,"Output":"SetSSB seccomp fixture secret"}]},"test":["CMD","healthcheck.sh"],"oom_killed":false,"restart_count":0}';;
  *'Config.Env'*) printf '%s\n' 'MARIADB_ROOT_PASSWORD=SECRET_FIXTURE';;
  run*)
    [[ " $* " = *' --read-only '* && " $* " = *' --pull=never '* && " $* " = *' --cap-drop=ALL '* && " $* " = *'SELECT 1'* ]] || exit 90
    exit "${CLIENT_STATUS:-0}";;
  *) exit 91;;
esac
SH
chmod +x "$S/bin/docker"
mkdir "$S/tmp"
export PATH="$S/bin:$PATH" TMPDIR="$S/tmp"
source "$S/function.sh"
database_health_evidence > "$S/pass.log" 2>&1
grep -q 'Independent database read-only SELECT 1: PASS' "$S/pass.log"
! grep -Eq 'SECRET_FIXTURE|fixture secret' "$S/pass.log"
[ -z "$(find "$S/tmp" -type f -print -quit)" ]
CLIENT_STATUS=1 database_health_evidence > "$S/fail.log" 2>&1
grep -q 'Independent database read-only SELECT 1: FAIL' "$S/fail.log"
[ -z "$(find "$S/tmp" -type f -print -quit)" ]
echo 'PASS: read-only client evidence reports success/failure and cleans private temp data'
