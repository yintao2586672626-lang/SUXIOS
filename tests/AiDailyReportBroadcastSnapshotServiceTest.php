<?php
declare(strict_types=1);

use app\service\AiDailyReportBroadcastSnapshotService;
use PHPUnit\Framework\TestCase;

final class AiDailyReportBroadcastSnapshotServiceTest extends TestCase
{
    public function testHotel80PartialFactsAreBroadcastReadyWhileAnalysisRemainsBlocked(): void
    {
        $service = $this->service($this->hotel80Closure());
        $draft = $service->preview(80, '2026-08-23');

        self::assertSame('facts_broadcast_ready', $draft['facts_broadcast_status']);
        self::assertSame('analysis_blocked', $draft['analysis_status']);
        self::assertSame('2026-08-24 23:17:33', $draft['data_cutoff_at']);
        self::assertCount(3, $draft['facts']);
        self::assertSame(['online_daily_data#102476'], $draft['fact_refs']);
        self::assertSame(
            ['exposure', 'visits', 'conversion'],
            array_column($draft['facts'], 'metric_key')
        );
        self::assertStringContainsString('门店：敦煌漠蓝新（Hotel 80）', $draft['final_text']);
        self::assertStringContainsString('业务日期：2026-08-23', $draft['final_text']);
        self::assertStringContainsString(
            '已确认事实：美团曝光人数 1,422、商详访客 206、曝光到访率 14.49%。',
            $draft['final_text']
        );
        self::assertStringContainsString(
            '携程曝光人数事实缺失、收入口径未确认，因此暂不生成双平台竞争和收益结论。',
            $draft['final_text']
        );
        self::assertStringNotContainsString('6,461.43', $draft['final_text']);
        self::assertStringNotContainsString('7,025.14', $draft['final_text']);
        self::assertStringNotContainsString('全酒店经营', $draft['final_text']);
        self::assertFalse($draft['authorization']['wecom_send_authorized']);
        self::assertFalse($draft['authorization']['external_delivery_authorized']);
    }

    public function testNoStrictFactsReturnsCollectionFailureWithoutAdviceOrText(): void
    {
        $closure = $this->hotel80Closure();
        foreach (['ctrip', 'meituan'] as $platform) {
            $closure['platforms'][$platform]['status'] = 'collection_failed';
            $closure['platforms'][$platform]['revenue_analysis']['status'] = 'blocked';
            foreach ($closure['platforms'][$platform]['fields'] as &$field) {
                $field = [
                    'status' => 'collection_failed',
                    'value' => null,
                    'source_record_refs' => [],
                    'revenue_analysis_consumable' => false,
                ];
            }
            unset($field);
        }
        $closure['status'] = 'partial';

        $draft = $this->service($closure)->preview(80, '2026-08-23');

        self::assertSame('collection_failed', $draft['facts_broadcast_status']);
        self::assertSame('analysis_blocked', $draft['analysis_status']);
        self::assertSame([], $draft['facts']);
        self::assertSame([], $draft['fact_refs']);
        self::assertSame('', $draft['final_text']);
        self::assertNull($draft['today_attention']);
    }

    public function testFactsFingerprintAndFinalTextDoNotDriftWithGenerationTime(): void
    {
        $first = $this->service(
            $this->hotel80Closure(),
            '2026-08-25 09:00:00'
        )->preview(80, '2026-08-23');
        $second = $this->service(
            $this->hotel80Closure(),
            '2026-08-25 10:30:00'
        )->preview(80, '2026-08-23');

        self::assertSame($first['facts_fingerprint'], $second['facts_fingerprint']);
        self::assertSame($first['final_text'], $second['final_text']);
        self::assertNotSame($first['generated_at'], $second['generated_at']);
    }

    /** @param array<string,mixed> $closure */
    private function service(
        array $closure,
        string $now = '2026-08-25 09:00:00'
    ): AiDailyReportBroadcastSnapshotService {
        return new AiDailyReportBroadcastSnapshotService(
            static fn(int $hotelId, string $businessDate): array => $closure,
            static fn(int $hotelId): array => [
                'id' => $hotelId,
                'tenant_id' => 80,
                'name' => '敦煌漠蓝新',
            ],
            static fn(): DateTimeImmutable => new DateTimeImmutable($now)
        );
    }

    /** @return array<string,mixed> */
    private function hotel80Closure(): array
    {
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 80,
            'hotel_id' => 80,
            'business_date' => '2026-08-23',
            'status' => 'partial',
            'platforms' => [
                'ctrip' => [
                    'identity_status' => 'verified',
                    'platform_status' => 'verified',
                    'target_date_status' => 'matched',
                    'exact_run_readback_status' => 'verified',
                    'status' => 'partial',
                    'revenue_analysis' => ['status' => 'blocked'],
                    'fields' => [
                        'revenue' => $this->fact('strict_readback', 6647.02, ['online_daily_data#102231']),
                        'order_count' => $this->fact('strict_readback', 4, ['online_daily_data#102235']),
                        'room_nights' => $this->fact('strict_readback', 11, ['online_daily_data#102231']),
                        'adr' => $this->fact('verified_calculation', 604.27, ['online_daily_data#102231']),
                        'exposure' => $this->missing('missing'),
                        'visits' => $this->fact('strict_readback', 44, ['online_daily_data#102479']),
                        'conversion' => $this->missing('missing'),
                        'collected_at' => $this->textFact('2026-08-24 23:19:12'),
                    ],
                ],
                'meituan' => [
                    'identity_status' => 'verified',
                    'platform_status' => 'verified',
                    'target_date_status' => 'matched',
                    'exact_run_readback_status' => 'verified',
                    'status' => 'partial',
                    'revenue_analysis' => ['status' => 'blocked'],
                    'fields' => [
                        'revenue' => [
                            'status' => 'caliber_uncertain',
                            'value' => null,
                            'observed_values' => [
                                ['value' => 6461.43, 'source_record_ref' => 'online_daily_data#101920'],
                                ['value' => 7025.14, 'source_record_ref' => 'online_daily_data#101926'],
                            ],
                            'source_record_refs' => ['online_daily_data#101920', 'online_daily_data#101926'],
                            'revenue_analysis_consumable' => false,
                        ],
                        'order_count' => $this->fact('strict_readback', 8, ['online_daily_data#101926']),
                        'room_nights' => $this->fact('strict_readback', 12, ['online_daily_data#101926']),
                        'adr' => $this->missing('caliber_uncertain'),
                        'exposure' => $this->fact('strict_readback', 1422, ['online_daily_data#102476'], true),
                        'visits' => $this->fact('strict_readback', 206, ['online_daily_data#102476'], true),
                        'conversion' => $this->fact('verified_calculation', 14.49, ['online_daily_data#102476'], true),
                        'collected_at' => $this->textFact('2026-08-24 23:17:33'),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function fact(
        string $status,
        int|float $value,
        array $refs,
        bool $consumable = false
    ): array {
        return [
            'status' => $status,
            'value' => $value,
            'source_record_refs' => $refs,
            'revenue_analysis_consumable' => $consumable,
        ];
    }

    /** @return array<string,mixed> */
    private function missing(string $status): array
    {
        return [
            'status' => $status,
            'value' => null,
            'source_record_refs' => [],
            'revenue_analysis_consumable' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function textFact(string $value): array
    {
        return [
            'status' => 'strict_readback',
            'value' => $value,
            'source_record_refs' => ['online_daily_data#102476'],
            'revenue_analysis_consumable' => false,
        ];
    }
}
