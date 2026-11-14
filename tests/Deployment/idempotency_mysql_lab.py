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

from idempotency_suite_evidence import validate_selection

ROOT = Path(__file__).resolve().parents[2]
VOLUME = re.compile(r"fokus-law-linux-[0-9a-f]{12}-files\Z")
SHA = re.compile(r"[0-9a-f]{40}\Z")
UUID = re.compile(r"[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\Z")
PASSWORD = "security-test-only"

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


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--volume", required=True)
    parser.add_argument("--candidate-sha", required=True)
    parser.add_argument("--profile", required=True)
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--execute", action="store_true")
    args = parser.parse_args()
    profiles = json.loads((ROOT / "tests/Deployment/linux-runtime/profiles.json").read_text(encoding="utf-8"))
    mysql_image, runtime_image = preflight(enabled=os.environ.get("RELEASE_DISPOSABLE_TESTS"),
                                           ci=os.environ.get("CI"), platform=os.name,
                                           volume=args.volume, source_sha=args.candidate_sha,
                                           profile=args.profile, profiles=profiles)
    if not args.execute:
        raise RuntimeError("Explicit --execute required")
    # A fresh, exclusively named output directory prevents stale evidence reuse.
    args.report.mkdir(parents=False, exist_ok=False)
    volume = json.loads(docker("volume", "inspect", args.volume).stdout)[0]
    validate_volume(volume)
    tag = "fokus-idempotency-" + uuid.uuid4().hex[:12]
    mysql, runner = tag + "-mysql", tag + "-runner"
    created: list[str] = []
    try:
        docker("run", "-d", "--pull=never", "--name", mysql, "--restart=no",
               "--network", "none", "--label", "fokus.release.disposable=true",
               "--tmpfs", "/var/lib/mysql", "-e", "MYSQL_ROOT_PASSWORD=" + PASSWORD,
               "-e", "MYSQL_DATABASE=fokus_idempotency_api_test", mysql_image)
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
        docker("run", "-d", "--pull=never", "--name", runner, "--restart=no",
               "--network", "container:" + mysql, "--label", "fokus.release.disposable=true",
               "--mount", "type=volume,src=" + args.volume + ",dst=/work", runtime_image,
               "sleep", "infinity")
        created.append(runner)
        docker("exec", "--user", "0", runner, "sh", "-ec",
               "test -d /work/release && ln -s /work/release /workspace && "
               "test ! -e /workspace/.env && test ! -e /workspace/.env.testing && "
               "test ! -e /workspace/bootstrap/cache/config.php && "
               "printf '%s\\n' '<?php require \"/workspace/vendor/autoload.php\";' > /test-bootstrap.php")
        observed = docker("exec", runner, "git", "-c", "safe.directory=/workspace", "-C", "/workspace",
                          "rev-parse", "HEAD").stdout.decode().strip()
        if observed != args.candidate_sha:
            raise RuntimeError("Laboratory checkout differs from candidate")
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
        result = docker("exec", "--user", "1000:1000", "-w", "/workspace", runner,
                        "env", "-i", *flags, "php", "-d", "allow_url_fopen=0", "-d",
                        "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,shell_exec,system,passthru,popen,proc_open",
                        "/workspace/vendor/phpunit/phpunit/phpunit", "--configuration", "/tmp/idempotency-phpunit.xml",
                        "--log-junit", "/tmp/idempotency-junit.xml", "--colors=never", "--do-not-cache-result", check=False)
        (args.report / "phpunit.log").write_bytes(result.stdout + result.stderr)
        copied = docker("cp", runner + ":/tmp/idempotency-junit.xml", str(args.report / "junit.xml"), check=False)
        if result.returncode != 0 or copied.returncode != 0:
            raise RuntimeError("Laboratory tests or evidence collection failed")
        evidence = validate_selection(args.report / "junit.xml", profile="mysql", source_sha=args.candidate_sha,
                                      observed_sha=observed, required=REQUIRED)
        evidence.update({"scope": "isolated MySQL laboratory only; not release approval",
                         "release_gate_integrated": False, "server_uuid_checked_by_test_bootstrap": True})
        (args.report / "evidence.json").write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    finally:
        # Only stop the containers this invocation created; do not delete them.
        for name in reversed(created):
            docker("stop", name, check=False)


if __name__ == "__main__":
    main()
