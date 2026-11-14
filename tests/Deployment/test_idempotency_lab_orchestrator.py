import unittest
import copy
import io
from pathlib import Path
import json
import os
import subprocess
import tarfile
import tempfile
import xml.etree.ElementTree as ET
from unittest.mock import patch
import re

import idempotency_lab_orchestrator as orchestrator
from idempotency_attestation import digest
from idempotency_lab_orchestrator import COMPOSER, REQUIRED, validate_context, validate_runtime, verify_lab_output
from idempotency_suite_evidence import validate_selection


class IdempotencyOrchestratorTest(unittest.TestCase):
    def test_only_exact_ci_candidate_is_accepted(self):
        valid = dict(ci="true", opt_in="1", platform="posix", github_sha="a" * 40,
                     candidate_sha="a" * 40, repository="jorgewreis/fokus-cloud")
        validate_context(**valid)
        for change in ({"ci": "false"}, {"opt_in": "0"}, {"platform": "nt"},
                       {"github_sha": "b" * 40}, {"candidate_sha": "a" * 39}, {"github_sha": None},
                       {"repository": "untrusted/fork"}):
            with self.subTest(change=change), self.assertRaises(ValueError):
                validate_context(**dict(valid, **change))

    def test_runtime_inspection_must_match_immutable_build_id(self):
        image = "sha256:" + "a" * 64
        validate_runtime(image, {"Id": image})
        for actual, inspected in (("latest", {"Id": image}), (image, {"Id": "sha256:" + "b" * 64}),
                                  (image, {}), (image, None)):
            with self.subTest(image=actual, inspected=inspected), self.assertRaises(ValueError):
                validate_runtime(actual, inspected)

    def test_install_does_not_execute_composer_plugins_or_scripts(self):
        self.assertEqual(COMPOSER[:2], ["composer", "install"])
        self.assertIn("--no-plugins", COMPOSER)
        self.assertIn("--no-scripts", COMPOSER)
        self.assertIn("--no-interaction", COMPOSER)

    def test_pr_event_requires_explicit_exact_head_from_same_repository(self):
        candidate = "a" * 40
        repository = "jorgewreis/fokus-cloud"
        event = {"action": "labeled", "label": {"name": "lab-" + candidate},
                 "pull_request": {"number": 99,
                                  "head": {"sha": candidate, "ref": "codex/idempotency-harness-20261114",
                                           "repo": {"full_name": repository}},
                                  "base": {"ref": "codex/company-idempotency-core-20261114",
                                           "repo": {"full_name": repository}}}}
        valid = dict(ci="true", opt_in="1", platform="posix", github_sha="b" * 40,
                     candidate_sha=candidate, event_name="pull_request", event=event,
                     repository=repository, github_ref="refs/pull/99/merge")
        validate_context(**valid)
        changes = [lambda x: x.update(action="synchronize"),
                   lambda x: x["label"].update(name="lab-" + "b" * 40),
                   lambda x: x["pull_request"].update(number=98),
                   lambda x: x["pull_request"]["head"].update(sha="b" * 40),
                   lambda x: x["pull_request"]["head"].update(ref="other"),
                   lambda x: x["pull_request"]["base"].update(ref="main"),
                   lambda x: x["pull_request"]["head"]["repo"].update(full_name="untrusted/fork"),
                   lambda x: x["pull_request"]["base"]["repo"].update(full_name="untrusted/fork")]
        for change in changes:
            edited = copy.deepcopy(event)
            change(edited)
            with self.subTest(edited=edited), self.assertRaises(ValueError):
                validate_context(**dict(valid, event=edited))
        for change in ({"github_ref": "refs/pull/98/merge"}, {"repository": "untrusted/fork"},
                       {"event_name": "push"}, {"event": None}, {"github_sha": "bad"}):
            with self.subTest(change=change), self.assertRaises(ValueError):
                validate_context(**dict(valid, **change))

    def test_manual_workflow_has_no_publication_or_secret_permissions(self):
        root = Path(__file__).resolve().parents[2]
        workflow = (root / ".github/workflows/idempotency-lab-ci.yml").read_text(encoding="utf-8")
        self.assertRegex(workflow, r"(?m)^  workflow_dispatch:\s*$")
        self.assertNotRegex(workflow, r"(?m)^  (push|schedule):")
        self.assertRegex(workflow, r"(?m)^  pull_request:\s*$")
        self.assertIn("types: [labeled]", workflow)
        self.assertIn("github.event.pull_request.number == 99", workflow)
        self.assertIn("format('lab-{0}', github.event.pull_request.head.sha)", workflow)
        self.assertIn("github.event.pull_request.head.repo.full_name == github.repository", workflow)
        self.assertIn("github.event.pull_request.base.repo.full_name == github.repository", workflow)
        self.assertIn("contents: read", workflow)
        self.assertNotIn("secrets.", workflow)
        self.assertNotIn("environment: production", workflow)
        self.assertNotIn("id-token: write", workflow)
        self.assertIn('test "$GITHUB_SHA" = "$EXPECTED_CANDIDATE"', workflow)
        self.assertIn("steps.canary_scan.outcome == 'success'", workflow)
        actions = re.findall(r"(?m)^\s*uses:\s*([^\s#]+)", workflow)
        self.assertTrue(actions)
        for action in actions:
            self.assertRegex(action, r"^[^@]+@[0-9a-f]{40}$")

    def test_child_evidence_must_bind_source_runtime_vendor_and_actual_junit(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            junit = ET.Element("testsuite")
            for identity in REQUIRED:
                cls, method = identity.split("::")
                ET.SubElement(junit, "testcase", {"class": cls, "name": method, "assertions": "1"})
            junit_path = root / "junit.xml"
            ET.ElementTree(junit).write(junit_path, encoding="utf-8", xml_declaration=True)
            candidate, image, source, receipt, vendor = "a" * 40, "sha256:" + "b" * 64, "c" * 64, "d" * 64, "e" * 64
            evidence = validate_selection(junit_path, profile="mysql", source_sha=candidate,
                                          observed_sha=candidate, required=REQUIRED)
            evidence.update({"runtime_image_id": image, "source_manifest_sha256": source,
                             "dependency_receipt_sha256": receipt, "vendor_tree_sha256": vendor,
                             "release_gate_integrated": False, "server_uuid_checked_by_test_bootstrap": True,
                             "receipt_origin_requires_orchestrator_attestation": True})
            target = root / "evidence.json"
            target.write_text(json.dumps(evidence), encoding="utf-8")
            kwargs = dict(candidate=candidate, image_id=image, source_hash=source,
                          receipt_hash=receipt, vendor_hash=vendor)
            self.assertEqual(verify_lab_output(root, **kwargs)["cases"], 13)
            for key, replacement in (("runtime_image_id", "sha256:" + "f" * 64),
                                     ("source_manifest_sha256", "f" * 64),
                                     ("dependency_receipt_sha256", "f" * 64),
                                     ("vendor_tree_sha256", "f" * 64),
                                     ("junit_sha256", "f" * 64),
                                     ("release_gate_integrated", True),
                                     ("server_uuid_checked_by_test_bootstrap", False)):
                changed = dict(evidence, **{key: replacement})
                target.write_text(json.dumps(changed), encoding="utf-8")
                with self.subTest(key=key), self.assertRaises(ValueError):
                    verify_lab_output(root, **kwargs)
            target.unlink()
            with self.assertRaises(ValueError):
                verify_lab_output(root, **kwargs)

    def test_mocked_orchestrator_stops_setup_before_using_its_receipt(self):
        candidate = "a" * 40
        image_id = "sha256:" + "b" * 64
        mysql_image = "mysql@sha256:" + "c" * 64
        dockerfile = "tests/Deployment/linux-runtime/Dockerfile"
        source = {"commit": candidate, "files": [
            {"path": dockerfile, "sha256": digest(b"FROM scratch"), "bytes": 12, "executable": False},
            {"path": "composer.json", "sha256": digest(b"{}"), "bytes": 2, "executable": False},
            {"path": "composer.lock", "sha256": digest(b"lock"), "bytes": 4, "executable": False}]}
        rows = [{"path": "autoload.php", "sha256": digest(b"php"), "bytes": 3},
                {"path": "composer/installed.json", "sha256": digest(b"installed"), "bytes": 9}]
        receipt = {"origin": {"kind": "verified-candidate-locked-install-snapshot"},
                   "composer_json_sha256": digest(b"{}"), "composer_lock_sha256": digest(b"lock"),
                   "installed_json_sha256": digest(b"installed"), "vendor_files": rows,
                   "vendor_tree_sha256": digest(json.dumps(rows, separators=(",", ":")).encode())}
        receipt_bytes = json.dumps(receipt).encode()
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode="w") as archive:
            info = tarfile.TarInfo(dockerfile)
            info.size = len(b"FROM scratch")
            archive.addfile(info, io.BytesIO(b"FROM scratch"))
        events = []

        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            report = root / "report"
            args = ["runner", "--candidate-sha", candidate, "--profile", "compatibility",
                    "--report", str(report), "--execute"]
            profiles = {"compatibility": {"mysql_image": mysql_image,
                                          "runtime_image": "fokus-law-linux-qa:test",
                                          "dockerfile": dockerfile}}

            def fake_docker(*arguments, data=None, check=True):
                events.append(("docker", arguments))
                output = b""
                if arguments[:2] == ("image", "inspect"):
                    record = {"Id": image_id} if arguments[2] == image_id else {"RepoDigests": [mysql_image]}
                    output = json.dumps([record]).encode()
                elif arguments[:2] == ("volume", "create"):
                    output = (arguments[-1] + "\n").encode()
                elif arguments[:2] == ("volume", "inspect"):
                    token = arguments[-1].removeprefix("fokus-law-linux-").removesuffix("-files")
                    output = json.dumps([{"Driver": "local", "Options": None,
                                          "Labels": {"fokus.release.disposable": "true",
                                                     "fokus.release.run": token}}]).encode()
                elif arguments[0] == "run":
                    output = ("d" * 64 + "\n").encode()
                elif arguments[0] == "cp" and arguments[1].endswith("dependency-receipt.json"):
                    Path(arguments[2]).write_bytes(receipt_bytes)
                return subprocess.CompletedProcess(["docker", *arguments], 0, output, b"")

            def fake_run(arguments, **kwargs):
                events.append(("run", tuple(str(x) for x in arguments)))
                if arguments[:2] == ["docker", "build"]:
                    Path(arguments[arguments.index("--iidfile") + 1]).write_text(image_id, encoding="utf-8")
                    return subprocess.CompletedProcess(arguments, 0, b"build", b"")
                lab_report = Path(arguments[arguments.index("--report") + 1])
                lab_report.mkdir()
                junit = ET.Element("testsuite")
                for identity in REQUIRED:
                    cls, method = identity.split("::")
                    ET.SubElement(junit, "testcase", {"class": cls, "name": method, "assertions": "1"})
                junit_path = lab_report / "junit.xml"
                ET.ElementTree(junit).write(junit_path, encoding="utf-8", xml_declaration=True)
                evidence = validate_selection(junit_path, profile="mysql", source_sha=candidate,
                                              observed_sha=candidate, required=REQUIRED)
                evidence.update({"runtime_image_id": image_id,
                                 "source_manifest_sha256": digest((report / "source-manifest.json").read_bytes()),
                                 "dependency_receipt_sha256": digest(receipt_bytes),
                                 "vendor_tree_sha256": receipt["vendor_tree_sha256"],
                                 "release_gate_integrated": False, "server_uuid_checked_by_test_bootstrap": True,
                                 "receipt_origin_requires_orchestrator_attestation": True})
                (lab_report / "evidence.json").write_text(json.dumps(evidence), encoding="utf-8")
                return subprocess.CompletedProcess(arguments, 0, b"", b"")

            with patch("sys.argv", args), patch.dict(os.environ, {"RUNNER_TEMP": temp}, clear=False), \
                    patch.object(orchestrator, "validate_context"), \
                    patch.object(orchestrator, "checked_source", return_value=(source, profiles)), \
                    patch.object(orchestrator, "docker", side_effect=fake_docker), \
                    patch.object(orchestrator.subprocess, "check_output", return_value=buffer.getvalue()), \
                    patch.object(orchestrator.subprocess, "run", side_effect=fake_run):
                orchestrator.main()
            stops = [i for i, event in enumerate(events) if event[0] == "docker" and event[1][0] == "stop"]
            exports = [i for i, event in enumerate(events) if event[0] == "docker" and event[1][0] == "cp"
                       and event[1][1].endswith("dependency-receipt.json")]
            labs = [i for i, event in enumerate(events) if event[0] == "run" and "--runtime-image-id" in event[1]]
            self.assertLess(stops[0], exports[0])
            self.assertLess(exports[0], labs[0])
            self.assertIn(image_id, events[labs[0]][1])
            installed = [event for event in events if event[0] == "docker" and "composer" in event[1]]
            self.assertEqual(len(installed), 1)
            self.assertIn("--no-scripts", installed[0][1])
            self.assertIn("--no-plugins", installed[0][1])


if __name__ == "__main__":
    unittest.main()
