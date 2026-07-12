# OTA Booking Diagnosis Knowledge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将“预订节奏与短窗口风险、流量增长但转化承接不足、客源城市损失贡献”三项高价值 OTA 诊断知识，幂等写入宿析OS三层知识库，并提供静态契约验证和员工可读文档。

**Architecture:** 只新增内容型 SQL 迁移，复用 knowledge_units、knowledge_chunks、knowledge_base 和系统级 OTA运营 分类；不新增业务事实表、采集器、接口、页面或 OTA 写回。独立 Node 验证器同时约束公式、字段、质量状态、OTA 渠道边界和幂等结构。

**Tech Stack:** MySQL 8/MariaDB JSON functions、Node.js ESM 静态契约验证、Markdown、npm scripts。

---

## Scope and acceptance

- 设计依据：docs/superpowers/specs/2026-07-12-ota-booking-diagnosis-knowledge-design.md
- Create: database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql
- Create: scripts/verify_ota_booking_diagnosis_knowledge.mjs
- Modify: docs/hotel_ota_metric_professional_knowledge.md
- Modify: package.json
- Do not modify: scripts/verify_e2e_contracts.mjs、业务事实表、OTA 采集/登录/Profile/Cookie、路由、页面或自动执行逻辑。
- 当前共享工作区已有大量用户改动。每次 git add 必须带上述精确路径；禁止 git add .。
- 验收结果：
  1. 单个 knowledge_units 知识单元；
  2. 五种 knowledge_chunks；
  3. 单个 hotel_id=0 的 knowledge_base 员工知识条目；
  4. 完整的六档提前期分桶；
  5. 三组公式和七个质量状态完整；
  6. 明确 metric_scope=ota_channel、null + data_gap、derived_estimate、禁止因果过度归因和禁止静默执行；
  7. npm run verify:ota-booking-diagnosis-knowledge 通过；
  8. 若本地 MySQL 可用，迁移重复执行两次后回读仍为 1/5/1；否则明确记录“文件已验证、数据库未应用”。

### Task 1: Write the failing knowledge contract verifier

**Files:**
- Create: scripts/verify_ota_booking_diagnosis_knowledge.mjs
- Read: docs/superpowers/specs/2026-07-12-ota-booking-diagnosis-knowledge-design.md

- [ ] **Step 1: Create the verifier with safe missing-file handling**

Create scripts/verify_ota_booking_diagnosis_knowledge.mjs with exactly this structure:

~~~js
import { existsSync, readFileSync } from 'node:fs';

const migrationPath = 'database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql';
const documentPath = 'docs/hotel_ota_metric_professional_knowledge.md';
const packagePath = 'package.json';

const readIfExists = (path) => existsSync(path) ? readFileSync(path, 'utf8') : '';
const migrationSource = readIfExists(migrationPath);
const documentSource = readIfExists(documentPath);
const packageSource = readIfExists(packagePath);
const failures = [];

const check = (name, pass) => {
  if (!pass) {
    failures.push(name);
  }
};

const includesAll = (source, needles) => needles.every((needle) => source.includes(needle));
const countMatches = (source, pattern) => (source.match(pattern) || []).length;

check('migration file exists', existsSync(migrationPath));
check('professional knowledge document exists', existsSync(documentPath));
check('package.json exists', existsSync(packagePath));

check(
  'migration writes all three knowledge layers',
  includesAll(migrationSource, [
    'INSERT INTO knowledge_units',
    'INSERT INTO knowledge_chunks',
    'INSERT INTO knowledge_base',
  ]),
);

check(
  'migration has exactly five structured knowledge chunk inserts',
  countMatches(migrationSource, /INSERT INTO knowledge_chunks/g) === 5,
);

check(
  'migration contains all five target chunk types',
  includesAll(migrationSource, [
    "'使用边界'",
    "'预订节奏诊断'",
    "'转化机会诊断'",
    "'客源城市诊断'",
    "'AI行动边界'",
  ]),
);

check(
  'migration is idempotent for unit, chunks, category, and staff article',
  countMatches(migrationSource, /WHERE NOT EXISTS/g) >= 3
    && migrationSource.includes('UPDATE knowledge_units')
    && migrationSource.includes('DELETE FROM knowledge_chunks')
    && migrationSource.includes('UPDATE knowledge_categories')
    && migrationSource.includes('UPDATE knowledge_base'),
);

