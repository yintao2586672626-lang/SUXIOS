<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RevenueAiCanonicalMetricUnitScopeTest.php';

final class RevenueAiCanonicalMetricKeyIdentityTest extends TestCase
{
    public function testConflictingExplicitMetricIdentityCannotRemainVerified(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr', 'whole_hotel_room_revenue', 'foreign_metric'] as $wrongKey) {
                if ($wrongKey === $key) continue;
                $layer = $this->layer();
                $layer['analysis_metrics'][$key]['key'] = $wrongKey;
                $metric = $this->overview($layer)['metrics'][$key];
                self::assertNull($metric['value']);
                self::assertSame($key, $metric['key']);
                self::assertSame('unverified', $metric['status']);
                self::assertSame('metric_scope_mismatch', $metric['reason']);
                self::assertSame($layer['analysis_metrics'][$key]['truth'], $metric['truth']);
            }
        }
    }

    public function testBlankOrNonStringMetricIdentityCannotClaimAUsableMetric(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            foreach (['', ' ', [], 123, true, null] as $invalidKey) {
                $layer = $this->layer();
                $layer['analysis_metrics'][$key]['key'] = $invalidKey;
                $metric = $this->overview($layer)['metrics'][$key];
                self::assertNull($metric['value']);
                self::assertSame($key, $metric['key']);
                self::assertSame('unverified', $metric['status']);
                self::assertSame('metric_scope_mismatch', $metric['reason']);
            }
        }
    }

    public function testMatchingExplicitCanonicalMetricKeysKeepTheirExactValuesAndZero(): void
    {
        $layer = $this->layer();
        foreach ($layer['analysis_metrics'] as $key => &$metric) $metric['key'] = $key;
        unset($metric);
        $layer['analysis_metrics']['ota_room_revenue']['value'] = '125.45';
        $overview = $this->overview($layer);
        self::assertSame(125.45, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('¥125.45', $overview['metrics']['ota_room_revenue']['display']);
        foreach ($layer['analysis_metrics'] as $key => &$metric) {
            self::assertSame($key, $overview['metrics'][$key]['key']);
            self::assertSame('ok', $overview['metrics'][$key]['status']);
            $metric['value'] = 0;
        }
        unset($metric);
        $overview = $this->overview($layer);
        foreach ($layer['analysis_metrics'] as $key => $metric) {
            self::assertSame(0.0, $overview['metrics'][$key]['value']);
            self::assertSame('ok', $overview['metrics'][$key]['status']);
        }
    }

    public function testOldRowsWithoutRedundantKeyUseTheirOuterCanonicalIdentity(): void
    {
        $layer = $this->layer();
        foreach ($layer['analysis_metrics'] as &$metric) unset($metric['key']);
        unset($metric);
        $overview = $this->overview($layer);
        foreach (['ota_room_revenue' => 100.0, 'ota_room_nights' => 1.0, 'ota_adr' => 100.0] as $key => $value) {
            self::assertSame($key, $overview['metrics'][$key]['key']);
            self::assertSame($value, $overview['metrics'][$key]['value']);
            self::assertSame('ok', $overview['metrics'][$key]['status']);
        }
    }

    public function testKeyMismatchRejectsOnlyTheAffectedMetricAndKeepsOriginalProof(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            $layer = $this->layer();
            $layer['analysis_metrics'][$key]['key'] = 'whole_hotel_room_revenue';
            $layer['analysis_metrics'][$key]['truth']['status'] = 'partial';
            $overview = $this->overview($layer);
            $metric = $overview['metrics'][$key];
            self::assertNull($metric['value']);
            self::assertSame($key, $metric['key']);
            self::assertSame('unverified', $metric['status']);
            self::assertSame($layer['analysis_metrics'][$key]['truth'], $metric['truth']);
            foreach (['ota_room_revenue' => 100.0, 'ota_room_nights' => 1.0, 'ota_adr' => 100.0] as $otherKey => $value) {
                if ($otherKey !== $key) self::assertSame($value, $overview['metrics'][$otherKey]['value']);
            }
            self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
        }
    }

    public function testWrongIdentityCannotHideBehindVerifiedZeroOrReadyNull(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            foreach ([0, null] as $value) {
                $layer = $this->layer();
                $layer['analysis_metrics'][$key]['key'] = 'foreign_metric';
                $layer['analysis_metrics'][$key]['value'] = $value;
                $layer['analysis_metrics'][$key]['status'] = ' Ready ';
                $metric = $this->overview($layer)['metrics'][$key];
                self::assertNull($metric['value']);
                self::assertSame($key, $metric['key']);
                self::assertSame('unverified', $metric['status']);
                self::assertSame('metric_scope_mismatch', $metric['reason']);
            }
        }
    }

    public function testExplicitEmptyFailureKeepsItsCauseButOutgoingIdentityIsStable(): void
    {
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            foreach (['collection_failed', 'partial', 'missing', 'unverified', 'empty_confirmed'] as $status) {
                $layer = $this->layer();
                $layer['analysis_metrics'][$key]['key'] = 'foreign_metric';
                $layer['analysis_metrics'][$key]['value'] = null;
                $layer['analysis_metrics'][$key]['status'] = $status;
                $layer['analysis_metrics'][$key]['reason'] = 'FIELD_MISSING';
                $metric = $this->overview($layer)['metrics'][$key];
                self::assertSame($key, $metric['key']);
                self::assertNull($metric['value']);
                self::assertSame($status, $metric['status']);
                self::assertSame('FIELD_MISSING', $metric['reason']);
            }
        }
    }

    public function testRestoringIdentityRecoversExactNumbersWithoutChangingTheProof(): void
    {
        foreach (['ota_room_revenue' => [125.45, '¥125.45'], 'ota_room_nights' => [2.0, '2'],
            'ota_adr' => [125.45, '¥125.45']] as $key => [$value, $display]) {
            $layer = $this->layer();
            $layer['analysis_metrics'][$key]['key'] = 'foreign_metric';
            self::assertNull($this->overview($layer)['metrics'][$key]['value']);
            $layer['analysis_metrics'][$key]['key'] = $key;
            $layer['analysis_metrics'][$key]['value'] = (string)$value;
            $metric = $this->overview($layer)['metrics'][$key];
            self::assertSame($key, $metric['key']);
            self::assertSame($value, $metric['value']);
            self::assertSame($display, $metric['display']);
            self::assertSame('ok', $metric['status']);
            self::assertSame($layer['analysis_metrics'][$key]['truth'], $metric['truth']);
        }
    }

    public function testIdentityMismatchExplainsIndicatorNamesAndTheRecoveryStep(): void
    {
        $layer = $this->layer();
        $layer['analysis_metrics']['ota_room_revenue']['key'] = 'ota_room_nights';
        $metric = $this->overview($layer)['metrics']['ota_room_revenue'];
        self::assertStringContainsString('指标名称', $metric['display_reason']);
        self::assertStringContainsString('指标名称', $metric['next_action']);
        self::assertStringContainsString('精确回读', $metric['next_action']);
        self::assertSame('online-data', $metric['target_page']);
        self::assertSame('data-health', $metric['target_tab']);
    }

    private function fixture(string $method, mixed ...$args): mixed
    {
        $fixture = new RevenueAiCanonicalMetricUnitScopeTest('testCanonicalProducerUnitsAndVerifiedZeroKeepTheirExactMeaning');
        return (new \ReflectionMethod($fixture, $method))->invoke($fixture, ...$args);
    }

    private function layer(): array { return $this->fixture('layer'); }
    private function overview(array $layer): array { return $this->fixture('overview', $layer); }
}
