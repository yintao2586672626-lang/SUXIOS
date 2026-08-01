-- Absorb the reusable learning-kernel patterns found by static review of the
-- user-provided XYOS local archive. This migration copies no source code and
-- creates no current-hotel facts or external write authority.
--
-- Safe rerun contract:
-- - preserve operator-authored units and chunks;
-- - update only the exact seed owner + key + version rows;
-- - never delete or silently promote prior knowledge;
-- - keep the unversioned source, unknown license and unverified runtime explicit.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @xyos_kernel_version := '2026-07-31.1';
SET @xyos_kernel_reviewed_at := '2026-07-31 00:00:00';
SET @xyos_kernel_review_due_at := '2026-10-29 00:00:00';
SET @xyos_kernel_seed_owner := 'suxios.xyos_learning_kernel_knowledge';
SET @xyos_kernel_unit_name := 'XYOS学习内核吸收与安全演进合同';
SET @xyos_kernel_source := 'revenue_operations_decision_support';
SET @xyos_kernel_description := '从用户提供的XYOS本地源码归档静态复盘中提炼的宿析OS学习内核合同：保留多源上下文、人工纠正、草稿复核和反馈学习的有效模式；补上候选知识晋级、状态一致性、决策快照、审批重校验、幂等动作、结果验证和降级机制。该知识仅是全局架构参考，不是当前酒店事实，也不授权任何OTA、PMS或企微外部写入。';
SET @xyos_kernel_archive_sha256 := '3CFAD4FD3168839B404E84157C421818E8551EDE71CEB780C01493824DDB3802';
SET @xyos_kernel_source_manifest := JSON_OBJECT(
  'material_type', 'local_source_archive',
  'archive_name', 'ota_watchdog_deliver_20260730.zip',
  'archive_sha256', @xyos_kernel_archive_sha256,
  'reviewed_at', '2026-07-31',
  'version_status', 'filename_dated_unversioned_snapshot',
  'version_label', '2026-07-30_from_archive_filename_only',
  'commit_status', 'git_metadata_not_present_in_archive',
  'license_status', 'license_file_not_found_in_archive',
  'execution_status', 'static_review_only',
  'reuse_mode', 'behavioral_rebuild',
  'source_code_copied', false
);

