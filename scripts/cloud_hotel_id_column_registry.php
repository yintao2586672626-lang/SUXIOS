<?php
declare(strict_types=1);

const CLOUD_HOTEL_ID_COLUMN_REGISTRY_CONTRACT = 'suxios.cloud_hotel_id_column_registry.v2';
const CLOUD_HOTEL_ID_COLUMN_POSITIVE = 'positive_system_hotel_id';
const CLOUD_HOTEL_ID_COLUMN_NEGATIVE = 'negative_non_system_hotel_id';
const CLOUD_HOTEL_ID_COLUMN_DERIVED = 'derived_readonly_system_hotel_id';
const CLOUD_HOTEL_ID_COLUMN_REQUIRED = 'required';
const CLOUD_HOTEL_ID_COLUMN_IF_PRESENT = 'if_present';
const CLOUD_HOTEL_ID_JSON_MUTABLE_ACTIVE = 'mutable_active_config';
const CLOUD_HOTEL_ID_JSON_IMMUTABLE_EVIDENCE = 'immutable_digest_bound_evidence';

/**
 * Explicit positive/negative classification for relational columns discovered
 * by the hotel-ID migration. The positive baseline includes every relation in
 * HotelDataMergeService::migrationPlans(), the cloud-only relations, and newer
 * versioned system-hotel ledgers. A name match never grants write authority.
 *
 * @return array<int,array{table:string,column:string,semantic:string,alias:string,classification:string,presence:string,source_column:?string}>
 */
