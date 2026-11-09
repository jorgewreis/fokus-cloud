#!/usr/bin/env python3
"""Summarize allowlisted Actions outcomes without reflecting runtime data."""

import os
from pathlib import Path


PHASES = (
    ("PREFLIGHT", "Prerequisites and current dynamic release contract"),
    ("SSH", "SSH connection using configured known hosts"),
    ("SCHEMA", "Prepublication schema prerequisite"),
    ("ASSETS", "Immutable assets transferred and checksummed"),
    ("FILES", "Application files transferred"),
    ("RUNTIME", "CLI runtime prerequisites"),
    ("DEPENDENCIES", "Dependencies installed"),
    ("MIGRATIONS", "Migrations completed"),
    ("DATAJUD_CONFIG", "Datajud configuration"),
    ("CACHES", "Laravel caches prepared"),
    ("TAGS", "Contact tags synchronized"),
    ("DATAJUD", "Datajud configuration checked (external probe is advisory)"),
    ("SCHEDULER", "Scheduler checked"),
    ("SERVICES", "Selected runtime action"),
    ("CDN", "Optional CDN purge"),
    ("RELEASE", "HTTP health and expected dynamic release observed"),
)
OUTCOMES = {"success", "failure", "cancelled", "skipped"}


def render(env):
    results = {key: env.get("PHASE_" + key, "") for key, _ in PHASES}
    # No unknown or empty result can become success.
    results = {key: value if value in OUTCOMES else "unknown" for key, value in results.items()}
    complete = all(value == "success" for key, value in results.items() if key != "CDN")
    complete = complete and results["CDN"] in {"success", "skipped"}
    lines = ["## Publication evidence", "", "| Phase | Outcome |", "| --- | --- |"]
    for key, label in PHASES:
        value = results[key]
        if key == "SERVICES" and value == "success":
            mode = env.get("FPM_RELOAD_MODE")
            if mode == "verify-only":
                value = "not requested (verify-only); no OPcache claim"
            elif mode != "reload":
                value = "unknown runtime policy"
                complete = False
        lines.append(f"| {label} | {value} |")
    lines.extend(["", "Automated publication checks passed." if complete else "Publication is NOT fully verified."])
    if not complete and any(results[key] != "skipped" for key in ("SCHEMA", "ASSETS", "FILES") if results[key] != "unknown"):
        lines.append("Remote writes may have occurred. Preserve evidence and assess partial publication; do not automatically roll back migrations.")
    lines.append("This evidence does not certify destination inventory, every PHP worker, every business flow, or full recoverability.")
    return "\n".join(lines) + "\n", complete


def main():
    report, complete = render(os.environ)
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with Path(summary).open("a", encoding="utf-8") as output:
            output.write(report)
    print(report, end="")
    return 0 if complete else 1


if __name__ == "__main__":
    raise SystemExit(main())