INSERT INTO `knowledge_units` (
  `hotel_id`,
  `name`,
  `source`,
  `status`,
  `description`,
  `tags`,
  `created_by`,
  `lifecycle_status`,
  `lifecycle_reason`,
  `reviewed_at`,
  `review_due_at`,
  `known_knowns`,
  `known_unknowns`,
  `truth_profile_version`,
  `created_at`,
  `updated_at`
)
SELECT
  0,
  @xyos_kernel_unit_name,
  @xyos_kernel_source,
  'done',
  @xyos_kernel_description,
  JSON_ARRAY(
    '学习内核',
    '候选知识',
    '知识晋级',
    '一致性',
    '决策快照',
    '幂等动作',
    '评测门禁',
    '结果学习',
    'structured_knowledge',
    'external_source_code_reviewed',
    'manual_review_only'
  ),
  0,
  'active',
  'external_source_archive_statically_reviewed_and_rebuilt_as_guarded_suxios_contract',
  @xyos_kernel_reviewed_at,
  @xyos_kernel_review_due_at,
  JSON_ARRAY(
    '归档中存在检索、上下文组装、评审、纠正和记忆回写的学习链路。',
    '知识库、长期事实、人工纠正规则和短期工作记忆是不同上下文来源，必须保留来源和作用域。',
    '草稿、人工审核、自检和重写可降低未经复核内容直接进入业务动作的风险。',
    '关系库与向量索引是两个状态面，必须由显式版本、投影和对账合同保持一致。',
    '评测、审批、执行和结果验证是不同证据层，任何状态名都不能替代后一层证据。',
    '宿析已有执行意图幂等键与保存回读基础，可作为受控动作网关的一部分继续扩展。'
  ),
  JSON_ARRAY(
    '归档缺少可核验的Git提交、正式版本清单和许可证文件。',
    '本次只做静态复盘，没有运行XYOS服务、模型、数据库、向量库、缓存或消息发送链路。',
    '归档是否等同于其当前生产版本、生产配置和真实运行状态未知。',
    '异常重试下关系库、向量索引和缓存是否一致尚未通过故障注入验证。',
    '候选知识晋级、当前流水线回放评测和结果因果验证在宿析仍需逐步实现。',
    '这些架构合同对经营结果的增益尚无宿析真实业务样本证明。'
  ),
  @xyos_kernel_version,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_units`
  WHERE `name` = @xyos_kernel_unit_name
    AND `source` = @xyos_kernel_source
);

UPDATE `knowledge_units`
SET
  `hotel_id` = 0,
  `status` = 'done',
  `description` = @xyos_kernel_description,
  `tags` = JSON_ARRAY(
    '学习内核',
    '候选知识',
    '知识晋级',
    '一致性',
    '决策快照',
    '幂等动作',
    '评测门禁',
    '结果学习',
    'structured_knowledge',
    'external_source_code_reviewed',
    'manual_review_only'
  ),
  `created_by` = 0,
  `lifecycle_status` = 'active',
  `lifecycle_reason` = 'external_source_archive_statically_reviewed_and_rebuilt_as_guarded_suxios_contract',
  `reviewed_at` = @xyos_kernel_reviewed_at,
  `review_due_at` = @xyos_kernel_review_due_at,
  `known_knowns` = JSON_ARRAY(
    '归档中存在检索、上下文组装、评审、纠正和记忆回写的学习链路。',
    '知识库、长期事实、人工纠正规则和短期工作记忆是不同上下文来源，必须保留来源和作用域。',
    '草稿、人工审核、自检和重写可降低未经复核内容直接进入业务动作的风险。',
    '关系库与向量索引是两个状态面，必须由显式版本、投影和对账合同保持一致。',
    '评测、审批、执行和结果验证是不同证据层，任何状态名都不能替代后一层证据。',
    '宿析已有执行意图幂等键与保存回读基础，可作为受控动作网关的一部分继续扩展。'
  ),
  `known_unknowns` = JSON_ARRAY(
    '归档缺少可核验的Git提交、正式版本清单和许可证文件。',
    '本次只做静态复盘，没有运行XYOS服务、模型、数据库、向量库、缓存或消息发送链路。',
    '归档是否等同于其当前生产版本、生产配置和真实运行状态未知。',
    '异常重试下关系库、向量索引和缓存是否一致尚未通过故障注入验证。',
    '候选知识晋级、当前流水线回放评测和结果因果验证在宿析仍需逐步实现。',
    '这些架构合同对经营结果的增益尚无宿析真实业务样本证明。'
  ),
  `truth_profile_version` = @xyos_kernel_version,
  `updated_at` = NOW()
WHERE `name` = @xyos_kernel_unit_name
  AND `source` = @xyos_kernel_source;

SET @xyos_kernel_unit_id := (
  SELECT `unit_id`
  FROM `knowledge_units`
  WHERE `name` = @xyos_kernel_unit_name
    AND `source` = @xyos_kernel_source
  ORDER BY `unit_id` ASC
  LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_xyos_learning_kernel_chunks`;
