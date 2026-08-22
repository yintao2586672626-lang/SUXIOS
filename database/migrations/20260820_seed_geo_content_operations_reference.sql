-- Absorb six user-provided hotel GEO workbooks/manuals as one guarded global
-- knowledge unit. Workbook defaults and document instructions are reference
-- material, not current-hotel facts, approved plans, or publication authority.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @geo_content_version := '2026-08-20.1';
SET @geo_content_reviewed_at := '2026-08-20 00:00:00';
SET @geo_content_review_due_at := '2027-02-16 00:00:00';
SET @geo_content_seed_owner := 'suxios.geo_content_operations_reference';
SET @geo_content_unit_name := '酒店GEO内容运营与审核门禁 v1.0（用户资料参考）';
SET @geo_content_source := 'geo_content_operations_reference';

SET @geo_information_sha256 := '6815D28084DBF2784ACE4C800B4E38BA3FC148E3F4B6DBE96D038D9BC3D9363C';
SET @geo_review_sha256 := 'B94427ADEA121B8FAD77525F9DA253F4C90490F24BF95A80B74B0F99055499C6';
SET @geo_annual_plan_sha256 := '1D8009ED9677227FBA665E3E4C80722B7C44A41010FFF2FA4352AD9C285170DB';
SET @geo_consultant_manual_sha256 := 'CAE0E787C5091551FE4EB6106D24D4B6E44C2CE17C81F2864E77640331F80BE5';
SET @geo_image_manual_sha256 := 'AF563F4BE8EE2F9114CA33D4354146AD4AE5CC3FEBE36B462CBFB2DB7A71C059';
SET @geo_content_manual_sha256 := 'DB7C12AF5260296B788EE9EF07F9EB2F51E249B354F66666B8FE79976A7A4E68';

SET @geo_content_description := '从用户提供的3份Excel模板和3份Word手册中提炼的酒店GEO内容资料建档、图库证据、关键词与蒸馏问题、标题任务卡、年度计划、人工审核和发布门禁参考。材料没有绑定任何租户、酒店、平台门店、业务日期、真实图片或发布结果；仅用于知识检索、资料缺口检查和人工审核草案，不授权自动生成事实、自动批准、自动发布、外发或写入OTA/PMS。';

