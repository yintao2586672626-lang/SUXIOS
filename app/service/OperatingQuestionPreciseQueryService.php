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
        $resolution = $service->resolveMetrics($question, $platform);
        $probe = $resolution;
        if ((array)($resolution['metrics'] ?? []) === []) {
            $probe = $service->resolveMetrics($question);
        }
        $probeMetrics = array_values(array_filter(
            is_array($probe['metrics'] ?? null) ? $probe['metrics'] : [],
            'is_array'
        ));
        $isMetricQuestion = $probeMetrics !== [];
        if (!$isMetricQuestion) {
            return [
                'applied' => false,
                'reason' => 'no_deterministic_metric_intent',
                'semantic_resolution' => $resolution,
            ];
        }

        $router = $this->router($resolution, $probeMetrics, $scope);
        if (!in_array((string)($resolution['status'] ?? ''), ['matched', 'matched_multi'], true)) {
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

        $readback = $service->metricReadbacks($resolution, $facts);
        $status = (string)($readback['status'] ?? 'blocked_by_missing_metric');
        $success = $status === 'readback_verified';
        $partial = $status === 'partial';
        $items = array_values(array_filter(
            is_array($readback['items'] ?? null) ? $readback['items'] : [],
            'is_array'
        ));
        $singleReadback = count($items) === 1 && is_array($items[0]['readback'] ?? null)
            ? $items[0]['readback']
            : null;
        $metricSet = $this->projectMetricSet($items, $scope);
        return [
            'applied' => true,
            'status' => $success
                ? 'answered_by_precise_query'
                : ($partial ? 'answered_by_precise_query_partial' : $this->blockedStatus($status)),
            'summary' => $this->summarySet($resolution, $readback, $scope),
            'precise_result' => [
                'contract_version' => self::CONTRACT_VERSION,
                'status' => $status,
                'semantic_resolution' => $resolution,
                'metric_readback' => $singleReadback,
                'metric_readback_set' => $readback,
                'metric_set' => $metricSet,
                'precise_results' => $metricSet['items'],
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

    /** @param array<string,mixed> $resolution @param list<array<string,mixed>> $metrics @param array<string,mixed> $scope @return array<string,mixed> */
    private function router(array $resolution, array $metrics, array $scope): array
    {
        $primary = $metrics[0] ?? [];
        $topicKey = trim((string)($primary['assistant_topic_key'] ?? ''));
        $routeKey = trim((string)($primary['route_key'] ?? ''));
        $catalog = SystemUsageAssistantService::catalog();
        $topic = $topicKey !== '' && is_array($catalog[$topicKey] ?? null) ? $catalog[$topicKey] : [];
        $targetPage = trim((string)($topic['target_page'] ?? ''));
        if ($targetPage === '') {
            $targetPage = $routeKey !== '' ? $routeKey : 'revenue-research-center';
        }
        $metricRoutes = [];
        foreach ($metrics as $metric) {
            $metricTopicKey = trim((string)($metric['assistant_topic_key'] ?? ''));
            $metricTopic = $metricTopicKey !== '' && is_array($catalog[$metricTopicKey] ?? null)
                ? $catalog[$metricTopicKey]
                : [];
            $metricTargetPage = trim((string)($metricTopic['target_page'] ?? ''));
            $metricRoutes[] = [
                'metric_key' => $metric['metric_key'] ?? null,
                'canonical_term' => $metric['canonical_term'] ?? null,
                'topic_key' => $metricTopicKey !== '' ? $metricTopicKey : null,
                'target_page' => $metricTargetPage !== '' ? $metricTargetPage : null,
                'action_key' => (string)($metricTopic['action_key'] ?? 'page'),
                'read_only' => true,
            ];
        }
        return [
            'contract_version' => self::ROUTER_CONTRACT_VERSION,
            'status' => in_array((string)($resolution['status'] ?? ''), ['matched', 'matched_multi'], true)
                ? 'matched'
                : 'blocked',
            'source_scope' => (string)($scope['source_scope'] ?? 'ota_channel'),
            'platform' => $resolution['effective_platform'] ?? ($scope['platform'] ?? null),
            'metric_key' => $primary['metric_key'] ?? null,
            'metric_keys' => array_values(array_filter(array_map(
                static fn(array $metric): string => trim((string)($metric['metric_key'] ?? '')),
                $metrics
            ))),
            'metric_routes' => $metricRoutes,
            'topic_key' => $topicKey !== '' ? $topicKey : null,
            'target_page' => $targetPage,
            'route_key' => $routeKey !== '' ? $routeKey : null,
            'action_key' => (string)($topic['action_key'] ?? 'page'),
            'read_only' => true,
            'external_write_authorized' => false,
        ];
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $scope @return array<string,mixed> */
    private function projectMetricSet(array $items, array $scope): array
    {
        $cards = [];
        $readyCount = 0;
        $refs = [];
        foreach ($items as $item) {
            $semantic = is_array($item['semantic'] ?? null) ? $item['semantic'] : [];
            $readback = is_array($item['readback'] ?? null) ? $item['readback'] : [];
            $values = array_values(array_filter(
                is_array($readback['values'] ?? null) ? $readback['values'] : [],
                'is_array'
            ));
            $point = $values[0] ?? [];
            $itemRefs = array_values(array_unique(array_map(
                'strval',
                (array)($readback['used_evidence_refs'] ?? [])
            )));
            $refs = array_merge($refs, $itemRefs);
            $gaps = array_values(array_filter(
                is_array($readback['data_gaps'] ?? null) ? $readback['data_gaps'] : [],
                'is_array'
            ));
            $verificationStatus = strtolower(trim((string)($point['verification_status'] ?? '')));
            $readbackStatus = strtolower(trim((string)($point['readback_status'] ?? '')));
            $pointDate = trim((string)($point['date'] ?? ''));
            $dateStart = trim((string)($scope['date_start'] ?? ''));
            $dateEnd = trim((string)($scope['date_end'] ?? ''));
            $value = $point['value'] ?? null;
            $valueIsNumeric = is_int($value) || is_float($value) || is_numeric($value);
            $dateBound = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $pointDate) === 1
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateStart) === 1
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateEnd) === 1
                && $pointDate >= $dateStart
                && $pointDate <= $dateEnd;
            $ready = $point !== []
                && $valueIsNumeric
                && in_array($verificationStatus, ['verified', 'derived_verified'], true)
                && $readbackStatus === 'readback_verified'
                && $itemRefs !== []
                && $dateBound;
            if ($ready) {
                $readyCount++;
            } elseif ($point !== []) {
                $gaps[] = [
                    'code' => 'metric_quality_contract_incomplete',
                    'reason' => '指标缺少显式验证、回读、来源记录或请求日期范围凭证。',
                ];
            }
            $cards[] = [
                'kind' => 'operating_metric',
                'status' => $ready
                    ? (string)($readback['status'] ?? 'readback_verified')
                    : ($point !== [] ? 'blocked_by_quality_contract' : (string)($readback['status'] ?? 'blocked_by_missing_metric')),
                'hotel' => [
                    'id' => (int)($scope['hotel_id'] ?? 0),
                    'name' => (string)($scope['hotel_name'] ?? '') ?: 'Hotel ' . (int)($scope['hotel_id'] ?? 0),
                ],
                'platform' => [
                    'key' => (string)($scope['platform'] ?? ''),
                    'name' => $this->platformLabel((string)($scope['platform'] ?? '')),
                ],
                'business_date' => (string)($point['date'] ?? $scope['date_start'] ?? ''),
                'metric' => [
                    'key' => $semantic['metric_key'] ?? $readback['metric_key'] ?? null,
                    'name' => $semantic['canonical_term'] ?? $readback['canonical_term'] ?? null,
                ],
                'value' => $value,
                'unit' => $point['unit'] ?? null,
                'source_record' => $itemRefs[0] ?? null,
                'source_records' => $itemRefs,
                'source_paths' => array_values((array)($point['source_paths'] ?? [])),
                'collected_at' => $point['collected_at'] ?? null,
                'verification_status' => $verificationStatus !== '' ? $verificationStatus : 'missing',
                'readback_status' => $readbackStatus !== '' ? $readbackStatus : 'not_verified',
                'data_scope' => (string)($scope['source_scope'] ?? 'ota_channel'),
                'formula' => $point['formula'] ?? null,
                'calculation_inputs' => is_array($point['inputs'] ?? null) ? $point['inputs'] : [],
                'blocked_reason' => $ready ? null : (string)($gaps[0]['reason'] ?? $gaps[0]['code'] ?? $readback['status'] ?? '缺少可信事实'),
                'data_gaps' => $gaps,
                'semantic_definition' => (string)($semantic['definition'] ?? ''),
            ];
        }
        return [
            'contract_version' => 'suxios.precise_metric_set.v1',
            'kind' => 'operating_metric_set',
            'items' => $cards,
            'result_count' => count($cards),
            'ready_count' => $readyCount,
            'blocked_count' => count($cards) - $readyCount,
            'used_evidence_refs' => array_values(array_unique($refs)),
            'data_scope' => (string)($scope['source_scope'] ?? 'ota_channel'),
        ];
    }

    /** @param array<string,mixed> $resolution @param array<string,mixed> $readback @param array<string,mixed> $scope */
    private function summarySet(array $resolution, array $readback, array $scope): string
    {
        $platform = $this->platformLabel((string)($resolution['effective_platform'] ?? ($scope['platform'] ?? '')));
        $dateStart = (string)($scope['date_start'] ?? '');
        $dateEnd = (string)($scope['date_end'] ?? '');
        $dateText = $dateStart === $dateEnd ? $dateStart : $dateStart . '至' . $dateEnd;
        $parts = [];
        foreach ((array)($readback['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $semantic = is_array($item['semantic'] ?? null) ? $item['semantic'] : [];
            $metric = is_array($item['readback'] ?? null) ? $item['readback'] : [];
            $term = (string)($semantic['canonical_term'] ?? $metric['canonical_term'] ?? '目标指标');
            $values = array_values(array_filter(
                is_array($metric['values'] ?? null) ? $metric['values'] : [],
                'is_array'
            ));
            if ($values !== []) {
                $point = $values[0];
                $parts[] = sprintf(
                    '%s %s%s',
                    $term,
                    (string)($point['value'] ?? ''),
                    trim((string)($point['unit'] ?? '')) !== '' ? '（' . (string)$point['unit'] . '）' : ''
                );
                continue;
            }
            $reason = (string)($metric['data_gaps'][0]['reason']
                ?? $metric['data_gaps'][0]['code']
                ?? $metric['status']
                ?? '缺少同范围事实');
            $parts[] = $term . '未返回（' . $reason . '）';
        }
        return mb_substr(sprintf(
            '%s%s已按同酒店、同平台、同日期逐项查数：%s。',
            $platform,
            $dateText !== '' ? '（' . $dateText . '）' : '',
            implode('；', $parts)
        ), 0, 1500);
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
