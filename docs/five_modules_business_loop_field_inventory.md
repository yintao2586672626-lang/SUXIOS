# 五大板块业务闭环与字段清单

> 核对范围：`public/index.html`、`route/app.php`、五大板块 Controller / Service、相关 SQL 迁移。  
> 目标：先固定页面、接口、字段、存储、公式和缺口，后续补代码按本清单逐项执行，避免扩大改动面。

## 1. 总览

| 板块 | 页面入口 | 后端入口 | 当前闭环状态 | 主要结论 |
|---|---|---|---|---|
| 筹建管理 | `ai-strategy` / `ai-simulation` / `ai-feasibility` | `/api/strategy`、`/api/agent/feasibility-report`、遗留 `/api/ai` | 部分闭环 | 战略推演和可行性报告有后端记录，量化模拟仍是前端本地计算。 |
| 开业管理 | `opening-overview` / `opening-checklist` | `/api/opening` | 基本闭环 | 项目创建、清单生成、任务编辑、评分回显已闭环；缺少项目编辑、删除/归档。 |
| 运营管理 | `ops-source` / `ops-analysis` / `ops-insight` / `ops-plan` / `ops-track` | `/api/operation` | 基本闭环 | 数据聚合、根因、预警、策略模拟、动作追踪已串通；需补强预警持久化和权限过滤。 |
| 扩张管理 | `market-evaluation` / `benchmark-model` / `collaboration-efficiency` | `/api/expansion` | 计算闭环，业务未持久化 | 三个接口可计算返回，但无保存、历史、编辑、项目关联。 |
| 转让管理 | `asset-pricing` / `timing-strategy` / `decision-board` | `/api/transfer` | 计算闭环，业务未持久化 | 定价、时机、看板可计算，但未绑定真实数据源，也无保存/回显。 |

## 2. 筹建管理

### 业务闭环

| 环节 | 当前实现 | 状态 |
|---|---|---|
| 项目信息录入 | `aiProject` 本地对象 | 已有 |
| 战略推演 | `POST /api/strategy/simulate`，写入 `strategy_simulation_records` | 已有 |
| 量化模拟 | 前端 `calculateSimulation()`，写入 `localStorage` | 部分 |
| 可行性报告 | `POST /api/agent/feasibility-report/generate`，写入 `feasibility_reports` | 已有 |
| 历史回看 | 可行性报告有 list/detail；战略推演记录无前端列表；量化模拟无后端记录 | 部分 |

### 字段清单

| 场景 | 字段 | 来源/去向 | 说明 |
|---|---|---|---|
| 项目基础 | `project_name`、`city`、`district`、`address` | 前端表单 -> 战略/报告接口 | 必填核心字段。 |
| 物业经营 | `property_area`、`room_count`、`monthly_rent`、`lease_years`、`rent_free_months` | 前端表单 -> `/strategy/simulate` | 战略评分和物业适配使用。 |
| 投入成本 | `decoration_budget`、`transfer_fee` | 前端表单 -> 可行性报告 | `transfer_fee` 不进入战略推演。 |
| 定位 | `business_type`、`primary_customer`、`target_grade` | 前端表单 -> `/strategy/simulate` | 前端映射为 `target_customer`、`target_hotel_level`。 |
| 竞争 | `competitor_count` | 前端表单 -> `/strategy/simulate` | 用于竞争压力评分。 |
| 量化模拟 | `roomCount`、`decorationInvestment`、`furnitureInvestment`、`openingCost`、`otherInvestment`、`adr`、`occupancyRate`、`otherIncome`、`monthlyRent`、`laborCost`、`utilityCost`、`otaCommissionRate`、`consumableCost`、`maintenanceCost`、`otherFixedCost` | 前端本地 | 未进入后端；保存到 `suxios_simulation_*` localStorage。 |
| 可行性报告 | `target_brand_level`、`target_customer`、`notes`、`adr`、`occ` | 前端 -> `FeasibilityReportService` | 生成 `input_json`、`snapshot_json`、`report_json`。 |

