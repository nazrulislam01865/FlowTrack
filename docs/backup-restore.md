# FlowTrack database backup and restore runbook

## Backup

Phase 14 provides a consistent MySQL/MariaDB backup command using `mysqldump --single-transaction`. Passwords are passed through the child-process environment rather than command-line arguments.

```bash
php artisan flowtrack:database:backup
```

The command writes:

```text
<database>_YYYYmmdd_HHMMSS.sql.gz
<database>_YYYYmmdd_HHMMSS.sql.gz.sha256
```

Backups should be written to durable shared/off-node storage via `FLOWTRACK_DATABASE_BACKUP_DIRECTORY`. Automatic daily backups are enabled only when `FLOWTRACK_DATABASE_BACKUP_ENABLED=true`; retention defaults to 14 days.

Verification:

```bash
sha256sum -c /path/to/backup.sql.gz.sha256
```

Also copy backups to a second failure domain/object store according to the production retention policy.

## Restore drill

Never first-test a restore against production. Restore into a disposable database/instance with the same major MySQL/MariaDB version.

1. Verify checksum.
2. Create an empty restore database.
3. Point a non-production FlowTrack `.env` at it.
4. Run:

```bash
php artisan flowtrack:database:restore /path/to/backup.sql.gz --force
php artisan optimize:clear
php artisan migrate --force
php artisan flowtrack:infrastructure:check --prepare-storage
```

5. Sign in and verify Dashboard, Orders, Inquiries, Tasks, Clients, Documents, finance records and setup/master-data references.
6. Record backup timestamp, restore start/end time and any errors.

## Emergency production restore

1. Remove all web nodes from the load balancer or run `php artisan down` on every node.
2. Stop queue workers and scheduler so no writes race the restore.
3. Take a final emergency backup if the database is readable.
4. Verify the selected backup checksum.
5. Run the restore command with `--force`.
6. Run migrations/config clear and application smoke tests.
7. Start one canary web node and workers; verify data and health endpoints.
8. Return nodes to service incrementally.

A backup is not considered operationally valid until a restore drill has completed successfully on representative infrastructure.
