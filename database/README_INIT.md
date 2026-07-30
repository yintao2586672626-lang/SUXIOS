# Database Initialization

## Fresh environment (canonical)

Run from the `HOTEL` project root:

```bash
php scripts/init_database.php
```

The command creates the configured database when needed, refuses a non-empty
database, imports the frozen `database/init_full.sql` baseline, applies every
pending file under `database/migrations/`, and records each successful file in
`schema_versions` with its migration name, version, SHA-256 checksum,
`execution_kind`, and execution time. The four frozen non-migration SQL sources
are checksummed separately in `schema_baseline_sources`.

`database/init_full.sql` is frozen at the 2026-07-19 baseline. Do not append
new migrations to it. A schema change is delivered only as a new, uniquely
named `database/migrations/YYYYMMDD_description.sql` file.

## Existing environment upgrade

Check without writing:

```bash
php think db:check
```

For a database that already has `schema_versions`:

```bash
php think db:migrate
```

For a legacy database initialized before version governance, verify that it
matches the frozen baseline, then adopt that baseline and apply newer files:

```bash
php think db:migrate --baseline
```

The migration runner registers a migration only after all statements in that
file succeed. A failed attempt is recorded in `schema_migration_failures`; fix
the reported cause and rerun `php think db:migrate`, which resolves that record
after the migration succeeds. Startup stops with an upgrade instruction while
any migration is pending, evidence is missing/drifted, or a failure is still
unresolved.

Business requests and legacy command aliases never create or alter application
tables. A missing table/column is an upgrade error and must be repaired through
a new registered migration.

## Frozen baseline contents

The baseline imports:

- `database/hotel_admin_mysql.sql`
- `database/login_logs.sql`
- `database/complaint_tables.sql`
- `database/update_system_config.sql`
- migrations that existed when the baseline was frozen

New migrations are intentionally absent from `init_full.sql` and are discovered
by the version runner.

## Build a single SQL file

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build_hotelx_full_dump.ps1
mysql -u root hotelx < output/hotelx_dump_full.sql
```

The build script concatenates the frozen sources plus every migration currently
present in the migration directory, bootstraps the ledger, and registers each
migration with its SHA-256 checksum and execution kind immediately after that
SQL file. It also registers the checksum of each frozen baseline source. Import
the generated dump only into an empty database.
