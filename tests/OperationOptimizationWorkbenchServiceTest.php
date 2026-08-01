<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationOptimizationWorkbenchService;
use PHPUnit\Framework\TestCase;

final class OperationOptimizationWorkbenchServiceTest extends TestCase
{
    public function testWorkbenchKeepsChannelMetricsSeparateAndBuildsDraftTasksFromVerifiedEvidence(): void
    {
        $dataset = [
            'status' => 'ready',
            'fact_ota_search_keyword' => [
                $this->trusted([
                    'date_key' => '2026-07-26',
                    'platform_key' => 'meituan',
                    'keyword' => '商务酒店',
                    'impressions' => 1000,
                    'clicks' => 50,
                    'order_contribution' => 5,
                    'raw_data' => [],
                ], 11),
            ],
            'fact_ota_advertising' => [
                $this->trusted([
                    'date_key' => '2026-07-26',
                    'platform_key' => 'meituan',
                    'impressions' => 800,
                    'clicks' => 40,
                    'bookings' => 4,
                    'spend' => 100,
                    'order_amount' => 500,
                    'raw_data' => ['keyword' => '商务酒店', 'landingRoomType' => '高级大床房'],
                ], 12),
                $this->trusted([
                    'date_key' => '2026-07-26',
                    'platform_key' => 'meituan',
                    'impressions' => 400,
                    'clicks' => 20,
                    'bookings' => 0,
                    'spend' => 80,
                    'order_amount' => 0,
                    'raw_data' => ['keyword' => '近地铁酒店'],
                ], 13),
            ],
            'fact_ota_daily' => [
                $this->trusted([
                    'date_key' => '2026-07-26',
                    'platform_key' => 'ctrip',
                    'data_type' => 'business',
                    'room_revenue' => 6000,
                    'room_nights' => 30,
                    'order_count' => 20,
                    'our_price' => 240,
                    'competitor_price' => 220,
                    'price_gap' => 20,
                    'raw_data' => ['roomTypeName' => '高级大床房', 'conversionRate' => 8],
                ], 21),
                $this->trusted([
                    'date_key' => '2026-07-26',
                    'platform_key' => 'ctrip',
                    'data_type' => 'business',
                    'room_revenue' => 4000,
                    'room_nights' => 20,
                    'order_count' => 12,
                    'raw_data' => ['roomTypeName' => '商务双床房', 'conversionRate' => 10],
                ], 22),
            ],
            'fact_ota_traffic' => [
                $this->trusted([
                    'date_key' => '2026-07-26',
                    'platform_key' => 'ctrip',
                    'list_exposure' => 1200,
                    'flow_rate' => 8.2,
                ], 31),
                $this->trusted([
                    'date_key' => '2026-07-26',
                    'platform_key' => 'meituan',
                    'list_exposure' => 900,
                    'flow_rate' => 6.4,
                ], 32),
            ],
            'data_quality' => ['accepted_rows' => 8],
        ];

        $result = (new OperationOptimizationWorkbenchService())->build($dataset, [
            'hotel_id' => 77,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-26',
        ]);

        self::assertSame('ready', $result['status']);
        self::assertSame('ota_channel', $result['metric_scope']);
        self::assertSame(2, $result['summary']['available_modules']);

        $keywords = $this->indexBy($result['keyword_workbench']['rows'], 'keyword');
        self::assertSame(800.0, $keywords['商务酒店']['impressions'], 'Paid keyword facts must replace, not add to, search-ranking exposure.');
        self::assertSame(5.0, $keywords['商务酒店']['ctr']);
        self::assertSame(5.0, $keywords['商务酒店']['roas']);
        self::assertSame('高级大床房', $keywords['商务酒店']['landing_room_type']);
        self::assertSame('pause_review', $keywords['近地铁酒店']['recommendation']['code']);
        self::assertTrue($keywords['近地铁酒店']['recommendation']['can_create_task']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $keywords['近地铁酒店']['recommendation']['id']);
        self::assertSame('pending_approval', $keywords['近地铁酒店']['recommendation']['task_payload']['status']);
        self::assertSame('operation_optimizer', $keywords['近地铁酒店']['recommendation']['task_payload']['source_module']);
        self::assertSame(
            $keywords['近地铁酒店']['recommendation']['id'],
            $keywords['近地铁酒店']['recommendation']['task_payload']['evidence']['optimizer_action_id']
        );
        self::assertFalse($keywords['近地铁酒店']['recommendation']['task_payload']['evidence']['auto_write_ota']);
        self::assertSame(0.01, $keywords['近地铁酒店']['recommendation']['task_payload']['expected_delta']);
        self::assertSame(
            'meituan-hotel-77',
            $keywords['近地铁酒店']['recommendation']['task_payload']['evidence']['platform_hotel_id']
        );
        self::assertSame(
            'ota_channel_advertising',
            $keywords['近地铁酒店']['recommendation']['task_payload']['evidence']['fact_scope']
        );
        self::assertSame(
            'same_length_period_after_manual_execution',
            $keywords['近地铁酒店']['recommendation']['task_payload']['evidence']['review_policy']['review_window']['mode']
        );
        self::assertSame(
            7,
            $keywords['近地铁酒店']['recommendation']['task_payload']['evidence']['review_policy']['review_window']['length_days']
        );
        self::assertSame(
            ['online_daily_data#13', 'source_trace:trace-13'],
            $keywords['近地铁酒店']['recommendation']['task_payload']['evidence']['evidence_refs'],
            'Advertising metrics must not keep unrelated search-keyword evidence.'
        );

        $rooms = $this->indexBy($result['room_product_mix']['rows'], 'room_type');
        self::assertSame(60.0, $rooms['高级大床房']['revenue_share']);
        self::assertSame(60.0, $rooms['高级大床房']['room_night_share']);
        self::assertSame(200.0, $rooms['高级大床房']['adr']);
        self::assertSame('price_review', $rooms['高级大床房']['recommendation']['code']);
        self::assertSame('room_product', $rooms['高级大床房']['recommendation']['task_payload']['object_type']);
        self::assertSame('competitor_price_gap', $rooms['高级大床房']['recommendation']['task_payload']['expected_metric']);
        self::assertSame('decrease', $rooms['高级大床房']['recommendation']['task_payload']['target_value']['expected_direction']);

        $differentScope = (new OperationOptimizationWorkbenchService())->build($dataset, [
            'hotel_id' => 78,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-26',
        ]);
        $differentKeywords = $this->indexBy($differentScope['keyword_workbench']['rows'], 'keyword');
        self::assertNotSame(
            $keywords['近地铁酒店']['recommendation']['id'],
            $differentKeywords['近地铁酒店']['recommendation']['id'],
            'Recommendation identity must include hotel and date scope.'
        );

        $channels = $this->indexBy($result['channel_views'], 'platform');
        self::assertSame(1200.0, $channels['ctrip']['questions'][0]['value']);
        self::assertSame(900.0, $channels['meituan']['questions'][0]['value']);
        self::assertStringContainsString('不与另一平台相加', $channels['ctrip']['questions'][0]['definition']);
    }

