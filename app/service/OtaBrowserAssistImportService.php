<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class OtaBrowserAssistImportService
{
    private const CONTRACT_VERSION = 'ota_browser_assist_collection_contract.v1';
    private const COLLECTION_MODE = 'browser_assist_dom';
    private const KNOWN_SECTION_KEYS = ['ctrip', 'ctripStats', 'meituan', 'meituanStats'];

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
            $this->normalizeMeituanStatsSection($capture['meituanStats'] ?? null, $context, $warnings)
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
        foreach (['ctrip', 'qunar'] as $channel) {
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function unwrapCapture(array $payload): array
    {
        foreach (['capture', 'payload', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && $this->hasKnownSection($payload[$key])) {
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
        return false;
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
            'peer_rank' => 'peer rank',
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
