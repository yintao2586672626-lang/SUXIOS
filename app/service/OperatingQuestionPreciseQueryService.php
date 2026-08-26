<?php
declare(strict_types=1);

namespace app\service;

/**
 * Deterministic metric finalizer for the existing operating-question save and
 * exact-readback loop. It reads only the strict fact packet already accepted
 * by OperatingQuestionService.
 */
final class OperatingQuestionPreciseQueryService
{
    public const CONTRACT_VERSION = 'suxios.operating_question_precise_query.v1';
    public const ROUTER_CONTRACT_VERSION = 'suxi_precise_query_router.v1';

    public function __construct(private readonly ?SemanticGlossaryService $glossary = null)
    {
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function finalize(array $payload): array
    {
        $question = trim((string)($payload['question'] ?? ''));
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $platform = trim((string)($scope['platform'] ?? ''));
        $facts = array_values(array_filter(
            is_array($payload['facts'] ?? null) ? $payload['facts'] : [],
            'is_array'
        ));
        $service = $this->glossary ?? new SemanticGlossaryService();
        $resolution = $service->resolve($question, $platform);
        $probe = $resolution;
        if (!is_array($resolution['primary'] ?? null)) {
            $probe = $service->resolve($question);
        }
        $probePrimary = is_array($probe['primary'] ?? null) ? $probe['primary'] : [];
        $isMetricQuestion = ($probePrimary['is_business_metric'] ?? false) === true;
        if (!$isMetricQuestion) {
            return [
                'applied' => false,
                'reason' => 'no_deterministic_metric_intent',
                'semantic_resolution' => $resolution,
            ];
        }

        $router = $this->router($resolution, $probePrimary, $scope);
        if (($resolution['status'] ?? '') !== 'matched') {
            return [
                'applied' => true,
                'status' => 'blocked_by_semantic_scope',
                'summary' => $this->scopeBlockedSummary($resolution),
                'precise_result' => [
                    'contract_version' => self::CONTRACT_VERSION,
                    'status' => 'blocked_by_semantic_scope',
                    'semantic_resolution' => $resolution,
                    'metric_readback' => null,
                    'scope' => $scope,
                    'decision_safe' => false,
                    'external_write_authorized' => false,
                ],
                'query_router' => $router,
                'used_evidence_refs' => [],
                'data_gaps' => [[
                    'code' => (string)($resolution['status'] ?? 'semantic_resolution_failed'),
                    'message' => '指标别名或平台范围不唯一，未读取或合并经营数值。',
                ]],
            ];
        }

        $readback = $service->metricReadback($resolution, $facts);
        $status = (string)($readback['status'] ?? 'blocked_by_missing_metric');
        $success = in_array($status, ['readback_verified', 'calculated_from_same_fact_scope'], true);
        return [
            'applied' => true,
            'status' => $success ? 'answered_by_precise_query' : $this->blockedStatus($status),
            'summary' => $this->summary($resolution, $readback, $scope),
            'precise_result' => [
                'contract_version' => self::CONTRACT_VERSION,
                'status' => $status,
                'semantic_resolution' => $resolution,
                'metric_readback' => $readback,
                'scope' => $scope,
                'decision_safe' => false,
                'external_write_authorized' => false,
            ],
            'query_router' => $router,
            'used_evidence_refs' => array_values(array_unique(array_map(
                'strval',
                (array)($readback['used_evidence_refs'] ?? [])
            ))),
            'data_gaps' => array_values(array_filter(
                is_array($readback['data_gaps'] ?? null) ? $readback['data_gaps'] : [],
                'is_array'
            )),
        ];
    }

    /** @param array<string,mixed> $resolution @param array<string,mixed> $primary @param array<string,mixed> $scope @return array<string,mixed> */
    private function router(array $resolution, array $primary, array $scope): array
    {
        $topicKey = trim((string)($primary['assistant_topic_key'] ?? ''));
        $routeKey = trim((string)($primary['route_key'] ?? ''));
        $catalog = SystemUsageAssistantService::catalog();
        $topic = $topicKey !== '' && is_array($catalog[$topicKey] ?? null) ? $catalog[$topicKey] : [];
        $targetPage = trim((string)($topic['target_page'] ?? ''));
        if ($targetPage === '') {
            $targetPage = $routeKey !== '' ? $routeKey : 'revenue-research-center';
        }
        return [
            'contract_version' => self::ROUTER_CONTRACT_VERSION,
            'status' => ($resolution['status'] ?? '') === 'matched' ? 'matched' : 'blocked',
            'source_scope' => (string)($scope['source_scope'] ?? 'ota_channel'),
            'platform' => $resolution['effective_platform'] ?? ($scope['platform'] ?? null),
            'metric_key' => $primary['metric_key'] ?? null,
            'topic_key' => $topicKey !== '' ? $topicKey : null,
            'target_page' => $targetPage,
            'route_key' => $routeKey !== '' ? $routeKey : null,
            'action_key' => (string)($topic['action_key'] ?? 'page'),
            'read_only' => true,
            'external_write_authorized' => false,
        ];
    }

    /** @param array<string,mixed> $resolution @param array<string,mixed> $readback @param array<string,mixed> $scope */
    private function summary(array $resolution, array $readback, array $scope): string
    {
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $term = (string)($primary['canonical_term'] ?? '目标指标');
        $platform = $this->platformLabel((string)($resolution['effective_platform'] ?? ($scope['platform'] ?? '')));
        $dateStart = (string)($scope['date_start'] ?? '');
        $dateEnd = (string)($scope['date_end'] ?? '');
        $dateText = $dateStart === $dateEnd ? $dateStart : $dateStart . '至' . $dateEnd;
        $status = (string)($readback['status'] ?? '');
        $values = array_values(array_filter(
            is_array($readback['values'] ?? null) ? $readback['values'] : [],
            'is_array'
        ));
        if (in_array($status, ['readback_verified', 'calculated_from_same_fact_scope'], true) && $values !== []) {
            $parts = array_map(static function (array $point): string {
                $value = $point['value'] ?? null;
                $unit = (string)($point['unit'] ?? '');
                return (string)($point['date'] ?? '') . ' ' . (string)$value . ($unit !== '' ? '（' . $unit . '）' : '');
            }, $values);
            return mb_substr(sprintf(
                '%s%s（%s）已按同酒店、同平台、同日期和严格回读事实返回：%s。',
                $platform,
                $term,
                $dateText,
                implode('；', $parts)
            ), 0, 1500);
        }
        if ($status === 'not_computable') {
            return sprintf('%s%s（%s）不可计算：同范围房费收入或间夜缺失，或间夜为0；未用0或其他金额代替。', $platform, $term, $dateText);
        }
        if ($status === 'blocked_by_source_contract') {
            return sprintf('%s%s（%s）已识别，但当前来源口径尚不能安全映射到该规范指标，因此不返回数值。', $platform, $term, $dateText);
        }
        return sprintf('%s%s（%s）缺少同范围且严格回读的目标指标事实，因此不返回数值。', $platform, $term, $dateText);
    }

    /** @param array<string,mixed> $resolution */
    private function scopeBlockedSummary(array $resolution): string
    {
        return match ((string)($resolution['status'] ?? '')) {
            'scope_conflict' => '问题文本中的平台与当前明确选择的平台冲突；为避免串平台，本次未读取或合并任何数值。',
            'ambiguous_platform' => '该别名在携程和美团有不同口径；请先明确平台，本次未读取或合并任何数值。',
            default => '该指标别名或平台口径尚未唯一确定，本次未读取或合并任何数值。',
        };
    }

    private function blockedStatus(string $status): string
    {
        return match ($status) {
            'not_computable' => 'blocked_by_missing_inputs',
            'blocked_by_source_contract' => 'blocked_by_metric_contract',
            'blocked_by_platform_scope' => 'blocked_by_semantic_scope',
            default => 'blocked_by_missing_metric',
        };
    }

    private function platformLabel(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'ctrip' => '携程',
            'meituan' => '美团',
            'qunar' => '去哪儿',
            'pms' => 'PMS',
            default => '',
        };
    }
}
