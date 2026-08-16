<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds a Ctrip-channel evidence radar from an already scoped OTA diagnosis.
 *
 * The radar is deliberately not a Ctrip score or ranking formula. It only
 * groups persisted channel facts and names the direct facts that are still
 * missing. It must never be used to authorize an OTA/PMS write.
 */
final class CtripOperatingRadarDiagnosisService
{
    public const SCHEMA_VERSION = 2;
    public const CONTRACT_VERSION = 'ctrip_operating_radar.v2';
    public const KNOWLEDGE_VERSION = '2026-08-11.4';

    /** @return array<string,mixed> */
    public function build(array $diagnosis): array
    {
        $platform = strtolower(trim((string)($diagnosis['platform'] ?? '')));
        if ($platform !== 'ctrip') {
            throw new \InvalidArgumentException('Ctrip operating radar only accepts platform=ctrip.');
        }

        $metrics = is_array($diagnosis['metrics'] ?? null) ? $diagnosis['metrics'] : [];
        $requestedRange = $this->dateRange(
            is_array($diagnosis['requested_date_range'] ?? null)
                ? $diagnosis['requested_date_range']
                : (array)($diagnosis['date_range'] ?? [])
        );
        $effectiveRange = $this->dateRange(
            is_array($diagnosis['effective_date_range'] ?? null)
                ? $diagnosis['effective_date_range']
                : (array)($diagnosis['date_range'] ?? [])
        );
        $usesLatestHistory = ($diagnosis['data_summary']['used_latest_available_data'] ?? false) === true;
        $hasTargetData = ($diagnosis['data_summary']['has_ota_data'] ?? false) === true && !$usesLatestHistory;
        $evidenceIndex = $this->evidenceIndex((array)($diagnosis['evidence_sources'] ?? []));

        $definitions = $this->dimensionDefinitions();
        $dimensions = [];
        foreach ($definitions as $definition) {
            $dimensions[] = $this->buildDimension(
                $definition,
                $metrics,
                $evidenceIndex,
                $hasTargetData,
                $usesLatestHistory
            );
        }

        $statusCounts = [
            'observed_channel_signal' => 0,
            'partial_evidence' => 0,
            'blocked_by_data' => 0,
        ];
        $allEvidenceRefs = [];
        foreach ($dimensions as $dimension) {
            $status = (string)($dimension['status'] ?? 'blocked_by_data');
            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status]++;
            }
            $allEvidenceRefs = array_merge($allEvidenceRefs, (array)($dimension['evidence_refs'] ?? []));
        }
        $allEvidenceRefs = array_values(array_unique(array_map('strval', $allEvidenceRefs)));
        sort($allEvidenceRefs, SORT_STRING);

        $overallStatus = $statusCounts['observed_channel_signal'] + $statusCounts['partial_evidence'] > 0
            ? 'partial_evidence'
            : 'blocked_by_data';
        $message = $usesLatestHistory
            ? '目标日期没有携程事实；五维仅保留最近历史参考，不能代表目标日期，也不能据此行动。'
            : ($hasTargetData
                ? '已按携程渠道事实整理五维证据与缺口；当前不计算携程官方分数、权重或排名。'
                : '目标日期没有可用携程事实；五个维度均保持数据受阻。');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'knowledge' => [
                'module_id' => 'ctrip_hotel_operating_radar',
                'truth_profile_version' => self::KNOWLEDGE_VERSION,
                'runtime_policy' => 'evidence_coverage_only_no_platform_score_inference',
                'source_status' => 'mixed_official_and_unverified_reference',
            ],
            'scope' => [
                'hotel_id' => (int)($diagnosis['hotel']['id'] ?? $diagnosis['hotel_id'] ?? 0),
                'platform' => 'ctrip',
                'requested_start_date' => $requestedRange['start_date'],
                'requested_end_date' => $requestedRange['end_date'],
                'effective_start_date' => $effectiveRange['start_date'],
                'effective_end_date' => $effectiveRange['end_date'],
                'source_scope' => 'ctrip_ota_channel_only',
                'uses_latest_available_history' => $usesLatestHistory,
            ],
            'status' => $overallStatus,
            'message' => $message,
            'summary' => [
                'dimension_count' => count($dimensions),
                'observed_count' => $statusCounts['observed_channel_signal'],
                'partial_count' => $statusCounts['partial_evidence'],
                'blocked_count' => $statusCounts['blocked_by_data'],
                'evidence_ref_count' => count($allEvidenceRefs),
            ],
            'dimensions' => $dimensions,
            'score_policy' => [
                'official_score_available' => false,
                'official_weights_available' => false,
                'official_formula_available' => false,
                'composite_score' => null,
                'single_dimension_determines_result' => false,
            ],
            'guards' => [
                'decision_safe' => false,
                'task_draft_safe' => false,
                'external_write_authorized' => false,
                'automatic_pricing' => false,
                'automatic_inventory_change' => false,
                'automatic_commission_change' => false,
                'automatic_marketing' => false,
                'automatic_ota_write' => false,
                'automatic_pms_write' => false,
                'blocked_uses' => [
                    'ctrip_official_score_or_ranking_inference',
                    'pricing_or_inventory_change',
                    'commission_or_service_fee_change',
                    'marketing_or_traffic_purchase',
                    'automatic_task_creation',
                    'ota_or_pms_write',
                ],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function dimensionDefinitions(): array
    {
        return [
            [
                'key' => 'information_score',
                'label' => '信息分',
                'stage' => '信息浏览',
                'meaning' => '用户点击前对酒店信息完整、准确与真实呈现的判断。',
                'signal_metrics' => [
                    'list_exposure' => ['label' => '列表曝光', 'unit' => '次', 'role' => 'supporting_signal'],
                    'detail_visitors' => ['label' => '详情访问用户', 'unit' => '人', 'role' => 'supporting_signal'],
                    'detail_rate' => ['label' => '曝光到访问率', 'unit' => '%', 'role' => 'supporting_signal'],
                ],
                'direct_metrics' => [
                    'information_completeness_status' => ['label' => '信息完整度', 'unit' => '', 'role' => 'direct_platform_fact'],
                    'image_video_quality_status' => ['label' => '图片/视频质量', 'unit' => '', 'role' => 'direct_platform_fact'],
                    'facility_policy_accuracy_status' => ['label' => '设施与政策准确性', 'unit' => '', 'role' => 'direct_platform_fact'],
                ],
                'required_facts' => [
                    'information_completeness_status' => '酒店信息完整度',
                    'image_video_quality_status' => '图片/视频质量',
                    'facility_policy_accuracy_status' => '设施、政策与房型描述准确性',
                ],
                'tags' => ['traffic'],
                'next_check' => '在 eBooking 核对图片/视频、设施、政策和房型描述，并采集平台直接状态后重跑。',
            ],
            [
                'key' => 'friendliness',
                'label' => '友好度',
                'stage' => '预订决策',
                'meaning' => '用户对价格、房态、确认与退改便利性的感受。',
                'signal_metrics' => [
                    'adr' => ['label' => '渠道ADR', 'unit' => '元', 'role' => 'supporting_signal'],
                    'competitor_avg_price' => ['label' => '同口径竞对均价', 'unit' => '元', 'role' => 'supporting_signal'],
                    'order_rate' => ['label' => '访问到订单率', 'unit' => '%', 'role' => 'supporting_signal'],
                    'submit_rate' => ['label' => '订单到提交率', 'unit' => '%', 'role' => 'supporting_signal'],
                ],
                'direct_metrics' => [
                    'room_inventory_accuracy_status' => ['label' => '房态准确/充足', 'unit' => '', 'role' => 'direct_platform_fact'],
                    'cancellation_policy_flexibility_status' => ['label' => '取消政策灵活性', 'unit' => '', 'role' => 'direct_platform_fact'],
                    'instant_confirmation_rate' => ['label' => '即时确认率', 'unit' => '%', 'role' => 'direct_platform_fact'],
                    'comparable_package_price' => ['label' => '同口径套餐价格', 'unit' => '元', 'role' => 'direct_platform_fact'],
                ],
                'required_facts' => [
                    'room_inventory_accuracy_status' => '房态准确与充足状态',
                    'cancellation_policy_flexibility_status' => '取消政策灵活性',
                    'instant_confirmation_rate' => '订单即时确认率',
                    'comparable_package_price' => '同日期、房型、餐食、退改和税费口径的套餐价格',
                ],
                'tags' => ['traffic', 'price', 'order'],
                'next_check' => '补齐同口径价格、房态、即时确认和退改政策事实；不要用ADR单独判断友好度。',
            ],
            [
                'key' => 'quality',
                'label' => '品质度',
                'stage' => '到店入住',
                'meaning' => '入住服务、履约、投诉与用户权益的综合体验证据。',
                'signal_metrics' => [
                    'avg_psi_score' => ['label' => '服务质量信号', 'unit' => '', 'role' => 'supporting_signal'],
                    'avg_service_score' => ['label' => '服务评分', 'unit' => '', 'role' => 'supporting_signal'],
                    'avg_im_score' => ['label' => 'IM服务评分', 'unit' => '', 'role' => 'supporting_signal'],
                    'avg_reply_rate' => ['label' => '回复率', 'unit' => '%', 'role' => 'supporting_signal'],
                    'comment_score' => ['label' => '点评分', 'unit' => '', 'role' => 'supporting_signal'],
                ],
                'direct_metrics' => [
                    'complaint_rate' => ['label' => '投诉率', 'unit' => '%', 'role' => 'direct_platform_fact'],
                    'service_defect_count' => ['label' => '服务缺陷数', 'unit' => '项', 'role' => 'direct_platform_fact'],
                    'order_confirmation_rate' => ['label' => '订单确认率', 'unit' => '%', 'role' => 'direct_platform_fact'],
                    'user_rights_incident_count' => ['label' => '用户权益事件数', 'unit' => '项', 'role' => 'direct_platform_fact'],
                ],
                'required_facts' => [
                    'complaint_rate' => '用户投诉率',
                    'service_defect_count' => '六大类服务缺陷事实',
                    'order_confirmation_rate' => '订单确认率',
                    'user_rights_incident_count' => '用户权益事件',
                ],
                'tags' => ['service_quality', 'quality', 'review'],
                'next_check' => '补齐投诉、订单确认、用户权益和服务缺陷事实，再与现有服务信号交叉核验。',
            ],
            [
                'key' => 'welcome',
                'label' => '欢迎度',
                'stage' => '长期价值',
                'meaning' => '用户真实选择在携程渠道订单、间夜、收入与转化中的呈现。',
                'signal_metrics' => [
                    'amount' => ['label' => '渠道成交额', 'unit' => '元', 'role' => 'direct_channel_outcome'],
                    'quantity' => ['label' => '渠道间夜', 'unit' => '间夜', 'role' => 'direct_channel_outcome'],
                    'book_order_num' => ['label' => '渠道订单', 'unit' => '单', 'role' => 'direct_channel_outcome'],
                    'detail_visitors' => ['label' => '详情访问用户', 'unit' => '人', 'role' => 'direct_channel_outcome'],
                    'order_rate' => ['label' => '访问到订单率', 'unit' => '%', 'role' => 'direct_channel_outcome'],
                    'submit_rate' => ['label' => '订单到提交率', 'unit' => '%', 'role' => 'direct_channel_outcome'],
                    'hotel_collect' => ['label' => '酒店收藏', 'unit' => '次', 'role' => 'direct_channel_outcome'],
                ],
                'direct_metrics' => [],
                'required_facts' => [
                    'repeat_booking_rate' => '复购率或回头客事实',
                    'fraud_transaction_status' => '虚假交易与恶意刷单排除状态',
                    'official_welcome_definition' => '携程欢迎度官方计算定义',
                ],
                'tags' => ['traffic', 'order', 'revenue'],
                'next_check' => '持续保存同口径订单、间夜、成交额和转化；另行核验复购与异常交易状态。',
                'observed_rule' => 'outcome_and_conversion',
            ],
            [
                'key' => 'platform_technical_service_fee',
                'label' => '服务费',
                'stage' => '平台合作',
                'meaning' => '平台技术服务费、账务与合作稳定性的直接事实。',
                'signal_metrics' => [],
                'direct_metrics' => [
                    'technical_service_fee_rate' => ['label' => '技术服务费率', 'unit' => '%', 'role' => 'direct_platform_fact'],
                    'service_fee_amount' => ['label' => '技术服务费金额', 'unit' => '元', 'role' => 'direct_platform_fact'],
                    'bill_overdue_count' => ['label' => '逾期账单数', 'unit' => '笔', 'role' => 'direct_platform_fact'],
                ],
                'required_facts' => [
                    'technical_service_fee_rate' => '平台技术服务费率',
                    'service_fee_amount' => '平台技术服务费账单金额',
                    'bill_overdue_count' => '逾期账单状态',
                ],
                'tags' => ['service_fee', 'billing'],
                'next_check' => '仅从携程账单或正式合作资料采集技术服务费与账务事实；不得用佣金率代填。',
            ],
        ];
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $metrics @param array<string,array<string,mixed>> $evidenceIndex */
    private function buildDimension(
        array $definition,
        array $metrics,
        array $evidenceIndex,
        bool $hasTargetData,
        bool $usesLatestHistory
    ): array {
        $metricRows = [];
        foreach (array_merge((array)$definition['signal_metrics'], (array)$definition['direct_metrics']) as $key => $meta) {
            if (!$this->hasValue($metrics, (string)$key)) {
                continue;
            }
            $value = $metrics[$key];
            $metricRows[] = [
                'key' => (string)$key,
                'label' => (string)($meta['label'] ?? $key),
                'value' => $value,
                'display_value' => $this->displayValue($value, (string)($meta['unit'] ?? '')),
                'unit' => (string)($meta['unit'] ?? ''),
                'evidence_role' => $usesLatestHistory ? 'historical_reference_only' : (string)($meta['role'] ?? 'supporting_signal'),
            ];
        }

        $missingFacts = [];
        foreach ((array)$definition['required_facts'] as $key => $label) {
            if (!$this->hasValue($metrics, (string)$key)) {
                $missingFacts[] = ['key' => (string)$key, 'label' => (string)$label];
            }
        }

        $directKeys = array_keys((array)$definition['direct_metrics']);
        $hasDirectFact = false;
        foreach ($directKeys as $key) {
            $hasDirectFact = $hasDirectFact || $this->hasValue($metrics, (string)$key);
        }
        $status = 'blocked_by_data';
        if ($hasTargetData && $metricRows !== []) {
            $status = 'partial_evidence';
            if ($hasDirectFact || $this->welcomeObserved($definition, $metrics)) {
                $status = 'observed_channel_signal';
            }
        }

        $evidenceRefs = $this->selectEvidenceRefs($definition, $metricRows, $evidenceIndex, $hasTargetData);
        $rootEvidenceRefs = array_values(array_filter(
            $evidenceRefs,
            static fn(string $ref): bool => str_starts_with($ref, 'online_daily_data#')
        ));
        $hasRootChannelEvidence = $rootEvidenceRefs !== [];
        if ($status !== 'blocked_by_data' && !$hasRootChannelEvidence) {
            $status = 'blocked_by_data';
            foreach ($metricRows as &$metricRow) {
                $metricRow['evidence_role'] = 'unverified_derived_without_ctrip_channel_root';
            }
            unset($metricRow);
        }
        $interpretation = match ($status) {
            'observed_channel_signal' => '已取得同范围渠道事实，但仍不是携程官方维度得分。',
            'partial_evidence' => '已有相关经营信号，直接维度事实仍不完整，不能推导得分或排名。',
            default => $hasTargetData && $metricRows !== [] && !$hasRootChannelEvidence
                ? '仅找到派生摘要，未闭合到可决策的携程渠道根记录；本维度保持阻断。'
                : ($usesLatestHistory
                ? '仅有最近历史参考；目标日期事实缺失，本维度保持阻断。'
                : '目标日期没有足够事实，本维度保持阻断。'),
        };

        return [
            'key' => (string)$definition['key'],
            'label' => (string)$definition['label'],
            'stage' => (string)$definition['stage'],
            'meaning' => (string)$definition['meaning'],
            'status' => $status,
            'status_label' => match ($status) {
                'observed_channel_signal' => '已取得渠道信号',
                'partial_evidence' => '部分证据',
                default => '数据不足',
            },
            'official_score' => null,
            'metric_count' => count($metricRows),
            'metrics' => $metricRows,
            'missing_facts' => $missingFacts,
            'evidence_refs' => $evidenceRefs,
            'root_evidence_status' => $hasRootChannelEvidence ? 'verified' : 'missing',
            'root_evidence_refs' => $rootEvidenceRefs,
            'interpretation' => $interpretation,
            'next_check' => (string)$definition['next_check'],
        ];
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $metrics */
    private function welcomeObserved(array $definition, array $metrics): bool
    {
        if (($definition['observed_rule'] ?? '') !== 'outcome_and_conversion') {
            return false;
        }
        $hasOutcome = $this->hasAnyValue($metrics, ['amount', 'quantity', 'book_order_num']);
        $hasConversion = $this->hasAnyValue($metrics, ['detail_visitors', 'order_rate', 'submit_rate']);
        return $hasOutcome && $hasConversion;
    }

    /** @param array<string,mixed> $definition @param array<int,array<string,mixed>> $metricRows @param array<string,array<string,mixed>> $evidenceIndex @return array<int,string> */
    private function selectEvidenceRefs(array $definition, array $metricRows, array $evidenceIndex, bool $hasTargetData): array
    {
        $scopeRefs = array_values(array_filter(
            array_keys($evidenceIndex),
            static fn(string $ref): bool => in_array(
                $ref,
                ['ota_no_data_scope', 'ota_latest_available_not_target_date'],
                true
            )
        ));
        if (!$hasTargetData || $metricRows === []) {
            sort($scopeRefs, SORT_STRING);
            return $scopeRefs;
        }

        $refs = isset($evidenceIndex['source_summary']) ? ['source_summary'] : [];
        if ($hasTargetData) {
            foreach ($evidenceIndex as $ref => $source) {
                if (!$this->isDecisionEligibleCtripChannelEvidence($ref, $source)) {
                    continue;
                }
                $sourceMetrics = is_array($source['metrics'] ?? null) ? $source['metrics'] : [];
                $metricMatches = false;
                foreach ($metricRows as $metric) {
                    if ($this->hasValue($sourceMetrics, (string)($metric['key'] ?? ''))) {
                        $metricMatches = true;
                        break;
                    }
                }
                $sourceTags = array_map('strval', (array)($source['tags'] ?? []));
                $tagMatches = array_intersect($sourceTags, array_map('strval', (array)($definition['tags'] ?? []))) !== [];
                if ($metricMatches || $tagMatches) {
                    $refs[] = $ref;
                }
            }
        }
        $refs = array_values(array_unique(array_filter(array_map('strval', $refs))));
        sort($refs, SORT_STRING);
        return $refs;
    }

    /** @param array<string,mixed> $source */
    private function isDecisionEligibleCtripChannelEvidence(string $ref, array $source): bool
    {
        return str_starts_with($ref, 'online_daily_data#')
            && strtolower(trim((string)($source['table'] ?? ''))) === 'online_daily_data'
            && strtolower(trim((string)($source['platform'] ?? ''))) === 'ctrip'
            && ($source['decision_eligible'] ?? false) === true;
    }

    /** @param array<int,mixed> $sources @return array<string,array<string,mixed>> */
    private function evidenceIndex(array $sources): array
    {
        $index = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $ref = trim((string)($source['ref'] ?? ''));
            if ($ref !== '') {
                $index[$ref] = $source;
            }
        }
        return $index;
    }

    /** @param array<string,mixed> $values */
    private function hasValue(array $values, string $key): bool
    {
        return $key !== ''
            && array_key_exists($key, $values)
            && $values[$key] !== null
            && $values[$key] !== '';
    }

    /** @param array<string,mixed> $values @param array<int,string> $keys */
    private function hasAnyValue(array $values, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->hasValue($values, $key)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{start_date:string,end_date:string} */
    private function dateRange(array $range): array
    {
        $startDate = trim((string)($range['start_date'] ?? $range['start'] ?? ''));
        $endDate = trim((string)($range['end_date'] ?? $range['end'] ?? $startDate));
        return ['start_date' => $startDate, 'end_date' => $endDate];
    }

    private function displayValue(mixed $value, string $unit): string
    {
        if (!is_numeric($value)) {
            return trim((string)$value) !== '' ? trim((string)$value) : '-';
        }
        $number = (float)$value;
        $formatted = abs($number - round($number)) < 0.0000001
            ? (string)(int)round($number)
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        if ($unit === '元') {
            return '¥' . $formatted;
        }
        return $formatted . $unit;
    }
}
