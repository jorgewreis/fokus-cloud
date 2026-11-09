# REI-25: isolated distribution and verified provenance

Status: implementation for review, not production activation. No previous PR branch,
deploy workflow, VPS, fingerprint or REI-26 scheduler contract is modified.

## Source and distribution

The five Styles HTML pages are canonical inputs. The existing cache-buster writes a
CSS query hash into them during the legacy build; it does not need to edit the Git
checkout for a release. `tools/releases/derived_assets.py build` exports the exact
clean full commit SHA into a new temporary work directory, installs the lockfile
and runs the unchanged SEO, Styles, Law and Vite pipeline there. The output directory
is the distribution, not a source checkout. No git reset, discarded changes or
normalized differences are used. Non-public tracked code must remain byte-identical.
Private/mutable tracked inputs, links and special files are rejected.

The build uses the existing dependencies and generators. No version/lock update is
included. `npm ci --ignore-scripts` precedes the existing build. A complete runtime
build also executes Composer install from lock with scripts disabled, platform
checks and explicit Laravel package discovery under synthetic environment variables.
No source .env or existing vendor/cache is copied. Local `--frontend-only` is explicitly
ineligible for packaging/sealing; it is not a runtime proof.

## Provenance and authority

The evidence records all versioned input hashes, full source SHA, lock hashes,
Node/npm/PHP/Composer versions, explicit non-sensitive configuration, and the complete
output inventory with destination, size, executable flag and SHA256. The caller's
manifest is NOT authority. `verify`, `package` and `seal` independently reconstruct
the entire distribution from a clean export and compare exact evidence and inventory.
A changed file with an updated caller hash is still rejected. Missing, extra,
misplaced, linked or altered files cannot be made valid by editing a JSON receipt.

The 14 Law entries are recomputed against the explicit Blade references, exact
schema, source asset bytes, content-addressed filenames and output bytes. Only the
single named `config/fokus-law-assets.json` is a permitted generated config. There
is no `config/**` exception. Vite entrypoints, referenced files and manifest imports
are checked, along with existing SEO/token/Backoffice contract validators.

Bunny fonts remain an external input of the existing locked Laravel Vite plugin.
Their resulting exact bytes are included and must match the independent rebuild.
This is an observed reproducibility check, not a permanent upstream pin or promise
that a future provider change will reproduce. Divergence fails closed. Transport
proxy/CA variables may be retained for network access; application secrets are not.
An optional `FOKUS_NPM_CACHE` directory is copied as a non-authoritative cache; npm
lock integrity still applies. No cache is required or used as source of truth.

## Explicit #59 / #61 composition

The original #59 and #61 commits are untouched. The new derived sealing path is an
explicit narrow contract extension: tracked distribution HTML may differ only when
an independent build reproduces it, and the exact Law JSON is allowed only after
functional and provenance validation. Other application bytes retain source equality.

`package` emits the #61 transport format; the pinned #61 `unpack` verifies archive
integrity. `seal` reconstructs independently, compares the entire release to that
expected inventory and transport, then writes the original #59 identity format.
The proven evidence is itself hashed as `bootstrap/cache/derived-provenance.json`
in the runtime identity manifest. The receipt binds the real tar digest, full SHA,
physical root, all files and original immutable checks. The final snapshot must
match exactly, including all three generated identity files, before a receipt exists.

Important boundary: the unchanged #59 HTTP consumer validates runtime trees and
only PHP under public. It therefore attests the Law/provenance JSON but does not
reread every static asset on each HTTP probe. Complete static integrity is validated
by the #61 receipt before activation, not claimed from that endpoint. OPcache/preload,
workers, ACL immutability and fleet coverage retain all existing runtime restrictions.
This delivery performs no activation and does not relax the #61 preparer pin or #55
audit fingerprint. Future integration must select this explicitly audited path.

## Commands and tests

Use a disposable checkout and output outside it:

```sh
python3 tools/releases/derived_assets.py build --source "$SOURCE" --output "$CANDIDATE" --evidence "$PROOF"
python3 tools/releases/derived_assets.py verify --source "$SOURCE" --candidate "$CANDIDATE" --evidence "$PROOF"
python3 tools/releases/derived_assets.py package --source "$SOURCE" --candidate "$CANDIDATE" --evidence "$PROOF" --output "$TAR"
```

The test-only `Derived asset provenance` workflow composes the pinned #59 in the
runner's disposable checkout, runs real builds and adversarial tests, then packages,
unpacks via #61, seals and starts the Laravel public endpoint with real PHP on a
loopback port. It checks full SHA, no-store and complete receipt. No workflows can
publish from this job; contents permission is read-only and no secrets are consumed.

Tests cover source changes, private tracked input, missing/extra output, altered
content with forged hashes, wrong generator/lock, wrong destination, incompatible
Law JSON, altered Law assets, HTML outside cache queries, symlinks and incomplete
packaging. The expected output is independently built, not a fabricated fixture.
Runtime tests are reported as executed only after the corresponding CI logs pass.

## Remaining activation boundaries

This is an offline artifact protocol. It does not configure storage/env sharing,
cron, service identities, FPM or production paths. REI-26 remains separate. Release
rollback is not data restoration. Production readiness still requires the real
runtime and recovery proofs described in REI-24 and an explicit activation decision.
