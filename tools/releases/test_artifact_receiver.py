#!/usr/bin/env python3
"""Disposable Linux filesystem tests for the artifact receiver."""
import hashlib
import io
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import unittest

import artifact_receiver as receiver


class Receiver(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.folder = Path(self.tmp.name)
        self.data = b'synthetic release artifact' * 4096
        self.digest = hashlib.sha256(self.data).hexdigest()

    def test_budget_matches_unpack_and_rejects_before_read_or_creation(self):
        import immutable
        self.assertEqual(receiver.MAX_ARTIFACT_BYTES, immutable.MAX_BYTES + 64 * 1024 * 1024)
        class Unreadable:
            def read(self, count):
                raise AssertionError('must not read oversized stream')
        with self.assertRaisesRegex(ValueError, 'invalid_artifact_size'):
            receiver.receive(Unreadable(), self.folder, 'release.tar', self.digest, receiver.MAX_ARTIFACT_BYTES + 1)
        self.assertEqual(list(self.folder.iterdir()), [])
    def test_budget_boundary_is_allowed_to_reach_read(self):
        class Unreadable:
            def read(self, count):
                raise AssertionError('read reached')
        with self.assertRaisesRegex(AssertionError, 'read reached'):
            receiver.receive(Unreadable(), self.folder, 'release.tar', self.digest, receiver.MAX_ARTIFACT_BYTES)
        self.assertEqual(list(self.folder.iterdir()), [])

    def test_receiver_and_publisher_cli_refuse_oversize_without_waiting_for_stdin(self):
        too_large = str(receiver.MAX_ARTIFACT_BYTES + 1)
        receiver_command = [sys.executable, str(Path(receiver.__file__)), '--directory', str(self.folder),
                            '--name', 'release.tar', '--sha256', self.digest, '--size', too_large]
        publisher_command = [sys.executable, str(Path(receiver.__file__).with_name('publisher.py')), 'receive-prepare',
                             '--staging-directory', str(self.folder), '--artifact-name', 'release.tar',
                             '--artifact-size', too_large, '--expected-digest', self.digest,
                             '--runtime-uid', '12345', '--runtime-gid', '12345']
        for name in ('source', 'candidate', 'evidence', 'base', 'root', 'storage', 'configuration', 'receipt', 'transport'):
            publisher_command.extend(['--' + name, str(self.folder / name)])
        for command in (receiver_command, publisher_command):
            with self.subTest(entrypoint=command[1]):
                process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                try:
                    self.assertNotEqual(process.wait(timeout=10), 0)
                finally:
                    if process.poll() is None:
                        process.kill()
                        process.wait()
                    process.stdin.close()
                    process.stdout.close()
                    process.stderr.close()
                self.assertEqual(list(self.folder.iterdir()), [])

    def test_cli_receives_and_rejects_existing_target(self):
        command = [sys.executable, str(Path(receiver.__file__)), '--directory', str(self.folder),
                   '--name', 'release.tar', '--sha256', self.digest, '--size', str(len(self.data))]
        first = subprocess.run(command, input=self.data, capture_output=True)
        self.assertEqual(first.returncode, 0, first.stderr)
        second = subprocess.run(command, input=self.data, capture_output=True)
        self.assertNotEqual(second.returncode, 0)
        self.assertEqual((self.folder / 'release.tar').read_bytes(), self.data)
        self.assertEqual(sorted(p.name for p in self.folder.iterdir()), ['release.tar'])

    def test_invalid_length_digest_and_symlink_leave_no_published_file(self):
        for data, size, digest in [(self.data[:-1], len(self.data), self.digest),
                                   (self.data + b'x', len(self.data), self.digest),
                                   (self.data, len(self.data), '0' * 64)]:
            with self.assertRaises(Exception):
                receiver.receive(io.BytesIO(data), self.folder, 'release.tar', digest, size)
            self.assertEqual(list(self.folder.iterdir()), [])
        (self.folder / 'release.tar').symlink_to(self.folder / 'untouched')
        with self.assertRaises(Exception):
            receiver.receive(io.BytesIO(self.data), self.folder, 'release.tar', self.digest, len(self.data))
        self.assertFalse((self.folder / 'untouched').exists())

    def test_competing_receivers_never_replace_winner(self):
        barrier = threading.Barrier(2)
        outcomes = []
        def run():
            barrier.wait()
            try:
                receiver.receive(io.BytesIO(self.data), self.folder, 'release.tar', self.digest, len(self.data))
                outcomes.append('published')
            except FileExistsError:
                outcomes.append('blocked')
        threads = [threading.Thread(target=run) for _ in range(2)]
        for thread in threads: thread.start()
        for thread in threads: thread.join()
        self.assertCountEqual(outcomes, ['published', 'blocked'])
        self.assertEqual((self.folder / 'release.tar').read_bytes(), self.data)
        self.assertEqual(sorted(p.name for p in self.folder.iterdir()), ['release.tar'])

    def test_mutable_staging_directory_is_rejected(self):
        self.folder.chmod(0o777)
        with self.assertRaises(Exception):
            receiver.receive(io.BytesIO(self.data), self.folder, 'release.tar', self.digest, len(self.data))
        self.assertEqual(list(self.folder.iterdir()), [])


if __name__ == '__main__':
    unittest.main()
