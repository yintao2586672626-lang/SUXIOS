<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use Throwable;

class AiEvaluationBatchReplayService
{
    private object $llmClient;
    private Closure $localCapabilityProbe;

    public function __construct(?object $llmClient = null, ?callable $localCapabilityProbe = null)
    {
        $this->llmClient = $llmClient ?? new LlmClient();
        $this->localCapabilityProbe = Closure::fromCallable($localCapabilityProbe ?? static fn(): array => (
            new LocalAiRuntimeService()
        )->capabilities());
    }

    public function run(array $caseRows, array $options = []): array
    {
        $evaluationSet = mb_substr(trim((string)($options['evaluation_set'] ?? '')), 0, 120);
        $modelKey = mb_substr(trim((string)($options['model_key'] ?? 'deepseek_v4_default')), 0, 100);
        $dryRun = $this->resolveDryRun($options);
        $allowExternalModelCall = $this->boolValue($options['allow_external_model_call'] ?? null, false);
        $heartbeat = $options['heartbeat'] ?? null;
        if ($heartbeat !== null && !is_callable($heartbeat)) {
            throw new \InvalidArgumentException('AI评测批次 heartbeat 无效');
        }
        $localModelCall = $modelKey === LocalAiRuntimeService::TEXT_MODEL_KEY;
        $localRuntimeReady = true;
        if (!$dryRun && $localModelCall) {
            try {
                $capability = ($this->localCapabilityProbe)();
                $localRuntimeReady = ($capability['text']['ready'] ?? false) === true
                    && ($capability['boundaries']['local_only'] ?? false) === true
                    && trim((string)($capability['text']['model'] ?? '')) === LocalAiRuntimeService::TEXT_MODEL;
            } catch (Throwable) {
                $localRuntimeReady = false;
            }
        }
        $cases = [];
        $summary = [
            'total' => count($caseRows),
            'ready' => 0,
            'blocked' => 0,
            'executed' => 0,
            'passed' => 0,
            'failed' => 0,
        ];

        foreach ($caseRows as $row) {
            $planned = $this->planCase(is_array($row) ? $row : [], $evaluationSet, $modelKey);
            if (empty($planned['blockers'])) {
                $summary['ready']++;
            }

            if ($dryRun) {
                $planned['status'] = empty($planned['blockers']) ? 'ready' : 'blocked';
                if ($planned['status'] === 'blocked') {
                    $summary['blocked']++;
                }
                $cases[] = $this->publicCaseResult($planned);
                continue;
            }

            if (!empty($planned['blockers'])) {
                $planned['status'] = 'blocked';
                $summary['blocked']++;
                $cases[] = $this->publicCaseResult($planned);
                continue;
            }

            if (!$allowExternalModelCall && !$localModelCall) {
                $planned['status'] = 'blocked';
                $planned['blockers'][] = 'external_model_call_not_allowed';
                $planned['blockers'] = array_values(array_unique($planned['blockers']));
                $summary['blocked']++;
                $cases[] = $this->publicCaseResult($planned);
                continue;
            }

            if ($localModelCall && !$localRuntimeReady) {
                $planned['status'] = 'blocked';
                $planned['blockers'][] = 'local_model_runtime_not_ready';
                $planned['blockers'] = array_values(array_unique($planned['blockers']));
                $summary['blocked']++;
                $cases[] = $this->publicCaseResult($planned);
                continue;
            }

            if ($heartbeat !== null) {
                try {
                    if ($heartbeat($planned) !== true) {
                        throw new \RuntimeException('heartbeat_rejected');
                    }
                } catch (Throwable $error) {
                    throw new \RuntimeException('AI评测批次 reservation 续租失败', 409, $error);
                }
            }

            $summary['executed']++;
            try {
                $envelope = $this->llmClient->createJsonResponseEnvelope(
                    $planned['messages'],
                    $this->withGovernance($planned['schema'], $planned, $evaluationSet),
                    $modelKey
                );
                $actual = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
                $planned['model_meta'] = $this->publicModelMeta(is_array($envelope['meta'] ?? null) ? $envelope['meta'] : []);
                if ($localModelCall && !$this->isConfirmedLocalModelMeta($planned['model_meta'])) {
                    throw new \RuntimeException('local_model_identity_unconfirmed');
                }
                $mismatches = $this->compareExpected($planned['expected_json'], $actual);
                $planned['actual_json'] = $actual;
                $planned['mismatches'] = $mismatches;
                if (empty($mismatches)) {
                    $planned['status'] = 'passed';
                    $summary['passed']++;
                } else {
                    $planned['status'] = 'failed';
                    $summary['failed']++;
                }
            } catch (Throwable $e) {
                $planned['status'] = 'blocked';
                $planned['blockers'][] = 'model_call_failed';
                $planned['error'] = [
                    'type' => get_class($e),
                    'message' => mb_substr($e->getMessage(), 0, 300),
                ];
                $summary['blocked']++;
            }

            $cases[] = $this->publicCaseResult($planned);
        }

        return [
            'dry_run' => $dryRun,
            'allow_external_model_call' => $allowExternalModelCall,
            'local_model_call' => $localModelCall,
            'evaluation_set' => $evaluationSet,
            'model_key' => $modelKey,
            'summary' => $summary,
            'cases' => $cases,
        ];
    }

