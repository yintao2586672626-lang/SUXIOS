---
name: suxi-ota-ops
description: Handle宿析OS OTA运营、携程/美团数据抓取、Cookie、订单、房价、库存、竞品和渠道诊断任务。Use when the request includes OTA、携程、美团、ebooking、Cookie、订单抓取、在线数据、渠道、房价、库存、竞品、排名、转化率、曝光、点评、商圈、OnlineData、cron_fetch、auto_fetch_online_data。
---

# Suxi OTA Ops

## Rules

1. Inspect current OTA data flow before editing: controller, route, scripts, storage, and scheduled jobs.
2. Treat cookies and credentials as sensitive; never print secrets or persist new secrets casually.
3. For scraping changes, identify the upstream page/API change before patching selectors or payloads.
4. Preserve existing channel field names and historical imported data.
5. Prefer small fixes around the failing channel path.

## Verification

- Confirm affected API endpoint or script.
- Check normal fetch, expired credential behavior, and empty-data handling.
