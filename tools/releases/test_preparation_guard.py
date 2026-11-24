#!/usr/bin/env python3
"""Adversarial POSIX checks for inactive release preparation, no production."""
import fcntl
import os
from pathlib import Path
import pwd
import tempfile
import unittest
from unittest.mock import patch

import preparation_guard as guard


class PreparationBoundary(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='immutable-prep-')
        self.addCleanup(self.tmp.cleanup)
        self.top = Path(self.tmp.name)
        self.base = self.top / 'control'
        self.releases = self.base / 'releases'
        self.releases.mkdir(parents=True, mode=0o700)
        self.lock = self.base / 'prepare.lock'
        self.lock.touch(mode=0o600)
        self.storage = self.top / 'storage'
        self.storage.mkdir(mode=0o700)
        self.config = self.top / 'synthetic.env'
        self.config.write_text('# synthetic')
        self.config.chmod(0o600)
        self.runtime = pwd.getpwnam('nobody').pw_uid
        if self.runtime == os.geteuid() or self.runtime == 0:
            self.skipTest('requires a distinct unprivileged fixture user')

    def protect(self, root_parent=None, runtime=None):
        return guard.secure_preparation(self.base, root_parent or self.releases, self.top,
                                        self.top, self.config, self.storage,
                                        self.runtime if runtime is None else runtime)

    def test_rejects_world_writable_release_parent_before_preparation(self):
        self.releases.chmod(0o777)
        with self.assertRaisesRegex(guard.UnsafePreparation, 'untrusted_path_writable'):
            with self.protect():
                self.fail('unexpected access')
        self.assertFalse((self.releases / ('a' * 40)).exists())

    def test_rejects_runtime_matching_operator_or_root(self):
        for runtime in (os.geteuid(), 0):
            with self.subTest(runtime=runtime), self.assertRaisesRegex(guard.UnsafePreparation, 'separate_runtime_principal_required'):
                with self.protect(runtime=runtime):
                    self.fail('unexpected access')

    def test_rejects_extended_acl_without_modifying_control(self):
        original = self.lock.read_bytes()
        with patch.object(guard.os, 'listxattr', return_value=['system.posix_acl_access']):
            with self.assertRaisesRegex(guard.UnsafePreparation, 'extended_acl_forbidden'):
                with self.protect():
                    self.fail('unexpected access')
        self.assertEqual(self.lock.read_bytes(), original)

    def test_lock_contention_fails_without_creating_candidate(self):
        fd = os.open(self.lock, os.O_RDWR)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):
                with self.protect():
                    self.fail('unexpected access')
            self.assertFalse((self.releases / ('a' * 40)).exists())
        finally:
            os.close(fd)

    def test_replacement_of_parent_is_detected_before_next_phase(self):
        with self.assertRaisesRegex(guard.UnsafePreparation, 'control_path_rebound'):
            with self.protect() as (checkpoint, _):
                self.releases.rename(self.base / 'old-releases')
                self.releases.mkdir(mode=0o700)
                checkpoint()

    def test_candidate_replacement_is_detected(self):
        root = self.releases / ('a' * 40)
        root.mkdir(mode=0o700)
        with self.assertRaisesRegex(guard.UnsafePreparation, 'candidate_root_rebound'):
            with self.protect() as (_, check_tree):
                check_tree(root, sealed=False)
                root.rename(self.releases / ('b' * 40))
                root.mkdir(mode=0o700)
                check_tree(root, sealed=False)

    def test_symlinked_control_parent_is_rejected(self):
        alias = self.base / 'alias'
        alias.symlink_to(self.releases, target_is_directory=True)
        with self.assertRaisesRegex(guard.UnsafePreparation, 'control_symlink'):
            with self.protect(root_parent=alias):
                self.fail('unexpected access')

    def test_untrusted_tree_file_and_unsealed_mode_are_rejected(self):
        root = self.releases / ('a' * 40)
        root.mkdir(mode=0o700)
        file = root / 'code.php'
        file.write_text('synthetic')
        file.chmod(0o666)
        with self.protect() as (_, check_tree):
            with self.assertRaisesRegex(guard.UnsafePreparation, 'untrusted_path_writable'):
                check_tree(root, sealed=False)
            file.chmod(0o644)
            with self.assertRaisesRegex(guard.UnsafePreparation, 'sealed_path_writable'):
                check_tree(root, sealed=True)


if __name__ == '__main__':
    unittest.main()