check(
  'booking pace formulas are complete',
  includesAll(migrationSource, [
    'current_on_books - previous_on_books',
    'new_valid_booked_room_nights - newly_cancelled_room_nights',
    'current_otb / comparable_baseline_otb * 100',
    'bucket_room_nights / total_otb_room_nights * 100',
    'target_final_room_nights - current_net_otb_room_nights',
    'target_gap / remaining_observation_days',
  ]),
);

check(
  'lead-time buckets are complete and mutually exclusive',
  includesAll(migrationSource, [
    "'0天'",
    "'1天'",
    "'2-3天'",
    "'4-7天'",
    "'8-14天'",
    "'15天及以上'",
    '完整且互斥',
  ]),
);

check(
  'conversion opportunity formulas and estimate label are complete',
  includesAll(migrationSource, [
    'hotel_conversion_rate - comparable_conversion_rate',
    'eligible_uv * max(0, comparable_conversion_rate - hotel_conversion_rate)',
    'potential_orders * verified_avg_room_nights_per_order',
    'potential_room_nights * aligned_ota_adr',
    'derived_estimate',
  ]),
);

check(
  'source-city loss formulas are complete',
  includesAll(migrationSource, [
    '(current_city_room_nights - baseline_city_room_nights) / baseline_city_room_nights * 100',
    'max(0, baseline_city_room_nights - current_city_room_nights)',
    'city_lost_room_nights / total_lost_room_nights * 100',
    'city_lost_room_nights * aligned_city_or_hotel_adr',
  ]),
);

check(
  'all required quality states are persisted',
  includesAll(migrationSource, [
    'on_books_snapshot_missing',
    'comparison_baseline_missing',
    'conversion_denominator_missing',
    'source_city_room_nights_missing',
    'competitor_caliber_mismatch',
    'small_base',
    'derived_estimate',
  ]),
);

check(
  'truthfulness and OTA scope guards are explicit',
  includesAll(migrationSource, [
    'metric_scope=ota_channel',
    'null + data_gap',
    '历史已售间夜不能冒充 OTB 或 Pickup',
    '城市占比不能反推城市间夜',
    '不自动写回 OTA',
    '不得直接输出因果结论',
  ]),
);

check(
  'migration is content-only and does not mutate business fact tables',
  !/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i.test(migrationSource)
    && !/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:online_daily_data|ota_|orders|room_status)/i.test(migrationSource),
);

check(
  'migration contains no credential or customer identity material',
  !/(?:password|passwd|cookie|token|authorization|账号|手机号|身份证)/i.test(migrationSource),
);

check(
  'professional document exposes the three diagnosis sections',
  includesAll(documentSource, [
    '## 三项高价值诊断知识',
    '### 1. 预订节奏与短窗口风险',
    '### 2. 流量增长但转化承接不足',
    '### 3. 客源城市损失贡献',
    '4-7天',
    'derived_estimate',
    'metric_scope=ota_channel',
  ]),
);

let packageJson = {};
try {
  packageJson = JSON.parse(packageSource);
} catch {
  failures.push('package.json is valid JSON');
}

check(
  'npm script exposes the focused verifier',
  packageJson.scripts?.['verify:ota-booking-diagnosis-knowledge']
    === 'node scripts/verify_ota_booking_diagnosis_knowledge.mjs',
);

if (failures.length > 0) {
  console.error('[verify:ota-booking-diagnosis-knowledge] failed checks:');
  for (const failure of failures) {
    console.error('- ' + failure);
  }
  process.exit(1);
}

console.log('[verify:ota-booking-diagnosis-knowledge] all checks passed');
~~~

- [ ] **Step 2: Run the verifier and confirm the red state**

Run:

~~~powershell
node scripts/verify_ota_booking_diagnosis_knowledge.mjs
~~~

Expected: exit code 1. The failure list must include migration file missing, three diagnosis sections missing, and npm script missing. A syntax error or unhandled ENOENT is not an acceptable red state.

- [ ] **Step 3: Confirm only the intended new verifier exists**

Run:

~~~powershell
git status --short -- scripts/verify_ota_booking_diagnosis_knowledge.mjs
~~~

Expected:

~~~text
?? scripts/verify_ota_booking_diagnosis_knowledge.mjs
~~~

Do not commit the verifier while the complete focused contract is red.

### Task 2: Add the idempotent three-layer knowledge migration

