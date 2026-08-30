<?php
declare(strict_types=1);

namespace Tests;

use app\service\HotelDataAnalystQualityReceiptService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HotelDataAnalystQualityReceiptServiceTest extends TestCase
{
    private HotelDataAnalystQualityReceiptService $service;

    protected function setUp(): void
    {
        $this->service = new HotelDataAnalystQualityReceiptService();
    }

    public function testFaithfulMissingFactsPassesTheQualityContractButBlocksTheClaim(): void
    {
        $record = $this->record('blocked_by_missing_facts', [
            'evidence_counts' => ['facts' => 0, 'fact_platforms' => []],
            'data_gaps' => [['code' => 'saved_verified_fact_missing']],
            'ai_runtime' => ['status' => 'not_called_missing_facts'],
        ], []);

        $receipt = $this->service->evaluate($record);

        self::assertSame('passed', $receipt['quality_status']);
        self::assertSame('blocked', $receipt['claim_status']);
        self::assertSame('blocked', $receipt['status']);
        self::assertFalse($receipt['usage_policy']['verified_portion_usable']);
        self::assertFalse($receipt['usage_policy']['analysis_claim_allowed']);
        self::assertContains('verified_fact_missing', $receipt['reason_codes']);
        self::assertSame(64, strlen($receipt['receipt_digest']));
    }

    public function testEvidenceSummaryIsLimitedEvenWhenStrictFactsExist(): void
    {
        $record = $this->record('evidence_ready', [
            'data_gaps' => [['code' => 'saved_agent_diagnosis_missing']],
            'ai_runtime' => ['status' => 'not_called'],
        ]);

        $receipt = $this->service->evaluate($record);

        self::assertSame('passed', $receipt['quality_status']);
        self::assertSame('limited', $receipt['claim_status']);
        self::assertSame('partial', $receipt['status']);
        self::assertTrue($receipt['usage_policy']['verified_portion_usable']);
        self::assertFalse($receipt['usage_policy']['analysis_claim_allowed']);
        self::assertContains('verified_evidence_partial', $receipt['reason_codes']);
    }

    public function testStrictDeterministicMetricIsSupportedWithoutCallingAModel(): void
    {
        $record = $this->preciseRecord([
            $this->metric('list_exposure', 1422, 'verified', 'readback_verified', 'online_daily_data#102476'),
        ]);

        $receipt = $this->service->evaluate($record);

        self::assertSame('passed', $receipt['quality_status']);
        self::assertSame('supported', $receipt['claim_status']);
        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['usage_policy']['analysis_claim_allowed']);
        self::assertTrue($receipt['readback_verified']);
        self::assertFalse($receipt['external_action_authorized']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['scope_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['evidence_digest']);
    }

    public function testMixedMetricSetIsLimitedAndKeepsOnlyTheVerifiedPortionUsable(): void
    {
        $record = $this->preciseRecord([
            $this->metric('list_exposure', 1422, 'verified', 'readback_verified', 'online_daily_data#102476'),
            $this->metric('detail_exposure', 206, 'derived_verified', 'readback_verified', 'online_daily_data#102476'),
            [
                'metric' => ['key' => 'intent_payment_conversion_rate'],
                'status' => 'blocked_by_source_contract',
                'value' => null,
                'blocked_reason' => 'source denominator missing',
                'data_gaps' => [['code' => 'payment_denominator_missing']],
            ],
        ], 'answered_by_precise_query_partial');

        $receipt = $this->service->evaluate($record);

        self::assertSame('passed', $receipt['quality_status']);
        self::assertSame('limited', $receipt['claim_status']);
        self::assertTrue($receipt['usage_policy']['verified_portion_usable']);
        self::assertContains('precise_metric_partial', $receipt['reason_codes']);
        self::assertSame('partial', $this->check($receipt, 'metric_integrity')['status']);
    }

    #[DataProvider('unverifiedMetricProvider')]
    public function testUnverifiedNumericMetricFailsClosed(array $metric): void
    {
        $receipt = $this->service->evaluate($this->preciseRecord([$metric]));

        self::assertSame('failed', $receipt['quality_status']);
        self::assertSame('blocked', $receipt['claim_status']);
        self::assertSame('blocked', $this->check($receipt, 'metric_integrity')['status']);
        self::assertFalse($receipt['usage_policy']['analysis_claim_allowed']);
    }

    public static function unverifiedMetricProvider(): array
    {
        return [
            'verification missing' => [[
                'metric' => ['key' => 'list_exposure'], 'status' => 'ready', 'value' => 1422,
                'readback_status' => 'readback_verified', 'source_record' => 'online_daily_data#102476',
            ]],
            'readback missing' => [[
                'metric' => ['key' => 'list_exposure'], 'status' => 'ready', 'value' => 1422,
                'verification_status' => 'verified', 'source_record' => 'online_daily_data#102476',
            ]],
            'source missing' => [[
                'metric' => ['key' => 'list_exposure'], 'status' => 'ready', 'value' => 1422,
                'verification_status' => 'verified', 'readback_status' => 'readback_verified',
            ]],
            'explicitly unverified' => [[
                'metric' => ['key' => 'list_exposure'], 'status' => 'ready', 'value' => 1422,
                'verification_status' => 'unverified', 'readback_status' => 'readback_verified',
                'source_record' => 'online_daily_data#102476',
            ]],
        ];
    }

    #[DataProvider('groundedRuntimeProvider')]
    public function testGroundedDeepSeekAndLocalOllamaRuntimeCanBeSupported(array $runtime): void
    {
        $record = $this->record('answered_by_grounded_ai', [
            'mode' => 'grounded_ai_saved_evidence',
            'confidence' => 'medium',
            'used_evidence_refs' => ['online_daily_data#102476'],
            'ai_runtime' => $runtime,
        ]);

        $receipt = $this->service->evaluate($record);

        self::assertSame('passed', $receipt['quality_status']);
        self::assertSame('supported', $receipt['claim_status']);
        self::assertSame('passed', $this->check($receipt, 'runtime_provenance')['status']);
    }

    public static function groundedRuntimeProvider(): array
    {
        return [
            'DeepSeek V4 Pro' => [[
                'status' => 'ready', 'provider' => 'deepseek', 'model' => 'deepseek-v4-pro',
                'finish_reason' => 'stop', 'external_llm_call_status' => 'confirmed_success',
                'external_llm_called' => true, 'fallback_used' => false, 'cache_hit' => false, 'degraded' => false,
            ]],
            'local qwen3' => [[
                'status' => 'ready', 'provider' => 'ollama', 'model' => 'qwen3:8b',
                'finish_reason' => 'stop', 'external_llm_call_status' => 'confirmed_local_success',
                'external_llm_called' => false, 'fallback_used' => false, 'cache_hit' => false, 'degraded' => false,
            ]],
        ];
    }

    public function testRuntimeOrExternalAuthorityContradictionFailsClosed(): void
    {
        $record = $this->record('answered_by_grounded_ai', [
            'mode' => 'grounded_ai_saved_evidence',
            'confidence' => 'high',
            'used_evidence_refs' => ['online_daily_data#102476'],
            'ai_runtime' => [
                'status' => 'ready', 'provider' => 'deepseek', 'model' => 'deepseek-v4-pro',
                'finish_reason' => 'stop', 'external_llm_call_status' => 'confirmed_success',
                'external_llm_called' => true, 'fallback_used' => true,
            ],
            'boundaries' => ['ota_write' => true, 'external_message' => false, 'automatic_execution' => false],
        ]);

        $receipt = $this->service->evaluate($record);

        self::assertSame('failed', $receipt['quality_status']);
        self::assertSame('blocked', $receipt['claim_status']);
        self::assertContains('grounded_ai_runtime_incoherent', $receipt['reason_codes']);
        self::assertContains('analysis_external_action_boundary_violation', $receipt['reason_codes']);
        self::assertFalse($receipt['usage_policy']['external_action_authorized']);
    }

    public function testBlockedAnswerCannotHideReadyMetricsOrForgedCounts(): void
    {
        $record = $this->preciseRecord([
            $this->metric('list_exposure', 1422, 'verified', 'readback_verified', 'online_daily_data#102476'),
        ]);
        $record['answer_status'] = 'blocked_by_missing_metric';
        $record['answer']['status'] = 'blocked_by_missing_metric';
        $record['answer']['summary'] = '状态与指标矛盾。';
        $record['answer_summary'] = '状态与指标矛盾。';
        $record['answer']['data_gaps'] = [['code' => 'declared_block']];
        $record['data_gaps'] = [['code' => 'declared_block']];
        $record['answer']['precise_result']['metric_set']['result_count'] = 1;
        $record['answer']['precise_result']['metric_set']['ready_count'] = 99;
        $record['answer']['precise_result']['metric_set']['blocked_count'] = 0;

        $receipt = $this->service->evaluate($record);

        self::assertSame('failed', $receipt['quality_status']);
        self::assertSame('blocked', $receipt['claim_status']);
        self::assertContains('precise_metric_declared_count_mismatch', $receipt['reason_codes']);
        self::assertContains('analysis_status_contract_incoherent', $receipt['reason_codes']);
    }

    public function testReceiptDigestIsStableAcrossAssociativeKeyOrder(): void
    {
        $record = $this->preciseRecord([
            $this->metric('list_exposure', 0, 'verified', 'readback_verified', 'online_daily_data#102476'),
        ]);
        $reordered = array_reverse($record, true);
        $reordered['answer'] = array_reverse($record['answer'], true);
        $reordered['answer']['scope'] = array_reverse($record['answer']['scope'], true);

        self::assertSame(
            $this->service->evaluate($record)['receipt_digest'],
            $this->service->evaluate($reordered)['receipt_digest']
        );
    }

    /** @param list<array<string,mixed>> $items */
    private function preciseRecord(array $items, string $status = 'answered_by_precise_query'): array
    {
        $gaps = [];
        foreach ($items as $item) {
            foreach ((array)($item['data_gaps'] ?? []) as $gap) {
                $gaps[] = $gap;
            }
        }
        return $this->record($status, [
            'mode' => 'deterministic_precise_query',
            'data_gaps' => $gaps,
            'precise_result' => [
                'status' => $status === 'answered_by_precise_query' ? 'ready' : 'partial',
                'decision_safe' => false,
                'external_write_authorized' => false,
                'metric_set' => [
                    'contract_version' => 'suxios.precise_metric_set.v1',
                    'kind' => 'operating_metric_set',
                    'items' => $items,
                ],
            ],
            'ai_runtime' => [
                'status' => 'not_called_deterministic',
                'model_attempted' => false,
                'llm_client_invoked' => false,
            ],
            'boundaries' => [
                'llm_attempted' => false,
                'ota_write' => false,
                'external_message' => false,
                'automatic_execution' => false,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function metric(
        string $key,
        int|float $value,
        string $verification,
        string $readback,
        string $source
    ): array {
        return [
            'metric' => ['key' => $key, 'name' => $key],
            'status' => $readback,
            'value' => $value,
            'unit' => 'people',
            'business_date' => '2026-08-23',
            'verification_status' => $verification,
            'readback_status' => $readback,
            'source_record' => $source,
            'data_gaps' => [],
        ];
    }

    /** @param array<string,mixed> $answerOverrides @param list<string>|null $factRefs */
    private function record(
        string $status,
        array $answerOverrides = [],
        ?array $factRefs = null
    ): array {
        $scope = [
            'tenant_id' => 10,
            'hotel_id' => 80,
            'platform' => 'meituan',
            'date_start' => '2026-08-23',
            'date_end' => '2026-08-23',
            'source_scope' => 'ota_channel',
        ];
        $answer = array_replace_recursive([
            'contract_version' => 'hotel_operating_question.v1',
            'mode' => 'deterministic_saved_evidence',
            'status' => $status,
            'summary' => '当前范围分析摘要。',
            'scope' => $scope,
            'evidence_counts' => ['facts' => 1, 'fact_platforms' => ['meituan' => 1]],
            'data_gaps' => [],
            'missing_information' => [],
            'action_drafts' => [],
            'ai_runtime' => ['status' => 'not_called'],
            'boundaries' => ['ota_write' => false, 'external_message' => false, 'automatic_execution' => false],
        ], $answerOverrides);
        return [
            'id' => 901,
            'tenant_id' => 10,
            'hotel_id' => 80,
            'question_text' => '当前范围最需要复核什么？',
            'platform' => 'meituan',
            'date_start' => '2026-08-23',
            'date_end' => '2026-08-23',
            'answer_status' => $status,
            'answer_summary' => (string)$answer['summary'],
            'answer' => $answer,
            'fact_refs' => $factRefs ?? ['online_daily_data#102476'],
            'memory_refs' => [],
            'knowledge_refs' => [],
            'execution_refs' => [],
            'data_gaps' => (array)($answer['data_gaps'] ?? []),
            'content_digest' => str_repeat('a', 64),
            'persistence_status' => 'readback_verified',
        ];
    }

    /** @return array<string,mixed> */
    private function check(array $receipt, string $key): array
    {
        foreach ($receipt['checks'] as $check) {
            if (($check['key'] ?? '') === $key) {
                return $check;
            }
        }
        self::fail('Missing quality check: ' . $key);
    }
}
