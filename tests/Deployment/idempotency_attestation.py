"""Byte-level copying for a disposable idempotency laboratory.

Trust boundary: the release orchestrator must authenticate the candidate SHA
and the locked-install dependency receipt. This module binds their bytes to a
private workspace; a caller-supplied digest alone is not an origin attestation.
"""
from __future__ import annotations

import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile

HEX = re.compile(r"[0-9a-f]{64}\Z")
MAX_TOTAL = 4 * 1024 * 1024 * 1024


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def unique_object(pairs: list[tuple[str, object]]) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate receipt key")
        result[key] = value
    return result


def safe_path(value: object) -> str:
    if (not isinstance(value, str) or not value or "\\" in value or "\x00" in value
            or value.startswith("/") or any(part in ("", ".", "..") for part in value.split("/"))
            or PurePosixPath(value).is_absolute()):
        raise ValueError("Unsafe manifest path")
    return value


def source_manifest(repo: Path, sha: str) -> dict:
    """Derive files from the exact commit, not from the mutable worktree."""
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("Invalid commit identity")
    head = subprocess.check_output(["git", "-C", str(repo), "rev-parse", "HEAD"], text=True).strip()
    if head != sha:
        raise ValueError("Host checkout is not the requested commit")
    archive = subprocess.check_output(["git", "-C", str(repo), "archive", "--format=tar", sha])
    rows = []
    total = 0
    with tarfile.open(fileobj=io.BytesIO(archive), mode="r:") as source:
        for member in source:
            if member.isdir():
                continue
            name = safe_path(member.name)
            if not member.isfile() or name.split("/")[0] in {".git", "vendor", "node_modules"}:
                raise ValueError("Unexpected source archive entry")
            total += member.size
            if total > MAX_TOTAL:
                raise ValueError("Source archive exceeds limit")
            stream = source.extractfile(member)
            if stream is None:
                raise ValueError("Unreadable source archive entry")
            data = stream.read()
            if len(data) != member.size:
                raise ValueError("Source archive size mismatch")
            rows.append({"path": name, "sha256": digest(data), "bytes": len(data),
                         "executable": bool(member.mode & 0o111)})
    rows.sort(key=lambda row: row["path"])
    if not rows or len({row["path"] for row in rows}) != len(rows):
        raise ValueError("Empty or duplicate source manifest")
    return {"commit": sha, "files": rows}


def validate_receipt(data: bytes, expected_digest: str, source: dict) -> dict:
    if not HEX.fullmatch(expected_digest) or digest(data) != expected_digest:
        raise ValueError("Dependency receipt digest mismatch")
    receipt = json.loads(data, object_pairs_hook=unique_object)
    if (not isinstance(receipt, dict) or not isinstance(receipt.get("origin"), dict)
            or receipt["origin"].get("kind") != "verified-candidate-locked-install-snapshot"):
        raise ValueError("Unexpected dependency receipt origin")
    files = source.get("files")
    if not isinstance(files, list):
        raise ValueError("Invalid source manifest")
    source_hash = {row["path"]: row["sha256"] for row in files}
    for name, key in (("composer.json", "composer_json_sha256"), ("composer.lock", "composer_lock_sha256")):
        if not HEX.fullmatch(str(receipt.get(key, ""))) or source_hash.get(name) != receipt[key]:
            raise ValueError("Dependency receipt and source lock differ")
    rows = receipt.get("vendor_files")
    if not isinstance(rows, list) or not rows:
        raise ValueError("Missing vendor manifest")
    total = 0
    names = set()
    for row in rows:
        if not isinstance(row, dict) or set(row) != {"path", "sha256", "bytes"}:
            raise ValueError("Invalid vendor manifest row")
        name = safe_path(row["path"])
        if (name in names or not HEX.fullmatch(str(row["sha256"]))
                or type(row["bytes"]) is not int or row["bytes"] < 0):
            raise ValueError("Invalid vendor manifest entry")
        total += row["bytes"]
        names.add(name)
    if total > MAX_TOTAL or "autoload.php" not in names or "composer/installed.json" not in names:
        raise ValueError("Incomplete or excessive vendor snapshot")
    tree = digest(json.dumps(rows, separators=(",", ":")).encode())
    if receipt.get("vendor_tree_sha256") != tree:
        raise ValueError("Vendor tree receipt mismatch")
    installed = next(row for row in rows if row["path"] == "composer/installed.json")
    if receipt.get("installed_json_sha256") != installed["sha256"]:
        raise ValueError("Installed package evidence mismatch")
    return receipt


