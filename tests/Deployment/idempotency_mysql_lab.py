"""Dedicated, opt-in idempotency MySQL laboratory; not a release-gate result.

Run only from the reviewed Linux release orchestrator after its source-volume
and runtime-image checks. The laboratory creates its own networkless MySQL;
it never connects to the release suite's database. This file is intentionally
not wired into the gate until its full execution is independently validated.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import time
import uuid
import xml.etree.ElementTree as ET

from idempotency_attestation import digest, source_manifest, validate_receipt
from idempotency_suite_evidence import validate_selection

ROOT = Path(__file__).resolve().parents[2]
VOLUME = re.compile(r"fokus-law-linux-[0-9a-f]{12}-files\Z")
SHA = re.compile(r"[0-9a-f]{40}\Z")
UUID = re.compile(r"[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\Z")
CONTAINER_ID = re.compile(r"[0-9a-f]{64}\Z")
PASSWORD = "security-test-only"
BLOCKED_FUNCTIONS = ("curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,"
                     "socket_connect,stream_wrapper_register,stream_wrapper_restore,"
                     "exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec")

# These are independent evidence, not additional main-suite cases. Minimums
# are provisional; the release gate must adopt measured individual minima.
METHODS = {
    r"Tests\Feature\CompanyIdempotencyApiLabTest": [
        "test_create_replay_is_one_company_one_audit_and_one_password_check",
        "test_changed_payload_or_actor_cannot_replay_a_create",
        "test_update_replay_precedes_optimistic_version_check",
        "test_disabled_or_malformed_config_never_mutates_when_guard_is_bypassed_in_lab",
        "test_permission_removed_during_password_preflight_prevents_the_mutation",
        "test_issue_status_and_revoke_are_actor_bound_and_do_not_expose_key_in_audit",
    ],
    r"Tests\Feature\CompanyIdempotencyDisabledTest": [
        "test_keyed_company_writes_fail_closed_without_echoing_the_key_or_writing",
        "test_legacy_requests_without_key_keep_validation_contract",
        "test_authentication_and_permission_are_checked_before_disabled_guard",
        "test_management_endpoints_remain_closed_even_if_configuration_is_enabled",
    ],
    r"Tests\Isolated\CompanyIdempotencyApiConcurrencyTest": [
        "test_two_independent_requests_replay_after_one_commits_during_preflight",
    ],
    r"Tests\Isolated\CompanyIdempotencyPreflightRaceTest": [
        "test_failed_preflight_is_not_definitive_while_matching_request_can_commit",
        "test_failed_writer_is_not_definitive_while_matching_request_waits_for_lock",
    ],
}
REQUIRED = {f"{cls}::{method}": 1 for cls, methods in METHODS.items() for method in methods}
FILES = (
    "/workspace/tests/Feature/CompanyIdempotencyApiLabTest.php",
    "/workspace/tests/Feature/CompanyIdempotencyDisabledTest.php",
    "/workspace/tests/Isolated/CompanyIdempotencyApiConcurrencyTest.php",
    "/workspace/tests/Isolated/CompanyIdempotencyPreflightRaceTest.php",
)


def preflight(*, enabled: str | None, ci: str | None, platform: str,
              volume: str, source_sha: str, profile: str, profiles: dict) -> tuple[str, str]:
    """Reject unsafe invocation before *any* Docker call."""
    if enabled != "1" or ci != "true" or platform != "posix":
        raise ValueError("Explicit isolated Linux CI opt-in required")
    if not VOLUME.fullmatch(volume) or not SHA.fullmatch(source_sha):
        raise ValueError("Invalid disposable volume or source identity")
    if profile not in {"target", "compatibility"} or profile not in profiles:
        raise ValueError("Unknown release profile")
    mysql, runtime = profiles[profile].get("mysql_image"), profiles[profile].get("runtime_image")
    if (not isinstance(mysql, str) or not re.fullmatch(r"[a-z0-9./_-]+@sha256:[0-9a-f]{64}", mysql)
            or not isinstance(runtime, str) or not re.fullmatch(r"fokus-law-linux-qa:[a-z0-9-]+", runtime)):
        raise ValueError("Unreviewed runtime or MySQL image")
    return mysql, runtime


def junit_config() -> bytes:
    root = ET.Element("phpunit", bootstrap="/workspace/tests/Isolated/company-idempotency-api-mysql-bootstrap.php", colors="false")
    suite = ET.SubElement(ET.SubElement(root, "testsuites"), "testsuite", name="idempotency-isolated-mysql")
    for file in FILES:
        ET.SubElement(suite, "file").text = file
    return ET.tostring(root, encoding="utf-8", xml_declaration=True)


def php_prefix() -> list[str]:
    # Stream handler construction is needed for Laravel Http::fake. The
    # auto-prepend guard removes network wrappers and verifies all functions.
    return ["php", "-d", "allow_url_fopen=1", "-d", "disable_functions=" + BLOCKED_FUNCTIONS,
            "-d", "auto_prepend_file=/workspace/tests/Deployment/idempotency-http-guard.php"]


def validate_volume(volume: object) -> None:
    """Refuse bind-backed, unlabelled or malformed volumes."""
    if (not isinstance(volume, dict) or volume.get("Driver") != "local"
            or volume.get("Options") not in (None, {})
            or not isinstance(volume.get("Labels"), dict)
            or volume["Labels"].get("fokus.release.disposable") != "true"):
        raise ValueError("Volume is not a release-owned disposable volume")


def docker(*args: str, input_data: bytes | None = None, check: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(["docker", *args], input=input_data, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, check=check)


def created_id(result: subprocess.CompletedProcess) -> str:
    value = result.stdout.decode("ascii").strip()
    if not CONTAINER_ID.fullmatch(value):
        raise RuntimeError("Docker did not return a full container ID")
    return value


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--volume", required=True)
    parser.add_argument("--candidate-sha", required=True)
    parser.add_argument("--profile", required=True)
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--dependency-receipt", type=Path, required=True)
    parser.add_argument("--dependency-receipt-sha256", required=True)
    parser.add_argument("--runtime-image-id", required=True)
    parser.add_argument("--execute", action="store_true")
    args = parser.parse_args()
    profiles = json.loads((ROOT / "tests/Deployment/linux-runtime/profiles.json").read_text(encoding="utf-8"))
    mysql_image, runtime_image = preflight(enabled=os.environ.get("RELEASE_DISPOSABLE_TESTS"),
                                           ci=os.environ.get("CI"), platform=os.name,
                                           volume=args.volume, source_sha=args.candidate_sha,
                                           profile=args.profile, profiles=profiles)
    if not args.execute:
        raise RuntimeError("Explicit --execute required")
    if not re.fullmatch(r"sha256:[0-9a-f]{64}", args.runtime_image_id):
        raise RuntimeError("An immutable runtime image ID is required")
    # The receipt must be supplied by the release orchestrator that recorded
    # the locked install. A digest alone does not attest the receipt's origin.
    if args.dependency_receipt.is_symlink() or not args.dependency_receipt.is_file():
        raise RuntimeError("Dependency receipt must be a regular file")
    source = source_manifest(ROOT, args.candidate_sha)
    source_hash = {row["path"]: row["sha256"] for row in source["files"]}
    for name in ("tests/Deployment/idempotency_mysql_lab.py",
                 "tests/Deployment/idempotency_attestation.py",
                 "tests/Deployment/idempotency_suite_evidence.py",
                 "tests/Deployment/linux-runtime/profiles.json"):
        if name not in source_hash or digest((ROOT / name).read_bytes()) != source_hash[name]:
            raise RuntimeError("Executing laboratory code differs from candidate")
    receipt_data = args.dependency_receipt.read_bytes()
    receipt = validate_receipt(receipt_data, args.dependency_receipt_sha256, source)
    source_data = json.dumps(source, separators=(",", ":")).encode()
    vendor_data = json.dumps(receipt, separators=(",", ":")).encode()
    helper_data = (ROOT / "tests/Deployment/idempotency_attestation.py").read_bytes()
    # A fresh, exclusively named output directory prevents stale evidence reuse.
    args.report.mkdir(parents=False, exist_ok=False)
    volume = json.loads(docker("volume", "inspect", args.volume).stdout)[0]
    validate_volume(volume)
    runtime = json.loads(docker("image", "inspect", args.runtime_image_id).stdout)[0]
    if runtime.get("Id") != args.runtime_image_id:
        raise RuntimeError("Runtime image identity changed")
    tag = "fokus-idempotency-" + uuid.uuid4().hex[:12]
    mysql_name, runner_name = tag + "-mysql", tag + "-runner"
    created: list[str] = []
    try:
        mysql = created_id(docker("run", "-d", "--pull=never", "--name", mysql_name, "--restart=no",
                                  "--network", "none", "--label", "fokus.release.disposable=true",
                                  "--tmpfs", "/var/lib/mysql", "-e", "MYSQL_ROOT_PASSWORD=" + PASSWORD,
                                  "-e", "MYSQL_DATABASE=fokus_idempotency_api_test", mysql_image))
        created.append(mysql)
        for _ in range(90):
            if docker("exec", mysql, "mysqladmin", "ping", "-h127.0.0.1", "-uroot",
                      "--password=" + PASSWORD, check=False).returncode == 0:
                break
            time.sleep(2)
        else:
            raise RuntimeError("Disposable MySQL did not become ready")
        db_uuid = docker("exec", mysql, "mysql", "-h127.0.0.1", "-uroot", "--password=" + PASSWORD,
                         "--batch", "--skip-column-names", "-e", "SELECT @@server_uuid").stdout.decode().strip()
        if not UUID.fullmatch(db_uuid):
            raise RuntimeError("Invalid laboratory server identity")
        runner = created_id(docker("run", "-d", "--pull=never", "--name", runner_name, "--restart=no",
                                   "--network", "container:" + mysql, "--label", "fokus.release.disposable=true",
                                   "--mount", "type=volume,src=" + args.volume + ",dst=/input,readonly",
                                   "--tmpfs", "/workspace:rw,exec,size=2g", args.runtime_image_id,
                                   "sleep", "infinity"))
        created.append(runner)
        docker("exec", "--user", "0", runner, "sh", "-ec",
               "test -d /input/release && test ! -e /input/release/.env && "
               "test ! -e /input/release/.env.testing && test ! -e /input/release/bootstrap/cache/config.php && "
               "printf '%s\\n' '<?php require \"/workspace/vendor/autoload.php\";' > /test-bootstrap.php")
        for path, data in (("/tmp/idempotency-attestation.py", helper_data),
                           ("/tmp/idempotency-source.json", source_data),
                           ("/tmp/idempotency-vendor.json", vendor_data)):
            # Paths are fixed literals, not user-supplied shell fragments.
            docker("exec", "-i", "--user", "0", runner, "sh", "-ec", "umask 077; cat > " + path,
                   input_data=data)
        docker("exec", "--user", "0", runner, "python3", "/tmp/idempotency-attestation.py", "copy")
        docker("exec", "--user", "0", runner, "python3", "-c",
               "import sys;from pathlib import Path;sys.path.insert(0,'/workspace/tests/Deployment');"
               "from linux_vendor import check_packages;check_packages(Path('/workspace'))")
        # The PHPUnit process cannot modify code/vendor. Laravel only needs
        # its cache and storage directories writable; source is rechecked.
        docker("exec", "--user", "0", runner, "sh", "-ec",
               "chmod -R a-w /workspace && mkdir -p /workspace/storage/framework/cache "
               "/workspace/storage/framework/sessions /workspace/storage/framework/views "
               "/workspace/storage/logs /workspace/bootstrap/cache && "
               "chown -R 1000:1000 /workspace/storage /workspace/bootstrap/cache && "
               "chmod -R u+rwX /workspace/storage /workspace/bootstrap/cache")
        docker("exec", "-i", "--user", "0", runner, "sh", "-ec", "cat > /tmp/idempotency-phpunit.xml",
               input_data=junit_config())
        env = {
            "APP_ENV": "testing", "APP_DEBUG": "false", "APP_BASE_PATH": "/workspace",
            "APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", "BCRYPT_ROUNDS": "4",
            "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_PORT": "3306",
            "DB_DATABASE": "fokus_idempotency_api_test", "DB_USERNAME": "root", "DB_PASSWORD": PASSWORD,
            "DB_URL": "", "DATABASE_URL": "", "DB_SOCKET": "", "LAB_MYSQL_UUID": db_uuid,
            "FOKUS_COMPANY_IDEMPOTENCY_LAB": "1", "CACHE_STORE": "array", "CACHE_LIMITER": "array",
            "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync", "MAIL_MAILER": "array",
            "BROADCAST_CONNECTION": "null", "FILESYSTEM_DISK": "local", "LAW_TASKS_ENABLED": "false",
            "PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/tmp",
        }
        # Discard inherited container environment, including alternate config
        # and database URL variables, before bootstrapping Laravel.
        flags = [key + "=" + value for key, value in env.items()]
        transport = docker("exec", "--user", "1000:1000", "-w", "/workspace", runner,
                           "env", "-i", *flags, *php_prefix(),
                           "/workspace/tests/Deployment/idempotency-http-preflight.php", check=False)
        (args.report / "http-preflight.log").write_bytes(transport.stdout + transport.stderr)
        if transport.returncode != 0:
            raise RuntimeError("Fake transport or unmocked HTTP preflight failed")
        result = docker("exec", "--user", "1000:1000", "-w", "/workspace", runner,
                        "env", "-i", *flags, *php_prefix(),
                        "/workspace/vendor/phpunit/phpunit/phpunit", "--configuration", "/tmp/idempotency-phpunit.xml",
                        "--log-junit", "/tmp/idempotency-junit.xml", "--colors=never", "--do-not-cache-result", check=False)
        (args.report / "phpunit.log").write_bytes(result.stdout + result.stderr)
        copied = docker("cp", runner + ":/tmp/idempotency-junit.xml", str(args.report / "junit.xml"), check=False)
        docker("exec", "--user", "0", runner, "python3", "/tmp/idempotency-attestation.py", "verify")
        if result.returncode != 0 or copied.returncode != 0:
            raise RuntimeError("Laboratory tests or evidence collection failed")
        evidence = validate_selection(args.report / "junit.xml", profile="mysql", source_sha=args.candidate_sha,
                                      observed_sha=source["commit"], required=REQUIRED)
        evidence.update({"scope": "isolated MySQL laboratory only; not release approval",
                         "release_gate_integrated": False, "server_uuid_checked_by_test_bootstrap": True,
                         "source_manifest_sha256": digest(source_data),
                         "dependency_receipt_sha256": args.dependency_receipt_sha256,
                         "vendor_tree_sha256": receipt["vendor_tree_sha256"],
                         "runtime_image_id": args.runtime_image_id,
                         "reviewed_runtime_tag": runtime_image,
                         "receipt_origin_requires_orchestrator_attestation": True})
        (args.report / "evidence.json").write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    finally:
        # Only stop the containers this invocation created; do not delete them.
        for name in reversed(created):
            docker("stop", name, check=False)


if __name__ == "__main__":
    main()
