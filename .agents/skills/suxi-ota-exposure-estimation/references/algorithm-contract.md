# 曝光人数估算合同

## 1. 指标身份

本方法只支持：

```text
target_metric = exposure_users
target_unit = people
browse_metric = detail_visitors
browse_unit = people
metric_scope = 指定 OTA 渠道
```

`曝光人数`是去重用户数；`曝光量 / 曝光次数 / overall exposure` 是展示次数，单位 `impressions`。名称相似不能建立映射。兼容字段名 `list_exposure`、`detail_exposure` 也必须先取得平台、模块、source path、官方定义和单位证据。

## 2. 两种方法

### 2.1 `anchored_inverse`

用于同一天、同一累计口径的可审计重放：

```text
raw_rate = verified_detail_visitors / verified_exposure_users
rate_used = raw_rate                         # exact
rate_used = round(raw_rate, precision)       # rounded
estimated_exposure_users = round(target_detail_visitors / rate_used)
```

- 一个锚点只允许同日重放，不构成跨日模型。
- `rounded` 模式必须输出由舍入步长产生的上下界；该区间不是统计置信区间。
- 附件工作簿采用 `round(126 / 1437, 4) = 0.0877`。所以 `107 / 0.0877 -> 1,220`，`126 / 0.0877 -> 1,437`。

### 2.2 `rolling_median`

用于目标日缺少真实曝光、但已有同店同平台历史配对的跨日估算：

```text
m_i = verified_exposure_users_i / verified_detail_visitors_i
M = median(last N eligible m_i), N defaults to 14 days
estimated_exposure_users_D = round(observed_detail_visitors_D * M)
```

可选滚动参数示例：

```json
{
  "options": {
    "min_verified_pairs": 7,
    "window_days": 14,
    "drift_ratio": 0.15,
    "drift_policy_ref": "fixture:unvalidated-heuristic-15pct"
  }
}
```

`drift_ratio` 没有默认值；提供时必须同时提供脱敏的 `drift_policy_ref`。示例引用只用于回归 fixture，不能复制成酒店生产政策。

最低要求：

- 目标日前至少 7 个 `verified_actual` 配对日；
- 平台、系统酒店、来源模块、指标定义、单位和日累计时间基准一致；
- 活动日、补齐值、派生值、重复日和目标日自身不进入基线；
- 至少积累 7 个先前有效配对后，先用此前窗口生成并保留当日预测误差；只有输入显式提供 `options.drift_ratio` 与脱敏 `options.drift_policy_ref` 时，才按该阈值判断漂移并退出后续基线。当前没有经过真实历史校准的默认阈值，示例中的 15% 只是合成回归启发式，不是业务或统计事实；该判定不读取未来日；
- 不使用跨酒店默认值，不采用附件中的 `11.65` 作为兜底。

误差带来自按时间顺序的滚动重放：每一日只有在它之前已有至少 7 个有效配对时才评分，且只用该日前窗口估算该日，再取绝对百分比误差 P90。若误差定义为 `|prediction-actual|/actual=e`，数值带按 `[prediction/(1+e), prediction/(1-e)]` 反解；`e>=100%` 时不输出有限上界。它只是历史误差描述，不是未来覆盖率或置信保证。目标日真实值若后来取得，只有 `verified_actual` 加独立来源引用时才能作为 holdout 验证，且不得回写先前估算的事实身份。

## 3. 输入 JSON

公共 scope 与 target：

```json
{
  "method": "rolling_median",
  "scope": {
    "platform": "meituan",
    "system_hotel_id": "hotel-123",
    "business_date": "2026-08-29",
    "timezone": "Asia/Shanghai",
    "scope_key": "meituan|hotel-123|traffic-funnel-v1|exposure-users-detail-visitors|people|same-day-cumulative|23:00|Asia-Shanghai",
    "source_path": "meituan/traffic-funnel/daily",
    "metric_definition": "same-day cumulative deduplicated users",
    "metric_definition_version": "meituan-traffic-funnel-v1",
    "time_basis": "same_day_cumulative",
    "cumulative_cutoff": "23:00",
    "target_metric": "exposure_users",
    "target_unit": "people",
    "browse_metric": "detail_visitors",
    "browse_unit": "people"
  },
  "target": {
    "date": "2026-08-29",
    "platform": "meituan",
    "system_hotel_id": "hotel-123",
    "scope_key": "meituan|hotel-123|traffic-funnel-v1|exposure-users-detail-visitors|people|same-day-cumulative|23:00|Asia-Shanghai",
    "detail_visitors": 126,
    "detail_visitors_quality": "observed_actual",
    "source_ref": "capture-batch-20260829-2330"
  }
}
```

