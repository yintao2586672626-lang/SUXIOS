<?php
declare(strict_types=1);
namespace Tests;

use app\service\DualOtaFieldClosureService;
use app\service\RevenueCockpitStrictEvidenceService;
use app\service\RevenueDecisionViewModelAttestationService;
use app\service\RevenueFactLayerService;
use app\service\RevenueOverviewDateContract;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use InvalidArgumentException;

require_once __DIR__ . '/DualOtaFormalSourceBoundaryTest.php';
require_once __DIR__ . '/RevenueCockpitStrictEvidenceServiceTest.php';

/** local_synthetic_fixture_only; pure services, no real DB or external calls. */
final class RevenueCockpitMetricRecoveryReasonTest extends TestCase
{
    public function testFieldCauseReachesSignedCoreCardsAndSameRecordRecovery(): void
    {
        $issuer = new RevenueDecisionViewModelAttestationService();
        foreach ([false, true, false] as $broken) {
            $overview = $this->overview($broken);
            $model = $issuer->issue($overview, $this->comparisons(), [], 10, 20, '2026-08-20', 'meituan');
            self::assertSame($model, $issuer->attest($model, $overview, $this->comparisons(), [], 10, 20, '2026-08-20', 'meituan'));
            $cards = [];
            foreach ($model['visibleSections'] as $section) {
                if ($section['key'] === 'core_metrics') {
                    foreach ($section['cards'] as $card) $cards[$card['key']] = $card;
                }
            }
            self::assertSame(2, $cards['meituan_ota:orders']['value']);
            self::assertSame(3, $cards['meituan_ota:room_nights']['value']);
            foreach (['revenue' => 120.0, 'adr' => 40.0] as $key => $value) {
                $card = $cards['meituan_ota:' . $key];
                if ($broken) self::assertNull($card['value']);
                else self::assertSame($value, (float)$card['value']);
                self::assertSame($broken ? '—' : '¥' . number_format($value, 2), $card['display']);
                self::assertStringContainsString('保存记录：#1', implode(';', $card['evidenceLines']));
                if ($broken) {
                    self::assertSame('cockpit_strict_metric_readback_missing', $card['reasonCode']);
                    self::assertStringContainsString('对应指标、入库字段', $card['reasonText']);
                    self::assertStringContainsString('只读刷新', $card['reasonText']);
                    self::assertStringContainsString('metric_field_evidence_unverified', implode(';', $card['evidenceLines']));
                    self::assertSame([1], $overview['cockpit_strict_evidence']['platforms']['meituan']['metrics'][$key]['rejected_row_ids']);
                } else {
                    self::assertSame('', $card['reasonCode']);
                    self::assertStringNotContainsString('metric_field_evidence_unverified', implode(';', $card['evidenceLines']));
                }
            }
            self::assertSame(10, $model['tenantId']);
            self::assertSame(20, $model['hotelId']);
            self::assertSame('meituan', $model['selectedPlatform']);
            self::assertSame('2026-08-20', $model['businessDate']);
        }
    }

    public function testLegacyAndMalformedQualityMetadataDoNotChangeGateOrIds(): void
    {
        $fixture = new RevenueCockpitStrictEvidenceServiceTest('testMetricAndSourceFailClosedWhenCanonicalRowsMissTheStrictGate');
        $closure = (new ReflectionMethod($fixture, 'closure'))->invoke($fixture, false);
        $overview = ['hotel_id' => 20, 'business_date' => '2026-08-20'];
        foreach ([null, 'metric_field_evidence_unverified', [null, 7, [], ''], ['', 'metric_field_evidence_unverified', 'metric_field_evidence_unverified']] as $flags) {
            $input = $closure;
            if ($flags !== null) $input['platforms']['meituan']['fields'][0]['quality_flags'] = $flags;
            $strict = (new RevenueCockpitStrictEvidenceService())->build($overview, 10, 20, '2026-08-20', 'meituan', $input);
            $metric = $strict['platforms']['meituan']['metrics']['revenue'];
            self::assertFalse($metric['strict_readback']);
            self::assertSame([101, 102], $metric['rejected_row_ids']);
            self::assertSame([], $metric['accepted_row_ids']);
            self::assertSame([103], $strict['platforms']['meituan']['accepted_row_ids']);
            self::assertSame(is_array($flags) && in_array('metric_field_evidence_unverified', $flags, true)
                ? ['metric_field_evidence_unverified'] : [], $metric['quality_flags']);
        }
    }

    public function testRecoveryReasonRemainsServerSigned(): void
    {
        $overview = $this->overview(true);
        $issuer = new RevenueDecisionViewModelAttestationService();
        $model = $issuer->issue($overview, $this->comparisons(), [], 10, 20, '2026-08-20', 'meituan');
        foreach (['sections', 'visibleSections'] as $collection) {
            foreach ($model[$collection] as &$section) foreach ($section['cards'] as &$card) {
                if ($card['key'] === 'meituan_ota:revenue') $card['reasonText'] = '客户端伪造已恢复';
            }
            unset($section, $card);
        }
        $this->expectException(InvalidArgumentException::class);
        $issuer->attest($model, $overview, $this->comparisons(), [], 10, 20, '2026-08-20', 'meituan');
    }

    private function comparisons(): array
    {
        return ['previous_date' => '', 'same_weekday_date' => '', 'previous_overview' => [], 'same_weekday_overview' => []];
    }

    private function overview(bool $broken): array
    {
        $fixture = new DualOtaFormalSourceBoundaryTest('testStructuredAndLegacySourcesKeepStrictNormalReadback');
        $row = (new ReflectionMethod($fixture, 'row'))->invoke($fixture, 'meituan');
        $hotel = ['id' => 20, 'tenant_id' => 10, 'name' => 'local_synthetic_fixture_only'];
        $receipt = ['status' => 'verified', 'target_date' => '2026-08-20', 'target_date_status' => 'matched',
            'platform_hotel_status' => 'verified', 'platform_hotel_id' => 'fixture-store-20', 'source_method' => 'browser_profile',
            'data_source_id' => 5, 'sync_task_id' => 6, 'sync_task_status' => 'success', 'data_period' => 'historical_daily',
            'run_readback_scope' => ['status' => 'verified', 'receipt_record_ids' => [1], 'accepted_record_ids' => [1]]];
        $trust = ['days' => [['date' => '2026-08-20', 'platforms' => [['platform' => 'meituan',
            'steps' => ['source' => true, 'account_profile_binding' => true, 'hotel' => true, 'date' => true],
            'acceptance_receipt' => $receipt]]]]];
        $layer = (new RevenueFactLayerService())->assemble($hotel, '2026-08-20', [],
            ['data_status' => 'ready', 'data_gaps' => [], 'rows' => [array_replace($row, ['row_id' => 1, 'readback_verified' => true])]], []);
        if ($broken) {
            $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            $raw['field_facts'][0]['storage_field'] = 'online_daily_data.quantity';
            $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
        }
        $closure = DualOtaFieldClosureService::evaluate($hotel, '2026-08-20', [$row], $trust);
        $overview = ['hotel_id' => 20, 'business_date' => '2026-08-20',
            'as_of_date' => RevenueOverviewDateContract::serverAsOfDate(),
            'as_of_date_contract_version' => RevenueOverviewDateContract::VERSION,
            'three_source_fact_layer' => $layer, 'dual_ota_field_closure' => $closure];
        $overview['cockpit_strict_evidence'] = (new RevenueCockpitStrictEvidenceService())->build($overview, 10, 20, '2026-08-20', 'meituan', $closure);
        return $overview;
    }
}
