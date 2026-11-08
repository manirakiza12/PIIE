#!/usr/bin/env python3
"""Strict extraction and read-only baseline comparison. No application execution."""
import hashlib
import os
from pathlib import Path, PurePosixPath
import shutil
import stat
import sys
import tarfile


def snapshot(source, destination):
    descriptor = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, "rb") as src:
        if not stat.S_ISREG(os.fstat(src.fileno()).st_mode):
            raise ValueError("Archive source must be a regular file")
        with open(destination, "xb") as out:
            shutil.copyfileobj(src, out)
    os.chmod(destination, 0o400)


def extract(archive, destination, expected):
    dest = Path(destination)
    with tarfile.open(archive, "r:gz") as tar:
        members = tar.getmembers()
        seen = set()
        for member in members:
            name = member.name
            parts = PurePosixPath(name).parts
            if (name.startswith("/") or ".." in parts or ".env" in parts
                    or any(ord(c) < 32 for c in name)
                    or not (member.isfile() or member.isdir())):
                raise ValueError("Unsafe archive entry")
            normalized = str(PurePosixPath(name))
            if normalized == ".":
                if not member.isdir():
                    raise ValueError("Invalid archive root")
                continue
            if normalized in seen:
                raise ValueError("Duplicate archive path")
            seen.add(normalized)
            if normalized == "public/storage" or normalized.startswith("public/storage/"):
                raise ValueError("Unexpected packaged public/storage")
        marker = [m for m in members if str(PurePosixPath(m.name)) == "RELEASE_SHA"]
        if len(marker) != 1 or not marker[0].isfile():
            raise ValueError("Missing release identity")
        if tar.extractfile(marker[0]).read() != (expected + "\n").encode():
            raise ValueError("Release differs from approved full SHA")
        dest.mkdir(mode=0o755)  # refuses an existing destination, including a link
        for member in members:
            name = str(PurePosixPath(member.name))
            if name == ".":
                continue
            path = dest / name
            path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
            if member.isdir():
                path.mkdir(mode=0o755, exist_ok=True)
            else:
                with tar.extractfile(member) as src, path.open("xb") as out:
                    shutil.copyfileobj(src, out)
                os.chmod(path, 0o755 if member.mode & 0o111 else 0o644)
        # Filesystem umask must not make application code unreadable.
        for root, dirs, files in os.walk(dest):
            os.chmod(root, 0o755)


RUNTIME = {".env", "storage", "storage-baseline", "bootstrap/cache", "public/storage",
           "public/assets/uploads", "public/assets/uploads-baseline"}
REPORTS = {"package-discover.txt", "migration-status.txt", "migration-preview.txt", "migration-run.txt"}


def inventory(root):
    result = {}
    def walk(folder, prefix=""):
        for entry in os.scandir(folder):
            name = prefix + entry.name
            if name in RUNTIME or name in REPORTS:
                continue
            if entry.is_symlink():
                raise ValueError("Unexpected code symlink: " + name)
            if entry.is_dir(follow_symlinks=False):
                result[name] = ("dir",)
                walk(entry.path, name + "/")
            elif entry.is_file(follow_symlinks=False):
                with open(entry.path, "rb") as handle:
                    hasher = hashlib.sha256()
                    for block in iter(lambda: handle.read(1024 * 1024), b""):
                        hasher.update(block)
                    digest = hasher.hexdigest()
                result[name] = ("file", digest, bool(entry.stat().st_mode & 0o111))
            else:
                raise ValueError("Unexpected special file: " + name)
    walk(root)
    return result


if __name__ == "__main__":
    try:
        if sys.argv[1] == "snapshot":
            snapshot(*sys.argv[2:])
        elif sys.argv[1] == "extract":
            extract(*sys.argv[2:])
        elif sys.argv[1] == "compare":
            if inventory(sys.argv[2]) != inventory(sys.argv[3]):
                raise ValueError("Live baseline code differs from approved archive")
        else:
            raise ValueError("Unknown action")
    except (OSError, ValueError, tarfile.TarError) as error:
        sys.exit("RELEASE REFUSED: " + str(error))
