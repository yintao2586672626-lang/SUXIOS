---
name: suxi-ota-ops
description: Handle宿析OS OTA运营、携程/美团数据抓取、浏览器采集、Cookie、订单、房价、库存、竞品和渠道诊断任务。Use when the request includes OTA、携程、美团、ebooking、browser capture、Profile、Cookie、订单抓取、在线数据、渠道、房价、库存、竞品、排名、转化率、曝光、点评、广告、OnlineData、cron_fetch、auto_fetch_online_data。
---

# Suxi OTA Ops

## Rules

1. Inspect current OTA data flow before editing: controller, route, scripts, storage, and scheduled jobs.
2. Treat cookies and credentials as sensitive; never print secrets or persist new secrets casually.
3. For scraping changes, identify the upstream page/API change before patching selectors or payloads.
4. Preserve existing channel field names, historical imported data, and `online_daily_data` compatibility.
5. Prefer small fixes around the failing channel path.
6. Do not add `reviews`, `orders`, or `traffic_data` tables for OTA capture unless a concrete product feature requires structured detail queries.
7. When recording endpoints, keep external platform endpoints, local system ports, and browser debugging ports separate. Do not hard-code `443`; record the observed protocol, host, explicit port if present, and whether any port is inferred from the protocol.
8. Manual capture means the user already provides required fetch context such as Cookie, token, hotel/store id, request payload, date range, or channel tab. Do not require a backend login step in manual mode; the system validates the supplied fields, then performs the business data fetch and parsing.

## Ctrip Browser Capture

- For Ctrip browser/Profile capture work, read `references/ctrip-browser-capture.md` before planning or editing.
- Use authorized hotel accounts only. Do not bypass platform permissions or collect data outside the current hotel's visible account scope.
- Keep one browser Profile per store, such as `storage/ctrip_profile_{store_id}`. Never commit Profile directories, cookies, tokens, screenshots with credentials, or captured secrets.
- Prefer real browser login reuse, page navigation, XHR/fetch JSON response listening, and targeted business URL matching over hand-written backend requests.
- Manual Ctrip flow accepts user-provided fetch context only: Cookie, `spidertoken` when needed, `node_id` / platform hotel id, payload JSON, date range, and channel tab. Business data should then be fetched by the existing system endpoint.
- JSON responses are the primary data source. DOM parsing is only a fallback for ranks or visible summary metrics that are not present in matched responses.
- Field mapping must use existing project fields first:
  - Overview: `amount`, `quantity`, `book_order_num`, `comment_score`, `raw_data`.
  - Reviews: `data_type=review`, score in `comment_score`/`data_value`, details in `raw_data`.
  - Traffic: `data_type=traffic`, use `list_exposure`, `detail_exposure`, `flow_rate`, `order_filling_num`, `order_submit_num`, details in `raw_data`.
  - Orders: `data_type=order`, amount in `amount`, room nights in `quantity`, order count in `book_order_num`, details in `raw_data`.
  - Ads: `data_type=advertising`, use existing traffic fields where possible, put spend/ROI/campaign details in `raw_data`.

## Meituan Browser Capture

- For Meituan eBooking browser/Profile capture work, read `references/meituan-browser-capture.md` before planning or editing.
- Use the page-triggered flow first: `POST /api/online-data/capture-meituan-browser` starts `scripts/meituan_browser_capture.mjs`, reuses `storage/meituan_profile_{store_id}`, waits for manual login when needed, captures, then saves rows.
- Manual Meituan flow accepts user-provided fetch context only: Cookie/session, `partner_id`, `poi_id` / `store_id`, request payload, date range, and `mtgsig` or other dynamic fields only when the compatibility endpoint requires them. Business data should then be fetched by the existing system endpoint.
- Keep the manual command and JSON import path only as a fallback for troubleshooting or offline replay.
- Capture order: comments page, traffic iframe, new traffic SPA, optional ads URL, order/check-in page.
- Response match keywords:
  - Reviews: `queryGeneralCommentInfo`, `commentsInfo`, `comments/statistics`.
  - Traffic: `businessData`, `traffic`, `peerTrends`.
  - Ads: `cureShops`.
  - Orders: `/orders/list`, `/order/unhandled/count`.
- Field mapping must use existing project fields first:
  - Reviews: `data_type=review`, score in `comment_score`/`data_value`, details in `raw_data`.
  - Traffic: `data_type=traffic`, use `list_exposure`, `detail_exposure`, `flow_rate`, ranking and keyword data in `raw_data`.
  - Orders: `data_type=order`, amount in `amount`, room nights in `quantity`, order count in `book_order_num`, average price in `data_value`, details in `raw_data`.
  - Ads: `data_type=advertising`, use existing traffic fields where possible, put campaign/keyword/ROI details in `raw_data`.

## Verification

- Confirm affected API endpoint or script.
- Check normal fetch, expired credential behavior, and empty-data handling.
- Confirm saved rows remain readable by existing OTA list, diagnosis, revenue, and operation analysis paths.
