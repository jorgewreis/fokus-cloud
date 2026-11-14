#!/usr/bin/env python3
"""Synthetic OS-level read/write checks under an actually different UID."""
import os
from pathlib import Path
import pwd
import tempfile
import unittest

import preparation_guard as guard


@unittest.skipUnless(os.geteuid() == 0, 'requires root inside a disposable Linux runner to drop UID')
class RuntimeAccess(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='immutable-runtime-access-')
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.base.chmod(0o755)
        user = pwd.getpwnam('nobody')
        self.uid, self.gid = user.pw_uid, user.pw_gid
        if self.uid == 0:
            self.fail('fixture runtime must not be root')
        self.root = self.base / ('a' * 40)
        self.root.mkdir(mode=0o700)
        self.code = self.root / 'code.txt'
        self.code.write_text('synthetic release')
        os.chown(self.root, -1, self.gid)
        os.chown(self.code, -1, self.gid)
        self.code.chmod(0o440)

    def exercise(self, expected_read):
        pid = os.fork()
        if pid == 0:
            try:
                os.setgroups([])
                os.setgid(self.gid)
                os.setuid(self.uid)
                try:
                    readable = self.code.read_text() == 'synthetic release'
                except PermissionError:
                    readable = False
                try:
                    fd = os.open(self.root / 'runtime-write', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                except PermissionError:
                    writable = False
                else:
                    os.close(fd)
                    writable = True
                os._exit(0 if readable == expected_read and not writable else 1)
            except BaseException:
                os._exit(2)
        _, status = os.waitpid(pid, 0)
        self.assertEqual(os.waitstatus_to_exitcode(status), 0)
        self.assertFalse((self.root / 'runtime-write').exists())
        self.assertEqual(self.code.read_text(), 'synthetic release')

    def test_missing_or_privileged_group_is_rejected_before_chmod(self):
        before = self.root.stat().st_mode & 0o777
        with self.assertRaisesRegex(guard.UnsafePreparation, 'explicit_runtime_group_required'):
            guard.grant_runtime_read(self.root, self.uid, 0)
        self.assertEqual(self.root.stat().st_mode & 0o777, before)

    def test_owner_only_root_prevents_runtime_read_and_write(self):
        self.root.chmod(0o500)
        self.exercise(expected_read=False)

    def test_explicit_group_allows_runtime_read_but_not_write(self):
        guard.grant_runtime_read(self.root, self.uid, self.gid)
        self.root.chmod(0o550)  # Simulate removal of owner write by sealing.
        guard.verify_runtime_read(self.root, self.uid, self.gid)
        guard.check_tree(self.root, os.geteuid(), self.uid, sealed=True)
        self.exercise(expected_read=True)


if __name__ == '__main__':
    if os.environ.get('FOKUS_REQUIRE_ROOT_RUNTIME_TEST') == '1' and os.geteuid() != 0:
        raise RuntimeError('Synthetic runtime test requires root in the disposable CI runner')
    unittest.main()