**Files:**
- Create: database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql
- Verify: scripts/verify_ota_booking_diagnosis_knowledge.mjs

- [ ] **Step 1: Create the content-only SQL migration**

Create database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql with the following complete structure and content:

~~~sql
-- Seed OTA booking pace, conversion opportunity, and source-city loss diagnosis knowledge.
-- Content-only: no business fact table, collector, interface, page, or OTA writeback is added.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @ota_booking_diagnosis_unit_name := 'OTA预订节奏与增长诊断知识库';
SET @ota_booking_diagnosis_source := 'ota';
SET @ota_booking_diagnosis_description := '沉淀预订节奏与短窗口风险、流量增长但转化承接不足、客源城市损失贡献三项OTA渠道诊断知识，并保留数据缺口、派生估算和人工复核边界。';

INSERT INTO knowledge_units (name, source, status, description, tags, created_at, updated_at)
SELECT
  @ota_booking_diagnosis_unit_name,
  @ota_booking_diagnosis_source,
  'done',
  @ota_booking_diagnosis_description,
  JSON_ARRAY('OTA', '预订节奏', 'Pickup', 'Booking pace', '转化机会', '客源城市', '数据质量'),
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM knowledge_units
  WHERE name = @ota_booking_diagnosis_unit_name
    AND source = @ota_booking_diagnosis_source
);

UPDATE knowledge_units
SET
  status = 'done',
  description = @ota_booking_diagnosis_description,
  tags = JSON_ARRAY('OTA', '预订节奏', 'Pickup', 'Booking pace', '转化机会', '客源城市', '数据质量'),
  updated_at = NOW()
WHERE name = @ota_booking_diagnosis_unit_name
  AND source = @ota_booking_diagnosis_source;

SET @ota_booking_diagnosis_unit_id := (
  SELECT unit_id
  FROM knowledge_units
  WHERE name = @ota_booking_diagnosis_unit_name
    AND source = @ota_booking_diagnosis_source
  ORDER BY unit_id ASC
  LIMIT 1
);

DELETE FROM knowledge_chunks
WHERE unit_id = @ota_booking_diagnosis_unit_id
  AND type IN ('使用边界', '预订节奏诊断', '转化机会诊断', '客源城市诊断', 'AI行动边界');

INSERT INTO knowledge_chunks (unit_id, type, content, created_at)
SELECT
  @ota_booking_diagnosis_unit_id,
  '使用边界',
  JSON_OBJECT(
    'title', 'OTA诊断使用边界',
    'metric_scope', 'metric_scope=ota_channel',
    'scope_rule', '只有OTA数据时只能解释该渠道，不得升级为全酒店入住、客源或收入事实。',
    'missing_value_rule', '必要事实或分母缺失时返回 null + data_gap，不得返回0、旧值或默认值。',
    'fact_estimate_rule', '真实观测事实、公式派生值、假设和未知项必须分开；机会订单、机会间夜和收入影响均标记 derived_estimate。',
    'prohibited_inference', JSON_ARRAY(
      '历史已售间夜不能冒充 OTB 或 Pickup',
      '城市占比不能反推城市间夜',
      '竞品口径或竞品集不一致时不能计算机会损失',
      '不得直接输出因果结论'
    ),
    'gap_statuses', JSON_ARRAY(
      'on_books_snapshot_missing',
      'comparison_baseline_missing',
      'conversion_denominator_missing',
      'source_city_room_nights_missing',
      'competitor_caliber_mismatch',
      'small_base',
      'derived_estimate'
    )
  ),
  NOW()
WHERE @ota_booking_diagnosis_unit_id IS NOT NULL;

