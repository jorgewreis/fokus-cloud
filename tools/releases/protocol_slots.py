#!/usr/bin/env python3
"""Fail-closed local protocol process slots; not an sshd-wide connection limit."""
from contextlib import contextmanager
import fcntl
import os
from pathlib import Path

from preparation_guard import metadata

SLOTS = 4


@contextmanager
def acquire(directory):
    directory = Path(directory).absolute()
    if directory != directory.resolve(): raise ValueError('slot_directory_symlink')
    snapshots = {}
    for path in (directory, *directory.parents):
        info = metadata(path, os.geteuid(), -1, direct=path == directory)
        snapshots[path] = (info.st_dev, info.st_ino)
    if directory.stat().st_mode & 0o077: raise ValueError('slot_directory_not_private')
    dfd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    selected = None
    try:
        actual = os.fstat(dfd)
        if (actual.st_dev,actual.st_ino) != snapshots[directory]: raise ValueError('slot_directory_rebound')
        for index in range(SLOTS):
            name = 'slot-' + str(index) + '.lock'
            info = metadata(directory / name, os.geteuid(), -1, direct=True, regular=True)
            if info.st_mode & 0o077: raise ValueError('slot_not_private')
            fd = os.open(name, os.O_RDWR | os.O_NOFOLLOW, dir_fd=dfd)
            opened = os.fstat(fd)
            if (opened.st_dev,opened.st_ino) != (info.st_dev,info.st_ino):
                os.close(fd); raise ValueError('slot_rebound')
            try:
                fcntl.flock(fd,fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                os.close(fd)
                continue
            selected = fd
            break
        if selected is None: raise ValueError('protocol_capacity_exhausted')
        for path, identity in snapshots.items():
            info = metadata(path,os.geteuid(),-1,direct=path == directory)
            if (info.st_dev,info.st_ino) != identity: raise ValueError('slot_directory_rebound')
        yield
    finally:
        if selected is not None: os.close(selected)
        os.close(dfd)
