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

A separate initial-MFA primitive now commits challenge consumption and the
explicit principal state together, acquiring the web user lock first when a
web principal exists. The real MFA controller now calls an adapter which reads
the web guard and rejects an existing binding, but its configuration gate
still makes the experimental path unreachable. The guard adapter was tested
only with a synthetic guard. It does not
serialize support/login changes, session persistence or concurrent requests and
must not be used to remove the MFA gate. The isolated SQLite smoke demonstrates
only sequential success and rollback; it does not validate a real guard.

A mixed initial MFA now requires a user ID and generation snapshot from the
web session and checks both generation and user status under the user lock,
before consuming the challenge. Portal login and support start now capture this snapshot behind the flag,
but other authentication paths remain unproven and reset does not advance it. Missing snapshot
fails closed. The no-user case is not serialized against a concurrent web login;
therefore the endpoint gate must stay closed. Synthetic tests provide their own
snapshot and do not establish end-to-end authentication or concurrency behavior.

Portal login and support-start now capture a generation snapshot under the user
lock in the flag-enabled prototype, verifying that the password hash observed by
the caller still matches the locked row. The snapshot contains no password hash.
Other authentication paths and nullable-password accounts have not been proven;
the reset path still does not advance the generation. Snapshot capture and
session persistence are distinct operations. Sequential in-memory tests exercise
both snapshot/advance orders but are not concurrent MySQL or HTTP proof.

Registration of a pending user must not fail solely because this user cannot
receive a platform authority. The prototype omits and clears the snapshot for
such a user. If a platform actor or binding already exists, experimental
registration is rejected before database writes and email because that context
transition is not atomic yet. This is a temporary closed path, not complete
support for mixed registration. Snapshot failures other than eligibility still
need an end-to-end failure audit; real invite, support, session-save and reset
paths have not been proven. The flag remains off and the routes stay guarded.

Security denial update: snapshot capture now propagates stale-password and
ineligible or changed-status errors. A pending user is allowed to omit the
snapshot only after matching both status and password against the locked row.
Experimental registration is currently blocked before all writes and external
notification, including for an otherwise legitimate pending account, until
its transaction and session effects are proven. The flag-off path is unchanged.

The experimental flag now blocks verify-email and accept-membership before
consuming their tokens, until token effects and session transitions are atomic.
The shared authentication helper captures the principal before ending support,
so a capture denial cannot itself end support first. Neither change proves all
login or support races; the default flag is still false. HTTP tests for these
pre-effect gates use synthetic tokens and must run only against an isolated test
database. Never infer production readiness from the temporary 503 responses.

The separate `PrincipalSecurityToken` prototype serializes on the user before
locking and consuming the token. Its membership mutation groups token,
membership, unit memberships and invitation; its email mutation groups token,
user, company and a same-connection audit callback. Neither path is wired to
controllers. Their SQLite rollback tests do not cover real HTTP, MySQL, audit
side effects, Eloquent model events, email uniqueness races or session persistence.
Do not remove the token gates on the strength of these primitives.

Eligibility review: the existing invitation creation path requires an active,
non-deleted company and an active/pending user, and records an expiry. The
experimental token service now rechecks these, a pending/non-deleted membership,
and a single unaccepted unexpired invitation under locks. More than one valid
invitation is rejected provisionally because precedence is not specified; this
can reject existing data and must be resolved before integration. The legacy
email verification still sets status active without this eligibility check, and
legacy acceptance lacks these checks; this branch does not change those routes.
The email prototype now uses Eloquent save for its events and timestamp, but a
synchronous observer may cause effects outside the DB even if the transaction
rolls back; audit and observers need review. Current invitation lock order starts
with user, while existing invitation creation locks company first; concurrent
MySQL deadlock and rollback tests are mandatory, and no global lock order has
been proven.

The current emitters store only `membership_id` in token payload and cannot
unambiguously map historical tokens to an invitation. The isolated acceptance
candidate now requires `invitation_id` in the locked token payload and checks
that exact row's membership, company, expiry and acceptance state. Legacy
tokens without it fail closed in this candidate; no emitter or public route is
connected, and issuing a replacement must be designed without inventing a
commercial expiry or invalidation policy. Tests verify an absent/wrong ID and
that accepting one of two matching invitations leaves the other untouched.

Lock order cannot be declared solved: platform invitation creation locks company
before user; company-user creation locks user before creating membership; the
prototype locks user, token, membership, company and invitation. A candidate
unified order is company -> user -> token -> membership -> invitation (with
actor/session locks placed consistently for every affected flow). It requires
changing and testing *all* issuers, acceptance, cancellation, account and session
paths; reading untrusted token or membership hints before locking must be followed
by full revalidation after locks. SQLite records the candidate's current query
sequence only; it cannot prove MySQL locking, gap-lock behavior, deadlock freedom,
or safe cross-product integration. The existing sendToken also expires all
unused tokens of the same user/purpose, potentially affecting another company's
invite; preserve this as a documented legacy risk pending an explicit design.

The two identified invitation emitters now include the exact invitation ID in
token payload only when the experimental lifecycle flag is true; the flag-off
payload and existing tokens are unchanged. The company-user emitter acquires the
company row before the user in that experiment, as the platform emitter already
does. The isolated acceptance candidate now reads company/user/token hints
without locks, then locks company -> user -> token -> membership -> invitation
and revalidates every hint before writes. Other writers and session flows are
not harmonized, and the legacy global token expiry in sendToken is unchanged.
This code has not been activated or tested via real issuer HTTP flows. Its
SQLite query-order test does not prove actual MySQL lock behavior or safety
against all other transactions. Tokens without invitation ID cannot use the
candidate; the legacy route remains guarded from the experimental path.

