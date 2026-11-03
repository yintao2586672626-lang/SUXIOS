<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripOrderExportImportService;
use app\service\CtripOrderAnalysisService;
use PHPUnit\Framework\TestCase;

final class OtaCurrencyBoundaryTest extends TestCase
{
    private function row(string $id, string $currency): array
    {
        return ['订单号' => $id, '订单状态' => '已入住', '入住日期' => '2026-08-08',
            '离店日期' => '2026-08-09', '预订时间' => '2026-08-01', '晚数' => 1,
            '房间数' => 1, '房型名称' => '标准房', '币种' => $currency, '底价' => 100, '预订网站' => '携程'];
    }

    public function testForeignCurrencyCannotBeAddedToDomesticPriceReference(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(422);
        (new CtripOrderExportImportService())->normalizeRows([
            $this->row('CNY-1', 'CNY'), $this->row('USD-1', 'USD'),
        ], ['system_hotel_id' => 80, 'test_fixture' => true]);
    }

    public function testCurrencyAndUnitSurviveNormalizationWithoutInferringMissingCurrency(): void
    {
        foreach (['CNY', '人民币', ''] as $currency) {
            $rows = (new CtripOrderExportImportService())->normalizeRows([
                $this->row('TEST-1', $currency),
            ], ['system_hotel_id' => 80, 'test_fixture' => true]);
            $raw = $rows[0]['raw_data'];
            self::assertSame($currency === '' ? null : 'CNY', $raw['currency']);
            self::assertSame($currency === '' ? 'unknown' : 'yuan', $raw['amount_storage_unit']);
            self::assertSame('ctrip_order_currency.v1', $raw['currency_contract_version']);
            self::assertNull($rows[0]['amount'], 'Bottom price remains reference-only, never confirmed revenue.');
        }
    }

    public function testNegativeRoomCountsAreRejectedInsteadOfClampedToZero(): void
    {
        foreach (['晚数', '房间数'] as $field) {
            try {
                (new CtripOrderExportImportService())->normalizeRows([
                    array_replace($this->row('TEST-1', 'CNY'), [$field => -1]),
                ], ['system_hotel_id' => 80, 'test_fixture' => true]);
                self::fail('Negative counts must not become an apparently valid zero.');
            } catch (\RuntimeException $error) {
                self::assertSame(422, $error->getCode());
            }
        }
    }

    public function testMalformedAmountIsAnExplicitInvalidValueRatherThanAnInventedAmount(): void
    {
        $rows = (new CtripOrderExportImportService())->normalizeRows([
            array_replace($this->row('TEST-1', 'CNY'), ['底价' => '1,2']),
        ], ['system_hotel_id' => 80, 'test_fixture' => true]);
        self::assertNull($rows[0]['raw_data']['bottom_price_sum']);
        self::assertSame(1, $rows[0]['raw_data']['bottom_price_invalid_order_count']);
    }

    public function testMissingRoomNightsCannotBecomeZeroOrInflatePriceAverage(): void
    {
        $rows = (new CtripOrderExportImportService())->normalizeRows([
            $this->row('VALID', 'CNY'),
            array_replace($this->row('MISSING', 'CNY'), ['晚数' => '']),
            array_replace($this->row('OTHER-DAY', 'CNY'), ['入住日期' => '2026-08-09', '离店日期' => '2026-08-10']),
        ], ['system_hotel_id' => 80, 'test_fixture' => true]);
        self::assertNull($rows[0]['quantity']);
        self::assertNull($rows[0]['bottom_price_adr']);
        self::assertSame('partial', $rows[0]['raw_data']['room_nights_completeness']);
        self::assertSame(1, $rows[0]['raw_data']['room_nights_missing_order_count']);
        self::assertSame(2, $rows[0]['book_order_num'], 'The known order count is preserved.');
        $fixtureReadbackRows = array_map(static fn(array $row): array => array_replace($row, ['_readback_verified' => true]), $rows);
        $analysis = (new CtripOrderAnalysisService())->analyzeRows($fixtureReadbackRows, 80, '2026-08-08', '2026-08-09');
        self::assertArrayHasKey('room_nights', $analysis['summary']);
        self::assertNull($analysis['summary']['room_nights']);
        self::assertNull($analysis['summary']['reference_bottom_price_adr']);
        self::assertNull($analysis['room_types']['rows'][0]['room_nights']);
        self::assertNull($analysis['room_types']['rows'][0]['reference_bottom_price_adr']);
    }
}
