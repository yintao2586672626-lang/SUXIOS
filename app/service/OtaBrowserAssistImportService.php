<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class OtaBrowserAssistImportService
{
    private const CONTRACT_VERSION = 'ota_browser_assist_collection_contract.v1';
    private const COLLECTION_MODE = 'browser_assist_dom';
    private const KNOWN_SECTION_KEYS = ['ctrip', 'ctripStats', 'meituan', 'meituanStats', 'meituanHook', 'meituanPeerHook', 'platformIdentity'];

    private PlatformDataSyncService $syncService;

    public function __construct(?PlatformDataSyncService $syncService = null)
    {
        $this->syncService = $syncService ?: new PlatformDataSyncService();
    }

    /**
     * @param mixed $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function importCapture($user, array $payload): array
    {
        $normalized = $this->normalizeCapturePackages($payload);
        $packages = $normalized['packages'];
        if ($packages === []) {
            throw new RuntimeException('浏览器辅助采集未解析到可入库数据。', 422);
        }

        $results = [];
        $saved = 0;
        $normalizedRows = 0;
        foreach ($packages as $package) {
            $result = $this->syncService->importRows($user, $package);
            $saved += (int)($result['saved_count'] ?? 0);
            $normalizedRows += (int)($result['normalized_count'] ?? 0);
            $results[] = [
                'platform' => (string)$package['platform'],
                'data_type' => (string)$package['data_type'],
                'row_count' => count($package['rows']),
                'status' => (string)($result['status'] ?? 'unknown'),
                'normalized_count' => (int)($result['normalized_count'] ?? 0),
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'sync_task_id' => (int)($result['task_id'] ?? 0),
            ];
        }

        $failed = array_values(array_filter($results, static function (array $item): bool {
            return !in_array((string)$item['status'], ['success', 'partial_success'], true);
        }));

        return [
            'status' => $failed === [] ? 'success' : 'partial_success',
            'source_contract' => self::CONTRACT_VERSION,
            'collection_mode' => self::COLLECTION_MODE,
            'package_count' => count($packages),
            'row_count' => (int)$normalized['summary']['row_count'],
            'normalized_count' => $normalizedRows,
            'saved_count' => $saved,
            'warnings' => $normalized['warnings'],
            'packages' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizeCapturePackages(array $payload): array
    {
        $capture = $this->unwrapCapture($payload);
        $context = [
            'generated_at' => $this->normalizeDateTime($payload['generated_at'] ?? $payload['generatedAt'] ?? date('Y-m-d H:i:s')),
            'system_hotel_id' => $this->toInt($payload['system_hotel_id'] ?? $payload['systemHotelId'] ?? $capture['system_hotel_id'] ?? $capture['systemHotelId'] ?? null),
            'hotel_id' => $this->cleanText($payload['hotel_id'] ?? $payload['hotelId'] ?? $capture['hotel_id'] ?? $capture['hotelId'] ?? ''),
            'hotel_name' => $this->cleanText($payload['hotel_name'] ?? $payload['hotelName'] ?? $capture['hotel_name'] ?? $capture['hotelName'] ?? ''),
            'data_date' => $this->normalizeDate($payload['data_date'] ?? $payload['dataDate'] ?? $capture['data_date'] ?? $capture['dataDate'] ?? ''),
            'snapshot_time' => $this->normalizeDateTime($payload['snapshot_time'] ?? $payload['snapshotTime'] ?? $capture['snapshot_time'] ?? $capture['snapshotTime'] ?? ''),
        ];

        $warnings = [];
        $rows = array_merge(
            $this->normalizeInventorySection($capture['ctrip'] ?? null, 'ctrip', 'ctrip_inventory', $context, $warnings),
            $this->normalizeInventorySection($capture['meituan'] ?? null, 'meituan', 'meituan_inventory', $context, $warnings),
            $this->normalizeCtripStatsSection($capture['ctripStats'] ?? null, $context, $warnings),
            $this->normalizeMeituanStatsSection($capture['meituanStats'] ?? null, $context, $warnings),
            $this->normalizeMeituanHookSection($this->resolveMeituanHookSection($capture), $context, $warnings),
            $this->normalizePlatformIdentitySection($this->resolvePlatformIdentitySection($capture), $context, $warnings)
        );

        $packages = $this->buildPackages($rows, $context);
        return [
            'type' => 'ota_browser_assist_import',
            'source_contract' => self::CONTRACT_VERSION,
            'collection_mode' => self::COLLECTION_MODE,
            'summary' => [
                'row_count' => count($rows),
                'package_count' => count($packages),
                'platforms' => array_values(array_unique(array_map(static fn(array $row): string => (string)($row['source'] ?? ''), $rows))),
                'data_types' => array_values(array_unique(array_map(static fn(array $row): string => (string)($row['data_type'] ?? ''), $rows))),
                'warning_count' => count($warnings),
            ],
            'warnings' => $warnings,
            'packages' => $packages,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function buildPackages(array $rows, array $context): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $platform = strtolower((string)($row['source'] ?? $row['platform'] ?? 'custom'));
            $dataType = strtolower((string)($row['data_type'] ?? 'business'));
            $key = $platform . ':' . $dataType;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'platform' => $platform,
                    'data_type' => $dataType,
                    'rows' => [],
                ];
            }
            $groups[$key]['rows'][] = $row;
        }

        $packages = [];
        foreach ($groups as $group) {
            $systemHotelId = (int)($context['system_hotel_id'] ?? 0);
            if ($systemHotelId <= 0) {
                foreach ($group['rows'] as $row) {
                    $rowHotelId = (int)($row['system_hotel_id'] ?? 0);
                    if ($rowHotelId > 0) {
                        $systemHotelId = $rowHotelId;
                        break;
                    }
                }
            }
            $packages[] = [
                'name' => $this->packageName((string)$group['platform'], (string)$group['data_type']),
                'platform' => (string)$group['platform'],
                'data_type' => (string)$group['data_type'],
                'system_hotel_id' => $systemHotelId,
                'source_contract' => self::CONTRACT_VERSION,
                'collection_mode' => self::COLLECTION_MODE,
                'rows' => $group['rows'],
            ];
        }

        return $packages;
    }

    /**
     * @param mixed $section
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeInventorySection($section, string $platform, string $module, array $context, array &$warnings): array
    {
        if (!is_array($section)) {
            return [];
        }
        $rooms = isset($section['rooms']) && is_array($section['rooms']) ? $section['rooms'] : [];
        if ($rooms === []) {
            $warnings[] = [
                'platform' => $platform,
                'module' => $module,
                'code' => 'rooms_missing',
                'message' => $module . ' has no room inventory rows.',
            ];
            return [];
        }

        $rows = [];
        $snapshot = $this->resolveSnapshot($section, $context);
        foreach ($rooms as $roomIndex => $room) {
            if (!is_array($room)) {
                continue;
            }
            $roomName = $this->cleanText($room['name'] ?? $room['roomName'] ?? $room['room_type_name'] ?? $room['productName'] ?? '');
            $days = isset($room['days']) && is_array($room['days']) ? $room['days'] : [];
            if ($days === []) {
                $warnings[] = [
                    'platform' => $platform,
                    'module' => $module,
                    'code' => 'room_days_missing',
                    'source_path' => $module . '.rooms.' . $roomIndex . '.days',
                    'message' => 'Room inventory days are missing for room index ' . $roomIndex . '.',
                ];
            }
            foreach ($days as $dayIndex => $day) {
                if (!is_array($day)) {
                    continue;
                }
                $sourcePath = $module . '.rooms.' . $roomIndex . '.days.' . $dayIndex;
                $dataDate = $this->normalizeDate($day['date'] ?? $day['data_date'] ?? $day['dataDate'] ?? $section['data_date'] ?? $section['dataDate'] ?? $context['data_date'] ?? '');
                if ($dataDate === '') {
                    $warnings[] = [
                        'platform' => $platform,
                        'module' => $module,
                        'code' => 'data_date_missing',
                        'source_path' => $sourcePath,
                        'message' => 'Inventory row skipped because no data_date could be proven.',
                    ];
                    continue;
                }

                $state = $this->cleanText($day['state'] ?? $day['status'] ?? $day['saleState'] ?? $day['product_status'] ?? '');
                $remain = $this->toNumber($day['remain'] ?? $day['available'] ?? $day['stock'] ?? $day['inventory_remaining'] ?? $day['remainText'] ?? null);
                $reserved = $this->toNumber($day['reserved'] ?? $day['locked'] ?? $day['inventory_reserved'] ?? null);
                $sold = $this->toNumber($day['sold'] ?? $day['soldOut'] ?? $day['inventory_sold'] ?? null);
                $dimension = $roomName !== '' ? $roomName : 'room_index:' . $roomIndex;
                $row = [
                    'source' => $platform,
                    'platform' => $platform,
                    'data_type' => 'inventory',
                    'data_date' => $dataDate,
                    'data_period' => 'realtime_snapshot',
                    'snapshot_time' => $snapshot['snapshot_time'],
                    'snapshot_bucket' => $snapshot['snapshot_bucket'],
                    'system_hotel_id' => (int)($section['system_hotel_id'] ?? $section['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                    'hotel_id' => $this->cleanText($section['hotel_id'] ?? $section['hotelId'] ?? $context['hotel_id'] ?? ''),
                    'hotel_name' => $this->cleanText($section['hotelName'] ?? $section['hotel_name'] ?? $context['hotel_name'] ?? ''),
                    'dimension' => $dimension,
                    'room_type_name' => $roomName,
                    'product_status' => $state,
                    'inventory_remaining' => $remain,
                    'inventory_reserved' => $reserved,
                    'inventory_sold' => $sold,
                    'data_value' => $remain,
                    'acquisition_method' => self::COLLECTION_MODE,
                    'source_contract' => self::CONTRACT_VERSION,
                    'raw_data' => [
                        'collection_mode' => self::COLLECTION_MODE,
                        'source_contract' => self::CONTRACT_VERSION,
                        'module' => $module,
                        'snapshot_time_source' => $snapshot['source'],
                        'inventory' => [
                            'room_name' => $roomName !== '' ? $roomName : null,
                            'data_date' => $dataDate,
                            'state' => $state !== '' ? $state : null,
                            'is_closed' => (bool)($day['isClosed'] ?? $day['closed'] ?? false),
                            'remain' => $remain,
                            'reserved' => $reserved,
                            'sold' => $sold,
                            'remain_text' => $this->cleanText($day['remainText'] ?? ''),
                            'limit_type' => $this->cleanText($day['limitType'] ?? $day['limit_type'] ?? ''),
                            'raw_text' => $this->cleanText($day['raw'] ?? $day['rawText'] ?? $day['text'] ?? ''),
                        ],
                        'missing_fields' => $this->missingInventoryFields($roomName, $remain, $state),
                        'field_facts' => [
                            $this->fieldFact('room_inventory_remaining', 'inventory', $sourcePath . '.remain', 'online_daily_data.raw_data.inventory.remain', $remain),
                            $this->fieldFact('room_inventory_reserved', 'inventory', $sourcePath . '.reserved', 'online_daily_data.raw_data.inventory.reserved', $reserved, 'optional_missing'),
                            $this->fieldFact('room_inventory_sold', 'inventory', $sourcePath . '.sold', 'online_daily_data.raw_data.inventory.sold', $sold, 'optional_missing'),
                            $this->fieldFact('room_sale_status', 'inventory', $sourcePath . '.state', 'online_daily_data.raw_data.inventory.state', $state),
                        ],
                    ],
                ];
                $rows[] = $this->attachEvidence($this->compact($row), $platform, 'inventory', $sourcePath, $module, $section);
            }
        }

        return $rows;
    }

    /**
     * @param mixed $section
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCtripStatsSection($section, array $context, array &$warnings): array
    {
        if (!is_array($section)) {
            return [];
        }
        $metricsRoot = isset($section['metrics']) && is_array($section['metrics']) ? $section['metrics'] : [];
        $rows = [];
        foreach (CtripCollectorWorkflowService::ctripFamilyChannels() as $channel) {
            $metrics = isset($metricsRoot[$channel]) && is_array($metricsRoot[$channel]) ? $metricsRoot[$channel] : null;
            if ($metrics === null) {
                continue;
            }
            $snapshot = $this->resolveSnapshot($section, $context);
            $dataDate = $this->resolveRealtimeDate($section, $context, $snapshot);
            if ($dataDate === '') {
                $warnings[] = [
                    'platform' => 'ctrip',
                    'module' => 'ctrip_stats',
                    'code' => 'data_date_missing',
                    'source_path' => 'ctrip_stats.metrics.' . $channel,
                    'message' => $channel . ' realtime metrics skipped because no data_date could be proven.',
                ];
                continue;
            }

            $visitors = $this->metricNumber($metrics, ['realtimeVisitors', 'visitorTotal', 'visitors', 'uv']);
            $peerAvg = $this->metricNumber($metrics, ['visitorPeerAvg', 'peerAvgVisitors', 'peer_avg_visitors']);
            $conversion = $this->metricNumber($metrics, ['orderConversionRate', 'conversionRate', 'flowRate']);
            $sourcePath = 'ctrip_stats.metrics.' . $channel;
            if ($this->hasAny([$visitors, $peerAvg, $conversion])) {
                $rows[] = $this->attachEvidence($this->compact([
                    'source' => 'ctrip',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'data_date' => $dataDate,
                    'data_period' => 'realtime_snapshot',
                    'snapshot_time' => $snapshot['snapshot_time'],
                    'snapshot_bucket' => $snapshot['snapshot_bucket'],
                    'system_hotel_id' => (int)($section['system_hotel_id'] ?? $section['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                    'hotel_id' => $this->cleanText($section['hotel_id'] ?? $section['hotelId'] ?? $context['hotel_id'] ?? ''),
                    'hotel_name' => $this->cleanText($section['hotelName'] ?? $section['hotel_name'] ?? $context['hotel_name'] ?? ''),
                    'dimension' => 'realtime:' . $channel,
                    'detail_exposure' => $visitors,
                    'flow_rate' => $conversion,
                    'data_value' => $visitors,
                    'acquisition_method' => self::COLLECTION_MODE,
                    'source_contract' => self::CONTRACT_VERSION,
                    'raw_data' => [
                        'collection_mode' => self::COLLECTION_MODE,
                        'source_contract' => self::CONTRACT_VERSION,
                        'module' => 'ctrip_stats',
                        'channel' => $channel,
                        'snapshot_time_source' => $snapshot['source'],
                        'metrics' => [
                            'realtime_visitors' => $this->metricRawValue($metrics, ['realtimeVisitors', 'visitorTotal', 'visitors', 'uv']),
                            'visitor_peer_avg' => $this->metricRawValue($metrics, ['visitorPeerAvg', 'peerAvgVisitors', 'peer_avg_visitors']),
                            'order_conversion_rate' => $this->metricRawValue($metrics, ['orderConversionRate', 'conversionRate', 'flowRate']),
                        ],
                        'field_facts' => [
                            $this->fieldFact('realtime_visitors', 'traffic', $sourcePath . '.realtimeVisitors', 'online_daily_data.detail_exposure', $visitors),
                            $this->fieldFact('visitor_peer_avg', 'traffic', $sourcePath . '.visitorPeerAvg', 'online_daily_data.raw_data.metrics.visitor_peer_avg', $peerAvg, 'optional_missing'),
                            $this->fieldFact('order_conversion_rate', 'traffic', $sourcePath . '.orderConversionRate', 'online_daily_data.flow_rate', $conversion),
                        ],
                    ],
                ]), 'ctrip', 'traffic', $sourcePath, 'ctrip_stats', $section);
            }

            $rank = $this->metricNumber($metrics, ['realtimeRank', 'rank', 'ranking']);
            if ($rank !== null) {
                $rankPath = $sourcePath . '.realtimeRank';
                $rows[] = $this->attachEvidence($this->compact([
                    'source' => 'ctrip',
                    'platform' => 'ctrip',
                    'data_type' => 'peer_rank',
                    'data_date' => $dataDate,
                    'data_period' => 'realtime_snapshot',
                    'snapshot_time' => $snapshot['snapshot_time'],
                    'snapshot_bucket' => $snapshot['snapshot_bucket'],
                    'system_hotel_id' => (int)($section['system_hotel_id'] ?? $section['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                    'hotel_id' => $this->cleanText($section['hotel_id'] ?? $section['hotelId'] ?? $context['hotel_id'] ?? ''),
                    'hotel_name' => $this->cleanText($section['hotelName'] ?? $section['hotel_name'] ?? $context['hotel_name'] ?? ''),
                    'dimension' => 'realtime:' . $channel . ':rank',
                    'compare_type' => 'channel_realtime_rank',
                    'rank_type' => 'realtime_rank',
                    'rank' => $rank,
                    'data_value' => $rank,
                    'acquisition_method' => self::COLLECTION_MODE,
                    'source_contract' => self::CONTRACT_VERSION,
                    'raw_data' => [
                        'collection_mode' => self::COLLECTION_MODE,
                        'source_contract' => self::CONTRACT_VERSION,
                        'module' => 'ctrip_stats',
                        'channel' => $channel,
                        'snapshot_time_source' => $snapshot['source'],
                        'rank_metrics' => [
                            'realtime_rank' => $this->metricRawValue($metrics, ['realtimeRank', 'rank', 'ranking']),
                        ],
                        'field_facts' => [
                            $this->fieldFact('realtime_rank', 'peer_rank', $rankPath, 'online_daily_data.data_value/raw_data.rank_metrics.realtime_rank', $rank),
                        ],
                    ],
                ]), 'ctrip', 'peer_rank', $rankPath, 'ctrip_stats', $section);
            }
        }

        if ($rows === []) {
            $warnings[] = [
                'platform' => 'ctrip',
                'module' => 'ctrip_stats',
                'code' => 'metrics_missing',
                'message' => 'Ctrip realtime metrics section had no importable values.',
            ];
        }

        return $rows;
    }

    /**
     * @param mixed $section
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMeituanStatsSection($section, array $context, array &$warnings): array
    {
        if (!is_array($section)) {
            return [];
        }
        $metrics = isset($section['metrics']) && is_array($section['metrics']) ? $section['metrics'] : [];
        $snapshot = $this->resolveSnapshot($section, $context);
        $dataDate = $this->resolveRealtimeDate($section, $context, $snapshot);
        if ($dataDate === '') {
            $warnings[] = [
                'platform' => 'meituan',
                'module' => 'meituan_stats',
                'code' => 'data_date_missing',
                'source_path' => 'meituan_stats.metrics',
                'message' => 'Meituan realtime metrics skipped because no data_date could be proven.',
            ];
            return [];
        }

        $exposure = $this->metricNumber($metrics, ['exposureUsers', 'listExposure', 'impressions', 'exposure_count']);
        $browse = $this->metricNumber($metrics, ['browseUsers', 'detailExposure', 'visitors', 'uv', 'clicks']);
        $orders = $this->metricNumber($metrics, ['paidOrders', 'orderSubmitNum', 'orderCount', 'orders']);
        $browsePayRate = $this->metricNumber($metrics, ['browsePayRate', 'conversionRate', 'flowRate']);
        if (!$this->hasAny([$exposure, $browse, $orders, $browsePayRate])) {
            $warnings[] = [
                'platform' => 'meituan',
                'module' => 'meituan_stats',
                'code' => 'metrics_missing',
                'message' => 'Meituan realtime metrics section had no importable values.',
            ];
            return [];
        }

        $sourcePath = 'meituan_stats.metrics';
        $row = $this->compact([
            'source' => 'meituan',
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'data_date' => $dataDate,
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => $snapshot['snapshot_time'],
            'snapshot_bucket' => $snapshot['snapshot_bucket'],
            'system_hotel_id' => (int)($section['system_hotel_id'] ?? $section['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
            'hotel_id' => $this->cleanText($section['hotel_id'] ?? $section['hotelId'] ?? $context['hotel_id'] ?? ''),
            'hotel_name' => $this->cleanText($section['hotelName'] ?? $section['hotel_name'] ?? $context['hotel_name'] ?? ''),
            'dimension' => 'realtime:meituan',
            'list_exposure' => $exposure,
            'detail_exposure' => $browse,
            'order_submit_num' => $orders,
            'flow_rate' => $browsePayRate,
            'data_value' => $orders ?? $browse ?? $exposure,
            'acquisition_method' => self::COLLECTION_MODE,
            'source_contract' => self::CONTRACT_VERSION,
            'raw_data' => [
                'collection_mode' => self::COLLECTION_MODE,
                'source_contract' => self::CONTRACT_VERSION,
                'module' => 'meituan_stats',
                'snapshot_time_source' => $snapshot['source'],
                'metrics' => [
                    'exposure_users' => $this->metricRawValue($metrics, ['exposureUsers', 'listExposure', 'impressions', 'exposure_count']),
                    'browse_users' => $this->metricRawValue($metrics, ['browseUsers', 'detailExposure', 'visitors', 'uv', 'clicks']),
                    'paid_orders' => $this->metricRawValue($metrics, ['paidOrders', 'orderSubmitNum', 'orderCount', 'orders']),
                    'exposure_browse_rate' => $this->metricRawValue($metrics, ['exposureBrowseRate', 'ctr']),
                    'browse_pay_rate' => $this->metricRawValue($metrics, ['browsePayRate', 'conversionRate', 'flowRate']),
                ],
                'field_facts' => [
                    $this->fieldFact('exposure_users', 'traffic', $sourcePath . '.exposureUsers', 'online_daily_data.list_exposure', $exposure),
                    $this->fieldFact('browse_users', 'traffic', $sourcePath . '.browseUsers', 'online_daily_data.detail_exposure', $browse),
                    $this->fieldFact('paid_orders', 'traffic', $sourcePath . '.paidOrders', 'online_daily_data.order_submit_num', $orders, 'optional_missing'),
                    $this->fieldFact('browse_pay_rate', 'traffic', $sourcePath . '.browsePayRate', 'online_daily_data.flow_rate', $browsePayRate),
                ],
            ],
        ]);

        return [$this->attachEvidence($row, 'meituan', 'traffic', $sourcePath, 'meituan_stats', $section)];
    }

    /**
     * @param mixed $section
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMeituanHookSection($section, array $context, array &$warnings): array
    {
        $root = $this->unwrapMeituanHookRoot($section);
        if ($root === null) {
            return [];
        }

        $rows = [];
        foreach ($root as $key => $item) {
            if (!is_string($key) || !is_array($item)) {
                continue;
            }
            if (in_array($key, ['OWN_METRICS', 'OWN_TODAY'], true)) {
                $warnings[] = [
                    'platform' => 'meituan',
                    'module' => 'meituan_hook',
                    'code' => 'own_metrics_not_imported',
                    'source_path' => 'meituan_hook.' . $key,
                    'message' => 'Meituan hook own metrics are retained as context only; they are not imported as confirmed revenue facts.',
                ];
                continue;
            }
            if ($this->isMeituanHookPeerItem($key, $item)) {
                $rows = array_merge($rows, $this->normalizeMeituanHookPeerItem($key, $item, $context, $warnings));
                continue;
            }
            if ($this->isMeituanHookForecastItem($key, $item)) {
                $rows = array_merge($rows, $this->normalizeMeituanHookForecastItem($key, $item, $context));
                continue;
            }
            if ($this->isMeituanHookKeywordItem($key, $item)) {
                $rows = array_merge($rows, $this->normalizeMeituanHookKeywordItem($key, $item, $context));
                continue;
            }
            if ($this->isMeituanHookFlowItem($key, $item)) {
                $rows = array_merge($rows, $this->normalizeMeituanHookFlowItem($key, $item, $context));
            }
        }

        if ($rows === [] && $this->hasMeituanHookShape($root)) {
            $warnings[] = [
                'platform' => 'meituan',
                'module' => 'meituan_hook',
                'code' => 'hook_rows_missing',
                'message' => 'Meituan hook payload was detected but no importable rows were found.',
            ];
        }

        return $rows;
    }

    /**
     * @param mixed $section
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function normalizePlatformIdentitySection($section, array $context, array &$warnings): array
    {
        if (!is_array($section)) {
            return [];
        }

        $platform = strtolower($this->cleanText($this->firstNonEmpty($section['platform'] ?? null, $section['source'] ?? null, 'meituan')));
        if ($platform !== 'meituan') {
            $warnings[] = [
                'platform' => $platform,
                'module' => 'platform_identity',
                'code' => 'unsupported_platform_identity',
                'message' => 'Only Meituan partnerId/poiId identity evidence is importable from browser assist captures.',
            ];
            return [];
        }

        $partnerId = $this->cleanText($this->firstNonEmpty($section['partnerId'] ?? null, $section['partner_id'] ?? null));
        $poiId = $this->cleanText($this->firstNonEmpty(
            $section['poiId'] ?? null,
            $section['poi_id'] ?? null,
            $section['storeId'] ?? null,
            $section['store_id'] ?? null,
            $section['hotelId'] ?? null,
            $section['hotel_id'] ?? null
        ));
        if ($partnerId === '' && $poiId === '') {
            $warnings[] = [
                'platform' => $platform,
                'module' => 'platform_identity',
                'code' => 'platform_identity_fields_missing',
                'source_path' => 'platform_identity',
                'message' => 'Meituan platform identity section had no partnerId or poiId.',
            ];
            return [];
        }

        $snapshot = $this->resolveSnapshot($section, $context);
        $dataDate = $this->normalizeDate($section['data_date'] ?? $section['dataDate'] ?? $context['data_date'] ?? '')
            ?: $this->normalizeDate($snapshot['snapshot_time'])
            ?: $this->normalizeDate($context['generated_at'] ?? '');
        $evidence = [];
        if (isset($section['evidence']) && is_array($section['evidence'])) {
            foreach (array_slice($section['evidence'], 0, 12) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $clean = $this->sanitizeIdentityEvidence($item);
                if ($clean !== []) {
                    $evidence[] = $clean;
                }
            }
        }

        $row = [
            'source' => $platform,
            'platform' => $platform,
            'data_type' => 'platform_identity',
            'data_date' => $dataDate,
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => $snapshot['snapshot_time'],
            'snapshot_bucket' => $snapshot['snapshot_bucket'],
            'system_hotel_id' => (int)($section['system_hotel_id'] ?? $section['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
            'hotel_id' => $poiId !== '' ? $poiId : $this->cleanText($context['hotel_id'] ?? ''),
            'hotel_name' => $this->cleanText($section['hotelName'] ?? $section['hotel_name'] ?? $context['hotel_name'] ?? ''),
            'dimension' => 'meituan:platform_identity',
            'data_value' => 1,
            'partner_id' => $partnerId,
            'poi_id' => $poiId,
            'acquisition_method' => self::COLLECTION_MODE,
            'source_contract' => self::CONTRACT_VERSION,
            'raw_data' => [
                'collection_mode' => self::COLLECTION_MODE,
                'source_contract' => self::CONTRACT_VERSION,
                'module' => 'platform_identity',
                'snapshot_time_source' => $snapshot['source'],
                'platform_identity' => $this->compact([
                    'platform' => $platform,
                    'partner_id' => $partnerId !== '' ? $partnerId : null,
                    'poi_id' => $poiId !== '' ? $poiId : null,
                    'evidence' => $evidence,
                ]),
                'field_facts' => [
                    $this->fieldFact('meituan_partner_id', 'platform_identity', 'platform_identity.partnerId', 'online_daily_data.raw_data.platform_identity.partner_id', $partnerId),
                    $this->fieldFact('meituan_poi_id', 'platform_identity', 'platform_identity.poiId', 'online_daily_data.hotel_id/raw_data.platform_identity.poi_id', $poiId),
                ],
            ],
        ];

        return [$this->attachEvidence($this->compact($row), $platform, 'platform_identity', 'platform_identity', 'browser_assist_platform_identity', $section)];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $warnings
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMeituanHookPeerItem(string $key, array $item, array $context, array &$warnings): array
    {
        $data = $this->hookData($item);
        $sections = isset($data['peerRankData']) && is_array($data['peerRankData']) ? $data['peerRankData'] : [];
        if ($sections === []) {
            $warnings[] = [
                'platform' => 'meituan',
                'module' => 'meituan_hook_peer_rank',
                'code' => 'peer_rank_rows_missing',
                'source_path' => 'meituan_hook.' . $key . '.data.peerRankData',
                'message' => 'Meituan hook peer rank item has no peerRankData rows.',
            ];
            return [];
        }

        $snapshot = $this->resolveSnapshot($item, $context);
        $dateRange = $this->cleanText($this->firstNonEmpty($item['dateRange'] ?? null, $item['date_range'] ?? null, $this->hookKeyPart($key, 2)));
        $rankType = $this->cleanText($this->firstNonEmpty($item['rankType'] ?? null, $item['rank_type'] ?? null, $this->hookKeyRankType($key)));
        $rows = [];
        foreach ($sections as $sectionIndex => $section) {
            if (!is_array($section)) {
                continue;
            }
            $dimName = $this->cleanText($this->firstNonEmpty($section['dimName'] ?? null, $section['dimension'] ?? null, $section['metricName'] ?? null, $section['aiMetricName'] ?? null));
            $rankRows = [];
            foreach (['roundRanks', 'roundRank', 'ranks', 'list'] as $rowKey) {
                if (isset($section[$rowKey]) && is_array($section[$rowKey])) {
                    $rankRows = $section[$rowKey];
                    break;
                }
            }
            foreach ($rankRows as $rowIndex => $rankRow) {
                if (!is_array($rankRow)) {
                    continue;
                }
                $sourcePath = 'meituan_hook.' . $key . '.data.peerRankData.' . $sectionIndex . '.roundRanks.' . $rowIndex;
                $rank = $this->toNumber($this->firstNonEmpty($rankRow['rank'] ?? null, $rankRow['ranking'] ?? null, $rankRow['rankValue'] ?? null, $rankRow['sort'] ?? null));
                $percent = $this->toNumber($this->firstNonEmpty($rankRow['percent'] ?? null, $rankRow['ratio'] ?? null, $rankRow['rank_percent'] ?? null, $rankRow['rankPercent'] ?? null));
                $metricValue = $this->toNumber($this->firstNonEmpty($rankRow['dataValue'] ?? null, $rankRow['data_value'] ?? null, $rankRow['value'] ?? null, $rankRow['metric_value'] ?? null));
                $row = [
                    'source' => 'meituan',
                    'platform' => 'meituan',
                    'data_type' => 'peer_rank',
                    'data_date' => $this->hookDataDate($item, $context, $snapshot, $dateRange),
                    'data_period' => $this->hookPeriod($dateRange),
                    'snapshot_time' => $snapshot['snapshot_time'],
                    'snapshot_bucket' => $snapshot['snapshot_bucket'],
                    'system_hotel_id' => (int)($item['system_hotel_id'] ?? $item['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                    'hotel_id' => $this->cleanText($this->firstNonEmpty($rankRow['poiId'] ?? null, $rankRow['poi_id'] ?? null, $rankRow['hotelId'] ?? null, $rankRow['hotel_id'] ?? null)),
                    'hotel_name' => $this->cleanText($this->firstNonEmpty($rankRow['poiName'] ?? null, $rankRow['poi_name'] ?? null, $rankRow['hotelName'] ?? null, $rankRow['hotel_name'] ?? null)),
                    'dimension' => 'peer_rank:' . ($rankType !== '' ? $rankType : 'unknown') . ':' . ($dimName !== '' ? $dimName : 'unknown'),
                    'compare_type' => 'competitor',
                    'rank_type' => $rankType,
                    'rank' => $rank,
                    'rank_percent' => $percent,
                    'data_value' => $metricValue ?? $rank,
                    'acquisition_method' => self::COLLECTION_MODE,
                    'source_contract' => self::CONTRACT_VERSION,
                    'raw_data' => [
                        'collection_mode' => self::COLLECTION_MODE,
                        'source_contract' => self::CONTRACT_VERSION,
                        'module' => 'meituan_hook_peer_rank',
                        'date_range' => $dateRange,
                        'date_range_name' => $this->cleanText($item['dateRangeName'] ?? $item['date_range_name'] ?? ''),
                        'rank_type_name' => $this->cleanText($item['rankTypeName'] ?? $item['rank_type_name'] ?? ''),
                        'dimension_name' => $dimName,
                        'snapshot_time_source' => $snapshot['source'],
                        'peer_rank' => [
                            'rank' => $rank,
                            'percent' => $percent,
                            'data_value' => $metricValue,
                            'poi_name' => $this->cleanText($rankRow['poiName'] ?? $rankRow['poi_name'] ?? ''),
                            'raw' => $rankRow,
                        ],
                        'field_facts' => [
                            $this->fieldFact('peer_rank', 'peer_rank', $sourcePath . '.rank', 'online_daily_data.data_value/raw_data.peer_rank.rank', $rank),
                            $this->fieldFact('peer_percent', 'peer_rank', $sourcePath . '.percent', 'online_daily_data.raw_data.peer_rank.percent', $percent, 'optional_missing'),
                            $this->fieldFact('peer_data_value', 'peer_rank', $sourcePath . '.dataValue', 'online_daily_data.raw_data.peer_rank.data_value', $metricValue, 'optional_missing'),
                        ],
                    ],
                ];
                $rows[] = $this->attachEvidence($this->compact($row), 'meituan', 'peer_rank', $sourcePath, 'meituan_hook', $item);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMeituanHookFlowItem(string $key, array $item, array $context): array
    {
        $data = $this->hookData($item);
        $snapshot = $this->resolveSnapshot($item, $context);
        $dateRange = $this->cleanText($this->firstNonEmpty($item['dateRange'] ?? null, $item['date_range'] ?? null, $this->hookKeyPart($key, 2)));
        $flowType = $this->hookFlowType($key, $item);
        $sourcePath = 'meituan_hook.' . $key . '.data';

        if ($flowType === 'conversion') {
            $orderCount = $this->toNumber($this->firstNonEmpty($data['orderCount'] ?? null, $data['payOrderCount'] ?? null, $data['orders'] ?? null));
            $row = [
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'traffic_analysis',
                'data_date' => $this->hookDataDate($item, $context, $snapshot, $dateRange),
                'data_period' => $this->hookPeriod($dateRange),
                'snapshot_time' => $snapshot['snapshot_time'],
                'snapshot_bucket' => $snapshot['snapshot_bucket'],
                'system_hotel_id' => (int)($item['system_hotel_id'] ?? $item['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                'hotel_name' => $this->cleanText($item['hotelName'] ?? $item['hotel_name'] ?? $context['hotel_name'] ?? ''),
                'dimension' => 'traffic_analysis:flow_conversion',
                'analysis_type' => 'conversion_funnel',
                'list_exposure' => $this->toNumber($this->firstNonEmpty($data['exposeCount'] ?? null, $data['exposureCount'] ?? null, $data['exposure'] ?? null)),
                'detail_exposure' => $this->toNumber($this->firstNonEmpty($data['visitCount'] ?? null, $data['visitorCount'] ?? null, $data['uv'] ?? null)),
                'order_submit_num' => $orderCount,
                'order_filling_num' => $orderCount,
                'flow_rate' => $this->toNumber($this->firstNonEmpty($data['visitOrderRate'] ?? null, $data['conversionRate'] ?? null, $data['orderConversionRate'] ?? null)),
                'data_value' => $orderCount,
                'acquisition_method' => self::COLLECTION_MODE,
                'source_contract' => self::CONTRACT_VERSION,
                'raw_data' => $this->hookRawData($item, 'meituan_hook_flow_conversion', $dateRange, $snapshot, $data),
            ];
            return [$this->attachEvidence($this->compact($row), 'meituan', 'traffic_analysis', $sourcePath, 'meituan_hook', $item)];
        }

        $list = isset($data['list']) && is_array($data['list']) ? $data['list'] : [];
        if ($list === []) {
            $row = [
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'traffic_analysis',
                'data_date' => $this->hookDataDate($item, $context, $snapshot, $dateRange),
                'data_period' => $this->hookPeriod($dateRange),
                'snapshot_time' => $snapshot['snapshot_time'],
                'snapshot_bucket' => $snapshot['snapshot_bucket'],
                'system_hotel_id' => (int)($item['system_hotel_id'] ?? $item['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                'hotel_name' => $this->cleanText($item['hotelName'] ?? $item['hotel_name'] ?? $context['hotel_name'] ?? ''),
                'dimension' => 'traffic_analysis:' . $flowType,
                'analysis_type' => $flowType,
                'data_value' => $this->toNumber($this->firstNonEmpty($data['value'] ?? null, $data['dataValue'] ?? null, $data['exposeCount'] ?? null, $data['visitCount'] ?? null, $data['orderCount'] ?? null)),
                'acquisition_method' => self::COLLECTION_MODE,
                'source_contract' => self::CONTRACT_VERSION,
                'raw_data' => $this->hookRawData($item, 'meituan_hook_flow_' . $flowType, $dateRange, $snapshot, $data),
            ];
            return [$this->attachEvidence($this->compact($row), 'meituan', 'traffic_analysis', $sourcePath, 'meituan_hook', $item)];
        }

        $rows = [];
        foreach ($list as $index => $sourceRow) {
            if (!is_array($sourceRow)) {
                continue;
            }
            $itemSourcePath = $sourcePath . '.list.' . $index;
            $row = [
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'traffic_analysis',
                'data_date' => $this->hookDataDate($item, $context, $snapshot, $dateRange),
                'data_period' => $this->hookPeriod($dateRange),
                'snapshot_time' => $snapshot['snapshot_time'],
                'snapshot_bucket' => $snapshot['snapshot_bucket'],
                'system_hotel_id' => (int)($item['system_hotel_id'] ?? $item['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                'hotel_name' => $this->cleanText($item['hotelName'] ?? $item['hotel_name'] ?? $context['hotel_name'] ?? ''),
                'dimension' => 'traffic_analysis:' . $flowType . ':' . $this->cleanText($sourceRow['name'] ?? $sourceRow['dimension'] ?? (string)$index),
                'analysis_type' => $flowType,
                'list_exposure' => $this->toNumber($this->firstNonEmpty($sourceRow['exposeCount'] ?? null, $sourceRow['exposureCount'] ?? null, $sourceRow['value'] ?? null)),
                'detail_exposure' => $this->toNumber($this->firstNonEmpty($sourceRow['visitCount'] ?? null, $sourceRow['visitorCount'] ?? null, $sourceRow['uv'] ?? null)),
                'flow_rate' => $this->toNumber($this->firstNonEmpty($sourceRow['visitOrderRate'] ?? null, $sourceRow['conversionRate'] ?? null, $sourceRow['flowRate'] ?? null)),
                'data_value' => $this->toNumber($this->firstNonEmpty($sourceRow['value'] ?? null, $sourceRow['dataValue'] ?? null, $sourceRow['current'] ?? null)),
                'peer_rank' => $this->toNumber($this->firstNonEmpty($sourceRow['rank'] ?? null, $sourceRow['peerRank'] ?? null)),
                'week_over_week' => $this->toNumber($this->firstNonEmpty($sourceRow['weekOverWeek'] ?? null, $sourceRow['wow'] ?? null)),
                'acquisition_method' => self::COLLECTION_MODE,
                'source_contract' => self::CONTRACT_VERSION,
                'raw_data' => $this->hookRawData($item, 'meituan_hook_flow_' . $flowType, $dateRange, $snapshot, $sourceRow),
            ];
            $rows[] = $this->attachEvidence($this->compact($row), 'meituan', 'traffic_analysis', $itemSourcePath, 'meituan_hook', $item);
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMeituanHookForecastItem(string $key, array $item, array $context): array
    {
        $data = $this->hookData($item);
        $snapshot = $this->resolveSnapshot($item, $context);
        $forecastType = $this->cleanText($this->firstNonEmpty($item['forecastType'] ?? null, $item['forecast_type'] ?? null, $this->hookKeyPart($key, 1), $data['type'] ?? null));
        $detail = isset($data['detail']) && is_array($data['detail']) ? $data['detail'] : [];
        $sourceRows = $detail !== [] ? $detail : [$data];
        $rows = [];
        foreach ($sourceRows as $index => $sourceRow) {
            if (!is_array($sourceRow)) {
                continue;
            }
            $sourcePath = $detail !== [] ? 'meituan_hook.' . $key . '.data.detail.' . $index : 'meituan_hook.' . $key . '.data';
            $row = [
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'traffic_forecast',
                'data_date' => $this->normalizeDate($this->firstNonEmpty($sourceRow['dateTime'] ?? null, $sourceRow['date'] ?? null, $sourceRow['dataDate'] ?? null, $sourceRow['statDate'] ?? null))
                    ?: $this->hookDataDate($item, $context, $snapshot, ''),
                'data_period' => 'next_30_days',
                'snapshot_time' => $snapshot['snapshot_time'],
                'snapshot_bucket' => $snapshot['snapshot_bucket'],
                'system_hotel_id' => (int)($item['system_hotel_id'] ?? $item['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                'hotel_name' => $this->cleanText($item['hotelName'] ?? $item['hotel_name'] ?? $context['hotel_name'] ?? ''),
                'dimension' => 'traffic_forecast:' . ($forecastType !== '' ? $forecastType : 'flow_forecast'),
                'forecast_type' => $forecastType,
                'compare_type' => 'forecast',
                'data_value' => $this->toNumber($this->firstNonEmpty($sourceRow['current'] ?? null, $sourceRow['dataValue'] ?? null, $sourceRow['value'] ?? null)),
                'peer_avg' => $this->toNumber($this->firstNonEmpty($sourceRow['peerAvg'] ?? null, $sourceRow['peer_avg'] ?? null, $sourceRow['competitorAvg'] ?? null, $sourceRow['competitor_avg'] ?? null)),
                'acquisition_method' => self::COLLECTION_MODE,
                'source_contract' => self::CONTRACT_VERSION,
                'raw_data' => $this->hookRawData($item, 'meituan_hook_traffic_forecast', '', $snapshot, $sourceRow),
            ];
            $rows[] = $this->attachEvidence($this->compact($row), 'meituan', 'traffic_forecast', $sourcePath, 'meituan_hook', $item);
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMeituanHookKeywordItem(string $key, array $item, array $context): array
    {
        $data = $this->hookData($item);
        $snapshot = $this->resolveSnapshot($item, $context);
        $dataDate = $this->hookDataDate($item, $context, $snapshot, '');
        $cards = isset($data['cards']) && is_array($data['cards']) ? $data['cards'] : [];
        $rows = [];
        foreach ($cards as $cardIndex => $card) {
            if (!is_array($card)) {
                continue;
            }
            $items = [];
            foreach (['itemList', 'items', 'list', 'keywords'] as $itemKey) {
                if (isset($card[$itemKey]) && is_array($card[$itemKey])) {
                    $items = $card[$itemKey];
                    break;
                }
            }
            foreach ($items as $rowIndex => $keywordRow) {
                if (!is_array($keywordRow)) {
                    continue;
                }
                $keyword = $this->cleanText($this->firstNonEmpty($keywordRow['name'] ?? null, $keywordRow['keyword'] ?? null, $keywordRow['searchKeyword'] ?? null, $keywordRow['searchWord'] ?? null));
                $sourcePath = 'meituan_hook.' . $key . '.data.cards.' . $cardIndex . '.itemList.' . $rowIndex;
                $row = [
                    'source' => 'meituan',
                    'platform' => 'meituan',
                    'data_type' => 'search_keyword',
                    'data_date' => $dataDate,
                    'data_period' => 'snapshot',
                    'snapshot_time' => $snapshot['snapshot_time'],
                    'snapshot_bucket' => $snapshot['snapshot_bucket'],
                    'system_hotel_id' => (int)($item['system_hotel_id'] ?? $item['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                    'hotel_name' => $this->cleanText($item['hotelName'] ?? $item['hotel_name'] ?? $context['hotel_name'] ?? ''),
                    'dimension' => $keyword !== '' ? $keyword : 'search_keyword',
                    'keyword' => $keyword,
                    'keyword_group' => $this->cleanText($card['title'] ?? $card['name'] ?? ''),
                    'data_value' => $this->toNumber($this->firstNonEmpty($keywordRow['value'] ?? null, $keywordRow['dataValue'] ?? null, $keywordRow['heat'] ?? null, $keywordRow['rank'] ?? null)),
                    'list_exposure' => $this->toNumber($this->firstNonEmpty($keywordRow['impressions'] ?? null, $keywordRow['exposure'] ?? null, $keywordRow['exposureCount'] ?? null)),
                    'detail_exposure' => $this->toNumber($this->firstNonEmpty($keywordRow['clicks'] ?? null, $keywordRow['clickCount'] ?? null, $keywordRow['detailExposure'] ?? null)),
                    'acquisition_method' => self::COLLECTION_MODE,
                    'source_contract' => self::CONTRACT_VERSION,
                    'raw_data' => $this->hookRawData($item, 'meituan_hook_search_keyword', '', $snapshot, $keywordRow),
                ];
                $rows[] = $this->attachEvidence($this->compact($row), 'meituan', 'search_keyword', $sourcePath, 'meituan_hook', $item);
            }
        }

        if ($rows !== []) {
            return $rows;
        }
        $list = isset($data['list']) && is_array($data['list']) ? $data['list'] : [];
        foreach ($list as $index => $keywordRow) {
            if (!is_array($keywordRow)) {
                continue;
            }
            $keyword = $this->cleanText($this->firstNonEmpty($keywordRow['keyword'] ?? null, $keywordRow['searchKeyword'] ?? null, $keywordRow['searchWord'] ?? null, $keywordRow['name'] ?? null));
            $sourcePath = 'meituan_hook.' . $key . '.data.list.' . $index;
            $row = [
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'search_keyword',
                'data_date' => $dataDate,
                'data_period' => 'snapshot',
                'snapshot_time' => $snapshot['snapshot_time'],
                'snapshot_bucket' => $snapshot['snapshot_bucket'],
                'system_hotel_id' => (int)($item['system_hotel_id'] ?? $item['systemHotelId'] ?? $context['system_hotel_id'] ?? 0),
                'hotel_name' => $this->cleanText($item['hotelName'] ?? $item['hotel_name'] ?? $context['hotel_name'] ?? ''),
                'dimension' => $keyword !== '' ? $keyword : 'search_keyword',
                'keyword' => $keyword,
                'data_value' => $this->toNumber($this->firstNonEmpty($keywordRow['value'] ?? null, $keywordRow['dataValue'] ?? null, $keywordRow['heat'] ?? null, $keywordRow['rank'] ?? null)),
                'acquisition_method' => self::COLLECTION_MODE,
                'source_contract' => self::CONTRACT_VERSION,
                'raw_data' => $this->hookRawData($item, 'meituan_hook_search_keyword', '', $snapshot, $keywordRow),
            ];
            $rows[] = $this->attachEvidence($this->compact($row), 'meituan', 'search_keyword', $sourcePath, 'meituan_hook', $item);
        }
        return $rows;
    }

    /**
     * @param mixed $section
     * @return array<string, mixed>|null
     */
    private function unwrapMeituanHookRoot($section): ?array
    {
        if (!is_array($section)) {
            return null;
        }
        if (isset($section['captured']) && is_array($section['captured']) && $this->hasMeituanHookShape($section['captured'])) {
            return $section['captured'];
        }
        return $this->hasMeituanHookShape($section) ? $section : null;
    }

    /**
     * @param array<string, mixed> $capture
     * @return array<string, mixed>|null
     */
    private function resolveMeituanHookSection(array $capture): ?array
    {
        foreach (['meituanHook', 'meituanPeerHook', 'meituanTrafficHook', 'meituanCompetitorHook', 'captured', 'capture'] as $key) {
            if (isset($capture[$key]) && is_array($capture[$key]) && $this->hasMeituanHookShape($capture[$key])) {
                return $capture[$key];
            }
        }
        return $this->hasMeituanHookShape($capture) ? $capture : null;
    }

    /**
     * @param array<string, mixed> $capture
     * @return array<string, mixed>|null
     */
    private function resolvePlatformIdentitySection(array $capture): ?array
    {
        foreach (['platformIdentity', 'platform_identity', 'meituanIdentity', 'meituan_identity'] as $key) {
            if (isset($capture[$key]) && is_array($capture[$key])) {
                return $capture[$key];
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function sanitizeIdentityEvidence(array $item): array
    {
        $fields = [];
        if (isset($item['fields']) && is_array($item['fields'])) {
            foreach ($item['fields'] as $field) {
                $text = $this->cleanText($field);
                if (in_array($text, ['partnerId', 'poiId', 'partner_id', 'poi_id'], true)) {
                    $fields[] = $text;
                }
            }
        }
        return $this->compact([
            'source' => $this->cleanText($item['source'] ?? ''),
            'host' => $this->cleanText($item['host'] ?? ''),
            'path' => $this->cleanText($item['path'] ?? ''),
            'fields' => array_slice(array_values(array_unique($fields)), 0, 4),
        ]);
    }

    /**
     * @param mixed $value
     */
    private function hasMeituanHookShape($value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/^(P_[A-Z]+_\d+|FLOW_[A-Z]+_\d+|FORECAST_\d+|KEYWORDS|OWN_METRICS|OWN_TODAY)$/', $key) === 1) {
                return true;
            }
            if (is_array($item)) {
                $source = strtolower($this->cleanText($item['source'] ?? ''));
                if (in_array($source, ['peer', 'flow', 'forecast', 'keywords'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string, mixed> $item */
    private function isMeituanHookPeerItem(string $key, array $item): bool
    {
        $data = $this->hookData($item);
        return preg_match('/^P_[A-Z]+_\d+$/', $key) === 1
            || strtolower($this->cleanText($item['source'] ?? '')) === 'peer'
            || isset($data['peerRankData']);
    }

    /** @param array<string, mixed> $item */
    private function isMeituanHookFlowItem(string $key, array $item): bool
    {
        return preg_match('/^FLOW_[A-Z]+_\d+$/', $key) === 1
            || strtolower($this->cleanText($item['source'] ?? '')) === 'flow';
    }

    /** @param array<string, mixed> $item */
    private function isMeituanHookForecastItem(string $key, array $item): bool
    {
        return preg_match('/^FORECAST_\d+$/', $key) === 1
            || strtolower($this->cleanText($item['source'] ?? '')) === 'forecast';
    }

    /** @param array<string, mixed> $item */
    private function isMeituanHookKeywordItem(string $key, array $item): bool
    {
        return $key === 'KEYWORDS' || strtolower($this->cleanText($item['source'] ?? '')) === 'keywords';
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function hookData(array $item): array
    {
        return isset($item['data']) && is_array($item['data']) ? $item['data'] : $item;
    }

    private function hookKeyPart(string $key, int $index): string
    {
        $parts = explode('_', $key);
        return (string)($parts[$index] ?? '');
    }

    private function hookKeyRankType(string $key): string
    {
        $parts = explode('_', $key);
        return count($parts) >= 3 ? $parts[0] . '_' . $parts[1] : '';
    }

    /** @param array<string, mixed> $item */
    private function hookFlowType(string $key, array $item): string
    {
        $value = strtoupper($key . ' ' . (string)($item['rankType'] ?? $item['rank_type'] ?? ''));
        if (str_contains($value, 'CONV')) {
            return 'conversion';
        }
        if (str_contains($value, 'SRC')) {
            return 'source';
        }
        if (str_contains($value, 'TREND')) {
            return 'trend';
        }
        if (str_contains($value, 'DOM')) {
            return 'dom_snapshot';
        }
        return 'flow_analysis';
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $context
     * @param array{snapshot_time:string,snapshot_bucket:string,source:string} $snapshot
     */
    private function hookDataDate(array $item, array $context, array $snapshot, string $dateRange): string
    {
        return $this->normalizeDate($item['data_date'] ?? $item['dataDate'] ?? $context['data_date'] ?? '')
            ?: $this->normalizeDate($snapshot['snapshot_time'])
            ?: ($dateRange === '0' ? $this->normalizeDate($context['generated_at'] ?? '') : '');
    }

    private function hookPeriod(string $dateRange): string
    {
        return match ($dateRange) {
            '0' => 'realtime_snapshot',
            '1' => 'yesterday',
            '7' => 'last_7_days',
            '30' => 'last_30_days',
            default => 'snapshot',
        };
    }

    /**
     * @param array<string, mixed> $item
     * @param array{snapshot_time:string,snapshot_bucket:string,source:string} $snapshot
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function hookRawData(array $item, string $module, string $dateRange, array $snapshot, $data): array
    {
        return [
            'collection_mode' => self::COLLECTION_MODE,
            'source_contract' => self::CONTRACT_VERSION,
            'module' => $module,
            'date_range' => $dateRange,
            'date_range_name' => $this->cleanText($item['dateRangeName'] ?? $item['date_range_name'] ?? ''),
            'source' => $this->cleanText($item['source'] ?? ''),
            'snapshot_time_source' => $snapshot['source'],
            'quality_status' => str_contains($module, 'forecast') ? 'signal_only' : '',
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function unwrapCapture(array $payload): array
    {
        foreach (['capture', 'payload', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && ($this->hasKnownSection($payload[$key]) || $this->hasMeituanHookShape($payload[$key]))) {
                return array_merge($payload, $payload[$key]);
            }
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function hasKnownSection(array $value): bool
    {
        foreach (self::KNOWN_SECTION_KEYS as $key) {
            if (isset($value[$key])) {
                return true;
            }
        }
        return $this->hasMeituanHookShape($value);
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $context
     * @return array{snapshot_time:string,snapshot_bucket:string,source:string}
     */
    private function resolveSnapshot(array $section, array $context): array
    {
        $snapshotTime = $this->normalizeDateTime($section['updatedAt'] ?? $section['updated_at'] ?? $section['capturedAt'] ?? $section['captured_at'] ?? $section['snapshot_time'] ?? $section['snapshotTime'] ?? $context['snapshot_time'] ?? '');
        if ($snapshotTime !== '') {
            return [
                'snapshot_time' => $snapshotTime,
                'snapshot_bucket' => $this->snapshotBucket($snapshotTime),
                'source' => 'source_timestamp',
            ];
        }
        $generatedAt = (string)($context['generated_at'] ?? date('Y-m-d H:i:s'));
        return [
            'snapshot_time' => $generatedAt,
            'snapshot_bucket' => $this->snapshotBucket($generatedAt),
            'source' => 'normalizer_generated_at',
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $context
     * @param array{snapshot_time:string,snapshot_bucket:string,source:string} $snapshot
     */
    private function resolveRealtimeDate(array $section, array $context, array $snapshot): string
    {
        return $this->normalizeDate($section['data_date'] ?? $section['dataDate'] ?? $context['data_date'] ?? '') ?: $this->normalizeDate($snapshot['snapshot_time']);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function attachEvidence(array $row, string $platform, string $sectionName, string $sourcePath, string $captureSource, array $section): array
    {
        $sourceUrl = $this->cleanText($section['url'] ?? $section['sourceUrl'] ?? '');
        $evidence = [
            'platform' => $platform,
            'section' => $sectionName,
            'source_path' => $sourcePath,
            'capture_source' => self::COLLECTION_MODE . ':' . $captureSource,
        ];
        if ($sourceUrl !== '') {
            $evidence['source_url_hash'] = hash('sha256', $sourceUrl);
        }
        $evidence['source_trace_id'] = $platform . ':' . hash('sha256', json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $row['capture_evidence'] = $evidence;
        $row['source_trace_id'] = $evidence['source_trace_id'];
        if (isset($evidence['source_url_hash'])) {
            $row['source_url_hash'] = $evidence['source_url_hash'];
        }
        unset($row['url'], $row['source_url'], $row['_source_url']);
        return $row;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function metricRawValue(array $metrics, array $keys)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $metrics)) {
                continue;
            }
            $item = $metrics[$key];
            if (is_array($item)) {
                foreach (['value', 'text', 'labelValue', 'rawValue'] as $valueKey) {
                    if ($this->hasValue($item[$valueKey] ?? null)) {
                        return $item[$valueKey];
                    }
                }
                continue;
            }
            if ($this->hasValue($item)) {
                return $item;
            }
        }
        return null;
    }

    private function metricNumber(array $metrics, array $keys): ?float
    {
        return $this->toNumber($this->metricRawValue($metrics, $keys));
    }

    /**
     * @param mixed $value
     */
    private function toNumber($value): ?float
    {
        if (!$this->hasValue($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        if (preg_match('/-?\d+(?:\.\d+)?/', str_replace(',', '', (string)$value), $matches) !== 1) {
            return null;
        }
        return (float)$matches[0];
    }

    /**
     * @param mixed $value
     */
    private function toInt($value): int
    {
        $number = $this->toNumber($value);
        return $number === null ? 0 : (int)$number;
    }

    /**
     * @param mixed $value
     */
    private function hasValue($value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_float($value) || is_int($value)) {
            return true;
        }
        if (is_bool($value)) {
            return true;
        }
        $text = trim((string)$value);
        return $text !== '' && !in_array(strtolower($text), ['-', '--', 'null', 'undefined', '暂无', '无'], true);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function hasAny(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->hasValue($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed ...$values
     * @return mixed|null
     */
    private function firstNonEmpty(...$values)
    {
        foreach ($values as $value) {
            if ($this->hasValue($value)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private function cleanText($value): string
    {
        if (!$this->hasValue($value)) {
            return '';
        }
        return trim((string)preg_replace('/\s+/u', ' ', (string)$value));
    }

    /**
     * @param mixed $value
     */
    private function normalizeDate($value): string
    {
        if (!$this->hasValue($value)) {
            return '';
        }
        $text = trim((string)$value);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $text, $matches) !== 1
            && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $text, $matches) !== 1) {
            return '';
        }
        return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
    }

    /**
     * @param mixed $value
     */
    private function normalizeDateTime($value): string
    {
        if (!$this->hasValue($value)) {
            return '';
        }
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 100000000000) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return date('Y-m-d H:i:s', $timestamp);
        }
        $text = trim((string)$value);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/', $text, $matches) !== 1) {
            return '';
        }
        return sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            (int)$matches[1],
            (int)$matches[2],
            (int)$matches[3],
            (int)($matches[4] ?? 0),
            (int)($matches[5] ?? 0),
            (int)($matches[6] ?? 0)
        );
    }

    private function snapshotBucket(string $snapshotTime): string
    {
        return substr(preg_replace('/\D/', '', $snapshotTime) ?: '', 0, 12);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function fieldFact(string $metricKey, string $dataType, string $sourcePath, string $storageField, $value, string $missingState = 'field_missing'): array
    {
        $present = $this->hasValue($value);
        return [
            'metric_key' => $metricKey,
            'data_type' => $dataType,
            'source_path' => $sourcePath,
            'storage_table' => 'online_daily_data',
            'storage_field' => $storageField,
            'status' => $present ? 'captured' : 'missing',
            'missing_state' => $present ? '' : $missingState,
            'stored_value_present' => $present,
            'value' => $present ? $value : null,
        ];
    }

    /**
     * @param mixed $remain
     * @return array<int, array<string, string>>
     */
    private function missingInventoryFields(string $roomName, $remain, string $state): array
    {
        $missing = [];
        if ($roomName === '') {
            $missing[] = ['field' => 'room_name', 'missing_state' => 'field_missing'];
        }
        if (!$this->hasValue($remain)) {
            $missing[] = ['field' => 'remain', 'missing_state' => 'field_missing'];
        }
        if ($state === '') {
            $missing[] = ['field' => 'state', 'missing_state' => 'optional_missing'];
        }
        return $missing;
    }

    private function packageName(string $platform, string $dataType): string
    {
        $platformLabel = ['ctrip' => 'Ctrip', 'meituan' => 'Meituan'][$platform] ?? $platform;
        $dataTypeLabel = [
            'inventory' => 'room inventory',
            'traffic' => 'realtime traffic',
            'traffic_analysis' => 'traffic analysis',
            'traffic_forecast' => 'traffic forecast',
            'peer_rank' => 'peer rank',
            'search_keyword' => 'search keyword',
            'platform_identity' => 'platform identity',
        ][$dataType] ?? $dataType;
        return $platformLabel . ' browser assist ' . $dataTypeLabel;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function compact($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $result[$key] = $this->compact($item);
        }
        return $result;
    }
}
