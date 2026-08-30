<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Html as HtmlReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use RuntimeException;

final class CtripOrderExportImportService
{
    private const IMPORT_CONTRACT = 'ctrip_order_aggregate_v2';
    private const CLASSIFICATION_POLICY_VERSION = 'ctrip_order_classification_v2';
    private const EXCLUSION_POLICY_VERSION = 'ctrip_order_exclusion_v1_unverified_not_applied';
    private const MAX_ROOM_TYPE_METRICS = 100;
    private const ACTIVE_STATUSES = ['已入住', '已接单', '已改订', '部分入住', '已确认'];
    private const CANCELLED_STATUSES = ['已取消', '已撤销', '已作废'];
    private const REQUIRED_HEADERS = ['订单号', '订单状态', '入住日期', '离店日期'];
    private const CTRIP_EXPORT_HEADERS = [
        '城市', '酒店名称', '订单号', '订单类型', '订单状态', '房型ID', '房型名称', '客人姓名',
        '入住日期', '离店日期', '晚数', '预订时间', '通知时间', '房间数', '币种', '底价',
        '卖价', '促销', '确认类型', '酒店确认人', '预订号', '备注', '确认备注', '携程提示', '预订网站',
    ];
    private const SAFE_IMPORT_HEADERS = [
        '城市', '酒店名称', '订单号', '订单类型', '订单状态', '房型ID', '房型名称',
        '入住日期', '离店日期', '晚数', '预订时间', '通知时间', '房间数', '币种',
        '底价', '卖价', '预订网站',
    ];
    private const HEADER_ALIASES = [
        '订单编号' => '订单号',
        '携程订单号' => '订单号',
        '订单状态名称' => '订单状态',
        '入住时间' => '入住日期',
        '入住日' => '入住日期',
        '离店时间' => '离店日期',
        '离店日' => '离店日期',
        '预订日期' => '预订时间',
        '下单时间' => '预订时间',
        '最后更新时间' => '通知时间',
        '间夜' => '晚数',
        '间夜数' => '晚数',
        '房数' => '房间数',
        '房间数量' => '房间数',
        '底价金额' => '底价',
        '底价总额' => '底价',
        '订单底价' => '底价',
        '销售价' => '卖价',
        '售卖价' => '卖价',
        '房型' => '房型名称',
        '渠道' => '预订网站',
        '预订渠道' => '预订网站',
        '来源网站' => '预订网站',
        '门店名称' => '酒店名称',
    ];

    /** @return array<int, array<string, mixed>> */
    public function parseLegacyXls(string $path, string $originalName): array
    {
        if (!class_exists(IOFactory::class)) {
            throw new RuntimeException('旧版 XLS 解析组件未安装。', 500);
        }

        $workbook = null;
        try {
            $reader = IOFactory::createReaderForFile($path, [
                IOFactory::READER_XLS,
                IOFactory::READER_HTML,
            ]);
            $sourceFormat = match (true) {
                $reader instanceof XlsReader => 'biff_xls',
                $reader instanceof HtmlReader => 'html_table_xls',
                default => throw new RuntimeException('Unsupported legacy XLS reader.'),
            };
            $reader->setReadDataOnly(true);
            $workbook = $reader->load($path);

            $rows = [];
            foreach ($workbook->getWorksheetIterator() as $worksheet) {
                $highestRow = (int)$worksheet->getHighestDataRow();
                $highestColumn = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
                if ($highestRow <= 0 || $highestColumn <= 0) {
                    continue;
                }

                $headerRow = 0;
                $headers = [];
                for ($rowIndex = 1; $rowIndex <= min($highestRow, 20); $rowIndex++) {
                    $candidate = [];
                    for ($columnIndex = 1; $columnIndex <= $highestColumn; $columnIndex++) {
                        $candidate[] = $this->cellText($worksheet, $columnIndex, $rowIndex);
                    }
                    $candidate = array_map(fn(string $header): string => $this->canonicalHeader($header), $candidate);
                    if ($this->hasRequiredHeaders($candidate)) {
                        $headerRow = $rowIndex;
                        $headers = $candidate;
                        break;
                    }
                }
                if ($headerRow <= 0) {
                    continue;
                }

                $sourceLayout = $this->sourceLayout($headers);
                $importColumns = [];
                foreach ($headers as $columnOffset => $header) {
                    if (in_array($header, self::SAFE_IMPORT_HEADERS, true)) {
                        $importColumns[$columnOffset + 1] = $header;
                    }
                }

                for ($rowIndex = $headerRow + 1; $rowIndex <= $highestRow; $rowIndex++) {
                    $row = [];
                    $hasValue = false;
                    foreach ($importColumns as $columnIndex => $header) {
                        $value = $this->cellText($worksheet, $columnIndex, $rowIndex);
                        if ($value !== '') {
                            $hasValue = true;
                        }
                        $row[$header] = $value;
                    }
                    if (!$hasValue) {
                        continue;
                    }
                    $row['_source_format'] = $sourceFormat;
                    $row['_source_layout'] = $sourceLayout;
                    $rows[] = $row;
                }
            }
        } catch (\Throwable) {
            throw new RuntimeException('携程 XLS 文件内容不受支持或已损坏。', 422);
        } finally {
            if ($workbook !== null) {
                try {
                    $workbook->disconnectWorksheets();
                } catch (\Throwable) {
                    // Cleanup must not replace the stable, sanitized import result.
                }
            }
            unset($workbook);
        }

        if ($rows === []) {
            throw new RuntimeException('未识别到携程订单表头，请确认文件来自携程订单导出。', 422);
        }
        return $rows;
    }

