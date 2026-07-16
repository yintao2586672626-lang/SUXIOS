<?php
declare(strict_types=1);

namespace app\service;

final class OnlineDataAnalysisReportService
{
    public function render(array $data, bool $includeSuggestions = true): string
    {
        $hotelCount = $data['hotel_count'];
        $totalRoomNights = $data['total_room_nights'];
        $totalRoomRevenue = $data['total_room_revenue'];
        $totalSales = $data['total_sales'];
        $totalExposure = $data['total_exposure'];
        $totalViews = $data['total_views'];
        $avgRoomNights = $data['avg_room_nights'];
        $avgRoomRevenue = $data['avg_room_revenue'];
        $avgPricePerNight = $data['avg_price_per_night'];
        $avgViewConversion = $data['avg_view_conversion'];
        $avgPayConversion = $data['avg_pay_conversion'];
        $top5ByRoomNights = $data['top5_by_room_nights'];
        $top5ByRevenue = $data['top5_by_revenue'];
        $unverifiedPreview = (string)($data['trust_status'] ?? '') === 'unverified_client_preview';
        $analysisTitle = $unverifiedPreview ? '竞对展示值预览' : '经营数据分析';
        $roomNightsLabel = $unverifiedPreview ? '展示间夜合计' : '总入住间夜';
        $revenueLabel = $unverifiedPreview ? '展示房费合计' : '总房费收入';
        $previewNoticeHtml = $unverifiedPreview
            ? '<div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800"><strong>未验证竞对数据预览：</strong>这些数值来自客户端当前展示，未经过后端来源、门店和日期口径校验；不得用于本店收益、定价或运营执行。</div>'
            : '';

        // 生成TOP5列表HTML
        $top5RoomNightsHtml = '';
        foreach ($top5ByRoomNights as $i => $hotel) {
            $rank = $i + 1;
            $bgClass = $i === 0 ? 'bg-yellow-50 border-l-4 border-yellow-400' : 'bg-gray-50';
            $badgeClass = $i < 3 ? 'bg-yellow-400 text-white' : 'bg-gray-300 text-white';
            $roomNights = $this->formatOptionalNumber($hotel['roomNights'] ?? null);
            $hotelName = $this->escapeHtml((string)($hotel['hotelName'] ?? '未命名酒店'));
            $top5RoomNightsHtml .= <<<HTML
            <div class="flex items-center justify-between p-2 {$bgClass} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full {$badgeClass} flex items-center justify-center text-xs font-bold mr-2">{$rank}</span>
                    <span class="text-sm font-medium">{$hotelName}</span>
                </div>
                <span class="text-sm font-bold text-blue-600">{$roomNights} 间夜</span>
            </div>
HTML;
        }

        $top5RevenueHtml = '';
        foreach ($top5ByRevenue as $i => $hotel) {
            $rank = $i + 1;
            $bgClass = $i === 0 ? 'bg-green-50 border-l-4 border-green-400' : 'bg-gray-50';
            $badgeClass = $i < 3 ? 'bg-green-400 text-white' : 'bg-gray-300 text-white';
            $revenue = $this->formatOptionalCurrency($hotel['roomRevenue'] ?? null);
            $hotelName = $this->escapeHtml((string)($hotel['hotelName'] ?? '未命名酒店'));
            $top5RevenueHtml .= <<<HTML
            <div class="flex items-center justify-between p-2 {$bgClass} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full {$badgeClass} flex items-center justify-center text-xs font-bold mr-2">{$rank}</span>
                    <span class="text-sm font-medium">{$hotelName}</span>
                </div>
                <span class="text-sm font-bold text-green-600">{$revenue}</span>
            </div>
HTML;
        }

        // 生成建议HTML
        $suggestionsHtml = '';
        if ($includeSuggestions) {
            $pricingAdvice = $avgPricePerNight > 300
                ? '建议关注性价比，可适当推出优惠套餐吸引更多客源'
                : '定价相对亲民，可通过增值服务提升客单价';

            $trafficAdvice = '';
            if ($totalExposure > 0 && $totalViews > 0) {
                $viewRate = ($totalViews / $totalExposure) * 100;
                $trafficAdvice = "曝光到浏览转化率 " . number_format($viewRate, 1) . "%，";
            }
            $trafficAdvice .= $avgViewConversion > 0
                ? "平均浏览转化 " . number_format($avgViewConversion, 1) . "，建议优化详情页图片和描述提升转化率。"
                : '建议关注流量入口优化，提升曝光量和浏览量。';

            $topHotelName = !empty($top5ByRoomNights)
                ? $this->escapeHtml((string)($top5ByRoomNights[0]['hotelName'] ?? '未命名酒店'))
                : '';
            $topHotelNights = !empty($top5ByRoomNights) ? number_format(floatval($top5ByRoomNights[0]['roomNights'] ?? 0)) : '0';

            $marketingAdvice = $totalExposure > $totalViews * 10
                ? '曝光量充足但浏览转化偏低，建议优化主图和标题吸引点击。'
                : '建议增加平台推广投放，扩大曝光量，同时关注评价维护。';

            $suggestionsHtml = <<<HTML
    <!-- AI建议 -->
    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg p-4">
        <h3 class="font-bold text-indigo-800 mb-3 flex items-center">
            <i class="fas fa-lightbulb text-indigo-500 mr-2"></i>AI经营建议
        </h3>
        <div class="space-y-3 text-sm text-gray-700">
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>定价策略：</strong>
                    当前平均房价 ¥{$avgPricePerNight}，{$pricingAdvice}。
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>流量转化：</strong>
                    {$trafficAdvice}
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>竞对分析：</strong>
                    共分析 {$hotelCount} 家竞对酒店，
                    {$topHotelName}表现最佳（{$topHotelNights} 间夜），
                    建议分析其成功因素并借鉴学习。
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>营销建议：</strong>
                    {$marketingAdvice}
                </div>
            </div>
        </div>
    </div>
HTML;
        }

        // 完整报告
        $totalRoomNightsFormatted = $this->formatOptionalNumber($totalRoomNights);
        $totalRoomRevenueFormatted = $this->formatOptionalCurrency($totalRoomRevenue);
        $totalSalesFormatted = $this->formatOptionalCurrency($totalSales);
        $totalExposureFormatted = $this->formatOptionalNumber($totalExposure);
        $totalViewsFormatted = $this->formatOptionalNumber($totalViews);
        $avgRoomNightsFormatted = $this->formatOptionalNumber($avgRoomNights, 1);
        $avgRoomRevenueFormatted = $this->formatOptionalCurrency($avgRoomRevenue);
        $avgPricePerNightFormatted = $this->formatOptionalCurrency($avgPricePerNight);

        $report = <<<HTML
<div class="space-y-6">
    {$previewNoticeHtml}
    <!-- 概览卡片 -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-blue-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">{$hotelCount}</div>
            <div class="text-sm text-gray-600">分析酒店数</div>
        </div>
        <div class="bg-green-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-green-600">{$totalRoomNightsFormatted}</div>
            <div class="text-sm text-gray-600">{$roomNightsLabel}</div>
        </div>
        <div class="bg-orange-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-orange-600">{$totalRoomRevenueFormatted}</div>
            <div class="text-sm text-gray-600">{$revenueLabel}</div>
        </div>
        <div class="bg-purple-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">{$avgPricePerNightFormatted}</div>
            <div class="text-sm text-gray-600">平均房价</div>
        </div>
    </div>

    <!-- 经营分析 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-chart-line text-blue-500 mr-2"></i>{$analysisTitle}
        </h3>
        <div class="space-y-3 text-sm">
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">平均间夜：</span>
                <span class="text-gray-800">{$avgRoomNightsFormatted} 间夜/店</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">平均收入：</span>
                <span class="text-gray-800">{$avgRoomRevenueFormatted}/店</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">总销售额：</span>
                <span class="text-gray-800">{$totalSalesFormatted}</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">曝光量：</span>
                <span class="text-gray-800">{$totalExposureFormatted} 次</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">浏览量：</span>
                <span class="text-gray-800">{$totalViewsFormatted} 次</span>
            </div>
        </div>
    </div>

    <!-- 入住间夜TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-trophy text-yellow-500 mr-2"></i>入住间夜 TOP5
        </h3>
        <div class="space-y-2">
            {$top5RoomNightsHtml}
        </div>
    </div>

    <!-- 房费收入TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-coins text-green-500 mr-2"></i>房费收入 TOP5
        </h3>
        <div class="space-y-2">
            {$top5RevenueHtml}
        </div>
    </div>

    {$suggestionsHtml}

    <!-- 分析时间 -->
    <div class="text-xs text-gray-400 text-right">
        <i class="fas fa-clock mr-1"></i>分析时间：{$this->getCurrentTime()}
    </div>
</div>
HTML;

        return $report;
    }

    private function formatOptionalNumber(mixed $value, int $decimals = 0): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '未返回';
        }
        return number_format((float)$value, $decimals);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function formatOptionalCurrency(mixed $value, int $decimals = 0): string
    {
        $formatted = $this->formatOptionalNumber($value, $decimals);
        return $formatted === '未返回' ? $formatted : '¥' . $formatted;
    }

    private function getCurrentTime(): string
    {
        return date('Y-m-d H:i:s');
    }
}