INSERT INTO knowledge_chunks (unit_id, type, content, created_at)
SELECT
  @ota_booking_diagnosis_unit_id,
  '预订节奏诊断',
  JSON_OBJECT(
    'goal', '回答未来入住窗口当前订了多少、最近增长多快、短期预订是否断层、距离目标还差多少。',
    'definitions', JSON_OBJECT(
      'otb', '指定未来入住窗口在观察时点仍有效的订单、间夜或收入。',
      'pickup', '相同入住窗口和业务口径下，两次真实OTB快照之间的变化。',
      'booking_pace', '针对未来入住日观察OTB随观察时间累积的速度。'
    ),
    'formulas', JSON_OBJECT(
      'pickup', 'current_on_books - previous_on_books',
      'net_pickup', 'new_valid_booked_room_nights - newly_cancelled_room_nights',
      'pace_index', 'current_otb / comparable_baseline_otb * 100',
      'booking_window_share', 'bucket_room_nights / total_otb_room_nights * 100',
      'target_gap', 'target_final_room_nights - current_net_otb_room_nights',
      'required_daily_pickup', 'target_gap / remaining_observation_days'
    ),
    'lead_time_buckets', JSON_ARRAY('0天', '1天', '2-3天', '4-7天', '8-14天', '15天及以上'),
    'bucket_rule', '提前期分桶必须完整且互斥；不得缺少中间分桶后仍描述为完整结构。',
    'required_facts', JSON_ARRAY(
      'system_hotel_id',
      'platform',
      'stay_date',
      'observed_at',
      'booking_date',
      'valid_order_status',
      'room_nights',
      'cancelled_at',
      'source_trace',
      'quality_status'
    ),
    'quality_gates', JSON_ARRAY(
      '没有两个可比较真实OTB快照时返回 on_books_snapshot_missing。',
      '没有目标、去年同提前期或近期可比基线时返回 comparison_baseline_missing。',
      '只有OTA数据时保持 metric_scope=ota_channel。',
      '历史已售间夜不能冒充 OTB 或 Pickup。'
    ),
    'candidate_actions', JSON_ARRAY('人工复核短期促销', '人工检查库存', '人工检查价格梯度')
  ),
  NOW()
WHERE @ota_booking_diagnosis_unit_id IS NOT NULL;

INSERT INTO knowledge_chunks (unit_id, type, content, created_at)
SELECT
  @ota_booking_diagnosis_unit_id,
  '转化机会诊断',
  JSON_OBJECT(
    'goal', '把UV增长但转化下降从描述性观点转成可量化机会损失和人工排查路径。',
    'formulas', JSON_OBJECT(
      'conversion_gap_pp', 'hotel_conversion_rate - comparable_conversion_rate',
      'potential_orders', 'eligible_uv * max(0, comparable_conversion_rate - hotel_conversion_rate)',
      'potential_room_nights', 'potential_orders * verified_avg_room_nights_per_order',
      'potential_revenue', 'potential_room_nights * aligned_ota_adr'
    ),
    'required_facts', JSON_ARRAY(
      'system_hotel_id',
      'platform',
      'data_window',
      'eligible_uv',
      'valid_orders',
      'room_nights',
      'aligned_ota_adr',
      'stable_competitor_set',
      'calculation_basis'
    ),
    'quality_gates', JSON_ARRAY(
      'UV、详情访客、填单人数和提交人数不得混成同一分母。',
      '转化分母缺失时返回 conversion_denominator_missing。',
      '竞品集或转化口径不一致时返回 competitor_caliber_mismatch。',
      '潜在订单、潜在间夜和潜在收入必须标记 derived_estimate。',
      '不得直接输出因果结论。'
    ),
    'candidate_checks', JSON_ARRAY('房型', '图片', '价格', '早餐', '退改', '评价', '库存', '支付确认链路')
  ),
  NOW()
WHERE @ota_booking_diagnosis_unit_id IS NOT NULL;

INSERT INTO knowledge_chunks (unit_id, type, content, created_at)
SELECT
  @ota_booking_diagnosis_unit_id,
  '客源城市诊断',
  JSON_OBJECT(
    'goal', '同时观察同比、绝对损失、收入影响、样本量和损失贡献，避免只按跌幅排序。',
    'formulas', JSON_OBJECT(
      'city_room_night_yoy', '(current_city_room_nights - baseline_city_room_nights) / baseline_city_room_nights * 100',
      'city_absolute_loss', 'max(0, baseline_city_room_nights - current_city_room_nights)',
      'loss_contribution', 'city_lost_room_nights / total_lost_room_nights * 100',
      'revenue_impact_estimate', 'city_lost_room_nights * aligned_city_or_hotel_adr'
    ),
    'required_facts', JSON_ARRAY(
      'system_hotel_id',
      'platform',
      'source_city',
      'stay_date_window',
      'observed_at',
      'valid_room_nights',
      'comparison_baseline',
      'source_trace',
      'quality_status'
    ),
    'quality_gates', JSON_ARRAY(
      '只有 source_city 和 distribution_share 时返回 source_city_room_nights_missing。',
      '城市占比不能反推城市间夜。',
      '基期为0或样本过小时返回 small_base，不输出同比百分比。',
      'TOP城市未覆盖全部客源时，不得描述为完整客源结构。',
      '收入影响只标记 derived_estimate。'
    ),
    'candidate_actions', JSON_ARRAY('人工评估城市定向投放', '人工评估交通或景区套餐', '人工评估异地客退改优化')
  ),
  NOW()
