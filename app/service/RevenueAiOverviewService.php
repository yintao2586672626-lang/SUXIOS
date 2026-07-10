<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

class RevenueAiOverviewService
{
    private const CHANNELS = ['ctrip', 'meituan'];
    private const TEMPORARY_PRICING_SKIP_REASON = 'missing_pricing_inputs_skipped_by_operator_policy';
    private const TEMPORARY_SKIPPABLE_PRICING_INPUT_CODES = [
        'room_types_enabled',
        'floor_price_or_min_rate_guard',
        'demand_forecast',
        'competitor_price_samples',
        'pricing_candidate_signal',
    ];
    private const TEMPORARY_SKIPPABLE_PRICING_REASONS = [
        self::TEMPORARY_PRICING_SKIP_REASON,
        'room_types_empty',
        'pricing_candidate_signals_missing',
        'floor_price_missing',
        'competitor_price_fields_missing',
        'demand_forecasts_not_loaded',
        'demand_forecasts_empty',
        'demand_forecasts_metric_missing',
    ];
    private const CTRIP_COMPETITOR_PLATFORM_VALUES = [1, '1', 'ctrip'];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters = []): array
    {
        $businessDate = $this->businessDate($filters['business_date'] ?? null);
        $hotelId = $this->hotelId($filters['hotel_id'] ?? $filters['system_hotel_id'] ?? null);
        $hotelIds = $this->hotelIds($filters['permitted_hotel_ids'] ?? []);
        $enabledChannels = $this->enabledChannels($filters['enabled_channels'] ?? null);
        if ($enabledChannels === []) {
            $enabledChannels = $this->enabledChannels($filters['platform'] ?? $filters['channel'] ?? null);
        }
        $channels = $enabledChannels !== [] ? $enabledChannels : self::CHANNELS;
        if ($hotelId !== null && $hotelIds !== [] && !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('hotel_id is outside permitted scope', 403);
        }
        $etl = new OtaStandardEtlService();
        $baseFilters = [
            'start_date' => $businessDate,
            'end_date' => $businessDate,
            'limit' => 5000,
        ];
        if ($hotelId !== null) {
            $baseFilters['system_hotel_id'] = $hotelId;
        }

        $channelDatasets = [];
        foreach ($channels as $channel) {
            $channelDatasets[$channel] = $etl->buildDataset(array_merge($baseFilters, ['source' => $channel]));
        }
        $dataset = $this->mergeChannelDatasets($channelDatasets);
        $reviewQueue = $this->priceSuggestionReviewQueue($businessDate, $hotelId);
        $pricingGenerationPreflight = $this->pricingGenerationPreflight($businessDate, $hotelId, $hotelIds, $dataset, $channels);
        $agentActivity = $this->agentActivity($businessDate, $hotelId);
        $executionSummary = $this->executionSummary($businessDate, $hotelId, $hotelIds);

        $context = [
                'business_date' => $businessDate,
                'hotel_id' => $hotelId,
                'enabled_channels' => $enabledChannels,
                'require_p0_downstream_gate' => true,
                'review_queue' => $reviewQueue,
                'pricing_generation_preflight' => $pricingGenerationPreflight,
                'agent_activity' => $agentActivity,
                'execution_summary' => $executionSummary,
        ];
        if (is_array($filters['p0_downstream_gate'] ?? null)) {
            $context['p0_downstream_gate'] = $filters['p0_downstream_gate'];
        }

        return $this->buildOverviewFromDataset(
            $dataset,
            $channelDatasets,
            $this->sourceStatusRows($hotelId),
            $context
        );
    }

    /**
     * @param array<string, array<string, mixed>> $channelDatasets
     * @return array<string, mixed>
     */
    private function mergeChannelDatasets(array $channelDatasets): array
    {
        $merged = [
            'status' => 'empty',
            'dim_hotel' => [],
            'dim_platform' => [],
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_peer_rank' => [],
            'fact_ota_traffic_analysis' => [],
            'fact_ota_traffic_forecast' => [],
            'fact_ota_comment' => [],
            'data_quality' => [
                'input_rows' => 0,
                'accepted_rows' => 0,
                'rejected_rows' => [],
            ],
        ];
        $hotelKeys = [];
        $platformKeys = [];
        foreach (self::CHANNELS as $channel) {
            $dataset = is_array($channelDatasets[$channel] ?? null) ? $channelDatasets[$channel] : [];
            foreach ($this->list($dataset['dim_hotel'] ?? []) as $hotel) {
                $key = (string)($hotel['hotel_key'] ?? json_encode($hotel));
                if (!isset($hotelKeys[$key])) {
                    $hotelKeys[$key] = true;
                    $merged['dim_hotel'][] = $hotel;
                }
            }
            foreach ($this->list($dataset['dim_platform'] ?? []) as $platform) {
                $key = (string)($platform['platform_key'] ?? '');
                if ($key !== '' && !isset($platformKeys[$key])) {
                    $platformKeys[$key] = true;
                    $merged['dim_platform'][] = $platform;
                }
            }
            foreach ([
                'fact_ota_daily',
                'fact_ota_traffic',
                'fact_ota_advertising',
                'fact_ota_quality',
                'fact_ota_search_keyword',
                'fact_ota_peer_rank',
                'fact_ota_traffic_analysis',
                'fact_ota_traffic_forecast',
                'fact_ota_comment',
            ] as $factKey) {
                $merged[$factKey] = array_merge($merged[$factKey], $this->list($dataset[$factKey] ?? []));
            }
            $quality = is_array($dataset['data_quality'] ?? null) ? $dataset['data_quality'] : [];
            $merged['data_quality']['input_rows'] += (int)($quality['input_rows'] ?? 0);
            $merged['data_quality']['accepted_rows'] += (int)($quality['accepted_rows'] ?? 0);
            $merged['data_quality']['rejected_rows'] = array_merge(
                $merged['data_quality']['rejected_rows'],
                $this->list($quality['rejected_rows'] ?? [])
            );
        }
        $acceptedCount = 0;
        foreach ([
            'fact_ota_daily',
            'fact_ota_traffic',
            'fact_ota_advertising',
            'fact_ota_quality',
            'fact_ota_search_keyword',
            'fact_ota_peer_rank',
            'fact_ota_traffic_analysis',
            'fact_ota_traffic_forecast',
            'fact_ota_comment',
        ] as $factKey) {
            $acceptedCount += count($merged[$factKey]);
        }
        $merged['status'] = $acceptedCount > 0 ? 'ready' : 'empty';
        return $merged;
    }

    /**
     * Public pure builder so tests can cover metric boundaries without a database.
     *
     * @param array<string, mixed> $dataset
     * @param array<string, array<string, mixed>> $channelDatasets
     * @param array<string, array<string, mixed>> $sourceStatuses
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function buildOverviewFromDataset(array $dataset, array $channelDatasets = [], array $sourceStatuses = [], array $context = []): array
    {
        $businessDate = $this->businessDate($context['business_date'] ?? null);
        $hotelId = $this->hotelId($context['hotel_id'] ?? null);
        $enabledChannels = $this->enabledChannels($context['enabled_channels'] ?? null);
        $p0GateService = new P0OtaDownstreamGateService();
        if (is_array($context['p0_downstream_gate'] ?? null)) {
            $dataset['p0_downstream_gate'] = $p0GateService->normalize(
                $context['p0_downstream_gate'],
                $businessDate,
                $hotelId,
                $enabledChannels
            );
        } elseif ($this->boolValue($context['require_p0_downstream_gate'] ?? false)) {
            $dataset['p0_downstream_gate'] = $p0GateService->blockedForDataset(
                $businessDate,
                $hotelId,
                $dataset,
                $enabledChannels
            );
        }
        if (!is_array($dataset['collection_quality'] ?? null)
            && !is_array($dataset['quality'] ?? null)
            && is_array($dataset['p0_downstream_gate'] ?? null)) {
            $dataset['collection_quality'] = $p0GateService->collectionQuality($dataset['p0_downstream_gate']);
        }
        $metricsSummary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $dailyFacts = $this->list($dataset['fact_ota_daily'] ?? []);
        $actualScopedSourceChannels = $this->sourceChannels($dataset, $channelDatasets);
        if ($enabledChannels !== []) {
            $actualScopedSourceChannels = array_values(array_intersect($actualScopedSourceChannels, $enabledChannels));
        }
        $displaySourceChannels = $this->displaySourceChannels($actualScopedSourceChannels, $enabledChannels);
        $lastSuccessAt = $this->lastSuccessAt($dataset, $sourceStatuses, $enabledChannels);
        $channelStatuses = $this->channelStatuses($channelDatasets, $sourceStatuses, $businessDate, $enabledChannels);
        $missingDatasets = $this->missingDatasets($actualScopedSourceChannels, $channelDatasets, $metricsSummary, $channelStatuses, $enabledChannels);
        $qualityIssues = $this->qualityIssues($dataset, $metricsSummary, $sourceStatuses, $channelStatuses);
        $completeness = $this->dataCompleteness($dataset, $actualScopedSourceChannels, $missingDatasets, $qualityIssues, $enabledChannels);
        $dataStatus = $this->overviewDataStatus($dataset, $actualScopedSourceChannels, $sourceStatuses, $missingDatasets, $qualityIssues, $channelStatuses, $enabledChannels);
        $marketSignals = is_array($context['market_signals'] ?? null) ? $context['market_signals'] : [];
        $signals = $this->signals($metricsSummary, $actualScopedSourceChannels, $marketSignals, $businessDate, $hotelId);
        $reviewQueue = is_array($context['review_queue'] ?? null)
            ? $context['review_queue']
            : $this->priceSuggestionReviewQueueUnavailable($businessDate, $hotelId, 'not_loaded', 'manual_review_workflow_not_connected');
        $pricingGenerationPreflight = is_array($context['pricing_generation_preflight'] ?? null)
            ? $context['pricing_generation_preflight']
            : $this->pricingGenerationPreflightUnavailable($businessDate, $hotelId, [], $actualScopedSourceChannels, 'not_loaded', 'price_suggestion_generation_not_loaded');
        $pricingGenerationPreflight = $this->applyPricingTemporarySkipPolicyToPreflight($pricingGenerationPreflight);
        $agentActivity = is_array($context['agent_activity'] ?? null)
            ? $context['agent_activity']
            : $this->agentActivityUnavailable($businessDate, $hotelId, 'not_loaded', 'agent_logs_not_loaded');
        $executionSummary = is_array($context['execution_summary'] ?? null)
            ? $context['execution_summary']
            : $this->executionSummaryUnavailable($businessDate, $hotelId, 'not_loaded', 'operation_execution_not_loaded');
        $pricingReadiness = $this->pricingReadiness(
            $metricsSummary,
            $missingDatasets,
            $qualityIssues,
            $signals,
            $reviewQueue,
            $executionSummary,
            $displaySourceChannels,
            $pricingGenerationPreflight
        );
        $pricingReadiness['ai_to_operation_handoff'] = $this->pricingAiToOperationHandoff($pricingReadiness, $executionSummary, $businessDate, $hotelId, $displaySourceChannels);
        $pricingReadiness['operation_to_investment_handoff'] = $this->pricingOperationToInvestmentHandoff(
            $pricingReadiness['ai_to_operation_handoff'],
            $executionSummary,
            $businessDate,
            $hotelId,
            $displaySourceChannels
        );
        $dailyMetricStatus = $dataStatus === 'empty_confirmed' ? 'empty_confirmed' : 'empty';
        $dailyMetricReason = $dataStatus === 'empty_confirmed' ? 'ZERO_CONFIRMED' : 'online_daily_data_empty';

        $metricContext = [
            'source_channels' => $displaySourceChannels,
            'last_success_at' => $lastSuccessAt,
        ];
        $metricContext['date_basis'] = 'data_date';
        $metricContext['scope'] = 'ota';

        return [
            'data_status' => $dataStatus,
            'scope' => 'ota',
            'date_basis' => 'data_date',
            'date_basis_note' => 'Phase 1A 使用 online_daily_data.data_date；尚未等同于入住日 stay_date、下单日 booking_date 或结算日 settlement_date。',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'source_channels' => $displaySourceChannels,
            'actual_source_channels' => $actualScopedSourceChannels,
            'source_channel_policy' => 'source_channels follows requested OTA scope; actual_source_channels contains channels with target-scope facts.',
            'last_success_at' => $lastSuccessAt,
            'missing_datasets' => $missingDatasets,
            'quality_issues' => $qualityIssues,
            'issue_summary' => $this->issueSummary($missingDatasets, $qualityIssues),
            'channel_statuses' => $channelStatuses,
            'data_completeness' => $completeness,
            'metrics' => [
                'ota_room_revenue' => $this->metric(
                    'ota_room_revenue',
                    '昨日OTA房费收入',
                    $dailyFacts !== [] ? $this->numeric($metricsSummary['totals']['room_revenue'] ?? null) : null,
                    'CNY',
                    $dailyFacts !== [] ? 'ok' : $dailyMetricStatus,
                    $dailyFacts !== [] ? '' : $dailyMetricReason,
                    $metricContext,
                    'money'
                ),
                'ota_room_nights' => $this->metric(
                    'ota_room_nights',
                    '昨日OTA间夜',
                    $dailyFacts !== [] ? $this->numeric($metricsSummary['totals']['room_nights'] ?? null) : null,
                    'room_nights',
                    $dailyFacts !== [] ? 'ok' : $dailyMetricStatus,
                    $dailyFacts !== [] ? '' : $dailyMetricReason,
                    $metricContext,
                    'number'
                ),
                'ota_adr' => $this->metric(
                    'ota_adr',
                    'OTA ADR',
                    $this->numeric($metricsSummary['totals']['adr'] ?? null),
                    'CNY',
                    $this->numeric($metricsSummary['totals']['adr'] ?? null) !== null ? 'ok' : ($dataStatus === 'empty_confirmed' ? 'empty_confirmed' : 'not_calculable'),
                    $this->numeric($metricsSummary['totals']['adr'] ?? null) !== null ? '' : ($dataStatus === 'empty_confirmed' ? 'ZERO_CONFIRMED' : 'adr_denominator_zero'),
                    $metricContext,
                    'money'
                ),
                'ota_contribution_revpar' => $this->metric(
                    'ota_contribution_revpar',
                    'OTA贡献RevPAR',
                    $this->numeric($metricsSummary['totals']['revpar'] ?? null),
                    'CNY',
                    $this->numeric($metricsSummary['totals']['revpar'] ?? null) !== null ? 'ok' : ($dataStatus === 'empty_confirmed' ? 'empty_confirmed' : 'not_calculable'),
                    $this->numeric($metricsSummary['totals']['revpar'] ?? null) !== null ? '' : ($dataStatus === 'empty_confirmed' ? 'ZERO_CONFIRMED' : 'available_room_nights_missing'),
                    array_merge($metricContext, [
                        'scope' => $this->numeric($metricsSummary['totals']['revpar'] ?? null) !== null ? 'hotel' : 'hotel_required',
                    ]),
                    'money'
                ),
                'data_completeness' => $this->metric(
                    'data_completeness',
                    '数据完整度',
                    (float)$completeness['percent'],
                    '%',
                    $completeness['status'],
                    (string)($completeness['reason'] ?? ''),
                    $metricContext,
                    'percent'
                ),
            ],
            'signals' => $signals,
            'p1_revenue_closure' => $metricsSummary['p1_revenue_closure'] ?? [],
            'pricing_readiness' => $pricingReadiness,
            'p0_downstream_gate' => $metricsSummary['credibility_gate']['evidence']['p0_downstream_gate'] ?? [],
            'review_queue' => $reviewQueue,
            'pricing_generation_preflight' => $pricingGenerationPreflight,
            'agent_activity' => $agentActivity,
            'execution_summary' => $executionSummary,
            'ai_to_operation_handoff' => $pricingReadiness['ai_to_operation_handoff'],
            'operation_to_investment_handoff' => $pricingReadiness['operation_to_investment_handoff'],
            'actions' => $this->actions($missingDatasets, $qualityIssues, $pricingReadiness, $reviewQueue, $pricingGenerationPreflight),
            'metric_summary' => [
                'fact_table' => $metricsSummary['fact_table'] ?? [],
                'credibility_gate' => $metricsSummary['credibility_gate'] ?? [],
                'p1_revenue_closure' => $metricsSummary['p1_revenue_closure'] ?? [],
                'data_gaps' => $metricsSummary['data_gaps'] ?? [],
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param mixed $value
     */
    private function businessDate(mixed $value): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return date('Y-m-d', strtotime('-1 day'));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            throw new RuntimeException('Invalid business_date, expected YYYY-MM-DD', 422);
        }
        return $text;
    }

    /**
     * @param mixed $value
     */
    private function hotelId(mixed $value): ?int
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (!ctype_digit($text) || (int)$text <= 0) {
            throw new RuntimeException('Invalid hotel_id, expected positive integer', 422);
        }
        return (int)$text;
    }

    /**
     * @return array<int, int>
     */
    private function hotelIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '' && ctype_digit($text) && (int)$text > 0) {
                $ids[] = (int)$text;
            }
        }

        return array_values(array_unique($ids));
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, array<string, mixed>> $channelDatasets
     * @return array<int, string>
     */
    private function sourceChannels(array $dataset, array $channelDatasets): array
    {
        $channels = [];
        foreach ($this->list($dataset['dim_platform'] ?? []) as $platform) {
            $key = strtolower(trim((string)($platform['platform_key'] ?? '')));
            if (in_array($key, self::CHANNELS, true)) {
                $channels[] = $key;
            }
        }
        foreach (self::CHANNELS as $channel) {
            if (($channelDatasets[$channel]['status'] ?? '') !== 'empty') {
                $channels[] = $channel;
            }
        }
        return array_values(array_unique($channels));
    }

    /**
     * Keep the requested OTA scope visible even when same-day facts are missing.
     *
     * @param array<int, string> $actualSourceChannels
     * @param array<int, string> $enabledChannels
     * @return array<int, string>
     */
    private function displaySourceChannels(array $actualSourceChannels, array $enabledChannels): array
    {
        if ($enabledChannels !== []) {
            return array_values(array_unique($enabledChannels));
        }
        return array_values(array_unique($actualSourceChannels));
    }

    /**
     * @return array<int, string>
     */
    private function enabledChannels(mixed $value): array
    {
        if (!is_array($value)) {
            $value = is_string($value) ? preg_split('/[,|]/', $value) : [];
        }
        $channels = [];
        foreach ($value as $channel) {
            $key = strtolower(trim((string)$channel));
            if (in_array($key, self::CHANNELS, true)) {
                $channels[] = $key;
            }
        }
        return array_values(array_unique($channels));
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, array<string, mixed>> $sourceStatuses
     */
    private function lastSuccessAt(array $dataset, array $sourceStatuses, array $enabledChannels = []): ?string
    {
        $candidates = [];
        foreach ([
            'fact_ota_daily',
            'fact_ota_traffic',
            'fact_ota_advertising',
            'fact_ota_quality',
            'fact_ota_search_keyword',
            'fact_ota_peer_rank',
            'fact_ota_traffic_analysis',
            'fact_ota_traffic_forecast',
            'fact_ota_comment',
        ] as $factKey) {
            foreach ($this->list($dataset[$factKey] ?? []) as $row) {
                $trace = is_array($row['source_trace'] ?? null) ? $row['source_trace'] : [];
                if (($trace['saved_success'] ?? false) !== true) {
                    continue;
                }
                $time = trim((string)($trace['updated_at'] ?? ''));
                if ($time !== '') {
                    $candidates[] = $time;
                }
            }
        }
        foreach ($sourceStatuses as $channel => $row) {
            if ($enabledChannels !== []) {
                $sourceKey = is_string($channel)
                    ? strtolower(trim($channel))
                    : strtolower(trim((string)($row['platform_key'] ?? $row['platform'] ?? $row['channel'] ?? $row['source'] ?? '')));
                if (!in_array($sourceKey, $enabledChannels, true)) {
                    continue;
                }
            }
            $status = strtolower(trim((string)($row['last_sync_status'] ?? $row['status'] ?? '')));
            $time = trim((string)($row['last_sync_time'] ?? $row['update_time'] ?? ''));
            if (in_array($status, ['success', 'ok', 'ready', 'empty_confirmed', 'zero_confirmed', 'no_data'], true) && $time !== '') {
                $candidates[] = $time;
            }
        }
        rsort($candidates);
        return $candidates[0] ?? null;
    }

    /**
     * @param array<int, string> $sourceChannels
     * @param array<string, array<string, mixed>> $channelDatasets
     * @param array<string, mixed> $metricsSummary
     * @return array<int, array<string, mixed>>
     */
    private function missingDatasets(array $sourceChannels, array $channelDatasets, array $metricsSummary, array $channelStatuses = [], array $enabledChannels = []): array
    {
        $missing = [];
        $channels = $enabledChannels !== [] ? $enabledChannels : self::CHANNELS;
        foreach ($channels as $channel) {
            if (!in_array($channel, $sourceChannels, true)) {
                $channelStatus = is_array($channelStatuses[$channel] ?? null) ? $channelStatuses[$channel] : [];
                $emptyConfirmed = ($channelStatus['status'] ?? '') === 'empty_confirmed';
                $missing[] = [
                    'key' => $channel . '_business',
                    'channel' => $channel,
                    'label' => $this->channelLabel($channel) . '经营数据',
                    'status' => $emptyConfirmed ? 'empty_confirmed' : 'missing',
                    'reason' => $emptyConfirmed
                        ? 'ZERO_CONFIRMED'
                        : ((($channelDatasets[$channel]['status'] ?? '') === 'empty') ? 'online_daily_data_empty' : 'source_not_loaded'),
                ];
            }
        }
        $gapCodes = array_column($this->list($metricsSummary['data_gaps'] ?? []), 'code');
        if (in_array('available_room_nights_missing', $gapCodes, true)) {
            $missing[] = [
                'key' => 'available_room_nights',
                'channel' => 'hotel',
                'label' => '全酒店可售房晚',
                'status' => 'missing',
                'reason' => 'available_room_nights_missing',
            ];
        }
        return $this->uniqueIssueRows($this->enrichIssueRows($missing, 'missing_dataset'));
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $metricsSummary
     * @param array<string, array<string, mixed>> $sourceStatuses
     * @return array<int, array<string, mixed>>
     */
    private function qualityIssues(array $dataset, array $metricsSummary, array $sourceStatuses, array $channelStatuses = []): array
    {
        $issues = [];
        foreach ($this->list($dataset['data_quality']['rejected_rows'] ?? []) as $row) {
            $reason = trim((string)($row['reason'] ?? 'row_rejected'));
            $issues[] = [
                'key' => 'etl_' . $reason,
                'status' => 'failed',
                'reason' => $reason,
                'message' => $this->issueMessage($reason),
            ];
        }
        foreach ($this->list($metricsSummary['data_gaps'] ?? []) as $gap) {
            $code = trim((string)($gap['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $issues[] = [
                'key' => $code,
                'status' => 'missing',
                'reason' => $code,
                'message' => trim((string)($gap['message'] ?? '')),
            ];
        }
        $statusRows = $channelStatuses !== [] ? $channelStatuses : array_map(fn(array $row): array => $this->mapSourceStatus($row), $sourceStatuses);
        foreach ($statusRows as $channel => $mapped) {
            if (in_array($mapped['status'], ['failed', 'unauthorized', 'stale'], true)
                || in_array($mapped['reason'], ['DATE_NOT_AVAILABLE', 'source_status_missing', 'source_status_unknown'], true)) {
                $issues[] = [
                    'key' => $channel . '_' . $mapped['reason'],
                    'channel' => $channel,
                    'status' => $mapped['status'],
                    'reason' => $mapped['reason'],
                    'message' => $mapped['detail'] ?? '',
                ];
            }
        }
        return $this->uniqueIssueRows($this->enrichIssueRows($issues, 'quality_issue'));
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<int, string> $sourceChannels
     * @param array<int, array<string, mixed>> $missingDatasets
     * @param array<int, array<string, mixed>> $qualityIssues
     * @return array<string, mixed>
     */
    private function dataCompleteness(array $dataset, array $sourceChannels, array $missingDatasets, array $qualityIssues, array $enabledChannels = []): array
    {
        $quality = is_array($dataset['data_quality'] ?? null) ? $dataset['data_quality'] : [];
        $inputRows = (int)($quality['input_rows'] ?? 0);
        $acceptedRows = (int)($quality['accepted_rows'] ?? 0);
        $rowPercent = $inputRows > 0 ? (int)round($acceptedRows / max(1, $inputRows) * 100) : 0;
        $expectedChannelCount = max(1, count($enabledChannels !== [] ? $enabledChannels : self::CHANNELS));
        $channelPercent = (int)round(count($sourceChannels) / $expectedChannelCount * 100);
        $percent = max(0, min(100, (int)round(($rowPercent + $channelPercent) / 2)));
        if ($inputRows <= 0) {
            $percent = $channelPercent > 0 ? min(50, $channelPercent) : 0;
        }
        $status = $percent >= 90 && $missingDatasets === [] && $qualityIssues === [] ? 'ok' : ($percent > 0 ? 'partial' : 'unknown');
        return [
            'percent' => $percent,
            'display' => $percent . '%',
            'status' => $status,
            'reason' => $status === 'ok' ? '' : ($missingDatasets[0]['reason'] ?? $qualityIssues[0]['reason'] ?? 'data_not_complete'),
            'input_rows' => $inputRows,
            'accepted_rows' => $acceptedRows,
        ];
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<int, string> $sourceChannels
     * @param array<string, array<string, mixed>> $sourceStatuses
     * @param array<int, array<string, mixed>> $missingDatasets
     * @param array<int, array<string, mixed>> $qualityIssues
     */
    private function overviewDataStatus(array $dataset, array $sourceChannels, array $sourceStatuses, array $missingDatasets, array $qualityIssues, array $channelStatuses = [], array $enabledChannels = []): string
    {
        $expectedChannelCount = max(1, count($enabledChannels !== [] ? $enabledChannels : self::CHANNELS));
        $mappedStatuses = $channelStatuses !== []
            ? array_map(fn(array $row): string => (string)($row['status'] ?? 'unknown'), $channelStatuses)
            : array_map(fn(array $row): string => $this->mapSourceStatus($row)['status'], $sourceStatuses);
        if (in_array('unauthorized', $mappedStatuses, true)) {
            return 'unauthorized';
        }
        if (in_array('stale', $mappedStatuses, true) && ($dataset['status'] ?? '') === 'empty') {
            return 'stale';
        }
        if (($dataset['status'] ?? '') === 'empty' && $sourceChannels === []) {
            if ($mappedStatuses !== [] && count($mappedStatuses) === count(array_filter($mappedStatuses, fn(string $status): bool => $status === 'empty_confirmed'))) {
                return 'empty_confirmed';
            }
            return in_array('failed', $mappedStatuses, true) ? 'failed' : 'unknown';
        }
        if (in_array('failed', $mappedStatuses, true)) {
            return 'failed';
        }
        if ($missingDatasets !== [] || $qualityIssues !== [] || count($sourceChannels) < $expectedChannelCount) {
            return 'partial';
        }
        return 'ok';
    }

    /**
     * @param array<string, array<string, mixed>> $channelDatasets
     * @param array<string, array<string, mixed>> $sourceStatuses
     * @return array<string, array<string, mixed>>
     */
    private function channelStatuses(array $channelDatasets, array $sourceStatuses, string $businessDate, array $enabledChannels = []): array
    {
        $statuses = [];
        $channels = $enabledChannels !== [] ? $enabledChannels : self::CHANNELS;
        foreach ($channels as $channel) {
            $dataset = is_array($channelDatasets[$channel] ?? null) ? $channelDatasets[$channel] : [];
            $mapped = $this->mapSourceStatus($sourceStatuses[$channel] ?? []);
            $hasRows = ($dataset['status'] ?? '') !== 'empty';
            if ($hasRows && in_array($mapped['status'], ['unknown', 'stale'], true)) {
                $mapped['status'] = 'ok';
                $mapped['label'] = '有当日数据';
                $mapped['reason'] = '';
                $mapped['detail'] = 'online_daily_data has accepted rows for ' . $businessDate . '.';
            } elseif (!$hasRows && in_array($mapped['status'], ['ok', 'partial', 'empty_confirmed'], true)) {
                if ($this->isBeforeBusinessDate($mapped['last_success_at'] ?? null, $businessDate)) {
                    $mapped['status'] = 'stale';
                    $mapped['label'] = '数据过期';
                    $mapped['reason'] = 'DATA_STALE';
                    $mapped['detail'] = 'last successful sync is earlier than target business_date and online_daily_data has no accepted rows for ' . $businessDate . '.';
                } elseif ($mapped['status'] !== 'empty_confirmed') {
                    $mapped['status'] = 'unknown';
                    $mapped['label'] = '未命中目标日期';
                    $mapped['reason'] = 'DATE_NOT_AVAILABLE';
                    $mapped['detail'] = 'platform_data_sources reports sync evidence, but online_daily_data has no accepted rows for ' . $businessDate . '.';
                }
            }
            $statuses[$channel] = array_merge($mapped, [
                'channel' => $channel,
                'channel_label' => $this->channelLabel($channel),
                'business_date' => $businessDate,
                'has_target_date_rows' => $hasRows,
            ]);
        }
        return $statuses;
    }

    /**
     * @param mixed $value
     */
    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function metric(string $key, string $label, ?float $value, string $unit, string $status, string $reason, array $context, string $format): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'display' => $value === null ? '--' : $this->displayValue($value, $format),
            'unit' => $unit,
            'scope' => $context['scope'],
            'date_basis' => $context['date_basis'],
            'source_channels' => $context['source_channels'],
            'last_success_at' => $context['last_success_at'],
            'status' => $status,
            'reason' => $reason,
            'display_reason' => $reason === '' ? '数据已命中当前口径。' : $this->issueReasonMeta($reason, '', 'metric')['display_reason'],
            'next_action' => $reason === '' ? '复核数据健康面板。' : $this->issueReasonMeta($reason, '', 'metric')['next_action'],
            'target_page' => 'online-data',
            'target_tab' => 'data-health',
        ];
    }

    private function displayValue(float $value, string $format): string
    {
        if ($format === 'money') {
            return '¥' . number_format($value, 2);
        }
        if ($format === 'percent') {
            return number_format($value, 0) . '%';
        }
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    /**
     * @param array<string, mixed> $metricsSummary
     * @param array<int, string> $sourceChannels
     * @return array<string, array<string, mixed>>
     */
    private function signals(
        array $metricsSummary,
        array $sourceChannels,
        array $marketSignals = [],
        string $businessDate = '',
        ?int $hotelId = null
    ): array
    {
        $competitorSignal = $this->competitorPriceSignal($metricsSummary, $sourceChannels);
        $holidaySignal = is_array($marketSignals['holiday_event'] ?? null)
            ? $marketSignals['holiday_event']
            : $this->holidayEventSignalUnavailable($businessDate, $hotelId, 'not_loaded', 'holiday_signal_not_loaded');
        $demandSignal = is_array($marketSignals['demand_7d'] ?? null)
            ? $marketSignals['demand_7d']
            : $this->demandForecastSignalUnavailable($businessDate, $hotelId, 'not_loaded', 'demand_forecasts_not_loaded');

        return [
            'holiday_event' => $holidaySignal,
            'demand_7d' => $demandSignal,
            'competitor_price_warning' => $competitorSignal,
            'pricing_advice' => [
                'label' => '今日调价建议',
                'value' => '--',
                'status' => 'blocked',
                'reason' => 'phase1a_readonly_no_pricing_model',
                'detail' => 'Phase 1A 只做调价前置条件检查，未生成可审核建议。',
                'scope' => 'hotel',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metricsSummary
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function competitorPriceSignal(array $metricsSummary, array $sourceChannels): array
    {
        $summary = is_array($metricsSummary['competitor_price'] ?? null) ? $metricsSummary['competitor_price'] : [];
        $rows = (int)($summary['rows'] ?? 0);
        $avgOurPrice = $this->numeric($summary['avg_our_price'] ?? null);
        $avgCompetitorPrice = $this->numeric($summary['avg_competitor_price'] ?? null);
        $avgPriceGap = $this->numeric($summary['avg_price_gap'] ?? null);
        if ($rows <= 0 || $avgOurPrice === null || $avgCompetitorPrice === null) {
            return [
                'label' => '竞对价格倒挂预警',
                'value' => '--',
                'status' => 'not_loaded',
                'reason' => 'competitor_price_fields_missing',
                'scope' => 'ota',
                'source_channels' => $sourceChannels,
                'detail_metrics' => [
                    'sample_rows' => $rows,
                    'avg_our_price' => $avgOurPrice,
                    'avg_competitor_price' => $avgCompetitorPrice,
                    'avg_price_gap' => $avgPriceGap,
                    'avg_price_gap_rate' => $this->numeric($summary['avg_price_gap_rate'] ?? null),
                ],
            ];
        }

        if ($avgPriceGap === null) {
            $avgPriceGap = round($avgOurPrice - $avgCompetitorPrice, 2);
        }
        $avgPriceGapRate = $this->numeric($summary['avg_price_gap_rate'] ?? null);
        if ($avgPriceGapRate === null && $avgCompetitorPrice > 0) {
            $avgPriceGapRate = round($avgPriceGap / $avgCompetitorPrice * 100, 2);
        }

        if (abs($avgPriceGap) < 0.01) {
            $value = '接近竞对均价';
            $status = 'ok';
            $reason = 'competitor_price_aligned';
        } elseif ($avgPriceGap > 0) {
            $value = '本店高于竞对 ¥' . number_format(abs($avgPriceGap), 2);
            $status = 'warning';
            $reason = 'competitor_price_above_competitor';
        } else {
            $value = '本店低于竞对 ¥' . number_format(abs($avgPriceGap), 2);
            $status = 'partial';
            $reason = 'competitor_price_below_competitor_review_required';
        }

        return [
            'label' => '竞对价格倒挂预警',
            'value' => $value,
            'status' => $status,
            'reason' => $reason,
            'scope' => 'ota',
            'source_channels' => $sourceChannels,
            'detail_metrics' => [
                'sample_rows' => $rows,
                'avg_our_price' => round($avgOurPrice, 2),
                'avg_competitor_price' => round($avgCompetitorPrice, 2),
                'avg_price_gap' => round($avgPriceGap, 2),
                'avg_price_gap_rate' => $avgPriceGapRate,
            ],
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, array<string, mixed>>
     */
    private function marketSignals(string $businessDate, ?int $hotelId, array $hotelIds): array
    {
        return [
            'holiday_event' => $this->buildHolidayEventSignal($this->holidayCalendar((int)date('Y')), date('Y-m-d'), $businessDate, $hotelId),
            'demand_7d' => $this->demandForecastSignal($businessDate, $hotelId, $hotelIds),
        ];
    }

    /**
     * @param array<int, array<string, string>> $calendar
     * @return array<string, mixed>
     */
    public function buildHolidayEventSignal(array $calendar, string $analysisDate, string $businessDate = '', ?int $hotelId = null): array
    {
        $analysisDate = $this->validDateOrToday($analysisDate);
        $businessDate = $businessDate !== '' ? $businessDate : $analysisDate;
        $calendar = array_values(array_filter($calendar, static function (array $row): bool {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($row['start_date'] ?? '')) === 1
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($row['end_date'] ?? '')) === 1;
        }));
        usort($calendar, static fn(array $a, array $b): int => strcmp((string)$a['start_date'], (string)$b['start_date']));
        if ($calendar === []) {
            return $this->holidayEventSignalUnavailable($businessDate, $hotelId, 'not_loaded', 'holiday_calendar_missing');
        }

        $nearest = null;
        foreach ($calendar as $holiday) {
            if ((string)$holiday['end_date'] >= $analysisDate) {
                $nearest = $holiday;
                break;
            }
        }
        if ($nearest === null) {
            return $this->holidayEventSignalUnavailable($businessDate, $hotelId, 'not_loaded', 'holiday_calendar_missing');
        }

        $daysLeft = max(0, (int)floor((strtotime((string)$nearest['start_date']) - strtotime($analysisDate)) / 86400));
        $holidayDays = ((int)floor((strtotime((string)$nearest['end_date']) - strtotime((string)$nearest['start_date'])) / 86400)) + 1;
        $inWindow = (string)$nearest['start_date'] <= $analysisDate && (string)$nearest['end_date'] >= $analysisDate;
        if ($inWindow) {
            $status = 'warning';
            $reason = 'holiday_event_in_window';
            $value = (string)$nearest['name'] . '进行中';
            $detail = '当前处于节假日窗口，需复核库存、底价、竞对价格和渠道活动。';
        } elseif ($daysLeft <= 14) {
            $status = 'warning';
            $reason = 'holiday_event_nearby';
            $value = (string)$nearest['name'] . ' T-' . $daysLeft;
            $detail = '距离节假日小于等于 14 天，需提前复核收益策略。';
        } elseif ($daysLeft <= 30) {
            $status = 'partial';
            $reason = 'holiday_event_upcoming';
            $value = (string)$nearest['name'] . ' T-' . $daysLeft;
            $detail = '30 天内存在节假日窗口，仅作为人工调价复核信号。';
        } else {
            $status = 'ok';
            $reason = 'holiday_event_none_nearby';
            $value = '30天内暂无节假日窗口';
            $detail = '最近节假日为 ' . (string)$nearest['name'] . '，距离 ' . $daysLeft . ' 天。';
        }

        return [
            'label' => '事件/节假日影响',
            'value' => $value,
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'scope' => 'hotel',
            'source_table' => 'internal_holiday_calendar',
            'date_basis' => 'calendar_date',
            'business_date' => $businessDate,
            'analysis_date' => $analysisDate,
            'hotel_id' => $hotelId,
            'read_only' => true,
            'auto_write_ota' => false,
            'detail_metrics' => [
                'holiday_name' => (string)$nearest['name'],
                'start_date' => (string)$nearest['start_date'],
                'end_date' => (string)$nearest['end_date'],
                'days_left' => $daysLeft,
                'holiday_days' => $holidayDays,
                'in_holiday_window' => $inWindow,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function buildDemandForecastSignal(array $rows, string $startDate, string $endDate, ?int $hotelId = null): array
    {
        $startDate = $this->validDateOrToday($startDate);
        $endDate = $this->validDateOrToday($endDate);
        $items = $this->list($rows);
        if ($items === []) {
            return $this->demandForecastSignalUnavailable($startDate, $hotelId, 'not_loaded', 'demand_forecasts_empty', '', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }

        $occupancySamples = [];
        $confidenceSamples = [];
        $totalDemand = 0.0;
        $highDemandDates = [];
        $eventDrivenCount = 0;
        foreach ($items as $row) {
            $occupancy = $this->numeric($row['predicted_occupancy'] ?? null);
            $demand = $this->numeric($row['predicted_demand'] ?? null);
            $confidence = $this->numeric($row['confidence_score'] ?? null);
            if ($occupancy !== null && $occupancy > 0) {
                $occupancySamples[] = $occupancy;
                if ($occupancy >= 85) {
                    $highDemandDates[] = (string)($row['forecast_date'] ?? '');
                }
            }
            if ($demand !== null && $demand > 0) {
                $totalDemand += $demand;
            }
            if ($confidence !== null && $confidence > 0) {
                $confidenceSamples[] = $confidence;
            }
            $eventType = trim((string)($row['event_type'] ?? $row['is_event_driven'] ?? ''));
            if ($eventType !== '' && !in_array($eventType, ['0', 'none', 'NONE'], true)) {
                $eventDrivenCount++;
            }
        }

        $sampleRows = count($items);
        $avgOccupancy = $occupancySamples !== [] ? round(array_sum($occupancySamples) / count($occupancySamples), 2) : null;
        $maxOccupancy = $occupancySamples !== [] ? round(max($occupancySamples), 2) : null;
        $avgConfidence = $confidenceSamples !== [] ? round(array_sum($confidenceSamples) / count($confidenceSamples), 2) : null;
        if ($avgOccupancy === null && $totalDemand <= 0) {
            return $this->demandForecastSignalUnavailable($startDate, $hotelId, 'not_calculable', 'demand_forecasts_metric_missing', '', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'sample_rows' => $sampleRows,
            ]);
        }

        $highDemandCount = count(array_filter($highDemandDates));
        if ($avgConfidence !== null && $avgConfidence < 0.6) {
            $status = 'partial';
            $reason = 'demand_forecasts_low_confidence';
        } elseif ($highDemandCount > 0) {
            $status = 'warning';
            $reason = 'demand_forecasts_high_demand';
        } else {
            $status = 'ok';
            $reason = 'demand_forecasts_available';
        }

        $value = $highDemandCount > 0
            ? '高需求 ' . $highDemandCount . '天'
            : ($totalDemand > 0 ? '未来需求 ' . (int)round($totalDemand) . '间夜' : '平均入住 ' . number_format((float)$avgOccupancy, 1) . '%');
        $detail = '读取 demand_forecasts 未来 7 天 ' . $sampleRows . ' 条预测；'
            . ($avgOccupancy !== null ? '平均入住 ' . number_format($avgOccupancy, 1) . '%；' : '')
            . ($totalDemand > 0 ? '预测需求 ' . (int)round($totalDemand) . ' 间夜；' : '')
            . '仅作为人工复核信号。';

        return [
            'label' => '未来7天需求信号',
            'value' => $value,
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'scope' => 'hotel',
            'source_table' => 'demand_forecasts',
            'date_basis' => 'forecast_date',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'hotel_id' => $hotelId,
            'read_only' => true,
            'auto_write_ota' => false,
            'detail_metrics' => [
                'sample_rows' => $sampleRows,
                'avg_predicted_occupancy' => $avgOccupancy,
                'max_predicted_occupancy' => $maxOccupancy,
                'total_predicted_demand' => round($totalDemand, 2),
                'avg_confidence' => $avgConfidence,
                'high_demand_dates' => array_values(array_filter($highDemandDates)),
                'event_driven_count' => $eventDrivenCount,
            ],
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function demandForecastSignal(string $businessDate, ?int $hotelId, array $hotelIds): array
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
        if (!$this->tableExists('demand_forecasts')) {
            return $this->demandForecastSignalUnavailable($businessDate, $hotelId, 'missing', 'demand_forecasts_missing', '', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }

        $columns = $this->tableColumns('demand_forecasts');
        $missingRequired = array_values(array_filter(['hotel_id', 'forecast_date'], static fn(string $field): bool => !isset($columns[$field])));
        if ($missingRequired !== []) {
            return $this->demandForecastSignalUnavailable(
                $businessDate,
                $hotelId,
                'failed',
                'demand_forecasts_required_fields_missing',
                'Missing required fields: ' . implode(', ', $missingRequired),
                ['missing_fields' => $missingRequired, 'start_date' => $startDate, 'end_date' => $endDate]
            );
        }
        if (!isset($columns['predicted_occupancy']) && !isset($columns['predicted_demand'])) {
            return $this->demandForecastSignalUnavailable(
                $businessDate,
                $hotelId,
                'failed',
                'demand_forecasts_metric_fields_missing',
                'Missing predicted_occupancy or predicted_demand',
                ['missing_fields' => ['predicted_occupancy_or_predicted_demand'], 'start_date' => $startDate, 'end_date' => $endDate]
            );
        }

        $fields = array_values(array_intersect([
            'id',
            'hotel_id',
            'room_type_id',
            'forecast_date',
            'predicted_occupancy',
            'predicted_demand',
            'confidence_score',
            'event_type',
            'is_event_driven',
            'create_time',
            'update_time',
        ], array_keys($columns)));

        try {
            $query = Db::name('demand_forecasts')
                ->field(implode(',', $fields))
                ->whereBetween('forecast_date', [$startDate, $endDate])
                ->order('forecast_date', 'asc');
            if ($hotelId !== null) {
                $query->where('hotel_id', $hotelId);
            } elseif ($hotelIds !== []) {
                $query->whereIn('hotel_id', $hotelIds);
            }
            $rows = $query->limit(1000)->select()->toArray();
        } catch (\Throwable) {
            return $this->demandForecastSignalUnavailable($businessDate, $hotelId, 'failed', 'demand_forecasts_read_failed', '', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }

        return $this->buildDemandForecastSignal($rows, $startDate, $endDate, $hotelId);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function demandForecastSignalUnavailable(
        string $businessDate,
        ?int $hotelId,
        string $status,
        string $reason,
        string $detail = '',
        array $extra = []
    ): array {
        return array_merge([
            'label' => '未来7天需求信号',
            'value' => '--',
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail !== '' ? $detail : $this->issueReasonMeta($reason, 'hotel', 'demand_signal')['display_reason'],
            'scope' => 'hotel',
            'source_table' => 'demand_forecasts',
            'date_basis' => 'forecast_date',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'read_only' => true,
            'auto_write_ota' => false,
            'detail_metrics' => [
                'sample_rows' => (int)($extra['sample_rows'] ?? 0),
            ],
        ], $extra);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function holidayEventSignalUnavailable(
        string $businessDate,
        ?int $hotelId,
        string $status,
        string $reason,
        string $detail = '',
        array $extra = []
    ): array {
        return array_merge([
            'label' => '事件/节假日影响',
            'value' => '--',
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail !== '' ? $detail : $this->issueReasonMeta($reason, 'hotel', 'holiday_signal')['display_reason'],
            'scope' => 'hotel',
            'source_table' => 'internal_holiday_calendar',
            'date_basis' => 'calendar_date',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'read_only' => true,
            'auto_write_ota' => false,
            'detail_metrics' => [],
        ], $extra);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function holidayCalendar(int $year): array
    {
        $map = [
            2026 => [
                ['name' => '元旦', 'start_date' => '2026-01-01', 'end_date' => '2026-01-03'],
                ['name' => '春节', 'start_date' => '2026-02-15', 'end_date' => '2026-02-23'],
                ['name' => '清明节', 'start_date' => '2026-04-04', 'end_date' => '2026-04-06'],
                ['name' => '劳动节', 'start_date' => '2026-05-01', 'end_date' => '2026-05-05'],
                ['name' => '端午节', 'start_date' => '2026-06-19', 'end_date' => '2026-06-21'],
                ['name' => '中秋节', 'start_date' => '2026-09-25', 'end_date' => '2026-09-27'],
                ['name' => '国庆节', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
            ],
        ];

        return $map[$year] ?? [];
    }

    private function validDateOrToday(string $value): string
    {
        $text = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text;
        }

        return date('Y-m-d');
    }

    /**
     * Pure builder for the local manual pricing review queue.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function buildPriceSuggestionReviewQueue(array $rows, string $businessDate, ?int $hotelId = null): array
    {
        $items = $this->list($rows);
        $counts = [
            'pending_count' => 0,
            'approved_count' => 0,
            'rejected_count' => 0,
            'applied_count' => 0,
            'expired_count' => 0,
            'unknown_count' => 0,
        ];
        $latestCandidates = [];
        $pendingIds = [];
        $pendingItems = [];
        $recentItems = [];

        foreach ($items as $row) {
            $status = (int)($row['status'] ?? 0);
            $reviewItem = $this->buildPriceSuggestionReviewItem($row);
            if (count($recentItems) < 5) {
                $recentItems[] = $reviewItem;
            }
            if ($status === 1) {
                $counts['pending_count']++;
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $pendingIds[] = $id;
                }
                if (count($pendingItems) < 5) {
                    $pendingItems[] = $reviewItem;
                }
            } elseif ($status === 2) {
                $counts['approved_count']++;
            } elseif ($status === 3) {
                $counts['rejected_count']++;
            } elseif ($status === 4) {
                $counts['applied_count']++;
            } elseif ($status === 5) {
                $counts['expired_count']++;
            } else {
                $counts['unknown_count']++;
            }

            foreach (['update_time', 'create_time', 'applied_time'] as $timeKey) {
                $time = trim((string)($row[$timeKey] ?? ''));
                if ($time !== '') {
                    $latestCandidates[] = $time;
                    break;
                }
            }
        }

        rsort($latestCandidates);
        $totalCount = count($items);
        if ($counts['pending_count'] > 0) {
            $status = 'pending_review';
            $reason = 'price_suggestions_pending_review';
            $nextAction = '进入定价建议列表完成人工批准、修改后批准、拒绝或转执行；Revenue AI 首页不自动写 OTA。';
        } elseif ($totalCount > 0) {
            $status = 'reviewed';
            $reason = 'price_suggestions_reviewed';
            $nextAction = '复核已处理建议是否需要转执行或补充效果复盘证据。';
        } else {
            $status = 'empty';
            $reason = 'price_suggestions_empty';
            $nextAction = '暂无存量可审核建议；继续补齐需求、竞对、保护价等前置条件后再生成可审核建议。';
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'scope' => 'hotel',
            'source_table' => 'price_suggestions',
            'date_basis' => 'suggestion_date',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'total_count' => $totalCount,
            'pending_count' => $counts['pending_count'],
            'approved_count' => $counts['approved_count'],
            'rejected_count' => $counts['rejected_count'],
            'applied_count' => $counts['applied_count'],
            'expired_count' => $counts['expired_count'],
            'unknown_count' => $counts['unknown_count'],
            'approved_or_applied_count' => $counts['approved_count'] + $counts['applied_count'],
            'display' => $totalCount > 0
                ? '待审核 ' . $counts['pending_count'] . ' / 已批准 ' . $counts['approved_count'] . ' / 已拒绝 ' . $counts['rejected_count'] . ' / 已应用 ' . $counts['applied_count']
                : '暂无存量调价建议',
            'last_success_at' => $latestCandidates[0] ?? null,
            'manual_review_required' => true,
            'auto_write_ota' => false,
            'pending_ids' => array_slice($pendingIds, 0, 20),
            'pending_items' => $pendingItems,
            'recent_items' => $recentItems,
            'next_action' => $nextAction,
            'target_page' => 'agent-center',
            'target_tab' => 'suggestions',
            'target_agent_tab' => 'revenue',
            'target_revenue_tab' => 'suggestions',
            'target_filter' => [
                'hotel_id' => $hotelId ?? 0,
                'date' => $businessDate,
                'status' => $counts['pending_count'] > 0 ? 1 : 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildPriceSuggestionReviewItem(array $row): array
    {
        $statusCode = (int)($row['status'] ?? 0);
        $currentPrice = $this->positiveMoneyValue($row['current_price'] ?? null);
        $suggestedPrice = $this->positiveMoneyValue($row['suggested_price'] ?? null);
        $minPrice = $this->positiveMoneyValue($row['min_price'] ?? null);
        $maxPrice = $this->positiveMoneyValue($row['max_price'] ?? null);
        $confidence = $this->numeric($row['confidence_score'] ?? null);
        $priceChange = $currentPrice !== null && $suggestedPrice !== null
            ? round($suggestedPrice - $currentPrice, 2)
            : null;
        $priceChangeRate = $priceChange !== null && $currentPrice > 0
            ? round(($priceChange / $currentPrice) * 100, 2)
            : null;
        $reason = $this->safeLogText($row['reason'] ?? ($row['remark'] ?? ''), 120);
        $riskLevel = $this->safeLogText($row['risk_level'] ?? '', 40);
        $expectedRevparImpact = $this->priceSuggestionExpectedRevparImpact($row['factors'] ?? null);
        $missingFields = [];
        foreach ([
            'current_price' => $currentPrice,
            'suggested_price' => $suggestedPrice,
            'min_price' => $minPrice,
        ] as $field => $value) {
            if ($value === null) {
                $missingFields[] = $field;
            }
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'room_type_id' => (int)($row['room_type_id'] ?? 0),
            'demand_forecast_id' => (int)($row['demand_forecast_id'] ?? 0),
            'suggestion_type' => (int)($row['suggestion_type'] ?? 0),
            'suggestion_type_label' => $this->priceSuggestionTypeLabel((int)($row['suggestion_type'] ?? 0)),
            'status_code' => $statusCode,
            'status' => $this->priceSuggestionStatusKey($statusCode),
            'status_label' => $this->priceSuggestionStatusLabel($statusCode),
            'suggestion_date' => $this->nullableText($row['suggestion_date'] ?? null),
            'current_price' => $currentPrice,
            'current_price_display' => $this->moneyDisplay($currentPrice),
            'suggested_price' => $suggestedPrice,
            'suggested_price_display' => $this->moneyDisplay($suggestedPrice),
            'min_price' => $minPrice,
            'min_price_display' => $this->moneyDisplay($minPrice),
            'max_price' => $maxPrice,
            'max_price_display' => $this->moneyDisplay($maxPrice),
            'price_change' => $priceChange,
            'price_change_display' => $this->signedMoneyDisplay($priceChange),
            'price_change_rate' => $priceChangeRate,
            'price_change_rate_display' => $this->signedPercentDisplay($priceChangeRate),
            'confidence_score' => $confidence !== null ? round($confidence, 2) : null,
            'confidence_display' => $this->confidenceDisplay($confidence),
            'reason' => $reason !== '' ? $reason : '--',
            'risk_level' => $riskLevel !== '' ? $riskLevel : null,
            'risk_level_display' => $riskLevel !== '' ? $riskLevel : '--',
            'expected_revpar_impact' => $expectedRevparImpact['value'],
            'expected_revpar_impact_display' => $expectedRevparImpact['display'],
            'expected_revpar_impact_status' => $expectedRevparImpact['status'],
            'expected_revpar_impact_reason' => $expectedRevparImpact['reason'],
            'expected_revpar_impact_scope' => $expectedRevparImpact['scope'],
            'expected_revpar_impact_date_basis' => $expectedRevparImpact['date_basis'],
            'factors_summary' => $this->priceSuggestionFactorsSummary($row['factors'] ?? null),
            'competitor_summary' => $this->priceSuggestionCompetitorSummary($row['competitor_data'] ?? null),
            'missing_fields' => $missingFields,
            'missing_reason' => $missingFields === [] ? '' : 'price_suggestion_required_values_missing',
            'last_success_at' => $this->nullableText($row['update_time'] ?? ($row['create_time'] ?? ($row['applied_time'] ?? null))),
            'action_entry' => $this->priceSuggestionActionEntry(
                $statusCode,
                (int)($row['id'] ?? 0),
                (int)($row['hotel_id'] ?? 0),
                $this->nullableText($row['suggestion_date'] ?? null)
            ),
            'manual_review_required' => true,
            'auto_write_ota' => false,
            'read_only' => true,
            'can_review' => $statusCode === 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function priceSuggestionActionEntry(int $status, int $id, int $hotelId, ?string $date): array
    {
        $label = match ($status) {
            1 => '去审核',
            2 => '去转单',
            4 => '去复盘',
            default => '查看建议',
        };
        $manualActions = match ($status) {
            1 => ['approve', 'approve_with_changes', 'reject'],
            2 => ['create_execution_intent'],
            4 => ['effect_review'],
            default => [],
        };

        return [
            'label' => $label,
            'target_page' => 'compass',
            'target_agent_tab' => '',
            'target_revenue_tab' => '',
            'target_filter' => [
                'hotel_id' => $hotelId,
                'date' => $date,
                'status' => in_array($status, [1, 2, 3, 4, 5], true) ? $status : 0,
                'suggestion_id' => $id,
            ],
            'requires_super_admin' => false,
            'requires_hotel_permission' => true,
            'homepage_read_only' => true,
            'allowed_endpoint' => $status === 2
                ? '/api/revenue-ai/price-suggestions/' . $id . '/execution-intent'
                : ($status === 1 ? '/api/revenue-ai/price-suggestions/' . $id . '/review' : ''),
            'allowed_endpoints' => [
                'review' => '/api/revenue-ai/price-suggestions/' . $id . '/review',
                'execution_intent' => '/api/revenue-ai/price-suggestions/' . $id . '/execution-intent',
            ],
            'manual_actions' => $manualActions,
            'forbidden_actions' => ['apply_price', 'ota_write'],
            'note' => 'Revenue AI 可在酒店权限范围内人工审核和转执行，但不应用价格或写 OTA。',
        ];
    }

    private function positiveMoneyValue(mixed $value): ?float
    {
        $number = $this->numeric($value);
        return $number !== null && $number > 0 ? round($number, 2) : null;
    }

    private function moneyDisplay(?float $value): string
    {
        if ($value === null) {
            return '--';
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '元';
    }

    private function signedMoneyDisplay(?float $value): string
    {
        if ($value === null) {
            return '--';
        }
        $prefix = $value > 0 ? '+' : '';
        return $prefix . $this->moneyDisplay($value);
    }

    private function signedPercentDisplay(?float $value): string
    {
        if ($value === null) {
            return '--';
        }
        $prefix = $value > 0 ? '+' : '';
        return $prefix . rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
    }

    private function confidenceDisplay(?float $value): string
    {
        if ($value === null || $value <= 0) {
            return '--';
        }
        $percent = $value <= 1 ? $value * 100 : $value;
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.') . '%';
    }

    private function priceSuggestionStatusKey(int $status): string
    {
        return match ($status) {
            1 => 'pending_review',
            2 => 'approved',
            3 => 'rejected',
            4 => 'applied',
            5 => 'expired',
            default => 'unknown',
        };
    }

    private function priceSuggestionStatusLabel(int $status): string
    {
        return match ($status) {
            1 => '待审核',
            2 => '已批准',
            3 => '已拒绝',
            4 => '已应用',
            5 => '已过期',
            default => '未知',
        };
    }

    private function priceSuggestionTypeLabel(int $type): string
    {
        return match ($type) {
            1 => '动态定价',
            2 => '竞对跟价',
            3 => '事件驱动',
            4 => '预测驱动',
            default => '--',
        };
    }

    private function priceSuggestionFactorsSummary(mixed $value): string
    {
        $items = $this->jsonLikeArray($value);
        if ($items === []) {
            $text = is_scalar($value) ? $this->safeLogText($value, 80) : '';
            return $text !== '' ? $text : '--';
        }

        $summary = [];
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $summary[] = is_string($key)
                    ? $this->safeLogText($key, 24) . ':已记录'
                    : '因子已记录';
                continue;
            }
            $text = $this->safeLogText($item, 40);
            if ($text !== '') {
                $summary[] = is_string($key)
                    ? $this->safeLogText($key, 24) . ':' . $text
                    : $text;
            }
            if (count($summary) >= 3) {
                break;
            }
        }

        return $summary === [] ? '--' : implode(' / ', $summary);
    }

    private function priceSuggestionCompetitorSummary(mixed $value): string
    {
        $items = $this->jsonLikeArray($value);
        if ($items === []) {
            return '--';
        }

        $flat = $this->isListArray($items) && is_array($items[0] ?? null)
            ? (array)$items[0]
            : $items;
        $labels = [
            'avg_price' => '竞对均价',
            'average_price' => '竞对均价',
            'median_price' => '竞对中位价',
            'min_price' => '竞对最低价',
            'lowest_price' => '竞对最低价',
            'max_price' => '竞对最高价',
            'highest_price' => '竞对最高价',
        ];
        $summary = [];
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $flat)) {
                continue;
            }
            $valueNumber = $this->positiveMoneyValue($flat[$key]);
            if ($valueNumber !== null) {
                $summary[] = $label . ' ' . $this->moneyDisplay($valueNumber);
            }
            if (count($summary) >= 3) {
                break;
            }
        }
        if ($summary !== []) {
            return implode(' / ', $summary);
        }

        if ($this->isListArray($items)) {
            return '竞对样本 ' . count($items) . '条';
        }

        return '已存竞对数据';
    }

    /**
     * @return array{value:?float, display:string, status:string, reason:string, scope:string, date_basis:string}
     */
    private function priceSuggestionExpectedRevparImpact(mixed $value): array
    {
        $items = $this->jsonLikeArray($value);
        $candidate = $this->firstNestedValue($items, [
            ['expected_revpar_impact'],
            ['estimated_revpar_impact'],
            ['revpar_impact'],
            ['expected_impact', 'revpar'],
            ['expected_impact', 'revpar_delta'],
            ['impact', 'revpar'],
            ['impact', 'revpar_delta'],
            ['metrics', 'revpar_impact'],
            ['target', 'expected_revpar_impact'],
        ]);
        $number = $this->numericFromScalarOrArray($candidate, ['value', 'amount', 'delta', 'revpar', 'revpar_delta']);
        if ($number === null) {
            return [
                'value' => null,
                'display' => '--',
                'status' => 'not_calculable',
                'reason' => 'expected_revpar_impact_missing',
                'scope' => 'hotel_required',
                'date_basis' => 'suggestion_date',
            ];
        }

        return [
            'value' => round($number, 2),
            'display' => $this->signedMoneyDisplay(round($number, 2)),
            'status' => 'partial',
            'reason' => '',
            'scope' => 'hotel',
            'date_basis' => 'suggestion_date',
        ];
    }

    /**
     * @param array<int|string, mixed> $items
     * @param list<list<string>> $paths
     */
    private function firstNestedValue(array $items, array $paths): mixed
    {
        foreach ($paths as $path) {
            $current = $items;
            foreach ($path as $key) {
                if (!is_array($current) || !array_key_exists($key, $current)) {
                    continue 2;
                }
                $current = $current[$key];
            }
            return $current;
        }

        return null;
    }

    /**
     * @param list<string> $arrayKeys
     */
    private function numericFromScalarOrArray(mixed $value, array $arrayKeys): ?float
    {
        if (is_array($value)) {
            foreach ($arrayKeys as $key) {
                if (array_key_exists($key, $value)) {
                    $number = $this->numeric($value[$key]);
                    if ($number !== null) {
                        return $number;
                    }
                }
            }

            return null;
        }

        return $this->numeric($value);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function jsonLikeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return [];
        }
        $text = trim($value);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isListArray(array $value): bool
    {
        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index) {
                return false;
            }
            $index++;
        }
        return true;
    }

    private function priceSuggestionReviewQueue(string $businessDate, ?int $hotelId): array
    {
        if (!$this->tableExists('price_suggestions')) {
            return $this->priceSuggestionReviewQueueUnavailable($businessDate, $hotelId, 'missing', 'price_suggestions_missing');
        }

        $columns = $this->tableColumns('price_suggestions');
        $missingRequired = array_values(array_filter(['status', 'suggestion_date'], static fn(string $field): bool => !isset($columns[$field])));
        if ($missingRequired !== []) {
            return $this->priceSuggestionReviewQueueUnavailable(
                $businessDate,
                $hotelId,
                'failed',
                'price_suggestions_required_fields_missing',
                'Missing required fields: ' . implode(', ', $missingRequired),
                ['missing_fields' => $missingRequired]
            );
        }
        if ($hotelId !== null && !isset($columns['hotel_id'])) {
            return $this->priceSuggestionReviewQueueUnavailable(
                $businessDate,
                $hotelId,
                'failed',
                'price_suggestions_required_fields_missing',
                'Missing required field: hotel_id',
                ['missing_fields' => ['hotel_id']]
            );
        }

        $fields = array_values(array_intersect([
            'id',
            'hotel_id',
            'room_type_id',
            'demand_forecast_id',
            'suggestion_type',
            'status',
            'suggestion_date',
            'current_price',
            'suggested_price',
            'min_price',
            'max_price',
            'confidence_score',
            'competitor_data',
            'factors',
            'reason',
            'remark',
            'risk_level',
            'create_time',
            'update_time',
            'applied_time',
        ], array_keys($columns)));

        try {
            $query = Db::name('price_suggestions')
                ->field(implode(',', $fields))
                ->where('suggestion_date', $businessDate);
            if ($hotelId !== null) {
                $query->where('hotel_id', $hotelId);
            }
            if (isset($columns['update_time'])) {
                $query->order('update_time', 'desc');
            }
            if (isset($columns['id'])) {
                $query->order('id', 'desc');
            }
            $rows = $query->limit(1000)->select()->toArray();
        } catch (\Throwable) {
            return $this->priceSuggestionReviewQueueUnavailable($businessDate, $hotelId, 'failed', 'price_suggestions_read_failed');
        }

        return $this->buildPriceSuggestionReviewQueue($rows, $businessDate, $hotelId);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function priceSuggestionReviewQueueUnavailable(
        string $businessDate,
        ?int $hotelId,
        string $status,
        string $reason,
        string $detail = '',
        array $extra = []
    ): array {
        return array_merge([
            'status' => $status,
            'reason' => $reason,
            'scope' => 'hotel',
            'source_table' => 'price_suggestions',
            'date_basis' => 'suggestion_date',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'total_count' => 0,
            'pending_count' => 0,
            'approved_count' => 0,
            'rejected_count' => 0,
            'applied_count' => 0,
            'expired_count' => 0,
            'unknown_count' => 0,
            'approved_or_applied_count' => 0,
            'display' => '--',
            'last_success_at' => null,
            'manual_review_required' => true,
            'auto_write_ota' => false,
            'pending_ids' => [],
            'pending_items' => [],
            'recent_items' => [],
            'detail' => $detail,
            'next_action' => '检查 price_suggestions 表、字段和读取权限；缺口明确前不生成或执行调价建议。',
        ], $extra);
    }

    private function reviewQueueIsConnected(array $reviewQueue): bool
    {
        return in_array((string)($reviewQueue['status'] ?? ''), ['empty', 'pending_review', 'reviewed'], true)
            && (string)($reviewQueue['source_table'] ?? '') === 'price_suggestions';
    }

    /**
     * Read-only preflight for creating pending price suggestions. It never writes price_suggestions or OTA rates.
     *
     * @param array<int, int> $permittedHotelIds
     * @param array<string, mixed> $dataset
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function pricingGenerationPreflight(
        string $businessDate,
        ?int $hotelId,
        array $permittedHotelIds,
        array $dataset,
        array $sourceChannels
    ): array {
        $targetHotelIds = $this->pricingPreflightTargetHotelIds($hotelId, $permittedHotelIds, $dataset);
        if ($targetHotelIds === []) {
            return $this->pricingGenerationPreflightUnavailable(
                $businessDate,
                $hotelId,
                [],
                $sourceChannels,
                'blocked',
                'pricing_generation_hotel_scope_missing',
                '未能从筛选条件或 OTA 标准事实中定位系统酒店，不能生成待审调价建议。'
            );
        }

        $targetRowsByHotel = $this->pricingPreflightTargetRowsByHotel($dataset, $targetHotelIds);
        $roomTypesByHotel = [];
        $tableGaps = [];
        $roomTypeCount = 0;

        if (!$this->tableExists('room_types')) {
            $tableGaps[] = 'room_types_missing';
        } else {
            $roomTypeColumns = $this->tableColumns('room_types');
            $missingRoomTypeFields = array_values(array_filter(
                ['id', 'hotel_id', 'is_enabled'],
                static fn(string $field): bool => !isset($roomTypeColumns[$field])
            ));
            if ($missingRoomTypeFields !== []) {
                $tableGaps[] = 'room_types_required_fields_missing';
            } else {
                try {
                    $fields = array_values(array_intersect([
                        'id',
                        'hotel_id',
                        'name',
                        'base_price',
                        'min_price',
                        'max_price',
                        'room_count',
                        'is_enabled',
                        'sort_order',
                    ], array_keys($roomTypeColumns)));
                    $roomRows = Db::name('room_types')
                        ->field(implode(',', $fields))
                        ->whereIn('hotel_id', $targetHotelIds)
                        ->where('is_enabled', 1)
                        ->order('hotel_id', 'asc')
                        ->order('sort_order', 'asc')
                        ->order('id', 'asc')
                        ->limit(200)
                        ->select()
                        ->toArray();
                    foreach ($this->list($roomRows) as $row) {
                        $rowHotelId = (int)($row['hotel_id'] ?? 0);
                        if ($rowHotelId <= 0) {
                            continue;
                        }
                        $roomTypesByHotel[$rowHotelId][] = $row;
                        $roomTypeCount++;
                    }
                } catch (\Throwable) {
                    $tableGaps[] = 'room_types_read_failed';
                }
            }
        }

        $pricingService = new RevenuePricingRecommendationService();
        $hotelChecks = [];
        $pendingSuggestionCount = 0;
        $demandForecastCount = 0;
        $ctripTrafficDemandForecastCount = 0;
        $competitorAnalysisRecentCount = 0;
        $createCandidateCount = 0;
        $skippedCandidateCount = 0;
        $candidateSkipReasons = [];
        $candidateDataGaps = [];
        $requiredInputs = [];

        foreach ($targetHotelIds as $targetHotelId) {
            $roomTypes = $roomTypesByHotel[$targetHotelId] ?? [];
            $pendingCount = $this->pricingPreflightCountRows(
                'price_suggestions',
                ['hotel_id', 'suggestion_date', 'status'],
                static function ($query) use ($targetHotelId, $businessDate): void {
                    $query->where('hotel_id', $targetHotelId)
                        ->where('suggestion_date', $businessDate)
                        ->where('status', 1);
                }
            );
            if ($pendingCount === null) {
                $tableGaps[] = 'price_suggestions_read_failed';
                $pendingCount = 0;
            }

            $demandCount = $this->pricingPreflightCountRows(
                'demand_forecasts',
                ['hotel_id', 'forecast_date'],
                static function ($query) use ($targetHotelId, $businessDate): void {
                    $query->where('hotel_id', $targetHotelId)
                        ->where('forecast_date', $businessDate);
                }
            );
            if ($demandCount === null) {
                $tableGaps[] = 'demand_forecasts_read_failed';
                $demandCount = 0;
            }
            $trafficDemandForecast = [];
            $trafficDemandForecastReady = false;
            if ($demandCount <= 0 && count($sourceChannels) === 1 && $sourceChannels[0] === 'ctrip') {
                try {
                    $trafficDemandForecast = $pricingService->ctripTrafficDemandForecastSignal($targetHotelId, $businessDate);
                    $trafficDemandForecastReady = ($trafficDemandForecast['data_status'] ?? '') === 'ok';
                } catch (\Throwable) {
                    $trafficDemandForecast = ['data_status' => 'failed', 'source' => 'ctrip_historical_traffic_trend'];
                    $trafficDemandForecastReady = false;
                }
            }
            if ($demandCount <= 0 && $trafficDemandForecastReady) {
                $ctripTrafficDemandForecastCount++;
            }
            $demandCountForRequiredInput = $trafficDemandForecastReady ? 1 : $demandCount;

            $competitorStartDate = date('Y-m-d', strtotime($businessDate . ' -7 days'));
            $competitorColumns = $this->tableColumns('competitor_analysis');
            $competitorHasPlatform = isset($competitorColumns['ota_platform']);
            $competitorCount = $this->pricingPreflightCountRows(
                'competitor_analysis',
                ['hotel_id', 'analysis_date'],
                static function ($query) use ($targetHotelId, $competitorStartDate, $businessDate, $competitorHasPlatform): void {
                    $query->where('hotel_id', $targetHotelId)
                        ->whereBetween('analysis_date', [$competitorStartDate, $businessDate]);
                    if ($competitorHasPlatform) {
                        $query->whereIn('ota_platform', self::CTRIP_COMPETITOR_PLATFORM_VALUES);
                    }
                }
            );
            if ($competitorCount === null) {
                $tableGaps[] = 'competitor_analysis_read_failed';
                $competitorCount = 0;
            }

            $hotelCreateCandidates = 0;
            $hotelSkippedCandidates = 0;
            $hotelSkipReasons = [];
            foreach ($roomTypes as $roomType) {
                try {
                    $recommendation = $pricingService->recommend($targetHotelId, $roomType, $businessDate);
                } catch (\Throwable) {
                    $recommendation = [
                        'should_create' => false,
                        'skip_reason' => 'pricing_recommendation_read_failed',
                        'factors' => ['signals' => ['data_gaps' => ['pricing_recommendation_read_failed']]],
                    ];
                }
                if (($recommendation['should_create'] ?? false) === true) {
                    $hotelCreateCandidates++;
                    continue;
                }
                $hotelSkippedCandidates++;
                $skipReason = (string)($recommendation['skip_reason'] ?? 'not_created');
                if ($skipReason !== '') {
                    $hotelSkipReasons[] = $skipReason;
                    $candidateSkipReasons[] = $skipReason;
                }
                $signals = is_array($recommendation['factors']['signals'] ?? null) ? $recommendation['factors']['signals'] : [];
                foreach ((array)($signals['data_gaps'] ?? []) as $gap) {
                    $gapText = trim((string)$gap);
                    if ($gapText !== '') {
                        $candidateDataGaps[] = $gapText;
                    }
                }
            }

            $hotelRoomTypeCount = count($roomTypes);
            if ($hotelRoomTypeCount === 0) {
                $requiredInputs[] = $this->pricingPreflightRequiredInput(
                    'room_types_enabled',
                    'room_types',
                    '为携程目标酒店配置至少一个启用房型。'
                );
                $requiredInputs[] = $this->pricingPreflightRequiredInput(
                    'floor_price_or_min_rate_guard',
                    'room_types',
                    '为启用房型补齐基础价和最低保护价。'
                );
            }
            if ($demandCountForRequiredInput <= 0) {
                $requiredInputs[] = $this->pricingPreflightRequiredInput(
                    'demand_forecast',
                    'demand_forecasts',
                    '补齐目标经营日的需求预测记录。'
                );
            }
            if ($competitorCount <= 0) {
                $requiredInputs[] = $this->pricingPreflightRequiredInput(
                    'competitor_price_samples',
                    'competitor_analysis',
                    '补齐目标经营日前 7 天内的竞对价格样本。'
                );
            }

            $pendingSuggestionCount += $pendingCount;
            $demandForecastCount += $demandCount;
            $competitorAnalysisRecentCount += $competitorCount;
            $createCandidateCount += $hotelCreateCandidates;
            $skippedCandidateCount += $hotelSkippedCandidates;
            $demandForecastSource = $demandCount > 0
                ? 'demand_forecasts'
                : ($trafficDemandForecastReady ? 'ctrip_historical_traffic_trend' : 'missing');
            $hotelChecks[] = [
                'hotel_id' => $targetHotelId,
                'target_date_rows' => (int)($targetRowsByHotel[$targetHotelId] ?? 0),
                'room_type_count' => $hotelRoomTypeCount,
                'pending_suggestions' => $pendingCount,
                'demand_forecasts' => $demandCount,
                'demand_forecast_source' => $demandForecastSource,
                'ctrip_traffic_demand_forecast_status' => (string)($trafficDemandForecast['data_status'] ?? 'not_checked'),
                'ctrip_traffic_demand_primary_metric' => (string)($trafficDemandForecast['primary_metric'] ?? ''),
                'competitor_analysis_recent' => $competitorCount,
                'create_candidate_count' => $hotelCreateCandidates,
                'skipped_candidate_count' => $hotelSkippedCandidates,
                'skip_reasons' => array_values(array_unique($hotelSkipReasons)),
            ];
        }

        $tableGaps = array_values(array_unique($tableGaps));
        if ($pendingSuggestionCount > 0) {
            $status = 'pending_review_exists';
            $reason = 'price_suggestions_pending_review';
            $detail = '已存在待人工审核调价建议，先进入建议列表审核；Revenue AI 不自动写 OTA。';
        } elseif (in_array('room_types_missing', $tableGaps, true) || in_array('room_types_required_fields_missing', $tableGaps, true) || in_array('room_types_read_failed', $tableGaps, true)) {
            $status = 'failed';
            $reason = $tableGaps[0];
            $detail = '房型表不可用，不能生成待审调价建议。';
        } elseif ($roomTypeCount === 0) {
            $status = 'blocked';
            $reason = 'room_types_empty';
            $detail = '携程目标酒店暂无启用房型，生成入口会产生 0 条待审调价建议。';
        } elseif ($createCandidateCount > 0) {
            $status = 'ready_for_manual_generation';
            $reason = 'pricing_generation_candidates_ready';
            $detail = '已存在可生成待审调价建议的只读候选；仍需人工审核，不写 OTA。';
        } else {
            $status = 'blocked';
            $reason = 'pricing_candidate_signals_missing';
            $detail = '启用房型存在，但需求、竞对、价格变化或保护价信号不足，当前不会生成待审调价建议。';
            $requiredInputs[] = $this->pricingPreflightRequiredInput(
                'pricing_candidate_signal',
                'RevenuePricingRecommendationService',
                '补齐推荐模型需要的主要信号，直到只读预检出现 should_create 候选。'
            );
        }

        $requiredInputs = $this->uniqueRequiredInputs($requiredInputs);
        $temporarySkipPolicy = $this->pricingTemporarySkipPolicy($status, $reason, $requiredInputs, $tableGaps);
        if (($temporarySkipPolicy['active'] ?? false) === true) {
            $status = 'skipped_by_operator_policy';
            $reason = self::TEMPORARY_PRICING_SKIP_REASON;
            $detail = '已按人工策略暂时跳过房型、保护价、需求预测和竞对价格样本缺口；缺口仍保留为证据，不生成待审建议，不写 OTA。';
            $requiredInputs = $this->markPricingRequiredInputsSkipped($requiredInputs);
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'scope' => 'hotel',
            'source_scope' => count($sourceChannels) === 1 && $sourceChannels[0] === 'ctrip' ? 'ctrip_ota_channel' : 'ota_channel',
            'source_channels' => array_values($sourceChannels),
            'source_tables' => [
                'room_types',
                'price_suggestions',
                'demand_forecasts',
                'competitor_analysis',
                'online_daily_data',
            ],
            'date_basis' => 'suggestion_date',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'target_hotel_ids' => $targetHotelIds,
            'target_hotel_count' => count($targetHotelIds),
            'target_date_rows' => array_sum($targetRowsByHotel),
            'room_type_count' => $roomTypeCount,
            'pending_suggestion_count' => $pendingSuggestionCount,
            'demand_forecast_count' => $demandForecastCount,
            'ctrip_traffic_demand_forecast_count' => $ctripTrafficDemandForecastCount,
            'competitor_analysis_recent_count' => $competitorAnalysisRecentCount,
            'create_candidate_count' => $createCandidateCount,
            'skipped_candidate_count' => $skippedCandidateCount,
            'candidate_skip_reasons' => array_values(array_unique($candidateSkipReasons)),
            'candidate_data_gaps' => array_values(array_unique($candidateDataGaps)),
            'table_gaps' => $tableGaps,
            'required_inputs' => $requiredInputs,
            'temporary_skip_policy' => $temporarySkipPolicy,
            'hotel_checks' => $hotelChecks,
            'can_generate_pending_suggestions' => $status === 'ready_for_manual_generation',
            'manual_review_required' => true,
            'advisory_only' => true,
            'read_only' => true,
            'auto_write_ota' => false,
            'detail' => $detail,
            'next_action' => $this->pricingGenerationPreflightNextAction($reason),
            'target_page' => 'agent-center',
            'target_tab' => 'suggestions',
            'target_agent_tab' => 'revenue',
            'target_revenue_tab' => 'suggestions',
            'target_filter' => [
                'hotel_id' => count($targetHotelIds) === 1 ? $targetHotelIds[0] : ($hotelId ?? 0),
                'date' => $businessDate,
                'status' => $pendingSuggestionCount > 0 ? 1 : 0,
            ],
        ];
    }

    /**
     * @param array<int, int> $targetHotelIds
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function pricingGenerationPreflightUnavailable(
        string $businessDate,
        ?int $hotelId,
        array $targetHotelIds,
        array $sourceChannels,
        string $status,
        string $reason,
        string $detail = ''
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'scope' => 'hotel',
            'source_scope' => count($sourceChannels) === 1 && $sourceChannels[0] === 'ctrip' ? 'ctrip_ota_channel' : 'ota_channel',
            'source_channels' => array_values($sourceChannels),
            'source_tables' => ['room_types', 'price_suggestions', 'demand_forecasts', 'competitor_analysis', 'online_daily_data'],
            'date_basis' => 'suggestion_date',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'target_hotel_ids' => array_values($targetHotelIds),
            'target_hotel_count' => count($targetHotelIds),
            'target_date_rows' => 0,
            'room_type_count' => 0,
            'pending_suggestion_count' => 0,
            'demand_forecast_count' => 0,
            'competitor_analysis_recent_count' => 0,
            'create_candidate_count' => 0,
            'skipped_candidate_count' => 0,
            'candidate_skip_reasons' => [],
            'candidate_data_gaps' => [],
            'table_gaps' => [],
            'required_inputs' => [],
            'hotel_checks' => [],
            'can_generate_pending_suggestions' => false,
            'manual_review_required' => true,
            'advisory_only' => true,
            'read_only' => true,
            'auto_write_ota' => false,
            'detail' => $detail,
            'next_action' => $this->pricingGenerationPreflightNextAction($reason),
            'target_page' => 'agent-center',
            'target_tab' => 'suggestions',
            'target_agent_tab' => 'revenue',
            'target_revenue_tab' => 'suggestions',
            'target_filter' => [
                'hotel_id' => count($targetHotelIds) === 1 ? $targetHotelIds[0] : ($hotelId ?? 0),
                'date' => $businessDate,
                'status' => 0,
            ],
        ];
    }

    /**
     * @param array<int, int> $permittedHotelIds
     * @param array<string, mixed> $dataset
     * @return array<int, int>
     */
    private function pricingPreflightTargetHotelIds(?int $hotelId, array $permittedHotelIds, array $dataset): array
    {
        if ($hotelId !== null) {
            return [$hotelId];
        }

        $datasetHotelIds = [];
        foreach ($this->list($dataset['fact_ota_daily'] ?? []) as $row) {
            $rowHotelId = $this->systemHotelIdFromFact($row);
            if ($rowHotelId > 0) {
                $datasetHotelIds[] = $rowHotelId;
            }
        }
        $datasetHotelIds = array_values(array_unique($datasetHotelIds));
        if ($datasetHotelIds !== [] && $permittedHotelIds !== []) {
            return array_values(array_intersect($datasetHotelIds, $permittedHotelIds));
        }
        if ($datasetHotelIds !== []) {
            return $datasetHotelIds;
        }
        return array_values(array_unique($permittedHotelIds));
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<int, int> $targetHotelIds
     * @return array<int, int>
     */
    private function pricingPreflightTargetRowsByHotel(array $dataset, array $targetHotelIds): array
    {
        $counts = array_fill_keys($targetHotelIds, 0);
        foreach ($this->list($dataset['fact_ota_daily'] ?? []) as $row) {
            $rowHotelId = $this->systemHotelIdFromFact($row);
            if ($rowHotelId > 0 && array_key_exists($rowHotelId, $counts)) {
                $counts[$rowHotelId]++;
            }
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function systemHotelIdFromFact(array $row): int
    {
        foreach (['system_hotel_id', 'systemHotelId'] as $key) {
            $value = $row[$key] ?? null;
            if (is_numeric($value) && (int)$value > 0) {
                return (int)$value;
            }
        }

        $hotelKey = (string)($row['hotel_key'] ?? '');
        if (preg_match('/(?:^|:)system:(\d+)$/', $hotelKey, $matches) === 1 || preg_match('/^system:(\d+)$/', $hotelKey, $matches) === 1) {
            return (int)$matches[1];
        }
        if (preg_match('/^system:(\d+)/', $hotelKey, $matches) === 1) {
            return (int)$matches[1];
        }

        $value = $row['hotel_id'] ?? null;
        return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
    }

    /**
     * @param list<string> $requiredColumns
     */
    private function pricingPreflightCountRows(string $table, array $requiredColumns, callable $applyWhere): ?int
    {
        if (!$this->tableExists($table)) {
            return null;
        }
        $columns = $this->tableColumns($table);
        foreach ($requiredColumns as $column) {
            if (!isset($columns[$column])) {
                return null;
            }
        }
        try {
            $query = Db::name($table);
            $applyWhere($query);
            return (int)$query->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function pricingPreflightRequiredInput(string $code, string $source, string $nextAction): array
    {
        return [
            'code' => $code,
            'status' => 'missing_or_blocked',
            'source' => $source,
            'required_before' => 'POST /api/agent/price-suggestions/generate',
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $requiredInputs
     * @param array<int, string> $tableGaps
     * @return array<string, mixed>
     */
    private function pricingTemporarySkipPolicy(string $status, string $reason, array $requiredInputs, array $tableGaps = []): array
    {
        $base = [
            'active' => false,
            'mode' => 'operator_explicit_temporary_skip',
            'reason' => self::TEMPORARY_PRICING_SKIP_REASON,
            'scope' => 'pricing_input_gaps_only',
            'original_status' => $status,
            'original_reason' => $reason,
            'skipped_input_codes' => [],
            'non_skippable_input_codes' => [],
            'preserve_missing_evidence' => true,
            'auto_generate_pending_suggestions' => false,
            'auto_write_ota' => false,
            'operation_intake_allowed' => false,
            'investment_decision_allowed' => false,
        ];
        if (in_array($status, ['failed', 'pending_review_exists', 'ready_for_manual_generation'], true)) {
            return $base;
        }
        if ($tableGaps !== []) {
            return $base;
        }

        $codes = [];
        foreach ($requiredInputs as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string)($item['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return $base;
        }

        $nonSkippable = array_values(array_diff($codes, self::TEMPORARY_SKIPPABLE_PRICING_INPUT_CODES));
        if ($nonSkippable !== []) {
            $base['non_skippable_input_codes'] = $nonSkippable;
            return $base;
        }

        if (!in_array($reason, self::TEMPORARY_SKIPPABLE_PRICING_REASONS, true)) {
            return $base;
        }

        $base['active'] = true;
        $base['skipped_input_codes'] = $codes;
        return $base;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function markPricingRequiredInputsSkipped(array $items): array
    {
        $skipReason = self::TEMPORARY_PRICING_SKIP_REASON;
        return array_map(static function (array $item) use ($skipReason): array {
            $item['original_status'] = (string)($item['status'] ?? 'missing_or_blocked');
            $item['status'] = 'skipped_by_operator_policy';
            $item['skip_policy'] = [
                'reason' => $skipReason,
                'preserve_missing_evidence' => true,
                'auto_write_ota' => false,
            ];
            return $item;
        }, $items);
    }

    /**
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function applyPricingTemporarySkipPolicyToPreflight(array $preflight): array
    {
        if ($preflight === [] || (string)($preflight['status'] ?? '') === 'not_loaded') {
            return $preflight;
        }
        if ((string)($preflight['status'] ?? '') === 'skipped_by_operator_policy') {
            return $preflight;
        }

        $requiredInputs = $this->uniqueRequiredInputs(is_array($preflight['required_inputs'] ?? null) ? $preflight['required_inputs'] : []);
        $policy = $this->pricingTemporarySkipPolicy(
            (string)($preflight['status'] ?? ''),
            (string)($preflight['reason'] ?? ''),
            $requiredInputs,
            is_array($preflight['table_gaps'] ?? null) ? $preflight['table_gaps'] : []
        );
        if (($policy['active'] ?? false) !== true) {
            if ($requiredInputs !== []) {
                $preflight['required_inputs'] = $requiredInputs;
            }
            $preflight['temporary_skip_policy'] = $policy;
            return $preflight;
        }

        $preflight['original_status'] = (string)($preflight['status'] ?? 'blocked');
        $preflight['status'] = 'skipped_by_operator_policy';
        $preflight['reason'] = self::TEMPORARY_PRICING_SKIP_REASON;
        $preflight['detail'] = '已按人工策略暂时跳过房型、保护价、需求预测和竞对价格样本缺口；缺口仍保留为证据，不生成待审建议，不写 OTA。';
        $preflight['required_inputs'] = $this->markPricingRequiredInputsSkipped($requiredInputs);
        $preflight['temporary_skip_policy'] = $policy;
        $preflight['can_generate_pending_suggestions'] = false;
        $preflight['auto_write_ota'] = false;
        $preflight['next_action'] = $this->pricingGenerationPreflightNextAction(self::TEMPORARY_PRICING_SKIP_REASON);
        return $preflight;
    }

    /**
     * @param array<int, array<string, string>> $items
     * @return array<int, array<string, string>>
     */
    private function uniqueRequiredInputs(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $result[] = $item;
        }
        return $result;
    }

    private function pricingGenerationPreflightNextAction(string $reason): string
    {
        return match ($reason) {
            'price_suggestions_pending_review' => '进入收益 Agent 的定价建议列表完成人工审核；Revenue AI 首页不自动写 OTA。',
            'pricing_generation_hotel_scope_missing' => '先选择或导入可映射到系统酒店的携程 OTA 数据，再生成待审调价建议。',
            'room_types_empty' => '为携程目标酒店配置启用房型和最低保护价，再补需求预测与竞对样本；缺口未补齐前不生成待审建议。',
            self::TEMPORARY_PRICING_SKIP_REASON => '已按人工策略暂时跳过抓不到的房型、保护价、需求预测和竞对样本缺口；继续保留缺口证据，不自动生成或写 OTA。',
            'pricing_candidate_signals_missing' => '补齐需求预测、竞对价格、历史价格变化和保护价信号，直到只读预检出现可生成候选。',
            'pricing_generation_candidates_ready' => '可进入收益 Agent 生成待审建议；生成后仍需人工审核，不写 OTA。',
            default => '检查定价建议生成前置表和读取权限；缺口明确前不生成或执行调价建议。',
        };
    }

    private function pricingGenerationPreflightIsLoaded(array $preflight): bool
    {
        return $preflight !== [] && (string)($preflight['status'] ?? '') !== 'not_loaded';
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function executionSummary(string $businessDate, ?int $hotelId, array $hotelIds): array
    {
        try {
            $flow = (new OperationManagementService())->executionFlow($hotelIds, $hotelId, ['object_type' => 'price']);
        } catch (\Throwable) {
            return $this->executionSummaryUnavailable($businessDate, $hotelId, 'failed', 'operation_execution_read_failed');
        }

        return $this->buildExecutionSummaryFromFlow($flow, $businessDate, $hotelId);
    }

    /**
     * Pure builder for the read-only Revenue AI execution progress summary.
     *
     * @param array<string, mixed> $flow
     * @return array<string, mixed>
     */
    public function buildExecutionSummaryFromFlow(array $flow, string $businessDate, ?int $hotelId = null): array
    {
        $flowGaps = $this->executionDataGaps($this->list($flow['data_gaps'] ?? []));
        $flowStatus = trim((string)($flow['data_status'] ?? ''));
        $rawItems = $this->list($flow['list'] ?? []);
        if ($rawItems === [] && $flowGaps !== [] && $flowStatus !== '' && $flowStatus !== 'ok') {
            $reason = (string)($flowGaps[0]['reason'] ?? 'operation_execution_not_loaded');
            return $this->executionSummaryUnavailable(
                $businessDate,
                $hotelId,
                $this->executionUnavailableStatus($reason),
                $reason,
                '',
                ['data_gaps' => $flowGaps]
            );
        }

        $items = array_values(array_filter(
            $rawItems,
            fn(array $item): bool => $this->executionItemMatchesBusinessDate($item, $businessDate)
        ));
        if ($items === []) {
            return array_merge($this->executionSummaryBase($businessDate, $hotelId), [
                'status' => 'empty',
                'reason' => 'operation_execution_empty',
                'display' => '暂无目标日期调价执行记录',
                'process' => [
                    'status' => 'empty',
                    'reason' => 'operation_execution_empty',
                    'display' => '执行单 0 / 已执行 0 / 证据 0',
                ],
                'effect_review' => [
                    'status' => 'empty',
                    'reason' => 'operation_execution_empty',
                    'display' => '复盘 0 / ROI 0',
                    'review_needed_count' => 0,
                    'reviewed_count' => 0,
                    'roi_ready_count' => 0,
                    'input_status' => 'empty',
                    'input_reason' => 'operation_execution_empty',
                    'input_count' => 0,
                    'input_total_count' => 0,
                    'input_ready_count' => 0,
                    'input_partial_count' => 0,
                    'input_missing_count' => 0,
                    'input_display' => '明日输入 可用 0 / 待补 0 / 缺失 0',
                    'inputs' => [],
                    'next_day_input_ready' => false,
                ],
                'data_gaps' => $flowGaps,
                'recent_items' => [],
                'next_action' => '暂无目标日期调价执行记录；如已有人工审核建议，请在运营执行页转为执行意图后再追踪。',
            ]);
        }

        $stageCounts = [
            'recommendation' => 0,
            'approval' => 0,
            'execution' => 0,
            'evidence' => 0,
            'review' => 0,
            'reviewed' => 0,
            'blocked' => 0,
            'rejected' => 0,
            'failed' => 0,
        ];
        $approved = 0;
        $executed = 0;
        $evidenceReady = 0;
        $pendingApproval = 0;
        $inProgress = 0;
        $evidenceNeeded = 0;
        $reviewNeeded = 0;
        $reviewed = 0;
        $roiReady = 0;
        $blocked = 0;
        $recentItems = [];
        $effectReviewInputRows = [];
        $effectInputReady = 0;
        $effectInputPartial = 0;
        $effectInputMissing = 0;
        $effectInputIndex = 0;

        foreach ($items as $item) {
            $stage = trim((string)($item['stage'] ?? 'approval'));
            $stage = $stage !== '' ? $stage : 'approval';
            if (!array_key_exists($stage, $stageCounts)) {
                $stageCounts[$stage] = 0;
            }
            $stageCounts[$stage]++;

            $approvalStatus = (string)($item['approval']['status'] ?? '');
            $executionStatus = (string)($item['execution']['status'] ?? '');
            $reviewStatus = (string)($item['review']['status'] ?? '');
            if ($approvalStatus === 'approved') {
                $approved++;
            }
            if ($executionStatus === 'executed') {
                $executed++;
            }
            if ((int)($item['evidence']['count'] ?? 0) > 0) {
                $evidenceReady++;
            }
            if (($item['roi']['status'] ?? '') === 'ready') {
                $roiReady++;
            }
            if ($stage === 'approval') {
                $pendingApproval++;
            }
            if ($stage === 'execution') {
                $inProgress++;
            }
            if ($stage === 'evidence') {
                $evidenceNeeded++;
            }
            if ($stage === 'review') {
                $reviewNeeded++;
            }
            if ($stage === 'reviewed' || in_array($reviewStatus, ['success', 'near_success', 'failed'], true)) {
                $reviewed++;
            }
            if (in_array($stage, ['blocked', 'rejected', 'failed'], true)
                || in_array($approvalStatus, ['blocked', 'rejected'], true)
                || in_array($executionStatus, ['blocked', 'failed'], true)) {
                $blocked++;
            }

            if (count($recentItems) < 5) {
                $recentItems[] = $this->buildExecutionRecentItem($item);
            }
            $effectReviewInput = $this->buildExecutionEffectReviewInput($item);
            $effectReviewInputStatus = (string)($effectReviewInput['input_status'] ?? '');
            if ($effectReviewInputStatus === 'ready') {
                $effectInputReady++;
            } elseif (in_array($effectReviewInputStatus, ['partial', 'reviewed_no_roi', 'pending_review', 'evidence_ready'], true)) {
                $effectInputPartial++;
            } else {
                $effectInputMissing++;
            }
            $effectReviewInput['_sort_index'] = $effectInputIndex++;
            $effectReviewInputRows[] = $effectReviewInput;
        }
        $effectReviewInputs = array_slice($this->sortExecutionEffectReviewInputs($effectReviewInputRows), 0, 5);

        $total = count($items);
        [$status, $reason] = $this->executionSummaryStatus(
            $pendingApproval,
            $inProgress,
            $evidenceNeeded,
            $reviewNeeded,
            $reviewed,
            $blocked,
            $total,
            $flowGaps
        );
        [$effectStatus, $effectReason] = $this->executionEffectReviewStatus(
            $total,
            $executed,
            $evidenceReady,
            $reviewNeeded,
            $reviewed,
            $roiReady
        );
        [$effectInputStatus, $effectInputReason] = $this->executionEffectReviewInputStatus(
            $total,
            $evidenceReady,
            $reviewNeeded,
            $reviewed,
            $roiReady
        );

        return array_merge($this->executionSummaryBase($businessDate, $hotelId), [
            'status' => $status,
            'reason' => $reason,
            'total_count' => $total,
            'stage_counts' => $stageCounts,
            'approved_count' => $approved,
            'executed_count' => $executed,
            'evidence_ready_count' => $evidenceReady,
            'review_needed_count' => $reviewNeeded,
            'reviewed_count' => $reviewed,
            'roi_ready_count' => $roiReady,
            'blocked_count' => $blocked,
            'display' => '执行单 ' . $total . ' / 已执行 ' . $executed . ' / 证据 ' . $evidenceReady . ' / 待复盘 ' . $reviewNeeded,
            'process' => [
                'status' => $status,
                'reason' => $reason,
                'display' => '执行单 ' . $total . ' / 已执行 ' . $executed . ' / 证据 ' . $evidenceReady,
                'pending_approval_count' => $pendingApproval,
                'in_progress_count' => $inProgress,
                'evidence_needed_count' => $evidenceNeeded,
                'blocked_count' => $blocked,
            ],
            'effect_review' => [
                'status' => $effectStatus,
                'reason' => $effectReason,
                'display' => '复盘 ' . $reviewed . ' / ROI ' . $roiReady,
                'review_needed_count' => $reviewNeeded,
                'reviewed_count' => $reviewed,
                'roi_ready_count' => $roiReady,
                'input_status' => $effectInputStatus,
                'input_reason' => $effectInputReason,
                'input_count' => count($effectReviewInputs),
                'input_total_count' => $total,
                'input_ready_count' => $effectInputReady,
                'input_partial_count' => $effectInputPartial,
                'input_missing_count' => $effectInputMissing,
                'input_display' => '明日输入 可用 ' . $effectInputReady . ' / 待补 ' . $effectInputPartial . ' / 缺失 ' . $effectInputMissing,
                'inputs' => $effectReviewInputs,
                'next_day_input_ready' => $roiReady > 0,
            ],
            'data_gaps' => $flowGaps,
            'recent_items' => $recentItems,
            'next_action' => $this->executionSummaryNextAction($status, $effectStatus),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $inputs
     * @return list<array<string, mixed>>
     */
    private function sortExecutionEffectReviewInputs(array $inputs): array
    {
        usort($inputs, function (array $left, array $right): int {
            $priority = $this->executionEffectReviewInputPriority($left) <=> $this->executionEffectReviewInputPriority($right);
            if ($priority !== 0) {
                return $priority;
            }

            return (int)($left['_sort_index'] ?? 0) <=> (int)($right['_sort_index'] ?? 0);
        });

        foreach ($inputs as &$input) {
            unset($input['_sort_index']);
        }
        unset($input);

        return array_values($inputs);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executionEffectReviewInputPriority(array $input): int
    {
        $status = (string)($input['input_status'] ?? '');
        $reason = (string)($input['input_reason'] ?? '');
        if ($reason === 'operation_roi_missing') {
            return 0;
        }
        if ($reason === 'operation_execution_evidence_needed') {
            return 1;
        }
        if (in_array($status, ['partial', 'reviewed_no_roi', 'pending_review', 'evidence_ready'], true)) {
            return 2;
        }
        if ($status === 'missing') {
            return 3;
        }
        if ($status === 'ready') {
            return 4;
        }
        if ($status === 'blocked') {
            return 5;
        }

        return 6;
    }

    private function executionSummaryUnavailable(
        string $businessDate,
        ?int $hotelId,
        string $status,
        string $reason,
        string $detail = '',
        array $extra = []
    ): array {
        $dataGaps = is_array($extra['data_gaps'] ?? null)
            ? $extra['data_gaps']
            : $this->executionDataGaps([['code' => $reason, 'message' => $detail]]);
        unset($extra['data_gaps']);

        return array_merge($this->executionSummaryBase($businessDate, $hotelId), [
            'status' => $status,
            'reason' => $reason,
            'display' => '--',
            'process' => [
                'status' => $status,
                'reason' => $reason,
                'display' => '--',
            ],
            'effect_review' => [
                'status' => $status,
                'reason' => $reason,
                'display' => '--',
                'review_needed_count' => 0,
                'reviewed_count' => 0,
                'roi_ready_count' => 0,
                'input_status' => $status,
                'input_reason' => $reason,
                'input_count' => 0,
                'input_total_count' => 0,
                'input_ready_count' => 0,
                'input_partial_count' => 0,
                'input_missing_count' => 0,
                'input_display' => '明日输入 可用 0 / 待补 0 / 缺失 0',
                'inputs' => [],
                'next_day_input_ready' => false,
            ],
            'data_gaps' => $dataGaps,
            'recent_items' => [],
            'detail' => $detail,
            'next_action' => '检查运营执行闭环表、字段和读取权限；缺口明确前不把执行状态当作调价闭环证据。',
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function executionSummaryBase(string $businessDate, ?int $hotelId): array
    {
        return [
            'status' => 'unknown',
            'reason' => 'operation_execution_not_loaded',
            'scope' => 'hotel',
            'source_service' => 'OperationManagementService::executionFlow',
            'source_table' => 'operation_execution_intents',
            'date_basis' => 'operation_execution_intents.date_start/date_end',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'object_type' => 'price',
            'total_count' => 0,
            'stage_counts' => [],
            'approved_count' => 0,
            'executed_count' => 0,
            'evidence_ready_count' => 0,
            'review_needed_count' => 0,
            'reviewed_count' => 0,
            'roi_ready_count' => 0,
            'blocked_count' => 0,
            'display' => '--',
            'process' => [],
            'effect_review' => [],
            'data_gaps' => [],
            'recent_items' => [],
            'read_only' => true,
            'auto_write_ota' => false,
            'next_action' => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $gaps
     * @return array<int, array<string, mixed>>
     */
    private function executionDataGaps(array $gaps): array
    {
        $rows = [];
        foreach ($gaps as $index => $gap) {
            $reason = trim((string)($gap['code'] ?? $gap['reason'] ?? 'operation_execution_not_loaded'));
            if ($reason === '') {
                $reason = 'operation_execution_not_loaded';
            }
            $meta = $this->issueReasonMeta($reason, 'hotel', 'operation_execution');
            $rows[] = [
                'key' => 'operation_execution_' . $reason . '_' . $index,
                'type' => 'operation_execution_gap',
                'label' => '运营执行闭环',
                'status' => $this->executionUnavailableStatus($reason),
                'reason' => $reason,
                'message' => (string)($gap['message'] ?? ''),
                'severity' => $meta['severity'],
                'category' => $meta['category'],
                'display_reason' => $meta['display_reason'],
                'next_action' => $meta['next_action'],
                'target_page' => 'ops-track',
                'target_tab' => '',
                'target_platform' => 'hotel',
            ];
        }

        return $this->uniqueIssueRows($rows);
    }

    private function executionUnavailableStatus(string $reason): string
    {
        if (str_contains($reason, 'missing')) {
            return 'missing';
        }
        if (str_contains($reason, 'failed')) {
            return 'failed';
        }
        if ($reason === 'operation_execution_not_loaded') {
            return 'not_loaded';
        }

        return 'partial';
    }

    private function executionItemMatchesBusinessDate(array $item, string $businessDate): bool
    {
        $recommendation = is_array($item['recommendation'] ?? null) ? $item['recommendation'] : [];
        $start = $this->datePart($recommendation['date_start'] ?? null);
        $end = $this->datePart($recommendation['date_end'] ?? null);
        if ($start === null && $end === null) {
            return false;
        }
        $start = $start ?? $end;
        $end = $end ?? $start;

        return $start <= $businessDate && $end >= $businessDate;
    }

    /**
     * @param array<int, array<string, mixed>> $flowGaps
     * @return array{0:string,1:string}
     */
    private function executionSummaryStatus(
        int $pendingApproval,
        int $inProgress,
        int $evidenceNeeded,
        int $reviewNeeded,
        int $reviewed,
        int $blocked,
        int $total,
        array $flowGaps
    ): array {
        if ($blocked > 0) {
            return ['blocked', 'operation_execution_blocked'];
        }
        if ($pendingApproval > 0) {
            return ['pending_approval', 'operation_execution_pending_approval'];
        }
        if ($inProgress > 0) {
            return ['in_progress', 'operation_execution_in_progress'];
        }
        if ($evidenceNeeded > 0) {
            return ['evidence_needed', 'operation_execution_evidence_needed'];
        }
        if ($reviewNeeded > 0) {
            return ['review_needed', 'operation_execution_review_needed'];
        }
        if ($reviewed >= $total && $total > 0) {
            return ['reviewed', 'operation_execution_reviewed'];
        }
        if ($flowGaps !== []) {
            return ['partial', (string)($flowGaps[0]['reason'] ?? 'operation_execution_partial')];
        }

        return ['partial', 'operation_execution_partial'];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function executionEffectReviewStatus(
        int $total,
        int $executed,
        int $evidenceReady,
        int $reviewNeeded,
        int $reviewed,
        int $roiReady
    ): array {
        if ($total <= 0) {
            return ['empty', 'operation_execution_empty'];
        }
        if ($reviewed > 0 && $roiReady > 0) {
            return ['ok', 'operation_effect_review_ready'];
        }
        if ($reviewNeeded > 0) {
            return ['review_needed', $roiReady <= 0 && $evidenceReady > 0 ? 'operation_roi_missing' : 'operation_effect_review_pending'];
        }
        if ($evidenceReady <= 0) {
            return ['evidence_needed', 'operation_execution_evidence_needed'];
        }
        if ($executed <= 0) {
            return ['in_progress', 'operation_execution_not_executed'];
        }

        return ['partial', 'operation_roi_missing'];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function executionEffectReviewInputStatus(
        int $total,
        int $evidenceReady,
        int $reviewNeeded,
        int $reviewed,
        int $roiReady
    ): array {
        if ($total <= 0) {
            return ['empty', 'operation_execution_empty'];
        }
        if ($roiReady > 0) {
            return ['ready', 'operation_effect_review_ready'];
        }
        if ($reviewed > 0) {
            return ['partial', 'operation_roi_missing'];
        }
        if ($reviewNeeded > 0) {
            return ['partial', $evidenceReady > 0 ? 'operation_roi_missing' : 'operation_effect_review_pending'];
        }
        if ($evidenceReady > 0) {
            return ['evidence_ready', 'operation_execution_review_needed'];
        }

        return ['missing', 'operation_execution_evidence_needed'];
    }

    private function executionSummaryNextAction(string $processStatus, string $effectStatus): string
    {
        if ($processStatus === 'pending_approval') {
            return '进入运营执行页审批调价执行意图；Revenue AI 首页不直接批准。';
        }
        if ($processStatus === 'in_progress') {
            return '进入运营执行页记录实际执行结果和执行人。';
        }
        if ($processStatus === 'evidence_needed') {
            return '补充执行前后价格、收入或平台回执证据。';
        }
        if ($processStatus === 'review_needed' || $effectStatus === 'review_needed') {
            return '进入运营执行页触发效果复盘，区分执行完成和收益验证。';
        }
        if ($processStatus === 'blocked') {
            return '先处理审批、执行或平台回写阻塞原因。';
        }
        if ($processStatus === 'reviewed') {
            return '复核 ROI/增量收入证据，作为明日调价判断输入。';
        }

        return '继续在运营执行页维护调价执行记录和复盘证据。';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExecutionEffectReviewInput(array $item): array
    {
        $recommendation = is_array($item['recommendation'] ?? null) ? $item['recommendation'] : [];
        $review = is_array($item['review'] ?? null) ? $item['review'] : [];
        $execution = is_array($item['execution'] ?? null) ? $item['execution'] : [];
        $evidence = is_array($item['evidence'] ?? null) ? $item['evidence'] : [];
        $roi = is_array($item['roi'] ?? null) ? $item['roi'] : [];
        $nextAction = is_array($item['next_action'] ?? null) ? $item['next_action'] : [];
        $platform = strtolower(trim((string)($recommendation['platform'] ?? '')));
        $stage = trim((string)($item['stage'] ?? ''));
        $reviewStatus = (string)($review['status'] ?? 'observing');
        $roiStatus = (string)($roi['status'] ?? 'data_gap');
        $evidenceCount = (int)($evidence['count'] ?? 0);
        $inputStatus = $this->executionEffectReviewInputRowStatus($stage, $reviewStatus, $roiStatus, $evidenceCount);
        $inputReason = $this->executionEffectReviewInputRowReason($inputStatus, $roiStatus);
        $inputAction = $this->executionEffectReviewInputAction($inputStatus, $inputReason);
        $evidenceSummary = $this->executionEffectReviewEvidenceSummary(
            is_array($evidence['latest'] ?? null) ? $evidence['latest'] : [],
            $evidenceCount,
            $roiStatus,
            $inputStatus
        );

        return [
            'id' => (int)($item['id'] ?? 0),
            'intent_id' => (int)($item['id'] ?? 0),
            'hotel_id' => (int)($item['hotel_id'] ?? 0),
            'task_id' => (int)($execution['task_id'] ?? 0),
            'scope' => 'hotel',
            'date_basis' => 'operation_execution_tasks.result_status/result_summary + operation_execution_evidence',
            'source_service' => 'OperationManagementService::executionFlow',
            'platform' => $platform,
            'platform_label' => in_array($platform, self::CHANNELS, true) ? $this->channelLabel($platform) : ($platform !== '' ? strtoupper($platform) : 'OTA'),
            'action_type' => (string)($recommendation['action_type'] ?? ''),
            'object_type' => (string)($recommendation['object_type'] ?? ''),
            'date_start' => (string)($recommendation['date_start'] ?? ''),
            'date_end' => (string)($recommendation['date_end'] ?? ''),
            'current_value' => is_array($recommendation['current_value'] ?? null) ? $recommendation['current_value'] : [],
            'target_value' => is_array($recommendation['target_value'] ?? null) ? $recommendation['target_value'] : [],
            'stage' => $stage !== '' ? $stage : 'approval',
            'stage_label' => $this->executionStageLabel($stage),
            'input_status' => $inputStatus,
            'input_reason' => $inputReason,
            'input_action_key' => $inputAction['key'],
            'input_action_label' => $inputAction['label'],
            'input_next_action' => $inputAction['next_action'],
            'input_action_reason' => $inputAction['reason'],
            'review_status' => $reviewStatus,
            'review_summary' => (string)($review['summary'] ?? ''),
            'evidence_count' => $evidenceCount,
            'latest_evidence_type' => $evidenceSummary['latest_evidence_type'],
            'latest_evidence_at' => $evidenceSummary['latest_evidence_at'],
            'latest_evidence_source' => $evidenceSummary['latest_evidence_source'],
            'latest_evidence_has_attachment' => $evidenceSummary['latest_evidence_has_attachment'],
            'has_revenue_evidence' => $evidenceSummary['has_revenue_evidence'],
            'has_cost_evidence' => $evidenceSummary['has_cost_evidence'],
            'has_operator_execution_evidence' => $evidenceSummary['has_operator_execution_evidence'],
            'has_operator_roi_evidence' => $evidenceSummary['has_operator_roi_evidence'],
            'operator_execution_evidence_summary' => $evidenceSummary['operator_execution_evidence_summary'],
            'operator_roi_evidence_summary' => $evidenceSummary['operator_roi_evidence_summary'],
            'evidence_summary' => $evidenceSummary['display'],
            'evidence_ready_for_next_day' => $evidenceSummary['ready_for_next_day'],
            'roi_status' => $roiStatus,
            'roi_value' => $roi['value'] ?? null,
            'roi_unit' => (string)($roi['unit'] ?? ''),
            'roi_display' => $this->executionRoiDisplay($roi),
            'before_revenue' => $roi['before_revenue'] ?? null,
            'after_revenue' => $roi['after_revenue'] ?? null,
            'incremental_revenue' => $roi['incremental_revenue'] ?? null,
            'cost' => $roi['cost'] ?? null,
            'profit' => $roi['profit'] ?? null,
            'formula' => (string)($roi['formula'] ?? ''),
            'read_only' => true,
            'auto_write_ota' => false,
            'target_page' => 'ops-track',
            'target_action' => (string)($nextAction['key'] ?? ''),
            'target_id' => (int)($nextAction['target_id'] ?? 0),
            'target_kind' => in_array((string)($nextAction['key'] ?? ''), ['approve_intent', 'resolve_blocker'], true)
                ? 'intent'
                : ((int)($execution['task_id'] ?? 0) > 0 ? 'task' : ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executionEffectReviewEvidenceSummary(array $latestEvidence, int $evidenceCount, string $roiStatus, string $inputStatus): array
    {
        $before = is_array($latestEvidence['before'] ?? null) ? $latestEvidence['before'] : [];
        $after = is_array($latestEvidence['after'] ?? null) ? $latestEvidence['after'] : [];
        $platformResponse = is_array($latestEvidence['platform_response'] ?? null) ? $latestEvidence['platform_response'] : [];
        $operatorExecutionEvidence = is_array($platformResponse['operator_execution_evidence'] ?? null)
            ? $platformResponse['operator_execution_evidence']
            : [];
        $operatorRoiEvidence = is_array($platformResponse['operator_roi_evidence'] ?? null)
            ? $platformResponse['operator_roi_evidence']
            : [];
        $operatorExecutionSummary = $this->executionOperatorEvidenceSummary(
            $operatorExecutionEvidence,
            ['executed_by', 'executed_at', 'execution_basis', 'room_rate_mapping_source', 'execution_receipt_or_screenshot_path']
        );
        $operatorRoiSummary = $this->executionOperatorEvidenceSummary(
            $operatorRoiEvidence,
            ['reviewed_by', 'reviewed_at', 'before_metric_source', 'after_metric_source', 'roi_calculation_basis', 'roi_receipt_or_screenshot_path']
        );
        $type = trim((string)($latestEvidence['evidence_type'] ?? ''));
        $createdAt = trim((string)($latestEvidence['created_at'] ?? ''));
        $source = trim((string)($platformResponse['source'] ?? $platformResponse['mode'] ?? ''));
        $hasAttachment = trim((string)($latestEvidence['attachment_path'] ?? '')) !== ''
            || trim((string)($platformResponse['receipt_path'] ?? '')) !== '';
        $hasRevenue = $this->arrayHasNumericMetric($before, ['revenue', 'avg_revenue', 'amount', 'income'])
            && $this->arrayHasNumericMetric($after, ['revenue', 'avg_revenue', 'amount', 'income']);
        $hasCost = $this->arrayHasNumericMetric($after, ['cost', 'ad_cost', 'spend', 'budget'])
            || $this->arrayHasNumericMetric($platformResponse, ['cost', 'ad_cost', 'spend', 'budget']);
        $parts = [];
        $parts[] = $type !== '' ? '最新证据 ' . $type : ($evidenceCount > 0 ? '最新证据 未标注类型' : '暂无执行证据');
        $parts[] = $hasRevenue ? '收入已具备' : '缺收入';
        if ($hasCost) {
            $parts[] = '成本已具备';
        }
        if ($hasAttachment) {
            $parts[] = '有回执';
        }
        if ((bool)$operatorExecutionSummary['provided']) {
            $parts[] = '人工执行证据已具备';
        }
        if ((bool)$operatorRoiSummary['provided']) {
            $parts[] = '人工ROI依据已具备';
        }
        if ($roiStatus === 'ready') {
            $parts[] = '可作明日输入';
        } elseif ($inputStatus === 'partial') {
            $parts[] = '待补ROI';
        }

        return [
            'latest_evidence_type' => $type,
            'latest_evidence_at' => $createdAt,
            'latest_evidence_source' => $source,
            'latest_evidence_has_attachment' => $hasAttachment,
            'has_revenue_evidence' => $hasRevenue,
            'has_cost_evidence' => $hasCost,
            'has_operator_execution_evidence' => (bool)$operatorExecutionSummary['provided'],
            'has_operator_roi_evidence' => (bool)$operatorRoiSummary['provided'],
            'operator_execution_evidence_summary' => $operatorExecutionSummary,
            'operator_roi_evidence_summary' => $operatorRoiSummary,
            'ready_for_next_day' => $roiStatus === 'ready',
            'display' => implode(' / ', $parts),
        ];
    }

    /**
     * @param list<string> $summaryKeys
     * @return array<string, mixed>
     */
    private function executionOperatorEvidenceSummary(array $evidence, array $summaryKeys): array
    {
        $summary = [
            'provided' => $evidence !== [],
            'keys' => array_values(array_keys($evidence)),
        ];

        foreach ($summaryKeys as $key) {
            $value = $evidence[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                $summary[$key] = (string)$value;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function arrayHasNumericMetric(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_numeric($value)) {
                return true;
            }
        }

        return false;
    }

    private function executionEffectReviewInputRowStatus(string $stage, string $reviewStatus, string $roiStatus, int $evidenceCount): string
    {
        if ($roiStatus === 'ready') {
            return 'ready';
        }
        if (in_array($reviewStatus, ['success', 'near_success', 'failed'], true)) {
            return 'reviewed_no_roi';
        }
        if (($stage === 'review' || $evidenceCount > 0) && $roiStatus === 'data_gap') {
            return 'partial';
        }
        if ($stage === 'review' || $evidenceCount > 0) {
            return 'pending_review';
        }
        if (in_array($stage, ['blocked', 'failed', 'rejected'], true)) {
            return 'blocked';
        }

        return 'missing';
    }

    private function executionEffectReviewInputRowReason(string $inputStatus, string $roiStatus): string
    {
        return match ($inputStatus) {
            'ready' => 'operation_effect_review_ready',
            'reviewed_no_roi' => $roiStatus === 'data_gap' ? 'operation_roi_missing' : 'operation_effect_review_pending',
            'partial' => $roiStatus === 'data_gap' ? 'operation_roi_missing' : 'operation_effect_review_pending',
            'pending_review' => 'operation_effect_review_pending',
            'blocked' => 'operation_execution_blocked',
            default => 'operation_execution_evidence_needed',
        };
    }

    /**
     * @return array{key:string,label:string,next_action:string,reason:string}
     */
    private function executionEffectReviewInputAction(string $inputStatus, string $inputReason): array
    {
        if ($inputReason === 'operation_roi_missing') {
            return [
                'key' => 'record_roi_evidence',
                'label' => '补录ROI证据',
                'next_action' => '补齐执行前后收入、成本或平台回执后再判断效果。',
                'reason' => $inputReason,
            ];
        }
        if ($inputReason === 'operation_execution_evidence_needed') {
            return [
                'key' => 'record_execution_evidence',
                'label' => '补执行证据',
                'next_action' => '补充执行前后价格、收入或平台回执证据。',
                'reason' => $inputReason,
            ];
        }
        if ($inputStatus === 'ready') {
            return [
                'key' => 'use_next_day_input',
                'label' => '可作明日输入',
                'next_action' => '将 ROI/增量收入证据作为明日调价判断输入。',
                'reason' => $inputReason,
            ];
        }
        if (in_array($inputStatus, ['partial', 'reviewed_no_roi', 'pending_review', 'evidence_ready'], true)) {
            return [
                'key' => 'record_effect_review',
                'label' => '记录效果复盘',
                'next_action' => '记录人工复盘结论并保留收入、成本和平台回执证据边界。',
                'reason' => $inputReason,
            ];
        }
        if ($inputStatus === 'blocked') {
            return [
                'key' => 'resolve_execution_blocker',
                'label' => '处理阻塞',
                'next_action' => '先处理执行阻塞、拒绝或失败原因。',
                'reason' => $inputReason,
            ];
        }

        return [
            'key' => 'inspect_execution',
            'label' => '查看运营执行',
            'next_action' => '进入运营执行页核对审批、执行、证据和复盘状态。',
            'reason' => $inputReason,
        ];
    }

    /**
     * @param array<string, mixed> $roi
     */
    private function executionRoiDisplay(array $roi): string
    {
        if ((string)($roi['status'] ?? '') !== 'ready') {
            return '--';
        }
        $value = $roi['value'] ?? null;
        if (!is_numeric($value)) {
            return '--';
        }
        $unit = (string)($roi['unit'] ?? '');
        if ($unit === '%') {
            return round((float)$value, 2) . '%';
        }
        if ($unit === 'amount') {
            return '¥' . number_format((float)$value, 2, '.', '');
        }

        return number_format((float)$value, 2, '.', '') . ($unit !== '' ? $unit : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExecutionRecentItem(array $item): array
    {
        $recommendation = is_array($item['recommendation'] ?? null) ? $item['recommendation'] : [];
        $nextAction = is_array($item['next_action'] ?? null) ? $item['next_action'] : [];
        $platform = strtolower(trim((string)($recommendation['platform'] ?? '')));
        $stage = trim((string)($item['stage'] ?? ''));

        return [
            'id' => (int)($item['id'] ?? 0),
            'intent_id' => (int)($item['id'] ?? 0),
            'hotel_id' => (int)($item['hotel_id'] ?? 0),
            'task_id' => (int)($item['execution']['task_id'] ?? 0),
            'stage' => $stage !== '' ? $stage : 'approval',
            'stage_label' => $this->executionStageLabel($stage),
            'platform' => $platform,
            'platform_label' => in_array($platform, self::CHANNELS, true) ? $this->channelLabel($platform) : ($platform !== '' ? strtoupper($platform) : 'OTA'),
            'action_type' => (string)($recommendation['action_type'] ?? ''),
            'date_start' => (string)($recommendation['date_start'] ?? ''),
            'date_end' => (string)($recommendation['date_end'] ?? ''),
            'approval_status' => (string)($item['approval']['status'] ?? ''),
            'execution_status' => (string)($item['execution']['status'] ?? ''),
            'evidence_count' => (int)($item['evidence']['count'] ?? 0),
            'review_status' => (string)($item['review']['status'] ?? ''),
            'roi_status' => (string)($item['roi']['status'] ?? ''),
            'next_action' => $nextAction,
            'next_action_label' => (string)($nextAction['label'] ?? ''),
            'target_page' => 'ops-track',
            'target_action' => (string)($nextAction['key'] ?? ''),
            'target_id' => (int)($nextAction['target_id'] ?? 0),
            'target_kind' => in_array((string)($nextAction['key'] ?? ''), ['approve_intent', 'resolve_blocker'], true)
                ? 'intent'
                : ((int)($item['execution']['task_id'] ?? 0) > 0 ? 'task' : ''),
        ];
    }

    private function executionStageLabel(string $stage): string
    {
        return [
            'recommendation' => '建议动作',
            'approval' => '审批',
            'execution' => '执行',
            'evidence' => '执行证据',
            'review' => '效果复盘',
            'reviewed' => 'ROI确认',
            'blocked' => '阻塞',
            'rejected' => '已拒绝',
            'failed' => '失败',
        ][trim($stage)] ?? '审批';
    }

    /**
     * Pure builder for recent Revenue Agent activity.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function buildAgentActivity(array $rows, string $businessDate, ?int $hotelId = null): array
    {
        $items = $this->list($rows);
        $counts = [
            'debug_count' => 0,
            'info_count' => 0,
            'warning_count' => 0,
            'error_count' => 0,
            'unknown_count' => 0,
        ];
        $latestCandidates = [];
        $recentLogs = [];

        foreach ($items as $row) {
            $level = (int)($row['log_level'] ?? 0);
            if ($level === 1) {
                $counts['debug_count']++;
            } elseif ($level === 2) {
                $counts['info_count']++;
            } elseif ($level === 3) {
                $counts['warning_count']++;
            } elseif ($level === 4) {
                $counts['error_count']++;
            } else {
                $counts['unknown_count']++;
            }

            $time = trim((string)($row['create_time'] ?? ''));
            if ($time !== '') {
                $latestCandidates[] = $time;
            }
            if (count($recentLogs) < 5) {
                $recentLogs[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'agent_type' => (int)($row['agent_type'] ?? 2),
                    'agent_type_label' => $this->agentTypeLabel((int)($row['agent_type'] ?? 2)),
                    'action' => $this->safeLogText($row['action'] ?? '', 80),
                    'message' => $this->safeLogText($row['message'] ?? '', 120),
                    'log_level' => $level,
                    'level_label' => $this->agentLogLevelLabel($level),
                    'status' => $this->agentLogStatus($level),
                    'create_time' => $time,
                ];
            }
        }

        rsort($latestCandidates);
        $totalCount = count($items);
        if ($counts['error_count'] > 0) {
            $status = 'failed';
            $reason = 'agent_logs_error_present';
            $nextAction = '查看收益管理 Agent 错误日志，先处理失败原因再继续生成或执行建议。';
        } elseif ($counts['warning_count'] > 0) {
            $status = 'warning';
            $reason = 'agent_logs_warning_present';
            $nextAction = '复核收益管理 Agent 警告日志，确认是否影响今日调价判断。';
        } elseif ($totalCount > 0) {
            $status = 'ok';
            $reason = 'agent_logs_available';
            $nextAction = '继续只读追踪 Agent 操作，不把日志数量当作业务成功证据。';
        } else {
            $status = 'empty';
            $reason = 'agent_logs_empty';
            $nextAction = '目标经营日期暂无收益管理 Agent 操作日志；如预期应有动作，检查 Agent 触发链路。';
        }

        return [
            'status' => $status,
            'reason' => $reason,
            'scope' => 'hotel',
            'source_table' => 'agent_logs',
            'date_basis' => 'create_time',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'agent_type' => 2,
            'agent_type_label' => '收益管理Agent',
            'total_count' => $totalCount,
            'debug_count' => $counts['debug_count'],
            'info_count' => $counts['info_count'],
            'warning_count' => $counts['warning_count'],
            'error_count' => $counts['error_count'],
            'unknown_count' => $counts['unknown_count'],
            'display' => $totalCount > 0
                ? '日志 ' . $totalCount . ' / 错误 ' . $counts['error_count'] . ' / 警告 ' . $counts['warning_count']
                : '暂无收益管理 Agent 日志',
            'last_success_at' => $latestCandidates[0] ?? null,
            'recent_logs' => $recentLogs,
            'read_only' => true,
            'auto_write_ota' => false,
            'next_action' => $nextAction,
        ];
    }

    private function agentActivity(string $businessDate, ?int $hotelId): array
    {
        if (!$this->tableExists('agent_logs')) {
            return $this->agentActivityUnavailable($businessDate, $hotelId, 'missing', 'agent_logs_missing');
        }

        $columns = $this->tableColumns('agent_logs');
        $missingRequired = array_values(array_filter(['agent_type', 'log_level', 'create_time'], static fn(string $field): bool => !isset($columns[$field])));
        if ($missingRequired !== []) {
            return $this->agentActivityUnavailable(
                $businessDate,
                $hotelId,
                'failed',
                'agent_logs_required_fields_missing',
                'Missing required fields: ' . implode(', ', $missingRequired),
                ['missing_fields' => $missingRequired]
            );
        }
        if ($hotelId !== null && !isset($columns['hotel_id'])) {
            return $this->agentActivityUnavailable(
                $businessDate,
                $hotelId,
                'failed',
                'agent_logs_required_fields_missing',
                'Missing required field: hotel_id',
                ['missing_fields' => ['hotel_id']]
            );
        }

        $fields = array_values(array_intersect([
            'id',
            'hotel_id',
            'agent_type',
            'action',
            'message',
            'log_level',
            'user_id',
            'create_time',
        ], array_keys($columns)));

        try {
            $query = Db::name('agent_logs')
                ->field(implode(',', $fields))
                ->where('agent_type', 2)
                ->whereBetween('create_time', [$businessDate . ' 00:00:00', $businessDate . ' 23:59:59'])
                ->order('create_time', 'desc');
            if ($hotelId !== null) {
                $query->where('hotel_id', $hotelId);
            }
            if (isset($columns['id'])) {
                $query->order('id', 'desc');
            }
            $rows = $query->limit(50)->select()->toArray();
        } catch (\Throwable) {
            return $this->agentActivityUnavailable($businessDate, $hotelId, 'failed', 'agent_logs_read_failed');
        }

        return $this->buildAgentActivity($rows, $businessDate, $hotelId);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function agentActivityUnavailable(
        string $businessDate,
        ?int $hotelId,
        string $status,
        string $reason,
        string $detail = '',
        array $extra = []
    ): array {
        return array_merge([
            'status' => $status,
            'reason' => $reason,
            'scope' => 'hotel',
            'source_table' => 'agent_logs',
            'date_basis' => 'create_time',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'agent_type' => 2,
            'agent_type_label' => '收益管理Agent',
            'total_count' => 0,
            'debug_count' => 0,
            'info_count' => 0,
            'warning_count' => 0,
            'error_count' => 0,
            'unknown_count' => 0,
            'display' => '--',
            'last_success_at' => null,
            'recent_logs' => [],
            'read_only' => true,
            'auto_write_ota' => false,
            'detail' => $detail,
            'next_action' => '检查 agent_logs 表、字段和读取权限；缺口明确前不把 Agent 日志当作闭环证据。',
        ], $extra);
    }

    private function agentTypeLabel(int $agentType): string
    {
        return match ($agentType) {
            1 => '智能员工Agent',
            2 => '收益管理Agent',
            3 => '资产运维Agent',
            default => '未知Agent',
        };
    }

    private function agentLogLevelLabel(int $level): string
    {
        return match ($level) {
            1 => '调试',
            2 => '信息',
            3 => '警告',
            4 => '错误',
            default => '未知',
        };
    }

    private function agentLogStatus(int $level): string
    {
        return match ($level) {
            1, 2 => 'ok',
            3 => 'warning',
            4 => 'failed',
            default => 'unknown',
        };
    }

    private function safeLogText(mixed $value, int $maxLength): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/(cookie|token|authorization|spidertoken|mtgsig|session)[=:：\\s]+[^\\s,;，；]+/iu', '$1=***', $text) ?? $text;
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            return mb_substr($text, 0, $maxLength, 'UTF-8') . '...';
        }
        return $text;
    }

    /**
     * @param array<int, array<string, mixed>> $missingDatasets
     * @param array<int, array<string, mixed>> $qualityIssues
     * @param array<string, mixed>|null $pricingReadiness
     * @return array<int, array<string, mixed>>
     */
    private function actions(
        array $missingDatasets,
        array $qualityIssues,
        ?array $pricingReadiness = null,
        array $reviewQueue = [],
        array $pricingGenerationPreflight = []
    ): array
    {
        $blockingReasons = is_array($pricingReadiness['blocking_reasons'] ?? null) ? $pricingReadiness['blocking_reasons'] : [];
        if ($pricingGenerationPreflight === [] && is_array($pricingReadiness['pricing_generation_preflight'] ?? null)) {
            $pricingGenerationPreflight = $pricingReadiness['pricing_generation_preflight'];
        }
        $decisionBasisSummary = $this->pricingDecisionBasisSummary(is_array($pricingReadiness) ? $pricingReadiness : []);
        $aiDecisionResolutionPlan = is_array($pricingReadiness['ai_decision_resolution_plan'] ?? null)
            ? $pricingReadiness['ai_decision_resolution_plan']
            : $this->pricingAiDecisionResolutionPlan(is_array($decisionBasisSummary['items'] ?? null) ? $decisionBasisSummary['items'] : []);
        $aiDecisionReviewContract = is_array($pricingReadiness['ai_decision_review_contract'] ?? null)
            ? $pricingReadiness['ai_decision_review_contract']
            : $this->pricingAiDecisionReviewContract($aiDecisionResolutionPlan);
        $aiToOperationHandoff = is_array($pricingReadiness['ai_to_operation_handoff'] ?? null)
            ? $pricingReadiness['ai_to_operation_handoff']
            : $this->pricingAiToOperationHandoff(is_array($pricingReadiness) ? $pricingReadiness : [], [], '', null, []);
        $operationIntakePacket = is_array($aiToOperationHandoff['operation_intake_packet'] ?? null) ? $aiToOperationHandoff['operation_intake_packet'] : [];
        $operationPreflight = is_array($operationIntakePacket['operation_intake_preflight_contract'] ?? null) ? $operationIntakePacket['operation_intake_preflight_contract'] : [];
        $operationToInvestmentHandoff = is_array($pricingReadiness['operation_to_investment_handoff'] ?? null)
            ? $pricingReadiness['operation_to_investment_handoff']
            : $this->pricingOperationToInvestmentHandoff($aiToOperationHandoff, [], '', null, []);
        $investmentPrecheckPacket = is_array($operationToInvestmentHandoff['investment_precheck_packet'] ?? null)
            ? $operationToInvestmentHandoff['investment_precheck_packet']
            : [];
        $pendingReviewCount = (int)($reviewQueue['pending_count'] ?? 0);
        $reason = $pendingReviewCount > 0
            ? 'price_suggestions_pending_review'
            : ($blockingReasons[0] ?? $missingDatasets[0]['reason'] ?? $qualityIssues[0]['reason'] ?? 'phase1a_readonly_no_pricing_model');
        $nextActions = is_array($pricingReadiness['next_actions'] ?? null) ? $pricingReadiness['next_actions'] : [];
        if (is_string($reviewQueue['next_action'] ?? null) && $reviewQueue['next_action'] !== '') {
            array_unshift($nextActions, (string)$reviewQueue['next_action']);
        }
        $detail = $pendingReviewCount > 0
            ? '已有 ' . $pendingReviewCount . ' 条来自 price_suggestions 的待人工审核调价建议；可在首页批准、修改后批准或拒绝，但不写 OTA。'
            : (is_string($pricingReadiness['summary'] ?? null) && $pricingReadiness['summary'] !== ''
            ? $pricingReadiness['summary']
            : 'Phase 1A only reads OTA evidence. Pricing suggestions require validated demand, competitor, floor-price and review workflow data.');
        return [
            [
                'key' => 'pricing_review',
                'title' => $pendingReviewCount > 0 ? '待人工审核调价建议' : '暂无可审核调价建议',
                'status' => $pendingReviewCount > 0 ? 'pending_review' : (string)($pricingReadiness['overall_status'] ?? 'blocked'),
                'reason' => $reason,
                'manual_review_required' => true,
                'auto_write_ota' => false,
                'detail' => $detail,
                'blocking_reasons' => array_values(array_unique($blockingReasons)),
                'next_actions' => array_slice(array_values(array_unique(array_filter(array_map('strval', $nextActions)))), 0, 4),
                'decision_basis_summary' => $decisionBasisSummary,
                'ai_decision_review_contract' => $aiDecisionReviewContract,
                'ai_decision_resolution_plan' => $aiDecisionResolutionPlan,
                'ai_to_operation_handoff' => $aiToOperationHandoff,
                'operation_intake_preflight_contract' => $operationPreflight,
                'operation_to_investment_handoff' => $operationToInvestmentHandoff,
                'investment_precheck_packet' => $investmentPrecheckPacket,
                'readiness' => $pricingReadiness,
                'review_queue' => $reviewQueue,
                'review_queue_summary' => (string)($reviewQueue['display'] ?? ''),
                'pricing_generation_preflight' => $pricingGenerationPreflight,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $pricingReadiness
     * @return array<string, mixed>
     */
    private function pricingDecisionBasisSummary(array $pricingReadiness): array
    {
        $gates = is_array($pricingReadiness['gates'] ?? null) ? $pricingReadiness['gates'] : [];
        $items = [];
        $readyLabels = [];
        $skippedLabels = [];
        $blockedLabels = [];
        foreach ($gates as $gate) {
            if (!is_array($gate)) {
                continue;
            }
            $status = (string)($gate['status'] ?? 'unknown');
            $label = (string)($gate['label'] ?? ($gate['key'] ?? ''));
            if ($label === '') {
                $label = '未命名依据';
            }
            if ($status === 'ok') {
                $readyLabels[] = $label;
            } elseif ($status === 'skipped_by_operator_policy') {
                $skippedLabels[] = $label;
            } else {
                $blockedLabels[] = $label;
            }
            $skipPolicy = is_array($gate['skip_policy'] ?? null) ? $gate['skip_policy'] : [];
            $items[] = [
                'key' => (string)($gate['key'] ?? $label),
                'label' => $label,
                'status' => $status,
                'original_status' => (string)($gate['original_status'] ?? ''),
                'reason' => (string)($gate['reason'] ?? ''),
                'display_reason' => (string)($gate['display_reason'] ?? ($gate['detail'] ?? '')),
                'next_action' => (string)($gate['next_action'] ?? ''),
                'category' => (string)($gate['category'] ?? ''),
                'severity' => (string)($gate['severity'] ?? ''),
                'target_page' => (string)($gate['target_page'] ?? ''),
                'target_tab' => (string)($gate['target_tab'] ?? ''),
                'target_platform' => (string)($gate['target_platform'] ?? ''),
                'target_agent_tab' => (string)($gate['target_agent_tab'] ?? ''),
                'target_revenue_tab' => (string)($gate['target_revenue_tab'] ?? ''),
                'skip_policy' => $skipPolicy,
            ];
        }

        $readyCount = count($readyLabels);
        $skippedCount = count($skippedLabels);
        $blockedCount = count($blockedLabels);
        $totalCount = $readyCount + $skippedCount + $blockedCount;
        return [
            'status' => $blockedCount > 0 ? 'blocked' : ($skippedCount > 0 ? 'warning' : ($readyCount > 0 ? 'ok' : 'unknown')),
            'display' => $totalCount > 0
                ? '判断依据 可用 ' . $readyCount . ($skippedCount > 0 ? ' / 跳过 ' . $skippedCount : '') . ' / 待补 ' . $blockedCount
                : '判断依据 未加载',
            'ready_count' => $readyCount,
            'skipped_count' => $skippedCount,
            'blocked_count' => $blockedCount,
            'ready_labels' => array_slice($readyLabels, 0, 6),
            'skipped_labels' => array_slice($skippedLabels, 0, 6),
            'blocked_labels' => array_slice($blockedLabels, 0, 6),
            'items' => $items,
            'read_only' => true,
            'auto_write_ota' => false,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $basisItems
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function pricingAiDecisionResolutionPlan(array $basisItems, array $sourceChannels = []): array
    {
        $items = [];
        $skippedItems = [];
        foreach ($basisItems as $basisItem) {
            if (!is_array($basisItem) || ($basisItem['status'] ?? '') === 'ok') {
                continue;
            }
            if (($basisItem['status'] ?? '') === 'skipped_by_operator_policy') {
                $code = (string)($basisItem['key'] ?? '');
                $evidenceCode = (string)($basisItem['reason'] ?? '');
                $resolution = $this->pricingAiDecisionResolutionSpec($code, $evidenceCode);
                $skippedItems[] = [
                    'code' => $code,
                    'input_type' => $this->pricingAiReviewInputType($code, $evidenceCode),
                    'evidence_code' => $evidenceCode,
                    'status' => 'skipped_by_operator_policy',
                    'resolution_action' => $resolution['resolution_action'],
                    'acceptance_check' => $resolution['acceptance_check'],
                    'unblocks' => $resolution['unblocks'],
                    'forbidden_shortcut' => $resolution['forbidden_shortcut'],
                    'skip_policy' => is_array($basisItem['skip_policy'] ?? null) ? $basisItem['skip_policy'] : [],
                ];
                continue;
            }

            $code = (string)($basisItem['key'] ?? '');
            $evidenceCode = (string)($basisItem['reason'] ?? '');
            $resolution = $this->pricingAiDecisionResolutionSpec($code, $evidenceCode);
            $items[] = [
                'order' => count($items) + 1,
                'code' => $code !== '' ? $code : ($evidenceCode !== '' ? $evidenceCode : 'review_input_' . (count($items) + 1)),
                'input_type' => $this->pricingAiReviewInputType($code, $evidenceCode),
                'evidence_code' => $evidenceCode,
                'status' => 'pending_evidence',
                'severity' => (string)($basisItem['severity'] ?? ''),
                'target_page' => (string)($basisItem['target_page'] ?? ($resolution['target_page'] ?? '')),
                'target_tab' => (string)($basisItem['target_tab'] ?? ($resolution['target_tab'] ?? '')),
                'target_platform' => (string)($basisItem['target_platform'] ?? ($resolution['target_platform'] ?? '')),
                'target_agent_tab' => (string)($basisItem['target_agent_tab'] ?? ($resolution['target_agent_tab'] ?? '')),
                'target_revenue_tab' => (string)($basisItem['target_revenue_tab'] ?? ($resolution['target_revenue_tab'] ?? '')),
                'resolution_action' => $resolution['resolution_action'],
                'acceptance_check' => $resolution['acceptance_check'],
                'unblocks' => $resolution['unblocks'],
                'forbidden_shortcut' => $resolution['forbidden_shortcut'],
            ];
        }

        return [
            'status' => $items === [] ? 'ready_for_ai_review' : 'has_pending_evidence',
            'source_scope' => count($sourceChannels) === 1 && $sourceChannels[0] === 'ctrip' ? 'ctrip_ota_channel' : 'ota_channel',
            'source_channels' => array_values($sourceChannels),
            'metric_scope' => 'ota_channel',
            'item_count' => count($items),
            'pending_count' => count($items),
            'skipped_count' => count($skippedItems),
            'approval_allowed_after_resolution' => $items === [],
            'post_resolution_gate' => 'ai_decision_review_contract.approval_allowed',
            'post_resolution_verifier' => 'C:\\xampp\\php\\php.exe vendor\\bin\\phpunit --colors=never tests\\RevenueAiOverviewServiceTest.php',
            'forbidden_actions' => [
                'fill_missing_evidence_with_defaults',
                'approve_ai_advice_without_resolving_inputs',
                'auto_write_ota',
                'auto_create_operation_execution_intent',
                'promote_ota_scope_to_whole_hotel_truth',
            ],
            'items' => $items,
            'skipped_items' => $skippedItems,
        ];
    }

    /**
     * @param array<string, mixed> $resolutionPlan
     * @return array<string, mixed>
     */
    private function pricingAiDecisionReviewContract(array $resolutionPlan): array
    {
        $hasPendingEvidence = (int)($resolutionPlan['pending_count'] ?? 0) > 0;

        return [
            'status' => $hasPendingEvidence ? 'blocked_by_review_inputs' : 'ready_for_human_ai_decision',
            'review_mode' => 'manual_review_only',
            'approval_allowed' => !$hasPendingEvidence,
            'operation_intake_allowed' => false,
            'auto_apply_ai_advice' => false,
            'required_input_count' => (int)($resolutionPlan['item_count'] ?? 0),
            'resolution_plan' => $resolutionPlan,
            'allowed_decision_outputs' => [
                [
                    'code' => 'request_revenue_metric_evidence',
                    'allowed' => true,
                    'next_gate' => 'resolve_revenue_metric_gap',
                ],
                [
                    'code' => 'record_manual_review_note',
                    'allowed' => true,
                    'next_gate' => 'manual_review_workflow_connected',
                ],
                [
                    'code' => 'reject_ai_advice',
                    'allowed' => true,
                    'next_gate' => 'new_revenue_ai_review',
                ],
                [
                    'code' => 'approve_ai_advice_for_operation_intake',
                    'allowed' => !$hasPendingEvidence,
                    'next_gate' => 'operator_creates_execution_intent',
                ],
            ],
            'forbidden_actions' => [
                'auto_apply_ai_advice',
                'auto_write_ota',
                'auto_create_operation_execution_intent',
                'claim_ai_decision_final_without_review_record',
                'promote_ota_scope_to_whole_hotel_truth',
            ],
            'protected_boundary' => 'manual_review_requires_explicit_evidence_no_auto_apply',
        ];
    }

    /**
     * @param array<string, mixed> $pricingReadiness
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function pricingAiToOperationHandoff(array $pricingReadiness, array $executionSummary, string $businessDate, ?int $hotelId, array $sourceChannels): array
    {
        $reviewContract = is_array($pricingReadiness['ai_decision_review_contract'] ?? null)
            ? $pricingReadiness['ai_decision_review_contract']
            : [];
        $reviewApproved = ($reviewContract['approval_allowed'] ?? false) === true;
        $operationIntakeAllowed = ($reviewContract['operation_intake_allowed'] ?? false) === true;
        $executionIntentCount = (int)($executionSummary['total_count'] ?? 0);
        $executionStatus = (string)($executionSummary['status'] ?? '');
        $executionReason = (string)($executionSummary['reason'] ?? '');
        $existingExecutionHandoffStatus = '';
        $existingExecutionPacketStatus = '';
        if ($executionIntentCount > 0) {
            if ($executionStatus === 'pending_approval') {
                $existingExecutionHandoffStatus = 'operation_intake_waiting_human_approval';
                $existingExecutionPacketStatus = 'execution_intent_pending_approval';
            } elseif (in_array($executionStatus, ['in_progress', 'evidence_needed', 'review_needed', 'reviewed', 'partial'], true)) {
                $existingExecutionHandoffStatus = 'operation_intake_in_operation_flow';
                $existingExecutionPacketStatus = 'execution_intent_in_operation_flow';
            } elseif ($executionStatus === 'blocked') {
                $existingExecutionHandoffStatus = 'operation_intake_blocked_by_operation_execution';
                $existingExecutionPacketStatus = 'execution_intent_blocked';
            } else {
                $existingExecutionHandoffStatus = 'operation_intake_waiting_operation_progress';
                $existingExecutionPacketStatus = 'execution_intent_available';
            }
        }
        $platform = count($sourceChannels) === 1 ? $sourceChannels[0] : '';
        $candidate = [
            'source_module' => 'ota_revenue_ai_manual_review',
            'source_record_id' => 0,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => $businessDate,
            'date_end' => $businessDate,
            'target_value' => [
                'room_type_key' => '',
                'rate_plan_key' => '',
                'target_price' => null,
            ],
            'expected_metric' => '',
            'evidence' => [
                'ai_decision_review_contract_status' => (string)($reviewContract['status'] ?? ''),
                'manual_review_required' => true,
                'auto_write_ota' => false,
            ],
        ];
        $preflight = $this->pricingOperationIntakePreflightContract($candidate, $reviewApproved, $operationIntakeAllowed);

        return [
            'status' => $existingExecutionHandoffStatus !== ''
                ? $existingExecutionHandoffStatus
                : ($preflight['status'] === 'ready_for_create'
                ? 'operation_intake_ready_for_human_create'
                : 'operation_intake_blocked_by_manual_review'),
            'source_scope' => count($sourceChannels) === 1 && $sourceChannels[0] === 'ctrip' ? 'ctrip_ota_channel' : 'ota_channel',
            'source_channels' => array_values($sourceChannels),
            'target_module' => 'operation_execution',
            'target_service' => 'OperationManagementService::buildExecutionIntentPayload',
            'target_entry' => '/api/operation/execution-intents',
            'persisted' => $executionIntentCount > 0,
            'existing_execution_intent_count' => $executionIntentCount,
            'existing_execution_status' => $executionStatus,
            'existing_execution_reason' => $executionReason,
            'can_create_operation_execution' => false,
            'auto_create_operation_execution' => false,
            'operation_intake_packet' => [
                'status' => $existingExecutionPacketStatus !== ''
                    ? $existingExecutionPacketStatus
                    : ($preflight['status'] === 'ready_for_create'
                    ? 'ready_for_human_create'
                    : 'blocked_by_manual_review_packet'),
                'candidate_source_module' => $candidate['source_module'],
                'candidate_object_type' => $candidate['object_type'],
                'candidate_action_type' => $candidate['action_type'],
                'candidate_platform' => $candidate['platform'],
                'candidate_blocked_reason' => $executionIntentCount > 0 ? $executionReason : $preflight['primary_blocker'],
                'candidate_payload_template' => $candidate,
                'operation_intake_preflight_contract' => $preflight,
                'existing_execution_summary' => $executionIntentCount > 0 ? [
                    'status' => $executionStatus,
                    'reason' => $executionReason,
                    'total_count' => $executionIntentCount,
                    'next_action' => (string)($executionSummary['next_action'] ?? ''),
                ] : null,
            ],
            'forbidden_actions' => [
                'auto_create_operation_execution_intent',
                'call_create_execution_intent_before_ai_review_approval',
                'mark_operation_executed_without_evidence',
                'claim_operation_roi_ready',
            ],
            'protected_boundary' => 'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
        ];
    }

    /**
     * @param array<string, mixed> $aiToOperationHandoff
     * @param array<string, mixed> $executionSummary
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function pricingOperationToInvestmentHandoff(
        array $aiToOperationHandoff,
        array $executionSummary,
        string $businessDate,
        ?int $hotelId,
        array $sourceChannels
    ): array {
        $roiGate = $this->pricingOperationRoiGate($executionSummary);
        $operationRoiReady = $roiGate['ready'];
        $sourceScope = count($sourceChannels) === 1 && $sourceChannels[0] === 'ctrip'
            ? 'ctrip_ota_channel_to_operation_roi'
            : 'ota_channel_to_operation_roi';
        $upstreamStatus = (string)($aiToOperationHandoff['status'] ?? '');
        $blockedReasons = $operationRoiReady
            ? ['decision_record.readiness_ready']
            : ['closed_operating_roi_missing', 'operation_process_closure_missing'];
        if ($upstreamStatus !== 'operation_intake_waiting_human_approval' && $upstreamStatus !== 'operation_intake_ready_for_human_create') {
            $blockedReasons[] = 'operation_intake_not_approved';
        }
        $blockedReasons = array_values(array_unique($blockedReasons));
        $missingEvidence = $operationRoiReady
            ? [
                $this->investmentPrecheckMissingEvidence(
                    'decision_record.readiness_ready',
                    'InvestmentDecisionSupportService::buildOverviewFromEvidence'
                ),
            ]
            : [
                $this->investmentPrecheckMissingEvidence(
                    'operation_execution.roi_ready',
                    'RevenueAiOverviewService.execution_summary.effect_review'
                ),
            ];
        $status = $operationRoiReady
            ? 'investment_precheck_waiting_decision_record'
            : 'investment_precheck_blocked_by_operation_roi';

        return [
            'status' => $status,
            'persisted' => false,
            'target_module' => 'investment_decision',
            'target_page' => 'investment-decision',
            'target_service' => 'InvestmentDecisionSupportService::buildOverviewFromEvidence',
            'target_entry' => '/api/investment-decision/overview',
            'business_date' => $businessDate,
            'hotel_id' => $hotelId,
            'source_scope' => $sourceScope,
            'metric_scope' => 'ota_channel',
            'source_channels' => array_values($sourceChannels),
            'source_platforms' => array_values($sourceChannels),
            'upstream_operation_intake_status' => $upstreamStatus,
            'operation_execution_total' => (int)($executionSummary['total_count'] ?? 0),
            'operation_roi_ready' => $operationRoiReady ? 1 : 0,
            'operation_roi_ready_count' => (int)$roiGate['ready_count'],
            'operation_roi_reason' => (string)$roiGate['reason'],
            'operating_gate_status' => $operationRoiReady ? 'closed_operating_data_ready' : 'not_ready',
            'business_closure_chain_status' => $operationRoiReady ? 'precheck_only_not_investment_ready' : 'not_closed',
            'decision_allowed' => false,
            'can_create_investment_decision' => false,
            'blocked_reasons' => $blockedReasons,
            'required_before_investment' => [
                'operation_execution_intent_created_by_human_review',
                'operation_execution_approved',
                'execution_evidence_attached',
                'operation_effect_review_completed',
                'operation_execution.roi_ready',
                'decision_record.readiness_ready',
                'human_investment_review',
            ],
            'forbidden_actions' => [
                'create_investment_decision_from_ota_channel_only',
                'claim_investment_decision_allowed',
                'create_investment_record_without_closed_operation_roi',
                'use_unreviewed_ai_advice_for_investment',
                'promote_ota_scope_to_whole_hotel_truth',
            ],
            'investment_precheck_packet' => [
                'status' => $operationRoiReady ? 'waiting_decision_record_readiness' : 'blocked_by_operation_roi',
                'source_policy' => 'read_only_precheck_from_closed_operation_gate',
                'upstream_operation_intake_status' => $upstreamStatus,
                'operation_roi_ready' => $operationRoiReady ? 1 : 0,
                'operation_roi_ready_count' => (int)$roiGate['ready_count'],
                'operating_gate_status' => $operationRoiReady ? 'closed_operating_data_ready' : 'not_ready',
                'business_closure_chain_status' => $operationRoiReady ? 'precheck_only_not_investment_ready' : 'not_closed',
                'required_gate' => 'operation_execution.roi_ready',
                'required_before' => 'investment_decision.summary.decision_allowed',
                'missing_evidence' => $missingEvidence,
                'missing_evidence_codes' => array_column($missingEvidence, 'code'),
                'protected_boundary' => 'investment_decision_requires_closed_operation_roi_not_ota_channel_only',
            ],
            'protected_boundary' => 'investment_decision_requires_closed_operation_roi_not_ota_channel_only',
        ];
    }

    /**
     * @param array<string, mixed> $executionSummary
     * @return array{ready:bool,ready_count:int,reason:string}
     */
    private function pricingOperationRoiGate(array $executionSummary): array
    {
        $effectReview = is_array($executionSummary['effect_review'] ?? null) ? $executionSummary['effect_review'] : [];
        $summaryRoiReady = (int)($executionSummary['roi_ready_count'] ?? 0);
        $effectRoiReady = (int)($effectReview['roi_ready_count'] ?? 0);
        $inputReadyCount = (int)($effectReview['input_ready_count'] ?? 0);
        $nextDayInputReady = ($effectReview['next_day_input_ready'] ?? false) === true;
        $inputReason = (string)($effectReview['input_reason'] ?? '');
        $effectReason = (string)($effectReview['reason'] ?? '');
        $summaryReason = (string)($executionSummary['reason'] ?? '');
        $reasonCandidates = array_values(array_filter([$inputReason, $effectReason, $summaryReason], static fn ($reason) => is_string($reason) && $reason !== ''));
        $readyByReason = in_array('operation_effect_review_ready', $reasonCandidates, true);
        $ready = $summaryRoiReady > 0
            || $effectRoiReady > 0
            || ($readyByReason && ($nextDayInputReady || $inputReadyCount > 0));
        $readyCount = max($summaryRoiReady, $effectRoiReady, $ready ? max(1, $inputReadyCount) : 0);

        return [
            'ready' => $ready,
            'ready_count' => $readyCount,
            'reason' => $ready ? 'operation_effect_review_ready' : ($reasonCandidates[0] ?? 'operation_execution_not_loaded'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function investmentPrecheckMissingEvidence(string $code, string $source): array
    {
        return [
            'code' => $code,
            'status' => 'missing_or_blocked',
            'source' => $source,
            'required_before' => 'investment_decision.summary.decision_allowed',
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function pricingOperationIntakePreflightContract(array $candidate, bool $reviewApproved, bool $operationIntakeAllowed): array
    {
        $targetValue = is_array($candidate['target_value'] ?? null) ? $candidate['target_value'] : [];
        $missing = [];
        if (!$reviewApproved) {
            $missing[] = $this->operationPreflightMissingField('approved_ai_advice', 'ai_decision_review_contract.approval_allowed');
        }
        if (!$operationIntakeAllowed) {
            $missing[] = $this->operationPreflightMissingField('operation_intake_allowed', 'ai_decision_review_contract.operation_intake_allowed');
        }
        foreach ([
            'hotel_id' => $candidate['hotel_id'] ?? null,
            'source_record_id' => $candidate['source_record_id'] ?? null,
            'operator_id' => null,
            'target_value.room_type_key' => $targetValue['room_type_key'] ?? null,
            'target_value.rate_plan_key' => $targetValue['rate_plan_key'] ?? null,
            'target_value.target_price' => $targetValue['target_price'] ?? null,
            'expected_metric' => $candidate['expected_metric'] ?? null,
        ] as $field => $value) {
            if ($value === null || (is_string($value) && trim($value) === '') || (is_int($value) && $value <= 0)) {
                $missing[] = $this->operationPreflightMissingField($field, 'OperationManagementService::buildExecutionIntentPayload');
            }
        }

        return [
            'status' => $missing === [] ? 'ready_for_create' : 'blocked_by_ai_review_contract',
            'target_service' => 'OperationManagementService::buildExecutionIntentPayload',
            'target_entry' => '/api/operation/execution-intents',
            'create_allowed' => false,
            'would_call_create_endpoint' => false,
            'dry_run_only' => true,
            'missing_required_field_count' => count($missing),
            'missing_required_fields' => $missing,
            'primary_blocker' => (string)($missing[0]['field'] ?? ''),
            'projected_payload_template' => [
                'source_module' => (string)($candidate['source_module'] ?? ''),
                'source_record_id' => (int)($candidate['source_record_id'] ?? 0),
                'hotel_id' => $candidate['hotel_id'] ?? null,
                'platform' => (string)($candidate['platform'] ?? ''),
                'object_type' => (string)($candidate['object_type'] ?? ''),
                'action_type' => (string)($candidate['action_type'] ?? ''),
                'target_value_required_fields' => [
                    'room_type_key',
                    'rate_plan_key',
                    'target_price',
                ],
                'expected_metric' => (string)($candidate['expected_metric'] ?? ''),
                'auto_write_ota' => false,
            ],
            'forbidden_actions' => [
                'call_create_execution_intent_before_ai_review_approval',
                'auto_create_operation_execution_intent',
                'mark_operation_executed_without_evidence',
                'claim_roi_ready_without_review',
            ],
            'protected_boundary' => 'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function operationPreflightMissingField(string $field, string $source): array
    {
        return [
            'field' => $field,
            'status' => 'missing_or_blocked',
            'source' => $source,
            'required_before' => 'POST /api/operation/execution-intents',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pricingAiDecisionResolutionSpec(string $code, string $evidenceCode): array
    {
        $map = [
            'available_room_nights_missing' => [
                'resolution_action' => 'provide_available_room_nights_or_mark_metric_unusable',
                'acceptance_check' => 'available_room_nights evidence exists or RevPAR remains explicitly not_calculable',
                'unblocks' => 'ota_contribution_revpar_review',
                'forbidden_shortcut' => 'default_available_room_nights',
            ],
            'floor_price_missing' => [
                'resolution_action' => 'provide_floor_price_or_min_rate_guard',
                'acceptance_check' => 'floor price guard is present before price recommendation approval',
                'unblocks' => 'pricing_guard_review',
                'forbidden_shortcut' => 'approve_price_without_floor_guard',
            ],
            'manual_review_workflow_not_connected' => [
                'resolution_action' => 'persist_or_attach_manual_review_record',
                'acceptance_check' => 'manual review record has reviewer, decision_status, decision_reason, and evidence_links',
                'unblocks' => 'approve_ai_advice_for_operation_intake',
                'forbidden_shortcut' => 'treat_chat_confirmation_as_persisted_review',
            ],
            'price_suggestions_empty' => [
                'resolution_action' => 'open_agent_pricing_suggestions_and_generate_pending_review_items',
                'acceptance_check' => 'price_suggestions has target-date pending or reviewed rows and review_queue.source_table stays price_suggestions',
                'unblocks' => 'manual_price_suggestion_review',
                'forbidden_shortcut' => 'auto_generate_and_apply_price',
                'target_page' => 'agent-center',
                'target_tab' => 'suggestions',
                'target_agent_tab' => 'revenue',
                'target_revenue_tab' => 'suggestions',
            ],
            'price_suggestion_generation_not_loaded' => [
                'resolution_action' => 'load_pricing_generation_preflight_before_generation',
                'acceptance_check' => 'pricing_generation_preflight is loaded from real tables or remains explicitly blocked',
                'unblocks' => 'pricing_generation_candidate_review',
                'forbidden_shortcut' => 'generate_price_suggestions_without_preflight',
                'target_page' => 'agent-center',
                'target_tab' => 'suggestions',
                'target_agent_tab' => 'revenue',
                'target_revenue_tab' => 'suggestions',
            ],
            'pricing_generation_hotel_scope_missing' => [
                'resolution_action' => 'select_or_import_ctrip_target_hotel_scope',
                'acceptance_check' => 'target system hotel ids are present before price suggestion generation',
                'unblocks' => 'pricing_generation_candidate_review',
                'forbidden_shortcut' => 'generate_price_suggestions_without_hotel_scope',
                'target_page' => 'agent-center',
                'target_tab' => 'suggestions',
                'target_agent_tab' => 'revenue',
                'target_revenue_tab' => 'suggestions',
            ],
            'room_types_empty' => [
                'resolution_action' => 'configure_enabled_room_types_and_floor_price_guards',
                'acceptance_check' => 'enabled room_types exist for the Ctrip target hotels before generating pending suggestions',
                'unblocks' => 'pricing_generation_candidate_review',
                'forbidden_shortcut' => 'generate_price_suggestions_without_room_type_contract',
                'target_page' => 'agent-center',
                'target_tab' => 'suggestions',
                'target_agent_tab' => 'revenue',
                'target_revenue_tab' => 'suggestions',
            ],
            'pricing_candidate_signals_missing' => [
                'resolution_action' => 'load_pricing_candidate_signals_before_generation',
                'acceptance_check' => 'recommendation preflight has at least one should_create candidate or explicit no-op reason',
                'unblocks' => 'pricing_generation_candidate_review',
                'forbidden_shortcut' => 'force_pending_suggestion_without_primary_signals',
                'target_page' => 'agent-center',
                'target_tab' => 'suggestions',
                'target_agent_tab' => 'revenue',
                'target_revenue_tab' => 'suggestions',
            ],
            'price_suggestions_pending_review' => [
                'resolution_action' => 'complete_manual_review_for_pending_price_suggestions',
                'acceptance_check' => 'pending suggestion is approved, approved_with_changes, rejected, or converted to operation execution intent',
                'unblocks' => 'approved_ai_advice_for_operation_intake',
                'forbidden_shortcut' => 'auto_write_ota_without_manual_review',
                'target_page' => 'agent-center',
                'target_tab' => 'suggestions',
                'target_agent_tab' => 'revenue',
                'target_revenue_tab' => 'suggestions',
            ],
            'ota_room_nights_zero' => [
                'resolution_action' => 'verify_zero_room_nights_or_correct_ota_room_nights',
                'acceptance_check' => 'zero room nights is operator-verified or corrected from source evidence',
                'unblocks' => 'adr_and_pricing_review',
                'forbidden_shortcut' => 'calculate_adr_from_zero_denominator',
            ],
            'competitor_price_fields_missing' => [
                'resolution_action' => 'provide_competitor_price_field_evidence',
                'acceptance_check' => 'competitor price fields are loaded or marked unavailable with explicit reason',
                'unblocks' => 'competitor_price_context_review',
                'forbidden_shortcut' => 'invent_competitor_price',
            ],
            'demand_forecasts_not_loaded' => [
                'resolution_action' => 'load_or_mark_7d_demand_forecast_unavailable',
                'acceptance_check' => '7-day demand forecast is loaded or unavailable state is explicit',
                'unblocks' => 'demand_context_review',
                'forbidden_shortcut' => 'invent_demand_forecast',
            ],
            'operation_execution_not_loaded' => [
                'resolution_action' => 'attach_operation_feedback_or_keep_feedback_gate_closed',
                'acceptance_check' => 'operation feedback evidence exists or operation feedback gate remains blocked',
                'unblocks' => 'operation_feedback_review',
                'forbidden_shortcut' => 'claim_operation_feedback_from_ota_only',
            ],
        ];

        if (isset($map[$evidenceCode])) {
            return $map[$evidenceCode];
        }

        return [
            'resolution_action' => $code !== '' ? 'resolve_' . $code : 'resolve_review_input',
            'acceptance_check' => $evidenceCode !== '' ? 'evidence code ' . $evidenceCode . ' is resolved or remains explicit' : 'input evidence is resolved or remains explicit',
            'unblocks' => 'ai_decision_review',
            'forbidden_shortcut' => 'hide_missing_evidence',
        ];
    }

    private function pricingAiReviewInputType(string $code, string $evidenceCode): string
    {
        if (in_array($evidenceCode, ['available_room_nights_missing', 'ota_room_nights_zero', 'floor_price_missing', 'ota_revenue_metrics_missing', 'online_daily_data_empty'], true)) {
            return 'revenue_metric_evidence';
        }

        if ($evidenceCode === 'manual_review_workflow_not_connected'
            || str_starts_with($evidenceCode, 'price_suggestions_')
            || in_array($evidenceCode, [
                'price_suggestion_generation_not_loaded',
                'pricing_generation_hotel_scope_missing',
                'room_types_empty',
                'pricing_candidate_signals_missing',
            ], true)) {
            return 'manual_review_process_gate';
        }

        if (in_array($evidenceCode, ['competitor_price_fields_missing', 'demand_forecasts_not_loaded'], true)) {
            return 'market_context_evidence';
        }

        if ($evidenceCode === 'operation_execution_not_loaded' || $code === 'operation_feedback_input') {
            return 'operation_feedback_evidence';
        }

        return 'source_data_quality_gate';
    }

    /**
     * @param array<string, mixed> $metricsSummary
     * @param array<int, array<string, mixed>> $missingDatasets
     * @param array<int, array<string, mixed>> $qualityIssues
     * @param array<string, array<string, mixed>> $signals
     * @return array<string, mixed>
     */
    private function pricingReadiness(
        array $metricsSummary,
        array $missingDatasets,
        array $qualityIssues,
        array $signals,
        array $reviewQueue = [],
        array $executionSummary = [],
        array $sourceChannels = [],
        array $pricingGenerationPreflight = []
    ): array
    {
        $competitorSignal = is_array($signals['competitor_price_warning'] ?? null) ? $signals['competitor_price_warning'] : [];
        $demandSignal = is_array($signals['demand_7d'] ?? null) ? $signals['demand_7d'] : [];
        $demandSignalReason = (string)($demandSignal['reason'] ?? 'demand_forecasts_not_loaded');
        $demandSignalReady = in_array($demandSignalReason, ['demand_forecasts_available', 'demand_forecasts_high_demand'], true);
        $reviewQueueReady = $this->reviewQueueIsConnected($reviewQueue);
        $reviewQueueReason = $reviewQueueReady ? '' : (string)($reviewQueue['reason'] ?? 'manual_review_workflow_not_connected');
        $gates = [
            $this->otaMetricsPricingGate($metricsSummary),
            $this->pricingGate(
                'data_quality',
                '数据质量状态',
                $this->hasBlockingQualityIssue($qualityIssues) === false,
                'ok',
                $this->pricingQualityIssueReason($qualityIssues),
                '当前未发现登录失效、采集失败或数据过期阻断项。'
            ),
            $this->pricingGate(
                'competitor_price',
                '竞对价格位置',
                in_array(($competitorSignal['reason'] ?? ''), [
                    'competitor_price_above_competitor',
                    'competitor_price_below_competitor_review_required',
                    'competitor_price_aligned',
                ], true),
                'ok',
                'competitor_price_fields_missing',
                '已命中本店均价和竞对均价，只用于人工复核。'
            ),
            $this->pricingGate(
                'revpar_denominator',
                '全酒店可售房晚',
                $this->numeric($metricsSummary['totals']['available_room_nights'] ?? null) !== null
                    && (float)($metricsSummary['totals']['available_room_nights'] ?? 0) > 0,
                'ok',
                'available_room_nights_missing',
                '已具备 OTA贡献RevPAR 分母。'
            ),
            $this->pricingGate(
                'demand_signal_7d',
                '未来 7 天需求信号',
                $demandSignalReady,
                'ok',
                $demandSignalReason,
                $demandSignalReady
                    ? '已读取 demand_forecasts 未来 7 天预测；只作为人工调价复核输入。'
                    : '未来 7 天需求预测尚不可用于调价判断。'
            ),
            $this->pricingGate(
                'floor_price',
                '最低保护价',
                false,
                'ok',
                'floor_price_missing',
                '尚未接入房型/价格计划级最低保护价。'
            ),
            $this->pricingGate(
                'manual_review_workflow',
                '人工审核工作流',
                $reviewQueueReady,
                'ok',
                $reviewQueueReason !== '' ? $reviewQueueReason : 'manual_review_workflow_not_connected',
                $reviewQueueReady
                    ? '已接入 price_suggestions 本地审核队列，可人工批准、修改后批准、拒绝或转执行；不写 OTA。'
                    : '尚未接入可读取的建议版本、批准/拒绝/转执行审计流。'
            ),
        ];
        if ($this->pricingGenerationPreflightIsLoaded($pricingGenerationPreflight)) {
            $preflightStatus = (string)($pricingGenerationPreflight['status'] ?? '');
            $preflightReady = in_array($preflightStatus, ['ready_for_manual_generation', 'pending_review_exists'], true);
            $gates[] = $this->pricingGate(
                'pricing_generation_preflight',
                '调价建议生成预检',
                $preflightReady,
                'ok',
                (string)($pricingGenerationPreflight['reason'] ?? 'price_suggestion_generation_not_loaded'),
                (string)($pricingGenerationPreflight['detail'] ?? '')
            );
        }
        $gates[] = $this->operationFeedbackInputGate($executionSummary);
        $gates = array_map(function (array $gate): array {
            return $this->applyPricingTemporarySkipPolicyToGate($gate);
        }, $gates);

        $blockingReasons = [];
        $blockedLabels = [];
        $skippedLabels = [];
        $nextActions = [];
        foreach ($gates as $gate) {
            if ($this->pricingGateSkippedByOperatorPolicy($gate)) {
                $skippedLabels[] = (string)$gate['label'];
                continue;
            }
            if (($gate['status'] ?? '') !== 'ok') {
                $blockingReasons[] = (string)$gate['reason'];
                $blockedLabels[] = (string)$gate['label'];
                if (is_string($gate['next_action'] ?? null) && $gate['next_action'] !== '') {
                    $nextActions[] = $gate['next_action'];
                }
            }
        }

        $resolutionPlan = $this->pricingAiDecisionResolutionPlan($gates, $sourceChannels);
        $reviewContract = $this->pricingAiDecisionReviewContract($resolutionPlan);
        $hasSkippedGates = $skippedLabels !== [];
        $overallStatus = $blockingReasons === [] ? ($hasSkippedGates ? 'warning' : 'ok') : 'blocked';
        $summary = $blockingReasons === []
            ? ($hasSkippedGates
                ? '已按人工策略暂时跳过抓不到的定价输入缺口；可继续做只读 AI 分析，但不自动生成待审建议、不写 OTA。'
                : '已具备可审核调价建议的前置条件；第一版仍需人工审核，不自动写 OTA。')
            : '暂不生成调价建议，' . implode('、', array_slice($blockedLabels, 0, 4)) . (count($blockedLabels) > 4 ? '等条件未满足。' : '未满足。');

        return [
            'overall_status' => $overallStatus,
            'can_generate_recommendation' => $blockingReasons === [],
            'can_auto_write_ota' => false,
            'manual_review_required' => true,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'skipped_reasons' => array_values(array_unique(array_filter(array_map(
                static fn(array $gate): string => (string)($gate['status'] ?? '') === 'skipped_by_operator_policy' ? (string)($gate['reason'] ?? '') : '',
                $gates
            )))),
            'gates' => $gates,
            'next_actions' => array_slice(array_values(array_unique($nextActions)), 0, 4),
            'pricing_generation_preflight' => $pricingGenerationPreflight,
            'ai_decision_review_contract' => $reviewContract,
            'ai_decision_resolution_plan' => $resolutionPlan,
            'temporary_skip_policy' => [
                'active' => $hasSkippedGates,
                'reason' => self::TEMPORARY_PRICING_SKIP_REASON,
                'scope' => 'pricing_input_gaps_only',
                'skipped_gate_count' => count($skippedLabels),
                'skipped_gate_labels' => array_slice($skippedLabels, 0, 8),
                'preserve_missing_evidence' => true,
                'auto_write_ota' => false,
                'auto_generate_pending_suggestions' => false,
                'operation_intake_allowed' => false,
                'investment_decision_allowed' => false,
            ],
            'review_policy' => [
                'mode' => 'manual_review_only',
                'auto_write_ota' => false,
                'requires_versioned_approval' => true,
                'note' => 'Phase 1B only exposes readiness blockers. It does not generate or write OTA prices.',
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $metricsSummary
     * @return array<string, mixed>
     */
    private function otaMetricsPricingGate(array $metricsSummary): array
    {
        $status = (string)($metricsSummary['status'] ?? 'empty');
        $roomRevenue = $this->numeric($metricsSummary['totals']['room_revenue'] ?? null);
        $roomNights = $this->numeric($metricsSummary['totals']['room_nights'] ?? null);
        $hasFactRows = $status !== 'empty';
        $ready = $hasFactRows && $roomRevenue !== null && $roomNights !== null && $roomNights > 0;

        if (!$hasFactRows) {
            $reason = 'online_daily_data_empty';
            $detail = '目标经营日期没有可用 OTA 入库数据。';
        } elseif ($roomRevenue === null || $roomNights === null) {
            $reason = 'ota_revenue_metrics_missing';
            $detail = '已命中 OTA 目标日数据，但房费收入或间夜指标缺失，不能形成调价判断。';
        } elseif ($roomNights <= 0) {
            $reason = 'ota_room_nights_zero';
            $detail = '已命中 OTA 目标日数据，但间夜为 0，不能计算 ADR 或形成调价判断。';
        } else {
            $reason = '';
            $detail = '已命中 OTA 房费收入和间夜，可计算 ADR。';
        }

        return $this->pricingGate(
            'ota_metrics',
            '昨日 OTA 收入和间夜',
            $ready,
            'ok',
            $reason,
            $detail
        );
    }

    private function pricingGate(string $key, string $label, bool $ready, string $readyStatus, string $blockedReason, string $detail): array
    {
        $targetMeta = $blockedReason !== ''
            ? $this->issueReasonMeta($blockedReason, '', 'pricing_gate')
            : ['target_page' => '', 'target_tab' => '', 'target_platform' => '', 'target_agent_tab' => '', 'target_revenue_tab' => ''];
        $meta = $ready
            ? ['severity' => 'low', 'category' => 'ready', 'display_reason' => $detail, 'next_action' => '继续只读观察，不自动写 OTA。']
            : $targetMeta;
        return [
            'key' => $key,
            'label' => $label,
            'status' => $ready ? $readyStatus : 'blocked',
            'reason' => $ready ? '' : $blockedReason,
            'detail' => $detail,
            'severity' => $meta['severity'] ?? ($ready ? 'low' : 'medium'),
            'category' => $meta['category'] ?? ($ready ? 'ready' : 'pricing_gate'),
            'display_reason' => $ready ? $detail : ($meta['display_reason'] ?? $this->issueMessage($blockedReason)),
            'next_action' => $meta['next_action'] ?? ($ready ? '继续只读观察，不自动写 OTA。' : '进入数据健康面板复核。'),
            'target_page' => $targetMeta['target_page'] ?? '',
            'target_tab' => $targetMeta['target_tab'] ?? '',
            'target_platform' => $targetMeta['target_platform'] ?? '',
            'target_agent_tab' => $targetMeta['target_agent_tab'] ?? '',
            'target_revenue_tab' => $targetMeta['target_revenue_tab'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    private function applyPricingTemporarySkipPolicyToGate(array $gate): array
    {
        if ((string)($gate['status'] ?? '') !== 'blocked') {
            return $gate;
        }

        $reason = (string)($gate['reason'] ?? '');
        if (!in_array($reason, self::TEMPORARY_SKIPPABLE_PRICING_REASONS, true)) {
            return $gate;
        }

        $displayReason = (string)($gate['display_reason'] ?? ($gate['detail'] ?? $reason));
        $gate['original_status'] = 'blocked';
        $gate['status'] = 'skipped_by_operator_policy';
        $gate['severity'] = 'low';
        $gate['category'] = (string)($gate['category'] ?? 'pricing_gate');
        $gate['display_reason'] = '已按人工策略暂时跳过；原缺口：' . $displayReason;
        $gate['next_action'] = '继续保留缺口证据；后续抓到真实房型、保护价、需求预测或竞对样本后再恢复门禁。';
        $gate['skip_policy'] = [
            'reason' => self::TEMPORARY_PRICING_SKIP_REASON,
            'original_reason' => $reason,
            'preserve_missing_evidence' => true,
            'auto_write_ota' => false,
            'auto_generate_pending_suggestions' => false,
            'operation_intake_allowed' => false,
            'investment_decision_allowed' => false,
        ];
        return $gate;
    }

    /**
     * @param array<string, mixed> $gate
     */
    private function pricingGateSkippedByOperatorPolicy(array $gate): bool
    {
        return (string)($gate['status'] ?? '') === 'skipped_by_operator_policy';
    }

    /**
     * @param array<string, mixed> $executionSummary
     * @return array<string, mixed>
     */
    private function operationFeedbackInputGate(array $executionSummary): array
    {
        $effectReview = is_array($executionSummary['effect_review'] ?? null) ? $executionSummary['effect_review'] : [];
        $readyCount = (int)($effectReview['input_ready_count'] ?? 0);
        $ready = ($effectReview['next_day_input_ready'] ?? false) === true || $readyCount > 0;
        $blockedReason = $ready
            ? ''
            : (string)($effectReview['input_reason'] ?? ($effectReview['reason'] ?? ($executionSummary['reason'] ?? 'operation_execution_not_loaded')));
        if ($blockedReason === '') {
            $blockedReason = 'operation_execution_not_loaded';
        }

        return $this->pricingGate(
            'operation_feedback_input',
            '上一轮调价效果输入',
            $ready,
            'ok',
            $ready ? 'operation_effect_review_ready' : $blockedReason,
            $ready
                ? '已具备 ' . max(1, $readyCount) . ' 条 ROI/增量收入证据，可作为明日人工调价判断输入。'
                : '尚未形成可用的执行复盘和 ROI/增量收入证据，不能作为明日调价判断输入。'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $qualityIssues
     */
    private function hasBlockingQualityIssue(array $qualityIssues): bool
    {
        return $this->pricingQualityIssueReason($qualityIssues) !== 'data_not_complete';
    }

    /**
     * @param array<int, array<string, mixed>> $qualityIssues
     */
    private function pricingQualityIssueReason(array $qualityIssues): string
    {
        foreach ($qualityIssues as $issue) {
            $category = (string)($issue['category'] ?? '');
            $reason = (string)($issue['reason'] ?? '');
            if (in_array($category, ['auth', 'parser', 'stale', 'sync', 'source', 'field', 'network', 'platform'], true)) {
                return $reason !== '' ? $reason : 'data_not_complete';
            }
            if (in_array($reason, ['AUTH_EXPIRED', 'CAPTCHA_REQUIRED', 'DATA_STALE', 'source_disabled', 'sync_failed', 'FIELD_MISSING', 'PARSER_MISMATCH'], true)) {
                return $reason;
            }
        }
        return 'data_not_complete';
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'ctrip' => '携程',
            'meituan' => '美团',
            default => $channel,
        };
    }

    private function issueMessage(string $reason): string
    {
        return match ($reason) {
            'available_room_nights_missing' => '暂缺可信全酒店可售房晚数据。',
            'competitor_price_fields_missing' => '暂缺竞对价格字段。',
            'competitor_price_above_competitor' => '本店均价高于竞对均价，需人工复核是否存在价格倒挂或竞争力风险。',
            'competitor_price_below_competitor_review_required' => '本店均价低于竞对均价，需复核是否低于保护价后再判断调价。',
            'competitor_price_aligned' => '本店均价与竞对均价接近。',
            'floor_price_missing' => '暂缺最低保护价。',
            'manual_review_workflow_not_connected' => '暂未接入人工审核工作流。',
            'price_suggestions_missing' => '定价建议表不存在。',
            'price_suggestions_required_fields_missing' => '定价建议表缺少必要字段。',
            'price_suggestions_read_failed' => '定价建议审核队列读取失败。',
            'price_suggestions_empty' => '目标经营日期暂无存量调价建议。',
            'price_suggestions_pending_review' => '存在待人工审核调价建议。',
            'price_suggestions_reviewed' => '目标经营日期调价建议已处理。',
            'agent_logs_not_loaded' => '收益管理 Agent 日志尚未读取。',
            'agent_logs_missing' => 'Agent 日志表不存在。',
            'agent_logs_required_fields_missing' => 'Agent 日志表缺少必要字段。',
            'agent_logs_read_failed' => '收益管理 Agent 日志读取失败。',
            'agent_logs_empty' => '目标经营日期暂无收益管理 Agent 操作日志。',
            'agent_logs_available' => '已读取收益管理 Agent 操作日志。',
            'agent_logs_warning_present' => '收益管理 Agent 存在警告日志。',
            'agent_logs_error_present' => '收益管理 Agent 存在错误日志。',
            'online_daily_data_empty' => '目标经营日期没有可用 OTA 入库数据。',
            'ota_revenue_metrics_missing' => '已命中 OTA 目标日数据，但房费收入或间夜指标缺失。',
            'ota_room_nights_zero' => '已命中 OTA 目标日数据，但间夜为 0，无法计算 ADR。',
            'ZERO_CONFIRMED' => '渠道明确确认目标经营日期无数据。',
            'DATE_NOT_AVAILABLE' => '目标经营日期未命中可用入库数据。',
            'DATA_STALE' => '平台数据过期，目标经营日期没有新入库证据。',
            'AUTH_EXPIRED' => '登录或授权已失效。',
            'CAPTCHA_REQUIRED' => '需要验证码或人工登录确认。',
            'PAGE_CHANGED' => '平台页面结构变化，采集解析需复核。',
            'FIELD_MISSING' => '关键字段缺失。',
            'PARSER_MISMATCH' => '解析器与平台返回结构不匹配。',
            'NETWORK_ERROR' => '平台请求网络异常。',
            'RATE_LIMITED' => '平台请求被限流。',
            default => $reason,
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string|null>
     */
    private function mapSourceStatus(array $row): array
    {
        if ($row === []) {
            return [
                'status' => 'unknown',
                'label' => '状态未知',
                'reason' => 'source_status_missing',
                'detail' => 'platform_data_sources has no matching status row.',
                'last_success_at' => null,
            ];
        }
        $status = strtolower(trim((string)($row['last_sync_status'] ?? $row['status'] ?? '')));
        $error = trim((string)($row['last_error'] ?? ''));
        $sourceStatus = strtolower(trim((string)($row['status'] ?? '')));
        if ($this->isDisabledFlag($row['enabled'] ?? null) || $sourceStatus === 'disabled') {
            return [
                'status' => 'unauthorized',
                'label' => '已禁用',
                'reason' => 'source_disabled',
                'detail' => $error !== '' ? $error : '平台数据源已禁用。',
                'last_success_at' => null,
            ];
        }
        if ($sourceStatus === 'waiting_config') {
            return [
                'status' => 'unauthorized',
                'label' => '待授权',
                'reason' => 'waiting_config',
                'detail' => $error !== '' ? $error : '平台数据源未处于 ready 状态。',
                'last_success_at' => null,
            ];
        }
        if (in_array($status, ['success', 'ok', 'ready'], true)) {
            return [
                'status' => 'ok',
                'label' => '同步成功',
                'reason' => '',
                'detail' => 'platform_data_sources reports successful sync.',
                'last_success_at' => $this->nullableText($row['last_sync_time'] ?? null),
            ];
        }
        if (in_array($status, ['empty_confirmed', 'zero_confirmed', 'no_data'], true)) {
            return [
                'status' => 'empty_confirmed',
                'label' => '确认无数据',
                'reason' => 'ZERO_CONFIRMED',
                'detail' => $error !== '' ? $error : '平台同步明确确认目标日期无可用业务数据。',
                'last_success_at' => $this->nullableText($row['last_sync_time'] ?? $row['update_time'] ?? null),
            ];
        }
        if ($status === 'partial_success') {
            return [
                'status' => 'partial',
                'label' => '部分可用',
                'reason' => 'partial_success',
                'detail' => $error !== '' ? $error : '同步部分模块成功，需复核缺失字段。',
                'last_success_at' => $this->nullableText($row['last_sync_time'] ?? null),
            ];
        }
        if (in_array($status, ['failed', 'fail', 'error'], true)) {
            $reason = $this->classifyErrorReason($error);
            $authBlocked = in_array($reason, ['AUTH_EXPIRED', 'CAPTCHA_REQUIRED'], true);
            return [
                'status' => $authBlocked ? 'unauthorized' : 'failed',
                'label' => $authBlocked ? '授权受阻' : '采集失败',
                'reason' => $reason,
                'detail' => $error !== '' ? $error : '平台同步失败。',
                'last_success_at' => null,
            ];
        }
        return [
            'status' => 'unknown',
            'label' => '状态未知',
            'reason' => 'source_status_unknown',
            'detail' => $error !== '' ? $error : '未命中明确同步状态。',
            'last_success_at' => $this->nullableText($row['last_sync_time'] ?? null),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function classifyErrorReason(string $message): string
    {
        $text = trim($message);
        if ($text === '') {
            return 'sync_failed';
        }
        if (preg_match('/captcha|verify code|slider|human|验证码|滑块|人机/i', $text) === 1) {
            return 'CAPTCHA_REQUIRED';
        }
        if (preg_match('/login|auth|unauthori|expired|cookie|token|session|登录|授权|失效|过期|未登录/i', $text) === 1) {
            return 'AUTH_EXPIRED';
        }
        if (preg_match('/page changed|page structure|selector|dom|页面结构|页面改版|选择器/i', $text) === 1) {
            return 'PAGE_CHANGED';
        }
        if (preg_match('/field missing|required field|missing field|字段缺失|缺字段|关键字段/i', $text) === 1) {
            return 'FIELD_MISSING';
        }
        if (preg_match('/parser|parse|mismatch|json|解析|结构不匹配/i', $text) === 1) {
            return 'PARSER_MISMATCH';
        }
        if (preg_match('/network|timeout|connection|连接|超时|网络/i', $text) === 1) {
            return 'NETWORK_ERROR';
        }
        if (preg_match('/rate limit|too many|限流|频率/i', $text) === 1) {
            return 'RATE_LIMITED';
        }
        return 'sync_failed';
    }

    private function isDisabledFlag(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_bool($value)) {
            return $value === false;
        }
        $text = strtolower(trim((string)$value));
        return in_array($text, ['0', 'false', 'off', 'no', 'disabled'], true);
    }

    private function isBeforeBusinessDate(mixed $time, string $businessDate): bool
    {
        $date = $this->datePart($time);
        return $date !== null && $date < $businessDate;
    }

    private function datePart(mixed $time): ?string
    {
        $text = trim((string)($time ?? ''));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1) {
            return substr($text, 0, 10);
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * @param int|null $hotelId
     * @return array<string, array<string, mixed>>
     */
    private function sourceStatusRows(?int $hotelId): array
    {
        if (!$this->tableExists('platform_data_sources')) {
            return [];
        }
        $columns = $this->tableColumns('platform_data_sources');
        $fields = array_values(array_intersect([
            'id',
            'system_hotel_id',
            'platform',
            'data_type',
            'status',
            'enabled',
            'last_sync_time',
            'last_sync_status',
            'last_error',
            'update_time',
        ], array_keys($columns)));
        if ($fields === []) {
            return [];
        }
        try {
            $query = Db::name('platform_data_sources')
                ->field(implode(',', $fields))
                ->whereIn('platform', self::CHANNELS)
                ->order('last_sync_time', 'desc')
                ->order('update_time', 'desc')
                ->order('id', 'desc');
            if ($hotelId !== null && isset($columns['system_hotel_id'])) {
                $query->where('system_hotel_id', $hotelId);
            }
            $rows = $query->select()->toArray();
        } catch (\Throwable) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $channel = strtolower(trim((string)($row['platform'] ?? '')));
            if (!in_array($channel, self::CHANNELS, true) || isset($result[$channel])) {
                continue;
            }
            $result[$channel] = $row;
        }
        return $result;
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        try {
            return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, bool>
     */
    private function tableColumns(string $table): array
    {
        $columns = [];
        try {
            foreach (Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`') as $row) {
                if (!empty($row['Field'])) {
                    $columns[(string)$row['Field']] = true;
                }
            }
        } catch (\Throwable) {
            return [];
        }
        return $columns;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function uniqueIssueRows(array $rows): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = (string)($row['key'] ?? $row['reason'] ?? json_encode($row));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichIssueRows(array $rows, string $type): array
    {
        return array_map(fn(array $row): array => $this->enrichIssueRow($row, $type), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichIssueRow(array $row, string $type): array
    {
        $reason = trim((string)($row['reason'] ?? 'data_not_complete'));
        $channel = strtolower(trim((string)($row['channel'] ?? '')));
        $meta = $this->issueReasonMeta($reason, $channel, $type);
        $label = trim((string)($row['label'] ?? ''));
        if ($label === '' && in_array($channel, self::CHANNELS, true)) {
            $label = $this->channelLabel($channel) . '数据状态';
        }
        if ($label === '') {
            $label = $type === 'missing_dataset' ? '缺失数据集' : '数据质量问题';
        }
        return array_merge($row, [
            'type' => $row['type'] ?? $type,
            'label' => $label,
            'severity' => $row['severity'] ?? $meta['severity'],
            'category' => $row['category'] ?? $meta['category'],
            'display_reason' => $row['display_reason'] ?? $meta['display_reason'],
            'next_action' => $row['next_action'] ?? $meta['next_action'],
            'target_page' => $row['target_page'] ?? $meta['target_page'],
            'target_tab' => $row['target_tab'] ?? $meta['target_tab'],
            'target_platform' => $row['target_platform'] ?? ($channel !== '' ? $channel : $meta['target_platform']),
            'evidence' => $row['evidence'] ?? ($row['message'] ?? $row['detail'] ?? $this->issueMessage($reason)),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function issueReasonMeta(string $reason, string $channel = '', string $type = 'quality_issue'): array
    {
        $platformLabel = in_array($channel, self::CHANNELS, true) ? $this->channelLabel($channel) : 'OTA';
        $base = [
            'severity' => 'medium',
            'category' => 'data',
            'display_reason' => $this->issueMessage($reason),
            'next_action' => '进入数据健康面板复核原始状态。',
            'target_page' => 'online-data',
            'target_tab' => 'data-health',
            'target_platform' => $channel,
        ];
        $overrides = [
            'AUTH_EXPIRED' => ['severity' => 'high', 'category' => 'auth', 'display_reason' => $platformLabel . '登录或授权已失效，Cookie/Profile 状态需复核。', 'next_action' => '进入数据健康面板复核登录/Cookie 状态，必要时重新登录。'],
            'CAPTCHA_REQUIRED' => ['severity' => 'high', 'category' => 'auth', 'display_reason' => $platformLabel . '需要验证码或人工登录确认。', 'next_action' => '进入平台账号状态处理验证码或人工登录。'],
            'PAGE_CHANGED' => ['severity' => 'high', 'category' => 'parser', 'display_reason' => $platformLabel . '页面结构变化，采集解析需复核。', 'next_action' => '复核最近一次采集证据和字段映射。'],
            'FIELD_MISSING' => ['severity' => 'high', 'category' => 'field', 'display_reason' => $platformLabel . '关键字段缺失。', 'next_action' => '进入数据健康面板查看缺字段明细。'],
            'PARSER_MISMATCH' => ['severity' => 'high', 'category' => 'parser', 'display_reason' => $platformLabel . '解析器与平台返回结构不匹配。', 'next_action' => '复核平台返回样本和解析规则。'],
            'NETWORK_ERROR' => ['severity' => 'medium', 'category' => 'network', 'display_reason' => $platformLabel . '平台请求网络异常。', 'next_action' => '查看同步日志并重试采集。'],
            'RATE_LIMITED' => ['severity' => 'medium', 'category' => 'platform', 'display_reason' => $platformLabel . '平台请求被限流。', 'next_action' => '暂停高频重试，稍后复核采集任务。'],
            'DATE_NOT_AVAILABLE' => ['severity' => 'medium', 'category' => 'data', 'display_reason' => $platformLabel . '未命中目标经营日期入库数据。', 'next_action' => '进入数据健康面板检查目标日期采集和入库记录。'],
            'DATA_STALE' => ['severity' => 'high', 'category' => 'stale', 'display_reason' => $platformLabel . '数据过期，目标经营日期没有新入库证据。', 'next_action' => '进入数据健康面板复核最后同步时间并重新采集。'],
            'available_room_nights_missing' => ['severity' => 'high', 'category' => 'denominator', 'display_reason' => '暂缺可信全酒店可售房晚数据。', 'next_action' => '补齐全酒店可售房晚口径后再计算 OTA贡献RevPAR。', 'target_platform' => 'hotel'],
            'online_daily_data_empty' => ['severity' => 'medium', 'category' => 'data', 'display_reason' => $platformLabel . '目标经营日期没有可用 OTA 入库数据。', 'next_action' => '进入数据健康面板检查该日期采集、导入和字段校验状态。'],
            'ota_revenue_metrics_missing' => ['severity' => 'high', 'category' => 'metric', 'display_reason' => '已命中 OTA 目标日数据，但房费收入或间夜指标缺失。', 'next_action' => '复核 online_daily_data 的 revenue、room_revenue、room_nights 字段映射和入库值。'],
            'ota_room_nights_zero' => ['severity' => 'medium', 'category' => 'metric', 'display_reason' => '已命中 OTA 目标日数据，但间夜为 0，无法计算 ADR。', 'next_action' => '复核携程目标日 business 行的 room_nights/order_count；若确认为 0，则只做观察，不生成调价建议。'],
            'ZERO_CONFIRMED' => ['severity' => 'low', 'category' => 'data', 'display_reason' => $platformLabel . '明确确认目标经营日期无数据。', 'next_action' => '无需填充假数据；如业务预期应有数据，再进入数据健康面板复核采集范围。'],
            'source_not_loaded' => ['severity' => 'medium', 'category' => 'source', 'display_reason' => $platformLabel . '数据源未加载或未接入。', 'next_action' => '进入数据健康面板检查平台数据源配置。'],
            'source_status_missing' => ['severity' => 'medium', 'category' => 'source', 'display_reason' => $platformLabel . '缺少平台数据源状态。', 'next_action' => '进入数据健康面板检查 platform_data_sources 绑定。'],
            'source_status_unknown' => ['severity' => 'medium', 'category' => 'source', 'display_reason' => $platformLabel . '平台同步状态未知。', 'next_action' => '进入数据健康面板复核最近一次同步记录。'],
            'waiting_config' => ['severity' => 'high', 'category' => 'auth', 'display_reason' => $platformLabel . '平台数据源待授权或配置。', 'next_action' => '补齐平台账号/授权配置后重新同步。'],
            'source_disabled' => ['severity' => 'high', 'category' => 'source', 'display_reason' => $platformLabel . '平台数据源已禁用。', 'next_action' => '确认是否恢复该平台数据源。'],
            'sync_failed' => ['severity' => 'high', 'category' => 'sync', 'display_reason' => $platformLabel . '平台同步失败。', 'next_action' => '进入数据健康面板查看失败原因并重试。'],
            'competitor_price_fields_missing' => ['severity' => 'medium', 'category' => 'competitor', 'display_reason' => '暂缺竞对价格字段。', 'next_action' => '补齐竞对价格采集字段后再判断倒挂风险。'],
            'competitor_price_above_competitor' => ['severity' => 'medium', 'category' => 'competitor', 'display_reason' => '本店均价高于竞对均价，需人工复核是否存在价格倒挂或竞争力风险。', 'next_action' => '复核竞对样本、房型口径和最低保护价后再进入人工调价审核。'],
            'competitor_price_below_competitor_review_required' => ['severity' => 'medium', 'category' => 'competitor', 'display_reason' => '本店均价低于竞对均价，需复核是否低于保护价后再判断调价。', 'next_action' => '补齐最低保护价和需求信号后再形成可审核调价建议。'],
            'competitor_price_aligned' => ['severity' => 'low', 'category' => 'competitor', 'display_reason' => '本店均价与竞对均价接近。', 'next_action' => '继续观察需求和转化数据，不自动生成调价建议。'],
            'holiday_signal_not_loaded' => ['severity' => 'medium', 'category' => 'event_signal', 'display_reason' => '节假日/事件信号尚未读取。', 'next_action' => '等待 Revenue AI 总览接口返回节假日窗口。', 'target_platform' => 'hotel'],
            'holiday_calendar_missing' => ['severity' => 'medium', 'category' => 'event_signal', 'display_reason' => '暂缺目标年份节假日日历。', 'next_action' => '补齐节假日日历后再判断事件影响。', 'target_platform' => 'hotel'],
            'holiday_event_in_window' => ['severity' => 'medium', 'category' => 'event_signal', 'display_reason' => '当前处于节假日窗口。', 'next_action' => '复核库存、底价、竞对价格和渠道活动。', 'target_platform' => 'hotel'],
            'holiday_event_nearby' => ['severity' => 'medium', 'category' => 'event_signal', 'display_reason' => '近期存在节假日窗口。', 'next_action' => '提前确认库存、底价、连住和高需求日调价节奏。', 'target_platform' => 'hotel'],
            'holiday_event_upcoming' => ['severity' => 'low', 'category' => 'event_signal', 'display_reason' => '30 天内存在节假日窗口。', 'next_action' => '纳入人工调价复核，但不自动改价。', 'target_platform' => 'hotel'],
            'holiday_event_none_nearby' => ['severity' => 'low', 'category' => 'event_signal', 'display_reason' => '30 天内暂无节假日窗口。', 'next_action' => '继续每日滚动观察需求和竞对变化。', 'target_platform' => 'hotel'],
            'demand_forecasts_not_loaded' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '未来 7 天需求预测尚未读取。', 'next_action' => '等待 Revenue AI 总览接口返回 demand_forecasts 摘要。', 'target_platform' => 'hotel'],
            'demand_forecasts_missing' => ['severity' => 'high', 'category' => 'demand_signal', 'display_reason' => '需求预测表 demand_forecasts 不存在。', 'next_action' => '恢复需求预测表后再展示未来 7 天信号。', 'target_platform' => 'hotel'],
            'demand_forecasts_required_fields_missing' => ['severity' => 'high', 'category' => 'demand_signal', 'display_reason' => '需求预测表缺少 hotel_id 或 forecast_date 等必要字段。', 'next_action' => '修复 demand_forecasts 字段契约后再展示未来 7 天信号。', 'target_platform' => 'hotel'],
            'demand_forecasts_metric_fields_missing' => ['severity' => 'high', 'category' => 'demand_signal', 'display_reason' => '需求预测表缺少 predicted_occupancy 或 predicted_demand。', 'next_action' => '补齐预测指标字段后再判断未来 7 天需求。', 'target_platform' => 'hotel'],
            'demand_forecasts_read_failed' => ['severity' => 'high', 'category' => 'demand_signal', 'display_reason' => '未来 7 天需求预测读取失败。', 'next_action' => '检查 demand_forecasts 读取权限和数据库错误。', 'target_platform' => 'hotel'],
            'demand_forecasts_empty' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '未来 7 天暂无需求预测记录。', 'next_action' => '生成或导入未来 7 天需求预测后再进入人工调价判断。', 'target_platform' => 'hotel'],
            'demand_forecasts_metric_missing' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '需求预测记录缺少可计算指标。', 'next_action' => '补齐入住率或需求间夜后再判断未来需求。', 'target_platform' => 'hotel'],
            'demand_forecasts_low_confidence' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '未来 7 天需求预测置信度偏低。', 'next_action' => '用近期订单和竞对样本校准预测后再进入调价审核。', 'target_platform' => 'hotel'],
            'demand_forecasts_high_demand' => ['severity' => 'medium', 'category' => 'demand_signal', 'display_reason' => '未来 7 天存在高需求日期。', 'next_action' => '结合最低保护价和竞对价格进入人工调价复核。', 'target_platform' => 'hotel'],
            'demand_forecasts_available' => ['severity' => 'low', 'category' => 'demand_signal', 'display_reason' => '已读取未来 7 天需求预测。', 'next_action' => '继续结合竞对、保护价和人工审核判断调价。', 'target_platform' => 'hotel'],
            'floor_price_missing' => ['severity' => 'high', 'category' => 'pricing_guard', 'display_reason' => '暂缺最低保护价。', 'next_action' => '补齐房型/价格计划级最低保护价后再允许生成可审核调价建议。'],
            'manual_review_workflow_not_connected' => ['severity' => 'high', 'category' => 'pricing_guard', 'display_reason' => '暂未接入人工审核工作流。', 'next_action' => '接入建议版本、批准/拒绝/转执行审计流后再开放调价建议。'],
            'price_suggestions_missing' => ['severity' => 'high', 'category' => 'pricing_review', 'display_reason' => '定价建议表 price_suggestions 不存在。', 'next_action' => '恢复定价建议表后再展示人工审核队列。'],
            'price_suggestions_required_fields_missing' => ['severity' => 'high', 'category' => 'pricing_review', 'display_reason' => '定价建议表缺少 status 或 suggestion_date 等必要字段。', 'next_action' => '修复 price_suggestions 字段契约后再展示人工审核队列。'],
            'price_suggestions_read_failed' => ['severity' => 'high', 'category' => 'pricing_review', 'display_reason' => '定价建议审核队列读取失败。', 'next_action' => '检查 price_suggestions 读取权限和数据库错误。'],
            'price_suggestions_empty' => ['severity' => 'low', 'category' => 'pricing_review', 'display_reason' => '目标经营日期暂无存量调价建议。', 'next_action' => '继续补齐需求、竞对、保护价等前置条件后再生成可审核建议。'],
            'price_suggestions_pending_review' => ['severity' => 'medium', 'category' => 'pricing_review', 'display_reason' => '存在待人工审核调价建议。', 'next_action' => '进入定价建议列表完成人工批准、修改后批准、拒绝或转执行。'],
            'price_suggestions_reviewed' => ['severity' => 'low', 'category' => 'pricing_review', 'display_reason' => '目标经营日期调价建议已处理。', 'next_action' => '复核已处理建议是否需要转执行或补充效果复盘证据。'],
            'agent_logs_not_loaded' => ['severity' => 'medium', 'category' => 'agent_activity', 'display_reason' => '收益管理 Agent 日志尚未读取。', 'next_action' => '等待 Revenue AI 总览接口返回 Agent 日志摘要。'],
            'agent_logs_missing' => ['severity' => 'high', 'category' => 'agent_activity', 'display_reason' => 'Agent 日志表 agent_logs 不存在。', 'next_action' => '恢复 Agent 日志表后再展示操作追溯。'],
            'agent_logs_required_fields_missing' => ['severity' => 'high', 'category' => 'agent_activity', 'display_reason' => 'Agent 日志表缺少 agent_type、log_level 或 create_time 等必要字段。', 'next_action' => '修复 agent_logs 字段契约后再展示操作追溯。'],
            'agent_logs_read_failed' => ['severity' => 'high', 'category' => 'agent_activity', 'display_reason' => '收益管理 Agent 日志读取失败。', 'next_action' => '检查 agent_logs 读取权限和数据库错误。'],
            'agent_logs_empty' => ['severity' => 'low', 'category' => 'agent_activity', 'display_reason' => '目标经营日期暂无收益管理 Agent 操作日志。', 'next_action' => '如预期应有动作，检查收益管理 Agent 触发链路。'],
            'agent_logs_available' => ['severity' => 'low', 'category' => 'agent_activity', 'display_reason' => '已读取收益管理 Agent 操作日志。', 'next_action' => '继续只读追踪，不把日志数量当作业务成功证据。'],
            'agent_logs_warning_present' => ['severity' => 'medium', 'category' => 'agent_activity', 'display_reason' => '收益管理 Agent 存在警告日志。', 'next_action' => '复核警告是否影响今日调价判断。'],
            'agent_logs_error_present' => ['severity' => 'high', 'category' => 'agent_activity', 'display_reason' => '收益管理 Agent 存在错误日志。', 'next_action' => '先处理错误日志，再继续生成或执行建议。'],
            'operation_execution_not_loaded' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '运营执行闭环尚未读取。', 'next_action' => '等待 Revenue AI 总览接口返回执行摘要。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_intents_missing' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '执行意图表 operation_execution_intents 不存在。', 'next_action' => '恢复执行意图表后再展示执行进度。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_tasks_missing' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '执行任务表 operation_execution_tasks 不存在。', 'next_action' => '恢复执行任务表后再展示执行进度。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_evidence_missing' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '执行证据表 operation_execution_evidence 不存在或缺少执行证据。', 'next_action' => '补齐执行证据后再判断效果复盘。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_read_failed' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '运营执行闭环读取失败。', 'next_action' => '检查执行闭环表读取权限和数据库错误。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_empty' => ['severity' => 'low', 'category' => 'operation_execution', 'display_reason' => '目标经营日期暂无调价执行记录。', 'next_action' => '如已有人工审核建议，请在运营执行页转为执行意图后再追踪。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_pending_approval' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '存在待审批的调价执行意图。', 'next_action' => '进入运营执行页完成人工审批；Revenue AI 首页不直接批准。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_in_progress' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '存在待执行或执行中的调价任务。', 'next_action' => '进入运营执行页记录实际执行结果和执行人。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_evidence_needed' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价任务已执行但缺少执行前后证据。', 'next_action' => '补充执行前后价格、收入或平台回执证据。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_review_needed' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价执行已具备证据，等待效果复盘。', 'next_action' => '进入运营执行页触发效果复盘。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_reviewed' => ['severity' => 'low', 'category' => 'operation_execution', 'display_reason' => '目标经营日期调价执行已完成复盘。', 'next_action' => '复核 ROI 或增量收入证据，作为明日调价判断输入。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_blocked' => ['severity' => 'high', 'category' => 'operation_execution', 'display_reason' => '调价执行存在阻塞、拒绝或失败记录。', 'next_action' => '先处理审批、执行或平台回写阻塞原因。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_partial' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价执行闭环尚未形成完整进度。', 'next_action' => '继续在运营执行页维护执行记录和复盘证据。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_execution_not_executed' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价任务尚未记录实际执行完成。', 'next_action' => '先记录执行结果，再做效果复盘。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_effect_review_pending' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价效果复盘待处理。', 'next_action' => '区分执行完成和收益验证，补齐复盘记录。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_effect_review_ready' => ['severity' => 'low', 'category' => 'operation_execution', 'display_reason' => '调价效果已有复盘和 ROI 证据。', 'next_action' => '将复盘结果作为明日调价判断输入。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'operation_roi_missing' => ['severity' => 'medium', 'category' => 'operation_execution', 'display_reason' => '调价复盘缺少 ROI 或增量收入证据。', 'next_action' => '补齐执行前后收入、成本或平台回执后再判断效果。', 'target_page' => 'ops-track', 'target_tab' => '', 'target_platform' => 'hotel'],
            'adr_denominator_zero' => ['severity' => 'medium', 'category' => 'metric', 'display_reason' => 'OTA 间夜为 0，ADR 不可计算。', 'next_action' => '复核目标日期 OTA 间夜是否为渠道确认零值。'],
        ];
        $meta = array_merge($base, $overrides[$reason] ?? []);
        $pricingGenerationMeta = match ($reason) {
            'price_suggestion_generation_not_loaded' => ['severity' => 'medium', 'category' => 'pricing_generation', 'display_reason' => '调价建议生成预检尚未加载。', 'next_action' => '先加载 room_types、demand_forecasts、competitor_analysis 和 price_suggestions 的只读预检，再决定是否生成待审建议。'],
            'pricing_generation_hotel_scope_missing' => ['severity' => 'high', 'category' => 'pricing_generation', 'display_reason' => '调价建议生成缺少目标系统酒店范围。', 'next_action' => '先选择或导入可映射到系统酒店的携程 OTA 数据，再生成待审调价建议。'],
            'room_types_empty' => ['severity' => 'high', 'category' => 'pricing_generation', 'display_reason' => '携程目标酒店暂无启用房型，不能生成待审调价建议。', 'next_action' => '为携程目标酒店配置启用房型、基础价和最低保护价后，再补需求预测与竞对样本。'],
            self::TEMPORARY_PRICING_SKIP_REASON => ['severity' => 'low', 'category' => 'pricing_generation', 'display_reason' => '已按人工策略暂时跳过抓不到的房型、保护价、需求预测和竞对样本缺口。', 'next_action' => '继续保留缺口证据；后续抓到真实输入后再恢复调价生成门禁。'],
            'pricing_candidate_signals_missing' => ['severity' => 'medium', 'category' => 'pricing_generation', 'display_reason' => '调价候选信号不足，当前不会生成待审建议。', 'next_action' => '补齐需求预测、竞对价格、历史价格变化和保护价信号，直到只读预检出现可生成候选。'],
            'pricing_generation_candidates_ready' => ['severity' => 'low', 'category' => 'pricing_generation', 'display_reason' => '已存在可生成待审调价建议的只读候选。', 'next_action' => '进入收益 Agent 生成待审建议；生成后仍需人工审核，不写 OTA。'],
            default => [],
        };
        if ($pricingGenerationMeta !== []) {
            $meta = array_merge($meta, $pricingGenerationMeta);
        }
        if (str_starts_with($reason, 'price_suggestions_')
            || in_array($reason, [
                'price_suggestion_generation_not_loaded',
                'pricing_generation_hotel_scope_missing',
                'room_types_empty',
                self::TEMPORARY_PRICING_SKIP_REASON,
                'pricing_candidate_signals_missing',
                'pricing_generation_candidates_ready',
            ], true)) {
            $meta['target_page'] = 'agent-center';
            $meta['target_tab'] = 'suggestions';
            $meta['target_agent_tab'] = 'revenue';
            $meta['target_revenue_tab'] = 'suggestions';
        }
        return $meta;
    }

    /**
     * @param array<int, array<string, mixed>> $missingDatasets
     * @param array<int, array<string, mixed>> $qualityIssues
     * @return array<string, mixed>
     */
    private function issueSummary(array $missingDatasets, array $qualityIssues): array
    {
        $rows = array_merge($missingDatasets, $qualityIssues);
        $bySeverity = [];
        $byCategory = [];
        foreach ($rows as $row) {
            $severity = (string)($row['severity'] ?? 'medium');
            $category = (string)($row['category'] ?? 'data');
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
        }
        return [
            'total' => count($rows),
            'missing_count' => count($missingDatasets),
            'quality_count' => count($qualityIssues),
            'high_count' => (int)($bySeverity['high'] ?? 0),
            'by_severity' => $bySeverity,
            'by_category' => $byCategory,
        ];
    }
}
