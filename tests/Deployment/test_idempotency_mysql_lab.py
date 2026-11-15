import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET
from pathlib import Path
import re
import subprocess
import os
import shutil
import json
import tempfile

import idempotency_mysql_lab as laboratory
from idempotency_mysql_lab import (BLOCKED_FUNCTIONS, FILES, METHODS, REQUIRED, created_id,
                                   junit_config, mysql_tmpfs, numeric_identity, php_prefix,
                                   preflight, summarize_mysql_startup, validate_volume)


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

    def test_mysql_tmpfs_owner_is_image_identity_and_mode_is_private(self):
        uid = numeric_identity(subprocess.CompletedProcess([], 0, b"1001\n"))
        gid = numeric_identity(subprocess.CompletedProcess([], 0, b"1001\n"))
        self.assertEqual(mysql_tmpfs(uid, gid), "/var/lib/mysql:rw,uid=1001,gid=1001,mode=0700")
        self.assertEqual(mysql_tmpfs(0, 0), "/var/lib/mysql:rw,uid=0,gid=0,mode=0700")
        for output in (b"", b"-1", b"1,mode=0777", b"2147483648"):
            with self.subTest(output=output), self.assertRaises(RuntimeError):
                numeric_identity(subprocess.CompletedProcess([], 0, output))
        for uid in (-1, True, 2**31):
            with self.subTest(uid=uid), self.assertRaises(ValueError):
                mysql_tmpfs(uid, 1001)

    def test_mysql_diagnostics_never_retains_log_or_state_values(self):
        marker = "do-not-copy-sensitive-marker"
        result = summarize_mysql_startup({"Running": False, "OOMKilled": True, "ExitCode": 1,
                                          "Error": marker},
                                         ("permission denied " + marker).encode())
        self.assertEqual(result["exit_code"], 1)
        self.assertTrue(result["oom_killed"])
        self.assertTrue(result["indicators"]["permission_denied"])
        self.assertNotIn(marker, json.dumps(result))
        malformed = summarize_mysql_startup({"ExitCode": marker}, marker.encode())
        self.assertIsNone(malformed["exit_code"])
        self.assertNotIn(marker, json.dumps(malformed))

    def test_php_prefix_requires_fail_closed_prepend(self):
        flags = php_prefix()
        self.assertIn("allow_url_fopen=1", flags)
        self.assertIn("disable_functions=" + BLOCKED_FUNCTIONS, flags)
        self.assertIn("auto_prepend_file=/workspace/tests/Deployment/idempotency-http-guard.php", flags)

    @unittest.skipUnless(shutil.which("php"), "PHP CLI is required")
    def test_transport_guard_removes_wrappers_and_refuses_unsafe_configuration(self):
        root = Path(__file__).resolve().parents[2]
        guard = root / "tests/Deployment/idempotency-http-guard.php"
        env = dict(os.environ, APP_ENV="testing", FOKUS_COMPANY_IDEMPOTENCY_LAB="1",
                   DB_CONNECTION="mysql", DB_HOST="127.0.0.1", DB_DATABASE="fokus_idempotency_api_test",
                   MAIL_MAILER="array")
        with tempfile.TemporaryDirectory() as directory:
            proof = Path(directory) / "proof.php"
            proof.write_text('<?php echo json_encode(["wrappers" => stream_get_wrappers(), '
                             '"restore" => function_exists("stream_wrapper_restore"), '
                             '"register" => function_exists("stream_wrapper_register")]);', encoding="utf-8")
            def run(*, disabled=BLOCKED_FUNCTIONS, fopen="1", environment=env):
                return subprocess.run(["php", "-d", "allow_url_fopen=" + fopen, "-d",
                                       "disable_functions=" + disabled, "-d", "auto_prepend_file=" + str(guard),
                                       str(proof)], env=environment, capture_output=True, text=True)
            accepted = run()
            self.assertEqual(accepted.returncode, 0, accepted.stderr)
            state = json.loads(accepted.stdout)
            self.assertFalse({"http", "https", "ftp", "ftps"} & set(state["wrappers"]))
            self.assertFalse(state["restore"])
            self.assertFalse(state["register"])
            self.assertNotEqual(run(disabled="").returncode, 0)
            self.assertNotEqual(run(disabled=BLOCKED_FUNCTIONS.replace("stream_wrapper_restore,", "")).returncode, 0)
            self.assertNotEqual(run(disabled=BLOCKED_FUNCTIONS.replace("stream_wrapper_register,", "")).returncode, 0)
            self.assertNotEqual(run(fopen="0").returncode, 0)
            self.assertNotEqual(run(environment=dict(env, DB_DATABASE="unsafe")).returncode, 0)

    @unittest.skipUnless(shutil.which("php"), "PHP CLI is required")
    def test_unmocked_http_cannot_connect_to_listening_loopback_sentinel(self):
        root = Path(__file__).resolve().parents[2]
        guard = root / "tests/Deployment/idempotency-http-guard.php"
        env = dict(os.environ, APP_ENV="testing", FOKUS_COMPANY_IDEMPOTENCY_LAB="1",
                   DB_CONNECTION="mysql", DB_HOST="127.0.0.1", DB_DATABASE="fokus_idempotency_api_test",
                   MAIL_MAILER="array")
        with tempfile.TemporaryDirectory() as directory:
            proof = Path(directory) / "sentinel.php"
            proof.write_text('<?php $s=stream_socket_server("tcp://127.0.0.1:0"); '
                             'if ($s===false) exit(2); $a=stream_socket_get_name($s,false); '
                             'stream_set_blocking($s,false); $response=@file_get_contents("http://".$a."/"); '
                             '$connection=@stream_socket_accept($s,0); fclose($s); '
                             'echo json_encode(["response"=>$response,"connected"=>$connection!==false]);', encoding="utf-8")
            result = subprocess.run(["php", "-d", "allow_url_fopen=1", "-d",
                                     "disable_functions=" + BLOCKED_FUNCTIONS, "-d", "auto_prepend_file=" + str(guard),
                                     str(proof)], env=env, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout), {"response": False, "connected": False})

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
