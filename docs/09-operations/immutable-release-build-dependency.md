# REI-24: dependency proposal for reproducible generated assets

Status: investigation and specific proposal, not implementation or activation. Base examined: 8dd9835053b7dc986343e8cfc44b5c35bdde920a. Date: 2026-11-09. No production touched.

## Proven cause

`package.json` runs seo:sync, styles:sync, styles:cache-bust, law:assets:prepare and Vite sequentially. The five changed tracked HTML files are `public/styles/docs/{components,forms,helpers,layout,utilities}/index.html`. Their only change is the fokus.css cache query from `bab781acdb04` to `47fa178b5b69`. `tools/cache-bust-styles-pages.mjs:36-54` transforms and writes those same canonical tracked source files. The current tracked CSS already hashes to `47fa178b5b69bdb6bcc2d1f01f6b1659b2b3f477525b274e723dc9d90a3f074b`; this is stale generated metadata in canonical HTML, not evidence of random Vite output or a CSS content change.

Repeated actual `npm run build` on the installed lockfile dependencies succeeded. Comparing SHA256 of all 393 files below public/ and config/ before and after the repeat found zero differences. This proves idempotence for these two same-environment runs, not universal cross-platform reproducibility or a fresh dependency-install comparison. The command/result inventory is recorded in the REI-24 checkpoint.

The real built tree was passed to immutable.py package and correctly returned `dirty_source`, exit 1. No generated HTML was discarded or ignored to force a pass.

## Law manifest

`tools/generate-fokus-law-assets.mjs:87-123` checks exactly 14 Blade references, hashes their source bytes, creates content-addressed asset names, and writes deterministic JSON to `config/fokus-law-assets.json`. It already supports separate source-root and output-root. The JSON is ignored by Git (.gitignore:23) but consumed by `app/Support/FokusLawAssets.php:62` as runtime configuration. Its current SHA256 is `88b65b367cd9382e38db08daf2415821562bfaa65324dc87fecc332e9d86defd`.

`node tests/Deployment/fokus-law-assets-rsync.mjs` passed generation/manifest checks for 14 assets. The rsync retention portion explicitly SKIPPED because rsync is absent, and is not claimed validated here.

PR #59 rejects modified tracked files and untracked runtime config, including this JSON. Preserving that contract means #61 must continue to refuse this current tree. Adding `config/**` to extras would be unsafe and is not proposed.

## Separate dependency delivery recommended

Because the safe solution changes the build contract and #59's source-vs-derived identity model, create a separate reviewed delivery before loosening #61. Do not silently modify #59 or main.

1. Keep a clean immutable source checkout identified by full SHA and lockfile digests.
2. Build only into a new disposable output root. Refactor SEO/Styles sync/cache-bust scripts to explicit source/output parameters; no writes into the source root. Source HTML remains canonical input, generated HTML is output. Vite output is directed into that root. All build consumers must be inspected, including font fetching, to pin or verify external build inputs.
3. Define an exact provenance contract for each transformation: source SHA, input paths and hashes, lockfile hashes, generator path/hash, toolchain version, output path/hash, transformation identifier. Unknown outputs or missing inputs fail closed.
4. For HTML, accept only output reproduced by the reviewed cache-busting transformation from the original tracked bytes and verified asset bytes; do not accept arbitrary replacement HTML or all public files.
5. For Law, independently recompute the exact existing 14-entry manifest from versioned inputs, verify both content-addressed output bytes and names, exact schema, no duplicate/extra/missing entries and no symlink traversal. Include the JSON digest in runtime identity manifest. This is a single named derived config contract, not a directory allowlist.
6. Extend #59 in that separate delivery to distinguish unchanged tracked code from expressly proven derived outputs. Binding to SHA alone is insufficient; bind the derived-output manifest digest into the release identity and preserve full runtime file verification. Update endpoint tests accordingly. Never merely copy a receipt supplied by a caller and call it provenance.
7. Transport packaging takes the clean source archive plus only verified derived outputs. Sealing occurs after the final tree is fixed; no post-seal mutation or regeneration.
8. #61 keeps its present fail-closed behavior until this new contract is reviewed, tested, and pinned. #55 audit digest requires explicit re-audit later, not automatic update.

## Acceptance tests for that dependency

- Fresh isolated npm ci and build twice, compare full generated inventory including ignored assets and Law JSON; source status remains clean.
- Tamper source, generator, lockfile, HTML, referenced CSS/JS, JSON digest/path/schema and content-addressed assets: each must fail verification.
- Missing, extra and duplicate Law entries; symlinks; unexpected generated runtime config: reject.
- Compare HTTP-visible HTML behavior/asset URLs and existing Law tests to preserve functionality.
- Real PHP tests of FokusLawAssets plus #59 identity verification on the final artifact, including JSON mutation after seal.
- Test a mixed source change, missing provenance and generation failure; never bypass full deployment classification.
- Integrate only after remote checks and review; no deployment or merge authorized here.

An alternative smaller source-maintenance strategy could commit regenerated HTML queries and a generated-but-tracked Law manifest with mandatory regeneration checks. It preserves #59 exactly but retains generated artifacts as canonical source and does not implement source/output separation; it is not recommended as the B+C solution.

Tracking: [REI-25](https://linear.app/reis-developer/issue/REI-25/separar-build-e-comprovar-proveniencia-dos-assets-bc), dependency of REI-24.
