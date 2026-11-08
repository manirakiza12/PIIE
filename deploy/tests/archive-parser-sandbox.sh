#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
python3 - "$ROOT" <<'PY'
import importlib.util, io, pathlib, tarfile, tempfile, sys
spec = importlib.util.spec_from_file_location("release_archive", pathlib.Path(sys.argv[1]) / "deploy/remote/release-archive.py")
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
sha = "a" * 40
with tempfile.TemporaryDirectory() as tmp:
    root = pathlib.Path(tmp)
    for index, kind in enumerate(["traversal", "absolute", "secret", "symlink", "hardlink", "fifo", "duplicate", "public-storage", "wrong-sha"]):
        archive = root / (kind + ".tar.gz")
        with tarfile.open(archive, "w:gz") as tar:
            marker = tarfile.TarInfo("RELEASE_SHA"); marker.size = 41
            tar.addfile(marker, io.BytesIO((("b" * 40 if kind == "wrong-sha" else sha) + "\n").encode()))
            name = {"traversal": "../outside", "absolute": "/outside", "secret": "sub/.env", "duplicate": "./RELEASE_SHA", "public-storage": "public/storage"}.get(kind, "unsafe")
            entry = tarfile.TarInfo(name)
            if kind == "symlink": entry.type = tarfile.SYMTYPE; entry.linkname = "/etc/passwd"
            elif kind == "hardlink": entry.type = tarfile.LNKTYPE; entry.linkname = "RELEASE_SHA"
            elif kind == "fifo": entry.type = tarfile.FIFOTYPE
            tar.addfile(entry, io.BytesIO(b""))
        target = root / ("extract-" + str(index))
        try: module.extract(archive, target, sha)
        except (ValueError, OSError, tarfile.TarError): pass
        else: raise AssertionError("Unsafe archive accepted: " + kind)
        assert not target.exists(), kind
    source = root / "source"; source.write_bytes(b"approved")
    link = root / "link"; link.symlink_to(source)
    try: module.snapshot(link, root / "copy")
    except OSError: pass
    else: raise AssertionError("Snapshot followed an incoming symlink")
print("PASS: nine unsafe archive cases rejected before extraction; snapshot refuses symlink source")
PY
