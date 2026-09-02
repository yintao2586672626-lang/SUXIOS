<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiEvaluationBatchReplayService;
use app\service\HotelDataAnalystFeedbackProjectionService;
use app\service\HotelDataAnalystQualityReceiptService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HotelDataAnalystFeedbackProjectionServiceTest extends TestCase
{
    public function testCorrectionBuildsDetachedDryRunCaseWithoutCallingModel(): void
    {
        $service = new HotelDataAnalystFeedbackProjectionService();
        $projection = $service->project($this->question(), 'needs_correction', [
            'summary' => '结论应明确这是美团渠道曝光人数，不是全酒店曝光量。',
            'issue_codes' => ['scope_overreach'],
        ]);

        self::assertSame('ready_for_dry_run', $projection['replay_status']);
        self::assertFalse($projection['formal_evaluation_case_created']);
        self::assertFalse($projection['model_training_triggered']);
        self::assertFalse($projection['external_action_authorized']);
        self::assertSame('active', $projection['case']['status']);
        self::assertSame('expected_subset', $projection['case']['metric_json']['match']);
        self::assertTrue($projection['case']['metric_json']['review_required']);
        self::assertFalse($projection['case']['metric_json']['automatic_promotion']);
        self::assertSame(64, strlen($projection['case']['case_snapshot_digest']));
        $payload = json_decode(
            $projection['case']['input_json']['messages'][1]['content'],
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $definition = $payload['frozen_verified_facts'][0]['metric_definitions']['list_exposure'];
        self::assertSame('ota_list_exposure_users.v1', $definition['definition_id']);
        self::assertSame('visitor_count', $definition['unit']);
        self::assertSame(
            'online_daily_data.list_exposure',
            $definition['storage_field']
        );

        $client = new class {
            public int $calls = 0;
            public function createJsonResponseEnvelope(): array
            {
                $this->calls++;
                throw new \RuntimeException('dry-run must not call model');
            }
        };
        $replay = (new AiEvaluationBatchReplayService($client))->run([$projection['case']], [
            'evaluation_set' => HotelDataAnalystFeedbackProjectionService::EVALUATION_SET,
            'model_key' => 'deepseek_v4_pro',
            'dry_run' => true,
        ]);
        self::assertSame(1, $replay['summary']['ready']);
        self::assertSame(0, $replay['summary']['blocked']);
        self::assertSame(0, $client->calls);
        $this->assertCredentialFree($projection);
    }

    public function testUsefulFeedbackIsObservationNotGoldAnswer(): void
    {
        $projection = (new HotelDataAnalystFeedbackProjectionService())->project(
            $this->question(),
            'useful',
            []
        );

        self::assertSame('not_applicable', $projection['replay_status']);
        self::assertSame(['useful_feedback_is_not_gold_answer'], $projection['blockers']);
        self::assertNull($projection['case']);
        self::assertFalse($projection['formal_evaluation_case_created']);
    }

    public function testMissingFrozenFactsKeepsCorrectionCandidateBlocked(): void
    {
        $question = $this->question();
        $question['answer']['fact_samples'] = [];
        $projection = (new HotelDataAnalystFeedbackProjectionService())->project(
            $question,
            'needs_correction',
            ['summary' => '需要纠正日期口径。', 'issue_codes' => ['date_scope']]
        );

        self::assertSame('blocked', $projection['replay_status']);
        self::assertContains('blocked_by_missing_frozen_replay_input', $projection['blockers']);
        self::assertNull($projection['case']);
    }

    public function testFrozenMetricWithoutValidatedDefinitionKeepsCorrectionCandidateBlocked(): void
    {
        $question = $this->question();
        unset($question['answer']['fact_samples'][0]['metric_definitions']);

        $projection = (new HotelDataAnalystFeedbackProjectionService())->project(
            $question,
            'needs_correction',
            ['summary' => '需要纠正指标口径。', 'issue_codes' => ['metric_semantics']]
        );

        self::assertSame('blocked', $projection['replay_status']);
        self::assertContains('blocked_by_missing_frozen_replay_input', $projection['blockers']);
        self::assertNull($projection['case']);
    }

    public function testSensitiveCorrectionIsRejectedBeforeProjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('feedback_contains_sensitive_material');
        (new HotelDataAnalystFeedbackProjectionService())->project(
            $this->question(),
            'needs_correction',
            ['summary' => 'Authorization: Bearer secret-token-value', 'issue_codes' => []]
        );
    }

    public function testSessionCookieCorrectionIsRejectedBeforeProjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('feedback_contains_sensitive_material');
        (new HotelDataAnalystFeedbackProjectionService())->project(
            $this->question(),
            'needs_correction',
            ['summary' => 'PHPSESSID=abcdef1234567890', 'issue_codes' => []]
        );
    }

    public function testSensitiveSourceQuestionBlocksCaseWithoutPersistingThePayload(): void
    {
        $question = $this->question();
        $question['question_text'] = 'Authorization: Bearer source-secret-token';
        $projection = (new HotelDataAnalystFeedbackProjectionService())->project(
            $question,
            'needs_correction',
            ['summary' => '需要纠正渠道范围。', 'issue_codes' => ['scope_overreach']]
        );

        self::assertSame('blocked', $projection['replay_status']);
        self::assertSame(['blocked_by_sensitive_replay_input'], $projection['blockers']);
        self::assertNull($projection['case']);
        self::assertStringNotContainsString('source-secret-token', json_encode($projection) ?: '');
    }

    public function testProjectionDigestIsStableAcrossCorrectionKeyOrder(): void
    {
        $service = new HotelDataAnalystFeedbackProjectionService();
        $first = $service->project($this->question(), 'needs_correction', [
            'summary' => '需要纠正渠道范围。',
            'issue_codes' => ['scope_overreach'],
        ]);
        $second = $service->project($this->question(), 'needs_correction', [
            'issue_codes' => ['scope_overreach'],
            'summary' => '需要纠正渠道范围。',
        ]);
        self::assertSame($first['projection_digest'], $second['projection_digest']);
        self::assertSame($first['case']['case_key'], $second['case']['case_key']);
    }

    /** @return array<string,mixed> */
    private function question(): array
    {
        $contentDigest = str_repeat('a', 64);
        return [
            'id' => 901,
            'tenant_id' => 10,
            'hotel_id' => 80,
            'question_text' => '美团曝光人数是多少？',
            'platform' => 'meituan',
            'date_start' => '2026-08-23',
            'date_end' => '2026-08-23',
            'answer_status' => 'evidence_ready',
            'answer_summary' => '美团渠道曝光人数为 1422。',
            'content_digest' => $contentDigest,
            'fact_refs' => ['online_daily_data#102476'],
            'analysis_quality_receipt' => [
                'contract_version' => HotelDataAnalystQualityReceiptService::CONTRACT_VERSION,
                'subject_digest' => $contentDigest,
                'receipt_digest' => str_repeat('b', 64),
            ],
            'answer' => [
                'mode' => 'deterministic_saved_evidence',
                'scope' => [
                    'tenant_id' => 10,
                    'hotel_id' => 80,
                    'platform' => 'meituan',
                    'date_start' => '2026-08-23',
                    'date_end' => '2026-08-23',
                    'source_scope' => 'ota_channel',
                ],
                'fact_samples' => [[
                    'ref' => 'online_daily_data#102476',
                    'data_date' => '2026-08-23',
                    'platform' => 'meituan',
                    'data_type' => 'traffic',
                    'quality_status' => 'verified',
                    'history_status' => 'success',
                    'readback_status' => 'readback_verified',
                    'metric_values' => ['list_exposure' => 1422],
                    'metric_units' => ['list_exposure' => 'visitor_count'],
                    'metric_definitions' => [
                        'list_exposure' => [
                            'claimable' => true,
                            'definition_id' => 'ota_list_exposure_users.v1',
                            'source_metric_key' => 'exposure_users',
                            'source_data_type' => 'traffic',
                            'source_key' => 'exposure_users',
                            'storage_field' => 'online_daily_data.list_exposure',
                            'source_path_digest' => 'c' . str_repeat('1', 63),
                            'field_fact_digest' => 'd' . str_repeat('2', 63),
                            'unit' => 'visitor_count',
                            'unit_status' => 'verified',
                            'unit_source' => 'operating_question_metric_semantics.v1',
                            'label' => '曝光用户数',
                        ],
                    ],
                ]],
                'key_points' => ['仅代表美团 OTA 渠道。'],
                'missing_information' => [],
                'confidence' => 'medium',
                'used_evidence_refs' => ['online_daily_data#102476'],
            ],
        ];
    }

    private function assertCredentialFree(mixed $value, string $path = 'root'): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                self::assertDoesNotMatchRegularExpression(
                    '/cookie|authorization|password|secret|api[_-]?key|access[_-]?token|refresh[_-]?token|profile[_-]?path/i',
                    (string)$key,
                    'sensitive key at ' . $path
                );
                $this->assertCredentialFree($child, $path . '.' . $key);
            }
            return;
        }
        if (!is_scalar($value)) return;
        self::assertDoesNotMatchRegularExpression(
            '/Bearer\s+[A-Za-z0-9._-]{8,}|sk-[A-Za-z0-9_-]{8,}/i',
            (string)$value,
            'sensitive value at ' . $path
        );
    }
}
