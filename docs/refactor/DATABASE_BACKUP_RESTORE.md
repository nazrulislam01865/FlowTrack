# Database backup and restore runbook

## Scope

Use this runbook before any structural migration that changes schema, constraints, destructive data, or migration semantics. Phase 0 documents the procedure; it does not modify production data.

## MySQL production backup

Use credentials supplied through the server environment or a protected MySQL option file. Do not put passwords in shell history or commit them.

```bash
BACKUP_DIR=/var/backups/flowtrack
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p "$BACKUP_DIR"

mysqldump \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --default-character-set=utf8mb4 \
  "$DB_DATABASE" | gzip -9 > "$BACKUP_DIR/flowtrack-$STAMP.sql.gz"

gzip -t "$BACKUP_DIR/flowtrack-$STAMP.sql.gz"
ls -lh "$BACKUP_DIR/flowtrack-$STAMP.sql.gz"
```

If the database is large, use the provider's snapshot/managed-backup facility in addition to the logical dump.

## Restore verification

Never prove a backup by checking only that a file exists. Restore into a non-production verification database first:

```bash
gunzip -c /var/backups/flowtrack/flowtrack-YYYYMMDD-HHMMSS.sql.gz \
  | mysql flowtrack_restore_check
```

Then run at minimum:

```bash
php artisan migrate:status
php artisan test
```

For a release restore drill, also validate login, Dashboard, Orders, Inquiries, My Work, Documents and an authorized attachment download against the restored database.

## SQLite local snapshot

For a local development database only:

```bash
cp database/database.sqlite "database/database.sqlite.$(date +%Y%m%d-%H%M%S).bak"
```

Do not treat the archive's SQLite file as a production backup.

## Migration release checklist

- backup timestamp recorded;
- checksum/integrity check passed;
- restore destination and command verified;
- current application release artifact retained;
- migration down/forward-fix strategy documented;
- maintenance window/traffic strategy chosen if the migration can lock large tables;
- post-migration row counts and critical workflow smoke tests defined.
