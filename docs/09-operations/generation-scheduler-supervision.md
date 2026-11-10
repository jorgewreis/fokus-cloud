# REI-26: scheduler generation supervision

Implementation for isolated review. No production cron, services, permissions,
configuration or data are changed. The existing `runInBackground()` remains intact.

## Observed application contract

The Law worker is a finite `queue:work law-datajud` scheduled each minute, with
`--stop-when-empty --max-time=50 --timeout=70 --tries=1`, `withoutOverlapping(5)`
and background execution. The database connection has retry_after 120. Other
scheduled commands have their existing cadence and mutex policies. A long-running
permanent worker is not a bounded scheduler cycle and is not silently adopted.

Laravel 13 ScheduleRunCommand dispatches ScheduledTaskFinished after launching a
background command, not when that work finishes. Its command may also return zero
when a foreground task failed. ScheduleFinishCommand receives the background exit
code and emits ScheduledBackgroundTaskFinished. Queue workers can return zero even
when a queued job failed. These are separate facts, all accounted for below.

Official source consulted: Laravel framework v13.24.0 Event, ScheduleRunCommand,
ScheduleFinishCommand, ScheduledBackgroundTaskFinished and queue events. The exact
installed version remains governed by composer.lock, not this descriptive reference.

## Mechanism and scope of proof

`tools/releases/generation_supervisor.py` is a dedicated Linux child subreaper.
It verifies PR_SET_CHILD_SUBREAPER with PR_GET_CHILD_SUBREAPER before launch. It
rejects pre-existing children, starts one physical release generation and waits
until waitpid reports ECHILD. Orphans from double-fork and setsid are adopted and
reaped; a PID scan or the main process exit is never accepted as drainage proof.
No systemd or cgroup dependency is introduced. Unsupported prctl fails closed.

The supervisor holds the same scheduler.lock used by #61 activation. A durable
activation-blocked.json latch and per-run journal are fsynced before launch. A
global per-minute slot record prevents sequential duplicate triggers across
release changes, in addition to concurrent flock exclusion. Records are not deleted
automatically. This does not promise exactly-once business effects after queue retry.

GenerationScheduleProvider is opt-in only when the supervisor supplies a valid run
ID, full SHA, exact physical root and pre-created external event file. It records
no command arguments, job payloads, secrets or personal data. Event identifiers are
hashes of mutex names. A start is recorded in a before callback, after Laravel has
acquired its mutex. Legitimate overlap skips are recorded without inventing success.
Foreground terminal codes, real background terminal codes, JobFailed and
JobExceptionOccurred are checked. Missing/duplicate/malformed results fail closed.
Repeated sub-minute events with the same mutex ID are currently unsupported and
block rather than correlate ambiguously; current application schedule has none.

Only a clean process drainage plus complete successful event results can remove the
latch. Policy/receipt/root/ownership/expiry are checked under the scheduler lock both
before launch and after drainage by the CLI adapter. Runtime UID/GID must differ
from the controller, own neither code nor controls, and have separately verified
read/write access. Group/other-writable or non-owner control files are rejected.
Runtime writes only its external mode-0600 event log. The template is intentionally
unconfigured; no production values are invented.

The event log is operational evidence from reviewed sealed code, not cryptographic
attestation against a compromised runtime. Subreaper covers only descendants it
launched, not pre-existing workers, legacy cron or delegated external services.
These require explicit fresh evidence that every scheduler entrypoint is controlled,
external workers are absent and children cannot delegate work outside supervision.
The CLI remains blocked without it. No production capability is inferred from CI.

## Failure and recovery

A timeout records failure but the supervisor continues reaping until descendants
finish; it does not kill jobs or pretend drainage at the deadline. New execution
and activation remain blocked. A permanent/hung worker requires operator review.
Any nonzero reaped status, job failure, missing result, integrity change, timeout or
unexpected exception leaves a manual-recovery latch. If the controller is killed,
its lock may be released but the durable latch remains. Neither absent PID nor a
reboot proves that abandoned descendants or transactions are safe.

`recover_drained` is a narrow explicit acknowledgement API: it requires exact
journal/latch/run binding, same boot, same current root and a terminal failure with
`drained=true` written after ECHILD. It does not restore data or undo failed jobs.
Unknown, incomplete, interrupted or cross-boot states cannot be cleared by it.
Re-execution in the same slot remains refused. Production recovery must additionally
review job/mutex/data effects; this delivery installs no automatic recovery command.

## Compatibility and honest integration boundary

#59 identity and #62 artifact construction remain unchanged. The disposable test
builds a complete artifact with exact #59/#61/#62 revisions and synthetic schedule,
then uses original #61 unpack, receipt verification and activation-lock behavior.
Shared queue/cache state is in an external disposable database, not release code.
Receipt verification is repeated after jobs; the sealed tree must remain unchanged.

The old #61 textual background guard deliberately remains conservative. This
supervisor does not monkeypatch it and does not enable production activation after
drainage. Tests prove that actual #61 activate cannot enter while work is active,
that drainage releases the lock/latch only on verified success, and that the old
guard still blocks until a separate explicit integration decision. Do not call
this rollout-ready or imply a production switch was executed.

## Verification

`python3 tools/releases/test_generation_supervisor.py -v` uses real fork/wait,
double-fork/setsid, locks, timeout, abrupt death, journals, duplicate slots and
recovery in disposable directories. These tests do not assert PHP/runtime permissions.

The test-only Generation scheduler supervision workflow composes pinned #59 b271260d,
#61 a4fab9f4 and #62 29df5490 in a runner checkout, never #60. It builds a real sealed
artifact, runs Laravel/PHP with the actual database queue and database cache backed
by disposable SQLite, exercises success/background, queue failure, foreground
failure and mutex skip. No actual Datajud command or customer data is used.
SQLite proves the selected driver API path, not MySQL locking equivalence. FPM,
OPcache, production cron, ACLs and user switching remain unhomologated unless a
separate test explicitly demonstrates them. CI results belong to the exact head.

Commands for a future explicitly authorized operator are intentionally not a cron
installation: the CLI requires base/root/events/policy/receipt and exact reviewed
#61 immutable-contract source. It only runs fixed `php artisan schedule:run` after
all gates; it has no arbitrary command option and no synthetic bypass flag.

Event logs are bound to device/inode/link count before launch and checked again
through an open descriptor after drainage. PHP checks the same identity before
each append, including child Artisan invocations. The production adapter also
requires operator/root-owned parent directories not writable by the runtime
(sticky root-owned ancestors are allowed). A path replacement or symlink causes
a blocked result, not successful drainage. Process regressions cover replacement
and symlink substitution after the initial ready event.
