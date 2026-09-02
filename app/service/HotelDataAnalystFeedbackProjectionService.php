<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** Builds one detached, credential-free replay candidate from human feedback. */
final class HotelDataAnalystFeedbackProjectionService
{
    public const CONTRACT_VERSION = 'hotel_data_analyst_feedback_projection.v1';
    public const EVALUATION_SET = 'hotel_data_analyst_feedback_v1';
    public const PROMPT_VERSION = 'hotel_data_analyst_feedback_replay.zh-CN.v1';

    /** @return array<string,mixed> */
    public function project(array $question, string $feedbackKind, array $correction): array
    {
        $questionId = (int)($question['id'] ?? 0);
        $sourceDigest = strtolower(trim((string)($question['content_digest'] ?? '')));
        $receipt = is_array($question['analysis_quality_receipt'] ?? null)
            ? $question['analysis_quality_receipt']
            : [];
        $scope = is_array($question['answer']['scope'] ?? null) ? $question['answer']['scope'] : [];
        $base = [
            'contract_version' => self::CONTRACT_VERSION,
            'evaluation_set' => self::EVALUATION_SET,
            'persistence_status' => 'not_persisted',
            'formal_evaluation_case_created' => false,
            'review_status' => 'candidate_only',
            'replay_status' => 'blocked',
            'blockers' => [],
            'case' => null,
            'external_model_called' => false,
            'model_training_triggered' => false,
            'external_action_authorized' => false,
        ];
        if ($feedbackKind === 'useful') {
            $base['replay_status'] = 'not_applicable';
            $base['blockers'] = ['useful_feedback_is_not_gold_answer'];
            return $this->finish($base);
        }
        if ($feedbackKind !== 'needs_correction') {
            throw new InvalidArgumentException('feedback_kind_invalid');
        }

        $summary = trim((string)($correction['summary'] ?? ''));
        if ($summary === '') {
            throw new InvalidArgumentException('correction_summary_required');
        }
        $correction = $this->canonicalize($correction);
        if ($this->containsSensitiveMaterial($correction)) {
            throw new InvalidArgumentException('feedback_contains_sensitive_material');
        }
        $receiptContract = (string)($receipt['contract_version'] ?? '');
        $receiptDigest = strtolower(trim((string)($receipt['receipt_digest'] ?? '')));
        if ($receiptContract !== HotelDataAnalystQualityReceiptService::CONTRACT_VERSION
            || preg_match('/^[a-f0-9]{64}$/D', $receiptDigest) !== 1
            || (string)($receipt['subject_digest'] ?? '') !== $sourceDigest
        ) {
            $base['blockers'] = ['quality_receipt_version_or_digest_unsupported'];
            return $this->finish($base);
        }
        if ($questionId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $sourceDigest) !== 1
            || !$this->validScope($question, $scope)
        ) {
            $base['blockers'] = ['source_analysis_identity_invalid'];
            return $this->finish($base);
        }

