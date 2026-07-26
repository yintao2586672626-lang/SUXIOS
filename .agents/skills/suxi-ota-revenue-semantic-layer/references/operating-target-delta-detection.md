# 经营目标差值检测与节奏判断（宿析OS吸收版）

## 1. 来源与吸收状态

- 来源文件：`酒店经营目标_Codex学习源码包_2026-07-25.zip`
- 来源 SHA256：`3997FFA6BD111136A5C3C9FE24796D92945C241BBA9862B4A7C09F92343FB765`
- 打包日期：`2026-07-25`
- 源码包版本：`src/package.json = 1.0.0`，经营目标策略版本：`1.4.20`
- 许可证声明：`ISC`
- 复核日期：`2026-07-27`
- 复核范围：`src/yunyi_target.js`、采集输入合同、快照逻辑、推送状态与 `tests/target_module.test.js`
- 验证结果：源码包自带 7 项基础测试通过，JavaScript 语法检查通过；复杂差值分支、旺季判断、满房质量和推送去重没有被源码包自带测试完整覆盖。
- 知识级别：`user_source_code_reviewed_method_adapted`

本知识只吸收经过代码复核后确实优于宿析OS当前单点计算的思维方法，不复制源项目代码，不把源项目阈值、酒店名称、历史快照或经营结果当作宿析OS当前门店事实。

## 2. 哪些板块比宿析OS当前实现更好

| 板块 | 源项目更好的思路 | 宿析OS吸收方式 |
| --- | --- | --- |
| 双轴判断 | 同时比较“当前值与目标/时间标准的差距”以及“当前值与上一快照的变化” | 固化为 `gap + delta`，两条证据必须同时展示 |
| 多指标差值 | 不只看营业额，还看 ADR、RevPAR、已售、取消和剩余目标 | 固化差值向量，缺失字段保持 `null` |
| 取消修正 | 用“净已售变化 + 累计取消增加”还原区间毛预订 | 仅在取消累计已验证时计算；订单来了当前只能输出净拾取 |
| 时间归一 | 把相邻快照变化除以真实间隔，得到每小时速度 | 不要求整点采集，统一按 `elapsed_hours` 归一 |
| 差距走势 | 不只说当前落后，还判断差距是在收窄还是扩大 | 用 `gap_change` 输出恢复、恶化或稳定 |
| 量价收矩阵 | 将售卖进度、收入进度、ADR 和 RevPAR 联合判断 | 作为观察性经营结构判断，不直接写成因果 |
| 异常优先级 | 收入或已售回落时，先查取消、冲账或修订，再给价格建议 | 固化为最高优先级异常门禁 |
| 满房质量 | 不把满房天然当成功，区分健康满房与低收益满房 | 目标完成和显式 ADR 目标共同判断；缺目标时不补默认值 |
| 自身节奏学习 | 尝试使用同店历史和同星期样本校准销售速度 | 采用同店、同口径、可比日期中位节奏；样本不足不判快慢 |
| 规则与推送状态 | 输出规则标识，并对满房事件按日去重 | 每次判断返回 `rule_id`、证据引用和复核时间；状态未变化不重复轰炸 |

## 3. 宿析OS已有优势必须保留

朋友项目的差值思维更细，但宿析OS当前实现的数据可信门禁更完整。吸收时必须保留：

1. 同租户、同系统门店、同经营日期、同事实范围。
2. `whole_hotel`、`accommodation_room_fee`、`ota_channel` 永不混算。
3. 当前快照与上一快照都必须达到允许计算的质量状态；自动来源还需保存回读通过。
4. 缺失值保持 `null`、`unknown`、`unverified` 或 `blocked`，不得用 `0`、旧值或默认值补齐。
5. 经营建议只供人工复核；没有真实写回接口、授权、审批和执行回执时，不自动改价、开关房或改库存。

## 4. 可比较快照合同

只有同时满足以下条件，才进入正式差值检测：

- `tenant_id`、`hotel_id`、`target_date`、`fact_scope` 完全相同。
- 指标语义版本、币种、时区和业务日切规则相同。
- 自动来源的 `source_provider` 相同，且两个快照都能回溯到独立 `capture_id` 或 `source_trace_id`。
- 当前快照时间严格晚于上一快照时间。
- 目标版本相同；中途修改目标后，从新目标版本重新建立基线。
- 可售房量和房量口径相同；维修房、关房或日租/时租口径变化时重新建立基线。
- 两个快照的质量均为 `verified` 或经过明确人工确认的 `manual_confirmed`。

不满足时返回 `not_comparable` 或 `rebaseline_required`，保留原始事实和缺口，不计算趋势。

第一条合格快照只执行：

```text
rule_id = OT_DIFF_BASELINE_ONLY
judgment = 已建立当日基线，暂无上一条可比快照
```

第一条快照不得假装成固定 07:00 开盘基线，也不得推送“变快、变慢、改善、恶化”。