    private function planCase(array $row, string $evaluationSet, string $modelKey): array
    {
        $input = $this->arrayValue($row['input_json'] ?? null);
        $expected = $this->arrayValue($row['expected_json'] ?? null);
        $metricJson = $this->arrayValue($row['metric_json'] ?? null);
        $schema = $this->extractSchema($input);
        $messages = $this->extractMessages($input);
        $caseKey = mb_substr(trim((string)($row['case_key'] ?? '')), 0, 120);
        $scenario = mb_substr(trim((string)($row['scenario'] ?? '')), 0, 120);
        $promptVersion = mb_substr(trim((string)($row['prompt_version'] ?? '')), 0, 120);
        $blockers = [];

        if ($evaluationSet === '') {
            $blockers[] = 'missing_evaluation_set';
        }
        if ($caseKey === '') {
            $blockers[] = 'missing_case_key';
        }
        if ($scenario === '') {
            $blockers[] = 'missing_scenario';
        }
        if ($promptVersion === '') {
            $blockers[] = 'missing_prompt_version';
        }
        if (($row['status'] ?? 'active') !== 'active') {
            $blockers[] = 'inactive_case';
        }
        if (empty($input)) {
            $blockers[] = 'missing_input_json';
        }
        if (empty($expected)) {
            $blockers[] = 'missing_expected_json';
        }
        if (empty($messages)) {
            $blockers[] = 'missing_messages';
        }
        if (empty($schema)) {
            $blockers[] = 'missing_schema';
        }
        if (empty($metricJson)) {
            $blockers[] = 'missing_metric_json';
        } elseif (trim((string)($metricJson['match'] ?? '')) !== 'expected_subset') {
            $blockers[] = 'unsupported_metric_policy';
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'case_key' => $caseKey,
            'scenario' => $scenario,
            'prompt_version' => $promptVersion,
            'evaluation_set' => $evaluationSet,
            'model_key' => $modelKey,
            'status' => 'pending',
            'blockers' => array_values(array_unique($blockers)),
            'messages' => $messages,
            'schema' => $schema,
            'expected_json' => $expected,
            'actual_json' => null,
            'mismatches' => [],
            'metric_json' => $metricJson,
            'input_digest' => $this->digest($input),
            'expected_digest' => $this->digest($expected),
            'metric_digest' => $this->digest($metricJson),
            'case_snapshot_digest' => $this->digest([
                'id' => (int)($row['id'] ?? 0),
                'case_key' => $caseKey,
                'scenario' => $scenario,
                'prompt_version' => $promptVersion,
                'evaluation_set' => $evaluationSet,
                'input' => $input,
                'expected' => $expected,
                'metrics' => $metricJson,
            ]),
            'model_meta' => [],
        ];
    }