WHERE @ota_booking_diagnosis_unit_id IS NOT NULL;

INSERT INTO knowledge_chunks (unit_id, type, content, created_at)
SELECT
  @ota_booking_diagnosis_unit_id,
  'AI行动边界',
  JSON_OBJECT(
    'reasoning_order', JSON_ARRAY(
      '确认门店、平台、入住日期窗口、观察时点和metric_scope。',
      '确认必要事实、来源、分母和对比基线。',
      '分开展示事实、派生指标、假设和data_gap。',
      '质量门禁通过后才输出诊断。',
      '只生成待人工复核的候选动作。'
    ),
    'allowed_outputs', JSON_ARRAY('指标解释', '数据缺口', '风险提示', '人工排查项', '候选运营动作'),
    'forbidden_outputs', JSON_ARRAY(
      '不自动调价',
      '不自动投放',
      '不自动创建促销',
      '不自动写回 OTA',
      '不声称候选动作已经执行'
    )
  ),
  NOW()
WHERE @ota_booking_diagnosis_unit_id IS NOT NULL;

SET @ota_booking_diagnosis_category_name := 'OTA运营';
SET @ota_booking_diagnosis_category_description := 'OTA数据采集、渠道运营、点评、订单、流量和广告方法';

INSERT INTO knowledge_categories (
  hotel_id, parent_id, name, description, sort_order, is_enabled, create_time, update_time
)
SELECT
  0,
  0,
  @ota_booking_diagnosis_category_name,
  @ota_booking_diagnosis_category_description,
  20,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM knowledge_categories
  WHERE hotel_id = 0
    AND parent_id = 0
    AND name = @ota_booking_diagnosis_category_name
);

UPDATE knowledge_categories
SET
  description = @ota_booking_diagnosis_category_description,
  is_enabled = 1,
  update_time = NOW()
WHERE hotel_id = 0
  AND parent_id = 0
  AND name = @ota_booking_diagnosis_category_name;

SET @ota_booking_diagnosis_category_id := (
  SELECT id
  FROM knowledge_categories
  WHERE hotel_id = 0
    AND parent_id = 0
    AND name = @ota_booking_diagnosis_category_name
  ORDER BY id ASC
  LIMIT 1
);

SET @ota_booking_diagnosis_staff_title := @ota_booking_diagnosis_unit_name;
SET @ota_booking_diagnosis_staff_content := CONCAT(
  '# OTA预订节奏与增长诊断知识库', '\n\n',
  '## 使用边界', '\n',
  '- 本知识只解释OTA渠道，统一标记 metric_scope=ota_channel，不升级为全酒店事实。', '\n',
  '- 缺少必要事实或分母时返回 null + data_gap，不用0、旧值或默认值掩盖缺口。', '\n',
  '- 历史已售间夜不能冒充 OTB 或 Pickup；城市占比不能反推城市间夜。', '\n',
  '- 机会订单、机会间夜和收入影响都是 derived_estimate。', '\n\n',
  '## 预订节奏与短窗口风险', '\n',
  '- Pickup = current_on_books - previous_on_books。', '\n',
  '- 净Pickup = new_valid_booked_room_nights - newly_cancelled_room_nights。', '\n',
  '- 提前期分桶必须完整且互斥：0天、1天、2-3天、4-7天、8-14天、15天及以上。', '\n',
  '- 缺真实OTB快照时返回 on_books_snapshot_missing。', '\n\n',
  '## 流量增长但转化承接不足', '\n',
  '- 转化差距 = hotel_conversion_rate - comparable_conversion_rate。', '\n',
  '- 潜在订单 = eligible_uv * max(0, comparable_conversion_rate - hotel_conversion_rate)。', '\n',
  '- 转化分母或竞品口径不一致时不计算机会损失；不得直接输出因果结论。', '\n\n',
  '## 客源城市损失贡献', '\n',
  '- 绝对损失 = max(0, baseline_city_room_nights - current_city_room_nights)。', '\n',
  '- 损失贡献 = city_lost_room_nights / total_lost_room_nights * 100。', '\n',
  '- 只有城市占比时返回 source_city_room_nights_missing；小样本返回 small_base。', '\n\n',
  '## AI行动边界', '\n',
  '- 只输出待人工复核的排查项和候选动作。', '\n',
  '- 不自动调价、投放、创建促销或写回OTA，不声称候选动作已经执行。'
);

