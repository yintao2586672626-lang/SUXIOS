<?php
declare(strict_types=1);

use app\model\PriceSuggestion;
use app\model\RoomType;
use app\service\OperationManagementService;
use app\service\RevenueAiOverviewService;
use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null}
 */
function ctrip_review_decision_verify_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($key, $options)) {
            $options[$key] = trim($value);
        }
    }

    foreach (['business-date', 'business_date'] as $dateKey) {
        if ($options[$dateKey] !== '') {
            $options['date'] = $options[$dateKey];
        }
    }
    if ($options['hotel-id'] === '' && $options['hotel_id'] !== '') {
        $options['hotel-id'] = $options['hotel_id'];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }

    $hotelId = null;
    if ($options['hotel-id'] !== '') {
        if (!ctype_digit($options['hotel-id']) || (int)$options['hotel-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --hotel-id, expected a positive integer.');
        }
        $hotelId = (int)$options['hotel-id'];
    }

    return [
        'date' => $options['date'],
        'hotel_id' => $hotelId,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_review_decision_verify_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $details
 */
function ctrip_review_decision_verify_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_review_decision_verify_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_review_decision_verify_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<int, mixed> $values
 * @return array<int, int>
 */
function ctrip_review_decision_verify_positive_ints(array $values): array
{
    return array_values(array_filter(
        array_map('intval', $values),
        static fn(int $value): bool => $value > 0
    ));
}

function ctrip_review_decision_verify_resolve_hotel_id(?int $requestedHotelId, string $businessDate): int
{
    if ($requestedHotelId !== null && $requestedHotelId > 0) {
        return $requestedHotelId;
    }

    $overview = (new RevenueAiOverviewService())->overview([
        'business_date' => $businessDate,
        'platform' => 'ctrip',
        'enabled_channels' => ['ctrip'],
    ]);
    $preflight = ctrip_review_decision_verify_map($overview['pricing_generation_preflight'] ?? []);
    $targetHotelIds = ctrip_review_decision_verify_positive_ints(ctrip_review_decision_verify_list($preflight['target_hotel_ids'] ?? []));
    if ($targetHotelIds !== []) {
        return $targetHotelIds[0];
    }

    $channelStatus = ctrip_review_decision_verify_map(ctrip_review_decision_verify_map($overview['channel_statuses'] ?? [])['ctrip'] ?? []);
    $channelHotelIds = ctrip_review_decision_verify_positive_ints(ctrip_review_decision_verify_list($channelStatus['system_hotel_ids'] ?? []));
    if ($channelHotelIds !== []) {
        return $channelHotelIds[0];
    }

    throw new RuntimeException('No Ctrip target hotel was found for the requested date.');
}

/**
 * @return array<string, int|string>
 */
function ctrip_review_decision_verify_insert_fixture(int $hotelId, string $businessDate): array
{
    $marker = 'codex_ctrip_review_decision_' . date('YmdHis');
    $roomType = RoomType::create([
        'hotel_id' => $hotelId,
        'name' => 'Ctrip Review Decision ' . substr($marker, -6),
        'base_price' => 300,
        'min_price' => 240,
        'max_price' => 430,
        'room_count' => 10,
        'sort_order' => 9997,
        'is_enabled' => RoomType::STATUS_ENABLED,
        'facilities' => [
            'source_scope' => 'ctrip_ota_channel',
            'evidence_status' => 'verifier_transaction_only',
            'auto_write_ota' => false,
            'verifier_marker' => $marker,
        ],
    ]);

    $suggestion = PriceSuggestion::create([
        'hotel_id' => $hotelId,
        'room_type_id' => (int)$roomType->id,
        'demand_forecast_id' => 0,
        'suggestion_type' => PriceSuggestion::TYPE_COMPETITOR,
        'status' => PriceSuggestion::STATUS_PENDING,
        'suggestion_date' => $businessDate,
        'current_price' => 300,
        'suggested_price' => 330,
        'min_price' => 240,
        'max_price' => 430,
        'confidence_score' => 0.82,
        'competitor_data' => [
            'source_scope' => 'ctrip_ota_channel',
            'source_channels' => ['ctrip'],
            'input_scope' => 'manual_pricing_configuration',
            'competitor_name' => 'Ctrip Review Decision Competitor',
            'our_price' => 300,
            'competitor_price' => 360,
            'auto_write_ota' => false,
            'verifier_marker' => $marker,
        ],
        'factors' => [
            'source_scope' => 'ctrip_ota_channel',
            'source_channels' => ['ctrip'],
            'decision_boundary' => 'manual_review_required_no_auto_rate_write',
            'risk_level' => 'medium',
            'primary_signal_count' => 3,
            'confidence_score' => 0.82,
            'signals' => [
                'demand_forecast' => ['data_status' => 'ok'],
                'competitor' => ['data_status' => 'ok'],
                'data_gaps' => [],
            ],
            'auto_write_ota' => false,
            'verifier_marker' => $marker,
        ],
        'reason' => 'transaction-only Ctrip review decision verifier',
    ]);

    return [
        'marker' => $marker,
        'room_type_id' => (int)$roomType->id,
        'price_suggestion_id' => (int)$suggestion->id,
    ];
}

/**
 * @return array<string, mixed>
 */
function ctrip_review_decision_verify_payload(string $businessDate, int $hotelId, int $suggestionId): array
{
    return [
        'business_date' => $businessDate,
        'hotel_id' => $hotelId,
        'platform' => 'ctrip',
        'source_scope' => 'ctrip_ota_channel',
        'evidence_status' => 'verifier_transaction_only',
        'auto_write_ota' => false,
        'review_decision' => [
            'suggestion_id' => $suggestionId,
            'action' => 'approve_with_changes',
            'approved_price' => 335,
            'remark' => 'transaction-only verifier approves changed Ctrip price within guard',
            'operator_review_evidence' => [
                'reviewed_by' => 'transaction-only verifier',
                'reviewed_at' => $businessDate . ' 00:00:00',
                'decision_basis' => 'transaction-only verifier manual review decision',
                'price_guard_source' => 'transaction-only verifier price guard review',
                'operation_intent_source' => 'transaction-only verifier operation intent decision',
            ],
            'create_execution_intent_after_approval' => true,
            'execution_intent' => [
                'platform' => 'ctrip',
                'room_type_key' => 'ctrip_review_decision_room',
                'rate_plan_key' => 'BAR',
                'expected_metric' => 'orders',
                'expected_delta' => 1,
                'risk_level' => 'medium',
            ],
        ],
    ];
}

/**
 * @param array<string, int|string> $fixture
 * @return array<string, int>
 */
function ctrip_review_decision_verify_inserted_id_counts(array $fixture, int $intentId): array
{
    $service = new OperationManagementService();
    $suggestionId = (int)($fixture['price_suggestion_id'] ?? 0);
    $roomTypeId = (int)($fixture['room_type_id'] ?? 0);

    return [
        'price_suggestions' => $suggestionId > 0 ? (int)PriceSuggestion::where('id', $suggestionId)->count() : -1,
        'room_types' => $roomTypeId > 0 ? (int)RoomType::where('id', $roomTypeId)->count() : -1,
        'operation_execution_intents' => $intentId > 0 && $service->tableExists('operation_execution_intents')
            ? (int)Db::name('operation_execution_intents')->where('id', $intentId)->count()
            : 0,
    ];
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    ctrip_review_decision_verify_finish([
        'status' => 'failed',
        'error' => 'vendor/autoload.php is missing.',
    ], 1);
}

require $autoload;
require_once $root . '/scripts/execute_revenue_ai_ctrip_review_decision.php';

try {
    $options = ctrip_review_decision_verify_parse_args($argv);
    $app = new App($root);
    $app->initialize();

    $hotelId = ctrip_review_decision_verify_resolve_hotel_id($options['hotel_id'], $options['date']);
    $checks = [];
    $fixture = [];
    $templatePayload = [];
    $templateExitCode = 1;
    $templateFilePayload = [];
    $duplicateTemplateError = '';
    $forceTemplatePayload = [];
    $runnerPayload = [];
    $runnerExitCode = 1;
    $intentId = 0;
    $rolledBack = false;
    $rollbackCounts = [];
    $decisionFile = tempnam(sys_get_temp_dir(), 'ctrip_review_decision_');
    if ($decisionFile === false) {
        throw new RuntimeException('Unable to create temporary review-decision file.');
    }
    $templateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ctrip_review_decision_template_' . bin2hex(random_bytes(6)) . '.json';

    Db::startTrans();
    try {
        $fixture = ctrip_review_decision_verify_insert_fixture($hotelId, $options['date']);
        $templateRun = ctrip_review_decision_run([
            'file' => '',
            'date' => $options['date'],
            'hotel_id' => $hotelId,
            'execute' => false,
            'create_intent' => false,
            'user_id' => 0,
            'print_template' => true,
            'output' => $templateFile,
            'force' => false,
            'suggestion_id' => (int)$fixture['price_suggestion_id'],
            'manage_transaction' => false,
        ]);
        $templatePayload = $templateRun['payload'];
        $templateExitCode = (int)$templateRun['exit_code'];
        if (is_file($templateFile)) {
            $templateFileDecoded = json_decode((string)file_get_contents($templateFile), true);
            $templateFilePayload = is_array($templateFileDecoded) ? $templateFileDecoded : [];
        }
        try {
            ctrip_review_decision_run([
                'file' => '',
                'date' => $options['date'],
                'hotel_id' => $hotelId,
                'execute' => false,
                'create_intent' => false,
                'user_id' => 0,
                'print_template' => true,
                'output' => $templateFile,
                'force' => false,
                'suggestion_id' => (int)$fixture['price_suggestion_id'],
                'manage_transaction' => false,
            ]);
        } catch (Throwable $duplicateError) {
            $duplicateTemplateError = $duplicateError->getMessage();
        }
        $forceTemplateRun = ctrip_review_decision_run([
            'file' => '',
            'date' => $options['date'],
            'hotel_id' => $hotelId,
            'execute' => false,
            'create_intent' => false,
            'user_id' => 0,
            'print_template' => true,
            'output' => $templateFile,
            'force' => true,
            'suggestion_id' => (int)$fixture['price_suggestion_id'],
            'manage_transaction' => false,
        ]);
        $forceTemplatePayload = $forceTemplateRun['payload'];
        $decisionPayload = ctrip_review_decision_verify_payload(
            $options['date'],
            $hotelId,
            (int)$fixture['price_suggestion_id']
        );
        file_put_contents(
            $decisionFile,
            json_encode($decisionPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $runner = ctrip_review_decision_run([
            'file' => $decisionFile,
            'date' => $options['date'],
            'hotel_id' => $hotelId,
            'execute' => true,
            'create_intent' => true,
            'user_id' => 0,
            'print_template' => false,
            'manage_transaction' => false,
        ]);
        $runnerPayload = $runner['payload'];
        $runnerExitCode = (int)$runner['exit_code'];
        $intent = ctrip_review_decision_verify_map(ctrip_review_decision_verify_map($runnerPayload['summary'] ?? [])['execution_intent'] ?? []);
        $intentId = (int)($intent['id'] ?? 0);
    } finally {
        Db::rollback();
        $rolledBack = true;
        if (is_file($decisionFile)) {
            @unlink($decisionFile);
        }
        if (is_file($templateFile)) {
            @unlink($templateFile);
        }
    }

    $rollbackCounts = ctrip_review_decision_verify_inserted_id_counts($fixture, $intentId);
    $scope = ctrip_review_decision_verify_map($runnerPayload['scope'] ?? []);
    $summary = ctrip_review_decision_verify_map($runnerPayload['summary'] ?? []);
    $summaryReviewEvidence = ctrip_review_decision_verify_map($summary['operator_review_evidence'] ?? []);
    $intentSummary = ctrip_review_decision_verify_map($summary['execution_intent'] ?? []);
    $operationHandoff = ctrip_review_decision_verify_map($summary['operation_evidence_handoff'] ?? []);
    $operationHandoffWindow = ctrip_review_decision_verify_map($operationHandoff['roi_window'] ?? []);
    $operationHandoffEndpoints = ctrip_review_decision_verify_map($operationHandoff['endpoints'] ?? []);
    $operationHandoffExecutionRequired = ctrip_review_decision_verify_list($operationHandoff['manual_execution_evidence_required'] ?? []);
    $operationHandoffRoiRequired = ctrip_review_decision_verify_list($operationHandoff['manual_roi_evidence_required'] ?? []);
    $operationHandoffExecutionPayload = ctrip_review_decision_verify_map($operationHandoff['execution_payload_template'] ?? []);
    $operationHandoffExecutionEvidence = ctrip_review_decision_verify_map($operationHandoffExecutionPayload['evidence'] ?? []);
    $operationHandoffOperatorExecution = ctrip_review_decision_verify_map($operationHandoffExecutionEvidence['operator_execution_evidence'] ?? []);
    $operationHandoffRoiPayload = ctrip_review_decision_verify_map($operationHandoff['roi_evidence_payload_template'] ?? []);
    $operationHandoffRoiEvidence = ctrip_review_decision_verify_map($operationHandoffRoiPayload['evidence'] ?? []);
    $operationHandoffOperatorRoi = ctrip_review_decision_verify_map($operationHandoffRoiEvidence['operator_roi_evidence'] ?? []);
    $operationHandoffPlatformResponse = ctrip_review_decision_verify_map($operationHandoffRoiEvidence['platform_response'] ?? []);
    $template = ctrip_review_decision_verify_map($templatePayload['template'] ?? []);
    $templateDecision = ctrip_review_decision_verify_map($template['review_decision'] ?? []);
    $templatePending = ctrip_review_decision_verify_map($template['pending_suggestion'] ?? []);
    $templateOperationPreview = ctrip_review_decision_verify_map($template['post_approval_operation_evidence_handoff_preview'] ?? []);
    $templateOperationPreviewWindow = ctrip_review_decision_verify_map($templateOperationPreview['roi_window'] ?? []);
    $templateOperationPreviewExecutionRequired = ctrip_review_decision_verify_list($templateOperationPreview['manual_execution_evidence_required'] ?? []);
    $templateOperationPreviewRoiRequired = ctrip_review_decision_verify_list($templateOperationPreview['manual_roi_evidence_required'] ?? []);
    $templateOperationPreviewExecutionPayload = ctrip_review_decision_verify_map($templateOperationPreview['execution_payload_template'] ?? []);
    $templateOperationPreviewExecutionEvidence = ctrip_review_decision_verify_map($templateOperationPreviewExecutionPayload['evidence'] ?? []);
    $templateOperationPreviewExecutionResponse = ctrip_review_decision_verify_map($templateOperationPreviewExecutionEvidence['platform_response'] ?? []);
    $templateOperationPreviewRoiPayload = ctrip_review_decision_verify_map($templateOperationPreview['roi_evidence_payload_template'] ?? []);
    $templateOperationPreviewRoiEvidence = ctrip_review_decision_verify_map($templateOperationPreviewRoiPayload['evidence'] ?? []);
    $templateOperationPreviewRoiResponse = ctrip_review_decision_verify_map($templateOperationPreviewRoiEvidence['platform_response'] ?? []);
    $templateOperatorRules = ctrip_review_decision_verify_list($template['operator_rules'] ?? []);
    $templateOutput = ctrip_review_decision_verify_map($templatePayload['output_file'] ?? []);
    $forceTemplateOutput = ctrip_review_decision_verify_map($forceTemplatePayload['output_file'] ?? []);
    $checksFromRunner = ctrip_review_decision_verify_list($runnerPayload['checks'] ?? []);
    $contamination = json_encode([
        'scope' => [
            'platform' => $scope['platform'] ?? null,
            'source_scope' => $scope['source_scope'] ?? null,
            'enabled_channels' => $scope['enabled_channels'] ?? [],
        ],
        'summary' => [
            'action' => $summary['action'] ?? null,
            'manual_review_storage' => $summary['manual_review_storage'] ?? null,
            'execution_platform' => $intentSummary['platform'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

    ctrip_review_decision_verify_check(
        $checks,
        'transaction_fixture_inserted',
        (int)($fixture['room_type_id'] ?? 0) > 0 && (int)($fixture['price_suggestion_id'] ?? 0) > 0,
        'Verifier inserted a transaction-only Ctrip pending AI suggestion.',
        $fixture
    );
    ctrip_review_decision_verify_check(
        $checks,
        'review_decision_runner_passed',
        $runnerExitCode === 0 && (string)($runnerPayload['status'] ?? '') === 'passed',
        'Review-decision runner accepts the transaction-only Ctrip review file.',
        ['exit_code' => $runnerExitCode, 'status' => $runnerPayload['status'] ?? null]
    );
    ctrip_review_decision_verify_check(
        $checks,
        'review_template_output_written',
        $templateExitCode === 0
            && (string)($templatePayload['status'] ?? '') === 'template'
            && (string)($templatePayload['source_policy'] ?? '') === 'review_decision_template_from_pending_suggestion'
            && (int)($templateOutput['bytes'] ?? 0) > 0
            && $templateFilePayload !== [],
        'Review-decision template can be exported to a JSON file from a pending Ctrip suggestion.',
        [
            'exit_code' => $templateExitCode,
            'status' => $templatePayload['status'] ?? null,
            'source_policy' => $templatePayload['source_policy'] ?? null,
            'output_file' => $templateOutput,
        ]
    );
    ctrip_review_decision_verify_check(
        $checks,
        'review_template_prefills_pending_suggestion',
        (int)($templateDecision['suggestion_id'] ?? 0) === (int)($fixture['price_suggestion_id'] ?? 0)
            && (int)($templatePending['suggestion_id'] ?? 0) === (int)($fixture['price_suggestion_id'] ?? 0)
            && ctrip_review_decision_verify_list($templatePending['source_channels'] ?? []) === ['ctrip']
            && (string)($templatePending['source_scope'] ?? '') === 'ctrip_ota_channel'
            && is_array($templateDecision['operator_review_evidence'] ?? null)
            && ($templatePending['auto_write_ota'] ?? true) === false,
        'Review-decision template pre-fills the pending suggestion id and carries Ctrip-only context.',
        [
            'review_decision' => $templateDecision,
            'pending_suggestion' => $templatePending,
        ]
    );
    ctrip_review_decision_verify_check(
        $checks,
        'review_template_exposes_post_approval_operation_evidence_preview',
        (string)($templateOperationPreview['status'] ?? '') === 'waiting_execution_intent'
            && (string)($templateOperationPreview['source_scope'] ?? '') === 'ctrip_ota_channel_execution_evidence'
            && ($templateOperationPreview['auto_write_ota'] ?? true) === false
            && (int)($templateOperationPreview['hotel_id'] ?? 0) === (int)($scope['hotel_id'] ?? 0)
            && (string)($templateOperationPreview['preview_boundary'] ?? '') === 'operation_evidence_handoff_preview_not_execution_proof'
            && (string)($templateOperationPreview['template_usage'] ?? '') === 'fill_after_manual_approval_only_not_execution_or_roi_proof'
            && (string)($templateOperationPreviewWindow['business_date'] ?? '') === $options['date']
            && (string)($templateOperationPreviewWindow['scope'] ?? '') === 'ctrip_ota_channel_only'
            && (string)($templateOperationPreviewWindow['protected_boundary'] ?? '') === 'do_not_promote_ctrip_ota_scope_to_whole_hotel_truth'
            && in_array('execution_receipt_or_screenshot_path', $templateOperationPreviewExecutionRequired, true)
            && in_array('roi_calculation_basis', $templateOperationPreviewRoiRequired, true)
            && (string)($templateOperationPreviewExecutionResponse['evidence_boundary'] ?? '') === 'local_manual_evidence_no_ota_write'
            && ($templateOperationPreviewExecutionResponse['auto_write_ota'] ?? true) === false
            && (string)($templateOperationPreviewRoiResponse['evidence_boundary'] ?? '') === 'local_manual_roi_evidence_no_ota_write'
            && ($templateOperationPreviewRoiResponse['auto_write_ota'] ?? true) === false
            && in_array('Use post_approval_operation_evidence_handoff_preview only after manual approval; it is not proof of execution or ROI.', $templateOperatorRules, true),
        'Review template exposes post-approval execution and ROI evidence payloads as a preview, not proof.',
        [
            'status' => $templateOperationPreview['status'] ?? null,
            'hotel_id' => $templateOperationPreview['hotel_id'] ?? null,
            'preview_boundary' => $templateOperationPreview['preview_boundary'] ?? null,
            'template_usage' => $templateOperationPreview['template_usage'] ?? null,
            'roi_window' => $templateOperationPreviewWindow,
            'manual_execution_evidence_required' => $templateOperationPreviewExecutionRequired,
            'manual_roi_evidence_required' => $templateOperationPreviewRoiRequired,
            'execution_platform_response' => $templateOperationPreviewExecutionResponse,
            'roi_platform_response' => $templateOperationPreviewRoiResponse,
            'operator_rules' => $templateOperatorRules,
        ]
    );
    ctrip_review_decision_verify_check(
        $checks,
        'review_template_output_overwrite_guarded',
        str_contains($duplicateTemplateError, 'Output file already exists')
            && ($forceTemplateOutput['overwritten'] ?? false) === true,
        'Review-decision template output refuses accidental overwrite unless --force=1 is used.',
        [
            'duplicate_error' => $duplicateTemplateError,
            'force_output_file' => $forceTemplateOutput,
        ]
    );
    ctrip_review_decision_verify_check(
        $checks,
        'review_decision_scope_ctrip_only',
        (string)($scope['source_scope'] ?? '') === 'ctrip_ota_channel'
            && ctrip_review_decision_verify_list($scope['enabled_channels'] ?? []) === ['ctrip']
            && ($scope['auto_write_ota'] ?? true) === false,
        'Review decision stays Ctrip-scoped and never writes OTA.',
        $scope
    );
    ctrip_review_decision_verify_check(
        $checks,
        'approve_with_changes_records_manual_review',
        (string)($summary['action'] ?? '') === 'approve_with_changes'
            && (float)($summary['approved_price'] ?? 0) === 335.0
            && (string)($summaryReviewEvidence['reviewed_by'] ?? '') !== ''
            && (string)($summary['manual_review_storage'] ?? '') === 'price_suggestions.factors.manual_review_versions',
        'approve_with_changes records the operator-approved price under manual review storage.',
        $summary
    );
    ctrip_review_decision_verify_check(
        $checks,
        'post_approval_execution_intent_created',
        $intentId > 0
            && (string)($intentSummary['source_module'] ?? '') === 'price_suggestion'
            && (int)($intentSummary['source_record_id'] ?? 0) === (int)($fixture['price_suggestion_id'] ?? 0)
            && strtolower((string)($intentSummary['platform'] ?? '')) === 'ctrip'
            && (string)($intentSummary['target_page'] ?? '') === 'ops-track',
        'Approved Ctrip AI decision can create an operation execution intent, but not an OTA price write.',
        $intentSummary
    );
    ctrip_review_decision_verify_check(
        $checks,
        'operation_evidence_handoff_includes_roi_window',
        (string)($operationHandoff['status'] ?? '') === 'waiting_operation_intent_approval'
            && (string)($operationHandoff['source_scope'] ?? '') === 'ctrip_ota_channel_execution_evidence'
            && ($operationHandoff['auto_write_ota'] ?? true) === false
            && (string)($operationHandoffWindow['business_date'] ?? '') === $options['date']
            && (string)($operationHandoffWindow['previous_day'] ?? '') === date('Y-m-d', strtotime($options['date'] . ' -1 day'))
            && (string)($operationHandoffWindow['next_day'] ?? '') === date('Y-m-d', strtotime($options['date'] . ' +1 day'))
            && (string)($operationHandoffWindow['scope'] ?? '') === 'ctrip_ota_channel_only'
            && (string)($operationHandoffWindow['protected_boundary'] ?? '') === 'do_not_promote_ctrip_ota_scope_to_whole_hotel_truth'
            && str_contains((string)($operationHandoffEndpoints['approve_intent'] ?? ''), '/api/operation/execution-intents/')
            && str_contains((string)($operationHandoffEndpoints['record_execution'] ?? ''), '/api/operation/execution-tasks/')
            && str_contains((string)($operationHandoffEndpoints['upload_roi_evidence'] ?? ''), '/api/operation/execution-tasks/')
            && str_contains((string)($operationHandoffEndpoints['review_roi'] ?? ''), '/api/operation/execution-tasks/')
            && in_array('execution_receipt_or_screenshot_path', $operationHandoffExecutionRequired, true)
            && in_array('roi_calculation_basis', $operationHandoffRoiRequired, true)
            && (string)($operationHandoffOperatorExecution['executed_by'] ?? '') === '<operator_name_or_role>'
            && (string)($operationHandoffOperatorRoi['before_metric_source'] ?? '') === '<source_for_previous_day_ctrip_metrics>'
            && (string)($operationHandoffPlatformResponse['evidence_boundary'] ?? '') === 'local_manual_roi_evidence_no_ota_write'
            && ($operationHandoffPlatformResponse['auto_write_ota'] ?? true) === false,
        'Post-review handoff exposes previous-day/next-day Ctrip OTA ROI evidence capture without OTA write or whole-hotel promotion.',
        [
            'status' => $operationHandoff['status'] ?? null,
            'roi_window' => $operationHandoffWindow,
            'manual_execution_evidence_required' => $operationHandoffExecutionRequired,
            'manual_roi_evidence_required' => $operationHandoffRoiRequired,
            'endpoints' => $operationHandoffEndpoints,
            'platform_response' => $operationHandoffPlatformResponse,
        ]
    );
    ctrip_review_decision_verify_check(
        $checks,
        'runner_checks_include_boundaries',
        in_array('review_keeps_no_ota_write', array_map(
            static fn(mixed $row): string => (string)(is_array($row) ? ($row['code'] ?? '') : ''),
            $checksFromRunner
        ), true)
            && in_array('operator_review_evidence_present', array_map(
                static fn(mixed $row): string => (string)(is_array($row) ? ($row['code'] ?? '') : ''),
                $checksFromRunner
            ), true)
            && in_array('manual_review_evidence_attached', array_map(
                static fn(mixed $row): string => (string)(is_array($row) ? ($row['code'] ?? '') : ''),
                $checksFromRunner
            ), true)
            && in_array('execution_intent_policy_respected', array_map(
                static fn(mixed $row): string => (string)(is_array($row) ? ($row['code'] ?? '') : ''),
                $checksFromRunner
            ), true),
        'Runner checks include no-OTA-write and execution-intent boundary assertions.',
        ['runner_check_count' => count($checksFromRunner)]
    );
    ctrip_review_decision_verify_check(
        $checks,
        'meituan_not_present',
        !str_contains(strtolower($contamination), 'meituan') && !str_contains($contamination, '美团'),
        'Transaction review-decision payload contains no Meituan token or label.',
        []
    );
    ctrip_review_decision_verify_check(
        $checks,
        'transaction_rolled_back',
        $rolledBack && $rollbackCounts !== [] && array_sum($rollbackCounts) === 0,
        'Verifier transaction was rolled back and did not leave fixture rows behind.',
        ['rolled_back' => $rolledBack, 'marker_counts_after_rollback' => $rollbackCounts]
    );

    $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    ctrip_review_decision_verify_finish([
        'status' => $failures === [] ? 'passed' : 'failed',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_policy' => 'transactional_review_decision_fixture_rollback_no_ota_write',
        ],
        'summary' => [
            'fixture' => $fixture,
            'runner' => [
                'status' => $runnerPayload['status'] ?? null,
                'mode' => $runnerPayload['mode'] ?? null,
                'source_scope' => $scope['source_scope'] ?? null,
                'database_written_inside_transaction' => $scope['database_written'] ?? null,
                'auto_write_ota' => $scope['auto_write_ota'] ?? null,
                'action' => $summary['action'] ?? null,
                'approved_price' => $summary['approved_price'] ?? null,
                'execution_intent_id' => $intentId,
                'execution_intent_platform' => $intentSummary['platform'] ?? null,
            ],
            'template_export' => [
                'status' => $templatePayload['status'] ?? null,
                'source_policy' => $templatePayload['source_policy'] ?? null,
                'suggestion_id' => $templatePayload['suggestion_id'] ?? null,
                'output_file_written' => $templateOutput !== [],
                'force_overwrite' => $forceTemplateOutput['overwritten'] ?? null,
            ],
            'rollback_counts' => $rollbackCounts,
        ],
        'checks' => $checks,
    ], $failures === [] ? 0 : 1);
} catch (Throwable $error) {
    try {
        Db::rollback();
    } catch (Throwable) {
    }
    ctrip_review_decision_verify_finish([
        'status' => 'failed',
        'error' => [
            'type' => get_class($error),
            'message' => $error->getMessage(),
        ],
    ], 1);
}
