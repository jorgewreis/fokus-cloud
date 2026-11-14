#!/usr/bin/env python3
"""POSIX ownership and exclusive-lock boundary for inactive release preparation.

This rejects untrusted writable ancestors and extended POSIX ACLs. It assumes the
operator UID and root are trusted. It does not attest FPM identity/capabilities;
the separate activation runtime policy remains mandatory.
"""
from contextlib import contextmanager
import fcntl
import grp
import os
from pathlib import Path
import pwd
import stat


class UnsafePreparation(ValueError):
    pass


def require(condition, reason):
    if not condition:
        raise UnsafePreparation(reason)


def reject_acl(path):
    try:
        attrs = os.listxattr(path, follow_symlinks=False)
    except (AttributeError, NotImplementedError, OSError) as error:
        raise UnsafePreparation('acl_unverifiable') from error
    require(not any(name in ('system.posix_acl_access', 'system.posix_acl_default') for name in attrs), 'extended_acl_forbidden')


def metadata(path, operator, runtime, *, direct=False, regular=False, sealed=False):
    path = Path(path)
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) if regular else stat.S_ISDIR(info.st_mode), 'unsafe_path_type')
    if regular:
        require(info.st_nlink == 1, 'hardlinked_control_file')
    require(info.st_uid in ((operator,) if direct else (0, operator)), 'untrusted_path_owner')
    require(not direct or info.st_uid != runtime, 'runtime_owns_control')
    write = info.st_mode & 0o022
    require(not write or (not direct and info.st_uid == 0 and info.st_mode & stat.S_ISVTX), 'untrusted_path_writable')
    require(not sealed or not info.st_mode & 0o222, 'sealed_path_writable')
    reject_acl(path)
    return info


def check_tree(root, operator, runtime, *, sealed):
    root = Path(root)
    metadata(root, operator, runtime, direct=True, sealed=sealed)
    for directory, dirs, names in os.walk(root, followlinks=False):
        for name in dirs + names:
            path = Path(directory) / name
            if path.is_symlink():
                require(path.parent == root and name in ('storage', '.env'), 'unexpected_tree_link')
                continue
            is_dir = path.is_dir()
            metadata(path, operator, runtime, direct=True, regular=not is_dir, sealed=sealed)


def runtime_groups(runtime_uid, runtime_gid):
    require(type(runtime_uid) is int and runtime_uid > 0 and runtime_uid != os.geteuid(), 'separate_runtime_principal_required')
    require(type(runtime_gid) is int and runtime_gid > 0, 'explicit_runtime_group_required')
    try:
        user = pwd.getpwuid(runtime_uid)
        grp.getgrgid(runtime_gid)
        groups = set(os.getgrouplist(user.pw_name, user.pw_gid))
    except KeyError as error:
        raise UnsafePreparation('unknown_runtime_principal') from error
    require(runtime_gid in groups, 'runtime_group_membership_required')
    require(os.geteuid() == 0 or runtime_gid in set(os.getgroups()) | {os.getegid()}, 'operator_group_membership_required')
    return groups


def grant_runtime_read(root, runtime_uid, runtime_gid):
    """Set only owner write and group read/traverse on the new inactive root."""
    runtime_groups(runtime_uid, runtime_gid)
    root = Path(root)
    before = root.lstat()
    fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
        require((opened.st_dev, opened.st_ino) == (before.st_dev, before.st_ino), 'candidate_root_rebound')
        require(opened.st_uid == os.geteuid() and opened.st_uid != runtime_uid, 'runtime_owns_control')
        if opened.st_gid != runtime_gid:
            os.fchown(fd, -1, runtime_gid)
        os.fchmod(fd, 0o750)
        after = os.fstat(fd)
        require(after.st_gid == runtime_gid and stat.S_IMODE(after.st_mode) == 0o750, 'runtime_root_permissions_failed')
        require((root.lstat().st_dev, root.lstat().st_ino) == (after.st_dev, after.st_ino), 'candidate_root_rebound')
    finally:
        os.close(fd)