    /**
     * Convert Ctrip order exports to PII-free daily channel aggregates.
     * Non-Ctrip canonical imports pass through unchanged.
     *
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    public function normalizeRows(array $rows, array $context = []): array
    {
        $rows = array_values(array_map(
            fn(mixed $row): mixed => is_array($row) ? $this->canonicalizeRow($row) : $row,
            $rows
        ));
        $first = null;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $first = $row;
                break;
            }
        }
        if (!$first || !$this->looksLikeCtripOrderRow($first)) {
            return $rows;
        }
        $missingHeaders = array_values(array_filter(
            self::REQUIRED_HEADERS,
            static fn(string $header): bool => !array_key_exists($header, $first)
        ));
        if ($missingHeaders !== []) {
            throw new RuntimeException('携程订单表头缺少必填列：' . implode('、', $missingHeaders), 422);
        }

        $systemHotelId = (int)($context['system_hotel_id'] ?? 0);
        if ($systemHotelId <= 0) {
            throw new RuntimeException('导入携程订单前必须选择目标酒店。', 422);
        }
        $targetHotelName = $this->text($context['hotel_name'] ?? '');
        $isTestFixture = !empty($context['test_fixture']);
        $this->assertHotelScope($rows, $targetHotelName, $isTestFixture);

        $rawRowCount = 0;
        $rowsWithOrderId = 0;
        $missingOrderIdCount = 0;
        $datasetSourceFileIds = [];
        $orders = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawRowCount++;
            $sourceFileId = max(1, (int)($row['_source_file_index'] ?? 1));
            $datasetSourceFileIds[$sourceFileId] = true;
            $orderId = $this->text($row['订单号'] ?? '');
            if ($orderId === '') {
                $missingOrderIdCount++;
                continue;
            }
            $rowsWithOrderId++;
            $fingerprint = hash('sha256', $orderId);
            $candidateTime = $this->dateTime($row['通知时间'] ?? $row['预订时间'] ?? null);
            if (!isset($orders[$fingerprint]) || $candidateTime >= (string)$orders[$fingerprint]['_candidate_time']) {
                $row['_order_fingerprint'] = $fingerprint;
                $row['_candidate_time'] = $candidateTime;
                $orders[$fingerprint] = $row;
            }
        }

        $datasetStatusFamilyCounts = $this->emptyStatusFamilyCounts();
        $datasetStatusLabelCounts = [];
        $datasetOrderTypeCounts = [];
        $datasetFactFingerprints = [];
        $datasetDates = [];
        $missingBusinessDateCount = 0;
        $groups = [];
        foreach ($orders as $row) {
            $status = $this->text($row['订单状态'] ?? '');
            $orderType = $this->text($row['订单类型'] ?? '');
            $state = $this->orderState($status, $orderType);
            $statusFamily = $this->statusFamily($status, $state);
            $statusLabel = $status !== '' ? mb_substr($status, 0, 40, 'UTF-8') : '未填写';
            $orderTypeLabel = $orderType !== '' ? mb_substr($orderType, 0, 40, 'UTF-8') : '未填写';
            $datasetStatusFamilyCounts[$statusFamily]++;
            $datasetStatusLabelCounts[$statusLabel] = ($datasetStatusLabelCounts[$statusLabel] ?? 0) + 1;
            $datasetOrderTypeCounts[$orderTypeLabel] = ($datasetOrderTypeCounts[$orderTypeLabel] ?? 0) + 1;
            $stayDate = $this->date($row['入住日期'] ?? null);
            $bookingDate = $this->date($row['预订时间'] ?? null);
            $dataDate = $stayDate ?: $bookingDate;
            $dateSource = $stayDate !== '' ? 'stay_date' : 'booking_date_fallback';
            $datasetFactFingerprints[] = hash('sha256', json_encode([
                'order' => (string)$row['_order_fingerprint'],
                'state' => $state,
                'status_family' => $statusFamily,
                'status_label' => $statusLabel,
                'order_type' => $orderTypeLabel,
                'candidate_time' => (string)($row['_candidate_time'] ?? ''),
                'stay_date' => $stayDate,
                'departure_date' => $this->date($row['离店日期'] ?? null),
                'booking_date' => $bookingDate,
                'nights' => $this->text($row['晚数'] ?? ''),
                'rooms' => $this->text($row['房间数'] ?? ''),
                'bottom_price' => $this->text($row['底价'] ?? ''),
                'channel' => $this->channel($row['预订网站'] ?? '')[0],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if ($dataDate === '') {
                $missingBusinessDateCount++;
                continue;
            }
            $datasetDates[$dataDate] = true;

            [$channelKey, $channelLabel] = $this->channel($row['预订网站'] ?? '');
            $groupKey = $systemHotelId . '|' . $channelKey . '|' . $dataDate;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'channel_key' => $channelKey,
                    'channel_label' => $channelLabel,
                    'data_date' => $dataDate,
                    'date_source' => $dateSource,
                    'date_sources' => [],
                    'hotel_name' => $targetHotelName !== ''
                        ? $targetHotelName
                        : $this->text($row['酒店名称'] ?? ''),
                    'city' => $this->text($row['城市'] ?? ''),
                    'gross_orders' => 0,
                    'active_orders' => 0,
                    'cancelled_orders' => 0,
                    'unknown_status_orders' => 0,
                    'room_nights' => 0.0,
                    'gross_room_nights' => 0.0,
                    'bottom_price_sum' => 0.0,
                    'bottom_price_room_nights' => 0.0,
                    'bottom_price_valid_orders' => 0,
                    'bottom_price_missing_orders' => 0,
                    'bottom_price_invalid_orders' => 0,
                    'sell_price_sum' => 0.0,
                    'sell_price_valid_orders' => 0,
                    'sell_price_missing_orders' => 0,
                    'sell_price_invalid_orders' => 0,
                    'los_sum' => 0.0,
                    'lead_days_sum' => 0.0,
                    'lead_days_count' => 0,
                    'single_night_orders' => 0,
                    'los_valid_order_count' => 0,
                    'los_missing_or_invalid_order_count' => 0,
                    'los_buckets' => $this->emptyLosBuckets(),
                    'lead_days_missing_count' => 0,
                    'lead_days_invalid_negative_count' => 0,
                    'lead_time_buckets' => $this->emptyLeadTimeBuckets(),
                    'stayed_orders' => 0,
                    'active_not_stayed_orders' => 0,
                    'status_family_counts' => $this->emptyStatusFamilyCounts(),
                    'status_label_counts' => [],
                    'order_type_counts' => [],
                    'room_types' => [],
                    'order_fact_fingerprints' => [],
                    'source_formats' => [],
                    'source_layouts' => [],
                    'source_file_ids' => [],
                ];
            }
            $group =& $groups[$groupKey];
            $group['date_sources'][$dateSource] = true;
            $group['gross_orders']++;
            $group['status_family_counts'][$statusFamily]++;
            $group['status_label_counts'][$statusLabel] = ($group['status_label_counts'][$statusLabel] ?? 0) + 1;
            $group['order_type_counts'][$orderTypeLabel] = ($group['order_type_counts'][$orderTypeLabel] ?? 0) + 1;
            $sourceFormat = $this->text($row['_source_format'] ?? '');
            if (in_array($sourceFormat, ['biff_xls', 'html_table_xls'], true)) {
                $group['source_formats'][$sourceFormat] = true;
            }
            $sourceLayout = $this->text($row['_source_layout'] ?? '');
            if ($sourceLayout !== '') {
                $group['source_layouts'][$sourceLayout] = true;
            }
            $sourceFileId = max(1, (int)($row['_source_file_index'] ?? 1));
            $group['source_file_ids'][$sourceFileId] = true;

            $nights = max(0.0, $this->number($row['晚数'] ?? null) ?? 0.0);
            $rooms = max(0.0, $this->number($row['房间数'] ?? null) ?? 0.0);
            $orderRoomNights = $nights * $rooms;
            $group['gross_room_nights'] += $orderRoomNights;
            $group['order_fact_fingerprints'][] = hash('sha256', json_encode([
                'order' => (string)$row['_order_fingerprint'],
                'state' => $state,
                'candidate_time' => (string)($row['_candidate_time'] ?? ''),
                'stay_date' => $stayDate,
                'departure_date' => $this->date($row['离店日期'] ?? null),
                'nights' => $nights,
                'rooms' => $rooms,
                'bottom_price' => $this->text($row['底价'] ?? ''),
                'channel' => $channelKey,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            if ($state === 'cancelled') {
                $group['cancelled_orders']++;
                unset($group);
                continue;
            }
            if ($state !== 'active') {
                $group['unknown_status_orders']++;
                unset($group);
                continue;
            }

            $group['active_orders']++;
            if ($status === '已入住') {
                $group['stayed_orders']++;
            } else {
                $group['active_not_stayed_orders']++;
            }
            $group['room_nights'] += $orderRoomNights;
            $roomType = mb_substr($this->text($row['房型名称'] ?? ''), 0, 120, 'UTF-8');
            if ($roomType !== '') {
                if (!isset($group['room_types'][$roomType])) {
                    $group['room_types'][$roomType] = [
                        'active_orders' => 0,
                        'room_nights' => 0.0,
                        'bottom_price_sum' => 0.0,
                        'bottom_price_room_nights' => 0.0,
                        'bottom_price_valid_orders' => 0,
                    ];
                }
                $group['room_types'][$roomType]['active_orders']++;
                $group['room_types'][$roomType]['room_nights'] += $orderRoomNights;
            }
            $bottomPriceText = $this->text($row['底价'] ?? '');
            $bottomPrice = $this->number($bottomPriceText);
            if ($bottomPriceText === '') {
                $group['bottom_price_missing_orders']++;
            } elseif ($bottomPrice === null || $bottomPrice < 0) {
                $group['bottom_price_invalid_orders']++;
            } else {
                $group['bottom_price_sum'] += $bottomPrice;
                $group['bottom_price_room_nights'] += $orderRoomNights;
                $group['bottom_price_valid_orders']++;
                if ($roomType !== '') {
                    $group['room_types'][$roomType]['bottom_price_sum'] += $bottomPrice;
                    $group['room_types'][$roomType]['bottom_price_room_nights'] += $orderRoomNights;
                    $group['room_types'][$roomType]['bottom_price_valid_orders']++;
                }
            }
            $sellPriceText = $this->text($row['卖价'] ?? '');
            $sellPrice = $this->number($sellPriceText);
            if ($sellPriceText === '') {
                $group['sell_price_missing_orders']++;
            } elseif ($sellPrice === null || $sellPrice < 0) {
                $group['sell_price_invalid_orders']++;
            } else {
                $group['sell_price_sum'] += $sellPrice;
                $group['sell_price_valid_orders']++;
            }
            $losBucket = $this->losBucket($nights);
            if ($losBucket === null) {
                $group['los_missing_or_invalid_order_count']++;
            } else {
                $group['los_sum'] += $nights;
                $group['los_valid_order_count']++;
                $group['los_buckets'][$losBucket]++;
                if ($nights === 1.0) {
                    $group['single_night_orders']++;
                }
            }
            if ($bookingDate !== '' && $stayDate !== '') {
                $leadDays = (int)((strtotime($stayDate) - strtotime($bookingDate)) / 86400);
                if ($leadDays < 0) {
                    $group['lead_days_invalid_negative_count']++;
                } else {
                    $group['lead_days_sum'] += $leadDays;
                    $group['lead_days_count']++;
                    $group['lead_time_buckets'][$this->leadTimeBucket($leadDays)]++;
                }
            } else {
                $group['lead_days_missing_count']++;
            }
            unset($group);
        }

        sort($datasetFactFingerprints);
        ksort($datasetStatusLabelCounts);
        ksort($datasetOrderTypeCounts);
        $datasetDateList = array_keys($datasetDates);
        sort($datasetDateList);
        $datasetReceipt = [
            'dataset_hash' => hash('sha256', implode('|', $datasetFactFingerprints)),
            'raw_row_count' => $rawRowCount,
            'rows_with_order_id_count' => $rowsWithOrderId,
            'distinct_order_count' => count($orders),
            'duplicate_version_count' => max(0, $rowsWithOrderId - count($orders)),
            'missing_order_id_count' => $missingOrderIdCount,
            'missing_business_date_count' => $missingBusinessDateCount,
            'accepted_aggregate_order_count' => max(0, count($orders) - $missingBusinessDateCount),
            'excluded_order_count' => 0,
            'exclusion_reason_counts' => [],
            'exclusion_policy_status' => 'unverified_not_applied',
            'exclusion_policy_version' => self::EXCLUSION_POLICY_VERSION,
            'classification_policy_version' => self::CLASSIFICATION_POLICY_VERSION,
            'status_family_counts' => $datasetStatusFamilyCounts,
            'status_label_counts' => $datasetStatusLabelCounts,
            'order_type_counts' => $datasetOrderTypeCounts,
            'source_file_count' => count($datasetSourceFileIds),
            'date_from' => $datasetDateList[0] ?? null,
            'date_to' => $datasetDateList !== [] ? $datasetDateList[count($datasetDateList) - 1] : null,
        ];

        $normalized = [];
        foreach ($groups as $group) {
            $dateSources = array_keys($group['date_sources'] ?? []);
            sort($dateSources);
            $dateSource = count($dateSources) === 1
                ? $dateSources[0]
                : 'mixed';
            $dateBasis = match ($dateSource) {
                'stay_date' => 'stay_date',
                'booking_date_fallback' => 'order_date',
                default => 'mixed',
            };
            uasort($group['room_types'], static function (array $left, array $right): int {
                return ((int)$right['active_orders'] <=> (int)$left['active_orders'])
                    ?: ((float)$right['room_nights'] <=> (float)$left['room_nights']);
            });
            $roomTypeMetricCount = count($group['room_types']);
            $roomTypeMetrics = [];
            foreach (array_slice($group['room_types'], 0, self::MAX_ROOM_TYPE_METRICS, true) as $roomType => $metric) {
                $roomTypeMetrics[] = [
                    'name' => $roomType,
                    'active_orders' => (int)$metric['active_orders'],
                    'room_nights' => (float)$metric['room_nights'],
                    'reference_bottom_price_total' => (int)$metric['bottom_price_valid_orders'] > 0
                        ? (float)$metric['bottom_price_sum']
                        : null,
                    'reference_bottom_price_adr' => (float)$metric['bottom_price_room_nights'] > 0
                        ? (float)$metric['bottom_price_sum'] / (float)$metric['bottom_price_room_nights']
                        : null,
                    'bottom_price_room_nights' => (float)$metric['bottom_price_room_nights'],
                    'bottom_price_valid_order_count' => (int)$metric['bottom_price_valid_orders'],
                ];
            }
            $topRoomTypes = [];
            foreach (array_slice($roomTypeMetrics, 0, 5) as $metric) {
                $topRoomTypes[] = ['name' => $metric['name'], 'orders' => $metric['active_orders']];
            }
            sort($group['order_fact_fingerprints']);
            $snapshotHash = hash('sha256', implode('|', $group['order_fact_fingerprints']));
            $sourceFormats = array_keys($group['source_formats']);
            sort($sourceFormats);
            $sourceFormat = count($sourceFormats) === 1
                ? $sourceFormats[0]
                : ($sourceFormats === [] ? null : 'mixed_allowed_formats');
            $cancelRate = $group['gross_orders'] > 0 && $group['unknown_status_orders'] === 0
                ? $group['cancelled_orders'] / $group['gross_orders']
                : null;
            $averageLos = $group['los_valid_order_count'] > 0
                ? $group['los_sum'] / $group['los_valid_order_count']
                : null;
            $singleNightRate = $group['los_valid_order_count'] > 0
                ? $group['single_night_orders'] / $group['los_valid_order_count']
                : null;
            $averageLeadDays = $group['lead_days_count'] > 0
                ? $group['lead_days_sum'] / $group['lead_days_count']
                : null;
            $referenceBottomPriceTotal = $group['active_orders'] === 0
                ? 0.0
                : ($group['bottom_price_valid_orders'] > 0 ? $group['bottom_price_sum'] : null);
            $bottomPriceCoverageRate = $group['active_orders'] > 0
                ? $group['bottom_price_valid_orders'] / $group['active_orders']
                : null;
            $bottomPriceCompleteness = $group['active_orders'] === 0
                ? 'not_applicable_no_active_orders'
                : ($group['bottom_price_valid_orders'] === $group['active_orders']
                    ? 'complete'
                    : ($group['bottom_price_valid_orders'] > 0 ? 'partial' : 'missing'));
            $bottomPriceAdr = $referenceBottomPriceTotal !== null && $group['bottom_price_room_nights'] > 0
                ? $group['bottom_price_sum'] / $group['bottom_price_room_nights']
                : null;
            $sourceLayouts = array_keys($group['source_layouts']);
            sort($sourceLayouts);
            $sourceLayout = count($sourceLayouts) === 1
                ? $sourceLayouts[0]
                : ($sourceLayouts === [] ? null : 'mixed_recognized_layouts');
            $fileLayoutAcceptance = $sourceLayout === 'ctrip_order_export_25_columns'
                ? 'verified_25_column_layout'
                : 'recognized_compatible_layout';
            ksort($group['status_label_counts']);
            ksort($group['order_type_counts']);
            $losDistribution = [
                'buckets' => $this->distributionRows($group['los_buckets'], $this->losBucketLabels()),
                'valid_order_count' => $group['los_valid_order_count'],
                'missing_or_invalid_order_count' => $group['los_missing_or_invalid_order_count'],
                'coverage_rate' => $group['active_orders'] > 0
                    ? $group['los_valid_order_count'] / $group['active_orders']
                    : null,
                'denominator' => 'active_orders_with_valid_positive_los',
            ];
            $leadTimeDistribution = [
                'buckets' => $this->distributionRows($group['lead_time_buckets'], $this->leadTimeBucketLabels()),
                'valid_order_count' => $group['lead_days_count'],
                'missing_order_count' => $group['lead_days_missing_count'],
                'invalid_negative_order_count' => $group['lead_days_invalid_negative_count'],
                'coverage_rate' => $group['active_orders'] > 0
                    ? $group['lead_days_count'] / $group['active_orders']
                    : null,
                'denominator' => 'active_orders_with_valid_booking_and_stay_dates',
            ];

            $normalized[] = [
                'system_hotel_id' => $systemHotelId,
                'hotel_id' => 'system:' . $systemHotelId,
                'hotel_name' => $group['hotel_name'],
                'platform' => 'ctrip',
                'source' => $group['channel_key'],
                'data_type' => 'order',
                'data_date' => $group['data_date'],
                'data_period' => 'day',
                'dimension' => 'channel_order:' . $group['channel_key'],
                'compare_type' => 'self',
                'book_order_num' => $group['active_orders'],
                'gross_order_num' => $group['gross_orders'],
                'cancel_order_num' => $group['cancelled_orders'],
                'unknown_status_order_num' => $group['unknown_status_orders'],
                'cancel_rate' => $cancelRate,
                'quantity' => $group['room_nights'],
                // The generic amount field is consumed by the standard OTA ETL
                // as revenue. A Ctrip order export only provides a reference
                // bottom price, so it must remain outside revenue facts.
                'amount' => null,
                'bottom_price_adr' => $bottomPriceAdr,
                'avg_los' => $averageLos,
                'avg_lead_days' => $averageLeadDays,
                'source_method' => 'user_provided_unverified',
                'source_trace_id' => 'ctrip_order:' . substr(hash('sha256', implode('|', [
                    (string)$systemHotelId,
                    $group['channel_key'],
                    $group['data_date'],
                ])), 0, 52),
                'validation_status' => 'unverified',
                'ingestion_method' => 'import_excel',
                'raw_data' => [
                    'metric_scope' => 'ota_channel',
                    'source_method' => 'user_provided_unverified',
                    'fixture_status' => $isTestFixture ? 'explicit_test_fixture' : 'not_fixture_claimed',
                    'hotel_identity_status' => $isTestFixture ? 'fixture_bypassed' : 'matched_to_selected_system_hotel',
                    'channel_key' => $group['channel_key'],
                    'channel_label' => $group['channel_label'],
                    'business_date_basis' => $dateSource,
                    'date_basis' => $dateBasis,
                    'date_source' => $dateSource,
                    'date_sources' => $dateSources,
                    'order_count_basis' => 'active_non_cancelled_orders',
                    'room_nights_basis' => 'active_non_cancelled_booked_room_nights',
                    'gross_order_num' => $group['gross_orders'],
                    'active_order_num' => $group['active_orders'],
                    'cancel_order_num' => $group['cancelled_orders'],
                    'unknown_status_order_num' => $group['unknown_status_orders'],
                    'cancel_rate' => $cancelRate,
                    'cancel_rate_basis' => $group['unknown_status_orders'] === 0
                        ? 'cancelled_orders_over_gross_orders_complete_classification'
                        : 'unavailable_unknown_status_orders_present',
                    'room_nights' => $group['room_nights'],
                    'gross_room_nights' => $group['gross_room_nights'],
                    'bottom_price_sum' => $referenceBottomPriceTotal,
                    'bottom_price_valid_order_count' => $group['bottom_price_valid_orders'],
                    'bottom_price_missing_order_count' => $group['bottom_price_missing_orders'],
                    'bottom_price_invalid_order_count' => $group['bottom_price_invalid_orders'],
                    'bottom_price_coverage_rate' => $bottomPriceCoverageRate,
                    'bottom_price_completeness' => $bottomPriceCompleteness,
                    'sell_price_sum' => $group['sell_price_valid_orders'] > 0 ? $group['sell_price_sum'] : null,
                    'sell_price_valid_order_count' => $group['sell_price_valid_orders'],
                    'sell_price_missing_order_count' => $group['sell_price_missing_orders'],
                    'sell_price_invalid_order_count' => $group['sell_price_invalid_orders'],
                    'bottom_price_adr' => $bottomPriceAdr,
                    'amount_basis' => 'ctrip_export_bottom_price_sum',
                    'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
                    'record_kind' => 'channel_daily_aggregate',
                    'import_contract' => self::IMPORT_CONTRACT,
                    'pii_policy' => 'aggregate_only_no_guest_staff_reservation_notes',
                    'dataset_receipt' => $datasetReceipt,
                    'classification_receipt' => [
                        'policy_version' => self::CLASSIFICATION_POLICY_VERSION,
                        'status_family_counts' => $group['status_family_counts'],
                        'status_label_counts' => $group['status_label_counts'],
                        'order_type_counts' => $group['order_type_counts'],
                        'stayed_order_num' => $group['stayed_orders'],
                        'active_not_stayed_order_num' => $group['active_not_stayed_orders'],
                    ],
                    'exclusion_receipt' => [
                        'policy_version' => self::EXCLUSION_POLICY_VERSION,
                        'status' => 'unverified_not_applied',
                        'excluded_order_count' => 0,
                        'reason_counts' => [],
                        'note' => 'No scan-order or closed-room rule is applied without exact source-field evidence.',
                    ],
                    'average_los' => $averageLos,
                    'single_night_rate' => $singleNightRate,
                    'average_booking_lead_days' => $averageLeadDays,
                    'los_distribution' => $losDistribution,
                    'lead_time_distribution' => $leadTimeDistribution,
                    'room_type_metrics' => $roomTypeMetrics,
                    'room_type_metric_count' => $roomTypeMetricCount,
                    'room_type_metrics_truncated' => $roomTypeMetricCount > self::MAX_ROOM_TYPE_METRICS,
                    'top_room_types' => $topRoomTypes,
                    'city' => $group['city'],
                    'source_file_count' => count($group['source_file_ids']),
                    'source_format' => $sourceFormat,
                    'source_formats' => $sourceFormats,
                    'source_layout' => $sourceLayout,
                    'source_layouts' => $sourceLayouts,
                    'file_layout_acceptance' => $fileLayoutAcceptance,
                    'snapshot_hash' => $snapshotHash,
                ],
            ];
        }

        return $normalized;
    }

    /** @return array<string, int> */
    private function emptyStatusFamilyCounts(): array
    {
        return [
            'active_stayed' => 0,
            'active_partial_stay' => 0,
            'active_confirmed' => 0,
            'cancelled' => 0,
            'unknown' => 0,
        ];
    }

