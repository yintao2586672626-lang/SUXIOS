<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiEvaluationBatchReplayService;
use PHPUnit\Framework\TestCase;

final class AiEvaluationBatchReplayServiceTest extends TestCase
{
    public function testDryRunSeparatesReadyAndBlockedCasesWithoutCallingModel(): void
    {
        self::assertTrue(class_exists(AiEvaluationBatchReplayService::class), 'AI evaluation batch replay service must exist.');

        $client = new class {
            public int $calls = 0;

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->calls++;
                return ['summary' => 'ok'];
            }
        };

        $service = new AiEvaluationBatchReplayService($client);
        $result = $service->run([
            $this->caseRow('ready_case', [
                'messages' => [['role' => 'user', 'content' => 'Return OTA diagnosis.']],
                'schema' => $this->schema(),
            ], ['summary' => 'ok']),
            $this->caseRow('missing_schema_case', [
                'messages' => [['role' => 'user', 'content' => 'Return OTA diagnosis.']],
            ], ['summary' => 'ok']),
        ], [
            'evaluation_set' => 'ota_diagnosis_governance_v1',
            'model_key' => 'deepseek_chat',
            'dry_run' => true,
        ]);

        self::assertSame(0, $client->calls);
        self::assertTrue($result['dry_run']);
        self::assertSame([
            'total' => 2,
            'ready' => 1,
            'blocked' => 1,
            'executed' => 0,
            'passed' => 0,
            'failed' => 0,
        ], $result['summary']);
        self::assertSame('ready', $result['cases'][0]['status']);
        self::assertSame('blocked', $result['cases'][1]['status']);
        self::assertContains('missing_schema', $result['cases'][1]['blockers']);
    }

    public function testExecuteScoresExpectedJsonSubsetAndMarksMismatch(): void
    {
        self::assertTrue(class_exists(AiEvaluationBatchReplayService::class), 'AI evaluation batch replay service must exist.');

        $client = new class {
            public array $calls = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->calls[] = compact('messages', 'schema', 'modelKey');
                if (count($this->calls) === 1) {
                    return ['summary' => 'ok', 'confidence' => 0.91, 'extra' => 'allowed'];
                }
                return ['summary' => 'wrong', 'confidence' => 0.88];
            }
        };

        $service = new AiEvaluationBatchReplayService($client);
        $result = $service->run([
            $this->caseRow('pass_case', [
                'prompt' => 'Return OTA diagnosis.',
                'schema' => $this->schema(),
            ], ['summary' => 'ok']),
            $this->caseRow('fail_case', [
                'prompt' => 'Return OTA diagnosis.',
                'schema' => $this->schema(),
            ], ['summary' => 'ok']),
        ], [
            'evaluation_set' => 'ota_diagnosis_governance_v1',
            'model_key' => 'deepseek_chat',
            'dry_run' => false,
            'allow_external_model_call' => true,
        ]);

        self::assertFalse($result['dry_run']);
        self::assertTrue($result['allow_external_model_call']);
        self::assertSame([
            'total' => 2,
            'ready' => 2,
            'blocked' => 0,
            'executed' => 2,
            'passed' => 1,
            'failed' => 1,
        ], $result['summary']);
        self::assertSame('passed', $result['cases'][0]['status']);
        self::assertSame('failed', $result['cases'][1]['status']);
        self::assertSame('summary', $result['cases'][1]['mismatches'][0]['path']);
        self::assertSame('ok', $result['cases'][1]['mismatches'][0]['expected']);
        self::assertSame('wrong', $result['cases'][1]['mismatches'][0]['actual']);
        self::assertSame('deepseek_chat', $client->calls[0]['modelKey']);
        self::assertSame('ai_governance_evaluation', $client->calls[0]['schema']['x-governance']['module']);
        self::assertSame('ota_diagnosis_governance_v1', $client->calls[0]['schema']['x-governance']['evaluation_set']);
        self::assertSame('pass_case', $client->calls[0]['schema']['x-governance']['eval_case_id']);
    }

    public function testExecuteRequiresExplicitExternalModelCallPermission(): void
    {
        $client = new class {
            public int $calls = 0;

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->calls++;
                return ['summary' => 'ok'];
            }
        };

        $service = new AiEvaluationBatchReplayService($client);
        $result = $service->run([
            $this->caseRow('ready_but_not_allowed', [
                'prompt' => 'Return OTA diagnosis.',
                'schema' => $this->schema(),
            ], ['summary' => 'ok']),
        ], [
            'evaluation_set' => 'ota_diagnosis_governance_v1',
            'model_key' => 'deepseek_chat',
            'dry_run' => false,
        ]);

        self::assertSame(0, $client->calls);
        self::assertFalse($result['dry_run']);
        self::assertFalse($result['allow_external_model_call']);
        self::assertSame(0, $result['summary']['executed']);
        self::assertSame(1, $result['summary']['blocked']);
        self::assertSame('blocked', $result['cases'][0]['status']);
        self::assertContains('external_model_call_not_allowed', $result['cases'][0]['blockers']);
    }

    public function testDryRunAcceptsPersistedJsonStringsForBackwardCompatibility(): void
    {
        $client = new class {
            public int $calls = 0;

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->calls++;
                return ['summary' => 'ok'];
            }
        };

        $service = new AiEvaluationBatchReplayService($client);
        $result = $service->run([
            [
                'id' => 11,
                'case_key' => 'json_string_case',
                'scenario' => 'ota_diagnosis',
                'prompt_version' => 'agent.ota_diagnosis.v1',
                'input_json' => json_encode([
                    'messages' => [['role' => 'user', 'content' => 'Return OTA diagnosis.']],
                    'schema' => $this->schema(),
                ], JSON_UNESCAPED_UNICODE),
                'expected_json' => json_encode(['summary' => 'ok'], JSON_UNESCAPED_UNICODE),
                'metric_json' => json_encode(['match' => 'expected_subset'], JSON_UNESCAPED_UNICODE),
                'status' => 'active',
            ],
        ], [
            'evaluation_set' => 'ota_diagnosis_governance_v1',
            'dry_run' => true,
        ]);

        self::assertSame(0, $client->calls);
        self::assertSame('ready', $result['cases'][0]['status']);
        self::assertSame([], $result['cases'][0]['blockers']);
        self::assertSame(1, $result['summary']['ready']);
        self::assertSame(0, $result['summary']['blocked']);
    }

    public function testDryRunBlocksCasesWithoutExplicitMetricJson(): void
    {
        $client = new class {
            public int $calls = 0;

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->calls++;
                return ['summary' => 'ok'];
            }
        };

        $service = new AiEvaluationBatchReplayService($client);
        $result = $service->run([[
            'id' => 12,
            'case_key' => 'missing_metric_policy_case',
            'scenario' => 'ota_diagnosis',
            'prompt_version' => 'agent.ota_diagnosis.v1',
            'input_json' => [
                'prompt' => 'Return OTA diagnosis.',
                'schema' => $this->schema(),
            ],
            'expected_json' => ['summary' => 'ok'],
            'status' => 'active',
        ]], [
            'evaluation_set' => 'ota_diagnosis_governance_v1',
            'dry_run' => true,
        ]);

        self::assertSame(0, $client->calls);
        self::assertSame(0, $result['summary']['ready']);
        self::assertSame(1, $result['summary']['blocked']);
        self::assertSame('blocked', $result['cases'][0]['status']);
        self::assertContains('missing_metric_json', $result['cases'][0]['blockers']);
    }

    private function caseRow(string $caseKey, array $input, array $expected): array
    {
        return [
            'id' => 10,
            'case_key' => $caseKey,
            'scenario' => 'ota_diagnosis',
            'prompt_version' => 'agent.ota_diagnosis.v1',
            'input_json' => $input,
            'expected_json' => $expected,
            'metric_json' => ['match' => 'expected_subset'],
            'status' => 'active',
        ];
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['summary'],
        ];
    }
}