function cloudHotelIdColumnRegistry(): array
{
    $positive = [
        // HotelDataMergeService::migrationPlans() authoritative baseline.
        ['users', 'hotel_id', 'hotel_id'],
        ['user_hotel_permissions', 'hotel_id', 'hotel_id'],
        ['daily_reports', 'hotel_id', 'hotel_id'],
        ['monthly_tasks', 'hotel_id', 'hotel_id'],
        ['ai_daily_reports', 'hotel_id', 'hotel_id'],
        ['ai_model_call_logs', 'hotel_id', 'hotel_id'],
        ['online_daily_data', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_ctrip_metric_facts', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_ctrip_entity_snapshots', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_ctrip_capture_runs', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_ctrip_capture_gaps', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_ctrip_reviews', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_ctrip_orders', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_ctrip_im_sessions', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_ctrip_review_order_matches', 'system_hotel_id', 'canonical_foreign_key'],
        ['platform_data_sources', 'system_hotel_id', 'canonical_foreign_key'],
        ['platform_data_sync_tasks', 'system_hotel_id', 'canonical_foreign_key'],
        ['platform_data_sync_logs', 'system_hotel_id', 'canonical_foreign_key'],
        ['platform_data_raw_records', 'system_hotel_id', 'canonical_foreign_key'],
        ['agent_configs', 'hotel_id', 'hotel_id'],
        ['agent_conversations', 'hotel_id', 'hotel_id'],
        ['agent_logs', 'hotel_id', 'hotel_id'],
        ['agent_tasks', 'hotel_id', 'hotel_id'],
        ['agent_work_orders', 'hotel_id', 'hotel_id'],
        ['operation_action_tracks', 'hotel_id', 'hotel_id'],
        ['operation_alerts', 'hotel_id', 'hotel_id'],
        ['operation_execution_intents', 'hotel_id', 'hotel_id'],
        ['operation_execution_tasks', 'hotel_id', 'hotel_id'],
        ['operation_logs', 'hotel_id', 'hotel_id'],
        ['field_mappings', 'hotel_id', 'hotel_id'],
        ['hotel_field_templates', 'hotel_id', 'hotel_id'],
        ['room_types', 'hotel_id', 'hotel_id'],
        ['devices', 'hotel_id', 'hotel_id'],
        ['energy_consumption', 'hotel_id', 'hotel_id'],
        ['energy_benchmarks', 'hotel_id', 'hotel_id'],
        ['energy_saving_suggestions', 'hotel_id', 'hotel_id'],
        ['maintenance_plans', 'hotel_id', 'hotel_id'],
        ['price_suggestions', 'hotel_id', 'hotel_id'],
        ['price_suggestion_shadow_replays', 'hotel_id', 'hotel_id'],
        ['demand_forecasts', 'hotel_id', 'hotel_id'],
        ['competitor_analysis', 'hotel_id', 'hotel_id'],
        ['competitor_price_log', 'store_id', 'legacy_store_id'],
        ['knowledge_categories', 'hotel_id', 'hotel_id'],
        ['knowledge_base', 'hotel_id', 'hotel_id'],
        ['knowledge_units', 'hotel_id', 'hotel_id'],
        ['complaint_feedbacks', 'hotel_id', 'hotel_id'],
        ['complaint_rooms', 'hotel_id', 'hotel_id'],
        ['opening_projects', 'hotel_id', 'hotel_id'],
        ['transfer_records', 'hotel_id', 'hotel_id'],
        ['system_notifications', 'hotel_id', 'hotel_id'],

        // Existing cloud migration relations not present in the merge service.
        ['cloud_browser_profiles', 'system_hotel_id', 'canonical_foreign_key'],
        ['cloud_collection_tasks', 'system_hotel_id', 'canonical_foreign_key'],
        ['competitor_device', 'store_id', 'legacy_store_id'],
        ['competitor_hotel', 'store_id', 'legacy_store_id'],
        ['competitor_wechat_robot', 'store_id', 'legacy_store_id'],
        ['dingdandao_operating_target_captures', 'hotel_id', 'hotel_id'],
        ['dingdandao_pms_integrations', 'hotel_id', 'hotel_id'],
        ['dingdandao_room_fee_capture_details', 'hotel_id', 'hotel_id'],
        ['hotel_collection_plans', 'system_hotel_id', 'canonical_foreign_key'],
        ['manual_notifications', 'hotel_id', 'hotel_id'],
        ['manual_notification_dispatch_attempts', 'hotel_id', 'hotel_id'],
        ['manual_notification_schedule_dispatches', 'hotel_id', 'hotel_id'],
        ['manual_notification_schedule_runs', 'scope_hotel_id', 'scope_hotel_id'],
        ['manual_notification_schedule_run_scopes', 'hotel_id', 'hotel_id'],
        ['meituan_cloud_pms_integrations', 'hotel_id', 'hotel_id'],
        ['operating_target_daily_records', 'hotel_id', 'hotel_id'],
        ['operating_target_daily_snapshots', 'hotel_id', 'hotel_id'],
        ['ota_profile_bindings', 'system_hotel_id', 'canonical_foreign_key'],
        ['users', 'default_hotel_id', 'default_hotel_id'],

        // Newer system-hotel facts, ledgers, automation and analysis relations.
        ['account_wechat_push_policies', 'hotel_id', 'hotel_id'],
        ['ai_report_generation_tasks', 'hotel_id', 'hotel_id'],
        ['ai_report_input_cache', 'hotel_id', 'hotel_id'],
        ['ai_daily_report_broadcast_snapshots', 'hotel_id', 'hotel_id'],
        ['weekly_operating_plan_snapshots', 'hotel_id', 'hotel_id'],
        ['operation_scheduled_review_scan_cursors', 'hotel_id', 'hotel_id'],
        ['user_learning_memory_events', 'hotel_id', 'hotel_id'],
        ['user_learning_memory_preferences', 'hotel_id', 'hotel_id'],
        ['user_guidance_journeys', 'hotel_id', 'hotel_id'],
        ['ai_suggestion_calibration_snapshots', 'hotel_id', 'hotel_id'],
        ['ai_suggestion_calibration_feedback_events', 'hotel_id', 'hotel_id'],
        ['ai_suggestion_calibration_observation_events', 'hotel_id', 'hotel_id'],
        ['ai_suggestion_strategy_comparisons', 'hotel_id', 'hotel_id'],
        ['ai_report_presentation_specs', 'hotel_id', 'hotel_id'],
        ['ai_report_presentation_artifacts', 'hotel_id', 'hotel_id'],
        ['dingdandao_pms_push_dispatches', 'hotel_id', 'hotel_id'],
        ['hotel_operating_cycles', 'hotel_id', 'hotel_id'],
        ['hotel_operating_cycle_events', 'hotel_id', 'hotel_id'],
        ['hotel_operating_cycle_evidence', 'hotel_id', 'hotel_id'],
        ['hotel_operating_goal_contracts', 'hotel_id', 'hotel_id'],
        ['hotel_data_analyst_feedbacks', 'hotel_id', 'hotel_id'],
        ['hotel_operating_memories', 'hotel_id', 'hotel_id'],
        ['hotel_operating_profiles', 'hotel_id', 'hotel_id'],
        ['hotel_operating_question_council_runs', 'hotel_id', 'hotel_id'],
        ['hotel_operating_question_model_responses', 'hotel_id', 'hotel_id'],
        ['hotel_operating_questions', 'hotel_id', 'hotel_id'],
        ['hotel_operating_sop_versions', 'hotel_id', 'hotel_id'],
        ['knowledge_candidates', 'hotel_id', 'hotel_id'],
        ['knowledge_promotion_events', 'hotel_id', 'hotel_id'],
        ['local_media_extractions', 'hotel_id', 'hotel_id'],
        ['manual_notification_rule_states', 'hotel_id', 'hotel_id'],
        ['manual_online_fetch_task_statuses', 'hotel_id', 'hotel_id'],
        ['meituan_cloud_pms_captures', 'hotel_id', 'hotel_id'],
        ['meituan_cloud_pms_room_type_details', 'hotel_id', 'hotel_id'],
        ['operating_goal_monitor_runs', 'hotel_id', 'hotel_id'],
        ['operation_action_lifecycle_events', 'hotel_id', 'hotel_id'],
        ['operation_action_reviews', 'hotel_id', 'hotel_id'],
        ['operation_effect_reviews', 'hotel_id', 'hotel_id'],
        ['operation_intervention_assessments', 'hotel_id', 'hotel_id'],
        ['operation_intervention_contracts', 'hotel_id', 'hotel_id'],
        ['ota_failure_wecom_deliveries', 'hotel_id', 'hotel_id'],
        ['ota_credentials', 'system_hotel_id', 'canonical_foreign_key'],
        ['online_data_correction_ledger', 'system_hotel_id', 'canonical_foreign_key'],
        ['temporal_forecast_snapshots', 'system_hotel_id', 'canonical_foreign_key'],
        ['analysis_reference_set_versions', 'system_hotel_id', 'canonical_foreign_key'],
        ['ai_report_human_reviews', 'hotel_id', 'hotel_id'],
        ['ota_credential_audit_logs', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_local_collector_account_hotels', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_local_collector_tasks', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_meituan_reviews', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_meituan_orders', 'system_hotel_id', 'canonical_foreign_key'],
        ['ota_meituan_review_order_matches', 'system_hotel_id', 'canonical_foreign_key'],
        ['temporal_forecast_trials', 'system_hotel_id', 'canonical_foreign_key'],
        ['temporal_forecast_trial_points', 'system_hotel_id', 'canonical_foreign_key'],
        ['hotel_collection_plan_runs', 'system_hotel_id', 'canonical_foreign_key'],
        ['hotel_autopilot_kick_queue', 'system_hotel_id', 'canonical_foreign_key'],
        ['hotel_automation_lifecycles', 'system_hotel_id', 'canonical_foreign_key'],
        ['hotel_collection_quality_judgments', 'system_hotel_id', 'canonical_foreign_key'],
        ['operating_opportunity_runs', 'system_hotel_id', 'canonical_foreign_key'],
        ['revenue_decision_snapshots', 'system_hotel_id', 'canonical_foreign_key'],
        ['manager_capability_cases', 'hotel_id', 'hotel_id'],
        ['manager_capability_score_snapshots', 'hotel_id', 'hotel_id'],
        ['manager_capability_case_adjustments', 'hotel_id', 'hotel_id'],
        ['manager_capability_score_reviews', 'hotel_id', 'hotel_id'],
        ['manager_capability_case_followups', 'hotel_id', 'hotel_id'],
        ['wecom_aibot_binding_codes', 'hotel_id', 'hotel_id'],
        ['wecom_inbound_bindings', 'hotel_id', 'hotel_id'],
        ['wecom_inbound_events', 'hotel_id', 'hotel_id'],
        ['wecom_task_receipts', 'hotel_id', 'hotel_id'],
        ['ota_settlement_import_batches', 'hotel_id', 'hotel_id'],
        ['hotel_on_books_snapshots', 'hotel_id', 'hotel_id'],
        ['hotel_demand_event_facts', 'hotel_id', 'hotel_id'],
        ['hotel_monthly_operating_finance_snapshots', 'hotel_id', 'hotel_id'],

        // Both sides of a controlled SOP replication are system-hotel aliases.
        ['hotel_operating_sop_replications', 'source_hotel_id', 'source_hotel_id'],
        ['hotel_operating_sop_replications', 'target_hotel_id', 'target_hotel_id'],
        ['hotel_operating_sop_replication_reviews', 'source_hotel_id', 'source_hotel_id'],
        ['hotel_operating_sop_replication_reviews', 'target_hotel_id', 'target_hotel_id'],

        // Primary key is last so all discovered relational aliases move first.
        ['hotels', 'id', 'canonical_primary_key'],
    ];

    $negative = [
        // Immutable evidence identity. The adjacent canonical hotel_id migrates;
        // source_hotel_id remains the original routing identity bound into digests.
        ['wecom_task_receipts', 'source_hotel_id', 'immutable_source_hotel_id_evidence'],
        ['ota_settlement_import_batches', 'source_hotel_id', 'immutable_source_hotel_id_evidence'],
        ['hotel_on_books_snapshots', 'source_hotel_id', 'immutable_source_hotel_id_evidence'],
        ['hotel_demand_event_facts', 'source_hotel_id', 'immutable_source_hotel_id_evidence'],
        ['hotel_monthly_operating_finance_snapshots', 'source_hotel_id', 'immutable_source_hotel_id_evidence'],
        // OTA/competitor/provider identifiers are not SUXIOS system hotel IDs.
        ['online_daily_data', 'hotel_id', 'ota_platform_hotel_id'],
        ['competitor_price_log', 'hotel_id', 'competitor_entity_id'],
        ['competitor_price_log', 'ota_hotel_id', 'ota_platform_hotel_id'],
        ['competitor_analysis', 'competitor_hotel_id', 'competitor_entity_id'],
        ['ota_ctrip_capture_runs', 'ota_hotel_id', 'ota_platform_hotel_id'],
        ['ota_ctrip_metric_facts', 'ota_hotel_id', 'ota_platform_hotel_id'],
        ['ota_ctrip_entity_snapshots', 'ota_hotel_id', 'ota_platform_hotel_id'],
        ['ota_ctrip_capture_gaps', 'ota_hotel_id', 'ota_platform_hotel_id'],
        ['ota_local_collector_account_hotels', 'platform_hotel_id', 'ota_platform_hotel_id'],
        ['ota_local_collector_account_hotels', 'active_platform_hotel_id', 'derived_ota_platform_hotel_id'],
        ['dingdandao_operating_target_captures', 'provider_hotel_id', 'provider_hotel_id'],
        ['dingdandao_pms_integrations', 'provider_hotel_id', 'provider_hotel_id'],
        ['meituan_cloud_pms_integrations', 'provider_hotel_id', 'provider_hotel_id'],
        ['meituan_cloud_pms_captures', 'provider_hotel_id', 'provider_hotel_id'],
    ];

    $derived = [
        // STORED generated identity: migrates only through system_hotel_id.
        ['ota_local_collector_account_hotels', 'active_system_hotel_id', 'active_system_hotel_id'],
    ];

    $registry = [];
    foreach ($positive as [$table, $column, $alias]) {
        $registry[] = [
            'table' => $table,
            'column' => $column,
            'semantic' => 'system_hotel_id',
            'alias' => $alias,
            'classification' => CLOUD_HOTEL_ID_COLUMN_POSITIVE,
            'presence' => $table === 'hotels' && $column === 'id'
                ? CLOUD_HOTEL_ID_COLUMN_REQUIRED
                : CLOUD_HOTEL_ID_COLUMN_IF_PRESENT,
            'source_column' => null,
        ];
    }
    foreach ($negative as [$table, $column, $alias]) {
        $registry[] = [
            'table' => $table,
            'column' => $column,
            'semantic' => 'non_system_hotel_id',
            'alias' => $alias,
            'classification' => CLOUD_HOTEL_ID_COLUMN_NEGATIVE,
            'presence' => CLOUD_HOTEL_ID_COLUMN_IF_PRESENT,
            'source_column' => null,
        ];
    }
    foreach ($derived as [$table, $column, $alias]) {
        $registry[] = [
            'table' => $table,
            'column' => $column,
            'semantic' => 'derived_system_hotel_id',
            'alias' => $alias,
            'classification' => CLOUD_HOTEL_ID_COLUMN_DERIVED,
            'presence' => CLOUD_HOTEL_ID_COLUMN_IF_PRESENT,
            'source_column' => 'system_hotel_id',
        ];
    }
    return $registry;
}

