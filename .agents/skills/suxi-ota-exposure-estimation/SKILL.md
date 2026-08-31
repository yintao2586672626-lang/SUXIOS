---
name: suxi-ota-exposure-estimation
description: 用于宿析OS按同一门店、同一OTA平台、同一口径的已核验“曝光人数—浏览人数”配对，进行曝光人数派生估算、滚动倍数校准、同日累计重放、估算区间与漂移检查。适用于“曝光反推、曝光漏抓估算、浏览人数倒推曝光人数、曝光→详情浏览一转率校准（detail_visitors / exposure_users）、逐时累计曝光估算”，也负责拒绝跨店套率或把估算回填成OTA事实；不用于采集/查询平台真实曝光、支付转化率或曝光量/曝光次数。
---

# 宿析 OTA 曝光人数估算

## 能力边界

本 Skill 只生成指定 OTA 渠道的 `exposure_users`（曝光人数，单位 `people`）派生估算。它不生成 `total_exposure`、`organic_exposure`、`ad_exposure` 或“曝光次数”（单位 `impressions`），也不替代平台采集。

所有成功估算固定为以下身份；失败和非适用结果使用对应 `quality_status` 且不返回数值：

```text
evidence_type = derived_estimate
quality_status = estimate_only
decision_eligible = false
writeback_allowed = false
```

平台原曝光字段继续保持真实的 `missing / available / collection_failed` 等状态；不得用估算值补齐、覆盖或冒充平台事实，不得据此单独做调价或全酒店经营结论。

## 当前成熟度

本目录当前是 `absorption_candidate / project-local only`，不是已发布、已安装或已现场验证的正式能力。现有来源材料只能核实 1 组同店同平台真实配对，因此：

- `anchored_inverse` 可以重放该锚点的同日累计代数关系；
- `rolling_median` 的 7 组以上样例仅用于验证算法守卫，不证明当前酒店已有可用真实基线；
- 对真实材料运行 `rolling_median` 时必须返回 `insufficient_baseline`，直到取得至少 7 个目标日前、同口径、已核验的独立配对日。

不得把回归 fixture、合成配对或候选状态描述成真实 OTA 准确率、现场可用性或正式插件分发证据。

## 路由

- 用户要采集、补抓或回读真实曝光：转到 `suxi-ota-ops`。
- 用户要判断曝光人数、曝光量、曝光次数、平台字段或单位：转到 `suxi-ota-revenue-semantic-layer`。
- 用户提供同店同平台的配对样本、校准率或浏览人数，要求估算缺失曝光人数：使用本 Skill。
- 用户要求把估算写回真实曝光字段：拒绝写回，只能返回独立的 estimate 结果。

## 使用流程

1. 先确认 `platform`、`system_hotel_id`、业务日期、来源模块/路径、指标定义和单位。只有 `exposure_users / people` 与 `detail_visitors / people` 的同口径配对可进入计算。
2. 区分两种方法：
   - `anchored_inverse`：用一个已核验锚点在**同一天、同一累计口径**重放代数关系；只适合同日小时累计或样例复现，不得外推为长期基线。
   - `rolling_median`：用目标日前至少 7 个已核验配对日的 `exposure_users / detail_visitors` 中位倍数估算目标日；活动日、补齐值、派生值和目标日自身不得进入基线。
3. 读取 [references/algorithm-contract.md](references/algorithm-contract.md)，按其 JSON 合同准备输入。解析用户给出的文件时保留文件哈希、sheet/range 或来源行引用。
4. 输入来自宿析严格事实投影时，先使用项目内适配器：

   ```text
   node scripts/estimate-from-trusted-facts.mjs --input <strict-facts.json> --pretty
   ```

   适配器不读取或写入事实表，只接受同租户、同酒店、同平台、同来源路径、同指标定义、同累计截止时点的 `exposure_users / detail_visitors` 日事实；每行必须同时满足 `history_status=success`、`validation_status=verified`、`readback_verified=true/1`。不足 7 个目标日前独立严格配对日时固定返回 `insufficient_baseline`，不套默认倍数。
5. 其他已按算法合同准备好的输入，从本 Skill 目录解析脚本绝对路径，运行：

   ```text
   node scripts/estimate-exposure-users.mjs --input <input.json> --pretty
   ```

6. 原样保留脚本的 `status`、输入谱系、公式、误差指标、告警和不可写回标记。缺少样本、口径冲突、跨店、跨平台、时间泄漏或输入自洽失败时，不手工补一个数。
7. 若用户给出新配对事实，先核验来源身份和真实/派生状态；只有真实、同口径、目标日前的数据才能更新滚动基线。

## 不可退步规则

- “一转率”在来源工作簿中指 `detail_visitors / exposure_users`；支付转化率是 `paid_orders / detail_visitors`。二者不得混用。
- 不采用跨店默认倍数、其他平台倍数、`11.65`、`8.77%` 或固定 `±3%` 作为无证据兜底。来源常量只有在对应锚点、精度和使用范围明确时才能重放，并继续标记为估算。
- 滚动验证必须按时间先后，仅用目标日前样本；禁止把当天真实曝光先加入基线再评估当天误差。
- 活动日可以按已核验状态退出后续基线。倍数漂移只在显式提供阈值和政策引用时判断；当前没有默认已验证阈值。只要估算前已有足够历史样本，退出日已产生的预测误差仍必须留在误差分布，不能事后删掉坏结果。
- 非活动日的人工排除只接受合同列明的有限原因，并要求独立来源引用；不能用自由文本或事后选择改善结果。
- 小时数据是累计值，只输出累计估算；不以相邻小时相减制造小时增量。
- 缺失不是 0。真实 0 只有在来源明确返回 0 且质量为 observed/verified 时才可参与目标计算。
- 已核实活动大流量日是 `verified_event_outlier`，不是采集错误；它保留为事实，但不进入日常基线。
- 连续缺失、插补和周同比补齐不属于本 Skill 的事实恢复能力；插补输入最高只能返回 `reference_only`。
- `source_ref` 只保留批次号、文件哈希、sheet/range 或脱敏行号；不得放入 Cookie、令牌、敏感请求头、浏览器 Profile 路径或带凭证的 URL。

## 来源与证据上限

本 Skill 吸收的是“同店同平台校准、滚动中位倍数、真实/估算分层、活动日隔离、累计口径”的方法，不继承附件中未经复核的准确率、默认参数或缺失补齐实现。首次审计结论与哈希见 [references/source-provenance.md](references/source-provenance.md)。

## 验收

- `anchored_inverse` 能忠实重放附件 2026-08-20 的 20:00 与 23:00 累计示例，并公开 8.77% 四位小数舍入造成的区间。
- `rolling_median` 在 7 个以上独立、已核验、目标日前配对样本上运行，并给出无同日泄漏的滚动误差；当前 `rolling-golden` 是行为回归 fixture，不是真实酒店基线证据。
- 一个真实材料稀疏样例返回 `insufficient_baseline`，一个口径冲突样例返回 `not_applicable`。
- 严格事实适配器拒绝租户、酒店、平台、来源路径、日期、累计截止时点或三重质量门不一致的输入；成功估算仍固定为 `estimate_only`，不足 7 日不返回数值。
- 所有成功结果仍为 `derived_estimate / estimate_only`，且 `decision_eligible=false`、`writeback_allowed=false`。
