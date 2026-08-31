<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingInputService;
use app\service\DailyOneThingService;
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
        self::assertSame('not_calculable', $result['selected']['impact_estimate']['status']);
        self::assertNull($result['selected']['impact_estimate']['low']);
        self::assertNull($result['selected']['impact_estimate']['high']);
    }

    public function testStrictSameScopeTrafficFactsProduceOnlyADeterministicPointImpact(): void
    {
        $closure = $this->fullyReadyClosure();
        $closure['platforms']['meituan']['fields'] = [
            $this->strictImpactField('exposure', 1422, 'online_daily_data#102476'),
            $this->strictImpactField('visits', 206, 'online_daily_data#102476'),
            $this->field('conversion', 'verified_calculation', 14.49),
            $this->field('revenue', 'missing', null, false),
            $this->field('room_nights', 'missing', null, false),
        ];
        $closure['platforms']['meituan']['current_receipt_record_refs'] = ['online_daily_data#102476'];

        $input = $this->service($closure)->build(80, 80, '2026-08-26', 7);
        $rawCandidate = array_values(array_filter(
            $input['candidates'],
            static fn(array $candidate): bool => $candidate['candidate_key'] === 'gap:meituan:traffic_only_scope'
        ))[0];
        self::assertSame('deterministic_point_estimate', $rawCandidate['impact_estimate']['status']);
        $result = (new DailyOneThingService())->select($input['candidates'], '2026-08-26');

        self::assertSame('gap:meituan:traffic_only_scope', $result['selected']['candidate_key']);
        self::assertSame([
            'low' => 1216.0,
            'high' => 1216.0,
            'unit' => 'users',
            'formula' => 'exposure_users - detail_visitors',
            'input_refs' => ['online_daily_data#102476'],
            'scope' => [
                'tenant_id' => 80,
                'hotel_id' => 80,
                'platform' => 'meituan',
                'business_date' => '2026-08-26',
                'metric_scope' => 'ota_channel',
            ],
            'status' => 'deterministic_point_estimate',
        ], $result['selected']['impact_estimate']);
    }

    public function testImpactStaysNotCalculableWhenStrictScopeOrDenominatorGateFails(): void
    {
        $mutations = [
            'tenant mismatch' => static function (array &$exposure): void { $exposure['tenant_id'] = 81; },
            'hotel mismatch' => static function (array &$exposure): void { $exposure['system_hotel_id'] = 81; },
            'platform mismatch' => static function (array &$exposure): void { $exposure['platform'] = 'ctrip'; },
            'business date mismatch' => static function (array &$exposure): void { $exposure['business_date'] = '2026-08-25'; },
            'history not final' => static function (array &$exposure): void { $exposure['history_statuses'] = ['partial']; },
            'validation not verified' => static function (array &$exposure): void { $exposure['validation_status'] = 'partial'; },
            'readback missing' => static function (array &$exposure): void { $exposure['readback_status'] = 'not_attempted'; },
            'denominator zero' => static function (array &$exposure): void { $exposure['value'] = 0; },
            'source ref missing' => static function (array &$exposure): void { $exposure['source_record_refs'] = []; },
        ];
        foreach ($mutations as $label => $mutate) {
            $closure = $this->fullyReadyClosure();
            $exposure = $this->strictImpactField('exposure', 1422, 'online_daily_data#102476');
            $visits = $this->strictImpactField('visits', 206, 'online_daily_data#102476');
            $mutate($exposure);
            $closure['platforms']['meituan']['fields'] = [
                $exposure,
                $visits,
                $this->field('conversion', 'verified_calculation', 14.49),
                $this->field('revenue', 'missing', null, false),
                $this->field('room_nights', 'missing', null, false),
            ];
            $closure['platforms']['meituan']['current_receipt_record_refs'] = ['online_daily_data#102476'];
            $input = $this->service($closure)->build(80, 80, '2026-08-26', 7);
            $candidate = array_values(array_filter(
                $input['candidates'],
                static fn(array $item): bool => $item['candidate_key'] === 'gap:meituan:traffic_only_scope'
            ))[0];
            self::assertSame('not_calculable', $candidate['impact_estimate']['status'], $label);
            self::assertNull($candidate['impact_estimate']['low'], $label);
            self::assertNull($candidate['impact_estimate']['high'], $label);
        }
    }

    public function testSavedQuestionIsAdaptedOnlyThroughTheReadOnlyEligibilityGate(): void
    {
        $question = $this->question();
        $service = new DailyOneThingInputService(
            fn(): array => $this->fullyReadyClosure(),
            static fn(): array => ['data_status' => 'ok', 'list' => [$question]],
            static fn(array $saved): array => $saved['answer']['action_drafts'][0],
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-26 09:00:00', new DateTimeZone('Asia/Shanghai'))
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

    public function testStrictFactReaderFailureStaysUnavailableWithoutVerifiedGapCandidate(): void
    {
        $service = new DailyOneThingInputService(
            static fn(): array => throw new \RuntimeException('database unavailable'),
            static fn(): array => ['data_status' => 'ok', 'list' => []],
            static fn(): ?array => null,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-26 09:00:00', new DateTimeZone('Asia/Shanghai'))
        );

        $input = $service->build(80, 80, '2026-08-26', 7);

        self::assertSame('source_unavailable', $input['strict_fact_status']);
        self::assertSame([['code' => 'strict_fact_layer_unavailable']], $input['source_errors']);
        self::assertSame([], $input['candidates']);
        self::assertFalse($input['boundary']['strict_fact_source_ready']);
        self::assertStringNotContainsString('gap_readback_verified', json_encode($input, JSON_UNESCAPED_UNICODE));
    }

    private function service(array $closure): DailyOneThingInputService
    {
        return new DailyOneThingInputService(
            static fn(): array => $closure,
            static fn(): array => ['data_status' => 'ok', 'list' => []],
            static fn(): ?array => null,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-26 09:00:00', new DateTimeZone('Asia/Shanghai'))
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

    /** @return array<string,mixed> */
    private function strictImpactField(string $key, int $value, string $ref): array
    {
        return $this->field($key, 'strict_readback', $value) + [
            'unit' => 'users',
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'business_date' => '2026-08-26',
            'data_dates' => ['2026-08-26'],
            'history_statuses' => ['success'],
            'source_validation_statuses' => ['verified'],
            'validation_status' => 'verified',
            'readback_status' => 'readback_verified',
            'formal_saved' => true,
            'source_table' => 'online_daily_data',
            'source_record_refs' => [$ref],
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
