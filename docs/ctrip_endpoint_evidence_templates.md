# 携程 P3 接口证据采样模板

- 生成时间：2026-05-30T21:49:36.975Z
- 候选方向数：5
- 必要证据：Request URL / Payload / Preview / Response / page/tab context / hotel/date parameters

## 安全边界

- 不要保存 Cookie、Authorization、Token、密码、签名、住客姓名、手机号、证件号或完整订单号明文。
- Request URL 可保留路径和非敏感查询参数；动态 trace、sid、token 类参数可保留为脱敏占位。
- Payload / Response 用于字段映射前必须先经过 validate:ctrip-endpoint-evidence 脱敏校验。

## 候选方向

| 候选方向 | 数据类型 | 状态 | 过滤关键词 |
|---|---|---|---|
| 订单明细 (orders_detail) | order | missing_evidence | orderdetail, orderdetails, orderdetailsearch, orderlist, ordersearch, searchorder, queryorder, orderquery |
| 价格房态 (price_inventory) | business | missing_evidence | ratecalendar, ratecalendarprice, pricequery, pricecalendar, roomstatus, inventory, stock, available |
| 促销活动 (promotion) | advertising | missing_evidence | promotion, campaign, coupon, benefit, discount, activity, marketing |
| 结算财务 (settlement_finance) | finance | missing_evidence | settlement, settle, bill, billing, invoice, finance, payment, accountstatement |
| 合同 / MICE / RFP (contract_mice_rfp) | contract | missing_evidence | contract, contractpre, termssearch, termsearch, mice, rfp, meeting, venue |

## 订单明细

- 候选ID：orders_detail
- 优先级：P3
- 数据类型：order
- 当前状态：missing_evidence
- 可进入正式字段目录：否

### 采样步骤

- 打开对应携程或携程商旅后台页面，并确认当前门店是授权门店。
- 在 DevTools Network 中过滤这些关键词：orderdetail, orderdetails, orderdetailsearch, orderlist, ordersearch, searchorder, queryorder, orderquery。
- 触发一次真实查询：切换日期、页签、搜索或滚动加载。
- 复制 Request URL、method、脱敏 headers、Payload、Preview/Response、页面上下文和酒店/日期参数。
- 运行 validate:ctrip-endpoint-evidence，状态为 complete_redacted 后再进入字段映射审核。

### 验收条件

- request_url 命中候选方向关键词。
- payload 或 params 中能确认酒店和日期范围。
- response 中包含可解释的业务字段，而不是纯日志、菜单、资源弹窗或埋点。
- 证据包无 Cookie、Authorization、Token、住客隐私和完整订单号明文。

### JSON 模板

```json
{
  "request_url": "",
  "method": "POST",
  "headers": {
    "content-type": "application/json",
    "cookie": "<redacted: do not paste real Cookie into repo>",
    "authorization": "<redacted if present>"
  },
  "payload": {
    "hotelId": "<hotel or node id>",
    "startDate": "YYYY-MM-DD",
    "endDate": "YYYY-MM-DD"
  },
  "response": {
    "<copy DevTools Preview or Response JSON here after desensitization>": ""
  },
  "page_context": {
    "page": "",
    "tab": "",
    "url": "",
    "operation": "open page, switch tab/date, then capture the XHR/fetch request"
  },
  "params": {
    "hotel_id": "<system hotel id or OTA hotel id>",
    "data_date": "YYYY-MM-DD",
    "start_date": "YYYY-MM-DD",
    "end_date": "YYYY-MM-DD"
  }
}
```

## 价格房态

- 候选ID：price_inventory
- 优先级：P3
- 数据类型：business
- 当前状态：missing_evidence
- 可进入正式字段目录：否

### 采样步骤

- 打开对应携程或携程商旅后台页面，并确认当前门店是授权门店。
- 在 DevTools Network 中过滤这些关键词：ratecalendar, ratecalendarprice, pricequery, pricecalendar, roomstatus, inventory, stock, available。
- 触发一次真实查询：切换日期、页签、搜索或滚动加载。
- 复制 Request URL、method、脱敏 headers、Payload、Preview/Response、页面上下文和酒店/日期参数。
- 运行 validate:ctrip-endpoint-evidence，状态为 complete_redacted 后再进入字段映射审核。

### 验收条件

- request_url 命中候选方向关键词。
- payload 或 params 中能确认酒店和日期范围。
- response 中包含可解释的业务字段，而不是纯日志、菜单、资源弹窗或埋点。
- 证据包无 Cookie、Authorization、Token、住客隐私和完整订单号明文。

### JSON 模板

```json
{
  "request_url": "",
  "method": "POST",
  "headers": {
    "content-type": "application/json",
    "cookie": "<redacted: do not paste real Cookie into repo>",
    "authorization": "<redacted if present>"
  },
  "payload": {
    "hotelId": "<hotel or node id>",
    "startDate": "YYYY-MM-DD",
    "endDate": "YYYY-MM-DD"
  },
  "response": {
    "<copy DevTools Preview or Response JSON here after desensitization>": ""
  },
  "page_context": {
    "page": "",
    "tab": "",
    "url": "",
    "operation": "open page, switch tab/date, then capture the XHR/fetch request"
  },
  "params": {
    "hotel_id": "<system hotel id or OTA hotel id>",
    "data_date": "YYYY-MM-DD",
    "start_date": "YYYY-MM-DD",
    "end_date": "YYYY-MM-DD"
  }
}
```

## 促销活动

- 候选ID：promotion
- 优先级：P3
- 数据类型：advertising
- 当前状态：missing_evidence
- 可进入正式字段目录：否

### 采样步骤

