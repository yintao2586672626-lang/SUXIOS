<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationBusinessPayloadService;
use app\service\ManualNotificationBusinessPreviewService;
use app\service\RevenueFactLayerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure public service projections: no application initialization or database. */
final class ManualNotificationPmsAdrAvailabilityTest extends TestCase
{
    private const PROVIDER = 'meituan_cloud_pms';
    private const ADR_REASON = 'pms_sold_room_nights_denominator_zero';
    private const SECTIONS = ['today_revenue_management', 'daily_review'];

    public static function emptyCaptureCases(): array
    {
        return [
            'reported ADR zero' => ['empty_zero_adr'],
            'reported ADR positive' => ['empty_reported_adr'],
        ];
    }

    #[DataProvider('emptyCaptureCases')]
    public function testZeroDenominatorOnlyRemovesAdrFromTheNotificationPreview(string $captureKey): void
    {
        $preview = $this->preview($this->layer($captureKey));
        foreach (self::SECTIONS as $type) {
            $section = $preview['sections'][$type];
            foreach ([
                'pms_room_fee' => 0,
                'pms_sold_room_nights' => 0,
                'pms_sellable_room_nights' => 12,
                'pms_remaining_sellable_room_nights' => 12,
                'pms_occupancy_rate' => 0,
                'pms_revpar' => 0,
            ] as $key => $expected) {
                $field = $this->field($section, $key);
                self::assertSame('available', $field['status'], "$captureKey / $type / $key");
                self::assertNotNull($field['value'], "$type / $key must not disappear with ADR");
                self::assertSame((float)$expected, (float)$field['value']);
            }
            $adr = $this->field($section, 'pms_adr');
            self::assertNull($adr['value']);
            self::assertSame('not_calculable', $adr['status']);
            self::assertMatchesRegularExpression('/(?:已售|出租).*房晚.*0.*ADR.*不可计算/u', (string)($adr['note'] ?? ''));
            self::assertContains(self::ADR_REASON, array_column($section['gaps'], 'code'));
            self::assertNotContains(
                self::PROVIDER . '_today_capture_readback_not_verified',
                array_column($section['gaps'], 'code')
            );
            $source = $section['message_data']['sources'][self::PROVIDER];
            self::assertSame('readback_verified', $source['data_status']);
            self::assertNull($source['facts']['adr']);
            self::assertSame('not_calculable', $source['fact_statuses']['adr']['status']);
            self::assertSame(self::ADR_REASON, $source['fact_statuses']['adr']['reason']);
            self::assertSame(0.0, (float)$source['facts']['room_revenue']);
            self::assertSame(12.0, (float)$source['facts']['sellable_room_nights']);
        }
    }

    #[DataProvider('emptyCaptureCases')]
    public function testZeroDenominatorBlocksBothMessageTypesWithAnAccurateReason(string $captureKey): void
    {
        $preview = $this->preview($this->layer($captureKey));
        foreach (self::SECTIONS as $type) {
            $result = $this->payload($preview, $type);
            self::assertSame('blocked', $result['status'], "$captureKey / $type");
            self::assertNull($result['payload']);
            self::assertSame('business_message_pms_adr_not_calculable', $result['reason_code']);
            self::assertFalse($result['formal_send_gate']['allowed']);
            self::assertMatchesRegularExpression(
                '/(?:已售|出租).*房晚.*0.*ADR.*不可计算/u',
                (string)$result['formal_send_gate']['blockers'][0]['message']
            );
            $facts = $result['fact_envelope']['facts'][self::PROVIDER];
            self::assertNull($facts['adr']);
            self::assertNotNull($facts['room_revenue']);
            self::assertSame(0.0, (float)$facts['room_revenue']);
            self::assertSame(12.0, (float)$facts['sellable_room_nights']);
        }
    }

    public function testOccupiedRoomsWithNoRevenueKeepTheGenuineZeroAdr(): void
    {
        $preview = $this->preview($this->layer('occupied_zero_revenue'));
        foreach (self::SECTIONS as $type) {
            $section = $preview['sections'][$type];
            $adr = $this->field($section, 'pms_adr');
            self::assertSame('available', $adr['status']);
            self::assertNotNull($adr['value']);
            self::assertSame(0.0, (float)$adr['value']);
            self::assertSame(6.0, (float)$this->field($section, 'pms_sold_room_nights')['value']);
            self::assertNotContains(self::ADR_REASON, array_column($section['gaps'], 'code'));
            $result = $this->payload($preview, $type);
            self::assertSame('ready', $result['status']);
            self::assertStringContainsString('ADR｜¥0.00', $result['payload']['markdown']['content']);
        }
    }

