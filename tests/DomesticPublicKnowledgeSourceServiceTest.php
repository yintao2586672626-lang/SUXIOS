<?php
declare(strict_types=1);

namespace Tests;

use app\service\DomesticPublicKnowledgeSourceService;
use PHPUnit\Framework\TestCase;

final class DomesticPublicKnowledgeSourceServiceTest extends TestCase
{
    public function testAssociationFeedKeepsHotelMetadataAndDropsRestaurantOnlyRows(): void
    {
        $feed = [
            'content' => [
                [
                    'id' => 101,
                    'title' => '中国住宿业消费指数报告（2026年上半年）',
                    'summary' => '住宿业公开指数摘要',
                    'publishDate' => '2026-07-23T10:07:59.000+08:00',
                    'outerLink' => '',
                ],
                [
                    'id' => 102,
                    'title' => '中国餐饮业消费指数报告',
                    'summary' => '仅讨论餐饮',
                    'publishDate' => '2026-07-24T10:07:59.000+08:00',
                    'outerLink' => '',
                ],
            ],
        ];
        $html = '<script>var newsPage = '
            . json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '; var articleCategory = {};</script>';
        $service = new DomesticPublicKnowledgeSourceService(
            static fn(string $url, int $timeout): array => [
                'ok' => true,
                'body' => $html,
                'http_status' => 200,
                'content_type' => 'text/html; charset=utf-8',
            ],
            [
                'association' => [
                    'name' => '中国饭店协会行业报告',
                    'url' => 'https://www.chinahotel.org.cn/categories/35',
                    'parser' => 'china_hotel_association',
                    'tier' => 'national_industry_association',
                    'discovery_mode' => 'feed',
                    'ttl_days' => 60,
                    'item_limit' => 10,
                    'keywords' => ['住宿', '饭店', '酒店'],
                ],
            ]
        );

        $result = $service->collect();

        self::assertSame('success', $result['status']);
        self::assertSame(1, $result['sources'][0]['item_count']);
        self::assertSame(
            '中国住宿业消费指数报告（2026年上半年）',
            $result['sources'][0]['items'][0]['title']
        );
        self::assertSame(
            'https://www.chinahotel.org.cn/articles/101',
            $result['sources'][0]['items'][0]['url']
        );
        self::assertSame(
            'metadata_verified_content_not_interpreted',
            $result['sources'][0]['items'][0]['verification_status']
        );
    }

    public function testOfficialLinkFeedResolvesRelativeUrlAndPublicationDate(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body>
<a href="../../tjxx/202606/t20260602_966073.html">文化和旅游发展统计公报</a>
<a href="../../tjxx/202604/t20260429_965662.html">无关资料</a>
</body></html>
HTML;
        $service = new DomesticPublicKnowledgeSourceService(
            static fn(string $url, int $timeout): array => [
                'ok' => true,
                'body' => $html,
                'http_status' => 200,
                'content_type' => 'text/html; charset=utf-8',
            ],
            [
                'mct' => [
                    'name' => '文化和旅游部统计信息',
                    'url' => 'https://zwgk.mct.gov.cn/zfxxgkml/447/465/index_3081.html',
                    'parser' => 'link_list',
                    'tier' => 'official_government',
                    'discovery_mode' => 'feed',
                    'ttl_days' => 120,
                    'item_limit' => 10,
                    'keywords' => ['旅游'],
                    'url_pattern' => '~t(?<date>\d{8})_\d+\.html~i',
                ],
            ]
        );

        $result = $service->collect();
        $item = $result['sources'][0]['items'][0];

        self::assertSame('success', $result['status']);
        self::assertSame('2026-06-02', $item['published_at']);
        self::assertSame(
            'https://zwgk.mct.gov.cn/zfxxgkml/tjxx/202606/t20260602_966073.html',
            $item['url']
        );
    }

    public function testLinkFeedKeepsTrailingSlashBaseDirectory(): void
    {
        $html = '<a href="./202607/t20260716_1964142.html">'
            . '2026年上半年国内生产总值初步核算结果</a>';
        $service = new DomesticPublicKnowledgeSourceService(
            static fn(string $url, int $timeout): array => [
                'ok' => true,
                'body' => $html,
                'http_status' => 200,
                'content_type' => 'text/html; charset=utf-8',
            ],
            [
                'nbs' => [
                    'name' => '国家统计局',
                    'url' => 'https://www.stats.gov.cn/sj/zxfb/',
                    'parser' => 'link_list',
                    'tier' => 'official_government',
                    'discovery_mode' => 'feed',
                    'ttl_days' => 45,
                    'item_limit' => 10,
                    'keywords' => ['国内生产总值'],
                    'url_pattern' => '~t(?<date>\d{8})_\d+\.html~i',
                ],
            ]
        );

        $result = $service->collect();

        self::assertSame(
            'https://www.stats.gov.cn/sj/zxfb/202607/t20260716_1964142.html',
            $result['sources'][0]['items'][0]['url']
        );
    }

    public function testFailedSourceReturnsExplicitFailureWithoutInventedItems(): void
    {
        $service = new DomesticPublicKnowledgeSourceService(
            static fn(string $url, int $timeout): array => [
                'ok' => false,
                'error' => 'http_status_403',
                'http_status' => 403,
            ],
            [
                'blocked' => [
                    'name' => '受阻公开来源',
                    'url' => 'https://example.gov.cn/reports/',
                    'parser' => 'link_list',
                    'tier' => 'official_government',
                    'discovery_mode' => 'feed',
                    'ttl_days' => 60,
                ],
            ]
        );

        $result = $service->collect();

        self::assertSame('collection_failed', $result['status']);
        self::assertSame('collection_failed', $result['sources'][0]['status']);
        self::assertSame('http_status_403', $result['sources'][0]['reason']);
        self::assertSame([], $result['sources'][0]['items']);
    }

    public function testDefaultCatalogUsesOnlyDomesticPublicHttpsSources(): void
    {
        $catalog = DomesticPublicKnowledgeSourceService::defaultSources();

        self::assertCount(4, $catalog);
        foreach ($catalog as $source) {
            self::assertStringStartsWith('https://', (string)$source['url']);
            self::assertStringNotContainsString('ctrip', strtolower((string)$source['url']));
            self::assertStringNotContainsString('meituan', strtolower((string)$source['url']));
            self::assertContains(
                (string)$source['tier'],
                ['official_government', 'official_regulation', 'national_industry_association']
            );
        }
    }
}
