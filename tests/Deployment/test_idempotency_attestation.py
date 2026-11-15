import json
from pathlib import Path
import subprocess
import tempfile
import unittest

from idempotency_attestation import (copy_attested, digest, safe_path, source_manifest, validate_receipt,
                                     verify_attested, verify_vendor_inventory)


def row(path, content):
    return {"path": path, "sha256": digest(content), "bytes": len(content)}


class AttestationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.destination = self.root / "destination"
        self.source.mkdir()
        self.destination.mkdir()

    def receipt(self):
        composer = b'{"require":{}}'
        lock = b'{"packages":[]}'
        installed = b'{"packages":[],"dev":true}'
        vendor = [row("autoload.php", b"<?php"), row("composer/installed.json", installed)]
        source = {"commit": "a" * 40, "files": [row("composer.json", composer), row("composer.lock", lock)]}
        receipt = {"origin": {"kind": "verified-candidate-locked-install-snapshot"},
                   "composer_json_sha256": digest(composer), "composer_lock_sha256": digest(lock),
                   "installed_json_sha256": digest(installed), "vendor_files": vendor,
                   "vendor_tree_sha256": digest(json.dumps(vendor, separators=(",", ":")).encode())}
        data = json.dumps(receipt).encode()
        return source, receipt, data

    def test_receipt_is_paired_to_source_and_fixed_digest(self):
        source, receipt, data = self.receipt()
        self.assertEqual(validate_receipt(data, digest(data), source), receipt)
        with self.assertRaises(ValueError):
            validate_receipt(data, "0" * 64, source)
        source["files"][1]["sha256"] = "0" * 64
        with self.assertRaises(ValueError):
            validate_receipt(data, digest(data), source)

    def test_untrusted_receipt_origin_duplicate_and_tree_change_fail(self):
        source, receipt, _ = self.receipt()
        for change in ("origin", "duplicate", "tree", "installed"):
            candidate = json.loads(json.dumps(receipt))
            if change == "origin":
                candidate["origin"]["kind"] = "untrusted"
            elif change == "duplicate":
                candidate["vendor_files"].append(candidate["vendor_files"][0])
            elif change == "tree":
                candidate["vendor_tree_sha256"] = "0" * 64
            else:
                candidate["installed_json_sha256"] = "0" * 64
            data = json.dumps(candidate).encode()
            with self.subTest(change=change), self.assertRaises(ValueError):
                validate_receipt(data, digest(data), source)

    def test_duplicate_json_keys_are_rejected_even_with_matching_digest(self):
        source, _, data = self.receipt()
        data = data.replace(b'{"origin":', b'{"origin":{},"origin":', 1)
        with self.assertRaises(ValueError):
            validate_receipt(data, digest(data), source)

    def test_source_or_vendor_mutation_is_detected_before_and_after_copy(self):
        for name in ("app/code.php", "vendor/autoload.php"):
            source = self.source / name
            source.parent.mkdir(parents=True, exist_ok=True)
            source.write_bytes(b"reviewed")
            rows = [row(name, b"reviewed")]
            source.write_bytes(b"changed")
            with self.subTest(name=name), self.assertRaises(ValueError):
                copy_attested(self.source, self.destination, rows)
            source.write_bytes(b"reviewed")
            copy_attested(self.source, self.destination, rows)
            verify_attested(self.destination, rows)
            copied = self.destination / name
            copied.chmod(0o644)
            copied.write_bytes(b"changed")
            with self.subTest(name=name), self.assertRaises(ValueError):
                verify_attested(self.destination, rows)

    def test_unlisted_vendor_file_and_unsafe_paths_are_rejected(self):
        (self.destination / "autoload.php").write_bytes(b"ok")
        rows = [row("autoload.php", b"ok")]
        verify_vendor_inventory(self.destination, rows)
        (self.destination / "injected.php").write_bytes(b"bad")
        with self.assertRaises(ValueError):
            verify_vendor_inventory(self.destination, rows)
        for value in ("../x", "/absolute", "a/../b", "a//b", "a\\b", "", None):
            with self.subTest(value=value), self.assertRaises(ValueError):
                safe_path(value)

    def test_manifest_comes_from_commit_not_mutable_worktree(self):
        repo = self.root / "repo"
        repo.mkdir()
        subprocess.run(["git", "init", "-q", str(repo)], check=True, capture_output=True)
        source = repo / "source.php"
        source.write_bytes(b"reviewed")
        subprocess.run(["git", "-C", str(repo), "add", "source.php"], check=True, capture_output=True)
        subprocess.run(["git", "-C", str(repo), "-c", "user.name=Test", "-c", "user.email=test@example.test",
                        "-c", "commit.gpgsign=false", "commit", "-qm", "synthetic"], check=True, capture_output=True)
        sha = subprocess.check_output(["git", "-C", str(repo), "rev-parse", "HEAD"], text=True).strip()
        source.write_bytes(b"modified")
        manifest = source_manifest(repo, sha)
        self.assertEqual(manifest["files"][0]["sha256"], digest(b"reviewed"))
        with self.assertRaises(ValueError):
            copy_attested(repo, self.destination, manifest["files"])
        with self.assertRaises(ValueError):
            source_manifest(repo, "0" * 40)


if __name__ == "__main__":
    unittest.main()
