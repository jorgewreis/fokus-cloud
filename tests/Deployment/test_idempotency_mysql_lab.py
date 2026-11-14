import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET
from pathlib import Path
import re
import subprocess
import tempfile

import idempotency_mysql_lab as laboratory
from idempotency_mysql_lab import FILES, METHODS, REQUIRED, created_id, junit_config, preflight, validate_volume


class LaboratoryPreflightTest(unittest.TestCase):
    def setUp(self):
        self.args = dict(enabled="1", ci="true", platform="posix",
                         volume="fokus-law-linux-0123456789ab-files", source_sha="a" * 40,
                         profile="target", profiles={"target": {
                             "mysql_image": "mysql@sha256:" + "b" * 64,
                             "runtime_image": "fokus-law-linux-qa:20261107-node2413"}})

    def test_reviewed_profile_and_manifest(self):
        self.assertEqual(preflight(**self.args)[0], "mysql@sha256:" + "b" * 64)
        root = ET.fromstring(junit_config())
        self.assertEqual([node.text for node in root.findall(".//file")], list(FILES))
        self.assertEqual(len(REQUIRED), 13)
        self.assertTrue(all(value == 1 for value in REQUIRED.values()))

    def test_unsafe_execution_inputs_are_rejected(self):
        cases = ({"enabled": "0"}, {"ci": "false"}, {"platform": "nt"},
                 {"volume": "other"}, {"volume": "fokus-law-linux-0123456789ab-files/../../x"},
                 {"source_sha": "a" * 39}, {"profile": "unreviewed"},
                 {"profiles": {"target": {"mysql_image": "mysql:latest",
                                           "runtime_image": "fokus-law-linux-qa:stable"}}},
                 {"profiles": {"target": {"mysql_image": "mysql@sha256:" + "b" * 64,
                                           "runtime_image": "unreviewed:latest"}}})
        for change in cases:
            with self.subTest(change=change), self.assertRaises(ValueError):
                preflight(**dict(self.args, **change))

    def test_volume_requires_local_unbound_labelled_storage(self):
        good = {"Driver": "local", "Options": None,
                "Labels": {"fokus.release.disposable": "true"}}
        validate_volume(good)
        for volume in (None, {}, dict(good, Driver="nfs"), dict(good, Labels={}),
                       dict(good, Options={"type": "none", "device": "/real", "o": "bind"})):
            with self.subTest(volume=volume), self.assertRaises(ValueError):
                validate_volume(volume)

    def test_cleanup_targets_require_full_immutable_container_id(self):
        self.assertEqual(created_id(subprocess.CompletedProcess([], 0, b"a" * 64 + b"\n")), "a" * 64)
        for output in (b"", b"a" * 12, b"friendly-container", b"a" * 63 + b"z"):
            with self.subTest(output=output), self.assertRaises(RuntimeError):
                created_id(subprocess.CompletedProcess([], 0, output))

    def test_manifest_names_match_checked_in_test_methods(self):
        root = Path(__file__).resolve().parents[2]
        for cls, methods in METHODS.items():
            name = cls.rsplit("\\", 1)[-1] + ".php"
            directory = "Feature" if "\\Feature\\" in cls else "Isolated"
            source = (root / "tests" / directory / name).read_text(encoding="utf-8")
            discovered = re.findall(r"public\s+function\s+(test_[a-zA-Z0-9_]+)\s*\(", source)
            with self.subTest(cls=cls):
                self.assertEqual(sorted(discovered), sorted(methods))
                self.assertEqual(len(discovered), len(set(discovered)))

    def test_missing_execute_flag_never_calls_docker(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            profiles_dir = root / "tests" / "Deployment" / "linux-runtime"
            profiles_dir.mkdir(parents=True)
            (profiles_dir / "profiles.json").write_text('{}', encoding="utf-8")
            arguments = ["runner", "--volume", self.args["volume"], "--candidate-sha",
                         self.args["source_sha"], "--profile", "target", "--report", str(root / "out"),
                         "--dependency-receipt", str(root / "receipt.json"),
                         "--dependency-receipt-sha256", "a" * 64,
                         "--runtime-image-id", "sha256:" + "a" * 64]
            with patch.object(laboratory, "ROOT", root), patch("sys.argv", arguments), \
                    patch.object(laboratory, "preflight", return_value=("mysql", "runtime")), \
                    patch.object(laboratory, "docker") as mocked_docker:
                with self.assertRaisesRegex(RuntimeError, "--execute"):
                    laboratory.main()
                mocked_docker.assert_not_called()
                self.assertFalse((root / "out").exists())


if __name__ == "__main__":
    unittest.main()
