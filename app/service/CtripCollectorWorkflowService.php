<?php
declare(strict_types=1);

namespace app\service;

final class CtripCollectorWorkflowService
{
    private const FLOW_DEFINITIONS = [
        'review_only' => [
            'label' => 'ctrip_review_only',
            'capture_sections' => 'comment_review',
            'capture_plan' => 'full',
            'data_period' => 'historical_daily',
            'phase' => 'review',
            'method' => 'browser_profile',
            'privacy_boundary' => 'aggregate_metrics_only_no_review_text',
            'required_core_fields' => ['comment_score', 'comment_count', 'bad_review_count'],
        ],
        'full' => [
            'label' => 'ctrip_full_collection',
            'capture_sections' => 'wide',
            'capture_plan' => 'full',
            'data_period' => 'historical_daily',
            'phase' => 'full',
            'method' => 'browser_profile',
            'privacy_boundary' => 'ota_channel_metrics_only',
            'required_core_fields' => ['order_amount', 'room_nights', 'order_count', 'traffic', 'review_score'],
        ],
        'historical_review' => [
            'label' => 'ctrip_historical_traffic_review',
            'capture_sections' => 'traffic_report',
            'capture_plan' => 'historical_review',
            'data_period' => 'historical_daily',
            'phase' => 'historical_review',
            'method' => 'browser_profile',
            'privacy_boundary' => 'ctrip_ota_channel_historical_aggregates_only',
            'required_core_fields' => [
                'list_exposure',
                'detail_exposure',
                'order_filling_num',
                'order_submit_num',
                'flow_rate',
            ],
        ],
        'realtime' => [
            'label' => 'ctrip_realtime',
            'capture_sections' => 'business_overview,traffic_report',
            'capture_plan' => 'realtime_broadcast',
            'data_period' => 'realtime_snapshot',
            'phase' => 'realtime',
            'method' => 'browser_profile',
            'privacy_boundary' => 'realtime_snapshot_not_final_daily_truth',
            'required_core_fields' => ['ctrip_orders', 'ctrip_room_nights', 'ctrip_visitor', 'ctrip_rank'],
        ],
        'intraday_trend' => [
            'label' => 'ctrip_intraday_traffic_trend',
            'capture_sections' => 'traffic_report',
            'capture_plan' => 'intraday_trend',
            'data_period' => 'realtime_snapshot',
            'phase' => 'intraday_trend',
            'method' => 'browser_profile',
            'privacy_boundary' => 'ctrip_ota_channel_hourly_aggregate_only',
            'required_core_fields' => ['visitor_trend'],
        ],
        'future_demand' => [
            'label' => 'ctrip_future_search_demand',
            'capture_sections' => 'traffic_report',
            'capture_plan' => 'future_demand',
            'data_period' => 'next_30_days',
            'phase' => 'future_demand',
            'method' => 'browser_profile',
            'privacy_boundary' => 'ctrip_ota_channel_search_aggregate_only',
            'required_core_fields' => ['future_search_pv', 'future_search_uv', 'future_search_conversion_rate'],
        ],
    ];

    private const FAMILY_CHANNELS = ['ctrip', 'qunar', 'tongcheng', 'zhixing'];

    private const CHANNEL_ALIASES = [
        'ctrip' => 'ctrip',
        'xiecheng' => 'ctrip',
        'qunar' => 'qunar',
        'tongcheng' => 'tongcheng',
        'tongchenglvxing' => 'tongcheng',
        'zhixing' => 'zhixing',
        'zhixinghuochepiao' => 'zhixing',
    ];

