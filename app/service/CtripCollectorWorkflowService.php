<?php
declare(strict_types=1);

namespace app\service;

final class CtripCollectorWorkflowService
{
    private const FLOW_DEFINITIONS = [
        'review_only' => [
            'label' => 'ctrip_review_only',
            'capture_sections' => 'comment_review',
            'data_period' => 'historical_daily',
            'phase' => 'review',
            'method' => 'browser_profile',
            'privacy_boundary' => 'aggregate_metrics_only_no_review_text',
            'required_core_fields' => ['comment_score', 'comment_count', 'bad_review_count'],
        ],
        'full' => [
            'label' => 'ctrip_full_collection',
            'capture_sections' => 'wide',
            'data_period' => 'historical_daily',
            'phase' => 'full',
            'method' => 'browser_profile',
            'privacy_boundary' => 'ota_channel_metrics_only',
            'required_core_fields' => ['order_amount', 'room_nights', 'order_count', 'traffic', 'review_score'],
        ],
        'realtime' => [
            'label' => 'ctrip_realtime',
            'capture_sections' => 'homepage,traffic_report',
            'data_period' => 'realtime_snapshot',
            'phase' => 'realtime',
            'method' => 'browser_profile',
            'privacy_boundary' => 'realtime_snapshot_not_final_daily_truth',
            'required_core_fields' => ['ctrip_orders', 'ctrip_room_nights', 'ctrip_visitor', 'ctrip_rank'],
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
        $options['collector_flow'] = $flow;
        $options['capture_sections'] = $definition['capture_sections'];
        $options['profile_sections'] = $definition['capture_sections'];
        $options['data_period'] = $definition['data_period'];
        if ($flow === 'realtime' && $this->firstValue($options, [], ['data_date', 'dataDate']) === null) {
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
            'realtime', 'real_time', 'live', 'snapshot', 'today_realtime' => 'realtime',
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
