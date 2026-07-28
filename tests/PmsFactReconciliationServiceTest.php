<?php
declare(strict_types=1);

namespace Tests;

use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\MeituanCloudPmsCaptureService;
use app\service\PmsFactReconciliationService;
use PHPUnit\Framework\TestCase;

final class PmsFactReconciliationServiceTest extends TestCase
{
    public function testDualVerifiedSourcesKeepIndependentIdentityAndAlignComparableFacts(): void
    {
        $dingdandaoCurrent = $this->dingdandaoCapture(
            2,
            '2026-07-28 10:10:00',
            10500,
            256.10,
            41,
            100,
            41,
            105
        );
        $dingdandaoPrevious = $this->dingdandaoCapture(
            1,
            '2026-07-28 09:10:00',
            8750,
            250,
            35,
            100,
            35,
            87.50
        );
        $meituanCurrent = $this->meituanCapture(
            4,
            '2026-07-28 10:12:00',
            10794,
            257,
            42,
            100,
            42,
            107
        );
        $meituanPrevious = $this->meituanCapture(
            3,
            '2026-07-28 09:12:00',
            8750,
            250,
            35,
            100,
            35,
            87.50
        );

        $result = (new PmsFactReconciliationService())->summarize(
            80,
            '2026-07-28',
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => $dingdandaoCurrent,
                MeituanCloudPmsCaptureService::PROVIDER => $meituanCurrent,
            ],
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => [
                    $dingdandaoCurrent,
                    $dingdandaoPrevious,
                ],
                MeituanCloudPmsCaptureService::PROVIDER => [
                    $meituanCurrent,
                    $meituanPrevious,
                ],
            ]
        );

        self::assertSame('dual_source_aligned', $result['decision']['status']);
        self::assertNull($result['decision']['preferred_source']);
        self::assertTrue($result['sources']['dingdandao_pms']['usable']);
        self::assertTrue($result['sources']['meituan_cloud_pms']['usable']);
        self::assertSame('volume_rate_up', $result['source_deltas']['dingdandao_pms']['status']);
        self::assertSame(6, $result['source_deltas']['dingdandao_pms']['delta_vector']['net_pickup']);
        self::assertSame('volume_rate_up', $result['source_deltas']['meituan_cloud_pms']['status']);

        $metrics = $this->metricsByKey($result);
        self::assertSame('semantic_mismatch', $metrics['room_revenue']['status']);
        self::assertNull($metrics['room_revenue']['difference']);
        self::assertSame('aligned', $metrics['sold_room_nights']['status']);
        self::assertSame(1.0, $metrics['sold_room_nights']['difference']);
        self::assertSame(3.0, $metrics['sold_room_nights']['tolerance']);
    }

    public function testCrossSourceDifferenceAboveLocalToleranceRequiresReviewWithoutSelectingTruth(): void
    {
        $dingdandao = $this->dingdandaoCapture(
            2,
            '2026-07-28 10:10:00',
            10000,
            250,
            40,
            100,
            40,
            100
        );
        $meituan = $this->meituanCapture(
            4,
            '2026-07-28 10:12:00',
            15000,
            250,
            60,
            100,
            60,
            150
        );

        $result = (new PmsFactReconciliationService())->summarize(
            80,
            '2026-07-28',
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => $dingdandao,
                MeituanCloudPmsCaptureService::PROVIDER => $meituan,
            ]
        );
        $metrics = $this->metricsByKey($result);

        self::assertSame('dual_source_needs_review', $result['decision']['status']);
        self::assertTrue($result['decision']['requires_operator_review']);
        self::assertNull($result['decision']['preferred_source']);
        self::assertSame('needs_review', $metrics['sold_room_nights']['status']);
        self::assertSame(20.0, $metrics['sold_room_nights']['difference']);
        self::assertSame(3.0, $metrics['sold_room_nights']['tolerance']);
    }

    public function testSingleVerifiedSourceBuildsBaselineWithoutInventingOtherPmsFacts(): void
    {
        $dingdandao = $this->dingdandaoCapture(
            2,
            '2026-07-28 10:10:00',
            10000,
            250,
            40,
            100,
            40,
            100
        );

        $result = (new PmsFactReconciliationService())->summarize(
            80,
            '2026-07-28',
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => $dingdandao,
                MeituanCloudPmsCaptureService::PROVIDER => $this->missingMeituanCapture(),
            ],
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => [$dingdandao],
                MeituanCloudPmsCaptureService::PROVIDER => [],
            ]
        );

        self::assertSame('single_source_verified', $result['decision']['status']);
        self::assertSame('baseline_only', $result['source_deltas']['dingdandao_pms']['status']);
        self::assertSame('blocked', $result['source_deltas']['meituan_cloud_pms']['status']);
        self::assertNull(
            $result['sources']['meituan_cloud_pms']['facts']['sold_room_nights']['value']
        );
        self::assertSame(
            'source_unverified',
            $this->metricsByKey($result)['sold_room_nights']['status']
        );
    }

    public function testCumulativeDropIsFlaggedBeforeAnyOperationalAttribution(): void
    {
        $current = $this->dingdandaoCapture(
            2,
            '2026-07-28 10:10:00',
            8000,
            266.67,
            30,
            100,
            30,
            80
        );
        $previous = $this->dingdandaoCapture(
            1,
            '2026-07-28 09:10:00',
            10000,
            250,
            40,
            100,
            40,
            100
        );

        $result = (new PmsFactReconciliationService())->summarize(
            80,
            '2026-07-28',
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => $current,
                MeituanCloudPmsCaptureService::PROVIDER => $this->missingMeituanCapture(),
            ],
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => [$current, $previous],
            ]
        );
        $delta = $result['source_deltas']['dingdandao_pms'];

        self::assertSame('reversal_unknown', $delta['status']);
        self::assertSame('PMS_DELTA_REVERSAL_UNKNOWN', $delta['rule_id']);
        self::assertSame(-10, $delta['delta_vector']['net_pickup']);
        self::assertSame(-2000, $delta['delta_vector']['room_revenue']);
        self::assertStringContainsString('取消、退款、冲账', $delta['recommended_manual_check']);
        self::assertSame('cumulative_cancellations_missing', $delta['data_gaps'][0]['code']);
    }

    public function testFailedCaptureDoesNotHidePreviousVerifiedBaseline(): void
    {
        $current = $this->dingdandaoCapture(
            3,
            '2026-07-28 10:10:00',
            10500,
            256.10,
            41,
            100,
            41,
            105
        );
        $failed = $this->dingdandaoCapture(
            2,
            '2026-07-28 09:40:00',
            9000,
            250,
            36,
            100,
            36,
            90
        );
        $failed['quality_status'] = 'failed';
        $failed['readback_status'] = 'failed';
        $previous = $this->dingdandaoCapture(
            1,
            '2026-07-28 09:10:00',
            8750,
            250,
            35,
            100,
            35,
            87.50
        );

        $result = (new PmsFactReconciliationService())->summarize(
            80,
            '2026-07-28',
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => $current,
                MeituanCloudPmsCaptureService::PROVIDER => $this->missingMeituanCapture(),
            ],
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => [
                    $current,
                    $failed,
                    $previous,
                ],
            ]
        );
        $delta = $result['source_deltas']['dingdandao_pms'];

        self::assertSame(1, $delta['previous_capture_id']);
        self::assertSame(6, $delta['delta_vector']['net_pickup']);
        self::assertSame('volume_rate_up', $delta['status']);
    }

    public function testMeituanDateMustBeExplicitlyVerified(): void
    {
        $meituan = $this->meituanCapture(
            4,
            '2026-07-28 10:12:00',
            10794,
            257,
            42,
            100,
            42,
            107
        );
        unset($meituan['date_status']);

        $result = (new PmsFactReconciliationService())->summarize(
            80,
            '2026-07-28',
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => [],
                MeituanCloudPmsCaptureService::PROVIDER => $meituan,
            ]
        );

        self::assertFalse($result['sources']['meituan_cloud_pms']['usable']);
        self::assertSame('unverified', $result['sources']['meituan_cloud_pms']['date_status']);
        self::assertSame('no_verified_source', $result['decision']['status']);
    }

    public function testMaterialReversalOutranksShortIntervalNoise(): void
    {
        $current = $this->dingdandaoCapture(
            2,
            '2026-07-28 09:14:00',
            8000,
            266.67,
            30,
            100,
            30,
            80
        );
        $previous = $this->dingdandaoCapture(
            1,
            '2026-07-28 09:10:00',
            10000,
            250,
            40,
            100,
            40,
            100
        );

        $result = (new PmsFactReconciliationService())->summarize(
            80,
            '2026-07-28',
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => $current,
                MeituanCloudPmsCaptureService::PROVIDER => $this->missingMeituanCapture(),
            ],
            [
                DingdandaoOperatingTargetCaptureService::PROVIDER => [$current, $previous],
            ]
        );

        self::assertSame(
            'reversal_unknown',
            $result['source_deltas']['dingdandao_pms']['status']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dingdandaoCapture(
        int $id,
        string $capturedAt,
        float $revenue,
        float $adr,
        int $sold,
        int $sellable,
        float $occupancy,
        float $revpar
    ): array {
        return [
            'id' => $id,
            'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
            'provider_hotel_id' => 'DD-80',
            'provider_hotel_name' => '测试酒店',
            'identity_status' => 'matched',
            'business_date' => '2026-07-28',
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'reconciliation_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'captured_at' => $capturedAt,
            'summary' => [
                'total_room_fee' => $revenue,
                'adr' => $adr,
                'occupancy_rate_percent' => $occupancy,
                'revpar' => $revpar,
                'sold_room_nights' => $sold,
                'derived_sellable_room_nights' => $sellable,
            ],
            'gaps' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meituanCapture(
        int $id,
        string $capturedAt,
        float $revenue,
        float $adr,
        int $sold,
        int $totalRooms,
        float $occupancy,
        float $revpar
    ): array {
        return [
            'id' => $id,
            'provider' => MeituanCloudPmsCaptureService::PROVIDER,
            'provider_hotel_id' => 'MT-80',
            'provider_hotel_name' => '测试酒店',
            'identity_status' => 'matched',
            'date_status' => 'matched',
            'business_date' => '2026-07-28',
            'source_scope' => MeituanCloudPmsCaptureService::SOURCE_SCOPE,
            'reconciliation_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'captured_at' => $capturedAt,
            'summary' => [
                'estimated_room_revenue' => $revenue,
                'adr' => $adr,
                'occupancy_rate_percent' => $occupancy,
                'revpar' => $revpar,
                'sold_room_nights' => $sold,
                'total_rooms' => $totalRooms,
            ],
            'gaps' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingMeituanCapture(): array
    {
        return [
            'provider' => MeituanCloudPmsCaptureService::PROVIDER,
            'business_date' => '2026-07-28',
            'source_scope' => MeituanCloudPmsCaptureService::SOURCE_SCOPE,
            'identity_status' => 'unverified',
            'date_status' => 'unverified',
            'reconciliation_status' => 'unverified',
            'capture_status' => 'missing',
            'quality_status' => 'missing',
            'readback_status' => 'missing',
            'summary' => [],
            'gaps' => [[
                'code' => 'meituan_cloud_capture_missing',
                'message' => '尚无美团云 PMS 当日事实。',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, array<string, mixed>>
     */
    private function metricsByKey(array $result): array
    {
        $indexed = [];
        foreach ($result['comparison']['metrics'] as $metric) {
            $indexed[$metric['key']] = $metric;
        }
        return $indexed;
    }
}
