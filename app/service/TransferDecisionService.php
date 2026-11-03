<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/** Retired generators retain scoped historical reads and existing-source approval compatibility. */
class TransferDecisionService
{
    private const TRUSTED_OTA_VALIDATION_STATUSES = [
        'normal', 'available', 'verified', 'valid', 'ok', 'success', 'complete', 'completed',
    ];
    private const SUPPORTED_OTA_PLATFORMS = ['ctrip', 'meituan', 'qunar'];
    private const TRANSFER_RECORD_SCOPE_MISSING = 'Transfer record does not exist or is outside current tenant scope';
    private const TRANSFER_RECORD_MIGRATION_REQUIRED = 'transfer_records_migration_required';

    private AiDecisionQualityService $decisionQualityService;
    private SourceBackedExecutionBridgeProjectionService $executionBridgeProjection;
    private bool $tableEnsured = false;
    /** @var array<string,array<string,int|string>> */
    private array $sourceReadStatus = [];

    public function __construct(
        ?LlmClient $client = null,
        ?AiDecisionQualityService $decisionQualityService = null,
        ?SourceBackedExecutionBridgeProjectionService $executionBridgeProjection = null
    )
    {
        $this->decisionQualityService = $decisionQualityService ?? new AiDecisionQualityService();
        $this->executionBridgeProjection = $executionBridgeProjection
            ?? new SourceBackedExecutionBridgeProjectionService();
    }

    public function calculateAssetPricing(array $input): array
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function calculateTransferTiming(array $input): array
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function buildTransferDashboard(array $pricing, array $timing, array $metrics): array
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function buildDecisionReadiness(string $recordType, array $input, array $result, array $snapshot, int $hotelId = 0): array
    {
        $pricingInput = is_array($input['pricing_input'] ?? null) ? $input['pricing_input'] : $input;
        $timingInput = is_array($input['timing_input'] ?? null) ? $input['timing_input'] : $input;
        $pricingResult = $this->readinessPricingResult($recordType, $input, $result);
        $timingResult = $this->readinessTimingResult($recordType, $input, $result);
        $dashboardResult = $recordType === 'dashboard' ? $result : [];

        $hotelBound = $hotelId > 0 || (int)($input['hotel_id'] ?? $snapshot['hotel_id'] ?? 0) > 0;
        $sourceCounts = is_array($snapshot['source_counts'] ?? null) ? $snapshot['source_counts'] : [];
        $actualDays = (int)($snapshot['current']['actual_days'] ?? 0);
        $sourceLoaded = $this->snapshotHasSourceRecords($snapshot);
        $sourceScope = $this->sourceScopeFromSnapshot($sourceCounts);

        $pricingReady = isset($pricingResult['valuation']['reasonable_valuation'])
            && array_key_exists('monthly_net_profit', (array)($pricingResult['profit'] ?? []));
        $timingReady = isset($timingResult['timing_score']) && trim((string)($timingResult['decision'] ?? '')) !== '';
        $dashboardReady = trim((string)($dashboardResult['final_judgement'] ?? '')) !== ''
            || trim((string)($dashboardResult['suggested_action'] ?? '')) !== '';
        $dataQualityClear = !$this->hasReadinessDataIssue($pricingInput, $timingInput, $pricingResult, $timingResult, $snapshot);
        $costInputsReady = $this->transferCostInputsReady($pricingInput, $pricingResult);
        $leaseLicenseReady = $this->leaseLicenseInputsReady($pricingInput);
        $diligenceEvidenceReady = $this->hasDiligenceEvidence($input, $result, $snapshot);
        $humanReviewReady = $this->hasHumanReviewApproval($input, $result);
        $postDecisionTrackingReady = $this->hasPostDecisionTracking($input, $result);

        $checks = [
            $this->readinessCheck('hotel_bound', '系统酒店绑定', $hotelBound, '已绑定到可访问酒店', '先选择系统酒店，避免脱离门店权限的孤立测算。', 10),
            $this->readinessCheck('source_snapshot', '来源记录快照', $sourceLoaded, $this->readinessSourceEvidence($snapshot, $sourceCounts), '先带入可追溯的来源记录，并保留取数日期、来源计数和核验状态。', 15),
            $this->readinessCheck('operating_window', '经营样本窗口', $sourceLoaded && $actualDays >= 7, $actualDays > 0 ? '当前窗口样本 ' . $actualDays . ' 天' : '当前窗口无样本', '至少补齐 7 天以上经营样本；30 天窗口更适合投决复核。', 10),
            $this->readinessCheck('cost_assumptions', '成本与报价输入', $costInputsReady, '租金、人力、转让价等关键输入已填写', '补齐租金、人力、预期转让价、房量和收入等关键假设。', 10),
            $this->readinessCheck('pricing_result', '资产定价结果', $pricingReady, '已形成估值、利润和风险结果', '先生成资产定价，不能只保留原始表单。', 15),
            $this->readinessCheck('timing_result', '转让时机结果', $timingReady, '已形成时机评分和动作建议', '先生成时机推演，补齐趋势与数据质量判断。', 15),
            $this->readinessCheck('dashboard_summary', '决策看板汇总', $dashboardReady, '已汇总定价、时机、风险和下一步动作', '在决策看板汇总，不用单一测算替代投决结论。', 10),
            $this->readinessCheck('data_quality_clear', '数据质量复核', $dataQualityClear, '未发现显式异常标记', '先复核数据异常、断档或 OTA 采集口径冲突。', 5),
            $this->readinessCheck('lease_license_inputs', '租约与证照输入', $leaseLicenseReady, '租期和证照输入已填写', '补齐剩余租期和证照状态；表单勾选不等同于原件证据。', 5),
            $this->readinessCheck('manual_review', '人工复核审批', $humanReviewReady, '已记录人工复核/审批状态', '补一条人工复核结论，明确通过、暂缓或重谈。', 3),
            $this->readinessCheck('post_decision_tracking', '投后跟踪', $postDecisionTrackingReady, '已关联执行/跟踪记录', '关联运营执行、成交跟踪或复盘记录，避免投决后失联。', 2),
        ];

        $missingEvidence = [];
        foreach ($checks as $check) {
            if (!$check['passed']) {
                $missingEvidence[] = [
                    'code' => $check['key'],
                    'label' => $check['label'],
                    'next_action' => $check['next_action'],
                ];
            }
        }
        if (($pricingReady || $timingReady || $dashboardReady) && !$diligenceEvidenceReady) {
            $missingEvidence[] = [
                'code' => 'diligence_document_evidence',
                'label' => '尽调原件证据',
                'next_action' => '补充租约、证照、流水、平台截图或附件证据；当前仅能视为测算记录。',
            ];
        }

        $stage = $this->decisionReadinessStage(
            $pricingReady,
            $timingReady,
            $dashboardReady,
            $sourceLoaded,
            $dataQualityClear,
            $leaseLicenseReady,
            $diligenceEvidenceReady,
            $humanReviewReady,
            $postDecisionTrackingReady
        );
        $score = 0;
        foreach ($checks as $check) {
            if ($check['passed']) {
                $score += (int)$check['weight'];
            }
        }

        return [
            'stage' => $stage,
            'status_label' => $this->decisionReadinessStageLabel($stage),
            'score' => $score,
            'ready_for_review' => in_array($stage, ['review_ready', 'approved_pending_tracking', 'decision_ready'], true),
            'decision_ready' => $stage === 'decision_ready',
            'source_scope' => $sourceScope,
            'record_type' => $recordType,
            'actual_days' => $actualDays,
            'checks' => $checks,
            'missing_evidence' => $missingEvidence,
            'next_action' => $missingEvidence[0]['next_action'] ?? '进入人工投决复核并保留审批和跟踪证据。',
            'notice' => $this->decisionReadinessNotice($stage),
        ];
    }