SET @geo_content_manifest := JSON_OBJECT(
  'material_type', 'user_provided_xlsx_docx_bundle',
  'observed_at', '2026-08-20',
  'verification_status', 'source_files_read_and_structurally_verified_reference_only',
  'document_count', 6,
  'documents', JSON_ARRAY(
    JSON_OBJECT(
      'file_name', '02_酒店GEO内容信息建设总表.xlsx',
      'file_type', 'xlsx',
      'sha256', @geo_information_sha256,
      'sheet_count', 18,
      'content_status', 'template_headers_and_pending_defaults_no_hotel_facts'
    ),
    JSON_OBJECT(
      'file_name', '07_酒店GEO关键词、蒸馏词与标题审核表.xlsx',
      'file_type', 'xlsx',
      'sha256', @geo_review_sha256,
      'sheet_count', 3,
      'content_status', 'header_only_review_templates'
    ),
    JSON_OBJECT(
      'file_name', '06_酒店GEO年度内容运营计划.xlsx',
      'file_type', 'xlsx',
      'sha256', @geo_annual_plan_sha256,
      'sheet_count', 2,
      'content_status', 'twelve_month_template_defaults_not_an_approved_plan'
    ),
    JSON_OBJECT(
      'file_name', '12_九逸得内部顾问审核版操作手册.docx',
      'file_type', 'docx',
      'sha256', @geo_consultant_manual_sha256,
      'content_status', 'consultant_process_and_review_reference'
    ),
    JSON_OBJECT(
      'file_name', '03_酒店GEO图片拍摄与图库建设指南.docx',
      'file_type', 'docx',
      'sha256', @geo_image_manual_sha256,
      'content_status', 'image_capture_and_library_reference'
    ),
    JSON_OBJECT(
      'file_name', '01_酒店GEO内容信息建设操作手册.docx',
      'file_type', 'docx',
      'sha256', @geo_content_manual_sha256,
      'content_status', 'merchant_process_and_gate_reference'
    )
  ),
  'xlsx_visual_verification', 'all_23_worksheets_rendered_and_reviewed',
  'docx_visual_verification', 'not_rendered_libreoffice_missing_structural_extraction_passed',
  'source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands',
  'source_authenticity_status', 'issuer_and_license_not_independently_verified',
  'reuse_mode', 'guarded_global_knowledge_reference'
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @geo_content_unit_name,
  @geo_content_source,
  'done',
  @geo_content_description,
  JSON_ARRAY('GEO内容', '内容运营', '酒店知识', '图片图库', '关键词审核', '人工审批', 'reference_only'),
  0,
  'active',
  'user_provided_geo_templates_and_manuals_distilled_as_reference_only',
  @geo_content_reviewed_at,
  @geo_content_review_due_at,
  JSON_ARRAY(
    '内容信息总表包含18个工作表，覆盖项目进度、酒店实体、房型设施、目的地、图库、关键词、蒸馏问题、标题任务卡和发布监测。',
    '关键词审核表包含关键词、蒸馏词和标题三张人工审核表。',
    '年度计划模板包含12个月、每月默认2篇、PENDING审核和NOT_STARTED发布状态及4个看板公式。',
    '三份手册给出角色分工、Gate 0至6、图片证据要求、证据等级、内容路线和发布红线。'
  ),
  JSON_ARRAY(
    '未提供目标租户、系统酒店、酒店标准名称、平台门店绑定或适用业务日期。',
    '表格中的酒店事实、房型设施、位置交通、图片、授权、关键词、标题和发布记录均未填写或未审核。',
    '年度计划的24篇为模板默认值，不是任何酒店已经批准或执行的计划。',
    '九逸得来源身份、许可、规则时效和法律适用性未独立核验。',
    '没有发布、收录、AI回答、咨询、预订或经营效果数据。'
  ),
  @geo_content_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @geo_content_unit_name AND `source` = @geo_content_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @geo_content_description,
  `tags` = JSON_ARRAY('GEO内容', '内容运营', '酒店知识', '图片图库', '关键词审核', '人工审批', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_provided_geo_templates_and_manuals_distilled_as_reference_only',
  `reviewed_at` = @geo_content_reviewed_at,
  `review_due_at` = @geo_content_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '内容信息总表包含18个工作表，覆盖项目进度、酒店实体、房型设施、目的地、图库、关键词、蒸馏问题、标题任务卡和发布监测。',
    '关键词审核表包含关键词、蒸馏词和标题三张人工审核表。',
    '年度计划模板包含12个月、每月默认2篇、PENDING审核和NOT_STARTED发布状态及4个看板公式。',
    '三份手册给出角色分工、Gate 0至6、图片证据要求、证据等级、内容路线和发布红线。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '未提供目标租户、系统酒店、酒店标准名称、平台门店绑定或适用业务日期。',
    '表格中的酒店事实、房型设施、位置交通、图片、授权、关键词、标题和发布记录均未填写或未审核。',
    '年度计划的24篇为模板默认值，不是任何酒店已经批准或执行的计划。',
    '九逸得来源身份、许可、规则时效和法律适用性未独立核验。',
    '没有发布、收录、AI回答、咨询、预订或经营效果数据。'
  ),
  `truth_profile_version` = @geo_content_version,
  `updated_at` = NOW()
WHERE `name` = @geo_content_unit_name AND `source` = @geo_content_source;

SET @geo_content_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @geo_content_unit_name AND `source` = @geo_content_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_geo_content_reference_chunks`;
CREATE TEMPORARY TABLE `tmp_geo_content_reference_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_geo_content_reference_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_geo_content_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_content_unit_id, 'geo_content_information_workbook_reference', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_template_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://02_酒店GEO内容信息建设总表.xlsx#sha256=', @geo_information_sha256)),
  'workbook_sheet_count', 18,
  'workbook_sheets', JSON_ARRAY(
    '01_项目进度', '02_基础实体', '03_品牌定位', '04_房型信息', '05_设施服务', '06_会议团队',
    '07_客群场景', '08_位置交通', '09_周边景点', '10_美食购物特产', '11_城市活动', '12_评价与问答',
    '13_荣誉证书', '14_图库清单', '15_关键词审核', '16_蒸馏问题', '17_标题任务卡', '18_发布监测'
  ),
  'identity_fields_required_before_hotel_use', JSON_ARRAY('tenant_id', 'system_hotel_id', 'hotel_standard_name', 'applicable_date', 'information_source', 'verified_at'),
  'major_domains', JSON_ARRAY('hotel_entity', 'brand_positioning', 'room_types', 'facilities', 'meetings', 'guest_scenarios', 'transport', 'destinations', 'food_and_shopping', 'city_events', 'faq', 'honors', 'image_library', 'keywords', 'distilled_questions', 'title_cards', 'publication_monitoring'),
  'default_data_status', 'template_only_no_current_hotel_facts',
  'missing_value_rule', '空白、PENDING、待填写和待核验必须保持缺失状态，不得转成0、完成或真实酒店事实'
), 0, NOW()
WHERE @geo_content_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_content_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_content_unit_id, 'geo_keyword_distillation_title_review_reference', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_template_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://07_酒店GEO关键词、蒸馏词与标题审核表.xlsx#sha256=', @geo_review_sha256)),
  'sheets', JSON_ARRAY(
    JSON_OBJECT('name', '关键词审核', 'key_fields', JSON_ARRAY('keyword_id', '关键词', '实体/酒店', '城市', '商圈/地标', '搜索意图', '商业价值1-5', '真实支持1-5', '知识支持1-5', '风险', '顾问建议', 'human_decision', 'human_revision', 'human_comment')),
    JSON_OBJECT('name', '蒸馏词审核', 'key_fields', JSON_ARRAY('distilled_id', '来源keyword_id', '蒸馏问题', '用户真实问题', '内容去向', '事实支持', '证据需求', '可回答性', '商业价值', '顾问建议', 'human_decision', 'human_revision', 'human_comment')),
    JSON_OBJECT('name', '标题审核', 'key_fields', JSON_ARRAY('title_id', '来源distilled_id', '标题', '内容类型', '目标读者', '标题承诺', '知识支持', '文章深度', '夸大风险', '重复风险', '顾问建议', 'human_decision', 'human_revision', 'human_comment'))
  ),
  'approval_chain', JSON_ARRAY('keyword_human_decision', 'distilled_question_human_decision', 'title_human_decision'),
  'source_data_status', 'header_only_no_approved_keywords_questions_or_titles',
  'guardrail', '评分和顾问建议只能形成待审核候选，human_decision未通过时不得进入下一阶段'
), 0, NOW()
WHERE @geo_content_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_content_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_content_unit_id, 'geo_annual_content_plan_reference', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_template_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://06_酒店GEO年度内容运营计划.xlsx#sha256=', @geo_annual_plan_sha256)),
  'months', 12,
  'template_items_per_month', 2,
  'template_total_items', 24,
  'default_review_status', 'PENDING',
  'default_publication_status', 'NOT_STARTED',
  'plan_fields', JSON_ARRAY('月份', '城市季节/节假日', '城市活动/展会', '目标客群', '核心主题', '关键词方向', '内容类型', '计划篇数', '图片需求', '负责人', '计划日期', '审核状态', '发布状态', '复盘结果'),
  'dashboard_formulas', JSON_OBJECT(
    'planned_content_count', '=SUM(AnnualPlan!H4:H15)',
    'published_month_count', '=COUNTIF(AnnualPlan!M4:M15,"PUBLISHED")',
    'pending_review_month_count', '=COUNTIF(AnnualPlan!L4:L15,"PENDING")',
    'completion_rate', '=IF(B4=0,0,B5/12)'
  ),
  'fact_boundary', '24篇、12个待审核月和0完成率均为模板初始状态，不代表任何酒店实际计划或执行结果',
  'activation_requirements', JSON_ARRAY('绑定酒店', '确认适用年度', '补齐城市活动来源与核验日期', '确认目标客群和内容主题', '指定负责人', '完成逐项人工审核')
), 0, NOW()
WHERE @geo_content_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_content_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_content_unit_id, 'geo_content_building_manual_reference', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_manual_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://01_酒店GEO内容信息建设操作手册.docx#sha256=', @geo_content_manual_sha256)),
  'roles', JSON_OBJECT('owner', '目标授权与最终发布确认', 'general_manager', '资料统筹与事实准确性', 'front_office', '房型服务政策与FAQ', 'sales', '会议团队与商务客群', 'marketing', '品牌图片与活动', 'consultant', '关键词蒸馏标题任务卡质量门和发布治理', 'system', '资产存储任务执行和状态跟踪'),
  'workflow_stages', JSON_ARRAY('项目建档', '基础资料', '目的地资料', '图库建设', '关键词审核', '蒸馏与任务卡', '内容审核', 'GEO执行'),
  'quality_gates', JSON_ARRAY(
    JSON_OBJECT('gate', 'Gate 0', 'check', '资料真实性、授权、证据', 'fail_action', '不得生成关键词'),
    JSON_OBJECT('gate', 'Gate 1', 'check', '关键词真实支持、搜索价值', 'fail_action', '不得生成蒸馏问题'),
    JSON_OBJECT('gate', 'Gate 2', 'check', '蒸馏问题可回答、非机械问句', 'fail_action', '不得生成标题'),
    JSON_OBJECT('gate', 'Gate 3', 'check', '标题不过度承诺、不夸大', 'fail_action', '不得建任务卡'),
    JSON_OBJECT('gate', 'Gate 4', 'check', '任务卡事实、框架、图片、禁用表达完整', 'fail_action', '不得生成文章'),
    JSON_OBJECT('gate', 'Gate 5', 'check', '文章与图片审核', 'fail_action', '不得录入和发布'),
    JSON_OBJECT('gate', 'Gate 6', 'check', '明确发布授权', 'fail_action', '不得发布、扣点或投喂')
  ),
  'source_completion_claims', JSON_ARRAY('基础实体字段完成率不低于95%', '核心房型和设施图片完成率100%', '所有图片授权状态明确', '关键词至文章全链路有人工审批记录', '进入GEO系统前完成真实性与公开授权确认'),
  'applicability_rule', '完成阈值是来源手册要求，落到具体酒店前仍需负责人确认，不自动升级为系统强制或当前完成事实'
), 0, NOW()
WHERE @geo_content_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_content_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_content_unit_id, 'geo_image_library_guide_reference', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_manual_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://03_酒店GEO图片拍摄与图库建设指南.docx#sha256=', @geo_image_manual_sha256)),
  'principles', JSON_ARRAY('真实', '完整', '可识别', '可授权', '可检索'),
  'source_recommended_minimums', JSON_OBJECT('酒店外观', '6张', '酒店大厅', '6张', '酒店客房', '每房型8张', '酒店餐饮', '8张', '公共设施', '每项4张', '会议室', '8张', '家庭亲子', '6张', '酒店周边', '10张', '景点购物特产打卡', '每类10张', '证书荣誉', '5张'),
  'technical_reference', JSON_ARRAY('建议横版4:3或16:9且原图不低于2000像素宽', '保持水平垂直并避免过度广角和空间失真', '自然光优先并避免严重过曝偏色和强滤镜', '房间拍摄前整理床品台面线缆垃圾和个人物品'),
  'metadata_fields', JSON_ARRAY('图片编号', '一级分类', '二级分类', '图片标题', '图片说明', '版权人', '公开授权', '拍摄日期', '核验状态', 'ALT文本'),
  'privacy_and_truth_rules', JSON_ARRAY('不得把与实际房型不符的AI生成图片作为事实图片', '涉及客人或员工正脸必须取得肖像授权', '第三方地点必须标名称地址与酒店关系距离', '不得暗示酒店拥有或经营第三方地点', '第三方图片必须有来源和授权'),
  'approval_rule', '版权、公开范围、隐私和时效任一不明确时保持待核验并禁止对外使用'
), 0, NOW()
WHERE @geo_content_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_content_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_content_unit_id, 'geo_consultant_review_manual_reference', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_manual_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-file://12_九逸得内部顾问审核版操作手册.docx#sha256=', @geo_consultant_manual_sha256)),
  'consultant_sequence', JSON_ARRAY('建档', '资料审查', '画像建模', '关键词', '蒸馏', '任务卡', '样稿审核', '发布治理'),
  'keyword_score_dimensions', JSON_ARRAY('真实支持0-5', '用户意图0-5', '商业价值0-5', '内容支撑0-5', '差异化0-5', '风险-5至0'),
  'source_evidence_levels', JSON_ARRAY(
    JSON_OBJECT('level', 'LEVEL A', 'definition', '证书、官方页面、合同、原始数据等独立证据', 'rule', '可直接使用但仍检查时效'),
    JSON_OBJECT('level', 'LEVEL B', 'definition', '酒店正式资料或负责人确认但缺独立证据', 'rule', '正文限定使用且不宜做强标题'),
    JSON_OBJECT('level', 'LEVEL C', 'definition', '口述、未核实宣传或推测', 'rule', '不得公开并列入待补证据')
  ),
  'content_routes', JSON_ARRAY('HOTEL_PROFILE', 'ROOM_FACILITY', 'TRAVEL_DECISION', 'CITY_GUIDE', 'FAQ', 'COMPARISON'),
  'red_lines', JSON_ARRAY('不得虚构酒店设施房型荣誉案例或第三方关系', '不得使用最好第一唯一保证等无法证明表达', '不得把周边景点餐厅停车场写成酒店自有设施', '不得把AI生成效果图作为现实事实图', '不得未经酒店确认发布扣点或创建投喂任务', '不得以绝对价格承诺替代动态价格说明'),
  'current_authority_status', 'consultant_method_reference_not_current_hotel_approval'
), 0, NOW()
WHERE @geo_content_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_content_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_content_unit_id, 'geo_content_operating_workflow_contract', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'derived_from_user_provided_reference_bundle',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-file://02_酒店GEO内容信息建设总表.xlsx#sha256=', @geo_information_sha256),
    CONCAT('user-file://07_酒店GEO关键词、蒸馏词与标题审核表.xlsx#sha256=', @geo_review_sha256),
    CONCAT('user-file://06_酒店GEO年度内容运营计划.xlsx#sha256=', @geo_annual_plan_sha256),
    CONCAT('user-file://12_九逸得内部顾问审核版操作手册.docx#sha256=', @geo_consultant_manual_sha256),
    CONCAT('user-file://03_酒店GEO图片拍摄与图库建设指南.docx#sha256=', @geo_image_manual_sha256),
    CONCAT('user-file://01_酒店GEO内容信息建设操作手册.docx#sha256=', @geo_content_manual_sha256)
  ),
  'role', '酒店负责人提供并确认事实与授权，顾问形成候选和审核意见，系统保存证据与状态',
  'trigger', '某一明确酒店要启动GEO内容资料建设或复核',
  'required_inputs', JSON_ARRAY('tenant_id', 'system_hotel_id', '酒店标准名称', '适用日期或年度', '酒店事实与来源', '图片与权利证明', '内容目标', '负责人和人工审核人'),
  'steps', JSON_ARRAY('绑定酒店身份', '录入并核验实体房型设施目的地事实', '补齐图片编号元数据和授权', '形成关键词候选并人工审核', '形成蒸馏问题并人工审核', '形成标题任务卡并人工审核', '审核文章图片事实和品牌露出', '取得明确发布授权', '记录发布与监测结果', '按同口径复盘'),
  'states', JSON_ARRAY('hotel_binding_missing', 'facts_pending', 'evidence_pending', 'keyword_review_pending', 'distillation_review_pending', 'title_review_pending', 'content_review_pending', 'publication_pending_approval', 'published_unverified', 'monitoring', 'closed'),
  'completion_evidence', JSON_ARRAY('酒店身份与适用日期', '字段缺口清单', '事实来源和最近核验日期', '图片授权', '逐级human_decision', '发布授权', '发布记录', '监测与复盘记录'),
  'exceptions', JSON_ARRAY('酒店身份缺失则停止建档', '事实或证据缺失则保持PENDING', '第三方地点关系不清则禁止写成自有', '图片版权隐私不清则禁止对外使用', 'human_decision未通过则不得进入下一阶段', '无Gate 6明确授权则保持publication_pending_approval'),
  'allowed_uses', JSON_ARRAY('knowledge_search', 'geo_content_readiness_checklist_draft', 'missing_information_questions', 'keyword_risk_review_draft', 'title_risk_review_draft', 'training_reference'),
  'success_path', '绑定酒店和日期后逐级保存事实、证据和人工决定；只有Gate 6授权后才允许人工发起发布并继续记录结果',
  'failure_path', '任一身份、事实、证据、授权或审核缺失时返回对应pending或blocked状态，不以模板默认值补齐'
), 0, NOW()
WHERE @geo_content_unit_id IS NOT NULL;

