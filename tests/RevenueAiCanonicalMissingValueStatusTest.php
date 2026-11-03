<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RevenueAiCanonicalRequestedPlatformScopeTest.php';

final class RevenueAiCanonicalMissingValueStatusTest extends TestCase
{
    public function testMissingCanonicalValueCannotClaimReadyWithCompleteMetadata(): void
    {
        foreach (['ok', 'ready', 'verified', ' OK ', 'Ready', 'VERIFIED'] as $status) {
            foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
                $layer = $this->layer();
                $layer['analysis_metrics'][$key]['value'] = null;
                $layer['analysis_metrics'][$key]['status'] = $status;
                $metric = $this->overview($layer)['metrics'][$key];
                self::assertNull($metric['value']);
                self::assertSame('--', $metric['display']);
                self::assertSame('not_calculable', $metric['status']);
                self::assertSame('metric_value_missing', $metric['reason']);
                self::assertSame('verified', $metric['truth']['status']);
            }
        }
    }

    public function testAbsentOrInvalidValueCannotBorrowChannelFactsOrOtherMetrics(): void
    {
        foreach (['absent', '', 'not-a-number', ['value' => 100], false] as $case) {
            $layer = $this->layer();
            if ($case === 'absent') {
                unset($layer['analysis_metrics']['ota_room_revenue']['value']);
            } else {
                $layer['analysis_metrics']['ota_room_revenue']['value'] = $case;
            }
            $overview = $this->overview($layer);
            self::assertNull($overview['metrics']['ota_room_revenue']['value']);
            self::assertSame('not_calculable', $overview['metrics']['ota_room_revenue']['status']);
            self::assertSame('metric_value_missing', $overview['metrics']['ota_room_revenue']['reason']);
            self::assertSame(1.0, $overview['metrics']['ota_room_nights']['value']);
            self::assertSame(100.0, $overview['metrics']['ota_adr']['value']);
        }
    }

    public function testVerifiedZeroAndExactNumbersRemainUsable(): void
    {
        $layer = $this->layer();
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            $layer['analysis_metrics'][$key]['value'] = 0;
        }
        $overview = $this->overview($layer);
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            self::assertSame(0.0, $overview['metrics'][$key]['value']);
            self::assertSame('ok', $overview['metrics'][$key]['status']);
            self::assertSame('', $overview['metrics'][$key]['reason']);
        }
        self::assertSame('¥0.00', $overview['metrics']['ota_room_revenue']['display']);
        self::assertSame(100.0, $this->overview($this->layer())['metrics']['ota_room_revenue']['value']);
    }

    public function testAnExplicitEmptyCollectionFailureKeepsItsCause(): void
    {
        $layer = $this->layer();
        $layer['analysis_metrics']['ota_room_revenue']['value'] = null;
        $layer['analysis_metrics']['ota_room_revenue']['status'] = 'collection_failed';
        $layer['analysis_metrics']['ota_room_revenue']['reason'] = 'ota_collect_failed';
        $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
        self::assertNull($metric['value']);
        self::assertSame('collection_failed', $metric['status']);
        self::assertSame('ota_collect_failed', $metric['reason']);
    }

    public function testNonFiniteValuesCannotBecomeVerifiedPublicNumbers(): void
    {
        foreach ([INF, -INF, NAN, '1e999'] as $invalid) {
            foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
                $layer = $this->layer();
                $layer['analysis_metrics'][$key]['value'] = $invalid;
                $metric = $this->overview($layer)['metrics'][$key];
                self::assertNull($metric['value']);
                self::assertSame('--', $metric['display']);
                self::assertSame('not_calculable', $metric['status']);
                self::assertSame('metric_value_missing', $metric['reason']);
                self::assertNotSame('', json_encode($metric, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testOldDefaultStatusDoesNotClaimTheMissingValueMatchedSuccessfully(): void
    {
        $layer = $this->layer();
        unset($layer['analysis_metrics']['ota_room_revenue']['value'],
            $layer['analysis_metrics']['ota_room_revenue']['status']);
        $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
        self::assertNull($metric['value']);
        self::assertSame('not_calculable', $metric['status']);
        self::assertSame('metric_value_missing', $metric['reason']);
        self::assertStringContainsString('缺失', $metric['display_reason']);
        self::assertNotSame('数据已命中当前口径。', $metric['display_reason']);
    }

    public function testExplicitNonreadyReasonsArePreservedWhenTheNumberIsMissing(): void
    {
        foreach (['failed' => 'target_date_dataset_failed', 'unauthorized' => 'AUTH_EXPIRED',
            'partial' => 'room_revenue_partial', 'unverified' => 'metric_truth_unverified',
            'missing' => 'room_revenue_missing', 'empty_confirmed' => 'ZERO_CONFIRMED',
            'blocked' => 'source_disabled', 'stale' => 'DATA_STALE'] as $status => $reason) {
            $layer = $this->layer();
            $layer['analysis_metrics']['ota_room_revenue']['value'] = null;
            $layer['analysis_metrics']['ota_room_revenue']['status'] = $status;
            $layer['analysis_metrics']['ota_room_revenue']['reason'] = $reason;
            $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
            self::assertNull($metric['value']);
            self::assertSame($status, $metric['status']);
            self::assertSame($reason, $metric['reason']);
        }
    }

    public function testUpstreamMissingFieldCauseIsKeptWhenReadyLabelIsCorrected(): void
    {
        $layer = $this->layer();
        $layer['analysis_metrics']['ota_room_revenue']['value'] = null;
        $layer['analysis_metrics']['ota_room_revenue']['reason'] = 'FIELD_MISSING';
        $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
        self::assertSame('not_calculable', $metric['status']);
        self::assertSame('FIELD_MISSING', $metric['reason']);
        self::assertNull($metric['value']);
    }

    public function testMissingNumberKeepsOriginalProofAndDataHealthRecovery(): void
    {
        $layer = $this->layer();
        $expectedTruth = $layer['analysis_metrics']['ota_room_revenue']['truth'];
        $layer['analysis_metrics']['ota_room_revenue']['value'] = null;
        $overview = $this->overview($layer);
        $metric = $overview['metrics']['ota_room_revenue'];
        self::assertSame($expectedTruth, $metric['truth']);
        self::assertSame('ota_channel', $metric['scope']);
        self::assertSame('data_date', $metric['date_basis']);
        self::assertSame(['ctrip'], $metric['source_channels']);
        self::assertStringContainsString('缺失', $metric['display_reason']);
        self::assertSame('online-data', $metric['target_page']);
        self::assertSame('data-health', $metric['target_tab']);
        self::assertNotSame('', $metric['next_action']);
        $gates = array_values(array_filter($overview['pricing_readiness']['gates'],
            static fn(array $gate): bool => $gate['key'] === 'ota_metrics'));
        self::assertCount(1, $gates);
        self::assertSame('blocked', $gates[0]['status']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    public function testRestoredValueCanRecoverWithoutChangingEvidenceOrScope(): void
    {
        $layer = $this->layer();
        $expectedTruth = $layer['analysis_metrics']['ota_room_revenue']['truth'];
        $layer['analysis_metrics']['ota_room_revenue']['value'] = null;
        self::assertSame('not_calculable', $this->overview($layer)['metrics']['ota_room_revenue']['status']);
        $layer['analysis_metrics']['ota_room_revenue']['value'] = '125.45';
        $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
        self::assertSame(125.45, $metric['value']);
        self::assertSame('¥125.45', $metric['display']);
        self::assertSame('ok', $metric['status']);
        self::assertSame('', $metric['reason']);
        self::assertSame($expectedTruth, $metric['truth']);
    }

    public function testSemanticOrPlatformMismatchRetainsHigherPriorityThanNumberGap(): void
    {
        foreach (['scope', 'source_channels'] as $field) {
            $layer = $this->layer();
            $layer['analysis_metrics']['ota_room_revenue']['value'] = null;
            $layer['analysis_metrics']['ota_room_revenue'][$field] = $field === 'scope' ? 'whole_hotel' : ['meituan'];
            $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
            self::assertNull($metric['value']);
            self::assertSame('unverified', $metric['status']);
            self::assertSame('metric_scope_mismatch', $metric['reason']);
        }
    }

    public function testMissingNumberAndUnverifiedProofDoNotClaimThatANumberExists(): void
    {
        $layer = $this->layer();
        $layer['analysis_metrics']['ota_room_revenue']['value'] = null;
        $layer['analysis_metrics']['ota_room_revenue']['truth']['status'] = 'unverified';
        $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
        self::assertNull($metric['value']);
        self::assertSame('unverified', $metric['status']);
        self::assertSame('metric_truth_unverified', $metric['reason']);
        self::assertStringNotContainsString('指标存在数值', $metric['display_reason']);
        self::assertSame($layer['analysis_metrics']['ota_room_revenue']['truth'], $metric['truth']);
    }

    private function fixture(string $method, mixed ...$args): mixed
    {
        $test = new RevenueAiCanonicalRequestedPlatformScopeTest('testMatchingSingleChannelCanonicalMetricsStillDisplayPrecisely');
        return (new \ReflectionMethod($test, $method))->invoke($test, ...$args);
    }

    private function layer(): array
    {
        return $this->fixture('layer', $this->fixture('dataset', 'ctrip'));
    }

    private function overview(array $layer): array
    {
        return (new RevenueAiOverviewService())->buildOverviewFromDataset([], ['ctrip' => $this->fixture('dataset', 'ctrip')], [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip'], 'revenue_fact_layer' => $layer]);
    }
}
