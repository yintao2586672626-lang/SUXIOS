<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RevenueAiCanonicalRequestedPlatformScopeTest.php';

final class RevenueAiCanonicalMetricUnitScopeTest extends TestCase
{
    public function testWrongCurrencyCannotBecomeVerifiedOtaRevenueOrAdr(): void
    {
        foreach (['USD', 'HKD', '%', 'room_nights'] as $unit) {
            $layer = $this->layer();
            foreach (['ota_room_revenue', 'ota_adr'] as $key) {
                $layer['analysis_metrics'][$key]['unit'] = $unit;
            }
            $overview = $this->overview($layer);
            foreach (['ota_room_revenue', 'ota_adr'] as $key) {
                self::assertNull($overview['metrics'][$key]['value']);
                self::assertSame('unverified', $overview['metrics'][$key]['status']);
                self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
                self::assertSame($unit, $overview['metrics'][$key]['unit']);
                self::assertSame('verified', $overview['metrics'][$key]['truth']['status']);
            }
        }
    }

    public function testOrderCountOrMoneyCannotBeDisplayedAsVerifiedRoomNights(): void
    {
        foreach (['orders', 'CNY', '%', 'rooms'] as $unit) {
            $layer = $this->layer();
            $layer['analysis_metrics']['ota_room_nights']['unit'] = $unit;
            $overview = $this->overview($layer);
            $metric = $overview['metrics']['ota_room_nights'];
            self::assertNull($metric['value']);
            self::assertSame('unverified', $metric['status']);
            self::assertSame('metric_scope_mismatch', $metric['reason']);
            self::assertSame(100.0, $overview['metrics']['ota_room_revenue']['value']);
        }
    }