UPDATE `tmp_geo_content_reference_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('geo_content_operations:', `type`),
  '$.content_type', 'geo_content_operations_reference',
  '$.module_id', 'geo_content_operations_reference',
  '$.platforms', JSON_ARRAY(),
  '$.roles', JSON_ARRAY('owner', 'general_manager', 'front_office', 'sales', 'marketing', 'geo_consultant', 'knowledge_reviewer'),
  '$.scenes', JSON_ARRAY('knowledge_search', 'geo_content_readiness', 'image_library_check', 'keyword_review', 'title_review', 'publication_gate_review'),
  '$.source_manifest', JSON_EXTRACT(@geo_content_manifest, '$'),
  '$.reviewed_at', @geo_content_reviewed_at,
  '$.review_due_at', @geo_content_review_due_at,
  '$.review_interval_days', 180,
  '$.freshness_policy', 'reference_only_until_current_hotel_identity_facts_evidence_and_approval_are_verified',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_verified_for_current_hotel',
  '$.decision_policy', 'reference_only_human_review_required',
  '$.decision_safe', false,
  '$.allowed_uses', JSON_MERGE_PRESERVE(
    COALESCE(JSON_EXTRACT(`content`, '$.allowed_uses'), JSON_ARRAY()),
    JSON_ARRAY('knowledge_search', 'training_reference', 'missing_information_questions')
  ),
  '$.blocked_uses', JSON_ARRAY('current_hotel_fact', 'current_ota_fact', 'automatic_keyword_approval', 'automatic_title_approval', 'operation_task_creation', 'operation_execution', 'automatic_content_generation', 'automatic_publication', 'automatic_ota_write', 'automatic_pms_write', 'external_message'),
  '$.seed_owner', @geo_content_seed_owner,
  '$.seed_key', CONCAT('geo_content_operations:', `type`),
  '$.seed_version', @geo_content_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.contains_approved_publication_plan', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_geo_content_reference_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET `existing`.`type` = `seed`.`type`, `existing`.`content` = `seed`.`content`, `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_geo_content_reference_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_version')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_geo_content_reference_chunks`;

