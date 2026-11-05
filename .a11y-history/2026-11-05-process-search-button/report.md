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

- `public/portal/assets/fokus-law-processes.js`
- `public/portal/assets/fokus-law-shell.css`

## Executive summary

Pass. No critical or serious findings.

The headline numbers for this run.

| Measure | Value |
|---|---|
| Score | 96 out of 100 |
| Grade | A |
| Findings | 1 |
| Blocking (critical plus serious) | 0 |
| Verdict | Pass |

## Severity breakdown

Counts by severity, highest impact first.

| Severity | Count | What it means |
|---|---:|---|
| critical | 0 | Blocks a person from completing the task |
| serious | 0 | Major barrier with a difficult workaround |
| moderate | 1 | Real friction, workaround exists |
| minor | 0 | Noticeable but rarely blocking |

## Findings

1 findings, ordered by severity then by rule.

### Moderate (1)

Each row names the rule, the criterion it fails, where it is, and what to change.

| Rule | WCAG | Location | Problem | Fix |
|---|---|---|---|---|
| `KEYBOARD-FOCUS-ORDER-001` | 2.4.3 A | public/portal/assets/fokus-law-shell.css:508-511 | The submit button appears vertically above the search input, despite following it in keyboard focus order. | Align the submit button with the input control's vertical position so its visual placement matches the input-then-button tab sequence. |

## Remediation priorities

The remaining 1 moderate and minor findings can follow in normal work.

## What passed

2 checks ran and found nothing.

- Search input, submit button, and archived checkbox are native form controls in DOM order, so each is reachable by Tab and the button can be activated with Enter or Space.
- The archived checkbox is placed on a separate grid row after the input and submit button; this matches its later position in DOM focus order.

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
    "public/portal/assets/fokus-law-processes.js",
    "public/portal/assets/fokus-law-shell.css"
  ],
  "findings": [
    {
      "rule": "KEYBOARD-FOCUS-ORDER-001",
      "wcag": "2.4.3 A",
      "severity": "moderate",
      "confidence": "high",
      "location": "public/portal/assets/fokus-law-shell.css:508-511",
      "summary": "The submit button appears vertically above the search input, despite following it in keyboard focus order.",
      "fix": "Align the submit button with the input control's vertical position so its visual placement matches the input-then-button tab sequence.",
      "evidence": "The search field occupies the first grid row including its label; the button uses align-self: end on that same row, but the screenshot shows it aligned with the label rather than the input.",
      "foundBy": [
        "keyboard-navigator"
      ]
    }
  ],
  "passed": [
    "Search input, submit button, and archived checkbox are native form controls in DOM order, so each is reachable by Tab and the button can be activated with Enter or Space.",
    "The archived checkbox is placed on a separate grid row after the input and submit button; this matches its later position in DOM focus order."
  ],
  "notes": [],
  "counts": {
    "critical": 0,
    "serious": 0,
    "moderate": 1,
    "minor": 0
  },
  "components": [],
  "score": 96,
  "grade": "A",
  "penalty": 2,
  "checks": 10,
  "generated": "2026-11-05",
  "template": "web"
}
```
