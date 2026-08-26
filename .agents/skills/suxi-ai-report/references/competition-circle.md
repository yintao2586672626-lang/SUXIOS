# 携程/美团竞争商圈报告

## 目标

将外部竞争商圈报告方法整合进宿析OS既有链路：

`昨日OTA数据 -> 异常判断 -> 竞品对比 -> AI建议 -> 今日运营动作`

只形成对应 OTA 渠道结论。不得把携程、美团商圈数据外推为全酒店经营
事实，也不得把两个平台的分母混算。

## 生产入口

宿析OS在线生成 AI 日报时，只调用 ThinkPHP 服务：

`app/service/OtaCompetitionAnalysisBundleService.php`

该服务复用已落库的携程/美团数据，随日报保存同一份 bundle。Web 请求
不得启动 Python 子进程。

## 离线计算基准

以下脚本仅用于结构化导入预检、离线计算对照和回归验收：

```powershell
python scripts/build_ota_competition_bundle.py `
  --source <美团数据目录|携程CSV|统一JSON> `
  --context <suxi_context.json> `
  --output <目录>/analysis_bundle.json
```

离线输入兼容：

- 美团商业套件目录：`project_config.json`、`market_summary.json`、
  `hotels.csv`；
- 携程商业套件结构化 CSV；
- 宿析统一 JSON：顶层包含 `project_config`、`market_summary`、`hotels`
  和可选 `context`。

`suxi_context.json` 至少包含：

```json
{
  "platform": "meituan",
  "system_hotel_id": "80",
  "platform_hotel_id": "平台酒店ID，始终按字符串处理",
  "binding_status": "verified",
  "data_date": "2026-07-23",
  "collected_at": "2026-07-24T08:00:00+08:00",
  "source_method": "authorized_export",
  "source_trace_id": "可回查的来源记录ID",
  "verification_status": "available",
  "dataset_kind": "live",
  "market_summary": {}
}
```

携程 CSV 没有独立的商圈汇总文件时，可将来源明确给出的汇总放进
`context.market_summary`。禁止把逐店明细求和伪装成来源汇总。

## 决策门槛

只有同时满足下列条件，`quality.decision_eligible` 才能为 `true`：

1. 本店唯一命中，无重复酒店键；
2. 宿析酒店ID与平台酒店ID绑定已验证；
3. 数据日期、采集时间、来源方法、来源记录可回查；
4. 来源状态为 `available`；
5. 来源汇总存在，逐店明细与汇总闭合；
6. 平台关键字段和分母存在。

`synthetic`、`unverified`、`partial`、`stale`、`binding_missing`、
`permission_denied`、`collection_failed` 必须原样暴露。此时可以计算有
分子分母支撑的派生指标，但角色、价格动作和运营建议必须标记
`withheld`。

## 平台口径

### 美团

- 销售口径与入住口径分开；
- 曝光、浏览、订单、销售间夜/收入、入住间夜/客房收入逐层保留；
- 平台转化率与 `浏览/曝光`、`订单/浏览`、`订单/曝光` 的派生率分开；
- 不引入携程 ARI/SCI 字段。

### 携程

- 保留销售额、间夜、ADR、访客、订单、平台转化率、ARI、SCI；
- ARI/SCI 只作为平台字段解读，不反推平台私有公式；
- 平台转化率与 `订单/访客` 派生预订转化率分开；
- 酒店ID按字符串保存，禁止丢失前导零；
- 裸值 `1` 的百分比含义不明确，要求改写为 `0.01`、`1%` 或 `100%`。

## 证据完整度与分析深度

`quality.decision_eligible` 表示当前已有证据可支持一个有界的人工研判，
不等于完整商圈证据已经齐全。在线 bundle 必须另行保存
`evidence_contracts.<platform>`，界面和 HTML 导出都直接显示：

- `analysis_scope`：当前只支持排名摘要、有界快照，还是完整商圈单期快照；
- `full_circle_ready`：来源门槛和平台必需字段是否同时满足；
- `required_checks_available / required_checks_total`：完整度计数；
- `checks`：每项的来源事实/派生指标、值、单位、定义和来源引用；
- `missing_required_labels` 与 `caveats`：待补字段和禁止越界的解释。

携程完整商圈快照至少核对：本店唯一绑定、目标日期、销售额/间夜/ADR、
ARI/SCI、访客/订单、平台转化率、订单/访客派生预订转化率。点评分是可选
辅助字段。平台转化率保留来源原值；派生预订转化率只按
`订单量 / APP详情页访客量 × 100%` 计算，分母缺失时必须为 `null`。

美团完整商圈快照至少核对：本店 POI、目标日期、排名摘要，以及入住榜、
销售榜、流量榜、转化榜四组全量明细。现有生产来源只有
`meituan_rank_summary` 时，必须显示 `rank_summary_only` 和“四榜证据未齐”，
但不得因此篡改原有排名摘要的可信状态。

销售榜与入住榜属于不同时间窗口，两者差额不是取消率；没有真实取消字段
时不得推断取消。证据合同只验证目标日快照，未单独验证可比历史序列时，
不得输出环比、同比、趋势或已验证价格弹性。

## 竞品与报告路由

竞品分为直接竞品、进攻标杆、流量标杆、转化标杆，名单不混用。
默认输出简版；明确要求旗舰、完整版、深度版或 HTML 时输出旗舰版；
要求双版时，两个渲染器都只读取同一份 `analysis_bundle.json`。

携程历史兼容文件名可以使用 `analysis_context.json`，但内容必须来自同
一 bundle，不得再次计算。

## 输出使用

报告严格按以下层次读取：

1. `source` 与 `facts`：来源事实；
2. `quality`：绑定、日期、闭合和缺口；
3. `derived_metrics`：有明确公式的派生指标；
4. `analysis`：达到门槛后才可使用的渠道角色与矛盾；
5. `recommendations`、`price_experiment`：仅为建议，真实OTA改价、投放
   和生产写入仍需显式授权。

先生成 bundle，再制作 Word/HTML/驾驶舱呈现。不得让漂亮报告掩盖
`partial` 或 `blocked`。