SET @geo_content_staff_content := CONCAT(
  '# 酒店GEO内容运营与审核门禁 v1.0（用户资料参考）', '\n\n',
  '## 可用范围', '\n',
  '用于检索酒店GEO资料建档、图片图库、关键词与蒸馏问题、标题任务卡、年度内容计划、人工审核和发布门禁。', '\n\n',
  '## 最短流程', '\n',
  '绑定酒店与适用日期 → 核验实体和目的地事实 → 补齐图片及授权 → 关键词人工审核 → 蒸馏问题人工审核 → 标题任务卡人工审核 → 内容与图片审核 → 明确发布授权 → 发布监测与复盘。', '\n\n',
  '## 使用边界', '\n',
  '六份材料是未绑定酒店的模板和手册。空白、PENDING、默认24篇和手册阈值都不是当前酒店事实或批准计划；不得自动批准、自动生成事实、自动发布、写OTA/PMS或外发。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @geo_content_unit_name, @geo_content_staff_content,
  'GEO内容,酒店资料,图片图库,关键词审核,蒸馏问题,标题任务卡,年度计划,发布门禁,人工审批',
  JSON_ARRAY('GEO内容', '内容运营', '图片图库', '关键词审核', '人工审批', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @geo_content_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @geo_content_staff_content,
  `keywords` = 'GEO内容,酒店资料,图片图库,关键词审核,蒸馏问题,标题任务卡,年度计划,发布门禁,人工审批',
  `tags` = JSON_ARRAY('GEO内容', '内容运营', '图片图库', '关键词审核', '人工审批', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @geo_content_unit_name;
