# Cloud Independent Trial Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Activate the uploaded SUXIOS release as a publicly reachable cloud trial whose data, credentials, users, jobs, and backups are fully independent from the local system.

**Architecture:** Nginx terminates HTTPS and serves the release selected by `/var/www/suxios/current`; PHP-FPM runs ThinkPHP and connects only to a localhost MariaDB database named `hotelx_cloud`. Runtime configuration lives outside Git, daily database dumps stay root-only, and OTA/AI collection remains disabled until separately configured.

**Tech Stack:** Ubuntu 24.04, Nginx 1.24, PHP-FPM 8.3, ThinkPHP 8, MariaDB 10.11, OpenSSL, cron, Bash.

---

### Task 1: Create the clean cloud-only database

**Files:**
- Read: `/var/www/suxios/releases/suxios-test-20260713-020614-b9e1c2b7269e-dirty/database/init_full.sql`
- Create: `/home/ubuntu/suxios-initial-admin.txt`

- [ ] **Step 1: Verify the target database and account do not exist**

Run:

```bash
sudo mariadb --batch --skip-column-names -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='hotelx_cloud'; SELECT User FROM mysql.user WHERE User='suxios_app' AND Host='localhost';"
```

Expected: no rows.

- [ ] **Step 2: Generate server-side database and administrator secrets**

Run secrets through `openssl rand`; keep the database password in memory for Task 2 and write only the initial administrator credential to `/home/ubuntu/suxios-initial-admin.txt` with mode `0600` and owner `ubuntu`.

- [ ] **Step 3: Create the database and localhost-only application account**

Create `hotelx_cloud` with `utf8mb4_unicode_ci`, create `suxios_app@localhost`, and grant only `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on `hotelx_cloud.*`.

- [ ] **Step 4: Import the repository initialization SQL as MariaDB root**

Run from the release root so every `SOURCE ./database/...` statement resolves correctly:

```bash
sudo mariadb hotelx_cloud < database/init_full.sql
```

Expected: exit code 0 and at least 80 tables.

- [ ] **Step 5: Remove only bundled business/demo rows and create one cloud administrator**

Delete rows from `hotels`, `monthly_tasks`, `online_daily_data`, `operation_logs`, `ota_profile_bindings`, `user_hotel_permissions`, `field_mappings`, and `users` with foreign-key checks disabled for this new database only. Preserve roles, report field configuration, administrative divisions, device categories, system defaults, and knowledge tables. Insert one enabled `admin` user using a PHP `password_hash()` result.

- [ ] **Step 6: Verify the clean data boundary**

Run row-count queries and require: one user, zero hotels, zero OTA/profile rows, zero business report rows, and non-zero roles/report configuration/knowledge rows.

### Task 2: Create durable cloud configuration outside the release

**Files:**
- Create: `/etc/suxios/suxios.env`
- Create: `/var/www/suxios/releases/suxios-test-20260713-020614-b9e1c2b7269e-dirty/.env` as a symlink

- [ ] **Step 1: Generate independent application secrets**

Generate `AI_CONFIG_SECRET`, `OTA_CREDENTIAL_KEY_B64`, `OTA_CREDENTIAL_KEY_ID`, and `CRON_TOKEN` on the server. Do not import local values or any credential previously pasted into chat.

- [ ] **Step 2: Write the production environment file**

Set `APP_ENV=production`, disable debug/trace, use Asia/Shanghai, point MySQL to `127.0.0.1:3306/hotelx_cloud`, enable OTA TLS verification, and leave provider API keys empty. Set the public application URL from the server's current public IPv4 address.

- [ ] **Step 3: Restrict permissions and link the release**

Set `/etc/suxios` to `root:www-data` mode `0750`, the environment file to mode `0640`, and symlink the release `.env` to it. Verify `www-data` can read the file while other users cannot.

- [ ] **Step 4: Verify application database connectivity**

Run `php think list --raw` as `www-data`, then insert and read back a `cloud_trial_deployment_marker` row in `system_config` through the `suxios_app` account.

### Task 3: Configure HTTPS and activate the release

**Files:**
- Create: `/etc/ssl/suxios/suxios.key`
- Create: `/etc/ssl/suxios/suxios.crt`
- Create: `/etc/nginx/sites-available/suxios`
- Create: `/etc/nginx/sites-enabled/suxios` as a symlink
- Create: `/var/www/suxios/current` as a symlink
- Preserve: `/etc/nginx/sites-available/default` for rollback; disable only its `sites-enabled/default` symlink

- [ ] **Step 1: Generate a self-signed certificate with the public IPv4 SAN**

Read the current public IPv4 from Tencent Cloud instance metadata at `http://metadata.tencentyun.com/latest/meta-data/public-ipv4`, then use RSA-2048, SHA-256, a 365-day lifetime, and that address as the certificate IP SAN. Keep the private key root-only.

