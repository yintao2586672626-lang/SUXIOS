<?php
declare(strict_types=1);

use app\model\Hotel;
use app\model\User;
use app\service\RevenueAiOverviewService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$hotelId = 80;
$businessDate = '2026-08-08';
$endpoint = 'http://127.0.0.1:8080/api/online-data/data-import';
$fixtureFormat = 'biff_xls';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--hotel-id=')) {
        $hotelId = (int)substr($argument, strlen('--hotel-id='));
    } elseif (str_starts_with($argument, '--business-date=')) {
        $businessDate = trim(substr($argument, strlen('--business-date=')));
    } elseif (str_starts_with($argument, '--endpoint=')) {
        $endpoint = trim(substr($argument, strlen('--endpoint=')));
    } elseif (str_starts_with($argument, '--fixture-format=')) {
        $fixtureFormat = trim(substr($argument, strlen('--fixture-format=')));
    }
}
if ($hotelId <= 0
    || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1
    || !str_starts_with($endpoint, 'http://127.0.0.1:')
    || !in_array($fixtureFormat, ['biff_xls', 'html_table_xls'], true)
) {
    throw new RuntimeException('Invalid Ctrip order import smoke scope.');
}

$app = new App(dirname(__DIR__));
$app->initialize();
$hotel = Hotel::find($hotelId);
if (!$hotel) {
    throw new RuntimeException('Target hotel does not exist: ' . $hotelId);
}
$user = null;
foreach (User::where('status', 1)->order('id', 'asc')->limit(100)->select() as $candidate) {
    if ($candidate->isSuperAdmin()) {
        $user = $candidate;
        break;
    }
}
if (!$user) {
    throw new RuntimeException('An active super administrator is required for local API acceptance.');
}

$temporaryBase = tempnam(sys_get_temp_dir(), 'ctrip-order-explicit-fixture-');
if ($temporaryBase === false) {
    throw new RuntimeException('Unable to allocate a temporary XLS fixture.');
}
$fixturePath = $temporaryBase . '.xls';
rename($temporaryBase, $fixturePath);
$token = 'ctrip_order_smoke_' . bin2hex(random_bytes(18));

