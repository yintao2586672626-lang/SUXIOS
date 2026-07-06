<?php
declare(strict_types=1);

use app\controller\Agent;
use app\controller\RevenueAi;
use app\model\CompetitorAnalysis;
use app\model\DemandForecast;
use app\model\PriceSuggestion;
use app\model\RoomType;
use app\service\InvestmentDecisionSupportService;
use app\service\OperationManagementService;
use app\service\RevenueAiOverviewService;
use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,complete_operation_roi:bool}
 */
function ctrip_generation_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'complete-operation-roi' => false,
        'complete_operation_roi' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, ['--complete-operation-roi', '--complete_operation_roi'], true)) {
            $options['complete-operation-roi'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($key, $options)) {
            $options[$key] = is_bool($options[$key])
                ? in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)
                : trim($value);
        }
    }

    foreach (['business-date', 'business_date'] as $dateKey) {
        if ((string)$options[$dateKey] !== '') {
            $options['date'] = (string)$options[$dateKey];
        }
    }
    if ((string)$options['hotel-id'] === '' && (string)$options['hotel_id'] !== '') {
        $options['hotel-id'] = (string)$options['hotel_id'];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }

    $hotelId = null;
    if ((string)$options['hotel-id'] !== '') {
        if (!ctype_digit((string)$options['hotel-id']) || (int)$options['hotel-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --hotel-id, expected a positive integer.');
        }
        $hotelId = (int)$options['hotel-id'];
    }

    return [
        'date' => (string)$options['date'],
        'hotel_id' => $hotelId,
        'complete_operation_roi' => (bool)$options['complete-operation-roi'] || (bool)$options['complete_operation_roi'],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_generation_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $details
 */
function ctrip_generation_check(array &$checks, string $code, bool $ok, string $message, array $details = []): void
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
function ctrip_generation_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_generation_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @return list<string>
 */
function ctrip_generation_list_scalars(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $items = [];
    foreach ($value as $item) {
        if (is_scalar($item)) {
            $items[] = (string)$item;
        }
    }

    return array_values($items);
}

/**
 * @return array<string, mixed>
 */
function ctrip_generation_decode_response(\think\Response $response): array
{
    $decoded = json_decode((string)$response->getContent(), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Agent generation response is not JSON.');
    }

    return $decoded;
}

/**
 * @param array<int, mixed> $values
 * @return array<int, int>
 */
function ctrip_generation_positive_ints(array $values): array
{
    return array_values(array_filter(
        array_map('intval', $values),
        static fn(int $value): bool => $value > 0
    ));
}

/**
 * @return int
 */
function ctrip_generation_resolve_hotel_id(?int $requestedHotelId, string $businessDate): int
{
    if ($requestedHotelId !== null && $requestedHotelId > 0) {
        return $requestedHotelId;
    }

    $overview = (new RevenueAiOverviewService())->overview([
        'business_date' => $businessDate,
        'platform' => 'ctrip',
        'enabled_channels' => ['ctrip'],
    ]);
    $preflight = ctrip_generation_map($overview['pricing_generation_preflight'] ?? []);
    $targetHotelIds = ctrip_generation_positive_ints(ctrip_generation_list($preflight['target_hotel_ids'] ?? []));
    if ($targetHotelIds !== []) {
        return $targetHotelIds[0];
    }

    $channelStatus = ctrip_generation_map(ctrip_generation_map($overview['channel_statuses'] ?? [])['ctrip'] ?? []);
    $channelHotelIds = ctrip_generation_positive_ints(ctrip_generation_list($channelStatus['system_hotel_ids'] ?? []));
    if ($channelHotelIds !== []) {
        return $channelHotelIds[0];
    }

    throw new RuntimeException('No Ctrip target hotel was found for the requested date.');
}

/**
 * @return object
 */
function ctrip_generation_fake_super_admin(int $hotelId): object
{
    return new class($hotelId) {
        public int $id = 0;
        private int $hotelId;

        public function __construct(int $hotelId)
        {
            $this->hotelId = $hotelId;
        }

        public function isSuperAdmin(): bool
        {
            return true;
        }

        /**
         * @return array<int, int>
         */
        public function getPermittedHotelIds(): array
        {
            return [$this->hotelId];
        }
    };
}

/**
 * @return array<string, mixed>
 */
function ctrip_generation_insert_fixture_inputs(int $hotelId, string $businessDate): array
{
    $marker = 'codex_ctrip_generation_smoke_' . date('YmdHis');
    $roomType = RoomType::create([
        'hotel_id' => $hotelId,
        'name' => 'Ctrip Smoke Room ' . substr($marker, -6),
        'base_price' => 300,
        'min_price' => 240,
        'max_price' => 430,
        'room_count' => 10,
        'sort_order' => 9999,
        'is_enabled' => RoomType::STATUS_ENABLED,
        'facilities' => ['verifier_marker' => $marker],
    ]);

    $forecast = DemandForecast::createForecast($hotelId, $businessDate, [
        'room_type_id' => (int)$roomType->id,
        'forecast_method' => DemandForecast::METHOD_HYBRID,
        'predicted_occupancy' => 92,
        'predicted_demand' => 9,
        'confidence_score' => 0.86,
        'is_event_driven' => 0,
        'event_factors' => [],
        'historical_data' => [
            'input_scope' => 'manual_pricing_configuration',
            'source_scope' => 'ctrip_ota_channel',
            'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
            'evidence_status' => 'verifier_transaction_only',
            'auto_write_ota' => false,
            'verifier_marker' => $marker,
        ],
        'remark' => 'transaction-only Ctrip generation verifier fixture',
    ]);

    $competitor = CompetitorAnalysis::recordAnalysis($hotelId, 0, [
        'analysis_date' => $businessDate,
        'room_type_id' => (int)$roomType->id,
        'competitor_room_type_id' => 0,
        'our_price' => 300,
        'competitor_price' => 360,
        'price_index' => 83.33,
        'ota_platform' => CompetitorAnalysis::PLATFORM_CTRIP,
        'competitor_data' => [
            'input_scope' => 'manual_pricing_configuration',
            'source_scope' => 'ctrip_ota_channel',
            'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
            'evidence_status' => 'verifier_transaction_only',
            'auto_write_ota' => false,
            'input_type' => 'manual_ctrip_competitor_price_sample',
            'competitor_name' => 'Ctrip Smoke Competitor',
            'verifier_marker' => $marker,
        ],
    ]);

    return [
        'marker' => $marker,
        'room_type_id' => (int)$roomType->id,
        'demand_forecast_id' => (int)$forecast->id,
        'competitor_analysis_id' => (int)$competitor->id,
    ];
}

/**
 * @return array<string, int>
 */
function ctrip_generation_inserted_id_counts(array $fixture, array $createdSuggestionIds): array
{
    $executionIntentIds = ctrip_generation_positive_ints((array)($fixture['execution_intent_ids'] ?? []));
    $executionTaskIds = ctrip_generation_positive_ints((array)($fixture['execution_task_ids'] ?? []));
    $executionEvidenceIds = ctrip_generation_positive_ints((array)($fixture['execution_evidence_ids'] ?? []));

    return [
        'room_types' => (int)RoomType::where('id', (int)($fixture['room_type_id'] ?? 0))->count(),
        'demand_forecasts' => (int)DemandForecast::where('id', (int)($fixture['demand_forecast_id'] ?? 0))->count(),
        'competitor_analysis' => (int)CompetitorAnalysis::where('id', (int)($fixture['competitor_analysis_id'] ?? 0))->count(),
        'price_suggestions' => $createdSuggestionIds === []
            ? 0
            : (int)PriceSuggestion::whereIn('id', $createdSuggestionIds)->count(),
        'operation_execution_intents' => $executionIntentIds === []
            ? 0
            : (int)Db::name('operation_execution_intents')->whereIn('id', $executionIntentIds)->count(),
        'operation_execution_tasks' => $executionTaskIds === []
            ? 0
            : (int)Db::name('operation_execution_tasks')->whereIn('id', $executionTaskIds)->count(),
        'operation_execution_evidence' => $executionEvidenceIds === []
            ? 0
            : (int)Db::name('operation_execution_evidence')->whereIn('id', $executionEvidenceIds)->count(),
    ];
}

/**
 * @param array<string, mixed> $operationFlowSummary
 * @return array<string, mixed>
 */
function ctrip_generation_investment_closure_overview(array $operationFlowSummary): array
{
    $operationTotal = (int)($operationFlowSummary['total'] ?? 0);
    $roiReady = (int)($operationFlowSummary['roi_ready'] ?? 0);

    return [
        'summary' => [
            'operation_execution_total' => $operationTotal,
            'operation_roi_ready' => $roiReady,
        ],
        'modules' => [
            [
                'key' => 'ai_daily_report',
                'label' => 'Ctrip transaction smoke AI decision',
                'status' => 'process_closed',
                'status_label' => 'transaction smoke AI review completed',
                'record_count' => 1,
                'process_closed_loop' => true,
                'roi_ready' => false,
                'roi_ready_count' => 0,
                'source_scope' => 'ctrip_ota_channel_transaction_smoke',
            ],
            [
                'key' => 'revenue_pricing',
                'label' => 'Ctrip transaction smoke revenue pricing',
                'status' => $roiReady > 0 ? 'roi_ready' : 'reviewed_no_roi',
                'status_label' => $roiReady > 0 ? 'operation ROI ready' : 'operation ROI missing',
                'record_count' => 1,
                'process_closed_loop' => true,
                'roi_ready' => $roiReady > 0,
                'roi_ready_count' => $roiReady > 0 ? 1 : 0,
                'source_scope' => 'ctrip_ota_channel_price_suggestion_transaction_smoke',
            ],
            [
                'key' => 'operation_execution',
                'label' => 'Ctrip transaction smoke operation execution',
                'status' => $roiReady > 0 ? 'roi_ready' : 'reviewed_no_roi',
                'status_label' => $roiReady > 0 ? 'operation ROI ready' : 'operation ROI missing',
                'record_count' => $operationTotal,
                'linked_execution_count' => $operationTotal,
                'process_closed_loop' => $operationTotal > 0,
                'roi_ready' => $roiReady > 0,
                'roi_ready_count' => $roiReady,
                'source_scope' => 'ctrip_ota_channel_execution_intents_tasks_evidence_roi',
            ],
        ],
        'data_gaps' => [],
    ];
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    ctrip_generation_finish([
        'status' => 'failed',
        'error' => 'vendor/autoload.php is missing.',
    ], 1);
}

require $autoload;

try {
    $options = ctrip_generation_parse_args($argv);
    $app = new App($root);
    $app->initialize();

    $hotelId = ctrip_generation_resolve_hotel_id($options['hotel_id'], $options['date']);
    $checks = [];
    $rolledBack = false;
    $rollbackCounts = [];
    $fixture = [];
    $generationData = [];
    $overviewInTransaction = [];
    $overviewAfterExecutionIntent = [];
    $overviewAfterOperationRoi = [];
    $reviewData = [];
    $executionIntentData = [];
    $operationApproval = [];
    $operationExecutedTask = [];
    $operationReviewedTask = [];
    $operationExecutionFlow = [];
    $investmentOverview = [];
    $createdSuggestionIds = [];

    Db::startTrans();
    try {
        $fixture = ctrip_generation_insert_fixture_inputs($hotelId, $options['date']);

        $app->request->user = ctrip_generation_fake_super_admin($hotelId);
        $app->request->withGet([
            'hotel_id' => $hotelId,
            'date' => $options['date'],
        ]);

        $response = (new Agent($app))->generatePriceSuggestions();
        $generationPayload = ctrip_generation_decode_response($response);
        $generationData = ctrip_generation_map($generationPayload['data'] ?? []);
        $createdSuggestionIds = ctrip_generation_positive_ints(array_map(
            static fn(mixed $item): mixed => is_array($item) ? ($item['id'] ?? 0) : 0,
            ctrip_generation_list($generationData['list'] ?? [])
        ));

        $overviewInTransaction = (new RevenueAiOverviewService())->overview([
            'business_date' => $options['date'],
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
        ]);

        $suggestionId = (int)($createdSuggestionIds[0] ?? 0);
        if ($suggestionId > 0) {
            $app->request->withPost([
                'action' => 'approve',
                'remark' => 'transaction-only Ctrip AI decision review smoke',
            ]);
            $reviewPayload = ctrip_generation_decode_response((new RevenueAi($app))->reviewPriceSuggestion($suggestionId));
            $reviewData = ctrip_generation_map($reviewPayload['data'] ?? []);

            $app->request->withPost([
                'platform' => 'ctrip',
                'room_type_key' => 'CTRIP-SMOKE-ROOM-' . (int)($fixture['room_type_id'] ?? 0),
                'rate_plan_key' => 'BAR',
                'expected_metric' => 'orders',
                'expected_delta' => 1,
                'risk_level' => 'medium',
            ]);
            $executionPayload = ctrip_generation_decode_response((new RevenueAi($app))->createPriceSuggestionExecutionIntent($suggestionId));
            $executionIntentData = ctrip_generation_map($executionPayload['data'] ?? []);
            $executionIntent = ctrip_generation_map($executionIntentData['execution_intent'] ?? []);
            $fixture['execution_intent_ids'] = [(int)($executionIntent['id'] ?? 0)];

            $overviewAfterExecutionIntent = (new RevenueAiOverviewService())->overview([
                'business_date' => $options['date'],
                'hotel_id' => $hotelId,
                'platform' => 'ctrip',
                'enabled_channels' => ['ctrip'],
            ]);

            if ($options['complete_operation_roi'] && (int)($executionIntent['id'] ?? 0) > 0) {
                $operationService = new OperationManagementService();
                $operationApproval = $operationService->approveExecutionIntent(
                    (int)$executionIntent['id'],
                    true,
                    'transaction-only Ctrip operation ROI smoke approval',
                    0,
                    [$hotelId]
                );
                $operationTasks = ctrip_generation_list($operationApproval['tasks'] ?? []);
                $operationTask = ctrip_generation_map($operationTasks[0] ?? []);
                $taskId = (int)($operationTask['id'] ?? 0);
                if ($taskId > 0) {
                    $fixture['execution_task_ids'] = [$taskId];
                    $currentPrice = (float)($firstSuggestion['current_price'] ?? 300);
                    $suggestedPrice = (float)($firstSuggestion['suggested_price'] ?? 330);
                    $operationExecutedTask = $operationService->executeExecutionTask($taskId, [$hotelId], [
                        'status' => 'executed',
                        'current_value' => [
                            'executed_before_price' => $currentPrice,
                            'source_scope' => 'ctrip_ota_channel',
                        ],
                        'target_value' => [
                            'executed_after_price' => $suggestedPrice,
                            'source_scope' => 'ctrip_ota_channel',
                        ],
                        'evidence_type' => 'manual_roi_evidence',
                        'evidence' => [
                            'before' => [
                                'revenue' => 1000,
                                'scope' => 'ctrip_ota_channel_manual_roi_smoke',
                            ],
                            'after' => [
                                'revenue' => 1300,
                                'scope' => 'ctrip_ota_channel_manual_roi_smoke',
                            ],
                            'platform_response' => [
                                'mode' => 'transaction_only_ctrip_operation_roi_smoke',
                                'source_scope' => 'ctrip_ota_channel',
                                'auto_write_ota' => false,
                                'evidence_boundary' => 'local_manual_roi_evidence_no_ota_write',
                            ],
                            'operator_execution_evidence' => [
                                'executed_by' => 'verifier_transaction_only',
                                'executed_at' => $options['date'] . ' 12:00:00',
                                'execution_basis' => 'verifier_transaction_only_ctrip_operation_execution_basis',
                                'room_rate_mapping_source' => 'verifier_transaction_only_ctrip_room_rate_mapping',
                                'execution_receipt_or_screenshot_path' => 'verifier_transaction_only://ctrip/execution-receipt',
                            ],
                            'operator_roi_evidence' => [
                                'reviewed_by' => 'verifier_transaction_only',
                                'reviewed_at' => $options['date'] . ' 18:00:00',
                                'before_metric_source' => 'verifier_transaction_only_ctrip_previous_day_metrics',
                                'after_metric_source' => 'verifier_transaction_only_ctrip_next_day_metrics',
                                'roi_calculation_basis' => 'verifier_transaction_only_ctrip_roi_calculation_basis',
                                'roi_receipt_or_screenshot_path' => 'verifier_transaction_only://ctrip/roi-receipt',
                            ],
                            'remark' => 'transaction-only Ctrip operation ROI evidence smoke',
                        ],
                    ], 0);
                    $evidenceRows = ctrip_generation_list($operationExecutedTask['evidence'] ?? []);
                    $fixture['execution_evidence_ids'] = ctrip_generation_positive_ints(array_map(
                        static fn(mixed $item): mixed => is_array($item) ? ($item['id'] ?? 0) : 0,
                        $evidenceRows
                    ));
                    $operationReviewedTask = $operationService->reviewExecutionTask($taskId, [$hotelId], [
                        'result_status' => 'success',
                        'result_summary' => 'transaction-only Ctrip operation ROI smoke reviewed',
                    ]);
                    $operationExecutionFlow = $operationService->executionFlow([$hotelId], $hotelId, ['object_type' => 'price']);
                    $investmentOverview = (new InvestmentDecisionSupportService())->buildOverviewFromEvidence(
                        ctrip_generation_investment_closure_overview(ctrip_generation_map($operationExecutionFlow['summary'] ?? [])),
                        [],
                        [],
                        [],
                        [
                            'status' => 'ok',
                            'sample_count' => 1,
                            'data_sources' => [[
                                'table' => 'competitor_analysis',
                                'count' => 1,
                                'source_scope' => 'ctrip_ota_channel_transaction_smoke',
                            ]],
                        ]
                    );
                    $overviewAfterOperationRoi = (new RevenueAiOverviewService())->overview([
                        'business_date' => $options['date'],
                        'hotel_id' => $hotelId,
                        'platform' => 'ctrip',
                        'enabled_channels' => ['ctrip'],
                    ]);
                }
            }
        }
    } finally {
        Db::rollback();
        $rolledBack = true;
    }

    if ($fixture !== []) {
        $rollbackCounts = ctrip_generation_inserted_id_counts($fixture, $createdSuggestionIds);
    }

    $createdList = ctrip_generation_list($generationData['list'] ?? []);
    $firstSuggestion = ctrip_generation_map($createdList[0] ?? []);
    $factors = ctrip_generation_map($firstSuggestion['factors'] ?? []);
    $signals = ctrip_generation_map($factors['signals'] ?? []);
    $reviewQueue = ctrip_generation_map($overviewInTransaction['review_queue'] ?? []);
    $preflight = ctrip_generation_map($overviewInTransaction['pricing_generation_preflight'] ?? []);
    $reviewContract = ctrip_generation_map(ctrip_generation_map($overviewInTransaction['pricing_readiness'] ?? [])['ai_decision_review_contract'] ?? []);
    $aiToOperation = ctrip_generation_map($overviewInTransaction['ai_to_operation_handoff'] ?? []);
    $executionIntent = ctrip_generation_map($executionIntentData['execution_intent'] ?? []);
    $executionIntentEvidence = ctrip_generation_map($executionIntent['evidence'] ?? []);
    $reviewAfterIntent = ctrip_generation_map($overviewAfterExecutionIntent['review_queue'] ?? []);
    $executionSummaryAfterIntent = ctrip_generation_map($overviewAfterExecutionIntent['execution_summary'] ?? []);
    $aiToOperationAfterIntent = ctrip_generation_map($overviewAfterExecutionIntent['ai_to_operation_handoff'] ?? []);
    $expectedAiToOperationAfterIntentStatus = 'operation_intake_waiting_human_approval';
    $operationFlowSummary = ctrip_generation_map($operationExecutionFlow['summary'] ?? []);
    $operationFlowList = ctrip_generation_list($operationExecutionFlow['list'] ?? []);
    $operationFlowFirst = ctrip_generation_map($operationFlowList[0] ?? []);
    $operationRoi = ctrip_generation_map($operationFlowFirst['roi'] ?? []);
    $operationRoiOperatorExecutionSummary = ctrip_generation_map($operationRoi['operator_execution_evidence_summary'] ?? []);
    $operationRoiOperatorRoiSummary = ctrip_generation_map($operationRoi['operator_roi_evidence_summary'] ?? []);
    $operationEvidenceRows = ctrip_generation_list($operationExecutedTask['evidence'] ?? []);
    $operationEvidenceFirst = ctrip_generation_map($operationEvidenceRows[0] ?? []);
    $operationEvidencePlatformResponse = ctrip_generation_map($operationEvidenceFirst['platform_response'] ?? []);
    $operatorExecutionEvidence = ctrip_generation_map($operationEvidencePlatformResponse['operator_execution_evidence'] ?? []);
    $operatorRoiEvidence = ctrip_generation_map($operationEvidencePlatformResponse['operator_roi_evidence'] ?? []);
    $executionSummaryAfterRoi = ctrip_generation_map($overviewAfterOperationRoi['execution_summary'] ?? []);
    $executionEffectReviewAfterRoi = ctrip_generation_map($executionSummaryAfterRoi['effect_review'] ?? []);
    $executionEffectReviewInputs = ctrip_generation_list($executionEffectReviewAfterRoi['inputs'] ?? []);
    $executionEffectReviewFirstInput = ctrip_generation_map($executionEffectReviewInputs[0] ?? []);
    $executionEffectOperatorExecutionSummary = ctrip_generation_map($executionEffectReviewFirstInput['operator_execution_evidence_summary'] ?? []);
    $executionEffectOperatorRoiSummary = ctrip_generation_map($executionEffectReviewFirstInput['operator_roi_evidence_summary'] ?? []);
    $operationToInvestmentAfterRoi = ctrip_generation_map($overviewAfterOperationRoi['operation_to_investment_handoff'] ?? []);
    $investmentSummary = ctrip_generation_map($investmentOverview['summary'] ?? []);
    $investmentOperatingGate = ctrip_generation_map($investmentOverview['operating_data_gate'] ?? []);
    $investmentBusinessChain = ctrip_generation_map($investmentOverview['business_closure_chain'] ?? []);
    $investmentActionQueue = ctrip_generation_map($investmentOverview['action_queue'] ?? []);
    $expectedOperationToInvestmentAfterRoiStatus = 'investment_precheck_waiting_decision_record';
    $operationRoiReadyGate = 'operation_execution.roi_ready';
    $contamination = json_encode([
        'generation' => [
            'status' => $generationData['status'] ?? null,
            'source_scope' => $generationData['source_scope'] ?? null,
            'source_channels' => $generationData['source_channels'] ?? null,
            'auto_write_ota' => $generationData['auto_write_ota'] ?? null,
        ],
        'review' => [
            'status' => $reviewData['status'] ?? null,
            'next_action' => $reviewData['next_action'] ?? null,
            'auto_write_ota' => $reviewData['auto_write_ota'] ?? null,
        ],
        'execution_intent' => [
            'source_module' => $executionIntentData['source_module'] ?? null,
            'platform' => $executionIntentData['platform'] ?? null,
            'object_type' => $executionIntentData['object_type'] ?? null,
            'target_page' => $executionIntentData['target_page'] ?? null,
            'target_action' => $executionIntentData['target_action'] ?? null,
            'next_action' => $executionIntentData['next_action'] ?? null,
            'auto_write_ota' => $executionIntentData['auto_write_ota'] ?? null,
        ],
        'operation_execution' => [
            'approval_status' => $operationApproval['status'] ?? null,
            'task_status' => $operationExecutedTask['status'] ?? null,
            'review_status' => $operationReviewedTask['result_status'] ?? null,
            'flow_stage' => $operationFlowFirst['stage'] ?? null,
            'roi_status' => $operationRoi['status'] ?? null,
            'roi_ready' => $operationFlowSummary['roi_ready'] ?? null,
        ],
        'investment_overview_business_scope' => [
            'source_scope' => $investmentOverview['source_scope'] ?? null,
            'summary' => $investmentSummary,
            'operating_data_gate' => $investmentOperatingGate,
            'business_closure_chain' => [
                'status' => $investmentBusinessChain['status'] ?? null,
                'judgement_gate' => $investmentBusinessChain['judgement_gate'] ?? null,
                'stage_count' => $investmentBusinessChain['stage_count'] ?? null,
                'closed_stage_count' => $investmentBusinessChain['closed_stage_count'] ?? null,
                'blocking_count' => $investmentBusinessChain['blocking_count'] ?? null,
            ],
            'action_queue' => [
                'status' => $investmentActionQueue['status'] ?? null,
                'item_count' => $investmentActionQueue['item_count'] ?? null,
                'blocking_count' => $investmentActionQueue['blocking_count'] ?? null,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

    ctrip_generation_check(
        $checks,
        'transaction_fixture_inserted',
        (int)($fixture['room_type_id'] ?? 0) > 0
            && (int)($fixture['demand_forecast_id'] ?? 0) > 0
            && (int)($fixture['competitor_analysis_id'] ?? 0) > 0,
        'Verifier inserted Ctrip manual-input fixtures inside a transaction.',
        $fixture
    );
    ctrip_generation_check(
        $checks,
        'agent_generation_created_pending_suggestion',
        (string)($generationData['status'] ?? '') === 'created'
            && (int)($generationData['created_count'] ?? 0) >= 1
            && (int)($firstSuggestion['status'] ?? 0) === PriceSuggestion::STATUS_PENDING,
        'Agent generation creates a local pending AI price suggestion when Ctrip inputs are complete.',
        [
            'status' => $generationData['status'] ?? null,
            'created_count' => $generationData['created_count'] ?? null,
            'first_suggestion_status' => $firstSuggestion['status'] ?? null,
            'first_suggestion_id' => $firstSuggestion['id'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'generation_result_keeps_ctrip_manual_review_gate',
        (string)($generationData['source_scope'] ?? '') === 'ctrip_ota_channel'
            && ctrip_generation_list($generationData['source_channels'] ?? []) === ['ctrip']
            && ($generationData['auto_write_ota'] ?? true) === false
            && ctrip_generation_map($generationData['ai_review_gate'] ?? []) !== []
            && (ctrip_generation_map($generationData['ai_review_gate'] ?? [])['operation_intake_allowed'] ?? true) === false,
        'Generated result remains Ctrip-scoped, advisory-only, manual-review gated, and does not write OTA.',
        [
            'source_scope' => $generationData['source_scope'] ?? null,
            'source_channels' => $generationData['source_channels'] ?? null,
            'auto_write_ota' => $generationData['auto_write_ota'] ?? null,
            'ai_review_gate' => $generationData['ai_review_gate'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'created_suggestion_uses_real_pricing_signals',
        (int)($factors['primary_signal_count'] ?? 0) >= 2
            && (string)($signals['demand_forecast']['data_status'] ?? '') === 'ok'
            && (string)($signals['competitor']['data_status'] ?? '') === 'ok'
            && (int)($firstSuggestion['demand_forecast_id'] ?? 0) === (int)($fixture['demand_forecast_id'] ?? 0),
        'Created suggestion is backed by demand forecast and Ctrip competitor price signals.',
        [
            'primary_signal_count' => $factors['primary_signal_count'] ?? null,
            'demand_forecast_status' => $signals['demand_forecast']['data_status'] ?? null,
            'competitor_status' => $signals['competitor']['data_status'] ?? null,
            'demand_forecast_id' => $firstSuggestion['demand_forecast_id'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'overview_queue_reads_pending_suggestion',
        (string)($reviewQueue['status'] ?? '') === 'pending_review'
            && (int)($reviewQueue['pending_count'] ?? 0) >= 1
            && (string)($reviewQueue['source_table'] ?? '') === 'price_suggestions',
        'Revenue AI overview reads the generated local pending suggestion into the AI review queue.',
        [
            'status' => $reviewQueue['status'] ?? null,
            'pending_count' => $reviewQueue['pending_count'] ?? null,
            'source_table' => $reviewQueue['source_table'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'overview_preflight_moves_to_pending_review',
        (string)($preflight['status'] ?? '') === 'pending_review_exists'
            && (string)($preflight['reason'] ?? '') === 'price_suggestions_pending_review'
            && (int)($preflight['pending_suggestion_count'] ?? 0) >= 1
            && ($preflight['auto_write_ota'] ?? true) === false,
        'Generation preflight moves from missing input into pending manual review without OTA write.',
        [
            'status' => $preflight['status'] ?? null,
            'reason' => $preflight['reason'] ?? null,
            'pending_suggestion_count' => $preflight['pending_suggestion_count'] ?? null,
            'auto_write_ota' => $preflight['auto_write_ota'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'ai_to_operation_stays_blocked_before_review',
        (string)($aiToOperation['source_scope'] ?? '') === 'ctrip_ota_channel'
            && ($aiToOperation['can_create_operation_execution'] ?? true) === false
            && ($reviewContract['operation_intake_allowed'] ?? true) === false
            && ($reviewContract['auto_apply_ai_advice'] ?? true) === false,
        'Pending AI suggestion does not open operation intake before human review.',
        [
            'review_contract_status' => $reviewContract['status'] ?? null,
            'approval_allowed' => $reviewContract['approval_allowed'] ?? null,
            'operation_intake_allowed' => $reviewContract['operation_intake_allowed'] ?? null,
            'auto_apply_ai_advice' => $reviewContract['auto_apply_ai_advice'] ?? null,
            'ai_to_operation_status' => $aiToOperation['status'] ?? null,
            'can_create_operation_execution' => $aiToOperation['can_create_operation_execution'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'manual_review_approves_without_ota_write',
        (string)($reviewData['status'] ?? '') === 'approved'
            && (string)($reviewData['next_action'] ?? '') === 'create_execution_intent'
            && (string)($reviewData['review_storage'] ?? '') === 'price_suggestions.factors.manual_review_versions'
            && ($reviewData['auto_write_ota'] ?? true) === false
            && ($reviewData['local_price_updated'] ?? true) === false
            && ($reviewData['ota_write'] ?? true) === false,
        'Revenue AI manual review approves the pending Ctrip suggestion without OTA write or local price mutation.',
        [
            'status' => $reviewData['status'] ?? null,
            'next_action' => $reviewData['next_action'] ?? null,
            'review_storage' => $reviewData['review_storage'] ?? null,
            'auto_write_ota' => $reviewData['auto_write_ota'] ?? null,
            'local_price_updated' => $reviewData['local_price_updated'] ?? null,
            'ota_write' => $reviewData['ota_write'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'approved_suggestion_creates_operation_execution_intent',
        (int)($executionIntent['id'] ?? 0) > 0
            && (string)($executionIntent['source_module'] ?? '') === 'price_suggestion'
            && (int)($executionIntent['source_record_id'] ?? 0) === (int)($createdSuggestionIds[0] ?? 0)
            && (string)($executionIntent['platform'] ?? '') === 'ctrip'
            && (string)($executionIntent['object_type'] ?? '') === 'price'
            && (string)($executionIntent['status'] ?? '') === 'pending_approval'
            && trim((string)($executionIntent['blocked_reason'] ?? '')) === '',
        'Approved Ctrip AI suggestion can create a local operation execution intent pending approval.',
        [
            'id' => $executionIntent['id'] ?? null,
            'source_module' => $executionIntent['source_module'] ?? null,
            'source_record_id' => $executionIntent['source_record_id'] ?? null,
            'platform' => $executionIntent['platform'] ?? null,
            'object_type' => $executionIntent['object_type'] ?? null,
            'status' => $executionIntent['status'] ?? null,
            'blocked_reason' => $executionIntent['blocked_reason'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'execution_intent_keeps_manual_execution_boundary',
        (string)($executionIntentData['target_page'] ?? '') === 'ops-track'
            && (string)($executionIntentData['target_action'] ?? '') === 'approve_intent'
            && (string)($executionIntentData['next_action'] ?? '') === 'operation_execution_manual_evidence'
            && ($executionIntentData['auto_write_ota'] ?? true) === false
            && ($executionIntentData['local_price_updated'] ?? true) === false
            && ($executionIntentData['ota_write'] ?? true) === false
            && ($executionIntentEvidence['auto_write_ota'] ?? true) === false
            && ctrip_generation_map($executionIntentEvidence['manual_review'] ?? []) !== [],
        'Execution intent remains an operation approval/evidence handoff and does not apply OTA rates.',
        [
            'target_page' => $executionIntentData['target_page'] ?? null,
            'target_action' => $executionIntentData['target_action'] ?? null,
            'next_action' => $executionIntentData['next_action'] ?? null,
            'auto_write_ota' => $executionIntentData['auto_write_ota'] ?? null,
            'local_price_updated' => $executionIntentData['local_price_updated'] ?? null,
            'ota_write' => $executionIntentData['ota_write'] ?? null,
            'evidence_auto_write_ota' => $executionIntentEvidence['auto_write_ota'] ?? null,
            'manual_review_storage' => $executionIntentEvidence['manual_review_storage'] ?? null,
        ]
    );
    ctrip_generation_check(
        $checks,
        'overview_reads_operation_execution_intent_after_review',
        (int)($executionSummaryAfterIntent['total_count'] ?? 0) >= 1
            && (string)($reviewAfterIntent['status'] ?? '') !== 'pending_review'
            && (string)($aiToOperationAfterIntent['status'] ?? '') === $expectedAiToOperationAfterIntentStatus,
        'Revenue AI overview sees the post-review execution intent and reports the AI-to-operation handoff as waiting for human operation approval.',
        [
            'review_queue_status' => $reviewAfterIntent['status'] ?? null,
            'review_queue_pending_count' => $reviewAfterIntent['pending_count'] ?? null,
            'execution_summary_status' => $executionSummaryAfterIntent['status'] ?? null,
            'execution_summary_total_count' => $executionSummaryAfterIntent['total_count'] ?? null,
            'expected_ai_to_operation_status' => $expectedAiToOperationAfterIntentStatus,
            'ai_to_operation_status' => $aiToOperationAfterIntent['status'] ?? null,
        ]
    );
    if ($options['complete_operation_roi']) {
        ctrip_generation_check(
            $checks,
            'operation_intent_approval_creates_manual_task',
            (string)($operationApproval['status'] ?? '') === 'approved'
                && (int)($fixture['execution_task_ids'][0] ?? 0) > 0,
            'Approved Ctrip execution intent creates a manual operation task.',
            [
                'intent_status' => $operationApproval['status'] ?? null,
                'task_ids' => $fixture['execution_task_ids'] ?? [],
            ]
        );
        ctrip_generation_check(
            $checks,
            'operation_execution_records_local_roi_evidence',
            (string)($operationExecutedTask['status'] ?? '') === 'executed'
                && ctrip_generation_list($operationExecutedTask['evidence'] ?? []) !== []
                && $operatorExecutionEvidence !== []
                && $operatorRoiEvidence !== [],
            'Operation execution records local manual execution and ROI evidence without OTA write.',
            [
                'task_status' => $operationExecutedTask['status'] ?? null,
                'evidence_ids' => $fixture['execution_evidence_ids'] ?? [],
                'operator_execution_evidence_keys' => array_keys($operatorExecutionEvidence),
                'operator_roi_evidence_keys' => array_keys($operatorRoiEvidence),
                'auto_write_ota' => false,
            ]
        );
        ctrip_generation_check(
            $checks,
            'operation_review_marks_roi_ready',
            (string)($operationReviewedTask['result_status'] ?? '') === 'success'
                && (string)($operationRoi['status'] ?? '') === 'ready'
                && (int)($operationFlowSummary['roi_ready'] ?? 0) >= 1,
            'Operation review converts local Ctrip execution evidence into ROI-ready operation evidence.',
            [
                'review_status' => $operationReviewedTask['result_status'] ?? null,
                'flow_stage' => $operationFlowFirst['stage'] ?? null,
                'roi_status' => $operationRoi['status'] ?? null,
                'roi_unit' => $operationRoi['unit'] ?? null,
                'roi_value' => $operationRoi['value'] ?? null,
                'roi_ready' => $operationFlowSummary['roi_ready'] ?? null,
            ]
        );
        ctrip_generation_check(
            $checks,
            'operation_roi_exposes_operator_evidence_summary',
            (bool)($operationRoiOperatorExecutionSummary['provided'] ?? false) === true
                && (bool)($operationRoiOperatorRoiSummary['provided'] ?? false) === true
                && in_array('execution_basis', ctrip_generation_list_scalars($operationRoiOperatorExecutionSummary['keys'] ?? []), true)
                && in_array('roi_calculation_basis', ctrip_generation_list_scalars($operationRoiOperatorRoiSummary['keys'] ?? []), true),
            'Operation ROI summary exposes local operator execution and ROI evidence sources.',
            [
                'operator_execution_evidence_summary_keys' => $operationRoiOperatorExecutionSummary['keys'] ?? [],
                'operator_roi_evidence_summary_keys' => $operationRoiOperatorRoiSummary['keys'] ?? [],
                'auto_write_ota' => false,
            ]
        );
        ctrip_generation_check(
            $checks,
            'overview_reads_operation_roi_ready_after_review',
            (int)($executionSummaryAfterRoi['roi_ready_count'] ?? 0) >= 1
                && (int)($operationToInvestmentAfterRoi['operation_roi_ready'] ?? 0) === 1
                && (string)($operationToInvestmentAfterRoi['status'] ?? '') === $expectedOperationToInvestmentAfterRoiStatus
                && (bool)($operationToInvestmentAfterRoi['decision_allowed'] ?? true) === false,
            'Revenue AI overview sees operation ROI evidence while keeping investment decision blocked until decision-record readiness.',
            [
                'execution_summary_status' => $executionSummaryAfterRoi['status'] ?? null,
                'execution_summary_roi_ready_count' => $executionSummaryAfterRoi['roi_ready_count'] ?? null,
                'required_gate' => $operationRoiReadyGate,
                'expected_operation_to_investment_status' => $expectedOperationToInvestmentAfterRoiStatus,
                'operation_to_investment_status' => $operationToInvestmentAfterRoi['status'] ?? null,
                'operation_roi_ready' => $operationToInvestmentAfterRoi['operation_roi_ready'] ?? null,
                'decision_allowed' => $operationToInvestmentAfterRoi['decision_allowed'] ?? null,
            ]
        );
        ctrip_generation_check(
            $checks,
            'overview_effect_review_exposes_operator_evidence_summary',
            (bool)($executionEffectReviewFirstInput['has_operator_execution_evidence'] ?? false) === true
                && (bool)($executionEffectReviewFirstInput['has_operator_roi_evidence'] ?? false) === true
                && (bool)($executionEffectOperatorExecutionSummary['provided'] ?? false) === true
                && (bool)($executionEffectOperatorRoiSummary['provided'] ?? false) === true,
            'Revenue AI overview effect-review input exposes operator execution and ROI evidence summaries.',
            [
                'effect_review_input_count' => count($executionEffectReviewInputs),
                'operator_execution_evidence_summary_keys' => $executionEffectOperatorExecutionSummary['keys'] ?? [],
                'operator_roi_evidence_summary_keys' => $executionEffectOperatorRoiSummary['keys'] ?? [],
                'auto_write_ota' => false,
            ]
        );
        ctrip_generation_check(
            $checks,
            'investment_support_reads_closed_operation_roi_without_decision',
            (string)($investmentOverview['source_scope'] ?? '') === 'closed_operating_data_only'
                && (bool)($investmentOperatingGate['can_use_for_investment_judgement'] ?? false) === true
                && (string)($investmentOperatingGate['required_gate'] ?? '') === $operationRoiReadyGate
                && (bool)($investmentSummary['decision_allowed'] ?? true) === false
                && (string)($investmentSummary['status'] ?? '') === 'not_ready',
            'Investment decision support reads closed Ctrip operation ROI but keeps judgement blocked without decision-record readiness.',
            [
                'source_scope' => $investmentOverview['source_scope'] ?? null,
                'summary_status' => $investmentSummary['status'] ?? null,
                'decision_allowed' => $investmentSummary['decision_allowed'] ?? null,
                'operating_gate_status' => $investmentOperatingGate['status'] ?? null,
                'operating_gate_ready' => $investmentOperatingGate['can_use_for_investment_judgement'] ?? null,
                'required_gate' => $investmentOperatingGate['required_gate'] ?? null,
                'decision_record_count' => $investmentSummary['decision_record_count'] ?? null,
                'eligible_decision_record_count' => $investmentSummary['eligible_decision_record_count'] ?? null,
            ]
        );
        ctrip_generation_check(
            $checks,
            'investment_support_action_queue_requires_decision_readiness',
            (string)($investmentBusinessChain['judgement_gate'] ?? '') === 'operation_execution.roi_ready + decision_record.readiness_ready'
                && (int)($investmentActionQueue['item_count'] ?? 0) >= 1
                && (int)($investmentActionQueue['blocking_count'] ?? 0) >= 1,
            'Investment decision support action queue points to decision-record readiness instead of creating an investment decision.',
            [
                'business_closure_chain_status' => $investmentBusinessChain['status'] ?? null,
                'judgement_gate' => $investmentBusinessChain['judgement_gate'] ?? null,
                'action_queue_status' => $investmentActionQueue['status'] ?? null,
                'action_queue_count' => $investmentActionQueue['item_count'] ?? null,
                'action_queue_blocking_count' => $investmentActionQueue['blocking_count'] ?? null,
            ]
        );
    }
    $meituanClear = !str_contains(strtolower($contamination), 'meituan') && !str_contains($contamination, '美团');
    ctrip_generation_check(
        $checks,
        'meituan_not_present',
        $meituanClear,
        'Ctrip generation/review/execution smoke payload contains no Meituan token or label.',
        $meituanClear ? [] : ['checked_payload' => json_decode($contamination, true)]
    );
    ctrip_generation_check(
        $checks,
        'transaction_rolled_back',
        $rolledBack && $rollbackCounts !== [] && array_sum($rollbackCounts) === 0,
        'Verifier transaction was rolled back and did not leave fixture rows behind.',
        ['rolled_back' => $rolledBack, 'marker_counts_after_rollback' => $rollbackCounts]
    );

    $failures = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] !== 'passed'));
    ctrip_generation_finish([
        'status' => $failures === [] ? 'passed' : 'failed',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_policy' => 'transactional_fixture_rollback_no_ota_write',
            'complete_operation_roi' => $options['complete_operation_roi'],
        ],
        'summary' => [
            'fixture' => $fixture,
            'generation' => [
                'status' => $generationData['status'] ?? null,
                'reason' => $generationData['reason'] ?? null,
                'created_count' => $generationData['created_count'] ?? null,
                'skipped_count' => $generationData['skipped_count'] ?? null,
                'source_scope' => $generationData['source_scope'] ?? null,
                'source_channels' => $generationData['source_channels'] ?? null,
                'auto_write_ota' => $generationData['auto_write_ota'] ?? null,
                'ai_review_gate' => $generationData['ai_review_gate'] ?? null,
            ],
            'manual_review' => [
                'status' => $reviewData['status'] ?? null,
                'next_action' => $reviewData['next_action'] ?? null,
                'review_storage' => $reviewData['review_storage'] ?? null,
                'auto_write_ota' => $reviewData['auto_write_ota'] ?? null,
                'local_price_updated' => $reviewData['local_price_updated'] ?? null,
                'ota_write' => $reviewData['ota_write'] ?? null,
            ],
            'execution_intent' => [
                'id' => $executionIntent['id'] ?? null,
                'source_module' => $executionIntent['source_module'] ?? null,
                'source_record_id' => $executionIntent['source_record_id'] ?? null,
                'platform' => $executionIntent['platform'] ?? null,
                'object_type' => $executionIntent['object_type'] ?? null,
                'status' => $executionIntent['status'] ?? null,
                'blocked_reason' => $executionIntent['blocked_reason'] ?? null,
                'target_page' => $executionIntentData['target_page'] ?? null,
                'target_action' => $executionIntentData['target_action'] ?? null,
                'next_action' => $executionIntentData['next_action'] ?? null,
                'auto_write_ota' => $executionIntentData['auto_write_ota'] ?? null,
            ],
            'review_queue' => [
                'status' => $reviewQueue['status'] ?? null,
                'reason' => $reviewQueue['reason'] ?? null,
                'pending_count' => $reviewQueue['pending_count'] ?? null,
                'source_table' => $reviewQueue['source_table'] ?? null,
            ],
            'post_review_overview' => [
                'review_queue_status' => $reviewAfterIntent['status'] ?? null,
                'review_queue_pending_count' => $reviewAfterIntent['pending_count'] ?? null,
                'execution_summary_status' => $executionSummaryAfterIntent['status'] ?? null,
                'execution_summary_total_count' => $executionSummaryAfterIntent['total_count'] ?? null,
                'ai_to_operation_status' => $aiToOperationAfterIntent['status'] ?? null,
            ],
            'operation_roi' => $options['complete_operation_roi'] ? [
                'approval_status' => $operationApproval['status'] ?? null,
                'task_id' => $fixture['execution_task_ids'][0] ?? null,
                'task_status' => $operationReviewedTask['status'] ?? ($operationExecutedTask['status'] ?? null),
                'review_status' => $operationReviewedTask['result_status'] ?? null,
                'flow_stage' => $operationFlowFirst['stage'] ?? null,
                'roi_status' => $operationRoi['status'] ?? null,
                'roi_unit' => $operationRoi['unit'] ?? null,
                'roi_value' => $operationRoi['value'] ?? null,
                'roi_ready' => $operationFlowSummary['roi_ready'] ?? null,
                'overview_roi_ready_count' => $executionSummaryAfterRoi['roi_ready_count'] ?? null,
                'required_gate' => $operationRoiReadyGate,
                'expected_operation_to_investment_status' => $expectedOperationToInvestmentAfterRoiStatus,
                'operation_to_investment_status' => $operationToInvestmentAfterRoi['status'] ?? null,
                'operation_roi_ready' => $operationToInvestmentAfterRoi['operation_roi_ready'] ?? null,
                'investment_decision_allowed' => $operationToInvestmentAfterRoi['decision_allowed'] ?? null,
                'investment_support_status' => $investmentSummary['status'] ?? null,
                'investment_support_decision_allowed' => $investmentSummary['decision_allowed'] ?? null,
                'investment_support_judgement_gate' => $investmentBusinessChain['judgement_gate'] ?? null,
                'investment_support_action_queue_count' => $investmentActionQueue['item_count'] ?? null,
                'auto_write_ota' => false,
            ] : null,
            'pricing_generation_preflight' => [
                'status' => $preflight['status'] ?? null,
                'reason' => $preflight['reason'] ?? null,
                'pending_suggestion_count' => $preflight['pending_suggestion_count'] ?? null,
                'create_candidate_count' => $preflight['create_candidate_count'] ?? null,
                'auto_write_ota' => $preflight['auto_write_ota'] ?? null,
            ],
            'rollback' => [
                'rolled_back' => $rolledBack,
                'marker_counts_after_rollback' => $rollbackCounts,
            ],
        ],
        'checks' => $checks,
    ], $failures === [] ? 0 : 1);
} catch (Throwable $error) {
    try {
        Db::rollback();
    } catch (Throwable) {
        // Best-effort rollback for early failures before normal control flow.
    }

    ctrip_generation_finish([
        'status' => 'failed',
        'error' => [
            'type' => get_class($error),
            'message' => $error->getMessage(),
        ],
    ], 1);
}