## 5. 宿析OS差值向量

### 5.1 订单来了当前可计算

订单来了当前已核验字段属于 `accommodation_room_fee`，包括房费、ADR、出租率、RevPAR、已售间夜和推导可售房夜，但没有累计取消字段。因此：

```text
elapsed_hours = (current.captured_at - previous.captured_at) / 3600

delta_room_fee = current.total_room_fee - previous.total_room_fee
delta_sold_room_nights = current.sold_room_nights - previous.sold_room_nights
delta_adr = current.adr - previous.adr
delta_occupancy_points = current.occupancy_rate_percent - previous.occupancy_rate_percent
delta_revpar = current.revpar - previous.revpar

net_pickup = delta_sold_room_nights
net_pickup_per_hour = net_pickup / elapsed_hours
room_fee_per_hour = delta_room_fee / elapsed_hours
```

`net_pickup` 只能解释为“账面净已售间夜变化”。没有取消累计证据时，不得称为“新增预订”“毛拾取”或“本时段新订了几间”。

`delta_room_fee / net_pickup` 只能标为“净增间夜对应的收入变化参考”。它不是订单成交 ADR；有改价、补录、退款、换房、跨日入账或取消时尤其不能这样解释。

### 5.2 将来取得取消累计后才可计算

只有当前与上一快照都存在同口径、累计型、已验证的取消间夜时：

```text
delta_cancellations = current.cancellations_total - previous.cancellations_total
gross_pickup = net_pickup + delta_cancellations
gross_pickup_per_hour = gross_pickup / elapsed_hours
interval_revenue_per_gross_pickup = delta_room_fee / gross_pickup
```

若取消累计下降、重置或来源变更，返回 `cancellation_counter_reset_or_mismatch`，不得把取消缺失写成 `0`。

### 5.3 目标差距与差距变化

```text
completion_rate = actual_revenue / target_revenue
selling_progress = sold_room_nights / sellable_room_nights

revenue_progress_gap_points =
  current_completion_rate - expected_completion_rate_at_capture

selling_progress_gap_points =
  current_selling_progress - expected_selling_progress_at_capture

revenue_gap_change_points =
  current_revenue_progress_gap_points - previous_revenue_progress_gap_points

selling_gap_change_points =
  current_selling_progress_gap_points - previous_selling_progress_gap_points
```

- `gap_change` 明显为正：差距收窄或优势扩大，标为 `recovering`。
- `gap_change` 明显为负：差距扩大或优势收窄，标为 `worsening`。
- 落在动态容差内：标为 `stable`。
- 没有可信时间标准：只展示当前目标完成率和实际差值，不输出“进度快/慢”。

剩余目标消耗速度仅在没有回落异常且上一快照剩余目标大于 0 时计算：

```text
target_consumption_rate_per_hour =
  (previous_remaining_revenue - current_remaining_revenue)
  / previous_remaining_revenue
  / elapsed_hours
```

## 6. 小体量门店的动态容差

朋友项目使用固定正负 5 或 10 个百分点，容易把小酒店一间房的正常波动判成强信号。宿析OS先使用可配置的冷启动容差，随后由同店历史校准：

```text
room_tolerance = max(1间, ceil(sellable_room_nights * 5%))
revenue_tolerance = max(50元, target_revenue * 0.5%)
rate_tolerance = max(5元, current_adr * 2%)
```

这些是 `cold_start_config`，不是已验证经营规律。规则输出必须带 `tolerance_source=cold_start_config`。积累至少 3 个同店、同星期或同日期类型、同口径的可比经营日后，优先使用历史分布的中位数和波动区间，并标记样本数。

时间间隔不要求整点。冷启动建议把少于 5 分钟标为 `interval_too_short_noise_risk`，超过 6 小时标为 `interval_too_long_low_comparability`；这两个值也必须按采集频率配置，而不是跨店固定事实。

可信节奏标准优先级：

1. 本店为目标日期类型配置并留有版本的当日节奏曲线。
2. 本店近期开业状态、星期、节假日类型和事实范围相同的历史中位曲线。
3. 有来源和有效期的人工计划曲线。
4. 无可信标准：不判快慢，只报事实与差值。

不得直接采用源码包中的 7 月/8 月固定时点进度表、固定 23:00 满房目标或跨店统一阈值。

## 7. 判定优先级

### P0：身份、质量和可比性

任一门禁失败，返回 `blocked` 或 `rebaseline_required`，停止经营归因。

### P1：异常与反转

同一业务日的累计房费或累计已售下降时：

```text
rule_id = OT_DIFF_REVERSAL_UNKNOWN
judgment = 累计值出现回落，先核对取消、退款、冲账、改价、换房、补录或数据修订
```

如果可售房量改变、ADR/RevPAR 与房费/房量无法对账、来源切换或时间倒序，先返回具体数据缺口。异常未解释前，不给降价或提价建议。

