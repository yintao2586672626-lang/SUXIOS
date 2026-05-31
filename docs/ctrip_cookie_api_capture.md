# Ctrip Cookie API capture docs

Target endpoint: `POST /api/online-data/fetch-ctrip-cookie-api`  
Purpose: call selected Ctrip API URLs directly with Cookie + payload and return
business-ready data (`catalog_facts`, `standard_rows`, etc.) without opening a browser.

## Request payload

Common fields:
- `system_hotel_id` (optional): hotel id in this project, needed when auto-save
- `hotel_id`: Ctrip hotel id or node id used by payload defaults
- `hotel_name`
- `profile_id`: used as profile key and runtime log id (optional)
- `data_date`: used as default `startDate/endDate`
- `request_url` + `method` + `payload_json`
- `endpoints`: array of `{ request_url, method, payload?, headers?, section? }`
- `endpoints_json`: JSON string of endpoints array
- `cookies` or `cookie`: raw Cookie header string (required when no profile can be read)
- `auto_save`: default save behavior when `system_hotel_id` exists

Each endpoint item can be provided via:
- `request_url`, `method`, `payload` (or `payload_json`)
- `headers`
- `section` (mapped to diagnosis section)

## Auto payload defaults

The backend fills missing POST payload fields by endpoint pattern:

- Sales
- `queryMarketDetails`, `queryOrderTrendV1`, `queryHotelOccupiedRoomTrendV1`, `queryRoomTensitiesV1`, `queryHotelTensitiesV1`, `queryMarketRoomTensity`, `queryRoomOccupiedTrend`
  - Adds: `hostType=HE`, `platform=EBK`, `startDate`, `endDate`
- Traffic
  - `queryScanFlowDetailsV2`, `queryFlowTransformNewV1`, `queryFlowSource`, `queryCityHotKeywords`, `querySearchFlowDetails`
  - Adds: `hostType=HE`, `platform=EBK`, `startDate`, `endDate`
- Ads
  - `queryCampaignSummaryReport`
  - Adds: `hostType=HE`, `platform=EBK`, `pageIndex=1`, `pageSize=20`, `startDate`, `endDate`
- User profile / audience
  - `queryUserSex`, `queryUserType`, `queryUserPriceInfo`, `getUserImageList`, `getOrderDistribution`, etc.
  - Adds: `hostType=HE`, `platform=EBK`, `startDate`, `endDate`, `hotelId`, `nodeId`
- IM board
  - `getImIndex`, `getImDateDistribute`, `getImSessionDistribute`, `getImOrderConversionByDay`, `getImOrderConversionDetail`
  - Adds: `hostType=HE`, `platform=EBK`, `startDate`, `endDate`, `hotelId`, `nodeId`
- Competitor
  - `getManagementData`, `getMasterHotelLabel`, `getFlowData`, `getServiceData`, `getFlowSource`, `getTripartiteOrderLoss`, `getLossOrderCompeteHotel`, `getCompetingRank`
  - Adds: `hostType=HE`, `platform=EBK`, `startDate`, `endDate`, `hotelId`, `nodeId`
- Biztravel BPI
  - `getBbkComprehensiveTable`
  - Adds: `hostType=HE`, `date`, `reportDate`
- Biztravel business/competitor report
  - `dataCenterBusinessReportDetail`, `dataCenterComparisonReportDetail`, `dataCenterComperatorReportDetail`
  - Adds: `hostType=HE`, `platform=BBK`, `startDate`, `endDate`, `hotelId`, `nodeId`

If payload already exists, existing values are not overwritten.

## Built-in presets in UI

The preset in the page includes (non-exhaustive):
- `market_calendar`:
  - `queryHotCalendarInfo`
- `homepage`:
  - `queryHomePageRealTimeData`
- `sales_report`:
  - `queryMarketDetails`, `queryOrderTrendV1`, `queryHotelOccupiedRoomTrendV1`, `queryRoomTensitiesV1`
- `traffic_report`:
  - `queryScanFlowDetailsV2`, `queryFlowTransformNewV1`, `queryFlowSource`, `queryCityHotKeywords`, `querySearchFlowDetails`
- `ads_pyramid`:
  - `queryCampaignSummaryReport`
- `quality_psi`:
  - `getHotelPsiV2`
- `biztravel_bpi`:
  - `getBbkComprehensiveTable`
- `biztravel_business_report`:
  - `dataCenterBusinessReportDetail`
- `biztravel_competitor`:
  - `dataCenterComparisonReportDetail`
- User / IM / competitor diagnostics:
  - `queryUserSex`, `getImIndex`, `getImDateDistribute`, `getManagementData`, `getTripartiteOrderLoss`, `getCompetingRank`

## Cookie from browser profile

When `cookies` is empty, backend will try to read Chrome/Chromium profile:
1. Resolve `profile_id` from payload/config/hotel ids.
2. Read Cookies DB under:
   `storage/ctrip_profile_<profile_id>`.
3. Execute `scripts/extract_chromium_cookie_header.php` to extract Ctrip cookies.
4. Return:
   - `cookie_source: browser_profile`
   - `profile_cookie_meta.cookie_count`
   - `profile_cookie_meta.skipped_count`

## Output keys

Common response shape includes:
- `auth_status`
- `responses`, `catalog_facts`, `standard_rows`
- `errors`
- `captured_counts`, `diagnosis_summary`
- `cookie_source`, `profile_cookie_meta` (if profile cookie is used)
- `row_count`, `saved_count`, `counts`

Error tags:
- `cookie_or_permission_failed`: token/permission problems
- `no_business_data`: request returned data but no catalog/standard rows
- `no_json_response` / `html_response_not_json`

## Verification

```powershell
node scripts/verify_ctrip_cookie_api_capture.mjs
node scripts/verify_ota_diagnosis_auto_fetch.mjs
```