    public function readinessSummaryFromRows(array $rows): array
    {
        $summary = [
            'record_count' => 0,
            'stage_counts' => [],
            'review_ready_count' => 0,
            'decision_ready_count' => 0,
            'best_score' => 0,
            'best_stage' => '',
            'best_status_label' => '',
            'missing_evidence' => [],
        ];

        $projectionContext = $this->executionBridgeProjection->projectionContext('transfer_decision', $rows);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            [$input, $result, $snapshot] = $this->executionBridgeProjection->trackingForResponses(
                'transfer_decision',
                [
                    ['source' => $row, 'payload' => $this->decodeJson($row['input_json'] ?? '')],
                    ['source' => $row, 'payload' => $this->decodeJson($row['result_json'] ?? '')],
                    ['source' => $row, 'payload' => $this->decodeJson($row['snapshot_json'] ?? '')],
                ],
                $projectionContext
            );
            $readiness = $this->buildDecisionReadiness(
                (string)($row['record_type'] ?? ''),
                $input,
                $result,
                $snapshot,
                (int)($row['hotel_id'] ?? 0)
            );
            $summary['record_count']++;
            $stage = (string)$readiness['stage'];
            $summary['stage_counts'][$stage] = (int)($summary['stage_counts'][$stage] ?? 0) + 1;
            if (($readiness['ready_for_review'] ?? false) === true) {
                $summary['review_ready_count']++;
            }
            if (($readiness['decision_ready'] ?? false) === true) {
                $summary['decision_ready_count']++;
            }
            if ((int)$readiness['score'] >= (int)$summary['best_score']) {
                $summary['best_score'] = (int)$readiness['score'];
                $summary['best_stage'] = $stage;
                $summary['best_status_label'] = (string)$readiness['status_label'];
                $summary['missing_evidence'] = array_slice((array)$readiness['missing_evidence'], 0, 4);
            }
        }

