<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionToolCallingService;
use app\service\OperatingQuestionUnifiedEvidenceService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OperatingQuestionToolCallingServiceTest extends TestCase
{
    public function testAllowlistedCallsProduceStableReadOnlyReceiptsAndOneEvidencePlane(): void
    {
        $service = $this->service(static fn(array $payload): array => [
            'tool_calls' => [
                ['name' => 'retrieve_media_evidence', 'reason' => '用户选择了截图'],
                ['name' => 'retrieve_knowledge', 'reason' => '需要SOP'],
            ],
            'meta' => [
                'provider' => 'ollama',
                'model_key' => 'ollama_qwen3_8b',
                'model' => 'qwen3:8b',
                'finish_reason' => 'stop',
                'llm_client_invoked' => true,
                'external_llm_called' => false,
            ],
        ]);

        $first = $service->run($this->scope(), '携程曝光下降怎么复核？', 'ollama_qwen3_8b', [31]);
        $second = $service->run($this->scope(), '携程曝光下降怎么复核？', 'ollama_qwen3_8b', [31]);

        self::assertSame('model', $first['selection_mode']);
        self::assertSame($first['run_digest'], $second['run_digest']);
        self::assertSame($first['tool_call_receipts'], $second['tool_call_receipts']);
        self::assertSame(
            ['retrieve_knowledge', 'retrieve_operating_memory', 'retrieve_media_evidence'],
            array_column($first['tool_calls'], 'name')
        );
        self::assertSame(
            ['knowledge_chunks#11', 'hotel_operating_memories#21', 'local_media_extractions#31'],
            $first['evidence_plane']['evidence_refs']
        );
        self::assertSame([
            'knowledge' => 1,
            'operating_memory' => 1,
            'local_media' => 1,
        ], $first['evidence_plane']['source_counts']);
        foreach ($first['tool_call_receipts'] as $receipt) {
            self::assertMatchesRegularExpression('/^tool_receipt_[a-f0-9]{32}$/', $receipt['receipt_id']);
            self::assertFalse($receipt['side_effects']['database_write']);
            self::assertFalse($receipt['side_effects']['external_write']);
            self::assertFalse($receipt['side_effects']['automatic_execution']);
        }
        $media = $first['evidence_plane']['source_results']['local_media']['items'][0];
        self::assertTrue($media['human_confirmation_required']);
        self::assertFalse($media['decision_safe']);
        self::assertSame('reference_only_until_human_confirmed', $media['usage_policy']);
    }

    public function testPlannerFailureFallsBackWithoutDroppingExplicitMedia(): void
    {
        $service = $this->service(static function (): array {
            throw new RuntimeException('provider unavailable');
        });

        $result = $service->run($this->scope(), '看一下我选择的截图', 'ollama_qwen3_8b', [31]);

        self::assertSame('deterministic_fallback', $result['selection_mode']);
        self::assertSame('planner_unavailable', $result['selection_status']);
        self::assertSame(
            ['retrieve_knowledge', 'retrieve_operating_memory', 'retrieve_media_evidence'],
            array_column($result['tool_calls'], 'name')
        );
        self::assertSame(3, count($result['tool_call_receipts']));
        self::assertSame('tool_planner_unavailable', $result['planner_meta']['error_code']);
    }

    public function testUnknownToolIsRejectedAndCannotReplaceBaselineRetrieval(): void
    {
        $service = $this->service(static fn(array $payload): array => [
            'tool_calls' => [
                ['name' => 'change_room_price', 'reason' => '直接改价'],
            ],
        ]);

        $result = $service->run($this->scope(), '把携程房价改掉', 'ollama_qwen3_8b');

        self::assertSame('rejected', $result['tool_call_receipts'][0]['status']);
        self::assertSame('tool_not_allowed', $result['tool_call_receipts'][0]['error_code']);
        self::assertFalse($result['tool_call_receipts'][0]['side_effects']['database_read']);
        self::assertSame(
            ['retrieve_knowledge', 'retrieve_operating_memory'],
            array_column($result['tool_calls'], 'name')
        );
    }

    public function testOutOfScopeOrOtherUserMediaIsExcluded(): void
    {
        $evidence = $this->evidenceService(static fn(int $id, array $scope): array => [
            'id' => $id,
            'tenant_id' => $scope['tenant_id'],
            'hotel_id' => $scope['hotel_id'],
            'created_by' => 999,
            'extraction_status' => 'ready',
            'persistence_status' => 'readback_verified',
        ]);
        $service = new OperatingQuestionToolCallingService(
            static fn(array $payload): array => [
                'tool_calls' => [['name' => 'retrieve_media_evidence', 'reason' => '读取媒体']],
            ],
            $evidence
        );

        $result = $service->run($this->scope(), '读取媒体', 'ollama_qwen3_8b', [31]);

        self::assertSame([], $result['evidence_plane']['source_results']['local_media']['items']);
        self::assertSame('no_match', $result['evidence_plane']['source_results']['local_media']['status']);
        self::assertSame(1, $result['evidence_plane']['source_results']['local_media']['excluded_count']);
        $mediaReceipt = array_values(array_filter(
            $result['tool_call_receipts'],
            static fn(array $receipt): bool => $receipt['tool_name'] === 'retrieve_media_evidence'
        ))[0];
        self::assertSame('no_match', $mediaReceipt['status']);
    }

    public function testDeterministicPolicySkipsPlannerButStillReturnsBaselineReceipts(): void
    {
        $plannerCalls = 0;
        $service = $this->service(static function () use (&$plannerCalls): array {
            $plannerCalls++;
            return ['tool_calls' => []];
        });

        $result = $service->run(
            $this->scope(),
            '携程曝光人数是多少？',
            'ollama_qwen3_8b',
            [],
            false
        );

        self::assertSame(0, $plannerCalls);
        self::assertSame('deterministic_policy', $result['selection_mode']);
        self::assertSame('model_not_called_by_policy', $result['selection_status']);
        self::assertFalse($result['planner_meta']['llm_client_invoked']);
        self::assertSame(
            ['deterministic_policy', 'deterministic_policy'],
            array_column($result['tool_calls'], 'requested_by')
        );
    }

    private function service(callable $planner): OperatingQuestionToolCallingService
    {
        return new OperatingQuestionToolCallingService($planner, $this->evidenceService());
    }

    private function evidenceService(?callable $mediaLoader = null): OperatingQuestionUnifiedEvidenceService
    {
        return new OperatingQuestionUnifiedEvidenceService(
            static fn(array $scope, string $question): array => [
                'status' => 'matched',
                'method' => 'hybrid',
                'items' => [[
                    'ref' => 'knowledge_chunks#11',
                    'unit_ref' => 'knowledge_units#1',
                    'name' => '曝光复核SOP',
                    'scope' => 'generic_methodology',
                    'platforms' => ['ctrip'],
                    'gate_status' => 'formal',
                    'usage_policy' => 'decision_support',
                    'retrieval_method' => 'hybrid',
                    'retrieval_score' => 0.9,
                    'excerpt' => '先核验同范围曝光数据。',
                    'source_refs' => ['knowledge_units#1'],
                ]],
            ],
            static fn(array $scope, string $question): array => [
                'status' => 'matched',
                'method' => 'hybrid',
                'items' => [[
                    'ref' => 'hotel_operating_memories#21',
                    'memory_layer' => 'episode',
                    'title' => '历史曝光复核',
                    'summary' => '曾按同口径复核曝光。',
                    'quality_status' => 'verified',
                    'usage_level' => 'decision_support',
                    'business_date' => '2026-08-31',
                    'platform' => 'ctrip',
                    'retrieval_method' => 'hybrid',
                    'retrieval_score' => 0.8,
                ]],
            ],
            $mediaLoader ?? static fn(int $id, array $scope): array => [
                'id' => $id,
                'tenant_id' => $scope['tenant_id'],
                'hotel_id' => $scope['hotel_id'],
                'created_by' => $scope['user_id'],
                'extraction_status' => 'ready',
                'persistence_status' => 'readback_verified',
                'source_sha256' => str_repeat('a', 64),
                'original_name' => '经营截图.png',
                'extracted_text' => '截图识别到携程曝光字段。',
                'extraction_method' => 'ocr',
                'media_kind' => 'image',
                'mime_type' => 'image/png',
                'source_retention' => 'digest_only',
                'extractor_version' => 'test.v1',
                'confidence' => 0.91,
                'content_digest' => str_repeat('b', 64),
            ]
        );
    }

    /** @return array<string,mixed> */
    private function scope(): array
    {
        return [
            'tenant_id' => 3,
            'hotel_id' => 20,
            'user_id' => 7,
            'platform' => 'ctrip',
            'date_start' => '2026-08-31',
            'date_end' => '2026-08-31',
        ];
    }
}
