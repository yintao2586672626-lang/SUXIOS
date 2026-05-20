<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaInsightAnalysisService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class OtaStandardModuleTest extends TestCase
{
    public function testEtlBuildsStarSchemaFromOnlineDailyRows(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->sampleRows());

        self::assertSame('ready', $dataset['status']);
        self::assertCount(2, $dataset['dim_hotel']);
        self::assertCount(2, $dataset['dim_platform']);
        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertCount(1, $dataset['fact_ota_traffic']);
        self::assertCount(1, $dataset['fact_ota_comment']);

        self::assertSame('system:7', $dataset['fact_ota_daily'][0]['hotel_key']);
        self::assertSame('ctrip', $dataset['fact_ota_daily'][0]['platform_key']);
        self::assertSame(1200.0, $dataset['fact_ota_daily'][0]['revenue']);
        self::assertSame(6.0, $dataset['fact_ota_daily'][0]['room_nights']);
        self::assertSame(4, $dataset['fact_ota_daily'][0]['order_count']);
        self::assertSame(200.0, $dataset['fact_ota_daily'][0]['adr']);

        self::assertSame(20.0, $dataset['fact_ota_traffic'][0]['flow_rate']);
        self::assertSame(33.33, $dataset['fact_ota_traffic'][0]['submit_rate']);
        self::assertSame('negative', $dataset['fact_ota_comment'][0]['sentiment']);
        self::assertSame([], $dataset['data_quality']['rejected_rows']);
    }

    public function testRevenueMetricsUseStandardFactsWithoutInventingMissingCancellationData(): void
    {
        $etl = new OtaStandardEtlService();
        $dataset = $etl->buildDatasetFromRows($this->sampleRows());
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(1200.0, $metrics['totals']['revenue']);
        self::assertSame(6.0, $metrics['totals']['room_nights']);
        self::assertSame(4, $metrics['totals']['order_count']);
        self::assertSame(200.0, $metrics['totals']['adr']);
        self::assertSame(25.0, $metrics['totals']['cancellation_rate']);
        self::assertSame(20.0, $metrics['traffic']['avg_flow_rate']);
        self::assertSame(33.33, $metrics['traffic']['avg_submit_rate']);
        self::assertSame(-20.0, $metrics['competitor_price']['avg_price_gap']);

        $missingCancel = (new OtaRevenueMetricService())->summarizeDataset($etl->buildDatasetFromRows([
            array_replace($this->sampleRows()[0], [
                'raw_data' => json_encode(['our_price' => 200, 'competitor_price' => 220], JSON_UNESCAPED_UNICODE),
            ]),
        ]));

        self::assertNull($missingCancel['totals']['cancellation_rate']);
        self::assertContains('cancellation_fields_missing', array_column($missingCancel['data_gaps'], 'code'));

        $partialCancel = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                ['platform_key' => 'ctrip', 'hotel_key' => 'system:7', 'revenue' => 100.0, 'room_nights' => 1.0, 'order_count' => 10, 'cancel_order_num' => 2],
                ['platform_key' => 'ctrip', 'hotel_key' => 'system:7', 'revenue' => 900.0, 'room_nights' => 9.0, 'order_count' => 90, 'cancel_order_num' => null],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertSame(20.0, $partialCancel['totals']['cancellation_rate']);
        self::assertContains('cancellation_fields_partial', array_column($partialCancel['data_gaps'], 'code'));
    }

    public function testEtlUsesPlatformFallbackAndRecursivelySanitizesRawData(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 10,
                'system_hotel_id' => 9,
                'hotel_id' => 'poi-9',
                'hotel_name' => 'Hotel Gamma',
                'source' => '',
                'platform' => 'Meituan',
                'data_type' => 'business',
                'data_date' => '2026-05-19',
                'amount' => 300,
                'quantity' => 2,
                'book_order_num' => 1,
                'raw_data' => json_encode([
                    'headers' => [
                        'Cookie' => 'secret-cookie',
                        'nested' => ['accessToken' => 'secret-token'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertSame('ready', $dataset['status']);
        self::assertSame('meituan', $dataset['fact_ota_daily'][0]['platform_key']);
        self::assertArrayNotHasKey('Cookie', $dataset['fact_ota_daily'][0]['raw_data']['headers']);
        self::assertArrayNotHasKey('accessToken', $dataset['fact_ota_daily'][0]['raw_data']['headers']['nested']);
    }

    public function testEtlRejectsInvalidDateFiltersInsteadOfWideningScope(): void
    {
        $method = new ReflectionMethod(OtaStandardEtlService::class, 'filterDateValue');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid start_date');

        $method->invoke(new OtaStandardEtlService(), '2026/05/19', 'start_date');
    }

    public function testEtlSourceFilterValuesIncludeProjectAliases(): void
    {
        $method = new ReflectionMethod(OtaStandardEtlService::class, 'sourceFilterValues');
        $method->setAccessible(true);

        $values = $method->invoke(new OtaStandardEtlService(), 'meituan');

        self::assertContains('meituan', $values);
        self::assertContains('meituan_rank', $values);
        self::assertContains('meituan_business', $values);
        self::assertContains('meituan_browser_profile', $values);
    }

    public function testInsightAnalysisPrioritizesAdrCancellationTrafficAndCompetitorPriceWithoutLstm(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->sampleRows());
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);

        self::assertSame('deterministic_rules', $analysis['model_policy']['model_type']);
        self::assertContains('LSTM', $analysis['model_policy']['excluded_models']);

        $keys = array_column($analysis['modules'], 'key');
        self::assertSame(['adr', 'cancellation_rate', 'traffic_conversion', 'competitor_price_gap'], $keys);
        self::assertSame('available', $analysis['modules'][0]['status']);
        self::assertSame('available', $analysis['modules'][1]['status']);
        self::assertSame('watch', $analysis['modules'][3]['status']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sampleRows(): array
    {
        return [
            [
                'id' => 1,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-18',
                'amount' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'comment_score' => 4.8,
                'raw_data' => json_encode([
                    'cancel_order_num' => 1,
                    'our_price' => 200,
                    'competitor_price' => 220,
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 2,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-18',
                'list_exposure' => 1000,
                'detail_exposure' => 200,
                'flow_rate' => 20,
                'order_filling_num' => 30,
                'order_submit_num' => 10,
                'raw_data' => '{}',
            ],
            [
                'id' => 3,
                'system_hotel_id' => 8,
                'hotel_id' => 'poi-8',
                'hotel_name' => 'Hotel Beta',
                'source' => 'meituan',
                'data_type' => 'review',
                'data_date' => '2026-05-18',
                'comment_score' => 3.0,
                'data_value' => 3.0,
                'raw_data' => json_encode([
                    'review_id' => 'r-1',
                    'content' => 'front desk issue',
                    'sentiment' => 'negative',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
    }
}
