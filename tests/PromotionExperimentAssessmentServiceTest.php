<?php
declare(strict_types=1);
namespace Tests;

use app\service\PromotionExperimentAssessmentService;
use PHPUnit\Framework\TestCase;
use Tests\Support\PromotionExperimentFixture as F;

final class PromotionExperimentAssessmentServiceTest extends TestCase
{
    public function testQualifiedSyntheticComparisonSubtractsAdsAfterContribution(): void
    {
        $r = (new PromotionExperimentAssessmentService())->evaluate(F::input());
        self::assertSame('directional_estimate', $r['incrementality']['status']);
        self::assertSame(30.0, $r['incrementality']['room_nights']);
        self::assertSame(1000.0, $r['incrementality']['net_contribution_after_ads']);
        self::assertFalse($r['incrementality']['causality_claimed']);
        self::assertFalse($r['incrementality']['statistical_significance_tested']);
    }
    public function testHolidayPriceIncreaseWithNoControlNeverBecomesAdvertisingIncrement(): void
    {
        $in = F::input(); $in['plan']['design_quality'] = 'none';
        $in['observation']['control_before'] = null; $in['observation']['control_after'] = null;
        $in['concurrent_changes']['holiday'] = ['status' => 'changed', 'note' => 'SYNTHETIC holiday'];
        $in['concurrent_changes']['price'] = ['status' => 'changed', 'note' => 'SYNTHETIC raised price'];
        $r = (new PromotionExperimentAssessmentService())->evaluate($in);
        self::assertSame('unknown', $r['incrementality']['status']);
        self::assertNull($r['incrementality']['room_nights']);
        self::assertNull($r['incrementality']['net_contribution_after_ads']);
        self::assertContains('concurrent_price_changed', $r['incrementality']['reason_codes']);
        self::assertContains('no_control_design', $r['incrementality']['reason_codes']);
        self::assertSame(900.0, $r['accounting']['platform_attribution']['net_revenue']);
    }
    public function testWindowMaturityRecoveryAndIncompleteLegacyObservations(): void
    {
        $in = F::input(); $in['as_of'] = '2026-08-17';
        $in['records'][0]['collected_at'] = '2026-08-17T10:00:00+08:00';
        $in['observation']['collected_at'] = '2026-08-17T10:00:00+08:00';
        $service = new PromotionExperimentAssessmentService();
        self::assertNull($service->evaluate($in)['incrementality']['room_nights']);
        $in['as_of'] = '2026-08-18';
        self::assertNull($service->evaluate($in)['incrementality']['room_nights'], 'Clock maturity cannot mature an older attribution snapshot');
        $in['records'][0]['collected_at'] = '2026-08-18T10:00:00+08:00';
        self::assertNull($service->evaluate($in)['incrementality']['room_nights'], 'Observation snapshot must mature as well');
        $in['observation']['collected_at'] = '2026-08-18T10:00:00+08:00';
        self::assertSame(30.0, $service->evaluate($in)['incrementality']['room_nights']);
        unset($in['observation'], $in['concurrent_changes']); $in['records'] = [];
        $r = $service->evaluate($in);
        self::assertSame('unknown', $r['incrementality']['status']);
        self::assertSame('blocked', $r['accounting']['status']);
        self::assertSame('unknown', $r['concurrent_changes']['holiday']['status']);
    }
    public function testUnknownAndUnverifiedEvidenceNeverPassesEvenWithFullNumbers(): void
    {
        foreach (['manual_input', 'test_fixture'] as $method) {
            $in = F::input(); $in['observation']['source_method'] = $method;
            if ($method === 'test_fixture') $in['concurrent_changes']['inventory'] = ['status' => 'unknown', 'note' => ''];
            self::assertNull((new PromotionExperimentAssessmentService())->evaluate($in)['incrementality']['room_nights']);
        }
    }
    public function testImpossibleOccupancyAndUnjustifiedConcurrentChecksAreRejected(): void
    {
        $in = F::input(); $in['observation']['treated_after'] = 201;
        try { (new PromotionExperimentAssessmentService())->evaluate($in); self::fail('Impossible exposure'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('可售间夜', $e->getMessage()); }
        $in = F::input(); $in['concurrent_changes']['price']['note'] = '';
        $this->expectException(\InvalidArgumentException::class);
        (new PromotionExperimentAssessmentService())->evaluate($in);
    }
}