    public function testMissingUnitsCannotBorrowExpectedUnitsFromTheMetricName(): void
    {
        $layer = $this->layer();
        foreach ($layer['analysis_metrics'] as &$metric) { unset($metric['unit']); }
        unset($metric);
        $overview = $this->overview($layer);
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            self::assertNull($overview['metrics'][$key]['value']);
            self::assertSame('unverified', $overview['metrics'][$key]['status']);
            self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
            self::assertSame('', $overview['metrics'][$key]['unit']);
        }
    }

    public function testCanonicalProducerUnitsAndVerifiedZeroKeepTheirExactMeaning(): void
    {
        $layer = $this->layer();
        $overview = $this->overview($layer);
        foreach (['ota_room_revenue' => 'CNY', 'ota_room_nights' => 'room_nights', 'ota_adr' => 'CNY'] as $key => $unit) {
            self::assertSame($unit, $overview['metrics'][$key]['unit']);
            self::assertSame('ok', $overview['metrics'][$key]['status']);
        }
        self::assertSame('¥100.00', $overview['metrics']['ota_room_revenue']['display']);
        $layer['analysis_metrics']['ota_room_revenue']['value'] = 0;
        $overview = $this->overview($layer);
        self::assertSame(0.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('¥0.00', $overview['metrics']['ota_room_revenue']['display']);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
    }

    public function testInvalidUnitTypesRejectOnlyTheAffectedMetricWithoutWarnings(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            foreach ([null, [], 123, true, '', ' CNY ', 'cny'] as $unit) {
                $layer = $this->layer();
                $layer['analysis_metrics'][$key]['unit'] = $unit;
                $truth = $layer['analysis_metrics'][$key]['truth'];
                $overview = $this->overview($layer);
                $metric = $overview['metrics'][$key];
                self::assertNull($metric['value']);
                self::assertSame('unverified', $metric['status']);
                self::assertSame('metric_scope_mismatch', $metric['reason']);
                self::assertSame(is_string($unit) ? $unit : '', $metric['unit']);
                self::assertSame($truth, $metric['truth']);
                foreach (['ota_room_revenue' => 100.0, 'ota_room_nights' => 1.0, 'ota_adr' => 100.0] as $otherKey => $value) {
                    if ($otherKey !== $key) {
                        self::assertSame($value, $overview['metrics'][$otherKey]['value']);
                        self::assertSame('ok', $overview['metrics'][$otherKey]['status']);
                    }
                }
                self::assertNotSame('', json_encode($metric, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testWrongUnitZeroCannotMasqueradeAsVerifiedZero(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            $layer = $this->layer();
            $layer['analysis_metrics'][$key]['value'] = 0;
            $layer['analysis_metrics'][$key]['unit'] = 'orders';
            $metric = $this->overview($layer)['metrics'][$key];
            self::assertNull($metric['value']);
            self::assertSame('--', $metric['display']);
            self::assertSame('unverified', $metric['status']);
            self::assertSame('metric_scope_mismatch', $metric['reason']);
        }
    }

    public function testPartialOriginalProofCannotOverrideTheUnitRejection(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            $layer = $this->layer();
            $layer['analysis_metrics'][$key]['unit'] = 'orders';
            $layer['analysis_metrics'][$key]['truth']['status'] = 'partial';
            $truth = $layer['analysis_metrics'][$key]['truth'];
            $overview = $this->overview($layer);
            self::assertNull($overview['metrics'][$key]['value']);
            self::assertSame('unverified', $overview['metrics'][$key]['status']);
            self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
            self::assertSame($truth, $overview['metrics'][$key]['truth']);
            self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
        }
    }

    public function testEmptyFailedAndPartialInputsKeepTheirExplicitCauseWithoutUnits(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            foreach (['collection_failed', 'partial', 'unverified', 'missing', 'empty_confirmed'] as $status) {
                $layer = $this->layer();
                unset($layer['analysis_metrics'][$key]['unit']);
                $layer['analysis_metrics'][$key]['value'] = null;
                $layer['analysis_metrics'][$key]['status'] = $status;
                $layer['analysis_metrics'][$key]['reason'] = 'FIELD_MISSING';
                $metric = $this->overview($layer)['metrics'][$key];
                self::assertNull($metric['value']);
                self::assertSame($status, $metric['status']);
                self::assertSame('FIELD_MISSING', $metric['reason']);
            }
        }
    }

    public function testReadyNullWithoutUnitsCannotClaimTheMetricIdentityWasVerified(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            foreach (['ok', 'ready', 'verified', ' Ready '] as $status) {
                $layer = $this->layer();
                unset($layer['analysis_metrics'][$key]['unit']);
                $layer['analysis_metrics'][$key]['value'] = null;
                $layer['analysis_metrics'][$key]['status'] = $status;
                $metric = $this->overview($layer)['metrics'][$key];
                self::assertNull($metric['value']);
                self::assertSame('unverified', $metric['status']);
                self::assertSame('metric_scope_mismatch', $metric['reason']);
                self::assertSame('verified', $metric['truth']['status']);
            }
        }
    }

    public function testRestoringProducerUnitsRecoversExactValuesWithoutChangingOriginalProof(): void
    {
        foreach (['ota_room_revenue' => ['CNY', 125.45, '¥125.45'], 'ota_room_nights' => ['room_nights', 2.0, '2'],
            'ota_adr' => ['CNY', 125.45, '¥125.45']] as $key => [$unit, $value, $display]) {
            $layer = $this->layer();
            $layer['analysis_metrics'][$key]['unit'] = 'orders';
            self::assertNull($this->overview($layer)['metrics'][$key]['value']);
            $layer['analysis_metrics'][$key]['unit'] = $unit;
            $layer['analysis_metrics'][$key]['value'] = (string)$value;
            $metric = $this->overview($layer)['metrics'][$key];
            self::assertSame($value, $metric['value']);
            self::assertSame($display, $metric['display']);
            self::assertSame('ok', $metric['status']);
            self::assertSame('', $metric['reason']);
            self::assertSame($layer['analysis_metrics'][$key]['truth'], $metric['truth']);
        }
    }

    public function testUnitMismatchExplainsUnitsAndProvidesTheExistingRecoveryEntry(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            $layer = $this->layer();
            $layer['analysis_metrics'][$key]['unit'] = 'orders';
            $metric = $this->overview($layer)['metrics'][$key];
            self::assertStringContainsString('单位', $metric['display_reason']);
            self::assertStringContainsString('币种', $metric['display_reason']);
            self::assertStringContainsString('单位', $metric['next_action']);
            self::assertStringContainsString('精确回读', $metric['next_action']);
            self::assertSame('online-data', $metric['target_page']);
            self::assertSame('data-health', $metric['target_tab']);
        }
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
