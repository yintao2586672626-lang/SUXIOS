<?php
declare(strict_types=1);

use app\service\CtripOrderAnalysisService;
use app\service\CtripOrderExportImportService;
use PHPUnit\Framework\TestCase;

final class CtripOrderAuditRegressionTest extends TestCase
{
    public function testSimilarBrandAndUnverifiedBranchNamesAreRejected(): void
    {
        foreach ([
            ['南京金陵饭店', '南京金陵饭店河西店', '南京'],
            ['南京金陵饭店', '南京金陵饭店（河西店）', '南京'],
            ['南京金陵饭店', '南京金陵饭店旗舰店', '南京'],
            ['南京金陵饭店', '南京金陵饭店分店', '南京'],
            ['南京金陵饭店河西店', '南京金陵饭店河西店二店', '南京'],
            ['桂林漓江望月', '漓江望月•Quiet Holiday 湖畔酒店(桂林两江四湖象鼻山景区店)', '桂林'],
        ] as [$target, $fileHotel, $city]) {
            $caught = null;
            try {
                (new CtripOrderExportImportService())->normalizeRows([
                    $this->order(['酒店名称' => $fileHotel, '城市' => $city]),
                ], ['system_hotel_id' => 80, 'hotel_name' => $target]);
            } catch (RuntimeException $error) {
                $caught = $error;
            }
            self::assertInstanceOf(RuntimeException::class, $caught, 'Unverified branch must not match: ' . $fileHotel);
            self::assertSame(422, $caught->getCode());
            self::assertStringContainsString('酒店与所选酒店不一致', $caught->getMessage());
        }
    }

    public function testCityPunctuationAndGenericHotelDescriptionRemainCompatible(): void
    {
        $rows = (new CtripOrderExportImportService())->normalizeRows([
            $this->order(['酒店名称' => '漓江望月·湖畔酒店（桂林）', '城市' => '桂林']),
        ], ['system_hotel_id' => 80, 'hotel_name' => '桂林漓江望月']);
        self::assertCount(1, $rows);
        self::assertSame('matched_to_selected_system_hotel', $rows[0]['raw_data']['hotel_identity_status']);
    }

    public function testZeroPricedNightsSurvivePersistedRoundTripAndContributeToAdr(): void
    {
        $rows = $this->normalize([
            $this->order(['底价' => 0]),
            $this->order(['订单号' => 'AUDIT-B', '入住日期' => '2026-08-02', '离店日期' => '2026-08-03', '底价' => 100]),
        ]);
        self::assertSame([1.0, 1.0], array_column(array_column($rows, 'raw_data'), 'bottom_price_room_nights'));
        $summary = $this->analyze($rows)['summary'];
        self::assertSame(2, $summary['active_orders']);
        self::assertSame(2.0, $summary['room_nights']);
        self::assertSame(100.0, $summary['reference_bottom_price_total']);
        self::assertSame(50.0, $summary['reference_bottom_price_adr']);
        self::assertSame(1.0, $summary['reference_bottom_price_coverage_rate']);
    }

    public function testOldCompletePriceCoverageCanUseExactStoredTotalNights(): void
    {
        $rows = $this->normalize([
            $this->order(['底价' => 0]),
            $this->order(['订单号' => 'AUDIT-B', '入住日期' => '2026-08-02', '底价' => 100]),
        ]);
        foreach ($rows as &$row) unset($row['raw_data']['bottom_price_room_nights']);
        unset($row);
        self::assertSame(50.0, $this->analyze($rows)['summary']['reference_bottom_price_adr']);
    }