    private function statusFamily(string $status, string $state): string
    {
        if ($state === 'cancelled') {
            return 'cancelled';
        }
        if ($state !== 'active') {
            return 'unknown';
        }
        return match ($status) {
            '已入住' => 'active_stayed',
            '部分入住' => 'active_partial_stay',
            default => 'active_confirmed',
        };
    }

    /** @return array<string, int> */
    private function emptyLosBuckets(): array
    {
        return array_fill_keys(array_keys($this->losBucketLabels()), 0);
    }

    /** @return array<string, string> */
    private function losBucketLabels(): array
    {
        return [
            'one_night' => '1晚',
            'two_nights' => '2晚',
            'three_to_four_nights' => '3-4晚',
            'five_plus_nights' => '5晚及以上',
        ];
    }

    private function losBucket(float $nights): ?string
    {
        if ($nights <= 0) {
            return null;
        }
        if ($nights === 1.0) {
            return 'one_night';
        }
        if ($nights === 2.0) {
            return 'two_nights';
        }
        if ($nights <= 4.0) {
            return 'three_to_four_nights';
        }
        return 'five_plus_nights';
    }

    /** @return array<string, int> */
    private function emptyLeadTimeBuckets(): array
    {
        return array_fill_keys(array_keys($this->leadTimeBucketLabels()), 0);
    }

