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
