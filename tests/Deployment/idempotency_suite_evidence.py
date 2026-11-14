"""Fail-closed, per-engine JUnit evidence validation for isolated laboratory runs.

This module does not invoke PHPUnit, approve a database, or modify a release gate.
Callers must separately establish the source identity and disposable environment.
"""

from __future__ import annotations

import hashlib
import re
import xml.etree.ElementTree as ET
from pathlib import Path


SOURCE = re.compile(r"[0-9a-f]{40}\Z")
PROFILES = frozenset({"sqlite", "mysql"})


def validate_selection(path: Path, *, profile: str, source_sha: str,
                       observed_sha: str, required: dict[str, int]) -> dict:
    """Validate one JUnit file against an explicit, individually reviewed manifest.

    Identity is class::method. The same identity may appear in another engine's
    independent evidence, but never twice in this selection. Unknown tests,
    zero-assertion cases and missing tests all fail closed.
    """
    if profile not in PROFILES or not SOURCE.fullmatch(source_sha) or source_sha != observed_sha:
        raise ValueError("Invalid profile or source identity")
    if not isinstance(required, dict) or not required:
        raise ValueError("A nonempty nominal manifest is required")
    for name, minimum in required.items():
        if (not isinstance(name, str) or name.count("::") != 1
                or not all(name.split("::")) or type(minimum) is not int or minimum < 1):
            raise ValueError("Invalid nominal manifest")

    data = path.read_bytes()
    root = ET.fromstring(data)
    if root.tag not in {"testsuite", "testsuites"}:
        raise ValueError("Unexpected JUnit root")
    cases = root.findall(".//testcase")
    if len(cases) != len(required):
        raise ValueError("Case count differs from manifest")
    observed: dict[str, int] = {}
    for case in cases:
        name = (case.get("class") or "") + "::" + (case.get("name") or "")
        if name in observed or name not in required:
            raise ValueError("Duplicate or unexpected case")
        if any(case.find(kind) is not None for kind in ("failure", "error", "skipped")):
            raise ValueError("A case failed, errored or was skipped")
        assertions = case.get("assertions", "")
        if not assertions.isascii() or not assertions.isdigit():
            raise ValueError("Missing or invalid assertion count")
        count = int(assertions)
        if count < required[name]:
            raise ValueError("Assertion count below nominal minimum")
        observed[name] = count
    if set(observed) != set(required):
        raise ValueError("Missing nominal case")

    # Both JUnit container types may report failures without a testcase, and
    # nested suites can disagree with their children. Never trust only the
    # leaf cases or the top-level summary.
    for suite in root.iter():
        if suite.tag not in {"testsuites", "testsuite"}:
            continue
        for attribute in ("failures", "errors", "skipped", "disabled"):
            value = suite.get(attribute, "0")
            if not value.isascii() or not value.isdigit() or int(value) != 0:
                raise ValueError("Suite reports a failure, error or skip")
        if any(suite.find(kind) is not None for kind in ("failure", "error", "skipped")):
            raise ValueError("Suite contains a failure, error or skip")
        descendants = suite.findall(".//testcase")
        for attribute, expected in (("tests", len(descendants)),
                                    ("assertions", sum(int(case.get("assertions")) for case in descendants))):
            value = suite.get(attribute)
            if value is not None and (not value.isascii() or not value.isdigit() or int(value) != expected):
                raise ValueError("Suite total does not match its cases")
    return {"profile": profile, "source_sha": source_sha,
            "junit_sha256": hashlib.sha256(data).hexdigest(),
            "cases": len(observed), "assertions": sum(observed.values()),
            "identities": observed}
