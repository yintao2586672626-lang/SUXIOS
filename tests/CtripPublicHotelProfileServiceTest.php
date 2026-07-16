<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripPublicHotelProfileService;
use PHPUnit\Framework\TestCase;

final class CtripPublicHotelProfileServiceTest extends TestCase
{
    public function testParsesStaticPublicHotelFactsWithFieldEvidence(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body>
<h1 aria-label="测试酒店">测试酒店</h1>
<div class="headInit_headInit-address_position"><span aria-label="上海市黄浦区测试路1号">地址</span></div>
<span role="img" aria-label="4 out of 5 diamonds"></span>
<div class="reviewTop_reviewTop-score-container-ctrip" aria-label="4.8 out of 5"><em>4.8</em></div>
<ul data-test-id="hotelOverview-label">
  <li>开业：2018</li><li>装修：2023</li><li>客房数：88</li>
</ul>
<div id="fac_0" aria-label="免费停车场"></div>
<div id="fac_1" aria-label="健身室"></div>
</body></html>
HTML;
        $service = new CtripPublicHotelProfileService(null, static fn(): string => '2026-07-16 20:00:00');
        $result = $service->parseHtml($html, '3456814');

        self::assertSame('available', $result['capture_status']);
        self::assertSame('测试酒店', $result['fields']['name']);
        self::assertSame('上海市黄浦区测试路1号', $result['fields']['address']);
        self::assertSame(4, $result['fields']['diamond_level']);
        self::assertNull($result['fields']['star_level']);
        self::assertSame(4.8, $result['fields']['rating']);
        self::assertSame(5.0, $result['fields']['rating_scale']);
        self::assertSame(['免费停车场', '健身室'], $result['fields']['facilities']);
        self::assertSame(2018, $result['fields']['opening_year']);
        self::assertSame(2023, $result['fields']['renovation_year']);
        self::assertSame(88, $result['fields']['room_count']);
        self::assertSame('html:hotel_overview_label', $result['evidence_paths']['room_count']);
        self::assertSame(
            CtripPublicHotelProfileService::ROOM_COUNT_SEMANTICS,
            $result['room_count_semantics']
        );
        self::assertContains('name_en', $result['missing_fields']);
        self::assertContains('policies', $result['missing_fields']);
        self::assertSame(2, $result['profile_schema_version']);
    }