    /**
     * @return array<int, string>
     */
    public static function ctripFamilyChannels(): array
    {
        return self::FAMILY_CHANNELS;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function applyFlowOptions(array $options, array $config = []): array
    {
        $flow = $this->normalizeFlow($this->firstValue($options, $config, ['collector_flow', 'collectorFlow', 'flow', 'phase']));
        if ($flow === '') {
            return $options;
        }

        $definition = self::FLOW_DEFINITIONS[$flow];
        $boundedSections = trim((string)($options['bounded_capture_sections'] ?? $options['boundedCaptureSections'] ?? ''));
        $options['collector_flow'] = $flow;
        // An automated yesterday backfill deliberately runs one missing
        // section at a time. It must not be widened by the source's normal
        // full-collection flow; manual/full callers do not set this option.
        $options['capture_sections'] = $boundedSections !== ''
            ? $boundedSections
            : $definition['capture_sections'];
        $options['profile_sections'] = $options['capture_sections'];
        $options['capture_plan'] = $this->firstValue(
            $options,
            [],
            ['capture_plan', 'capturePlan', 'ctrip_capture_plan', 'ctripCapturePlan']
        ) ?? $definition['capture_plan'];
        $options['data_period'] = $definition['data_period'];
        if (in_array($flow, ['realtime', 'intraday_trend', 'future_demand'], true)
            && $this->firstValue($options, [], ['data_date', 'dataDate']) === null) {
            $options['data_date'] = date('Y-m-d');
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function collectionGate(array $source, array $options = []): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $flow = $this->normalizeFlow($this->firstValue($options, $config, ['collector_flow', 'collectorFlow', 'flow', 'phase']));
        if (!$this->collectCtripEnabled($source, $options, $config)) {
            return [
                'allowed' => false,
                'status' => 'skipped',
                'reason' => 'collect_ctrip_disabled',
                'message' => 'Ctrip collection is disabled for this source.',
                'collector_flow' => $flow,
            ];
        }

        $profileStatus = strtolower(trim((string)($this->firstValue($options, $config, ['profile_status', 'profileStatus', 'session_status', 'sessionStatus']) ?? '')));
        if (in_array($profileStatus, ['expired', 'session_expired', 'login_expired'], true)) {
            return [
                'allowed' => false,
                'status' => 'waiting_login',
                'reason' => 'session_expired',
                'message' => 'Ctrip browser Profile session is expired and must be re-logged in.',
                'collector_flow' => $flow,
            ];
        }
        if (in_array($profileStatus, ['locked', 'profile_locked', 'resource_busy_login'], true)) {
            return [
                'allowed' => false,
                'status' => 'skipped_locked',
                'reason' => 'profile_locked',
                'message' => 'Ctrip browser Profile is locked by another capture task.',
                'collector_flow' => $flow,
            ];
        }

        return [
            'allowed' => true,
            'status' => 'ready',
            'reason' => '',
            'message' => 'Ctrip collection gate passed.',
            'collector_flow' => $flow,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function buildContract(array $source = [], array $options = []): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $flow = $this->normalizeFlow($this->firstValue($options, $config, ['collector_flow', 'collectorFlow', 'flow', 'phase']));
        $gate = $this->collectionGate($source, $options);

        return [
            'scope' => 'ctrip_ota_channel',
            'source_chain' => 'OTA data -> revenue analysis -> AI decisions -> operations management -> investment decisions',
            'collector_flow' => $flow,
            'gate' => $gate,
            'flows' => self::FLOW_DEFINITIONS,
            'temporal_push_contract' => [
                'version' => 'ctrip_temporal_push.v2',
                'preview_endpoint' => '/api/online-data/ctrip-temporal-broadcast-preview',
                'notification_template_type' => 'ctrip_temporal_report',
                'segments' => [
                    'past' => [
                        'label' => '过去复盘',
                        'collector_flows' => ['historical_review'],
                        'windows' => ['yesterday', 'last_7_days', 'last_30_days'],
                        'push_items' => [
                            'list_exposure',
                            'detail_exposure',
                            'flow_rate',
                            'order_filling_num',
                            'order_submit_num',
                            'order_trend',
                        ],
                        'decision_use' => '复盘流量漏斗、识别转化损失阶段和趋势变化',
                    ],
                    'present' => [
                        'label' => '如今实时',
                        'collector_flows' => ['realtime', 'intraday_trend'],
                        'push_items' => [
                            'starting_price',
                            'realtime_visitors',
                            'last_week_visitors',
                            'competitor_avg_visitor',
                            'traffic_rank',
                            'booking_orders',
                            'in_house_room_nights',
                            'list_exposure',
                            'detail_exposure',
                            'flow_rate',
                            'order_filling_num',
                            'order_submit_num',
                            'intraday_visitor_trend',
                        ],
                        'decision_use' => '判断满房或在售状态、当日流量节奏及预订转化',
                    ],
                    'future' => [
                        'label' => '未来研判',
                        'collector_flows' => ['future_demand'],
                        'windows' => ['next_30_days'],
                        'push_items' => [
                            'future_search_pv',
                            'future_search_uv',
                            'future_search_order_count',
                            'future_search_conversion_rate',
                            'competitor_future_search_pv',
                            'competitor_future_search_uv',
                            'competitor_future_search_conversion_rate',
                        ],
                        'decision_use' => '研判未来需求热度、本店与竞争圈差距及机会日期',
                    ],
                ],
                'collection_runs' => [
                    'past' => [
                        'collector_flow' => 'historical_review',
                        'capture_plan' => 'historical_review',
                        'data_period' => 'historical_daily',
                        'date_rule' => 'previous_day_after_finalization',
                        'cadence' => 'once_daily',
                    ],
                    'present' => [
                        'collector_flow' => 'realtime',
                        'capture_plan' => 'realtime_broadcast',
                        'data_period' => 'realtime_snapshot',
                        'date_rule' => 'current_day',
                        'cadence' => 'hourly_or_on_demand',
                        'includes_intraday_trend' => true,
                    ],
                    'future' => [
                        'collector_flow' => 'future_demand',
                        'capture_plan' => 'future_demand',
                        'data_period' => 'next_30_days',
                        'date_rule' => 'current_capture_day',
                        'cadence' => 'once_daily',
                    ],
                ],
                'orchestration_rules' => [
                    'collection_endpoint' => '/api/online-data/auto-fetch',
                    'collector_flow_parameter' => 'ctrip_collector_flow',
                    'one_flow_per_capture' => true,
                    'profile_runs_are_serial' => true,
                    'scheduled_refresh_policy' => [
                        'realtime' => 'every_due_dispatch',
                        'historical_review' => 'once_daily_when_missing',
                        'future_demand' => 'once_daily_when_missing',
                    ],
                    'scheduled_dispatch_requires_current_realtime_readback' => true,
                    'scheduled_plan_requires_successful_same_robot_test' => true,
                    'structured_json_before_dom' => true,
                    'preview_requires_saved_readback' => true,
                    'preview_does_not_send' => true,
                    'timer_is_not_enabled_by_contract' => true,
                ],
                'rendering_rules' => [
                    'captured_at_is_distinct_from_sent_at' => true,
                    'stale_warning_after_seconds' => 3600,
                    'missing_value_policy' => 'omit_from_external_message_keep_internal_gap',
                    'starting_price_scope' => 'starting_price_only',
                    'zero_starting_price_policy' => 'ctrip_channel_no_sellable_room_likely_full_not_whole_hotel',
                    'excluded_items' => ['competitor_circle_rank', 'non_starting_price_fields'],
                    'ota_scope_only' => true,
                ],
                'snapshot_rules' => [
                    'fact_source' => 'online_daily_data',
                    'readback_verified_required' => true,
                    'lineage_required' => [
                        'data_source_id',
                        'sync_task_id',
                        'source_trace_id',
                    ],
                    'latest_batch_only' => true,
                    'older_batch_value_borrowing' => false,
                    'present_date_policy' => 'same_hotel_same_day_realtime_snapshot',
                    'past_date_policy' => 'finalized_historical_daily_only',
                    'future_date_policy' => 'same_capture_batch_targets_within_30_days',
                ],
                'quality_states' => ['available', 'partial', 'stale', 'blocked'],
                'derivation_rules' => [
                    'captured_zero_is_valid' => true,
                    'missing_is_never_zero' => true,
                    'rate_requires_explicit_numerator_denominator_and_positive_denominator' => true,
                    'future_search_conversion_is_never_inferred_from_unknown_formula' => true,
                ],
                'delivery_modes' => [
                    'realtime' => 'hourly_on_new_snapshot',
                    'daily' => 'once_daily_after_history_finalized',
                    'review' => 'once_after_yesterday_finalized',
                    'future' => 'daily_or_new_capture',
                ],
                'deduplication' => [
                    'fingerprint_scope' => 'hotel_mode_batch_selected_facts_status',
                    'baseline_without_alert' => true,
                    'unchanged_snapshot_suppressed' => true,
                    'stale_state_transition_may_alert_once' => true,
                ],
            ],
            'family_channel_rule' => [
                'channels' => self::FAMILY_CHANNELS,
                'source_policy' => 'keep source=ctrip; store channel in platform/dimension/raw_data.channel',
                'zero_room_nights_policy' => 'all-zero Ctrip-family room nights are suspicious when collect_ctrip is enabled',
                'pms_policy' => 'do_not_fill_ota_room_nights_from_pms',
            ],
            'safety' => [
                'respect_collect_ctrip_false' => true,
                'skip_expired_or_locked_profile' => true,
                'stealth_policy' => 'stealth_false_for_ctrip_micro_frontend',
                'write_policy' => 'write only through PlatformDataSyncService/OtaBrowserAssistImportService',
            ],
        ];
    }

    public function normalizeFlow(mixed $value): string
    {
        $flow = strtolower(str_replace(['-', ' '], '_', trim((string)$value)));
        return match ($flow) {
            'review', 'reviews', 'comments', 'comment_review', 'review_only' => 'review_only',
            'full', 'wide', 'daily_full', 'full_daily', 'complete' => 'full',
            'past', 'history', 'historical', 'historical_review', 'past_review' => 'historical_review',
            'realtime', 'real_time', 'live', 'snapshot', 'today_realtime' => 'realtime',
            'intraday', 'intraday_trend', 'traffic_trend', 'hourly_trend' => 'intraday_trend',
            'future', 'future_demand', 'search_demand', 'future_search' => 'future_demand',
            default => '',
        };
    }

    public function normalizeFamilyChannel(mixed $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $text) ?: $text);
        return self::CHANNEL_ALIASES[$key] ?? self::CHANNEL_ALIASES[$text] ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function validateRealtimeRows(array $rows): array
    {
        $found = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($this->realtimeCoreEvidence($row) as $field => $evidence) {
                if ($evidence !== null) {
                    $found[$field] = $evidence;
                }
            }
        }

        return [
            'status' => $found === [] ? 'blocked' : 'ready',
            'required_any' => self::FLOW_DEFINITIONS['realtime']['required_core_fields'],
            'found_fields' => array_keys($found),
            'field_evidence' => $found,
            'blocked_reason' => $found === [] ? 'realtime_core_fields_missing' : '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function auditSubChannels(array $rows, bool $collectCtrip = true): array
    {
        $channels = [];
        $warnings = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = $this->rawData($row);
            $channel = $this->resolveRowChannel($row, $raw);
            if ($channel === '') {
                continue;
            }
            $channels[$channel] = $channels[$channel] ?? [
                'row_count' => 0,
                'room_night_values' => [],
            ];
            $channels[$channel]['row_count']++;
            $roomNights = $this->firstNumeric($row, $raw, ['room_nights', 'roomNights', 'quantity', 'night_count', 'nightCount', 'checkout_room_nights']);
            if ($roomNights !== null) {
                $channels[$channel]['room_night_values'][] = $roomNights;
            }
            $source = strtolower(trim((string)($row['source'] ?? '')));
            if ($source !== '' && $source !== 'ctrip' && in_array($channel, self::FAMILY_CHANNELS, true)) {
                $warnings[] = [
                    'code' => 'ctrip_family_channel_source_not_ctrip',
                    'channel' => $channel,
                    'source' => $source,
                    'message' => 'Ctrip-family sub-channel rows must keep source=ctrip.',
                ];
            }
        }

        if ($collectCtrip) {
            foreach ($channels as $channel => $summary) {
                $values = $summary['room_night_values'];
                if ($values !== [] && count(array_filter($values, static fn($value): bool => (float)$value !== 0.0)) === 0) {
                    $warnings[] = [
                        'code' => 'ctrip_family_room_nights_all_zero_suspicious',
                        'channel' => $channel,
                        'message' => 'Ctrip-family room nights are all zero; inspect the channel-tab API before treating this as truth.',
                    ];
                }
            }
        }

        return [
            'status' => $warnings === [] ? 'ready' : 'warning',
            'channels' => $channels,
            'warnings' => $warnings,
            'pms_policy' => 'do_not_fill_ota_room_nights_from_pms',
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     */
    private function collectCtripEnabled(array $source, array $options, array $config): bool
    {
        foreach (['collect_ctrip', 'collectCtrip', 'ctrip_enabled', 'ctripEnabled'] as $key) {
            if (array_key_exists($key, $options)) {
                return $this->truthy($options[$key]);
            }
            if (array_key_exists($key, $config)) {
                return $this->truthy($config[$key]);
            }
            if (array_key_exists($key, $source)) {
                return $this->truthy($source[$key]);
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed|null>
     */
    private function realtimeCoreEvidence(array $row): array
    {
        $raw = $this->rawData($row);
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        return [
            'ctrip_orders' => $this->firstPresent($row, $raw, ['ctrip_orders', 'orders', 'order_count', 'orderCount', 'book_order_num', 'order_submit_num']),
            'ctrip_room_nights' => $this->firstPresent($row, $raw, ['ctrip_room_nights', 'room_nights', 'roomNights', 'quantity', 'night_count', 'nightCount']),
            'ctrip_visitor' => $this->firstPresent($row, $raw, ['ctrip_visitor', 'visitor', 'visitors', 'visitorTotal', 'detail_exposure', 'realtime_visitors']),
            'ctrip_rank' => $dataType === 'peer_rank'
                ? $this->firstPresent($row, $raw, ['ctrip_rank', 'rank', 'realtime_rank', 'data_value'])
                : $this->firstPresent($row, $raw, ['ctrip_rank', 'rank', 'realtime_rank']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function resolveRowChannel(array $row, array $raw): string
    {
        foreach ([$raw['channel'] ?? null, $row['channel'] ?? null, $row['platform'] ?? null] as $value) {
            $channel = $this->normalizeFamilyChannel($value);
            if ($channel !== '') {
                return $channel;
            }
        }
        $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
        if (str_starts_with($dimension, 'realtime:')) {
            $parts = explode(':', $dimension);
            return $this->normalizeFamilyChannel($parts[1] ?? '');
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rawData(array $row): array
    {
        $raw = $row['raw_data'] ?? [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     * @return mixed
     */
    private function firstPresent(array $row, array $raw, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return ['path' => $key, 'value' => $row[$key]];
            }
        }
        $flat = $this->flattenArray($raw);
        foreach ($keys as $key) {
            $needle = strtolower($key);
            foreach ($flat as $path => $value) {
                if (strtolower(basename(str_replace('.', '/', $path))) === $needle && $value !== null && $value !== '') {
                    return ['path' => 'raw_data.' . $path, 'value' => $value];
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function firstNumeric(array $row, array $raw, array $keys): ?float
    {
        $evidence = $this->firstPresent($row, $raw, $keys);
        if (!is_array($evidence) || !is_numeric($evidence['value'] ?? null)) {
            return null;
        }
        return (float)$evidence['value'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function flattenArray(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += $this->flattenArray($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }
        return $flat;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     * @param array<int, string> $keys
     * @return mixed|null
     */
    private function firstValue(array $options, array $config, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $options)) {
                return $options[$key];
            }
            if (array_key_exists($key, $config)) {
                return $config[$key];
            }
        }
        return null;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        $text = strtolower(trim((string)$value));
        return !in_array($text, ['', '0', 'false', 'no', 'off', 'disabled'], true);
    }
}
