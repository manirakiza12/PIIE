#!/usr/bin/env python3
"""Real root filesystem tests for the narrowly attested legacy import."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile

spec = importlib.util.spec_from_file_location('archive', Path(__file__).parents[1] / 'remote/release-archive.py')
archive = importlib.util.module_from_spec(spec)
spec.loader.exec_module(archive)
assert os.geteuid() == 0, 'Run isolated tests as root'
sha = 'f23cff88f15a35fd3d7606df929c26cd951ee288'
checksum = 'd' * 64
passed = 0
with tempfile.TemporaryDirectory(prefix='piie-attestation-') as temp:
    root = Path(temp)
    live, trusted = root / 'live', root / 'trusted'
    for folder in (live, trusted):
        folder.mkdir()
        (folder / 'RELEASE_SHA').write_text(sha + '\n')
        (folder / 'app.php').write_text('approved code\n')
        (folder / 'app.php').chmod(0o644)
    (live / 'app.php').chmod(0o755)
    backup = live / '.env.before-mail-fix'
    backup.write_text('synthetic private fixture\n')
    backup.chmod(0o640)
    receipt = root / 'receipt.json'
    proof = dict(schema=1, release_sha=sha, archive_sha256=checksum,
                 release_identity=[live.stat().st_dev, live.stat().st_ino],
                 live_inventory_sha256=archive.inventory_digest(archive.inventory(live)),
                 archive_inventory_sha256=archive.inventory_digest(archive.inventory(trusted)))
    def save():
        receipt.write_text(json.dumps(proof))
        receipt.chmod(0o600)
    def check():
        archive.compare_attested(str(live), str(trusted), str(receipt), checksum)
    def reject(label):
        global passed
        try:
            check()
        except (OSError, ValueError):
            passed += 1
            print('PASS: ' + label)
        else:
            raise AssertionError(label)
    save()
    assert archive.inventory(live) != archive.inventory(trusted)
    passed += 1; print('PASS: strict comparison still refuses legacy drift')
    check(); passed += 1; print('PASS: exact attested state accepted')
    (live / 'app.php').write_text('changed code\n')
    reject('code mutation refused')
    (live / 'app.php').write_text('approved code\n')
    (live / 'unknown').touch(); reject('unknown path refused'); (live / 'unknown').unlink()
    receipt.chmod(0o644); reject('public receipt refused'); receipt.chmod(0o600)
    proof['release_identity'][1] += 1; save(); reject('replacement identity refused')
    proof['release_identity'][1] -= 1; save()
    backup.chmod(0o644); reject('backup permission weakening refused'); backup.chmod(0o640)
    backup.rename(root / 'backup'); backup.symlink_to(root / 'backup')
    reject('backup symlink refused'); backup.unlink(); (root / 'backup').rename(backup)
    os.link(backup, root / 'hardlink'); reject('backup hardlink refused'); (root / 'hardlink').unlink()
    # Even a freshly authored receipt cannot authorize different code or unknown paths.
    (live / 'app.php').write_text('changed code\n')
    proof['live_inventory_sha256'] = archive.inventory_digest(archive.inventory(live)); save()
    reject('receipt cannot authorize code drift')
    (live / 'app.php').write_text('approved code\n'); (live / 'unknown').touch()
    proof['live_inventory_sha256'] = archive.inventory_digest(archive.inventory(live)); save()
    reject('receipt cannot authorize unknown paths')
print(f'{passed}/{passed} legacy attestation checks passed')
