-- Remove explicitly retired knowledge artifacts and give every retained
-- decision knowledge unit a versioned known-known / known-unknown profile.
-- Deletion is bounded by exact unit identity plus the prior quarantine reason.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `knowledge_units`
  ADD COLUMN IF NOT EXISTS `known_knowns`
    JSON DEFAULT NULL
    COMMENT 'Reviewed facts, methods and boundaries that are currently known'
    AFTER `reviewed_at`,
  ADD COLUMN IF NOT EXISTS `known_unknowns`
    JSON DEFAULT NULL
    COMMENT 'Explicit unresolved facts, evidence gaps and unimplemented capabilities'
    AFTER `known_knowns`,
  ADD COLUMN IF NOT EXISTS `truth_profile_version`
    varchar(32) DEFAULT NULL
    COMMENT 'Version of the known-known / known-unknown review'
    AFTER `known_unknowns`;

DROP TEMPORARY TABLE IF EXISTS `tmp_knowledge_truth_profiles`;
CREATE TEMPORARY TABLE `tmp_knowledge_truth_profiles` (
  `unit_name` varchar(255) NOT NULL,
  `unit_source` varchar(50) NOT NULL,
  `known_knowns` longtext NOT NULL,
  `known_unknowns` longtext NOT NULL,
  PRIMARY KEY (`unit_name`, `unit_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_knowledge_truth_profiles`
  (`unit_name`, `unit_source`, `known_knowns`, `known_unknowns`)
VALUES
  (
    '美团 eBooking 浏览器自动化采集方法',
    'meituan',
    JSON_ARRAY(
      '采集只允许在合法授权账号、正确门店绑定和独立浏览器Profile范围内执行',
      '优先读取页面同源XHR或fetch结构化响应，并保留平台、门店、业务日期、采集时间、质量状态与数据库回读'
    ),
    JSON_ARRAY(
      '当前美团登录态、门店绑定、页面接口和字段选择器是否仍有效',
      '目标日期字段是否已真实采集、保存并完成同门店数据库回读'
    )
  ),
  (
    'OTA平台可确认字段与假设字段清单',
    'ota',
    JSON_ARRAY(
      '字段清单只用于采集优先级和字段映射，未核验字段不得直接成为经营事实',
      '平台字段必须由当前官方文档、已授权后台或真实接口响应确认'
    ),
    JSON_ARRAY(
      '携程和美团当前版本实际可返回的字段名、权限和响应结构',
      '目标门店与目标日期的字段完整度、保存状态和质量状态'
    )
  ),
  (
    'OTA标准指标与推荐公式清单',
    'ota',
    JSON_ARRAY(
      '指标必须声明渠道范围、分子、分母、单位、时间窗口和缺失值处理',
      '分母缺失或为0时返回不可计算，OTA指标不能扩大为全酒店经营指标'
    ),
    JSON_ARRAY(
      '当前平台响应是否具备每个公式所需的原始字段',
      '全酒店营收、成本、线下渠道和利润口径是否已取得并核验'
    )
  ),
  (
    'OTA数据产品矩阵',
    'ota',
    JSON_ARRAY(
      '产品矩阵是字段优先级和交付路线参考，不代表功能、接口或表结构已经存在',
      '任何产品输出进入决策前都必须绑定真实来源、门店、日期和质量状态'
    ),
    JSON_ARRAY(
      '矩阵中的各项产品在当前运行时是否已经实现并可被用户实际操作',
      '各产品的数据覆盖、实际使用频率和收益价值是否已验证'
    )
  ),
  (
    'OTA数据分层架构与治理规则',
    'ota',
    JSON_ARRAY(
      '来源身份、业务日期、原始层保留、标准化、去重、质量状态和回读是稳定治理原则',
      '资料描述的是目标架构蓝图，不等于当前数据库已具备完整ODS、DWD、DWS和模型层'
    ),
    JSON_ARRAY(
      '当前各数据层、字段血缘和质量监控的实际覆盖范围',
      '跨平台用户去重、归因、特征层和模型层是否已有真实运行证据'
    )
  ),
  (
    'OTA手动与自动获取策略',
    'ota',
    JSON_ARRAY(
      '手动与自动获取是两条独立路径，携程与美团也必须按平台分别验证',
      '采集失败、登录失效、字段缺失和未保存状态必须如实回显'
    ),
    JSON_ARRAY(
      '当前账号会话、接口端点、授权范围和平台限制是否允许自动获取',
      '自动路径在目标门店和目标日期的稳定性、幂等保存与回读是否已验证'
    )
  ),
  (
    '房型经营分析报告解读话术库',
    'room_type_analysis_communication',
    JSON_ARRAY(
      '解读必须先统一日期、范围和口径，再联读出租率、ADR、RevPAR、渠道与房型角色',
      '话术库是沟通框架，不包含当前门店事实，也不能把OTA局部数据扩大为全酒店结论'
    ),
    JSON_ARRAY(
      '当前门店目标日期的真实经营指标、来源、质量和保存回读',
      '具体话术是否适用于当前房型、渠道、客群和价格情境'
    )
  ),
  (
    '收益运营诊断与建议知识底座',
    'revenue_operations_decision_support',
    JSON_ARRAY(
      '诊断先统一门店、日期、范围和指标口径，再拆销量、价格、渠道和房型贡献',
      '案例数值必须通过显式case_key读取，建议不等于自动OTA写入或已执行动作'
    ),
    JSON_ARRAY(
      '当前门店的真实订单、收入、成本、目标、竞对和执行结果是否齐全',
      '案例方法在当前门店条件下的适用性和实际收益效果'
    )
  ),
  (
    'OTA公开页诊断方法库',
    'ota_public_page_diagnosis_reference',
    JSON_ARRAY(
      '公开页只能证明OTA渠道当时可见的页面状态，不能证明后台算法、真实库存或全酒店经营',
      '诊断结果必须保留页面日期、来源、可见字段、缺失字段和质量状态'
    ),
    JSON_ARRAY(
      '当前目标页面的实时展示、字段完整性和登录差异',
      '平台隐藏排序逻辑、真实流量分配和页面优化后的因果效果'
    )
  ),
  (
    'OTA运营SOP参考模板库',
    'ota_operation_sop_reference',
    JSON_ARRAY(
      'SOP可复用的是角色、触发条件、输入、动作、证据、异常和复盘结构',
      '平台规则和经验阈值必须版本化并在执行当天复核'
    ),
    JSON_ARRAY(
      '当前平台规则、活动条件和经验阈值是否仍有效',
      '模板在目标门店的适用性、执行证据和实际经营效果'
    )
  ),
  (
    'OTA商圈竞争脉冲方法库',
    'ota_competition_pulse_reference',
    JSON_ARRAY(
      '竞争观察必须同时记录采集时间与入住日期，并展示样本量、分布和质量状态',
      'OTA可见价格与售罄状态不能推导真实库存、出租率、ADR、RevPAR或利润'
    ),
    JSON_ARRAY(
      '当前商圈样本、重点竞对和价格可售数据的准确性与完整性',
      '竞争变化的真实原因、隐藏库存和对本店收益的因果影响'
    )
  ),
  (
    'OTA每日经营台账与晨报闭环',
    'ota_daily_operations_ledger_reference',
    JSON_ARRAY(
      '历史表格可确认记录结构、漏斗顺序、晨报节奏和数据到动作的复盘方法',
      '历史数值、平台来源和门店身份尚未核验，不能替代当前事实'
    ),
    JSON_ARRAY(
      '历史台账每行的平台、门店、业务日期、原始来源和保存回读',
      '目标日期携程与美团的真实指标、缺失原因和后续动作效果'
    )
  ),
  (
    '经营目标差值检测与节奏判断',
    'operating_target_delta_detection_reference',
    JSON_ARRAY(
      'PmsFactReconciliationService已对同租户、同门店、同经营日、同范围、同来源的已验证相邻快照计算gap与delta',
      '首条快照只建立基线；取消累计缺失时保持null，不得把净拾取称为新增预订'
    ),
    JSON_ARRAY(
      '同店历史节奏学习、累计取消采集、完整目标差距收窄扩大引擎和提醒状态机尚未完成',
      '当前门店目标日期事实仍需真实采集、保存和数据库回读后才能判断'
    )
  ),
  (
    '流量经营与运营管理决策金句库',
    'revenue_operations_decision_support',
    JSON_ARRAY(
      '金句是指标解释、决策结构和保护线，不是当前门店事实或执行指令',
      '价格与流量动作必须绑定基线、目标、保护线、止损、回滚和复盘'
    ),
    JSON_ARRAY(
      '当前门店指标是否满足某条金句的触发条件',
      '截图中的酒店、排名、指数、金额和R级门槛是否适用于当前门店'
    )
  );

UPDATE `knowledge_units` AS `ku`
JOIN `tmp_knowledge_truth_profiles` AS `profile`
  ON `profile`.`unit_name` = `ku`.`name`
  AND `profile`.`unit_source` = `ku`.`source`
SET
  `ku`.`known_knowns` = `profile`.`known_knowns`,
  `ku`.`known_unknowns` = `profile`.`known_unknowns`,
  `ku`.`truth_profile_version` = '2026-07-29.1',
  `ku`.`reviewed_at` = '2026-07-29 00:00:00'
WHERE `ku`.`lifecycle_status` = 'active';

DROP TEMPORARY TABLE IF EXISTS `tmp_knowledge_prune_targets`;
CREATE TEMPORARY TABLE `tmp_knowledge_prune_targets` (
  `unit_name` varchar(255) NOT NULL,
  `unit_source` varchar(50) NOT NULL,
  `lifecycle_reason` varchar(255) NOT NULL,
  PRIMARY KEY (`unit_name`, `unit_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_knowledge_prune_targets`
  (`unit_name`, `unit_source`, `lifecycle_reason`)
VALUES
  (
    '酒店收益管理研究中心 - 取消率预测',
    'revenue_research',
    '旧版收益研究快照缺少现行readiness、decision_ready与执行门禁，等待按当前合同重新生成'
  ),
  (
    '酒店收益管理研究中心 - 渠道归因与增量评估',
    'revenue_research',
    '旧版收益研究快照缺少现行readiness、decision_ready与执行门禁，等待按当前合同重新生成'
  ),
  (
    '酒店收益管理研究中心 - 客群细分',
    'revenue_research',
    '旧版收益研究快照缺少现行readiness、decision_ready与执行门禁，等待按当前合同重新生成'
  ),
  (
    '酒店收益管理研究中心 - LTV 预测',
    'revenue_research',
    '旧版收益研究快照缺少现行readiness、decision_ready与执行门禁，等待按当前合同重新生成'
  ),
  (
    '酒店收益管理研究中心 - 点评主题与服务缺口识别',
    'revenue_research',
    '旧版收益研究快照缺少现行readiness、decision_ready与执行门禁，等待按当前合同重新生成'
  ),
  (
    '酒店收益管理研究中心 - 异常检测',
    'revenue_research',
    '旧版收益研究快照缺少现行readiness、decision_ready与执行门禁，等待按当前合同重新生成'
  ),
  (
    '知识蒸馏训练结果 - 知识蒸馏训练 - 2026-05-20 19:58',
    'ml_distillation',
    'synthetic训练产物且运行时checkpoint已失效，不作为经营知识'
  );

DELETE `kc`
FROM `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
JOIN `tmp_knowledge_prune_targets` AS `target`
  ON `target`.`unit_name` = `ku`.`name`
  AND `target`.`unit_source` = `ku`.`source`
  AND `target`.`lifecycle_reason` = `ku`.`lifecycle_reason`
WHERE `ku`.`lifecycle_status` = 'quarantined';

DELETE `ku`
FROM `knowledge_units` AS `ku`
JOIN `tmp_knowledge_prune_targets` AS `target`
  ON `target`.`unit_name` = `ku`.`name`
  AND `target`.`unit_source` = `ku`.`source`
  AND `target`.`lifecycle_reason` = `ku`.`lifecycle_reason`
WHERE `ku`.`lifecycle_status` = 'quarantined';

DELETE `kc`
FROM `knowledge_chunks` AS `kc`
JOIN `knowledge_units` AS `ku` ON `ku`.`unit_id` = `kc`.`unit_id`
WHERE `ku`.`source` = 'meituan'
  AND `ku`.`lifecycle_status` = 'active'
  AND JSON_VALID(`kc`.`content`)
  AND JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.lifecycle_status')) = 'quarantined'
  AND JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.lifecycle_reason')) = 'empty_legacy_experience_placeholder'
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.raw')), '') = ''
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`kc`.`content`, '$.distilled_experience')), '') = '';

DROP TEMPORARY TABLE IF EXISTS `tmp_knowledge_prune_targets`;
DROP TEMPORARY TABLE IF EXISTS `tmp_knowledge_truth_profiles`;