### P2：量、价、收入变化

以下均是“观察结果”，不是因果结论：

| 净已售 | 房费 | ADR | RevPAR | 观察性判断 |
| --- | --- | --- | --- | --- |
| 上升 | 上升 | 上升 | 上升 | `volume_rate_up`，量价同步改善 |
| 上升 | 上升 | 下降 | 上升 | `volume_driven_improvement`，增量偏向以价换量，需观察房价稀释 |
| 上升 | 上升 | 下降 | 下降 | 先检查口径、可售房量和累计/区间混用；对账通过后才讨论低收益增量 |
| 不变 | 上升 | 任意 | 任意 | `posting_or_rate_adjustment`，先查补录、改价或入账时点 |
| 上升 | 不变 | 任意 | 任意 | `revenue_posting_lag_or_scope_mismatch`，先查收入入账 |
| 不变 | 不变 | 近似不变 | 近似不变 | `no_movement`；只有绑定已执行动作后，才可称“动作暂无响应” |
| 下降 | 任意 | 任意 | 任意 | `reversal_unknown`，异常优先 |
| 任意 | 下降 | 任意 | 任意 | `reversal_unknown`，异常优先 |

如果有可信时间标准，再叠加收入进度 × 售卖进度：

| 收入进度 | 售卖进度 | 结构判断 |
| --- | --- | --- |
| 领先 | 领先 | 量收共同领先，继续检查 ADR 是否健康 |
| 领先 | 落后 | 价格或高价值结构贡献较强，但库存消耗仍慢 |
| 落后 | 领先 | 低收益占房风险，检查低价库存和房型结构 |
| 落后 | 落后 | 收入与库存消耗同时承压，只能列排查假设，不能直接定责价格 |

“调价有效、活动无效、需求增长”等因果措辞，必须额外具备 `action_id`、执行时间、作用房型/渠道、同步回执和执行前后可比快照。否则统一改写为“观察到……，可能……，需核对……”。

### P3：目标压力

在事实可用时继续展示：

- 完成率、剩余目标。
- 剩余可售房夜。
- 剩余目标所需均价。
- 所需均价相对当前 ADR 的压力。

这一步只描述压力，不单凭压力自动建议涨价。流量、库存、房型、渠道与市场证据不足时，输出缺口。

### P4：满房质量

满房不是天然成功：

- `healthy_full_house`：可售归零、营收目标完成；若门店显式设置了 `target_adr`，ADR 也达到目标。
- `risk_full_house`：可售归零，但营收目标未完成，或显式 ADR 目标未达到。
- `full_house_quality_partial`：满房事实成立，但缺营收目标或缺显式 ADR 目标，只报告已知部分。

`target_revenue / total_sellable_rooms` 在满房基准下更接近目标 RevPAR，不是 ADR。不得把它命名为目标 ADR。

## 8. 输出与提醒合同

每次检测至少返回：

```text
rule_version
rule_id
status
fact_scope
current_capture_id
previous_capture_id
captured_at
elapsed_hours
facts
delta_vector
target_gap
gap_change
pace_reference
tolerance
judgment
hypotheses
recommended_manual_check
confidence
data_gaps
next_review_at
```

提醒策略：

- 基线只保存不推送。
- 身份/质量阻断只推送一次状态变化，后续相同状态不重复轰炸。
- 只有规则状态变化、跨过动态容差、出现 P1 异常或首次满房时提醒。
- 满房按门店 + 业务日 + 事实范围去重；满房解除后再次满房可作为新事件，但必须保留状态转换证据。
- 每条提醒带当前/上一快照引用、规则版本和下一次复核时间。

## 9. 明确不吸收的源项目逻辑

1. 固定只在 7 月、8 月启用的旺季规则。
2. 固定 07:00、11:00、15:00、19:00、23:00 的统一进度阈值。
3. 固定正负 5/10 个百分点作为所有酒店的强弱判定。
4. 把第一次观察当作 07:00 开盘基线。
5. 把 `target_revenue / total_rooms` 命名为目标 ADR。
6. 取消字段缺失时自动按 `0` 参与毛预订计算。
7. 不区分来源切换、容量变化、补录和真实经营变化。
8. 没有动作执行与同步证据时直接宣称调价或促销有效。
9. 把源项目自带 7 项基础测试当作复杂分支已经充分验证。

## 10. 当前落地边界

- 已沉淀：差值检测知识、字段与公式合同、动态容差、规则优先级、满房质量、检索关键词和来源指纹。
- 宿析OS当前可用事实：经营目标单点指标与版本快照；订单来了住宿房费事实经过身份、日期、范围、对账、保存和回读门禁后可作为差值引擎输入。
- 尚未实现：`OperatingTargetService` 运行时差值引擎、历史节奏学习、取消累计采集和差值提醒状态机。
- 因此当前只能说“知识已吸收”，不能说“差值检测功能已上线”。
