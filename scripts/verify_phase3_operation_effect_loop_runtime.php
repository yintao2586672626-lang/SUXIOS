<?php
declare(strict_types=1);

use app\service\Phase3OperationEffectLoopService;
use think\App;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    exit(1);
}

require $autoload;

$root = dirname(__DIR__);
$targetDate = date('Y-m-d');
$previousDate = date('Y-m-d', strtotime($targetDate . ' -1 day') ?: time());
$runtimePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'suxi_phase3_effect_loop_runtime_'
    . date('YmdHis')
    . '_'
    . bin2hex(random_bytes(4))
    . DIRECTORY_SEPARATOR;
$checks = [];
$issues = [];

function phase3_runtime_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
{
    $row = [
        'code' => $code,
        'status' => $ok ? 'passed' : 'failed',
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $checks[] = $row;
}

function phase3_runtime_issue(array &$issues, string $code, string $message, array $details = []): void
{
    $row = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $issues[] = $row;
}

function phase3_runtime_tracking_key(int $hotelId, string $actionCode, string $questionKey): string
{
    $identity = $actionCode !== '' ? $actionCode : $questionKey;
    return $hotelId . '|' . preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $identity);
}

function phase3_runtime_delete_dir(string $dir): void
{
    $dir = rtrim($dir, DIRECTORY_SEPARATOR);
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'suxi_phase3_effect_loop_runtime_';
    if (strncmp($dir, $prefix, strlen($prefix)) !== 0) {
        throw new RuntimeException('Refuse to delete non-verifier runtime path: ' . $dir);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function phase3_fixture_snapshot(string $targetDate): array
{
    $closedKey = phase3_runtime_tracking_key(101, 'fix_conversion_gap', 'revenue_traffic_conversion');

    return [
        'run_id' => 'daily_workbench_' . str_replace('-', '', $targetDate) . '_120000_abcdef12',
        'snapshot_type' => 'phase2_daily_workbench_patrol',
        'created_at' => $targetDate . ' 12:00:00',
        'trigger_type' => 'cron',
        'scope' => [
            'metric_scope' => 'ota_channel',
            'target_date' => $targetDate,
            'source_policy' => 'read_existing_collection_reliability_only',
            'protected_boundary' => 'Snapshot reads existing OTA evidence only; it does not trigger Ctrip or Meituan acquisition.',
        ],
        'rows' => [
            [
                'hotel_id' => 101,
                'hotel_name' => 'Phase3 Fixture Hotel A',
                'target_date' => $targetDate,
                'status' => 'incomplete',
                'metric_diagnosis' => [
                    'status' => 'blocked',
                    'data_gap_codes' => ['conversion_drop'],
                ],
                'ai_evidence' => [
                    'status' => 'blocked',
                    'diagnosis_status' => 'blocked',
                    'blocking_missing_codes' => ['traffic_conversion_review_needed'],
                    'explanation' => [
                        'summary' => 'OTA conversion dropped and needs operator follow-up.',
                        'missing_codes' => ['traffic_conversion_review_needed'],
                    ],
                ],
                'operation_execution' => [
                    'blocking_missing_codes' => [],
                ],
                'next_action' => [
                    'hotel_id' => 101,
                    'hotel_name' => 'Phase3 Fixture Hotel A',
                    'target_date' => $targetDate,
                    'platform' => 'ctrip',
                    'action_code' => 'fix_conversion_gap',
                    'question_key' => 'revenue_traffic_conversion',
                    'priority' => 'high',
                    'action' => 'Review OTA price, availability, and conversion gap.',
                    'entry' => '/api/online-data/daily-workbench',
                    'data_gaps' => ['conversion_drop'],
                ],
            ],
            [
                'hotel_id' => 102,
                'hotel_name' => 'Phase3 Fixture Hotel B',
                'target_date' => $targetDate,
                'status' => 'incomplete',
                'metric_diagnosis' => [
                    'status' => 'blocked',
                    'data_gap_codes' => ['conversion_drop'],
                ],
                'ai_evidence' => [
                    'status' => 'blocked',
                    'diagnosis_status' => 'blocked',
                    'blocking_missing_codes' => ['traffic_conversion_review_needed'],
                    'explanation' => [
                        'summary' => 'Similar OTA conversion issue exists in another hotel.',
                        'missing_codes' => ['traffic_conversion_review_needed'],
                    ],
                ],
                'next_action' => [
                    'hotel_id' => 102,
                    'hotel_name' => 'Phase3 Fixture Hotel B',
                    'target_date' => $targetDate,
                    'platform' => 'ctrip',
                    'action_code' => 'fix_conversion_gap',
                    'question_key' => 'revenue_traffic_conversion',
                    'priority' => 'medium',
                    'action' => 'Review OTA price, availability, and conversion gap.',
                    'entry' => '/api/online-data/daily-workbench',
                    'data_gaps' => ['conversion_drop'],
                ],
            ],
        ],
        'next_actions' => [
            [
                'hotel_id' => 101,
                'hotel_name' => 'Phase3 Fixture Hotel A',
                'target_date' => $targetDate,
                'platform' => 'ctrip',
                'action_code' => 'fix_conversion_gap',
                'question_key' => 'revenue_traffic_conversion',
                'priority' => 'high',
                'action' => 'Review OTA price, availability, and conversion gap.',
                'entry' => '/api/online-data/daily-workbench',
                'data_gaps' => ['conversion_drop'],
            ],
            [
                'hotel_id' => 102,
                'hotel_name' => 'Phase3 Fixture Hotel B',
                'target_date' => $targetDate,
                'platform' => 'ctrip',
                'action_code' => 'fix_conversion_gap',
                'question_key' => 'revenue_traffic_conversion',
                'priority' => 'medium',
                'action' => 'Review OTA price, availability, and conversion gap.',
                'entry' => '/api/online-data/daily-workbench',
                'data_gaps' => ['conversion_drop'],
            ],
        ],
        'action_tracking' => [
            'items' => [
                $closedKey => [
                    'hotel_id' => 101,
                    'action_code' => 'fix_conversion_gap',
                    'question_key' => 'revenue_traffic_conversion',
                    'status' => 'done',
                    'note' => 'Operator completed OTA conversion action and attached evidence.',
                    'updated_at' => $targetDate . ' 14:00:00',
                    'operation_execution' => [
                        'intent_id' => 701,
                        'intent_status' => 'approved',
                        'task_id' => 801,
                        'task_status' => 'executed',
                        'source_policy' => 'fixture_operation_execution_only',
                    ],
                    'review_result' => [
                        'result_status' => 'success',
                        'result_summary' => 'OTA conversion recovered in the review window.',
                        'reviewed_at' => $targetDate . ' 18:00:00',
                        'source_policy' => 'operator_review_on_runtime_patrol_snapshot_only',
                    ],
                ],
            ],
        ],
        'evidence_policy' => [
            'metric_scope' => 'ota_channel',
            'collection_logic_changed' => false,
            'raw_data_exposed' => false,
        ],
    ];
}

function phase3_fixture_metric_window(string $targetDate, string $previousDate): array
{
    return [
        'status' => 'partial',
        'target_date' => $targetDate,
        'previous_date' => $previousDate,
        'data_gaps' => ['metric_window_missing'],
        'by_hotel' => [
            101 => [
                'status' => 'ready',
                'current' => [
                    'source_row_count' => 3,
                    'sources' => ['ctrip'],
                    'amount' => 2600,
                    'quantity' => 8,
                    'book_order_num' => 6,
                    'list_exposure' => 1500,
                    'detail_exposure' => 360,
                    'order_submit_num' => 18,
                ],
                'previous' => [
                    'source_row_count' => 3,
                    'sources' => ['ctrip'],
                    'amount' => 1800,
                    'quantity' => 5,
                    'book_order_num' => 4,
                    'list_exposure' => 1300,
                    'detail_exposure' => 300,
                    'order_submit_num' => 12,
                ],
                'delta' => [
                    'amount' => 800,
                    'quantity' => 3,
                    'book_order_num' => 2,
                    'list_exposure' => 200,
                    'detail_exposure' => 60,
                    'order_submit_num' => 6,
                ],
                'missing_dates' => [],
                'data_gaps' => [],
            ],
            102 => [
                'status' => 'metric_window_missing',
                'current' => [],
                'previous' => [],
                'delta' => [],
                'missing_dates' => [$targetDate, $previousDate],
                'data_gaps' => ['metric_window_missing'],
            ],
        ],
    ];
}

try {
    if (!is_dir($runtimePath) && !mkdir($runtimePath, 0775, true) && !is_dir($runtimePath)) {
        throw new RuntimeException('Failed to create isolated runtime path.');
    }

    $app = new App($root);
    $app->setRuntimePath($runtimePath);
    $app->initialize();
    phase3_runtime_check($checks, 'isolated_runtime', is_dir($runtimePath), 'Verifier uses an isolated temporary runtime path.', [
        'runtime_path' => $runtimePath,
    ]);

    $service = new Phase3OperationEffectLoopService();
    $payload = $service->buildFromSnapshot(phase3_fixture_snapshot($targetDate), [
        'target_date' => $targetDate,
        'metric_window' => phase3_fixture_metric_window($targetDate, $previousDate),
    ]);

    phase3_runtime_check($checks, 'phase_shape', (string)($payload['phase'] ?? '') === 'phase3_operation_effect_loop', 'Payload exposes the phase3 operation effect loop contract.');
    phase3_runtime_check($checks, 'scope_boundary', ($payload['scope']['collection_logic_changed'] ?? true) === false
        && ($payload['scope']['collection_fields_changed'] ?? true) === false
        && ($payload['scope']['auto_decision_enabled'] ?? true) === false
        && (string)($payload['scope']['metric_scope'] ?? '') === 'ota_channel', 'Payload keeps OTA scope and protected collection boundaries.');

    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    phase3_runtime_check($checks, 'two_loop_rows', count($rows) === 2, 'Fixture produces two operation loop rows.');

    $first = is_array($rows[0] ?? null) ? $rows[0] : [];
    $firstStages = is_array($first['stages'] ?? null) ? $first['stages'] : [];
    phase3_runtime_check($checks, 'six_stage_keys', isset(
        $firstStages['anomaly'],
        $firstStages['operation_action'],
        $firstStages['execution_evidence'],
        $firstStages['effect_review'],
        $firstStages['sop'],
        $firstStages['replication']
    ), 'Each row exposes anomaly, action, evidence, review, SOP, and replication stages.');
    phase3_runtime_check($checks, 'closed_action_reviewed', (string)($firstStages['execution_evidence']['status'] ?? '') === 'executed_evidence_recorded'
        && (string)($firstStages['effect_review']['status'] ?? '') === 'reviewed'
        && (string)($firstStages['effect_review']['result_status'] ?? '') === 'success', 'Closed action carries execution evidence and a success review.');
    phase3_runtime_check($checks, 'sop_and_replication_candidates', (string)($firstStages['sop']['status'] ?? '') === 'candidate'
        && (string)($firstStages['replication']['status'] ?? '') === 'candidate'
        && count((array)($firstStages['replication']['target_hotels'] ?? [])) === 1, 'Successful reviewed action becomes SOP and replication candidate.');
    phase3_runtime_check($checks, 'causality_not_claimed', ($firstStages['effect_review']['causality_claimed'] ?? true) === false, 'Effect review does not claim causality automatically.');

    $second = is_array($rows[1] ?? null) ? $rows[1] : [];
    $secondStages = is_array($second['stages'] ?? null) ? $second['stages'] : [];
    phase3_runtime_check($checks, 'missing_execution_explicit', (string)($secondStages['execution_evidence']['status'] ?? '') === 'execution_missing'
        && in_array('execution_missing', (array)($secondStages['effect_review']['reason_codes'] ?? []), true)
        && (string)($secondStages['sop']['status'] ?? '') === 'not_ready', 'Untracked action exposes missing execution, missing review, and no SOP candidate.');

    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    phase3_runtime_check($checks, 'summary_counts', (int)($summary['anomaly_count'] ?? 0) === 2
        && (int)($summary['executed_action_count'] ?? 0) === 1
        && (int)($summary['reviewed_action_count'] ?? 0) === 1
        && (int)($summary['sop_candidate_count'] ?? 0) === 1
        && (int)($summary['replication_candidate_count'] ?? 0) === 1, 'Summary counts reflect closed, missing, SOP, and replication states.');

    $published = $service->publishSopFromLoopRow($first, [
        'run_id' => (string)($payload['run_id'] ?? ''),
        'title' => 'Fixture OTA conversion recovery SOP',
    ], 0);
    $sop = is_array($published['sop'] ?? null) ? $published['sop'] : [];
    phase3_runtime_check($checks, 'sop_published_to_runtime_ledger', (string)($sop['status'] ?? '') === 'published'
        && (string)($sop['metric_scope'] ?? '') === 'ota_channel'
        && ($sop['auto_apply_enabled'] ?? true) === false
        && ($sop['raw_data_exposed'] ?? true) === false, 'SOP candidate can be persisted to runtime ledger without auto-apply or raw data.');

    $replicated = $service->createReplicationPlanFromLoopRow($first, [
        'run_id' => (string)($payload['run_id'] ?? ''),
    ], 0);
    $plan = is_array($replicated['replication_plan'] ?? null) ? $replicated['replication_plan'] : [];
    phase3_runtime_check($checks, 'replication_plan_created_to_runtime_ledger', (string)($plan['status'] ?? '') === 'draft'
        && count((array)($plan['target_hotels'] ?? [])) === 1
        && ($plan['auto_apply_enabled'] ?? true) === false
        && ($plan['auto_decision_enabled'] ?? true) === false, 'Replication candidate can be persisted as a manual draft plan.');

    $ledger = $service->ledger();
    phase3_runtime_check($checks, 'ledger_readback', count((array)($ledger['sops'] ?? [])) === 1
        && count((array)($ledger['replication_plans'] ?? [])) === 1
        && ($ledger['scope']['collection_logic_changed'] ?? true) === false, 'Runtime ledger reads back persisted SOP and replication plan.');

    $encoded = json_encode([$payload, $published, $replicated, $ledger], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    phase3_runtime_check($checks, 'no_raw_or_sensitive_output', is_string($encoded)
        && stripos($encoded, '"raw_data":') === false
        && stripos($encoded, '"cookie"') === false
        && stripos($encoded, '"usertoken"') === false
        && stripos($encoded, '"usersign"') === false
        && stripos($encoded, '"spidertoken"') === false, 'Runtime output does not expose raw_data or sensitive credential literals.');
} catch (Throwable $e) {
    phase3_runtime_issue($issues, 'verifier_runtime_error', $e->getMessage(), [
        'class' => $e::class,
    ]);
} finally {
    try {
        phase3_runtime_delete_dir($runtimePath);
    } catch (Throwable $cleanupError) {
        phase3_runtime_issue($issues, 'runtime_cleanup_failed', $cleanupError->getMessage());
    }
}

$failedChecks = array_values(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') !== 'passed'));
$ok = $issues === [] && $failedChecks === [];
$payload = [
    'status' => $ok ? 'passed' : 'failed',
    'metric_scope' => 'ota_channel',
    'collection_logic_changed' => false,
    'checks' => $checks,
    'issues' => $issues,
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($ok ? 0 : 1);