CREATE TEMPORARY TABLE `tmp_xyos_learning_kernel_chunks` (
  `unit_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `content` JSON NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tmp_xyos_kernel_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_xyos_learning_kernel_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @xyos_kernel_unit_id,
  'xyos_source_scope_reference',
  JSON_OBJECT(
    'scope', 'global_architecture_reference',
    'evidence_level', 'external_source_code_reviewed_reference',
    'evidence_grade', 'B',
    'source_refs', JSON_ARRAY(
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/kb/retrieve.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/compose.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/correction.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/quality_gate.py'
    ),
    'observed_facts', JSON_ARRAY(
      '检索结果会进入策略上下文组装。',
      '上下文还会合并长期事实、人工纠正规则和短期群工作记忆。',
      '人工纠正会触发蒸馏、重写、合规检查和记忆回写。',
      '策略草稿具有草稿、审核、批准、发送等不同状态。'
    ),
    'review_inferences', JSON_ARRAY(
      '多来源上下文能增强连续学习，但只有在来源、作用域、时效和优先级可追溯时才安全。',
      '人工纠正适合作为候选知识证据，不能仅凭一次纠正自动变成全局事实。'
    ),
    'source_limitations', JSON_ARRAY(
      '归档未包含Git提交证据。',
      '归档未找到许可证文件。',
      '本次未执行服务或验证生产配置。',
      '文件名日期不等于正式发布版本。'
    ),
    'non_claims', JSON_ARRAY(
      '不声称XYOS当前生产环境与归档一致。',
      '不声称其消息发送、评测或学习链路已在真实环境闭环。',
      '不把源码中的示例、状态或阈值写成当前酒店事实。'
    ),
    'suxios_landing_state', JSON_OBJECT(
      'absorption_stage', 'integrated_knowledge_contract',
      'implementation_status', 'source_boundary_guarded',
      'runtime_verified', false
    )
  ),
  0,
  NOW()
WHERE @xyos_kernel_unit_id IS NOT NULL;

INSERT INTO `tmp_xyos_learning_kernel_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @xyos_kernel_unit_id,
  'candidate_knowledge_promotion_contract',
  JSON_OBJECT(
    'scope', 'global_architecture_reference',
    'evidence_level', 'external_source_code_reviewed_reference',
    'evidence_grade', 'B',
    'source_refs', JSON_ARRAY(
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/correction.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/kb/propose.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/agents/self_learn.py'
    ),
    'observed_source_behavior', JSON_ARRAY(
      '人工纠正可被蒸馏后写入共享知识层并赋予较高初始信任。',
      '系统会基于相似、跨店复现、引用和采纳状态生成合并、晋级或归档提议。',
      '自学习内容可由模型直答和网络资料蒸馏后直接写入共享知识层。'
    ),
    'absorbed_contract', JSON_OBJECT(
      'promotion_ladder', JSON_ARRAY(
        'observation',
        'pattern_candidate',
        'reviewed_action',
        'candidate_sop',
        'verified_sop'
      ),
      'required_evidence', JSON_ARRAY(
        'source_identity',
        'scope_and_applicability',
        'reviewer_and_review_time',
        'saved_exact_readback',
        'independent_recurrence',
        'outcome_verification',
        'conflict_and_negative_evidence'
      ),
      'promotion_rule', 'status_change_alone_never_promotes_knowledge',
      'globalization_rule', 'single_hotel_or_single_correction_never_becomes_global_without_cross_scope_review',
      'demotion_rule', 'expired_conflicted_harmful_or_nonreproducible_knowledge_is_demoted_or_quarantined',
      'missing_value_rule', 'unknown_remains_unknown_and_never_becomes_default_or_zero'
    ),
    'suxios_landing_state', JSON_OBJECT(
      'absorption_stage', 'integrated_knowledge_contract',
      'implementation_status', 'promotion_contract_defined_not_fully_automated',
      'runtime_verified', false
    )
  ),
  0,
  NOW()
WHERE @xyos_kernel_unit_id IS NOT NULL;

INSERT INTO `tmp_xyos_learning_kernel_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @xyos_kernel_unit_id,
  'knowledge_state_consistency_contract',
  JSON_OBJECT(
    'scope', 'global_architecture_reference',
    'evidence_level', 'external_source_code_reviewed_reference',
    'evidence_grade', 'B',
    'source_refs', JSON_ARRAY(
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/kb/writer.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/kb/vector.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/kb/retrieve.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/semantic_cache.py'
    ),
    'observed_source_risks', JSON_ARRAY(
      '关系库落库与向量索引写入没有共同事务或可见的持久化outbox。',
      '向量写入失败可保留仅关系库记录，反向失败可能留下孤立索引。',
      '向量检索主要按门店和知识层过滤，未见对归档、有效期和现行信任状态的同版本校验。',
      '缓存、关系库和向量索引缺少统一revision与强制对账证据。'
    ),
    'absorbed_contract', JSON_OBJECT(
      'canonical_truth_store', 'relational_knowledge_record',
      'projection_stores', JSON_ARRAY('vector_index', 'semantic_cache'),
      'required_identity', JSON_ARRAY(
        'tenant_id',
        'system_hotel_id',
        'scope',
        'source_id',
        'source_snapshot_hash',
        'knowledge_revision'
      ),
      'outbox_states', JSON_ARRAY(
        'pending_projection',
        'projected',
        'projection_failed',
        'reconcile_required'
      ),
      'read_rule', 'projection_revision_must_equal_canonical_revision_or_result_is_excluded',
      'archive_rule', 'archived_expired_quarantined_or_untrusted_records_are_filtered_by_canonical_state',
      'reconcile_rule', 'missing_or_orphaned_projection_is_detected_and_repaired_with_auditable_readback',
      'cache_rule', 'cache_key_includes_scope_source_revision_and_expires_closed_on_drift'
    ),
    'suxios_landing_state', JSON_OBJECT(
      'absorption_stage', 'integrated_knowledge_contract',
      'implementation_status', 'consistency_contract_defined_not_fully_implemented',
      'runtime_verified', false
    )
  ),
  0,
  NOW()
WHERE @xyos_kernel_unit_id IS NOT NULL;

INSERT INTO `tmp_xyos_learning_kernel_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @xyos_kernel_unit_id,
  'decision_snapshot_action_gateway_contract',
  JSON_OBJECT(
    'scope', 'global_architecture_reference',
    'evidence_level', 'external_source_code_reviewed_reference',
    'evidence_grade', 'B',
    'source_refs', JSON_ARRAY(
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/compose.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/quality_gate.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/agents/store_speaker.py',
      'suxios://app/service/KnowledgeSopExecutionProvenanceService.php',
      'suxios://app/service/OperationManagementService.php'
    ),
    'observed_source_risks', JSON_ARRAY(
      '自动发送资格在确定性质量门禁计算前形成，已通过自检的自动发送可绕过质量跳过状态。',
      '统一发声器有节流和发送结果记录，但未见以业务意图为核心的持久化幂等键与outbox。',
      '接口返回ok或草稿状态不能单独证明消息已送达、业务动作已生效或结果已产生。'
    ),
    'absorbed_contract', JSON_OBJECT(
      'decision_snapshot_identity', JSON_ARRAY(
        'tenant_id',
        'system_hotel_id',
        'platform',
        'external_store_or_profile_id',
        'business_date',
        'fact_scope',
        'source_snapshot_hash',
        'knowledge_revision',
        'model_or_rule_revision'
      ),
      'approval_contract', JSON_ARRAY(
        'approval_binds_to_decision_snapshot_hash',
        'execution_revalidates_identity_freshness_protection_line_and_permissions',
        'drift_requires_reapproval'
      ),
      'quality_contract', 'all_quality_and_permission_gates_must_pass_before_auto_send_or_external_action',
      'idempotency_contract', 'one_business_intent_one_stable_idempotency_key_one_canonical_result',
      'action_chain', JSON_ARRAY(
        'decision_snapshot',
        'human_or_policy_approval',
        'execution_revalidation',
        'idempotent_action_gateway',
        'save',
        'exact_readback',
        'external_receipt',
        'outcome_verification'
      ),
      'success_rule', 'local_save_api_ok_and_external_delivery_are_separate_evidence_layers',
      'failure_rule', 'failed_uncertain_or_unreadable_execution_remains_failed_or_unknown_and_never_becomes_success'
    ),
    'suxios_landing_state', JSON_OBJECT(
      'absorption_stage', 'partially_guarded',
      'implementation_status', 'execution_intent_idempotency_and_provenance_partially_landed',
      'runtime_verified', true,
      'remaining', JSON_ARRAY(
        'full_decision_snapshot_hash_binding',
        'unified_external_action_outbox',
        'outcome_verification_for_every_action_type'
      )
    )
  ),
  0,
  NOW()
WHERE @xyos_kernel_unit_id IS NOT NULL;

INSERT INTO `tmp_xyos_learning_kernel_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @xyos_kernel_unit_id,
  'evaluation_autonomy_gate_contract',
  JSON_OBJECT(
    'scope', 'global_architecture_reference',
    'evidence_level', 'external_source_code_reviewed_reference',
    'evidence_grade', 'B',
    'source_refs', JSON_ARRAY(
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/tasks/agent_eval.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/compose.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/quality_gate.py',
      'archive://ota_watchdog_deliver_20260730.zip#eval/promptfoo.yaml'
    ),
    'observed_source_risks', JSON_ARRAY(
      '离线评测比较已保存的候选输出与期望输出，不等于重放当前检索、组装、评审和发送流水线。',
      '评测分数未见与自动权限版本形成强绑定。',
      '质量门禁若只记录而不阻断自动发送，就不能称为执行门禁。'
    ),
    'absorbed_contract', JSON_OBJECT(
      'evaluation_input', JSON_ARRAY(
        'frozen_source_snapshot',
        'current_knowledge_revision',
        'current_retrieval_result',
        'current_prompt_and_model_revision',
        'current_policy_and_permission_revision'
      ),
      'evaluation_modes', JSON_ARRAY(
        'offline_regression',
        'current_pipeline_replay',
        'shadow_business_replay',
        'bounded_canary'
      ),
      'required_scores', JSON_ARRAY(
        'grounding',
        'identity_isolation',
        'missing_value_truthfulness',
        'action_safety',
        'idempotency',
        'outcome_quality'
      ),
      'autonomy_rule', 'evaluation_pass_plus_current_policy_gate_plus_bounded_scope_required',
      'failure_rule', 'any_identity_truthfulness_permission_or_delivery_failure_blocks_autonomy',
      'automatic_autonomy_unlock_from_status_only', false
    ),
    'suxios_landing_state', JSON_OBJECT(
      'absorption_stage', 'integrated_knowledge_contract',
      'implementation_status', 'current_pipeline_replay_gate_not_yet_complete',
      'runtime_verified', false
    )
  ),
  0,
  NOW()
WHERE @xyos_kernel_unit_id IS NOT NULL;

INSERT INTO `tmp_xyos_learning_kernel_chunks`
  (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  @xyos_kernel_unit_id,
  'outcome_learning_contract',
  JSON_OBJECT(
    'scope', 'global_architecture_reference',
    'evidence_level', 'external_source_code_reviewed_reference',
    'evidence_grade', 'B',
    'source_refs', JSON_ARRAY(
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/kb/feedback.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/kb/propose.py',
      'archive://ota_watchdog_deliver_20260730.zip#backend/app/strategy/correction.py'
    ),
    'observed_source_behavior', JSON_ARRAY(
      '引用、批准、发送和人工纠正会成为知识信任或治理信号。',
      '跨店相似、引用次数和草稿采纳率被用于候选晋级或归档。',
      '这些信号主要证明使用与反馈，不自动证明经营因果。'
    ),
    'absorbed_contract', JSON_OBJECT(
      'separate_events', JSON_ARRAY(
        'knowledge_retrieved',
        'advice_generated',
        'human_approved',
        'action_saved',
        'external_action_confirmed',
        'outcome_observed',
        'outcome_attributed',
        'knowledge_promoted_or_demoted'
      ),
      'outcome_identity', JSON_ARRAY(
        'tenant_id',
        'system_hotel_id',
        'platform',
        'business_date',
        'decision_snapshot_hash',
        'execution_intent_idempotency_key',
        'measurement_window',
        'metric_definition'
      ),
      'promotion_rule', 'only_traceable_repeated_and_outcome_verified_patterns_can_advance',
      'causality_rule', 'post_action_movement_is_not_causality_without_comparable_baseline_and_confound_review',
      'negative_evidence_rule', 'failed_harmful_reversed_or_nonreproducible_outcomes_reduce_trust_and_can_demote',
      'retention_rule', 'retain_raw_evidence_and_decision_snapshot_even_when_summary_knowledge_is_revised'
    ),
    'suxios_landing_state', JSON_OBJECT(
      'absorption_stage', 'integrated_knowledge_contract',
      'implementation_status', 'outcome_learning_contract_defined_not_fully_automated',
      'runtime_verified', false
    )
  ),
  0,
  NOW()
WHERE @xyos_kernel_unit_id IS NOT NULL;

UPDATE `tmp_xyos_learning_kernel_chunks`
SET `content` = JSON_SET(
  `content`,
  '$.content_key', CONCAT('xyos_learning_kernel:', `type`),
  '$.content_type', 'governance_contract',
  '$.module_id', 'xyos_learning_kernel',
  '$.roles', JSON_ARRAY(
    'owner',
    'product_manager',
    'knowledge_reviewer',
    'revenue_manager',
    'system_operator'
  ),
  '$.scenes', JSON_ARRAY(
    'knowledge_review',
    'ai_decision_design',
    'operation_action_approval',
    'weekly_learning_review',
    'incident_reconciliation'
  ),
  '$.platforms', JSON_ARRAY('suxios_internal'),
  '$.source_manifest', JSON_EXTRACT(@xyos_kernel_source_manifest, '$'),
  '$.reviewed_at', @xyos_kernel_reviewed_at,
  '$.review_due_at', @xyos_kernel_review_due_at,
  '$.review_interval_days', 90,
  '$.freshness_policy', 'review_due_reference_only',
  '$.allowed_uses', JSON_ARRAY(
    'architecture_decision_support',
    'knowledge_governance_design',
    'manual_review',
    'test_contract_design'
  ),
  '$.blocked_uses', JSON_ARRAY(
    'current_hotel_fact',
    'operation_task_creation',
    'operation_execution',
    'automatic_operation_task',
    'automatic_ota_write'
  ),
  '$.seed_owner', @xyos_kernel_seed_owner,
  '$.seed_key', CONCAT('xyos_learning_kernel:', `type`),
  '$.seed_version', @xyos_kernel_version,
  '$.lifecycle_status', 'active',
  '$.contains_current_hotel_fact', false,
  '$.external_write_authorized', false
);

UPDATE `knowledge_chunks` AS `existing`
INNER JOIN `tmp_xyos_learning_kernel_chunks` AS `seed`
  ON `existing`.`unit_id` = `seed`.`unit_id`
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_owner'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_key'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
  AND JSON_UNQUOTE(JSON_EXTRACT(
    CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
    '$.seed_version'
  )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
SET
  `existing`.`type` = `seed`.`type`,
  `existing`.`content` = `seed`.`content`,
  `existing`.`created_by` = `seed`.`created_by`;

INSERT INTO `knowledge_chunks` (`unit_id`, `type`, `content`, `created_by`, `created_at`)
SELECT
  `seed`.`unit_id`,
  `seed`.`type`,
  `seed`.`content`,
  `seed`.`created_by`,
  `seed`.`created_at`
FROM `tmp_xyos_learning_kernel_chunks` AS `seed`
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_chunks` AS `existing`
  WHERE `existing`.`unit_id` = `seed`.`unit_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_owner'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_owner'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_key'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_key'))
    AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END,
      '$.seed_version'
    )) = JSON_UNQUOTE(JSON_EXTRACT(`seed`.`content`, '$.seed_version'))
);

DROP TEMPORARY TABLE `tmp_xyos_learning_kernel_chunks`;

SET @xyos_kernel_staff_content := CONCAT(
  '# XYOS学习内核吸收与安全演进合同', '\n\n',
  '## 吸收了什么', '\n',
  '保留多源上下文、知识检索、人工纠正、草稿审核、自检重写和反馈学习的有效模式；不复制源码，不继承其默认信任、自动晋级或自动发送行为。', '\n\n',
  '## 宿析闭环', '\n',
  '已核验事实 → 候选知识 → 决策快照 → 审批重校验 → 幂等动作网关 → 保存与精确回读 → 外部回执 → 结果验证 → 晋级、保留或降级。', '\n\n',
  '## 知识晋级', '\n',
  '观察、模式候选、已复核动作、候选SOP、已验证SOP逐级晋升。一次纠正、一次采纳、一次发送或一次指标变化都不能单独变成全局知识。', '\n\n',
  '## 状态一致性', '\n',
  '关系库是知识真相源，向量索引和缓存只是带revision的投影；版本不一致、过期、归档、隔离或缺少对账时必须排除。', '\n\n',
  '## 行动与评测', '\n',
  '所有质量、身份、时效和权限门禁必须在自动发送或外部动作前通过。评测要重放当前检索到执行流水线，状态名和离线分数不能单独解锁自治。', '\n\n',
  '## 已知的未知', '\n',
  '来源归档没有Git提交、正式版本和许可证证据，本次未运行XYOS；宿析的完整晋级引擎、统一outbox、当前流水线回放与因果结果学习仍需逐步实现。', '\n\n',
  '## 保护边界', '\n',
  '本知识是全局架构参考，不是当前酒店事实，不生成经营数值，不创建运营任务，不授权OTA、PMS或企微自动写入。'
);

INSERT INTO `knowledge_base` (
  `tenant_id`,
  `hotel_id`,
  `category_id`,
  `title`,
  `content`,
  `keywords`,
  `tags`,
  `sort_order`,
  `is_enabled`,
  `view_count`,
  `like_count`,
  `create_time`,
  `update_time`
)
SELECT
  0,
  0,
  7,
  @xyos_kernel_unit_name,
  @xyos_kernel_staff_content,
  '学习内核,候选知识,知识晋级,向量索引,一致性,决策快照,审批重校验,幂等动作,评测门禁,结果学习,outbox,revision',
  JSON_ARRAY(
    '学习内核',
    '知识治理',
    '决策快照',
    '幂等动作',
    '结果学习',
    'manual_review_only'
  ),
  0,
  1,
  0,
  0,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM `knowledge_base`
  WHERE `hotel_id` = 0
    AND `title` = @xyos_kernel_unit_name
);

UPDATE `knowledge_base`
SET
  `tenant_id` = 0,
  `category_id` = 7,
  `content` = @xyos_kernel_staff_content,
  `keywords` = '学习内核,候选知识,知识晋级,向量索引,一致性,决策快照,审批重校验,幂等动作,评测门禁,结果学习,outbox,revision',
  `tags` = JSON_ARRAY(
    '学习内核',
    '知识治理',
    '决策快照',
    '幂等动作',
    '结果学习',
    'manual_review_only'
  ),
  `is_enabled` = 1,
  `update_time` = NOW()
WHERE `hotel_id` = 0
  AND `title` = @xyos_kernel_unit_name;
