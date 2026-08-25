-- Absorb the user-provided hotel GEO delivery bundle into the existing Knowledge Center.
-- The bundle is a global method/template reference only. It is not a current-hotel fact set
-- and it grants no authority to create tasks, publish content, spend credits, or write OTA/PMS data.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @geo_version := '2026-08-20.2';
SET @geo_reviewed_at := '2026-08-20 00:00:00';
SET @geo_review_due_at := '2027-02-16 00:00:00';
SET @geo_seed_owner := 'suxios.hotel_geo_operations_reference';
SET @geo_unit_name := '酒店GEO内容资产与发布审核工作流 v1.0（用户资料参考）';
SET @geo_source := 'hotel_geo_operations_reference';
SET @geo_description := '从用户提供的15份酒店GEO手册、表格、培训材料和示例中提炼建档、证据、图库、关键词、任务卡、人工审核、发布授权与AI可见度监测合同。仅供知识检索和人工执行参考，不代表任何当前酒店事实、平台规则、发布结果或收益结果。';

SET @geo_sha_delivery := 'F94BD6B830A4D217FDFE21EDEE27699D3964F3C16680FCB9E9F29A52D91B8871';
SET @geo_sha_manual := 'DB7C12AF5260296B788EE9EF07F9EB2F51E249B354F66666B8FE79976A7A4E68';
SET @geo_sha_information := '6815D28084DBF2784ACE4C800B4E38BA3FC148E3F4B6DBE96D038D9BC3D9363C';
SET @geo_sha_images := 'AF563F4BE8EE2F9114CA33D4354146AD4AE5CC3FEBE36B462CBFB2DB7A71C059';
SET @geo_sha_truth_auth := 'DD4CD3AEB68B57B19920DFAB64076F844C4CF458CB78941F4D9E8F696A7300B7';
SET @geo_sha_human_review := '9EBB6661A4EA9A0174E11A06CECDBCACB040C1AFEB3A8C0E85252F6A454CAC50';
SET @geo_sha_annual_plan := '1D8009ED9677227FBA665E3E4C80722B7C44A41010FFF2FA4352AD9C285170DB';
SET @geo_sha_keyword_review := 'B94427ADEA121B8FAD77525F9DA253F4C90490F24BF95A80B74B0F99055499C6';
SET @geo_sha_monitor := '258FE4D2619546877528C55A8B833639513ADBDD5918FFDA615825AB1DCB51C2';
SET @geo_sha_folder_structure := '86F34F57EF697A958DBA476E71A950A7F7D4E04721E5AB41F94FE771719449D0';
SET @geo_sha_sop := '6A9002CD8A2196358D26F3DB95863BABB2582DF9C2A15FF0A258ECA5FF262E96';
SET @geo_sha_example := '9ECEF2A686D28188541D36A62A4E66173500A60CEB8CEE8E2A625F791D51F99A';
SET @geo_sha_consultant := 'CAE0E787C5091551FE4EB6106D24D4B6E44C2CE17C81F2864E77640331F80BE5';
SET @geo_sha_training_html := '4A279F24EF53DC96CA8329F454DF515993F447B38C60AC1C1EF19BB87C599872';
SET @geo_sha_speaker_docx := '4189E993E437AD8DBA2141D9AC85E190E90E97D878BC6DB1C50E59EFC30E04C3';

