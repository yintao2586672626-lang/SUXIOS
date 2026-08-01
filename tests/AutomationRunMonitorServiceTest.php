<?php
declare(strict_types=1);

namespace Tests;

use app\service\AutomationRunMonitorService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AutomationRunMonitorServiceTest extends TestCase
{
    public function testMonitorUsesOneSelectedPmsAndSeparatesScheduleFromDeliveryReceipt(): void
    {
        $service = new AutomationRunMonitorService(
            fn(): array => $this->otaPreview('readback_verified', 'readback_verified'),
            fn(): array => $this->pmsBinding(
                'configured',
                $this->dingdandaoStatus(true, 'sent')
            ),
            fn(): array => $this->taskOverview(
                '2026-07-28 09:00:03',
                '2026-07-28 10:00:00',
                '已发送'
            ),
            new DateTimeImmutable('2026-07-28 09:30:00', new DateTimeZone('Asia/Shanghai')),
            fn(array $permittedHotelIds): array => [80],
            function (array $hotels, string $businessDate): array {
                self::assertSame([80], array_column($hotels, 'id'));
                self::assertSame('2026-07-28', $businessDate);
                return [
                    80 => [
                        'sources' => [
                            'ctrip' => ['last_success_at' => '2026-07-28 08:43:47'],
                            'meituan' => ['last_success_at' => '2026-07-28 08:45:12'],
                        ],
                        'delivery' => [
                            'success_count' => 3,
                            'last_success_at' => '2026-07-28 09:15:30',
                            'status' => 'verified',
                        ],
                    ],
                ];
            }
        );

        $overview = $service->overview([$this->hotel()], '2026-07-28', 9);
        $row = $overview['rows'][0];

        self::assertSame('订单来了 PMS', $row['pms']['label']);
        self::assertSame('ready', $row['data_status']);
        self::assertSame('3/3 数据已就绪', $row['data_status_label']);
        self::assertSame('2026-07-28 10:00:00', $row['next_push_at']);
        self::assertSame('sent', $row['push_status']);
        self::assertSame('企业微信已送达', $row['push_result']);
        self::assertSame('2026-07-28 08:43:47', $row['ctrip']['last_success_at']);
        self::assertSame('2026-07-28 08:45:12', $row['meituan']['last_success_at']);
        self::assertSame(3, $row['push_success_count']);
        self::assertSame('verified', $row['push_success_count_status']);
        self::assertSame('2026-07-28 09:15:30', $row['push_result_at']);
        self::assertSame('无', $row['blocker_reason']);
        self::assertSame(1, $overview['summary']['push_succeeded_count']);
    }

    public function testMonitorBlocksInsteadOfSilentlyChoosingWhenBothPmsBindingsAreEnabled(): void
    {
        $service = new AutomationRunMonitorService(
            fn(): array => $this->otaPreview('readback_verified', 'readback_verified'),
            fn(): array => $this->pmsBinding('conflict'),
            fn(): array => $this->taskOverview('', '', ''),
            new DateTimeImmutable('2026-07-28 09:30:00', new DateTimeZone('Asia/Shanghai')),
            fn(array $permittedHotelIds): array => [80]
        );

        $row = $service->overview([$this->hotel()], '2026-07-28', 9)['rows'][0];

        self::assertSame('PMS绑定冲突', $row['pms']['label']);
        self::assertSame('binding_conflict', $row['pms']['status']);
        self::assertSame('blocked', $row['data_status']);
        self::assertStringContainsString('同时启用', $row['blocker_reason']);
        self::assertStringContainsString('主 PMS', $row['blocker_reason']);
    }

    public function testMonitorShowsPartialDataAndMissingPushTimeWithoutInventingSuccess(): void
    {
        $service = new AutomationRunMonitorService(
            fn(): array => $this->otaPreview('readback_verified', 'pending_readback'),
            fn(): array => $this->pmsBinding(
                'configured',
                $this->meituanCloudStatus(true)
            ),
            fn(): array => [
                'source_status' => 'database_only',
                'tasks' => [[
                    'key' => 'manual_notification_1',
                    'status' => 'active',
                    'next_run_at' => '',
                    'last_run_at' => '',
                    'last_result' => '已通过测试并启用',
                ]],
            ],
            new DateTimeImmutable('2026-07-28 09:30:00', new DateTimeZone('Asia/Shanghai')),
            fn(array $permittedHotelIds): array => [80],
            fn(): array => [
                80 => [
                    'sources' => [
                        'ctrip' => ['last_success_at' => '2026-07-28 08:43:47'],
                        'meituan' => ['last_success_at' => '2026-07-27 22:00:14'],
                    ],
                ],
            ]
        );

        $row = $service->overview([$this->hotel()], '2026-07-28', 9)['rows'][0];

        self::assertSame('美团云 PMS', $row['pms']['label']);
        self::assertSame('partial', $row['data_status']);
        self::assertSame('部分就绪（2/3）', $row['data_status_label']);
        self::assertNull($row['next_push_at']);
        self::assertSame('2026-07-28 08:43:47', $row['ctrip']['last_success_at']);
        self::assertSame('2026-07-27 22:00:14', $row['meituan']['last_success_at']);
        self::assertSame('pending_readback', $row['meituan']['status']);
        self::assertSame('预计时间未取得', $row['next_push_label']);
        self::assertSame('waiting', $row['push_status']);
        self::assertSame('尚无执行回执', $row['push_result']);
        self::assertStringContainsString('等待保存回读', $row['blocker_reason']);
        self::assertStringContainsString('预计下次执行时间', implode('；', $row['blockers']));
    }

    public function testUnconfiguredPmsRemainsBlockedWhileOtaCollectionIsInProgress(): void
    {
        $service = new AutomationRunMonitorService(
            fn(): array => $this->otaPreview('pending_collection', 'pending_collection'),
            fn(): array => $this->pmsBinding('unconfigured'),
            fn(): array => $this->taskOverview('', '', ''),
            new DateTimeImmutable('2026-07-28 09:30:00', new DateTimeZone('Asia/Shanghai')),
            fn(array $permittedHotelIds): array => [80]
        );

        $overview = $service->overview([$this->hotel()], '2026-07-28', 9);
        $row = $overview['rows'][0];

        self::assertSame('未绑定 PMS', $row['pms']['label']);
        self::assertSame('binding_missing', $row['pms']['status']);
        self::assertSame('blocked', $row['data_status']);
        self::assertSame(1, $overview['summary']['blocked_count']);
        self::assertStringContainsString('尚未在门店管理中选择', $row['blocker_reason']);
    }

    public function testMonitorIncludesPermittedHotelsAndMarksMissingWechatRobotAsBlocked(): void
    {
        $monitoredHotelIds = [];
        $service = new AutomationRunMonitorService(
            function (int $hotelId) use (&$monitoredHotelIds): array {
                $monitoredHotelIds[] = $hotelId;
                return $this->otaPreview('readback_verified', 'readback_verified');
            },
            fn(): array => $this->pmsBinding(
                'configured',
                $this->dingdandaoStatus(true)
            ),
            fn(int $tenantId, int $hotelId): array => $hotelId === 81
                ? $this->taskOverview(
                    '2026-07-28 09:00:03',
                    '2026-07-28 10:00:00',
                    '已发送'
                )
                : ['source_status' => 'database_only', 'tasks' => []],
            new DateTimeImmutable('2026-07-28 09:30:00', new DateTimeZone('Asia/Shanghai')),
            function (array $permittedHotelIds): array {
                self::assertSame([80, 81], $permittedHotelIds);
                return [81, 999];
            },
            fn(): array => []
        );

        $overview = $service->overview([
            $this->hotel(),
            [
                'id' => 81,
                'tenant_id' => 81,
                'name' => '已绑定机器人门店',
            ],
        ], '2026-07-28', 9);

        sort($monitoredHotelIds);
        self::assertSame([80, 81], $monitoredHotelIds);
        self::assertSame(2, $overview['summary']['hotel_count']);
        $rows = array_column($overview['rows'], null, 'hotel_id');
        self::assertFalse($rows[80]['wechat_robot_configured']);
        self::assertSame('blocked', $rows[80]['push_status']);
        self::assertSame('企业微信机器人未绑定', $rows[80]['next_push_label']);
        self::assertStringContainsString('尚未为门店绑定并启用企业微信机器人', $rows[80]['blocker_reason']);
        self::assertTrue($rows[81]['wechat_robot_configured']);
        self::assertStringContainsString('当前账号有权限的营业门店', $overview['message']);
    }

    /** @return array<string, mixed> */
    private function hotel(): array
    {
        return [
            'id' => 80,
            'tenant_id' => 80,
            'name' => '敦煌漠蓝新',
        ];
    }

    /** @return array<string, mixed> */
    private function otaPreview(string $ctripStatus, string $meituanStatus): array
    {
        return [
            'sections' => [
                'today_revenue_management' => [
                    'ota_collection' => [
                        'platforms' => [
                            'ctrip' => [
                                'status' => $ctripStatus,
                                'label' => $this->otaLabel($ctripStatus),
                            ],
                            'meituan' => [
                                'status' => $meituanStatus,
                                'label' => $this->otaLabel($meituanStatus),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function otaLabel(string $status): string
    {
        return match ($status) {
            'readback_verified' => '已保存并回读',
            'pending_collection' => '正在采集或等待任务状态回写',
            'pending_readback' => '等待保存回读',
            'collection_failed' => '采集失败',
            default => '等待采集',
        };
    }

    /**
     * @param array<string, mixed>|null $source
     * @return array<string, mixed>
     */
    private function pmsBinding(string $status, ?array $source = null): array
    {
        if ($status === 'conflict') {
            return [
                'binding_status' => 'conflict',
                'selected_provider' => null,
                'selected_provider_label' => null,
                'selected_source' => null,
                'blockers' => [[
                    'code' => 'hotel_pms_multiple_sources_enabled',
                    'message' => '历史配置中有两套 PMS 同时启用，请在门店管理中明确保留一个主 PMS。',
                ]],
            ];
        }

        return [
            'binding_status' => $status,
            'selected_provider' => $source['provider'] ?? null,
            'selected_provider_label' => $source['provider_label'] ?? null,
            'selected_source' => $source,
            'blockers' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function dingdandaoStatus(
        bool $captureReady,
        string $deliveryStatus = ''
    ): array {
        return [
            'provider' => 'dingdandao_pms',
            'provider_label' => '订单来了 PMS',
            'capture' => $captureReady ? $this->verifiedCapture() : null,
            'fact_gate' => [
                'allowed' => $captureReady,
                'blockers' => $captureReady ? [] : [[
                    'code' => 'dingdandao_capture_missing',
                    'message' => '订单来了当天事实尚未通过。',
                ]],
            ],
            'push_gate' => [
                'allowed' => $captureReady,
                'blockers' => $captureReady ? [] : [[
                    'code' => 'dingdandao_capture_missing',
                    'message' => '订单来了当天事实尚未通过。',
                ]],
            ],
            'latest_dispatch' => $deliveryStatus === '' ? null : [
                'delivery_status' => $deliveryStatus,
                'delivered_at' => '2026-07-28 09:00:04',
                'error_summary' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function meituanCloudStatus(bool $captureReady): array
    {
        return [
            'provider' => 'meituan_cloud_pms',
            'provider_label' => '美团云 PMS',
            'capture' => $captureReady ? $this->verifiedCapture() : null,
            'fact_gate' => [
                'allowed' => $captureReady,
                'blockers' => $captureReady ? [] : [[
                    'code' => 'meituan_cloud_capture_missing',
                    'message' => '美团云 PMS 当天事实尚未通过。',
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function verifiedCapture(): array
    {
        return [
            'business_date' => '2026-07-28',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'identity_status' => 'matched',
            'readback_status' => 'readback_verified',
        ];
    }

    /** @return array<string, mixed> */
    private function taskOverview(string $lastRunAt, string $nextRunAt, string $lastResult): array
    {
        return [
            'source_status' => 'live',
            'tasks' => [[
                'key' => 'hourly_operating_monitor',
                'status' => 'active',
                'last_run_at' => $lastRunAt,
                'next_run_at' => $nextRunAt,
                'last_result' => $lastResult,
            ]],
        ];
    }
}
