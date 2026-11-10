# Additive checkout URL repair: synthetic validation

The reviewed origin has 79 historical migration names but lacks
`payments.provider_checkout_url`. Three existing columns use TIMESTAMP rather than
the DATETIME emitted by a fresh historical migration run:
`audit_events.created_at`, `support_accesses.starts_at`, and `support_accesses.ends_at`.

The new migration adds only nullable VARCHAR(512) checkout URL storage when absent.
An existing correct definition is a no-op; incompatible definitions fail closed.
No historical migration, ledger entry, existing payment value, financial workflow,
or temporal type is rewritten. `down()` deliberately preserves the column and data.
This change is authorized in the working copy only, not as production DDL.

## Required evidence

- Compare the exact five new migration names, not only their count.
- Check fresh-schema no-op and a separate synthetic origin-shaped baseline.
- Require the origin-shaped baseline's 79 names and global 1,110-column projection
  to match the reviewed metadata digests. Synthetic migration batches are not
  fabricated to match the origin's aggregate batch digest.
- Reproduce missing-column checkout/history failure using a fake gateway and no
  external HTTP, then prove checkout/history restoration and old values retained.
- Preserve all original schema metadata across the upgrade. Allow only the four
  Law migrations' additions and the complete reviewed checkout column definition.
- Check repeat migration, UTC/-03 session behavior and unchanged timestamp epochs.
- Laboratory rollback removes five ledger records but retains the repair column
  and its values. Report that explicit delta rather than pretending to restore the
  old schema. Production recovery remains forward-fix-only.
- Run negative definition tests, the complete application suites, production
  no-dev package proof, concurrency, real browser, rsync and offline build on the
  final source identity. Local auxiliary results do not replace final CI.

SQLite's native Laravel string declaration is `varchar` without a stored length.
Accepting that representation is not evidence of a 512-character database limit.
MySQL/Percona must report VARCHAR(512), nullable, no non-null default or generation.
The three origin timestamp defaults/EXTRA were not fully captured in the original
global projection; do not claim a complete production clone from that projection.

All fixture DDL and generated payment/session rows belong only to guarded,
disposable databases. No production account, checkout, payment, secret, customer
data, backup or deployment is part of these tests.
