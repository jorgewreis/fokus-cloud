import unittest
import xml.etree.ElementTree as ET

from idempotency_mysql_lab import FILES, REQUIRED, junit_config, preflight, validate_volume


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


if __name__ == "__main__":
    unittest.main()
