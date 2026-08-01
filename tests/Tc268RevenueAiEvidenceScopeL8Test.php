<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Tc268RevenueAiEvidenceScopeL8Test extends TestCase
{
    private const TARGET_DATE = '2026-07-15';
    private const STALE_DATE = '2026-07-01';

    #[DataProvider('l8VariantProvider')]
    public function testTc268KeepsRevenueAiEvidenceInsideTheOtaChannelBoundary(
        string $caseId,
        string $platformPermission,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): void {
        $dataset = $this->dataset($dataCompleteness, $freshness, $upstreamState);
        $sourceStatuses = $this->sourceStatuses($freshness, $upstreamState);
        $p0Gate = $this->p0Gate($platformPermission, $dataCompleteness, $freshness, $upstreamState);

        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $dataset,
            ['ctrip' => $dataset],
            $sourceStatuses,
            [
                'business_date' => self::TARGET_DATE,
                'hotel_id' => 7,
                'enabled_channels' => ['ctrip'],
                'p0_downstream_gate' => $p0Gate,
            ]
        );

        $credibilityGate = $overview['metric_summary']['credibility_gate'];
        $closure = $overview['p1_revenue_closure'];
        $normalizedP0Gate = $overview['p0_downstream_gate'];
        $collectionQuality = $credibilityGate['evidence']['collection_quality'];
        $handoff = $overview['ai_to_operation_handoff'];
        $operationPreflight = $handoff['operation_intake_packet']['operation_intake_preflight_contract'];

        // TC-268: every conclusion is an OTA-channel claim. Direct-sales, offline and
        // whole-hotel cost evidence is absent, so no combination may upgrade the scope.
        self::assertSame('ota', $overview['scope'], $caseId);
        self::assertSame(['ctrip'], $overview['source_channels'], $caseId);
        self::assertSame('ota_channel', $credibilityGate['metric_scope'], $caseId);
        self::assertSame('ota_channel', $closure['scope'], $caseId);
        self::assertStringContainsString('not whole-hotel', $closure['scope_statement'], $caseId);
        self::assertFalse($closure['whole_hotel_guard']['allowed'], $caseId);
        self::assertSame('whole_hotel_scope_not_proved', $closure['whole_hotel_guard']['reason'], $caseId);
        self::assertFalse($credibilityGate['decision_use']['investment_decision']['allowed'], $caseId);
        self::assertSame(
            'ota_channel',
            $overview['pricing_readiness']['ai_decision_resolution_plan']['metric_scope'],
            $caseId
        );
        self::assertSame(
            'ctrip_ota_channel',
            $overview['pricing_readiness']['ai_decision_resolution_plan']['source_scope'],
            $caseId
        );
        self::assertSame('ctrip_ota_channel', $handoff['source_scope'], $caseId);
        self::assertSame(['ctrip'], $handoff['source_channels'], $caseId);
        self::assertFalse($handoff['can_create_operation_execution'], $caseId);
        self::assertFalse($handoff['auto_create_operation_execution'], $caseId);
        self::assertFalse($operationPreflight['create_allowed'], $caseId);
        self::assertFalse($operationPreflight['would_call_create_endpoint'], $caseId);
        self::assertTrue($overview['execution_summary']['read_only'], $caseId);
        self::assertFalse($overview['execution_summary']['auto_write_ota'], $caseId);

        $blockers = $normalizedP0Gate['blocking_missing_inputs'];
        $reasonCodes = $credibilityGate['reason_codes'];

        if ($dataCompleteness === 'complete') {
            self::assertSame(6.0, $overview['metrics']['ota_room_nights']['value'], $caseId);
            self::assertSame('ok', $overview['metrics']['ota_room_nights']['status'], $caseId);
            self::assertNotContains('required_field_missing:room_nights', $blockers, $caseId);
        } else {
            self::assertNull($overview['metrics']['ota_room_nights']['value'], $caseId);
            self::assertSame('not_calculable', $overview['metrics']['ota_room_nights']['status'], $caseId);
            self::assertContains('required_field_missing:room_nights', $blockers, $caseId);
            self::assertContains(
                'p0_ota_gate_missing:required_field_missing:room_nights',
                $reasonCodes,
                $caseId
            );
            self::assertContains(
                'critical_metric_untrusted:totals.room_nights',
                $credibilityGate['evidence']['failed_critical_metrics'],
                $caseId
            );
        }

        if ($freshness === 'fresh') {
            self::assertSame(self::TARGET_DATE, $dataset['fact_ota_daily'][0]['date_key'], $caseId);
            self::assertNotContains('stale_target_date_data', $blockers, $caseId);
        } else {
            self::assertSame(self::STALE_DATE, $dataset['fact_ota_daily'][0]['date_key'], $caseId);
            self::assertContains('stale_target_date_data', $blockers, $caseId);
            self::assertContains('p0_ota_gate_missing:stale_target_date_data', $reasonCodes, $caseId);
        }

        if ($upstreamState === 'success') {
            self::assertSame('ready', $credibilityGate['evidence']['dataset_status'], $caseId);
            self::assertSame('ok', $overview['channel_statuses']['ctrip']['status'], $caseId);
            self::assertNotContains('collection_failed', $blockers, $caseId);
            self::assertNotContains('ota_dataset_failed', $reasonCodes, $caseId);
        } else {
            self::assertSame('failed', $credibilityGate['evidence']['dataset_status'], $caseId);
            self::assertSame('failed', $overview['channel_statuses']['ctrip']['status'], $caseId);
            self::assertContains('collection_failed', $blockers, $caseId);
            self::assertContains('p0_ota_gate_missing:collection_failed', $reasonCodes, $caseId);
            self::assertContains('ota_dataset_failed', $reasonCodes, $caseId);
        }

        if ($platformPermission === 'restricted') {
            // This is a Ctrip platform/collection-quality permission boundary. It is
            // intentionally not an application actor, route or HTTP ACL assertion.
            self::assertContains('permission_denied:ctrip_required_module', $blockers, $caseId);
            self::assertSame('permission_denied', $collectionQuality['primary_quality_state'], $caseId);
            self::assertContains('ota_collection_quality:permission_denied', $reasonCodes, $caseId);
            self::assertArrayNotHasKey('http_status', $normalizedP0Gate, $caseId);
            self::assertArrayNotHasKey('route_authorization', $normalizedP0Gate, $caseId);
            self::assertArrayNotHasKey('permitted_hotel_ids', $normalizedP0Gate, $caseId);
        } else {
            self::assertNotContains('permission_denied:ctrip_required_module', $blockers, $caseId);
        }

        $allEvidenceReady = $platformPermission === 'authorized'
            && $dataCompleteness === 'complete'
            && $freshness === 'fresh'
            && $upstreamState === 'success';

        if ($allEvidenceReady) {
            self::assertSame('ready', $normalizedP0Gate['status'], $caseId);
            self::assertSame('available', $collectionQuality['primary_quality_state'], $caseId);
            self::assertTrue($closure['calculation_allowed'], $caseId);
            self::assertTrue($credibilityGate['decision_use']['revenue_analysis']['allowed'], $caseId);
            self::assertTrue($credibilityGate['decision_use']['ai_decision_support']['allowed'], $caseId);
            self::assertTrue($credibilityGate['decision_use']['operation_management']['allowed'], $caseId);
            self::assertSame(1200.0, $closure['sections']['revenue']['value'], $caseId);
            self::assertSame(6.0, $closure['sections']['room_nights']['value'], $caseId);
            return;
        }

        self::assertSame('blocked_by_p0_ota_gate', $normalizedP0Gate['status'], $caseId);
        self::assertSame('blocked', $credibilityGate['status'], $caseId);
        self::assertContains('p0_ota_gate_not_ready', $reasonCodes, $caseId);
        self::assertFalse($closure['calculation_allowed'], $caseId);
        self::assertFalse($credibilityGate['decision_use']['revenue_analysis']['allowed'], $caseId);
        self::assertFalse($credibilityGate['decision_use']['ai_decision_support']['allowed'], $caseId);
        self::assertFalse($credibilityGate['decision_use']['operation_management']['allowed'], $caseId);
        self::assertSame(
            'blocked_by_p0_ota_gate',
            $credibilityGate['decision_use']['ai_decision_support']['status'],
            $caseId
        );
        self::assertSame(
            'blocked_by_p0_ota_gate',
            $credibilityGate['decision_use']['operation_management']['status'],
            $caseId
        );
        self::assertNull($closure['sections']['revenue']['value'], $caseId);
        self::assertNull($closure['sections']['room_nights']['value'], $caseId);
    }

    /**
     * @return array<string, array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2137' => ['DX-2137', 'authorized', 'complete', 'fresh', 'success'],
            'DX-2138' => ['DX-2138', 'authorized', 'complete', 'stale', 'failure'],
            'DX-2139' => ['DX-2139', 'authorized', 'missing_required', 'fresh', 'failure'],
            'DX-2140' => ['DX-2140', 'authorized', 'missing_required', 'stale', 'success'],
            'DX-2141' => ['DX-2141', 'restricted', 'complete', 'fresh', 'failure'],
            'DX-2142' => ['DX-2142', 'restricted', 'complete', 'stale', 'success'],
            'DX-2143' => ['DX-2143', 'restricted', 'missing_required', 'fresh', 'success'],
            'DX-2144' => ['DX-2144', 'restricted', 'missing_required', 'stale', 'failure'],
        ];
    }

    /** @return array<string, mixed> */
    private function dataset(string $dataCompleteness, string $freshness, string $upstreamState): array
    {
        $date = $freshness === 'fresh' ? self::TARGET_DATE : self::STALE_DATE;
        $saved = $upstreamState === 'success';
        $failureReasons = $saved ? [] : ['upstream_collection_failed'];
        $daily = [
            'date_key' => $date,
            'hotel_key' => 'system:7',
            'platform_key' => 'ctrip',
            'data_type' => 'business',
            'metric_scope' => 'ota_channel',
            'calculation_basis' => 'ota_daily_standard_fact',
            'revenue' => 1200.0,
            'gross_revenue' => 1200.0,
            'room_revenue' => 1200.0,
            'net_revenue' => 1020.0,
            'commission_amount' => 180.0,
            'commission_rate' => 15.0,
            'available_room_nights' => 10.0,
            'occupied_room_nights' => 6.0,
            'order_count' => 4,
            'cancel_order_num' => 0,
            'cancel_room_nights' => 0,
            'lead_time_days' => 2,
            'our_price' => 200.0,
            'competitor_price' => 210.0,
            'price_gap' => -10.0,
            'price_gap_rate' => -4.76,
            'source_trace' => $this->trace(26801, 'business', $date, $saved, $failureReasons),
        ];
        if ($dataCompleteness === 'complete') {
            $daily['room_nights'] = 6.0;
            $daily['adr'] = 200.0;
            $daily['revpar'] = 120.0;
        }

        return [
            'status' => $upstreamState === 'success' ? 'ready' : 'failed',
            'dim_hotel' => [[
                'hotel_key' => 'system:7',
                'system_hotel_id' => 7,
                'hotel_name' => 'Hotel Alpha',
            ]],
            'dim_platform' => [[
                'platform_key' => 'ctrip',
                'platform_name' => 'Ctrip',
            ]],
            'data_quality' => [
                'input_rows' => 2,
                'accepted_rows' => 2,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [$daily],
            'fact_ota_traffic' => [[
                'date_key' => $date,
                'hotel_key' => 'system:7',
                'platform_key' => 'ctrip',
                'resource' => 'traffic',
                'metric_scope' => 'ota_channel',
                'flow_rate' => 20.0,
                'submit_rate' => 33.33,
                'source_trace' => $this->trace(26802, 'traffic', $date, $saved, $failureReasons),
            ]],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_peer_rank' => [],
            'fact_ota_traffic_analysis' => [],
            'fact_ota_traffic_forecast' => [],
            'fact_ota_comment' => [],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function sourceStatuses(string $freshness, string $upstreamState): array
    {
        if ($upstreamState === 'failure') {
            return [
                'ctrip' => [
                    'status' => 'ready',
                    'last_sync_status' => 'failed',
                    'last_error' => 'network timeout while collecting target-date OTA module',
                ],
            ];
        }

        $date = $freshness === 'fresh' ? self::TARGET_DATE : self::STALE_DATE;
        return [
            'ctrip' => [
                'status' => 'ready',
                'last_sync_status' => 'success',
                'last_sync_time' => $date . ' 10:05:00',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function p0Gate(
        string $platformPermission,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        $blockers = [];
        if ($platformPermission === 'restricted') {
            $blockers[] = 'permission_denied:ctrip_required_module';
        }
        if ($dataCompleteness === 'missing_required') {
            $blockers[] = 'required_field_missing:room_nights';
        }
        if ($freshness === 'stale') {
            $blockers[] = 'stale_target_date_data';
        }
        if ($upstreamState === 'failure') {
            $blockers[] = 'collection_failed';
        }

        if ($blockers === []) {
            return ['status' => 'ready'];
        }

        return [
            'status' => 'blocked_by_p0_ota_gate',
            'current_upstream_status' => $platformPermission === 'restricted'
                ? 'platform_permission_restricted'
                : ($upstreamState === 'failure' ? 'collection_failed' : 'incomplete'),
            'required_upstream_status' => 'ready',
            'scope_policy' => 'ota_channel_gate_before_downstream_claims',
            'blocking_missing_inputs' => $blockers,
        ];
    }

    /**
     * @param array<int, string> $failureReasons
     * @return array<string, mixed>
     */
    private function trace(
        int $rowId,
        string $dataType,
        string $date,
        bool $saved,
        array $failureReasons
    ): array {
        return [
            'table' => 'online_daily_data',
            'row_id' => $rowId,
            'hotel_key' => 'system:7',
            'platform' => 'ctrip',
            'data_type' => $dataType,
            'date_key' => $date,
            'updated_at' => $date . ' 10:00:00',
            'saved_success' => $saved,
            'failure_reasons' => $failureReasons,
        ];
    }
}
