<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RevenueAiCanonicalRequestedPlatformScopeTest.php';

final class RevenueAiCanonicalMetricSemanticScopeTest extends TestCase
{
    public function testWholeHotelScopeCannotPromoteChannelOnlyCanonicalEvidence(): void
    {
        $layer = $this->layer();
        foreach ($layer['analysis_metrics'] as &$metric) {
            $metric['scope'] = 'whole_hotel';
        }
        unset($metric);
        $overview = $this->overview($layer);
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            self::assertNull($overview['metrics'][$key]['value']);
            self::assertSame('unverified', $overview['metrics'][$key]['status']);
            self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
            self::assertSame('verified', $overview['metrics'][$key]['truth']['status']);
        }
    }

    public function testStayDateOrPmsBusinessDayCannotBecomeOtaDataDate(): void
    {
        foreach (['stay_date', 'pms_business_date', 'same_date_key_distinct_source_semantics'] as $dateBasis) {
            $layer = $this->layer();
            foreach ($layer['analysis_metrics'] as &$metric) {
                $metric['date_basis'] = $dateBasis;
            }
            unset($metric);
            $overview = $this->overview($layer);
            foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
                self::assertNull($overview['metrics'][$key]['value']);
                self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
            }
        }
    }

    public function testMissingSemanticMetadataCannotBorrowDefaultVerifiedMeaning(): void
    {
        foreach (['scope', 'date_basis'] as $field) {
            $layer = $this->layer();
            foreach ($layer['analysis_metrics'] as &$metric) {
                unset($metric[$field]);
            }
            unset($metric);
            $overview = $this->overview($layer);
            self::assertNull($overview['metrics']['ota_room_revenue']['value']);
            self::assertSame('unverified', $overview['metrics']['ota_room_revenue']['status']);
            self::assertSame('metric_scope_mismatch', $overview['metrics']['ota_room_revenue']['reason']);
            foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
                self::assertSame('', $overview['metrics'][$key][$field]);
            }
        }
    }

    public function testInvalidOrUnknownSemanticMetadataStaysUnverified(): void
    {
        foreach (['scope', 'date_basis'] as $field) {
            foreach ([null, '', ' ', 'unknown', false, 1, ['data_date']] as $invalid) {
                $layer = $this->layer();
                foreach ($layer['analysis_metrics'] as &$metric) {
                    $metric[$field] = $invalid;
                }
                unset($metric);
                $overview = $this->overview($layer);
                foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
                    self::assertNull($overview['metrics'][$key]['value']);
                    self::assertSame('unverified', $overview['metrics'][$key]['status']);
                    self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
                    self::assertSame(is_string($invalid) ? $invalid : '', $overview['metrics'][$key][$field]);
                }
            }
        }
    }

    public function testCrossSourceOrPmsScopeCannotRenamePureOtaMetric(): void
    {
        foreach (['cross_source_comparison', 'whole_hotel_accommodation', 'pms', 'hotel'] as $scope) {
            $layer = $this->layer();
            foreach ($layer['analysis_metrics'] as &$metric) {
                $metric['scope'] = $scope;
            }
            unset($metric);
            $overview = $this->overview($layer);
            foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
                self::assertNull($overview['metrics'][$key]['value']);
                self::assertSame($scope, $overview['metrics'][$key]['scope']);
                self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
            }
        }
    }

    public function testEachMetricRequiresItsOwnSemanticMetadata(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $changedKey) {
            $layer = $this->layer();
            $layer['analysis_metrics'][$changedKey]['date_basis'] = 'stay_date';
            $overview = $this->overview($layer);
            foreach (['ota_room_revenue' => 100.0, 'ota_room_nights' => 1.0, 'ota_adr' => 100.0] as $key => $value) {
                self::assertSame($key === $changedKey ? null : $value, $overview['metrics'][$key]['value']);
                self::assertSame($key === $changedKey ? 'unverified' : 'ok', $overview['metrics'][$key]['status']);
            }
        }
    }

    public function testVerifiedZeroNeedsTheSameExplicitSemantics(): void
    {
        $layer = $this->layer();
        $layer['analysis_metrics']['ota_room_revenue']['value'] = 0;
        $layer['analysis_metrics']['ota_adr']['value'] = 0;
        $overview = $this->overview($layer);
        self::assertSame(0.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('¥0.00', $overview['metrics']['ota_room_revenue']['display']);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
        unset($layer['analysis_metrics']['ota_room_revenue']['scope']);
        $overview = $this->overview($layer);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('--', $overview['metrics']['ota_room_revenue']['display']);
        self::assertSame('metric_scope_mismatch', $overview['metrics']['ota_room_revenue']['reason']);
    }

    public function testPartialAndFailedProofRemainDistinctWithValidSemantics(): void
    {
        foreach (['partial', 'collection_failed', 'unverified'] as $truthStatus) {
            $layer = $this->layer();
            $layer['analysis_metrics']['ota_room_revenue']['truth']['status'] = $truthStatus;
            $overview = $this->overview($layer);
            self::assertSame(100.0, $overview['metrics']['ota_room_revenue']['value']);
            self::assertSame($truthStatus, $overview['metrics']['ota_room_revenue']['status']);
            self::assertSame('metric_truth_' . $truthStatus, $overview['metrics']['ota_room_revenue']['reason']);
        }
    }

    public function testMissingNumericValueKeepsItsFailureAndDoesNotGainSemanticDefaults(): void
    {
        $layer = $this->layer();
        $layer['analysis_metrics']['ota_room_revenue'] = [
            'value' => null, 'unit' => 'CNY', 'status' => 'collection_failed',
            'reason' => 'ota_collect_failed', 'source_channels' => ['ctrip'],
        ];
        $overview = $this->overview($layer);
        $metric = $overview['metrics']['ota_room_revenue'];
        self::assertNull($metric['value']);
        self::assertSame('--', $metric['display']);
        self::assertSame('collection_failed', $metric['status']);
        self::assertSame('ota_collect_failed', $metric['reason']);
        self::assertSame('', $metric['scope']);
        self::assertSame('', $metric['date_basis']);
    }

    public function testSemanticMismatchKeepsOriginalEvidenceAndRecoveryEntry(): void
    {
        $layer = $this->layer();
        $layer['analysis_metrics']['ota_room_revenue']['scope'] = 'whole_hotel';
        $expectedTruth = $layer['analysis_metrics']['ota_room_revenue']['truth'];
        $overview = $this->overview($layer);
        $metric = $overview['metrics']['ota_room_revenue'];
        self::assertNull($metric['value']);
        self::assertSame($expectedTruth, $metric['truth']);
        self::assertSame('online-data', $metric['target_page']);
        self::assertSame('data-health', $metric['target_tab']);
        self::assertNotSame('数据已命中当前口径。', $metric['display_reason']);
        self::assertNotSame('', $metric['next_action']);
        $gates = array_values(array_filter($overview['pricing_readiness']['gates'],
            static fn(array $gate): bool => $gate['key'] === 'ota_metrics'));
        self::assertCount(1, $gates);
        self::assertSame('metric_scope_mismatch', $gates[0]['reason']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    public function testEmptyOldMetricCannotClaimReadyWithMissingSemantics(): void
    {
        foreach (['ok', 'ready', 'verified'] as $status) {
            foreach (['scope', 'date_basis'] as $field) {
                $layer = $this->layer();
                $layer['analysis_metrics']['ota_room_revenue']['value'] = null;
                $layer['analysis_metrics']['ota_room_revenue']['status'] = $status;
                unset($layer['analysis_metrics']['ota_room_revenue'][$field]);
                $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
                self::assertNull($metric['value']);
                self::assertSame('--', $metric['display']);
                self::assertSame('unverified', $metric['status']);
                self::assertSame('metric_scope_mismatch', $metric['reason']);
            }
        }
    }

    public function testCorrectedMetadataCanRecoverWithoutRewritingOriginalTruth(): void
    {
        $layer = $this->layer();
        $expectedTruth = $layer['analysis_metrics']['ota_room_revenue']['truth'];
        $layer['analysis_metrics']['ota_room_revenue']['date_basis'] = 'stay_date';
        self::assertNull($this->overview($layer)['metrics']['ota_room_revenue']['value']);
        $layer['analysis_metrics']['ota_room_revenue']['date_basis'] = 'data_date';
        $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
        self::assertSame(100.0, $metric['value']);
        self::assertSame('ok', $metric['status']);
        self::assertSame('', $metric['reason']);
        self::assertSame($expectedTruth, $metric['truth']);
    }

    public function testMatchingCanonicalOtaDataDateKeepsPreciseVerifiedMetrics(): void
    {
        $overview = $this->overview($this->layer());
        self::assertSame(100.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(1.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertSame(100.0, $overview['metrics']['ota_adr']['value']);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
        self::assertSame('ota_channel', $overview['metrics']['ota_room_revenue']['scope']);
        self::assertSame('data_date', $overview['metrics']['ota_room_revenue']['date_basis']);
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
