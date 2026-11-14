"""Prototype locked-install provenance chain for the isolated MySQL lab.

Requires a trusted ephemeral CI host and Docker daemon. This is not a release
gate or a signed provenance statement. Package servers and mutable apt inputs
remain outside this proof; the resulting runtime ID is pinned for this run.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import uuid

from idempotency_attestation import digest, source_manifest, validate_receipt
from idempotency_mysql_lab import REQUIRED, created_id, preflight, validate_volume
from idempotency_suite_evidence import validate_selection

ROOT = Path(__file__).resolve().parents[2]
IMAGE_ID = re.compile(r"sha256:[0-9a-f]{64}\Z")
COMPOSER = ["composer", "install", "--no-interaction", "--no-plugins", "--no-scripts",
            "--prefer-dist", "--no-progress", "--optimize-autoloader"]


def validate_context(*, ci: str | None, opt_in: str | None, platform: str,
                     github_sha: str | None, candidate_sha: str) -> None:
    if (ci != "true" or opt_in != "1" or platform != "posix"
            or not re.fullmatch(r"[0-9a-f]{40}", candidate_sha) or github_sha != candidate_sha):
        raise ValueError("Exact candidate and disposable Linux CI required")


def validate_runtime(image_id: str, inspected: object) -> None:
    if (not IMAGE_ID.fullmatch(image_id) or not isinstance(inspected, dict)
            or inspected.get("Id") != image_id):
        raise ValueError("Built runtime image identity differs")


def verify_lab_output(report: Path, *, candidate: str, image_id: str,
                      source_hash: str, receipt_hash: str, vendor_hash: str) -> dict:
    """Require actual, mutually consistent child evidence, not only exit 0."""
    evidence_path = report / "evidence.json"
    junit_path = report / "junit.xml"
    if any(path.is_symlink() or not path.is_file() for path in (evidence_path, junit_path)):
        raise ValueError("Laboratory evidence missing")
    evidence = json.loads(evidence_path.read_bytes())
    expected = validate_selection(junit_path, profile="mysql", source_sha=candidate,
                                  observed_sha=candidate, required=REQUIRED)
    bindings = {"runtime_image_id": image_id, "source_manifest_sha256": source_hash,
                "dependency_receipt_sha256": receipt_hash, "vendor_tree_sha256": vendor_hash,
                "release_gate_integrated": False, "server_uuid_checked_by_test_bootstrap": True,
                "receipt_origin_requires_orchestrator_attestation": True}
    if (not isinstance(evidence, dict)
            or any(type(evidence.get(key)) is not type(value) or evidence.get(key) != value
                   for key, value in (expected | bindings).items())
            or any(type(evidence["identities"].get(key)) is not int for key in expected["identities"])):
        raise ValueError("Laboratory evidence does not match this build")
    return evidence


def docker(*args: str, data: bytes | None = None, check: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(["docker", *args], input=data, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, check=check)


def checked_source(candidate: str) -> tuple[dict, dict]:
    source = source_manifest(ROOT, candidate)
    hashes = {row["path"]: row["sha256"] for row in source["files"]}
    for name in ("tests/Deployment/idempotency_lab_orchestrator.py",
                 "tests/Deployment/idempotency_mysql_lab.py",
                 "tests/Deployment/idempotency_attestation.py",
                 "tests/Deployment/idempotency_suite_evidence.py",
                 "tests/Deployment/linux_vendor.py", "tests/Deployment/linux_release_common.py",
                 "tests/Deployment/linux-runtime/profiles.json"):
        if name not in hashes or digest((ROOT / name).read_bytes()) != hashes[name]:
            raise ValueError("Orchestration source differs from candidate")
    profiles = json.loads((ROOT / "tests/Deployment/linux-runtime/profiles.json").read_text(encoding="utf-8"))
    return source, profiles


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidate-sha", required=True)
    parser.add_argument("--profile", required=True)
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--execute", action="store_true")
    args = parser.parse_args()
    validate_context(ci=os.environ.get("CI"), opt_in=os.environ.get("RELEASE_DISPOSABLE_TESTS"),
                     platform=os.name, github_sha=os.environ.get("GITHUB_SHA"), candidate_sha=args.candidate_sha)
    if not args.execute:
        raise RuntimeError("Explicit --execute required")
    source, profiles = checked_source(args.candidate_sha)
    token = uuid.uuid4().hex[:12]
    volume = "fokus-law-linux-" + token + "-files"
    mysql_image, runtime_tag = preflight(enabled="1", ci="true", platform="posix", volume=volume,
                                        source_sha=args.candidate_sha, profile=args.profile, profiles=profiles)
    dockerfile = profiles[args.profile].get("dockerfile")
    expected_files = {row["path"]: row["sha256"] for row in source["files"]}
    allowed = {"compatibility": "tests/Deployment/linux-runtime/Dockerfile",
               "target": "tests/Deployment/linux-runtime/php85/Dockerfile"}
    if dockerfile != allowed[args.profile] or dockerfile not in expected_files:
        raise RuntimeError("Unreviewed runtime Dockerfile")
    runner_temp = Path(os.environ.get("RUNNER_TEMP", "")).resolve()
    report = args.report.resolve()
    if (not runner_temp.is_dir() or report == runner_temp or runner_temp not in report.parents
            or args.report.is_symlink() or report.exists()):
        raise RuntimeError("Fresh report directory within CI runner temp required")
    report.mkdir(parents=False, exist_ok=False)
    source_bytes = json.dumps(source, separators=(",", ":")).encode()
    source_file = report / "source-manifest.json"
    source_file.write_bytes(source_bytes)
    setup_name = "fokus-idempotency-build-" + token
    setup = None
    setup_running = False
    try:
        with tempfile.TemporaryDirectory(prefix="idempotency-build-", dir=runner_temp) as temporary:
            temp = Path(temporary)
            archive = temp / "source.tar"
            archive.write_bytes(subprocess.check_output(["git", "-C", str(ROOT), "archive", "--format=tar", args.candidate_sha]))
            # Extraction is performed by the release harness' path/link guard,
            # and only under this newly-created CI temp directory.
            import tarfile
            from linux_release_common import safe_extract_source_archive
            build_root = temp / "source"
            build_root.mkdir()
            with tarfile.open(archive, "r:") as contents:
                safe_extract_source_archive(contents, build_root)
            iidfile = temp / "runtime-id.txt"
            build = subprocess.run(["docker", "build", "--pull", "--iidfile", str(iidfile),
                                    "--file", str(build_root / dockerfile), str((build_root / dockerfile).parent)],
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=False)
            (report / "runtime-build.log").write_bytes(build.stdout)
            if build.returncode != 0 or not iidfile.is_file():
                raise RuntimeError("Controlled runtime build failed")
            image_id = iidfile.read_text(encoding="utf-8").strip()
            inspected = json.loads(docker("image", "inspect", image_id).stdout)[0]
            validate_runtime(image_id, inspected)
            # Image is pinned to this build's immutable ID for both the setup
            # and test runner. Base manifests are pinned by the Dockerfile;
            # apt packages remain mutable and their resulting bytes are not
            # asserted to be reproducible across different builds.
            docker("pull", mysql_image)
            mysql_info = json.loads(docker("image", "inspect", mysql_image).stdout)[0]
            if mysql_image not in (mysql_info.get("RepoDigests") or []):
                raise RuntimeError("Pinned MySQL digest not present")
            existing = docker("volume", "ls", "--format", "{{.Name}}", "--filter", "name=" + volume).stdout.decode().splitlines()
            if volume in existing:
                raise RuntimeError("Disposable volume name already exists")
            created_volume = docker("volume", "create", "--label", "fokus.release.disposable=true",
                                    "--label", "fokus.release.run=" + token, volume).stdout.decode().strip()
            if created_volume != volume:
                raise RuntimeError("Unexpected disposable volume")
            inspected_volume = json.loads(docker("volume", "inspect", volume).stdout)[0]
            validate_volume(inspected_volume)
            if inspected_volume["Labels"].get("fokus.release.run") != token:
                raise RuntimeError("Disposable volume does not belong to this run")
            setup = created_id(docker("run", "-d", "--pull=never", "--name", setup_name, "--restart=no", "--network", "bridge",
                                      "--label", "fokus.release.disposable=true", "--mount", "type=volume,src=" + volume + ",dst=/work",
                                      image_id, "sleep", "infinity"))
            setup_running = True
            docker("cp", str(archive), setup + ":/tmp/idempotency-source.tar")
            docker("exec", "-i", "--user", "0", setup, "sh", "-ec", "umask 077; cat > /tmp/source-manifest.json", data=source_bytes)
            docker("exec", "--user", "0", setup, "sh", "-ec",
                   "mkdir -p /work/release /work/release-dependencies/candidate && "
                   "tar --no-same-owner -xf /tmp/idempotency-source.tar -C /work/release && "
                   "chown -R 1000:1000 /work")
            verify = ("import sys,json;from pathlib import Path;"
                      "sys.path.insert(0,'/work/release/tests/Deployment');"
                      "from idempotency_attestation import verify_attested;"
                      "verify_attested(Path('/work/release'),json.load(open('/tmp/source-manifest.json'))['files'])")
            docker("exec", "--user", "0", setup, "python3", "-c", verify)
            clean_env = ["PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "HOME=/tmp",
                         "COMPOSER_HOME=/tmp/composer-lab", "COMPOSER_CACHE_DIR=/tmp/composer-cache",
                         "APP_ENV=testing", "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
                         "DB_CONNECTION=sqlite", "DB_DATABASE=:memory:", "CACHE_STORE=array", "SESSION_DRIVER=array"]
            install = docker("exec", "--user", "1000:1000", "-w", "/work/release", setup,
                             "env", "-i", *clean_env, *COMPOSER, check=False)
            (report / "composer-install.log").write_bytes(install.stdout + install.stderr)
            if install.returncode != 0:
                raise RuntimeError("Locked Composer install failed")
            docker("exec", "--user", "0", setup, "python3", "-c", verify)
            make_receipt = (
                "import shutil,sys;from pathlib import Path;"
                "sys.path.insert(0,'/work/release/tests/Deployment');"
                "import linux_vendor as v;root=Path('/work/release');"
                "dest=Path('/work/release-dependencies/candidate');"
                "v.check_packages(root);"
                "[shutil.copyfile(root/n,dest/n) for n in ('composer.json','composer.lock')];"
                "shutil.copytree(root/'vendor',dest/'vendor',symlinks=True);"
                "v.receipt(dest,{'kind':'verified-candidate-locked-install-snapshot','copy_byte_verified':True});"
                "v.verify_snapshot(dest,root)"
            )
            docker("exec", "--user", "1000:1000", setup, "python3", "-c", make_receipt)
            # No test ever mounts this volume read-write; stop the only setup
            # container before exporting its receipt or starting the lab.
            docker("stop", setup)
            setup_running = False
            receipt_path = report / "candidate-receipt.json"
            docker("cp", setup + ":/work/release-dependencies/candidate/dependency-receipt.json", str(receipt_path))
            receipt_data = receipt_path.read_bytes()
            receipt_hash = digest(receipt_data)
            receipt = validate_receipt(receipt_data, receipt_hash, source)
            attestation = {
                "scope": "same-run controlled build and locked Composer install; not signed provenance or release approval",
                "candidate_sha": args.candidate_sha, "profile": args.profile,
                "source_manifest_sha256": digest(source_bytes), "dockerfile_sha256": expected_files[dockerfile],
                "runtime_image_id": image_id, "reviewed_runtime_tag": runtime_tag, "mysql_image": mysql_image,
                "composer_arguments": COMPOSER, "composer_lock_sha256": receipt["composer_lock_sha256"],
                "dependency_receipt_sha256": receipt_hash, "vendor_tree_sha256": receipt["vendor_tree_sha256"],
                "build_log_sha256": digest(build.stdout), "composer_log_sha256": digest(install.stdout + install.stderr),
                "limitations": ["CI host and Docker daemon are trusted", "apt inputs may change across builds",
                                "locked versions do not independently authenticate all upstream package bytes"],
            }
            (report / "same-run-build.json").write_text(json.dumps(attestation, indent=2) + "\n", encoding="utf-8")
            result = subprocess.run([sys.executable, str(ROOT / "tests/Deployment/idempotency_mysql_lab.py"),
                                     "--volume", volume, "--candidate-sha", args.candidate_sha,
                                     "--profile", args.profile, "--report", str(report / "laboratory"),
                                     "--dependency-receipt", str(receipt_path), "--dependency-receipt-sha256", receipt_hash,
                                     "--runtime-image-id", image_id, "--execute"], check=False)
            if result.returncode != 0:
                raise RuntimeError("Isolated laboratory did not pass")
            verify_lab_output(report / "laboratory", candidate=args.candidate_sha, image_id=image_id,
                              source_hash=digest(source_bytes), receipt_hash=receipt_hash,
                              vendor_hash=receipt["vendor_tree_sha256"])
    finally:
        if setup_running:
            docker("stop", setup, check=False)


if __name__ == "__main__":
    main()
