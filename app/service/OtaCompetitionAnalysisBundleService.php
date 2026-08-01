<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds one persisted competition-circle result for every report edition.
 *
 * The service only uses stored OTA-channel evidence. It never widens Ctrip or
 * Meituan facts into whole-hotel/PMS truth and never writes back to an OTA.
 */
final class OtaCompetitionAnalysisBundleService
{
    public const SCHEMA_VERSION = 'ota_competition_analysis_bundle.v1';
    public const DEFAULT_EDITION = 'lite';
    private const EDITIONS = ['lite', 'flagship', 'both'];

    public function __construct(private ?CtripCompetitiveOperationsService $ctripService = null)
    {
        $this->ctripService ??= new CtripCompetitiveOperationsService();
    }

    public static function normalizeEdition(mixed $edition): string
    {
        $normalized = strtolower(trim((string)$edition));
        if ($normalized === '' || $normalized === 'auto') {
            return self::DEFAULT_EDITION;
        }
        if (!in_array($normalized, self::EDITIONS, true)) {
            throw new \InvalidArgumentException('competition report edition must be lite, flagship or both');
        }
        return $normalized;
    }

    public static function editionRequiresAdmin(mixed $edition): bool
    {
        return in_array(self::normalizeEdition($edition), ['flagship', 'both'], true);
    }

    public static function assertGenerationAllowed(mixed $edition, bool $isAdmin): void
    {
        if (self::editionRequiresAdmin($edition) && !$isAdmin) {
            throw new \RuntimeException('flagship_generation_requires_admin');
        }
    }