INSERT INTO knowledge_base (
  hotel_id, category_id, title, content, keywords, tags,
  sort_order, is_enabled, view_count, like_count, create_time, update_time
)
SELECT
  0,
  COALESCE(@ota_booking_diagnosis_category_id, 0),
  @ota_booking_diagnosis_staff_title,
  @ota_booking_diagnosis_staff_content,
  'OTA,预订节奏,OTB,Pickup,Booking pace,转化机会,客源城市,短窗口风险',
  JSON_ARRAY('OTA', '预订节奏', 'Pickup', '转化机会', '客源城市', '数据质量'),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM knowledge_base
  WHERE hotel_id = 0
    AND title = @ota_booking_diagnosis_staff_title
);

UPDATE knowledge_base
SET
  category_id = COALESCE(@ota_booking_diagnosis_category_id, 0),
  content = @ota_booking_diagnosis_staff_content,
  keywords = 'OTA,预订节奏,OTB,Pickup,Booking pace,转化机会,客源城市,短窗口风险',
  tags = JSON_ARRAY('OTA', '预订节奏', 'Pickup', '转化机会', '客源城市', '数据质量'),
  is_enabled = 1,
  update_time = NOW()
WHERE hotel_id = 0
  AND title = @ota_booking_diagnosis_staff_title;
~~~

- [ ] **Step 2: Run the focused verifier and confirm only integration checks remain red**

Run:

~~~powershell
node scripts/verify_ota_booking_diagnosis_knowledge.mjs
~~~

Expected: exit code 1. Migration checks pass. Remaining failures are limited to:

~~~text
- professional document exposes the three diagnosis sections
- npm script exposes the focused verifier
~~~

If any formula, bucket, quality-state, idempotency, credential-boundary, or content-only check still fails, fix the migration before proceeding.

- [ ] **Step 3: Run a targeted SQL risk scan**

Run:

~~~powershell
rg -n "CREATE TABLE|ALTER TABLE|DROP TABLE|online_daily_data|Cookie|password|token|手机号|身份证" database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql
~~~

Expected: no matches. The migration must remain content-only and contain no credentials or customer identity material.

### Task 3: Add the human-readable knowledge section and npm entry point

**Files:**
- Modify: docs/hotel_ota_metric_professional_knowledge.md
- Modify: package.json
- Verify: database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql
- Verify: scripts/verify_ota_booking_diagnosis_knowledge.mjs

- [ ] **Step 1: Insert the new documentation section before 平台私有指标**

Insert the following block after the existing 订单与库存指标 table and before the 平台私有指标 heading:

~~~markdown
## 三项高价值诊断知识

> 统一范围：以下诊断只在数据来自 OTA 时标记为 metric_scope=ota_channel。缺少必要事实或分母时返回 null + data_gap，不用 0、旧值或默认值代替；机会订单、机会间夜和收入影响均标记 derived_estimate。

### 1. 预订节奏与短窗口风险

| 项目 | 口径 |
| --- | --- |
| OTB | 指定未来入住窗口在观察时点仍有效的订单、间夜或收入 |
| Pickup | current_on_books - previous_on_books，必须使用相同入住窗口和业务口径 |
| 净 Pickup | new_valid_booked_room_nights - newly_cancelled_room_nights |
| Pace index | current_otb / comparable_baseline_otb * 100 |
| 目标缺口 | target_final_room_nights - current_net_otb_room_nights |
| 每日所需 Pickup | target_gap / remaining_observation_days |

提前期分桶必须完整且互斥：0天、1天、2-3天、4-7天、8-14天、15天及以上。没有两个可比较的真实 OTB 快照时返回 on_books_snapshot_missing；历史已售间夜不能冒充 OTB 或 Pickup。

### 2. 流量增长但转化承接不足

