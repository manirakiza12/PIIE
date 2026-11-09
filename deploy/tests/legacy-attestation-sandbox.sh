#!/usr/bin/env bash
# CI-REQUIRES-ROOT: root ownership checks use synthetic temporary files only.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
python3 "$ROOT/deploy/tests/legacy-attestation.py"