function cloudHotelIdColumnKey(string $table, string $column): string
{
    return trim($table) . '.' . trim($column);
}

/** @return array<string,array{table:string,column:string,semantic:string,alias:string,classification:string,presence:string,source_column:?string}> */
function cloudHotelIdColumnRegistryIndex(): array
{
    $index = [];
    foreach (cloudHotelIdColumnRegistry() as $entry) {
        $key = cloudHotelIdColumnKey($entry['table'], $entry['column']);
        if ($key === '.' || isset($index[$key])) {
            throw new LogicException('invalid_cloud_hotel_id_registry_entry:' . $key);
        }
        $index[$key] = $entry;
    }
    return $index;
}

/** @return array<int,array{table:string,column:string,semantic:string,alias:string,classification:string,presence:string,source_column:?string}> */
function cloudHotelIdPositiveColumnRegistry(): array
{
    return array_values(array_filter(
        cloudHotelIdColumnRegistry(),
        static fn(array $entry): bool => $entry['classification'] === CLOUD_HOTEL_ID_COLUMN_POSITIVE
    ));
}

/**
 * Pure classification used by both runtime scripts and DB-free contract tests.
 * Discovery never grants write authority to an unknown or negative column.
 *
 * @return array{key:string,registered:bool,classification:string,presence:?string,automatic_migration_eligible:bool,review_required:bool,reason:string,alias:?string,source_column:?string}
 */