    /**
     * Production entry: Ctrip is read from its existing service and Meituan is
     * reused from OperationManagementService.fullData in the daily snapshot.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function build(int $hotelId, string $reportDate, array $snapshot, array $options = []): array
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id must be positive');
        }
        $reportDate = $this->normalizeDate($reportDate);
        if (is_array($options['ctrip_result'] ?? null)) {
            $ctrip = $options['ctrip_result'];
        } else {
            try {
                $ctrip = $this->ctripService->build($hotelId, $reportDate, $reportDate);
            } catch (\Throwable) {
                $ctrip = [
                    'status' => 'collection_failed',
                    'context' => ['binding_status' => 'binding_unverified'],
                    'business_comparison' => [],
                    'data_coverage' => [],
                ];
            }
        }
        $meituan = is_array($options['meituan_summary'] ?? null)
            ? $options['meituan_summary']
            : (is_array($snapshot['operation']['competitors']['meituan_rank_summary'] ?? null)
                ? $snapshot['operation']['competitors']['meituan_rank_summary']
                : []);

        return $this->buildFromInputs(
            $hotelId,
            $reportDate,
            $ctrip,
            $meituan,
            array_merge($options, [
                'readback_verified' => ($snapshot['input_trust']['readback_verified'] ?? false) === true,
            ])
        );
    }

    /**
     * Pure deterministic entry used by offline/synthetic verification.
     *
     * @param array<string,mixed> $ctrip
     * @param array<string,mixed> $meituan
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function buildFromInputs(
        int $hotelId,
        string $reportDate,
        array $ctrip,
        array $meituan,
        array $options = []
    ): array {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id must be positive');
        }
        $reportDate = $this->normalizeDate($reportDate);
        $edition = self::normalizeEdition($options['edition'] ?? self::DEFAULT_EDITION);
        $actorIsAdmin = ($options['actor_is_admin'] ?? false) === true;
        self::assertGenerationAllowed($edition, $actorIsAdmin);
        $datasetKind = strtolower(trim((string)($options['dataset_kind'] ?? 'live')));
        if ($datasetKind === '') {
            $datasetKind = 'live';
        }
        $readbackVerified = ($options['readback_verified'] ?? false) === true;
        $ctripResult = $this->normalizeCtrip(
            $ctrip,
            $reportDate,
            $datasetKind,
            $readbackVerified
        );
        $meituanResult = $this->normalizeMeituan(
            $meituan,
            $reportDate,
            $datasetKind,
            $readbackVerified
        );
        $platforms = [
            'ctrip' => $ctripResult,
            'meituan' => $meituanResult,
        ];
        $eligiblePlatforms = array_values(array_keys(array_filter(
            $platforms,
            static fn(array $platform): bool => ($platform['quality']['decision_eligible'] ?? false) === true
        )));
        $allGaps = $this->dedupeGaps(array_merge(
            $ctripResult['quality']['data_gaps'],
            $meituanResult['quality']['data_gaps']
        ));
        if ($datasetKind === 'synthetic') {
            $allGaps[] = $this->gap(
                'synthetic_data_not_executable',
                '模拟数据仅用于页面、权限和契约测试，不得生成OTA执行建议。',
                'competition_circle_bundle.source.dataset_kind'
            );
            $allGaps = $this->dedupeGaps($allGaps);
        }

        $decisionEligible = $datasetKind === 'live' && $eligiblePlatforms !== [];
        $qualityStatus = $datasetKind === 'synthetic'
            ? 'synthetic'
            : ($decisionEligible
                ? (count($eligiblePlatforms) === count($platforms) ? 'available' : 'partial')
                : 'blocked');
        $source = [
            'metric_scope' => 'ota_channel',
            'whole_hotel_truth' => false,
            'system_hotel_id' => $hotelId,
            'data_date' => $reportDate,
            'dataset_kind' => $datasetKind,
            'readback_verified' => $readbackVerified,
            'platforms' => ['ctrip', 'meituan'],
            'source_refs' => [
                'ctrip' => 'online_daily_data(source=ctrip,data_type=competitor|traffic)',
                'meituan' => 'operation.full_data.competitors.meituan_rank_summary',
            ],
        ];
        $sourceFingerprint = $this->fingerprint([
            'schema_version' => self::SCHEMA_VERSION,
            'source' => $source,
            'platform_facts' => [
                'ctrip' => [
                    'facts' => $ctripResult['facts'],
                    'derived_metrics' => $ctripResult['derived_metrics'],
                    'candidate_competitors' => $ctripResult['candidate_competitors'],
                    'quality' => $ctripResult['quality'],
                ],
                'meituan' => [
                    'facts' => $meituanResult['facts'],
                    'derived_metrics' => $meituanResult['derived_metrics'],
                    'candidate_competitors' => $meituanResult['candidate_competitors'],
                    'quality' => $meituanResult['quality'],
                ],
            ],
        ]);
        $recommendations = $this->buildRecommendations($platforms, $decisionEligible);
        $requestedEditions = $edition === 'both' ? ['lite', 'flagship'] : [$edition];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'bundle_id' => 'ota-competition-' . $hotelId . '-' . str_replace('-', '', $reportDate) . '-' . substr($sourceFingerprint, 0, 12),
            'source_fingerprint' => $sourceFingerprint,
            'source' => $source,
            'quality' => [
                'status' => $qualityStatus,
                'decision_eligible' => $decisionEligible,
                'eligible_platforms' => $eligiblePlatforms,
                'data_gaps' => $allGaps,
            ],
            'facts' => [
                'ctrip' => $ctripResult['facts'],
                'meituan' => $meituanResult['facts'],
            ],
            'derived_metrics' => [
                'ctrip' => $ctripResult['derived_metrics'],
                'meituan' => $meituanResult['derived_metrics'],
            ],
            'analysis' => [
                'ctrip' => $ctripResult['analysis'],
                'meituan' => $meituanResult['analysis'],
            ],
            'candidate_competitors' => [
                'ctrip' => $ctripResult['candidate_competitors'],
                'meituan' => $meituanResult['candidate_competitors'],
            ],
            'recommendations' => $recommendations,
            'render_contract' => [
                'requested_edition' => $edition,
                'requested_editions' => $requestedEditions,
                'default_edition' => self::DEFAULT_EDITION,
                'single_calculation' => true,
                'flagship_generation_requires_admin' => true,
                'generated_by_admin' => $actorIsAdmin,
                'lite_reads_same_bundle' => true,
                'flagship_reads_same_bundle' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeCtrip(
        array $source,
        string $reportDate,
        string $datasetKind,
        bool $readbackVerified
    ): array {
        $context = is_array($source['context'] ?? null) ? $source['context'] : [];
        $comparison = is_array($source['business_comparison'] ?? null) ? $source['business_comparison'] : [];
        $coverage = is_array($source['data_coverage'] ?? null) ? $source['data_coverage'] : [];
        $self = is_array($comparison['self'] ?? null) ? $comparison['self'] : null;
        $average = is_array($comparison['competitor_average'] ?? null) ? $comparison['competitor_average'] : null;
        $competitorCount = array_key_exists('competitor_count', $comparison)
            && is_numeric($comparison['competitor_count'])
            ? (int)$comparison['competitor_count']
            : null;
        $hotels = array_values(array_filter((array)($comparison['hotels'] ?? []), 'is_array'));
        $latestDate = substr(trim((string)($comparison['latest_date'] ?? '')), 0, 10);
        $bindingStatus = strtolower(trim((string)($context['binding_status'] ?? 'binding_missing')));
        $platformHotelIdentifierPresent = ($context['platform_hotel_identifier_present'] ?? false) === true;
        $sourceStatus = strtolower(trim((string)($source['status'] ?? 'data_missing')));
        $sourceEvidence = is_array($context['source_evidence'] ?? null)
            ? $context['source_evidence']
            : [];
        $sourceEvidenceVerified = strtolower(trim((string)($sourceEvidence['status'] ?? ''))) === 'verified';
        $gaps = [];

        if (in_array($sourceStatus, ['data_missing', 'missing', 'collection_failed'], true)) {
            $gaps[] = $this->gap('ctrip_source_missing', '携程目标日竞争来源缺失或读取失败。', 'online_daily_data:ctrip');
        }
        if ($bindingStatus !== 'bound') {
            $gaps[] = $this->gap('ctrip_binding_missing', '携程本店绑定未唯一确认。', 'ctrip.context.binding_status');
        }
        if (!$platformHotelIdentifierPresent) {
            $gaps[] = $this->gap(
                'ctrip_platform_hotel_identifier_missing',
                '携程本店平台酒店标识缺失，不能确认唯一“本店”。',
                'ctrip.context.platform_hotel_identifier_present'
            );
        }
        if ($latestDate !== $reportDate) {
            $gaps[] = $this->gap('ctrip_target_date_missing', '携程竞争数据日期未命中目标日。', 'ctrip.business_comparison.latest_date');
        }
        if (!is_array($self)) {
            $gaps[] = $this->gap('ctrip_self_missing', '携程竞争数据未找到唯一“本店”行。', 'ctrip.business_comparison.self');
        }
        if (!is_array($average)) {
            $gaps[] = $this->gap('ctrip_competitor_summary_missing', '携程竞品汇总缺失。', 'ctrip.business_comparison.competitor_average');
        }
        if (!$this->positiveNumber($self['room_nights'] ?? null) || !$this->positiveNumber($average['room_nights'] ?? null)) {
            $gaps[] = $this->gap('ctrip_price_denominator_missing', '携程间夜分母缺失，不能形成价格实验。', 'ctrip.business_comparison.room_nights');
        }
        if (!$readbackVerified && $datasetKind !== 'synthetic') {
            $gaps[] = $this->gap('ctrip_readback_unverified', '携程来源尚未通过数据库精确回读。', 'snapshot.input_trust');
        }
        if (!$sourceEvidenceVerified && $datasetKind !== 'synthetic') {
            $gaps[] = $this->gap(
                'ctrip_source_trace_unverified',
                '携程竞争数据缺少完整来源行、采集时间或来源追踪证据。',
                'ctrip.context.source_evidence'
            );
        }

        $decisionEligible = $datasetKind === 'live'
            && $readbackVerified
            && $sourceEvidenceVerified
            && in_array($sourceStatus, ['available', 'partial'], true)
            && $bindingStatus === 'bound'
            && $platformHotelIdentifierPresent
            && $latestDate === $reportDate
            && is_array($self)
            && is_array($average)
            && $this->positiveNumber($self['room_nights'] ?? null)
            && $this->positiveNumber($average['room_nights'] ?? null)
            && (int)($coverage['decision_eligible_row_count'] ?? 0) > 0;
        $candidates = $this->buildCtripCandidates($hotels, $self);
        $analysis = $decisionEligible
            ? $this->buildCtripAnalysis($self, $average)
            : $this->withheldAnalysis('ctrip', $gaps, $datasetKind);

        return [
            'quality' => [
                'status' => $datasetKind === 'synthetic'
                    ? 'synthetic'
                    : ($decisionEligible ? ($gaps === [] ? 'available' : 'partial') : 'blocked'),
                'decision_eligible' => $decisionEligible,
                'source_status' => $sourceStatus,
                'binding_status' => $bindingStatus,
                'data_gaps' => $gaps,
            ],
            'facts' => [
                'latest_data_date' => $latestDate,
                'latest_fetched_at' => trim((string)($context['latest_fetched_at'] ?? '')),
                'self' => $self,
                'competitor_average' => $average,
                'competitor_count' => $competitorCount,
                'decision_eligible_row_count' => (int)($coverage['decision_eligible_row_count'] ?? 0),
                'platform_hotel_identifier_present' => $platformHotelIdentifierPresent,
                'platform_hotel_identifier_source' => trim((string)($context['platform_hotel_identifier_source'] ?? '')),
                'source_evidence' => $sourceEvidence,
            ],
            'derived_metrics' => [
                'gaps' => array_values(array_filter((array)($comparison['gaps'] ?? []), 'is_array')),
                'adr_gap' => $this->difference($self['adr'] ?? null, $average['adr'] ?? null),
                'order_gap' => $this->difference($self['orders'] ?? null, $average['orders'] ?? null),
                'conversion_gap' => $this->difference($self['conversion_rate'] ?? null, $average['conversion_rate'] ?? null),
            ],
            'analysis' => $analysis,
            'candidate_competitors' => $candidates,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeMeituan(
        array $source,
        string $reportDate,
        string $datasetKind,
        bool $readbackVerified
    ): array {
        $latestDate = substr(trim((string)($source['latest_data_date'] ?? '')), 0, 10);
        $rankStatus = strtolower(trim((string)($source['rank_status'] ?? 'missing')));
        $dataStatus = strtolower(trim((string)($source['data_status'] ?? 'pending')));
        $targetBound = ($source['target_poi_bound'] ?? false) === true;
        $sourceEvidence = is_array($source['source_evidence'] ?? null)
            ? $source['source_evidence']
            : [];
        $sourceEvidenceVerified = strtolower(trim((string)($sourceEvidence['status'] ?? ''))) === 'verified';
        $selfRank = $this->rankNumber($source['self_position_text'] ?? null);
        $topRank = $this->numberOrNull($source['top_rank'] ?? null);
        $gaps = [];
        if ($source === [] || in_array($dataStatus, ['pending', 'missing', 'data_missing', 'collection_failed'], true)) {
            $gaps[] = $this->gap('meituan_source_missing', '美团目标日竞争来源缺失或读取失败。', 'online_daily_data:meituan');
        }
        if (!$targetBound) {
            $gaps[] = $this->gap('meituan_binding_missing', '美团本店POI绑定未确认。', 'meituan.target_poi_bound');
        }
        if ($latestDate !== $reportDate) {
            $gaps[] = $this->gap('meituan_target_date_missing', '美团竞品榜单日期未命中目标日。', 'meituan.latest_data_date');
        }
        if ($rankStatus !== 'ok' || $selfRank === null) {
            $gaps[] = $this->gap('meituan_self_rank_missing', '美团榜单未返回可核对的本店排名。', 'meituan.rank_status');
        }
        if (!$readbackVerified && $datasetKind !== 'synthetic') {
            $gaps[] = $this->gap('meituan_readback_unverified', '美团来源尚未通过数据库精确回读。', 'snapshot.input_trust');
        }
        if (!$sourceEvidenceVerified && $datasetKind !== 'synthetic') {
            $gaps[] = $this->gap(
                'meituan_source_trace_unverified',
                '美团竞品榜单缺少完整来源行、采集时间或来源追踪证据。',
                'meituan.source_evidence'
            );
        }
        $decisionEligible = $datasetKind === 'live'
            && $readbackVerified
            && $sourceEvidenceVerified
            && $dataStatus === 'ok'
            && $rankStatus === 'ok'
            && $targetBound
            && $latestDate === $reportDate
            && $selfRank !== null;
        $topName = trim((string)($source['top_hotel_name'] ?? ''));
        $candidates = [
            'direct' => [],
            'attack_benchmark' => $topName !== '' && $topName !== '未返回'
                ? [[
                    'ota_hotel_id' => '',
                    'hotel_name' => $topName,
                    'rank' => $topRank,
                    'candidate_only' => !$decisionEligible,
                ]]
                : [],
            'traffic_benchmark' => [],
            'conversion_benchmark' => [],
        ];
        $analysis = $decisionEligible
            ? [
                'status' => 'available',
                'channel_role' => $selfRank !== null && $selfRank <= 3 ? '守位渠道' : '排名改善渠道',
                'first_conflict' => $selfRank !== null && $topRank !== null && $selfRank > $topRank
                    ? '本店与TOP1存在排名差，平台未返回可直接归因的指标差额。'
                    : '当前榜单未显示明确排名落后。',
                'price_experiment' => null,
            ]
            : $this->withheldAnalysis('meituan', $gaps, $datasetKind);

        return [
            'quality' => [
                'status' => $datasetKind === 'synthetic'
                    ? 'synthetic'
                    : ($decisionEligible ? ($gaps === [] ? 'available' : 'partial') : 'blocked'),
                'decision_eligible' => $decisionEligible,
                'source_status' => $dataStatus,
                'binding_status' => $targetBound ? 'bound' : 'binding_missing',
                'data_gaps' => $gaps,
            ],
            'facts' => [
                'latest_data_date' => $latestDate,
                'latest_fetched_at' => trim((string)($source['latest_fetched_at'] ?? '')),
                'hotel_count' => (int)($source['hotel_count'] ?? 0),
                'self_position_text' => trim((string)($source['self_position_text'] ?? '未返回')),
                'top_hotel_name' => $topName,
                'top_rank' => $topRank,
                'gap_to_previous_text' => trim((string)($source['gap_to_previous_text'] ?? '未返回')),
                'top1_gap_text' => trim((string)($source['top1_gap_text'] ?? '未返回')),
                'rank_trend_text' => trim((string)($source['rank_trend_text'] ?? '未返回')),
                'platform_tag_text' => trim((string)($source['platform_tag_text'] ?? '未返回')),
                'source_evidence' => $sourceEvidence,
            ],
            'derived_metrics' => [
                'self_rank' => $selfRank,
                'top_rank' => $topRank,
                'rank_gap_to_top1' => $selfRank !== null && $topRank !== null ? $selfRank - $topRank : null,
            ],
            'analysis' => $analysis,
            'candidate_competitors' => $candidates,
        ];
    }

    /** @return array<string,mixed> */
    private function buildCtripCandidates(array $hotels, ?array $self): array
    {
        $competitors = array_values(array_filter(
            $hotels,
            static fn(array $hotel): bool => strtolower((string)($hotel['compare_type'] ?? '')) === 'competitor'
        ));
        $map = static fn(array $hotel): array => [
            'ota_hotel_id' => (string)($hotel['ota_hotel_id'] ?? ''),
            'hotel_name' => (string)($hotel['hotel_name'] ?? ''),
            'adr' => is_numeric($hotel['adr'] ?? null) ? (float)$hotel['adr'] : null,
            'room_nights' => is_numeric($hotel['room_nights'] ?? null) ? (float)$hotel['room_nights'] : null,
            'detail_visitors' => is_numeric($hotel['detail_visitors'] ?? null) ? (float)$hotel['detail_visitors'] : null,
            'conversion_rate' => is_numeric($hotel['conversion_rate'] ?? null) ? (float)$hotel['conversion_rate'] : null,
            'quality_status' => (string)($hotel['quality_status'] ?? ''),
            'candidate_only' => true,
        ];
        $selfAdr = $this->numberOrNull($self['adr'] ?? null);
        $direct = $competitors;
        if ($selfAdr !== null && $selfAdr > 0) {
            usort($direct, static fn(array $left, array $right): int => abs((float)($left['adr'] ?? PHP_INT_MAX) - $selfAdr)
                <=> abs((float)($right['adr'] ?? PHP_INT_MAX) - $selfAdr));
        }
        $attack = $this->sortByMetric($competitors, 'room_nights');
        $traffic = $this->sortByMetric($competitors, 'detail_visitors');
        $conversion = $this->sortByMetric($competitors, 'conversion_rate');

        return [
            'direct' => array_map($map, array_slice($direct, 0, 5)),
            'attack_benchmark' => array_map($map, array_slice($attack, 0, 5)),
            'traffic_benchmark' => array_map($map, array_slice($traffic, 0, 5)),
            'conversion_benchmark' => array_map($map, array_slice($conversion, 0, 5)),
        ];
    }