def verify_runtime_read(root, runtime_uid, runtime_gid):
    """Check POSIX mode readability; actual process/capabilities remain separate evidence."""
    groups = runtime_groups(runtime_uid, runtime_gid)
    for parent in Path(root).parents:
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode), 'runtime_ancestor_not_directory')
        if info.st_uid == runtime_uid:
            granted = (info.st_mode >> 6) & 0o7
        elif info.st_gid in groups:
            granted = (info.st_mode >> 3) & 0o7
        else:
            granted = info.st_mode & 0o7
        require(granted & 0o1, 'runtime_ancestor_traverse_denied')
    for directory, dirs, names in os.walk(root, followlinks=False):
        for path in [Path(directory)] + [Path(directory) / name for name in dirs + names]:
            if path.is_symlink():
                continue
            info = path.lstat()
            required = 0o5 if stat.S_ISDIR(info.st_mode) else 0o4
            if info.st_uid == runtime_uid:
                granted = (info.st_mode >> 6) & 0o7
            elif info.st_gid in groups:
                granted = (info.st_mode >> 3) & 0o7
            else:
                granted = info.st_mode & 0o7
            require(granted & required == required, 'runtime_read_or_traverse_denied')
            require(not granted & 0o2, 'runtime_write_forbidden')


@contextmanager
def secure_preparation(base, root_parent, receipt_parent, transport_parent, configuration, storage, runtime_uid):
    require(type(runtime_uid) is int and runtime_uid > 0 and runtime_uid != os.geteuid(), 'separate_runtime_principal_required')
    try:
        pwd.getpwuid(runtime_uid)
    except KeyError as error:
        raise UnsafePreparation('unknown_runtime_principal') from error
    operator = os.geteuid()
    direct = {Path(p).absolute() for p in (base, root_parent, receipt_parent, transport_parent)}
    directories = set(direct)
    directories.update(Path(configuration).absolute().parents)
    directories.update(Path(storage).absolute().parents)
    for path in tuple(direct):
        directories.update(path.parents)
    snapshots = {}
    for path in sorted(directories, key=lambda p: (len(p.parts), str(p))):
        require(path == path.resolve(), 'control_symlink')
        info = metadata(path, operator, runtime_uid, direct=path in direct)
        snapshots[path] = (info.st_dev, info.st_ino)
    config = Path(configuration).absolute()
    require(config == config.resolve(), 'configuration_symlink')
    config_info = metadata(config, operator, runtime_uid, direct=True, regular=True)
    storage = Path(storage).absolute()
    require(storage == storage.resolve() and storage.is_dir(), 'storage_path_invalid')
    storage_info = storage.lstat()
    lock = Path(base).absolute() / 'prepare.lock'
    lock_info = metadata(lock, operator, runtime_uid, direct=True, regular=True)
    require(not lock_info.st_mode & 0o077, 'prepare_lock_not_private')
    fd = os.open(lock, os.O_RDWR | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
        require((opened.st_dev, opened.st_ino) == (lock_info.st_dev, lock_info.st_ino), 'prepare_lock_rebound')
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        def checkpoint():
            for path, identity in snapshots.items():
                current = metadata(path, operator, runtime_uid, direct=path in direct)
                require((current.st_dev, current.st_ino) == identity, 'control_path_rebound')
            current = metadata(config, operator, runtime_uid, direct=True, regular=True)
            require((current.st_dev, current.st_ino) == (config_info.st_dev, config_info.st_ino), 'configuration_rebound')
            current = storage.lstat()
            require(stat.S_ISDIR(current.st_mode) and (current.st_dev, current.st_ino) == (storage_info.st_dev, storage_info.st_ino), 'storage_rebound')
            current = metadata(lock, operator, runtime_uid, direct=True, regular=True)
            require((current.st_dev, current.st_ino) == (opened.st_dev, opened.st_ino), 'prepare_lock_rebound')
        candidate_identity = {}
        def guarded_tree(root, sealed=False):
            root = Path(root)
            before = root.lstat()
            identity = (before.st_dev, before.st_ino)
            if not candidate_identity:
                candidate_identity['root'] = identity
            require(candidate_identity['root'] == identity, 'candidate_root_rebound')
            check_tree(root, operator, runtime_uid, sealed=sealed)
            after = root.lstat()
            require((after.st_dev, after.st_ino) == identity, 'candidate_root_rebound')
        checkpoint()
        yield checkpoint, guarded_tree
        checkpoint()
    finally:
        os.close(fd)