def copy_attested(source: Path, target: Path, rows: list[dict]) -> None:
    """Copy only attested regular files; reject links and changed bytes."""
    if not source.is_dir() or source.is_symlink() or not target.is_dir() or target.is_symlink():
        raise ValueError("Invalid snapshot roots")
    for row in rows:
        name = safe_path(row.get("path"))
        if not HEX.fullmatch(str(row.get("sha256", ""))) or type(row.get("bytes")) is not int or row["bytes"] < 0:
            raise ValueError("Invalid snapshot entry")
        path = source
        for component in PurePosixPath(name).parts:
            path = path / component
            if path.is_symlink():
                raise ValueError("Snapshot links are forbidden")
        if not path.is_file():
            raise ValueError("Missing snapshot file")
        data = path.read_bytes()
        if len(data) != row["bytes"] or digest(data) != row["sha256"]:
            raise ValueError("Snapshot file changed")
        destination = target.joinpath(*PurePosixPath(name).parts)
        destination.parent.mkdir(parents=True, exist_ok=True)
        if destination.exists() or destination.is_symlink():
            raise ValueError("Refusing to overwrite snapshot")
        destination.write_bytes(data)
        destination.chmod(0o555 if row.get("executable") else 0o444)


def verify_attested(target: Path, rows: list[dict]) -> None:
    for row in rows:
        path = target.joinpath(*PurePosixPath(safe_path(row["path"])).parts)
        cursor = target
        for component in PurePosixPath(row["path"]).parts:
            cursor = cursor / component
            if cursor.is_symlink():
                raise ValueError("Snapshot links are forbidden")
        if not path.is_file():
            raise ValueError("Missing snapshot file")
        data = path.read_bytes()
        if len(data) != row["bytes"] or digest(data) != row["sha256"]:
            raise ValueError("Snapshot file changed")


def verify_vendor_inventory(vendor: Path, rows: list[dict]) -> None:
    """Reject unlisted files and links that an autoloader could execute."""
    actual = set()
    for path in vendor.rglob("*"):
        if path.is_symlink():
            raise ValueError("Vendor links are forbidden")
        if path.is_file():
            actual.add(path.relative_to(vendor).as_posix())
        elif not path.is_dir():
            raise ValueError("Unexpected vendor filesystem entry")
    if actual != {row["path"] for row in rows}:
        raise ValueError("Vendor inventory differs from receipt")


def run_internal() -> None:
    """Internal copy runs inside a newly created container, never on a host DB."""
    import sys
    if len(sys.argv) != 2 or sys.argv[1] not in {"copy", "verify"}:
        raise ValueError("Invalid internal action")
    source = json.loads(Path("/tmp/idempotency-source.json").read_text(encoding="utf-8"))
    receipt = json.loads(Path("/tmp/idempotency-vendor.json").read_text(encoding="utf-8"))
    workspace = Path("/workspace")
    if sys.argv[1] == "copy":
        for path in (Path("/input"), Path("/input/release"), Path("/input/release-dependencies"),
                     Path("/input/release-dependencies/candidate"),
                     Path("/input/release-dependencies/candidate/vendor")):
            if path.is_symlink() or not path.is_dir():
                raise ValueError("Snapshot root links are forbidden")
        copy_attested(Path("/input/release"), workspace, source["files"])
        (workspace / "vendor").mkdir()
        copy_attested(Path("/input/release-dependencies/candidate/vendor"), workspace / "vendor", receipt["vendor_files"])
    verify_attested(workspace, source["files"])
    verify_attested(workspace / "vendor", receipt["vendor_files"])
    verify_vendor_inventory(workspace / "vendor", receipt["vendor_files"])


if __name__ == "__main__":
    run_internal()
