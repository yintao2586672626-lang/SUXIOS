<?php
declare(strict_types=1);

namespace Tests;

use app\service\DualOtaFieldClosureService;
use app\service\OtaExposureEstimationReferenceService;
use PHPUnit\Framework\TestCase;

final class OtaExposureEstimationReferenceServiceTest extends TestCase
{
    public function testSevenStrictPairsProduceEstimateOnlyWithoutChangingPlatformFact(): void
    {
        $closures = $this->closures(7);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('estimated', $result['status']);
        self::assertSame('derived_estimate', $result['evidence_type']);
        self::assertSame('estimate_only', $result['quality_status']);
        self::assertSame(7, $result['accepted_verified_pairs']);
        self::assertSame(1000, $result['estimate']['value']);
        self::assertSame('users', $result['estimate']['unit']);
        self::assertSame(10.0, $result['estimate']['median_multiplier']);
        self::assertNull($result['estimate']['interval']);
        self::assertFalse($result['decision_eligible']);
        self::assertFalse($result['writeback_allowed']);
        self::assertSame('unchanged', $result['platform_fact_status']);
        self::assertSame(0, $result['external_write_count']);
    }

    public function testSixPairsStayInsufficientWithoutDefaultMultiplier(): void
    {
        $closures = $this->closures(6);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('insufficient_baseline', $result['status']);
        self::assertSame(6, $result['accepted_verified_pairs']);
        self::assertNull($result['estimate']);
        self::assertSame('verified_pair_baseline_insufficient', $result['reason_code']);
        self::assertStringContainsString('没有套用默认倍数', $result['reason']);
    }

    public function testExposureCountUnitCannotMasqueradeAsExposureUsers(): void
    {
        $closures = $this->closures(7, exposureUnit: 'impressions');
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('insufficient_baseline', $result['status']);
        self::assertSame(0, $result['accepted_verified_pairs']);
        self::assertNull($result['estimate']);
    }

    public function testExistingTargetExposureStopsReferenceEstimation(): void
    {
        $closures = $this->closures(7, targetExposure: true);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('fact_already_available', $result['status']);
        self::assertSame('target_exposure_already_available', $result['reason_code']);
        self::assertNull($result['estimate']);
        self::assertFalse($result['writeback_allowed']);
    }

    public function testPairMustShareOneSourceRecordAndScopeIdentity(): void
    {
        $closures = $this->closures(7, mismatchedPairRef: true);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('insufficient_baseline', $result['status']);
        self::assertSame(0, $result['accepted_verified_pairs']);
    }

    public function testExposureBelowDetailVisitorsIsRejectedAsAnImpossibleFunnelPair(): void
    {
        $closures = $this->closures(7, impossibleFunnel: true);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('insufficient_baseline', $result['status']);
        self::assertSame(0, $result['accepted_verified_pairs']);
        self::assertSame(7, $result['rejected_inconsistent_pair_count']);
        self::assertNull($result['estimate']);
    }

    public function testEveryBaselinePairMustMatchTheTargetSemanticScope(): void
    {
        foreach ([
            ['metric_definition_version', 'fixture-exposure-users-detail-visitors.v2'],
            ['source_paths', ['fixture.other_source_module']],
            ['time_basis', 'rolling_24_hours'],
            ['cumulative_cutoff', '22:00'],
        ] as [$field, $driftedValue]) {
            $closures = $this->closures(7);
            foreach ($closures as $date => &$closure) {
                if ($date === '2026-08-15') {
                    continue;
                }
                foreach ($closure['platforms']['meituan']['fields'] as &$fact) {
                    $fact[$field] = $driftedValue;
                }
                unset($fact);
            }
            unset($closure);

            $result = (new OtaExposureEstimationReferenceService(
                static fn(int $hotelId, string $date): array => $closures[$date] ?? []
            ))->estimate(10, 80, 'meituan', '2026-08-15');

            self::assertSame('insufficient_baseline', $result['status'], (string)$field);
            self::assertSame(0, $result['accepted_verified_pairs'], (string)$field);
            self::assertSame(7, $result['rejected_semantic_scope_count'], (string)$field);
            self::assertNull($result['estimate'], (string)$field);
        }
    }