    /** @return array<string, string> */
    private function leadTimeBucketLabels(): array
    {
        return [
            'same_day' => '当天',
            'one_to_three_days' => '1-3天',
            'four_to_seven_days' => '4-7天',
            'eight_to_fourteen_days' => '8-14天',
            'fifteen_to_thirty_days' => '15-30天',
            'thirty_one_plus_days' => '31天及以上',
        ];
    }

    private function leadTimeBucket(int $days): string
    {
        return match (true) {
            $days === 0 => 'same_day',
            $days <= 3 => 'one_to_three_days',
            $days <= 7 => 'four_to_seven_days',
            $days <= 14 => 'eight_to_fourteen_days',
            $days <= 30 => 'fifteen_to_thirty_days',
            default => 'thirty_one_plus_days',
        };
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, string> $labels
     * @return array<int, array{key:string,label:string,orders:int}>
     */
    private function distributionRows(array $counts, array $labels): array
    {
        $rows = [];
        foreach ($labels as $key => $label) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'orders' => max(0, (int)($counts[$key] ?? 0)),
            ];
        }
        return $rows;
    }

    /** @param array<int, string> $headers */
    private function hasRequiredHeaders(array $headers): bool
    {
        foreach (self::REQUIRED_HEADERS as $required) {
            if (!in_array($required, $headers, true)) {
                return false;
            }
        }
        return true;
    }

    private function canonicalHeader(string $header): string
    {
        $header = trim(str_replace(["\xEF\xBB\xBF", "\r", "\n", "\t", '：', ':'], '', $header));
        $header = (string)preg_replace('/\s+/u', '', $header);
        return self::HEADER_ALIASES[$header] ?? $header;
    }

    /** @param array<int, string> $headers */
    private function sourceLayout(array $headers): string
    {
        $headers = array_values(array_filter($headers, static fn(string $header): bool => $header !== ''));
        return $headers === self::CTRIP_EXPORT_HEADERS
            ? 'ctrip_order_export_25_columns'
            : 'recognized_legacy_order_layout';
    }

    /** @param array<int, mixed> $rows */
    private function assertHotelScope(array $rows, string $targetHotelName, bool $isTestFixture): void
    {
        if ($isTestFixture) {
            return;
        }
        if ($targetHotelName === '') {
            throw new RuntimeException('无法核验目标酒店，请重新选择酒店后导入。', 422);
        }

        $fileHotelNames = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fileHotelName = $this->text($row['酒店名称'] ?? '');
            if ($fileHotelName === '') {
                throw new RuntimeException('携程订单文件缺少酒店名称，无法验证目标酒店。', 422);
            }
            $normalizedFileHotelName = $this->normalizedHotelName($fileHotelName);
            if ($normalizedFileHotelName !== '') {
                $fileHotelNames[$normalizedFileHotelName] = true;
            }
            if (!$this->hotelNamesMatch(
                $targetHotelName,
                $fileHotelName,
                $this->text($row['城市'] ?? '')
            )) {
                throw new RuntimeException('携程订单文件酒店与所选酒店不一致，请重新选择正确酒店。', 422);
            }
        }
        if (count($fileHotelNames) !== 1) {
            throw new RuntimeException('携程订单文件包含多个酒店，不能合并导入。', 422);
        }
    }

    private function hotelNamesMatch(string $targetHotelName, string $fileHotelName, string $city): bool
    {
        $target = $this->normalizedHotelName($targetHotelName);
        $file = $this->normalizedHotelName($fileHotelName);
        if ($target === '' || $file === '') {
            return false;
        }
        if (hash_equals($target, $file)) {
            return true;
        }
        $city = $this->normalizedHotelName($city);
        if (mb_strlen($city, 'UTF-8') < 2) {
            return false;
        }
        $targetCore = $this->distinctiveHotelCore($target, $city);
        if (mb_strlen($targetCore, 'UTF-8') < 4) {
            return false;
        }
        $fileWithoutCity = str_replace($city, '', $file);
        return str_contains($fileWithoutCity, $targetCore);
    }

    private function distinctiveHotelCore(string $normalizedName, string $normalizedCity): string
    {
        $core = str_replace($normalizedCity, '', $normalizedName);
        foreach ([
            '湖畔酒店', '度假酒店', '精品酒店', '国际酒店', '商务酒店', '大酒店',
            '景区店', '旗舰店', '度假村', '酒店', '宾馆', '客栈', '民宿', '公寓', '旅馆', '旅舍', '分店',
        ] as $genericTerm) {
            $core = str_replace($genericTerm, '', $core);
        }
        return $core;
    }

    private function normalizedHotelName(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function canonicalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $keyText = (string)$key;
            $canonical = str_starts_with($keyText, '_') ? $keyText : $this->canonicalHeader($keyText);
            if ($canonical !== '') {
                $normalized[$canonical] = $value;
            }
        }
        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private function looksLikeCtripOrderRow(array $row): bool
    {
        foreach (['订单号', '订单状态', '入住日期', '离店日期'] as $header) {
            if (array_key_exists($header, $row)) {
                return true;
            }
        }
        return false;
    }

    private function cellText(object $worksheet, int $columnIndex, int $rowIndex): string
    {
        $coordinate = Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
        return $this->text($worksheet->getCell($coordinate)->getFormattedValue());
    }

    private function orderState(string $status, string $orderType): string
    {
        if (in_array($status, self::CANCELLED_STATUSES, true) || $orderType === '无效') {
            return 'cancelled';
        }
        return in_array($status, self::ACTIVE_STATUSES, true) ? 'active' : 'unknown';
    }

    /** @return array{0:string,1:string} */
    private function channel(mixed $value): array
    {
        $text = strtolower($this->text($value));
        return match (true) {
            str_contains($text, 'trip.com'), str_contains($text, '国际') => ['tripcom', 'Trip.com'],
            str_contains($text, '去哪儿'), str_contains($text, 'qunar') => ['qunar', '去哪儿'],
            str_contains($text, '同程'), str_contains($text, 'ly.com') => ['tongcheng', '同程旅行'],
            str_contains($text, '商旅'), str_contains($text, '企业') => ['business_travel', '商旅渠道'],
            str_contains($text, '携程'), str_contains($text, 'ctrip') => ['ctrip', '携程主站'],
            $text !== '' => ['ctrip_family_other', $this->text($value)],
            default => ['ctrip_family_unspecified', '携程系未细分'],
        };
    }

    private function date(mixed $value): string
    {
        $text = $this->text($value);
        if ($text === '') {
            return '';
        }
        $text = str_replace(['年', '月', '日', '/'], ['-', '-', '', '-'], $text);
        try {
            return (new DateTimeImmutable($text))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function dateTime(mixed $value): string
    {
        $text = $this->text($value);
        if ($text === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($text))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '';
        }
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = is_string($value)
            ? str_replace([',', '¥', '￥', ' '], '', $value)
            : $value;
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
