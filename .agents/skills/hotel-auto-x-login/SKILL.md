---
name: hotel-auto-x-login
description: Use when working on hotel-auto-x platform login/session/Profile handling for PMS, Meituan, or Ctrip, including manual login, session checks, expired cookies, browser profile safety, and login-related alerts.
---

# hotel-auto-x Login

Use this skill for login/session work in `/home/qing/hotel-auto-x/hotel_auto_x`.

## Core Rules

- Start read-only: inspect service status, `store_sessions`, recent `collection_logs`, and alerts before opening browsers.
- Do not delete `profiles/` or platform Profile directories. Login state is stored there.
- Do not clear cookies/localStorage except for a specific user-approved platform/store.
- Prefer single-store checks before broad actions.
- Avoid running manual login while the same platform/store collector is active.

## Project Map

- Browser/Profile manager: `core/browser.py`
- Base login check flow: `core/collector.py`
- Scheduler session check: `scheduler/engine.py::task_session_health_check`
- PMS login probe: `scheduler/engine.py::task_pms_login_probe`
- Web/API trigger layer: `web/api.py`, `web/realtime_api.py`
- Profile path: `config.settings.profiles_dir`, normally `./profiles`

## Profile Naming

- PMS group account: `profiles/pms_default`
- Meituan store account: `profiles/meituan_<store_id>`
- Ctrip store account: `profiles/ctrip_<store_id>`

## Manual Login Workflow

1. Confirm no conflicting collector is running:
   - Query `task_runs` for `status='running'`.
   - Check service logs for current collector progress.
2. Open headed browser through `BrowserManager.create_context(platform, store_id, stealth=False, headless=False)`.
3. Navigate to platform entry:
   - PMS: `https://pms.meituan.com/login`
   - Meituan: `https://me.meituan.com/ebooking/`
   - Ctrip: `https://ebooking.ctrip.com/home/mainland`
4. Let the user complete login in the browser.
5. Verify login by URL, page text, cookies, and where available a lightweight API probe.
6. Update `store_sessions` only after real evidence of active session.

## Validation

After login repair:

```bash
curl -s http://127.0.0.1:8001/api/health
```

Then run the smallest relevant health check instead of full collection. For PMS, prefer the existing PMS login probe/session check path. For OTA stores, check only the affected store/platform first.

## Failure Meaning

- `session_expired`: login is invalid or redirected to login/passport.
- `anti_bot`: page classified as platform risk-control; do not repeat aggressive retries.
- `resource_busy_login`: login window or collector lock is active.
- `cookies_incomplete`: browser is logged in enough to load a page but missing business cookies needed by API.

## Handoff Notes

When reporting login work, include platform, store_id, profile used, verification evidence, and whether any session status row was changed.