function cloudHotelIdClassifyDiscoveredColumn(string $table, string $column): array
{
    $key = cloudHotelIdColumnKey($table, $column);
    $entry = cloudHotelIdColumnRegistryIndex()[$key] ?? null;
    if (is_array($entry)) {
        $positive = $entry['classification'] === CLOUD_HOTEL_ID_COLUMN_POSITIVE;
        $derived = $entry['classification'] === CLOUD_HOTEL_ID_COLUMN_DERIVED;
        return [
            'key' => $key,
            'registered' => true,
            'classification' => $entry['classification'],
            'presence' => $entry['presence'],
            'automatic_migration_eligible' => $positive,
            'review_required' => false,
            'reason' => $positive
                ? 'registered_positive_system_hotel_column'
                : ($derived
                    ? 'registered_derived_readonly_system_hotel_column'
                    : 'registered_negative_non_system_hotel_column'),
            'alias' => $entry['alias'],
            'source_column' => $entry['source_column'],
        ];
    }

    return [
        'key' => $key,
        'registered' => false,
        'classification' => 'unknown',
        'presence' => null,
        'automatic_migration_eligible' => false,
        'review_required' => true,
        'reason' => trim($column) === 'store_id'
            ? 'unregistered_store_id_requires_review'
            : 'unknown_hotel_id_candidate_requires_review',
        'alias' => null,
        'source_column' => null,
    ];
}

