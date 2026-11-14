# Session authorization primitive: experimental checkpoint

This branch is not enabled or connected to public routes. Experimental hooks
exist for several session invalidation paths behind a separate, default-false
configuration flag; they are not an authorization to activate the feature.
The change does not yet prove stale in-flight request handling. Keep the
existing unconditional public guards closed. The new migration must not be
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
path. The factory used by the guarded controllers now requires a valid marker
and always selects the composed path. A marker with no active durable record
fails closed. Routes remain unconditionally blocked. Writer, audit and
completion must remain on the same connection; callbacks cannot enforce their
own connection or purity. The composed path also locks the Laravel session row,
requires it to exist and be unexpired, and rejects non-database sessions or a
session connection different from the business connection. These checks are
experimental; the deployment topology has not been verified.
Hooks are present for platform and portal logout, successful MFA, support
entry/exit and automatic termination, administrative bulk revocation and idle
session expiration. Portal password/session regeneration hooks have also been
added. They have not been exercised through the real routes. In particular,
account security changes and session handler writes are not proven atomic with
these hooks. Integrate and prove every remaining effective invalidation path,
including grant revocation, session cleanup and mixed session contexts.
The session database can use a different connection; no distributed transaction
is assumed. Commit authority revocation before acknowledging logout, and fail
closed if consistency or persistence is unavailable. Old sessions without an
authority record must require MFA, not silently bootstrap a record.

When the experimental flag is enabled, an explicit hard maximum age is required;
there is no default and it does not replace or change Laravel's idle setting.
This proposal is fail-closed but not a finished user experience or a proven
sliding-idle policy. Configuration is checked before consuming a valid MFA
challenge. In the enabled prototype, the conditional MFA challenge update and
registry replacement share one DB transaction. In the enabled prototype,
password-reset implementation is now explicitly unavailable (HTTP 503 before
password validation or token consumption) while the lifecycle flag is enabled.
The existing experimental transaction is retained for review but is unreachable:
deleting rows by `sessions.user_id` cannot identify all web identities because
Laravel populates it using the default guard, including in mixed/support sessions.
Additionally, membership and invitation updates occur after the reset transaction.
The default-false flag preserves the legacy reset path; it does not fix its
existing limitations. Do not remove this gate until durable principal association,
atomic membership/invitation updates and concurrency are proven.
Cookie/session persistence
follows the DB commit; failure there can require reauthentication and must not be
confused with an atomic cross-system commit. Other token, session and
administrative flows need review. The flag is deliberately disabled by default.

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
operations, factory rejection of an absent marker or record, rollback, revoked
permissions, nested transaction rejection, missing/expired session records,
separate session connection rejection, synthetic MFA/password and session-delete
rollback, a direct Laravel database-session-handler late write, and sequential
query order. The handler exercise does not include actual StartSession, cookies,
request concurrency or a container-provided guard. It does not execute migrations, real HTTP paths,
concurrent transactions or MySQL and cannot demonstrate lock ordering across
processes. Syntax and smoke checks are not evidence of concurrency correctness
or release readiness.

Concurrent reset and writers also require an independent MySQL deadlock and
rollback test: the candidate reset locks a user and can then delete session rows
while a different writer holds another session row. No safe universal lock
order or successful concurrent reset has been established. A deadlock victim must
roll back the token, password, memberships, invitations and authority together.