### 数据表

| 表 | 字段重点 | 用途 |
|---|---|---|
| `strategy_simulation_records` | 项目字段、`input_json`、`data_snapshot_json`、`score_json`、`recommendation_json`、`risk_json` | 战略推演记录。 |
| `strategy_data_snapshots` | `city`、`district`、`business_type`、`target_customer`、`raw_json`、`normalized_json` | 外部数据缓存。 |
| `feasibility_reports` | `project_name`、`input_json`、`snapshot_json`、`report_json`、`conclusion_grade`、`payback_months`、`total_investment` | 可行性报告记录。 |

### 缺口

1. `ai-simulation` 未走后端，无法统一保存、回显、编辑和审计。
2. `/api/ai/strategy`、`/api/ai/simulation` 是遗留接口，当前主页面实际使用 `/api/strategy/simulate` 和前端本地计算。
3. 可行性报告接口在 `Agent` Controller 中调用 `checkAdmin()`，普通门店角色无法使用。
4. 战略推演已写表，但前端没有记录列表、详情、复用旧输入能力。

## 3. 开业管理

### 业务闭环

| 环节 | 当前实现 | 状态 |
|---|---|---|
| 创建项目 | `POST /api/opening/projects` | 已有 |
| 项目列表 | `GET /api/opening/projects` | 已有 |
| 准备总览 | `GET /api/opening/projects/:id/overview` | 已有 |
| 生成清单 | `POST /api/opening/projects/:id/generate-tasks` | 已有 |
| 编辑检查项 | `PUT /api/opening/tasks/:id` | 已有 |
| 重新评分 | `POST /api/opening/projects/:id/recalculate` | 已有 |

### 字段清单

| 表/对象 | 字段 | 说明 |
|---|---|---|
| `openingProjectForm` | `project_name`、`hotel_name`、`city`、`brand`、`positioning`、`room_count`、`opening_date`、`manager_name` | 前端创建项目字段。 |
| `opening_projects` | `hotel_id`、`project_name`、`hotel_name`、`city`、`brand`、`positioning`、`room_count`、`opening_date`、`manager_name`、`status`、`overall_score`、`risk_level`、`ai_penetration_rate`、`created_by` | 项目主表。 |
| `opening_tasks` | `project_id`、`category`、`task_name`、`task_desc`、`is_core`、`owner_name`、`collaborator_name`、`deadline`、`status`、`risk_level`、`acceptance_standard`、`ai_suggestion`、`remark`、`sort_order` | 检查清单。 |
| 评分输出 | `days_left`、`total_tasks`、`completed_tasks`、`completion_rate`、`core_tasks`、`core_completed_tasks`、`core_completion_rate`、`high_risk_count`、`overdue_count`、`ai_penetration_rate` | 总览卡片字段。 |

### 公式与规则

| 指标 | 公式/规则 |
|---|---|
| 完成率 | `completed_tasks / total_tasks * 100` |
| 核心完成率 | `core_completed_tasks / core_tasks * 100` |
| AI 渗透率 | 带 `ai_suggestion` 的任务数 / 总任务数 |
| 风险等级 | 有高风险或逾期为 `high`；未全部完成为 `medium`；否则 `low` |
| 任务高风险 | 逾期、核心事项临近开业、包含 PMS/OTA/支付/消防/安全/库存关键词 |

### 缺口

1. 项目只有创建和列表，缺少项目编辑、归档/删除、状态变更。
2. 前端创建项目没有显式酒店选择，后端仅在单酒店权限时自动填 `hotel_id`。
3. 检查项只允许更新负责人、协同人、截止日、状态、备注；不支持新增自定义检查项、删除、调整排序。

## 4. 运营管理

### 业务闭环

