<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripOrderAnalysisService;
use app\service\CtripOrderExportImportService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;

final class OtaUnknownCurrencyBoundaryTest extends TestCase
{
    private function revenueRow(int $id, array $evidence): array
    {
        return [
            'id' => $id, 'system_hotel_id' => 100, 'hotel_id' => 'fixture-ota100',
            'source' => 'ctrip', 'source_method' => 'browser_capture',
            'data_type' => 'order', 'data_date' => '2026-09-03',
            'data_period' => 'final', 'is_final' => 1, 'compare_type' => 'self',
            'dimension' => 'fixture-currency:' . $id,
            'amount' => 280, 'room_revenue' => 280, 'quantity' => 1,
            'book_order_num' => 1, 'order_count_basis' => 'active_non_cancelled_orders',
            'source_trace_id' => 'fixture:currency:' . $id,
            'status' => 'success', 'validation_status' => 'verified',
            'readback_verified' => 1, 'collected_at' => '2026-09-04 10:00:00',
            'create_time' => '2026-09-04 10:00:00', 'update_time' => '2026-09-04 10:00:00',
            'raw_data' => $evidence + ['record_kind' => 'channel_daily_aggregate'],
        ];
    }

    private function summarize(array $rows): array
    {
        return (new OtaRevenueMetricService())->summarizeDataset(
            (new OtaStandardEtlService())->buildDatasetFromRows($rows)
        );
    }

    public function testExplicitUnknownOrUnsupportedMoneyNeverBecomesTrustedCny(): void
    {
        foreach ([
            ['currency' => null, 'currency_status' => 'missing_source_currency', 'amount_storage_unit' => 'unknown'],
            ['currency' => 'unknown'],
            ['currency' => 'USD', 'amount_storage_unit' => 'yuan'],
            ['currency' => 'CNY', 'amount_storage_unit' => 'fen'],
        ] as $evidence) {
            $row = $this->revenueRow(991061, $evidence);
            $original = $row;
            $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([$row]);
            $fact = $dataset['fact_ota_daily'][0];
            self::assertNull($fact['revenue']);
            self::assertNull($fact['room_revenue']);
            self::assertNull($fact['adr']);
            self::assertSame(1.0, $fact['room_nights']);
            self::assertSame(1, $fact['order_count']);
            self::assertNotEmpty($dataset['data_quality']['monetary_unit_gaps']);
            self::assertSame($original, $row, 'The source record is not rewritten.');
            self::assertSame($original['raw_data'], $fact['raw_data']);

            $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            self::assertNull($metrics['totals']['revenue']);
            self::assertNull($metrics['totals']['adr']);
            self::assertFalse($metrics['metric_trust']['totals.revenue']['saved_success']);
            self::assertFalse($metrics['metric_trust']['totals.adr']['saved_success']);
            self::assertTrue($metrics['metric_trust']['totals.room_nights']['saved_success']);
            self::assertNull($metrics['p1_revenue_closure']['sections']['revenue']['value']);
            self::assertNotSame('ok', $metrics['p1_revenue_closure']['sections']['revenue']['status']);
        }
    }

    public function testUnknownStoredWrapperCannotTurnKnownSubsetIntoACompleteSummary(): void
    {
        $known = $this->revenueRow(991062, ['currency' => 'CNY', 'amount_storage_unit' => 'yuan']);
        $unknown = $this->revenueRow(991063, []);
        $unknown['raw_data'] = ['row' => ['raw_data' => [
            'currency_status' => 'missing_source_currency', 'amount_storage_unit' => 'unknown',
        ]]];
        $metrics = $this->summarize([$known, $unknown]);
        self::assertNull($metrics['totals']['revenue']);
        self::assertNull($metrics['totals']['room_revenue']);
        self::assertNull($metrics['totals']['adr']);
        self::assertSame(2.0, $metrics['totals']['room_nights']);
        self::assertSame(2, $metrics['totals']['order_count']);
        self::assertNull($metrics['by_platform'][0]['revenue']);
        self::assertNull($metrics['by_platform'][0]['adr']);
        self::assertContains('missing_source_currency', array_column($metrics['data_gaps'], 'code'));
        self::assertContains('missing_source_currency', $metrics['metric_trust']['totals.revenue']['failure_reasons']);
        self::assertFalse($metrics['credibility_gate']['decision_use']['revenue_analysis']['allowed']);
    }