    /** @return array<string,mixed> */
    private function buildCtripAnalysis(array $self, array $average): array
    {
        $adrGap = $this->difference($self['adr'] ?? null, $average['adr'] ?? null);
        $ordersGap = $this->difference($self['orders'] ?? null, $average['orders'] ?? null);
        $conversionGap = $this->difference($self['conversion_rate'] ?? null, $average['conversion_rate'] ?? null);
        $role = $ordersGap !== null && $ordersGap >= 0 ? '守位渠道' : '订单改善渠道';
        $conflict = '当前没有足够差异形成明确第一矛盾。';
        if ($ordersGap !== null && $ordersGap < 0 && $conversionGap !== null && $conversionGap < 0) {
            $conflict = '订单与转化同时低于竞品均值，先核对流量到下单转化环节。';
        } elseif ($ordersGap !== null && $ordersGap < 0 && $adrGap !== null && $adrGap > 0) {
            $conflict = '本店ADR高于竞品均值但订单落后，先做有边界的价格实验复核。';
        } elseif ($ordersGap !== null && $ordersGap < 0) {
            $conflict = '本店订单低于竞品均值，需先核对流量、转化与价格差异。';
        }

        return [
            'status' => 'available',
            'channel_role' => $role,
            'first_conflict' => $conflict,
            'price_experiment' => [
                'status' => 'manual_confirmation_required',
                'hypothesis' => '在不改变全酒店价格策略的前提下，围绕携程竞品ADR差异做小范围复核。',
                'target_metric' => 'orders',
                'observation_window' => '下一个可获得完整OTA数据的经营日',
                'rollback_condition' => '订单未改善或ADR明显偏离人工设定边界时停止实验。',
                'auto_write_ota' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function withheldAnalysis(string $platform, array $gaps, string $datasetKind): array
    {
        return [
            'status' => $datasetKind === 'synthetic' ? 'synthetic_reference_only' : 'blocked',
            'channel_role' => null,
            'first_conflict' => null,
            'price_experiment' => null,
            'blocked_reason' => $datasetKind === 'synthetic'
                ? 'synthetic_data_not_executable'
                : (string)($gaps[0]['code'] ?? ($platform . '_evidence_incomplete')),
        ];
    }

    /** @return array<string,mixed> */
    private function buildRecommendations(array $platforms, bool $decisionEligible): array
    {
        if (!$decisionEligible) {
            return [
                'status' => 'withheld',
                'items' => [],
                'max_items' => 3,
                'auto_write_ota' => false,
                'manual_confirmation_required' => true,
            ];
        }
        $items = [];
        if (($platforms['ctrip']['quality']['decision_eligible'] ?? false) === true) {
            $analysis = $platforms['ctrip']['analysis'];
            $items[] = [
                'title' => '人工确认携程竞争商圈实验',
                'action' => '核对本店与直接竞品的ADR、订单和转化差异，确认房型、价型、日期边界后再创建运营执行意图。',
                'reason' => (string)($analysis['first_conflict'] ?? ''),
                'source_refs' => ['competition_circle_bundle.platforms.ctrip'],
                'platform' => 'ctrip',
                'object_type' => 'campaign',
                'action_type' => 'manual_review',
                'expected_metric' => 'orders',
                'expected_delta' => 0.0,
                'risk_level' => 'medium',
                'target_value' => [
                    'campaign_type' => 'competition_circle_price_review',
                    'target_metric' => 'orders',
                ],
                'review_window' => (string)($analysis['price_experiment']['observation_window'] ?? ''),
                'rollback_condition' => (string)($analysis['price_experiment']['rollback_condition'] ?? ''),
                'can_create_execution_intent' => true,
                'manual_confirmation_required' => true,
                'auto_write_ota' => false,
            ];
        }
        if (($platforms['meituan']['quality']['decision_eligible'] ?? false) === true) {
            $analysis = $platforms['meituan']['analysis'];
            $items[] = [
                'title' => '人工复核美团榜单差距',
                'action' => '复核本店、TOP1和前一名位置；平台未返回指标差额时不得直接归因为价格问题。',
                'reason' => (string)($analysis['first_conflict'] ?? ''),
                'source_refs' => ['competition_circle_bundle.platforms.meituan'],
                'platform' => 'meituan',
                'object_type' => 'campaign',
                'action_type' => 'manual_review',
                'expected_metric' => 'orders',
                'expected_delta' => 0.0,
                'risk_level' => 'medium',
                'target_value' => [
                    'campaign_type' => 'competition_rank_review',
                    'target_metric' => 'orders',
                ],
                'review_window' => '下一个可获得完整OTA数据的经营日',
                'rollback_condition' => '榜单来源或本店POI绑定失效时终止。',
                'can_create_execution_intent' => true,
                'manual_confirmation_required' => true,
                'auto_write_ota' => false,
            ];
        }

        return [
            'status' => $items === [] ? 'withheld' : 'ready_for_manual_confirmation',
            'items' => array_slice($items, 0, 3),
            'max_items' => 3,
            'auto_write_ota' => false,
            'manual_confirmation_required' => true,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function sortByMetric(array $rows, string $metric): array
    {
        usort($rows, static function (array $left, array $right) use ($metric): int {
            $leftValue = is_numeric($left[$metric] ?? null) ? (float)$left[$metric] : -INF;
            $rightValue = is_numeric($right[$metric] ?? null) ? (float)$right[$metric] : -INF;
            return $rightValue <=> $leftValue;
        });
        return $rows;
    }

    /** @return array<string,mixed> */
    private function gap(string $code, string $message, string $sourceRef): array
    {
        return ['code' => $code, 'message' => $message, 'source_ref' => $sourceRef];
    }

    /** @return array<int,array<string,mixed>> */
    private function dedupeGaps(array $gaps): array
    {
        $seen = [];
        $result = [];
        foreach ($gaps as $gap) {
            if (!is_array($gap)) {
                continue;
            }
            $key = (string)($gap['code'] ?? '') . '|' . (string)($gap['source_ref'] ?? '');
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $gap;
        }
        return $result;
    }

    private function normalizeDate(string $date): string
    {
        $date = substr(trim($date), 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('report_date must use Y-m-d');
        }
        return $date;
    }

    private function positiveNumber(mixed $value): bool
    {
        return is_numeric($value) && (float)$value > 0;
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function rankNumber(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        if (preg_match('/(\d+(?:\.\d+)?)/u', (string)$value, $matches) === 1) {
            return (float)$matches[1];
        }
        return null;
    }

    private function difference(mixed $left, mixed $right): ?float
    {
        $left = $this->numberOrNull($left);
        $right = $this->numberOrNull($right);
        return $left !== null && $right !== null ? round($left - $right, 4) : null;
    }

    private function fingerprint(array $value): string
    {
        $canonical = $this->canonicalize($value);
        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('competition bundle fingerprint encode failed');
        }
        return hash('sha256', $json);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
