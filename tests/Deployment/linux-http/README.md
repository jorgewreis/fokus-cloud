# Linux HTTP release gate

Run only in the dedicated Linux runtime after dependencies, assets and Playwright Chromium are installed. Use an unprivileged Linux user with a working Chromium sandbox. The harness intentionally retains `chromiumSandbox:true`; it does not bypass certificate errors or browser sandbox restrictions.

```
RELEASE_DISPOSABLE_TESTS=1 RELEASE_REPORT_DIR=/work/evidence/http-attempt-01 python3 tests/Deployment/linux-http/run-all.py
```

Prerequisites: PHP 8.4 with MySQL/Redis drivers, Node 24, application lockfile dependencies, Git history, writable app storage/cache, MySQL on 127.0.0.1:3306 (root / security-test-only), and synthetic Redis on 127.0.0.1:6379. The container network must prevent production/internet access. No host or personal volumes are required.

The report directory must be new and empty. The command creates `fokus_synthetic` without `IF NOT EXISTS`, refusing to reset an existing database. Only after successful creation does it run migrations and synthetic seeders; finally it drops only that owned database. Do not run concurrently with another gate using this same DB. Redis databases 10/11 use the `linux-http-` prefix. Logs, fixtures and all failed-attempt evidence remain local in the requested report directory.

The preserved tests execute nine HTTP concurrency scenarios and verify 94 assertions, followed by nine browser scenarios with 59 assertions. A count mismatch fails the aggregate gate. Dynamic source manifests bind tests to actual release bytes before and after execution; freeze the candidate before running. The check excludes runtime storage/dependencies and records the participating file hashes. No application sources are modified.

`adaptations.json` contains source and derived byte hashes plus unified diffs. Changes cover Linux process launching, portable paths, dynamic source identity, a Linux owned-process exit check, lockfile Chromium instead of Windows Chrome, and explicit disposable-runtime guards. Application assertions and synthetic PHP fixtures are preserved. The browser router also loads the existing network guard. These scripts were ported from the prior external harnesses; lint is not execution evidence.

Scope is real loopback HTTP, MySQL row locks, Redis, authenticated sessions and CSRF. `SESSION_SECURE_COOKIE=false` is deliberate for HTTP only. This gate does not prove HTTPS, browser Secure-cookie transport, origin proxy configuration or production readiness.
