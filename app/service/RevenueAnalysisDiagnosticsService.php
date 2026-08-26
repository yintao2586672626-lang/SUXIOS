<?php
declare(strict_types=1);

namespace app\service;

/**
 * Converts the canonical three-source fact layer into a decision-facing
 * validation and metric-diagnostic contract. It never reloads or repairs data;
 * the fact layer remains the only authority for values and readback status.
 */
final class RevenueAnalysisDiagnosticsService
{
    public const CONTRACT_VERSION = 'revenue_analysis_diagnostics.v1';
    public const METHODOLOGY_VERSION = 'data-analytics.0.2.8-13ceeea1f599';

    /** @var array<string,string> */
    private const REQUIRED_OTA_SOURCES = [
        'ctrip_ota' => '携程OTA渠道事实',
        'meituan_ota' => '美团OTA渠道事实',
    ];

    /** @return array<string,mixed> */
    public function build(array $factLayer): array
    {
        $status = $this->analysisStatus($factLayer['revenue_analysis_status'] ?? null);
        $hotel = is_array($factLayer['hotel'] ?? null) ? $factLayer['hotel'] : [];
        $businessDate = trim((string)($factLayer['business_date'] ?? ''));
        $scopeReady = (int)($hotel['tenant_id'] ?? 0) > 0
            && (int)($hotel['system_hotel_id'] ?? 0) > 0
            && $this->isDate($businessDate);
        $sourceCompleteness = is_array($factLayer['source_completeness'] ?? null)
            ? $factLayer['source_completeness']
            : [];
        $pmsSelection = (new RevenuePmsFactSelectorService())
            ->select($factLayer);
        $pmsSourceKey = (string)$pmsSelection['source_key'];
        $requiredSources = [
            $pmsSourceKey => (string)$pmsSelection['label']
                . ' 全酒店住宿事实',
            ...self::REQUIRED_OTA_SOURCES,
        ];
        $verifiedSourceCount = 0;
        $sourceChecks = [];
        foreach ($requiredSources as $source => $label) {
            $sourceStatus = $source === $pmsSourceKey
                ? (string)$pmsSelection['data_status']
                : trim((string)($sourceCompleteness[$source] ?? 'missing'));
            $verified = $sourceStatus === 'readback_verified';
            if ($verified) {
                $verifiedSourceCount++;
            }
            $sourceChecks[] = [
                'source' => $source,
                'label' => $label,
                'status' => $verified ? 'passed' : 'blocked',
                'readback_status' => $sourceStatus,
                'business_date' => $businessDate !== '' ? $businessDate : null,
            ];
        }

        $metrics = is_array($factLayer['analysis_metrics'] ?? null)
            ? $factLayer['analysis_metrics']
            : [];
        $metricDiagnostics = [];
        $calculableMetricCount = 0;
        foreach ($metrics as $key => $metric) {
            if (!is_array($metric)) {
                continue;
            }
            $diagnostic = $this->metricDiagnostic((string)$key, $metric);
            if ($diagnostic['status'] === 'ok') {
                $calculableMetricCount++;
            }
            $metricDiagnostics[] = $diagnostic;
        }

        $analysisGaps = $this->arrayRows($factLayer['analysis_gaps'] ?? []);
        $reviewGaps = $this->arrayRows($factLayer['ai_review_gaps'] ?? []);
        $issues = $this->issues($analysisGaps, $reviewGaps);
        $analysisAllowed = $status === 'ready'
            && ($factLayer['all_three_sources_readback_verified'] ?? false) === true;
        $aiReviewAllowed = $analysisAllowed
            && (string)($factLayer['ai_review_status'] ?? '') === 'ready_for_manual_review';
        $nullPolicyPassed = is_array($factLayer['aggregation_policy'] ?? null)
            && array_key_exists('missing_source_value', $factLayer['aggregation_policy'])
            && $factLayer['aggregation_policy']['missing_source_value'] === null
            && ($factLayer['aggregation_policy']['pms_plus_ota_revenue_addition_allowed'] ?? null) === false
            && ($factLayer['aggregation_policy']['ota_data_may_represent_whole_hotel_revenue'] ?? null) === false;
        $allMetricsCalculable = $metricDiagnostics !== []
            && $calculableMetricCount === count($metricDiagnostics);
        $metricCheckStatus = $metricDiagnostics === []
            ? 'blocked'
            : ($allMetricsCalculable ? 'passed' : 'warning');
        $sourceCheckStatus = $verifiedSourceCount === count($requiredSources)
            ? 'passed'
            : ($pmsSelection['data_status'] === 'readback_verified'
                ? 'warning'
                : 'blocked');
        $dateAlignment = is_array($factLayer['date_alignment'] ?? null)
            ? $factLayer['date_alignment']
            : [];
        $dateAlignmentPassed = $scopeReady
            && in_array((string)($dateAlignment['status'] ?? ''), [
                'aligned',
                'same_date_key_distinct_source_semantics',
            ], true);

        $checks = [
            $this->check(
                'scope_identity',
                '酒店、租户与业务日身份',
                $scopeReady ? 'passed' : 'blocked',
                $scopeReady
                    ? 'tenant_id、system_hotel_id 与 business_date 已明确。'
                    : '酒店、租户或业务日身份不完整。',
                '身份缺失时禁止读取或解释跨酒店事实。'
            ),
            $this->check(
                'target_date_alignment',
                '同店同日、分来源语义',
                $dateAlignmentPassed ? 'passed' : 'blocked',
                $dateAlignmentPassed
                    ? 'PMS 经营日与 OTA data_date 使用同一日期键分层比较。'
                    : '尚未证明目标日期对齐及来源语义隔离。',
                '日期或语义未对齐时禁止跨源比较。'
            ),
            $this->check(
                'source_readback',
                '三源保存与精确回读',
                $sourceCheckStatus,
                $verifiedSourceCount . '/' . count($requiredSources) . ' 个必需来源已精确回读。',
                '未回读来源的事实和依赖指标必须保持为空。'
            ),
            $this->check(
                'metric_calculability',
                '指标可计算性',
                $metricCheckStatus,
                $calculableMetricCount . '/' . count($metricDiagnostics) . ' 个事实层指标可计算。',
                '不可计算指标保留原因，不以 0、旧值或预测值补齐。'
            ),
            $this->check(
                'null_and_scope_policy',
                '缺失值与口径隔离',
                $nullPolicyPassed ? 'passed' : 'blocked',
                $nullPolicyPassed
                    ? '缺失保持 null；PMS 与 OTA 收入禁止跨口径相加。'
                    : '缺失值或跨口径聚合策略未通过。',
                '策略未通过时禁止对外给出收益结论。'
            ),
            $this->check(
                'pricing_review_guard',
                '最低保护价与人工审核',
                $aiReviewAllowed ? 'passed' : ($analysisAllowed ? 'warning' : 'blocked'),
                $aiReviewAllowed
                    ? '已具备进入人工调价审核的最低输入。'
                    : '事实分析与调价审核分开判定；当前审核输入仍有缺口。',
                '不满足时只展示事实诊断，不生成可执行调价结论。'
            ),
        ];

        $assessment = !$analysisAllowed
            ? 'needs_revision'
            : ($aiReviewAllowed && $issues === [] ? 'ready_to_share' : 'share_with_caveats');
        $summary = $this->summary($assessment, $verifiedSourceCount, $issues);
        $nextAction = $issues[0]['next_action'] ?? (
            $aiReviewAllowed
                ? '保持同店同日三源回读，并在人工审核后记录执行与效果证据。'
                : '补齐调价审核输入后重新生成诊断；当前只使用已验证事实。'
        );

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'methodology' => [
                'version' => self::METHODOLOGY_VERSION,
                'skills' => [
                    'data-analytics:analyze-data-quality',
                    'data-analytics:validate-data',
                    'data-analytics:metric-diagnostics',
                ],
                'external_connector_used' => false,
                'fact_mutation_allowed' => false,
            ],
            'overall_assessment' => $assessment,
            'status' => $status,
            'confidence' => $analysisAllowed ? 'high' : ($verifiedSourceCount > 0 ? 'medium' : 'low'),
            'summary' => $summary,
            'scope' => [
                'tenant_id' => (int)($hotel['tenant_id'] ?? 0) ?: null,
                'system_hotel_id' => (int)($hotel['system_hotel_id'] ?? 0) ?: null,
                'business_date' => $businessDate !== '' ? $businessDate : null,
                'grain' => 'system_hotel_id + business_date + source',
            ],
            'decision_use' => [
                'revenue_analysis' => [
                    'allowed' => $analysisAllowed,
                    'status' => $analysisAllowed ? 'allowed' : $status,
                ],
                'ai_manual_review' => [
                    'allowed' => $aiReviewAllowed,
                    'status' => $aiReviewAllowed ? 'allowed' : 'blocked_by_required_inputs',
                ],
                'whole_hotel_generalization' => [
                    'allowed' => false,
                    'status' => 'blocked_by_scope',
                ],
            ],
            'checks' => $checks,
            'source_checks' => $sourceChecks,
            'metric_diagnostics' => $metricDiagnostics,
            'issues' => $issues,
            'next_action' => $nextAction,
            'evidence_summary' => [
                'required_source_count' => count($requiredSources),
                'readback_verified_source_count' => $verifiedSourceCount,
                'metric_count' => count($metricDiagnostics),
                'calculable_metric_count' => $calculableMetricCount,
                'issue_count' => count($issues),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function metricDiagnostic(string $fallbackKey, array $metric): array
    {
        $status = trim((string)($metric['status'] ?? 'not_calculable'));
        $truth = is_array($metric['truth'] ?? null) ? $metric['truth'] : [];
        return [
            'key' => trim((string)($metric['key'] ?? $fallbackKey)),
            'label' => trim((string)($metric['label'] ?? $fallbackKey)),
            'value' => array_key_exists('value', $metric) ? $metric['value'] : null,
            'unit' => trim((string)($metric['unit'] ?? '')),
            'status' => $status === 'ok' ? 'ok' : 'not_calculable',
            'reason' => $status === 'ok' ? '' : trim((string)($metric['reason'] ?? 'metric_not_calculable')),
            'scope' => trim((string)($metric['scope'] ?? 'unknown')),
            'date_basis' => trim((string)($metric['date_basis'] ?? 'unknown')),
            'source_channels' => $this->textList($metric['source_channels'] ?? []),
            'truth_status' => trim((string)($truth['status'] ?? 'unverified')),
        ];
    }

    /** @param array<int,array<string,mixed>> $analysisGaps
     *  @param array<int,array<string,mixed>> $reviewGaps
     *  @return array<int,array<string,mixed>>
     */
    private function issues(array $analysisGaps, array $reviewGaps): array
    {
        $analysisKeys = [];
        foreach ($analysisGaps as $gap) {
            $analysisKeys[$this->issueKey($gap)] = true;
        }
        $seen = [];
        $issues = [];
        foreach (array_merge($analysisGaps, $reviewGaps) as $gap) {
            $key = $this->issueKey($gap);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $issues[] = $this->normalizeIssue($gap, isset($analysisKeys[$key]));
        }
        $weights = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($issues, static function (array $left, array $right) use ($weights): int {
            return ($weights[$right['severity']] ?? 0) <=> ($weights[$left['severity']] ?? 0);
        });
        return $issues;
    }

    /** @return array<string,mixed> */
    private function normalizeIssue(array $gap, bool $blocksAnalysis): array
    {
        $code = trim((string)($gap['code'] ?? $gap['reason'] ?? 'revenue_analysis_gap'));
        $source = trim((string)($gap['source'] ?? 'unknown'));
        $severity = $this->issueSeverity($code, $source, $blocksAnalysis);
        return [
            'code' => $code,
            'source' => $source,
            'status' => trim((string)($gap['status'] ?? 'missing')),
            'category' => trim((string)($gap['category'] ?? 'data_quality')),
            'severity' => $severity,
            'confidence' => 'high',
            'decision_impact' => $blocksAnalysis
                ? 'blocks_revenue_analysis'
                : 'blocks_ai_manual_review',
            'message' => trim((string)(
                $gap['display_reason']
                ?? $gap['message']
                ?? $this->issueMessage($code)
            )),
            'next_action' => trim((string)(
                $gap['next_action']
                ?? $this->issueNextAction($code, $source)
            )),
            'evidence_gap_codes' => $this->textList($gap['evidence_gap_codes'] ?? []),
        ];
    }

    private function issueSeverity(string $code, string $source, bool $blocksAnalysis): string
    {
        if ($code === 'system_hotel_scope_unavailable') {
            return 'critical';
        }
        if ($code === 'floor_price_missing' || $source === 'pricing_guard') {
            return 'medium';
        }
        return $blocksAnalysis ? 'high' : 'medium';
    }

    private function issueMessage(string $code): string
    {
        return match ($code) {
            'system_hotel_scope_unavailable' => '未取得系统酒店与租户身份，收益分析已阻断。',
            'dingdandao_pms_not_readback_verified',
            'meituan_cloud_pms_not_readback_verified',
            'pms_not_readback_verified' => 'PMS 全酒店住宿事实尚未完成目标日精确回读。',
            'ctrip_ota_not_readback_verified' => '携程 OTA 渠道事实尚未完成目标日精确回读。',
            'meituan_ota_not_readback_verified' => '美团 OTA 渠道事实尚未完成目标日精确回读。',
            'floor_price_missing' => '三源事实可分析，但最低保护价缺失，不能进入调价审核。',
            default => '存在未通过的数据质量或分析验证项：' . $code . '。',
        };
    }

    private function issueNextAction(string $code, string $source): string
    {
        if ($code === 'floor_price_missing' || $source === 'pricing_guard') {
            return '为启用房型配置最低保护价，保存回显后重新进入人工调价审核。';
        }
        if ($code === 'system_hotel_scope_unavailable') {
            return '先选择有权限的系统酒店并确认租户与业务日，再重新读取事实。';
        }
        return '补齐对应来源的目标日采集、保存和精确回读；不要用 0、旧数据或其他酒店数据替代。';
    }

    /** @return array<string,string> */
    private function check(
        string $key,
        string $label,
        string $status,
        string $evidence,
        string $impact
    ): array {
        return compact('key', 'label', 'status', 'evidence', 'impact');
    }

    /** @param array<int,array<string,mixed>> $issues */
    private function summary(string $assessment, int $verifiedSourceCount, array $issues): string
    {
        if ($assessment === 'ready_to_share') {
            return '三源事实已完成同店同日回读，指标与口径校验通过，可进入人工收益审核。';
        }
        if ($assessment === 'share_with_caveats') {
            $message = trim((string)($issues[0]['message'] ?? '仍有非事实层审核缺口。'));
            return '三源事实可用于收益分析，但需带限制说明：' . $message;
        }
        $message = trim((string)($issues[0]['message'] ?? '必需事实尚未通过验证。'));
        return $verifiedSourceCount . '/' . (count(self::REQUIRED_OTA_SOURCES) + 1)
            . ' 个必需来源已回读；当前不能形成完整收益结论：' . $message;
    }

    private function issueKey(array $gap): string
    {
        return trim((string)($gap['source'] ?? 'unknown'))
            . ':'
            . trim((string)($gap['code'] ?? $gap['reason'] ?? 'revenue_analysis_gap'));
    }

    /** @return array<int,array<string,mixed>> */
    private function arrayRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_array'));
    }

    /** @return array<int,string> */
    private function textList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return array_values(array_unique($result));
    }

    private function analysisStatus(mixed $value): string
    {
        $status = trim((string)$value);
        return in_array($status, ['ready', 'partial', 'blocked'], true)
            ? $status
            : 'blocked';
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}
