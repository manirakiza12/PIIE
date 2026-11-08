#!/usr/bin/env python3
"""Repair only an explicitly selected upload tree using no-follow file descriptors."""
import grp
import os
import stat
import sys

FLAGS = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
GROUP = grp.getgrnam("www-data").gr_gid


def repair(fd):
    info = os.fstat(fd)
    if stat.S_ISDIR(info.st_mode):
        for name in os.listdir(fd):
            child = os.open(name, FLAGS, dir_fd=fd)
            try:
                repair(child)
            finally:
                os.close(child)
        os.fchown(fd, 0, GROUP)
        os.fchmod(fd, 0o2775)
    elif stat.S_ISREG(info.st_mode) and info.st_nlink == 1:
        os.fchown(fd, 0, GROUP)
        os.fchmod(fd, 0o664)
    else:
        raise ValueError("Upload hardlink or special file refused")


if __name__ == "__main__":
    try:
        fd = os.open(sys.argv[1], FLAGS | os.O_DIRECTORY)
        try:
            repair(fd)
        finally:
            os.close(fd)
    except (OSError, ValueError) as error:
        sys.exit("Cannot set upload permissions: " + str(error))