| 环节 | 当前实现 | 状态 |
|---|---|---|
| 策源·全维数据 | `GET /api/operation/full-data` | 已有 |
| 策析·根因定位 | `POST /api/operation/root-cause` | 已有 |
| 策见·预警推送 | `GET /api/operation/alerts`、`POST /api/operation/alerts/read` | 部分 |
| 策案·策略模拟 | `POST /api/operation/strategy-simulation` | 已有 |
| 策行·效果追踪 | `POST /api/operation/actions`、`GET /api/operation/action-tracking`、`POST /api/operation/actions/:id/finish` | 已有 |

### 字段清单

| 场景 | 字段 | 说明 |
|---|---|---|
| 筛选 | `hotel_id`、`date` | 前端 `operationFilters`，用于全维数据和根因。 |
| 全维数据输出 | `summary`、`ota`、`competitors`、`reviews`、`holiday`、`abnormal_flags` | `/operation/full-data` 返回。 |
| `summary` | `revenue`、`orders`、`room_nights`、`adr`、`occ`、`revpar`、`data_status` | 来自 `daily_reports` + `online_daily_data`。 |
| `ota` | `exposure`、`visitors`、`views`、`orders`、`view_rate`、`order_rate`、`data_status` | 来自 `online_daily_data.raw_data` 与 `book_order_num`。 |
| `competitors` | `avg_price`、`avg_score`、`price_gap`、`score_gap`、`rank_position`、`data_status` | 优先 `competitor_analysis`，其次 `online_daily_data` 竞对行。 |
| `reviews` | `score`、`review_count`、`negative_keywords`、`data_status` | 来自点评分和原始 JSON。 |
| 策略模拟入参 | `hotel_id`、`strategy_type`、`adjust_amount`、`discount_rate`、`start_date`、`end_date` | `strategy_type` 支持调价、促销、房量、竞对跟价、节假日。 |
| 动作追踪入参 | `hotel_id`、`action_type`、`action_title`、`start_date`、`end_date`、`target_metric`、`target_change_rate`、`remark` | 写入 `operation_action_tracks`。 |

### 数据表

| 表 | 字段重点 | 用途 |
|---|---|---|
| `daily_reports` | `hotel_id`、`report_date`、`report_data`、`occupancy_rate`、`room_count`、`guest_count`、`revenue` | 经营日报来源。 |
| `online_daily_data` | `system_hotel_id`、`data_date`、`amount`、`quantity`、`book_order_num`、`comment_score`、`qunar_comment_score`、`raw_data`、`data_value`、`source`、`dimension`、`data_type` | OTA 与点评来源。 |
| `competitor_analysis` | `hotel_id`、`analysis_date`、`our_price`、`competitor_price`、`price_difference`、`price_index`、`competitor_data` | 竞对价格来源。 |
| `operation_alerts` | `hotel_id`、`alert_type`、`level`、`title`、`message`、`source`、`status`、`related_date`、`raw_data`、`deleted_at` | 预警持久化。 |
| `operation_action_tracks` | `hotel_id`、`action_type`、`action_title`、`start_date`、`end_date`、`target_metric`、`target_change_rate`、`before_data_json`、`after_data_json`、`result_status`、`result_summary`、`status` | 策略动作追踪。 |

### 公式与规则

| 指标 | 公式/规则 |
|---|---|
| `adr` | `revenue / room_nights` |
| `occ` | 优先 `daily_reports.occupancy_rate`；为空时 `room_nights / room_count * 100` |
| `revpar` | `revenue / room_count` |
| `view_rate` | `views / exposure * 100` |
| `order_rate` | `orders / visitors * 100` |
| 根因 | 曝光下降、浏览转化低、订单转化低、价格偏高、评分偏低、节假日临近、数据采集异常 |
| 动作效果 | 执行满 3 天后，对比执行前后 `orders/revenue/room_nights/conversion` 达成率 |

### 缺口

1. 规则生成的预警没有写入 `operation_alerts`，刷新后仍会重新生成。
2. `alerts/read` 只按 ID 标记，当前服务层未再次按酒店权限过滤 ID。
3. `strategyForm.start_date/end_date` 前端有字段，但后端策略模拟当前主要使用近 30 天基线，日期字段未参与计算。
4. 节假日表写死在代码内，后续应改成可配置数据源。

