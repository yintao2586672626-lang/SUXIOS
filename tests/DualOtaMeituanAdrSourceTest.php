<?php
declare(strict_types=1);

use app\controller\OnlineData;
use app\service\AiDailyReportBroadcastFactService;
use app\service\DualOtaFieldClosureService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DualOtaMeituanAdrSourceTest extends TestCase
{
    public function testActualNormalizedQualityKeepsAdrBlockedButIdentifiesItsRealCandidateSource(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/dual-ota/meituan-adr-normalized.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['normal', 'normal'], array_column($fixture['rows'], 'validation_status'));
        $closure = DualOtaFieldClosureService::evaluate(['id'=>80,'tenant_id'=>7],'2026-08-23',$fixture['rows'],$fixture['trust']);
        $adr = array_column($closure['platforms']['meituan']['fields'],null,'key')['adr'];
        self::assertNull($adr['value']);
        self::assertSame('caliber_uncertain',$adr['status']);
        self::assertFalse($adr['revenue_analysis_consumable']);
        self::assertSame([101874],array_column($adr['observed_values'],'source_record_id'));
        self::assertContains('online_daily_data#101874',$adr['source_record_refs']);
    }

    public function testZeroAdrUsesTheBusinessRowThatActuallySuppliesItsPositiveDenominator(): void
    {
        $rows = self::capturedRows();
        self::assertSame(3, $rows[0]['quantity']);
        self::assertSame(0, $rows[1]['quantity']);
        self::assertSame(0.0, $rows[0]['amount']);
        $before = $rows;
        $closure = self::closure($rows);
        $adr = array_column($closure['platforms']['meituan']['fields'], null, 'key')['adr'];
        self::assertSame('verified_calculation', $adr['status']);
        self::assertSame(0.0, $adr['value']);
        self::assertSame(['online_daily_data#101874'], $adr['source_record_refs']);
        self::assertSame('business_card_amount / room_nights', $adr['basis']);
        self::assertTrue($adr['revenue_analysis_consumable']);
        $facts = (new AiDailyReportBroadcastFactService(
            hotelReader: static fn(): array => ['id' => 80, 'tenant_id' => 7],
            closureReader: static fn(): array => $closure
        ))->build(80, '2026-08-23');
        self::assertSame($adr['source_record_refs'], $facts['platforms']['meituan']['fields']['adr']['source_record_refs']);
        self::assertSame(0.0, $facts['platforms']['meituan']['fields']['adr']['value']);
        self::assertSame($before, $rows, 'capture records remain immutable');
    }

    #[DataProvider('notFinal')]
    public function testOrderVerificationCannotPromoteANonFinalBusinessAdr(string $field, string $value): void
    {
        $rows = self::capturedRows();
        $rows[0][$field] = $value;
        $adr = array_column(self::closure($rows)['platforms']['meituan']['fields'], null, 'key')['adr'];
        self::assertNull($adr['value']);
        self::assertSame('caliber_uncertain', $adr['status']);
        self::assertFalse($adr['revenue_analysis_consumable']);
        self::assertSame(['online_daily_data#101874'], $adr['source_record_refs']);
        self::assertSame([101874], array_column($adr['observed_values'], 'source_record_id'));
    }
    public static function notFinal(): array
    {
        return [['validation_status', 'normal'], ['history_status', 'partial']];
    }

    #[DataProvider('wrongScope')]
    public function testIneligibleBusinessRowsCannotSupplyAdr(string $field, mixed $value): void
    {
        $rows = self::capturedRows();
        $rows[0][$field] = $value;
        $adr = array_column(self::closure($rows)['platforms']['meituan']['fields'], null, 'key')['adr'];
        self::assertNull($adr['value']);
        self::assertFalse($adr['revenue_analysis_consumable']);
    }
    public static function wrongScope(): array
    {
        return [['system_hotel_id', 81], ['tenant_id', 8], ['data_date', '2026-08-22'],
            ['sync_task_id', 4000], ['readback_verified', 0]];
    }

    public function testNormalOrderAndTrueZeroOrderAdrKeepOrderReferences(): void
    {
        foreach ([0.0, 600.0] as $amount) {
            $rows = self::capturedRows(3, 3, $amount);
            $adr = array_column(self::closure($rows)['platforms']['meituan']['fields'], null, 'key')['adr'];
            self::assertSame('verified_calculation', $adr['status']);
            self::assertSame($amount / 3, $adr['value']);
            self::assertSame(['online_daily_data#101931'], $adr['source_record_refs']);
        }
    }

    public function testMissingDenominatorAndRecoveryNeverBorrowTheOtherRowIdentity(): void
    {
        $rows = self::capturedRows(0, 0);
        $fields = array_column(self::closure($rows)['platforms']['meituan']['fields'], null, 'key');
        self::assertNull($fields['adr']['value']);
        self::assertSame(0.0, $fields['revenue']['value']);
        $rows = self::capturedRows(3, 0);
        $fields = array_column(self::closure($rows)['platforms']['meituan']['fields'], null, 'key');
        self::assertSame(0.0, $fields['adr']['value']);
        self::assertSame(['online_daily_data#101874'], $fields['adr']['source_record_refs']);
    }

    /** Actual capture normalizer, then explicit synthetic persisted-row metadata. */
    public static function capturedRows(int $businessNights = 3, int $orderNights = 0, float $amount = 0.0): array
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $rows = (new ReflectionMethod($controller, 'buildMeituanCapturedDailyRows'))->invoke($controller, [
            'storeId' => 'synthetic-store', 'poiId' => 'synthetic-poi', 'poiName' => 'Synthetic hotel',
            'defaultDataDate' => '2026-08-23',
            'business' => [['_capture_source' => 'xhr:traffic:business_data', 'data_date' => '2026-08-23',
                'sales_room_nights' => $businessNights, 'sales_amount' => $amount]],
            'orders' => [['_capture_source' => 'xhr:orders:daily_summary', 'data_date' => '2026-08-23',
                'room_nights' => $orderNights, 'order_count' => $orderNights, 'total_amount' => $amount]],
        ], 80);
        self::assertCount(2, $rows);
        foreach ($rows as &$row) {
            $row = array_replace($row, ['id' => $row['data_type'] === 'business' ? 101874 : 101931,
                'tenant_id' => 7, 'data_source_id' => 101, 'sync_task_id' => 4352,
                'data_period' => 'realtime_snapshot', 'snapshot_time' => '2026-08-23 23:50:54',
                'history_status' => 'success', 'validation_status' => 'verified', 'validation_flags' => '[]',
                'readback_verified' => 1]);
        }
        unset($row);
        return $rows;
    }

    public static function closure(array $rows): array
    {
        return DualOtaFieldClosureService::evaluate(['id' => 80, 'tenant_id' => 7], '2026-08-23', $rows,
            ['days' => [['date' => '2026-08-23', 'platforms' => [[
                'platform' => 'meituan', 'acceptance_status' => 'partial', 'target_date' => '2026-08-23',
                'steps' => ['source' => true, 'account_profile_binding' => true, 'hotel' => true, 'date' => true],
                'acceptance_receipt' => ['status' => 'partial', 'target_date' => '2026-08-23', 'target_date_status' => 'matched',
                    'platform_hotel_status' => 'verified', 'data_source_id' => 101, 'sync_task_id' => 4352,
                    'sync_task_status' => 'partial_success', 'data_period' => 'realtime_snapshot',
                    'run_readback_scope' => ['status' => 'verified', 'receipt_record_ids' => [101874, 101931], 'accepted_record_ids' => [101874, 101931]],
                ],
            ]]]]]);
    }
}
