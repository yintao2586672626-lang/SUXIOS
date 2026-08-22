-- Absorb three user-provided hotel self-service SOP documents as a guarded
-- global reference. Document statuses such as READY/active/complete remain
-- source claims; they do not become current-hotel policy, legal truth,
-- operating facts, task authority, or external-write authorization.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @self_service_version := '2026-08-16.1';
SET @self_service_reviewed_at := '2026-08-16 00:00:00';
SET @self_service_review_due_at := '2027-02-12 00:00:00';
SET @self_service_seed_owner := 'suxios.hotel_self_service_sop_reference';
SET @self_service_unit_name := '酒店自助服务知识模型 v0.1（历史SOP参考）';
SET @self_service_source := 'hotel_service_operations_reference';
SET @self_service_index_sha256 := 'B9EBD8FA76BA67632431914BCE29363AADAD809207B9BD7F8D5F5308834111AF';
SET @self_service_boundary_sha256 := 'A15D215083911EE4686FF7D604486AFB26AD2ECED87A06A89FE51435B73CB043';
SET @self_service_report_sha256 := '176F54192094541D93F4B0702867800B74CDEE193E365F016C4EE7FBF2088DB0';
SET @self_service_description := '从用户提供的酒店自助服务知识模型索引、历史SOP跨类型调用边界和蒸馏处理报告中提炼的跨酒店服务方法参考。它可用于知识检索、培训草案、检查表和待补问题，不是任一酒店的现行制度、法规、服务效果或执行参数，也不授权自动任务、服务承诺、OTA/PMS写入或外发。';
SET @self_service_manifest := JSON_OBJECT(
  'material_type', 'user_provided_markdown_documents',
  'source_batch', '2026-08-11',
  'observed_at', '2026-08-16',
  'verification_status', 'user_provided_reference_only',
  'document_count', 3,
  'documents', JSON_ARRAY(
    JSON_OBJECT('file_name', '索引_酒店自助服务知识模型_v0.1.md', 'sha256', @self_service_index_sha256),
    JSON_OBJECT('file_name', '规则_历史酒店SOP跨类型调用边界_v0.1.md', 'sha256', @self_service_boundary_sha256),
    JSON_OBJECT('file_name', '2026-08-11_酒店自助服务SOP深度蒸馏处理报告_v0.1.md', 'sha256', @self_service_report_sha256)
  ),
  'source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands',
  'supporting_source_files_status', 'not_independently_provided_or_verified',
  'reuse_mode', 'guarded_knowledge_reference'
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @self_service_unit_name,
  @self_service_source,
  'done',
  @self_service_description,
  JSON_ARRAY('酒店服务运营', '自助服务', '历史SOP', '跨酒店适配', '宾客旅程', '风险边界', 'reference_only'),
  0,
  'active',
  'user_provided_historical_sop_distilled_as_reference_only',
  @self_service_reviewed_at,
  @self_service_review_due_at,
  JSON_ARRAY(
    '索引文档给出服务六环、服务三层、酒店适配八维、宾客六阶段和开业七门禁。',
    '跨类型边界文档区分来源事实、专业判断、行动建议和资料未提供，并列出允许迁移与禁止直接迁移项。',
    '三份材料都明确历史SOP不能替代酒店当期制度、当地法规、当前能力与管理者审批。'
  ),
  JSON_ARRAY(
    '处理报告引用的19份物理文件、17份唯一来源、802页PDF、104页OCR和12份正式笔记未在本任务独立复核。',
    '任一酒店当前客群、房量、岗位班次、设施、系统、供应链、服务参数和制度均未绑定。',
    '食品、消防、治安、税务、劳动、个人信息、医疗和儿童服务的现行规则未由本材料证明。',
    '服务使用率、履约率、成本、投诉、满意度、复购和收入效果数据未提供。'
  ),
  @self_service_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @self_service_unit_name AND `source` = @self_service_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @self_service_description,
  `tags` = JSON_ARRAY('酒店服务运营', '自助服务', '历史SOP', '跨酒店适配', '宾客旅程', '风险边界', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_historical_sop_distilled_as_reference_only',
  `reviewed_at` = @self_service_reviewed_at,
  `review_due_at` = @self_service_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '索引文档给出服务六环、服务三层、酒店适配八维、宾客六阶段和开业七门禁。',
    '跨类型边界文档区分来源事实、专业判断、行动建议和资料未提供，并列出允许迁移与禁止直接迁移项。',
    '三份材料都明确历史SOP不能替代酒店当期制度、当地法规、当前能力与管理者审批。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '处理报告引用的19份物理文件、17份唯一来源、802页PDF、104页OCR和12份正式笔记未在本任务独立复核。',
    '任一酒店当前客群、房量、岗位班次、设施、系统、供应链、服务参数和制度均未绑定。',
    '食品、消防、治安、税务、劳动、个人信息、医疗和儿童服务的现行规则未由本材料证明。',
    '服务使用率、履约率、成本、投诉、满意度、复购和收入效果数据未提供。'
  ),
  `truth_profile_version` = @self_service_version,
  `updated_at` = NOW()
WHERE `name` = @self_service_unit_name AND `source` = @self_service_source;

SET @self_service_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @self_service_unit_name AND `source` = @self_service_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_hotel_self_service_sop_chunks`;
CREATE TEMPORARY TABLE `tmp_hotel_self_service_sop_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_hotel_self_service_sop_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_hotel_self_service_sop_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @self_service_unit_id, 'hotel_self_service_model_index_reference', JSON_OBJECT(
  'scope', 'global_hotel_service_method_reference',
  'evidence_level', 'user_provided_historical_sop_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://索引_酒店自助服务知识模型_v0.1.md#sha256=', @self_service_index_sha256)),
  'service_loop', JSON_ARRAY('需求识别', '资源确认', '任务分派', '服务交付', '感知确认', '回收复盘'),
  'service_layers', JSON_ARRAY('核心必备', '场景增强', '特色定制'),
  'adaptation_dimensions', JSON_ARRAY('定位', '客群', '规模', '人员', '设施', '系统', '供应链', '风险'),
  'guest_journey', JSON_ARRAY('预订后', '预到', '到店', '入住或住中', '离店', '离店后'),
  'opening_gates', JSON_ARRAY('合法安全', '工程环境', '产品客房', '人员能力', '物资供应', '系统数据', '模拟批准'),
  'minimum_inputs', JSON_ARRAY('酒店定位与房量', '主要客群', '设施与服务', '岗位班次与人员能力', 'PMS工单库存客史系统能力', '目标旅程阶段或经营问题', '本店制度与当前授权'),
  'missing_input_rule', '信息不足时只输出核心必备层和待补问题，不补造酒店条件'
), 0, NOW()
WHERE @self_service_unit_id IS NOT NULL;