## 5. 扩张管理

### 业务闭环

| 环节 | 当前实现 | 状态 |
|---|---|---|
| 智投·市场评估 | `POST /api/expansion/market-evaluation` | 计算闭环 |
| 智瞰·标杆选模 | `POST /api/expansion/benchmark-model` | 计算闭环 |
| 智联·协同提效 | `POST /api/expansion/collaboration-efficiency` | 计算闭环 |
| 保存/回显/编辑 | 无持久化 | 未闭环 |

### 字段清单

| 场景 | 字段 | 说明 |
|---|---|---|
| 市场评估入参 | `city`、`business_area`、`property_area`、`estimated_rent`、`target_room_count`、`decoration_level`、`target_customer` | 前端 `marketEvaluationForm`。 |
| 市场评估输出 | `market_heat_score`、`supply_competition_strength`、`price_band_suggestion`、`investment_risk_level`、`recommended_property_type`、`ai_operation_suggestions`、`not_recommended_risks`、`metrics`、`decision`、`data_status`、`rule_reasons` | 规则引擎结果。 |
| 标杆选模入参 | `city`、`business_area`、`target_price_band`、`hotel_type`、`target_room_count` | 前端 `benchmarkModelForm`。 |
| 标杆选模输出 | `position`、`recommended_benchmarks`、`copyable_strategies`、`differentiation_suggestions`、`avoid_copying_points`、`data_status` | 当前为规则构造的模拟标杆。 |
| 协同提效入参 | `project_name`、`city_area`、`current_stage`、`owner`、`expected_online_date`、`tasks[]` | 前端 `collaborationProject` + `collaborationTasks`。 |
| `tasks[]` | `name`、`status`、`owner`、`due_date`、`risk_note` | 服务端标准化为 7 个固定节点。 |
| 协同提效输出 | `project_overview`、`task_board`、`progress`、`delay_risk`、`next_actions`、`data_status` | 看板结果。 |

### 公式与规则

| 指标 | 公式/规则 |
|---|---|
| 单房面积 | `property_area / target_room_count` |
| 单房月租 | `estimated_rent / target_room_count` |
| 每平米月租 | `estimated_rent / property_area` |
| 市场评分 | 基础 62 分，按商圈、房量、面积、租金、客群、装修等级加减分 |
| 协同进度 | `已完成任务数 / 总任务数 * 100` |
| 延误风险 | 风险状态、逾期、上线 15/30 天内关键节点未完成 |

### 缺口

1. 没有扩张项目主表，市场评估、标杆选模、协同任务不能保存。
2. `data_status.real_data_used` 固定为 `false`，没有接入真实市场、竞品或客流数据。
3. 标杆酒店是规则生成的 A/B/C 模型，不是来自 `competitor_hotel` 或外部 POI 数据。
4. 协同任务只存在前端内存和接口返回中，刷新后回到默认任务。

## 6. 转让管理

### 业务闭环

| 环节 | 当前实现 | 状态 |
|---|---|---|
| 智算·资产定价 | `POST /api/transfer/pricing` | 计算闭环 |
| 智略·时机推演 | `POST /api/transfer/timing` | 计算闭环 |
| 智决·数据看板 | `POST /api/transfer/dashboard` | 计算闭环 |
| 保存/回显/编辑 | 无持久化 | 未闭环 |

### 字段清单