/** @return array<int,array{table:string,column:string}> */
function cloudHotelIdMigrationPlan(): array
{
    return array_map(
        static fn(array $entry): array => [
            'table' => $entry['table'],
            'column' => $entry['column'],
        ],
        cloudHotelIdPositiveColumnRegistry()
    );
}

/**
 * JSON identity policy is separate from relational-column authority. Active
 * OTA config must follow the renamed hotel; immutable evidence stays byte-for-
 * byte unchanged. Any unlisted JSON location containing the source ID blocks.
 *
 * @return array<int,array{table:string,column:string,policy:string,row_keys:array<int,string>,selector:string,identity_keys:array<int,string>,non_system_keys:array<int,string>}>
 */
function cloudHotelIdJsonPolicyRegistry(): array
{
    $mutable = [
        [
            'system_configs',
            'config_value',
            ['ctrip_config_list', 'meituan_config_list'],
            'config_key_allowlist',
            ['system_hotel_id', 'hotel_id'],
            [],
        ],
        [
            'platform_data_sources',
            'config_json',
            [],
            'system_hotel_scope',
            ['system_hotel_id', 'collector_hotel_id', 'source_system_hotel_id', 'destination_system_hotel_id'],
            ['hotel_id'],
        ],
    ];
    $immutable = [
        ['platform_data_raw_records', 'raw_payload'],
        ['strategy_data_snapshots', 'raw_json'],
        ['operation_action_tracks', 'before_data_json'],
        ['operation_action_tracks', 'after_data_json'],
        ['transfer_records', 'snapshot_json'],
        ['operation_execution_intents', 'evidence_json'],
        ['operation_execution_evidence', 'before_json'],
        ['operation_execution_evidence', 'after_json'],
        ['operation_execution_evidence', 'platform_response_json'],
        ['ai_daily_reports', 'snapshot_json'],
        ['ota_ctrip_reviews', 'raw_review_json'],
        ['ota_ctrip_orders', 'raw_order_json'],
        ['ota_ctrip_review_order_matches', 'evidence_json'],
        ['ota_meituan_reviews', 'raw_review_json'],
        ['ota_meituan_orders', 'raw_order_json'],
        ['ota_meituan_review_order_matches', 'evidence_json'],
        ['online_data_correction_ledger', 'changed_fields_json'],
        ['online_data_correction_ledger', 'before_json'],
        ['online_data_correction_ledger', 'after_json'],
        ['ai_report_human_reviews', 'before_json'],
        ['cloud_collection_tasks', 'receipt_evidence_json'],
        ['dingdandao_operating_target_captures', 'snapshot_json'],
        ['manual_notification_schedule_dispatches', 'payload_snapshot_json'],
        ['manual_notification_schedule_dispatches', 'source_snapshot_refs_json'],
        ['operating_target_daily_snapshots', 'snapshot_json'],
        ['meituan_cloud_pms_captures', 'snapshot_json'],
        ['hotel_operating_memories', 'evidence_refs_json'],
        ['hotel_operating_sop_versions', 'evidence_refs_json'],
        ['knowledge_candidate_revisions', 'evidence_refs_json'],
        ['knowledge_promotion_events', 'payload_json'],
        ['hotel_operating_sop_replication_reviews', 'review_json'],
        ['operating_goal_monitor_runs', 'primary_snapshot_json'],
        ['hotel_operating_cycle_events', 'payload_json'],
        ['operation_intervention_contracts', 'baseline_snapshot_json'],
        ['operation_intervention_assessments', 'followup_snapshot_json'],
        ['operation_intervention_assessments', 'stop_evidence_refs_json'],
        ['operation_action_lifecycle_events', 'event_payload_json'],
        ['operation_action_reviews', 'evidence_refs_json'],
        ['manager_capability_case_adjustments', 'effective_payload_json'],
    ];

    $registry = [];
    foreach ($mutable as [$table, $column, $rowKeys, $selector, $identityKeys, $nonSystemKeys]) {
        $registry[] = [
            'table' => $table,
            'column' => $column,
            'policy' => CLOUD_HOTEL_ID_JSON_MUTABLE_ACTIVE,
            'row_keys' => $rowKeys,
            'selector' => $selector,
            'identity_keys' => $identityKeys,
            'non_system_keys' => $nonSystemKeys,
        ];
    }
    foreach ($immutable as [$table, $column]) {
        $registry[] = [
            'table' => $table,
            'column' => $column,
            'policy' => CLOUD_HOTEL_ID_JSON_IMMUTABLE_EVIDENCE,
            'row_keys' => [],
            'selector' => 'all_rows_preserved',
            'identity_keys' => [],
            'non_system_keys' => [],
        ];
    }
    return $registry;
}

