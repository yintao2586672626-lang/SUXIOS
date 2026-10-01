<?php
declare(strict_types=1);

use app\service\CtripOrderAnalysisService;
use PHPUnit\Framework\TestCase;

final class CtripLegacyOrderHistoryTest extends TestCase
{
    public function testContractlessSavedAggregateIsReadableWithoutPretendingToBeV1(): void
    {
        $row = $this->storedLegacyRow();
        $before = $row;
        $result = (new CtripOrderAnalysisService())->analyzeRows([$row], 80, '2026-08-01', '2026-08-31');
        self::assertSame('available_partial', $result['status']);
        self::assertNull($result['batch']['import_contract']);
        self::assertSame('ctrip_order_legacy_saved_aggregate', $result['batch']['read_adapter']);
        self::assertSame('verified', $result['persistence_readback_status']);
        self::assertSame(2, $result['summary']['active_orders']);
        self::assertSame(4.0, $result['summary']['room_nights']);
        self::assertSame('2026-08-08', $result['date_range']['from']);
        self::assertNull($result['summary']['amount']);
        self::assertSame('evidence_missing', $result['distributions']['los']['status']);
        self::assertSame('test_fixture', $result['quality_status']);
        self::assertStringContainsString('测试样例', $result['quality_label']);
        self::assertSame($before, $row, 'Read adaptation must not modify stored facts.');
        self::assertStringNotContainsString('PRIVATE_TEST_SENTINEL', json_encode($result));
    }

    public function testLegacyReadRespectsHotelAndDateFilters(): void
    {
        $service = new CtripOrderAnalysisService();
        self::assertSame('no_data', $service->analyzeRows([$this->storedLegacyRow()], 81)['status']);
        self::assertSame('no_data', $service->analyzeRows([$this->storedLegacyRow()], 80, '2026-07-01', '2026-07-31')['status']);
        self::assertSame('available_partial', $service->analyzeRows([$this->storedLegacyRow()], 80)['status']);
    }

    public function testLegacyReadbackMustBeVerified(): void
    {
        $row = $this->storedLegacyRow();
        $row['readback_verified'] = 0;
        self::assertSame('indeterminate', (new CtripOrderAnalysisService())->analyzeRows([$row], 80)['status']);
    }

    public function testWrapperScopeCannotBeOverriddenByNestedLegacyPayload(): void
    {
        foreach (['source' => 'meituan', 'platform' => 'meituan', 'system_hotel_id' => 81] as $key => $value) {
            $row = $this->storedLegacyRow();
            $row[$key] = $value;
            self::assertSame('no_data', (new CtripOrderAnalysisService())->analyzeRows([$row], 80)['status'], $key);
        }
    }

    public function testUnknownContractsAndGenericRawOrdersAreNotTreatedAsLegacyAggregates(): void
    {
        foreach ([['import_contract', 'unknown_future_contract'], ['pii_policy', 'unknown']] as [$key, $value]) {
            $row = $this->storedLegacyRow();
            $row['raw_data']['row']['raw_data'][$key] = $value;
            self::assertSame('no_data', (new CtripOrderAnalysisService())->analyzeRows([$row], 80)['status']);
        }
    }

    public function testNonFixtureLegacyHistoryKeepsSourceUnverified(): void
    {
        $row = $this->storedLegacyRow();
        unset($row['raw_data']['row']['raw_data']['fixture_status']);
        $result = (new CtripOrderAnalysisService())->analyzeRows([$row], 80);
        self::assertSame('available_partial', $result['status']);
        self::assertSame('user_provided_unverified', $result['quality_status']);
    }

    private function storedLegacyRow(): array
    {
        return [
            'id' => 100, 'tenant_id' => 1, 'system_hotel_id' => 80,
            'source' => 'ctrip', 'platform' => 'ctrip', 'data_type' => 'order',
            'data_date' => '2026-08-08', 'readback_verified' => 1,
            'sync_task_id' => 10, 'ingestion_method' => 'manual',
            'raw_data' => ['row' => [
                'system_hotel_id' => 80, 'platform' => 'ctrip', 'source' => 'ctrip',
                'data_type' => 'order', 'data_date' => '2026-08-08',
                'gross_order_num' => 2, 'book_order_num' => 2,
                'cancel_order_num' => 0, 'unknown_status_order_num' => 0,
                'quantity' => 4, 'amount' => null, 'avg_los' => 2,
                'raw_data' => [
                    'channel_key' => 'ctrip', 'channel_label' => '携程',
                    'gross_order_num' => 2, 'active_order_num' => 2, 'room_nights' => 4,
                    'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
                    'pii_policy' => 'guest_name_and_raw_order_id_excluded',
                    'fixture_status' => 'explicit_test_fixture',
                    'business_date_basis' => 'stay_date', 'snapshot_hash' => str_repeat('a', 64),
                    'arbitrary_raw_field' => 'PRIVATE_TEST_SENTINEL',
                ],
            ]],
        ];
    }
}
