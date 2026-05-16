# Database Initialization

## Full import

Run from the `HOTEL` project root:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS hotelx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root hotelx < database/init_full.sql
```

`database/init_full.sql` imports:

- `hotelx_dump.sql`
- `database/login_logs.sql`
- `database/complaint_tables.sql`
- `database/update_system_config.sql`
- all SQL files under `database/migrations/`

This covers Agent, competitor, opening, operation, expansion records, transfer records, strategy simulation, quant simulation, OTA traffic, and AI model config tables.

## Build a single SQL file

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build_hotelx_full_dump.ps1
mysql -u root hotelx < output/hotelx_dump_full.sql
```

The build script concatenates the same source files in a deterministic order.
