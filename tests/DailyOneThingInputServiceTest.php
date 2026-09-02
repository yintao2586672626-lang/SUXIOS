<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingInputService;
use app\service\DailyOneThingService;
use app\service\OtaReputationDailySignalService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DailyOneThingInputServiceTest extends TestCase
{
    public function testMissingCtripTargetDateRowsBecomeTheOnlyTopDailyItem(): void
    {
        $service = $this->service($this->closure([], []));
        $input = $service->build(80, 80, '2026-08-26', 7);
        $result = (new DailyOneThingService())->select($input['candidates'], '2026-08-26');

        self::assertSame('gap:ctrip:target_date_source_rows', $result['selected']['candidate_key']);
        self::assertSame('explicit_data_gap', $result['selected']['source_type']);
        self::assertSame('ctrip', $result['selected']['scope']['platform']);
        self::assertSame('ota_channel_data_quality', $result['selected']['scope']['metric_scope']);
        self::assertSame(['ctrip_target_date_source_rows_missing'], $result['selected']['source']['gap_codes']);
        self::assertSame(0.0, $result['selected']['expected_observation_metric']['baseline_value']);
        self::assertFalse($input['boundary']['automatic_ota_write']);
    }

    public function testExistingCtripRowsWithMissingExposureChooseTheSpecificCoreFactGap(): void
    {
        $ctripFields = $this->readyFields(['revenue', 'order_count', 'room_nights', 'adr', 'visits']);
        $ctripFields[] = $this->field('exposure', 'missing', null, false);
        $ctripFields[] = $this->field('conversion', 'missing', null, false);
        $closure = $this->closure($ctripFields, []);
        $closure['platforms']['ctrip']['current_receipt_all_record_refs'] = ['online_daily_data#501'];
        $closure['platforms']['ctrip']['current_receipt_record_refs'] = ['online_daily_data#501'];
        $service = $this->service($closure);

        $result = (new DailyOneThingService())->select(
            $service->build(80, 80, '2026-08-26', 7)['candidates'],
            '2026-08-26'
        );

        self::assertSame('gap:ctrip:core_facts', $result['selected']['candidate_key']);
        self::assertStringContainsString('曝光', $result['selected']['problem']);
        self::assertStringContainsString('转化', $result['selected']['problem']);
        self::assertSame(4.0, $result['selected']['expected_observation_metric']['baseline_value']);
        self::assertSame(['online_daily_data#501'], $result['selected']['source']['fact_refs']);
    }

    public function testMeituanTrafficOnlyCandidateNeverExpandsToRevenueOrWholeHotel(): void
    {
        $ctripFields = $this->readyFields([
            'revenue', 'order_count', 'room_nights', 'adr', 'exposure', 'visits', 'conversion',
        ]);
        $meituanFields = [
            $this->field('exposure', 'strict_readback', 1000),
            $this->field('visits', 'strict_readback', 150),
            $this->field('conversion', 'verified_calculation', 15.0),
            $this->field('revenue', 'missing', null, false),
            $this->field('room_nights', 'missing', null, false),
        ];
        $closure = $this->closure($ctripFields, $meituanFields);
        $closure['platforms']['ctrip']['current_receipt_all_record_refs'] = ['online_daily_data#601'];
        $closure['platforms']['ctrip']['current_receipt_record_refs'] = ['online_daily_data#601'];
        $closure['platforms']['meituan']['current_receipt_record_refs'] = ['online_daily_data#701'];
        $service = $this->service($closure);

        $result = (new DailyOneThingService())->select(
            $service->build(80, 80, '2026-08-26', 7)['candidates'],
            '2026-08-26'
        );

        self::assertSame('gap:meituan:traffic_only_scope', $result['selected']['candidate_key']);
        self::assertSame('meituan', $result['selected']['scope']['platform']);
        self::assertSame('ota_channel', $result['selected']['scope']['metric_scope']);
        self::assertSame('conversion', $result['selected']['expected_observation_metric']['key']);
        self::assertStringContainsString('不代表携程、全 OTA 或全酒店收益', $result['selected']['scope']['scope_note']);
        self::assertStringNotContainsString('ADR', $result['selected']['problem']);
    }

    public function testSavedQuestionIsAdaptedOnlyThroughTheReadOnlyEligibilityGate(): void
    {
        $question = $this->question();
        $service = new DailyOneThingInputService(
            fn(): array => $this->fullyReadyClosure(),
            static fn(): array => ['data_status' => 'ok', 'list' => [$question]],
            static fn(array $saved): array => $saved['answer']['action_drafts'][0],
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-26 09:00:00', new DateTimeZone('Asia/Shanghai')),
            fn(): array => $this->emptyReputation('2026-08-26')
        );

        $input = $service->build(80, 80, '2026-08-26', 7);
        $questionCandidates = array_values(array_filter(
            $input['candidates'],
            static fn(array $candidate): bool => $candidate['source_type'] === 'saved_question'
        ));

        self::assertCount(1, $questionCandidates);
        self::assertSame('question:88:action:0', $questionCandidates[0]['candidate_key']);
        self::assertSame('hotel_operating_questions#88', $questionCandidates[0]['source']['record_ref']);
        self::assertSame('detail_exposure', $questionCandidates[0]['expected_observation_metric']['key']);
        self::assertSame(201.0, $questionCandidates[0]['expected_observation_metric']['baseline_value']);
    }

    public function testVolatileClosureReceiptDigestDoesNotReplaceTheSameExplicitGap(): void
    {
        $firstClosure = $this->closure([], []);
        $secondClosure = $firstClosure;
        $secondClosure['closure_digest'] = str_repeat('e', 64);
        $first = $this->service($firstClosure)->build(80, 80, '2026-08-26', 7);
        $second = $this->service($secondClosure)->build(80, 80, '2026-08-26', 7);

        self::assertSame($first['source_digest'], $second['source_digest']);
        self::assertSame(
            DailyOneThingService::materialIdentityDigest($first['candidates'][0]),
            DailyOneThingService::materialIdentityDigest($second['candidates'][0])
        );
    }

    public function testVerifiedReputationSignalUsesTheExistingDailyOneThingSelectionAndApprovalBoundary(): void
    {
        $reputation = (new OtaReputationDailySignalService(fn(): array => [
            $this->reviewRow(902, '2026-08-26', 4.8, 2, 0, true),
            $this->reviewRow(901, '2026-08-25', 4.8, 1, 0, true),
        ]))->build(80, 80, '2026-08-26');
        $service = new DailyOneThingInputService(
            fn(): array => $this->fullyReadyClosure(),
            static fn(): array => ['data_status' => 'ok', 'list' => []],
            static fn(): ?array => null,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-26 09:00:00', new DateTimeZone('Asia/Shanghai')),
            static fn(): array => $reputation
        );

        $input = $service->build(80, 80, '2026-08-26', 7);
        $result = (new DailyOneThingService())->select($input['candidates'], '2026-08-26');

        self::assertSame('signal:ctrip:reputation:bad_reviews_increased', $result['selected']['candidate_key']);
        self::assertSame('strict_fact_signal', $result['selected']['source_type']);
        self::assertSame('ctrip', $result['selected']['scope']['platform']);
        self::assertSame('bad_review_count', $result['selected']['expected_observation_metric']['key']);
        self::assertSame(2.0, $result['selected']['expected_observation_metric']['baseline_value']);
        self::assertSame('human_reviewed_reputation_check', $result['selected']['recommended_action']['type']);
        self::assertFalse($result['selected']['external_write_boundary']['automatic_ctrip_write']);
        self::assertFalse($result['can_execute']);
        self::assertSame('actionable_signals_available', $input['source_snapshot']['ota_reputation_signal']['status']);
    }

    public function testStrictFactReaderFailureStaysUnavailableWithoutVerifiedGapCandidate(): void
    {
        $service = new DailyOneThingInputService(
            static fn(): array => throw new \RuntimeException('database unavailable'),
            static fn(): array => ['data_status' => 'ok', 'list' => []],
            static fn(): ?array => null,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-26 09:00:00', new DateTimeZone('Asia/Shanghai')),
            fn(): array => $this->emptyReputation('2026-08-26')
        );

        $input = $service->build(80, 80, '2026-08-26', 7);

        self::assertSame('unavailable', $input['source_snapshot']['dual_ota_field_closure']['status']);
        self::assertSame([['code' => 'strict_fact_layer_unavailable']], $input['source_errors']);
        self::assertSame([], $input['candidates']);
        self::assertStringNotContainsString('gap_readback_verified', json_encode($input, JSON_UNESCAPED_UNICODE));
    }

    private function service(array $closure): DailyOneThingInputService
    {
        return new DailyOneThingInputService(
            static fn(): array => $closure,
            static fn(): array => ['data_status' => 'ok', 'list' => []],
            static fn(): ?array => null,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-26 09:00:00', new DateTimeZone('Asia/Shanghai')),
            fn(): array => $this->emptyReputation('2026-08-26')
        );
    }

    /** @param list<array<string,mixed>> $ctripFields @param list<array<string,mixed>> $meituanFields */
    private function closure(array $ctripFields, array $meituanFields): array
    {
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 80,
            'hotel_id' => 80,
            'business_date' => '2026-08-26',
            'metric_scope' => 'ota_channel_only',
            'closure_digest' => str_repeat('c', 64),
            'platforms' => [
                'ctrip' => [
                    'platform' => 'ctrip',
                    'fields' => $ctripFields,
                    'current_receipt_all_record_refs' => [],
                    'current_receipt_record_refs' => [],
                ],
                'meituan' => [
                    'platform' => 'meituan',
                    'fields' => $meituanFields,
                    'current_receipt_all_record_refs' => [],
                    'current_receipt_record_refs' => [],
                ],
            ],
        ];
    }

    /** @param list<string> $keys @return list<array<string,mixed>> */
    private function readyFields(array $keys): array
    {
        return array_map(
            fn(string $key): array => $this->field($key, $key === 'adr' || $key === 'conversion' ? 'verified_calculation' : 'strict_readback', 1),
            $keys
        );
    }

    /** @return array<string,mixed> */
    private function field(string $key, string $status, mixed $value, bool $ready = true): array
    {
        return [
            'key' => $key,
            'label' => [
                'revenue' => '收入', 'order_count' => '订单量', 'room_nights' => '间夜量',
                'adr' => 'ADR', 'exposure' => '曝光', 'visits' => '访问', 'conversion' => '曝光→访问转化',
            ][$key] ?? $key,
            'status' => $status,
            'value' => $value,
            'identity_binding_verified' => $ready,
            'strict_final_gate' => $ready,
        ];
    }

    private function fullyReadyClosure(): array
    {
        $fields = $this->readyFields([
            'revenue', 'order_count', 'room_nights', 'adr', 'exposure', 'visits', 'conversion',
        ]);
        $closure = $this->closure($fields, $fields);
        $closure['platforms']['ctrip']['current_receipt_all_record_refs'] = ['online_daily_data#1'];
        $closure['platforms']['ctrip']['current_receipt_record_refs'] = ['online_daily_data#1'];
        $closure['platforms']['meituan']['current_receipt_all_record_refs'] = ['online_daily_data#2'];
        $closure['platforms']['meituan']['current_receipt_record_refs'] = ['online_daily_data#2'];
        return $closure;
    }

    /** @return array<string,mixed> */
    private function emptyReputation(string $businessDate): array
    {
        return [
            'contract_version' => OtaReputationDailySignalService::CONTRACT_VERSION,
            'tenant_id' => 80,
            'hotel_id' => 80,
            'business_date' => $businessDate,
            'status' => 'no_actionable_signal',
            'signals' => [],
            'boundary' => ['external_write_count' => 0],
        ];
    }

    /** @return array<string,mixed> */
    private function reviewRow(
        int $id,
        string $dataDate,
        float $score,
        int $badReviewCount,
        int $unrepliedCount,
        bool $readbackVerified
    ): array {
        return [
            'id' => $id,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-80',
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_type' => 'review',
            'data_date' => $dataDate,
            'comment_score' => $score,
            'readback_verified' => $readbackVerified ? 1 : 0,
            'validation_status' => 'normal',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'ctrip:' . str_repeat('a', 64),
            'update_time' => $dataDate . ' 09:00:00',
            'raw_data' => json_encode([
                'metrics' => [
                    'bad_review_count' => $badReviewCount,
                    'comment_unreply_count' => $unrepliedCount,
                ],
                'dimension_values' => ['comment_channel' => '携程'],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array<string,mixed> */
    private function question(): array
    {
        $action = [
            'title' => '核对美团详情曝光',
            'action' => '只读核对同范围详情曝光并记录证据。',
            'action_object' => 'meituan_detail_exposure',
            'expected_metric' => 'detail_exposure',
            'evidence_refs' => ['online_daily_data#88382'],
            'execution_steps' => ['只读核对。', '记录证据。'],
            'priority' => 'high',
            'risk' => [
                'level' => 'low',
                'summary' => '不修改平台。',
                'controls' => ['人工确认'],
            ],
            'stop_conditions' => ['范围不一致时停止'],
        ];
        return [
            'id' => 88,
            'tenant_id' => 80,
            'hotel_id' => 80,
            'platform' => 'meituan',
            'date_start' => '2026-08-26',
            'date_end' => '2026-08-26',
            'question' => '美团详情曝光是否需要核对？',
            'answer_status' => 'answered_by_grounded_ai',
            'answer_summary' => '同范围详情曝光为 201。',
            'content_digest' => str_repeat('d', 64),
            'answer' => [
                'summary' => '同范围详情曝光为 201。',
                'fact_samples' => [[
                    'ref' => 'online_daily_data#88382',
                    'metric_values' => ['detail_exposure' => 201],
                    'metric_units' => ['detail_exposure' => 'exposure_count'],
                ]],
                'action_drafts' => [$action],
            ],
        ];
    }
}