    public function testParsesStructuredNextFlightStaticProfileAndExcludesContactFields(): void
    {
        $detail = [
            'hotelBaseInfo' => [
                'nameInfo' => ['name' => '结构化测试酒店', 'nameEn' => 'Structured Test Hotel'],
                'cityId' => 2,
                'provinceId' => 9,
                'countryId' => 1,
                'cityName' => '上海',
                'starInfo' => ['level' => 5, 'type' => 'star'],
                'brandInfo' => ['name' => '测试品牌'],
                'hotelTypeName' => '精品酒店',
                'newHighlights' => ['list' => [
                    ['tagTitle' => '免费停车场'],
                    ['tagTitle' => '近地铁'],
                ]],
            ],
            'hotelPositionInfo' => [
                'address' => '上海市测试路1号',
                'lat' => '31.2304',
                'lng' => '121.4737',
                'mapType' => 'bd',
                'placeInfo' => ['wholePoiInfoList' => [[
                    'poiName' => '测试站',
                    'type' => 'station',
                    'distance' => '500米',
                    'walkDriveDistance' => '500.2',
                    'distType' => 'WALK',
                ]]],
            ],
            'hotelDescriptionInfo' => [
                'description' => '酒店位于测试商圈，交通便利。',
                'image' => '//ak-d.tripcdn.com/images/test-cover.jpg',
                'sectionList' => [['title' => '酒店简介', 'desc' => '提供安静的住宿环境。']],
                'detailDescPopTags' => [
                    ['type' => 'openTime', 'value' => '开业：2018'],
                    ['type' => 'renovationTime', 'value' => '装修：2023'],
                    ['type' => 'roomNum', 'value' => '客房数：88'],
                    ['type' => 'tel', 'value' => 'CONTACT_SHOULD_NOT_PERSIST'],
                ],
            ],
            'hotelFacilityBelt' => [
                'title' => '热门设施',
                'facilityList' => [
                    ['facilityDesc' => '无线WIFI免费'],
                    ['facilityDesc' => '行李寄存'],
                ],
            ],
            'hotelFacilityPopV2' => ['ubtData' => [
                'totalFacilityCount' => 38,
                'freeFacilityCount' => 3,
                'feeFacilityCount' => 1,
            ]],
            'hotelPolicyInfo' => [
                'checkInAndOut' => [
                    'title' => '入离时间',
                    'content' => [
                        ['description' => '入住时间：14:00后'],
                        ['description' => '退房时间：12:00前'],
                    ],
                ],
                'guestLimit' => [
                    'title' => '接待提示',
                    'content' => [['description' => '入住办理人需年满18岁']],
                ],
                'pet' => [
                    'title' => '宠物',
                    'content' => [['description' => '不可携带宠物']],
                ],
            ],
            'hotelTopImage' => [
                'total' => 2,
                'imgUrlList' => [
                    '//ak-d.tripcdn.com/images/test-1.jpg',
                    '//dimg04.c-ctrip.com/images/test-2.jpg',
                ],
            ],
        ];
        $payload = 'J1:' . json_encode(['hotelDetailResponse' => $detail], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $flightArguments = json_encode([1, $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $html = '<!doctype html><html><body><script>self.__next_f.push(' . $flightArguments . ');</script></body></html>';

        $result = (new CtripPublicHotelProfileService())->parseHtml($html, '3456814');
        $fields = $result['fields'];

        self::assertSame('结构化测试酒店', $fields['name']);
        self::assertSame('Structured Test Hotel', $fields['name_en']);
        self::assertSame('上海', $fields['city_name']);
        self::assertSame(31.2304, $fields['latitude']);
        self::assertSame(121.4737, $fields['longitude']);
        self::assertSame('测试品牌', $fields['brand_name']);
        self::assertSame('精品酒店', $fields['hotel_type']);
        self::assertSame(5, $fields['star_level']);
        self::assertSame(['免费停车场', '近地铁'], $fields['highlights']);
        self::assertSame(['无线WIFI免费', '行李寄存'], $fields['facilities']);
        self::assertSame(38, $fields['facility_total_count']);
        self::assertSame(2018, $fields['opening_year']);
        self::assertSame(2023, $fields['renovation_year']);
        self::assertSame(88, $fields['room_count']);
        self::assertSame('14:00', $fields['check_in_time']);
        self::assertSame('12:00', $fields['check_out_time']);
        self::assertSame(18, $fields['minimum_check_in_age']);
        self::assertSame('测试站', $fields['nearby_places'][0]['name']);
        self::assertSame('https://ak-d.tripcdn.com/images/test-cover.jpg', $fields['cover_image_url']);
        self::assertCount(2, $fields['gallery_image_urls']);
        self::assertStringNotContainsString('CONTACT_SHOULD_NOT_PERSIST', json_encode($fields, JSON_UNESCAPED_UNICODE));
    }

    public function testPartialPageKeepsMissingYearsNullInsteadOfMatchingTranslationText(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body>
<h1>测试酒店</h1>
<script>{"translation":"装修前的历史点评不计入点评分"}</script>
<ul data-test-id="hotelOverview-label"><li>客房数：8</li></ul>
</body></html>
HTML;
        $result = (new CtripPublicHotelProfileService())->parseHtml($html, '3456814');

        self::assertSame('partial', $result['capture_status']);
        self::assertSame(8, $result['fields']['room_count']);
        self::assertNull($result['fields']['opening_year']);
        self::assertNull($result['fields']['renovation_year']);
        self::assertContains('opening_year', $result['missing_fields']);
        self::assertContains('renovation_year', $result['missing_fields']);
    }

    public function testZeroFacilitySummaryDoesNotHideObservedFacilities(): void
    {
        $detail = [
            'hotelDetailResponse' => [
                'hotelFacilityBelt' => [
                    'title' => '热门设施',
                    'facilityList' => [['facilityDesc' => '无线WIFI免费']],
                ],
                'hotelFacilityPopV2' => ['ubtData' => [
                    'totalFacilityCount' => 0,
                    'freeFacilityCount' => 0,
                    'feeFacilityCount' => 0,
                ]],
            ],
        ];
        $payload = 'J1:' . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $arguments = json_encode([1, $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $html = '<!doctype html><html><body><script>self.__next_f.push(' . $arguments . ');</script></body></html>';

        $fields = (new CtripPublicHotelProfileService())->parseHtml($html, '3456814')['fields'];

        self::assertSame(['无线WIFI免费'], $fields['facilities']);
        self::assertNull($fields['facility_total_count']);
        self::assertNull($fields['facility_free_count']);
        self::assertNull($fields['facility_fee_count']);
    }

    public function testHttpFailureIsExplicitAndDoesNotReturnFallbackProfile(): void
    {
        $service = new CtripPublicHotelProfileService(
            static fn(string $_url): array => [
                'http_status' => 429,
                'body' => '',
                'final_url' => 'https://hotels.ctrip.com/hotels/3456814.html',
                'error' => '',
            ],
            static fn(): string => '2026-07-16 20:00:00'
        );
        $result = $service->fetchProfile('3456814');

        self::assertSame('collection_failed', $result['capture_status']);
        self::assertSame('http_failure', $result['failure_reason']);
        self::assertSame(429, $result['http_status']);
        self::assertNull($result['fields']['room_count']);
        self::assertNull($result['fields']['name']);
        self::assertSame('', $result['content_hash']);
    }

    public function testPublicUrlOnlyAcceptsPositiveNumericCtripHotelId(): void
    {
        self::assertSame(
            'https://hotels.ctrip.com/hotels/3456814.html',
            CtripPublicHotelProfileService::publicUrl('3456814')
        );

        $this->expectException(\InvalidArgumentException::class);
        CtripPublicHotelProfileService::publicUrl('../account');
    }

    public function testBlockedPageIsNeverAcceptedAsAHotelProfileEvenWhenItHasAHeading(): void
    {
        $service = new CtripPublicHotelProfileService(
            static fn(string $url): array => [
                'http_status' => 200,
                'body' => '<!doctype html><html><body><h1>安全验证</h1><p>请输入验证码</p></body></html>',
                'final_url' => $url,
            ],
            static fn(): string => '2026-07-16 20:00:00'
        );

        $result = $service->fetchProfile('3456814');

        self::assertSame('collection_failed', $result['capture_status']);
        self::assertSame('public_page_blocked', $result['failure_reason']);
        self::assertNull($result['fields']['name']);
    }

    public function testSameHostRedirectMustStillMatchTheRequestedHotelId(): void
    {
        $service = new CtripPublicHotelProfileService(
            static fn(string $_url): array => [
                'http_status' => 200,
                'body' => '<!doctype html><html><body><h1>另一家酒店</h1></body></html>',
                'final_url' => 'https://hotels.ctrip.com/hotels/7654321.html',
            ],
            static fn(): string => '2026-07-16 20:00:00'
        );

        $result = $service->fetchProfile('3456814');

        self::assertSame('collection_failed', $result['capture_status']);
        self::assertSame('unexpected_redirect', $result['failure_reason']);
        self::assertNull($result['fields']['name']);
    }

    public function testUnsafeRedirectTargetsAreRejectedBeforeASecondRequest(): void
    {
        foreach ([
            'http://hotels.ctrip.com/hotels/3456814.html',
            'https://example.com/hotels/3456814.html',
            'https://hotels.ctrip.com:8443/hotels/3456814.html',
            'https://hotels.ctrip.com/hotels/7654321.html',
        ] as $redirectTarget) {
            $requestedUrls = [];
            $service = new CtripPublicHotelProfileService(
                null,
                static fn(): string => '2026-07-16 20:00:00',
                static fn(string $_host): array => ['1.1.1.1'],
                static function (string $url, array $_context) use (&$requestedUrls, $redirectTarget): array {
                    $requestedUrls[] = $url;
                    return [
                        'http_status' => 302,
                        'body' => '',
                        'location' => $redirectTarget,
                    ];
                }
            );

            $result = $service->fetchProfile('3456814');

            self::assertSame('collection_failed', $result['capture_status'], $redirectTarget);
            self::assertSame('unexpected_redirect', $result['failure_reason'], $redirectTarget);
            self::assertCount(1, $requestedUrls, $redirectTarget);
        }
    }

    public function testPrivateDnsResolutionIsRejectedBeforeFollowingRedirect(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '::1', 'fc00::1', 'fe80::1'] as $privateIp) {
            $requestedUrls = [];
            $resolutionCount = 0;
            $service = new CtripPublicHotelProfileService(
                null,
                static fn(): string => '2026-07-16 20:00:00',
                static function (string $_host) use (&$resolutionCount, $privateIp): array {
                    $resolutionCount++;
                    return $resolutionCount === 1 ? ['1.1.1.1'] : [$privateIp];
                },
                static function (string $url, array $_context) use (&$requestedUrls): array {
                    $requestedUrls[] = $url;
                    return [
                        'http_status' => 302,
                        'body' => '',
                        'location' => 'https://hotels.ctrip.com/hotels/3456814.html?retry=1',
                    ];
                }
            );

            $result = $service->fetchProfile('3456814');

            self::assertSame('collection_failed', $result['capture_status'], $privateIp);
            self::assertSame('unexpected_redirect', $result['failure_reason'], $privateIp);
            self::assertCount(1, $requestedUrls, $privateIp);
            self::assertSame(2, $resolutionCount, $privateIp);
        }
    }

    public function testValidRedirectUsesPinnedPublicIpAndHttpsOnlyCurlOptions(): void
    {
        $requestedUrls = [];
        $requestContexts = [];
        $service = new CtripPublicHotelProfileService(
            null,
            static fn(): string => '2026-07-16 20:00:00',
            static fn(string $_host): array => ['1.1.1.1'],
            static function (string $url, array $context) use (&$requestedUrls, &$requestContexts): array {
                $requestedUrls[] = $url;
                $requestContexts[] = $context;
                if (count($requestedUrls) === 1) {
                    return [
                        'http_status' => 302,
                        'body' => '',
                        'location' => '/hotels/3456814.html?from=redirect',
                    ];
                }
                return [
                    'http_status' => 200,
                    'body' => '<!doctype html><html><body><h1>安全测试酒店</h1></body></html>',
                ];
            }
        );

        $result = $service->fetchProfile('3456814');

        self::assertSame('partial', $result['capture_status']);
        self::assertSame([
            'https://hotels.ctrip.com/hotels/3456814.html',
            'https://hotels.ctrip.com/hotels/3456814.html?from=redirect',
        ], $requestedUrls);
        foreach ($requestContexts as $context) {
            self::assertSame('1.1.1.1', $context['resolved_ip']);
            self::assertFalse($context['follow_redirects']);
            self::assertSame(['https'], $context['allowed_protocols']);
            self::assertSame(CURLPROTO_HTTPS, $context['curl_options'][CURLOPT_PROTOCOLS]);
            self::assertSame(CURLPROTO_HTTPS, $context['curl_options'][CURLOPT_REDIR_PROTOCOLS]);
            self::assertFalse($context['curl_options'][CURLOPT_FOLLOWLOCATION]);
            self::assertSame(
                ['hotels.ctrip.com:443:1.1.1.1'],
                $context['curl_options'][CURLOPT_RESOLVE]
            );
        }
    }
}
