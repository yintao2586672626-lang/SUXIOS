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
                    'evidence_contract' => $ctripResult['evidence_contract'],
                    'candidate_competitors' => $ctripResult['candidate_competitors'],
                    'quality' => $ctripResult['quality'],
                ],
                'meituan' => [
                    'facts' => $meituanResult['facts'],
                    'derived_metrics' => $meituanResult['derived_metrics'],
                    'evidence_contract' => $meituanResult['evidence_contract'],
                    'candidate_competitors' => $meituanResult['candidate_competitors'],
                    'quality' => $meituanResult['quality'],
                ],
            ],
        ]);
        $recommendations = $this->buildRecommendations($platforms, $decisionEligible);
        $requestedEditions = $edition === 'both' ? ['lite', 'flagship'] : [$edition];
        $quality = [
            'status' => $qualityStatus,
            'decision_eligible' => $decisionEligible,
            'eligible_platforms' => $eligiblePlatforms,
            'data_gaps' => $allGaps,
        ];
        $reportDocument = $this->buildReportDocument(
            $platforms,
            $quality,
            $recommendations,
            $source,
            $sourceFingerprint,
            $edition
        );

        $bundle = [
            'schema_version' => self::SCHEMA_VERSION,
            'bundle_id' => 'ota-competition-' . $hotelId . '-' . str_replace('-', '', $reportDate) . '-' . substr($sourceFingerprint, 0, 12),
            'source_fingerprint' => $sourceFingerprint,
            'source' => $source,
            'quality' => $quality,
            'facts' => [
                'ctrip' => $ctripResult['facts'],
                'meituan' => $meituanResult['facts'],
            ],
            'derived_metrics' => [
                'ctrip' => $ctripResult['derived_metrics'],
                'meituan' => $meituanResult['derived_metrics'],
            ],
            'evidence_contracts' => [
                'ctrip' => $ctripResult['evidence_contract'],
                'meituan' => $meituanResult['evidence_contract'],
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
            'report_document' => $reportDocument,
            'content_drafts' => [
                'xiaohongshu' => $this->buildXiaohongshuDraft(
                    $reportDocument,
                    $quality,
                    $sourceFingerprint
                ),
            ],
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

        $contentDigest = self::contentDigest($bundle);
        $bundle['content_digest'] = $contentDigest;
        $bundle['report_document']['render_contract']['content_digest'] = $contentDigest;
        return $bundle;
    }

    /**
     * Canonical digest of the complete persisted bundle. The two digest mirror
     * fields are excluded from their own checksum.
     *
     * @param array<string,mixed> $bundle
     */
    public static function contentDigest(array $bundle): string
    {
        unset($bundle['content_digest']);
        if (is_array($bundle['report_document']['render_contract'] ?? null)) {
            unset($bundle['report_document']['render_contract']['content_digest']);
        }
        $canonical = self::canonicalContentValue($bundle);
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('competition bundle content digest encode failed');
        }
        return hash('sha256', $json);
    }

    private static function canonicalContentValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalContentValue($item);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function buildReportDocument(
        array $platforms,
        array $quality,
        array $recommendations,
        array $source,
        string $sourceFingerprint,
        string $edition
    ): array {
        $eligiblePlatforms = array_values(array_filter(
            (array)($quality['eligible_platforms'] ?? []),
            static fn(mixed $platform): bool => in_array($platform, ['ctrip', 'meituan'], true)
        ));
        $decisionEligible = ($quality['decision_eligible'] ?? false) === true;
        $platformLabels = ['ctrip' => '携程', 'meituan' => '美团'];
        $platformSections = [];

        foreach (['ctrip', 'meituan'] as $platform) {
            $result = is_array($platforms[$platform] ?? null) ? $platforms[$platform] : [];
            $platformEligible = ($result['quality']['decision_eligible'] ?? false) === true;
            $candidateGroups = [];
            foreach ((array)($result['candidate_competitors'] ?? []) as $group => $items) {
                $candidateGroups[(string)$group] = count(array_filter((array)$items, 'is_array'));
            }
            $platformSections[$platform] = [
                'platform' => $platform,
                'label' => $platformLabels[$platform],
                'status' => $platformEligible ? 'ready_for_review' : 'blocked',
                'quality_status' => (string)($result['quality']['status'] ?? 'blocked'),
                'channel_role' => $platformEligible
                    ? ($result['analysis']['channel_role'] ?? null)
                    : null,
                'first_conflict' => $platformEligible
                    ? ($result['analysis']['first_conflict'] ?? null)
                    : null,
                'candidate_group_counts' => $candidateGroups,
                'evidence_contract' => is_array($result['evidence_contract'] ?? null)
                    ? $result['evidence_contract']
                    : [],
                'source_refs' => [
                    'facts' => 'competition_circle_bundle.facts.' . $platform,
                    'derived_metrics' => 'competition_circle_bundle.derived_metrics.' . $platform,
                    'analysis' => 'competition_circle_bundle.analysis.' . $platform,
                    'evidence_contract' => 'competition_circle_bundle.evidence_contracts.' . $platform,
                    'candidates' => 'competition_circle_bundle.candidate_competitors.' . $platform,
                    'quality' => 'competition_circle_bundle.quality',
                ],
            ];
        }

        return [
            'schema_version' => 'suxios.ota_competition_report.v1',
            'status' => $decisionEligible ? 'ready_for_review' : 'blocked',
            'artifact_kind' => 'interactive_decision_report',
            'title' => 'OTA竞争商圈经营报告',
            'scope' => [
                'metric_scope' => 'ota_channel',
                'whole_hotel_truth' => false,
                'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0),
                'data_date' => (string)($source['data_date'] ?? ''),
                'dataset_kind' => (string)($source['dataset_kind'] ?? ''),
                'readback_verified' => ($source['readback_verified'] ?? false) === true,
                'eligible_platforms' => $eligiblePlatforms,
            ],
            'management_snapshot' => [
                'quality_status' => (string)($quality['status'] ?? 'blocked'),
                'decision_eligible' => $decisionEligible,
                'platforms_ready' => count($eligiblePlatforms),
                'platforms_total' => 2,
                'full_circle_platforms' => count(array_filter(
                    $platformSections,
                    static fn(array $section): bool => ($section['evidence_contract']['full_circle_ready'] ?? false) === true
                )),
                'first_conflicts' => array_values(array_filter(array_map(
                    static fn(string $platform): ?array => isset($platformSections[$platform])
                        ? [
                            'platform' => $platform,
                            'label' => (string)$platformSections[$platform]['label'],
                            'value' => $platformSections[$platform]['first_conflict'],
                        ]
                        : null,
                    $eligiblePlatforms
                ), 'is_array')),
                'action_status' => (string)($recommendations['status'] ?? 'withheld'),
                'action_count' => count(array_filter((array)($recommendations['items'] ?? []), 'is_array')),
            ],
            'platform_sections' => $platformSections,
            'data_gaps' => array_values(array_filter((array)($quality['data_gaps'] ?? []), 'is_array')),
            'actions' => array_values(array_filter((array)($recommendations['items'] ?? []), 'is_array')),
            'render_contract' => [
                'requested_edition' => $edition,
                'single_calculation' => true,
                'source_fingerprint' => $sourceFingerprint,
                'saved_with_daily_report' => true,
                'exact_readback_required' => true,
                'offline_html_export_allowed' => true,
                'commercial_release_ready' => false,
                'commercial_artifacts_generated' => [],
                'commercial_boundary' => '界面版不是携程30—35/70—86页或美团40/66+页商业DOCX/HTML交付。',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function buildXiaohongshuDraft(
        array $reportDocument,
        array $quality,
        string $sourceFingerprint
    ): array {
        $eligiblePlatforms = array_values((array)($quality['eligible_platforms'] ?? []));
        if (($quality['decision_eligible'] ?? false) !== true || $eligiblePlatforms === []) {
            return [
                'schema_version' => 'suxios.xiaohongshu.content_draft.v1',
                'status' => 'withheld',
                'publication_status' => 'not_created',
                'blocked_reason' => (string)(($quality['data_gaps'][0]['code'] ?? '') ?: 'verified_report_required'),
                'source_fingerprint' => $sourceFingerprint,
                'human_review_required' => true,
                'auto_publish' => false,
                'external_write' => false,
            ];
        }

        $platformLabel = count($eligiblePlatforms) > 1
            ? '携程和美团'
            : ($eligiblePlatforms[0] === 'ctrip' ? '携程' : '美团');
        $topic = count($eligiblePlatforms) > 1
            ? '看双OTA竞争商圈，先分平台再下判断'
            : '看' . $platformLabel . '竞争商圈，先分清证据再谈动作';
        $coreLine = $eligiblePlatforms[0] === 'meituan' && count($eligiblePlatforms) === 1
            ? '曝光、浏览、订单、销售和入住不是一个口径，先拆开看。'
            : '流量、转化、价格和价值不是一个问题，先拆开看。';

        return [
            'schema_version' => 'suxios.xiaohongshu.content_draft.v1',
            'status' => 'ready_for_human_review',
            'publication_status' => 'draft_only',
            'source_fingerprint' => $sourceFingerprint,
            'source_report_schema' => (string)($reportDocument['schema_version'] ?? ''),
            'source_summary' => '从本次已回读的OTA竞争商圈报告方法生成；未复制酒店、竞店或精确经营数值。',
            'privacy' => [
                'hotel_names_removed' => true,
                'competitor_names_removed' => true,
                'exact_business_values_removed' => true,
                'promissory_claims_forbidden' => true,
            ],
            'topic' => $topic,
            'titles_10' => [
                $topic,
                '竞争商圈报告，先别急着看谁价格低',
                '酒店做竞对分析，最容易混错的4个口径',
                '为什么有流量，订单还是接不住？',
                '看竞店之前，先把本店数据身份对上',
                'OTA商圈分析：事实、计算、判断要分层',
                '别用一天数据，判断酒店长期趋势',
                '低价高转化的竞店，为什么不能直接照抄？',
                '一份能执行的商圈报告，要有这3道门槛',
                '酒店竞对分析最后一步：保护线和回滚',
            ],
            'cover_titles_5' => [
                '商圈报告别先看价格',
                '先分清4个口径',
                '有流量≠会成交',
                '竞店不能直接抄',
                '动作必须能回滚',
            ],
            'pages_8' => [
                ['page' => 1, 'title' => '商圈报告别先看价格', 'points' => '先确认酒店、平台、日期和来源，数据身份错了，后面的结论都不成立。'],
                ['page' => 2, 'title' => '为什么总会看偏', 'points' => $coreLine],
                ['page' => 3, 'title' => '核心方法', 'points' => '把来源事实、可复算指标、经营判断和待验证假设分成四层。'],
                ['page' => 4, 'title' => '第一步：对身份', 'points' => '确认本店唯一、平台门店绑定正确、业务日期一致，并完成保存回读。'],
                ['page' => 5, 'title' => '第二步：分口径', 'points' => '平台字段保留平台定义，重算指标单列公式；没有分母就写缺失，不补0。'],
                ['page' => 6, 'title' => '第三步：设动作门槛', 'points' => '一次只改一个变量，写清观察期、保护线、停止条件和回滚。'],
                ['page' => 7, 'title' => '最容易踩的坑', 'points' => '不要把低价高转化竞店当价格标杆，也不要把单日快照写成长期趋势。'],
                ['page' => 8, 'title' => '今天就做这件事', 'points' => '找一份已回读的商圈数据，按“身份—口径—门槛”重新检查一遍。'],
            ],
            'post_text' => "做酒店竞争商圈分析，最容易犯的错，是打开报告先找谁价格最低。\n\n真正的第一步，是先把酒店、平台、业务日期和来源对上。数据有没有保存？能不能按同一报告ID回读？如果这些还没确认，后面的排名、差距和动作都只能算待验证。\n\n第二步是分口径。{$coreLine} 平台直接给出的字段要保留原定义，自己重算的指标要单列公式；分母缺失时就明确写缺失，不能用0把问题藏起来。\n\n第三步才是动作。竞店低价、转化高，不代表本店照抄就有效。每次只测一个日期、一个房型或一个变量，同时写清观察期、保护线、停止条件和回滚。\n\n一份真正能用的商圈报告，不是结论越多越好，而是每个结论都能追到来源，每个动作都能停、能复盘。你看商圈报告时，最容易混淆的是流量、转化还是价格？",
            'tags_10' => [
                '#酒店收益管理', '#酒店运营', '#OTA运营', '#酒店店长', '#酒店业主',
                '#携程运营', '#美团酒店', '#竞争商圈', '#酒店数据分析', '#酒店经营',
            ],
            'comments_3' => [
                '先对酒店、平台、日期和来源，再看结论。',
                '想要“商圈报告检查清单”，可以留言说说你最常看的平台。',
                '本文只讲方法，不构成价格或收益承诺；实际动作要结合本店真实数据。',
            ],
            'image_spec' => [
                'width' => 1080,
                'height' => 1440,
                'page_count' => 8,
                'layout_rule' => '一页一个重点；封面短标题；正文保留充足留白。',
            ],
            'human_review_checklist' => [
                '补充本人真实经验，但不得编造数据、案例、奖项或收益结果。',
                '确认不含酒店名、竞店名、精确经营数值、客户信息或内部路径。',
                '检查语言是否符合账号定位，并由人工选择最终标题和封面。',
                '只通过小红书官方功能人工发布；系统不自动发布。',
            ],
            'human_review_required' => true,
            'auto_publish' => false,
            'external_write' => false,
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
        $bookingConversion = $this->ratioPercent($self['orders'] ?? null, $self['detail_visitors'] ?? null);
        $competitorBookingConversion = $this->ratioPercent(
            $average['orders'] ?? null,
            $average['detail_visitors'] ?? null
        );
        $evidenceContract = $this->buildCtripEvidenceContract(
            $self,
            $latestDate,
            $reportDate,
            $bindingStatus,
            $platformHotelIdentifierPresent,
            $decisionEligible,
            $bookingConversion
        );

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
                'platform_conversion_gap' => $this->difference(
                    $self['conversion_rate'] ?? null,
                    $average['conversion_rate'] ?? null
                ),
                'booking_conversion_rate' => $bookingConversion,
                'competitor_booking_conversion_rate' => $competitorBookingConversion,
            ],
            'evidence_contract' => $evidenceContract,
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
        $evidenceGroups = is_array($source['evidence_groups'] ?? null) ? $source['evidence_groups'] : [];
        $evidenceGroupCounts = [];
        foreach (['stay', 'sales', 'traffic', 'conversion'] as $group) {
            $rows = array_values(array_filter((array)($evidenceGroups[$group] ?? []), 'is_array'));
            $evidenceGroupCounts[$group] = $rows === [] ? null : count($rows);
        }
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
        $evidenceContract = $this->buildMeituanEvidenceContract(
            $latestDate,
            $reportDate,
            $targetBound,
            $rankStatus,
            $selfRank,
            $decisionEligible,
            $evidenceGroupCounts
        );

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
                'evidence_group_counts' => $evidenceGroupCounts,
                'source_evidence' => $sourceEvidence,
            ],
            'derived_metrics' => [
                'self_rank' => $selfRank,
                'top_rank' => $topRank,
                'rank_gap_to_top1' => $selfRank !== null && $topRank !== null ? $selfRank - $topRank : null,
            ],
            'evidence_contract' => $evidenceContract,
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

    private function ratioPercent(mixed $numerator, mixed $denominator): ?float
    {
        $numerator = $this->numberOrNull($numerator);
        $denominator = $this->numberOrNull($denominator);
        if ($numerator === null || $numerator < 0 || $denominator === null || $denominator <= 0) {
            return null;
        }
        return round($numerator / $denominator * 100, 4);
    }

    /** @return array<string,mixed> */
    private function buildCtripEvidenceContract(
        ?array $self,
        string $latestDate,
        string $reportDate,
        string $bindingStatus,
        bool $platformHotelIdentifierPresent,
        bool $decisionEligible,
        ?float $bookingConversion
    ): array {
        $self = is_array($self) ? $self : [];
        $ari = $this->numberOrNull($self['ari'] ?? null);
        $sci = $this->numberOrNull($self['sci'] ?? null);
        $platformConversion = $this->numberOrNull($self['conversion_rate'] ?? null);
        $orders = $this->numberOrNull($self['orders'] ?? null);
        $visitors = $this->numberOrNull($self['detail_visitors'] ?? null);
        $businessValues = [
            $this->numberOrNull($self['amount'] ?? null),
            $this->numberOrNull($self['room_nights'] ?? null),
            $this->numberOrNull($self['adr'] ?? null),
        ];
        $businessValueCount = count(array_filter(
            $businessValues,
            static fn(?float $value): bool => $value !== null
        ));
        $checks = [
            $this->evidenceCheck(
                'subject_identity',
                '本店唯一绑定',
                $bindingStatus === 'bound' && $platformHotelIdentifierPresent && $self !== [] ? 'available' : 'missing',
                true,
                'source_fact',
                $bindingStatus === 'bound' && $platformHotelIdentifierPresent ? 'bound' : null,
                '',
                'ctrip.context.binding_status',
                '平台酒店ID优先，且必须唯一命中本店。'
            ),
            $this->evidenceCheck(
                'target_date',
                '目标业务日期',
                $latestDate === $reportDate ? 'available' : 'missing',
                true,
                'source_fact',
                $latestDate !== '' ? $latestDate : null,
                '',
                'ctrip.business_comparison.latest_date',
                '只使用目标业务日期的同口径快照。'
            ),
            $this->evidenceCheck(
                'sales_room_nights_adr',
                '销售额/间夜/ADR',
                $businessValueCount === 3 ? 'available' : ($businessValueCount > 0 ? 'partial' : 'not_collected'),
                true,
                'source_fact',
                ['sales' => $businessValues[0], 'room_nights' => $businessValues[1], 'adr' => $businessValues[2]],
                '',
                'ctrip.business_comparison.self',
                'ADR只在销售额与间夜分母存在时使用。'
            ),
            $this->evidenceCheck(
                'ari_sci',
                'ARI/SCI平台字段',
                $ari !== null && $sci !== null ? 'available' : (($ari !== null || $sci !== null) ? 'partial' : 'not_collected'),
                true,
                'source_fact',
                ['ari' => $ari, 'sci' => $sci],
                'index',
                'ctrip.business_comparison.self',
                '仅解读携程来源值，不反推平台私有公式。'
            ),
            $this->evidenceCheck(
                'traffic_orders',
                'APP访客量/订单量',
                $visitors !== null && $orders !== null ? 'available' : (($visitors !== null || $orders !== null) ? 'partial' : 'not_collected'),
                true,
                'source_fact',
                ['detail_visitors' => $visitors, 'orders' => $orders],
                '',
                'ctrip.business_comparison.self',
                '访客量和订单量分别保留，缺失时不补零。'
            ),
            $this->evidenceCheck(
                'platform_conversion',
                '平台转化率',
                $platformConversion !== null ? 'available' : 'not_collected',
                true,
                'source_fact',
                $platformConversion,
                'platform_source_value',
                'ctrip.business_comparison.self.conversion_rate',
                '保留平台来源原值，不用订单/访客结果覆盖。'
            ),
            $this->evidenceCheck(
                'booking_conversion',
                '派生预订转化率',
                $bookingConversion !== null ? 'available' : 'unavailable',
                true,
                'derived_metric',
                $bookingConversion,
                '%',
                'competition_circle_bundle.derived_metrics.ctrip.booking_conversion_rate',
                '订单量 / APP详情页访客量 × 100%；分母缺失时为 null。'
            ),
            $this->evidenceCheck(
                'ctrip_rating',
                '携程点评分',
                $this->numberOrNull($self['ctrip_rating'] ?? null) !== null ? 'available' : 'not_collected',
                false,
                'source_fact',
                $this->numberOrNull($self['ctrip_rating'] ?? null),
                'score',
                'ctrip.business_comparison.self.ctrip_rating',
                '可选辅助字段，不作为当前完整度硬门槛。'
            ),
        ];

        return $this->finalizeEvidenceContract(
            'ctrip',
            $checks,
            $decisionEligible,
            'bounded_snapshot',
            '当前为携程经营快照，未达到完整商圈证据',
            [
                'platform_conversion_and_booking_conversion_are_separate' => true,
                'ari_sci_are_platform_fields_not_reverse_engineered' => true,
                'single_snapshot_has_no_trend_or_elasticity_claim' => true,
            ],
            [
                '平台转化率是来源字段；订单/访客是派生预订转化率，两者不得互相替代。',
                'ARI/SCI仅按携程平台字段解读，不反推私有公式。',
                '本合同只验证目标日快照，未单独验证可比历史序列；不据此输出环比、同比、趋势或已验证价格弹性。',
            ]
        );
    }

    /** @param array<string,?int> $groupCounts @return array<string,mixed> */
    private function buildMeituanEvidenceContract(
        string $latestDate,
        string $reportDate,
        bool $targetBound,
        string $rankStatus,
        ?float $selfRank,
        bool $decisionEligible,
        array $groupCounts
    ): array {
        $checks = [
            $this->evidenceCheck(
                'subject_identity',
                '本店POI唯一绑定',
                $targetBound ? 'available' : 'missing',
                true,
                'source_fact',
                $targetBound ? 'bound' : null,
                '',
                'meituan.target_poi_bound',
                '必须先确认本店POI，排名摘要才可用于本店判断。'
            ),
            $this->evidenceCheck(
                'target_date',
                '目标业务日期',
                $latestDate === $reportDate ? 'available' : 'missing',
                true,
                'source_fact',
                $latestDate !== '' ? $latestDate : null,
                '',
                'meituan.latest_data_date',
                '只使用目标业务日期的同口径快照。'
            ),
            $this->evidenceCheck(
                'rank_summary',
                '本店排名摘要',
                $rankStatus === 'ok' && $selfRank !== null ? 'available' : 'missing',
                true,
                'source_fact',
                $selfRank,
                'rank',
                'meituan.self_position_text',
                '排名摘要仅支持有界的排名研判。'
            ),
        ];
        foreach ([
            'stay' => ['入住榜全量明细', '入住间夜与客房收入'],
            'sales' => ['销售榜全量明细', '销售间夜与销售收入'],
            'traffic' => ['流量榜全量明细', '曝光量与浏览量'],
            'conversion' => ['转化榜全量明细', '平台浏览、支付与绝对转化字段'],
        ] as $group => [$label, $definition]) {
            $count = $groupCounts[$group] ?? null;
            $checks[] = $this->evidenceCheck(
                $group . '_ranking',
                $label,
                is_int($count) && $count > 0 ? 'available' : 'not_collected',
                true,
                'source_fact',
                $count,
                'rows',
                'meituan.evidence_groups.' . $group,
                $definition . '；必须包含可核对的本店行和竞品行。'
            );
        }

        return $this->finalizeEvidenceContract(
            'meituan',
            $checks,
            $decisionEligible,
            'rank_summary_only',
            '当前仅有排名摘要，四榜证据未齐',
            [
                'sales_stay_gap_is_not_cancellation_rate' => true,
                'platform_rates_and_derived_rates_are_separate' => true,
                'single_snapshot_has_no_trend_or_elasticity_claim' => true,
            ],
            [
                '销售榜与入住榜属于不同时间窗口；两者差额不是取消率，缺少取消字段时不得推断取消。',
                '平台支付/绝对转化率与自行派生率分开，不与携程口径混用。',
                '本合同只验证目标日快照，未单独验证可比历史序列；不据此输出环比、同比、趋势或已验证价格弹性。',
            ]
        );
    }

    /** @param array<int,array<string,mixed>> $checks @return array<string,mixed> */
    private function finalizeEvidenceContract(
        string $platform,
        array $checks,
        bool $decisionEligible,
        string $incompleteScope,
        string $incompleteLabel,
        array $formulaBoundaries,
        array $caveats
    ): array {
        $required = array_values(array_filter(
            $checks,
            static fn(array $check): bool => ($check['required'] ?? false) === true
        ));
        $available = array_values(array_filter(
            $required,
            static fn(array $check): bool => ($check['status'] ?? '') === 'available'
        ));
        $missingLabels = array_values(array_map(
            static fn(array $check): string => (string)($check['label'] ?? $check['key'] ?? ''),
            array_filter($required, static fn(array $check): bool => ($check['status'] ?? '') !== 'available')
        ));
        $fieldComplete = count($available) === count($required) && $required !== [];
        $fullCircleReady = $fieldComplete && $decisionEligible;
        $analysisScope = $fullCircleReady
            ? 'full_circle_snapshot'
            : ($decisionEligible ? $incompleteScope : 'insufficient_evidence');
        $scopeLabel = $fullCircleReady
            ? '完整商圈单期快照证据已齐'
            : ($decisionEligible ? $incompleteLabel : '来源或字段未达到可信研判门槛');

        return [
            'platform' => $platform,
            'analysis_scope' => $analysisScope,
            'scope_label' => $scopeLabel,
            'field_complete' => $fieldComplete,
            'full_circle_ready' => $fullCircleReady,
            'required_checks_available' => count($available),
            'required_checks_total' => count($required),
            'missing_required_labels' => $missingLabels,
            'checks' => $checks,
            'formula_boundaries' => $formulaBoundaries,
            'caveats' => $caveats,
        ];
    }

    /** @return array<string,mixed> */
    private function evidenceCheck(
        string $key,
        string $label,
        string $status,
        bool $required,
        string $resultLayer,
        mixed $value,
        string $unit,
        string $sourceRef,
        string $definition
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'required' => $required,
            'result_layer' => $resultLayer,
            'value' => $value,
            'unit' => $unit,
            'source_ref' => $sourceRef,
            'definition' => $definition,
        ];
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