| 场景 | 字段 | 说明 |
|---|---|---|
| 资产定价入参 | `hotel_name`、`location`、`room_count`、`monthly_revenue`、`monthly_rent`、`labor_cost`、`utility_cost`、`ota_commission`、`other_fixed_cost`、`decoration_investment`、`remaining_lease_months`、`expected_transfer_price`、`occupancy_rate`、`adr`、`rating`、`order_count`、`licenses_complete`、`has_data_anomaly` | 前端 `transferPricingForm`。金额单位为万元。 |
| 资产定价输出 | `basic_info`、`costs`、`profit`、`valuation`、`risk_level`、`risk_points`、`main_reasons`、`suggestion`、`data_notice`、`unit` | `TransferDecisionService::calculateAssetPricing()`。 |
| 时机推演入参 | `current_revenue`、`previous_revenue`、`current_orders`、`previous_orders`、`current_adr`、`previous_adr`、`current_occupancy_rate`、`previous_occupancy_rate`、`rating`、`holiday_days`、`is_peak_season`、`has_data_anomaly`、`has_data_gap`、`exposure`、`visitors`、`conversion_rate`、`order_count`、`room_nights` | 前端 `transferTimingForm`。 |
| 时机推演输出 | `timing_score`、`decision`、`main_reasons`、`risk_points`、`next_suggestions`、`suggested_action`、`data_quality` | `TransferDecisionService::calculateTransferTiming()`。 |
| 看板入参 | `pricing`、`timing`、`metrics` | 前端目前传 `metrics: {}`。 |
| 看板输出 | `cards`、`final_judgement`、`main_reasons`、`risk_points`、`next_suggestions`、`suggested_action`、`unit` | 汇总定价与时机结果。 |

### 公式与规则

| 指标 | 公式/规则 |
|---|---|
| 月总成本 | `monthly_rent + labor_cost + utility_cost + ota_commission + other_fixed_cost` |
| 月净利润 | `monthly_revenue - monthly_total_cost` |
| 年净利润 | `monthly_net_profit * 12` |
| 回收周期 | `expected_transfer_price / monthly_net_profit` |
| 估值倍数 | 基础 24 个月，按剩余租期、评分、入住率、数据异常、证照完整度加减，范围 6-42 |
| 保守/合理/乐观估值 | 月净利润为正时按利润倍数；月净利润非正时按装修投入折价 |
| 转让时机评分 | 基础 50 分，营业额/订单/ADR/入住率趋势、评分、节假日、旺季、数据异常、数据断档加减分 |

### 缺口

1. 没有转让项目/记录表，无法保存资产定价、时机推演和看板结果。
2. 前端字段为手填，未从 `daily_reports`、`online_daily_data`、`competitor_analysis` 自动取数。
3. 看板 `metrics` 目前为空，无法承载额外交易质量指标。
4. 无历史版本、编辑回显、删除/归档、审计日志。

## 7. 建议补码顺序

| 优先级 | 改动 | 文件范围 | 验证方式 |
|---|---|---|---|
| P0 | 确认本清单为字段契约，避免继续新增同义字段 | 文档 | 人工确认 |
| P1 | 开业管理补项目编辑、归档/删除、酒店选择 | `route/app.php`、`Opening.php`、`OpeningService.php`、`public/index.html` | 创建 -> 编辑 -> 生成任务 -> 更新任务 -> 归档 |
| P1 | 运营预警持久化和标记已读权限过滤 | `OperationManagement.php`、`OperationManagementService.php` | 生成预警 -> 刷新仍存在 -> 非授权 ID 不可标记 |
| P2 | 筹建量化模拟后端化并保存历史 | 新增迁移、Controller/Service、前端调用 | 计算 -> 保存 -> 列表 -> 详情 -> 复用输入 |
| P2 | 扩张管理新增项目与三类结果记录 | 新增迁移、`ExpansionService.php`、前端 | 保存 -> 回显 -> 编辑 -> 历史 |
| P2 | 转让管理新增记录表并绑定真实数据 | 新增迁移、`TransferDecisionService.php`、前端 | 自动取数 -> 计算 -> 保存 -> 看板复核 |

## 8. 修改边界

1. 不在 `public/` 目录运行 Vite build。
2. 不删除历史字段；新增字段必须兼容旧数据空值。
3. 涉及金额的单位必须明确：筹建多为元，扩张市场评估租金为元，转让测算金额为万元。
4. 所有新增持久化接口必须覆盖保存、回显、编辑、删除/归档、旧数据兼容、权限过滤。
5. OTA Cookie、spidertoken、`.env` 不进入文档、日志或提交。
