# REI-24: supervised generation activation gate

This isolated integration prepares technical homologation, not production rollout.
It builds on #59 b271260d, #61 a4fab9f4, #62 29df5490 and #63 2d13fa4b.
Original branches, immutable artifact contracts, release identity and AUDIT_DIGEST
are not modified. PR60 is excluded.

## Contract and entry points

`generation_activation.py` reuses the exact pinned #61 policy, artifact receipt,
node binding, runtime gates and post-activation verifier. It replaces only the
static scheduler rejection at this **new** activation entry point. Legacy #61
entry points remain conservative; they are not monkeypatched or made permissive.

`generation_supervisor.py --handoff-boundary PATH` optionally emits an activation
proof while still holding scheduler.lock, after actual ECHILD, successful descendant
exit results, Laravel event reconciliation and receipt revalidation. The proof
binds full physical previous/current generation SHA, node, boot, source-code hashes,
receipt, journal, immutable controller-owned event snapshot and boundary evidence.
It expires within 120 seconds, also measured by same-boot monotonic time. Every later
scheduler attempt invalidates the previous proof before validation or launch.
Laravel withoutOverlapping installs a rejection filter; its first skip dispatches
ScheduledTaskSkipped before Starting/Finished. Both this path and the later mutex
race are recorded. Distributed onOneServer schedules conservatively emit a scope_unverified record at CommandFinished, after console route registration,
because Laravel's cross-server skip has no terminal event. Any skipped task cannot qualify: its mutex does not establish that prior
or external work terminated. Ordinary scheduler execution still permits a valid skip.

The boundary JSON uses schema `fokus-generation-handoff-v1`, exact base/root,
receipt_sha256, node_sha256, boot, operator_uid, verified_at, expires_at (maximum
300 seconds), evidence_reference, and explicitly verified scope fields:
all_scheduler_entrypoints_controlled, external_workers_absent_verified,
no_external_process_delegation_verified, pre_supervision_work_absent_verified,
permanent_workers_absent_verified, queue_generation_compatibility_verified.
There are no production defaults. These attestations describe separately inspected
operational scope; they are **not** drainage evidence. Missing scope blocks even
when the supervised tree drained. A boolean alone never qualifies activation.

Subreaper proves descendants launched/adopted by that controller, including orphaned
double-forks. It does not establish absence of outside cron, permanent workers,
pre-supervisor work, delegated services or queued jobs. Compatibility of queued
payloads and future generations is a separate explicit requirement.

Operator-owned control paths and ancestors cannot be symlinked or writable by
runtime/other principals (trusted root-owned sticky temporary ancestors are allowed).
Evidence is protected by that OS boundary, not a signature against compromised root.
The production adapter retains the original separate runtime UID and receipt gates.

## Activation and recovery

The activation command requires base, candidate, receipt, policy, previous,
previous-receipt, boundary and the exact immutable-contract source. It locks
activation then scheduler, verifies both releases and all original policy gates,
rejects any latch or unfinished/inconsistent journal, verifies the exact latest
successful run and slot record, and consumes its proof once. A durable intent latch
and journal are written before consumption; consumption precedes pointer mutation.
All records remain for recovery. The pointer switch does not imply FPM or workers
updated: the original verifier and runtime prerequisites remain mandatory.

`--code-rollback` retains schema/data compatibility gates and **cannot bypass a
pending latch**. No automatic database, storage or migration rollback exists.

`recover` is explicit manual acknowledgement, not a retry: exact journal/latch/run,
same boot/node, consumed proof, known previous/candidate current root, fresh original
policy, receipt and successful actual verifier are required before clearing a latch.
It does not recreate consumed proof. Interrupted unknown states, missing consumption,
other current roots or unresolved work remain blocked. A new supervised cycle is
needed for any later activation.

## Tests and evidence boundaries

- 26 local process/state-machine gate regressions plus 21 supervisor tests.
  Local gate fixtures stub artifact/runtime HTTP contracts and are not real runtime
  evidence. Cases include active descendants, double-fork, expiry, node/receipt/code
  mismatch, slots, overlap, failed later run, external unknown, concurrent locks,
  abrupt os._exit before/after pointer, verifier failure and explicit recovery.
- Remote test workflow composes the pinned #59/#61/#62 with this #63-based branch.
  It builds two actual artifacts with locked npm/Composer dependencies, seals and
  verifies receipts, runs PHP/Laravel foreground/background and disposable database
  queue/cache, and exercises the new gate under a real scheduler lock.
- Integrated HTTP verification uses the exact #57 verifier protocol with an explicit
  loopback HTTP transport injection against the real #59 PHP endpoint. It tests a
  stale real endpoint, persistent block, healthy manual acknowledgement and an
  explicitly requested synthetic code-only rollback. It does **not** prove TLS,
  production FPM, OPcache, cron, permissions or actual VPS policy attestations.
- SQLite tests the selected database API path, not MySQL/Redis concurrency equivalence.
  Existing MySQL/Redis CI is separate evidence, not coverage of this new fixture.

## Consolidated readiness matrix

| Area | State | Remaining condition |
|---|---|---|
| Supervisor #63 and gate adapter | Implemented; local regressions passed | Exact-head remote outcomes are recorded in PR #64 and REI-24; acceptance requires their verified passing state |
| Identity #59, artifacts #62, primitives #61 | Reused at pinned commits; executable composition test included | Consult PR #64 logs for the tested head; no merge performed |
| FPM/OPcache/cron/ACL/user switch | Pending runtime homologation | Actual environment evidence and separate authorization |
| External/legacy/permanent work and queue compatibility | Pending runtime homologation | Fresh bounded scope evidence; otherwise blocked |
| Production bootstrap, recovery and publication | Blocked by operational decision | Explicit reviewed activation plan and authorization |
| #55 fingerprint | Not changed | Separate explicit re-audit after operational changes |
| #56/#57/#58 and PR60 | Existing contracts preserved; PR60 excluded | No integration authorization inferred |

No server changes, merge, deploy, service restart, production migration, permission
change, paid API or external paid infrastructure is performed by this delivery.

### Console lifecycle evidence

The real-process fixtures use `APP_ENV=fokus-synthetic`, not `testing`. In the
locked Laravel 13.24.0 sources, `Application::runningUnitTests()` is exactly the
`env === testing` check, and `Console\Kernel` suppresses Symfony command-event
rerouting in that mode. Thus the earlier fixture failure did not prove that the
schedule collection was incomplete: both command hooks were disabled. Sources:
[Application](https://github.com/laravel/framework/blob/v13.24.0/src/Illuminate/Foundation/Application.php)
and [Kernel](https://github.com/laravel/framework/blob/v13.24.0/src/Illuminate/Foundation/Console/Kernel.php).

No production environment is used. The sealed temporary release, synthetic queue
job and disposable SQLite database still provide isolation; no Datajud calls or
customer data are introduced. The gate requires exactly one `scope_checked`
record after the completed schedule's full traversal. Missing or duplicated
lifecycle evidence blocks handoff, even with successful local tasks. The real
fixture also runs a negative `APP_ENV=testing` case: suppressed lifecycle must
fail with `scheduler_lifecycle_unproven`, never produce a ready proof.