    public function testMissingOrUnverifiedFactsReturnOneRecoveryActionInsteadOfZeroMetrics(): void
    {
        $dataset = [
            'status' => 'ready',
            'fact_ota_search_keyword' => [[
                'date_key' => '2026-07-26',
                'platform_key' => 'meituan',
                'keyword' => '酒店',
                'impressions' => 100,
                'clicks' => 10,
                'source_trace' => [
                    'stored' => true,
                    'readback_verified' => false,
                    'saved_success' => false,
                ],
            ]],
            'fact_ota_advertising' => [],
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
        ];

        $result = (new OperationOptimizationWorkbenchService())->build($dataset, [
            'hotel_id' => 77,
            'start_date' => '2026-07-26',
            'end_date' => '2026-07-26',
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertSame([], $result['keyword_workbench']['rows']);
        self::assertSame('keyword_data_missing', $result['keyword_workbench']['recovery']['code']);
        self::assertSame('采集美团广告与搜索词', $result['keyword_workbench']['recovery']['action_label']);
        self::assertTrue($result['truth_policy']['missing_is_not_zero']);
        self::assertNull($result['channel_views'][0]['questions'][0]['value']);
    }

    public function testOneAvailableModuleKeepsWorkbenchPartial(): void
    {
        $dataset = [
            'fact_ota_search_keyword' => [],
            'fact_ota_advertising' => [
                $this->trusted([
                    'date_key' => '2026-07-26',
                    'platform_key' => 'meituan',
                    'impressions' => 200,
                    'clicks' => 10,
                    'bookings' => 1,
                    'spend' => 20,
                    'order_amount' => 100,
                    'raw_data' => ['keyword' => '敦煌酒店'],
                ], 41),
            ],
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
        ];

        $result = (new OperationOptimizationWorkbenchService())->build($dataset, [
            'hotel_id' => 77,
            'start_date' => '2026-07-26',
            'end_date' => '2026-07-26',
        ]);

        self::assertSame('partial', $result['status']);
        self::assertSame('ready', $result['keyword_workbench']['status']);
        self::assertSame('blocked', $result['room_product_mix']['status']);
    }

    public function testActionableMetricWithoutPlatformStoreIdentityCannotCreateTask(): void
    {
        $dataset = [
            'fact_ota_search_keyword' => [],
            'fact_ota_advertising' => [[
                'date_key' => '2026-07-26',
                'platform_key' => 'meituan',
                'impressions' => 200,
                'clicks' => 10,
                'bookings' => 0,
                'spend' => 20,
                'order_amount' => 0,
                'raw_data' => ['keyword' => '敦煌酒店'],
                'source_trace' => [
                    'table' => 'online_daily_data',
                    'row_id' => 51,
                    'source_trace_id' => 'trace-51',
                    'ingestion_method' => 'profile_capture',
                    'collected_at' => '2026-07-26 08:00:00',
                    'stored' => true,
                    'readback_verified' => true,
                    'saved_success' => true,
                ],
            ]],
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
        ];

        $result = (new OperationOptimizationWorkbenchService())->build($dataset, [
            'hotel_id' => 77,
            'start_date' => '2026-07-26',
            'end_date' => '2026-07-26',
        ]);
        $row = $result['keyword_workbench']['rows'][0];

        self::assertSame('pause_review', $row['recommendation']['code']);
        self::assertSame('missing', $row['identity_status']);
        self::assertFalse($row['recommendation']['can_create_task']);
        self::assertNull($row['recommendation']['task_payload']);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function trusted(array $row, int $id): array
    {
        $row['source_trace'] = [
            'table' => 'online_daily_data',
            'row_id' => $id,
            'source_trace_id' => 'trace-' . $id,
            'platform_hotel_id' => str_starts_with((string)($row['platform_key'] ?? ''), 'ctrip')
                ? 'ctrip-hotel-77'
                : 'meituan-hotel-77',
            'ingestion_method' => 'profile_capture',
            'collected_at' => (string)($row['date_key'] ?? '2026-07-26') . ' 08:00:00',
            'stored' => true,
            'readback_verified' => true,
            'saved_success' => true,
        ];
        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexBy(array $rows, string $key): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string)$row[$key]] = $row;
        }
        return $indexed;
    }
}
