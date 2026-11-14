#!/usr/bin/env python3
"""Receive one explicitly identified artifact into a private staging directory.

The caller supplies authenticated digest and length over a separate trusted
channel. This local receiver does not configure or attest an SSH transport.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import sys

from preparation_guard import metadata, require


def receive(stream, directory, name, expected_sha256, expected_size):
    require(re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}', name) is not None, 'invalid_artifact_name')
    require(re.fullmatch(r'[0-9a-f]{64}', expected_sha256) is not None, 'invalid_artifact_digest')
    require(type(expected_size) is int and expected_size > 0, 'invalid_artifact_size')
    directory = Path(directory).absolute()
    require(directory == directory.resolve(), 'staging_symlink')
    operator = os.geteuid()
    identities = {}
    for path in (directory, *directory.parents):
        info = metadata(path, operator, -1, direct=path == directory)
        identities[path] = (info.st_dev, info.st_ino)
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    temporary = '.receiving-' + secrets.token_hex(16)
    created = False
    try:
        opened = os.fstat(fd)
        require((opened.st_dev, opened.st_ino) == identities[directory], 'staging_rebound')
        file_fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=fd)
        created = True
        h = hashlib.sha256()
        total = 0
        with os.fdopen(file_fd, 'wb') as output:
            while True:
                chunk = stream.read(min(1024 * 1024, expected_size - total + 1))
                if not chunk:
                    break
                total += len(chunk)
                require(total <= expected_size, 'artifact_size_mismatch')
                h.update(chunk)
                output.write(chunk)
            require(total == expected_size and h.hexdigest() == expected_sha256, 'artifact_integrity_mismatch')
            output.flush()
            os.fsync(output.fileno())
        for path, identity in identities.items():
            info = metadata(path, operator, -1, direct=path == directory)
            require((info.st_dev, info.st_ino) == identity, 'staging_rebound')
        # link is atomic and fails if the destination already exists, including symlinks.
        os.link(temporary, name, src_dir_fd=fd, dst_dir_fd=fd, follow_symlinks=False)
        os.unlink(temporary, dir_fd=fd)
        created = False
        os.fsync(fd)
        return {'state': 'received_not_active', 'sha256': expected_sha256, 'size': total}
    finally:
        if created:
            os.unlink(temporary, dir_fd=fd)
        os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', required=True)
    parser.add_argument('--name', required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--size', type=int, required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(receive(sys.stdin.buffer, args.directory, args.name, args.sha256, args.size), sort_keys=True))
        return 0
    except Exception:
        print(json.dumps({'state': 'blocked', 'reason': 'artifact_receive_failed'}), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