/** @return array<string,array{table:string,column:string,policy:string,row_keys:array<int,string>,selector:string,identity_keys:array<int,string>,non_system_keys:array<int,string>}> */
function cloudHotelIdJsonPolicyRegistryIndex(): array
{
    $index = [];
    foreach (cloudHotelIdJsonPolicyRegistry() as $entry) {
        $key = cloudHotelIdColumnKey($entry['table'], $entry['column']);
        if (isset($index[$key])) {
            throw new LogicException('duplicate_cloud_hotel_id_json_policy:' . $key);
        }
        $index[$key] = $entry;
    }
    return $index;
}

/** @param array<int,string> $identityKeys @return array{from_count:int,to_count:int,transformed:mixed} */
function cloudHotelIdTransformMutableJsonValue(
    mixed $value,
    int $fromHotelId,
    int $toHotelId,
    array $identityKeys
): array
{
    $fromCount = 0;
    $toCount = 0;
    if (!is_array($value)) {
        return ['from_count' => 0, 'to_count' => 0, 'transformed' => $value];
    }
    $transformed = [];
    foreach ($value as $key => $child) {
        if (in_array((string)$key, $identityKeys, true)) {
            $canonicalInteger = is_int($child) && $child > 0 ? $child : null;
            $canonicalString = is_string($child)
                && preg_match('/^[1-9][0-9]*$/D', $child) === 1
                && (string)(int)$child === $child
                    ? (int)$child
                    : null;
            if ($canonicalInteger === $fromHotelId || $canonicalString === $fromHotelId) {
                $fromCount++;
                $child = is_string($child) ? (string)$toHotelId : $toHotelId;
            } elseif ($canonicalInteger === $toHotelId || $canonicalString === $toHotelId) {
                $toCount++;
            }
        }
        if (is_array($child)) {
            $nested = cloudHotelIdTransformMutableJsonValue($child, $fromHotelId, $toHotelId, $identityKeys);
            $fromCount += $nested['from_count'];
            $toCount += $nested['to_count'];
            $child = $nested['transformed'];
        }
        $transformed[$key] = $child;
    }
    return ['from_count' => $fromCount, 'to_count' => $toCount, 'transformed' => $transformed];
}