    private function publicCaseResult(array $planned): array
    {
        $result = [
            'id' => $planned['id'],
            'case_key' => $planned['case_key'],
            'scenario' => $planned['scenario'],
            'prompt_version' => $planned['prompt_version'],
            'evaluation_set' => $planned['evaluation_set'],
            'model_key' => $planned['model_key'],
            'status' => $planned['status'],
            'blockers' => $planned['blockers'],
            'mismatches' => $planned['mismatches'],
            'input_digest' => $planned['input_digest'],
            'expected_digest' => $planned['expected_digest'],
            'metric_digest' => $planned['metric_digest'],
            'case_snapshot_digest' => $planned['case_snapshot_digest'],
        ];
        if ($planned['actual_json'] !== null) {
            $result['actual_json'] = $planned['actual_json'];
        }
        if (!empty($planned['model_meta'])) {
            $result['model_meta'] = $planned['model_meta'];
        }
        if (isset($planned['error'])) {
            $result['error'] = $planned['error'];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function publicModelMeta(array $meta): array
    {
        return [
            'provider' => mb_substr(trim((string)($meta['provider'] ?? '')), 0, 60),
            'model_key' => mb_substr(trim((string)($meta['model_key'] ?? '')), 0, 100),
            'model' => mb_substr(trim((string)($meta['model'] ?? '')), 0, 120),
            'finish_reason' => mb_substr(trim((string)($meta['finish_reason'] ?? '')), 0, 60),
            'fallback_used' => ($meta['fallback_used'] ?? false) === true,
            'cache_hit' => ($meta['cache_hit'] ?? false) === true,
            'degraded' => ($meta['degraded'] ?? false) === true,
        ];
    }

    /** @param array<string,mixed> $meta */
    private function isConfirmedLocalModelMeta(array $meta): bool
    {
        return strtolower(trim((string)($meta['provider'] ?? ''))) === 'ollama'
            && trim((string)($meta['model_key'] ?? '')) === LocalAiRuntimeService::TEXT_MODEL_KEY
            && trim((string)($meta['model'] ?? '')) === LocalAiRuntimeService::TEXT_MODEL
            && ($meta['fallback_used'] ?? false) === false
            && ($meta['cache_hit'] ?? false) === false
            && ($meta['degraded'] ?? false) === false;
    }

    private function withGovernance(array $schema, array $planned, string $evaluationSet): array
    {
        $schema['x-governance'] = array_merge(is_array($schema['x-governance'] ?? null) ? $schema['x-governance'] : [], [
            'module' => 'ai_governance_evaluation',
            'scenario' => $planned['scenario'],
            'prompt_version' => $planned['prompt_version'],
            'decision_impact' => 'none',
            'evaluation_set' => $evaluationSet,
            'eval_case_id' => $planned['case_key'],
        ]);
        return $schema;
    }

    private function extractMessages(array $input): array
    {
        $messages = $input['messages'] ?? null;
        if (is_array($messages)) {
            $normalized = [];
            foreach ($messages as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $content = trim((string)($message['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                $normalized[] = [
                    'role' => mb_substr(trim((string)($message['role'] ?? 'user')), 0, 40),
                    'content' => $content,
                ];
            }
            return $normalized;
        }

        $prompt = trim((string)($input['prompt'] ?? ''));
        if ($prompt === '') {
            return [];
        }
        return [['role' => 'user', 'content' => $prompt]];
    }

    private function extractSchema(array $input): array
    {
        return $this->arrayValue($input['schema'] ?? $input['json_schema'] ?? null);
    }

    private function compareExpected(array $expected, array $actual): array
    {
        $mismatches = [];
        $this->compareValue($expected, $actual, '', $mismatches);
        return array_slice($mismatches, 0, 50);
    }

    private function compareValue(mixed $expected, mixed $actual, string $path, array &$mismatches): void
    {
        if (count($mismatches) >= 50) {
            return;
        }

        if (is_array($expected)) {
            if (!is_array($actual)) {
                $mismatches[] = $this->mismatch($path, $expected, $actual);
                return;
            }
            foreach ($expected as $key => $value) {
                $childPath = $path === '' ? (string)$key : $path . '.' . (string)$key;
                if (!array_key_exists($key, $actual)) {
                    $mismatches[] = $this->mismatch($childPath, $value, null, true);
                    continue;
                }
                $this->compareValue($value, $actual[$key], $childPath, $mismatches);
            }
            return;
        }

        if ($actual !== $expected) {
            $mismatches[] = $this->mismatch($path, $expected, $actual);
        }
    }

    private function mismatch(string $path, mixed $expected, mixed $actual, bool $missing = false): array
    {
        return [
            'path' => $path,
            'expected' => $expected,
            'actual' => $actual,
            'missing' => $missing,
        ];
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function resolveDryRun(array $options): bool
    {
        if (array_key_exists('execute', $options)) {
            return !$this->boolValue($options['execute'], false);
        }
        return $this->boolValue($options['dry_run'] ?? null, true);
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $text = strtolower(trim((string)$value));
        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonical($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }
}