try {
    $headers = [
        '订单编号', '订单状态名称', '入住时间', '离店时间', '预订日期', '最后更新时间',
        '间夜数', '房间数量', '底价总额', '售卖价', '房型', '预订渠道', '门店名称',
        '住客姓名', '联系电话', '订单类型',
    ];
    $fixtureRows = [
        ['FIX-100', '已确认', $businessDate, date('Y-m-d', strtotime($businessDate . ' +2 days')), date('Y-m-d H:i:s', strtotime($businessDate . ' -7 days')), date('Y-m-d H:i:s', strtotime($businessDate . ' -6 days')), 2, 1, 400, 520, '江景大床房', '携程', (string)$hotel->name, '测试住客甲', '13800000001', '正常'],
        ['FIX-101', '已入住', $businessDate, date('Y-m-d', strtotime($businessDate . ' +1 day')), date('Y-m-d H:i:s', strtotime($businessDate . ' -3 days')), date('Y-m-d H:i:s', strtotime($businessDate . ' -2 days')), 1, 2, 500, 620, '双床房', '携程', (string)$hotel->name, '测试住客乙', '13800000002', '正常'],
        ['FIX-102', '已取消', $businessDate, date('Y-m-d', strtotime($businessDate . ' +1 day')), date('Y-m-d H:i:s', strtotime($businessDate . ' -2 days')), date('Y-m-d H:i:s', strtotime($businessDate . ' -1 day')), 1, 1, 200, 260, '大床房', '去哪儿', (string)$hotel->name, '测试住客丙', '13800000003', '正常'],
    ];
    if ($fixtureFormat === 'biff_xls') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('携程订单-明确测试fixture');
        $sheet->fromArray([['携程旧版订单明确测试 fixture']], null, 'A1');
        $sheet->fromArray([$headers], null, 'A3');
        $sheet->fromArray($fixtureRows, null, 'A4');
        (new Xls($spreadsheet))->save($fixturePath);
        $spreadsheet->disconnectWorksheets();
    } else {
        $escapeHtml = static fn(mixed $value): string => htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8">'
            . '<title>携程订单明确测试 fixture</title></head><body><table>'
            . '<tr><td colspan="16">携程旧版订单明确测试 fixture</td></tr><tr><td></td></tr><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . $escapeHtml($header) . '</th>';
        }
        $html .= '</tr>';
        foreach ($fixtureRows as $fixtureRow) {
            $html .= '<tr>';
            foreach ($fixtureRow as $cell) {
                $html .= '<td>' . $escapeHtml($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></body></html>';
        if (file_put_contents($fixturePath, $html) === false) {
            throw new RuntimeException('Unable to write the HTML-table-as-XLS fixture.');
        }
    }

    cache('token_' . $token, [
        'user_id' => (int)$user->id,
        'created_at' => time(),
        'auth_version' => $user->authSessionVersion(),
    ], 300);

    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize local HTTP client.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($fixturePath, 'application/vnd.ms-excel', 'EXPLICIT-TEST-FIXTURE-ctrip-orders.xls'),
            'system_hotel_id' => (string)$hotelId,
            'hotel_name' => (string)$hotel->name,
            'name' => (string)$hotel->name . '-携程订单明确测试fixture',
            'platform' => 'ctrip',
            'data_type' => 'order',
            'metric_scope' => 'ota_channel',
            'ingestion_method' => 'import_excel',
            'fixture_status' => 'explicit_test_fixture',
        ],
    ]);
    $responseBody = curl_exec($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($responseBody) || $responseBody === '') {
        throw new RuntimeException('Local import API returned no body: ' . $curlError);
    }
    foreach (['FIX-100', 'FIX-101', 'FIX-102', '测试住客甲', '测试住客乙', '测试住客丙', '13800000001', '13800000002', '13800000003'] as $privateText) {
        if (str_contains($responseBody, $privateText)) {
            throw new RuntimeException('Import API response exposed order or guest details.');
        }
    }
    $payload = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    $result = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $readback = is_array($result['import_readback'] ?? null) ? $result['import_readback'] : [];
    $preview = is_array($result['import_preview'] ?? null) ? $result['import_preview'] : [];
    $taskDiagnostic = [];
    if ((int)($result['task_id'] ?? 0) > 0) {
        $taskRow = Db::name('platform_data_sync_tasks')->where('id', (int)$result['task_id'])->find();
        $taskStats = is_array($taskRow)
            ? json_decode((string)($taskRow['stats_json'] ?? '{}'), true)
            : [];
        $taskDiagnostic = [
            'mismatch_field' => $taskStats['mismatch_field'] ?? null,
            'attempted_count' => $taskStats['attempted_count'] ?? null,
            'inserted_count' => $taskStats['inserted_count'] ?? null,
            'updated_count' => $taskStats['updated_count'] ?? null,
            'readback_count' => $taskStats['readback_count'] ?? null,
        ];
    }
    if ($httpStatus !== 200
        || (int)($payload['code'] ?? 0) !== 200
        || (int)($result['saved_count'] ?? 0) !== 2
        || (string)($readback['status'] ?? '') !== 'verified'
        || ($readback['value_level_verified'] ?? false) !== true
        || (int)($readback['readback_count'] ?? 0) !== 2
        || (string)($preview['quality_status'] ?? '') !== 'user_provided_unverified'
        || (string)($preview['real_file_acceptance'] ?? '') !== 'test_fixture_only'
    ) {
        throw new RuntimeException(sprintf(
            'Local import API contract failed: HTTP %d, code %d, message %s; evidence=%s',
            $httpStatus,
            (int)($payload['code'] ?? 0),
            (string)($payload['message'] ?? $payload['msg'] ?? 'unknown'),
            json_encode([
                'status' => $result['status'] ?? null,
                'task_id' => $result['task_id'] ?? null,
                'normalized_count' => $result['normalized_count'] ?? null,
                'saved_count' => $result['saved_count'] ?? null,
                'readback_count' => $result['readback_count'] ?? null,
                'readback_verified' => $result['readback_verified'] ?? null,
                'rolled_back' => $result['rolled_back'] ?? null,
                'failure_reason' => $result['failure_reason'] ?? null,
                'message' => $result['message'] ?? null,
                'missing_fields' => $result['missing_fields'] ?? null,
                'sync_diagnostics' => $result['sync_diagnostics'] ?? null,
                'import_readback' => $readback,
                'task_diagnostic' => $taskDiagnostic,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        ));
    }

    $taskId = (int)($result['task_id'] ?? $result['sync_task_id'] ?? 0);
    if ($taskId <= 0) {
        throw new RuntimeException('Import API did not return a persisted task_id.');
    }
    $storedRows = Db::name('online_daily_data')
        ->where('sync_task_id', $taskId)
        ->where('system_hotel_id', $hotelId)
        ->where('data_date', $businessDate)
        ->order('id', 'asc')
        ->select()
        ->toArray();
    if (count($storedRows) !== 2) {
        throw new RuntimeException('Database exact readback count mismatch for task ' . $taskId . '.');
    }
    $storedJson = json_encode($storedRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    foreach (['FIX-100', 'FIX-101', 'FIX-102', '测试住客甲', '测试住客乙', '测试住客丙', '13800000001', '13800000002', '13800000003'] as $privateText) {
        if (str_contains($storedJson, $privateText)) {
            throw new RuntimeException('Persisted aggregate exposed order or guest details.');
        }
    }
    if (!str_contains($storedJson, 'explicit_test_fixture')
        || !str_contains($storedJson, 'reference_bottom_price_not_confirmed_revenue')
    ) {
        throw new RuntimeException('Persisted fixture or reference-bottom-price semantics are missing.');
    }
    $expectedByChannel = [
        'ctrip' => [
            'business_date' => $businessDate,
            'active_orders' => 2.0,
            'gross_orders' => 2.0,
            'cancelled_orders' => 0.0,
            'room_nights' => 4.0,
            'cancel_rate' => 0.0,
            'average_booking_lead_days' => 5.0,
            'reference_bottom_price_total' => 900.0,
            'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
            'source_format' => $fixtureFormat,
        ],
        'qunar' => [
            'business_date' => $businessDate,
            'active_orders' => 0.0,
            'gross_orders' => 1.0,
            'cancelled_orders' => 1.0,
            'room_nights' => 0.0,
            'cancel_rate' => 1.0,
            'average_booking_lead_days' => null,
            'reference_bottom_price_total' => 0.0,
            'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
            'source_format' => $fixtureFormat,
        ],
    ];
    $assertOracleValue = static function (mixed $actual, mixed $expected, string $label): void {
        if ($expected === null) {
            if ($actual !== null) {
                throw new RuntimeException($label . ' must be null.');
            }
            return;
        }
        if (is_int($expected) || is_float($expected)) {
            if (!is_numeric($actual) || abs((float)$actual - (float)$expected) > 0.000001) {
                throw new RuntimeException($label . ' numeric value mismatch.');
            }
            return;
        }
        if ((string)$actual !== (string)$expected) {
            throw new RuntimeException($label . ' value mismatch.');
        }
    };

    $storedRowIds = [];
    $databaseSourceFormats = [];
    $databaseEvidenceByRowId = [];
    $databaseChannels = [];
    foreach ($storedRows as $storedRow) {
        $storedRawData = $storedRow['raw_data'] ?? [];
        $storedPayload = is_array($storedRawData)
            ? $storedRawData
            : json_decode((string)$storedRawData, true, 512, JSON_THROW_ON_ERROR);
        $canonicalRow = is_array($storedPayload['row'] ?? null) ? $storedPayload['row'] : [];
        $rawData = is_array($canonicalRow['raw_data'] ?? null) ? $canonicalRow['raw_data'] : [];
        $channelKey = (string)($rawData['channel_key'] ?? $canonicalRow['source'] ?? '');
        if (!isset($expectedByChannel[$channelKey]) || isset($databaseChannels[$channelKey])) {
            throw new RuntimeException('Database readback channel set does not match the fixture oracle.');
        }
        $storedSourceFormat = (string)($rawData['source_format'] ?? '');
        if ($storedSourceFormat !== $fixtureFormat) {
            throw new RuntimeException('Database source_format does not match the requested fixture format.');
        }
        $storedRowId = (int)($storedRow['id'] ?? 0);
        if ($storedRowId <= 0) {
            throw new RuntimeException('Database readback row is missing its persisted id.');
        }
        $storedRowIds[$storedRowId] = true;
        $databaseChannels[$channelKey] = true;
        $databaseSourceFormats[$storedSourceFormat] = true;
        $databaseEvidenceByRowId[$storedRowId] = [
            'channel_key' => $channelKey,
        ];
        $databaseActual = [
            'business_date' => $storedRow['data_date'] ?? null,
            'active_orders' => $canonicalRow['book_order_num'] ?? null,
            'gross_orders' => $canonicalRow['gross_order_num'] ?? null,
            'cancelled_orders' => $canonicalRow['cancel_order_num'] ?? null,
            'room_nights' => $canonicalRow['quantity'] ?? null,
            'cancel_rate' => $canonicalRow['cancel_rate'] ?? null,
            'average_booking_lead_days' => $canonicalRow['avg_lead_days'] ?? null,
            'reference_bottom_price_total' => $canonicalRow['amount'] ?? null,
            'amount_semantics' => $rawData['amount_semantics'] ?? null,
            'source_format' => $storedSourceFormat,
        ];
        $assertOracleValue(
            $canonicalRow['source'] ?? null,
            $channelKey,
            'Database ' . $channelKey . ' source'
        );
        foreach ($expectedByChannel[$channelKey] as $field => $expectedValue) {
            $assertOracleValue(
                $databaseActual[$field] ?? null,
                $expectedValue,
                'Database ' . $channelKey . ' ' . $field
            );
        }
    }
    $expectedChannels = array_keys($expectedByChannel);
    $actualDatabaseChannels = array_keys($databaseChannels);
    sort($expectedChannels);
    sort($actualDatabaseChannels);
    if ($actualDatabaseChannels !== $expectedChannels) {
        throw new RuntimeException('Database readback did not contain exactly the expected fixture channels.');
    }

    $apiOrigin = preg_replace('#/api/online-data/data-import$#', '', $endpoint);
    $overviewEndpoint = $apiOrigin . '/api/revenue-ai/overview?' . http_build_query([
        'business_date' => $businessDate,
        'hotel_id' => $hotelId,
        'platform' => 'ctrip',
    ]);
    $overviewCurl = curl_init($overviewEndpoint);
    if ($overviewCurl === false) {
        throw new RuntimeException('Unable to initialize Revenue AI HTTP client.');
    }
    curl_setopt_array($overviewCurl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $overviewBody = curl_exec($overviewCurl);
    $overviewHttpStatus = (int)curl_getinfo($overviewCurl, CURLINFO_RESPONSE_CODE);
    $overviewCurlError = curl_error($overviewCurl);
    curl_close($overviewCurl);
    if (!is_string($overviewBody) || $overviewBody === '') {
        throw new RuntimeException('Revenue AI API returned no body: ' . $overviewCurlError);
    }
    foreach (['FIX-100', 'FIX-101', 'FIX-102', '测试住客甲', '测试住客乙', '测试住客丙', '13800000001', '13800000002', '13800000003'] as $privateText) {
        if (str_contains($overviewBody, $privateText)) {
            throw new RuntimeException('Revenue AI API exposed order or guest details.');
        }
    }
    $overviewPayload = json_decode($overviewBody, true, 512, JSON_THROW_ON_ERROR);
    $overviewHttpData = is_array($overviewPayload['data'] ?? null) ? $overviewPayload['data'] : [];
    if ($overviewHttpStatus !== 200 || (int)($overviewPayload['code'] ?? 0) !== 200) {
        throw new RuntimeException(sprintf(
            'Revenue AI API failed: HTTP %d, code %d, message %s',
            $overviewHttpStatus,
            (int)($overviewPayload['code'] ?? 0),
            (string)($overviewPayload['message'] ?? $overviewPayload['msg'] ?? 'unknown')
        ));
    }

    $overview = (new RevenueAiOverviewService())->overview([
        'business_date' => $businessDate,
        'hotel_id' => $hotelId,
        'is_super_admin' => true,
    ]);
    $manualImports = is_array($overview['manual_order_imports'] ?? null)
        ? $overview['manual_order_imports']
        : [];
    $httpManualImports = is_array($overviewHttpData['manual_order_imports'] ?? null)
        ? $overviewHttpData['manual_order_imports']
        : [];
    $fixtureRows = array_values(array_filter(
        is_array($manualImports['rows'] ?? null) ? $manualImports['rows'] : [],
        static fn(array $row): bool => ($row['real_file_acceptance'] ?? '') === 'test_fixture_only'
    ));
    $httpFixtureRows = array_values(array_filter(
        is_array($httpManualImports['rows'] ?? null) ? $httpManualImports['rows'] : [],
        static fn(array $row): bool => ($row['real_file_acceptance'] ?? '') === 'test_fixture_only'
    ));
    $taskFixtureRows = array_values(array_filter(
        $fixtureRows,
        static fn(array $row): bool => isset($storedRowIds[(int)($row['row_id'] ?? 0)])
    ));
    $httpTaskFixtureRows = array_values(array_filter(
        $httpFixtureRows,
        static fn(array $row): bool => isset($storedRowIds[(int)($row['row_id'] ?? 0)])
    ));
    if ((string)($manualImports['status'] ?? '') !== 'available_unverified'
        || count($taskFixtureRows) !== 2
        || ($manualImports['summary']['readback_verified'] ?? false) !== true
        || (string)($httpManualImports['status'] ?? '') !== 'available_unverified'
        || count($httpTaskFixtureRows) !== 2
        || ($httpManualImports['summary']['readback_verified'] ?? false) !== true
    ) {
        throw new RuntimeException('Revenue overview did not read the persisted fixture aggregates.');
    }
    $verifyRevenueRows = static function (array $rows, string $evidenceName) use (
        $assertOracleValue,
        $databaseEvidenceByRowId,
        $expectedByChannel
    ): array {
        $verifiedChannels = [];
        foreach ($rows as $row) {
            $rowId = (int)($row['row_id'] ?? 0);
            $databaseEvidence = $databaseEvidenceByRowId[$rowId] ?? null;
            if (!is_array($databaseEvidence)) {
                throw new RuntimeException($evidenceName . ' row is not linked to the task-scoped database readback.');
            }
            $channelKey = (string)($row['channel_key'] ?? '');
            if (!isset($expectedByChannel[$channelKey])
                || isset($verifiedChannels[$channelKey])
                || (string)$databaseEvidence['channel_key'] !== $channelKey
            ) {
                throw new RuntimeException($evidenceName . ' channel/source linkage mismatch.');
            }
            $actual = [
                'business_date' => $row['business_date'] ?? null,
                'active_orders' => $row['active_orders'] ?? null,
                'gross_orders' => $row['gross_orders'] ?? null,
                'cancelled_orders' => $row['cancelled_orders'] ?? null,
                'room_nights' => $row['room_nights'] ?? null,
                'cancel_rate' => $row['cancel_rate'] ?? null,
                'average_booking_lead_days' => $row['average_booking_lead_days'] ?? null,
                'reference_bottom_price_total' => $row['reference_bottom_price_total'] ?? null,
                'amount_semantics' => $row['amount_semantics'] ?? null,
                'source_format' => $row['source_format'] ?? null,
            ];
            $assertOracleValue(
                $row['source'] ?? null,
                'ctrip_manual_order_import',
                $evidenceName . ' ' . $channelKey . ' source'
            );
            foreach ($expectedByChannel[$channelKey] as $field => $expectedValue) {
                $assertOracleValue(
                    $actual[$field] ?? null,
                    $expectedValue,
                    $evidenceName . ' ' . $channelKey . ' ' . $field
                );
            }
            $verifiedChannels[$channelKey] = true;
        }
        $channels = array_keys($verifiedChannels);
        sort($channels);
        return $channels;
    };
    $serviceVerifiedChannels = $verifyRevenueRows($taskFixtureRows, 'Revenue AI service readback');
    $httpVerifiedChannels = $verifyRevenueRows($httpTaskFixtureRows, 'Revenue AI HTTP readback');
    $revenueOverviewSourceFormatVerified = $serviceVerifiedChannels === $expectedChannels
        && $httpVerifiedChannels === $expectedChannels;

    echo json_encode([
        'status' => 'passed',
        'acceptance_scope' => 'local_explicit_test_fixture_only',
        'real_ctrip_file_acceptance' => 'unverified',
        'hotel_id' => $hotelId,
        'business_date' => $businessDate,
        'fixture_format' => $fixtureFormat,
        'http_status' => $httpStatus,
        'revenue_overview_http_status' => $overviewHttpStatus,
        'sync_task_id' => $taskId,
        'saved_count' => (int)$result['saved_count'],
        'readback_count' => (int)$readback['readback_count'],
        'value_level_readback_verified' => true,
        'fixture_status' => 'explicit_test_fixture',
        'quality_status' => 'user_provided_unverified',
        'privacy_status' => 'raw_order_id_and_guest_pii_not_persisted',
        'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
        'database_source_formats' => array_keys($databaseSourceFormats),
        'revenue_overview_source_format_verified' => $revenueOverviewSourceFormatVerified,
        'revenue_overview_verified_channels' => $serviceVerifiedChannels,
        'revenue_overview_fixture_rows' => count($taskFixtureRows),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    cache('token_' . $token, null);
    if (is_file($fixturePath)) {
        unlink($fixturePath);
    }
}
