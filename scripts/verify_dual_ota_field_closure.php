<?php
declare(strict_types=1);

use app\service\DualOtaFieldClosureService;
use think\App;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
(new App())->initialize();

$options = getopt('', ['hotel-id:', 'date:', 'require-ready', 'summary']);
$hotelId = filter_var($options['hotel-id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$date = trim((string)($options['date'] ?? ''));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$hotelId || !$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Usage: php scripts/verify_dual_ota_field_closure.php --hotel-id=<id> --date=YYYY-MM-DD [--require-ready] [--summary]\n");
    exit(2);
}

try {
    $service = new DualOtaFieldClosureService();
    $first = $service->build((int)$hotelId, $date);
    $second = $service->build((int)$hotelId, $date);
    if ((string)($first['closure_digest'] ?? '') === ''
        || ($first['closure_digest'] ?? null) !== ($second['closure_digest'] ?? null)
        || ($first['page_identity'] ?? null) !== ($second['page_identity'] ?? null)
    ) {
        throw new RuntimeException('dual_ota_field_closure_refresh_identity_mismatch');
    }
    foreach (['ctrip', 'meituan'] as $platform) {
        $fields = $first['platforms'][$platform]['fields'] ?? null;
        if (!is_array($fields) || count($fields) !== 13) {
            throw new RuntimeException('dual_ota_field_closure_field_count_mismatch:' . $platform);
        }
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new RuntimeException('dual_ota_field_closure_field_invalid:' . $platform);
            }
            $status = (string)($field['status'] ?? '');
            if (!in_array($status, [
                'strict_readback', 'verified_calculation', 'missing',
                'platform_not_provided', 'collection_failed', 'login_expired',
                'date_mismatch', 'caliber_uncertain',
            ], true)) {
                throw new RuntimeException('dual_ota_field_closure_status_invalid:' . $platform);
            }
            if (in_array($status, [
                'missing', 'platform_not_provided', 'collection_failed',
                'login_expired', 'date_mismatch', 'caliber_uncertain',
            ], true) && ($field['value'] ?? null) !== null) {
                throw new RuntimeException('dual_ota_field_closure_unknown_value_not_null:' . $platform);
            }
        }
    }
    if (array_key_exists('require-ready', $options)
        && (string)($first['status'] ?? '') !== 'ready'
    ) {
        throw new RuntimeException('dual_ota_field_closure_not_ready');
    }

    $closureOutput = $first;
    if (array_key_exists('summary', $options)) {
        $closureOutput = [
            'contract_version' => $first['contract_version'] ?? null,
            'tenant_id' => $first['tenant_id'] ?? null,
            'hotel_id' => $first['hotel_id'] ?? null,
            'business_date' => $first['business_date'] ?? null,
            'status' => $first['status'] ?? null,
            'closure_digest' => $first['closure_digest'] ?? null,
            'page_identity' => $first['page_identity'] ?? null,
            'revenue_analysis_consumable_field_count' => $first['revenue_analysis_consumable_field_count'] ?? null,
            'platforms' => array_map(static function (array $platform): array {
                $fields = [];
                foreach ((array)($platform['fields'] ?? []) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $fields[(string)($field['key'] ?? '')] = [
                        'status' => $field['status'] ?? null,
                        'value' => $field['value'] ?? null,
                        'observed_values' => $field['observed_values'] ?? [],
                        'source_record_refs' => $field['source_record_refs'] ?? [],
                        'revenue_analysis_consumable' => $field['revenue_analysis_consumable'] ?? false,
                    ];
                }
                return [
                    'identity_status' => $platform['identity_status'] ?? null,
                    'status' => $platform['status'] ?? null,
                    'data_source_id' => $platform['latest_collection']['data_source_id'] ?? null,
                    'sync_task_id' => $platform['latest_collection']['sync_task_id'] ?? null,
                    'sync_task_status' => $platform['latest_collection']['sync_task_status'] ?? null,
                    'platform_status' => $platform['latest_collection']['platform_status'] ?? null,
                    'target_date_status' => $platform['latest_collection']['target_date_status'] ?? null,
                    'exact_run_readback_status' => $platform['latest_collection']['exact_run_readback_status'] ?? null,
                    'reason_codes' => $platform['latest_collection']['reason_codes'] ?? [],
                    'counts' => $platform['latest_collection']['counts'] ?? [],
                    'receipt_record_refs' => array_map(
                        static fn(int $id): string => 'online_daily_data#' . $id,
                        (array)($platform['latest_collection']['receipt_record_ids'] ?? [])
                    ),
                    'accepted_record_refs' => array_map(
                        static fn(int $id): string => 'online_daily_data#' . $id,
                        (array)($platform['latest_collection']['accepted_record_ids'] ?? [])
                    ),
                    'run_readback_scope_counts' => [
                        'receipt' => $platform['latest_collection']['receipt_row_count'] ?? 0,
                        'current' => $platform['latest_collection']['receipt_current_row_count'] ?? 0,
                        'missing' => $platform['latest_collection']['receipt_missing_row_count'] ?? 0,
                        'identity_mismatch' => $platform['latest_collection']['receipt_identity_mismatch_count'] ?? 0,
                        'authoritative' => $platform['latest_collection']['authoritative_row_count'] ?? 0,
                        'mismatched' => $platform['latest_collection']['mismatched_row_count'] ?? 0,
                    ],
                    'formal_record_refs' => $platform['formal_record_refs'] ?? [],
                    'current_receipt_record_refs' => $platform['current_receipt_record_refs'] ?? [],
                    'semantic_veto_record_refs' => $platform['semantic_veto_record_refs'] ?? [],
                    'revenue_analysis' => $platform['revenue_analysis'] ?? [],
                    'fields' => $fields,
                ];
            }, (array)($first['platforms'] ?? [])),
        ];
    }

    echo json_encode([
        'verification_status' => 'contract_structure_verified',
        'closure_status' => $first['status'] ?? 'partial',
        'strict_completion_status' => ($first['status'] ?? '') === 'ready' ? 'ready' : 'blocked',
        'refresh_readback_identity_stable' => true,
        'closure' => $closureOutput,
        'sensitive_values_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'verification_status' => 'failed',
        'closure_status' => 'unknown',
        'strict_completion_status' => 'blocked',
        'reason' => preg_match('/^[a-z0-9_:.-]+$/D', $exception->getMessage()) === 1
            ? $exception->getMessage()
            : 'dual_ota_field_closure_verification_failed',
        'sensitive_values_exposed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
