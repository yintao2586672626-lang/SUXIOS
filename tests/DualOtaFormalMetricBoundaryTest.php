<?php
declare(strict_types=1);
namespace Tests;

use app\service\OtaStructuredCaptureEvidenceService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/DualOtaFormalSourceBoundaryTest.php';

/** local_synthetic_fixture_only; production evaluation, no DB or external calls. */
final class DualOtaFormalMetricBoundaryTest extends TestCase
{
    public function testEachCoreMetricRequiresItsOwnCapturedMapping(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            foreach (['revenue' => 0, 'room_nights' => 1, 'order_count' => 2] as $key => $index) {
                $normal = $this->row($platform, $key);
                foreach (['missing', 'metric', 'normalized', 'storage', 'status', 'stored'] as $failure) {
                    $row = $normal;
                    $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
                    if ($failure === 'missing') unset($raw['field_facts'][$index]);
                    if ($failure === 'metric') $raw['field_facts'][$index]['metric_key'] = 'advertising_cost';
                    if ($failure === 'normalized') $raw['field_facts'][$index]['normalized_field'] = 'list_exposure';
                    if ($failure === 'storage') $raw['field_facts'][$index]['storage_field'] = 'online_daily_data.list_exposure';
                    if ($failure === 'status') $raw['field_facts'][$index]['status'] = 'missing';
                    if ($failure === 'stored') $raw['field_facts'][$index]['stored_value_present'] = false;
                    $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
                    self::assertTrue((new OtaStructuredCaptureEvidenceService())->classifyRow($row)['allowed']);
                    $blocked = $this->evaluate([$row])[$key];
                    self::assertNull($blocked['value'], $platform . ':' . $key . ':' . $failure);
                    self::assertSame('caliber_uncertain', $blocked['status']);
                    self::assertFalse($blocked['strict_final_gate']);
                    self::assertFalse($blocked['revenue_analysis_consumable']);
                    self::assertSame([1], $blocked['source_record_ids']);
                    self::assertTrue($blocked['formal_readback_verified']);
                    self::assertNotEmpty($blocked['observed_values']);
                    self::assertContains('metric_field_evidence_unverified', $blocked['quality_flags']);
                    self::assertStringContainsString('入库字段', $blocked['next_action']);
                    $recovered = $this->evaluate([$normal])[$key];
                    self::assertTrue($recovered['revenue_analysis_consumable']);
                    self::assertSame($blocked['source_record_ids'], $recovered['source_record_ids']);
                }
            }
        }
    }

    public function testAdrRequiresBothInputsWithoutBlockingIndependentMetrics(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            foreach ([0 => 'revenue', 1 => 'room_nights'] as $index => $key) {
                $row = $this->row($platform);
                $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
                unset($raw['field_facts'][$index]);
                $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
                $fields = $this->evaluate([$row]);
                self::assertFalse($fields[$key]['revenue_analysis_consumable']);
                self::assertTrue($fields[$index === 0 ? 'room_nights' : 'revenue']['revenue_analysis_consumable']);
                self::assertNull($fields['adr']['value']);
                self::assertFalse($fields['adr']['revenue_analysis_consumable']);
            }
        }
    }

    public function testLegacyLineageNestedFactsAndCapturedZeroRemainCompatible(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            $row = $this->row($platform);
            $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            unset($raw['row']['capture_evidence']['capture_strategy'], $raw['row']['capture_evidence']['response_evidence_type']);
            foreach ($raw['field_facts'] as &$fact) {
                unset($fact['capture_evidence']['capture_strategy'], $fact['capture_evidence']['response_evidence_type']);
            }
            unset($fact);
            $raw['row']['field_facts'] = $raw['field_facts'];
            unset($raw['field_facts']);
            $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
            $fields = $this->evaluate([$row]);
            self::assertTrue($fields['revenue']['revenue_analysis_consumable']);
            self::assertSame(120.0, $fields['revenue']['value']);
            self::assertSame(40.0, $fields['adr']['value']);
            $zero = $this->row($platform, 'order_count');
            $zero['book_order_num'] = 0;
            $zero['amount'] = $zero['quantity'] = $platform === 'meituan' ? 0 : null;
            $fields = $this->evaluate([$zero]);
            self::assertSame(0.0, $fields['order_count']['value']);
            self::assertTrue($fields['order_count']['revenue_analysis_consumable']);
        }
        $checkout = $this->row('ctrip');
        $checkout['book_order_num'] = null;
        $booking = $this->row('ctrip', 'order_count');
        $booking['id'] = 2;
        $booking['amount'] = $booking['quantity'] = null;
        $fields = $this->evaluate([$checkout, $booking]);
        foreach (['revenue', 'room_nights', 'order_count', 'adr'] as $key) self::assertTrue($fields[$key]['revenue_analysis_consumable']);
        self::assertSame([2], $fields['order_count']['source_record_ids']);
    }

    private function row(string $platform, string $key = 'revenue'): array
    {
        $fixture = new DualOtaFormalSourceBoundaryTest('testStructuredAndLegacySourcesKeepStrictNormalReadback');
        $row = (new ReflectionMethod($fixture, 'row'))->invoke($fixture, $platform);
        if ($platform === 'ctrip' && $key === 'order_count') $row['dimension'] = 'semantic:ctrip_business_market_overview:booking_order_count';
        return $row;
    }

    private function evaluate(array $rows): array
    {
        $fixture = new DualOtaFormalSourceBoundaryTest('testStructuredAndLegacySourcesKeepStrictNormalReadback');
        return (new ReflectionMethod($fixture, 'evaluate'))->invoke($fixture, $rows);
    }
}