    public function testOrdinaryMissingAdrDoesNotReceiveTheZeroDenominatorException(): void
    {
        $layer = $this->layer('occupied_normal');
        $source =& $layer['sources'][self::PROVIDER];
        $source['facts']['adr'] = null;
        $source['fact_statuses']['adr'] = ['status' => 'missing', 'reason' => 'capture_adr_field_missing'];
        $preview = $this->preview($layer);
        foreach (self::SECTIONS as $type) {
            $section = $preview['sections'][$type];
            self::assertNull($this->field($section, 'pms_adr')['value']);
            self::assertNotContains(self::ADR_REASON, array_column($section['gaps'], 'code'));
            $result = $this->payload($preview, $type);
            self::assertSame('blocked', $result['status']);
            self::assertNull($result['payload']);
            self::assertNotSame('business_message_pms_adr_not_calculable', $result['reason_code']);
        }
    }

    public static function wrongSourceCases(): array
    {
        return [
            'wrong hotel' => ['system_hotel_id', 81],
            'wrong provider table' => ['table', 'dingdandao_operating_target_captures'],
        ];
    }

    #[DataProvider('wrongSourceCases')]
    public function testZeroDenominatorNeverRelaxesSourceIdentity(string $field, mixed $value): void
    {
        $layer = $this->layer('empty_reported_adr');
        $layer['sources'][self::PROVIDER]['source'][$field] = $value;
        $preview = $this->preview($layer);
        foreach (self::SECTIONS as $type) {
            $section = $preview['sections'][$type];
            foreach (['pms_adr', 'pms_room_fee', 'pms_sold_room_nights', 'pms_sellable_room_nights'] as $key) {
                self::assertNull($this->field($section, $key)['value'], "$field / $type / $key");
            }
            self::assertNotSame('readback_verified', $section['message_data']['sources'][self::PROVIDER]['data_status']);
            self::assertContains(self::PROVIDER . '_today_capture_readback_not_verified', array_column($section['gaps'], 'code'));
            $result = $this->payload($preview, $type);
            self::assertSame('blocked', $result['status']);
            self::assertNull($result['payload']);
            self::assertSame('business_message_meituan_cloud_pms_not_verified', $result['reason_code']);
        }
    }

    private function fixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/fixtures/revenue-fact-layer/pms-adr-denominator-captures.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function layer(string $captureKey): array
    {
        $fixture = $this->fixture();
        return (new RevenueFactLayerService(
            hotelLoader: static fn(): array => $fixture['hotel'],
            pmsLoader: static fn(): array => $fixture['captures'][$captureKey],
            otaLoader: static fn(): array => ['data_status' => 'missing', 'rows' => []],
            pricingGuardLoader: static fn(): array => [],
            pmsBindingLoader: static fn(): array => [
                'binding_status' => 'configured', 'selected_provider' => self::PROVIDER,
            ]
        ))->build(80, $fixture['business_date'], ['isolated_no_ota_database' => []]);
    }

    private function preview(array $layer): array
    {
        $fixture = $this->fixture();
        return ManualNotificationBusinessPreviewService::buildPreview(
            $fixture['hotel'], $fixture['business_date'], null, [], [], [], [], $layer
        );
    }

    private function payload(array $preview, string $type): array
    {
        $fixture = $this->fixture();
        return (new ManualNotificationBusinessPayloadService(
            static fn(string $sectionType): array => [
                'contract_version' => $preview['contract_version'],
                'hotel' => $preview['hotel'],
                'business_date' => $preview['business_date'],
                'section' => $preview['sections'][$sectionType],
            ]
        ))->pagePreview(8, 80, $fixture['hotel']['name'], $fixture['business_date'], $type);
    }

    private function field(array $section, string $key): array
    {
        $fields = array_column($section['facts'], null, 'key');
        self::assertArrayHasKey($key, $fields);
        return $fields[$key];
    }
}
