<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDataAnalysisReportService;
use PHPUnit\Framework\TestCase;

final class OnlineDataAnalysisReportServiceTest extends TestCase
{
    public function testRenderIncludesCoreMetricsAndSuggestionsByDefault(): void
    {
        $html = (new OnlineDataAnalysisReportService())->render($this->sampleReportData());

        self::assertStringContainsString('space-y-6', $html);
        self::assertStringContainsString('Hotel Alpha', $html);
        self::assertStringContainsString('Hotel Beta', $html);
        self::assertStringContainsString('1,234', $html);
        self::assertStringContainsString('98,765', $html);
        self::assertStringContainsString('bg-gradient-to-r', $html);
        self::assertStringContainsString('fas fa-clock', $html);
    }

    public function testRenderCanExcludeSuggestions(): void
    {
        $html = (new OnlineDataAnalysisReportService())->render($this->sampleReportData(), false);

        self::assertStringContainsString('Hotel Alpha', $html);
        self::assertStringNotContainsString('bg-gradient-to-r', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleReportData(): array
    {
        return [
            'hotel_count' => 2,
            'total_room_nights' => 1234,
            'total_room_revenue' => 98765,
            'total_sales' => 123456,
            'total_exposure' => 5000,
            'total_views' => 900,
            'avg_room_nights' => 617,
            'avg_room_revenue' => 49382.5,
            'avg_price_per_night' => 321,
            'avg_view_conversion' => 18.2,
            'avg_pay_conversion' => 4.1,
            'top5_by_room_nights' => [
                ['hotelName' => 'Hotel Alpha', 'roomNights' => 800, 'roomRevenue' => 60000],
                ['hotelName' => 'Hotel Beta', 'roomNights' => 434, 'roomRevenue' => 38765],
            ],
            'top5_by_revenue' => [
                ['hotelName' => 'Hotel Alpha', 'roomNights' => 800, 'roomRevenue' => 60000],
                ['hotelName' => 'Hotel Beta', 'roomNights' => 434, 'roomRevenue' => 38765],
            ],
        ];
    }
}
