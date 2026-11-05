# Web Accessibility Audit

An audit of web content, run on 2026-11-05. Read the executive summary for the verdict, the priorities section for what to do first, and the findings tables for detail.

## Metadata

What was audited, with what, and under which settings.

| Field | Value |
|---|---|
| Date | 2026-11-05 |
| Scope | 2 items |
| Skills run | keyboard-navigator |
| Tooling | node v24.13.0, a11y-mcp-server 7.1.0 |
| Scan configuration | defaults, no config file found |
| Checks counted | 10 |

Audited:

- `public/portal/assets/fokus-law-shell.css`
- `resources/views/portal/fokus-law.blade.php`

## Executive summary

Pass. No critical or serious findings.

The headline numbers for this run.

| Measure | Value |
|---|---|
| Score | 100 out of 100 |
| Grade | A |
| Findings | 0 |
| Blocking (critical plus serious) | 0 |
| Verdict | Pass |

## Severity breakdown

Counts by severity, highest impact first.

| Severity | Count | What it means |
|---|---:|---|
| critical | 0 | Blocks a person from completing the task |
| serious | 0 | Major barrier with a difficult workaround |
| moderate | 0 | Real friction, workaround exists |
| minor | 0 | Noticeable but rarely blocking |

## Findings

No findings. Every check listed under "What passed" ran and came back clean.

## Remediation priorities

Nothing to remediate.

## What passed

2 checks ran and found nothing.

- The account toggle remains a native button reachable and operable by keyboard.
- The sizing changes preserve the visible focus indicator and do not alter focus order or keyboard behavior.

## Next steps

1. Keep the current checks in continuous integration so this does not regress.
2. Re-audit when the interface changes materially, or quarterly.
3. Confirm the automated result with a manual screen reader and keyboard pass.

Automated checks find a minority of accessibility barriers. A passing score is a floor, not a guarantee.

## Findings data

The merged findings, embedded so the next run can compute a delta without parsing this document.

```json a11y-findings
{
  "skills": [
    "keyboard-navigator"
  ],
  "scope": [
    "public/portal/assets/fokus-law-shell.css",
    "resources/views/portal/fokus-law.blade.php"
  ],
  "findings": [],
  "passed": [
    "The account toggle remains a native button reachable and operable by keyboard.",
    "The sizing changes preserve the visible focus indicator and do not alter focus order or keyboard behavior."
  ],
  "notes": [],
  "counts": {
    "critical": 0,
    "serious": 0,
    "moderate": 0,
    "minor": 0
  },
  "components": [],
  "score": 100,
  "grade": "A",
  "penalty": 0,
  "checks": 10,
  "generated": "2026-11-05",
  "template": "web"
}
```
