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
The candidate now places membership and invitation updates inside its reset transaction; the guarded real route remains untested.
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

## Proposed principal-aware continuation (not implemented or approved for activation)

A Laravel session can contain both a platform administrator and a portal user.
`DatabaseSessionHandler` writes `user_id` using the default guard, so the sessions
row cannot be the authoritative list of all affected portal identities. The
session payload is not a safe index either, and deleting matching rows alone does
not serialize a new login or a request which already loaded its session.

Introduce a durable principal generation on each portal user and bind the exact
web user ID and generation (or an explicit no-web-principal state) to every
platform authority marker. The marker must be replaced whenever portal login,
logout, identity switch, support entry or exit changes that state. Do not infer
this binding from `sessions.user_id`. A writer reads the authority's principal
hint, locks that user, then the administrator, binding, Laravel session, grants
and idempotency token in a globally defined order; it must re-read the binding
under lock and reject if the hint or generation changed. A no-user binding must
be explicitly represented and revalidated. All transitions which add or remove a
web principal must use the same order, or the scheme is not valid. Existing
bindings with unknown context fail closed and require reauthentication.

A reset locks the target user before any actor/binding locks, validates and
consumes its token in the same transaction, increments the user generation,
updates the password, and activates memberships and invitations in the same
transaction. Thus a reset committed first rejects a stale principal binding; a
writer which obtained the user lock first finishes before the reset commits.
When the request also has a platform context, lock both principals in the same
order, revalidate the context, and retire or replace the binding atomically.
The generation also needs enforcement in portal authentication middleware and
support workflows: protecting backoffice writes alone is not complete revocation.
Database session cleanup is then best-effort hygiene, not the authority. Avoid
bulk session deletion inside this transaction until its lock order is proven.

An isolated principal-generation and principal-bound authority primitive now
implements the non-null user case, with a top-level transaction and revalidation
under the user and actor locks. The existing authority methods and real callers
do not use it; they remain unsafe to expose. The no-user case, real MFA, login,
context switches, portal middleware and reset are not integrated. This is a
partial candidate, not a release guarantee. It needs a precise
inventory of all writes and principal transitions, proof against concurrent
first-login/registration and missing users, and a migration/rollout policy for
old sessions. Use deterministic two-connection MySQL barrier tests for reset
versus real authenticated writer in both orders, concurrent logins, mixed and
support contexts, deadlock victim rollback, and failures at each membership or
invitation update. Real HTTP tests must cover Laravel StartSession persistence,
cookies, CSRF and guard selection. Only remove the temporary 503 when those
proofs pass and independent review confirms every affected path.

The legacy writer, rotation and MFA replacement now reject records explicitly
bound to a web principal; MFA replacement also rejects a missing prior record.
A null web principal is still an unknown legacy state, not proof that the session
has no web guard. Consequently these checks cannot establish that all mixed
sessions are detected. Explicit no-user state, trusted context transitions and
integration with the real store remain mandatory before removing the route guards.

The experimental schema now distinguishes unknown context (`principal_known =
false`) from a verified platform-only context and a principal-bound context. Old
issuers produce unknown context; old writer, rotate and MFA replacement reject
it. Synthetic tests directly mark selected fixtures platform-only solely to
exercise existing primitives; no real issuer has been shown to verify and record
that state. The real MFA controller now fails closed before consuming a challenge when the
flag is enabled. Direct primitive calls remain experimental and are not safe
issuance APIs. Lifecycle activation remains prohibited until issuance verifies
trusted context and tests cover the real flows.
