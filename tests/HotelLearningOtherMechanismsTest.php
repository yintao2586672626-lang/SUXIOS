<?php
declare(strict_types=1);

use app\service\HotelLearningMechanismService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HotelLearningOtherMechanismsTest extends TestCase
{
    private function calculate(string $mode, array $input): array
    {
        return (new HotelLearningMechanismService())->calculate($mode, $input);
    }

    private function ota(): array
    {
        return ['scene' => ['keyword' => '合成酒店', 'location' => '测试城市', 'device' => 'desktop',
            'login_state' => 'anonymous', 'sort' => '推荐', 'filters' => '无', 'observed_at' => '2026-10-03 12:00',
            'source_ref' => 'synthetic-search-screen', 'platform_store_id' => '80', 'check_in' => '2026-10-10',
            'check_out' => '2026-10-11', 'page_capacity' => 20], 'visibility' => 'observed',
            'rank_min' => 3, 'rank_max' => 5, 'conversion_rate' => 0.01, 'rate_unit' => 'fraction', 'price' => 100,
            'price_terms' => ['room_type' => '标准双床', 'cancellation' => '免费取消', 'breakfast' => '双早',
                'guest_count' => '2', 'membership' => '非会员', 'tax_basis' => '含税', 'payment' => '预付']];
    }

    private function market(): array
    {
        return ['weights' => ['traffic' => 0.4, 'conversion' => 0.3, 'revenue' => 0.3],
            'model_version' => 'synthetic-manual-v1', 'sample_ref' => 'synthetic-same-scene',
            'comparison_key' => 'synthetic-scene-key', 'comparison_attested' => true, 'hotels' => [
                ['platform_store_id' => '80', 'comparison_key' => 'synthetic-scene-key', 'traffic' => 100,
                    'conversion' => 0.01, 'rate_unit' => 'fraction', 'revenue' => 1000],
                ['platform_store_id' => '81', 'comparison_key' => 'synthetic-scene-key', 'traffic' => 200,
                    'conversion' => 2, 'rate_unit' => 'percentage_point', 'revenue' => 2000],
            ]];
    }

    private function review(): array
    {
        $data = ['period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'source_ref' => 'synthetic-finance', 'basis' => 'whole_hotel_actual_cash',
            'available_room_nights' => 1000, 'sold_room_nights' => 800, 'revenue' => 100000,
            'operating_cost' => 70000, 'debt_service' => 2000, 'project_net_cash' => 28000,
            'investor_received_cash' => 0];
        return ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'actual' => $data,
            'plan' => array_replace($data, ['source_ref' => 'synthetic-plan', 'revenue' => 120000])];
    }

    private function geo(): array
    {
        return ['question' => '合成问题', 'model' => 'manual-record', 'model_version' => 'v1',
            'region' => '测试城市', 'network' => '合成网络', 'observed_at' => '2026-10-03 12:00',
            'response_summary' => '合成回答摘要', 'source_ref' => 'synthetic-model-screen',
            'citations' => [['url' => 'https://example.com/evidence', 'fact_consistency' => 'unverified']]];
    }

    public function testProfilePreservesZeroAndManualProvenanceOnNormalizedReplay(): void
    {
        $input = ['fields' => [['key' => ' rooms ', 'value' => 0, 'unit' => ' 间 ',
            'source_ref' => ' synthetic-file ', 'as_of' => '2026-10-03', 'quality' => 'operator_attested']]];
        $result = $this->calculate('profile', $input);
        self::assertSame('recorded', $result['status']);
        self::assertSame('0', $result['fields'][0]['value']);
        self::assertSame('synthetic-file', $result['fields'][0]['source_ref']);
        self::assertFalse($result['contains_verified_hotel_fact']);
        self::assertSame($result, $this->calculate('profile', $result['inputs']));
    }

    public function testProfileWithoutSourceOrDateKeepsValueUnverified(): void
    {
        $result = $this->calculate('profile', ['fields' => [['key' => 'rooms', 'value' => '60', 'quality' => 'operator_attested']]]);
        self::assertSame('partial', $result['status']);
        self::assertSame('60', $result['fields'][0]['value']);
        self::assertSame('unverified', $result['fields'][0]['status']);
        self::assertNull($result['fields'][0]['as_of']);
        self::assertSame(['rooms'], $result['missing_items']);
    }

    #[DataProvider('invalidProfileInputs')]
    public function testProfileRejectsInvalidRecordStructure(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculate('profile', $input);
    }

    public static function invalidProfileInputs(): array
    {
        return ['duplicate normalized key' => [['fields' => [['key' => 'rooms'], ['key' => ' rooms ']]]],
            'structured value' => [['fields' => [['key' => 'rooms', 'value' => ['60']]]]],
            'unknown quality' => [['fields' => [['key' => 'rooms', 'quality' => 'verified_fact']]]],
            'invalid source date' => [['fields' => [['key' => 'rooms', 'as_of' => '2026-02-30']]]],
            'non-list fields' => [['fields' => ['rooms' => ['key' => 'rooms']]]]];
    }

    #[DataProvider('validObservationTimes')]
    public function testObservationTimesPreserveAcceptedLegacyFormats(string $time): void
    {
        $ota = $this->ota();
        $ota['scene']['observed_at'] = $time;
        $result = $this->calculate('ota_scene', $ota);
        self::assertTrue($result['comparison_ready']);
        self::assertSame($time, $result['inputs']['scene']['observed_at']);
        $geo = $this->geo();
        $geo['observed_at'] = $time;
        self::assertSame($time, $this->calculate('geo_observation', $geo)['inputs']['observed_at']);
    }

    public static function validObservationTimes(): array
    {
        return array_map(static fn(string $time): array => [$time], ['2026-10-03 12:00', '2026-10-03T12:00',
            '2026-10-03 12:00:59', '2026-10-03T12:00:59+08:00', '2024-02-29T00:00+08:00']);
    }

    #[DataProvider('invalidObservationTimes')]
    public function testObservationCannotBecomeRecordedWithInvalidTime(string $mode, string $time): void
    {
        $input = $mode === 'ota_scene' ? $this->ota() : $this->geo();
        if ($mode === 'ota_scene') $input['scene']['observed_at'] = $time;
        else $input['observed_at'] = $time;
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($mode, $input);
    }

    public static function invalidObservationTimes(): array
    {
        $cases = [];
        foreach (['ota_scene', 'geo_observation'] as $mode) {
            foreach (['not-a-date', '2026-02-30 12:00', '2026-10-03 24:00', '2026-10-03 12:60',
                '2026-10-03 12:00:60', '2026-10-03', '2026-10-03T12:00Z'] as $time) {
                $cases[$mode . ' ' . $time] = [$mode, $time];
            }
        }
        return $cases;
    }

    public function testOtaMissingObservationTimeRetainsPartialRecordWithoutComparison(): void
    {
        $input = $this->ota();
        $input['scene']['observed_at'] = '';
        $result = $this->calculate('ota_scene', $input);
        self::assertSame('partial', $result['status']);
        self::assertContains('scene.observed_at', $result['missing_items']);
        self::assertFalse($result['comparison_ready']);
        self::assertNull($result['comparison_key']);
    }

    public function testOtaFractionRateAndNormalizedReplayKeepSameScene(): void
    {
        $result = $this->calculate('ota_scene', $this->ota());
        self::assertSame(1.0, $result['conversion_percentage_point']);
        self::assertSame($result, $this->calculate('ota_scene', $result['inputs']));
        self::assertFalse($result['contains_verified_ota_fact']);
    }

    public function testOtaRejectsRankBeyondExplicitObservationRange(): void
    {
        $input = $this->ota();
        $input['observed_through_rank'] = 4;
        $this->expectException(InvalidArgumentException::class);
        $this->calculate('ota_scene', $input);
    }

    public function testMarketMixedExplicitRateUnitsNormalizeToSamePercentageBasis(): void
    {
        $result = $this->calculate('market_sample', $this->market());
        self::assertSame('calculated_reference', $result['status']);
        self::assertSame(1.0, $result['items'][0]['conversion']);
        self::assertSame(0.0, $result['items'][0]['reference_score']);
        self::assertSame(100.0, $result['items'][1]['reference_score']);
        self::assertSame($result, $this->calculate('market_sample', $result['inputs']));
        self::assertFalse($result['official_platform_score']);
        self::assertFalse($result['automatic_grading']);
    }

    public function testMarketMissingUnusedMetricDoesNotInventValueOrBlockTrafficOnlyModel(): void
    {
        $input = $this->market();
        $input['weights'] = ['traffic' => 1, 'conversion' => 0, 'revenue' => 0];
        foreach ($input['hotels'] as &$hotel) { $hotel['conversion'] = null; $hotel['revenue'] = null; }
        unset($hotel);
        $result = $this->calculate('market_sample', $input);
        self::assertSame('calculated_reference', $result['status']);
        self::assertSame(100.0, $result['items'][1]['reference_score']);
        self::assertNull($result['items'][0]['conversion']);
        self::assertNull($result['items'][0]['scores']['conversion']);
        self::assertSame(['present' => 0, 'total' => 2], $result['coverage']['conversion']);
    }

    public function testMarketConstantActiveMetricCannotGenerateNeutralScore(): void
    {
        $input = $this->market();
        $input['hotels'][1]['traffic'] = 100;
        $result = $this->calculate('market_sample', $input);
        self::assertSame('partial', $result['status']);
        foreach ($result['items'] as $hotel) {
            self::assertNull($hotel['reference_score']);
            self::assertNull($hotel['scores']['traffic']);
        }
    }

    public function testMarketMissingConversionOnlyIdentifiesHotelWithAbsentValue(): void
    {
        $input = $this->market();
        $input['weights'] = ['traffic' => 0.3, 'conversion' => 0.3, 'revenue' => 0.4];
        $input['hotels'][0]['platform_store_id'] = 'store.80';
        $input['hotels'][0]['conversion'] = 1;
        $input['hotels'][0]['rate_unit'] = 'percentage_point';
        $input['hotels'][1]['platform_store_id'] = 'store.81';
        $input['hotels'][1]['conversion'] = null;
        $result = $this->calculate('market_sample', $input);
        self::assertSame('partial', $result['status']);
        self::assertSame(['store.81.conversion'], $result['missing_items']);
        self::assertSame(['conversion' => 'incomplete_metric_coverage'], $result['score_unavailable_reasons']);
        self::assertSame(1.0, $result['items'][0]['conversion']);
        self::assertNull($result['items'][1]['conversion']);
        foreach ($result['items'] as $hotel) {
            self::assertNull($hotel['reference_score']);
            self::assertNull($hotel['scores']['conversion']);
        }
        self::assertSame($result, $this->calculate('market_sample', $result['inputs']));
    }

    public function testMarketConstantBaselineDoesNotMisidentifyRecordedMetricsAsMissing(): void
    {
        $input = $this->market();
        $input['hotels'][1]['traffic'] = $input['hotels'][0]['traffic'];
        $result = $this->calculate('market_sample', $input);
        self::assertSame('partial', $result['status']);
        self::assertSame([], $result['missing_items']);
        self::assertSame(['traffic' => 'no_metric_variation'], $result['score_unavailable_reasons']);
        foreach ($result['items'] as $hotel) self::assertNull($hotel['reference_score']);
    }

    public function testSingleMarketSampleReportsBaselineLimitWithoutInventingMissingData(): void
    {
        $input = $this->market();
        $input['hotels'] = [$input['hotels'][0]];
        $result = $this->calculate('market_sample', $input);
        self::assertSame('partial', $result['status']);
        self::assertSame([], $result['missing_items']);
        self::assertSame('insufficient_sample_size', $result['score_unavailable_reasons']['sample']);
        self::assertNull($result['items'][0]['reference_score']);
    }

    #[DataProvider('invalidMarketFields')]
    public function testMarketRejectsInvalidWeightsIdentityOrRate(string $case): void
    {
        $input = $this->market();
        if ($case === 'duplicate id') $input['hotels'][1]['platform_store_id'] = '80';
        if ($case === 'weight sum') $input['weights']['traffic'] = 0.5;
        if ($case === 'missing weight') unset($input['weights']['traffic']);
        if ($case === 'rate above fraction') $input['hotels'][0]['conversion'] = 2;
        if ($case === 'boolean attestation') $input['comparison_attested'] = 'true';
        $this->expectException(InvalidArgumentException::class);
        $this->calculate('market_sample', $input);
    }

    public static function invalidMarketFields(): array
    {
        return array_map(static fn(string $case): array => [$case], ['duplicate id', 'weight sum', 'missing weight',
            'rate above fraction', 'boolean attestation']);
    }

    #[DataProvider('reviewSides')]
    public function testReviewMissingSourceCannotProduceComparisonNumbers(string $side): void
    {
        $input = $this->review();
        $input[$side]['source_ref'] = ' ';
        $result = $this->calculate('operating_review', $input);
        self::assertSame('partial', $result['status']);
        self::assertTrue($result['scope_aligned']);
        self::assertFalse($result['comparison_ready']);
        self::assertContains($side . '.source_ref', $result['missing_items']);
        if ($side === 'actual') self::assertNull($result['actual_cost_ratio']);
        else self::assertSame(0.7, $result['actual_cost_ratio']);
        foreach ($result['rows'] as $row) self::assertNull($row['difference']);
        self::assertSame(100000.0, $result['inputs']['actual']['revenue']);
    }

    public static function reviewSides(): array { return [['actual'], ['plan']]; }

    public function testReviewMissingValueIdentifiesActualSideAndRetainsKnownComparisons(): void
    {
        $input = $this->review();
        unset($input['actual']['revenue']);
        $result = $this->calculate('operating_review', $input);
        self::assertSame('partial', $result['status']);
        self::assertContains('actual.revenue', $result['missing_items']);
        self::assertNotContains('plan.revenue', $result['missing_items']);
        self::assertContains('revenue', $result['missing_items']);
        $rows = array_column($result['rows'], null, 'metric');
        self::assertNull($rows['revenue']['difference']);
        self::assertSame(0.0, $rows['operating_cost']['difference']);
    }

    #[DataProvider('tinyCashDifferences')]
    public function testReviewPreservesRepresentableNonzeroCashDifference(float $actual, float $plan): void
    {
        $input = $this->review();
        $input['actual']['investor_received_cash'] = $actual;
        $input['plan']['investor_received_cash'] = $plan;
        $result = $this->calculate('operating_review', $input);
        $rows = array_column($result['rows'], null, 'metric');
        self::assertSame($actual - $plan, $rows['investor_received_cash']['difference']);
        self::assertNotSame(0.0, $rows['investor_received_cash']['difference']);
    }

    public static function tinyCashDifferences(): array
    {
        return [[1e-7, 0.0], [0.0, 1e-7], [4e-8, 2e-8]];
    }

    public function testReviewZeroRevenueAndSeparateCashKeepFiniteNormalizedReadback(): void
    {
        $input = $this->review();
        $input['actual']['revenue'] = 0;
        $input['actual']['project_net_cash'] = -2000;
        $result = $this->calculate('operating_review', $input);
        self::assertSame('compared', $result['status']);
        self::assertTrue($result['comparison_ready']);
        self::assertNull($result['actual_cost_ratio']);
        $rows = array_column($result['rows'], null, 'metric');
        self::assertSame(-2000.0, $rows['project_net_cash']['actual']);
        self::assertSame(0.0, $rows['investor_received_cash']['actual']);
        self::assertFalse($result['project_cash_equals_investor_recovery']);
        self::assertSame($result, $this->calculate('operating_review', $result['inputs']));
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testReviewRejectsSoldRoomNightsBeyondAvailable(): void
    {
        $input = $this->review();
        $input['actual']['sold_room_nights'] = 1001;
        $this->expectException(InvalidArgumentException::class);
        $this->calculate('operating_review', $input);
    }

    public function testGeoMissingSummaryCannotBecomeRecordedOrClaimEffect(): void
    {
        $input = $this->geo();
        $input['response_summary'] = '';
        $result = $this->calculate('geo_observation', $input);
        self::assertSame('partial', $result['status']);
        self::assertContains('response_summary', $result['missing_items']);
        self::assertFalse($result['model_called']);
        self::assertFalse($result['marketing_effect_claimed']);
        self::assertFalse($result['order_attribution_verified']);
    }

    public function testGeoEnvironmentKeyIsStableAcrossAnswersButChangesWithModelVersion(): void
    {
        $first = $this->calculate('geo_observation', $this->geo());
        $input = $this->geo();
        $input['response_summary'] = '另一次合成回答';
        $input['observed_at'] = '2026-10-03 12:05';
        $input['source_ref'] = 'synthetic-model-screen-2';
        self::assertSame($first['experiment_key'], $this->calculate('geo_observation', $input)['experiment_key']);
        $input['model_version'] = 'v2';
        self::assertNotSame($first['experiment_key'], $this->calculate('geo_observation', $input)['experiment_key']);
        self::assertSame($first, $this->calculate('geo_observation', $first['inputs']));
    }

    public function testGeoRejectsNonHttpCitation(): void
    {
        $input = $this->geo();
        $input['citations'][0]['url'] = 'file:///synthetic-document';
        $this->expectException(InvalidArgumentException::class);
        $this->calculate('geo_observation', $input);
    }
}
