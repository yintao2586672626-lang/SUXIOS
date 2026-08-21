<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueCockpitApprovalService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RevenueCockpitApprovalServiceTest extends TestCase
{
    public function testAllOtaApprovalUsesSeparateVerifiedSourceRefsAndStopsBeforeExecution(): void
    {
        $captured = [];
        $service = new RevenueCockpitApprovalService(
            static function (
                int $tenantId,
                int $hotelId,
                string $businessDate,
                int $actorId,
                array $refs
            ) use (&$captured): array {
                $captured = compact('tenantId', 'hotelId', 'businessDate', 'actorId', 'refs');
                return [
                    'status' => 'pending_approval',
                    'execution_intent' => ['id' => 91, 'status' => 'pending_approval'],
                    'persistence_status' => 'readback_verified',
                    'execution_task_created' => false,
                    'external_action_triggered' => false,
                    'reused_existing_intent' => false,
                ];
            }
        );

        $result = $service->createFromOverview(
            $this->overview(),
            10,
            20,
            '2026-08-20',
            'all_ota',
            7
        );

        self::assertSame('pending_approval', $result['status']);
        self::assertSame('readback_verified', $result['persistence_status']);
        self::assertFalse($result['execution_task_created']);
        self::assertFalse($result['external_action_triggered']);
        self::assertSame(['ctrip', 'meituan', 'dingdandao_pms'], array_column($captured['refs'], 'platform'));
        self::assertSame([[101, 102], [201], [301]], array_column($captured['refs'], 'row_ids'));
        self::assertSame(['ota_channel', 'ota_channel', 'whole_hotel_accommodation'], array_column($captured['refs'], 'fact_scope'));
        self::assertTrue($result['boundaries']['human_approval_required']);
        self::assertFalse($result['boundaries']['automatic_execution']);
        self::assertFalse($result['boundaries']['ota_write']);
    }

    public function testAllOtaApprovalRejectsMissingSecondPlatformReadback(): void
    {
        $overview = $this->overview();
        $overview['three_source_fact_layer']['sources']['meituan_ota']['data_status'] = 'not_verified';
        $service = new RevenueCockpitApprovalService(static fn(): array => []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revenue_cockpit_meituan_evidence_not_readback_verified');
        $service->createFromOverview($overview, 10, 20, '2026-08-20', 'all_ota', 7);
    }

    public function testApprovalRejectsRowsThatMissTheCockpitStrictFactGate(): void
    {
        $overview = $this->overview();
        $overview['cockpit_strict_evidence']['platforms']['meituan'] = [
            'source_strict_readback' => false,
            'accepted_row_ids' => [],
            'rejected_row_ids' => [201],
        ];
        $service = new RevenueCockpitApprovalService(static fn(): array => []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revenue_cockpit_meituan_evidence_not_readback_verified');
        $service->createFromOverview($overview, 10, 20, '2026-08-20', 'meituan', 7);
    }

    /** @return array<string,mixed> */
    private function overview(): array
    {
        $ota = static fn(string $platform, array $rowIds): array => [
            'data_status' => 'readback_verified',
            'business_date' => '2026-08-20',
            'actual_business_date' => '2026-08-20',
            'source' => [
                'table' => 'online_daily_data',
                'data_date' => '2026-08-20',
                'platform' => $platform,
                'row_ids' => $rowIds,
                'readback_status' => 'readback_verified',
            ],
        ];
        return [
            'hotel_id' => 20,
            'business_date' => '2026-08-20',
            'cockpit_strict_evidence' => [
                'contract_version' => 'revenue_cockpit_strict_evidence.v1',
                'tenant_id' => 10,
                'hotel_id' => 20,
                'business_date' => '2026-08-20',
                'strict_gate' => 'history_success+validation_verified+readback_verified',
                'platforms' => [
                    'ctrip' => [
                        'source_strict_readback' => true,
                        'accepted_row_ids' => [101, 102],
                        'rejected_row_ids' => [],
                    ],
                    'meituan' => [
                        'source_strict_readback' => true,
                        'accepted_row_ids' => [201],
                        'rejected_row_ids' => [],
                    ],
                ],
            ],
            'three_source_fact_layer' => [
                'business_date' => '2026-08-20',
                'hotel' => [
                    'tenant_id' => 10,
                    'system_hotel_id' => 20,
                    'name' => '测试酒店',
                ],
                'sources' => [
                    'ctrip_ota' => $ota('ctrip', [102, 101]),
                    'meituan_ota' => $ota('meituan', [201]),
                    'dingdandao_pms' => [
                        'data_status' => 'readback_verified',
                        'business_date' => '2026-08-20',
                        'actual_business_date' => '2026-08-20',
                        'source' => [
                            'table' => 'dingdandao_operating_target_captures',
                            'data_date' => '2026-08-20',
                            'record_id' => 301,
                            'readback_status' => 'readback_verified',
                        ],
                    ],
                ],
            ],
        ];
    }
}
