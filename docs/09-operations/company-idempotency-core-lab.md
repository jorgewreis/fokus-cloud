# Company idempotency: isolated core checkpoint

This code is a laboratory candidate. Preparatory management routes are present
but an unconditional middleware blocks them; the company-write guard also
rejects keyed writes. It does not enable a client, provision keys, establish
production key custody, or guarantee that existing requests are idempotent. Do
not enable or deploy it as a complete feature without the proofs below.

As a fail-closed transition guard, company create and update reject every
request carrying an `Idempotency-Key` header with HTTP 503, after existing
platform authentication and permission checks. Header contents are not
reflected in the response. Requests without the header retain the previous
contract; they do not acquire an idempotency guarantee. This guard must only be
replaced when all serving nodes enforce the complete contract consistently.

Issuance requires three explicit integer limits supplied to the store: maximum
uncompleted and unrevoked records, maximum issues in a rolling window, and the
window duration in seconds. No production values or retention policy are set.
Both quotas are checked while the administrator row is locked. The rolling
window counts every issued record for that actor, including completed, revoked,
and expired records. Its recorded time is taken from the server after acquiring
the lock; callers cannot choose it. This is a per-actor control, not a global
storage budget or a substitute for an HTTP rate limit.

There is no cleanup job. A future cleanup must preserve records for at least
the longer of the configured issuance window and the token status/recovery
period, including any required operational tolerance. Deleting records inside
the window would weaken the quota; deleting them while reconciliation is needed
could lose evidence. Restores and clock drift also affect the count. Deployment
requires a trusted clock and a reviewed procedure for multiple nodes, backup,
restore, compromised keys, quotas, and future cleanup. No clock-synchronization
or durable quota proof is implied by the laboratory test.

The migration's automatic `down` deliberately fails closed; a reviewed forward
recovery procedure is required rather than dropping request records. The Linux
upgrade manifest and full migration/restore tests must be updated before merge.

The isolated factory can construct the store only when explicitly enabled and
all keyring, active-key, revocation-list, quota, window and lifetime settings
are present and valid. This setting does not expose routes or bypass the guard.
The keyring and revocation list use canonical compact JSON without whitespace;
duplicate object keys, malformed values, missing revocation configuration and
unknown or revoked active keys are rejected. JSON slash escaping must match the
canonical unescaped form. Errors do not include configuration values. Keyring
material can still be included in Laravel's configuration cache: permissions,
custody, backup exclusion and recovery must be verified before provisioning.

Required before integration: authenticated and CSRF-protected endpoints, fresh
authorization across the real request and session lifecycle, canonicalization of
the exact POST/PATCH fields including password without logging them, mutation
and audit on the same connection, replay and status semantics, revocation,
timeout/recovery and browser handling, explicit key custody and rotation,
release coexistence, plus security review. The isolated MySQL test exercises
row-lock ordering and a synthetic quota window, not those end-to-end contracts.

The mutation orchestrator binds the normalized payload and checks
for a completed replay before running external preflight, then enters the store's
transaction for local writes. If preflight or the writer fails, it rechecks under
lock for a matching completion; otherwise it reports an indeterminate HTTP 503,
because another matching request may still commit. Laboratory-only calls to
`createCompany` and `updateCompany` use that path with the public guards bypassed:
business uniqueness and optimistic-version checks run inside the writer, password
security checks outside the long-held lock, and audit on the same transaction.
The tests use fake password verification and synthetic data. Real HTTP CSRF,
cross-node rollout, credential custody and provider behavior are not established.
Callback purity and normalization require independent review; type signatures
alone cannot enforce them.

An experimental session envelope signs the inner token and the current platform
session marker with a separate HKDF-derived purpose. The marker is not stored
in the idempotency table; the table continues to hold only the inner token hash.
Issue requires a marker and status, revoke and keyed writes verify the envelope
before accessing the store. An old token without an envelope, an absent marker,
another session or a revoked key fails closed. This changes the token wire
contract and deliberately provides no legacy-token fallback. No migration is
needed for this laboratory approach; a future rollout must assess compatibility,
key rotation, session persistence and recovery before enabling any route.

The envelope does not prove instantaneous revocation of a request that loaded
its session before logout or support-session rotation. Laravel session locking,
concurrent requests, multiple nodes and cross-tab logout remain unverified. A
same-actor different-marker rejection in a synthetic test is not proof of those
races, and no public guard may be removed on that basis.

The unit test `test_envelope_alone_does_not_revoke_a_previously_loaded_session_snapshot`
demonstrates the remaining defect: after the in-memory session is invalidated,
an earlier snapshot still verifies the envelope. It intentionally verifies the
limitation, not a security fix. Production configuration chooses database
sessions, but `SESSION_CONNECTION` can select a connection different from the
business transaction. Logout invalidates the session through Laravel's session
handler; support entry and login regenerate sessions; account blocking deletes
session rows. A solution must identify and synchronize all effective revocation
paths on the same durable lock as the mutation, including expiration, bulk
revocation, login and support rotation. It must fail closed when driver, table,
connection or session identity cannot be verified. A row-existence check or a
lock on the in-memory marker alone is insufficient.

Before removing any guard, an isolated real-MySQL integration test must hold a
barrier after the request loads the old session and prove both orders: revocation
commits first and the actual company writer performs no mutation, or the actual
writer commits first and revocation observes that ordering without claiming to
undo it. Repeat for every supported revocation path and session connection,
and test logout, reload and multiple nodes. No such two-order proof exists here.

The guarded MySQL API concurrency test was run against commit `93d52eaf`
using a new, initially empty MySQL 8.4.13 container with networking disabled
and temporary storage: one test and ten assertions passed. Native InnoDB AIO
was disabled for that laboratory because the host had exhausted its AIO
resources. The test bypasses the write-blocking middleware only within the
test and fakes the password-check provider. It is not evidence of real browser
recovery, CSRF, external provider operation, or production key custody.

The destructive test bootstrap requires an exclusively owned disposable server,
an initially empty database and the expected server UUID. Before each migration,
the guard rechecks approval, connection settings, network namespace and server
identity. It rejects split read/write connections. SQLite feature tests require
an in-memory testing database. The general MySQL release harness must integrate
the guarded bootstrap and adapt the SQLite-only store test before those tests
can run there; do not bypass the guard to make that harness pass.