    public function testDeclaredYuanAndUnlabelledLegacyRowsKeepTheirNumericCompatibility(): void
    {
        foreach ([[], ['currency' => 'CNY', 'currency_status' => 'source_declared', 'amount_storage_unit' => 'yuan']] as $evidence) {
            $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([$this->revenueRow(991064, $evidence)]);
            $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            self::assertSame(280.0, $metrics['totals']['revenue']);
            self::assertSame(280.0, $metrics['totals']['adr']);
            self::assertTrue($metrics['metric_trust']['totals.revenue']['saved_success']);
            if ($evidence === []) {
                self::assertSame('legacy_unspecified', $dataset['fact_ota_daily'][0]['monetary_unit_evidence']['currency_status']);
                self::assertNull($dataset['fact_ota_daily'][0]['monetary_unit_evidence']['currency']);
            }
        }
    }

    private function importedRows(string $currency): array
    {
        $rows = (new CtripOrderExportImportService())->normalizeRows([[
            '订单号' => 'fixture-currency-import', '订单状态' => '已入住',
            '入住日期' => '2026-09-02', '离店日期' => '2026-09-03',
            '预订时间' => '2026-09-01 10:00:00', '晚数' => 1, '房间数' => 1,
            '底价' => 280, '币种' => $currency, '预订网站' => '携程', '房型名称' => 'fixture-room',
        ]], ['system_hotel_id' => 100, 'test_fixture' => true, 'observed_at' => '2026-09-04 10:00:00']);
        return array_map(static fn(array $row): array => array_replace($row, ['_readback_verified' => true]), $rows);
    }

    public function testImportReferenceAnalysisRetainsUnknownCurrencyAndCannotPriceIt(): void
    {
        $rows = $this->importedRows('');
        $original = $rows;
        $analysis = (new CtripOrderAnalysisService())->analyzeRows($rows, 100, '2026-09-02', '2026-09-02');
        self::assertSame($original, $rows);
        self::assertSame(1, $analysis['summary']['active_orders']);
        self::assertSame(1.0, $analysis['summary']['room_nights']);
        self::assertNull($analysis['summary']['reference_bottom_price_total']);
        self::assertNull($analysis['summary']['reference_bottom_price_adr']);
        self::assertNull($analysis['channels'][0]['reference_bottom_price_total']);
        self::assertNull($analysis['room_types']['rows'][0]['reference_bottom_price_total']);
        self::assertNull($analysis['room_types']['rows'][0]['reference_bottom_price_adr']);
        self::assertSame('missing_source_currency', $analysis['summary']['currency_evidence'][0]['currency_status']);
        self::assertContains('missing_source_currency', array_column($analysis['missing_dimensions'], 'key'));
    }

    public function testReferenceAnalysisPreservesLegacyNumbersWithoutInventingCurrency(): void
    {
        $rows = $this->importedRows('CNY');
        foreach ($rows as &$row) {
            unset($row['raw_data']['currency'], $row['raw_data']['currency_status'], $row['raw_data']['amount_storage_unit']);
        }
        unset($row);
        $analysis = (new CtripOrderAnalysisService())->analyzeRows($rows, 100, '2026-09-02', '2026-09-02');
        self::assertSame(280.0, $analysis['summary']['reference_bottom_price_total']);
        self::assertSame(280.0, $analysis['summary']['reference_bottom_price_adr']);
        self::assertSame('legacy_unspecified', $analysis['summary']['currency_evidence'][0]['currency_status']);
        self::assertNull($analysis['summary']['currency_evidence'][0]['currency']);
    }

    public function testReferenceKnownSubsetAndRoomTypeCannotHideAnUnsupportedUnit(): void
    {
        $rows = $this->importedRows('CNY');
        $unknown = $rows[0];
        $unknown['data_date'] = '2026-09-03';
        $unknown['raw_data']['amount_storage_unit'] = 'fen';
        $rows[] = $unknown;
        $analysis = (new CtripOrderAnalysisService())->analyzeRows($rows, 100, '2026-09-02', '2026-09-03');
        self::assertSame(2, $analysis['summary']['active_orders']);
        self::assertSame(2.0, $analysis['summary']['room_nights']);
        self::assertNull($analysis['summary']['reference_bottom_price_total']);
        self::assertNull($analysis['summary']['reference_bottom_price_adr']);
        self::assertNull($analysis['channels'][0]['reference_bottom_price_total']);
        self::assertNull($analysis['room_types']['rows'][0]['reference_bottom_price_total']);
        self::assertNull($analysis['room_types']['rows'][0]['reference_bottom_price_adr']);
        self::assertContains('unsupported_amount_storage_unit', array_column($analysis['missing_dimensions'], 'key'));
    }
}
