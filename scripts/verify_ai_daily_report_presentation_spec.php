#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\AiDailyReportPresentationSpecService;
use app\service\AiDailyReportService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

$errors = [];
$summary = [];

try {
    $candidate = Db::name('ai_daily_reports')
        ->alias('report')
        ->join('hotels hotel', 'hotel.id = report.hotel_id')
        ->whereNull('report.deleted_at')
        ->where('hotel.tenant_id', '>', 0)
        ->field('report.id,report.hotel_id,report.tenant_id,report.report_date')
        ->order('report.id', 'desc')
        ->find();
    if (!is_array($candidate)) {
        throw new RuntimeException('no persisted AI daily report is available for presentation-spec verification');
    }

    $reportId = (int)($candidate['id'] ?? 0);
    $hotelId = (int)($candidate['hotel_id'] ?? 0);
    $report = (new AiDailyReportService())->read($reportId, [$hotelId]);
    if (!is_array($report) || (int)($report['id'] ?? 0) !== $reportId) {
        throw new RuntimeException('tenant/hotel-scoped AI daily report read failed');
    }

    $service = new AiDailyReportPresentationSpecService();
    $tenantId = $service->resolveTenantScope($report);
    $saved = $service->saveAndReadback($report, 'owner', 0);
    $readback = $service->readLatest($reportId, [$hotelId], $tenantId, 'owner');
    $classCounts = [];
    if (!is_array($readback)) {
        $errors[] = 'latest_readback_missing';
    } else {
        if (($saved['readback_verified'] ?? false) !== true
            || ($readback['readback_verified'] ?? false) !== true
        ) {
            $errors[] = 'readback_not_verified';
        }
        if (!hash_equals(
            (string)($saved['spec_fingerprint'] ?? ''),
            (string)($readback['spec_fingerprint'] ?? '')
        )) {
            $errors[] = 'fingerprint_mismatch';
        }
        if (($readback['render_status'] ?? '') !== 'not_rendered'
            || ($readback['spec']['render_contract']['html']['status'] ?? '') !== 'not_rendered'
            || ($readback['spec']['render_contract']['pptx']['status'] ?? '') !== 'not_rendered'
        ) {
            $errors[] = 'render_truth_state_mismatch';
        }
        if (($readback['spec']['authorization']['external_write_authorized'] ?? true) !== false
            || ($readback['spec']['authorization']['ota_write_authorized'] ?? true) !== false
            || ($readback['spec']['authorization']['pms_write_authorized'] ?? true) !== false
            || ($readback['spec']['authorization']['publish_authorized'] ?? true) !== false
        ) {
            $errors[] = 'authorization_boundary_mismatch';
        }
        if (($readback['spec']['visual_system']['brand'] ?? '') !== 'SUXIOS'
            || ($readback['spec']['visual_system']['external_brand_adopted'] ?? true) !== false
        ) {
            $errors[] = 'brand_boundary_mismatch';
        }
        foreach ((array)($readback['spec']['evidence_ledger'] ?? []) as $item) {
            if (!is_array($item)) {
                $errors[] = 'evidence_row_invalid';
                continue;
            }
            $class = (string)($item['class'] ?? '');
            $classCounts[$class] = ($classCounts[$class] ?? 0) + 1;
            if ($class === 'UNKNOWN' && ($item['value'] ?? null) !== null) {
                $errors[] = 'unknown_value_not_null:' . (string)($item['id'] ?? '');
            }
            if (in_array($class, ['VERIFIED_FACT', 'DERIVED_METRIC'], true)
                && ($item['metric_scope'] ?? '') !== 'ota_channel'
            ) {
                $errors[] = 'verified_evidence_scope_mismatch:' . (string)($item['id'] ?? '');
            }
            if ($class === 'ACTION_RECOMMENDATION'
                && (($item['execution_authorized'] ?? true) !== false
                    || ($item['external_write_authorized'] ?? true) !== false)
            ) {
                $errors[] = 'action_authorization_mismatch:' . (string)($item['id'] ?? '');
            }
        }
        ksort($classCounts, SORT_STRING);
    }

    $summary = [
        'report_id' => $reportId,
        'hotel_id' => $hotelId,
        'business_date' => (string)($candidate['report_date'] ?? ''),
        'record_id' => (int)($saved['record_id'] ?? 0),
        'storage_status' => (string)($saved['storage_status'] ?? ''),
        'spec_fingerprint' => (string)($saved['spec_fingerprint'] ?? ''),
        'data_status' => (string)($saved['data_status'] ?? ''),
        'adapter_version' => (string)($saved['spec']['adapter_version'] ?? ''),
        'evidence_class_counts' => $classCounts,
        'readback_verified' => ($saved['readback_verified'] ?? false) === true,
        'render_status' => (string)($saved['render_status'] ?? ''),
        'external_write_authorized' => false,
    ];
} catch (Throwable $exception) {
    $errors[] = 'exception:' . get_class($exception) . ':' . $exception->getMessage();
}

$result = [
    'status' => $errors === [] ? 'pass' : 'fail',
    'summary' => $summary,
    'errors' => $errors,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);
