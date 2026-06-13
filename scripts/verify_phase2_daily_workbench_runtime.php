<?php
declare(strict_types=1);

use app\service\DailyWorkbenchPatrolService;
use think\App;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    exit(1);
}

require $autoload;

$root = dirname(__DIR__);
$targetDate = date('Y-m-d');
$runtimePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'suxi_phase2_workbench_runtime_'
    . date('YmdHis')
    . '_'
    . bin2hex(random_bytes(4))
    . DIRECTORY_SEPARATOR;

$checks = [];
$issues = [];

function phase2_runtime_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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

function phase2_runtime_issue(array &$issues, string $code, string $message, array $details = []): void
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

function phase2_runtime_delete_dir(string $dir): void
{
    $dir = rtrim($dir, DIRECTORY_SEPARATOR);
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $prefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'suxi_phase2_workbench_runtime_';
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

function phase2_runtime_payload(string $targetDate): array
{
    return [
        'scope' => [
            'metric_scope' => 'ota_channel',
            'target_date' => $targetDate,
            'requested_hotel_limit' => 2,
            'source_policy' => 'read_existing_collection_reliability_only',
        ],
        'summary' => [
            'total_hotels' => 2,
            'complete_hotels' => 1,
            'incomplete_hotels' => 1,
            'request_failed_hotels' => 0,
            'action_required_hotels' => 1,
            'next_action_count' => 1,
            'high_priority_action_count' => 1,
            'target_date_source_rows' => 8,
            'ai_evidence_missing_hotels' => 1,
        ],
        'rows' => [
            [
                'hotel_id' => 101,
                'hotel_name' => 'Phase2 Fixture Hotel A',
                'target_date' => $targetDate,
                'metric_scope' => 'ota_channel',
                'status' => 'incomplete',
                'employee_questions' => [
                    'count' => 6,
                    'proved_count' => 4,
                    'missing_count' => 2,
                    'missing_question_keys' => ['revenue_traffic_conversion', 'ai_evidence'],
                    'statuses' => [
                        'today_ota_collected' => 'proved',
                        'trusted_fields' => 'proved',
                        'missing_fields' => 'proved',
                        'revenue_traffic_conversion' => 'blocked',
                        'ai_evidence' => 'blocked',
                        'next_operation_action' => 'proved',
                    ],
                ],
                'collection' => [
                    'status' => 'proved',
                    'platforms' => [['platform' => 'ctrip', 'target_date_source_rows' => 4]],
                    'target_date_source_rows' => 4,
                    'source_policy' => 'read_existing_online_daily_data_only',
                ],
                'field_trust' => [
                    'status' => 'proved',
                    'missing_status' => 'proved',
                    'data_quality_status' => 'partial',
                    'missing_field_count' => 1,
                ],
                'metric_diagnosis' => [
                    'status' => 'blocked',
                    'revenue_metric_status' => 'partial',
                    'metric_trust_key_count' => 3,
                    'data_gap_codes' => ['ctrip_traffic_facts_missing'],
                    'traffic_rows' => 0,
                    'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
                ],
                'ai_evidence' => [
                    'status' => 'blocked',
                    'diagnosis_status' => 'blocked',
                    'action_item_status' => 'ready',
                    'blocking_missing_codes' => ['ai_evidence_sources_missing'],
                    'source_policy' => 'missing_real_ota_diagnosis_response',
                    'explanation' => [
                        'summary' => 'AI suggestion is blocked because verified OTA evidence is incomplete.',
                        'diagnosis_status' => 'blocked',
                        'action_item_status' => 'ready',
                        'missing_codes' => ['ai_evidence_sources_missing'],
                        'source_policy' => 'missing_real_ota_diagnosis_response',
                        'next_step' => 'Resolve missing evidence before treating the suggestion as executable.',
                        'boundary' => 'AI suggestions must cite OTA evidence and data gaps; missing evidence cannot be converted into a confirmed business conclusion.',
                    ],
                ],
                'operation_execution' => [
                    'status' => 'proved',
                    'operation_evidence_status' => 'ready',
                    'execution_intent_count' => 0,
                    'execution_flow_item_count' => 0,
                    'completion_signal_count' => 0,
                    'blocking_missing_codes' => [],
                    'source_policy' => 'read_existing_operation_execution_state_only',
                ],
                'next_action' => [
                    'hotel_id' => 101,
                    'hotel_name' => 'Phase2 Fixture Hotel A',
                    'target_date' => $targetDate,
                    'platform' => 'ctrip',
                    'action_code' => 'phase2_fixture_fill_ai_evidence',
                    'question_key' => 'ai_evidence',
                    'priority' => 'high',
                    'status' => 'blocked',
                    'action' => '补齐 OTA 诊断证据后复跑巡检。',
                    'entry' => '/api/online-data/daily-workbench',
                ],
                'next_action_count' => 1,
                'high_priority_action_count' => 1,
                'source_policy' => 'read_existing_phase1_employee_question_rows_only',
                'protected_boundary' => 'Do not change OTA acquisition; this row summarizes existing evidence only.',
            ],
            [
                'hotel_id' => 102,
                'hotel_name' => 'Phase2 Fixture Hotel B',
                'target_date' => $targetDate,
                'metric_scope' => 'ota_channel',
                'status' => 'complete',
                'employee_questions' => [
                    'count' => 6,
                    'proved_count' => 6,
                    'missing_count' => 0,
                    'missing_question_keys' => [],
                    'statuses' => [
                        'today_ota_collected' => 'proved',
                        'trusted_fields' => 'proved',
                        'missing_fields' => 'proved',
                        'revenue_traffic_conversion' => 'proved',
                        'ai_evidence' => 'proved',
                        'next_operation_action' => 'proved',
                    ],
                ],
                'collection' => [
                    'status' => 'proved',
                    'platforms' => [['platform' => 'meituan', 'target_date_source_rows' => 4]],
                    'target_date_source_rows' => 4,
                    'source_policy' => 'read_existing_online_daily_data_only',
                ],
                'field_trust' => [
                    'status' => 'proved',
                    'missing_status' => 'proved',
                    'data_quality_status' => 'complete',
                    'missing_field_count' => 0,
                ],
                'metric_diagnosis' => [
                    'status' => 'proved',
                    'revenue_metric_status' => 'complete',
                    'metric_trust_key_count' => 5,
                    'data_gap_codes' => [],
                    'traffic_rows' => 4,
                    'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
                ],
                'ai_evidence' => [
                    'status' => 'proved',
                    'diagnosis_status' => 'proved',
                    'action_item_status' => 'ready',
                    'blocking_missing_codes' => [],
                    'source_policy' => 'read_existing_ai_diagnosis_evidence_only',
                    'explanation' => [
                        'summary' => 'AI suggestion is backed by OTA evidence, data-gap review, and an action item.',
                        'diagnosis_status' => 'proved',
                        'action_item_status' => 'ready',
                        'missing_codes' => [],
                        'source_policy' => 'read_existing_ai_diagnosis_evidence_only',
                        'next_step' => 'Track execution and review the result.',
                        'boundary' => 'AI suggestions must cite OTA evidence and data gaps; missing evidence cannot be converted into a confirmed business conclusion.',
                    ],
                ],
                'operation_execution' => [
                    'status' => 'proved',
                    'operation_evidence_status' => 'ready',
                    'execution_intent_count' => 1,
                    'execution_flow_item_count' => 1,
                    'completion_signal_count' => 1,
                    'blocking_missing_codes' => [],
                    'source_policy' => 'read_existing_operation_execution_state_only',
                ],
                'next_action' => null,
                'next_action_count' => 0,
                'high_priority_action_count' => 0,
                'source_policy' => 'read_existing_phase1_employee_question_rows_only',
                'protected_boundary' => 'Do not change OTA acquisition; this row summarizes existing evidence only.',
            ],
        ],
        'next_actions' => [
            [
                'hotel_id' => 101,
                'hotel_name' => 'Phase2 Fixture Hotel A',
                'target_date' => $targetDate,
                'platform' => 'ctrip',
                'action_code' => 'phase2_fixture_fill_ai_evidence',
                'question_key' => 'ai_evidence',
                'priority' => 'high',
                'status' => 'blocked',
                'action' => '补齐 OTA 诊断证据后复跑巡检。',
                'entry' => '/api/online-data/daily-workbench',
            ],
        ],
        'data_status' => [
            'metric_scope' => 'ota_channel',
            'source_policy' => 'fixture_read_existing_evidence_only',
            'collection_logic_changed' => false,
            'raw_data_exposed' => false,
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
    phase2_runtime_check($checks, 'isolated_runtime', is_dir($runtimePath), 'Verifier uses an isolated temporary runtime path.', [
        'runtime_path' => $runtimePath,
    ]);

    $service = new DailyWorkbenchPatrolService();
    $snapshot = $service->write(phase2_runtime_payload($targetDate), [
        'trigger_type' => 'cron',
        'target_date' => $targetDate,
        'user_id' => 0,
    ]);

    phase2_runtime_check($checks, 'snapshot_written', (string)($snapshot['snapshot_type'] ?? '') === 'phase2_daily_workbench_patrol', 'Patrol snapshot was written.');
    phase2_runtime_check($checks, 'multi_hotel_rows', count((array)($snapshot['rows'] ?? [])) === 2, 'Snapshot contains multiple hotel rows.');
    $health = $service->health($targetDate);
    phase2_runtime_check($checks, 'auto_trigger_health', (string)($health['status'] ?? '') === 'auto_ready', 'Health reports target-date automatic patrol ready.');
    $automationHealth = is_array($health['automation'] ?? null) ? $health['automation'] : [];
    phase2_runtime_check($checks, 'automation_config_status_explicit', isset($automationHealth['status'], $automationHealth['next_action'], $automationHealth['scheduler_status'])
        && ($automationHealth['secret_exposed'] ?? true) === false, 'Health exposes automatic patrol configuration status without exposing secrets.');
    phase2_runtime_check($checks, 'evidence_boundary', ($snapshot['evidence_policy']['collection_logic_changed'] ?? true) === false
        && ($snapshot['evidence_policy']['raw_data_exposed'] ?? true) === false
        && ($snapshot['evidence_policy']['sensitive_credentials_exposed'] ?? true) === false, 'Snapshot keeps evidence and collection boundaries explicit.');

    $firstRow = is_array($snapshot['rows'][0] ?? null) ? $snapshot['rows'][0] : [];
    $aiExplanation = is_array($firstRow['ai_evidence']['explanation'] ?? null) ? $firstRow['ai_evidence']['explanation'] : [];
    phase2_runtime_check($checks, 'ai_explanation_present', isset($aiExplanation['summary'], $aiExplanation['missing_codes'], $aiExplanation['next_step'], $aiExplanation['boundary']), 'AI explanation exposes summary, missing evidence, next step, and boundary.');

    $runId = (string)($snapshot['run_id'] ?? '');
    $tracked = $service->updateActionStatus([
        'run_id' => $runId,
        'hotel_id' => 101,
        'action_code' => 'phase2_fixture_fill_ai_evidence',
        'question_key' => 'ai_evidence',
        'status' => 'done',
        'note' => 'Fixture action executed for runtime verification.',
        'operation_execution' => [
            'intent_id' => 501,
            'task_id' => 601,
            'intent_status' => 'executed',
            'task_status' => 'executed',
            'source_policy' => 'fixture_operation_execution_only',
        ],
    ], 0);
    $trackingItems = is_array($tracked['action_tracking']['items'] ?? null) ? $tracked['action_tracking']['items'] : [];
    phase2_runtime_check($checks, 'action_tracking_updated', count($trackingItems) === 1
        && (string)(array_values($trackingItems)[0]['status'] ?? '') === 'done', 'Action status can be tracked on the runtime snapshot.');

    $reviewed = $service->updateActionReview([
        'run_id' => $runId,
        'hotel_id' => 101,
        'action_code' => 'phase2_fixture_fill_ai_evidence',
        'question_key' => 'ai_evidence',
        'result_status' => 'success',
        'result_summary' => 'Fixture review proved the operation loop can be closed.',
    ], 0);
    $reviewSummary = is_array($reviewed['action_tracking']['review_summary'] ?? null) ? $reviewed['action_tracking']['review_summary'] : [];
    phase2_runtime_check($checks, 'action_review_recorded', (int)($reviewSummary['success'] ?? 0) === 1
        && (int)($reviewSummary['reviewed_count'] ?? 0) === 1, 'Action review result is recorded and summarized.');

    $report = $service->markdownReport($runId);
    $content = (string)($report['content'] ?? '');
    phase2_runtime_check($checks, 'markdown_report_sections', strpos($content, '## 门店巡检') !== false
        && strpos($content, '## AI 建议解释') !== false
        && strpos($content, '## 动作跟踪与复盘') !== false
        && strpos($content, '## 复盘汇总') !== false
        && strpos($content, 'OTA 渠道，不代表全酒店经营事实') !== false, 'Markdown report contains the employee-facing patrol, AI explanation, action, review, and scope sections.');
    phase2_runtime_check($checks, 'markdown_report_review_value', strpos($content, 'success') !== false
        && strpos($content, 'Fixture review proved the operation loop can be closed.') !== false, 'Markdown report includes the review result.');
    phase2_runtime_check($checks, 'no_secret_literals_from_fixture', stripos($content, 'Cookie') === false
        && stripos($content, 'Token') === false
        && stripos($content, 'password') === false, 'Runtime report does not include credential literals from the fixture.');
} catch (Throwable $e) {
    phase2_runtime_issue($issues, 'verifier_runtime_error', $e->getMessage(), [
        'class' => $e::class,
    ]);
} finally {
    try {
        phase2_runtime_delete_dir($runtimePath);
    } catch (Throwable $cleanupError) {
        phase2_runtime_issue($issues, 'runtime_cleanup_failed', $cleanupError->getMessage());
    }
}

$failedChecks = array_values(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') !== 'passed'));
$ok = $issues === [] && $failedChecks === [];
$payload = [
    'status' => $ok ? 'passed' : 'failed',
    'metric_scope' => 'ota_channel',
    'collection_logic_changed' => false,
    'runtime_isolated' => true,
    'checks' => $checks,
    'issues' => $issues,
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($ok ? 0 : 1);
