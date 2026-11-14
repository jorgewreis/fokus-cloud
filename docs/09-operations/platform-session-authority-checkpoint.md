# Session authorization primitive: experimental checkpoint

This branch is not connected to routes, authentication, session storage or the
company idempotency store. It does not remediate stale in-flight requests. Keep
the existing unconditional public guards closed. The new migration must not be
deployed as a standalone security fix.

The experimental primitive uses the default business database connection, locks
the administrator before the session authorization row, and stores only a
SHA-256 digest of the random 256-bit server-generated marker. A successful
operation and revocation must serialize on that administrator row. Expiration is
decided when the authorization is locked; it does not cancel a transaction that
has already acquired the lock. No idle lifetime or renewal policy is chosen.

The store now has an experimental constructor path that composes session
authority and idempotency within one top-level transaction. Its five operations
acquire actor, session, authorization and token rows in that order; the legacy
constructor remains available and must never be exposed as a session-protected
path. No route or factory selects the composed path yet. Integration must make
the session authority mandatory before removing the public route guards. Writer,
audit and completion must remain on the same connection; callbacks cannot enforce
their own connection or purity.
Integrate and prove every effective invalidation path, including both platform
and portal logout, MFA, support entry/exit and automatic termination, account
and grant revocation, idle expiry, session cleanup and mixed session contexts.
The session database can use a different connection; no distributed transaction
is assumed. Commit authority revocation before acknowledging logout, and fail
closed if consistency or persistence is unavailable. Old sessions without an
authority record must require MFA, not silently bootstrap a record.

The authority primitive itself does not perform actor status and permission checks;
the composed store uses the existing authorization service in its transaction. A caller
must not treat `registerAfterMfa` as an authentication endpoint. Validate
connection identity, database time/clock assumptions, session expiry and renewal,
session write and cookie races, recovery, migration/rollout and bulk invalidation.
Prove on isolated real MySQL with independent sessions and barriers that (a) a
committed revocation prevents the real writer and (b) a writer that holds the
lock commits before revocation is acknowledged, with audit and idempotency
atomic. Repeat the two orders for each supported invalidator and session driver.

An isolated smoke script constructs its own SQLite `:memory:` connection and
checks registration, actor isolation, stale binding rejection, rotation,
specific and bulk revocation, hash storage, expired registration, composed store
operations, rollback, revoked permissions, nested transaction rejection and
sequential query order. It does not execute migrations, real HTTP paths,
concurrent transactions or MySQL and cannot demonstrate lock ordering across
processes. Syntax and smoke checks are not evidence of concurrency correctness
or release readiness.
