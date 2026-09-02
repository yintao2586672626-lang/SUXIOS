<?php
declare(strict_types=1);

namespace Tests\Support\OnlineData;

trait DailyOtaReviewTestCases
{
    public function testDailyOtaSupplementSummaryBuildsSeparateTruthfulReviewPlatforms(): void
    {
        $controller = $this->controller();

        $verifiedTruth = $this->verifiedOtaTruth();
        $summary = $this->invokeNonPublic($controller, 'buildDailyOtaSupplementSummary', [[
            [
                'data_type' => 'advertising',
                'amount' => 100,
                'list_exposure' => 1000,
                'detail_exposure' => 100,
                'book_order_num' => 4,
                'raw_data' => json_encode(['orderAmount' => 500], JSON_UNESCAPED_UNICODE),
                'truth' => $this->verifiedOtaTruth(),
            ],
            [
                'data_type' => 'quality',
                'data_value' => 86.5,
                'raw_data' => json_encode(['serviceScore' => 91], JSON_UNESCAPED_UNICODE),
                'truth' => $this->verifiedOtaTruth(),
            ],
            [
                'id' => 12,
                'source' => 'ctrip',
                'system_hotel_id' => 80,
                'hotel_id' => 'ctrip-hotel-80',
                'data_type' => 'review',
                'data_date' => '2026-07-18',
                'comment_score' => 4.8,
                'raw_data' => json_encode([
                    'metrics' => [
                        'comment_count' => 577,
                        'bad_review_count' => 6,
                        'comment_unreply_count' => 2,
                        'comment_good_rate' => 98.9,
                        'comment_response_rate' => 92.5,
                        'review_photo_count' => 288,
                        'review_photo_rate' => 49.9,
                        'review_environment_score' => 4.91,
                        'review_facility_score' => 4.75,
                        'review_service_score' => 4.91,
                        'review_cleanliness_score' => 4.75,
                    ],
                    'dimension_values' => ['comment_channel' => '携程'],
                    'content' => '不应进入汇总结果',
                ], JSON_UNESCAPED_UNICODE),
                'truth' => $verifiedTruth,
            ],
            [
                'id' => 11,
                'source' => 'ctrip',
                'system_hotel_id' => 80,
                'hotel_id' => 'ctrip-hotel-80',
                'data_type' => 'review',
                'data_date' => '2026-07-17',
                'comment_score' => 4.7,
                'raw_data' => json_encode([
                    'metrics' => ['comment_count' => 575, 'bad_review_count' => 5],
                    'dimension_values' => ['comment_channel' => '携程'],
                ], JSON_UNESCAPED_UNICODE),
                'truth' => $verifiedTruth,
            ],
            [
                'id' => 10,
                'source' => 'ctrip',
                'system_hotel_id' => 80,
                'hotel_id' => 'ctrip-hotel-80',
                'data_type' => 'review',
                'data_date' => '2026-07-18',
                'comment_score' => 4.6,
                'raw_data' => json_encode([
                    'metrics' => ['comment_count' => 649, 'bad_review_count' => 2],
                    'dimension_values' => ['comment_channel' => '去哪儿'],
                ], JSON_UNESCAPED_UNICODE),
                'truth' => $verifiedTruth,
            ],
            [
                'id' => 9,
                'source' => 'meituan',
                'system_hotel_id' => 80,
                'hotel_id' => 'meituan-hotel-80',
                'data_type' => 'review',
                'data_date' => '2026-07-18',
                'comment_score' => 4.61,
                'quantity' => 534,
                'data_value' => 3,
                'dimension' => 'review:meituan',
                'raw_data' => json_encode(['comment_score_present' => true], JSON_UNESCAPED_UNICODE),
                'truth' => $verifiedTruth,
            ],
            [
                'id' => 13,
                'source' => 'ctrip',
                'system_hotel_id' => 80,
                'hotel_id' => 'ctrip-hotel-80',
                'data_type' => 'review',
                'data_date' => '2026-07-19',
                'comment_score' => 1.0,
                'raw_data' => json_encode([
                    'metrics' => ['comment_count' => 9999],
                    'dimension_values' => ['comment_channel' => '携程'],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]]);

        self::assertSame('ota_channel', $summary['scope']);
        self::assertSame('ok', $summary['data_status']);
        self::assertSame(100.0, $summary['advertising']['spend']);
        self::assertSame(500.0, $summary['advertising']['order_amount']);
        self::assertSame(5.0, $summary['advertising']['roas']);
        self::assertSame(1, $summary['service_quality']['sample_count']);
        self::assertSame(86.5, $summary['service_quality']['avg_psi_score']);
        self::assertSame(91.0, $summary['service_quality']['avg_service_score']);
        self::assertSame('platform_separate_no_cross_platform_average', $summary['reviews']['score_aggregation']);
        self::assertArrayNotHasKey('avg_score', $summary['reviews']);

        $platforms = [];
        foreach ($summary['reviews']['platforms'] as $platform) {
            $platforms[$platform['source']] = $platform;
        }
        self::assertSame(4.8, $platforms['ctrip']['score']);
        self::assertSame(577, $platforms['ctrip']['review_count']);
        self::assertSame(6, $platforms['ctrip']['bad_review_count']);
        self::assertSame(0.1, $platforms['ctrip']['score_change']);
        self::assertSame(2, $platforms['ctrip']['review_count_change']);
        self::assertSame('adjacent_business_day', $platforms['ctrip']['review_count_change_basis']);
        self::assertSame(2, $platforms['ctrip']['latest_day_new_review_count']);
        self::assertSame('2026-07-18', $platforms['ctrip']['latest_day_new_review_date']);
        self::assertSame(1, $platforms['ctrip']['bad_review_count_change']);
        self::assertSame(2, $platforms['ctrip']['unreplied_count']);
        self::assertSame(98.9, $platforms['ctrip']['good_rate']);
        self::assertSame(92.5, $platforms['ctrip']['response_rate']);
        self::assertSame(288, $platforms['ctrip']['review_photo_count']);
        self::assertSame(49.9, $platforms['ctrip']['review_photo_rate']);
        self::assertSame([
            'environment' => 4.91,
            'facility' => 4.75,
            'service' => 4.91,
            'cleanliness' => 4.75,
        ], $platforms['ctrip']['quality_dimensions']);
        self::assertSame('2026-07-18', $platforms['ctrip']['latest_data_date']);
        self::assertSame(80, $platforms['ctrip']['identity']['system_hotel_id']);
        self::assertSame('ctrip-hotel-80', $platforms['ctrip']['identity']['platform_store_id']);
        self::assertCount(2, $platforms['ctrip']['trend']);
        self::assertSame(['去哪儿', '携程'], array_column($platforms['ctrip']['channels'], 'label'));
        self::assertSame(4.61, $platforms['meituan']['score']);
        self::assertSame(534, $platforms['meituan']['review_count']);
        self::assertSame(3, $platforms['meituan']['bad_review_count']);
        self::assertStringNotContainsString('不应进入汇总结果', json_encode($summary['reviews'], JSON_UNESCAPED_UNICODE));
    }

    public function testDailyOtaReviewSummaryKeepsUnverifiedAndMissingMetricsExplicit(): void
    {
        $controller = $this->controller();
        $summary = $this->invokeNonPublic($controller, 'buildDailyOtaReviewSummary', [[
            [
                'id' => 1,
                'source' => 'ctrip',
                'system_hotel_id' => 80,
                'data_type' => 'review',
                'data_date' => '2026-07-18',
                'comment_score' => 4.9,
                'raw_data' => json_encode(['metrics' => ['comment_count' => 100]], JSON_UNESCAPED_UNICODE),
            ],
        ], []]);

        $platforms = [];
        foreach ($summary['platforms'] as $platform) {
            $platforms[$platform['source']] = $platform;
        }
        self::assertSame('unverified', $summary['data_status']);
        self::assertSame('unverified', $platforms['ctrip']['data_status']);
        self::assertNull($platforms['ctrip']['score']);
        self::assertNull($platforms['ctrip']['review_count']);
        self::assertSame('missing', $platforms['meituan']['data_status']);
        self::assertNull($platforms['meituan']['score']);
        self::assertNull($platforms['meituan']['review_count']);

        $mixed = $this->invokeNonPublic($controller, 'buildDailyOtaReviewSummary', [[
            [
                'id' => 2,
                'source' => 'ctrip',
                'system_hotel_id' => 80,
                'data_type' => 'review',
                'data_date' => '2026-07-18',
                'comment_score' => 4.8,
                'raw_data' => json_encode(['metrics' => ['comment_count' => 100]], JSON_UNESCAPED_UNICODE),
                'truth' => $this->verifiedOtaTruth(),
            ],
            [
                'id' => 3,
                'source' => 'ctrip',
                'system_hotel_id' => 81,
                'data_type' => 'review',
                'data_date' => '2026-07-18',
                'comment_score' => 4.9,
                'raw_data' => json_encode(['metrics' => ['comment_count' => 200]], JSON_UNESCAPED_UNICODE),
                'truth' => $this->verifiedOtaTruth(),
            ],
        ], []]);
        self::assertSame('hotel_scope_mixed', $mixed['scope_blocker']);
        self::assertSame('mixed', $mixed['identity']['hotel_scope_status']);
        self::assertSame('unverified', $mixed['data_status']);
        self::assertNull($mixed['platforms'][0]['score']);

        self::assertSame('previous_available_snapshot', $this->invokeNonPublic(
            $controller,
            'dailyOtaReviewChangeBasis',
            [
                ['data_date' => '2026-07-18', 'review_count' => 10],
                ['data_date' => '2026-07-11', 'review_count' => 8],
            ]
        ));
        self::assertSame('rebaseline_required', $this->invokeNonPublic(
            $controller,
            'dailyOtaReviewChangeBasis',
            [
                ['data_date' => '2026-07-18', 'review_count' => 7],
                ['data_date' => '2026-07-17', 'review_count' => 8],
            ]
        ));
    }

}