- [ ] **Step 2: Write the Nginx virtual host**

Port 80 redirects to HTTPS. Port 443 serves only `current/public`, uses PHP 8.3-FPM, rewrites missing files to `index.php/<original-path>` so ThinkPHP receives `PATH_INFO`, limits uploads to 50 MB, denies dotfiles and SQL/backup artifacts, and sends basic browser security headers.

- [ ] **Step 3: Activate atomically after configuration validation**

Create the `current` symlink, validate with `sudo nginx -t`, remove only the default site's enabled symlink while preserving its `sites-available` file, enable the SUXIOS site, and reload Nginx. Rollback recreates the original default symlink.

- [ ] **Step 4: Verify locally before public access**

Poll after reload until the new worker serves the API route, then run:

```bash
curl -kfsS https://127.0.0.1/api/health
curl -kfsS https://127.0.0.1/
```

Expected: health JSON contains `"status":"ok"`; the homepage contains the SUXIOS HTML title/content.

### Task 4: Configure and prove daily database backups

**Files:**
- Create: `/usr/local/sbin/suxios-db-backup`
- Create: `/etc/cron.d/suxios-db-backup`
- Create: `/etc/logrotate.d/suxios-db-backup`
- Create runtime output under: `/var/backups/suxios/mysql/`

- [ ] **Step 1: Install a root-only backup script**

The script must use `flock`, `mariadb-dump --single-transaction --quick --routines --triggers --events`, gzip compression, `gzip -t`, SHA-256 generation, and deletion limited to matching backup files older than seven days.

- [ ] **Step 2: Schedule daily execution**

Install a root cron entry for `03:25` Asia/Shanghai and rotate `/var/log/suxios-db-backup.log` weekly with four retained rotations.

- [ ] **Step 3: Run the backup manually and verify it**

Require one non-empty `.sql.gz`, a matching `.sha256`, successful `gzip -t`, and successful `sha256sum -c`.

### Task 5: Verify access, login, isolation, and rollback readiness

**Files:**
- Read: `/home/ubuntu/suxios-initial-admin.txt`
- Read: `/var/www/suxios/current/RELEASE_INFO.json`

- [ ] **Step 1: Test administrator login without printing the password or token**

Read the credential file inside a root-only remote shell and POST form fields `username` and `password` to `/api/auth/login`. Parse the response and require success, username `admin`, and a non-empty token; print only pass/fail metadata.

- [ ] **Step 2: Test public endpoints from outside the server**

Run `curl -k` from the local workstation against the public HTTPS homepage and `/api/health`. If TCP 443 is blocked by the Tencent Cloud firewall, stop and request only a TCP 443 allow rule; do not fall back to credential use over plaintext HTTP.

- [ ] **Step 3: Verify no unintended integration is active**

Require empty OTA credential/profile tables, no OTA/AI provider key in the environment file, and no OTA collection cron entry. Confirm MariaDB still listens only on `127.0.0.1`.

- [ ] **Step 4: Record rollback evidence**

Record the resolved `current` release path, the disabled default-site path, Nginx configuration status, service status, database table count, backup checksum status, and public health result. Stop without migrating local data or enabling OTA/AI jobs.