Both experimental invitation issuers are now blocked before validation and
writes while the consuming paths are unavailable. The existing flag-off portal
issuer still calls SMTP within its database transaction; the existing platform
issuer commits before calling sendToken. Neither has transactional email, and
this checkpoint does not fix those legacy behaviors.

A future outbox needs a single DB transaction for invitation, exact token binding
and delivery intent, plus a worker with durable claims, retry/backoff, expiry,
monitoring and recovery. Because the link must be delivered but tokens are
stored hashed, the design must decide how to protect and eventually purge any
recoverable token material, including key rotation and access. SMTP outcomes
can be unknown after a timeout; DB and SMTP cannot provide exactly-once delivery
by themselves, so retry semantics and duplicate emails need an explicit product
and operational policy. Reissue must target one specific invitation and avoid
invalidating unrelated companies' tokens, while preserving existing links until
a compatible migration policy is settled. No outbox, scheduler, queue, key or
commercial-expiry policy is configured or implemented by this checkpoint.

Additional pre-effect gates now cover password-reset requests, verification
resends, email-changing profile updates, company registration for an unverified
current user, and platform-forced password reset. The common sendToken also
rejects the four affected purposes as a defense in depth. Other purposes such as
admin transfer are not gated. Password reset returns the same unavailable response
before CPF lookup for known and unknown accounts; it does not falsely report that
an email was sent. Profile-only changes and company creation by a verified user
remain available. Tests use synthetic records and an isolated SQLite database;
these gates do not solve legacy delivery or provide an outbox.

A new isolated outbox staging experiment creates an invitation-bound token and
an encrypted recipient/token envelope in one DB transaction. It checks a
caller-supplied expiry against the existing invitation expiry, retains existing
tokens, and deduplicates the same request key for the same target and expiry.
It is not wired to an issuer, worker or scheduler and does not send email. There
is no claim/lease protocol, retry, purge, key-rotation story, provider outcome
reconciliation or tested MySQL concurrency. Do not deploy the migration or
activate a route from this partial primitive; encrypted token retention is a
security and operational liability until those parts are implemented and
reviewed. The caller must generate and protect the random request key.

Outbox staging requires an explicit issuer context and compares the encrypted
recipient with the locked user on retries. A distinct request for the same
invitation is blocked while its staged token remains active; unrelated company
invites are not blocked. This does not serialize legacy issuers.

A future worker must lock and revalidate company, user, token, membership,
invitation and delivery record in a single consistent order, then compare the
current verified address, user status, exact token purpose/binding, expiry,
invitation status and encrypted envelope. If any change is found, revoke the
pending token and remove recoverable ciphertext atomically; do not send. A
separate policy is required for reissue, simultaneous invitations, key rotation,
retry, manual reconciliation and old ciphertext purge. Revalidation immediately
before SMTP still has a race with a later email change once locks are released;
SMTP cannot participate in the DB transaction. Holding locks through SMTP has
its own failure and availability costs. The system must explicitly choose and
prove a cross-flow synchronization or acceptable-delivery policy before a worker
can be enabled; neither this code nor sequential SQLite tests resolve that race.

The platform issuer selects purpose according to verification status:
verified email uses membership_acceptance; an unverified user receives a
password_creation token. The password-creation consumer remains closed and has
not been adapted to the exact invitation ID. A different request key cannot
stage another unexpired token for the same invitation if it was staged through
this outbox; another company/invitation for the same user remains eligible.
Same-key retry additionally checks the current address and the original token's
purpose, active state and hash. No old token is invalidated. Tokens issued by
legacy paths without an outbox record cannot be mapped reliably, and a legacy
issuer can race this check. This is not a complete reissue policy or proof that
only one valid token exists across all issuers.


Portal issuer behavior differs: an existing account uses membership_acceptance
regardless of verification; a newly created account uses password_creation.
The experimental stage API now requires an explicit platform, portal_existing or
portal_new issuer and includes it in idempotency matching. The existing-account
classification must be derived by trusted server code inside the real issuance
transaction, never from request input. That provenance is not yet persisted or
connected to either real emitter; no delivery is enabled.

The isolated portal composition API now owns the top-level transaction, locks
the company, queries CPF to derive whether the account already exists, invokes
trusted server-side authorization on both initial execution and replay, and
stages a graph created on that same connection. A keyed digest of canonical
intent is checked on replay; creation, token and outbox rollback together in
synthetic SQLite tests. Standalone stage no longer accepts a portal issuer.
This is not the real controller integration: caller-supplied callbacks and
intent cannot be treated as authorization or canonical data from HTTP, and
external side effects cannot be ruled out by PHP's callable type. A real adapter
must construct both callbacks and intent exclusively from validated server-side
contracts, revalidate assignments and permissions under locks, and ensure audit
uses the same connection. Key rotation currently invalidates old intent digests;
a versioned key strategy is required. Unique-CPF gap locking, duplicate-key
races, other writers and both transaction orders require real MySQL tests. No
route or worker is connected and the experimental gates remain closed.
