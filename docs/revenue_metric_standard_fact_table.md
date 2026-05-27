# 收益指标标准化事实表

## 事实表边界

- 事实表名称：`fact_ota_daily`
- 事实表类型：由 `OtaStandardEtlService` 从 `online_daily_data` 标准化生成的日粒度事实表。
- 粒度：`date_key + hotel_key + platform_key + data_type + dimension`
- 物理存储：暂不新增物理表，继续复用 `online_daily_data` 与脱敏后的 `raw_data`。
- 指标范围：OTA 渠道口径，不冒充全店经营口径；只有提供 `available_room_nights` 时才计算 OCC、RevPAR、Net RevPAR。

## 字段清单

| 字段 | 口径 | 不可计算条件 |
| --- | --- | --- |
| `gross_revenue` / `revenue` | OTA 渠道成交总额，优先 `amount` | 缺收入字段 |
| `room_revenue` | 房费收入；缺结构化房费时沿用 OTA 成交额 | 缺收入字段 |
| `room_nights` | 间夜量，优先 `quantity` / `room_nights` | 缺间夜或为 0 时 ADR 不可计算 |
| `available_room_nights` | 可售间夜，来自可售房量/可售间夜字段 | 缺失或为 0 时 OCC、RevPAR、Net RevPAR 不可计算 |
| `occupied_room_nights` | 已售/已住间夜，缺结构化入住字段时用 OTA 间夜 | 缺失时 OCC 不可计算 |
| `adr` | `sum(room_revenue) / sum(room_nights)` | `room_nights` 为 0 |
| `occ` | `sum(occupied_room_nights) / sum(available_room_nights) * 100` | 缺可售间夜或已住间夜 |
| `revpar` | `sum(room_revenue) / sum(available_room_nights)` | 缺可售间夜 |
| `commission_amount` | 佣金金额；优先平台直接金额，可由 `gross_revenue * commission_rate` 派生 | 佣金金额和佣金率均缺失 |
| `commission_amount_basis` | `direct` 或 `derived_from_commission_rate` | 无可用佣金字段 |
| `commission_rate` | `sum(commission_amount) / sum(gross_revenue) * 100`；只使用有佣金字段的对齐行 | 佣金字段缺失或佣金收入分母为 0 |
| `net_revenue` | 佣金后收益；优先平台直接净收入，缺失时用 `gross_revenue - commission_amount` 派生 | 无净收入且无佣金字段 |
| `net_revenue_basis` | `direct` 或 `derived_from_commission_amount` | 无可用净收入口径 |
| `net_revpar` | `sum(net_revenue) / sum(available_room_nights)` | 缺净收入或可售间夜 |
| `channel_contribution_rate` | `channel_revenue / total_revenue * 100` | 总收入为 0 |
| `net_channel_contribution_rate` | `channel_net_revenue / total_net_revenue * 100` | 总净收入为 0 或渠道净收入缺失 |
| `lead_time_days` | `checkin_date - booking_date` | 缺预订日或入住日 |
| `cancellation_rate` | `cancel_order_num / order_count * 100`；平台直接提供 `cancel_rate` 时可使用 | 缺取消字段或订单分母为 0 |
| `room_night_cancellation_rate` | `cancel_room_nights / room_nights * 100` | 缺取消间夜或间夜为 0 |
| `price_gap` | `our_price - competitor_price` | 缺本店价或竞对价 |
| `price_gap_rate` | `price_gap / competitor_price * 100` | 缺竞对价或竞对价为 0 |

## 接口输出

- `/api/ota-standard/etl`：返回 `fact_ota_daily` 明细事实行。
- `/api/ota-standard/revenue-metrics`：返回 `totals`、`channel_contribution`、`by_platform`、`by_hotel`、`metric_definitions`、`metric_trust` 和 `data_gaps`。
- 分母缺失时返回 `null` 与 `data_gaps`，不返回 0 伪装为真实指标。
- 可售间夜、净收入、佣金字段只覆盖部分事实行时，只使用字段完整的对齐行计算 RevPAR、Net RevPAR、佣金率，并在 `data_gaps` 返回 `*_partial`。
- 佣金率、取消率只接受 0-100 的有效百分比；负提前期不进入平均提前期。
