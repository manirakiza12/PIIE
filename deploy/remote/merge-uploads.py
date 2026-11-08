#!/usr/bin/env python3
"""Merge regular upload files, preserving existing destinations; never follow links."""
import grp
import os
import shutil
import stat
import sys

DIR = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
FILE = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
GROUP = grp.getgrnam("www-data").gr_gid


def directory(path):
    # O_NOFOLLOW on every component, including user-controlled legacy releases.
    if not os.path.isabs(path):
        raise ValueError("Absolute upload path required")
    fd = os.open("/", DIR)
    try:
        for part in path.split("/"):
            if part in ("", "."):
                continue
            if part == "..":
                raise ValueError("Upload traversal refused")
            child = os.open(part, DIR, dir_fd=fd)
            os.close(fd); fd = child
        return fd
    except BaseException:
        os.close(fd)
        raise


def merge(source, destination):
    for name in os.listdir(source):
        src = os.open(name, FILE, dir_fd=source)
        try:
            info = os.fstat(src)
            if stat.S_ISDIR(info.st_mode):
                try:
                    os.mkdir(name, 0o2775, dir_fd=destination)
                except FileExistsError:
                    pass
                dst = os.open(name, DIR, dir_fd=destination)
                try:
                    merge(src, dst)
                finally:
                    os.close(dst)
            elif stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
                try:
                    dst = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=destination)
                except FileExistsError:
                    existing = os.stat(name, dir_fd=destination, follow_symlinks=False)
                    if not stat.S_ISREG(existing.st_mode) or existing.st_nlink != 1:
                        raise ValueError("Unsafe existing upload destination")
                    continue
                with os.fdopen(dst, "wb") as output, os.fdopen(os.dup(src), "rb") as input_file:
                    shutil.copyfileobj(input_file, output)
                    os.fchown(output.fileno(), 0, GROUP)
                    os.fchmod(output.fileno(), 0o664)
            else:
                raise ValueError("Upload hardlink or special file refused")
        finally:
            os.close(src)


if __name__ == "__main__":
    try:
        source = directory(sys.argv[1])
        try:
            destination = directory(sys.argv[2])
            try:
                merge(source, destination)
            finally:
                os.close(destination)
        finally:
            os.close(source)
    except (OSError, ValueError) as error:
        sys.exit("Upload merge refused: " + str(error))
