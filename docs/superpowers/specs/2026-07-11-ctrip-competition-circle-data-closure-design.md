# 携程竞争圈数据闭环设计

## 目标

修复携程 Cookie/API 竞争圈获取后的分类、身份、追踪、历史回读、缺失值和 AI 使用边界，并全量回填可识别的历史携程竞争圈数据。

## 不变边界

- `system_hotel_id` 保持为竞争圈所属的系统门店，不把竞品注册成系统门店。
- 不改变现有门店获取状态、凭据状态和 Cookie/API 主执行路径。
- `全渠道AI预计总间夜数` 保留原标题，但必须同时展示“AI推导、非平台原始字段”的说明。
- 不新增业务表，不删除历史行，不用伪造的平台证据填补历史缺口。

## 数据语义

- 竞争圈逐酒店行统一为 `data_type=competitor`、`dimension=competition_circle_hotel`。
- 本店行使用 `compare_type=self`；竞品行使用 `compare_type=competitor`。
- `system_hotel_id` 表示竞争圈归属；`compare_type` 表示圈内角色。历史页不得再以 `system_hotel_id IS NOT NULL` 判断“是否我的酒店”。
- 新采集创建或复用 `platform_data_sources` 中携程手动竞争圈数据源，并创建 `platform_data_sync_tasks`，写回 `data_source_id`、`sync_task_id`、`snapshot_time` 和脱敏 `source_trace_id`。
- 历史数据缺少原始接口证据时，使用 `legacy_backfill:<hash>` 作为迁移追踪号；它只证明回填来源，不证明平台响应。此类行标记 `partial` 或 `unverified`，并写入 `historical_source_trace_unavailable`。
- 去哪儿评分无有效值时写 `NULL`，并增加 `field_missing:qunar_comment_score`；不得保存为真实 `0.0`。

## 历史筛选与回显

- 按系统门店筛选时，返回该门店所属的完整竞争圈。
- “竞对数据”筛选必须包含新的 `data_type=competitor` 逐酒店行，并兼容旧 `competitor_avg` 汇总行。
- 最近获取/入库时间取 `GREATEST(create_time, update_time)`；覆盖更新显示“更新 N 条”，首次写入显示“新增 N 条”。
- 批次汇总展示圈所属门店、本店数量、竞品数量和验证状态，不再把整圈标记为“是否我的酒店：是”。

## AI 与指标说明

- AI经营建议默认只允许选择 `compare_type=self` 的本店行；竞品行作为只读对照数据输入，不作为被建议门店。
- 本店显示系统门店名称，同时保留 OTA 酒店 ID。
- `全渠道AI预计总间夜数` 保持标题，增加 `derived` 标识和“基于携程/去哪儿竞争圈可见字段推导”说明。
- “竞争健康度：恶化/改善”只有存在同门店、同口径、可比前序快照时才显示；否则显示“当前得分”，同时展示公式版本和比较基期。

## 全量回填

- 先只读预检并输出候选行、系统门店数、日期范围和无法识别本店的数量。
- 仅回填能从携程竞争圈字段签名识别的历史行；不改普通经营、流量、广告数据。
- 每个系统门店创建一个回填同步任务；事务内更新分类、角色、追踪、快照时间和校验状态。
- 无法可靠识别本店的竞争圈保留 `compare_type=competitor`，并标记 `self_identity_unresolved`，不得猜测。
- 脚本可重复执行；再次执行不得重复创建业务行或改变已验证的新采集证据。

## 验收

1. 巢湖测试昨日26行仍可获取，核心汇总与数据库一致。
2. “携程 + 竞对数据 + 巢湖测试”可查询刚获取的整圈数据。
3. 圈归属仍为系统酒店7，仅 OTA ID 832085 标记为本店。
4. 新采集四个追踪字段齐全；历史回填明确标记为迁移追踪而非平台原始证据。
5. 缺失去哪儿评分为 `NULL + partial`，不进入 AI 评分计算。
6. AI默认只选择巢湖测试本店，竞品作为对照。
7. 覆盖更新后最近时间正确变化，页面不再误称新增。