SET @geo_source_manifest := JSON_OBJECT(
  'manifest_version', '2026-08-20.1',
  'material_count', 15,
  'documents', JSON_ARRAY(
    JSON_OBJECT('file_name', '00_交付清单.md', 'media_type', 'text/markdown', 'sha256', @geo_sha_delivery, 'parse_status', 'parsed', 'role', 'delivery_order'),
    JSON_OBJECT('file_name', '01_酒店GEO内容信息建设操作手册(1).docx', 'media_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'sha256', @geo_sha_manual, 'parse_status', 'parsed', 'role', 'merchant_manual'),
    JSON_OBJECT('file_name', '02_酒店GEO内容信息建设总表(1).xlsx', 'media_type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'sha256', @geo_sha_information, 'parse_status', 'parsed', 'role', 'information_workbook'),
    JSON_OBJECT('file_name', '03_酒店GEO图片拍摄与图库建设指南(1).docx', 'media_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'sha256', @geo_sha_images, 'parse_status', 'parsed', 'role', 'image_evidence_guide'),
    JSON_OBJECT('file_name', '04_酒店GEO资料真实性与公开授权确认书.docx', 'media_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'sha256', @geo_sha_truth_auth, 'parse_status', 'parsed', 'role', 'truth_and_public_authorization'),
    JSON_OBJECT('file_name', '05_酒店GEO内容人工审核确认书.docx', 'media_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'sha256', @geo_sha_human_review, 'parse_status', 'parsed', 'role', 'human_review_and_publication_authorization'),
    JSON_OBJECT('file_name', '06_酒店GEO年度内容运营计划(1).xlsx', 'media_type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'sha256', @geo_sha_annual_plan, 'parse_status', 'parsed', 'role', 'annual_content_plan_template'),
    JSON_OBJECT('file_name', '07_酒店GEO关键词、蒸馏词与标题审核表(1).xlsx', 'media_type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'sha256', @geo_sha_keyword_review, 'parse_status', 'parsed', 'role', 'keyword_and_title_review_template'),
    JSON_OBJECT('file_name', '08_酒店GEO发布与AI可见度监测表.xlsx', 'media_type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'sha256', @geo_sha_monitor, 'parse_status', 'parsed', 'role', 'publication_and_visibility_monitor_template'),
    JSON_OBJECT('file_name', '09_酒店GEO项目文件夹标准结构.zip', 'media_type', 'application/zip', 'sha256', @geo_sha_folder_structure, 'parse_status', 'static_member_inventory_only', 'role', 'project_folder_structure'),
    JSON_OBJECT('file_name', '10_酒店商家GEO实施SOP.md', 'media_type', 'text/markdown', 'sha256', @geo_sha_sop, 'parse_status', 'parsed', 'role', 'merchant_execution_sop'),
    JSON_OBJECT('file_name', '11_酒店商家填写示例包.zip', 'media_type', 'application/zip', 'sha256', @geo_sha_example, 'parse_status', 'static_member_content_parsed_no_execution', 'role', 'synthetic_examples'),
    JSON_OBJECT('file_name', '12_九逸得内部顾问审核版操作手册(1).docx', 'media_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'sha256', @geo_sha_consultant, 'parse_status', 'parsed', 'role', 'consultant_review_manual'),
    JSON_OBJECT('file_name', 'hotel_geo_training_九逸得GEO酒店行业场景培训课件_系统实操案例增强版.html', 'media_type', 'text/html', 'sha256', @geo_sha_training_html, 'parse_status', 'parsed_without_scripts_or_styles', 'role', 'training_course'),
    JSON_OBJECT('file_name', 'hotel_geo_training_完整口播稿_系统实操案例增强版.docx', 'media_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'sha256', @geo_sha_speaker_docx, 'parse_status', 'parsed', 'role', 'training_speaker_notes')
  ),
  'zip_execution_policy', 'static_inventory_and_supported_document_read_only_no_member_execution',
  'source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands',
  'external_claim_verification_status', 'not_verified_this_run',
  'current_hotel_verification_status', 'not_verified_for_current_hotel'
);

INSERT INTO `knowledge_units` (
  `hotel_id`, `name`, `source`, `status`, `description`, `tags`, `created_by`,
  `lifecycle_status`, `lifecycle_reason`, `reviewed_at`, `review_due_at`,
  `known_knowns`, `known_unknowns`, `truth_profile_version`, `created_at`, `updated_at`
)
SELECT
  0,
  @geo_unit_name,
  @geo_source,
  'done',
  @geo_description,
  JSON_ARRAY('GEO内容', '知识资产', '关键词审核', '图片图库', '人工审批', '发布监测', 'reference_only'),
  0,
  'active',
  'user_bundle_absorbed_as_global_reference_with_current_hotel_and_external_write_boundaries',
  @geo_reviewed_at,
  @geo_review_due_at,
  JSON_ARRAY(
    '15份GEO材料均已完成只读解析或压缩包静态清点，并保留文件级SHA-256。',
    '材料共同描述了从酒店建档、事实与授权、图库、关键词、蒸馏问题、标题任务卡、双重人工审核到发布监测的连续工作流。',
    '审核状态统一使用APPROVE、REVISE、REJECT、PENDING，发布和扣点需要独立授权。',
    '示例包明确使用虚构酒店，仅用于展示填写方法。'
  ),
  JSON_ARRAY(
    '未绑定当前系统酒店、租户、平台门店、业务日期或实际酒店资料。',
    '材料中的行业数据、平台观察和公开网页口径本次未做联网复核。',
    '没有任何真实酒店的Gate 0至Gate 6签署记录、已发布内容或AI可见度样本。',
    '没有授权创建GEO任务、发布内容、扣点、发送消息或修改OTA/PMS。'
  ),
  @geo_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_units`
  WHERE `name` = @geo_unit_name AND `source` = @geo_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @geo_description,
  `tags` = JSON_ARRAY('GEO内容', '知识资产', '关键词审核', '图片图库', '人工审批', '发布监测', 'reference_only'),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'user_bundle_absorbed_as_global_reference_with_current_hotel_and_external_write_boundaries',
  `reviewed_at` = @geo_reviewed_at,
  `review_due_at` = @geo_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '15份GEO材料均已完成只读解析或压缩包静态清点，并保留文件级SHA-256。',
    '材料共同描述了从酒店建档、事实与授权、图库、关键词、蒸馏问题、标题任务卡、双重人工审核到发布监测的连续工作流。',
    '审核状态统一使用APPROVE、REVISE、REJECT、PENDING，发布和扣点需要独立授权。',
    '示例包明确使用虚构酒店，仅用于展示填写方法。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '未绑定当前系统酒店、租户、平台门店、业务日期或实际酒店资料。',
    '材料中的行业数据、平台观察和公开网页口径本次未做联网复核。',
    '没有任何真实酒店的Gate 0至Gate 6签署记录、已发布内容或AI可见度样本。',
    '没有授权创建GEO任务、发布内容、扣点、发送消息或修改OTA/PMS。'
  ),
  `truth_profile_version` = @geo_version,
  `updated_at` = NOW()
WHERE `name` = @geo_unit_name AND `source` = @geo_source;

SET @geo_unit_id := (
  SELECT `unit_id` FROM `knowledge_units`
  WHERE `name` = @geo_unit_name AND `source` = @geo_source
  ORDER BY `unit_id` ASC LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_geo_reference_chunks`;
CREATE TEMPORARY TABLE `tmp_geo_reference_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_geo_reference_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_geo_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_unit_id, 'geo_source_audit_reference', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_bundle_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-bundle://2026-08-20/00_交付清单.md#sha256=', @geo_sha_delivery),
    CONCAT('user-bundle://2026-08-20/10_酒店商家GEO实施SOP.md#sha256=', @geo_sha_sop)
  ),
  'source_manifest', JSON_EXTRACT(@geo_source_manifest, '$'),
  'observed_facts', JSON_ARRAY(
    '来源包包含15份GEO材料：6个DOCX、4个XLSX、2个MD、2个ZIP和1个HTML。',
    '信息建设总表有18个工作表；年度计划、关键词审核和可见度监测表均为未填模板。',
    '两个ZIP只做成员清点和支持格式只读解析，没有执行成员内容。'
  ),
  'source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands',
  'source_limitations', JSON_ARRAY(
    '材料中的外部行业数据和网页口径未在本次任务联网核验。',
    '材料未提供任何当前酒店已填写、已签字、已发布或已复测的业务记录。'
  )
), 0, NOW()
WHERE @geo_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_unit_id, 'geo_stage_gate_workflow_contract', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_sop_and_manual_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-bundle://2026-08-20/00_交付清单.md#sha256=', @geo_sha_delivery),
    CONCAT('user-bundle://2026-08-20/01_酒店GEO内容信息建设操作手册.docx#sha256=', @geo_sha_manual),
    CONCAT('user-bundle://2026-08-20/10_酒店商家GEO实施SOP.md#sha256=', @geo_sha_sop),
    CONCAT('user-bundle://2026-08-20/12_九逸得内部顾问审核版操作手册.docx#sha256=', @geo_sha_consultant)
  ),
  'workflow', JSON_ARRAY(
    '建档并唯一识别酒店主体、地址、地图与OTA链接',
    '填写基础实体、产品、场景和目的地资料，未知项标记待核验',
    '建设有编号、元数据、版权与公开范围的图库',
    '生成关键词候选并由商家确认事实、顾问评估搜索价值',
    '把关键词蒸馏为真实用户问题，再形成标题和文章任务卡',
    '酒店审核事实与公开边界，顾问审核用户价值、证据和风险',
    'Gate 6独立授权后才可进入外部发布或额度消耗',
    '用固定问题集持续监测出现、准确、引用、竞品和承接'
  ),
  'quality_gates', JSON_OBJECT(
    'gate_0', '资料真实性、授权和证据；未通过不得生成关键词',
    'gate_1', '关键词真实支持和搜索价值；未通过不得生成蒸馏问题',
    'gate_2', '蒸馏问题可回答且非机械问句；未通过不得生成标题',
    'gate_3', '标题不过度承诺或夸大；未通过不得建任务卡',
    'gate_4', '任务卡事实、框架、图片和禁用表达完整；未通过不得生成文章',
    'gate_5', '文章与图片完成人工审核；未通过不得录入或发布',
    'gate_6', '发布、平台范围和额度消耗获得明确授权；未通过不得外部写入'
  ),
  'hold_conditions', JSON_ARRAY(
    '酒店主体不能唯一识别',
    '关键设施、距离、政策、活动或图片真实性存疑',
    '版权、肖像或公开范围不清',
    '出现绝对化、收益保证或竞品贬损',
    '没有发布授权'
  ),
  'success_evidence', JSON_ARRAY('责任人', '输入来源', '人工决定', '版本', '日期', '授权范围', '发布或复查记录')
), 0, NOW()
WHERE @geo_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_unit_id, 'geo_property_information_contract', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_workbook_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-bundle://2026-08-20/02_酒店GEO内容信息建设总表.xlsx#sha256=', @geo_sha_information)),
  'modules', JSON_ARRAY(
    '项目进度', '基础实体', '品牌定位', '房型信息', '设施服务', '会议团队', '客群场景', '位置交通',
    '周边景点', '美食购物特产', '城市活动', '评价与问答', '荣誉证书', '图库清单', '关键词审核',
    '蒸馏问题', '标题任务卡', '发布监测'
  ),
  'identity_fields', JSON_ARRAY('酒店标准名称', '经营主体', '地址', '城市', '行政区', '地图链接', '携程链接', '美团链接'),
  'fact_fields', JSON_ARRAY('填写内容', '信息来源', '最近核验日期', '公开范围', '核验状态', '审核状态'),
  'unknown_policy', '不确定的信息必须标记待核验，不得留空后由系统猜测，也不得用默认值伪装完成',
  'third_party_policy', '景点、餐厅、商场、停车场等必须标明第三方关系、来源和核验日期，不得写成酒店自有设施',
  'template_initial_state', '模板中的否、0、PENDING、NOT_STARTED和待填写是空模板状态，不代表任何酒店实际完成率或业务结果',
  'source_completion_recommendation', JSON_OBJECT('base_entity_fields', 'not_less_than_95_percent', 'core_room_and_facility_images', '100_percent'),
  'completion_recommendation_boundary', '来源完成率是实施建议，未绑定酒店和实测回读前不作为宿析OS当前完成状态'
), 0, NOW()
WHERE @geo_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_unit_id, 'geo_image_evidence_contract', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_image_guide_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-bundle://2026-08-20/03_酒店GEO图片拍摄与图库建设指南.docx#sha256=', @geo_sha_images),
    CONCAT('user-bundle://2026-08-20/09_酒店GEO项目文件夹标准结构.zip#sha256=', @geo_sha_folder_structure)
  ),
  'principles', JSON_ARRAY('真实', '完整', '一图一主题且可识别', '版权和公开范围清楚', '可按编号和ALT文本检索'),
  'core_categories', JSON_ARRAY('酒店外观', '酒店大厅', '酒店客房', '酒店餐饮', '公共设施', '会议室', '家庭亲子', '酒店周边', '证书荣誉'),
  'metadata_fields', JSON_ARRAY('图片编号', '一级分类', '二级分类', '标题', '说明', '版权人', '公开授权', '拍摄日期', '核验状态', 'ALT文本'),
  'quality_rules', JSON_ARRAY(
    '图片必须与当前实景、房型和设施一致',
    '不得把AI生成效果图当作现实事实图',
    '涉及人脸需要肖像授权',
    '不得暴露证件、房卡、订单或其他隐私信息',
    '第三方图片需要来源、授权、关系和核验日期',
    '过期装修、旧房型和已取消设施必须剔除'
  ),
  'recommended_quantity_boundary', '来源中的最低张数属于拍摄建议，不是已完成证据或系统硬门'
), 0, NOW()
WHERE @geo_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_unit_id, 'geo_keyword_content_review_contract', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_review_template_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-bundle://2026-08-20/07_酒店GEO关键词蒸馏词标题审核表.xlsx#sha256=', @geo_sha_keyword_review),
    CONCAT('user-bundle://2026-08-20/12_九逸得内部顾问审核版操作手册.docx#sha256=', @geo_sha_consultant),
    CONCAT('user-bundle://2026-08-20/05_酒店GEO内容人工审核确认书.docx#sha256=', @geo_sha_human_review)
  ),
  'review_states', JSON_ARRAY('APPROVE', 'REVISE', 'REJECT', 'PENDING'),
  'keyword_dimensions', JSON_ARRAY('真实支持', '用户意图', '商业价值', '内容支撑', '差异化', '风险'),
  'evidence_levels', JSON_OBJECT(
    'LEVEL_A', '证书、官方页面、合同或原始数据等独立证据；仍需检查时效',
    'LEVEL_B', '酒店正式资料或负责人确认但缺独立证据；只做限定表达',
    'LEVEL_C', '口述、未核实宣传或推测；不得公开并进入待补证据'
  ),
  'content_chain', JSON_ARRAY('关键词候选', '真实用户蒸馏问题', '标题', '文章任务卡', '样稿', '人工审核', '发布授权'),
  'task_card_fields', JSON_ARRAY('目标读者', '酒店场景', '核心判断', '必须事实', '证据', '图片', '禁用表达', '品牌露出规则', '建议字数'),
  'review_rules', JSON_ARRAY(
    'AI可扩展候选但不得自动批准关键词或标题',
    '蒸馏问题必须对应真实住宿决策，不能机械改写问句',
    '标题不得使用最好、第一、唯一、保证等无法证明表达',
    'FAQ应回答适合谁、证据、限制条件和下一步入口',
    '没有证据的服务只能写成方向、建议或待补材料，不得写成落地案例'
  )
), 0, NOW()
WHERE @geo_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_unit_id, 'geo_publication_approval_contract', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_authorization_form_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-bundle://2026-08-20/04_酒店GEO资料真实性与公开授权确认书.docx#sha256=', @geo_sha_truth_auth),
    CONCAT('user-bundle://2026-08-20/05_酒店GEO内容人工审核确认书.docx#sha256=', @geo_sha_human_review),
    CONCAT('user-bundle://2026-08-20/10_酒店商家GEO实施SOP.md#sha256=', @geo_sha_sop)
  ),
  'approval_dimensions', JSON_ARRAY('资料真实性', '图片版权和肖像', '公开范围', '内容事实', '第三方关系', '价格活动时效', '平台范围'),
  'separate_authorizations', JSON_OBJECT(
    'create_geo_task', 'explicit_yes_required',
    'publish_content', 'explicit_yes_required',
    'consume_platform_credit_or_budget', 'explicit_yes_required',
    'authorized_platform_scope', 'must_be_recorded'
  ),
  'default_state', 'publication_pending_approval',
  'fail_closed_rule', '任何授权缺失、事实待核验、图片限制使用或审核为REVISE_REJECT_PENDING时不得进入外部写入',
  'authorization_non_transfer', '材料模板本身不构成用户对宿析OS、九逸得系统或任何外部平台的发布和扣费授权'
), 0, NOW()
WHERE @geo_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_unit_id, 'geo_visibility_monitoring_contract', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_monitoring_template_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(
    CONCAT('user-bundle://2026-08-20/06_酒店GEO年度内容运营计划.xlsx#sha256=', @geo_sha_annual_plan),
    CONCAT('user-bundle://2026-08-20/08_酒店GEO发布与AI可见度监测表.xlsx#sha256=', @geo_sha_monitor),
    CONCAT('user-bundle://2026-08-20/hotel_geo_training.html#sha256=', @geo_sha_training_html),
    CONCAT('user-bundle://2026-08-20/hotel_geo_training_speaker.docx#sha256=', @geo_sha_speaker_docx)
  ),
  'visibility_dimensions', JSON_ARRAY('固定问题集出现率', '事实准确率', '引用源', '同问竞品池', '预订或咨询承接入口'),
  'content_metrics', JSON_ARRAY('曝光', '点击', 'CTR', '停留', '跳出'),
  'business_metrics', JSON_ARRAY('咨询', '订单', 'CVR', 'ADR', 'RevPAR'),
  'asset_metrics', JSON_ARRAY('关键词覆盖', '图片标签', 'FAQ更新', '事实更新时间'),
  'cadence', JSON_OBJECT(
    'weekly', JSON_ARRAY('看固定问题与竞品答案', '补知识和图片', '生成并人工审核内容', '按授权发布', '记录结果'),
    'monthly', JSON_ARRAY('同一问题集和时间口径复测', '核对错误信息和引用变化', '与咨询订单反馈分层复盘'),
    'thirty_day', JSON_ARRAY('基线诊断', '统一实体资料', '补FAQ和图片标签', '首批内容与首次复盘'),
    'ninety_day', JSON_ARRAY('实体治理', '场景内容', '首轮分发', '周报和A/B复盘')
  ),
  'experiment_controls', JSON_ARRAY('同一渠道或问题集', '同一时间窗口', '一次只改标题图片FAQ或套餐表达中的一个主要变量', '同时看负向指标'),
  'causality_rule', 'AI可见度是前置指标；没有同口径对照和经营结果证据，不得声称内容导致订单、ADR或RevPAR提升',
  'empty_template_rule', '模板公式在没有数据时可能显示0，该0只表示空模板计算结果，不得成为酒店可见度或经营事实',
  'external_claim_boundary', '培训材料中的公开统计、搜索规则、平台观察和案例数量本次未联网或原始资产复核，保留为来源陈述'
), 0, NOW()
WHERE @geo_unit_id IS NOT NULL;

INSERT INTO `tmp_geo_reference_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT @geo_unit_id, 'geo_synthetic_example_reference', JSON_OBJECT(
  'scope', 'global_hotel_geo_content_method_reference',
  'evidence_level', 'user_provided_example_fixture_reference',
  'evidence_grade', 'C',
  'source_refs', JSON_ARRAY(CONCAT('user-bundle://2026-08-20/11_酒店商家填写示例包.zip#sha256=', @geo_sha_example)),
  'dataset_kind', 'synthetic_example',
  'example_hotel', '悦城商务酒店（虚构示例）',
  'positive_example', JSON_OBJECT('keyword', '杭州东站附近商务酒店', 'hotel_support', '是', 'consultant_advice', '建议保留', 'human_decision', 'PENDING'),
  'negative_example', JSON_OBJECT('keyword', '杭州最好的酒店', 'hotel_support', '否', 'consultant_advice', '建议拒绝', 'human_decision', 'PENDING'),
  'fixture_rule', '示例只用于验证字段、状态和失败保护，不得写入真实酒店知识、发布计划或经营结论'
), 0, NOW()
WHERE @geo_unit_id IS NOT NULL;

UPDATE `tmp_geo_reference_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('hotel_geo:', `type`),
  '$.content_type', 'hotel_geo_reference_contract',
  '$.module_id', 'hotel_geo_content_operations',
  '$.platforms', JSON_ARRAY('suxios_internal'),
  '$.roles', JSON_ARRAY('owner', 'manager', 'front_desk', 'sales', 'marketing', 'consultant'),
  '$.scenes', JSON_ARRAY('geo_asset_building', 'keyword_review', 'content_review', 'publication_authorization', 'visibility_monitoring'),
  '$.reviewed_at', @geo_reviewed_at,
  '$.review_due_at', @geo_review_due_at,
  '$.review_interval_days', 180,
  '$.freshness_policy', 'review_due_reference_only',
  '$.requires_current_verification', true,
  '$.current_verification_status', 'not_verified_for_current_hotel',
  '$.allowed_uses', JSON_ARRAY('knowledge_retrieval', 'manual_checklist_reference', 'manual_form_design_reference', 'synthetic_fixture_validation'),
  '$.blocked_uses', JSON_ARRAY(
    'current_hotel_fact', 'operation_task_creation', 'operation_execution',
    'automatic_keyword_approval', 'automatic_title_approval', 'automatic_content_generation',
    'automatic_publication', 'automatic_ota_write', 'automatic_pms_write',
    'external_message', 'platform_credit_consumption', 'business_outcome_claim'
  ),
  '$.seed_owner', @geo_seed_owner,
  '$.seed_key', CONCAT('hotel_geo:', `type`),
  '$.seed_version', @geo_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.contains_current_ota_fact', false,
  '$.contains_current_business_metric', false,
  '$.contains_approved_publication_plan', false,
  '$.external_write_authorized', false,
  '$.source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands'
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_geo_reference_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT `seed`.`unit_id`, `seed`.`type`, `seed`.`content`, `seed`.`created_by`, `seed`.`created_at`
FROM `tmp_geo_reference_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_owner')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END, '$.seed_key')) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
);

DROP TEMPORARY TABLE `tmp_geo_reference_chunks`;

SET @geo_staff_content := CONCAT(
  '# 酒店GEO内容资产与发布审核工作流', '\n\n',
  '## 使用边界', '\n',
  '这是用户资料提炼出的通用参考，不是任何当前酒店的事实、审核结果或发布授权。', '\n\n',
  '## 连续流程', '\n',
  '建档与授权 → 信息总表 → 图库证据 → 关键词 → 蒸馏问题 → 标题与任务卡 → 双重人工审核 → Gate 6发布授权 → 固定问题集监测。', '\n\n',
  '## 关键状态', '\n',
  '审核只使用 APPROVE / REVISE / REJECT / PENDING；未知资料写待核验。任务创建、发布、扣点和平台范围分别授权。', '\n\n',
  '## 失败保护', '\n',
  '主体不唯一、事实或时效存疑、版权肖像不清、绝对化或收益承诺、未授权发布时必须暂停。', '\n\n',
  '## 监测', '\n',
  '用同一问题集观察出现、准确、引用、竞品和承接；再与内容和经营指标分层复盘，不把相关变化直接写成因果。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`, `hotel_id`, `category_id`, `title`, `content`, `keywords`, `tags`,
  `sort_order`, `is_enabled`, `view_count`, `like_count`, `create_time`, `update_time`
)
SELECT
  0, 0, 7, @geo_unit_name, @geo_staff_content,
  '酒店GEO,GEO内容,信息总表,图片图库,关键词审核,蒸馏问题,文章任务卡,人工审核,发布授权,AI可见度',
  JSON_ARRAY('GEO内容', '图片图库', '关键词审核', '发布门禁', 'AI可见度', 'reference_only'),
  0, 1, 0, 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `knowledge_base` WHERE `hotel_id` = 0 AND `title` = @geo_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @geo_staff_content,
  `keywords` = '酒店GEO,GEO内容,信息总表,图片图库,关键词审核,蒸馏问题,文章任务卡,人工审核,发布授权,AI可见度',
  `tags` = JSON_ARRAY('GEO内容', '图片图库', '关键词审核', '发布门禁', 'AI可见度', 'reference_only'),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0 AND `title` = @geo_unit_name;