    public function testActualDualOtaClosureContractProducesReferenceEstimate(): void
    {
        $closures = [];
        $targetDate = '2026-08-15';
        for ($offset = 0; $offset <= 7; $offset++) {
            $date = (new \DateTimeImmutable($targetDate))->modify('-' . $offset . ' days')->format('Y-m-d');
            $rowId = 3000 + $offset;
            $taskId = 7000 + $offset;
            $target = $offset === 0;
            $closures[$date] = DualOtaFieldClosureService::evaluate(
                ['id' => 80, 'tenant_id' => 10, 'name' => 'Hotel 80'],
                $date,
                [$this->actualCtripRow($rowId, $taskId, $date, $target)],
                $this->actualTrust($rowId, $taskId, $date)
            );
        }

        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'ctrip', $targetDate);

        self::assertSame('estimated', $result['status']);
        self::assertSame(7, $result['accepted_verified_pairs']);
        self::assertSame(1000, $result['estimate']['value']);
        self::assertSame('users', $result['estimate']['unit']);
    }

    public function testClosureReadFailureRemainsAnExplicitFailure(): void
    {
        $service = new OtaExposureEstimationReferenceService(
            static function (): array {
                throw new \RuntimeException('fixture source unavailable');
            }
        );

        try {
            $service->estimate(10, 80, 'ctrip', '2026-08-15');
            self::fail('a closure read failure must not be converted into missing facts');
        } catch (\RuntimeException $error) {
            self::assertSame('ota_exposure_estimation_closure_read_failed', $error->getMessage());
            self::assertSame('fixture source unavailable', $error->getPrevious()?->getMessage());
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function closures(
        int $pairCount,
        string $exposureUnit = 'users',
        bool $targetExposure = false,
        bool $mismatchedPairRef = false,
        bool $impossibleFunnel = false
    ): array {
        $closures = [];
        $targetDate = '2026-08-15';
        $closures[$targetDate] = $this->closure($targetDate, [
            $this->field('visits', 100, 'users', 'online_daily_data#900'),
            ...($targetExposure ? [$this->field('exposure', 1000, 'users', 'online_daily_data#900')] : []),
        ]);
        for ($offset = 1; $offset <= $pairCount; $offset++) {
            $date = (new \DateTimeImmutable($targetDate))->modify('-' . $offset . ' days')->format('Y-m-d');
            $visitsRef = 'online_daily_data#' . (900 + $offset);
            $exposureRef = $mismatchedPairRef ? 'online_daily_data#' . (1900 + $offset) : $visitsRef;
            $visitors = 100 + $offset;
            $closures[$date] = $this->closure($date, [
                $this->field('visits', $visitors, 'users', $visitsRef),
                $this->field(
                    'exposure',
                    $impossibleFunnel ? $visitors - 1 : $visitors * 10,
                    $exposureUnit,
                    $exposureRef
                ),
            ]);
        }
        return $closures;
    }

    /** @param list<array<string,mixed>> $fields @return array<string,mixed> */
    private function closure(string $date, array $fields): array
    {
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 10,
            'hotel_id' => 80,
            'business_date' => $date,
            'consumer_contract' => ['contract_version' => 'trusted_ota_daily_fact_consumer.v1'],
            'platforms' => ['meituan' => ['fields' => $fields], 'ctrip' => ['fields' => []]],
        ];
    }

    /** @return array<string,mixed> */
    private function field(string $key, int $value, string $unit, string $ref): array
    {
        return [
            'key' => $key,
            'metric_key' => $key,
            'value' => $value,
            'unit' => $unit,
            'status' => 'strict_readback',
            'validation_status' => 'verified',
            'history_statuses' => ['success'],
            'readback_status' => 'readback_verified',
            'strict_final_gate' => true,
            'revenue_analysis_consumable' => true,
            'source_record_refs' => [$ref],
            'source_paths' => ['fixture.same_snapshot'],
            'source_method' => 'browser_profile',
            'time_basis' => 'same_day_cumulative',
            'cumulative_cutoff' => '23:00',
            'metric_definition_version' => 'fixture-exposure-users-detail-visitors.v1',
        ];
    }

    /** @return array<string,mixed> */
    private function actualCtripRow(int $rowId, int $taskId, string $date, bool $target): array
    {
        $facts = [[
            'metric_key' => 'detail_exposure',
            'source_key' => 'detailExposure',
            'source_path' => $target
                ? '$.data.visitor.detailExposure'
                : '$.data.traffic.detailExposure',
            'storage_field' => 'online_daily_data.detail_exposure',
            'status' => 'captured',
            'stored_value_present' => true,
            'capture_evidence' => [
                'capture_source' => $target ? 'xhr:business:visitor' : 'xhr:traffic:traffic',
                'source_trace_id' => 'ctrip:exposure-reference:' . $rowId,
                'source_url_hash' => str_repeat('a', 64),
            ],
        ]];
        if (!$target) {
            $facts[] = [
                'metric_key' => 'list_exposure',
                'source_key' => 'listExposure',
                'source_path' => '$.data.traffic.listExposure',
                'storage_field' => 'online_daily_data.list_exposure',
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'capture_source' => 'xhr:traffic:traffic',
                    'source_trace_id' => 'ctrip:exposure-reference:' . $rowId,
                    'source_url_hash' => str_repeat('a', 64),
                ],
            ];
        }
        return [
            'id' => $rowId,
            'tenant_id' => 10,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-hotel-80',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_type' => $target ? 'business' : 'traffic',
            'dimension' => $target ? 'business_visitor_title:visitor_count' : 'traffic',
            'data_date' => $date,
            'data_period' => 'historical_daily',
            'history_status' => 'success',
            'validation_status' => 'verified',
            'validation_flags' => '[]',
            'readback_verified' => 1,
            'data_source_id' => 25,
            'sync_task_id' => $taskId,
            'snapshot_time' => $date . ' 23:00:00',
            'list_exposure' => $target ? null : 1000,
            'detail_exposure' => 100,
            'flow_rate' => $target ? null : 10,
            'source_trace_id' => 'ctrip:exposure-reference:' . $rowId,
            'ingestion_method' => 'browser_profile',
            'raw_data' => json_encode([
                'source_trace_id' => 'ctrip:exposure-reference:' . $rowId,
                'source_url_hash' => str_repeat('a', 64),
                'row' => [
                    'endpoint_id' => $target ? 'business_visitor_title' : 'traffic_flow_transform',
                    '_capture_source' => $target ? 'xhr:business:visitor' : 'xhr:traffic:traffic',
                    'listExposure' => $target ? null : 1000,
                    'detailExposure' => 100,
                    'flowRate' => $target ? null : 10,
                ],
                'field_facts' => $facts,
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string,mixed> */
    private function actualTrust(int $rowId, int $taskId, string $date): array
    {
        return ['days' => [[
            'date' => $date,
            'platforms' => [[
                'platform' => 'ctrip',
                'acceptance_status' => 'verified',
                'target_date' => $date,
                'p0_status' => 'ready',
                'sync_task_status' => 'success',
                'steps' => [
                    'source' => true,
                    'account_profile_binding' => true,
                    'hotel' => true,
                    'date' => true,
                    'p0' => true,
                ],
                'acceptance_receipt' => [
                    'status' => 'verified',
                    'target_date' => $date,
                    'target_date_status' => 'matched',
                    'platform_hotel_id' => 'ctrip-hotel-80',
                    'platform_hotel_status' => 'verified',
                    'data_source_id' => 25,
                    'sync_task_id' => $taskId,
                    'sync_task_status' => 'success',
                    'source_method' => 'browser_profile',
                    'data_period' => 'historical_daily',
                    'reason_codes' => [],
                    'claim_allowed' => true,
                    'run_readback_scope' => [
                        'status' => 'verified',
                        'receipt_record_ids' => [$rowId],
                        'accepted_record_ids' => [$rowId],
                        'receipt_row_count' => 1,
                        'receipt_current_row_count' => 1,
                        'receipt_missing_row_count' => 0,
                        'receipt_identity_mismatch_count' => 0,
                        'authoritative_row_count' => 1,
                        'mismatched_row_count' => 0,
                    ],
                ],
            ]],
        ]]];
    }
}
