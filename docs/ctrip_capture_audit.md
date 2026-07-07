# 携程采集结果审计

- 生成时间：2026-07-07T19:09:01.756Z
- 输入文件数：1
- 已归档接口响应数：10
- 已抽取字段事实数：96
- 可入库标准行数：14
- 正式接口覆盖：2/2
- 字段覆盖：8/20
- 不适用模块：-
- 登录状态：ok_or_unverified
- 未归档接口候选数：1
- 页面交互触发：1/3
- 页面交互未触发/异常：2/0
- P3证据草稿数：1
- P3完整证据数：1

## 已归档模块覆盖

| 模块 | 响应数 | 字段事实数 | 标准行数 | 已命中接口 | 已命中字段 |
|---|---:|---:|---:|---|---|

## capture_gap_report

- Status: needs_evidence
- Blockers: -
- Not applicable sections: -
- Missing formal endpoints: 0
- P3 candidate sections: orders_detail

| Action | Section | Endpoint/Field | Reason |
|---|---|---|---|
| capture_missing_fields | comment_review | comment_date, comment_good_rate, comment_response_rate, comment_store_name, comment_unreply_count, review_cleanliness_score, review_environment_score, review_facility_score, review_photo_count, review_photo_rate, review_service_score, target_url | field_coverage_missing |
| collect_p3_devtools_evidence | orders_detail | - | p3_candidate_needs_evidence |
| comment_review | 10 | 96 | 14 | comment_hotel_rating, comment_review_aggregate | bad_review_count, comment_channel, comment_channel+comment_count+ctrip_comment_count, comment_channel+comment_count+elong_comment_count, comment_channel+comment_count+qunar_comment_count, comment_channel+comment_count+zx_comment_count, comment_count, comment_count+bad_review_count, comment_score, ctrip_comment_count, elong_comment_count, qunar_comment_count, zx_comment_count |

## 登录状态

- 状态：ok_or_unverified
- 登录页数量：0

## 正式接口覆盖

| 模块 | 预期接口 | 已命中 | 缺失 | 缺失接口 |
|---|---:|---:|---:|---|
| comment_review | 2 | 2 | 0 | - |

## 字段覆盖

| 模块 | 预期字段 | 已命中 | 缺失 | 缺失字段 |
|---|---:|---:|---:|---|
| comment_review | 20 | 8 | 12 | comment_date, comment_good_rate, comment_response_rate, comment_store_name, comment_unreply_count, review_cleanliness_score, review_environment_score, review_facility_score, review_photo_count, review_photo_rate, review_service_score, target_url |

## 页面交互触发覆盖

| 模块 | 页面数 | 计划动作 | 已点击 | 未点击 | 异常 | 未触发动作 |
|---|---:|---:|---:|---:|---:|---|
| comment_review | 1 | 3 | 1 | 2 | 0 | 全部, 点评列表 |

## 未归档接口候选

| 候选方向 | 数量 | 状态 | 需要补充 | 样例接口 |
|---|---:|---|---|---|
| traffic_report (经营报告-流量数据) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| orders_detail (订单明细) | 1 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | https://ebooking.ctrip.com/ebkorderv2/api/order/domestic/unprocessOrderList?_fxpcqlniredt=09031060219661935072&x-traceID=09031060219661935072-1783451256121-8750537 |
| price_inventory (价格房态) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| promotion (促销活动) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| settlement_finance (结算财务) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |
| contract_mice_rfp (合同 / MICE / RFP) | 0 | needs_payload_response | Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters | - |

## P3 证据草稿覆盖

| 候选方向 | 状态 | 完整证据 | 不完整证据 | 字段草案 | 缺失证据 |
|---|---|---:|---:|---:|---|
| traffic_report | missing_evidence | 0 | 0 | 0 | - |
| orders_detail | ready_for_review | 1 | 0 | 15 | - |
| price_inventory | missing_evidence | 0 | 0 | 0 | - |
| promotion | missing_evidence | 0 | 0 | 0 | - |
| settlement_finance | missing_evidence | 0 | 0 | 0 | - |
| contract_mice_rfp | missing_evidence | 0 | 0 | 0 | - |

## 下一步证据

- Request URL
- Payload
- Preview / Response
- page/tab context
- hotel/date parameters
