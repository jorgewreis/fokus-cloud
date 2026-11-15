# Styles Examples ZIP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore byte-identical portable Styles examples by regenerating the checked-in ZIP and updating its documentation.

**Architecture:** `tools/styles-showcase/build-examples.py` is the canonical deterministic generator. The committed ZIP is a release artifact derived exclusively from `public/styles/examples/` and `public/styles/assets/`; documentation must describe the actual archive contents rather than an older three-example snapshot.

**Tech Stack:** Python standard-library `zipfile`, Node test runner, committed static HTML/CSS/JS artifacts.

**Spec:** `docs/03-architecture/rei-54-consumo-law.md` and the failed isolated validation run `43713499593`.

## Global Constraints

- Do not change deploy workflows, dispatch new runs, or access production.
- Generate the ZIP only with `python3 tools/styles-showcase/build-examples.py`.
- Preserve byte stability: the generated archive must remain unchanged on an immediate second generation.
- Do not claim browser or production validation from static checks.

## Review Focus

- ZIP entries must be exact copies of all portable source files, especially `cloud-datatable.html`.
- A second generator invocation must preserve the ZIP hash.
- Documentation must not retain any claim that the portable examples are outside the ZIP or that it contains only three original examples.
- The archive must retain local runtime assets and offline documentation.
- Repository-local changes must be limited to the archive, its explanatory documentation, and this plan.

---

### Task 1: Regenerate the portable archive and align documentation

**Files:**
- Modify: `public/styles/examples/fokus-styles-examples.zip`
- Modify: `public/styles/examples/README.md`
- Modify: `docs/03-architecture/rei-54-consumo-law.md:64,66,68,70,76`
- Test: `tools/styles-showcase/visual-coherence.test.mjs`

**Interfaces:**
- Consumes: `python3 tools/styles-showcase/build-examples.py` and its exact source-file list.
- Produces: a byte-stable ZIP matching all portable preview sources and accurate archive documentation.

- [x] **Step 1: Capture the failing archive contract**

Run: `node --test tools/styles-showcase/visual-coherence.test.mjs`
Expected: FAIL identifying the portable ZIP mismatch.

- [x] **Step 2: Regenerate `public/styles/examples/fokus-styles-examples.zip`**

Run: `python3 tools/styles-showcase/build-examples.py`
Expected: ZIP is rebuilt using only the generator's canonical source list.

- [x] **Step 3: Update the two documentation locations**

State in `public/styles/examples/README.md` that the ZIP includes all current portable examples and is generated from the exact source files. Replace the five obsolete claims at `docs/03-architecture/rei-54-consumo-law.md:64,66,68,70,76`; retain the limits about product integration and browser/production evidence.

- [x] **Step 4: Verify generator idempotency and archive contract**

Run: `python3 tools/styles-showcase/build-examples.py; $first=(Get-FileHash public/styles/examples/fokus-styles-examples.zip -Algorithm SHA256).Hash; python3 tools/styles-showcase/build-examples.py; $second=(Get-FileHash public/styles/examples/fokus-styles-examples.zip -Algorithm SHA256).Hash; if ($first -ne $second) { throw 'ZIP changed on the second generation' }; node --test tools/styles-showcase/visual-coherence.test.mjs`
Expected: the two SHA-256 values match and the visual-coherence contract passes.

- [x] **Step 5: Run the focused deployment contract**

Run: `node --test tools/styles-showcase/deployment.test.mjs; git diff --check`
Expected: PASS without rsync execution or a deployment.