| 项目 | 口径 |
| --- | --- |
| 转化差距 | hotel_conversion_rate - comparable_conversion_rate，按百分点表达 |
| 潜在订单机会 | eligible_uv * max(0, comparable_conversion_rate - hotel_conversion_rate) |
| 潜在间夜机会 | potential_orders * verified_avg_room_nights_per_order |
| 潜在收入机会 | potential_room_nights * aligned_ota_adr |

UV、详情访客、填单人数和提交人数不得混成同一分母。分母缺失返回 conversion_denominator_missing；竞品集或转化口径不一致返回 competitor_caliber_mismatch。AI 只能提示核查房型、图片、价格、早餐、退改、评价、库存和支付确认链路，不得直接输出因果结论。

### 3. 客源城市损失贡献

| 项目 | 口径 |
| --- | --- |
| 城市间夜同比 | (current_city_room_nights - baseline_city_room_nights) / baseline_city_room_nights * 100 |
| 城市绝对损失 | max(0, baseline_city_room_nights - current_city_room_nights) |
| 损失贡献率 | city_lost_room_nights / total_lost_room_nights * 100 |
| 收入影响估算 | city_lost_room_nights * aligned_city_or_hotel_adr |

只有 source_city 和 distribution_share 时返回 source_city_room_nights_missing，城市占比不能反推城市间夜。基期为 0 或样本过小时返回 small_base，不输出同比百分比；TOP 城市未覆盖全部客源时，不得描述为完整客源结构。

### AI行动边界

- 先确认门店、平台、入住日期窗口、观察时点、来源和口径，再做计算。
- 分开展示事实、派生指标、假设与数据缺口。
- 只生成待人工复核的候选动作。
- 不自动调价、投放、创建促销或写回 OTA，不声称候选动作已经执行。
~~~

- [ ] **Step 2: Register the focused npm command**

In package.json, add the new entry immediately after verify:ota-revenue-metrics-smoke:

~~~json
"verify:ota-revenue-metrics-smoke": "C:\\xampp\\php\\php.exe scripts\\verify_ota_revenue_metrics_smoke.php",
"verify:ota-booking-diagnosis-knowledge": "node scripts/verify_ota_booking_diagnosis_knowledge.mjs",
"verify:taste-coverage": "node scripts/verify_taste_page_coverage.mjs",
~~~

- [ ] **Step 3: Run the complete focused verifier**

Run:

~~~powershell
npm.cmd run verify:ota-booking-diagnosis-knowledge
~~~

Expected:

~~~text
[verify:ota-booking-diagnosis-knowledge] all checks passed
~~~

- [ ] **Step 4: Validate JSON, formatting, and exact scope**

Run:

~~~powershell
node -e "JSON.parse(require('node:fs').readFileSync('package.json','utf8')); console.log('package.json valid')"
git diff --check -- database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql docs/hotel_ota_metric_professional_knowledge.md scripts/verify_ota_booking_diagnosis_knowledge.mjs package.json
git diff --name-only -- database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql docs/hotel_ota_metric_professional_knowledge.md scripts/verify_ota_booking_diagnosis_knowledge.mjs package.json
~~~

Expected:

~~~text
package.json valid
database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql
docs/hotel_ota_metric_professional_knowledge.md
package.json
scripts/verify_ota_booking_diagnosis_knowledge.mjs
~~~

git diff --check must print nothing.

- [ ] **Step 5: Commit only the four implementation files**

Run:

~~~powershell
git add -- database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql docs/hotel_ota_metric_professional_knowledge.md scripts/verify_ota_booking_diagnosis_knowledge.mjs package.json
git diff --cached --name-only
~~~

Expected: exactly the same four implementation files and no pre-existing user changes.

Then run:

~~~powershell
git commit -m "feat: 补强OTA预订诊断知识库"
~~~

### Task 4: Apply and read back the local knowledge data when MySQL is available

**Files:**
- Execute: database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql
- Read only after execution: knowledge_units、knowledge_chunks、knowledge_base
- Do not edit any repository file in this task.

- [ ] **Step 1: Check the MySQL client without starting or changing services**

Run:

~~~powershell
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
if (-not (Test-Path -LiteralPath $mysql)) { throw 'mysql client unavailable' }
& $mysql --version
~~~

Expected: a MySQL/MariaDB client version. If unavailable, stop this task and record “迁移文件已验证、数据库未应用”; do not claim database completion.

- [ ] **Step 2: Apply the migration using current environment credentials without printing the password**

