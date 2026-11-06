# Backup verification and isolated restore gate

No production data transfer or restore is authorized yet. Historical successful
backup logs are evidence of past checks, not current recoverability.

Before deployment, collect read-only inventory from the independently verified
host. Record current RELEASE_SHA, resolved current path, mounts and image digests.
Compare its file manifest with the LF git archive of f23cff8, recording extra,
missing and changed paths. Separately account for vendor, generated cache, .env,
storage, uploads and infrastructure configuration; never overwrite unexplained
differences. Preserve approved host-only changes in a reviewed patch or documented
configuration source before release activation.

Verify the latest recognized backup directory and all four SHA256SUMS members:
database.sql.gz, storage.tar.gz, public-uploads.tar.gz and environment.backup.
Require regular non-symlink files, checksum success, gzip integrity, SQL completion
marker, archive integrity, freshness within 24 hours at deployment, and sufficient
disk/inodes. Inventory reads existing backups only. A missing or stale backup
blocks deployment; creating one requires production mutation approval.

Obtain explicit approval naming the backup ID, transfer destination, authorized
operators, encryption, retention period and deletion procedure. The DB, uploads
and environment backup contain sensitive institutional data and credentials.
Never publish them as GitHub artifacts or commit them.

After transfer approval:

1. Transfer using verified SSH into encrypted storage with restrictive permissions.
   Compare source and destination SHA256SUMS before extraction. Keep APP_KEY and
   the environment backup separately access-controlled; confirm external escrow.
2. Use a disposable MariaDB 11.4 instance matching the recorded production image,
   bound to loopback with no production networks or credentials. Disable outbound
   email, queues, scheduled jobs, payment requests and webhooks. Do not boot the
   restored application with the production .env.
3. Inspect archive member paths/links before extracting into fresh isolated
   directories. Restore SQL only into an explicitly named disposable database;
   never point import tools at production. Record tool versions and import exit.
4. Compare source-consistent row counts for every table, migration ledger and
   financial/payroll aggregates with the backup snapshot; counts taken later on
   live production can legitimately differ. Validate foreign-key/orphan checks,
   student/academic relationships and read-only payment/payroll reports. Record
   duplicate payroll groups without reconciliation. Compare upload file counts,
   sizes and hashes and test representative document readability privately.
5. Record backup ID, checksum manifest, restore duration, counts, integrity results
   and reviewer sign-off without personal data. Test the proposed migration only
   in this isolated copy and demonstrate existing row preservation.
6. Close the isolated environment and dispose of copies according to the approved
   retention procedure. Preserve sanitized evidence, not production records.

Activation requires reviewed exact commit + green CI + explained baseline diff +
successful restore evidence + approval. Ask separately before changing the freeze.
Use the existing staging/activation workflow with production reviewer approval;
stop on any failed prerequisite. Code rollback must retain shared data and never
run migrate:rollback: this payroll migration's down() drops columns and tables.