        return $summary;
    }

    private function normalizeTransferBusinessDate(mixed $date): string
    {
        if (!is_string($date)) {
            throw new InvalidArgumentException('transfer business date must use Y-m-d');
        }
        $timezone = new DateTimeZone('Asia/Shanghai');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('transfer business date must use a valid Y-m-d calendar date');
        }
        return $date;
    }

    private function transferBusinessToday(?DateTimeInterface $now = null): string
    {
        $timestamp = $now?->getTimestamp() ?? time();
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
    }

    /** @return array{start:string,end:string} */
    private function transferBusinessWindow(string $endDate, int $days): array
    {
        $end = $this->normalizeTransferBusinessDate($endDate);
        if ($days <= 0) {
            throw new InvalidArgumentException('transfer business window days must be positive');
        }
        $start = (new DateTimeImmutable($end, new DateTimeZone('Asia/Shanghai')))
            ->modify('-' . ($days - 1) . ' days')
            ->format('Y-m-d');
        return ['start' => $start, 'end' => $end];
    }

    public function buildSourcePayload(array $hotelIds, ?int $hotelId, string $date): array
    {
        $this->ensureTable();
        $this->sourceReadStatus = [];

        $targetHotelId = $hotelId ?: ($hotelIds[0] ?? 0);
        $scopeHotelIds = $targetHotelId > 0 ? [$targetHotelId] : $hotelIds;
        $sourceDate = $this->normalizeTransferBusinessDate($date);
        $currentWindow = $this->transferBusinessWindow($sourceDate, 30);
        $annualWindow = $this->transferBusinessWindow($sourceDate, 365);
        $currentStart = $currentWindow['start'];
        $annualStart = $annualWindow['start'];

        return Db::transaction(function () use (
            $targetHotelId,
            $scopeHotelIds,
            $sourceDate,
            $currentStart,
            $annualStart
        ): array {
        $hotel = $this->lockedHotelIdentity($targetHotelId);
        $tenantId = (int)$hotel['tenant_id'];
        $currentDaily = $this->dailyReportRows($scopeHotelIds, $currentStart, $sourceDate, $tenantId);
        $currentOnline = $this->onlineRows($scopeHotelIds, $currentStart, $sourceDate, $tenantId);
        $annualDaily = $this->dailyReportRows($scopeHotelIds, $annualStart, $sourceDate, $tenantId);
        $annualOnline = $this->onlineRows($scopeHotelIds, $annualStart, $sourceDate, $tenantId);
        $current = $this->aggregateTransferMetrics($currentDaily, $currentOnline, [
            'target_hotel_id' => $targetHotelId,
            'start_date' => $currentStart,
            'end_date' => $sourceDate,
        ]);
        $annual = $this->aggregateTransferMetrics($annualDaily, $annualOnline, [
            'target_hotel_id' => $targetHotelId,
            'start_date' => $annualStart,
            'end_date' => $sourceDate,
        ]);
        $annualBenchmark = $this->annualThirtyDayBenchmark($annual);
        $hotelName = (string)($hotel['name'] ?? $current['hotel_name'] ?? '');
        $hasDailyReports = count($currentDaily) > 0;
        $hasOnlineRows = (int)($current['truth_context']['included_verified_count'] ?? 0) > 0;
        $sourceScope = $hasDailyReports
            ? ($hasOnlineRows ? 'local_daily_report_with_ota_channel' : 'local_daily_report_only')
            : ($hasOnlineRows ? 'ota_channel_only' : 'no_records');
        $dataGaps = [];
        if (!$hasDailyReports) {
            $dataGaps[] = '缺少可核验的全酒店经营日报；OTA渠道记录不能替代全酒店经营收入。';
        }
        if (!$hasOnlineRows) {
            $dataGaps[] = count($currentOnline) > 0
                ? 'ota_channel_trusted_rows_missing'
                : 'ota_channel_rows_missing';
        }
        if ((int)($current['truth_context']['excluded_untrusted_count'] ?? 0) > 0) {
            $dataGaps[] = 'ota_channel_untrusted_rows_excluded';
        }
        $dataStatus = $hasDailyReports
            ? '已读取本地经营日报；来源、口径与真实性仍需核验。'
            : ($hasOnlineRows
                ? '已读取OTA渠道记录；该口径不能替代全酒店经营收入，当前不足以进行转让估值。'
                : '未读取到可用于转让测算的经营记录。');

        $snapshot = [
            'hotel_id' => $targetHotelId,
            'tenant_id' => $tenantId,
            'source_identity' => [
                'hotel_id' => $targetHotelId,
                'tenant_id' => $tenantId,
            ],
            'hotel_name' => $hotelName,
            'location' => (string)($hotel['address'] ?? ''),
            'source_date' => $sourceDate,
            'current_window' => ['start' => $currentStart, 'end' => $sourceDate],
            'annual_window' => ['start' => $annualStart, 'end' => $sourceDate],
            'current' => $current,
            'annual' => $annual,
            'annual_benchmark' => $annualBenchmark,
            'source_counts' => [
                'daily_reports' => count($currentDaily),
                'online_daily_data' => (int)($current['truth_context']['included_verified_count'] ?? 0),
                'online_daily_data_scoped' => count($currentOnline),
                'online_daily_data_excluded_untrusted' => (int)($current['truth_context']['excluded_untrusted_count'] ?? 0),
                'annual_daily_reports' => count($annualDaily),
                'annual_online_daily_data' => (int)($annual['truth_context']['included_verified_count'] ?? 0),
                'annual_online_daily_data_scoped' => count($annualOnline),
                'annual_online_daily_data_excluded_untrusted' => (int)($annual['truth_context']['excluded_untrusted_count'] ?? 0),
            ],
            'source_scope' => $sourceScope,
            'ota_scope' => 'ota_channel',
            'source_verified' => false,
            'source_read_status' => $this->sourceReadStatus,
            'truth_context' => $current['truth_context'],
            'annual_truth_context' => $annual['truth_context'],
            'data_gaps' => $dataGaps,
            'data_status' => $dataStatus,
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        return [
            'hotel_id' => $targetHotelId,
            'tenant_id' => $tenantId,
            'source_identity' => $snapshot['source_identity'],
            'hotel_name' => $hotelName,
            'source_date' => $sourceDate,
            'truth_context' => $snapshot['truth_context'],
            'snapshot' => $snapshot,
            'pricing_input' => [
                'hotel_id' => $targetHotelId,
                'hotel_name' => $hotelName,
                'location' => (string)($hotel['address'] ?? ''),
                'room_count' => $current['room_count'] > 0 ? $current['room_count'] : null,
                'monthly_revenue' => $hasDailyReports && $current['revenue'] > 0 ? round($current['revenue'] / 10000, 2) : null,
                'ota_channel_revenue' => !empty($current['ota_channel_revenue_observed']) ? round($current['ota_channel_revenue'] / 10000, 2) : null,
                'occupancy_rate' => $current['occupancy_rate'] > 0 ? $current['occupancy_rate'] : null,
                'adr' => $current['adr'] > 0 ? $current['adr'] : null,
                'rating' => $current['rating'] > 0 ? $current['rating'] : null,
                'order_count' => null,
                'ota_channel_order_count' => !empty($current['ota_channel_orders_observed']) ? $current['ota_channel_orders'] : null,
                'has_data_anomaly' => $current['has_data_anomaly'],
                'source_scope' => $sourceScope,
            ],
            'timing_input' => [
                'hotel_id' => $targetHotelId,
                'current_revenue' => $hasDailyReports && $current['revenue'] > 0 ? round($current['revenue'] / 10000, 2) : null,
                'previous_revenue' => count($annualDaily) > 0 && $annualBenchmark['revenue'] > 0 ? round($annualBenchmark['revenue'] / 10000, 2) : null,
                'current_orders' => null,
                'previous_orders' => null,
                'ota_channel_orders' => !empty($current['ota_channel_orders_observed']) ? $current['ota_channel_orders'] : null,
                'current_adr' => $current['adr'] > 0 ? $current['adr'] : null,
                'previous_adr' => $annualBenchmark['adr'] > 0 ? $annualBenchmark['adr'] : null,
                'current_occupancy_rate' => $current['occupancy_rate'] > 0 ? $current['occupancy_rate'] : null,
                'previous_occupancy_rate' => $annualBenchmark['occupancy_rate'] > 0 ? $annualBenchmark['occupancy_rate'] : null,
                'rating' => $current['rating'] > 0 ? $current['rating'] : null,
                'has_data_anomaly' => $current['has_data_anomaly'],
                'has_data_gap' => $current['actual_days'] > 0 && $current['actual_days'] < 7,
                'exposure' => $current['exposure'] > 0 ? $current['exposure'] : null,
                'visitors' => $current['visitors'] > 0 ? $current['visitors'] : null,
                'conversion_rate' => $current['conversion_rate'] > 0 ? $current['conversion_rate'] : null,
                'order_count' => !empty($current['ota_channel_orders_observed']) ? $current['ota_channel_orders'] : null,
                'room_nights' => !empty($current['ota_channel_room_nights_observed']) ? $current['ota_channel_room_nights'] : null,
                'ota_channel_room_nights' => !empty($current['ota_channel_room_nights_observed']) ? $current['ota_channel_room_nights'] : null,
                'source_scope' => $sourceScope,
            ],
            'data_notice' => $snapshot['data_status'],
        ];
        });
    }

    public function saveRecord(string $recordType, array $input, array $result, array $snapshot, int $hotelId, int $userId): int
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function records(array $hotelIds, int $userId, bool $isSuperAdmin): array
    {
        try {
            $this->ensureTable();
            $hotelIds = $this->normalizeTransferHotelIds($hotelIds);
            if ($hotelIds === []) {
                return [];
            }

            $query = $this->currentTenantTransferRecordQuery($hotelIds);
            $rows = $query->order('transfer_record.id', 'desc')->limit(80)->select()->toArray();
        } catch (Throwable $exception) {
            throw $this->transferRecordMigrationRequired($exception);
        }

        $projectionContext = $this->executionBridgeProjection->projectionContext('transfer_decision', $rows);
        return array_values(array_map(
            fn(array $row): array => $this->formatRecord($row, false, $projectionContext),
            $rows
        ));
    }

    public function detail(int $id, array $hotelIds, int $userId, bool $isSuperAdmin): array
    {
        try {
            $this->ensureTable();
            $hotelIds = $this->normalizeTransferHotelIds($hotelIds);

            $row = $hotelIds === []
                ? null
                : $this->currentTenantTransferRecordQuery($hotelIds, $id)->find();
        } catch (Throwable $exception) {
            throw $this->transferRecordMigrationRequired($exception);
        }
        if (!$row) {
            throw new RuntimeException(self::TRANSFER_RECORD_SCOPE_MISSING);
        }

        return $this->formatRecord($row, true);
    }

    public function archive(int $id, array $hotelIds, int $userId, bool $isSuperAdmin): bool
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function buildExecutionIntentInput(array $record, array $overrides = []): array
    {
        $hotelId = (int)($record['hotel_id'] ?? 0);
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('hotel_id is required for transfer execution tracking');
        }

        $input = $this->decodeJson($record['input'] ?? $record['input_json'] ?? []);
        $result = $this->decodeJson($record['result'] ?? $record['result_json'] ?? []);
        $snapshot = $this->decodeJson($record['snapshot'] ?? $record['snapshot_json'] ?? []);
        $recordType = (string)($record['record_type'] ?? '');
        $readiness = is_array($record['decision_readiness'] ?? null)
            ? $record['decision_readiness']
            : $this->buildDecisionReadiness($recordType, $input, $result, $snapshot, $hotelId);
        $dateStart = array_key_exists('date_start', $overrides)
            ? $this->normalizeTransferBusinessDate($overrides['date_start'])
            : $this->transferBusinessToday();
        $dateEnd = array_key_exists('date_end', $overrides)
            ? $this->normalizeTransferBusinessDate($overrides['date_end'])
            : $dateStart;
        $projectName = trim((string)($record['hotel_name'] ?? $snapshot['hotel_name'] ?? $input['hotel_name'] ?? ''));
        if ($projectName === '') {
            $projectName = 'transfer_record_' . (int)($record['id'] ?? 0);
        }

        return [
            'source_module' => 'transfer_decision',
            'source_record_id' => (int)($record['id'] ?? 0),
            'hotel_id' => $hotelId,
            'platform' => 'investment',
            'object_type' => 'investment',
            'action_type' => 'transfer_post_decision_tracking',
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'current_value' => [
                'project_name' => $projectName,
                'record_type' => $recordType,
                'decision' => (string)($record['decision'] ?? $result['suggested_action'] ?? $result['decision'] ?? ''),
                'risk_level' => (string)($record['risk_level'] ?? $result['risk_level'] ?? ''),
                'readiness_stage' => (string)($readiness['stage'] ?? ''),
            ],
            'target_value' => [
                'project_name' => $projectName,
                'tracking_status' => 'pending_transfer_followup',
                'target_metric' => 'transfer_decision_closure',
                'decision_stage' => (string)($readiness['stage'] ?? ''),
                'next_action' => (string)($readiness['next_action'] ?? ''),
            ],
            'evidence' => [
                'record_type' => $recordType,
                'source_snapshot_digest' => SourceBackedExecutionIntentIdentityService::snapshotDigest('transfer_decision', [
                    'id' => (int)($record['id'] ?? 0),
                    'hotel_id' => $hotelId,
                    'record_type' => $recordType,
                    'input' => $input,
                    'result' => $result,
                    'snapshot' => $snapshot,
                    'decision' => (string)($record['decision'] ?? ''),
                    'risk_level' => (string)($record['risk_level'] ?? ''),
                    'source_date' => (string)($record['source_date'] ?? ''),
                ]),
                'readiness_stage' => (string)($readiness['stage'] ?? ''),
                'readiness_score' => (int)($readiness['score'] ?? 0),
                'source_scope' => (string)($readiness['source_scope'] ?? ''),
                'missing_evidence' => array_values((array)($readiness['missing_evidence'] ?? [])),
                'decision' => (string)($record['decision'] ?? ''),
                'source_date' => (string)($record['source_date'] ?? ''),
                'scope_notice' => 'Transfer decision evidence is investment decision scope; OTA evidence remains channel-scope unless backed by full hotel operating data.',
            ],
            'expected_metric' => 'transfer_decision_closure',
            'expected_delta' => 0,
            'risk_level' => $this->executionRiskLevel((string)($record['risk_level'] ?? ''), (string)($record['decision'] ?? '')),
            'status' => 'pending_approval',
        ];
    }

    /** @return array<string,mixed> raw, locked transfer_records row */
    public function lockExecutionTrackingSource(int $id, array $hotelIds, int $expectedHotelId): array
    {
        $this->ensureTable();

        return Db::transaction(function () use ($id, $hotelIds, $expectedHotelId): array {
            $hotelIds = $this->normalizeTransferHotelIds($hotelIds);
            if ($expectedHotelId <= 0 || !in_array($expectedHotelId, $hotelIds, true)) {
                throw new RuntimeException(self::TRANSFER_RECORD_SCOPE_MISSING);
            }

            // Stable order for all bridge writers: lock current hotel identity, then source row.
            $hotel = $this->lockedHotelIdentity($expectedHotelId, false);
            $row = $this->currentTenantTransferRecordQuery([$expectedHotelId], $id)->lock(true)->find();
            if (!is_array($row)
                || (int)($row['id'] ?? 0) !== $id
                || (int)($row['hotel_id'] ?? 0) !== $expectedHotelId
                || (int)($row['tenant_id'] ?? 0) !== (int)$hotel['tenant_id']
            ) {
                throw new RuntimeException(self::TRANSFER_RECORD_SCOPE_MISSING);
            }

            return $row;
        });
    }

    public function attachExecutionTracking(int $id, array $hotelIds, int $userId, bool $isSuperAdmin, array $tracking): array
    {
        $this->ensureTable();
        $intentId = (int)($tracking['execution_intent_id'] ?? $tracking['id'] ?? 0);
        if ($intentId <= 0) {
            throw new InvalidArgumentException('execution_intent_id is required');
        }

        $trackingHotelId = (int)($tracking['hotel_id'] ?? 0);
        if ($trackingHotelId <= 0) {
            throw new InvalidArgumentException('hotel_id is required for execution tracking');
        }

        return Db::transaction(function () use (
            $id,
            $hotelIds,
            $intentId,
            $tracking,
            $trackingHotelId
        ): array {
            // The source row lock serializes JSON-array appends and prevents lost updates.
            $row = $this->lockExecutionTrackingSource($id, $hotelIds, $trackingHotelId);
            $result = $this->decodeJson($row['result_json'] ?? '');
            $now = date('Y-m-d H:i:s');
            $trackingPayload = [
                'type' => 'operation_execution_intent',
                'execution_intent_id' => $intentId,
                'hotel_id' => $trackingHotelId,
                'status' => trim((string)($tracking['status'] ?? '')),
                'source_module' => 'transfer_decision',
                'linked_at' => $now,
            ];

            $existing = $result['execution_tracking'] ?? [];
            if (!is_array($existing)) {
                $existing = [];
            }
            if ($existing !== [] && array_keys($existing) !== range(0, count($existing) - 1)) {
                $existing = [$existing];
            }
            foreach ($existing as $linked) {
                if (is_array($linked) && (int)($linked['execution_intent_id'] ?? 0) === $intentId) {
                    return $this->formatRecord($row, true);
                }
            }
            $existing[] = $trackingPayload;

            $result['execution_tracking'] = $existing;
            $result['operation_execution_intent_id'] = $intentId;
            $result['post_decision_tracking'] = [
                'status' => 'linked',
                'latest_execution_intent_id' => $intentId,
                'latest_status' => $trackingPayload['status'],
                'hotel_id' => $trackingPayload['hotel_id'],
                'linked_at' => $now,
            ];

            $affected = Db::name('transfer_records')
                ->where('id', $id)
                ->where('tenant_id', (int)$row['tenant_id'])
                ->where('hotel_id', $trackingHotelId)
                ->whereNull('deleted_at')
                ->update([
                    'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                ]);
            if ((int)$affected <= 0) {
                throw new RuntimeException(self::TRANSFER_RECORD_SCOPE_MISSING);
            }

            $row['result_json'] = $result;
            $row['updated_at'] = $now;
            return $this->formatRecord($row, true);
        });
    }

    public function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->assertTransferTableColumns('transfer_records', [
            'id', 'record_type', 'tenant_id', 'hotel_id', 'hotel_name', 'source_date',
            'input_json', 'result_json', 'snapshot_json', 'decision', 'risk_level',
            'created_by', 'created_at', 'updated_at', 'deleted_at',
        ]);
        $this->assertTransferTableColumns('hotels', ['id', 'tenant_id']);
        $this->tableEnsured = true;
    }

    /** @return list<int> */
    private function normalizeTransferHotelIds(array $hotelIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $hotelId): bool => $hotelId > 0
        )));
    }

    private function currentTenantTransferRecordQuery(array $hotelIds, ?int $recordId = null): mixed
    {
        $query = Db::name('transfer_records')
            ->alias('transfer_record')
            ->join(
                'hotels transfer_hotel',
                'transfer_hotel.id = transfer_record.hotel_id AND transfer_hotel.tenant_id = transfer_record.tenant_id'
            )
            ->whereNull('transfer_record.deleted_at')
            ->whereIn('transfer_record.hotel_id', $hotelIds)
            ->field('transfer_record.*');
        if ($recordId !== null) {
            $query->where('transfer_record.id', $recordId);
        }
        return $query;
    }

    /** @return array<string,mixed> */
    private function lockedHotelIdentity(int $hotelId, bool $markSourceRead = true): array
    {
        if ($hotelId <= 0) {
            throw new RuntimeException('Hotel scope is missing');
        }
        $this->assertTransferTableColumns('hotels', ['id', 'tenant_id']);

        try {
            $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
        } catch (Throwable $exception) {
            throw new RuntimeException('transfer_source_read_failed:hotels', 503, $exception);
        }
        if (!is_array($hotel) || (int)($hotel['id'] ?? 0) !== $hotelId) {
            throw new RuntimeException('Hotel scope is missing');
        }
        if ((int)($hotel['tenant_id'] ?? 0) <= 0) {
            throw new RuntimeException('Hotel tenant scope is missing');
        }
        if ($markSourceRead) {
            $this->markSourceReadSuccess('hotels', 1);
        }
        return $hotel;
    }

    /** @param list<string> $path */

    private function transferRecordMigrationRequired(Throwable $exception): RuntimeException
    {
        return new RuntimeException(self::TRANSFER_RECORD_MIGRATION_REQUIRED, 503, $exception);
    }

    /** @param list<string> $requiredColumns */
    private function assertTransferTableColumns(string $table, array $requiredColumns): void
    {
        try {
            // Force a live schema read. Long-running workers must not authorize
            // tenant-scoped queries with ThinkORM's cached pre-migration shape.
            $schema = Db::connect()->getSchemaInfo($table, true);
            $actualColumns = array_map('strval', (array)($schema['fields'] ?? []));
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf(
                'Database schema upgrade required: table "%s" is unavailable; run php think db:migrate.',
                $table
            ), 0, $exception);
        }

        $missing = array_values(array_diff($requiredColumns, $actualColumns));
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Database schema upgrade required: table "%s" is missing columns [%s]; run php think db:migrate.',
                $table,
                implode(', ', $missing)
            ));
        }
    }

    private function readinessPricingResult(string $recordType, array $input, array $result): array
    {
        if ($recordType === 'pricing') {
            return $result;
        }
        if (is_array($input['pricing'] ?? null)) {
            return $input['pricing'];
        }
        if (is_array($result['pricing'] ?? null)) {
            return $result['pricing'];
        }
        return [];
    }

    private function readinessTimingResult(string $recordType, array $input, array $result): array
    {
        if ($recordType === 'timing') {
            return $result;
        }
        if (is_array($input['timing'] ?? null)) {
            return $input['timing'];
        }
        if (is_array($result['timing'] ?? null)) {
            return $result['timing'];
        }
        return [];
    }

    private function snapshotHasSourceRecords(array $snapshot): bool
    {
        $counts = is_array($snapshot['source_counts'] ?? null) ? $snapshot['source_counts'] : [];
        foreach (['daily_reports', 'online_daily_data', 'annual_daily_reports', 'annual_online_daily_data'] as $key) {
            if ((int)($counts[$key] ?? 0) > 0) {
                return true;
            }
        }
        return (int)($snapshot['current']['actual_days'] ?? 0) > 0;
    }

    private function sourceScopeFromSnapshot(array $sourceCounts): string
    {
        $hasDaily = (int)($sourceCounts['daily_reports'] ?? 0) > 0 || (int)($sourceCounts['annual_daily_reports'] ?? 0) > 0;
        $hasOta = (int)($sourceCounts['online_daily_data'] ?? 0) > 0 || (int)($sourceCounts['annual_online_daily_data'] ?? 0) > 0;
        if ($hasDaily && $hasOta) {
            return 'daily_reports_and_ota_snapshot';
        }
        if ($hasDaily) {
            return 'daily_reports_snapshot';
        }
        if ($hasOta) {
            return 'ota_channel_snapshot';
        }
        return 'manual_input_only';
    }

    private function readinessSourceEvidence(array $snapshot, array $sourceCounts): string
    {
        if (!$this->snapshotHasSourceRecords($snapshot)) {
            return '暂无可追溯的来源记录快照';
        }

        $parts = [];
        foreach (['daily_reports', 'online_daily_data', 'annual_daily_reports', 'annual_online_daily_data'] as $key) {
            $count = (int)($sourceCounts[$key] ?? 0);
            if ($count > 0) {
                $parts[] = $key . '=' . $count;
            }
        }
        $date = trim((string)($snapshot['source_date'] ?? ''));
        return ($date !== '' ? '取数日 ' . $date . '；' : '') . ($parts ? implode('；', $parts) : '已读取本地记录；来源真实性待核验');
    }

    private function transferCostInputsReady(array $input, array $pricingResult): bool
    {
        $required = [
            ['room_count', 'basic_info.room_count'],
            ['monthly_revenue', 'profit.monthly_revenue'],
            ['monthly_rent', 'costs.monthly_rent'],
            ['labor_cost', 'costs.labor_cost'],
            ['expected_transfer_price', 'valuation.expected_transfer_price'],
        ];
        foreach ($required as [$inputKey, $resultPath]) {
            if (!$this->hasPositiveReadinessValue($input[$inputKey] ?? null)
                && !$this->hasPositiveReadinessValue($this->readPath($pricingResult, $resultPath))) {
                return false;
            }
        }

        return true;
    }

    private function leaseLicenseInputsReady(array $input): bool
    {
        $leaseReady = $this->hasPositiveReadinessValue($input['remaining_lease_months'] ?? null);
        $licenseValue = $input['licenses_complete'] ?? null;
        return $leaseReady && $this->nullableBool($licenseValue) === true;
    }

    private function hasReadinessDataIssue(array $pricingInput, array $timingInput, array $pricingResult, array $timingResult, array $snapshot): bool
    {
        if ($this->nullableBool($pricingInput['has_data_anomaly'] ?? null) === true
            || $this->nullableBool($timingInput['has_data_anomaly'] ?? null) === true
            || $this->nullableBool($timingInput['has_data_gap'] ?? null) === true) {
            return true;
        }
        if ($this->nullableBool($snapshot['current']['has_data_anomaly'] ?? null) === true) {
            return true;
        }
        if (($pricingResult['data_quality']['has_data_anomaly'] ?? null) === true) {
            return true;
        }
        $quality = is_array($timingResult['data_quality'] ?? null) ? $timingResult['data_quality'] : [];
        return $this->nullableBool($quality['has_data_anomaly'] ?? null) === true
            || $this->nullableBool($quality['has_data_gap'] ?? null) === true
            || $this->nullableBool($quality['suspected_collection_anomaly'] ?? null) === true;
    }

    private function hasDiligenceEvidence(array $input, array $result, array $snapshot): bool
    {
        foreach ([$input, $result, $snapshot] as $payload) {
            foreach (['diligence_evidence', 'evidence_files', 'attachments', 'lease_contract_evidence', 'license_evidence', 'bank_flow_evidence'] as $key) {
                if (!array_key_exists($key, $payload)) {
                    continue;
                }
                $value = $payload[$key];
                if (is_array($value) && !empty($value)) {
                    return true;
                }
                if (trim((string)$value) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    private function hasHumanReviewApproval(array $input, array $result): bool
    {
        foreach ([$input, $result] as $payload) {
            foreach (['review_status', 'approval_status', 'decision_status', 'manual_review_status'] as $key) {
                $value = strtolower(trim((string)($payload[$key] ?? '')));
                if (in_array($value, ['approved', 'reviewed', 'passed', '通过', '已复核', '已审批'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function hasPostDecisionTracking(array $input, array $result): bool
    {
        return SourceBackedExecutionBridgeProjectionService::hasProjectedTracking($input)
            || SourceBackedExecutionBridgeProjectionService::hasProjectedTracking($result);
    }

    private function decisionReadinessStage(
        bool $pricingReady,
        bool $timingReady,
        bool $dashboardReady,
        bool $sourceLoaded,
        bool $dataQualityClear,
        bool $leaseLicenseReady,
        bool $diligenceEvidenceReady,
        bool $humanReviewReady,
        bool $postDecisionTrackingReady
    ): string {
        if (!$pricingReady && !$timingReady && !$dashboardReady) {
            return 'calculation_missing';
        }
        if (!$sourceLoaded) {
            return 'manual_input_only';
        }
        if (!$pricingReady || !$timingReady || !$dashboardReady) {
            return 'partial_calculation';
        }
        if (!$dataQualityClear) {
            return 'data_recheck_required';
        }
        if (!$leaseLicenseReady || !$diligenceEvidenceReady) {
            return 'diligence_required';
        }
        if (!$humanReviewReady) {
            return 'review_ready';
        }
        if (!$postDecisionTrackingReady) {
            return 'approved_pending_tracking';
        }
        return 'decision_ready';
    }

    private function decisionReadinessStageLabel(string $stage): string
    {
        return [
            'calculation_missing' => '未形成测算',
            'manual_input_only' => '仅手工测算',
            'partial_calculation' => '测算未完整',
            'data_recheck_required' => '需复核数据',
            'diligence_required' => '需补尽调证据',
            'review_ready' => '可进入人工复核',
            'approved_pending_tracking' => '已审批待跟踪',
            'decision_ready' => '投决闭环就绪',
        ][$stage] ?? $stage;
    }

    private function decisionReadinessNotice(string $stage): string
    {
        return [
            'calculation_missing' => '当前还没有可复核的资产定价、时机或决策看板结果。',
            'manual_input_only' => '当前只有手工输入或测算结果，不能作为来源背书的投决依据。',
            'partial_calculation' => '已接入真实快照，但定价、时机或决策看板尚未完整汇总。',
            'data_recheck_required' => '存在数据异常、断档或口径冲突，需先复核再进入投决。',
            'diligence_required' => '测算链路已基本形成，但缺少租约、证照、流水等尽调证据。',
            'review_ready' => '核心测算和来源已具备，可进入人工复核；尚不能视为已审批。',
            'approved_pending_tracking' => '已有人审痕迹，但还缺投后执行或跟踪记录。',
            'decision_ready' => '已有来源、测算、人审和跟踪证据，可视为投决闭环就绪。',
        ][$stage] ?? '';
    }

    private function readinessCheck(string $key, string $label, bool $passed, string $evidence, string $nextAction, int $weight): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'status' => $passed ? 'ok' : 'missing',
            'evidence' => $evidence,
            'next_action' => $nextAction,
            'weight' => $weight,
        ];
    }

    private function readPath(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function hasPositiveReadinessValue(mixed $value): bool
    {
        return is_numeric($value) && (float)$value > 0;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $text = strtolower(trim((string)$value));
        if (in_array($text, ['1', 'true', 'yes', 'on', '是', '有', '齐全', '完整'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off', '否', '无', '不齐全', '缺失'], true)) {
            return false;
        }
        return null;
    }

    private function normalizePricingAiEvaluation(mixed $raw, array $defaults = []): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        $decisionQualityContext = is_array($defaults['decision_quality_context'] ?? null)
            ? $defaults['decision_quality_context']
            : $this->transferDecisionQualityContext([], []);
        $recommendations = $this->decisionQualityService->enrichRecommendations(
            $this->normalizeAiRecommendationItems($raw['recommendations'] ?? []),
            $decisionQualityContext
        );

        return [
            'source' => trim((string)($raw['source'] ?? $defaults['source'] ?? '')),
            'model_key' => trim((string)($raw['model_key'] ?? $raw['modelKey'] ?? $defaults['model_key'] ?? '')),
            'generated_at' => trim((string)($raw['generated_at'] ?? $raw['generatedAt'] ?? $defaults['generated_at'] ?? '')),
            'summary' => mb_substr(trim((string)($raw['summary'] ?? '')), 0, 300),
            'decision' => mb_substr(trim((string)($raw['decision'] ?? '')), 0, 160),
            'recommendations' => $recommendations,
            'recommendation_quality' => $this->decisionQualityService->summarize($recommendations, $decisionQualityContext),
            'watch_points' => $this->normalizeAiWatchPointItems($raw['watch_points'] ?? $raw['watchPoints'] ?? []),
            'assumptions' => $this->stringList($raw['assumptions'] ?? []),
            'error' => mb_substr(trim((string)($raw['error'] ?? '')), 0, 120),
        ];
    }

    private function normalizeAiRecommendationItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $value = trim((string)$item);
                if ($value !== '') {
                    $normalized[] = ['priority' => 'P1', 'title' => '评估建议', 'detail' => mb_substr($value, 0, 220)];
                }
                continue;
            }

            $title = trim((string)($item['title'] ?? ''));
            $detail = trim((string)($item['detail'] ?? $item['content'] ?? ''));
            if ($title === '' && $detail === '') {
                continue;
            }
            $priority = trim((string)($item['priority'] ?? 'P1'));
            if (!in_array($priority, ['P0', 'P1', 'P2'], true)) {
                $priority = 'P1';
            }
            $normalized[] = array_merge($item, [
                'priority' => $priority,
                'title' => mb_substr($title !== '' ? $title : '评估建议', 0, 60),
                'detail' => mb_substr($detail, 0, 220),
            ]);
        }

        return array_slice($normalized, 0, 6);
    }

    private function normalizeAiWatchPointItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $value = trim((string)$item);
                if ($value !== '') {
                    $normalized[] = ['metric' => '关键指标', 'threshold' => mb_substr($value, 0, 120), 'action' => '进入下一步前复核。'];
                }
                continue;
            }

            $metric = trim((string)($item['metric'] ?? ''));
            $threshold = trim((string)($item['threshold'] ?? ''));
            $action = trim((string)($item['action'] ?? ''));
            if ($metric === '' && $threshold === '' && $action === '') {
                continue;
            }
            $normalized[] = [
                'metric' => mb_substr($metric !== '' ? $metric : '关键指标', 0, 80),
                'threshold' => mb_substr($threshold, 0, 160),
                'action' => mb_substr($action, 0, 200),
            ];
        }

        return array_slice($normalized, 0, 6);
    }

    /** @param array<string,mixed>|null $projectionContext */
    private function formatRecord(array $row, bool $withDetail, ?array $projectionContext = null): array
    {
        $input = $this->decodeJson($row['input_json'] ?? '');
        $result = $this->decodeJson($row['result_json'] ?? '');
        $snapshot = $this->decodeJson($row['snapshot_json'] ?? '');
        [$input, $result, $snapshot] = $this->executionBridgeProjection->trackingForResponses(
            'transfer_decision',
            [
                ['source' => $row, 'payload' => $input],
                ['source' => $row, 'payload' => $result],
                ['source' => $row, 'payload' => $snapshot],
            ],
            $projectionContext
        );
        if (is_array($result['ai_evaluation'] ?? null)) {
            $result['ai_evaluation'] = $this->normalizePricingAiEvaluation($result['ai_evaluation'], [
                'decision_quality_context' => $this->transferDecisionQualityContext($input, $result),
            ]);
        }
        $record = [
            'id' => (int)$row['id'],
            'record_type' => (string)($row['record_type'] ?? ''),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'hotel_name' => (string)($row['hotel_name'] ?? ''),
            'source_date' => (string)($row['source_date'] ?? ''),
            'decision' => (string)($row['decision'] ?? ''),
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'execution_intent_id' => (int)($result['operation_execution_intent_id'] ?? $result['execution_intent_id'] ?? 0),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'summary' => [
                'monthly_net_profit' => $result['profit']['monthly_net_profit'] ?? null,
                'reasonable_valuation' => $result['valuation']['reasonable_valuation'] ?? null,
                'timing_score' => $result['timing_score'] ?? null,
                'suggested_action' => $result['suggested_action'] ?? null,
            ],
            'decision_readiness' => $this->buildDecisionReadiness(
                (string)($row['record_type'] ?? ''),
                $input,
                $result,
                $snapshot,
                (int)($row['hotel_id'] ?? 0)
            ),
        ];

        if ($withDetail) {
            $record['input'] = $input;
            $record['result'] = $result;
            $record['snapshot'] = $snapshot;
        }

        return $record;
    }

    private function transferDecisionQualityContext(array $input, array $result): array
    {
        return [
            'scope' => 'investment_scenario',
            'hotel_id' => (int)($input['hotel_id'] ?? 0),
            'data_basis' => [
                [
                    'ref' => 'transfer_user_input_snapshot',
                    'source' => 'user_provided_transfer_inputs',
                    'scope' => 'investment_scenario',
                    'quality_status' => 'user_provided_unverified',
                    'summary' => '收入、成本、租约、报价和证照状态来自当前录入，需以原始资料复核。',
                ],
                [
                    'ref' => 'transfer_deterministic_valuation',
                    'source' => 'deterministic_valuation_and_payback_result',
                    'scope' => 'investment_scenario',
                    'quality_status' => 'derived_unverified',
                    'summary' => '估值、回本期和风险由当前输入派生，不代表已经完成尽调。',
                ],
            ],
            'basis_summary' => (string)($result['suggestion'] ?? $result['decision'] ?? ''),
            'default_risk_level' => (string)($result['risk_level'] ?? 'medium'),
            'review_window' => '进入报价或交割前，以真实流水、租约、证照、成本单据和OTA渠道样本复核',
        ];
    }

    private function executionRiskLevel(string $riskLevel, string $decision): string
    {
        $text = strtolower($riskLevel . ' ' . $decision);
        if (str_contains($text, 'high') || str_contains($riskLevel, '高') || str_contains($decision, '暂缓') || str_contains($decision, '暂不')) {
            return 'high';
        }
        if (str_contains($text, 'low') || str_contains($riskLevel, '低')) {
            return 'low';
        }
        return 'medium';
    }

    private function dailyReportRows(
        array $hotelIds,
        string $startDate,
        string $endDate,
        ?int $expectedTenantId = null
    ): array
    {
        if (empty($hotelIds)) {
            return [];
        }
        $this->assertTransferTableColumns('daily_reports', [
            'tenant_id', 'hotel_id', 'report_date',
        ]);
        $this->assertTransferTableColumns('hotels', ['id', 'tenant_id']);

        try {
            $query = Db::name('daily_reports')
                ->alias('transfer_daily')
                ->join('hotels transfer_daily_hotel', 'transfer_daily_hotel.id = transfer_daily.hotel_id')
                ->whereColumn('transfer_daily_hotel.tenant_id', 'transfer_daily.tenant_id')
                ->whereIn('transfer_daily.hotel_id', $hotelIds)
                ->whereBetween('transfer_daily.report_date', [$startDate, $endDate]);
            if ($expectedTenantId !== null) {
                $query->where('transfer_daily.tenant_id', $expectedTenantId);
            }
            $rows = $query->field('transfer_daily.*')->select()->toArray();
            $this->markSourceReadSuccess('daily_reports', count($rows));
            return $rows;
        } catch (Throwable $e) {
            throw new RuntimeException('transfer_source_read_failed:daily_reports', 503, $e);
        }
    }

    private function onlineRows(
        array $hotelIds,
        string $startDate,
        string $endDate,
        ?int $expectedTenantId = null
    ): array
    {
        if (empty($hotelIds)) {
            return [];
        }
        $this->assertTransferTableColumns('online_daily_data', [
            'tenant_id', 'system_hotel_id', 'data_date',
        ]);
        $this->assertTransferTableColumns('hotels', ['id', 'tenant_id']);

        try {
            $query = Db::name('online_daily_data')
                ->alias('transfer_online')
                ->join('hotels transfer_online_hotel', 'transfer_online_hotel.id = transfer_online.system_hotel_id')
                ->whereColumn('transfer_online_hotel.tenant_id', 'transfer_online.tenant_id')
                ->whereBetween('transfer_online.data_date', [$startDate, $endDate])
                ->whereIn('transfer_online.system_hotel_id', array_map('intval', $hotelIds));
            if ($expectedTenantId !== null) {
                $query->where('transfer_online.tenant_id', $expectedTenantId);
            }
            $rows = $query->field('transfer_online.*')->select()->toArray();
            $this->markSourceReadSuccess('online_daily_data', count($rows));
            return $rows;
        } catch (Throwable $e) {
            throw new RuntimeException('transfer_source_read_failed:online_daily_data', 503, $e);
        }
    }

    /**
     * @return array{verified_rows:array<int,array<string,mixed>>,truth_context:array<string,mixed>}
     */
    private function filterTrustedTransferOtaRows(array $rows, array $scope): array
    {
        $targetHotelId = max(0, (int)($scope['target_hotel_id'] ?? 0));
        $startDate = trim((string)($scope['start_date'] ?? ''));
        $endDate = trim((string)($scope['end_date'] ?? ''));
        $dateScopeValid = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $startDate) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $endDate) === 1
            && $startDate <= $endDate;
        $verifiedRows = [];
        $truthEnvelopes = [];
        $failureReasons = [];
        $includedRowIds = [];
        $excludedCount = 0;
        $scopeExclusionCounts = [
            'target_hotel_missing' => 0,
            'hotel_scope_mismatch' => 0,
            'target_date_range_missing_or_invalid' => 0,
            'date_scope_mismatch' => 0,
            'unsupported_ota_platform' => 0,
            'row_not_array' => 0,
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $excludedCount++;
                $scopeExclusionCounts['row_not_array']++;
                $failureReasons[] = 'row_not_array';
                continue;
            }

            $raw = $this->decodeJson($row['raw_data'] ?? '');
            $truth = OnlineDataTrustStatusService::truthEnvelope(
                $row,
                OnlineDataFieldFactService::buildStatus($row, $raw)
            );
            $rowHotelId = max(0, (int)($row['system_hotel_id'] ?? 0));
            $rowDate = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            $platform = strtolower(trim((string)($truth['platform'] ?? '')));

            $scopeFailure = '';
            if ($targetHotelId <= 0) {
                $scopeFailure = 'target_hotel_missing';
            } elseif ($rowHotelId !== $targetHotelId) {
                $scopeFailure = 'hotel_scope_mismatch';
            } elseif (!$dateScopeValid) {
                $scopeFailure = 'target_date_range_missing_or_invalid';
            } elseif ($rowDate < $startDate || $rowDate > $endDate) {
                $scopeFailure = 'date_scope_mismatch';
            } elseif (!in_array($platform, self::SUPPORTED_OTA_PLATFORMS, true)) {
                $scopeFailure = 'unsupported_ota_platform';
            }
            if ($scopeFailure !== '') {
                $excludedCount++;
                $scopeExclusionCounts[$scopeFailure]++;
                $failureReasons[] = $scopeFailure;
                continue;
            }

            $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
            if (!in_array($validationStatus, self::TRUSTED_OTA_VALIDATION_STATUSES, true)
                && ($truth['status'] ?? '') === 'verified'
            ) {
                $truth['status'] = 'unverified';
                $truth['failure_reason'] = 'validation_status_not_explicitly_trusted';
            }
            $truthEnvelopes[] = $truth;

            if (($truth['status'] ?? '') === 'verified') {
                $verifiedRows[] = $row;
                $rowId = max(0, (int)($row['id'] ?? 0));
                if ($rowId > 0) {
                    $includedRowIds[] = $rowId;
                }
                continue;
            }

            $excludedCount++;
            $reason = trim((string)($truth['failure_reason'] ?? ''));
            $failureReasons[] = $reason !== '' ? $reason : 'truth_status_' . (string)($truth['status'] ?? 'unverified');
        }

        $failureReasons = array_values(array_unique(array_filter(
            $failureReasons,
            static fn(string $reason): bool => trim($reason) !== ''
        )));
        $fallbackReason = $failureReasons !== []
            ? implode('; ', array_slice($failureReasons, 0, 4))
            : ($rows === [] ? 'ota_channel_rows_missing' : 'ota_channel_verified_rows_missing');
        $truthContext = OnlineDataTrustStatusService::summarizeTruthEnvelopes($truthEnvelopes, [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'excluded_untrusted_count' => $excludedCount,
            'fallback_failure_reason' => $fallbackReason,
        ]);
        $truthContext['source_table'] = 'online_daily_data';
        $truthContext['scope'] = [
            'target_hotel_id' => $targetHotelId > 0 ? $targetHotelId : null,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'platforms' => self::SUPPORTED_OTA_PLATFORMS,
        ];
        $truthContext['included_verified_count'] = count($verifiedRows);
        $truthContext['excluded_untrusted_count'] = $excludedCount;
        $truthContext['included_row_ids'] = array_values(array_unique($includedRowIds));
        $truthContext['failure_reasons'] = $failureReasons;
        $truthContext['scope_exclusion_counts'] = $scopeExclusionCounts;
        $truthContext['metric_caliber'] = [
            'ota_channel_revenue' => 'sum(online_daily_data.amount) over verified OTA rows',
            'ota_channel_orders' => 'sum(online_daily_data.book_order_num) over verified OTA rows',
            'ota_channel_room_nights' => 'sum(online_daily_data.quantity) over verified OTA rows',
        ];

        return [
            'verified_rows' => $verifiedRows,
            'truth_context' => $truthContext,
        ];
    }

    private function aggregateTransferMetrics(array $dailyRows, array $onlineRows, array $otaScope = []): array
    {
        $otaTruth = $this->filterTrustedTransferOtaRows($onlineRows, $otaScope);
        $onlineRows = $otaTruth['verified_rows'];
        $dates = [];
        $dailyDates = [];
        $onlineDates = [];
        $revenue = 0.0;
        $otaChannelRevenue = 0.0;
        $otaChannelOrders = 0;
        $otaChannelRevenueObserved = false;
        $otaChannelOrdersObserved = false;
        $roomNights = 0.0;
        $otaChannelRoomNights = 0.0;
        $otaChannelRoomNightsObserved = false;
        $roomCount = 0;
        $occValues = [];
        $ratingValues = [];
        $exposure = 0;
        $visitors = 0;
        $hotelName = '';

        foreach ($dailyRows as $row) {
            $dates[(string)$row['report_date']] = true;
            $dailyDates[(string)$row['report_date']] = true;
            $reportData = $this->decodeJson($row['report_data'] ?? '');
            $revenue += $this->extractRevenue($row, $reportData);
            $roomCount = max($roomCount, (int)$this->extractSalableRoomCount($row, $reportData));
            $roomNights += $this->extractRoomNights($row, $reportData);
            $occ = (float)($row['occupancy_rate'] ?? $reportData['occ'] ?? $reportData['occupancy_rate'] ?? 0);
            if ($occ > 0) {
                $occValues[] = $occ > 1 ? $occ : $occ * 100;
            }
        }

        foreach ($onlineRows as $row) {
            $dates[(string)$row['data_date']] = true;
            $onlineDates[(string)$row['data_date']] = true;
            $raw = $this->decodeJson($row['raw_data'] ?? '');
            $hotelName = $hotelName ?: (string)($row['hotel_name'] ?? '');
            $orderValue = $row['book_order_num'] ?? $raw['bookOrderNum'] ?? $raw['orders'] ?? null;
            if (is_numeric($orderValue)) {
                $otaChannelOrdersObserved = true;
                $otaChannelOrders += (int)$orderValue;
            }
            $revenueValue = $row['amount'] ?? $raw['amount'] ?? $raw['revenue'] ?? null;
            if (is_numeric($revenueValue)) {
                $otaChannelRevenueObserved = true;
                $otaChannelRevenue += (float)$revenueValue;
            }
            $roomNightValue = $row['quantity'] ?? $row['data_value'] ?? $raw['roomNights'] ?? null;
            if (is_numeric($roomNightValue)) {
                $otaChannelRoomNightsObserved = true;
                $otaChannelRoomNights += (float)$roomNightValue;
            }
            $exposure += (int)($raw['exposure'] ?? $raw['showNum'] ?? $raw['impression'] ?? 0);
            $visitors += (int)($raw['visitors'] ?? $raw['visitorNum'] ?? $raw['qunarDetailVisitors'] ?? $raw['totalDetailNum'] ?? 0);
            $score = (float)($row['comment_score'] ?? $row['qunar_comment_score'] ?? $raw['rating'] ?? $raw['score'] ?? 0);
            if ($score > 0) {
                $ratingValues[] = $score;
            }
        }

        $actualDays = count($dates);
        $adr = $roomNights > 0 ? $revenue / $roomNights : 0;
        $occupancyRate = $this->avg($occValues);
        if ($occupancyRate <= 0 && $roomCount > 0 && $roomNights > 0 && $actualDays > 0) {
            $occupancyRate = $roomNights / ($roomCount * $actualDays) * 100;
        }

        $conversionRate = $visitors > 0 ? $otaChannelOrders / $visitors * 100 : 0;

        return [
            'hotel_name' => $hotelName,
            'actual_days' => $actualDays,
            'daily_report_days' => count($dailyDates),
            'ota_channel_days' => count($onlineDates),
            'revenue' => round($revenue, 2),
            'ota_channel_revenue' => round($otaChannelRevenue, 2),
            'ota_channel_revenue_observed' => $otaChannelRevenueObserved,
            'orders' => $otaChannelOrders,
            'ota_channel_orders' => $otaChannelOrders,
            'ota_channel_orders_observed' => $otaChannelOrdersObserved,
            'room_nights' => round($roomNights, 2),
            'ota_channel_room_nights' => round($otaChannelRoomNights, 2),
            'ota_channel_room_nights_observed' => $otaChannelRoomNightsObserved,
            'room_count' => $roomCount,
            'adr' => round($adr, 2),
            'occupancy_rate' => round($occupancyRate, 2),
            'rating' => $this->avg($ratingValues),
            'exposure' => $exposure,
            'visitors' => $visitors,
            'conversion_rate' => round($conversionRate, 2),
            'has_data_anomaly' => $otaChannelOrders > 0 && $exposure <= 0 && $visitors <= 0,
            'ota_scope' => 'ota_channel',
            'truth_context' => $otaTruth['truth_context'],
            'scope_note' => 'revenue/room_nights 仅汇总本地经营日报；ota_channel_* 仅汇总OTA渠道记录，两者不得互相替代。',
        ];
    }

    private function annualThirtyDayBenchmark(array $annual): array
    {
        $actualDays = (int)($annual['actual_days'] ?? 0);
        $scale = $actualDays > 0 ? 30 / $actualDays : 0;

        return [
            'actual_days' => $actualDays,
            'revenue' => round((float)($annual['revenue'] ?? 0) * $scale, 2),
            'orders' => (int)round((float)($annual['orders'] ?? 0) * $scale),
            'adr' => round((float)($annual['adr'] ?? 0), 2),
            'occupancy_rate' => round((float)($annual['occupancy_rate'] ?? 0), 2),
        ];
    }

    private function extractRevenue(array $row, array $reportData): float
    {
        foreach (['revenue', 'day_revenue', 'room_revenue', 'ctrip_revenue', 'meituan_revenue'] as $key) {
            $value = $row[$key] ?? $reportData[$key] ?? null;
            if (is_numeric($value) && (float)$value > 0) {
                return (float)$value;
            }
        }
        return $this->sumReportFields($reportData, [
            'xb_revenue', 'mt_revenue', 'fliggy_revenue', 'dy_revenue', 'tc_revenue', 'qn_revenue', 'zx_revenue',
            'booking_revenue', 'agoda_revenue', 'expedia_revenue',
            'walkin_revenue', 'member_exp_revenue', 'web_exp_revenue', 'group_revenue', 'protocol_revenue', 'wechat_revenue',
            'free_revenue', 'gold_card_revenue', 'black_gold_revenue', 'hourly_revenue',
            'parking_revenue', 'dining_revenue', 'meeting_revenue', 'goods_revenue', 'member_card_revenue', 'other_revenue',
        ]);
    }

    private function extractRoomNights(array $row, array $reportData): float
    {
        foreach (['room_nights', 'occupied_rooms', 'day_total_rooms', 'total_rooms'] as $key) {
            $value = $reportData[$key] ?? null;
            if (is_numeric($value) && (float)$value > 0) {
                return (float)$value;
            }
        }

        $rooms = $this->sumReportFields($reportData, [
            'xb_rooms', 'mt_rooms', 'fliggy_rooms', 'dy_rooms', 'tc_rooms', 'qn_rooms', 'zx_rooms',
            'booking_rooms', 'agoda_rooms', 'expedia_rooms',
            'walkin_rooms', 'member_exp_rooms', 'web_exp_rooms', 'group_rooms', 'protocol_rooms', 'wechat_rooms',
            'free_rooms', 'gold_card_rooms', 'black_gold_rooms', 'hourly_rooms',
        ]);
        if ($rooms > 0) {
            return $rooms;
        }

        return (float)($row['guest_count'] ?? 0);
    }

    private function extractSalableRoomCount(array $row, array $reportData): float
    {
        foreach ([
            $row['room_count'] ?? null,
            $reportData['salable_rooms'] ?? null,
            $reportData['salable_rooms_total'] ?? null,
            $reportData['total_rooms_count'] ?? null,
            $reportData['room_count'] ?? null,
            $reportData['rooms_total'] ?? null,
        ] as $value) {
            if (is_numeric($value) && (float)$value > 0) {
                return (float)$value;
            }
        }
        return 0.0;
    }

    private function sumReportFields(array $reportData, array $fields): float
    {
        $total = 0.0;
        foreach ($fields as $field) {
            $total += (float)($reportData[$field] ?? 0);
        }
        return $total;
    }

    private function stringList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $list[] = mb_substr($value, 0, 220);
            }
        }
        return array_values(array_unique($list));
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function markSourceReadSuccess(string $table, int $rowCount): void
    {
        $status = $this->sourceReadStatus[$table] ?? [
            'table_status' => 'available',
            'read_status' => 'pending',
            'query_count' => 0,
            'row_count' => 0,
        ];
        $status['read_status'] = 'ok';
        $status['query_count'] = (int)($status['query_count'] ?? 0) + 1;
        $status['row_count'] = (int)($status['row_count'] ?? 0) + max(0, $rowCount);
        $this->sourceReadStatus[$table] = $status;
    }

    private function avg(array $values): float
    {
        $values = array_values(array_filter($values, static fn($value): bool => is_numeric($value) && (float)$value > 0));
        return $values ? round(array_sum($values) / count($values), 2) : 0.0;
    }

}