        $facts = $this->strictFrozenFacts((array)($question['answer']['fact_samples'] ?? []), $scope);
        if ($facts === []) {
            $base['blockers'] = ['blocked_by_missing_frozen_replay_input'];
            return $this->finish($base);
        }
        $correctionDigest = $this->digest($correction);
        $caseKey = sprintf(
            'hda-feedback-v1:%d:%s:%s',
            $questionId,
            substr($sourceDigest, 0, 16),
            substr($correctionDigest, 0, 16)
        );
        $original = [
            'answer_status' => (string)($question['answer_status'] ?? ''),
            'answer_summary' => (string)($question['answer_summary'] ?? ''),
            'mode' => (string)($question['answer']['mode'] ?? ''),
            'confidence' => (string)($question['answer']['confidence'] ?? ''),
            'key_points' => $this->stringList($question['answer']['key_points'] ?? [], 5, 320),
            'missing_information' => $this->stringList($question['answer']['missing_information'] ?? [], 5, 320),
            'used_evidence_refs' => $this->stringList($question['answer']['used_evidence_refs'] ?? $question['fact_refs'] ?? [], 20, 180),
        ];
        $messages = [[
            'role' => 'system',
            'content' => '你是宿析OS酒店数据分析师纠正回放器。只使用冻结的同酒店、同平台、同日期事实；人工纠正是待复核候选，不是新事实。不得补数字、改写范围、写OTA/PMS、外发或自动执行。只输出JSON。',
        ], [
            'role' => 'user',
            'content' => $this->encode([
                'task' => '在保持严格事实、范围和权限边界的前提下，重新回答并明确是否处理了人工纠正。',
                'question' => (string)($question['question_text'] ?? ''),
                'scope' => $scope,
                'frozen_verified_facts' => $facts,
                'original_answer' => $original,
                'human_correction_candidate' => $correction,
                'source_content_digest' => $sourceDigest,
                'quality_receipt_digest' => $receiptDigest,
            ]),
        ]];
        $schema = [
            'type' => 'object',
            'required' => [
                'answer_summary', 'key_points', 'missing_information', 'confidence',
                'used_evidence_refs', 'correction_addressed', 'scope', 'boundaries',
            ],
            'properties' => [
                'answer_summary' => ['type' => 'string'],
                'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                'missing_information' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'used_evidence_refs' => ['type' => 'array', 'items' => ['type' => 'string']],
                'correction_addressed' => ['type' => 'boolean'],
                'scope' => [
                    'type' => 'object',
                    'required' => ['tenant_id', 'hotel_id', 'platform', 'date_start', 'date_end', 'source_scope'],
                    'properties' => [
                        'tenant_id' => ['type' => 'integer'],
                        'hotel_id' => ['type' => 'integer'],
                        'platform' => ['type' => 'string'],
                        'date_start' => ['type' => 'string'],
                        'date_end' => ['type' => 'string'],
                        'source_scope' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'boundaries' => [
                    'type' => 'object',
                    'required' => ['ota_write', 'pms_write', 'external_message', 'automatic_execution'],
                    'properties' => [
                        'ota_write' => ['type' => 'boolean'],
                        'pms_write' => ['type' => 'boolean'],
                        'external_message' => ['type' => 'boolean'],
                        'automatic_execution' => ['type' => 'boolean'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ];
        $case = [
            'case_key' => $caseKey,
            'scenario' => 'hotel_data_analyst_feedback',
            'prompt_version' => self::PROMPT_VERSION,
            'input_json' => [
                'messages' => $messages,
                'schema' => $schema,
                'source_content_digest' => $sourceDigest,
                'quality_receipt_digest' => $receiptDigest,
            ],
            'expected_json' => [
                'correction_addressed' => true,
                'scope' => $scope,
                'boundaries' => [
                    'ota_write' => false,
                    'pms_write' => false,
                    'external_message' => false,
                    'automatic_execution' => false,
                ],
            ],
            'metric_json' => [
                'match' => 'expected_subset',
                'source' => 'human_feedback_candidate',
                'review_required' => true,
                'cross_hotel_reuse_allowed' => false,
                'automatic_promotion' => false,
            ],
            'status' => 'active',
        ];
        if ($this->containsSensitiveMaterial($case)) {
            $base['blockers'] = ['blocked_by_sensitive_replay_input'];
            return $this->finish($base);
        }
        $case['case_snapshot_digest'] = $this->digest($case);
        $base['replay_status'] = 'ready_for_dry_run';
        $base['case'] = $case;
        return $this->finish($base);
    }

    /** @return list<array<string,mixed>> */
    private function strictFrozenFacts(array $facts, array $scope): array
    {
        $result = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) continue;
            $ref = trim((string)($fact['ref'] ?? ''));
            $date = trim((string)($fact['data_date'] ?? ''));
            $platform = strtolower(trim((string)($fact['platform'] ?? '')));
            $scopePlatform = strtolower(trim((string)($scope['platform'] ?? '')));
            $platformMatched = $scopePlatform === 'all_ota'
                ? in_array($platform, ['ctrip', 'meituan'], true)
                : $platform === $scopePlatform;
            if (preg_match('/^online_daily_data#[1-9][0-9]*$/D', $ref) !== 1
                || !$platformMatched
                || $date < (string)($scope['date_start'] ?? '')
                || $date > (string)($scope['date_end'] ?? '')
                || (string)($fact['history_status'] ?? '') !== 'success'
                || (string)($fact['quality_status'] ?? '') !== 'verified'
                || (string)($fact['readback_status'] ?? '') !== 'readback_verified'
            ) continue;
            $sourceUnits = (array)($fact['metric_units'] ?? []);
            $sourceDefinitions = (array)($fact['metric_definitions'] ?? []);
            $metricValues = [];
            $metricUnits = [];
            $metricDefinitions = [];
            foreach ((array)($fact['metric_values'] ?? []) as $key => $value) {
                $key = trim((string)$key);
                $unit = is_scalar($sourceUnits[$key] ?? null)
                    ? mb_substr(trim((string)$sourceUnits[$key]), 0, 80)
                    : '';
                $definition = is_array($sourceDefinitions[$key] ?? null)
                    ? $sourceDefinitions[$key]
                    : [];
                if ((!is_int($value) && !is_float($value))
                    || !$this->validMetricDefinition($key, $unit, $definition)
                ) {
                    continue;
                }
                $metricValues[$key] = $value;
                $metricUnits[$key] = $unit;
                $metricDefinitions[$key] = $this->canonicalize($definition);
            }
            if ($metricValues === []) continue;
            $result[] = [
                'ref' => $ref,
                'data_date' => $date,
                'platform' => $platform,
                'data_type' => mb_substr(trim((string)($fact['data_type'] ?? '')), 0, 80),
                'quality_status' => 'verified',
                'history_status' => 'success',
                'readback_status' => 'readback_verified',
                'metric_values' => $metricValues,
                'metric_units' => $metricUnits,
                'metric_definitions' => $metricDefinitions,
            ];
        }
        if ($this->containsSensitiveMaterial($result)) {
            throw new InvalidArgumentException('frozen_replay_input_contains_sensitive_material');
        }
        return array_slice($result, 0, 40);
    }

    /** @param array<string,mixed> $definition */
    private function validMetricDefinition(string $metricKey, string $unit, array $definition): bool
    {
        $definitionId = trim((string)($definition['definition_id'] ?? ''));
        $sourceMetricKey = trim((string)($definition['source_metric_key'] ?? ''));
        $sourceDataType = trim((string)($definition['source_data_type'] ?? ''));
        $sourceKey = trim((string)($definition['source_key'] ?? ''));
        $storageField = trim((string)($definition['storage_field'] ?? ''));
        $fieldFactDigest = strtolower(trim((string)($definition['field_fact_digest'] ?? '')));
        $sourcePathDigest = strtolower(trim((string)($definition['source_path_digest'] ?? '')));
        return $metricKey !== ''
            && $unit !== ''
            && ($definition['claimable'] ?? false) === true
            && preg_match('/^[a-z0-9_.-]+\\.v[1-9][0-9]*$/D', $definitionId) === 1
            && preg_match('/^[a-z0-9_.:-]{1,100}$/D', $sourceMetricKey) === 1
            && preg_match('/^[a-z0-9_.:-]{1,50}$/D', $sourceDataType) === 1
            && preg_match('/^[a-z0-9_.:-]{1,100}$/D', $sourceKey) === 1
            && $storageField === 'online_daily_data.' . $metricKey
            && preg_match('/^[a-f0-9]{64}$/D', $fieldFactDigest) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $sourcePathDigest) === 1
            && (string)($definition['unit_status'] ?? '') === 'verified'
            && hash_equals(trim((string)($definition['unit'] ?? '')), $unit);
    }

    private function validScope(array $question, array $scope): bool
    {
        return (int)($scope['tenant_id'] ?? 0) === (int)($question['tenant_id'] ?? 0)
            && (int)($scope['hotel_id'] ?? 0) === (int)($question['hotel_id'] ?? 0)
            && (string)($scope['platform'] ?? '') === (string)($question['platform'] ?? '')
            && (string)($scope['date_start'] ?? '') === (string)($question['date_start'] ?? '')
            && (string)($scope['date_end'] ?? '') === (string)($question['date_end'] ?? '')
            && (string)($scope['source_scope'] ?? '') === 'ota_channel';
    }

    private function containsSensitiveMaterial(mixed $value, string $key = ''): bool
    {
        if (preg_match('/cookie|authorization|password|passwd|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|session[_-]?(id|key)|profile[_-]?path/i', $key) === 1) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                if ($this->containsSensitiveMaterial($child, (string)$childKey)) return true;
            }
            return false;
        }
        if (!is_scalar($value)) return false;
        $text = (string)$value;
        return preg_match('/Bearer\s+[A-Za-z0-9._-]{8,}|(?:cookie|token|password|api[_ -]?key|session|php[_-]?sessid|jsessionid|session[_-]?id)\s*[:=]\s*\S{4,}|sk-[A-Za-z0-9_-]{8,}/i', $text) === 1;
    }

    /** @return array<string,mixed> */
    private function finish(array $projection): array
    {
        $projection['projection_digest'] = $this->digest($projection);
        return $projection;
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $limit, int $length): array
    {
        if (!is_array($value)) return [];
        return array_values(array_slice(array_unique(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? mb_substr(trim((string)$item), 0, $length) : '',
            $value
        ))), 0, $limit));
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($this->canonicalize($value)));
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map([$this, 'canonicalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
