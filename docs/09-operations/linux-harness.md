# Isolated Linux release harness

These controls use synthetic data only. They require `RELEASE_DISPOSABLE_TESTS=1`, Linux, no inherited `.env`/`.env.testing` or cached Laravel configuration, and an isolated network namespace containing MySQL on `127.0.0.1:3306` and Redis on `127.0.0.1:6379`. Never run against a real database. The container/network isolation is provisioned by the coordinator; this flag alone does not establish network isolation.

Use PHP 8.4 with PDO SQLite/MySQL and Redis; installed Composer dependencies must match `composer.lock`. MySQL has synthetic root password `security-test-only` and a disposable `fokuscloud_security` database. Redis has no password and is private to the isolated test namespace. The environment is rebuilt from an allowlist, clearing database URLs and real integration keys. PHP HTTP wrappers and outbound HTTP/socket/process functions are blocked during execution. No production secret is required.

Commands, from the repository root:

```
python3 tests/Deployment/test_linux_release_harness.py
RELEASE_DISPOSABLE_TESTS=1 python3 tests/Deployment/linux-upgrade.py --driver sqlite
RELEASE_DISPOSABLE_TESTS=1 python3 tests/Deployment/linux-upgrade.py --driver mysql
RELEASE_DISPOSABLE_TESTS=1 python3 tests/Deployment/linux-release-suite.py --driver sqlite
RELEASE_DISPOSABLE_TESTS=1 python3 tests/Deployment/linux-release-suite.py --driver mysql
```

Evidence defaults to `test-results/linux-release`; `RELEASE_REPORT_DIR` selects another writable evidence directory. Each subprocess records exit code, duration and SHA-256 of its log. PHPUnit JUnit files must contain exactly 373 tests, at least 3,036 assertions, no failures/errors/skips. A test count change requires explicit review rather than silently relaxing this gate.

## Portability adapters, not skipped assertions

The suite keeps every Unit/Feature test. For MySQL/Redis only, two temporary PHP copies replace the original files in PHPUnit's collected suite. The originals remain untouched. Every replacement requires a unique exact anchor and records original/adapted SHA-256 and replacement text:

- `CatalogAdminTest`: capture the server-normalized persisted MySQL JSON bytes immediately after the original update, then retain all endpoint assertions comparing those bytes.
- `PlatformSecurityTest`: replace the single Carbon 61-second advance with a real `sleep(61)`. Record wall-clock wait and that no cache was deleted before the assertion. Other Carbon advances remain unchanged.
- Upgrade reversal: recognize MySQL `DROP `last_official_movement_at`` spelling as equivalent to `DROP COLUMN` for the controlled interruption; retain all original assertions and log the actual DDL when injecting failure.

## Upgrade and synthetic restoration

The historical baseline is fixed at `26daac7c27d246eaef64bb3f0e59499376993f8f`. Existing baseline-gate logic verifies commit existence, ancestry and the reviewed release plan. The harness archives that exact commit and copies current candidate source into separate temporary runtimes. Installed vendor is copied only after both lockfiles match. It requires exactly four additional migrations and uses the unchanged synthetic fixture and unchanged reversal assertions, aside from the documented MySQL injection spelling adapter.

Each driver executes historical migrations/seeding, synthetic fixture population, upgrade, data invariants, idempotence, backup, roll-forward recovery, destructive reversal in the disposable database, restore and all post-upgrade invariants. SQLite is copied only between exited PHP processes and is byte-verified before reopening. MySQL uses real `mysqldump --single-transaction --hex-blob --no-tablespaces` and `mysql` restore, then the full fixture. MySQL database names are generated as `fokus_upgrade_<16 hex>` and removed in `finally`. Credentials pass through environment variables, not command arguments. The dump itself stays temporary and is deleted with the sandbox; only its hash/size enter evidence.

The final negative control injects a fixture failure, requires exit 1 and checks the exact failure reason. This is recorded as an expected negative, never presented as a failed product test. A failed subprocess raises immediately and preserves evidence; a successful Linux harness does not establish browser HTTPS, production backup, or permission to deploy. Source hashes here describe their documented manifest format, not a Git commit or another stage's source-hash format.