- 打开对应携程或携程商旅后台页面，并确认当前门店是授权门店。
- 在 DevTools Network 中过滤这些关键词：promotion, campaign, coupon, benefit, discount, activity, marketing。
- 触发一次真实查询：切换日期、页签、搜索或滚动加载。
- 复制 Request URL、method、脱敏 headers、Payload、Preview/Response、页面上下文和酒店/日期参数。
- 运行 validate:ctrip-endpoint-evidence，状态为 complete_redacted 后再进入字段映射审核。

### 验收条件

- request_url 命中候选方向关键词。
- payload 或 params 中能确认酒店和日期范围。
- response 中包含可解释的业务字段，而不是纯日志、菜单、资源弹窗或埋点。
- 证据包无 Cookie、Authorization、Token、住客隐私和完整订单号明文。

### JSON 模板

```json
{
  "request_url": "",
  "method": "POST",
  "headers": {
    "content-type": "application/json",
    "cookie": "<redacted: do not paste real Cookie into repo>",
    "authorization": "<redacted if present>"
  },
  "payload": {
    "hotelId": "<hotel or node id>",
    "startDate": "YYYY-MM-DD",
    "endDate": "YYYY-MM-DD"
  },
  "response": {
    "<copy DevTools Preview or Response JSON here after desensitization>": ""
  },
  "page_context": {
    "page": "",
    "tab": "",
    "url": "",
    "operation": "open page, switch tab/date, then capture the XHR/fetch request"
  },
  "params": {
    "hotel_id": "<system hotel id or OTA hotel id>",
    "data_date": "YYYY-MM-DD",
    "start_date": "YYYY-MM-DD",
    "end_date": "YYYY-MM-DD"
  }
}
```

## 结算财务

- 候选ID：settlement_finance
- 优先级：P3
- 数据类型：finance
- 当前状态：missing_evidence
- 可进入正式字段目录：否

### 采样步骤

- 打开对应携程或携程商旅后台页面，并确认当前门店是授权门店。
- 在 DevTools Network 中过滤这些关键词：settlement, settle, bill, billing, invoice, finance, payment, accountstatement。
- 触发一次真实查询：切换日期、页签、搜索或滚动加载。
- 复制 Request URL、method、脱敏 headers、Payload、Preview/Response、页面上下文和酒店/日期参数。
- 运行 validate:ctrip-endpoint-evidence，状态为 complete_redacted 后再进入字段映射审核。

### 验收条件

- request_url 命中候选方向关键词。
- payload 或 params 中能确认酒店和日期范围。
- response 中包含可解释的业务字段，而不是纯日志、菜单、资源弹窗或埋点。
- 证据包无 Cookie、Authorization、Token、住客隐私和完整订单号明文。

### JSON 模板

```json
{
  "request_url": "",
  "method": "POST",
  "headers": {
    "content-type": "application/json",
    "cookie": "<redacted: do not paste real Cookie into repo>",
    "authorization": "<redacted if present>"
  },
  "payload": {
    "hotelId": "<hotel or node id>",
    "startDate": "YYYY-MM-DD",
    "endDate": "YYYY-MM-DD"
  },
  "response": {
    "<copy DevTools Preview or Response JSON here after desensitization>": ""
  },
  "page_context": {
    "page": "",
    "tab": "",
    "url": "",
    "operation": "open page, switch tab/date, then capture the XHR/fetch request"
  },
  "params": {
    "hotel_id": "<system hotel id or OTA hotel id>",
    "data_date": "YYYY-MM-DD",
    "start_date": "YYYY-MM-DD",
    "end_date": "YYYY-MM-DD"
  }
}
```

## 合同 / MICE / RFP

- 候选ID：contract_mice_rfp
- 优先级：P3
- 数据类型：contract
- 当前状态：missing_evidence
- 可进入正式字段目录：否

### 采样步骤

- 打开对应携程或携程商旅后台页面，并确认当前门店是授权门店。
- 在 DevTools Network 中过滤这些关键词：contract, contractpre, termssearch, termsearch, mice, rfp, meeting, venue。
- 触发一次真实查询：切换日期、页签、搜索或滚动加载。
- 复制 Request URL、method、脱敏 headers、Payload、Preview/Response、页面上下文和酒店/日期参数。
- 运行 validate:ctrip-endpoint-evidence，状态为 complete_redacted 后再进入字段映射审核。

### 验收条件

- request_url 命中候选方向关键词。
- payload 或 params 中能确认酒店和日期范围。
- response 中包含可解释的业务字段，而不是纯日志、菜单、资源弹窗或埋点。
- 证据包无 Cookie、Authorization、Token、住客隐私和完整订单号明文。

### JSON 模板

```json
{
  "request_url": "",
  "method": "POST",
  "headers": {
    "content-type": "application/json",
    "cookie": "<redacted: do not paste real Cookie into repo>",
    "authorization": "<redacted if present>"
  },
  "payload": {
    "hotelId": "<hotel or node id>",
    "startDate": "YYYY-MM-DD",
    "endDate": "YYYY-MM-DD"
  },
  "response": {
    "<copy DevTools Preview or Response JSON here after desensitization>": ""
  },
  "page_context": {
    "page": "",
    "tab": "",
    "url": "",
    "operation": "open page, switch tab/date, then capture the XHR/fetch request"
  },
  "params": {
    "hotel_id": "<system hotel id or OTA hotel id>",
    "data_date": "YYYY-MM-DD",
    "start_date": "YYYY-MM-DD",
    "end_date": "YYYY-MM-DD"
  }
}
```

## 使用命令

```powershell
npm run validate:ctrip-endpoint-evidence -- --input=<endpoint_evidence.json>
npm run promote:ctrip-mapping-draft -- --input=<endpoint_evidence.json> --output=<approved_mapping.candidate.json>
```

> 模板只用于采样和校验，不会把 P3 候选接口自动升级为正式字段。