INSERT INTO `tmp_hotel_self_service_sop_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @self_service_unit_id, 'historical_sop_cross_type_boundary', JSON_OBJECT(
  'scope', 'global_hotel_service_method_reference',
  'evidence_level', 'user_provided_historical_sop_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://规则_历史酒店SOP跨类型调用边界_v0.1.md#sha256=', @self_service_boundary_sha256)),
  'evidence_layers', JSON_ARRAY('来源事实', '专业判断', '行动建议', '资料未提供'),
  'allowed_migrations', JSON_ARRAY('服务需求分类与宾客旅程触点', '岗位责任交接留痕升级与复盘结构', '服务产品卡字段结构', '清洁库存回收消毒客人确认等闭环原理', '服务分层授权治理与案例复盘方法'),
  'forbidden_direct_migrations', JSON_ARRAY('品牌门店个人客户名称', '旧系统名称按钮字段界面操作', '旧价格折扣积分会员等级赔付授权额度', '超订比例早餐数量库存基数清洁时长考核扣分', '罚款扣薪辞退等历史用工条款', '旧税务治安消防食安隐私法律流程', '未经独立复核的业绩排名竞争结论'),
  'risk_routes', JSON_ARRAY('食品与过敏', '儿童与长者', '医疗与健康', '隐私与身份', '劳动与绩效', '税务与治安', '收益与库存'),
  'failure_stop_rule', '酒店类型客群责任岗位或当前制度法规无法确认时，不生成可直接执行SOP，只输出缺口和核验清单',
  'output_sections', JSON_ARRAY('适用对象', '已知事实', '待补信息', '推荐服务层级', '岗位动作', '风险与停止条件', '试运行与复盘指标', '来源锚点')
), 0, NOW()
WHERE @self_service_unit_id IS NOT NULL;

INSERT INTO `tmp_hotel_self_service_sop_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @self_service_unit_id, 'hotel_self_service_distillation_report_limits', JSON_OBJECT(
  'scope', 'global_hotel_service_method_reference',
  'evidence_level', 'user_provided_historical_sop_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://2026-08-11_酒店自助服务SOP深度蒸馏处理报告_v0.1.md#sha256=', @self_service_report_sha256)),
  'document_claims_not_independently_verified', JSON_OBJECT('physical_files', 19, 'unique_sources', 17, 'duplicates', 2, 'pdf_pages', 802, 'ocr_pages', 104, 'formal_notes', 12),
  'verified_in_this_absorption', JSON_ARRAY('三份当前提供文件已读取', '三份当前提供文件SHA256已计算', '可见结构与边界已人工核对'),
  'not_verified_in_this_absorption', JSON_ARRAY('报告引用的原始19份文件', 'OCR输出与失败率', '12份正式笔记及其断链扫描', '140条金句与释义卡', '历史来源页码锚点'),
  'effect_limit', '没有服务使用率履约率成本投诉满意度复购或收入数据，不能证明经营改善',
  'hkos_limit', '本材料不能证明已经进入稳定HKOS节点库或模型运行环境'
), 0, NOW()
WHERE @self_service_unit_id IS NOT NULL;

