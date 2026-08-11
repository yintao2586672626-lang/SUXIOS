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

        $orders = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = $this->text($row['订单号'] ?? '');
            if ($orderId === '') {
                continue;
            }
            $fingerprint = hash('sha256', $orderId);
            $candidateTime = $this->dateTime($row['通知时间'] ?? $row['预订时间'] ?? null);
            if (!isset($orders[$fingerprint]) || $candidateTime >= (string)$orders[$fingerprint]['_candidate_time']) {
                $row['_order_fingerprint'] = $fingerprint;
                $row['_candidate_time'] = $candidateTime;
                $orders[$fingerprint] = $row;
            }
        }

        $groups = [];
        foreach ($orders as $row) {
            $status = $this->text($row['订单状态'] ?? '');
            $orderType = $this->text($row['订单类型'] ?? '');
            $state = $this->orderState($status, $orderType);
            $stayDate = $this->date($row['入住日期'] ?? null);
            $bookingDate = $this->date($row['预订时间'] ?? null);
            $dataDate = $stayDate ?: $bookingDate;
            $dateSource = $stayDate !== '' ? 'stay_date' : 'booking_date_fallback';
            if ($dataDate === '') {
                continue;
            }

            [$channelKey, $channelLabel] = $this->channel($row['预订网站'] ?? '');
            $groupKey = $systemHotelId . '|' . $channelKey . '|' . $dataDate;
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'channel_key' => $channelKey,
                    'channel_label' => $channelLabel,
                    'data_date' => $dataDate,
                    'date_source' => $dateSource,
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
                    'room_types' => [],
                    'order_fact_fingerprints' => [],
                    'source_formats' => [],
                    'source_layouts' => [],
                    'source_file_ids' => [],
                ];
            }
            $group =& $groups[$groupKey];
            $group['gross_orders']++;
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
            $group['room_nights'] += $orderRoomNights;
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
            $group['los_sum'] += $nights;
            if ($nights === 1.0) {
                $group['single_night_orders']++;
            }
            if ($bookingDate !== '' && $stayDate !== '') {
                $leadDays = max(0, (int)((strtotime($stayDate) - strtotime($bookingDate)) / 86400));
                $group['lead_days_sum'] += $leadDays;
                $group['lead_days_count']++;
            }
            $roomType = $this->text($row['房型名称'] ?? '');
            if ($roomType !== '') {
                $group['room_types'][$roomType] = ($group['room_types'][$roomType] ?? 0) + 1;
            }
            unset($group);
        }

        $normalized = [];
        foreach ($groups as $group) {
            arsort($group['room_types']);
            $topRoomTypes = [];
            foreach (array_slice($group['room_types'], 0, 5, true) as $roomType => $count) {
                $topRoomTypes[] = ['name' => $roomType, 'orders' => $count];
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
            $averageLos = $group['active_orders'] > 0
                ? $group['los_sum'] / $group['active_orders']
                : null;
            $singleNightRate = $group['active_orders'] > 0
                ? $group['single_night_orders'] / $group['active_orders']
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
                    'business_date_basis' => $group['date_source'],
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
                    'import_contract' => 'ctrip_order_aggregate_v1',
                    'pii_policy' => 'aggregate_only_no_guest_staff_reservation_notes',
                    'average_los' => $averageLos,
                    'single_night_rate' => $singleNightRate,
                    'average_booking_lead_days' => $averageLeadDays,
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
