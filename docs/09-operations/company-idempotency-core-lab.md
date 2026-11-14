# Company idempotency: isolated core checkpoint

This code is an unconnected laboratory candidate. It does not expose endpoints,
enable a client, provision keys, establish production key custody, or guarantee
that existing requests are idempotent. Do not enable or deploy it as a complete
feature without the integration and operational proofs below.

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

The unregistered mutation orchestrator binds the normalized payload and checks
for a completed replay before running external preflight, then enters the store's
transaction for local writes. If preflight fails, it rechecks under lock for a
matching completion before propagating failure. Its callbacks are not yet wired
to `createCompany` or `updateCompany`: business uniqueness and optimistic-version
checks must execute inside the writer after the record lock, password security
checks must remain outside long-held locks, and audit must use the same database
transaction. Callback purity and correct normalization require integration tests;
the type signature alone cannot enforce them.