UPDATE `tmp_hotel_self_service_sop_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('hotel_self_service_sop:', `type`),
  '$.content_type', 'historical_sop_reference',
  '$.module_id', 'hotel_self_service_sop_reference',
  '$.platforms', JSON_ARRAY(),
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'front_office_manager', 'housekeeping_manager', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('knowledge_search', 'service_sop_draft', 'training_checklist', 'cross_hotel_adaptation_review'),
  '$.source_manifest', JSON_EXTRACT(@self_service_manifest, '$'),
  '$.reviewed_at', @self_service_reviewed_at,
  '$.review_due_at', @self_service_review_due_at,
  '$.review_interval_days', 180,
  '$.freshness_policy', 'reference_only_until_current_hotel_policy_capability_and_legal_verification',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_verified_for_current_hotel',
  '$.allowed_uses', JSON_ARRAY('knowledge_search', 'training_draft', 'checklist_draft', 'missing_information_questions', 'cross_hotel_adaptation_review'),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'current_policy_claim', 'current_legal_rule', 'operation_task_creation', 'operation_execution', 'automatic_service_promise', 'automatic_pricing', 'automatic_inventory_change', 'automatic_ota_write', 'automatic_pms_write', 'external_message'),
  '$.seed_owner', @self_service_seed_owner,
  '$.seed_key', CONCAT('hotel_self_service_sop:', `type`),
  '$.seed_version', @self_service_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_hotel_self_service_sop_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_hotel_self_service_sop_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_hotel_self_service_sop_chunks`;

SET @self_service_staff_content := CONCAT(
  '# 酒店自助服务知识模型 v0.1（历史SOP参考）', '\n\n',
  '## 可用范围', '\n',
  '用于检索服务六环、三层服务、酒店适配、宾客旅程、岗位动作字段、跨酒店迁移和风险核验清单。', '\n\n',
  '## 使用边界', '\n',
  '这是三份用户提供Markdown蒸馏出的跨酒店参考，不是本店现行制度、法规、服务参数或效果证明。缺少酒店画像、当前能力和制度时，只输出核心层与待补问题。', '\n\n',
  '## 禁止', '\n',
  '不得照搬品牌、旧系统、旧价格折扣、赔付额度、超订比例、劳动处罚或旧法规条款；不得自动创建任务、承诺服务、改价、改库存、写OTA/PMS或外发。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @self_service_unit_name, @self_service_staff_content,
  '酒店服务,自助服务,SOP,前厅,客房,早餐,宾客旅程,跨酒店适配,服务分层,风险边界',
  JSON_ARRAY('酒店服务运营', '历史SOP', '跨酒店适配', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @self_service_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @self_service_staff_content,
  `keywords` = '酒店服务,自助服务,SOP,前厅,客房,早餐,宾客旅程,跨酒店适配,服务分层,风险边界',
  `tags` = JSON_ARRAY('酒店服务运营', '历史SOP', '跨酒店适配', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @self_service_unit_name;