文件来源可在 scope 增加 64 位十六进制 `source_file_sha256`；数据库/API 来源使用稳定批次或行引用，不伪造文件哈希。`scope_key` 是行级口径绑定键，target、anchor 和每个 calibration row 必须逐字一致；脚本还会拒绝行内与 scope 冲突的来源、定义版本、指标、单位、时区、累计口径或截止时点。
`source_ref` 必须是脱敏的批次、文件、sheet/range 或行引用，不能含 Cookie、令牌、敏感请求头、Profile 路径或带凭证 URL。

可选自洽检查字段：

```json
{
  "payment_conversion_rate_pct": 18.3,
  "paid_orders": 23
}
```

两者必须成对出现，并属于同一门店、平台、日期、累计时点和漏斗定义；只提供其中一个属于 `invalid_input`。检查采用：

```text
expected_orders = detail_visitors * payment_conversion_rate_pct / 100
tolerance_orders = max(1 order, paid_orders * 5%)
```

不能把这里的支付转化率当成曝光→浏览的一转率。
浏览为 0 但支付订单大于 0 时直接判 `data_error`，不受 1 单容差保护。

`anchored_inverse` 额外输入：

```json
{
  "rate_policy": "rounded",
  "rate_precision": 4,
  "anchor": {
    "date": "2026-08-20",
    "platform": "meituan",
    "system_hotel_id": "hotel-123",
    "scope_key": "meituan|hotel-123|traffic-funnel-v1|exposure-users-detail-visitors|people|same-day-cumulative|23:00|Asia-Shanghai",
    "detail_visitors_actual": 126,
    "exposure_users_actual": 1437,
    "quality": "verified_actual",
    "source_ref": "workbook:8月20日每小时曝光!B17:C17"
  }
}
```

`rolling_median` 的每个校准行：

```json
{
  "date": "2026-08-18",
  "platform": "meituan",
  "system_hotel_id": "hotel-123",
  "scope_key": "meituan|hotel-123|traffic-funnel-v1|exposure-users-detail-visitors|people|same-day-cumulative|23:00|Asia-Shanghai",
  "detail_visitors_actual": 120,
  "exposure_users_actual": 1380,
  "quality": "verified_actual",
  "source_ref": "capture-batch-id"
}
```

普通人工排除不能使用任意理由。除 `verified_event_outlier` 外，`baseline_eligible=false` 只接受以下已核验原因，并必须同时提供脱敏 `exclusion_source_ref`：

```text
verified_source_definition_change
verified_cumulative_cutoff_mismatch
verified_capture_corruption
verified_duplicate_batch
```

排除原因和引用必须进入输出谱系；不得为了改善误差而事后挑掉表现差的有效日。

已核实活动日增加：

```json
{
  "event_status": "verified_event_outlier",
  "baseline_eligible": false
}
```

小时累计数组使用 `{ "time": "20:00", "detail_visitors": 107 }`。时间必须为严格递增且不重复的 `HH:mm`，不得晚于 `cumulative_cutoff`；累计人数必须单调不下降且不得超过 target，若包含截止时点则必须与 target 相等。脚本只给累计估算，不计算相邻小时差。

目标日后来取得真实曝光时，holdout 三个字段必须成组提供：`exposure_users_actual`、`exposure_users_quality="verified_actual"`、`exposure_users_source_ref`。否则不得称为 actual 或计算误差。

## 4. 状态

| status | 含义 | 是否给估算 |
| --- | --- | --- |
| `estimated` | 输入与方法门通过 | 是，仍为 `estimate_only` |
| `insufficient_baseline` | 配对不足或单锚点被要求跨日 | 否 |
| `reference_only` | 目标浏览为补齐/派生/未核验 | 否 |
| `data_error` | 同快照漏斗自洽检查失败 | 否 |
| `not_applicable` | 指标或单位不是曝光人数/浏览人数 | 否 |
| `invalid_input` | 日期、范围、重复日、跨店、跨平台或数值合同错误 | 否，CLI 退出码 2 |

成功估算必须携带：

```text
evidence_type = derived_estimate
quality_status = estimate_only
decision_eligible = false
writeback_allowed = false
platform_fact_status = unchanged
```

## 5. 非适用与失败守卫

- 平台、酒店、业务日期、source path、指标定义或单位缺失；
- 使用美团门店率估算携程、其他门店或全酒店经营数据；
- 把曝光量/次数当曝光人数；
- 把工作簿中由曝光倒算出的浏览值再次作为独立配对训练；
- 把当日真实曝光加入基线后再宣称当天估算误差很小；
- 使用目标日或未来日校准行；
- 将缺失、补齐或派生输入标成真实 0；
- 小时累计序列下降却未先核查平台回填/抓取；
- 把估算写入 `list_exposure`、`exposure_users` 或其他平台事实字段；
- 用固定 ±3% 作为未经验证的统计置信区间。