    public function testOldPartialCoverageDoesNotReconstructMissingNightsFromAdr(): void
    {
        $rows = $this->normalize([
            $this->order(['底价' => 100]),
            $this->order(['订单号' => 'AUDIT-B', '底价' => '', '晚数' => 3]),
            $this->order(['订单号' => 'AUDIT-C', '入住日期' => '2026-08-02', '底价' => 200]),
        ]);
        unset($rows[0]['raw_data']['bottom_price_room_nights']);
        foreach ($rows[0]['raw_data']['room_type_metrics'] as &$roomType) unset($roomType['bottom_price_room_nights']);
        unset($roomType);
        $analysis = $this->analyze($rows);
        self::assertSame(300.0, $analysis['summary']['reference_bottom_price_total']);
        self::assertSame(5.0, $analysis['summary']['room_nights']);
        self::assertEqualsWithDelta(2 / 3, $analysis['summary']['reference_bottom_price_coverage_rate'], 0.000001);
        self::assertNull($analysis['summary']['reference_bottom_price_adr']);
        self::assertContains('bottom_price_room_nights', array_column($analysis['missing_dimensions'], 'key'));
        self::assertNull($analysis['room_types']['rows'][0]['reference_bottom_price_adr']);
    }

    public function testMissingNightsDoNotEraseKnownOrderCountsOrCancellationRate(): void
    {
        $analysis = $this->analyze($this->normalize([
            $this->order(['晚数' => '']),
            $this->order(['订单号' => 'AUDIT-B', '订单状态' => '已取消']),
            $this->order(['订单号' => 'AUDIT-C', '预订网站' => '去哪儿']),
        ]));
        $summary = $analysis['summary'];
        self::assertSame(3, $summary['gross_orders']);
        self::assertSame(2, $summary['active_orders']);
        self::assertSame(1, $summary['cancelled_orders']);
        self::assertSame(0, $summary['unknown_status_orders']);
        self::assertEqualsWithDelta(1 / 3, $summary['cancel_rate'], 0.000001);
        self::assertNull($summary['room_nights']);
        self::assertNull($summary['reference_bottom_price_adr']);
        $channels = array_column($analysis['channels'], null, 'key');
        self::assertSame(1, $channels['ctrip']['active_orders']);
        self::assertSame(0.5, $channels['ctrip']['cancel_rate']);
        self::assertNull($channels['ctrip']['room_nights']);
        self::assertSame(1.0, $channels['qunar']['room_nights']);
    }

    public function testMissingOneCountDoesNotEraseOtherCountsOrKnownNights(): void
    {
        $rows = $this->normalize([$this->order()]);
        unset($rows[0]['gross_order_num'], $rows[0]['raw_data']['gross_order_num']);
        $summary = $this->analyze($rows)['summary'];
        self::assertNull($summary['gross_orders']);
        self::assertSame(1, $summary['active_orders']);
        self::assertSame(0, $summary['cancelled_orders']);
        self::assertSame(1.0, $summary['room_nights']);
        self::assertNull($summary['cancel_rate']);
    }

    private function order(array $overrides = []): array
    {
        return array_replace([
            '酒店名称' => '南京金陵饭店', '城市' => '南京', '订单号' => 'AUDIT-A',
            '订单状态' => '已入住', '入住日期' => '2026-08-01', '离店日期' => '2026-08-02',
            '晚数' => 1, '房间数' => 1, '币种' => 'CNY', '底价' => 100,
            '预订网站' => '携程', '房型名称' => '大床房',
        ], $overrides);
    }

    private function normalize(array $orders): array
    {
        return (new CtripOrderExportImportService())->normalizeRows($orders, [
            'system_hotel_id' => 80, 'hotel_name' => '南京金陵饭店',
        ]);
    }

    private function analyze(array $rows): array
    {
        // Exercise the saved-row envelope without connecting to a business DB.
        $stored = array_map(static fn(array $row): array => [
            'system_hotel_id' => 80, 'source' => 'ctrip', 'platform' => 'ctrip',
            'readback_verified' => 1,
            'raw_data' => json_encode(['row' => $row], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        ], $rows);
        return (new CtripOrderAnalysisService())->analyzeRows($stored, 80);
    }
}