Use the current shell DB_HOST、DB_PORT、DB_NAME、DB_USER、DB_PASS when present, otherwise use the repository defaults 127.0.0.1、3306、hotelx、root and empty password:

~~~powershell
$dbHost = if ($env:DB_HOST) { $env:DB_HOST } else { '127.0.0.1' }
$dbPort = if ($env:DB_PORT) { $env:DB_PORT } else { '3306' }
$dbName = if ($env:DB_NAME) { $env:DB_NAME } else { 'hotelx' }
$dbUser = if ($env:DB_USER) { $env:DB_USER } else { 'root' }
$previousMysqlPwd = $env:MYSQL_PWD
try {
  if ($env:DB_PASS) { $env:MYSQL_PWD = $env:DB_PASS }
  Get-Content -Raw -Encoding utf8 'database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql' |
    & $mysql -h $dbHost -P $dbPort -u $dbUser --default-character-set=utf8mb4 $dbName
  if ($LASTEXITCODE -ne 0) { throw 'knowledge migration failed' }
} finally {
  $env:MYSQL_PWD = $previousMysqlPwd
}
~~~

Expected: exit code 0 and no SQL error. If authentication or service connection fails, report the real error category and retain the truthful state “数据库未应用”.

- [ ] **Step 3: Read back the 1/5/1 persistence contract**

Run:

~~~powershell
$readbackSql = @"
SELECT COUNT(*) FROM knowledge_units
WHERE name = 'OTA预订节奏与增长诊断知识库' AND source = 'ota';
SELECT COUNT(DISTINCT kc.type)
FROM knowledge_chunks kc
JOIN knowledge_units ku ON ku.unit_id = kc.unit_id
WHERE ku.name = 'OTA预订节奏与增长诊断知识库'
  AND ku.source = 'ota'
  AND kc.type IN ('使用边界','预订节奏诊断','转化机会诊断','客源城市诊断','AI行动边界');
SELECT COUNT(*) FROM knowledge_base
WHERE hotel_id = 0 AND title = 'OTA预订节奏与增长诊断知识库';
"@
$previousMysqlPwd = $env:MYSQL_PWD
try {
  if ($env:DB_PASS) { $env:MYSQL_PWD = $env:DB_PASS }
  $readbackSql |
    & $mysql -N -B -h $dbHost -P $dbPort -u $dbUser --default-character-set=utf8mb4 $dbName
  if ($LASTEXITCODE -ne 0) { throw 'knowledge readback failed' }
} finally {
  $env:MYSQL_PWD = $previousMysqlPwd
}
~~~

Expected:

~~~text
1
5
1
~~~

- [ ] **Step 4: Prove idempotency with a second apply and identical readback**

Repeat Step 2 once, then repeat Step 3.

Expected again:

~~~text
1
5
1
~~~

Any count other than 1/5/1 is a failure; do not delete unrelated knowledge rows to force the result.

### Task 5: Final focused verification and handoff

**Files:**
- Verify only the four implementation files and the implementation commit.

- [ ] **Step 1: Re-run the static contract from the committed tree**

Run:

~~~powershell
npm.cmd run verify:ota-booking-diagnosis-knowledge
git diff --check -- database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql docs/hotel_ota_metric_professional_knowledge.md scripts/verify_ota_booking_diagnosis_knowledge.mjs package.json
~~~

Expected: verifier passes; diff check is silent.

- [ ] **Step 2: Confirm the commit contains no unrelated dirty-worktree files**

Run:

~~~powershell
git show --stat --oneline --name-only HEAD
git status --short -- database/migrations/20260712_seed_ota_booking_diagnosis_knowledge.sql docs/hotel_ota_metric_professional_knowledge.md scripts/verify_ota_booking_diagnosis_knowledge.mjs package.json
~~~

Expected: HEAD contains exactly the four implementation files; scoped status is clean. Other pre-existing worktree changes may remain and must not be altered or reported as this feature's work.

- [ ] **Step 3: Report the truthful completion boundary**

Report:

- 已完成：三项诊断知识、五类知识块、员工知识文章、文档章节、验证命令。
- 已验证：focused verifier、package JSON、diff check、commit scope。
- 数据库状态二选一：
  - 已应用且两次回读均为 1/5/1；或
  - 迁移文件已验证、数据库未应用，并附连接失败类别。
- 明确未包含：真实 OTB/城市间夜采集、业务事实表、页面、自动调价、自动投放、促销创建、OTA 写回。
