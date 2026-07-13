<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\command\PlatformProfileLogin;
use app\service\BrowserProfileCaptureRequestService;
use app\service\CtripTrafficDisplayService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;
use think\App;

final class OnlineDataTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): OnlineData
    {
        $reflection = new ReflectionClass(OnlineData::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    private function setControllerCurrentUser(OnlineData $controller, object $user): void
    {
        $reflection = new ReflectionClass($controller);
        while (!$reflection->hasProperty('currentUser') && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        $property = $reflection->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $user);
    }

    private function profileLoginCommand(): PlatformProfileLogin
    {
        $reflection = new ReflectionClass(PlatformProfileLogin::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    public function testOtaConfigMaintenanceAllowsBetaManagerForOwnHotelOnly(): void
    {
        $controller = $this->controller();
        $this->setControllerCurrentUser($controller, new class {
            public int $id = 7;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function canManageOwnHotels(): bool
            {
                return true;
            }

            public function getPermittedHotelIds(): array
            {
                return [58];
            }
        });

        self::assertTrue($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfig', [58]));
        self::assertFalse($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfig', [64]));
    }

    public function testOtaConfigMaintenanceKeepsNormalExternalUserBlocked(): void
    {
        $controller = $this->controller();
        $this->setControllerCurrentUser($controller, new class {
            public int $id = 8;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function canManageOwnHotels(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [58];
            }
        });

        self::assertFalse($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfig', [58]));
    }

    public function testOtaConfigMaintenanceKeepsExistingFetchRoleScopedToOwnHotel(): void
    {
        $controller = $this->controller();
        $this->setControllerCurrentUser($controller, new class {
            public int $id = 9;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === 'can_fetch_online_data';
            }

            public function canManageOwnHotels(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [58];
            }
        });

        self::assertTrue($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfig', [58]));
        self::assertFalse($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfig', [64]));
    }

    public function testOtaConfigMaintenanceBlocksOwnedConfigWithoutHotelScope(): void
    {
        $controller = $this->controller();
        $this->setControllerCurrentUser($controller, new class {
            public int $id = 7;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function canManageOwnHotels(): bool
            {
                return true;
            }

            public function getPermittedHotelIds(): array
            {
                return [];
            }
        });

        $config = ['user_id' => 7, 'system_hotel_id' => 118];

        self::assertFalse($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfigItem', [$config]));
        self::assertFalse($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfigItem', [$config, 118]));
    }

    public function testOtaConfigMaintenanceBlocksOwnedConfigRebindingWithoutHotelPermission(): void
    {
        $controller = $this->controller();
        $this->setControllerCurrentUser($controller, new class {
            public int $id = 7;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function canManageOwnHotels(): bool
            {
                return true;
            }

            public function getPermittedHotelIds(): array
            {
                return [];
            }
        });

        $config = ['user_id' => 7, 'system_hotel_id' => 118];

        self::assertFalse($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfigItem', [$config, 119]));
    }

    public function testOtaConfigMaintenanceAllowsHotelScopedConfigWithoutOwner(): void
    {
        $controller = $this->controller();
        $this->setControllerCurrentUser($controller, new class {
            public int $id = 8;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasPermission(string $permission): bool
            {
                return $permission === 'can_fetch_online_data';
            }

            public function canManageOwnHotels(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [118];
            }
        });

        $config = ['system_hotel_id' => 118];

        self::assertTrue($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfigItem', [$config]));
    }

    public function testOtaConfigBindingConflictIsRejectedEverywhere(): void
    {
        $controller = $this->controller();
        $conflict = ['system_hotel_id' => 58, 'hotel_id' => 64];
        self::assertTrue($this->invokeNonPublic($controller, 'otaConfigHasHotelBindingConflict', [$conflict]));
        self::assertFalse($this->invokeNonPublic($controller, 'currentUserCanMaintainOtaConfigItem', [$conflict]));
        $user = new class {
            public int $id = 1;
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return [58, 64]; }
        };
        self::assertFalse($this->invokeNonPublic($controller, 'isOtaConfigVisibleToUser', [$conflict, $user]));
    }

    public function testPublicEndpointSecuritySummaryUsesSanitizedAuditRows(): void
    {
        $controller = $this->controller();
        $logs = [
            [
                'id' => 10,
                'action' => 'receive_cookies_public_failure',
                'create_time' => '2026-06-08 10:00:00',
                'error_info' => 'HTTP 429',
                'extra_data' => json_encode([
                    'endpoint' => 'receive_cookies',
                    'reason' => 'rate_limited',
                    'status' => 429,
                    'method' => 'POST',
                    'origin' => 'https://ebooking.ctrip.com',
                    'ip_hash' => 'abc123',
                    'extra' => ['token' => 'plain-token-value', 'cookies' => 'plain-cookie-value'],
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 11,
                'action' => 'cron_trigger_public_failure',
                'create_time' => '2026-06-08 10:01:00',
                'error_info' => 'HTTP 401',
                'extra_data' => json_encode([
                    'endpoint' => 'cron_trigger',
                    'reason' => 'invalid_cron_token',
                    'status' => 401,
                    'method' => 'GET',
                    'origin' => '',
                    'ip_hash' => 'def456',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $row = $this->invokeNonPublic($controller, 'buildPublicEndpointSecurityRow', [
            'receive_cookies',
            $logs,
            [
                'method' => 'POST|OPTIONS',
                'path' => '/api/online-data/receive-cookies',
                'auth' => 'legacy bookmarklet disabled; no current-session token accepted',
                'rate_limit' => ['limit' => 30, 'window_seconds' => 60],
                'token_configured' => false,
            ],
        ]);

        self::assertSame('receive_cookies', $row['endpoint']);
        self::assertFalse($row['normal_auth_middleware']);
        self::assertSame(1, $row['recent_failure_count']);
        self::assertSame(1, $row['rate_limited_count']);
        self::assertSame('rate_limited', $row['last_failure']['reason']);
        self::assertSame('abc123', $row['last_failure']['ip_hash']);
        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('plain-token-value', $encoded);
        self::assertStringNotContainsString('plain-cookie-value', $encoded);
    }

    public function testPublicEndpointAuditSanitizesSecretsHiddenInOrdinaryTextFields(): void
    {
        $controller = $this->controller();
        $raw = 'source=manual Cookie: sid=cookie-secret; session=session-secret Authorization: Bearer auth-secret token=token-secret';
        $safeText = $this->invokeNonPublic($controller, 'safePublicEndpointText', [$raw]);
        $safeExtra = $this->invokeNonPublic($controller, 'sanitizePublicEndpointExtra', [[
            'source' => 'Authorization: Bearer nested-auth-secret',
            'name' => 'session=nested-session-secret',
            'origin' => 'https://example.test/?token=query-token-secret',
        ]]);

        $encoded = (string)json_encode([$safeText, $safeExtra], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([
            'cookie-secret',
            'session-secret',
            'auth-secret',
            'token-secret',
            'nested-auth-secret',
            'nested-session-secret',
            'query-token-secret',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        self::assertStringContainsString('****', $encoded);
    }

    public function testPublicEndpointSecuritySummaryIncludesCompetitorPublicApis(): void
    {
        $controller = $this->controller();
        $logs = [
            [
                'id' => 20,
                'action' => 'external_rate_limited',
                'create_time' => '2026-07-08 10:00:00',
                'error_info' => 'HTTP 429',
                'extra_data' => json_encode([
                    'audit_type' => 'operation',
                    'scope' => 'task',
                    'limit' => 30,
                    'window' => 60,
                    'identity' => 'device-a|ctrip',
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 21,
                'action' => 'report_denied',
                'create_time' => '2026-07-08 10:01:00',
                'error_info' => 'invalid_report_token',
                'extra_data' => json_encode([
                    'audit_type' => 'operation',
                    'device_id' => 'device-b',
                    'platform' => 'meituan',
                    'token' => 'plain-report-token',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $taskRow = $this->invokeNonPublic($controller, 'buildPublicEndpointSecurityRow', [
            'competitor_task',
            $logs,
            [
                'method' => 'POST',
                'path' => '/api/competitor/task',
                'auth' => 'X-Task-Token header only',
                'rate_limit' => ['limit' => 30, 'window_seconds' => 60],
                'token_configured' => true,
                'failure_actions' => ['task_denied', 'external_rate_limited'],
                'failure_scope' => 'task',
            ],
        ]);
        $reportRow = $this->invokeNonPublic($controller, 'buildPublicEndpointSecurityRow', [
            'competitor_report',
            $logs,
            [
                'method' => 'POST',
                'path' => '/api/competitor/report',
                'auth' => 'X-Report-Token header only',
                'rate_limit' => ['limit' => 60, 'window_seconds' => 60],
                'token_configured' => true,
                'failure_actions' => ['report_denied', 'external_rate_limited'],
                'failure_scope' => 'report',
            ],
        ]);

        self::assertSame('competitor_task', $taskRow['endpoint']);
        self::assertSame(1, $taskRow['recent_failure_count']);
        self::assertSame(1, $taskRow['rate_limited_count']);
        self::assertSame('rate_limited', $taskRow['last_failure']['reason']);
        self::assertSame(429, $taskRow['last_failure']['status']);
        self::assertSame('competitor_report', $reportRow['endpoint']);
        self::assertSame(1, $reportRow['recent_failure_count']);
        self::assertSame(0, $reportRow['rate_limited_count']);
        self::assertSame('invalid_report_token', $reportRow['last_failure']['reason']);
        $encoded = json_encode([$taskRow, $reportRow], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('plain-report-token', $encoded);
    }

    public function testCollectionStatusMarksStaleRunningTaskExplicitly(): void
    {
        $controller = $this->controller();
        $oldRunningTask = [
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 7200),
            'message' => '',
        ];
        $freshRunningTask = [
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 120),
        ];

        self::assertSame('collecting', $this->invokeNonPublic($controller, 'resolveCollectionStatus', [
            false,
            false,
            $freshRunningTask,
            [],
            [],
            [],
        ]));
        self::assertSame('stale_running', $this->invokeNonPublic($controller, 'resolveCollectionStatus', [
            false,
            false,
            $oldRunningTask,
            [],
            [],
            [],
        ]));
        self::assertSame('stale_running_task', $this->invokeNonPublic($controller, 'collectionStatusFailureReason', [
            'stale_running',
            $oldRunningTask,
            null,
            [],
            [],
            false,
            [],
            [],
        ]));
    }

    public function testCollectionReliabilityDefinitionsAndQualitySnapshot(): void
    {
        $controller = $this->controller();

        $definitions = $this->invokeNonPublic($controller, 'buildOtaCollectionFieldDefinitions');
        $ctripTraffic = current(array_filter($definitions, static fn(array $item): bool => ($item['source'] ?? '') === 'ctrip' && ($item['module'] ?? '') === 'traffic'));

        self::assertIsArray($ctripTraffic);
        self::assertSame('online_daily_data', $ctripTraffic['storage_table']);
        self::assertContains('list_exposure', array_column($ctripTraffic['fields'], 'field'));
        self::assertContains('detail_exposure', array_column($ctripTraffic['fields'], 'field'));

        $fieldAssetSummary = $this->invokeNonPublic($controller, 'summarizeOtaCollectionFieldDefinitions', [$definitions]);
        self::assertGreaterThan(0, $fieldAssetSummary['stable_field_count']);
        self::assertSame(2, $fieldAssetSummary['not_returned_field_count']);
        self::assertSame(4, $fieldAssetSummary['forbidden_field_count']);
        self::assertSame(
            $fieldAssetSummary['field_count'] - $fieldAssetSummary['forbidden_field_count'],
            $fieldAssetSummary['collectable_field_count']
        );
        self::assertContains('raw_data.platformTagStatus', array_column($fieldAssetSummary['not_returned_fields'], 'field'));
        self::assertContains('guest_phone', array_column($fieldAssetSummary['forbidden_fields'], 'field'));
        self::assertContains('order_phone', array_column($fieldAssetSummary['forbidden_fields'], 'field'));
        self::assertContains('room_status', array_column($fieldAssetSummary['forbidden_fields'], 'field'));
        self::assertContains('room_source_mapping', array_column($fieldAssetSummary['forbidden_fields'], 'field'));

        $snapshot = $this->invokeNonPublic($controller, 'buildCollectionQualitySnapshot', [[
            [
                'hotel_id' => '1001',
                'hotel_name' => 'Demo Hotel',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-24',
                'list_exposure' => 100,
                'detail_exposure' => 20,
                'raw_data' => json_encode(['listExposure' => 100, 'detailExposure' => 20], JSON_UNESCAPED_UNICODE),
            ],
            [
                'hotel_id' => '',
                'hotel_name' => '',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-24',
                'raw_data' => '{bad-json',
            ],
        ]]);

        self::assertSame(2, $snapshot['checked_records']);
        self::assertSame(1, $snapshot['coverage_days']);
        self::assertGreaterThan(0, $snapshot['issue_records']);
        self::assertGreaterThan(0, $snapshot['score']);
        self::assertNotEmpty($snapshot['source_breakdown']);
    }

    public function testCollectionMetricPreviewExposesCtripStandardRowMetrics(): void
    {
        $controller = $this->controller();

        $preview = $this->invokeNonPublic($controller, 'buildCollectionMetricPreview', [[
            'source' => 'ctrip',
            'data_type' => 'quality',
            'data_date' => '2026-06-06',
            'dimension' => 'catalog:quality_psi:psi_overview:psi_score+reply_rate:root',
            'data_value' => 0,
            'raw_data' => json_encode([
                'capture_section' => 'quality_psi',
                'endpoint_id' => 'psi_overview',
                'metrics' => [
                    'psi_score' => '4.54',
                ],
                'rank_metrics' => [
                    'amount_rank' => 8,
                ],
                'facts' => [
                    ['metric_key' => 'reply_rate', 'value' => '91.2'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('quality_psi', $preview['capture_section']);
        self::assertSame('psi_overview', $preview['endpoint_id']);
        self::assertSame('psi_score+reply_rate', $preview['metric_key']);
        self::assertSame('4.54', $preview['psi_score']);
        self::assertSame(8, $preview['amount_rank']);
        self::assertSame('91.2', $preview['reply_rate']);
    }

    public function testNonNumericCtripFactRowsDoNotRequireRevenueMetrics(): void
    {
        $controller = $this->controller();

        $quality = $this->invokeNonPublic($controller, 'buildOnlineDataQuality', [[
            'hotel_id' => 'ctrip-1001',
            'hotel_name' => 'Demo Hotel',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-06-06',
            'dimension' => 'catalog:market_calendar:hot_calendar:hot_spot_name:0',
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'raw_data' => json_encode([
                'fact_only' => true,
                'metric_status' => 'non_numeric_fact',
                'metrics' => [
                    'hot_spot_name' => 'Concert A',
                    'start_date' => '2026-06-06',
                    'end_date' => '2026-06-06',
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('ok', $quality['status']);
        self::assertSame(0, $quality['missing_count']);
        self::assertNotContains('amount', array_column($quality['missing_metrics'], 'key'));
        self::assertNotContains('quantity', array_column($quality['missing_metrics'], 'key'));
        self::assertNotContains('book_order_num', array_column($quality['missing_metrics'], 'key'));
    }

    /**
     * 覆盖 normalizeAppTrafficRow/readTrafficNumber/normalizeTrafficPercent/trafficRate：
     * 验证正常流量行、零分母边界值、非法日期异常输入兜底。
     */
    public function testNormalizeAppTrafficRowCoversNormalBoundaryAndInvalidInput(): void
    {
        $normal = CtripTrafficDisplayService::normalizeAppTrafficRow([
            'dataDate' => '2026-05-01 08:00:00',
            'hotelId' => 88,
            'listExposure' => '1000',
            'detailExposure' => '250',
            'orderFillingNum' => '25',
            'orderSubmitNum' => '5',
            'flowRate' => '0.2',
            'orderFillRate' => '10',
            'submitRate' => '0.2',
        ]);

        self::assertSame('2026-05-01', $normal['date']);
        self::assertSame('self', $normal['compare_type']);
        self::assertSame(1000.0, $normal['metrics']['exposure']);
        self::assertSame(20.0, $normal['metrics']['exposure_rate']);
        self::assertSame(10.0, $normal['metrics']['order_rate']);
        self::assertSame(20.0, $normal['metrics']['deal_rate']);

        $boundary = CtripTrafficDisplayService::normalizeAppTrafficRow([
            'date' => '2026-05-02',
            'compare_type' => 'competitor',
            'exposure' => 0,
            'detail_visitors' => 0,
            'order_visitors' => 0,
            'submit_users' => 0,
        ]);

        self::assertSame('competitor', $boundary['compare_type']);
        self::assertSame(0.0, $boundary['metrics']['exposure_rate']);
        self::assertSame(0.0, CtripTrafficDisplayService::trafficRate(12.0, 0.0));
        self::assertNull(CtripTrafficDisplayService::normalizeAppTrafficRow(['date' => 'not-a-date']));
    }

    /**
     * 覆盖 buildAppTrafficDerivedAnalysis/calculateAppTrafficDerivedMetrics：
     * 验证携程流量响应的汇总、缺口指标、空响应边界。
     */
    public function testBuildAppTrafficDerivedAnalysisCoversSummaryAndEmptyResponse(): void
    {
        $rows = [
            [
                'date' => '2026-05-01',
                'hotelId' => 1001,
                'listExposure' => 1000,
                'detailExposure' => 200,
                'orderFillingNum' => 40,
                'orderSubmitNum' => 8,
            ],
            [
                'date' => '2026-05-01',
                'hotelId' => -1,
                'listExposure' => 2000,
                'detailExposure' => 600,
                'orderFillingNum' => 120,
                'orderSubmitNum' => 36,
            ],
        ];

        $analysis = CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis($rows);

        self::assertCount(1, $analysis['rows']);
        self::assertSame(1000.0, $analysis['summary']['exposure_gap']);
        self::assertSame(33.33, $analysis['summary']['detail_achieve_rate']);
        self::assertSame(20.0, $analysis['summary']['self']['deal_rate']);
        self::assertSame(30.0, $analysis['summary']['competitor']['deal_rate']);
        self::assertIsArray($analysis['recommendations']);

        $empty = CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis([]);
        self::assertSame([], $empty['rows']);
        self::assertSame(0.0, $empty['summary']['self']['exposure']);
    }

    public function testCtripTrafficDateRangeUsesSettledDailyRange(): void
    {
        $controller = $this->controller();
        $now = strtotime('2026-05-26 00:30:00');

        self::assertSame(['2026-05-25', '2026-05-25'], $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', [
            'yesterday',
            '',
            '',
            $now,
        ]));
        self::assertSame(['2026-05-19', '2026-05-25'], $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', [
            'last_7_days',
            '',
            '',
            $now,
        ]));
        self::assertSame(['2026-04-26', '2026-05-25'], $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', [
            'last_30_days',
            '',
            '',
            $now,
        ]));
    }

    public function testMeituanDateRangeNormalizesPlatformDateFormats(): void
    {
        $controller = $this->controller();

        self::assertSame(['2026-05-02', '2026-05-03'], $this->invokeNonPublic($controller, 'normalizeMeituanManualDateRange', [
            '2026/5/2',
            '20260503',
        ]));
        self::assertSame(['2026-05-03', '2026-05-03'], $this->invokeNonPublic($controller, 'normalizeMeituanManualDateRange', [
            '',
            '2026-05-03',
        ]));
    }

    public function testMeituanDateRangeRejectsReverseRange(): void
    {
        $controller = $this->controller();

        $this->expectException(InvalidArgumentException::class);
        $this->invokeNonPublic($controller, 'normalizeMeituanManualDateRange', [
            '2026-05-04',
            '2026-05-03',
        ]);
    }

    public function testMeituanCapturedRowsCleanTrafficAndOrdersWithoutExternalCalls(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-1',
            'poiId' => 'poi-1',
            'poiName' => 'Meituan Hotel',
            'defaultDataDate' => '2026/5/2',
            'traffic' => [
                'data' => [
                    'rows' => [[
                        'statDate' => '20260503',
                        'exposure_count' => '100',
                        'page_views' => '40',
                        'click_count' => '5',
                        'conversion_rate' => '40%',
                    ]],
                ],
            ],
            'reviews' => [
                [
                    'commentId' => 'COMMENT-1',
                    'content' => 'This comment section must be ignored.',
                    'score' => 1,
                    'commentTime' => '2026-05-03',
                ],
            ],
            'orders' => [
                'data' => [
                    'list' => [[
                        'orderId' => 'ORDER-1',
                        'totalAmount' => '500',
                        'roomCount' => 2,
                        'checkInDate' => '2026-05-01',
                        'checkOutDate' => '2026-05-03',
                        'createTime' => '2026/5/1',
                        'guestName' => 'Alice Guest',
                        'phone' => '90000008000',
                        'mobile' => '90000009000',
                        'idCardNo' => 'sample-id-card-token',
                        'customerRemark' => 'late arrival with child',
                    ]],
                ],
            ],
        ], 7]);

        self::assertCount(3, $rows);
        self::assertContains('review', array_column($rows, 'data_type'));
        self::assertSame('meituan', $rows[0]['source']);
        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame('2026-05-03', $rows[0]['data_date']);
        self::assertSame(100, $rows[0]['list_exposure']);
        self::assertSame(40, $rows[0]['detail_exposure']);
        self::assertSame(40.0, $rows[0]['flow_rate']);

        self::assertSame('review', $rows[1]['data_type']);
        self::assertSame('2026-05-03', $rows[1]['data_date']);
        self::assertSame(1.0, $rows[1]['comment_score']);
        self::assertNull($rows[1]['quantity']);
        self::assertNull($rows[1]['data_value']);
        $reviewRaw = (string)$rows[1]['raw_data'];
        self::assertStringNotContainsString('COMMENT-1', $reviewRaw);
        self::assertStringNotContainsString('This comment section must be ignored.', $reviewRaw);
        self::assertStringContainsString('"comment_score":1', $reviewRaw);
        self::assertStringNotContainsString('"comment_count"', $reviewRaw);
        self::assertStringNotContainsString('"bad_review_count"', $reviewRaw);
        $decodedReviewRaw = json_decode($reviewRaw, true);
        self::assertIsArray($decodedReviewRaw);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decodedReviewRaw['review_id_hash'] ?? ''));

        self::assertSame('order', $rows[2]['data_type']);
        self::assertSame('2026-05-01', $rows[2]['data_date']);
        self::assertSame(500.0, $rows[2]['amount']);
        self::assertSame(4, $rows[2]['quantity']);
        self::assertSame(7, $rows[2]['system_hotel_id']);
        self::assertStringNotContainsString('ORDER-1', (string)$rows[2]['dimension']);

        $orderRaw = (string)$rows[2]['raw_data'];
        self::assertStringNotContainsString('ORDER-1', $orderRaw);
        self::assertStringNotContainsString('Alice Guest', $orderRaw);
        self::assertStringNotContainsString('90000008000', $orderRaw);
        self::assertStringNotContainsString('90000009000', $orderRaw);
        self::assertStringNotContainsString('sample-id-card-token', $orderRaw);
        self::assertStringNotContainsString('late arrival with child', $orderRaw);

        $decodedOrderRaw = json_decode($orderRaw, true);
        self::assertIsArray($decodedOrderRaw);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decodedOrderRaw['order_id_hash'] ?? ''));
        self::assertSame('A***', $decodedOrderRaw['guest_name_masked'] ?? null);
        self::assertSame('*******8000', $decodedOrderRaw['phone_masked'] ?? null);
        self::assertSame('*******9000', $decodedOrderRaw['mobile_masked'] ?? null);
        self::assertArrayNotHasKey('idCardNo', $decodedOrderRaw);
        self::assertArrayNotHasKey('customerRemark', $decodedOrderRaw);

        self::assertSame(
            [],
            BrowserProfileCaptureRequestService::unverifiedMeituanTargetDateRows([
                [...$rows[1], 'raw_data' => json_encode([...$decodedReviewRaw, 'commentTime' => '2026-05-03', 'date_source' => 'row.commentTime'])],
                [...$rows[2], 'raw_data' => json_encode([...$decodedOrderRaw, 'createTime' => '2026/5/1', 'date_source' => 'row.createTime'])],
            ], '2026-05-03')
        );
    }

    public function testMeituanDomCsvOrderRowsKeepBottomPriceOutOfRevenueAmount(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-1',
            'poiId' => 'poi-1',
            'poiName' => 'Meituan Hotel',
            'data_period' => 'manual_dom_csv',
            'orders' => [[
                'orderNo' => '123456789012345',
                'roomType' => '阳光双床房',
                'checkIn' => '2026-05-29',
                'checkOut' => '2026-05-30',
                'buyTime' => '2026-05-28 20:30',
                'bottomPrice' => '188.50',
                '_ingestion_method' => 'manual_dom_csv',
            ]],
        ], 7]);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('order', $row['data_type']);
        self::assertSame('2026-05-28', $row['data_date']);
        self::assertNull($row['amount']);
        self::assertNull($row['quantity']);
        self::assertNull($row['book_order_num']);
        self::assertSame(188.5, $row['data_value']);
        self::assertSame('manual_dom_csv', $row['data_period']);
        self::assertStringNotContainsString('123456789012345', (string)$row['dimension']);

        $decodedOrderRaw = json_decode((string)$row['raw_data'], true);
        self::assertIsArray($decodedOrderRaw);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decodedOrderRaw['order_id_hash'] ?? ''));
        self::assertSame('阳光双床房', $decodedOrderRaw['roomType'] ?? null);
        self::assertSame('188.50', $decodedOrderRaw['bottomPrice'] ?? null);
        self::assertStringNotContainsString('123456789012345', (string)$row['raw_data']);
    }

    public function testMeituanBrowserSupplementRowsMapIntoOnlineDailyData(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'storeId' => 'store-1',
            'poiId' => 'poi-1',
            'poiName' => 'Meituan Hotel',
            'defaultDataDate' => '2026-06-26',
            'peerRank' => [[
                'poiId' => 'peer-1',
                'poiName' => 'Peer Hotel',
                'dataDate' => '2026-06-26',
                'rankType' => 'P_RZ',
                'dimension' => '入住间夜',
                'rank' => 2,
                'percent' => '35.5',
            ]],
            'flowAnalysis' => [[
                'dataDate' => '2026-06-26',
                'analysis_type' => 'conversion_funnel',
                'dimension' => 'flow_conversion',
                'listExposure' => 1000,
                'detailExposure' => 200,
                'orderSubmitNum' => 20,
                'flowRate' => 10,
            ]],
            'searchKeywords' => [[
                'dataDate' => '2026-06-26',
                'keyword' => '机场酒店',
                'data_value' => 320,
                'impressions' => 500,
                'clicks' => 40,
            ]],
            'trafficForecast' => [[
                'dataDate' => '2026-07-01',
                'forecast_type' => '2',
                'current' => 88,
                'peerAvg' => 120,
            ]],
        ], 7]);

        self::assertCount(4, $rows);
        self::assertSame(['peer_rank', 'traffic_analysis', 'search_keyword', 'traffic_forecast'], array_column($rows, 'data_type'));

        self::assertSame('peer-1', $rows[0]['hotel_id']);
        self::assertSame('Peer Hotel', $rows[0]['hotel_name']);
        self::assertNull($rows[0]['data_value']);
        self::assertSame('peer_rank:P_RZ:range=unknown:入住间夜', $rows[0]['dimension']);
        self::assertStringContainsString('"rank":2', (string)$rows[0]['raw_data']);
        self::assertSame('competitor', $rows[0]['compare_type']);

        self::assertSame(1000, $rows[1]['list_exposure']);
        self::assertSame(200, $rows[1]['detail_exposure']);
        self::assertSame(20, $rows[1]['order_submit_num']);
        self::assertSame(10.0, $rows[1]['flow_rate']);

        self::assertSame('机场酒店', $rows[2]['dimension']);
        self::assertSame(320.0, $rows[2]['data_value']);
        self::assertSame(500, $rows[2]['list_exposure']);
        self::assertSame(40, $rows[2]['detail_exposure']);

        self::assertSame('2026-07-01', $rows[3]['data_date']);
        self::assertSame(88.0, $rows[3]['data_value']);
        self::assertSame('forecast', $rows[3]['compare_type']);
        self::assertSame(7, $rows[3]['system_hotel_id']);
        self::assertStringContainsString('"peerAvg":120', (string)$rows[3]['raw_data']);
    }

    public function testDailyOtaSupplementSummaryExcludesReviews(): void
    {
        $controller = $this->controller();

        $summary = $this->invokeNonPublic($controller, 'buildDailyOtaSupplementSummary', [[
            [
                'data_type' => 'advertising',
                'amount' => 100,
                'list_exposure' => 1000,
                'detail_exposure' => 100,
                'book_order_num' => 4,
                'raw_data' => json_encode(['orderAmount' => 500], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_type' => 'quality',
                'data_value' => 86.5,
                'raw_data' => json_encode(['serviceScore' => 91], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_type' => 'review',
                'comment_score' => 1.0,
                'raw_data' => json_encode(['content' => 'ignored'], JSON_UNESCAPED_UNICODE),
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
        self::assertArrayNotHasKey('reviews', $summary);
    }

    public function testDailyOperatingSummaryExcludesNonRevenueAndLegacyRankRows(): void
    {
        $controller = $this->controller();
        self::assertTrue(method_exists($controller, 'buildDailyOperatingSummary'));

        $summary = $this->invokeNonPublic($controller, 'buildDailyOperatingSummary', [[
            [
                'data_date' => '2026-07-11', 'system_hotel_id' => 80, 'source' => 'meituan',
                'data_type' => 'business', 'compare_type' => 'self',
                'amount' => 1200, 'quantity' => 6, 'book_order_num' => 4, 'comment_score' => null,
                'raw_data' => json_encode(['metric' => 'daily_trade']),
            ],
            [
                'data_date' => '2026-07-11', 'system_hotel_id' => 80, 'source' => 'meituan',
                'data_type' => 'business', 'compare_type' => null, 'dimension' => '销售榜',
                'amount' => 99999, 'quantity' => 99, 'book_order_num' => 99,
                'raw_data' => json_encode(['rank' => 1, 'poiName' => '同行酒店']),
            ],
            [
                'data_date' => '2026-07-11', 'system_hotel_id' => 80, 'source' => 'meituan',
                'data_type' => 'peer_rank', 'amount' => 88888, 'quantity' => 88, 'book_order_num' => 88,
            ],
            [
                'data_date' => '2026-07-11', 'system_hotel_id' => 80, 'source' => 'meituan',
                'data_type' => 'advertising', 'amount' => 300, 'quantity' => 3, 'book_order_num' => 2,
            ],
            [
                'data_date' => '2026-07-11', 'system_hotel_id' => 80, 'source' => 'meituan',
                'data_type' => 'traffic', 'amount' => 700, 'quantity' => 70, 'book_order_num' => 7,
            ],
        ]]);

        self::assertSame('ota_channel', $summary['total']['scope']);
        self::assertSame('ok', $summary['total']['data_status']);
        self::assertSame(1200.0, $summary['total']['total_amount']);
        self::assertSame(6, $summary['total']['total_quantity']);
        self::assertSame(4, $summary['total']['total_book_order_num']);
        self::assertCount(1, $summary['daily']);
    }

    public function testDailyOperatingSummaryKeepsMissingRevenuePending(): void
    {
        $controller = $this->controller();
        self::assertTrue(method_exists($controller, 'buildDailyOperatingSummary'));

        $summary = $this->invokeNonPublic($controller, 'buildDailyOperatingSummary', [[
            [
                'data_date' => '2026-07-11', 'system_hotel_id' => 80, 'source' => 'meituan',
                'data_type' => 'advertising', 'amount' => null, 'quantity' => null, 'book_order_num' => null,
            ],
            [
                'data_date' => '2026-07-11', 'system_hotel_id' => 80, 'source' => 'meituan',
                'data_type' => 'peer_rank', 'amount' => 5000, 'quantity' => 50, 'book_order_num' => 5,
            ],
        ]]);

        self::assertSame([], $summary['daily']);
        self::assertSame('pending', $summary['total']['data_status']);
        self::assertNull($summary['total']['total_amount']);
        self::assertNull($summary['total']['total_quantity']);
        self::assertNull($summary['total']['total_book_order_num']);
    }

    public function testAutoFetchTaskPlanIgnoresLegacyCommentCredentialConfigs(): void
    {
        $controller = $this->controller();

        $tasks = $this->invokeNonPublic($controller, 'buildAutoFetchConfigTaskPlan', [
            7,
            '2026-05-03',
            [
                'id' => 'ctrip-7',
                'config_id' => 'ctrip-7',
                'system_hotel_id' => 7,
                'credential_status' => 'ready',
                'has_cookies' => true,
                'node_id' => '24588',
                'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
            ],
            [
                'id' => 'meituan-7',
                'config_id' => 'meituan-7',
                'system_hotel_id' => 7,
                'credential_status' => 'ready',
                'has_cookies' => true,
                'partner_id' => 'partner-1',
                'poi_id' => 'poi-1',
                'url' => 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
            ],
        ]);

        $labels = array_column($tasks, 'label');
        self::assertNotContains('ctrip-comments', $labels);
        self::assertNotContains('meituan-comments', $labels);
        self::assertNotContains('comments', array_column($tasks, 'module'));
        self::assertContains('business', array_column($tasks, 'module'));
        self::assertContains('ranking', array_column($tasks, 'module'));
        foreach ($tasks as $task) {
            self::assertArrayHasKey('config_id', $task['body']);
            self::assertArrayNotHasKey('cookies', $task['body']);
            self::assertArrayNotHasKey('auth_data', $task['body']);
        }
    }

    public function testAutoFetchTaskPlanNeverDerivesCookieApiTasksFromBrowserProfiles(): void
    {
        $tasks = $this->invokeNonPublic($this->controller(), 'buildAutoFetchConfigTaskPlan', [
            7,
            '2026-05-03',
            [
                'profile_id' => 'profile-7',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-05-03 09:00:00',
            ],
            [],
        ]);

        self::assertNotContains('ctrip-cookie-api', array_column($tasks, 'label'));
        self::assertSame([], $tasks);
    }

    public function testAutoFetchTaskPlanDoesNotUseUnverifiedCtripProfileAsCookieSource(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $profileId = 'phpunit_ctrip_unverified_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId;
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0775, true);
        }

        try {
            $tasks = $this->invokeNonPublic($controller, 'buildAutoFetchConfigTaskPlan', [
                7,
                '2026-05-03',
                [
                    'profile_id' => $profileId,
                    'profile_status' => 'logged_in',
                    'last_login_verified_at' => '2026-05-03 09:00:00',
                ],
                [],
                [
                    'ctrip-cookie-api' => [
                        'enabled' => true,
                        'system_hotel_id' => 7,
                        'request_urls' => 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo',
                        'hotel_id' => '24588',
                    ],
                ],
            ]);
        } finally {
            @rmdir($profileDir);
        }

        self::assertNotContains('ctrip-cookie-api', array_column($tasks, 'label'));
    }

    public function testProfileDerivedCookieExtractionRequiresAuthoritativeReusableProof(): void
    {
        $controller = $this->controller();

        self::assertSame(['profile_session_unverified'], $this->invokeNonPublic(
            $controller,
            'profileCookieSourceLoginMissingRequirements',
            [[
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-05-03 09:00:00',
            ]]
        ));
    }

    public function testExtractCtripTrafficRowsExpandsDailyMetricSeries(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'extractCtripTrafficRows', [[
            'data' => [
                'dateList' => ['2026-04-12', '2026-04-13'],
                'myHotel' => [
                    'totalListExposure' => [3146, 3941],
                    'totalDetailExposure' => [526, 647],
                    'listTransforDetailRate' => ['16.72%', '16.42%'],
                    'orderFillingNum' => [32, 30],
                    'orderSubmitNum' => [20, 19],
                ],
                'competeHotelAvg' => [
                    'totalListExposure' => [2096, 2460],
                    'totalDetailExposure' => [320, 380],
                    'listTransforDetailRate' => ['15.29%', '15.45%'],
                    'orderFillingNum' => [20, 20],
                    'orderSubmitNum' => [11, 12],
                ],
            ],
        ]]);

        self::assertCount(4, $rows);
        self::assertSame('2026-04-12', $rows[0]['date']);
        self::assertSame('self', $rows[0]['compareType']);
        self::assertSame(3146, $rows[0]['listExposure']);
        self::assertSame(16.72, $rows[0]['flowRate']);
        self::assertSame('competitor', $rows[2]['compareType']);
        self::assertSame(2460, $rows[3]['listExposure']);
        self::assertSame(12, $rows[3]['orderSubmitNum']);
    }

    /**
     * 覆盖 extractCtripBusinessDataList/buildCtripBusinessFingerprint/extractCtripResponseDates/extractHotelData：
     * 验证多层响应解析、指纹稳定性、日期递归提取。
     */
    public function testCtripBusinessExtractionFingerprintAndDates(): void
    {
        $controller = $this->controller();
        $response = [
            'data' => [
                'bucket' => [
                    ['hotelId' => 2, 'hotelName' => 'B', 'amount' => 200, 'quantity' => 2],
                    ['hotel_id' => 1, 'hotel_name' => 'A', 'amount' => 100, 'room_nights' => 1],
                ],
            ],
        ];

        $list = $this->invokeNonPublic($controller, 'extractCtripBusinessDataList', [$response]);
        self::assertCount(2, $list);

        $fingerprintA = $this->invokeNonPublic($controller, 'buildCtripBusinessFingerprint', [$response]);
        $fingerprintB = $this->invokeNonPublic($controller, 'buildCtripBusinessFingerprint', [[
            ['hotel_id' => 1, 'hotel_name' => 'A', 'totalAmount' => 100, 'roomNights' => 1],
            ['hotelId' => 2, 'hotelName' => 'B', 'amount' => 200, 'quantity' => 2],
        ]]);
        self::assertNotSame('', $fingerprintA);
        self::assertSame($fingerprintA, $fingerprintB);

        $dates = $this->invokeNonPublic($controller, 'extractCtripResponseDates', [[
            'dataDate' => '20260501',
            'nested' => ['statDate' => '2026-05-02 12:00:00'],
            'invalid' => ['reportDate' => ['2026-05-03']],
        ]]);
        self::assertSame(['2026-05-01', '2026-05-02'], $dates);

        $hotels = $this->invokeNonPublic($controller, 'extractHotelData', [[
            'outer' => [['HotelId' => 9, 'HotelName' => 'Nested']],
        ]]);
        self::assertSame(9, $hotels[0]['HotelId']);
    }

    public function testUnknownLegacyMetricsStayNullAcrossAnalyticsHistoryAndFingerprinting(): void
    {
        $controller = $this->controller();
        $aggregated = $this->invokeNonPublic($controller, 'aggregateByDimension', [[
            [
                'data_date' => '2026-07-13',
                'amount' => null,
                'quantity' => null,
                'data_value' => null,
                'book_order_num' => null,
                'comment_score' => null,
            ],
        ], 'day']);

        self::assertNull($aggregated[0]['amount']);
        self::assertNull($aggregated[0]['quantity']);
        self::assertNull($aggregated[0]['data_value']);
        self::assertNull($aggregated[0]['book_order_num']);
        self::assertNull($aggregated[0]['avg_comment_score']);
        self::assertSame('partial', $aggregated[0]['data_status']);
        self::assertContains('amount', $aggregated[0]['data_gaps']);

        $unknownHistory = $this->invokeNonPublic($controller, 'buildOnlineRowPayload', [[
            'hotel_id' => '832085',
            'hotel_name' => 'A',
            'data_date' => '2026-07-13',
            'amount' => null,
        ]]);
        $zeroHistory = $this->invokeNonPublic($controller, 'buildOnlineRowPayload', [[
            'hotel_id' => '832085',
            'hotel_name' => 'A',
            'data_date' => '2026-07-13',
            'amount' => 0,
            'quantity' => '0',
            'book_order_num' => 0,
        ]]);
        self::assertNull($unknownHistory['amount']);
        self::assertNull($unknownHistory['quantity']);
        self::assertSame(0.0, $zeroHistory['amount']);
        self::assertSame(0, $zeroHistory['quantity']);
        self::assertSame(0, $zeroHistory['bookOrderNum']);

        $missingFingerprint = $this->invokeNonPublic($controller, 'buildCtripBusinessFingerprint', [[
            ['hotelId' => '832085', 'hotelName' => 'A'],
        ]]);
        $zeroFingerprint = $this->invokeNonPublic($controller, 'buildCtripBusinessFingerprint', [[
            ['hotelId' => '832085', 'hotelName' => 'A', 'amount' => 0],
        ]]);
        self::assertNotSame($missingFingerprint, $zeroFingerprint);
    }

    public function testBackendBuildsCtripBusinessDisplayRowsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            'date_results' => [
                ['data' => ['data' => [['hotelId' => 1, 'hotelName' => 'A', 'amount' => 100, 'quantity' => 2, 'bookOrderNum' => 1]]]],
                ['data' => ['data' => [['hotelId' => 1, 'hotelName' => 'A', 'amount' => 80, 'quantity' => 3, 'bookOrderNum' => 2]]]],
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame('1', (string)$rows[0]['hotelId']);
        self::assertSame('A', $rows[0]['hotelName']);
        self::assertSame(180.0, $rows[0]['amount']);
        self::assertSame(5, $rows[0]['quantity']);
        self::assertSame(3, $rows[0]['bookOrderNum']);
        self::assertSame(3, $rows[0]['totalOrderNum']);
        self::assertSame('携程竞争圈返回', $rows[0]['sourceStatusText']);
        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['amount']);
    }

    public function testBackendDoesNotInventAiEstimatedRoomNights(): void
    {
        $controller = $this->controller();

        $missing = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            ['hotelId' => 'A', 'hotelName' => 'A', 'bookOrderNum' => 10],
        ]]);
        $returned = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            ['hotelId' => 'B', 'hotelName' => 'B', 'bookOrderNum' => 10, 'aiEstimatedTotalRoomNights' => 12],
        ]]);
        $summary = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplaySummary', [$missing]);

        self::assertNull($missing[0]['aiEstimatedTotalRoomNights']);
        self::assertSame(12, $returned[0]['aiEstimatedTotalRoomNights']);
        self::assertNull($summary['metrics']['aiEstimatedTotalRoomNights']);
        self::assertNotContains('aiEstimatedTotalRoomNights', array_column($summary['cards'], 'key'));
        self::assertStringNotContainsString('全渠道AI预计总间夜数', $summary['source_notice']);
        self::assertStringNotContainsString('AI推导', $summary['source_notice']);
    }

    public function testBackendBuildsCtripBusinessDisplayRowsFromStoredRawData(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            [
                'hotel_id' => '121669867',
                'hotel_name' => '长沙宾际·云端酒店',
                'amount' => '28898.42',
                'quantity' => 114,
                'book_order_num' => 95,
                'raw_data' => json_encode([
                    'hotelId' => 121669867,
                    'hotelName' => '长沙宾际·云端酒店',
                    'totalDetailNum' => 612,
                    'qunarDetailVisitors' => 438,
                    'qunarDetailCR' => 10.05,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame(612, $rows[0]['totalDetailNum']);
        self::assertSame(438, $rows[0]['qunarDetailVisitors']);

        $summary = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplaySummary', [$rows]);
        self::assertSame(612, $summary['metrics']['totalDetailNum']);
        self::assertSame(438, $summary['metrics']['totalQunarDetailVisitors']);
    }

    public function testBackendMarksReturnedZeroCtripMetricAsReturnedSource(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            ['hotelId' => 'Z', 'hotelName' => 'Zero Hotel', 'amount' => 0, 'quantity' => 0],
        ]]);

        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['amount']);
        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['quantity']);
        self::assertSame('系统未返回', $rows[0]['metricSourceStatus']['totalDetailNum']);
    }

    public function testBackendTreatsZeroQunarVisitorsAsPartialCtripCapture(): void
    {
        $controller = $this->controller();

        $quality = $this->invokeNonPublic($controller, 'ctripBusinessQunarVisitorQuality', [[
            ['hotelId' => 'A', 'hotelName' => 'A', 'amount' => 1000, 'quantity' => 5, 'qunarDetailVisitors' => 0],
            ['hotelId' => 'B', 'hotelName' => 'B', 'amount' => 800, 'quantity' => 4, 'qunarDetailVisitors' => 0],
        ]]);

        self::assertSame(2, $quality['row_count']);
        self::assertSame(0.0, $quality['visitor_total']);
        self::assertFalse($quality['ready']);
        self::assertSame('partial_qunar_visitor_gap', $quality['status']);
        self::assertStringContainsString('仅作为字段缺口提示', $quality['message']);
        self::assertStringContainsString('不阻断携程竞争圈获取和入库', $quality['message']);
        self::assertStringNotContainsString('需要自动重抓', $quality['message']);
    }

    public function testBackendBuildsCtripBusinessDisplayDerivedMetricsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            ['hotelId' => 'A', 'hotelName' => 'A', 'amount' => 1000, 'quantity' => 5, 'bookOrderNum' => 2, 'totalDetailNum' => 100],
            ['hotelId' => 'B', 'hotelName' => 'B', 'amount' => 800, 'quantity' => 4, 'bookOrderNum' => 1, 'totalDetailNum' => 50],
        ]]);

        self::assertSame('A', $rows[0]['hotelId']);
        self::assertSame(200.0, $rows[0]['adr']);
        self::assertSame('200.00', $rows[0]['adrText']);
        self::assertSame(100.0, $rows[0]['ari']);
        self::assertSame('100.0', $rows[0]['ariText']);
        self::assertSame(round(100 * log(5), 2), $rows[0]['sci']);
        self::assertSame((string)round(100 * log(5)), $rows[0]['sciText']);
        self::assertSame(2.0, $rows[0]['bookingRate']);
        self::assertSame('2.0%', $rows[0]['bookingRateText']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['adr']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['ari']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['bookingRate']);
        self::assertSame('携程竞争圈返回', $rows[0]['metricSourceStatus']['bookingRate']);
        self::assertSame('系统未返回', $rows[0]['metricSourceStatus']['qunarDetailVisitors']);
    }

    public function testBackendBuildsCtripBusinessDisplaySummaryForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplayHotels', [[
            ['hotelId' => 'A', 'hotelName' => 'A', 'amount' => 1000, 'quantity' => 5, 'bookOrderNum' => 2, 'totalOrderNum' => 4, 'totalDetailNum' => 100, 'qunarDetailVisitors' => 50],
            ['hotelId' => 'B', 'hotelName' => 'B', 'amount' => 800, 'quantity' => 4, 'bookOrderNum' => 1, 'totalOrderNum' => 2, 'totalDetailNum' => 50, 'qunarDetailVisitors' => 25],
        ]]);
        $summary = $this->invokeNonPublic($controller, 'buildCtripBusinessDisplaySummary', [$rows]);

        self::assertSame('success', $summary['status']);
        self::assertSame(2, $summary['metrics']['hotelCount']);
        self::assertSame(1800.0, $summary['metrics']['totalAmount']);
        self::assertSame(9, $summary['metrics']['totalQuantity']);
        self::assertSame(200.0, $summary['metrics']['adr']);
        self::assertSame(100.0, $summary['metrics']['avgAri']);
        self::assertSame(round((round(100 * log(5), 2) + round(100 * log(4), 2)) / 2, 2), $summary['metrics']['avgSci']);
        self::assertSame(150, $summary['metrics']['totalDetailNum']);
        self::assertSame(75, $summary['metrics']['totalQunarDetailVisitors']);
        self::assertSame(6, $summary['metrics']['totalOrderNum']);
        self::assertSame(2, $summary['metrics']['sourceStatusReadyCount']);
        self::assertSame(2, $summary['metrics']['sourceStatusTotalCount']);
        self::assertStringContainsString('携程竞争圈/榜单已返回字段', $summary['source_notice']);
        self::assertSame('totalAmount', $summary['cards'][1]['key']);
        self::assertSame('¥1,800', $summary['cards'][1]['value']);
        self::assertSame('adr', $summary['cards'][3]['key']);
        self::assertSame('¥200.00', $summary['cards'][3]['value']);
    }

    public function testBackendBuildsMeituanBusinessDisplayRowsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 9, 'rank' => 2]],
                    ],
                    [
                        'dimName' => '房费收入榜',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 600, 'rank' => 3]],
                    ],
                    [
                        'dimName' => '曝光榜',
                        'aiMetricName' => 'EXPOSURE',
                        'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 1200]],
                    ],
                ],
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame('8', (string)$rows[0]['poiId']);
        self::assertSame('M', $rows[0]['hotelName']);
        self::assertSame(9.0, $rows[0]['roomNights']);
        self::assertSame(600.0, $rows[0]['roomRevenue']);
        self::assertSame(1200.0, $rows[0]['exposure']);
        self::assertSame(2, $rows[0]['rank']);
    }

    public function testBackendCarriesMeituanPlatformTagsFromReturnedFieldsOnly(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [[
                            'poiId' => 8,
                            'poiName' => 'M',
                            'dataValue' => 9,
                            'rank' => 2,
                            'tags' => [
                                ['name' => 'VIP'],
                                ['tagName' => '优选'],
                            ],
                            'tagList' => [
                                ['name' => '1'],
                                ['name' => 'true'],
                            ],
                            'crownLevel' => 2,
                        ]],
                    ],
                ],
            ],
        ], [
            'date_range' => '1',
            'rank_type' => 'P_RZ',
            'target_poi_id' => '8',
        ]]);

        self::assertSame(['VIP', '优选', '冠级2'], $rows[0]['platformTags']);
        self::assertTrue($rows[0]['hasVipTag']);
        self::assertSame('VIP / 优选 / 冠级2', $rows[0]['platformTagText']);
        self::assertSame('美团榜单返回', $rows[0]['platformTagSourceText']);
        self::assertTrue($rows[0]['isSelf']);
        self::assertSame('昨日第2', $rows[0]['rankSummaryText']);
    }

    /**
     * 覆盖 buildCtripTrafficDateRange：
     * 验证预设日期、自定义日期、非法日期范围异常。
     */
    public function testBackendBuildsMeituanBusinessDisplayDerivedMetricsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    ['dimName' => 'room nights', 'aiMetricName' => 'P_RZ_NIGHT_COUNT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 10]]],
                    ['dimName' => 'room revenue', 'aiMetricName' => 'P_RZ_ROOM_PAY', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 1000]]],
                    ['dimName' => 'sales nights', 'aiMetricName' => 'P_XS_NIGHT_COUNT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 8]]],
                    ['dimName' => 'sales amount', 'aiMetricName' => 'P_XS_AMT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 960]]],
                    ['dimName' => 'exposure', 'aiMetricName' => 'EXPOSURE', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 2000]]],
                    ['dimName' => 'view', 'aiMetricName' => 'VIEW', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 500]]],
                    ['dimName' => 'view conversion', 'aiMetricName' => 'VIEW_CONVERT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 0.5]]],
                    ['dimName' => 'pay conversion', 'aiMetricName' => 'PAY_CONVERT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 0.1]]],
                ],
            ],
        ]]);

        self::assertSame(100.0, $rows[0]['avgRoomPrice']);
        self::assertSame('100', $rows[0]['avgRoomPriceText']);
        self::assertSame(120.0, $rows[0]['avgSalesPrice']);
        self::assertSame('120', $rows[0]['avgSalesPriceText']);
        self::assertSame(50, $rows[0]['orderCount']);
        self::assertSame('50', $rows[0]['orderCountText']);
        self::assertSame('1,000', $rows[0]['roomRevenueText']);
        self::assertSame('', $rows[0]['roomRevenuePrefix']);
        self::assertSame('960', $rows[0]['salesText']);
        self::assertSame('', $rows[0]['salesPrefix']);
        self::assertSame(0.05, $rows[0]['absoluteConversion']);
        self::assertSame('5.00%', $rows[0]['absoluteConversionText']);
        self::assertSame('50.00%', $rows[0]['viewConversionText']);
        self::assertSame('10.00%', $rows[0]['payConversionText']);
        self::assertSame('derived_from_display_metrics', $rows[0]['displayMetricStatus']['avgRoomPrice']);
        self::assertSame('derived_from_display_metrics', $rows[0]['displayMetricStatus']['avgSalesPrice']);
        self::assertSame('room_revenue_div_room_nights_display_metric', $rows[0]['metricDerived']['avgRoomPrice']['method']);
        self::assertSame('sales_div_sales_room_nights_display_metric', $rows[0]['metricDerived']['avgSalesPrice']['method']);
        self::assertSame('views_times_pay_conversion', $rows[0]['metricDerived']['orderCount']['method']);
        self::assertSame('ok', $rows[0]['displayMetricStatus']['absoluteConversion']);
    }

    public function testBackendMarksMeituanAverageRoomPriceAsDerivedFromRankValues(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    ['dimName' => 'room nights', 'aiMetricName' => 'P_RZ_NIGHT_COUNT', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 492, 'rank' => 1]]],
                    ['dimName' => 'room revenue', 'aiMetricName' => 'P_RZ_ROOM_PAY', 'roundRanks' => [['poiId' => 8, 'poiName' => 'M', 'dataValue' => 6054.34, 'rank' => 1]]],
                ],
            ],
        ]]);

        self::assertSame(492.0, $rows[0]['roomNights']);
        self::assertSame(6054.34, $rows[0]['roomRevenue']);
        self::assertSame('492', $rows[0]['roomNightsText']);
        self::assertSame('6,054', $rows[0]['roomRevenueText']);
        self::assertSame('', $rows[0]['roomRevenuePrefix']);
        self::assertSame(12.0, $rows[0]['avgRoomPrice']);
        self::assertSame('12', $rows[0]['avgRoomPriceText']);
        self::assertSame('', $rows[0]['avgRoomPricePrefix']);
        self::assertSame('derived_from_display_metrics', $rows[0]['displayMetricStatus']['avgRoomPrice']);
        self::assertSame('room_revenue_div_room_nights_display_metric', $rows[0]['metricDerived']['avgRoomPrice']['method']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        self::assertSame(12.0, $summary['metrics']['avgRoomPrice']);
        self::assertSame('-', $summary['metrics']['marketPriceSignal']);
    }

    public function testBackendParsesMeituanTradeManageCardsAsSelfMetricValues(): void
    {
        $controller = $this->controller();

        $values = $this->invokeNonPublic($controller, 'normalizeMeituanSelfMetricValues', [[
            'data' => [
                'cards' => [
                    ['id' => 1, 'title' => '销售间夜', 'value' => '101'],
                    ['id' => 2, 'title' => '销售额', 'value' => '1.77', 'suffix' => '万元'],
                    ['id' => 4, 'title' => '入住间夜', 'value' => '88'],
                    ['id' => 5, 'title' => '入住金额', 'value' => '1.54', 'suffix' => '万元'],
                    ['id' => 6, 'title' => '平均房价', 'value' => '175.03', 'suffix' => '元'],
                ],
            ],
        ]]);

        self::assertSame(101.0, $values['salesRoomNights']);
        self::assertSame(17700.0, $values['sales']);
        self::assertSame(88.0, $values['roomNights']);
        self::assertSame(15400.0, $values['roomRevenue']);
        self::assertArrayNotHasKey('avgRoomPrice', $values);
    }

    public function testBackendParsesMeituanTradeManageOrderCountCardAsSelfMetricValue(): void
    {
        $controller = $this->controller();

        $values = $this->invokeNonPublic($controller, 'normalizeMeituanSelfMetricValues', [[
            'data' => [
                'cards' => [
                    ['id' => 3, 'title' => 'pay order count', 'value' => '9'],
                ],
            ],
        ]]);

        self::assertSame(9.0, $values['orderCount']);
    }

    public function testBackendFetchesMeituanTradeMetricsWhenOnlySomeSelfMetricsExist(): void
    {
        $controller = $this->controller();
        $requiredFields = ['roomNights', 'roomRevenue', 'salesRoomNights', 'sales', 'orderCount'];

        self::assertTrue($this->invokeNonPublic($controller, 'hasMissingMeituanSelfMetricValues', [[
            'exposure' => 22333,
            'views' => 1884,
            'sales' => 1763,
        ], $requiredFields]));

        self::assertFalse($this->invokeNonPublic($controller, 'hasMissingMeituanSelfMetricValues', [[
            'roomNights' => 7,
            'roomRevenue' => 1177,
            'salesRoomNights' => 10,
            'sales' => 1763,
            'orderCount' => 9,
        ], $requiredFields]));
    }

    public function testBackendReusesMeituanSelfTradeMetricsWithinShortCacheWindow(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        restore_error_handler();
        restore_exception_handler();
        $controller = $this->controller();
        $cacheKey = $this->invokeNonPublic($controller, 'meituanSelfTradeMetricCacheKey', [
            'partner-1',
            'poi-1',
            '2026-07-14',
            '2026-07-14',
            'cookie-a',
            '0',
        ]);
        cache($cacheKey, [
            'status' => 'returned',
            'values' => ['roomNights' => 21, 'roomRevenue' => 3409],
            'message' => '',
            'update_time' => '2026-07-14 00:21:46',
            'cache_hit' => false,
        ], 120);

        try {
            $result = $this->invokeNonPublic($controller, 'fetchMeituanSelfTradeMetricValues', [
                'partner-1',
                'poi-1',
                '2026-07-14',
                '2026-07-14',
                'cookie-a',
                [],
                '0',
            ]);

            self::assertTrue($result['cache_hit']);
            self::assertSame(21.0, $result['values']['roomNights']);
            self::assertSame(3409.0, $result['values']['roomRevenue']);
        } finally {
            cache($cacheKey, null);
        }
    }

    public function testBackendKeepsRealtimePeerStayValuesMissingWhileShowingSelfActuals(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 50, 'rank' => 3],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                        ],
                    ],
                    [
                        'dimName' => '房费收入榜',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 50, 'rank' => 3],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                        ],
                    ],
                ],
            ],
        ], [
            'date_range' => '0',
            'rank_type' => 'P_RZ',
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'roomNights' => 21,
                'roomRevenue' => 3409,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame('21', $rowsByPoi['SELF']['roomNightsText']);
        self::assertSame('3,409', $rowsByPoi['SELF']['roomRevenueText']);
        self::assertSame('-', $rowsByPoi['RIVAL']['roomNightsText']);
        self::assertSame('-', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertArrayNotHasKey('roomNights', $rowsByPoi['RIVAL']['metricDerived']);
        self::assertArrayNotHasKey('roomRevenue', $rowsByPoi['RIVAL']['metricDerived']);
    }

    public function testBackendDerivesMeituanRoomRevenueFromSelfMetricsBeforeRankAmount(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'room nights',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => 88, 'percent' => 50, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => 492, 'percent' => 100, 'rank' => 1],
                        ],
                    ],
                    [
                        'dimName' => 'room revenue',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => 154, 'percent' => 50, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => 6054.34, 'percent' => 100, 'rank' => 1],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'roomNights' => 88,
                'roomRevenue' => 15400,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(15400.0, $rowsByPoi['SELF']['roomRevenue']);
        self::assertArrayNotHasKey('roomRevenue', $rowsByPoi['SELF']['metricDerived']);
        self::assertSame(30800.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('¥', $rowsByPoi['RIVAL']['roomRevenuePrefix']);
        self::assertSame('30,800', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(63.0, $rowsByPoi['RIVAL']['avgRoomPrice']);
        self::assertSame('63', $rowsByPoi['RIVAL']['avgRoomPriceText']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['method']);
    }

    public function testBackendDerivesMeituanRoomRevenueFromSelfMetricAndRankValueWhenPercentMissing(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'room nights',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => 88, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => 492, 'rank' => 1],
                        ],
                    ],
                    [
                        'dimName' => 'room revenue',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => 1540, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => 6054.34, 'rank' => 1],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'roomNights' => 88,
                'roomRevenue' => 15400,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(15400.0, $rowsByPoi['SELF']['roomRevenue']);
        self::assertSame(1540.0, $rowsByPoi['SELF']['metricRankValue']['roomRevenue']);
        self::assertSame(60543.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('60,543', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(123.0, $rowsByPoi['RIVAL']['avgRoomPrice']);
        self::assertSame('123', $rowsByPoi['RIVAL']['avgRoomPriceText']);
        self::assertSame('self_value_times_row_rank_value_div_self_rank_value', $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['method']);
        self::assertSame(6054.34, $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['row_rank_value']);
    }

    public function testBackendKeepsMeituanRankValueDerivedRoomRevenueThroughDisplayGroups(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayGroups', [[
            [
                'date_range' => '7',
                'self_metric_values' => [
                    'roomNights' => 7,
                    'roomRevenue' => 1177,
                ],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'roomNights' => 7,
                        'roomRevenue' => 117.7,
                        'metricRankValue' => ['roomRevenue' => 117.7],
                        'metricSourceStatus' => ['roomRevenue' => 'rank_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomNights' => 439,
                        'roomRevenue' => 7240,
                        'metricRankValue' => ['roomRevenue' => 7240],
                        'metricSourceStatus' => ['roomRevenue' => 'rank_returned'],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'date_ranges' => ['7'],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(1177.0, $rowsByPoi['SELF']['roomRevenue']);
        self::assertSame(72400.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('72,400', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(165.0, $rowsByPoi['RIVAL']['avgRoomPrice']);
        self::assertSame('self_value_times_row_rank_value_div_self_rank_value', $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['method']);
    }

    public function testBackendDerivesMeituanPercentOnlyRankValuesFromSelfMetrics(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '销售间夜榜',
                        'aiMetricName' => 'P_XS_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 80, 'rank' => 2],
                        ],
                    ],
                    [
                        'dimName' => '销售额榜',
                        'aiMetricName' => 'P_XS_AMT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 70.5, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'salesRoomNights' => 20,
                'sales' => 3000,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(20.0, $rowsByPoi['SELF']['salesRoomNights']);
        self::assertSame(3000.0, $rowsByPoi['SELF']['sales']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['SELF']['metricSourceStatus']['salesRoomNights']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['SELF']['metricSourceStatus']['sales']);
        self::assertArrayNotHasKey('salesRoomNights', $rowsByPoi['SELF']['metricDerived']);
        self::assertSame('¥', $rowsByPoi['SELF']['salesPrefix']);
        self::assertSame(16.0, $rowsByPoi['RIVAL']['salesRoomNights']);
        self::assertSame(2115.0, $rowsByPoi['RIVAL']['sales']);
        self::assertSame('¥', $rowsByPoi['RIVAL']['salesPrefix']);
        self::assertSame('2,115', $rowsByPoi['RIVAL']['salesText']);
        self::assertSame(80.0, $rowsByPoi['RIVAL']['metricRankPercent']['salesRoomNights']);
        self::assertSame('按本店值和美团百分比推导', $rowsByPoi['RIVAL']['metricSourceStatus']['sales']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['sales']['method']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        self::assertSame(5115.0, $summary['metrics']['totalSales']);
        self::assertSame(3, $summary['metrics']['derivedMetricCount']);
        self::assertStringContainsString('推导', $summary['source_notice']);
    }

    public function testBackendKeepsMeituanPercentOnlyRankValuesMissingWithoutActualAnchor(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SECOND', 'poiName' => 'Second Hotel', 'dataValue' => null, 'percent' => 66.67, 'rank' => 2],
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 5.13, 'rank' => 9],
                            ['poiId' => 'ZERO', 'poiName' => 'Zero Hotel', 'dataValue' => null, 'percent' => 0, 'rank' => 11],
                        ],
                    ],
                    [
                        'dimName' => '销售间夜榜',
                        'aiMetricName' => 'P_XS_PAY_ROOM_NIGHT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SECOND', 'poiName' => 'Second Hotel', 'dataValue' => null, 'percent' => 79.55, 'rank' => 2],
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 2.27, 'rank' => 9],
                            ['poiId' => 'ZERO', 'poiName' => 'Zero Hotel', 'dataValue' => null, 'percent' => 0, 'rank' => 11],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(0.0, $rowsByPoi['TOP']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['SECOND']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['SELF']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['ZERO']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['TOP']['salesRoomNights']);
        self::assertSame(0.0, $rowsByPoi['SECOND']['salesRoomNights']);
        self::assertSame(0.0, $rowsByPoi['SELF']['salesRoomNights']);
        self::assertArrayNotHasKey('roomNights', $rowsByPoi['SELF']['metricDerived']);
        self::assertSame('-', $rowsByPoi['TOP']['roomNightsText']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        self::assertSame(0.0, $summary['metrics']['totalRoomNights']);
        self::assertSame(0.0, $summary['metrics']['totalSalesRoomNights']);
        self::assertStringContainsString('未返回可展示数值', $summary['source_notice']);
        self::assertStringNotContainsString('最小一致整数比例尺', $summary['source_notice']);
        $cardsByKey = [];
        foreach ($summary['cards'] as $card) {
            $cardsByKey[$card['key']] = $card;
        }
        self::assertSame('-', $cardsByKey['totalRoomNights']['value']);
        self::assertSame('-', $cardsByKey['totalSalesRoomNights']['value']);
        self::assertSame('-', $cardsByKey['totalSales']['value']);
        self::assertSame('-', $cardsByKey['avgViewConversionRate']['value']);
        self::assertSame('-', $cardsByKey['avgPayConversionRate']['value']);
    }

    public function testBackendDoesNotPrefixPercentScaleMeituanAmountsAsCurrency(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '销售额榜',
                        'aiMetricName' => 'P_XS_AMT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SECOND', 'poiName' => 'Second Hotel', 'dataValue' => null, 'percent' => 50, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertArrayNotHasKey('sales', $rowsByPoi['TOP']['metricDerived']);
        self::assertSame('-', $rowsByPoi['TOP']['salesText']);
        self::assertSame('', $rowsByPoi['TOP']['salesPrefix']);
        self::assertSame('', $rowsByPoi['SECOND']['salesPrefix']);
    }

    public function testBackendDoesNotShowAverageRoomPriceFromHiddenPercentScaleRoomRevenue(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'room nights',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SECOND', 'poiName' => 'Second Hotel', 'dataValue' => null, 'percent' => 50, 'rank' => 2],
                        ],
                    ],
                    [
                        'dimName' => 'room revenue',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SECOND', 'poiName' => 'Second Hotel', 'dataValue' => null, 'percent' => 50, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertArrayNotHasKey('roomRevenue', $rowsByPoi['TOP']['metricDerived']);
        self::assertSame('-', $rowsByPoi['TOP']['roomRevenueText']);
        self::assertSame(0.0, $rowsByPoi['TOP']['avgRoomPrice']);
        self::assertSame('-', $rowsByPoi['TOP']['avgRoomPriceText']);
        self::assertSame('missing_room_nights', $rowsByPoi['TOP']['displayMetricStatus']['avgRoomPrice']);
    }

    public function testBackendDerivesMeituanTrafficRankValuesFromSelfMetricsAsCounts(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'exposure',
                        'aiMetricName' => 'EXPOSURE',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 60, 'rank' => 2],
                        ],
                    ],
                    [
                        'dimName' => 'view',
                        'aiMetricName' => 'VIEW',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 40, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'self_metric_values' => [
                'exposure' => 10000,
                'views' => 2500,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(10000.0, $rowsByPoi['SELF']['exposure']);
        self::assertSame(2500.0, $rowsByPoi['SELF']['views']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['SELF']['metricSourceStatus']['exposure']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['SELF']['metricSourceStatus']['views']);
        self::assertSame('', $rowsByPoi['SELF']['exposurePrefix']);
        self::assertSame('', $rowsByPoi['SELF']['viewsPrefix']);
        self::assertSame(6000.0, $rowsByPoi['RIVAL']['exposure']);
        self::assertSame(1000.0, $rowsByPoi['RIVAL']['views']);
        self::assertSame('', $rowsByPoi['RIVAL']['exposurePrefix']);
        self::assertSame('', $rowsByPoi['RIVAL']['viewsPrefix']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['exposure']['method']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['views']['method']);
    }

    public function testBackendUsesStoredMeituanSelfTrafficAnchorsForPercentOnlyTrafficRanks(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'exposure',
                        'aiMetricName' => 'EXPOSURE',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 60, 'rank' => 2],
                        ],
                    ],
                    [
                        'dimName' => 'view',
                        'aiMetricName' => 'VIEW',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 40, 'rank' => 2],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'stored_self_metric_values' => [
                'list_exposure' => 10000,
                'detail_exposure' => 2500,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(10000.0, $rowsByPoi['SELF']['exposure']);
        self::assertSame(2500.0, $rowsByPoi['SELF']['views']);
        self::assertSame('meituan_stored_self_traffic', $rowsByPoi['SELF']['metricSourceStatus']['exposure']);
        self::assertSame('meituan_stored_self_traffic', $rowsByPoi['SELF']['metricSourceStatus']['views']);
        self::assertSame(6000.0, $rowsByPoi['RIVAL']['exposure']);
        self::assertSame(1000.0, $rowsByPoi['RIVAL']['views']);
        self::assertSame('', $rowsByPoi['RIVAL']['exposurePrefix']);
        self::assertSame('', $rowsByPoi['RIVAL']['viewsPrefix']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['exposure']['method']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['views']['method']);
    }

    public function testBackendNormalizesMeituanFlowConversionMyHotelSelfMetrics(): void
    {
        $controller = $this->controller();

        $values = $this->invokeNonPublic($controller, 'normalizeMeituanSelfMetricValues', [[
            'data' => [
                'myHotel' => [
                    'exposureUV' => 22333,
                    'intentionUV' => 1884,
                    'payOrderCnt' => 108,
                    'intentionPerExposure' => '8.44%',
                    'payOrderPerIntention' => '5.73%',
                ],
            ],
        ]]);

        self::assertSame(22333.0, $values['exposure']);
        self::assertSame(1884.0, $values['views']);
        self::assertSame(108.0, $values['orderCount']);
        self::assertSame(0.0844, $values['viewConversion']);
        self::assertSame(0.0573, $values['payConversion']);
    }

    public function testBackendNormalizesMeituanHomeBusinessDataCardsAsSelfMetrics(): void
    {
        $controller = $this->controller();

        $values = $this->invokeNonPublic($controller, 'normalizeMeituanSelfMetricValues', [[
            'data' => [
                'cards' => [
                    ['id' => 'EXPOSE_PV_CNT', 'value' => '1.84', 'unit' => '万'],
                    ['id' => 'INTENTION_UV', 'value' => '1884'],
                    ['id' => 'PAY_ORDER_CNT_UV', 'value' => '5.73', 'suffix' => '%'],
                    ['id' => 'PAY_ORDER_CNT', 'value' => '108'],
                    ['id' => 'PAY_ROOMNIGHT', 'value' => '113'],
                    ['id' => 'PAY_AMT', 'value' => '1.99', 'unit' => '万', 'suffix' => '元'],
                    ['id' => 'CONSUME_ROOMNIGHT_SPLIT_EX_7DAYS_REFUND', 'value' => '93'],
                ],
            ],
        ]]);

        self::assertSame(18400.0, $values['exposure']);
        self::assertSame(1884.0, $values['views']);
        self::assertSame(0.0573, $values['payConversion']);
        self::assertSame(108.0, $values['orderCount']);
        self::assertSame(113.0, $values['salesRoomNights']);
        self::assertSame(19900.0, $values['sales']);
        self::assertSame(93.0, $values['roomNights']);
    }

    public function testBackendKeepsMeituanSelfMetricAnchorsScopedByDateRangeGroups(): void
    {
        $controller = $this->controller();

        $groups = [
            [
                'date_range' => '7',
                'self_metric_values' => ['salesRoomNights' => 70],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'salesRoomNights' => 70,
                        'metricRankPercent' => ['salesRoomNights' => 100],
                        'metricSourceStatus' => ['salesRoomNights' => 'meituan_business_detail_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'salesRoomNights' => 0,
                        'metricRankPercent' => ['salesRoomNights' => 50],
                        'metricSourceStatus' => ['salesRoomNights' => '美团仅返回百分比'],
                    ],
                ],
            ],
            [
                'date_range' => '30',
                'self_metric_values' => ['salesRoomNights' => 300],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'salesRoomNights' => 300,
                        'metricRankPercent' => ['salesRoomNights' => 100],
                        'metricSourceStatus' => ['salesRoomNights' => 'meituan_business_detail_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'salesRoomNights' => 0,
                        'metricRankPercent' => ['salesRoomNights' => 10],
                        'metricSourceStatus' => ['salesRoomNights' => '美团仅返回百分比'],
                    ],
                ],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayGroups', [$groups, [
            'target_poi_id' => 'SELF',
            'date_ranges' => ['7', '30'],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(35.0, $rowsByPoi['RIVAL']['salesRoomNights']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['salesRoomNights']['method']);
    }

    public function testBackendKeepsMeituanRoomRevenueScopedToCurrentDateRangeGroup(): void
    {
        $controller = $this->controller();

        $groups = [
            [
                'date_range' => '7',
                'self_metric_values' => ['roomRevenue' => 15400],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'roomRevenue' => 15400,
                        'metricRankPercent' => ['roomRevenue' => 50],
                        'metricSourceStatus' => ['roomRevenue' => 'meituan_business_detail_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomRevenue' => 0,
                        'metricRankPercent' => ['roomRevenue' => 100],
                        'metricSourceStatus' => ['roomRevenue' => '美团仅返回百分比'],
                    ],
                ],
            ],
            [
                'date_range' => '30',
                'self_metric_values' => ['roomRevenue' => 60000],
                'display_hotels' => [
                    [
                        'poiId' => 'SELF',
                        'hotelName' => 'Self Hotel',
                        'roomRevenue' => 60000,
                        'metricRankPercent' => ['roomRevenue' => 50],
                        'metricSourceStatus' => ['roomRevenue' => 'meituan_business_detail_returned'],
                        'isSelf' => true,
                    ],
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomRevenue' => 0,
                        'metricRankPercent' => ['roomRevenue' => 80],
                        'metricSourceStatus' => ['roomRevenue' => '美团仅返回百分比'],
                    ],
                ],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayGroups', [$groups, [
            'target_poi_id' => 'SELF',
            'date_ranges' => ['7', '30'],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(30800.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('30,800', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(15400.0, $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['self_value']);
        self::assertSame(100.0, $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['row_percent']);
    }

    public function testBackendPrefersHigherQualityMeituanGroupMetricOverEarlierPercentScale(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayGroups', [[
            [
                'date_range' => '1',
                'display_hotels' => [
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomRevenue' => 1,
                        'metricSourceStatus' => ['roomRevenue' => 'percent_only'],
                        'metricDerived' => ['roomRevenue' => ['method' => 'percent_min_integer_scale']],
                    ],
                ],
            ],
            [
                'date_range' => '30',
                'display_hotels' => [
                    [
                        'poiId' => 'RIVAL',
                        'hotelName' => 'Rival Hotel',
                        'roomRevenue' => 1000,
                        'metricSourceStatus' => ['roomRevenue' => 'meituan_business_detail_returned'],
                    ],
                ],
            ],
        ], [
            'date_ranges' => ['1', '30'],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(1000.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('1,000', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame('meituan_business_detail_returned', $rowsByPoi['RIVAL']['metricSourceStatus']['roomRevenue']);
        self::assertArrayNotHasKey('roomRevenue', $rowsByPoi['RIVAL']['metricDerived']);
    }

    public function testBackendKeepsMeituanTodayRealtimePercentOnlyValuesMissing(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 0, 'rank' => 11],
                        ],
                    ],
                    [
                        'dimName' => '房费收入榜',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'TOP', 'poiName' => 'Top Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 0, 'rank' => 11],
                        ],
                    ],
                ],
            ],
        ], [
            'target_poi_id' => 'SELF',
            'date_range' => '0',
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(0.0, $rowsByPoi['TOP']['roomNights']);
        self::assertSame('-', $rowsByPoi['TOP']['roomNightsText']);
        self::assertSame('-', $rowsByPoi['TOP']['roomRevenueText']);
        self::assertSame('美团仅返回百分比', $rowsByPoi['TOP']['metricSourceStatus']['roomNights']);
        self::assertSame('美团仅返回百分比', $rowsByPoi['TOP']['metricSourceStatus']['roomRevenue']);
        self::assertArrayNotHasKey('roomNights', $rowsByPoi['TOP']['metricDerived']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        $healthByKey = [];
        foreach ($summary['rank_health_rows'] as $row) {
            $healthByKey[$row['key']] = $row;
        }
        self::assertSame('rank_only', $healthByKey['P_RZ']['status']);
        self::assertSame('仅排名', $healthByKey['P_RZ']['statusText']);
        self::assertSame(0, $summary['metrics']['rankHealthReadyCount']);
        self::assertStringContainsString('不用 0', $summary['source_notice']);
    }

    public function testBackendFillsMeituanFunnelColumnsFromPercentDerivedTrafficAndSales(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayHotels', [[
            [
                'poiId' => 'TOP',
                'hotelName' => 'Top Hotel',
                'salesRoomNights' => 44,
                'exposure' => 2166,
                'views' => 232,
                'metricSourceStatus' => [
                    'salesRoomNights' => '按美团百分比最小整数比例尺估算',
                    'exposure' => '按美团百分比最小整数比例尺估算',
                    'views' => '按美团百分比最小整数比例尺估算',
                ],
                'metricDerived' => [
                    'salesRoomNights' => ['method' => 'percent_min_integer_scale'],
                    'exposure' => ['method' => 'percent_min_integer_scale'],
                    'views' => ['method' => 'percent_min_integer_scale'],
                ],
            ],
        ]]);

        self::assertSame(0, $rows[0]['orderCount']);
        self::assertSame('-', $rows[0]['orderCountText']);
        self::assertSame(round(232 / 2166, 4), $rows[0]['viewConversion']);
        self::assertSame('10.71%', $rows[0]['viewConversionText']);
        self::assertSame(0.0, $rows[0]['payConversion']);
        self::assertSame('-', $rows[0]['payConversionText']);
        self::assertSame(0.0, $rows[0]['absoluteConversion']);
        self::assertSame('-', $rows[0]['absoluteConversionText']);
        self::assertSame('指数 ', $rows[0]['exposurePrefix']);
        self::assertSame('指数 ', $rows[0]['viewsPrefix']);
        self::assertSame('views_div_exposure', $rows[0]['metricDerived']['viewConversion']['method']);
        self::assertArrayNotHasKey('orderCount', $rows[0]['metricDerived']);
        self::assertArrayNotHasKey('payConversion', $rows[0]['metricDerived']);
        self::assertArrayNotHasKey('absoluteConversion', $rows[0]['metricDerived']);
        self::assertArrayNotHasKey('orderCount', $rows[0]['metricSourceStatus']);
        self::assertSame('按曝光和浏览估算', $rows[0]['metricSourceStatus']['viewConversion']);
        self::assertSame('missing_order_count', $rows[0]['displayMetricStatus']['orderCount']);
        self::assertSame('missing_conversion', $rows[0]['displayMetricStatus']['absoluteConversion']);
    }

    public function testBackendBuildsMeituanBusinessDisplaySummaryForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayHotels', [[
            ['poiId' => 'A', 'hotelName' => 'A', 'roomNights' => 10, 'roomRevenue' => 1000, 'salesRoomNights' => 8, 'sales' => 960, 'exposure' => 2000, 'views' => 500, 'viewConversion' => 0.5, 'payConversion' => 0.1],
            ['poiId' => 'B', 'hotelName' => 'B', 'roomNights' => 5, 'roomRevenue' => 400, 'salesRoomNights' => 4, 'sales' => 360, 'exposure' => 1000, 'views' => 250, 'viewConversion' => 0.4, 'payConversion' => 0.08],
        ]]);
        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, [
            'competitor_room_count' => 20,
            'date_ranges' => ['1'],
        ]]);

        self::assertSame('success', $summary['status']);
        self::assertSame(2, $summary['metrics']['hotelCount']);
        self::assertSame(20, $summary['metrics']['marketInventory']);
        self::assertSame(75.0, $summary['metrics']['marketVitalityRate']);
        self::assertSame(15.0, $summary['metrics']['totalRoomNights']);
        self::assertSame(1400.0, $summary['metrics']['totalRoomRevenue']);
        self::assertSame(12.0, $summary['metrics']['totalSalesRoomNights']);
        self::assertSame(1320.0, $summary['metrics']['totalSales']);
        self::assertSame(3000.0, $summary['metrics']['totalExposure']);
        self::assertSame(750.0, $summary['metrics']['totalViews']);
        self::assertSame(70, $summary['metrics']['totalOrderCount']);
        self::assertSame(45.0, $summary['metrics']['avgViewConversionRate']);
        self::assertSame(9.0, $summary['metrics']['avgPayConversionRate']);
        self::assertSame(4.1, $summary['metrics']['avgAbsoluteConversionRate']);
        $cardsByKey = [];
        foreach ($summary['cards'] as $card) {
            $cardsByKey[$card['key']] = $card;
        }
        self::assertSame('2', $cardsByKey['hotelCount']['value']);
        self::assertSame('20', $cardsByKey['marketInventory']['value']);
    }

    public function testMeituanHistoricalTradePresetsUseCustomDateType(): void
    {
        $controller = $this->controller();

        self::assertSame('CUSTOM', $this->invokeNonPublic($controller, 'meituanSelfTradeDateType', ['7', '2026-07-04', '2026-07-11']));
        self::assertSame('CUSTOM', $this->invokeNonPublic($controller, 'meituanSelfTradeDateType', ['30', '2026-06-12', '2026-07-11']));
        self::assertSame('CUSTOM', $this->invokeNonPublic($controller, 'meituanSelfTradeDateType', ['1', '2026-06-12', '2026-07-11']));
        self::assertSame('DAY', $this->invokeNonPublic($controller, 'meituanSelfTradeDateType', ['1', '2026-07-11', '2026-07-11']));
    }

    public function testMeituanSevenDayMissingTradeAnchorFallsBackToDailyTotals(): void
    {
        $controller = $this->controller();
        if (!method_exists($controller, 'fetchMeituanSelfDailyTradeMetricValues')) {
            self::fail('Missing seven-day daily trade fallback');
        }

        $requestedDates = [];
        $result = $this->invokeNonPublic($controller, 'fetchMeituanSelfDailyTradeMetricValues', [
            'partner-1',
            'poi-1',
            '2026-07-05',
            '2026-07-11',
            'cookie',
            [],
            static function (string $date) use (&$requestedDates): array {
                $requestedDates[] = $date;
                return [
                    'status' => 'returned',
                    'values' => [
                        'roomNights' => 2,
                        'roomRevenue' => 100,
                        'salesRoomNights' => 3,
                        'sales' => 120,
                        'orderCount' => 1,
                    ],
                ];
            },
        ]);

        self::assertSame([
            '2026-07-05',
            '2026-07-06',
            '2026-07-07',
            '2026-07-08',
            '2026-07-09',
            '2026-07-10',
            '2026-07-11',
        ], $requestedDates);
        self::assertSame('returned', $result['status']);
        self::assertSame(7, $result['days_returned']);
        self::assertSame(14.0, $result['values']['roomNights']);
        self::assertSame(700.0, $result['values']['roomRevenue']);
        self::assertSame(21.0, $result['values']['salesRoomNights']);
        self::assertSame(840.0, $result['values']['sales']);
        self::assertSame(7.0, $result['values']['orderCount']);
    }

    public function testMeituanThirtyDayMissingRoomRevenueAnchorFallsBackToDailyTotals(): void
    {
        $controller = $this->controller();
        $requestedDates = [];

        $result = $this->invokeNonPublic($controller, 'fetchMeituanSelfDailyTradeMetricValues', [
            'partner-1',
            'poi-1',
            '2026-06-12',
            '2026-07-11',
            'cookie',
            [],
            static function (string $date) use (&$requestedDates): array {
                $requestedDates[] = $date;
                return [
                    'status' => 'returned',
                    'values' => [
                        'roomNights' => 2,
                        'roomRevenue' => 100,
                        'salesRoomNights' => 3,
                        'sales' => 120,
                        'orderCount' => 1,
                    ],
                ];
            },
        ]);

        self::assertCount(30, $requestedDates);
        self::assertSame('2026-06-12', $requestedDates[0]);
        self::assertSame('2026-07-11', $requestedDates[29]);
        self::assertSame('returned', $result['status']);
        self::assertSame(30, $result['days_returned']);
        self::assertSame(60.0, $result['values']['roomNights']);
        self::assertSame(3000.0, $result['values']['roomRevenue']);
        self::assertSame(90.0, $result['values']['salesRoomNights']);
        self::assertSame(3600.0, $result['values']['sales']);
        self::assertSame(30.0, $result['values']['orderCount']);
    }

    public function testMeituanBooleanVipTagIsPreservedForDisplay(): void
    {
        $controller = $this->controller();

        self::assertSame([
            'tags' => ['VIP'],
            'status' => 'returned',
        ], $this->invokeNonPublic($controller, 'extractMeituanPlatformTagInfo', [['vipTag' => true]]));
        self::assertSame([
            'tags' => [],
            'status' => 'returned_empty',
        ], $this->invokeNonPublic($controller, 'extractMeituanPlatformTagInfo', [['vipTag' => false]]));
    }

    public function testMeituanMissingRoomRevenueSummaryDoesNotPretendZeroRevenue(): void
    {
        $controller = $this->controller();
        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayHotels', [[[
            'poiId' => 'A',
            'hotelName' => 'A',
            'roomNights' => 10,
            'roomRevenue' => 1000,
            'metricSourceStatus' => [
                'roomNights' => '按美团百分比最小整数比例尺估算',
                'roomRevenue' => '按美团百分比最小整数比例尺估算',
            ],
            'metricDerived' => [
                'roomNights' => ['method' => 'percent_min_integer_scale'],
                'roomRevenue' => ['method' => 'percent_min_integer_scale'],
            ],
        ]]]);
        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);
        $cardsByKey = [];
        foreach ($summary['cards'] as $card) {
            $cardsByKey[$card['key']] = $card;
        }

        self::assertSame('-', $cardsByKey['totalRoomRevenue']['value']);
    }

    public function testBackendBuildsMeituanRankInsightsAndGapsForFrontend(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'mergeMeituanBusinessDisplayHotels', [[
            [
                'poiId' => 'SELF',
                'hotelName' => 'Self Hotel',
                'roomNights' => 8,
                'roomRevenue' => 800,
                'salesRoomNights' => 7,
                'sales' => 770,
                'exposure' => 680,
                'views' => 150,
                'viewConversion' => 0.12,
                'payConversion' => 0.02,
                'rank' => 11,
                'platformTags' => ['VIP'],
                'isSelf' => true,
                'rankHistory' => [
                    ['dateRange' => '1', 'dateRangeLabel' => '昨日', 'rankType' => 'P_RZ', 'rankTypeLabel' => '入住榜', 'rank' => 11],
                    ['dateRange' => '7', 'dateRangeLabel' => '近7天', 'rankType' => 'P_RZ', 'rankTypeLabel' => '入住榜', 'rank' => 8],
                    ['dateRange' => '30', 'dateRangeLabel' => '近30天', 'rankType' => 'P_RZ', 'rankTypeLabel' => '入住榜', 'rank' => 7],
                    ['dateRange' => '30', 'dateRangeLabel' => '近30天', 'rankType' => 'P_XS', 'rankTypeLabel' => '销售榜', 'rank' => 14],
                ],
            ],
            [
                'poiId' => 'TOP',
                'hotelName' => 'Top Hotel',
                'roomNights' => 12,
                'roomRevenue' => 1500,
                'salesRoomNights' => 11,
                'sales' => 1600,
                'exposure' => 700,
                'views' => 160,
                'viewConversion' => 0.14,
                'payConversion' => 0.10,
                'rank' => 1,
                'rankHistory' => [
                    ['dateRange' => '1', 'dateRangeLabel' => '昨日', 'rankType' => 'P_RZ', 'rankTypeLabel' => '入住榜', 'rank' => 1],
                ],
            ],
        ], ['target_poi_id' => 'SELF']]);
        $self = array_values(array_filter($rows, static fn($row): bool => ($row['poiId'] ?? '') === 'SELF'))[0];
        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, []]);

        self::assertSame('掉出前10', $self['rankTrendText']);
        self::assertSame('距前一名 4', $self['gapToPrevText']);
        self::assertSame('近30天最好第 7 / 最差第 14', $self['rank30RangeText']);
        self::assertStringContainsString('距TOP1 4', $self['rankGapSummaryText']);
        self::assertSame(1, $summary['metrics']['vipTaggedCount']);
        self::assertSame('查转化 +2', $summary['metrics']['funnelDiagnosisValue']);
        self::assertSame(3, $summary['metrics']['funnelDiagnosisIssueCount']);
        self::assertSame('第 11 名（本次返回2家）', $summary['metrics']['selfPositionText']);
        $insightsByKey = [];
        foreach ($summary['rank_insights'] as $card) {
            $insightsByKey[$card['key']] = $card;
        }
        self::assertSame('rank_health', str_replace('-', '_', $summary['rank_insights'][0]['key']));
        self::assertSame('距前一名 4', $insightsByKey['rank-gap']['value']);
        self::assertSame('查转化 +2', $insightsByKey['funnel-diagnosis']['value']);
        self::assertStringContainsString('转化低', $insightsByKey['funnel-diagnosis']['note']);
        self::assertSame('非VIP超过本店', $insightsByKey['tag-metric-link']['value']);
        self::assertSame('P_RZ', $summary['rank_health_rows'][0]['key']);
        self::assertSame('已返回', $summary['rank_health_rows'][0]['statusText']);
        self::assertSame('Top Hotel', $summary['top_summary_rows'][0]['hotelName']);
    }

    public function testBackendBuildsCompetitorSummaryFromStoredMeituanRows(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [[
            [
                'system_hotel_id' => 100,
                'hotel_id' => 'TOP',
                'hotel_name' => 'Top Hotel',
                'data_date' => '2026-06-06',
                'data_value' => 15,
                'quantity' => 15,
                'amount' => 0,
                'dimension' => 'room nights',
                'raw_data' => json_encode([
                    'poiName' => 'Top Hotel',
                    'dataValue' => 15,
                    'rankType' => 'P_RZ',
                    'rank' => 1,
                    'dateRange' => '1',
                    'dimension' => 'room nights',
                    'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                    'platformTags' => [],
                    'platformTagStatus' => 'returned_empty',
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'system_hotel_id' => 100,
                'hotel_id' => 'SELF',
                'hotel_name' => 'Self Hotel',
                'data_date' => '2026-06-06',
                'data_value' => 10,
                'quantity' => 10,
                'amount' => 0,
                'dimension' => 'room nights',
                'raw_data' => json_encode([
                    'poiName' => 'Self Hotel',
                    'dataValue' => 10,
                    'rankType' => 'P_RZ',
                    'rank' => 2,
                    'dateRange' => '1',
                    'dimension' => 'room nights',
                    'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                    'platformTags' => ['VIP'],
                    'platformTagStatus' => 'returned',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ], [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
        ]]);

        self::assertSame('success', $payload['status']);
        self::assertSame('success', $payload['data_status']);
        self::assertSame(2, $payload['display_hotel_count']);
        self::assertSame('2026-06-06', $payload['latest_data_date']);
        self::assertSame('Top Hotel', $payload['top_summary_rows'][0]['hotelName']);

        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertTrue($rowsByPoi['SELF']['isSelf']);
        self::assertTrue($rowsByPoi['SELF']['hasVipTag']);
        self::assertSame(['VIP'], $rowsByPoi['SELF']['platformTags']);
        self::assertGreaterThan(0, $rowsByPoi['SELF']['gapToPrev']);
        self::assertNotEmpty($payload['rank_insights']);
        self::assertNotEmpty($payload['rank_health_rows']);
    }

    public function testStoredMeituanSummaryInfersRankTypesAndKeepsFullDateSliceReliable(): void
    {
        $controller = $this->controller();
        $rows = [];
        foreach ([
            ['dimension' => '入住间夜榜', 'top_value' => 15, 'self_value' => 10, 'column' => 'quantity'],
            ['dimension' => '销售额榜', 'top_value' => 3000, 'self_value' => 2000, 'column' => 'amount'],
            ['dimension' => '曝光榜', 'top_value' => 1000, 'self_value' => 700, 'column' => 'data_value'],
            ['dimension' => '支付转化榜', 'top_value' => 0.12, 'self_value' => 0.08, 'column' => 'data_value'],
        ] as $item) {
            foreach ([
                ['poi' => 'TOP', 'name' => 'Top Hotel', 'value' => $item['top_value'], 'rank' => 1],
                ['poi' => 'SELF', 'name' => 'Self Hotel', 'value' => $item['self_value'], 'rank' => 2],
            ] as $hotel) {
                $row = [
                    'system_hotel_id' => 100,
                    'hotel_id' => $hotel['poi'],
                    'hotel_name' => $hotel['name'],
                    'data_date' => '2026-06-06',
                    'data_value' => 0,
                    'quantity' => 0,
                    'amount' => 0,
                    'dimension' => $item['dimension'],
                    'raw_data' => json_encode([
                        'poiName' => $hotel['name'],
                        'dataValue' => $hotel['value'],
                        'rank' => $hotel['rank'],
                        'dimension' => $item['dimension'],
                        'platformTagStatus' => 'returned_empty',
                    ], JSON_UNESCAPED_UNICODE),
                ];
                $row[$item['column']] = $hotel['value'];
                $rows[] = $row;
            }
        }

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [$rows, [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
        ]]);

        self::assertSame('ok', $payload['readiness']['status']);
        self::assertSame(2, $payload['display_hotel_count']);
        self::assertSame('Top Hotel', $payload['top_summary_rows'][0]['hotelName']);
        self::assertSame(['P_RZ', 'P_XS', 'P_LL', 'P_ZH'], array_column($payload['rank_health_rows'], 'key'));
        self::assertSame(['ok', 'ok', 'ok', 'ok'], array_column($payload['rank_health_rows'], 'status'));
        self::assertSame('returned_empty', $payload['display_summary']['platform_tag_summary']['status']);
        self::assertSame(0, $payload['display_summary']['platform_tag_summary']['vip_count']);
    }

    public function testStoredMeituanSummaryDerivesPercentOnlyRowsFromSelfMetrics(): void
    {
        $controller = $this->controller();

        $rows = [];
        foreach ([
            ['poi' => 'SELF', 'name' => 'Self Hotel', 'percent' => 100, 'rank' => 1],
            ['poi' => 'RIVAL', 'name' => 'Rival Hotel', 'percent' => 80, 'rank' => 2],
        ] as $hotel) {
            $rows[] = [
                'system_hotel_id' => 100,
                'hotel_id' => $hotel['poi'],
                'hotel_name' => $hotel['name'],
                'data_date' => '2026-06-07',
                'data_value' => 0,
                'quantity' => 0,
                'amount' => 0,
                'dimension' => '销售间夜榜',
                'raw_data' => json_encode([
                    'poiName' => $hotel['name'],
                    'dataValue' => null,
                    'percent' => $hotel['percent'],
                    'metricStatus' => 'platform_percent_only',
                    'rank' => $hotel['rank'],
                    'dimension' => '销售间夜榜',
                    'aiMetricName' => 'P_XS_NIGHT_COUNT',
                    'platformTagStatus' => 'returned_empty',
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [$rows, [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
            'self_metric_values' => ['salesRoomNights' => 25],
        ]]);
        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(25.0, $rowsByPoi['SELF']['salesRoomNights']);
        self::assertSame(20.0, $rowsByPoi['RIVAL']['salesRoomNights']);
        self::assertSame(80.0, $rowsByPoi['RIVAL']['metricRankPercent']['salesRoomNights']);
        self::assertSame('按本店值和美团百分比推导', $rowsByPoi['RIVAL']['metricSourceStatus']['salesRoomNights']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['salesRoomNights']['method']);
        self::assertSame(1, $payload['display_summary']['metrics']['derivedMetricCount']);
        self::assertStringContainsString('推导', $payload['source_notice']);
    }

    public function testStoredMeituanSummaryReusesPersistedSelfMetricAnchor(): void
    {
        $controller = $this->controller();
        $rows = [];
        foreach ([
            ['poi' => 'SELF', 'name' => 'Self Hotel', 'percent' => 50, 'rank' => 2],
            ['poi' => 'RIVAL', 'name' => 'Rival Hotel', 'percent' => 100, 'rank' => 1],
        ] as $hotel) {
            $raw = [
                'poiName' => $hotel['name'],
                'dataValue' => null,
                'percent' => $hotel['percent'],
                'metricStatus' => 'platform_percent_only',
                'rankType' => 'P_RZ',
                'rank' => $hotel['rank'],
                'dimension' => '房费收入榜',
                'aiMetricName' => 'P_RZ_ROOM_PAY',
                'platformTagStatus' => 'returned_empty',
            ];
            if ($hotel['poi'] === 'SELF') {
                $raw['selfMetricValues'] = ['roomRevenue' => 1000];
                $raw['selfMetricStatus'] = 'daily_trade_returned';
            }
            $rows[] = [
                'system_hotel_id' => 100,
                'hotel_id' => $hotel['poi'],
                'hotel_name' => $hotel['name'],
                'data_date' => '2026-07-11',
                'data_value' => 0,
                'quantity' => 0,
                'amount' => 0,
                'dimension' => '房费收入榜',
                'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
        }

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [$rows, [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
        ]]);
        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(1000.0, $rowsByPoi['SELF']['roomRevenue']);
        self::assertSame(2000.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('1,000', $rowsByPoi['SELF']['roomRevenueText']);
        self::assertSame('2,000', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame(3000.0, $payload['display_summary']['metrics']['totalRoomRevenue']);
    }

    public function testStoredMeituanSummaryKeepsPercentOnlyRowsAsRankEvidence(): void
    {
        $controller = $this->controller();

        $rows = [];
        foreach ([
            ['poi' => 'TOP', 'name' => 'Top Hotel', 'percent' => 100, 'rank' => 1],
            ['poi' => 'SECOND', 'name' => 'Second Hotel', 'percent' => 66.67, 'rank' => 2],
            ['poi' => 'SELF', 'name' => 'Self Hotel', 'percent' => 5.13, 'rank' => 9],
        ] as $hotel) {
            $rows[] = [
                'system_hotel_id' => 100,
                'hotel_id' => $hotel['poi'],
                'hotel_name' => $hotel['name'],
                'data_date' => '2026-06-08',
                'data_value' => 0,
                'quantity' => 0,
                'amount' => 0,
                'dimension' => '入住间夜榜',
                'raw_data' => json_encode([
                    'poiName' => $hotel['name'],
                    'dataValue' => null,
                    'percent' => $hotel['percent'],
                    'metricStatus' => 'platform_percent_only',
                    'rank' => $hotel['rank'],
                    'dimension' => '入住间夜榜',
                    'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                    'rankType' => '',
                    'platformTagStatus' => 'returned_empty',
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        $payload = $this->invokeNonPublic($controller, 'buildMeituanCompetitorSummaryFromStoredRows', [$rows, [
            'system_hotel_id' => 100,
            'target_poi_id' => 'SELF',
        ]]);
        $rowsByPoi = [];
        foreach ($payload['display_hotels'] as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame(0.0, $rowsByPoi['TOP']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['SECOND']['roomNights']);
        self::assertSame(0.0, $rowsByPoi['SELF']['roomNights']);
        self::assertArrayNotHasKey('roomNights', $rowsByPoi['SELF']['metricDerived']);
        self::assertSame(0.0, $payload['display_summary']['metrics']['totalRoomNights']);
        self::assertStringContainsString('未返回可展示数值', $payload['source_notice']);
        self::assertStringNotContainsString('最小一致整数比例尺', $payload['source_notice']);
    }

    public function testCtripTrafficDateRangeCoversPresetsCustomAndInvalidInput(): void
    {
        $controller = $this->controller();

        $lastSevenDays = $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', ['last_7_days', '', '']);
        self::assertCount(2, $lastSevenDays);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $lastSevenDays[0]);

        self::assertSame(
            ['2026-05-01', '2026-05-03'],
            $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', ['custom', '2026-05-01', '2026-05-03'])
        );

        $this->expectException(InvalidArgumentException::class);
        $this->invokeNonPublic($controller, 'buildCtripTrafficDateRange', ['custom', '2026-05-04', '2026-05-03']);
    }

    /**
     * 覆盖 extractCtripTrafficRows/isAllowedOtaRequestUrl：
     * 验证流量列表路径兼容、非数组边界、安全域名校验。
     */
    public function testCtripTrafficRowsAndAllowedUrlValidation(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'extractCtripTrafficRows', [[
            'result' => ['list' => [['date' => '2026-05-01', 'hotelId' => 1]]],
        ]]);
        self::assertSame(1, $rows[0]['hotelId']);
        self::assertSame([], $this->invokeNonPublic($controller, 'extractCtripTrafficRows', ['bad-response']));

        $suffixes = ['ctrip.com', 'meituan.com'];
        self::assertTrue($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ebooking.ctrip.com/api', $suffixes]));
        self::assertTrue($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ctrip.com/api', $suffixes]));
        self::assertTrue($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://bbk.ctripbiz.cn/api', ['ctripbiz.cn']]));
        self::assertFalse($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['http://ebooking.ctrip.com/api', $suffixes]));
        self::assertFalse($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ctrip.com.evil.test/api', $suffixes]));
        self::assertFalse($this->invokeNonPublic($controller, 'isAllowedOtaRequestUrl', ['https://ctripbiz.cn.evil.test/api', ['ctripbiz.cn']]));
    }

    public function testBackendBuildsCtripTrafficDisplayRowsAndSummaryForFrontend(): void
    {
        $rows = CtripTrafficDisplayService::buildCtripTrafficDisplayRows([
            ['dataDate' => '2026-05-18', 'hotelId' => 88, 'listExposure' => 1000, 'detailExposure' => 200, 'orderFillingNum' => 20, 'orderSubmitNum' => 5],
            ['dataDate' => '2026-05-18', 'hotelId' => -1, 'listExposure' => 800, 'detailExposure' => 160, 'orderFillingNum' => 16, 'orderSubmitNum' => 4],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('self', $rows[0]['compareType']);
        self::assertSame('competitor_avg', $rows[1]['compareType']);
        self::assertSame(20.0, $rows[0]['flowRate']);
        self::assertSame(25.0, $rows[0]['submitRate']);

        $summary = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($rows);
        self::assertSame(1000.0, $summary['self']['listExposure']);
        self::assertSame(800.0, $summary['avg']['listExposure']);
        self::assertSame(20.0, $summary['self']['flowRate']);
        self::assertSame(25.0, $summary['avg']['submitRate']);
    }

    public function testCtripFlowPageTrafficAliasesAndRankRowsAreExtracted(): void
    {
        $controller = $this->controller();

        $response = [
            'data' => [
                'categoryRankList' => [[
                    'statDate' => '2026-05-18',
                    'nodeId' => 1685042,
                    'PV' => '1234',
                    'UV' => '456',
                    'clickCount' => '78',
                    'orderCount' => '9',
                    'conversionRate' => '12.5%',
                    'competitionRank' => 3,
                    'categoryRank' => 5,
                    'rankJson' => ['category' => 5, 'competition' => 3],
                ]],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'extractCtripTrafficRows', [$response]);
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]['categoryRank']);

        $normalized = CtripTrafficDisplayService::normalizeAppTrafficRow($rows[0]);
        self::assertSame('2026-05-18', $normalized['date']);
        self::assertSame(1234.0, $normalized['metrics']['exposure']);
        self::assertSame(456.0, $normalized['metrics']['detail_visitors']);
        self::assertSame(78.0, $normalized['metrics']['order_visitors']);
        self::assertSame(9.0, $normalized['metrics']['submit_users']);
        self::assertSame(12.5, $normalized['metrics']['exposure_rate']);

        $captured = $this->invokeNonPublic($controller, 'extractCtripCapturedSection', [[
            'responses' => [[
                'url' => 'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/getStatData',
                'data' => [
                    'data' => [
                        'rankList' => [[
                            'date' => '2026-05-18',
                            'nodeId' => 1685042,
                            'competitionRank' => 2,
                            'categoryRank' => 4,
                            'rankJson' => ['category' => 4, 'competition' => 2],
                        ]],
                    ],
                ],
            ]],
        ], 'traffic']);

        self::assertCount(1, $captured);
        self::assertSame(4, $captured[0]['categoryRank']);
        self::assertSame(['category' => 4, 'competition' => 2], $captured[0]['rankJson']);
    }

    /**
     * 覆盖 mergeOnlineDataHotelList/onlineDataHotelKey/sanitizeSecretConfig/maskSecretValue：
     * 验证系统酒店优先合并、OTA ID 兜底、敏感字段脱敏。
     */
    public function testHotelListMergeAndSecretSanitization(): void
    {
        $controller = $this->controller();

        $merged = $this->invokeNonPublic($controller, 'mergeOnlineDataHotelList', [[
            ['system_hotel_id' => 7, 'hotel_id' => 'ota-a', 'hotel_name' => ''],
            ['system_hotel_id' => '7', 'hotel_id' => 'ota-b', 'hotel_name' => 'Hotel A'],
            ['hotel_id' => 'external-1', 'hotel_name' => 'External'],
            ['hotel_name' => 'Missing key'],
        ]]);

        self::assertCount(2, $merged);
        self::assertSame(7, $merged[0]['id']);
        self::assertSame('Hotel A', $merged[0]['hotel_name']);
        self::assertSame('ota-a', $merged[0]['ota_hotel_id']);
        self::assertSame('external-1', $merged[1]['id']);

        $sanitized = $this->invokeNonPublic($controller, 'sanitizeSecretConfig', [[
            'name' => 'config-a',
            'cookies' => 'abcdefghijk',
            'token' => '12345678',
            'spidertoken' => '',
        ]]);

        self::assertArrayNotHasKey('cookies', $sanitized);
        self::assertArrayNotHasKey('token', $sanitized);
        self::assertTrue($sanitized['has_cookies']);
        self::assertSame('********', $sanitized['secret_mask']);
        self::assertArrayNotHasKey('cookies_preview', $sanitized);
        self::assertArrayNotHasKey('token_preview', $sanitized);
        self::assertArrayNotHasKey('has_spidertoken', $sanitized);
    }

    public function testAutoFetchConfigTaskPlanUsesVaultLocatorsOnly(): void
    {
        $controller = $this->controller();

        $tasks = $this->invokeNonPublic($controller, 'buildAutoFetchConfigTaskPlan', [
            7,
            '2026-05-18',
            [
                'id' => 'ctrip-7',
                'config_id' => 'ctrip-7',
                'system_hotel_id' => 7,
                'credential_status' => 'ready',
                'has_cookies' => true,
                'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                'node_id' => 'node-7',
            ],
            [
                'id' => 'meituan-7',
                'config_id' => 'meituan-7',
                'system_hotel_id' => 7,
                'credential_status' => 'ready',
                'has_cookies' => true,
                'url' => 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
                'partner_id' => 'partner-7',
                'poi_id' => 'poi-7',
            ],
        ]);

        $labels = array_column($tasks, 'label');
        self::assertContains('ctrip-business', $labels);
        self::assertContains('meituan-P_RZ', $labels);
        self::assertContains('meituan-P_XS', $labels);
        self::assertContains('meituan-P_ZH', $labels);
        self::assertContains('meituan-P_LL', $labels);
        self::assertNotContains('ctrip-traffic', $labels);
        self::assertNotContains('ctrip-comments', $labels);
        self::assertNotContains('meituan-traffic', $labels);
        self::assertNotContains('meituan-comments', $labels);
        self::assertNotContains('comments', array_column($tasks, 'module'));

        foreach ($tasks as $task) {
            self::assertSame(7, $task['body']['system_hotel_id']);
            self::assertTrue($task['body']['auto_save']);
            self::assertSame('2026-05-18', $task['body']['start_date']);
            self::assertSame('2026-05-18', $task['body']['end_date']);
            self::assertArrayHasKey('config_id', $task['body']);
            foreach (['cookies', 'cookie', 'auth_data', 'authorization', 'token', 'headers'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $task['body']);
            }
        }

        $rankTask = $tasks[array_search('meituan-P_RZ', $labels, true)];
        self::assertSame('P_RZ', $rankTask['body']['rank_type']);
    }

    public function testAutoFetchTaskRejectsCtripCookieApiWithoutCredentialLocator(): void
    {
        $controller = $this->controller();

        $result = $this->invokeNonPublic($controller, 'executeAutoFetchTask', [[
            'platform' => 'ctrip',
            'module' => 'cookie_api',
            'label' => 'ctrip-cookie-api',
            'strategy' => 'cookie_api',
            'body' => [
                'profile_id' => 'store-7',
                'system_hotel_id' => 7,
                'auto_save' => true,
            ],
        ], 7, '2026-05-03']);

        self::assertSame('ctrip-cookie-api', $result['module']);
        self::assertSame('cookie_api', $result['strategy']);
        self::assertFalse($result['success']);
        self::assertArrayNotHasKey('skipped', $result);
        self::assertSame('failed', $result['status_code']);
        self::assertSame('credential_execution_failed', $result['message']);
    }

    public function testCookieHealthMessagesAreActionableChinesePrompts(): void
    {
        $controller = $this->controller();

        self::assertSame('携程 Cookie状态正常。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['ctrip', 'ok', 0]));
        self::assertSame('美团 Cookie为空，请重新登录OTA后台后更新授权。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['meituan', 'empty', null]));
        self::assertSame('OTA Cookie缺少更新时间，请重新保存一次配置以便系统判断有效期。', $this->invokeNonPublic($controller, 'cookieHealthMessage', ['generic', 'unknown', null]));
        self::assertSame('/online-data?tab=cookies', $this->invokeNonPublic($controller, 'cookieReauthorizeEntry', []));
    }

    public function testCookieHealthStateClassifiesEmptyUnknownWarningExpiredAndAlerted(): void
    {
        $controller = $this->controller();

        self::assertSame('expired', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['', null, false, 5, 14]));
        self::assertSame('unknown', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', null, false, 5, 14]));
        self::assertSame('ok', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', 4, false, 5, 14]));
        self::assertSame('warning', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', 5, false, 5, 14]));
        self::assertSame('expired', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', 14, false, 5, 14]));
        self::assertSame('expired', $this->invokeNonPublic($controller, 'resolveCookieHealthState', ['cookie=value', 1, true, 5, 14]));
    }

    public function testCtripCookieHealthExposesTrafficLightAndCrudMetadata(): void
    {
        $controller = $this->controller();

        $ok = $this->invokeNonPublic($controller, 'cookieHealthPresentationMeta', ['ctrip', 'ok', 'ctrip_7']);

        self::assertSame('ctrip_7', $ok['config_id']);
        self::assertSame('ctrip_config', $ok['config_source']);
        self::assertTrue($ok['editable']);
        self::assertTrue($ok['deletable']);
        self::assertTrue($ok['is_usable']);
        self::assertSame('green', $ok['light_status']);
        self::assertSame('可用', $ok['light_label']);
        self::assertSame('可继续使用', $ok['action_hint']);

        $expired = $this->invokeNonPublic($controller, 'cookieHealthPresentationMeta', ['ctrip', 'expired', 'ctrip_old']);

        self::assertFalse($expired['is_usable']);
        self::assertSame('red', $expired['light_status']);
        self::assertSame('不可用', $expired['light_label']);
        self::assertStringContainsString('建议删除', $expired['action_hint']);
    }

    public function testCollectionAuthorizationRowsFilterGlobalAndSelectedHotelHistory(): void
    {
        $controller = $this->controller();
        $rows = [
            ['hotel_id' => 0, 'status' => 'ok'],
            ['hotel_id' => 7, 'status' => 'warning'],
            ['hotel_id' => 8, 'status' => 'expired'],
        ];

        $filtered = $this->invokeNonPublic($controller, 'filterCollectionAuthorizationRows', [$rows, 7]);
        $summary = $this->invokeNonPublic($controller, 'buildCollectionAuthorizationSummary', [$filtered]);

        self::assertSame([0, 7], array_column($filtered, 'hotel_id'));
        self::assertSame('warning', $summary['overall_status']);
        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['ok']);
        self::assertSame(1, $summary['warning']);
        self::assertSame(0, $summary['expired']);
    }

    public function testAuthorizationHealthUsesOneCurrentCredentialAndIgnoresRevokedHistory(): void
    {
        $controller = $this->controller();
        $rows = [
            ['id' => 1, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'config_id' => 'old', 'credential_status' => 'revoked', 'rotated_at' => '2026-07-12 15:00:00'],
            ['id' => 2, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'config_id' => 'current', 'credential_status' => 'ready', 'rotated_at' => '2026-07-11 12:00:00'],
            ['id' => 3, 'system_hotel_id' => 7, 'platform' => 'meituan', 'config_id' => 'mt', 'credential_status' => 'ready', 'rotated_at' => '2026-07-10 12:00:00'],
        ];

        $selected = $this->invokeNonPublic($controller, 'selectCurrentCredentialHealthItems', [$rows]);

        self::assertCount(2, $selected);
        $byPlatform = array_column($selected, null, 'platform');
        self::assertSame('current', $byPlatform['ctrip']['config_id']);
        self::assertSame('ready', $byPlatform['ctrip']['credential_status']);
        self::assertSame('mt', $byPlatform['meituan']['config_id']);
    }

    public function testCollectionReliabilityUsesUnifiedStatusVocabulary(): void
    {
        $controller = $this->controller();

        $catalog = $this->invokeNonPublic($controller, 'collectionReliabilityStatusCatalog');
        self::assertSame([
            'ok',
            'warning',
            'expired',
            'unknown',
            'waiting_config',
            'failed',
            'partial_success',
            'success',
            'not_collected',
        ], $catalog);

        $emptySummary = $this->invokeNonPublic($controller, 'buildCollectionAuthorizationSummary', [[]]);
        self::assertSame('waiting_config', $emptySummary['overall_status']);

        $expiredSummary = $this->invokeNonPublic($controller, 'buildCollectionAuthorizationSummary', [[
            ['hotel_id' => 7, 'status' => 'expired'],
        ]]);
        self::assertSame('expired', $expiredSummary['overall_status']);

        $notCollectedSummary = $this->invokeNonPublic($controller, 'buildCollectionAuthorizationSummary', [[
            ['hotel_id' => 7, 'status' => 'not_collected'],
        ]]);
        self::assertSame('not_collected', $notCollectedSummary['overall_status']);
        self::assertSame(1, $notCollectedSummary['not_collected']);
    }

    public function testDashboardMetricValueStateDistinguishesZeroNullMissingAndFailureStates(): void
    {
        $controller = $this->controller();

        $zero = $this->invokeNonPublic($controller, 'buildDashboardMetricValue', [['amount' => 0], 'amount', '营业额']);
        self::assertSame('zero', $zero['state']);
        self::assertSame(0, $zero['value']);
        self::assertSame('0', $zero['display_value']);

        $null = $this->invokeNonPublic($controller, 'buildDashboardMetricValue', [['amount' => null], 'amount', '营业额']);
        self::assertSame('null', $null['state']);
        self::assertNull($null['value']);

        $missing = $this->invokeNonPublic($controller, 'buildDashboardMetricValue', [[], 'amount', '营业额']);
        self::assertSame('field_missing', $missing['state']);
        self::assertNull($missing['value']);

        $notCollected = $this->invokeNonPublic($controller, 'buildDashboardMetricValue', [['__collection_status' => 'not_collected'], 'amount', '营业额']);
        self::assertSame('not_collected', $notCollected['state']);

        $authFailed = $this->invokeNonPublic($controller, 'buildDashboardMetricValue', [['__collection_status' => 'auth_failed'], 'amount', '营业额']);
        self::assertSame('auth_failed', $authFailed['state']);

        $requestFailed = $this->invokeNonPublic($controller, 'buildDashboardMetricValue', [['__collection_status' => 'request_failed'], 'amount', '营业额']);
        self::assertSame('request_failed', $requestFailed['state']);
    }

    public function testDashboardDiagnosisAlwaysContainsProblemEvidenceImpactAndAction(): void
    {
        $controller = $this->controller();

        $diagnosis = $this->invokeNonPublic($controller, 'buildDashboardDiagnosis', [
            '授权失败',
            ['platform' => 'ctrip', 'status' => 'expired'],
            '该门店无法同步 OTA 数据',
            '重新登录或更新携程 Cookie/API 辅助内容',
            'auth_failed',
        ]);

        self::assertSame(['problem', 'evidence', 'impact', 'action', 'status', 'severity'], array_keys($diagnosis));
        self::assertSame('授权失败', $diagnosis['problem']);
        self::assertSame('expired', $diagnosis['evidence']['status']);
        self::assertSame('该门店无法同步 OTA 数据', $diagnosis['impact']);
        self::assertSame('重新登录或更新携程 Cookie/API 辅助内容', $diagnosis['action']);
        self::assertSame('auth_failed', $diagnosis['status']);
    }

    public function testDashboardAccountOverviewMapsCollectionReliabilityIntoCockpitStructure(): void
    {
        $controller = $this->controller();
        $reliability = [
            'period' => ['start_date' => '2026-05-03', 'end_date' => '2026-06-01', 'days' => 30],
            'authorization' => [
                'summary' => ['overall_status' => 'expired', 'total' => 2, 'ok' => 1, 'expired' => 1],
                'list' => [],
            ],
            'collection_logs' => [
                ['hotel_id' => 1, 'platform' => 'ctrip', 'status' => 'success', 'run_time' => '2026-06-01 09:00:00'],
                ['hotel_id' => 2, 'platform' => 'ctrip', 'status' => 'failed', 'message' => 'request 500'],
            ],
            'data_quality' => [
                'status' => 'warning',
                'checked_records' => 2,
                'issue_records' => 1,
                'score' => 72,
                'missing_count' => 1,
                'abnormal_count' => 0,
                'top_prompts' => ['缺失营业额'],
            ],
            'failure_reasons' => [],
            'pending_actions' => [
                ['type' => 'collection', 'status' => 'failed', 'platform' => 'ctrip', 'reason' => 'request 500', 'action' => '重试采集'],
            ],
            'ctrip_latest_capture' => [
                'captured_at' => '2026-06-01 08:30:00',
                'module_count' => 3,
                'standard_row_count' => 1,
                'missing_field_count' => 4,
            ],
        ];
        $hotels = [
            ['id' => 1, 'name' => 'A Hotel'],
            ['id' => 2, 'name' => 'B Hotel'],
        ];
        $qualityRows = [
            ['system_hotel_id' => 1, 'hotel_name' => 'A Hotel', 'source' => 'ctrip', 'data_type' => 'business', 'amount' => 0, 'quantity' => 2, 'book_order_num' => 1],
            ['system_hotel_id' => 2, 'hotel_name' => 'B Hotel', 'source' => 'ctrip', 'data_type' => 'business', 'quantity' => 1, 'book_order_num' => 1],
        ];

        $overview = $this->invokeNonPublic($controller, 'buildDashboardAccountOverview', [$reliability, $hotels, $qualityRows]);

        self::assertSame(2, $overview['summary']['hotel_count']);
        self::assertSame(1, $overview['summary']['portrait_completed_count']);
        self::assertSame(1, $overview['summary']['abnormal_hotel_count']);
        self::assertSame('auth_failed', $overview['summary']['sync_status']);
        self::assertSame('zero', $overview['core_kpis'][0]['state']);
        self::assertNotEmpty($overview['risk_alerts']);
        self::assertNotEmpty($overview['today_actions']);
        foreach ($overview['diagnostics'] as $diagnosis) {
            self::assertArrayHasKey('problem', $diagnosis);
            self::assertArrayHasKey('evidence', $diagnosis);
            self::assertArrayHasKey('impact', $diagnosis);
            self::assertArrayHasKey('action', $diagnosis);
        }
    }

    public function testCollectionPendingActionsExposeNoDataNextActionForEmployeeConsole(): void
    {
        $controller = $this->controller();

        $actions = $this->invokeNonPublic($controller, 'buildCollectionPendingActions', [[], [], [], []]);

        self::assertNotEmpty($actions);
        self::assertSame('collection_gap', $actions[0]['type']);
        self::assertSame('not_collected', $actions[0]['status']);
        self::assertSame('ota_same_period_source_rows_missing', $actions[0]['action_code']);
        self::assertStringContainsString('浏览器 Profile 采集入口', $actions[0]['action']);
        self::assertStringContainsString('手动 Cookie/API 仅作临时补数或排障', $actions[0]['action']);
        self::assertSame($actions[0]['action'], $actions[0]['next_action']);
        self::assertContains('online_daily_data 同日期源数据行', $actions[0]['evidence_needed']);
        self::assertStringContainsString('不改变采集字段', $actions[0]['protected_boundary']);
    }

    public function testPhase1EmployeeQuestionsStayIncompleteWithoutOtaEvidence(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'light',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [],
            'history_replay' => [],
            'data_quality' => ['status' => 'not_loaded', 'checked_records' => 0, 'missing_count' => 0],
            'field_definitions' => [
                ['source' => 'ctrip', 'module' => 'business', 'fields' => [['field' => 'amount']]],
            ],
            'pending_actions' => [],
            'failure_reasons' => [],
        ]]);

        $questions = $payload['phase1_employee_questions'];
        self::assertSame('ota_channel', $questions['scope']['metric_scope']);
        self::assertSame('read_existing_collection_reliability_only', $questions['source_policy']);
        self::assertSame('incomplete', $questions['summary']['status']);
        self::assertSame($questions['summary'], $questions['closure_summary']);
        self::assertSame('read_existing_phase1_employee_question_rows_only', $questions['closure_summary']['source_policy']);
        self::assertSame('phase1_confirm_source_date_evidence', $questions['closure_summary']['top_action_code']);
        self::assertSame('/api/online-data/collection-reliability', $questions['closure_summary']['top_action_entry']);
        self::assertContains('today_ota_collected', $questions['closure_summary']['missing_question_keys']);
        self::assertSame('latest_available_and_history_rows_are_reference_only_not_target_date_proof', $questions['closure_summary']['reference_policy']);
        self::assertCount(6, $questions['rows']);
        self::assertSame('today_ota_collected', $questions['rows'][0]['key']);
        self::assertSame('not_proved', $questions['rows'][0]['status']);
        self::assertSame('missing', $questions['rows'][0]['evidence']['target_date_platform_coverage']['status']);
        self::assertTrue($questions['rows'][0]['evidence']['target_date_platform_coverage']['source_date_evidence_missing']);
        self::assertSame('warning', $questions['rows'][4]['status']);
        self::assertSame('missing', $questions['rows'][5]['status']);
        self::assertGreaterThanOrEqual(3, $questions['summary']['next_action_count']);
        self::assertContains('phase1_confirm_source_date_evidence', array_column($questions['next_required_actions'], 'action_code'));
        self::assertContains('phase1_collect_ai_diagnosis_evidence', array_column($questions['next_required_actions'], 'action_code'));
    }

    public function testSourceDateEvidenceSummaryKeepsRawProofAndFieldTrustExplicit(): void
    {
        $controller = $this->controller();
        $raw = [
            'source_trace_id' => 'ctrip:test-trace',
            'field_facts' => [
                [
                    'metric_key' => 'list_exposure',
                    'source_path' => 'data.rows[0].listExposure',
                    'storage_table' => 'online_daily_data',
                    'storage_field' => 'online_daily_data.list_exposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'capture_evidence' => [
                        'source_trace_id' => 'ctrip:test-trace',
                        'source_url_hash' => 'hash-list',
                    ],
                ],
                [
                    'metric_key' => 'detail_exposure',
                    'source_path' => '',
                    'storage_table' => 'online_daily_data',
                    'storage_field' => 'online_daily_data.detail_exposure',
                    'status' => 'missing',
                    'missing_state' => 'field_missing',
                    'stored_value_present' => false,
                ],
            ],
        ];

        $summary = $this->invokeNonPublic($controller, 'summarizeCollectionTargetDateEvidenceRows', [[
            [
                'id' => 10,
                'source' => 'ctrip',
                'data_date' => '2026-06-12',
                'data_type' => 'traffic',
                'source_trace_id' => 'ctrip:test-trace',
                'list_exposure' => 120,
                'detail_exposure' => null,
                'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ]]);

        self::assertSame('partial', $summary['credibility_status']);
        self::assertSame(1, $summary['target_date_rows_sampled']);
        self::assertSame(1, $summary['raw_data_present_count']);
        self::assertSame(false, $summary['raw_data_exposed']);
        self::assertSame(['ctrip:test-trace'], $summary['source_trace_id_samples']);
        self::assertSame(1, $summary['source_trace_id_present_count']);
        self::assertSame(1, $summary['source_path_count']);
        self::assertSame(1, $summary['structured_source_path_count']);
        self::assertSame(['list_exposure'], $summary['trusted_metric_keys']);
        self::assertSame(['detail_exposure'], $summary['missing_metric_keys']);
        self::assertSame('list_exposure', $summary['field_mapping_samples'][0]['metric_key']);
        self::assertSame('data.rows[0].listExposure', $summary['field_mapping_samples'][0]['source_path']);
        self::assertTrue($summary['field_mapping_samples'][0]['source_trace_id_present']);
        self::assertTrue($summary['field_mapping_samples'][0]['source_url_hash_present']);
        self::assertStringNotContainsString('raw_data', json_encode($summary['field_mapping_samples'], JSON_UNESCAPED_UNICODE));
    }

    public function testTrafficSourceIssueCodeClassifiesCaptureEntryBlockersWithoutRawErrorExposure(): void
    {
        $controller = $this->controller();

        $profileMissing = $this->invokeNonPublic($controller, 'phase1TrafficSourceIssueCode', [[
            'enabled' => 1,
            'status' => 'waiting_config',
            'last_sync_status' => 'waiting_config',
            'last_error' => 'Ctrip browser Profile is not prepared: storage/ctrip_profile_system_60',
            'ingestion_method' => 'browser_profile',
        ], [
            'profile_id' => 'system_60',
            'hotel_id' => 'redacted',
        ]]);
        $loginMissing = $this->invokeNonPublic($controller, 'phase1TrafficSourceIssueCode', [[
            'enabled' => 1,
            'status' => 'waiting_config',
            'last_sync_status' => 'waiting_config',
            'last_error' => 'Meituan login session is not ready. Re-login with a visible browser Profile before scheduled sync.',
            'ingestion_method' => 'browser_profile',
        ], [
            'store_id' => 'redacted',
        ]]);
        $dependencyMissing = $this->invokeNonPublic($controller, 'phase1TrafficSourceIssueCode', [[
            'enabled' => 1,
            'status' => 'failed',
            'last_sync_status' => 'failed',
            'last_error' => "Cannot find package 'cloakbrowser' imported from D:\\project\\scripts\\lib\\cloakbrowser_launcher.mjs",
            'ingestion_method' => 'browser_profile',
        ], []]);
        $historicalLoginWithStaleError = $this->invokeNonPublic($controller, 'phase1TrafficSourceIssueCode', [[
            'enabled' => 1,
            'status' => 'ready',
            'last_sync_status' => 'waiting_config',
            'last_error' => 'Meituan login session is not ready. Re-login with a visible browser Profile before scheduled sync.',
            'ingestion_method' => 'browser_profile',
        ], [
            'store_id' => 'redacted',
            'manual_login_state_verified' => true,
            'login_status' => 'logged_in',
        ]]);

        self::assertSame('profile_not_prepared', $profileMissing);
        self::assertSame('login_session_not_ready', $loginMissing);
        self::assertSame('browser_dependency_missing', $dependencyMissing);
        self::assertSame('login_session_not_ready', $historicalLoginWithStaleError);
    }

    public function testPlatformProfileLoginVerifiedConfigMarksTrafficSourceWithoutSensitiveValues(): void
    {
        $command = $this->profileLoginCommand();

        $config = $this->invokeNonPublic($command, 'buildProfileLoginVerifiedConfig', [[
            'registered_by' => 'p0_ota_field_loop',
            'capture_sections' => 'traffic',
            'profile_id' => 'system_60',
            'hotel_id' => 'ctrip-60',
            'profile_status' => 'expired',
            'auth_status' => ['ok' => false, 'status' => 'login_required'],
        ], 'ctrip', 'system_60', [
            'data_source_id' => 14,
            'capture_sections' => 'traffic',
        ], [
            'auth_status' => [
                'ok' => true,
                'status' => 'logged_in',
                'url' => 'https://ebooking.ctrip.com/path?token=secret-token',
                'message' => 'Ctrip profile is logged in.',
            ],
            'capture_gate' => [
                'status' => 'pass',
                'mode' => 'login_only',
                'failed_check_ids' => [],
                'checks' => [[
                    'id' => 'auth_session',
                    'status' => 'pass',
                    'message' => 'ready',
                    'raw_token' => 'must-not-store',
                ]],
            ],
        ], '2026-06-27 09:00:00']);

        self::assertTrue($config['manual_login_state_verified']);
        self::assertTrue($config['login_state_verified']);
        self::assertTrue($config['profile_login_verified']);
        self::assertSame('logged_in', $config['profile_status']);
        self::assertSame('logged_in', $config['login_status']);
        self::assertSame('2026-06-27 09:00:00', $config['last_login_verified_at']);
        self::assertSame('traffic', $config['capture_sections']);
        self::assertSame('p0_ota_field_loop', $config['registered_by']);
        self::assertSame('ctrip-60', $config['hotel_id']);
        self::assertSame('system_60', $config['stable_profile_id']);
        self::assertSame('system_60', $config['profile_binding_key']);
        self::assertSame('ota_account_store', $config['profile_reuse_scope']);
        self::assertTrue($config['profile_daily_reuse_enabled']);
        self::assertSame('data-sources/:id/sync', $config['profile_daily_reuse_entry']);
        self::assertTrue($config['profile_login_probe_required_before_relogin']);
        self::assertSame(['ok' => true, 'status' => 'logged_in', 'message' => 'Ctrip profile is logged in.'], $config['auth_status']);
        self::assertSame('pass', $config['profile_login_capture_gate']['status']);

        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('must-not-store', $encoded);
        self::assertStringNotContainsString('ebooking.ctrip.com/path', $encoded);
        self::assertArrayNotHasKey('data_type', $config);
        $this->invokeNonPublic($command, 'assertProfileSourceMetadataIsSafe', [$config]);
    }

    public function testPlatformProfileLoginAcceptsMetadataOnlySourceConfig(): void
    {
        $config = $this->invokeNonPublic($this->profileLoginCommand(), 'decodeSafeProfileSourceConfig', [
            json_encode([
                'profile_id' => 'profile-58',
                'capture_sections' => ['traffic', 'orders'],
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        self::assertSame('profile-58', $config['profile_id']);
        self::assertSame(['traffic', 'orders'], $config['capture_sections']);
    }

    public function testPlatformProfileLoginRejectsLegacySecretsInsideSourceConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credential migration is required');
        $this->invokeNonPublic($this->profileLoginCommand(), 'decodeSafeProfileSourceConfig', [
            json_encode([
                'profile_id' => 'profile-58',
                'nested' => ['authorization' => 'Bearer legacy-profile-token'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function testCtripProfileFieldMetadataRejectsCredentialMaterial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('字段元数据不得包含');
        $this->invokeNonPublic($this->controller(), 'normalizeCtripProfileCaptureField', [[
            'id' => 'unsafe-field',
            'field_key' => 'unsafe_field',
            'field_name' => 'Unsafe field',
            'section' => 'traffic_report',
            'source_interface' => 'Authorization: Bearer profile-field-secret',
            'source_keys' => 'metric.value',
            'enabled' => true,
        ]]);
    }

    public function testPlatformProfileLoginTaskRequestUsesMetadataAllowlist(): void
    {
        $prepared = $this->invokeNonPublic($this->controller(), 'preparePlatformProfileLoginRequest', [
            'ctrip',
            [
                'source_id' => 91,
                'profile_id' => 'profile-58',
                'hotel_id' => 'ctrip-hotel-58',
                'hotel_name' => '测试门店',
                'captureSections' => ['traffic', 'business_overview'],
                'syncAfterLogin' => true,
                'targetDate' => '2026-07-09',
                'debug_note' => 'must-not-enter-task-file',
            ],
            58,
            'profile-58',
        ]);

        self::assertSame('ctrip', $prepared['platform']);
        self::assertSame(58, $prepared['system_hotel_id']);
        self::assertSame(91, $prepared['data_source_id']);
        self::assertSame('traffic,business_overview', $prepared['capture_sections']);
        self::assertSame('2026-07-09', $prepared['data_date']);
        self::assertTrue($prepared['sync_after_login']);
        self::assertArrayNotHasKey('debug_note', $prepared);
    }

    public function testPlatformProfileLoginTaskRequestRejectsReusableSecrets(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('只接受元数据');
        $this->invokeNonPublic($this->controller(), 'preparePlatformProfileLoginRequest', [
            'ctrip',
            [
                'profile_id' => 'profile-58',
                'cookies' => 'sid=profile-login-secret',
            ],
            58,
            'profile-58',
        ]);
    }

    public function testPlatformProfileLoginCachesRedactNestedCredentialMaterial(): void
    {
        $payload = [
            'status' => 'failed',
            'auth_status' => [
                'status' => 'login_required',
                'message' => 'Cookie: sid=cache-cookie-secret; session=cache-session-secret',
                'raw_token' => 'cache-raw-token-secret',
            ],
            'capture_gate' => [
                'checks' => [[
                    'id' => 'auth',
                    'message' => 'Authorization: Bearer cache-auth-secret',
                ]],
            ],
        ];

        $controllerSafe = $this->invokeNonPublic($this->controller(), 'sanitizePlatformProfileLoginCachePayload', [$payload]);
        $commandSafe = $this->invokeNonPublic($this->profileLoginCommand(), 'sanitizeProfileLoginCachePayload', [$payload]);
        foreach ([$controllerSafe, $commandSafe] as $safe) {
            $encoded = (string)json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            self::assertSame('login_required', $safe['auth_status']['status']);
            self::assertSame('[redacted]', $safe['auth_status']['raw_token']);
            foreach (['cache-cookie-secret', 'cache-session-secret', 'cache-raw-token-secret', 'cache-auth-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $encoded);
            }
        }
    }

    public function testPlatformProfileProbeKeepsAntiBotAndSessionExpiredStates(): void
    {
        $controller = $this->controller();
        $command = $this->profileLoginCommand();

        $antiBotStatus = $this->invokeNonPublic($controller, 'ctripProfileProbeStatusCode', [[
            'message' => 'captcha required by platform risk control',
        ], [
            'ok' => false,
            'status' => 'captcha_required',
            'message' => 'captcha required by platform risk control',
        ]]);
        $sessionExpiredStatus = $this->invokeNonPublic($controller, 'ctripProfileProbeStatusCode', [[
            'message' => 'session_expired',
        ], [
            'ok' => false,
            'status' => 'session_expired',
        ]]);
        $loginTaskAntiBot = $this->invokeNonPublic($command, 'profileLoginFailureStatusCode', [
            'human verification required',
            ['ok' => false, 'status' => 'human_verification_required'],
            null,
        ]);

        self::assertSame('anti_bot', $antiBotStatus);
        self::assertSame('session_expired', $sessionExpiredStatus);
        self::assertSame('anti_bot', $loginTaskAntiBot);
    }

    public function testPlatformProfileStatusDetectsAntiBotFromSourceLog(): void
    {
        $controller = $this->controller();

        $status = $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            'store-7',
            true,
            [
                'status' => 'failed',
                'last_sync_status' => 'failed',
                'last_error' => 'captcha required by platform risk control',
            ],
            [],
            [],
        ]);

        self::assertSame('anti_bot', $status);
    }

    public function testBrowserProfileBindingDoesNotPersistRequestCookiesAsDataSourceSecret(): void
    {
        $secretAssignment = "\$payloadForSave['secret'] = ['cookies' => \$cookies];";
        $commandSource = (string)file_get_contents(dirname(__DIR__) . '/app/command/PlatformProfileLogin.php');
        $controllerSource = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php');

        self::assertStringNotContainsString($secretAssignment, $commandSource);
        self::assertStringNotContainsString($secretAssignment, $controllerSource);
    }

    public function testPlatformProfileLoginDataSourceStatusClearsOnlyStaleLoginErrors(): void
    {
        $command = $this->profileLoginCommand();

        self::assertSame('ready', $this->invokeNonPublic($command, 'dataSourceStatusAfterProfileLogin', [[
            'status' => 'waiting_config',
            'data_type' => 'traffic',
        ]]));
        self::assertSame('success', $this->invokeNonPublic($command, 'dataSourceStatusAfterProfileLogin', [[
            'status' => 'success',
            'data_type' => 'traffic',
        ]]));
        self::assertTrue($this->invokeNonPublic($command, 'isStaleProfileLoginError', [
            'Meituan login session is not ready. Re-login with a visible browser Profile before scheduled sync.',
        ]));
        self::assertFalse($this->invokeNonPublic($command, 'isStaleProfileLoginError', [
            'Ctrip browser capture completed but no business rows were parsed.',
        ]));
    }

    public function testAvailableProfileWithoutAuthoritativeProofWaitsForLogin(): void
    {
        $controller = $this->controller();

        self::assertSame('waiting_login', $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            'profile-58',
            true,
            ['ingestion_method' => 'browser_profile', 'status' => 'ready'],
            [],
            ['profile_daily_reuse_enabled' => true],
        ]));
    }

    public function testPlatformProfileLoginBuildsTargetDateTrafficSyncOptions(): void
    {
        $command = $this->profileLoginCommand();

        $options = $this->invokeNonPublic($command, 'buildProfileLoginSyncOptions', ['ctrip', [
            'sync_after_login' => true,
            'target_date' => '2026-06-27',
            'capture_sections' => ['traffic', 'orders'],
            'data_period' => 'historical_daily',
            'snapshot_time' => '2026-06-27 10:00:00',
        ]]);
        $compact = $this->invokeNonPublic($command, 'compactProfileLoginSyncResult', [[
            'task_id' => 91,
            'status' => 'success',
            'message' => 'Platform data synchronized.',
            'normalized_count' => 5,
            'saved_count' => 5,
            'payload' => ['token' => 'must-not-copy'],
        ], 14, $options]);

        self::assertTrue($this->invokeNonPublic($command, 'shouldSyncDataSourceAfterProfileLogin', [[
            'sync_after_login' => true,
        ]]));
        self::assertFalse($this->invokeNonPublic($command, 'shouldSyncDataSourceAfterProfileLogin', [[
            'sync_after_login' => false,
        ]]));
        self::assertSame('profile_login_after_login', $options['trigger_type']);
        self::assertSame('2026-06-27', $options['data_date']);
        self::assertSame('traffic,orders', $options['capture_sections']);
        self::assertSame(['traffic', 'orders'], $options['sections']);
        self::assertSame('historical_daily', $options['data_period']);
        self::assertFalse($options['interactive_browser']);
        self::assertSame('success', $compact['status']);
        self::assertSame(14, $compact['data_source_id']);
        self::assertSame(91, $compact['task_id']);
        self::assertSame(5, $compact['saved_count']);
        self::assertFalse($compact['sensitive_values_exposed']);
        self::assertStringNotContainsString('must-not-copy', json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testPlatformProfileLoginRequestResolvesMeituanDataSourceServerSide(): void
    {
        $controller = $this->controller();

        $request = $this->invokeNonPublic($controller, 'buildPlatformProfileLoginRequestFromDataSource', [
            'meituan',
            [
                'data_source_id' => 18,
                'system_hotel_id' => 58,
                'bind_data_source' => true,
            ],
            [
                'id' => 18,
                'platform' => 'meituan',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'waiting_config',
                'system_hotel_id' => 58,
                'data_type' => 'traffic',
                'name' => '天成美团流量源',
                'config' => [
                    'store_id' => 'mt-store-58',
                    'poi_id' => 'mt-poi-58',
                    'partner_id' => 'partner-58',
                    'capture_sections' => ['traffic', 'orders'],
                ],
                'secret_json' => json_encode(['cookies' => 'must-not-merge'], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertSame(18, $request['data_source_id']);
        self::assertSame(58, $request['system_hotel_id']);
        self::assertSame('mt-store-58', $request['store_id']);
        self::assertSame('mt-poi-58', $request['poi_id']);
        self::assertSame('partner-58', $request['partner_id']);
        self::assertSame('traffic,orders', $request['capture_sections']);
        self::assertArrayNotHasKey('secret_json', $request);
        self::assertArrayNotHasKey('cookies', $request);
    }

    public function testPlatformProfileStatusItemExposesMachineReadableBindingContract(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php');

        self::assertStringContainsString('PlatformProfileBindingReadinessService::buildContract(', $source);
        self::assertStringContainsString("'binding_contract' => \$bindingContract", $source);
    }

    public function testPlatformProfileLoginRequestRejectsMismatchedDataSourceScope(): void
    {
        $controller = $this->controller();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('平台 Profile 数据源与当前酒店不匹配');

        $this->invokeNonPublic($controller, 'buildPlatformProfileLoginRequestFromDataSource', [
            'ctrip',
            [
                'data_source_id' => 14,
                'system_hotel_id' => 60,
            ],
            [
                'id' => 14,
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'waiting_config',
                'system_hotel_id' => 58,
                'data_type' => 'traffic',
                'config' => [
                    'profile_id' => 'system_58',
                    'hotel_id' => 'ctrip-58',
                ],
            ],
        ]);
    }

    public function testP0ProfileLoginTriggerActionUsesDataSourceIdWithoutRawPlatformIdentifiers(): void
    {
        $controller = $this->controller();

        $action = $this->invokeNonPublic($controller, 'phase1P0ProfileLoginTriggerAction', [
            'ctrip',
            14,
            58,
            '2026-06-27',
        ]);

        self::assertSame('client_local_authorization_required', $action['status']);
        self::assertSame('CLIENT_OPEN', $action['method']);
        self::assertSame('https://ebooking.ctrip.com/home/mainland', $action['entry']);
        self::assertSame('account_owner_local_computer_only', $action['authorization_policy']);
        self::assertTrue($action['server_browser_launch_disabled']);
        self::assertSame(14, $action['client_authorization_context']['data_source_id']);
        self::assertSame(58, $action['client_authorization_context']['system_hotel_id']);
        self::assertSame('2026-06-27', $action['client_authorization_context']['data_date']);
        self::assertSame('/api/online-data/data-sources/14/sync', $action['after_login_sync']['entry']);
        self::assertFalse($action['sensitive_values_exposed']);

        $encoded = json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('profile_id', $encoded);
        self::assertStringNotContainsString('store_id', $encoded);
        self::assertStringNotContainsString('poi_id', $encoded);
        self::assertStringNotContainsString('cookie', strtolower((string)$encoded));
    }

    public function testP0SyncTaskMessageCodeClassifiesWithoutRawErrorExposure(): void
    {
        $controller = $this->controller();

        $saved = $this->invokeNonPublic($controller, 'phase1P0SyncTaskMessageCode', [[
            'status' => 'success',
            'message' => 'Platform data synchronized.',
        ], [
            'saved_count' => 3,
            'normalized_count' => 3,
            'sync_diagnostics' => ['target_date' => '2026-07-09'],
        ], '2026-07-09']);
        $mismatched = $this->invokeNonPublic($controller, 'phase1P0SyncTaskMessageCode', [[
            'status' => 'success',
            'message' => 'Platform data synchronized.',
        ], [
            'saved_count' => 3,
            'normalized_count' => 3,
            'sync_diagnostics' => ['target_date' => '2026-07-08'],
        ], '2026-07-09']);
        $zeroSaved = $this->invokeNonPublic($controller, 'phase1P0SyncTaskMessageCode', [[
            'status' => 'partial_success',
            'message' => 'Ctrip browser capture completed but no business rows were parsed.',
        ], [
            'saved_count' => 0,
            'normalized_count' => 0,
        ], '2026-07-09']);
        $login = $this->invokeNonPublic($controller, 'phase1P0SyncTaskMessageCode', [[
            'status' => 'waiting_config',
            'message' => 'Ctrip browser Profile is not prepared: storage/ctrip_profile_system_60',
        ], [], '2026-07-09']);
        $dependency = $this->invokeNonPublic($controller, 'phase1P0SyncTaskMessageCode', [[
            'status' => 'failed',
            'message' => "Cannot find package 'cloakbrowser' imported from D:\\project\\capture.mjs",
        ], [], '2026-07-09']);
        $freshRunning = $this->invokeNonPublic($controller, 'phase1P0SyncTaskMessageCode', [[
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 120),
        ], [], '2026-07-09']);
        $staleRunning = $this->invokeNonPublic($controller, 'phase1P0SyncTaskMessageCode', [[
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 7200),
        ], [], '2026-07-09']);

        self::assertSame('sync_reported_saved_rows_requires_target_date_verifier', $saved);
        self::assertSame('sync_task_target_date_mismatch', $mismatched);
        self::assertSame('sync_completed_without_saved_rows', $zeroSaved);
        self::assertSame('login_or_profile_not_ready', $login);
        self::assertSame('browser_dependency_missing', $dependency);
        self::assertSame('sync_running', $freshRunning);
        self::assertSame('stale_running', $staleRunning);

        $encoded = json_encode([$saved, $mismatched, $zeroSaved, $login, $dependency, $freshRunning, $staleRunning], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('storage/ctrip_profile_system_60', (string)$encoded);
        self::assertStringNotContainsString('cloakbrowser', strtolower((string)$encoded));
    }

    public function testDailyWorkbenchWorkflowChainFollowsOtaToExecutionOrder(): void
    {
        $controller = $this->controller();

        $reliability = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'full',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [
                ['status' => 'success', 'saved_count' => 4, 'run_time' => '2026-06-12 09:00:00'],
            ],
            'history_replay' => [],
            'source_date_evidence' => [
                'status' => 'target_date_missing',
                'target_date' => '2026-06-12',
                'source_policy' => 'read_online_daily_data_aggregate_only',
                'platforms' => [
                    [
                        'platform' => 'ctrip',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 0,
                        'target_date_data_types' => [],
                        'latest_available' => ['date' => '2026-06-11', 'rows' => 4, 'data_types' => ['business']],
                        'date_relation' => 'stale_before_target',
                    ],
                    [
                        'platform' => 'meituan',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 0,
                        'target_date_data_types' => [],
                        'latest_available' => ['date' => '2026-06-10', 'rows' => 2, 'data_types' => ['business']],
                        'date_relation' => 'stale_before_target',
                    ],
                ],
            ],
            'data_quality' => ['status' => 'warning', 'checked_records' => 4, 'missing_count' => 1, 'missing_fields' => ['quantity']],
            'revenue_metric_evidence' => [
                'status' => 'empty',
                'metric_trust_keys' => [],
                'data_gap_codes' => ['available_room_nights_missing'],
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
            ],
            'field_definitions' => [
                ['source' => 'ctrip', 'module' => 'business', 'fields' => [['field' => 'amount']]],
                ['source' => 'meituan', 'module' => 'business', 'fields' => [['field' => 'amount']]],
            ],
            'pending_actions' => [],
            'failure_reasons' => [],
        ]]);

        $row = $this->invokeNonPublic($controller, 'buildDailyWorkbenchRow', [
            ['id' => 1, 'name' => 'Workflow Fixture Hotel'],
            $reliability,
            '2026-06-12',
        ]);

        $chain = $row['workflow_chain'];
        self::assertCount(5, $chain);
        self::assertSame([
            'today_ota_data',
            'field_trust_and_gaps',
            'revenue_metrics',
            'ai_diagnosis',
            'operation_action',
        ], array_column($chain, 'key'));
        self::assertSame('携程/美团今日数据', $chain[0]['label']);
        self::assertSame('today_ota_collected', $chain[0]['question_key']);
        self::assertSame('read_existing_online_daily_data_only', $chain[0]['source_policy']);
        self::assertSame(0, $chain[0]['evidence']['target_date_source_rows']);
        self::assertContains('ctrip_target_date_source_rows_missing', $chain[0]['blocking_gap_codes']);
        self::assertSame('字段可信/缺失', $chain[1]['label']);
        self::assertSame('收益指标', $chain[2]['label']);
        self::assertSame('read_existing_ota_standard_revenue_metrics_only', $chain[2]['source_policy']);
        self::assertContains('available_room_nights_missing', $chain[2]['evidence']['data_gap_codes']);
        self::assertSame('AI诊断', $chain[3]['label']);
        self::assertSame('read_existing_ota_gap_evidence_only', $chain[3]['source_policy']);
        self::assertSame('执行动作', $chain[4]['label']);
        self::assertSame('read_existing_operation_execution_state_only', $chain[4]['source_policy']);
        self::assertStringContainsString('Read-only workflow decomposition', $chain[4]['protected_boundary']);
    }

    public function testCompetitionCircleSummaryCountsDistinctHotelsAndSeparatesSelf(): void
    {
        $controller = $this->controller();
        $summary = $this->invokeNonPublic($controller, 'summarizeCollectionCompetitionCircleRows', [[
            ['hotel_id' => '100', 'hotel_name' => '我的酒店', 'raw_data' => json_encode(['hotelId' => '100', 'hotelName' => '我的酒店'])],
            ['hotel_id' => '200', 'hotel_name' => '竞店A', 'raw_data' => json_encode(['hotelId' => '200', 'hotelName' => '竞店A'])],
            ['hotel_id' => '200', 'hotel_name' => '竞店A', 'raw_data' => json_encode(['hotelId' => '200', 'hotelName' => '竞店A'])],
            ['hotel_id' => '', 'hotel_name' => '竞店B', 'raw_data' => json_encode(['hotelName' => '竞店B'])],
        ]]);

        self::assertSame(3, $summary['target_date_competition_hotel_count']);
        self::assertSame(1, $summary['target_date_competition_self_count']);
        self::assertSame(2, $summary['target_date_competition_competitor_count']);
    }

    public function testCompetitionCircleCountsFlowIntoDailyWorkbenchPlatformRows(): void
    {
        $controller = $this->controller();
        $summary = $this->invokeNonPublic($controller, 'phase1CollectionSourceSummary', [[
            'source_date_evidence' => [
                'target_date' => '2026-07-11',
                'platforms' => [[
                    'platform' => 'ctrip',
                    'target_date_rows' => 130,
                    'target_date_data_types' => ['traffic', 'competitor'],
                    'target_date_competition_hotel_count' => 26,
                    'target_date_competition_self_count' => 1,
                    'target_date_competition_competitor_count' => 25,
                    'date_relation' => 'target_date',
                ]],
            ],
        ]]);
        $rows = $this->invokeNonPublic($controller, 'dailyWorkbenchPlatformRows', [$summary]);

        self::assertSame(130, $rows[0]['target_date_rows']);
        self::assertSame(26, $rows[0]['target_date_competition_hotel_count']);
        self::assertSame(1, $rows[0]['target_date_competition_self_count']);
        self::assertSame(25, $rows[0]['target_date_competition_competitor_count']);
    }

    public function testManualFetchEvidenceUsesRequestedDateAndCompetitionRowsOnly(): void
    {
        $controller = $this->controller();
        $rows = $this->invokeNonPublic($controller, 'buildManualFetchEvidenceRows', [[
            ['id' => 7, 'name' => '巢湖测试'],
            ['id' => 124, 'name' => '敦煌兰亭宿集'],
        ], [
            ['system_hotel_id' => 7, 'source' => 'ctrip', 'data_type' => 'traffic', 'dimension' => 'search', 'hotel_id' => '832085', 'hotel_name' => '巢湖测试'],
            ['system_hotel_id' => 7, 'source' => 'ctrip', 'data_type' => 'competitor', 'dimension' => 'competition_circle_hotel', 'hotel_id' => '832085', 'hotel_name' => '我的酒店'],
            ['system_hotel_id' => 7, 'source' => 'ctrip', 'data_type' => 'competitor', 'dimension' => 'competition_circle_hotel', 'hotel_id' => '200', 'hotel_name' => '竞店A'],
            ['system_hotel_id' => 7, 'source' => 'meituan', 'data_type' => 'business', 'dimension' => 'overview'],
            ['system_hotel_id' => 124, 'source' => 'ctrip', 'data_type' => 'competitor', 'dimension' => 'competition_circle_hotel', 'hotel_id' => '300', 'hotel_name' => '竞店B'],
        ], '2026-07-11']);

        self::assertSame('2026-07-11', $rows[0]['targetDate']);
        self::assertSame(2, $rows[0]['platformRows'][0]['target_date_competition_hotel_count']);
        self::assertSame(1, $rows[0]['platformRows'][0]['target_date_competition_self_count']);
        self::assertSame(1, $rows[0]['platformRows'][1]['target_date_rows']);
        self::assertSame(1, $rows[1]['platformRows'][0]['target_date_competition_hotel_count']);
        self::assertSame(0, $rows[1]['platformRows'][0]['target_date_competition_self_count']);
    }

    public function testPhase1EmployeeQuestionsExposeEvidenceButKeepAiAndExecutionOpen(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'full',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [
                ['status' => 'success', 'saved_count' => 2, 'run_time' => '2026-06-12 09:00:00'],
            ],
            'history_replay' => [],
            'data_quality' => ['status' => 'warning', 'checked_records' => 2, 'missing_count' => 1, 'missing_fields' => ['quantity']],
            'revenue_metric_evidence' => [
                'status' => 'ready',
                'metric_trust_keys' => ['totals.revenue', 'totals.room_nights'],
                'data_gap_codes' => ['available_room_nights_missing'],
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
            ],
            'field_definitions' => [
                ['source' => 'ctrip', 'module' => 'business', 'fields' => [['field' => 'amount'], ['field' => 'quantity']]],
            ],
            'pending_actions' => [
                ['type' => 'field_quality', 'action_code' => 'ota_field_quality_warning', 'reason' => 'field missing'],
            ],
            'failure_reasons' => [],
        ]]);

        $rowsByKey = [];
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            $rowsByKey[$row['key']] = $row;
        }

        self::assertSame('warning', $rowsByKey['today_ota_collected']['status']);
        self::assertSame(2, $rowsByKey['today_ota_collected']['evidence']['source_rows']);
        self::assertSame(0, $rowsByKey['today_ota_collected']['evidence']['target_date_source_rows']);
        self::assertSame('unknown', $rowsByKey['today_ota_collected']['evidence']['target_date_platform_coverage']['status']);
        self::assertTrue($rowsByKey['today_ota_collected']['evidence']['target_date_platform_coverage']['source_date_evidence_missing']);
        self::assertTrue($rowsByKey['today_ota_collected']['evidence']['source_date_evidence_missing']);
        self::assertSame('warning', $rowsByKey['trusted_fields']['status']);
        self::assertSame(2, $rowsByKey['trusted_fields']['evidence']['field_definition_count']);
        self::assertSame(['ctrip.business.amount', 'ctrip.business.quantity'], $rowsByKey['trusted_fields']['evidence']['field_definition_keys']);
        self::assertSame(0, $rowsByKey['trusted_fields']['evidence']['target_date_source_rows']);
        self::assertTrue($rowsByKey['trusted_fields']['evidence']['metric_trust_required']);
        self::assertSame(['totals.revenue', 'totals.room_nights'], $rowsByKey['trusted_fields']['evidence']['metric_trust_keys']);
        self::assertSame(['available_room_nights_missing'], $rowsByKey['trusted_fields']['evidence']['data_gap_codes']);
        self::assertSame('requires_target_date_rows_field_definitions_metric_trust_and_data_quality', $rowsByKey['trusted_fields']['evidence']['field_trust_policy']);
        self::assertSame(['ota_field_quality_warning'], $rowsByKey['trusted_fields']['evidence']['field_pending_action_codes']);
        self::assertContains('/api/ota-standard/revenue-metrics.metric_trust', $rowsByKey['trusted_fields']['evidence']['evidence_refs']);
        self::assertSame('proved', $rowsByKey['missing_fields']['status']);
        self::assertSame(['quantity'], $rowsByKey['missing_fields']['evidence']['missing_field_codes']);
        self::assertSame(['available_room_nights_missing'], $rowsByKey['missing_fields']['evidence']['data_gap_codes']);
        self::assertSame(['ota_field_quality_warning'], $rowsByKey['missing_fields']['evidence']['field_pending_action_codes']);
        self::assertSame('not_proved', $rowsByKey['revenue_traffic_conversion']['status']);
        self::assertSame('warning', $rowsByKey['ai_evidence']['status']);
        self::assertContains('source_date_evidence_missing', $rowsByKey['ai_evidence']['evidence']['upstream_blockers']);
        self::assertSame('blocked_by_verified_ota_gaps', $rowsByKey['ai_evidence']['evidence']['diagnosis_status']);
        self::assertSame('blocked_by_verified_ota_gaps', $rowsByKey['ai_evidence']['evidence']['action_item_status']);
        self::assertContains('source_date_evidence_missing', $rowsByKey['ai_evidence']['evidence']['blocking_missing_codes']);
        self::assertSame('warning', $rowsByKey['next_operation_action']['status']);
        self::assertSame('missing', $rowsByKey['next_operation_action']['evidence']['operation_evidence_status']);
        self::assertSame('read_existing_operation_execution_state_only', $rowsByKey['next_operation_action']['evidence']['source_policy']);
        self::assertSame(0, $rowsByKey['next_operation_action']['evidence']['ota_diagnosis_linked_intent_count']);
        self::assertContains('operation_execution_context_missing', $rowsByKey['next_operation_action']['evidence']['data_gap_codes']);
        self::assertContains('operation_execution_sample_missing', $rowsByKey['next_operation_action']['evidence']['blocking_missing_codes']);
        self::assertContains('source_date_evidence_missing', $rowsByKey['next_operation_action']['evidence']['blocking_missing_codes']);
        self::assertContains('phase1_confirm_source_date_evidence', array_column($payload['phase1_employee_questions']['next_required_actions'], 'action_code'));
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            self::assertArrayHasKey('next_action_codes', $row);
            self::assertIsArray($row['next_action_codes']);
        }
        self::assertContains('phase1_confirm_source_date_evidence', $rowsByKey['today_ota_collected']['next_action_codes']);
        self::assertContains('phase1_collect_ai_diagnosis_evidence', $rowsByKey['ai_evidence']['next_action_codes']);
        self::assertContains('phase1_create_operation_execution_evidence', $rowsByKey['next_operation_action']['next_action_codes']);
        self::assertSame('incomplete', $payload['phase1_employee_questions']['summary']['status']);
        self::assertSame('incomplete', $payload['phase1_employee_questions']['closure_summary']['status']);
        self::assertSame($payload['phase1_employee_questions']['summary'], $payload['phase1_employee_questions']['closure_summary']);
    }

    public function testPhase1EmployeeQuestionsDoNotUseStaleOrFutureRowsAsTargetDateProof(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'full',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [
                ['status' => 'success', 'saved_count' => 9, 'run_time' => '2026-06-12 09:00:00'],
            ],
            'history_replay' => [],
            'source_date_evidence' => [
                'status' => 'target_date_missing',
                'target_date' => '2026-06-12',
                'source_policy' => 'read_online_daily_data_aggregate_only',
                'platforms' => [
                    [
                        'platform' => 'ctrip',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 0,
                        'target_date_data_types' => [],
                        'latest_available' => ['date' => '2026-06-14', 'rows' => 4, 'data_types' => ['business']],
                        'date_relation' => 'future_dated_for_target',
                    ],
                    [
                        'platform' => 'meituan',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 0,
                        'target_date_data_types' => [],
                        'latest_available' => ['date' => '2026-06-11', 'rows' => 176, 'data_types' => ['business']],
                        'date_relation' => 'stale_before_target',
                    ],
                ],
            ],
            'data_quality' => ['status' => 'warning', 'checked_records' => 9, 'missing_count' => 0],
            'field_definitions' => [
                ['source' => 'ctrip', 'module' => 'business', 'fields' => [['field' => 'amount']]],
            ],
            'pending_actions' => [],
            'failure_reasons' => [],
        ]]);

        $first = $payload['phase1_employee_questions']['rows'][0];
        self::assertSame('today_ota_collected', $first['key']);
        self::assertSame('not_proved', $first['status']);
        self::assertSame(9, $first['evidence']['source_rows']);
        self::assertSame(0, $first['evidence']['target_date_source_rows']);
        self::assertSame('future_dated_for_target', $first['evidence']['source_date_evidence']['platforms'][0]['date_relation']);
        self::assertSame('stale_before_target', $first['evidence']['source_date_evidence']['platforms'][1]['date_relation']);
        $sourceSummary = $payload['phase1_employee_questions']['collection_source_summary'];
        self::assertSame($sourceSummary, $payload['collection_source_summary']);
        self::assertCount(2, $sourceSummary);
        self::assertSame('online_daily_data', $sourceSummary[0]['storage_table']);
        self::assertSame('read_existing_online_daily_data_only', $sourceSummary[0]['source_policy']);
        self::assertSame('ota_channel', $sourceSummary[0]['metric_scope']);
        self::assertSame(0, $sourceSummary[0]['target_date_rows']);
        self::assertSame('2026-06-14', $sourceSummary[0]['latest_available']['date']);
        self::assertSame('future_dated_for_target', $sourceSummary[0]['latest_available']['date_relation']);
        self::assertTrue($sourceSummary[0]['latest_available_reference_only']);
        self::assertFalse($sourceSummary[0]['collection_logic_changed']);
        self::assertSame('stale_before_target', $sourceSummary[1]['latest_available']['date_relation']);
        $rowsByKey = [];
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            $rowsByKey[$row['key']] = $row;
        }
        self::assertSame('warning', $rowsByKey['trusted_fields']['status']);
        self::assertContains('ctrip_target_date_source_rows_missing', $rowsByKey['today_ota_collected']['blocking_gap_codes']);
        self::assertContains('ctrip_target_date_source_rows_missing', $rowsByKey['trusted_fields']['blocking_gap_codes']);
        self::assertSame(0, $rowsByKey['trusted_fields']['evidence']['target_date_source_rows']);
        self::assertSame('target_date_source_missing', $rowsByKey['trusted_fields']['evidence']['platform_field_trust'][0]['field_trust_status']);
        self::assertSame('target_date_source_missing', $rowsByKey['trusted_fields']['evidence']['platform_field_trust'][1]['field_trust_status']);
        self::assertContains('ctrip_target_date_source_rows_missing', $rowsByKey['trusted_fields']['evidence']['platform_field_trust'][0]['reason_codes']);
        self::assertSame('not_proved', $rowsByKey['revenue_traffic_conversion']['status']);
        self::assertContains('ctrip_revenue_metric_inputs_missing', $rowsByKey['revenue_traffic_conversion']['blocking_gap_codes']);
        self::assertSame(0, $rowsByKey['revenue_traffic_conversion']['evidence']['target_date_source_rows']);
        self::assertSame([], $rowsByKey['revenue_traffic_conversion']['evidence']['revenue_ready_platforms']);
        self::assertSame(['ctrip', 'meituan'], $rowsByKey['revenue_traffic_conversion']['evidence']['revenue_missing_platforms']);
        self::assertSame(['ctrip', 'meituan'], $rowsByKey['revenue_traffic_conversion']['evidence']['traffic_missing_platforms']);
        self::assertContains('ctrip_revenue_metric_inputs_missing', $rowsByKey['revenue_traffic_conversion']['evidence']['metric_domain_gap_codes']);
        self::assertContains('meituan_traffic_conversion_facts_missing', $rowsByKey['revenue_traffic_conversion']['evidence']['metric_domain_gap_codes']);
        self::assertSame('missing', $rowsByKey['revenue_traffic_conversion']['evidence']['metric_domain_readiness'][0]['revenue_status']);
        $sourceActionByCode = [];
        foreach ($payload['phase1_employee_questions']['next_required_actions'] as $action) {
            $sourceActionByCode[$action['action_code']] = $action;
        }
        self::assertSame('/api/online-data/capture-ctrip-browser', $sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry']);
        self::assertContains('/api/online-data/capture-ctrip-browser', array_column($sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'], 'entry'));
        self::assertContains('临时 Cookie/API', array_column($sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'], 'label'));
        self::assertContains($sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][0]['readiness']['status'], ['profile_missing', 'profile_found_login_unverified']);
        self::assertArrayHasKey('profile_count', $sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][0]['readiness']);
        self::assertStringContainsString('已取得携程 Cookie', $sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][1]['use_when']);
        self::assertStringContainsString('不改变采集字段', $sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][1]['boundary']);
        self::assertSame('requires_user_context', $sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][1]['readiness']['status']);
        self::assertFalse($sourceActionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][1]['readiness']['can_run_now']);
        self::assertSame('/api/online-data/capture-meituan-browser', $sourceActionByCode['phase1_collect_meituan_target_date_source_rows']['entry']);
        self::assertContains('/api/online-data/capture-meituan-browser', array_column($sourceActionByCode['phase1_collect_meituan_target_date_source_rows']['entry_options'], 'entry'));
        self::assertStringContainsString('已取得美团 Cookie', $sourceActionByCode['phase1_collect_meituan_target_date_source_rows']['entry_options'][1]['use_when']);
        self::assertSame('requires_user_context', $sourceActionByCode['phase1_collect_meituan_target_date_source_rows']['entry_options'][1]['readiness']['status']);
        self::assertSame('incomplete', $payload['phase1_employee_questions']['summary']['status']);
        self::assertSame('phase1_collect_ctrip_target_date_source_rows', $payload['phase1_employee_questions']['closure_summary']['top_action_code']);
        self::assertContains('/api/online-data/fetch-ctrip-overview', array_column($payload['phase1_employee_questions']['closure_summary']['top_action_entry_options'], 'entry'));
        self::assertContains('/api/online-data/capture-ctrip-browser', array_column($payload['phase1_employee_questions']['closure_summary']['top_action_entry_options'], 'entry'));
        self::assertContains('/api/online-data/collection-reliability', array_column($payload['phase1_employee_questions']['closure_summary']['top_action_entry_options'], 'entry'));
        self::assertStringContainsString('本地 Profile 存在', $payload['phase1_employee_questions']['closure_summary']['top_action_entry_options'][0]['requires']);
        self::assertStringContainsString('用户提供 Cookie/Payload 上下文', $payload['phase1_employee_questions']['closure_summary']['top_action_entry_options'][1]['requires']);
        self::assertStringContainsString('只读状态', $payload['phase1_employee_questions']['closure_summary']['top_action_entry_options'][2]['boundary']);
        self::assertSame('ready', $payload['phase1_employee_questions']['closure_summary']['top_action_entry_options'][2]['readiness']['status']);
        self::assertTrue($payload['phase1_employee_questions']['closure_summary']['top_action_entry_options'][2]['readiness']['can_run_now']);
        self::assertContains('today_ota_collected', $payload['phase1_employee_questions']['closure_summary']['top_action_related_question_keys']);
        self::assertContains('trusted_fields', $payload['phase1_employee_questions']['closure_summary']['top_action_related_question_keys']);
        self::assertSame(['ctrip_target_date_source_rows_missing'], $payload['phase1_employee_questions']['closure_summary']['top_action_resolves_missing_codes']);
        self::assertSame(['ctrip_source_rows_missing'], $payload['phase1_employee_questions']['closure_summary']['top_action_live_closure_gap_codes']);
        self::assertSame('ctrip', $payload['phase1_employee_questions']['closure_summary']['top_action_source_snapshot']['platform']);
        self::assertSame(0, $payload['phase1_employee_questions']['closure_summary']['top_action_source_snapshot']['target_date_rows']);
        self::assertSame('future_dated_for_target', $payload['phase1_employee_questions']['closure_summary']['top_action_source_snapshot']['latest_available']['date_relation']);
        self::assertTrue($payload['phase1_employee_questions']['closure_summary']['top_action_source_snapshot']['latest_available_reference_only']);
        self::assertStringContainsString('target_date_rows > 0', $payload['phase1_employee_questions']['closure_summary']['top_action_source_snapshot']['proof_requirement']);
    }

    public function testPhase1EmployeeQuestionsTreatSinglePlatformTargetRowsAsPartialCoverage(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'full',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [
                ['status' => 'success', 'saved_count' => 88, 'run_time' => '2026-06-12 09:00:00'],
            ],
            'history_replay' => [],
            'source_date_evidence' => [
                'status' => 'target_date_present',
                'target_date' => '2026-06-12',
                'source_policy' => 'read_online_daily_data_aggregate_only',
                'platforms' => [
                    [
                        'platform' => 'ctrip',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 0,
                        'target_date_data_types' => [],
                        'latest_available' => ['date' => '2026-06-14', 'rows' => 4, 'data_types' => ['business']],
                        'date_relation' => 'future_dated_for_target',
                    ],
                    [
                        'platform' => 'meituan',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 88,
                        'target_date_data_types' => ['business'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 88, 'data_types' => ['business']],
                        'date_relation' => 'target_date',
                    ],
                ],
            ],
            'data_quality' => ['status' => 'warning', 'checked_records' => 88, 'missing_count' => 0],
            'field_definitions' => [
                ['source' => 'meituan', 'module' => 'business', 'fields' => [['field' => 'amount']]],
            ],
            'pending_actions' => [],
            'failure_reasons' => [],
        ]]);

        $rowsByKey = [];
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            $rowsByKey[$row['key']] = $row;
        }

        self::assertSame('warning', $rowsByKey['today_ota_collected']['status']);
        self::assertSame(88, $rowsByKey['today_ota_collected']['evidence']['target_date_source_rows']);
        self::assertSame('partial', $rowsByKey['today_ota_collected']['evidence']['target_date_platform_coverage']['status']);
        self::assertSame(['ctrip'], $rowsByKey['today_ota_collected']['evidence']['target_date_platform_coverage']['missing_platforms']);
        self::assertSame('warning', $rowsByKey['trusted_fields']['status']);
        self::assertSame('target_date_source_missing', $rowsByKey['trusted_fields']['evidence']['platform_field_trust'][0]['field_trust_status']);
        self::assertSame('target_date_revenue_sample_present', $rowsByKey['trusted_fields']['evidence']['platform_field_trust'][1]['field_trust_status']);
        self::assertSame(88, $rowsByKey['trusted_fields']['evidence']['platform_field_trust'][1]['target_date_rows']);
        self::assertSame('warning', $rowsByKey['revenue_traffic_conversion']['status']);
        self::assertSame(['meituan'], $rowsByKey['revenue_traffic_conversion']['evidence']['revenue_ready_platforms']);
        self::assertSame([], $rowsByKey['revenue_traffic_conversion']['evidence']['traffic_ready_platforms']);
        self::assertSame([], $rowsByKey['revenue_traffic_conversion']['evidence']['conversion_ready_platforms']);
        self::assertSame(['ctrip'], $rowsByKey['revenue_traffic_conversion']['evidence']['revenue_missing_platforms']);
        self::assertSame(['ctrip', 'meituan'], $rowsByKey['revenue_traffic_conversion']['evidence']['traffic_missing_platforms']);
        self::assertSame(['ctrip', 'meituan'], $rowsByKey['revenue_traffic_conversion']['evidence']['conversion_missing_platforms']);
        self::assertContains('ctrip_revenue_metric_inputs_missing', $rowsByKey['revenue_traffic_conversion']['evidence']['metric_domain_gap_codes']);
        self::assertContains('meituan_traffic_conversion_facts_missing', $rowsByKey['revenue_traffic_conversion']['evidence']['metric_domain_gap_codes']);
        self::assertSame('ready', $rowsByKey['revenue_traffic_conversion']['evidence']['metric_domain_readiness'][1]['revenue_status']);
        self::assertSame('missing', $rowsByKey['revenue_traffic_conversion']['evidence']['metric_domain_readiness'][1]['traffic_status']);
        self::assertContains('traffic', $rowsByKey['revenue_traffic_conversion']['evidence']['metric_domain_readiness'][1]['missing_domains']);
        self::assertSame(['ctrip_target_date_source_rows_missing'], $rowsByKey['ai_evidence']['evidence']['upstream_blockers']);
        self::assertContains('ai_action_items_blocked', $rowsByKey['next_operation_action']['evidence']['upstream_blockers']);
        self::assertContains('operation_execution_sample_missing', $rowsByKey['next_operation_action']['evidence']['blocking_missing_codes']);
        $actionCodes = array_column($payload['phase1_employee_questions']['next_required_actions'], 'action_code');
        self::assertSame('phase1_collect_ctrip_target_date_source_rows', $actionCodes[0]);
        self::assertContains('phase1_collect_ctrip_target_date_source_rows', $actionCodes);
        self::assertContains('phase1_confirm_meituan_traffic_conversion_facts', $actionCodes);
        self::assertContains('phase1_collect_ai_diagnosis_evidence', $actionCodes);
        self::assertContains('phase1_create_operation_execution_evidence', $actionCodes);
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            self::assertArrayHasKey('next_action_codes', $row);
            self::assertIsArray($row['next_action_codes']);
            if (!in_array($row['status'], ['proved', 'no_gap_reported'], true) && $row['next_action_codes'] !== []) {
                self::assertArrayHasKey('primary_next_action_code', $row);
                self::assertArrayHasKey('direct_next_action_code', $row);
                self::assertContains($row['primary_next_action_code'], $row['next_action_codes']);
                self::assertContains($row['direct_next_action_code'], $row['next_action_codes']);
                self::assertSame(count($row['next_action_codes']), $row['evidence']['linked_action_count'] ?? null);
            }
        }
        self::assertContains('phase1_collect_ctrip_target_date_source_rows', $rowsByKey['today_ota_collected']['next_action_codes']);
        self::assertContains('phase1_confirm_meituan_traffic_conversion_facts', $rowsByKey['revenue_traffic_conversion']['next_action_codes']);
        self::assertContains('phase1_collect_ai_diagnosis_evidence', $rowsByKey['ai_evidence']['next_action_codes']);
        self::assertContains('phase1_create_operation_execution_evidence', $rowsByKey['next_operation_action']['next_action_codes']);
        self::assertSame('phase1_collect_ai_diagnosis_evidence', $rowsByKey['ai_evidence']['direct_next_action_code']);
        self::assertSame('ai_diagnosis_evidence', $rowsByKey['ai_evidence']['direct_next_action_family']);
        self::assertSame('phase1_create_operation_execution_evidence', $rowsByKey['next_operation_action']['direct_next_action_code']);
        self::assertSame('operation_execution_evidence', $rowsByKey['next_operation_action']['direct_next_action_family']);
        $actionByCode = [];
        $seenBlockedAction = false;
        foreach ($payload['phase1_employee_questions']['next_required_actions'] as $action) {
            self::assertArrayHasKey('success_criteria', $action);
            self::assertNotSame('', $action['success_criteria']);
            self::assertArrayHasKey('resolves_missing_codes', $action);
            self::assertIsArray($action['resolves_missing_codes']);
            self::assertArrayHasKey('live_closure_gap_codes', $action);
            self::assertIsArray($action['live_closure_gap_codes']);
            self::assertNotSame([], $action['live_closure_gap_codes']);
            self::assertArrayHasKey('blocked_by_action_codes', $action);
            self::assertIsArray($action['blocked_by_action_codes']);
            self::assertNotContains($action['action_code'], $action['blocked_by_action_codes']);
            self::assertArrayHasKey('related_question_keys', $action);
            self::assertIsArray($action['related_question_keys']);
            self::assertArrayHasKey('employee_explanation', $action);
            self::assertNotSame('', $action['employee_explanation']);
            self::assertArrayHasKey('limited_conclusions', $action);
            self::assertIsArray($action['limited_conclusions']);
            self::assertNotSame([], $action['limited_conclusions']);
            self::assertArrayHasKey('still_usable_metrics', $action);
            self::assertIsArray($action['still_usable_metrics']);
            self::assertNotSame([], $action['still_usable_metrics']);
            self::assertArrayHasKey('explanation_next_action', $action);
            self::assertNotSame('', $action['explanation_next_action']);
            if (($action['status'] ?? '') === 'blocked') {
                $seenBlockedAction = true;
                self::assertNotSame([], $action['blocked_by_action_codes']);
            }
            if ($seenBlockedAction) {
                self::assertNotSame('missing', $action['status'] ?? '');
            }
            $actionByCode[$action['action_code']] = $action;
        }
        self::assertSame('high', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['priority']);
        self::assertSame('target_date_source_rows', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['action_family']);
        self::assertSame('/api/online-data/capture-ctrip-browser', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry']);
        self::assertContains('/api/online-data/capture-ctrip-browser', array_column($actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'], 'entry'));
        self::assertContains('/api/online-data/collection-reliability', array_column($actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'], 'entry'));
        self::assertStringContainsString('本地 Profile 存在', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][0]['requires']);
        self::assertStringContainsString('只核对目标日', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][2]['use_when']);
        self::assertContains($actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][0]['readiness']['status'], ['profile_missing', 'profile_found_login_unverified']);
        self::assertSame('read_local_profile_directory_names_only', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][0]['readiness']['source_policy']);
        self::assertSame('requires_user_context', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][1]['readiness']['status']);
        self::assertSame('ready', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['entry_options'][2]['readiness']['status']);
        self::assertStringContainsString('target_date_rows', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['success_criteria']);
        self::assertSame(['ctrip_target_date_source_rows_missing'], $actionByCode['phase1_collect_ctrip_target_date_source_rows']['resolves_missing_codes']);
        self::assertSame(['ctrip_source_rows_missing'], $actionByCode['phase1_collect_ctrip_target_date_source_rows']['live_closure_gap_codes']);
        self::assertSame([], $actionByCode['phase1_collect_ctrip_target_date_source_rows']['blocked_by_action_codes']);
        self::assertContains('trusted_fields', $actionByCode['phase1_collect_ctrip_target_date_source_rows']['related_question_keys']);
        self::assertContains('phase1_collect_ctrip_target_date_source_rows', $rowsByKey['trusted_fields']['next_action_codes']);
        self::assertSame('traffic_conversion_facts', $actionByCode['phase1_confirm_meituan_traffic_conversion_facts']['action_family']);
        self::assertSame('/api/online-data/capture-meituan-browser', $actionByCode['phase1_confirm_meituan_traffic_conversion_facts']['entry']);
        self::assertNotSame('/api/ota-standard/revenue-metrics', $actionByCode['phase1_confirm_meituan_traffic_conversion_facts']['entry']);
        self::assertSame('ai_diagnosis_evidence', $actionByCode['phase1_collect_ai_diagnosis_evidence']['action_family']);
        self::assertSame('operation_execution_evidence', $actionByCode['phase1_create_operation_execution_evidence']['action_family']);
        self::assertSame(['meituan_traffic_facts_missing'], $actionByCode['phase1_confirm_meituan_traffic_conversion_facts']['live_closure_gap_codes']);
        self::assertSame(['ai_diagnosis_action_items_blocked'], $actionByCode['phase1_collect_ai_diagnosis_evidence']['live_closure_gap_codes']);
        self::assertSame(['operation_execution_sample_missing'], $actionByCode['phase1_create_operation_execution_evidence']['live_closure_gap_codes']);
        self::assertContains('list_exposure', $actionByCode['phase1_confirm_meituan_traffic_conversion_facts']['evidence_needed']);
        self::assertStringContainsString('不改变采集字段', $actionByCode['phase1_confirm_meituan_traffic_conversion_facts']['protected_boundary']);
        self::assertContains('approval.status=approved', $actionByCode['phase1_create_operation_execution_evidence']['evidence_needed']);
        self::assertStringContainsString('OTA diagnosis action_items', $actionByCode['phase1_create_operation_execution_evidence']['success_criteria']);
        self::assertContains('phase1_collect_ctrip_target_date_source_rows', $actionByCode['phase1_collect_ai_diagnosis_evidence']['blocked_by_action_codes']);
        self::assertContains('phase1_collect_ai_diagnosis_evidence', $actionByCode['phase1_create_operation_execution_evidence']['blocked_by_action_codes']);
        self::assertSame('incomplete', $payload['phase1_employee_questions']['summary']['status']);
    }

    public function testPhase1SourceDateEvidenceStatusSeparatesPartialCoverage(): void
    {
        $controller = $this->controller();

        $complete = $this->invokeNonPublic($controller, 'phase1SourceDateEvidenceStatus', [[
            ['platform' => 'ctrip', 'target_date_rows' => 2],
            ['platform' => 'meituan', 'target_date_rows' => 88],
        ]]);
        $partial = $this->invokeNonPublic($controller, 'phase1SourceDateEvidenceStatus', [[
            ['platform' => 'ctrip', 'target_date_rows' => 2],
            ['platform' => 'meituan', 'target_date_rows' => 0],
        ]]);
        $missing = $this->invokeNonPublic($controller, 'phase1SourceDateEvidenceStatus', [[
            ['platform' => 'ctrip', 'target_date_rows' => 0],
            ['platform' => 'meituan', 'target_date_rows' => 0],
        ]]);

        self::assertSame('target_date_complete', $complete);
        self::assertSame('target_date_partial', $partial);
        self::assertSame('target_date_missing', $missing);
    }

    public function testPhase1EmployeeQuestionsMakeAiDiagnosisActionRunnableWhenUpstreamEvidenceIsReady(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'full',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [
                ['status' => 'success', 'saved_count' => 24, 'run_time' => '2026-06-12 09:00:00'],
            ],
            'history_replay' => [],
            'source_date_evidence' => [
                'status' => 'target_date_present',
                'target_date' => '2026-06-12',
                'source_policy' => 'read_online_daily_data_aggregate_only',
                'platforms' => [
                    [
                        'platform' => 'ctrip',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 12,
                        'target_date_data_types' => ['business', 'traffic'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 12, 'data_types' => ['business', 'traffic']],
                        'date_relation' => 'target_date',
                    ],
                    [
                        'platform' => 'meituan',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 12,
                        'target_date_data_types' => ['business', 'traffic'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 12, 'data_types' => ['business', 'traffic']],
                        'date_relation' => 'target_date',
                    ],
                ],
            ],
            'data_quality' => ['status' => 'ok', 'checked_records' => 24, 'missing_count' => 0],
            'revenue_metric_evidence' => [
                'status' => 'ready',
                'metric_trust_keys' => ['totals.revenue', 'traffic.rows'],
                'data_gap_codes' => [],
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
            ],
            'field_definitions' => [
                ['source' => 'ctrip', 'module' => 'business', 'fields' => [['field' => 'amount']]],
                ['source' => 'meituan', 'module' => 'business', 'fields' => [['field' => 'amount']]],
            ],
            'pending_actions' => [],
            'failure_reasons' => [],
        ]]);

        $rowsByKey = [];
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            $rowsByKey[$row['key']] = $row;
        }
        self::assertSame('proved', $rowsByKey['today_ota_collected']['status']);
        self::assertSame('proved', $rowsByKey['trusted_fields']['status']);
        self::assertSame(['totals.revenue', 'traffic.rows'], $rowsByKey['trusted_fields']['evidence']['metric_trust_keys']);
        self::assertSame('proved', $rowsByKey['revenue_traffic_conversion']['status']);
        self::assertSame([], $rowsByKey['ai_evidence']['evidence']['upstream_blockers']);

        $actionByCode = [];
        foreach ($payload['phase1_employee_questions']['next_required_actions'] as $action) {
            $actionByCode[$action['action_code']] = $action;
        }

        self::assertSame('missing', $actionByCode['phase1_collect_ai_diagnosis_evidence']['status']);
        self::assertSame([], $actionByCode['phase1_collect_ai_diagnosis_evidence']['blocked_by']);
        self::assertSame([], $actionByCode['phase1_collect_ai_diagnosis_evidence']['blocked_by_action_codes']);
        self::assertContains('ai_action_items_missing', $actionByCode['phase1_collect_ai_diagnosis_evidence']['resolves_missing_codes']);
        self::assertStringContainsString('调用现有 OTA 诊断', $actionByCode['phase1_collect_ai_diagnosis_evidence']['action']);
        self::assertStringContainsString('evidence_sources', $actionByCode['phase1_collect_ai_diagnosis_evidence']['success_criteria']);
        self::assertContains('phase1_collect_ai_diagnosis_evidence', $rowsByKey['ai_evidence']['next_action_codes']);
        self::assertSame('phase1_collect_ai_diagnosis_evidence', $rowsByKey['ai_evidence']['primary_next_action_code']);
        self::assertSame('phase1_collect_ai_diagnosis_evidence', $rowsByKey['ai_evidence']['direct_next_action_code']);
        self::assertSame('missing', $rowsByKey['ai_evidence']['primary_next_action_status']);
        self::assertSame('blocked', $actionByCode['phase1_create_operation_execution_evidence']['status']);
        self::assertContains('ai_action_items_missing', $actionByCode['phase1_create_operation_execution_evidence']['blocked_by']);
        self::assertContains('phase1_collect_ai_diagnosis_evidence', $actionByCode['phase1_create_operation_execution_evidence']['blocked_by_action_codes']);
    }

    public function testPhase1EmployeeQuestionsRequireMetricTrustForTrustedFieldsAndMetricProof(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'full',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [
                ['status' => 'success', 'saved_count' => 24, 'run_time' => '2026-06-12 09:00:00'],
            ],
            'history_replay' => [],
            'source_date_evidence' => [
                'status' => 'target_date_present',
                'target_date' => '2026-06-12',
                'source_policy' => 'read_online_daily_data_aggregate_only',
                'platforms' => [
                    [
                        'platform' => 'ctrip',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 12,
                        'target_date_data_types' => ['business', 'traffic'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 12, 'data_types' => ['business', 'traffic']],
                        'date_relation' => 'target_date',
                    ],
                    [
                        'platform' => 'meituan',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 12,
                        'target_date_data_types' => ['business', 'traffic'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 12, 'data_types' => ['business', 'traffic']],
                        'date_relation' => 'target_date',
                    ],
                ],
            ],
            'data_quality' => ['status' => 'ok', 'checked_records' => 24, 'missing_count' => 0],
            'revenue_metric_evidence' => [
                'status' => 'ready',
                'metric_trust_keys' => [],
                'data_gap_codes' => [],
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
            ],
            'field_definitions' => [
                ['source' => 'ctrip', 'module' => 'business', 'fields' => [['field' => 'amount']]],
                ['source' => 'meituan', 'module' => 'business', 'fields' => [['field' => 'amount']]],
            ],
            'pending_actions' => [],
            'failure_reasons' => [],
        ]]);

        $rowsByKey = [];
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            $rowsByKey[$row['key']] = $row;
        }

        self::assertSame('proved', $rowsByKey['today_ota_collected']['status']);
        self::assertSame('warning', $rowsByKey['trusted_fields']['status']);
        self::assertSame(0, $rowsByKey['trusted_fields']['evidence']['metric_trust_key_count']);
        self::assertSame([], $rowsByKey['trusted_fields']['evidence']['metric_trust_keys']);
        self::assertTrue($rowsByKey['trusted_fields']['evidence']['metric_trust_required']);
        self::assertStringContainsString('metric_trust', $rowsByKey['trusted_fields']['next_action']);
        self::assertSame('warning', $rowsByKey['revenue_traffic_conversion']['status']);
        self::assertSame(0, $rowsByKey['revenue_traffic_conversion']['evidence']['metric_trust_key_count']);
        self::assertTrue($rowsByKey['revenue_traffic_conversion']['evidence']['metric_trust_required']);
        self::assertStringContainsString('metric_trust', $rowsByKey['revenue_traffic_conversion']['next_action']);

        $actionByCode = [];
        foreach ($payload['phase1_employee_questions']['next_required_actions'] as $action) {
            $actionByCode[$action['action_code']] = $action;
        }

        self::assertArrayHasKey('phase1_check_ctrip_revenue_metric_inputs', $actionByCode);
        self::assertArrayHasKey('phase1_check_meituan_revenue_metric_inputs', $actionByCode);
        self::assertContains('ctrip_metric_trust_missing', $actionByCode['phase1_check_ctrip_revenue_metric_inputs']['resolves_missing_codes']);
        self::assertContains('meituan_metric_trust_missing', $actionByCode['phase1_check_meituan_revenue_metric_inputs']['resolves_missing_codes']);
        self::assertContains('ctrip_metric_trust_missing', $actionByCode['phase1_check_ctrip_revenue_metric_inputs']['live_closure_gap_codes']);
        self::assertContains('meituan_metric_trust_missing', $actionByCode['phase1_check_meituan_revenue_metric_inputs']['live_closure_gap_codes']);
        self::assertSame('incomplete', $payload['phase1_employee_questions']['summary']['status']);
    }

    public function testPhase1EmployeeQuestionsUseReadOnlyOperationExecutionEvidence(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'full',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [
                ['status' => 'success', 'saved_count' => 24, 'run_time' => '2026-06-12 09:00:00'],
            ],
            'history_replay' => [],
            'source_date_evidence' => [
                'status' => 'target_date_present',
                'target_date' => '2026-06-12',
                'source_policy' => 'read_online_daily_data_aggregate_only',
                'platforms' => [
                    [
                        'platform' => 'ctrip',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 12,
                        'target_date_data_types' => ['business', 'traffic'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 12, 'data_types' => ['business', 'traffic']],
                        'date_relation' => 'target_date',
                    ],
                    [
                        'platform' => 'meituan',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 12,
                        'target_date_data_types' => ['business', 'traffic'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 12, 'data_types' => ['business', 'traffic']],
                        'date_relation' => 'target_date',
                    ],
                ],
            ],
            'data_quality' => ['status' => 'ok', 'checked_records' => 24, 'missing_count' => 0],
            'revenue_metric_evidence' => [
                'status' => 'ready',
                'metric_trust_keys' => ['totals.revenue', 'traffic.rows'],
                'data_gap_codes' => [],
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
            ],
            'field_definitions' => [
                ['source' => 'ctrip', 'module' => 'business', 'fields' => [['field' => 'amount']]],
                ['source' => 'meituan', 'module' => 'business', 'fields' => [['field' => 'amount']]],
            ],
            'pending_actions' => [],
            'failure_reasons' => [],
            'operation_execution_flow' => [
                'summary' => [
                    'total' => 1,
                    'stage_counts' => ['reviewed' => 1],
                ],
                'list' => [
                    [
                        'stage' => 'reviewed',
                        'recommendation' => [
                            'source_module' => 'ota_diagnosis',
                            'evidence' => [
                                'evidence_refs' => ['ota_diagnosis#1'],
                                'data_gaps' => [],
                                'action_item_id' => 'act-1',
                                'action_item_status' => 'ready',
                                'diagnosis_summary' => 'same-day OTA action',
                            ],
                        ],
                        'approval' => ['status' => 'approved'],
                        'execution' => ['status' => 'executed'],
                        'evidence' => ['count' => 1],
                        'review' => ['status' => 'success'],
                        'roi' => ['status' => 'ready'],
                    ],
                ],
                'data_status' => 'ok',
                'data_gaps' => [],
            ],
        ]]);

        $rowsByKey = [];
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            $rowsByKey[$row['key']] = $row;
        }

        self::assertSame('proved', $rowsByKey['next_operation_action']['status']);
        self::assertSame('proved', $rowsByKey['next_operation_action']['evidence']['operation_evidence_status']);
        self::assertSame('read_existing_operation_execution_state_only', $rowsByKey['next_operation_action']['evidence']['source_policy']);
        self::assertSame(1, $rowsByKey['next_operation_action']['evidence']['execution_intent_count']);
        self::assertSame(1, $rowsByKey['next_operation_action']['evidence']['ota_diagnosis_linked_intent_count']);
        self::assertSame(1, $rowsByKey['next_operation_action']['evidence']['ota_diagnosis_linked_flow_item_count']);
        self::assertSame(1, $rowsByKey['next_operation_action']['evidence']['approved_count']);
        self::assertSame(1, $rowsByKey['next_operation_action']['evidence']['executed_count']);
        self::assertSame(1, $rowsByKey['next_operation_action']['evidence']['evidence_ready_count']);
        self::assertSame(1, $rowsByKey['next_operation_action']['evidence']['reviewed_count']);
        self::assertSame(1, $rowsByKey['next_operation_action']['evidence']['roi_ready_count']);
        self::assertSame(5, $rowsByKey['next_operation_action']['evidence']['completion_signal_count']);
        self::assertSame([], $rowsByKey['next_operation_action']['evidence']['operation_blocking_missing_codes']);
        self::assertSame(
            $payload['phase1_employee_questions']['operation_execution_evidence']['completion_signal_count'],
            $rowsByKey['next_operation_action']['evidence']['completion_signal_count']
        );
        self::assertSame('proved', $payload['phase1_employee_questions']['summary']['operation_evidence_status']);
        self::assertFalse($payload['phase1_employee_questions']['operation_execution_evidence']['raw_data_exposed']);
    }

    public function testPhase1EmployeeQuestionsRejectUnlinkedOperationFlowAsClosedLoop(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'withPhase1EmployeeQuestions', [[
            'mode' => 'full',
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'hotel_id' => 1,
            'collection_logs' => [
                ['status' => 'success', 'saved_count' => 24, 'run_time' => '2026-06-12 09:00:00'],
            ],
            'history_replay' => [],
            'source_date_evidence' => [
                'status' => 'target_date_present',
                'target_date' => '2026-06-12',
                'source_policy' => 'read_online_daily_data_aggregate_only',
                'platforms' => [
                    [
                        'platform' => 'ctrip',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 12,
                        'target_date_data_types' => ['business', 'traffic'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 12, 'data_types' => ['business', 'traffic']],
                        'date_relation' => 'target_date',
                    ],
                    [
                        'platform' => 'meituan',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 12,
                        'target_date_data_types' => ['business', 'traffic'],
                        'latest_available' => ['date' => '2026-06-12', 'rows' => 12, 'data_types' => ['business', 'traffic']],
                        'date_relation' => 'target_date',
                    ],
                ],
            ],
            'data_quality' => ['status' => 'ok', 'checked_records' => 24, 'missing_count' => 0],
            'revenue_metric_evidence' => [
                'status' => 'ready',
                'metric_trust_keys' => ['totals.revenue', 'traffic.rows'],
                'data_gap_codes' => [],
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
            ],
            'field_definitions' => [
                ['source' => 'ctrip', 'module' => 'business', 'fields' => [['field' => 'amount']]],
                ['source' => 'meituan', 'module' => 'business', 'fields' => [['field' => 'amount']]],
            ],
            'pending_actions' => [],
            'failure_reasons' => [],
            'operation_execution_flow' => [
                'summary' => [
                    'total' => 1,
                    'stage_counts' => ['reviewed' => 1],
                ],
                'list' => [
                    [
                        'stage' => 'reviewed',
                        'recommendation' => [
                            'source_module' => 'manual',
                            'evidence' => [
                                'evidence_refs' => ['manual#1'],
                            ],
                        ],
                        'approval' => ['status' => 'approved'],
                        'execution' => ['status' => 'executed'],
                        'evidence' => ['count' => 1],
                        'review' => ['status' => 'success'],
                        'roi' => ['status' => 'ready'],
                    ],
                ],
                'data_status' => 'ok',
                'data_gaps' => [],
            ],
        ]]);

        $rowsByKey = [];
        foreach ($payload['phase1_employee_questions']['rows'] as $row) {
            $rowsByKey[$row['key']] = $row;
        }
        $operationEvidence = $rowsByKey['next_operation_action']['evidence'];

        self::assertSame('warning', $rowsByKey['next_operation_action']['status']);
        self::assertSame('warning', $operationEvidence['operation_evidence_status']);
        self::assertSame(1, $operationEvidence['execution_intent_count']);
        self::assertSame(1, $operationEvidence['execution_flow_item_count']);
        self::assertSame(0, $operationEvidence['ota_diagnosis_linked_intent_count']);
        self::assertSame(0, $operationEvidence['ota_diagnosis_linked_flow_item_count']);
        self::assertSame(0, $operationEvidence['approved_count']);
        self::assertSame(0, $operationEvidence['executed_count']);
        self::assertSame(0, $operationEvidence['evidence_ready_count']);
        self::assertSame(0, $operationEvidence['reviewed_count']);
        self::assertSame(0, $operationEvidence['roi_ready_count']);
        self::assertSame(0, $operationEvidence['completion_signal_count']);
        self::assertContains('operation_execution_ai_action_link_missing', $operationEvidence['operation_blocking_missing_codes']);
        self::assertContains('operation_execution_ai_action_link_missing', $operationEvidence['blocking_missing_codes']);

        $actionByCode = [];
        foreach ($payload['phase1_employee_questions']['next_required_actions'] as $action) {
            $actionByCode[$action['action_code']] = $action;
        }
        $operationAction = $actionByCode['phase1_create_operation_execution_evidence'];
        self::assertContains('operation_execution_ai_action_link_missing', $operationAction['resolves_missing_codes']);
        self::assertContains('operation_execution_ai_action_link_missing', $operationAction['live_closure_gap_codes']);
        self::assertFalse(in_array('operation_execution_ai_action_link_missing', $operationAction['blocked_by'], true));
        self::assertContains('ai_action_items_missing', $operationAction['blocked_by']);
        self::assertContains('source_module=ota_diagnosis 或 source=ota_diagnosis#action_item', $operationAction['evidence_needed']);
        self::assertStringContainsString('不改携程/美团采集字段和采集逻辑', $operationAction['protected_boundary']);
        self::assertSame('incomplete', $payload['phase1_employee_questions']['summary']['status']);
    }

    public function testDashboardDataSourcesExposePhase1EmployeeQuestions(): void
    {
        $controller = $this->controller();

        $dataSources = $this->invokeNonPublic($controller, 'buildDashboardDataSources', [[
            'period' => ['start_date' => '2026-06-12', 'end_date' => '2026-06-12', 'days' => 1],
            'authorization' => ['summary' => ['overall_status' => 'ok'], 'list' => []],
            'collection_logs' => [],
            'history_replay' => [],
            'source_date_evidence' => [
                'status' => 'target_date_missing',
                'target_date' => '2026-06-12',
                'source_policy' => 'read_online_daily_data_aggregate_only',
                'platforms' => [
                    [
                        'platform' => 'ctrip',
                        'target_date' => '2026-06-12',
                        'target_date_rows' => 0,
                        'target_date_data_types' => [],
                        'latest_available' => ['date' => '2026-06-11', 'rows' => 4, 'data_types' => ['business']],
                        'date_relation' => 'stale_before_target',
                    ],
                ],
            ],
            'data_quality' => ['status' => 'not_loaded', 'checked_records' => 0, 'missing_count' => 0],
            'field_definitions' => [],
            'pending_actions' => [],
            'failure_reasons' => [],
        ]]);

        self::assertArrayHasKey('phase1_employee_questions', $dataSources);
        self::assertArrayHasKey('source_date_evidence', $dataSources);
        self::assertArrayHasKey('collection_source_summary', $dataSources);
        self::assertArrayHasKey('operation_execution_evidence', $dataSources);
        self::assertSame($dataSources['collection_source_summary'], $dataSources['phase1_employee_questions']['collection_source_summary']);
        self::assertSame($dataSources['operation_execution_evidence'], $dataSources['phase1_employee_questions']['operation_execution_evidence']);
        self::assertSame('ctrip', $dataSources['collection_source_summary'][0]['platform']);
        self::assertSame('stale_before_target', $dataSources['collection_source_summary'][0]['latest_available']['date_relation']);
        self::assertTrue($dataSources['collection_source_summary'][0]['latest_available_reference_only']);
        self::assertSame('incomplete', $dataSources['phase1_employee_questions']['summary']['status']);
        self::assertSame($dataSources['phase1_employee_questions']['summary'], $dataSources['phase1_employee_questions']['closure_summary']);
        self::assertArrayHasKey('top_action_code', $dataSources['phase1_employee_questions']['closure_summary']);
        self::assertSame('read_existing_collection_reliability_only', $dataSources['phase1_employee_questions']['source_policy']);
        self::assertArrayHasKey('next_required_actions', $dataSources['phase1_employee_questions']);
        self::assertSame('ai_evidence', $dataSources['phase1_employee_questions']['rows'][4]['key']);
        self::assertSame('warning', $dataSources['phase1_employee_questions']['rows'][4]['status']);
    }

    public function testDashboardHotelPortraitContainsRequiredSections(): void
    {
        $controller = $this->controller();
        $reliability = [
            'period' => ['start_date' => '2026-05-03', 'end_date' => '2026-06-01', 'days' => 30],
            'authorization' => ['summary' => ['overall_status' => 'ok', 'total' => 1, 'ok' => 1], 'list' => []],
            'collection_logs' => [],
            'data_quality' => ['status' => 'ok', 'checked_records' => 1, 'issue_records' => 0, 'score' => 100],
            'pending_actions' => [],
            'failure_reasons' => [],
        ];
        $hotel = ['id' => 1, 'name' => 'A Hotel'];
        $qualityRows = [
            ['system_hotel_id' => 1, 'hotel_name' => 'A Hotel', 'source' => 'ctrip', 'data_type' => 'business', 'amount' => 0, 'quantity' => 2, 'book_order_num' => 1],
        ];

        $portrait = $this->invokeNonPublic($controller, 'buildDashboardHotelPortrait', [$reliability, $hotel, $qualityRows]);
        $sectionKeys = array_column($portrait['sections'], 'key');

        self::assertSame([
            'basic',
            'business',
            'traffic',
            'conversion',
            'price_inventory',
            'competitor',
            'review_service',
            'im',
            'ads',
            'customer',
            'data_health',
        ], $sectionKeys);
        self::assertSame('zero', $portrait['sections'][1]['metrics'][0]['state']);
        foreach ($portrait['sections'] as $section) {
            self::assertArrayHasKey('diagnostics', $section);
            foreach ($section['diagnostics'] as $diagnosis) {
                self::assertArrayHasKey('problem', $diagnosis);
                self::assertArrayHasKey('evidence', $diagnosis);
                self::assertArrayHasKey('impact', $diagnosis);
                self::assertArrayHasKey('action', $diagnosis);
            }
        }
    }

    public function testCtripCaptureCatalogHealthSummarizesCatalogAndFailedAudit(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'buildCtripCaptureCatalogHealth', [[
            'platform' => 'ctrip',
            'section_count' => 18,
            'endpoint_count' => 69,
            'field_count' => 107,
            'default_sections' => ['business_overview', 'business_weekly_overview', 'traffic_report'],
            'presets' => [
                'default' => ['sections' => ['business_overview', 'business_weekly_overview', 'traffic_report']],
                'wide' => ['sections' => ['homepage', 'biztravel_bpi']],
            ],
            'interaction_plan_section_count' => 16,
            'interaction_plan_step_count' => 64,
        ], [
            'auth_status' => ['status' => 'login_required'],
            'summary' => ['response_count' => 0, 'standard_row_count' => 0],
            'field_coverage' => ['coverage_rate' => null],
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['auth_session', 'field_coverage'],
            ],
            'capture_gap_report' => [
                'status' => 'blocked_auth',
                'blockers' => ['auth_session', 'response_count'],
                'missing_formal_endpoint_count' => 2,
                'missing_formal_endpoints' => [
                    ['id' => 'business_realtime', 'section' => 'business_overview'],
                    ['id' => 'traffic_flow_transform', 'section' => 'traffic_report'],
                ],
                'missing_fields_by_section' => [
                    'business_overview' => ['missing_field_count' => 3],
                    'traffic_report' => ['missing_field_count' => 2],
                ],
                'p3_candidate_sections' => [
                    'orders_detail' => ['count' => 1],
                ],
                'p3_evidence_sections' => [
                    'orders_detail' => ['status' => 'missing_evidence'],
                    'settlement_finance' => ['status' => 'missing_evidence'],
                ],
                'next_actions' => [
                    [
                        'action' => 'login_and_rerun_capture',
                        'reason' => 'login_required',
                        'section' => '',
                        'endpoint_id' => '',
                        'required_evidence' => ['logged-in browser profile'],
                    ],
                    [
                        'action' => 'capture_missing_formal_endpoint',
                        'reason' => 'missing_endpoint',
                        'section' => 'business_overview',
                        'endpoint_id' => 'business_realtime',
                        'required_evidence' => ['Request URL', 'Payload', 'Preview / Response'],
                    ],
                ],
            ],
        ]]);

        self::assertTrue($health['available']);
        self::assertSame('ctrip', $health['platform']);
        self::assertSame(18, $health['section_count']);
        self::assertSame(69, $health['endpoint_count']);
        self::assertSame(107, $health['field_count']);
        self::assertSame(['business_overview', 'business_weekly_overview', 'traffic_report'], $health['default_sections']);
        self::assertSame(['homepage', 'biztravel_bpi'], $health['wide_sections']);
        self::assertSame(16, $health['interaction_plan_section_count']);
        self::assertSame(64, $health['interaction_plan_step_count']);
        self::assertSame('fail', $health['capture_gate_status']);
        self::assertSame(['auth_session', 'field_coverage'], $health['failed_check_ids']);
        self::assertSame('login_required', $health['auth_status']);
        self::assertSame(0, $health['response_count']);
        self::assertSame(0, $health['standard_row_count']);
        self::assertNull($health['coverage_rate']);
        self::assertFalse($health['is_live_capture_ready']);
        self::assertSame('blocked_auth', $health['capture_gap_status']);
        self::assertSame(['auth_session', 'response_count'], $health['capture_gap_blockers']);
        self::assertSame(2, $health['capture_gap_missing_formal_endpoint_count']);
        self::assertSame(2, $health['capture_gap_missing_field_section_count']);
        self::assertSame(5, $health['capture_gap_missing_field_count']);
        self::assertSame(1, $health['capture_gap_p3_candidate_section_count']);
        self::assertSame(2, $health['capture_gap_p3_evidence_section_count']);
        self::assertSame('login_and_rerun_capture', $health['capture_gap_next_actions'][0]['action']);
        self::assertSame('capture_missing_formal_endpoint', $health['capture_gap_next_actions'][1]['action']);
        self::assertSame(['Request URL', 'Payload', 'Preview / Response'], $health['capture_gap_next_actions'][1]['required_evidence']);
        self::assertStringContainsString('未通过', $health['message']);
    }

    public function testCtripCaptureCatalogHealthUsesEffectiveDiagnosisSnapshotOverStaleAuthAudit(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'buildCtripCaptureCatalogHealth', [[
            'platform' => 'ctrip',
            'section_count' => 18,
            'endpoint_count' => 69,
            'field_count' => 107,
        ], [
            'auth_status' => ['status' => 'login_required'],
            'summary' => ['response_count' => 0, 'standard_row_count' => 0],
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['auth_session', 'response_count', 'standard_rows'],
            ],
            'capture_gap_report' => [
                'status' => 'blocked_auth',
                'blockers' => ['auth_session', 'response_count', 'standard_rows'],
                'next_actions' => [
                    ['action' => 'login_and_rerun_capture', 'reason' => 'login_required'],
                    ['action' => 'verify_standard_row_mapping', 'reason' => 'standard_row_count_zero'],
                ],
            ],
        ], [
            'available' => true,
            'source' => 'diagnosis_snapshot',
            'status' => 'ready',
            'generated_at' => '2026-06-06T01:30:00+08:00',
            'snapshot_path' => 'runtime/ctrip_capture/ctrip_63.diagnosis.snapshot.json',
            'counts' => [
                'responses' => 12,
                'standard_rows' => 8,
                'catalog_facts' => 20,
            ],
            'available_groups' => ['收益经营', '流量漏斗'],
            'missing_groups' => ['广告投放'],
            'diagnosis_summary' => [
                'status' => 'ready',
                'available_groups' => ['收益经营', '流量漏斗'],
                'missing_groups' => ['广告投放'],
            ],
        ]]);

        self::assertTrue($health['available']);
        self::assertTrue($health['is_live_capture_ready']);
        self::assertSame('snapshot_ready', $health['auth_status']);
        self::assertSame('snapshot_ready', $health['capture_gap_status']);
        self::assertSame('pass', $health['capture_gate_status']);
        self::assertSame(12, $health['response_count']);
        self::assertSame(8, $health['standard_row_count']);
        self::assertSame('login_required', $health['audit_evidence']['auth_status']);
        self::assertSame('blocked_auth', $health['audit_evidence']['capture_gap_status']);
        self::assertSame('fail', $health['audit_evidence']['capture_gate_status']);
        self::assertSame(['auth_session', 'response_count', 'standard_rows'], $health['audit_evidence']['capture_gap_blockers']);
        self::assertSame([], $health['capture_gap_blockers']);
        self::assertSame([], $health['capture_gap_next_actions']);
        self::assertSame('diagnosis_snapshot', $health['diagnosis_snapshot']['source']);
        self::assertSame('ready', $health['diagnosis_snapshot']['status']);
        self::assertStringContainsString('diagnosis snapshot', $health['message']);
    }

    public function testCtripCaptureCatalogHealthExposesMissingCatalogExplicitly(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'buildCtripCaptureCatalogHealth', [[], []]);

        self::assertFalse($health['available']);
        self::assertSame('ctrip', $health['platform']);
        self::assertSame('missing', $health['capture_gate_status']);
        self::assertSame('missing', $health['capture_gap_status']);
        self::assertSame([], $health['capture_gap_next_actions']);
        self::assertFalse($health['is_live_capture_ready']);
        self::assertStringContainsString('未生成', $health['message']);
    }

    public function testCtripCaptureCatalogHealthReadsProjectReports(): void
    {
        $controller = $this->controller();

        $health = $this->invokeNonPublic($controller, 'readCtripCaptureCatalogHealth');

        self::assertTrue($health['available']);
        self::assertSame('ctrip', $health['platform']);
        self::assertGreaterThanOrEqual(16, $health['section_count']);
        self::assertGreaterThanOrEqual(69, $health['endpoint_count']);
        self::assertGreaterThanOrEqual(107, $health['field_count']);
        self::assertArrayHasKey('audit_evidence', $health);
        self::assertSame('login_required', $health['audit_evidence']['auth_status']);
        self::assertSame('blocked_auth', $health['audit_evidence']['capture_gap_status']);
        if (!empty($health['diagnosis_snapshot_ready'])) {
            self::assertSame('pass', $health['capture_gate_status']);
            self::assertSame('snapshot_ready', $health['auth_status']);
            self::assertSame('diagnosis_snapshot', $health['capture_gate_status_source']);
            self::assertTrue($health['is_live_capture_ready']);
        } else {
            self::assertSame('fail', $health['capture_gate_status']);
            self::assertSame('login_required', $health['auth_status']);
            self::assertSame('blocked_auth', $health['capture_gap_status']);
            self::assertSame('login_and_rerun_capture', $health['capture_gap_next_actions'][0]['action']);
            self::assertFalse($health['is_live_capture_ready']);
        }
    }

    public function testCtripCaptureCatalogHealthReadsDiagnosisSnapshotReportOverAudit(): void
    {
        $controller = $this->controller();
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'ctrip_diagnosis_snapshot.json';
        $previous = is_file($path) ? file_get_contents($path) : null;
        $snapshot = [
            'status' => 'ready',
            'generated_at' => '2030-01-01T00:00:00+08:00',
            'counts' => [
                'responses' => 3,
                'catalog_facts' => 7,
                'standard_rows' => 2,
            ],
            'available_groups' => ['revenue'],
            'missing_groups' => [],
            'inputs' => [
                [
                    'path' => 'runtime/ctrip_capture/example.json',
                    'auth_status' => ['status' => 'logged_in'],
                    'counts' => ['standard_rows' => 2],
                ],
            ],
        ];

        try {
            file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

            $health = $this->invokeNonPublic($controller, 'readCtripCaptureCatalogHealth');

            self::assertTrue($health['diagnosis_snapshot_ready']);
            self::assertTrue($health['is_live_capture_ready']);
            self::assertSame('snapshot_ready', $health['auth_status']);
            self::assertSame('snapshot_ready', $health['capture_gap_status']);
            self::assertSame('reports/ctrip_diagnosis_snapshot.json', $health['diagnosis_snapshot']['source_path']);
            self::assertSame('login_required', $health['audit_evidence']['auth_status']);
        } finally {
            if ($previous === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $previous);
            }
        }
    }

    public function testCtripLatestBatchScopeUsesLatestFetchTimeWhenHotelIsSelected(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-05-18 16:54:51'],
            '7',
            ['system_hotel_id' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['where', 'update_time', '2026-05-18 16:54:51'],
        ], $query->calls);
    }

    public function testCtripLatestBatchScopeKeepsLatestSystemHotelAndFetchTimeWhenHotelIsEmpty(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-05-18 16:54:51'],
            '',
            ['system_hotel_id' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['where', 'system_hotel_id', 7],
            ['where', 'update_time', '2026-05-18 16:54:51'],
        ], $query->calls);
    }

    public function testCtripCompetitionCircleBatchKeyDoesNotCollapseHistoricalSnapshotsByBackfillTask(): void
    {
        $controller = $this->controller();
        $columns = [
            'sync_task_id' => true,
            'data_date' => true,
            'snapshot_time' => true,
            'update_time' => true,
            'system_hotel_id' => true,
        ];
        $base = [
            'sync_task_id' => 99,
            'system_hotel_id' => 7,
            'data_type' => 'competitor',
            'dimension' => 'competition_circle_hotel',
        ];

        $first = $this->invokeNonPublic($controller, 'ctripLatestBatchKey', [
            $base + ['data_date' => '2026-07-09', 'snapshot_time' => '2026-07-10 13:10:49', 'update_time' => '2026-07-10 13:10:49'],
            $columns,
            true,
        ]);
        $second = $this->invokeNonPublic($controller, 'ctripLatestBatchKey', [
            $base + ['data_date' => '2026-07-10', 'snapshot_time' => '2026-07-11 15:43:35', 'update_time' => '2026-07-11 15:43:35'],
            $columns,
            true,
        ]);

        self::assertNotSame($first, $second);
        self::assertStringContainsString('date:2026-07-10', $second);
        self::assertStringContainsString('time:2026-07-11 15:43:35', $second);
        self::assertStringContainsString('hotel:7', $second);
    }

    public function testCtripCompetitionCircleFallbackIsStrictlyScopedToCircleRows(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripCompetitionCircleFilter', [
            $query,
            ['data_type' => true, 'dimension' => true],
        ]);

        self::assertSame([
            ['where', 'data_type', 'competitor'],
            ['where', 'dimension', 'competition_circle_hotel'],
        ], $query->calls);
    }

    /**
     * 覆盖 normalizeOnlineDataDate/extractCtripCommentScore：
     * 验证日期输入兼容、非法值兜底、点评分数字段别名。
     */
    public function testMeituanCompetitorLatestBatchScopeUsesLatestFetchTimeWhenHotelIsSelected(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyMeituanCompetitorLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-06-06 18:20:00'],
            '7',
            ['system_hotel_id' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['whereBetween', 'update_time', ['2026-06-06 18:18:00', '2026-06-06 18:20:00']],
        ], $query->calls);
    }

    public function testMeituanCompetitorLatestBatchScopeKeepsLatestSystemHotelAndFetchTimeWhenHotelIsEmpty(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyMeituanCompetitorLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-06-06 18:20:00'],
            '',
            ['system_hotel_id' => true, 'update_time' => true],
        ]);

        self::assertSame([
            ['where', 'system_hotel_id', 7],
            ['whereBetween', 'update_time', ['2026-06-06 18:18:00', '2026-06-06 18:20:00']],
        ], $query->calls);
    }

    public function testMeituanCompetitorLatestBatchScopePrefersSyncTaskIdWhenAvailable(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyMeituanCompetitorLatestBatchScope', [
            $query,
            ['system_hotel_id' => 7, 'update_time' => '2026-06-06 18:20:00', 'sync_task_id' => 42],
            '7',
            ['system_hotel_id' => true, 'update_time' => true, 'sync_task_id' => true],
        ]);

        self::assertSame([
            ['where', 'sync_task_id', 42],
        ], $query->calls);
    }

    public function testOtaConfigListForUserKeepsOnlyPermittedHotelMappings(): void
    {
        $controller = $this->controller();
        $user = new class {
            public int $id = 12;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [7];
            }
        };

        $filtered = $this->invokeNonPublic($controller, 'filterOtaConfigListForUser', [[
            ['system_hotel_id' => 7, 'poi_id' => 'VISIBLE'],
            ['hotel_id' => 7, 'poi_id' => 'VISIBLE_LEGACY'],
            ['system_hotel_id' => 8, 'poi_id' => 'HIDDEN'],
            ['user_id' => 12, 'poi_id' => 'OWNED'],
        ], $user]);

        self::assertSame(['VISIBLE', 'VISIBLE_LEGACY'], array_column($filtered, 'poi_id'));
    }

    public function testOnlineDataQualityFlagsMissingAndAbnormalMetrics(): void
    {
        $controller = $this->controller();

        $quality = $this->invokeNonPublic($controller, 'buildOnlineDataQuality', [[
            'id' => 11,
            'source' => 'ctrip',
            'data_type' => 'business',
            'hotel_id' => 'ota-11',
            'hotel_name' => 'Hotel A',
            'data_date' => '2026-05-17',
            'amount' => 800,
            'quantity' => 0,
            'book_order_num' => 2,
            'comment_score' => 6.2,
            'raw_data' => json_encode([
                'hotelId' => 'ota-11',
                'hotelName' => 'Hotel A',
                'amount' => 800,
                'bookOrderNum' => 2,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('warning', $quality['status']);
        self::assertContains('quantity', array_column($quality['missing_metrics'], 'key'));
        self::assertContains('adr_denominator_zero', array_column($quality['abnormal_metrics'], 'code'));
        self::assertContains('comment_score_range', array_column($quality['abnormal_metrics'], 'code'));
        self::assertStringContainsString('缺失', $quality['summary']);
    }

    public function testOnlineDataQualityAcceptsCtripOrderNumAlias(): void
    {
        $controller = $this->controller();

        $quality = $this->invokeNonPublic($controller, 'buildOnlineDataQuality', [[
            'id' => 12,
            'source' => 'ctrip',
            'data_type' => 'business',
            'hotel_id' => 'ota-12',
            'hotel_name' => 'Hotel Alias',
            'data_date' => '2026-05-17',
            'amount' => 900,
            'quantity' => 3,
            'comment_score' => 4.7,
            'raw_data' => json_encode([
                'hotelId' => 'ota-12',
                'hotelName' => 'Hotel Alias',
                'amount' => 900,
                'quantity' => 3,
                'orderNum' => 2,
                'commentScore' => 4.7,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertNotContains('book_order_num', array_column($quality['missing_metrics'], 'key'));
    }

    public function testOnlineDataQualitySummaryCountsIssueRows(): void
    {
        $controller = $this->controller();

        $rows = [
            [
                'id' => 1,
                'source' => 'ctrip',
                'data_type' => 'business',
                'hotel_id' => 'ota-1',
                'hotel_name' => 'Hotel A',
                'data_date' => '2026-05-17',
                'amount' => 1000,
                'quantity' => 5,
                'book_order_num' => 3,
                'comment_score' => 4.8,
                'raw_data' => json_encode([
                    'hotelId' => 'ota-1',
                    'hotelName' => 'Hotel A',
                    'amount' => 1000,
                    'quantity' => 5,
                    'bookOrderNum' => 3,
                    'commentScore' => 4.8,
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 2,
                'source' => 'ctrip',
                'data_type' => 'business',
                'hotel_id' => '',
                'hotel_name' => 'Hotel B',
                'data_date' => '2026-05-17',
                'amount' => 500,
                'quantity' => 0,
                'book_order_num' => 1,
                'comment_score' => 4.6,
                'raw_data' => json_encode(['hotelName' => 'Hotel B', 'amount' => 500], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $summary = $this->invokeNonPublic($controller, 'buildOnlineDataQualitySummary', [$rows]);

        self::assertSame(2, $summary['checked_records']);
        self::assertSame(1, $summary['issue_records']);
        self::assertSame(1, $summary['ok_records']);
        self::assertGreaterThanOrEqual(2, $summary['missing_count']);
        self::assertGreaterThanOrEqual(1, $summary['abnormal_count']);
        self::assertSame('warning', $summary['status']);
        self::assertNotEmpty($summary['top_prompts']);
    }

    public function testOnlineDataDateAndCommentScoreNormalization(): void
    {
        $controller = $this->controller();

        self::assertSame('', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', [null]));
        self::assertSame('2026-05-18', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', ['20260518']));
        self::assertSame('2026-05-02', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', ['2026/5/2']));
        self::assertSame('2026-05-03', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', [strtotime('2026-05-03 00:00:00')]));
        self::assertSame('', $this->invokeNonPublic($controller, 'normalizeOnlineDataDate', ['not-a-date']));

        self::assertSame(4.8, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['rating' => '4.8']]));
        self::assertSame(4.0, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['score' => 40]]));
        self::assertSame(5.0, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['commentScore' => 100]]));
        self::assertSame(0.0, $this->invokeNonPublic($controller, 'extractCtripCommentScore', [['rating' => 'bad']]));
    }

    public function testCtripBrowserCapturePayloadExtractsGetCommentListRows(): void
    {
        $controller = $this->controller();

        $comments = $this->invokeNonPublic($controller, 'extractCtripCapturedComments', [[
            'reviews' => [[
                'review_id' => 'local-1',
                'content' => '本地浏览器归一化点评',
            ]],
            'responses' => [
                [
                    'url' => 'https://ebooking.ctrip.com/api/getCommentList',
                    'section' => 'reviews',
                    'data' => [
                        'data' => [
                            'commentList' => [[
                                'commentId' => 'api-1',
                                'score' => 40,
                                'commentContent' => '接口点评',
                            ]],
                        ],
                    ],
                ],
                [
                    'url' => 'https://ebooking.ctrip.com/api/other',
                    'data' => [
                        'data' => [
                            'commentList' => [[
                                'commentId' => 'skip-1',
                                'commentContent' => '非点评接口不应进入',
                            ]],
                        ],
                    ],
                ],
            ],
        ]]);

        self::assertCount(2, $comments);
        self::assertSame('local-1', $comments[0]['review_id']);
        self::assertSame('api-1', $comments[1]['commentId']);
    }

    public function testCtripAdsPayloadMapsToAdvertisingRows(): void
    {
        $controller = $this->controller();

        $ads = $this->invokeNonPublic($controller, 'extractCtripCapturedAds', [[
            'responses' => [[
                'url' => 'https://ebooking.ctrip.com/toolcenter/api/pyramidad/report',
                'section' => 'ads',
                'data' => [
                    'data' => [
                        'list' => [[
                            'campaignId' => 'ad-1',
                            'campaignName' => '金字塔计划',
                            'impressions' => 1000,
                            'clicks' => 50,
                            'orderNum' => 3,
                            'consume' => 188.5,
                            'statDate' => '2026-05-18',
                        ]],
                    ],
                ],
            ]],
        ]]);
        $rows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [$ads, [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'request_start_date' => '2026-05-12',
            'request_end_date' => '2026-05-18',
        ], 58]);

        self::assertCount(1, $rows);
        self::assertSame('advertising', $rows[0]['data_type']);
        self::assertSame('ctrip', $rows[0]['source']);
        self::assertSame('Ctrip', $rows[0]['platform']);
        self::assertSame(1000, $rows[0]['list_exposure']);
        self::assertSame(50, $rows[0]['detail_exposure']);
        self::assertSame(3, $rows[0]['book_order_num']);
        self::assertSame(188.5, $rows[0]['amount']);
    }

    public function testCtripAdsRowsDoNotUseProfileIdAsHotelId(): void
    {
        $controller = $this->controller();
        $ad = [
            'campaignId' => 'ad-identity',
            'impressions' => 10,
            'clicks' => 1,
            'consume' => 2,
            'statDate' => '2026-05-18',
        ];

        $rows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [[$ad], [
            'profile_id' => 'profile-58',
            'ctrip_hotel_id' => 'ctrip-58',
            'request_start_date' => '2026-05-18',
            'request_end_date' => '2026-05-18',
        ], 58]);

        self::assertCount(1, $rows);
        self::assertSame('ctrip-58', $rows[0]['hotel_id']);
        $raw = json_decode((string)$rows[0]['raw_data'], true);
        self::assertSame('ctrip-58', $raw['_capture_context']['hotel_id']);
        self::assertArrayNotHasKey('profile_id', $raw['_capture_context']);

        $profileOnlyRows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [[$ad], [
            'profile_id' => 'profile-58',
            'request_start_date' => '2026-05-18',
            'request_end_date' => '2026-05-18',
        ], 58]);

        self::assertCount(1, $profileOnlyRows);
        self::assertSame('', $profileOnlyRows[0]['hotel_id']);
        $profileOnlyRaw = json_decode((string)$profileOnlyRows[0]['raw_data'], true);
        self::assertArrayNotHasKey('hotel_id', $profileOnlyRaw['_capture_context']);
        self::assertArrayNotHasKey('profile_id', $profileOnlyRaw['_capture_context']);
    }

    public function testCtripAdsApiUrlOnlyAllowsPyramidadOrPromotion(): void
    {
        $controller = $this->controller();
        $defaultUrl = $this->invokeNonPublic($controller, 'defaultCtripAdsEffectReportUrl');

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/toolcenter/api/pyramidad/report',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/api/promotion/report',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE&v=0.8021101893559687',
        ]));
        self::assertStringContainsString('queryCampaignReportList', $defaultUrl);
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [$defaultUrl]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripAdsApiUrl', [
            'https://ebooking.ctrip.com/toolcenter/cpc/pyramid',
        ]));
    }

    public function testCtripAdsLastSevenDaysUsesSettledReportEndDate(): void
    {
        $controller = $this->controller();

        $beforeUpdate = $this->invokeNonPublic($controller, 'buildCtripAdsDateRange', [
            'last_7_days',
            '',
            '',
            strtotime('2026-05-20 02:44:00'),
        ]);
        self::assertSame(['2026-05-12', '2026-05-18'], $beforeUpdate);

        $afterUpdate = $this->invokeNonPublic($controller, 'buildCtripAdsDateRange', [
            'last_7_days',
            '',
            '',
            strtotime('2026-05-20 08:00:00'),
        ]);
        self::assertSame(['2026-05-13', '2026-05-19'], $afterUpdate);
    }

    public function testCtripAdsDirectPayloadAndChineseFieldsMapToMetrics(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'buildCtripAdsDirectPayload', [[
            'pageIndex' => 1,
        ], '2026-05-18', '2026-05-18', 'campaign_report']);

        self::assertSame('2026-05-18', $payload['startDate']);
        self::assertSame('2026-05-18', $payload['endDate']);
        self::assertSame('effect_report', $payload['apiType']);

        $ads = $this->invokeNonPublic($controller, 'extractCtripCapturedAds', [[
            'responses' => [[
                'url' => 'https://ebooking.ctrip.com/api/promotion/report',
                'section' => 'ads',
                'data' => [
                    'data' => [
                        'rows' => [[
                            '计划名称' => '中文广告计划',
                            '曝光量' => '1,200',
                            '点击量' => '60',
                            '成交数' => '4',
                            '消耗金额' => '¥240.50',
                            '统计日期' => '2026-05-18',
                        ]],
                    ],
                ],
            ]],
        ]]);
        $rows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [$ads, [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'request_start_date' => '2026-05-12',
            'request_end_date' => '2026-05-18',
        ], 58]);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripAdRows', [$rows]);

        self::assertCount(1, $rows);
        self::assertSame(1200, $rows[0]['list_exposure']);
        self::assertSame(60, $rows[0]['detail_exposure']);
        self::assertSame(4, $rows[0]['book_order_num']);
        self::assertSame(240.5, $rows[0]['amount']);
        self::assertSame(1200, $metrics['exposure']);
        self::assertSame(60, $metrics['clicks']);
        self::assertSame(4, $metrics['orders']);
        self::assertSame(240.5, $metrics['cost']);
        self::assertSame(5.0, $metrics['click_rate']);
    }

    public function testCtripCpcCampaignReportRecordsMapToAdMetrics(): void
    {
        $controller = $this->controller();

        $ads = $this->invokeNonPublic($controller, 'extractCtripCapturedAds', [[
            'responses' => [[
                'url' => 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE',
                'data' => [
                    'code' => 0,
                    'message' => 'success',
                    'data' => [
                        'records' => [[
                            'campaignId' => null,
                            'impressions' => 16511,
                            'clicks' => 748,
                            'ctr' => 0.0453,
                            'ctrStr' => '4.53%',
                            'todayCost' => 1714.78,
                            'bonusCost' => 856.09,
                            'cashCost' => 858.69,
                            'bookings' => 19,
                            'nights' => 37,
                            'orderAmount' => 29282,
                            'roas' => 17.08,
                            'effectTime' => '2026-05-12',
                        ]],
                        'totalRecords' => 1,
                    ],
                ],
            ]],
        ]]);
        $rows = $this->invokeNonPublic($controller, 'buildCtripCapturedAdRows', [$ads, [
            'hotel_id' => 'ctrip-58',
            'hotel_name' => 'Ctrip Hotel',
            'request_start_date' => '2026-05-12',
            'request_end_date' => '2026-05-18',
        ], 58]);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripAdRows', [$rows]);

        self::assertCount(1, $rows);
        self::assertSame(16511, $rows[0]['list_exposure']);
        self::assertSame(748, $rows[0]['detail_exposure']);
        self::assertSame(19, $rows[0]['book_order_num']);
        self::assertSame(37, $rows[0]['quantity']);
        self::assertSame('2026-05-12', $rows[0]['data_date']);
        self::assertSame(1714.78, $rows[0]['amount']);
        self::assertSame(16511, $metrics['exposure']);
        self::assertSame(748, $metrics['clicks']);
        self::assertSame(19, $metrics['orders']);
        self::assertSame(1714.78, $metrics['cost']);
        self::assertSame(4.53, $metrics['click_rate']);

        $raw = json_decode((string)$rows[0]['raw_data'], true);
        self::assertSame(29282, $raw['orderAmount']);
        self::assertSame(17.08, $raw['roas']);
        self::assertSame('2026-05-12', $raw['_capture_context']['request_start_date']);
        self::assertSame('2026-05-18', $raw['_capture_context']['request_end_date']);
    }

    public function testCtripOverviewRowsPreserveRequestedMetrics(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'collectCtripOverviewRows', [[
            'business' => [[
                'hotelName' => 'Ctrip Hotel',
                '昨日UV' => 23,
                '订单数' => 9,
                '成交收入' => '8,709',
                '成交间夜' => 13,
                '均价' => 669.92,
                '成交率' => '92.86%',
                '竞品UV' => 30,
                '竞品订单数' => 12,
                '竞品收入' => '10,000',
                'PSI' => 81,
                '回复率' => '98.5%',
                '收藏数' => 7,
                '访客排名' => 12,
            ]],
        ], 'ctrip-58', '2026-05-18']);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripOverviewRows', [$rows]);

        self::assertCount(1, $rows);
        self::assertSame('ctrip-58', $rows[0]['hotelId']);
        self::assertSame('2026-05-18', $rows[0]['dataDate']);
        self::assertSame(23, $metrics['yesterday_uv']);
        self::assertSame(9, $metrics['order_count']);
        self::assertSame(8709.0, $metrics['amount']);
        self::assertSame(13, $metrics['room_nights']);
        self::assertSame(669.92, $metrics['avg_price']);
        self::assertSame(92.86, $metrics['conversion_rate']);
        self::assertSame(30, $metrics['competitor_uv']);
        self::assertSame(12, $metrics['competitor_orders']);
        self::assertSame(10000.0, $metrics['competitor_amount']);
        self::assertSame(81.0, $metrics['psi']);
        self::assertSame(98.5, $metrics['reply_rate']);
        self::assertSame(7, $metrics['favorite_count']);
        self::assertSame(12, $metrics['visitor_rank']);
    }

    public function testCtripOverviewRowsMapMarketFlowServiceAndFunnelResponses(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'collectCtripOverviewRows', [[
            'responses' => [
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/sale/fetchMarketOverViewV2',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'amount' => 8709.00,
                            'quantity' => 13,
                            'closeRate' => 92.86,
                            'averagePrice' => 669.92,
                            'bookOrderNum' => 0,
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportFlowCompete',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'masterhotelid' => 134396668,
                            'ordquantity' => 819,
                            'comhtluv' => 15275,
                            'ordamount' => 752689.08,
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportServerQuantity',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'serviceScore' => 4.92,
                            'ctripRatingall' => 5.0,
                            'replyrate5m' => 87.5,
                            'hotelCollect' => 247,
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
                    'data' => [
                        [
                            'date' => '2026-05-18',
                            'listExposure' => 701,
                            'detailExposure' => 151,
                            'flowRate' => 21.54,
                            'orderFillingNum' => 2,
                            'orderSubmitNum' => 0,
                            'hotelId' => 134396668,
                        ],
                        [
                            'date' => '2026-05-18',
                            'listExposure' => 318,
                            'detailExposure' => 67,
                            'flowRate' => 22.12,
                            'orderFillingNum' => 5,
                            'orderSubmitNum' => 2,
                            'hotelId' => -1,
                        ],
                    ],
                ],
            ],
        ], '134396668', '2026-05-18']);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripOverviewRows', [$rows]);

        self::assertCount(1, $rows);
        self::assertSame('134396668', $rows[0]['hotelId']);
        self::assertSame(8709.0, $metrics['amount']);
        self::assertSame(13, $metrics['room_nights']);
        self::assertSame(669.92, $metrics['avg_price']);
        self::assertSame(92.86, $metrics['conversion_rate']);
        self::assertSame(15275, $metrics['competitor_uv']);
        self::assertSame(819, $metrics['competitor_orders']);
        self::assertSame(752689.08, $metrics['competitor_amount']);
        self::assertSame(4.92, $metrics['psi']);
        self::assertSame(5.0, $metrics['hotel_score']);
        self::assertSame(87.5, $metrics['reply_rate']);
        self::assertSame(247, $metrics['favorite_count']);
        self::assertSame(701, $metrics['self_list_exposure']);
        self::assertSame(151, $metrics['self_detail_exposure']);
        self::assertSame(2, $metrics['self_order_filling_num']);
        self::assertSame(0, $metrics['self_order_submit_num']);
        self::assertSame(21.54, $metrics['self_flow_rate']);
        self::assertSame(1.32, $metrics['self_order_fill_rate']);
        self::assertSame(0.0, $metrics['self_deal_rate']);
        self::assertSame(318, $metrics['competitor_list_exposure']);
        self::assertSame(67, $metrics['competitor_detail_exposure']);
        self::assertSame(5, $metrics['competitor_order_filling_num']);
        self::assertSame(2, $metrics['competitor_order_submit_num']);
        self::assertSame(21.07, $metrics['competitor_flow_rate']);
        self::assertSame(7.46, $metrics['competitor_order_fill_rate']);
        self::assertSame(40.0, $metrics['competitor_deal_rate']);
    }

    public function testCtripOverviewRowsMapRankingHotListsWeeklyAndTrafficReports(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'collectCtripOverviewRows', [[
            'responses' => [
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            ['hotelId' => 664563, 'hotelName' => '竞品A', 'amount' => 6, 'quantity' => 2, 'bookOrderNum' => 3, 'commentScore' => 14, 'totalDetailNum' => 8, 'convertionRate' => 1],
                            ['hotelId' => 134396668, 'hotelName' => '我的酒店', 'amount' => 8, 'quantity' => 8, 'bookOrderNum' => 6, 'commentScore' => 1, 'totalDetailNum' => 7, 'convertionRate' => 11],
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotWordsV1',
                    'data' => ['rcode' => 0, 'data' => ['敦煌夜市', '5钻/星|豪华']],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotHotelsV1',
                    'data' => ['rcode' => 0, 'data' => ['敦煌中洲国际酒店(敦煌夜市店)', '敦煌福朋喜来登酒店']],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getFlowHotelsV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'flowHotelItemVos' => [
                                ['hotelName' => '敦煌山庄', 'proportion' => '31.08%', 'orderPro' => '2.51%', 'masterHotelId' => 439474],
                            ],
                            'lossOrderVo' => ['ordernum' => 535, 'ordquantity' => 1035.0, 'ordamount' => 784911.01],
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotRoomsV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'hotRooms' => [
                                ['roomName' => '景观大床房', 'roomShortName' => '景观大床房', 'saleRoomNights' => 27, 'salePercent' => '42.19%'],
                            ],
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getUserBehavorV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'lastWeekCommentScore' => 5.0,
                            'lastWeekGoodAdd' => 0,
                            'lastWeekBadAdd' => 0,
                            'lastWeekPriceScore' => 0.28,
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getTrafficReportV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'myHotel' => ['totalListExposure' => 11192, 'listTransforDetailRate' => '17%', 'totalDetailExposure' => 1893, 'detailTransforOrderFillRate' => '2%', 'orderFillingNum' => 38, 'orderFillTransforOrderSubmitRate' => '53%', 'orderSubmitNum' => 20],
                            'competeHotelAvg' => ['totalListExposure' => 6040, 'listTransforDetailRate' => '23%', 'totalDetailExposure' => 1390, 'detailTransforOrderFillRate' => '5%', 'orderFillingNum' => 71, 'orderFillTransforOrderSubmitRate' => '59%', 'orderSubmitNum' => 42],
                            'topCompeteHotel' => ['totalListExposure' => 10440, 'listTransforDetailRate' => '19%', 'totalDetailExposure' => 2014, 'detailTransforOrderFillRate' => '8%', 'orderFillingNum' => 168, 'orderFillTransforOrderSubmitRate' => '76%', 'orderSubmitNum' => 128],
                        ],
                    ],
                ],
                [
                    'section' => 'business',
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getLastWeekReportV1',
                    'data' => [
                        'rcode' => 0,
                        'data' => [
                            'lastWeekCheckoutRoomNights' => 44,
                            'lastWeekCheckoutSales' => 31132.82,
                            'lastWeekCheckoutRoomPrice' => 707.56,
                            'lastWeekBookQuantity' => 98,
                            'lastWeekBookRoomNights' => 144,
                            'lastWeekBookSales' => 103008.94,
                        ],
                    ],
                ],
            ],
        ], '134396668', '2026-05-18']);
        $metrics = $this->invokeNonPublic($controller, 'summarizeCtripOverviewRows', [$rows]);
        $rawRows = $rows[0]['_overview_rows'] ?? [];

        self::assertCount(1, $rows);
        self::assertSame('134396668', $rows[0]['hotelId']);
        self::assertSame(2, $metrics['compete_hotel_count']);
        self::assertSame(8, $metrics['amount_rank']);
        self::assertSame(8, $metrics['quantity_rank']);
        self::assertSame(6, $metrics['book_order_num_rank']);
        self::assertSame(1, $metrics['comment_score_rank']);
        self::assertSame(7, $metrics['visitor_rank']);
        self::assertSame(11, $metrics['conversion_rank']);
        self::assertSame(['敦煌夜市', '5钻/星|豪华'], $metrics['top_hot_words']);
        self::assertSame(['敦煌中洲国际酒店(敦煌夜市店)', '敦煌福朋喜来登酒店'], $metrics['top_hot_hotels']);
        self::assertSame(535, $metrics['flow_lost_order_num']);
        self::assertSame(1035, $metrics['flow_lost_room_nights']);
        self::assertSame(784911.01, $metrics['flow_lost_amount']);
        self::assertSame('敦煌山庄', $metrics['top_flow_hotel']);
        self::assertSame(31.08, $metrics['top_flow_hotel_browse_rate']);
        self::assertSame('景观大床房', $metrics['top_hot_room']);
        self::assertSame(27, $metrics['top_hot_room_nights']);
        self::assertSame(42.19, $metrics['top_hot_room_sale_percent']);
        self::assertSame(5.0, $metrics['last_week_comment_score']);
        self::assertSame(0.28, $metrics['last_week_price_score']);
        self::assertSame(44, $metrics['last_week_checkout_room_nights']);
        self::assertSame(31132.82, $metrics['last_week_checkout_sales']);
        self::assertSame(98, $metrics['last_week_book_quantity']);
        self::assertSame(103008.94, $metrics['last_week_book_sales']);
        self::assertSame(11192, $metrics['weekly_self_list_exposure']);
        self::assertSame(17.0, $metrics['weekly_self_flow_rate']);
        self::assertSame(6040, $metrics['weekly_competitor_list_exposure']);
        self::assertSame(10440, $metrics['top_competitor_list_exposure']);
        self::assertSame(76.0, $metrics['top_competitor_deal_rate']);
        self::assertCount(8, $rawRows);
    }

    public function testCtripOverviewDirectApiValidationAndPayloadDefaults(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/api/fetchMarketOverViewV2',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/api/fetchCurrentHotelSeqInfoV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/queryScanFlowDetailsV2',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/queryHomePageRealTimeData',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/inland/businessreport/flowdata/getTrafficData',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getReportSuggestV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getWeekSuggestionV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getUserBehavorV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getHotWordsV1',
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getTrafficReportV1',
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripOverviewApiUrl', [
            'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true',
        ]));

        $urls = $this->invokeNonPublic($controller, 'normalizeCtripOverviewRequestUrls', [
            " https://ebooking.ctrip.com/api/getDayReportRealTimeDate\nhttps://ebooking.ctrip.com/api/fetchCapacityOverViewV4 ",
        ]);
        self::assertCount(2, $urls);

        $payload = $this->invokeNonPublic($controller, 'buildCtripOverviewRequestPayload', [[
            'pageIndex' => 1,
        ], 'ctrip-58', '2026-05-18']);
        self::assertSame('2026-05-18', $payload['dataDate']);
        self::assertSame('2026-05-18', $payload['startDate']);
        self::assertSame('2026-05-18', $payload['endDate']);
        self::assertSame('ctrip-58', $payload['hotelId']);
        self::assertSame('ctrip-58', $payload['nodeId']);

        $inferred = $this->invokeNonPublic($controller, 'inferCtripOverviewHotelIdFromResponses', [[
            ['data' => ['data' => ['masterhotelid' => 134396668]]],
        ], '7']);
        self::assertSame('134396668', $inferred);

        $fallback = $this->invokeNonPublic($controller, 'inferCtripOverviewHotelIdFromResponses', [[
            ['data' => ['data' => ['敦煌夜市', '5钻/星|豪华']]],
        ], '7']);
        self::assertSame('7', $fallback);
    }

    public function testCtripOverviewExecutionEvidenceReturnsSafeRequestAndResponseSummaries(): void
    {
        $controller = $this->controller();
        $url = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate';
        $evidence = $this->invokeNonPublic($controller, 'summarizeCtripOverviewExecutionEvidence', [[
            $url,
        ], [[
            'url' => $url,
            'status' => 200,
            'request_type' => 'post',
            'headers' => ['Cookie' => 'secret'],
        ]], [[
            'url' => $url,
            'status' => 200,
            'request_type' => 'post',
            'data' => ['secret' => 'response body'],
        ]]]);

        self::assertSame([$url], $evidence['request_urls']);
        self::assertSame([[
            'url' => $url,
            'status' => 200,
            'request_type' => 'post',
        ]], $evidence['xhr_urls']);
        self::assertSame([[
            'url' => $url,
            'status' => 200,
            'request_type' => 'post',
        ]], $evidence['responses']);
        self::assertArrayNotHasKey('headers', $evidence['xhr_urls'][0]);
        self::assertArrayNotHasKey('data', $evidence['responses'][0]);
    }

    public function testMeituanCapturedRowsMapBrowserSectionsToOnlineDailyData(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'store_id' => 'store-7',
            'poi_id' => 'poi-99',
            'poi_name' => 'Meituan Hotel',
            'reviews' => [[
                'review_id' => 'review-1',
                'score' => 40,
                'content' => 'room issue',
                'reply' => '',
                'is_negative' => true,
                'review_time' => '2026-05-18 09:30:00',
            ]],
            'traffic' => [[
                'date' => '2026-05-18',
                'exposure_count' => 1000,
                'page_views' => 180,
                'click_count' => 120,
                'unique_visitors' => 80,
                'mt_pay_orders' => 12,
                'mt_pay_rooms' => 9,
                'conversion_rate' => '12.5%',
                'search_rank' => 3,
                'keyword_rank_data' => ['hotel' => 2],
            ]],
            'ads' => [[
                'date' => '2026-05-18',
                'exposure_count' => 500,
                'click_count' => 50,
                'cost' => 88.5,
                'orderAmount' => 300,
                'orderNum' => 2,
                'conversion_rate' => 0.1,
                'keyword_rank_data' => ['cureShops' => true],
            ]],
            'orders' => [[
                'order_id' => 'order-1',
                'order_status' => 'confirmed',
                'room_count' => 2,
                'nights' => 3,
                'total_amount' => 688,
                'avg_price' => 344,
                'order_time' => '2026-05-17 20:00:00',
            ]],
        ], 99]);

        self::assertCount(4, $rows);
        self::assertContains('review', array_column($rows, 'data_type'));

        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame(1000, $rows[0]['list_exposure']);
        self::assertSame(180, $rows[0]['detail_exposure']);
        self::assertSame(12.5, $rows[0]['flow_rate']);
        self::assertSame(120, $rows[0]['order_filling_num']);
        self::assertSame(9, $rows[0]['quantity']);
        self::assertSame(12, $rows[0]['book_order_num']);
        self::assertSame(12, $rows[0]['order_submit_num']);
        self::assertStringContainsString('"unique_visitors":80', $rows[0]['raw_data']);
        self::assertStringContainsString('"mt_pay_orders":12', $rows[0]['raw_data']);

        self::assertSame('review', $rows[1]['data_type']);
        self::assertSame('2026-05-18', $rows[1]['data_date']);
        self::assertSame(4.0, $rows[1]['comment_score']);
        self::assertNull($rows[1]['quantity']);
        self::assertStringNotContainsString('review-1', (string)$rows[1]['raw_data']);
        self::assertStringNotContainsString('room issue', (string)$rows[1]['raw_data']);

        self::assertSame('advertising', $rows[2]['data_type']);
        self::assertSame(500, $rows[2]['list_exposure']);
        self::assertSame(50, $rows[2]['detail_exposure']);
        self::assertSame(88.5, $rows[2]['amount']);
        self::assertNull($rows[2]['quantity']);
        self::assertSame(2, $rows[2]['book_order_num']);
        self::assertSame(2, $rows[2]['order_submit_num']);
        self::assertSame(10.0, $rows[2]['flow_rate']);
        self::assertStringContainsString('"order_amount":300', (string)$rows[2]['raw_data']);

        self::assertSame('order', $rows[3]['data_type']);
        self::assertSame(688.0, $rows[3]['amount']);
        self::assertSame(6, $rows[3]['quantity']);
        self::assertNull($rows[3]['book_order_num']);
        self::assertStringNotContainsString('order-1', (string)$rows[3]['dimension']);
        self::assertMatchesRegularExpression('/^order:confirmed:[a-f0-9]{64}$/', (string)$rows[3]['dimension']);
        self::assertStringNotContainsString('order-1', (string)$rows[3]['raw_data']);
    }

    public function testMeituanAdsKeepIndependentCampaignRowsAndVerifyMetricReadback(): void
    {
        $controller = $this->controller();
        $rows = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [[
            'poi_id' => 'poi-99',
            'poi_name' => 'Hotel A',
            'ads' => [
                [
                    'campaignId' => 'campaign-1',
                    'planId' => 'plan-1',
                    'date' => '2026-07-13',
                    'cost' => 88.505,
                    'click_count' => 50,
                    'orderNum' => 2,
                ],
                [
                    'campaignId' => 'campaign-1',
                    'planId' => 'plan-2',
                    'date' => '2026-07-13',
                    'cost' => 40,
                    'click_count' => 20,
                    'orderNum' => 1,
                ],
            ],
        ], 99]);

        self::assertCount(2, $rows);
        self::assertCount(2, array_unique(array_column($rows, 'dimension')));
        $uniqueRows = $this->invokeNonPublic(
            $controller,
            'uniqueMeituanCapturedRowsForPersistence',
            [[$rows[0], $rows[0], $rows[1]]]
        );
        self::assertCount(2, $uniqueRows);
        $deduplicatedPersistenceState = $this->invokeNonPublic(
            $controller,
            'buildMeituanDirectPersistenceState',
            [true, count($uniqueRows), count($uniqueRows), 'meituan_ads']
        );
        self::assertTrue($deduplicatedPersistenceState['persisted']);
        self::assertSame('readback_verified', $deduplicatedPersistenceState['persistence_status']);
        self::assertMatchesRegularExpression('/^ads:identity:[a-f0-9]{24}$/', $rows[0]['dimension']);
        self::assertStringNotContainsString('campaign-1', $rows[0]['dimension']);
        self::assertTrue($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$rows[0], $rows[0]]
        ));

        $wrongAmount = $rows[0];
        $wrongAmount['amount'] = 0;
        self::assertFalse($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$wrongAmount, $rows[0]]
        ));

        $persistedZeroQuantity = $rows[0];
        $persistedZeroQuantity['quantity'] = 0;
        self::assertNull($rows[0]['quantity']);
        self::assertFalse($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$persistedZeroQuantity, $rows[0]]
        ));

        $roundedByDatabase = $rows[0];
        $roundedByDatabase['amount'] = 88.51;
        self::assertTrue($this->invokeNonPublic(
            $controller,
            'meituanCapturedRowMatchesReadback',
            [$roundedByDatabase, $rows[0]]
        ));
    }

    public function testMeituanAdsWithoutStableIdUseContentFingerprintInsteadOfBatchIndex(): void
    {
        $controller = $this->controller();
        $payload = [
            'poi_id' => 'poi-99',
            'poi_name' => 'Hotel A',
            'ads' => [[
                'date' => '2026-07-13',
                'cost' => 30,
                'click_count' => 5,
            ]],
        ];
        $first = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [$payload, 99]);
        $second = $this->invokeNonPublic($controller, 'buildMeituanCapturedDailyRows', [$payload, 99]);

        self::assertSame($first[0]['dimension'], $second[0]['dimension']);
        self::assertMatchesRegularExpression('/^ads:unidentified:[a-f0-9]{24}$/', $first[0]['dimension']);
        self::assertStringContainsString('"ad_identity_status":"missing_stable_id"', (string)$first[0]['raw_data']);
    }

    public function testOnlineDailyDataValidationFieldsMarkAbnormalRows(): void
    {
        $controller = $this->controller();

        $normal = $this->invokeNonPublic($controller, 'buildOnlineDailyDataValidationFields', [[
            'source' => 'ctrip',
            'hotel_id' => '1001',
            'data_date' => '2026-05-17',
            'amount' => 1000,
            'quantity' => 5,
        ]]);
        self::assertSame('normal', $normal['validation_status']);
        self::assertSame([], json_decode($normal['validation_flags'], true));

        $abnormal = $this->invokeNonPublic($controller, 'buildOnlineDailyDataValidationFields', [[
            'source' => 'ctrip',
            'hotel_id' => '',
            'data_date' => '2026-05-17',
            'amount' => 1000,
            'quantity' => -1,
        ]]);
        self::assertSame('abnormal', $abnormal['validation_status']);
        $flags = json_decode($abnormal['validation_flags'], true);
        self::assertContains('hotel_id', array_column($flags, 'field'));
        self::assertContains('quantity', array_column($flags, 'field'));
    }

    public function testCtripProfilePrefersExistingSystemHotelProfileOverNodeId(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $profileId = 'phpunit_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId;

        if (!is_dir($profileDir)) {
            self::assertTrue(mkdir($profileDir, 0775, true));
        }

        try {
            $resolved = $this->invokeNonPublic($controller, 'ctripProfileStoreIdFromConfig', [[
                'node_id' => 'node-should-not-win',
                'system_hotel_id' => $profileId,
            ], 0]);

            self::assertSame($profileId, $resolved);
        } finally {
            if (is_dir($profileDir)) {
                @rmdir($profileDir);
            }
        }
    }

    public function testCtripProfileCanResolveOtaHotelIdWhenProfileIdMissing(): void
    {
        $controller = $this->controller();

        $resolved = $this->invokeNonPublic($controller, 'ctripProfileStoreIdFromConfig', [[
            'ota_hotel_id' => 'ctrip-ota-24588',
            'node_id' => 'node-24588',
            'system_hotel_id' => '7',
        ], 0]);

        self::assertSame('ctrip-ota-24588', $resolved);
    }

    public function testCtripPlatformHotelIdPrefersMasterHotelIdForOwnership(): void
    {
        $controller = $this->controller();

        self::assertSame('6866634', $this->invokeNonPublic($controller, 'resolveCtripPlatformHotelId', [[
            'hotelId' => 'node-should-not-win',
            'masterHotelId' => 6866634,
        ]]));
        self::assertSame('6866634', $this->invokeNonPublic($controller, 'resolveCtripPlatformHotelId', [[
            'hotel_id' => 'legacy-24588',
            'master_hotel_id' => '6866634',
        ]]));
        self::assertSame('fallback-1', $this->invokeNonPublic($controller, 'resolveCtripPlatformHotelId', [[], 'fallback-1']));
    }

    public function testCtripRankOnlyBusinessItemDetectsRankingEndpoints(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripRankOnlyBusinessItem', [[
            'hotelId' => '6866634',
            'amount' => 7,
            'quantity' => 2,
            'bookOrderNum' => 3,
            '_source_url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canSaveCtripLegacyBusinessMetricItem', [[
            'hotelId' => '6866634',
            'amount' => 7,
            'quantity' => 2,
            'bookOrderNum' => 3,
            '_source_url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getCompeteHotelReportV1',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripRankOnlyBusinessItem', [[
            'masterHotelId' => '6866634',
            'bookingOrdersrank' => 18,
            'bookingGMVrank' => 7,
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canSaveCtripLegacyBusinessMetricItem', [[
            'hotelId' => '6866634',
            'amount' => 123,
            'quantity' => 4,
            'bookOrderNum' => 2,
            '_source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24588/unknownNewApi',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripRankOnlyBusinessItem', [[
            'hotelId' => '6866634',
            'amount' => 33856.25,
            'quantity' => 137,
            'bookOrderNum' => 72,
            '_source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'canSaveCtripLegacyBusinessMetricItem', [[
            'hotelId' => '6866634',
            'amount' => 33856.25,
            'quantity' => 137,
            'bookOrderNum' => 72,
            '_source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData',
        ]]));
    }

    public function testCtripCompetitionCircleRowsBypassLegacyBusinessPersistence(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'canSaveCtripLegacyBusinessMetricItem', [[
            'hotelId' => '130079194',
            'hotelName' => '我的酒店',
            'amount' => 1244.52,
            'quantity' => 2,
            'bookOrderNum' => 4,
            'amountRank' => 25,
            'quantityRank' => 20,
            'bookOrderNumRank' => 16,
        ]]));
    }

    public function testCtripProfileStatusReportsReusableProfileWithoutLeakingCookie(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $profileId = 'phpunit_status_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId;

        if (!is_dir($profileDir)) {
            self::assertTrue(mkdir($profileDir, 0775, true));
        }

        try {
            $status = $this->invokeNonPublic($controller, 'buildCtripProfileStatus', [[
                'profile_id' => $profileId,
            ], null, false]);

            self::assertSame($profileId, $status['profile_id']);
            self::assertTrue($status['exists']);
            self::assertSame('storage/ctrip_profile_' . $profileId, $status['profile_dir']);
            self::assertFalse($status['cookie_probe_requested']);
            self::assertFalse($status['cookie_extractable']);
            self::assertSame(0, $status['cookie_count']);
            self::assertArrayNotHasKey('cookie', $status);
            self::assertArrayNotHasKey('cookies', $status);
        } finally {
            if (is_dir($profileDir)) {
                @rmdir($profileDir);
            }
        }
    }

    public function testCtripProfileStatusExposesMissingProfileNextAction(): void
    {
        $controller = $this->controller();
        $profileId = 'missing_' . bin2hex(random_bytes(4));

        $status = $this->invokeNonPublic($controller, 'buildCtripProfileStatus', [[
            'profile_id' => $profileId,
        ], null, false]);

        self::assertSame($profileId, $status['profile_id']);
        self::assertFalse($status['exists']);
        self::assertSame('missing_profile', $status['status']);
        self::assertStringContainsString('Profile', $status['next_action']);
    }

    public function testAutoFetchModeNormalizationSupportsExplicitStrategies(): void
    {
        $controller = $this->controller();

        self::assertSame('hybrid_auto', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['']));
        self::assertSame('hybrid_auto', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['hybrid']));
        self::assertSame('cookie_config', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['api']));
        self::assertSame('cookie_config', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['cookie-config']));
        self::assertSame('profile_browser', $this->invokeNonPublic($controller, 'normalizeAutoFetchMode', ['browser_profile']));
    }

    public function testAutoFetchSyncTaskDeleteHelpersProtectRunningTasks(): void
    {
        $controller = $this->controller();

        self::assertSame([12, 45], $this->invokeNonPublic($controller, 'extractAutoFetchSyncTaskIdsFromRecordIds', [[
            'sync_task_12',
            'cache_7_0_0',
            'sync_task_45',
            'sync_task_12',
            'sync_task_0',
            'sync_task_bad',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'isAutoFetchPlatformSyncTaskDeletableStatus', ['failed']));
        self::assertTrue($this->invokeNonPublic($controller, 'isAutoFetchPlatformSyncTaskDeletableStatus', ['success']));
        self::assertTrue($this->invokeNonPublic($controller, 'isAutoFetchPlatformSyncTaskDeletableStatus', ['partial_success']));
        self::assertFalse($this->invokeNonPublic($controller, 'isAutoFetchPlatformSyncTaskDeletableStatus', ['pending']));
        self::assertFalse($this->invokeNonPublic($controller, 'isAutoFetchPlatformSyncTaskDeletableStatus', ['running']));
    }

    public function testAutoFetchDataRecordListHidesConfigurationOnlySkippedRows(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'isAutoFetchDataRecordListRow', [[
            'status' => 'skipped',
            'saved_count' => 0,
            'module_summary' => 'configuration[cookie_config:skip:0]',
            'message' => '未配置美团 Partner ID / POI ID / Cookies',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'isAutoFetchDataRecordListRow', [[
            'status' => 'success',
            'saved_count' => 77,
            'module_summary' => 'business[browser_profile:success:77]',
            'message' => 'Platform data synchronized.',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'isAutoFetchDataRecordListRow', [[
            'source_record_type' => 'platform_sync_task',
            'status' => 'failed',
            'saved_count' => 0,
            'module_summary' => 'business[browser_profile:failed:0]',
            'message' => 'Ctrip login timeout after 30 seconds',
        ]]));
    }

    public function testAutoFetchCostStrategyOnlyRunsProfileWhenExplicitlySelected(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['cookie_config', 0]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['profile_browser', 10]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['hybrid_auto', 3]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunProfileBrowserForCost', ['hybrid_auto', 0]));
    }

    public function testCtripHybridAutoRunsProfileWhenBrowserDataSourceExists(): void
    {
        $controller = $this->controller();
        $sources = [['id' => 13, 'platform' => 'ctrip', 'ingestion_method' => 'browser_profile']];

        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunCtripProfileBrowser', ['cookie_config', $sources]));
        self::assertFalse($this->invokeNonPublic($controller, 'shouldRunCtripProfileBrowser', ['hybrid_auto', []]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldRunCtripProfileBrowser', ['hybrid_auto', $sources]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldRunCtripProfileBrowserForCost', ['hybrid_auto', 26, $sources]));
    }

    public function testAutoFetchResultMetaKeepsFailureActionExplicit(): void
    {
        $controller = $this->controller();

        $cookieResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'day_report_api',
            'saved_count' => 0,
            'success' => false,
            'skipped' => true,
            'message' => '未配置携程 Cookie',
        ], 'cookie_config']);
        self::assertSame('cookie_config', $cookieResult['strategy']);
        self::assertSame('needs_cookie', $cookieResult['status_code']);
        self::assertSame('更新 Cookie 或重新登录 OTA 后台', $cookieResult['next_action']);

        $profileResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'browser_profile',
            'saved_count' => 0,
            'success' => false,
            'skipped' => true,
            'message' => '未发现本地美团浏览器 Profile',
        ], 'profile_browser']);
        self::assertSame('needs_profile', $profileResult['status_code']);
        self::assertSame('建立或重新登录浏览器 Profile', $profileResult['next_action']);

        $profileLoginTimeoutResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'browser_profile',
            'saved_count' => 0,
            'success' => false,
            'message' => 'Ctrip login timeout after 30 seconds',
        ], 'profile_browser']);
        self::assertSame('needs_profile', $profileLoginTimeoutResult['status_code']);
        self::assertStringContainsString('Profile', $profileLoginTimeoutResult['next_action']);

        $costSkippedResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'browser_profile',
            'saved_count' => 0,
            'success' => false,
            'skipped' => true,
            'message' => '当前策略未启动 Profile',
        ], 'profile_browser']);
        self::assertSame('skipped', $costSkippedResult['status_code']);
        self::assertSame('', $costSkippedResult['next_action']);

        $meituanMissingResult = $this->invokeNonPublic($controller, 'withAutoFetchResultMeta', [[
            'module' => 'ranking_api',
            'saved_count' => 0,
            'success' => false,
            'skipped' => true,
            'message' => '缺少美团 Partner ID / POI ID / Cookies',
        ], 'cookie_config']);
        self::assertSame('needs_config', $meituanMissingResult['status_code']);
        self::assertSame('补齐美团 Partner ID / POI ID / Cookies', $meituanMissingResult['next_action']);
    }

    public function testPlatformProfileStatusUsesLatestLoginFailureOverLoggedInCache(): void
    {
        $controller = $this->controller();

        $status = $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            '6866634',
            true,
            [
                'last_sync_status' => 'failed',
                'last_error' => 'Ctrip browser Profile section capture failed: Ctrip login timeout after 30 seconds',
            ],
            [
                'status_code' => 'logged_in',
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            ],
        ]);

        self::assertSame('login_expired', $status);

        $nonAuthFailure = $this->invokeNonPublic($controller, 'resolvePlatformProfileStatusCode', [
            '6866634',
            true,
            [
                'last_sync_status' => 'failed',
                'last_error' => 'field coverage failed',
            ],
            [
                'status_code' => 'logged_in',
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            ],
        ]);

        self::assertSame('capture_failed', $nonAuthFailure);
    }

    public function testPlatformProfileLoginExpiredPromotesReloginAction(): void
    {
        $controller = $this->controller();

        $checks = $this->invokeNonPublic($controller, 'buildPlatformProfileBindingChecks', [
            'ctrip',
            7,
            ['profile_id' => '6866634', 'ota_hotel_id' => '6866634'],
            [
                'system_hotel_id' => 7,
                'last_sync_status' => 'failed',
                'last_error' => 'browser_profile needs relogin Ctrip login timeout after 30 seconds',
            ],
            'login_expired',
            true,
            '6866634',
        ]);
        $byKey = [];
        foreach ($checks as $check) {
            $byKey[$check['key']] = $check;
        }

        self::assertSame('error', $byKey['profile_login']['status']);
        self::assertSame('login_platform_profile', $byKey['profile_login']['action_key']);

        $primary = $this->invokeNonPublic($controller, 'firstPlatformProfileBindingAction', [$checks]);
        self::assertSame('profile_login', $primary['check_key']);
        self::assertSame('login_platform_profile', $primary['action_key']);
    }

    public function testPlatformProfileBindingChecksExposeDirectP0Actions(): void
    {
        $controller = $this->controller();

        $checks = $this->invokeNonPublic($controller, 'buildPlatformProfileBindingChecks', [
            'meituan',
            7,
            ['hotel_id' => 7],
            ['system_hotel_id' => 7, 'last_sync_status' => 'failed', 'last_error' => 'login expired'],
            'capture_failed',
            false,
            '',
        ]);
        $byKey = [];
        foreach ($checks as $check) {
            $byKey[$check['key']] = $check;
        }

        self::assertSame('configure_meituan_poi', $byKey['platform_identity']['action_key']);
        self::assertSame('补齐美团 POI/Store', $byKey['platform_identity']['action_label']);
        self::assertSame('platform-sources', $byKey['platform_identity']['action_target']);
        self::assertSame('open_sync_logs', $byKey['trial_capture']['action_key']);
        self::assertSame('查看日志并重试采集', $byKey['trial_capture']['action_label']);
        self::assertSame('sync-logs', $byKey['trial_capture']['action_target']);

        $primary = $this->invokeNonPublic($controller, 'firstPlatformProfileBindingAction', [$checks]);
        self::assertSame('profile_login', $primary['check_key']);
        self::assertSame('open_sync_logs', $primary['action_key']);
        self::assertSame('查看最近同步日志后重新检测登录状态', $primary['next_action']);
    }

    public function testPlatformProfileBindingChecksPromoteLoginActionWhenProfileNotLoggedIn(): void
    {
        $controller = $this->controller();

        $checks = $this->invokeNonPublic($controller, 'buildPlatformProfileBindingChecks', [
            'meituan',
            7,
            ['hotel_id' => 7, 'poi_id' => 'poi-7', 'partner_id' => 'partner-7'],
            null,
            'waiting_login',
            false,
            'poi-7',
        ]);
        $byKey = [];
        foreach ($checks as $check) {
            $byKey[$check['key']] = $check;
        }
        $primary = $this->invokeNonPublic($controller, 'firstPlatformProfileBindingAction', [$checks]);

        self::assertSame('ok', $byKey['platform_identity']['status']);
        self::assertSame('warning', $byKey['profile_login']['status']);
        self::assertSame('profile_login', $primary['check_key']);
        self::assertSame('login_platform_profile', $primary['action_key']);
        self::assertSame('登录美团', $primary['action_label']);
        self::assertSame('profile-login', $primary['action_target']);
        self::assertSame('点击“登录美团”完成平台验证', $primary['next_action']);
    }

    public function testMeituanAutoFetchConfigStatusReportsMissingFields(): void
    {
        $controller = $this->controller();

        $missing = $this->invokeNonPublic($controller, 'meituanAutoFetchConfigStatus', [[
            'partner_id' => '',
            'poi_id' => 'poi-7',
            'cookies' => '',
        ]]);

        self::assertFalse($missing['api_configured']);
        self::assertSame(['Partner ID', 'Cookies'], $missing['missing_fields']);
        self::assertSame('Partner ID / Cookies', $missing['missing_text']);
        self::assertSame('cookie_plus_resource_id', $missing['credential_level']);
        self::assertSame('missing_cookie', $missing['credential_status']);

        $complete = $this->invokeNonPublic($controller, 'meituanAutoFetchConfigStatus', [[
            'config_id' => 'meituan-7',
            'system_hotel_id' => 7,
            'credential_status' => 'ready',
            'has_cookies' => true,
            'partnerId' => 'partner-7',
            'poiId' => 'poi-7',
        ]]);

        self::assertTrue($complete['api_configured']);
        self::assertSame([], $complete['missing_fields']);
        self::assertSame('ready', $complete['credential_status']);
    }

    public function testMeituanAutoFetchConfigStatusRejectsLegacyInlineCookieWithoutLocator(): void
    {
        $controller = $this->controller();

        $status = $this->invokeNonPublic($controller, 'meituanAutoFetchConfigStatus', [[
            'partner_id' => '',
            'poi_id' => '',
            'cookies' => 'meituan-cookie',
        ]]);

        self::assertFalse($status['api_configured']);
        self::assertFalse($status['has_cookies']);
        self::assertFalse($status['has_partner_id']);
        self::assertFalse($status['has_poi_id']);
        self::assertSame(['Partner ID', 'POI ID', 'Cookies'], $status['missing_fields']);
        self::assertSame('missing_cookie', $status['credential_status']);
        self::assertSame('缺少 Cookie', $status['credential_status_label']);
        self::assertSame(['Cookie'], $status['daily_required_fields']);
        self::assertSame(['Partner ID', 'POI ID'], $status['one_time_required_fields']);
    }

    public function testMeituanAutoFetchConfigStatusRejectsProfileDirectoryWithoutReusableProof(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $storeId = 'phpunit_profile_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . $storeId;
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0775, true);
        }

        try {
            $status = $this->invokeNonPublic($controller, 'meituanAutoFetchConfigStatus', [[
                'partner_id' => 'partner-7',
                'poi_id' => 'poi-7',
                'store_id' => $storeId,
                'cookies' => '',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-05-18 09:00:00',
            ]]);

            self::assertFalse($status['api_configured']);
            self::assertFalse($status['has_cookies']);
            self::assertFalse($status['has_profile_cookie_source']);
            self::assertSame(['profile_session_unverified'], $status['profile_cookie_missing_requirements']);
            self::assertContains('profile_session_unverified', $status['missing_fields']);
        } finally {
            @rmdir($profileDir);
        }
    }

    public function testMeituanAutoFetchConfigStatusRejectsUnverifiedExistingProfileSource(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $storeId = 'phpunit_profile_unverified_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . $storeId;
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0775, true);
        }

        try {
            $status = $this->invokeNonPublic($controller, 'meituanAutoFetchConfigStatus', [[
                'partner_id' => 'partner-7',
                'poi_id' => 'poi-7',
                'store_id' => $storeId,
                'cookies' => '',
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-05-18 09:00:00',
            ]]);

            self::assertFalse($status['api_configured']);
            self::assertFalse($status['has_cookies']);
            self::assertFalse($status['has_profile_cookie_source']);
            self::assertTrue($status['profile_cookie_source_candidate']);
            self::assertSame(['profile_session_unverified'], $status['profile_cookie_missing_requirements']);
            self::assertContains('profile_session_unverified', $status['missing_fields']);
        } finally {
            @rmdir($profileDir);
        }
    }

    public function testAutoFetchTaskPlanNeverDerivesMeituanCookieTasksFromProfile(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $storeId = 'phpunit_plan_' . bin2hex(random_bytes(4));
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . $storeId;
        if (!is_dir($profileDir)) {
            mkdir($profileDir, 0775, true);
        }

        try {
            $tasks = $this->invokeNonPublic($controller, 'buildAutoFetchConfigTaskPlan', [
                7,
                '2026-05-18',
                [],
                [
                    'url' => 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
                    'partner_id' => 'partner-7',
                    'poi_id' => 'poi-7',
                    'store_id' => $storeId,
                    'cookies' => '',
                    'manual_login_state_verified' => true,
                    'profile_status' => 'logged_in',
                    'last_login_verified_at' => '2026-05-18 09:00:00',
                ],
                [
                    'meituan-traffic' => [
                        'system_hotel_id' => 7,
                        'url' => 'https://eb.meituan.com/api/v1/ebooking/traffic',
                        'partner_id' => 'partner-traffic-7',
                        'poi_id' => 'poi-traffic-7',
                    ],
                ],
            ]);

            $labels = array_column($tasks, 'label');
            self::assertNotContains('meituan-P_RZ', $labels);
            self::assertNotContains('meituan-traffic', $labels);
            self::assertSame([], $tasks);
        } finally {
            @rmdir($profileDir);
        }
    }

    public function testCtripApprovedMappingsPathResolverAcceptsProjectJsonAliases(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $mappingDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'test_ctrip_mapping';
        if (!is_dir($mappingDir)) {
            mkdir($mappingDir, 0775, true);
        }
        $mappingPath = $mappingDir . DIRECTORY_SEPARATOR . 'approved_mapping_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($mappingPath, json_encode(['mappings' => []], JSON_UNESCAPED_UNICODE));

        try {
            $resolved = $this->invokeNonPublic($controller, 'resolveCtripApprovedMappingsPath', [[
                'approved_mapping_path' => 'runtime/test_ctrip_mapping/' . basename($mappingPath),
            ], $projectRoot]);

            self::assertTrue($resolved['configured']);
            self::assertSame(realpath($mappingPath), $resolved['path']);
            self::assertSame('', $resolved['error']);

            $camelCase = $this->invokeNonPublic($controller, 'resolveCtripApprovedMappingsPath', [[
                'p3MappingsPath' => 'runtime/test_ctrip_mapping/' . basename($mappingPath),
            ], $projectRoot]);
            self::assertSame(realpath($mappingPath), $camelCase['path']);
        } finally {
            if (is_file($mappingPath)) {
                unlink($mappingPath);
            }
        }
    }

    public function testCtripApprovedMappingsPathResolverRejectsUnsafeOrInvalidFiles(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $mappingDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'test_ctrip_mapping';
        if (!is_dir($mappingDir)) {
            mkdir($mappingDir, 0775, true);
        }
        $txtPath = $mappingDir . DIRECTORY_SEPARATOR . 'approved_mapping_' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($txtPath, 'not json');

        try {
            $nonJson = $this->invokeNonPublic($controller, 'resolveCtripApprovedMappingsPath', [[
                'approved_mappings_path' => 'runtime/test_ctrip_mapping/' . basename($txtPath),
            ], $projectRoot]);
            self::assertTrue($nonJson['configured']);
            self::assertSame('', $nonJson['path']);
            self::assertStringContainsString('JSON', $nonJson['error']);

            $outside = $this->invokeNonPublic($controller, 'resolveCtripApprovedMappingsPath', [[
                'approved_mappings_path' => 'C:\\Windows\\win.ini',
            ], $projectRoot]);
            self::assertTrue($outside['configured']);
            self::assertSame('', $outside['path']);
            self::assertStringContainsString('项目目录', $outside['error']);
        } finally {
            if (is_file($txtPath)) {
                unlink($txtPath);
            }
        }
    }

    public function testCtripApprovedMappingsArgBuilderAppendsResolvedFile(): void
    {
        $controller = $this->controller();
        $projectRoot = dirname(__DIR__);
        $mappingDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'test_ctrip_mapping';
        if (!is_dir($mappingDir)) {
            mkdir($mappingDir, 0775, true);
        }
        $mappingPath = $mappingDir . DIRECTORY_SEPARATOR . 'approved_mapping_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($mappingPath, json_encode(['mappings' => []], JSON_UNESCAPED_UNICODE));

        try {
            $result = $this->invokeNonPublic($controller, 'appendCtripApprovedMappingsArg', [[
                'node',
                'scripts/ctrip_browser_capture.mjs',
            ], [
                'approved_mappings_path' => 'runtime/test_ctrip_mapping/' . basename($mappingPath),
            ], $projectRoot]);

            self::assertSame('', $result['error']);
            self::assertSame('--approved-mappings=' . realpath($mappingPath), end($result['args']));
            self::assertSame(realpath($mappingPath), $result['approved_mappings']['path']);
        } finally {
            if (is_file($mappingPath)) {
                unlink($mappingPath);
            }
        }
    }

    public function testCtripProfileCaptureConfigOptionsNormalizeSectionsAndMappingAliases(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[
            'captureSections' => ['business', 'traffic', 'quality_psi', '../bad', 'BIZTRAVEL_BPI'],
            'approvedMappingPath' => ' docs/ctrip_approved_mapping.example.json ',
        ], []]);

        self::assertSame('all', $options['capture_sections']);
        self::assertSame('all', $options['profile_sections']);
        self::assertSame('docs/ctrip_approved_mapping.example.json', $options['approved_mappings_path']);
    }

    public function testCtripProfileCaptureConfigOptionsPreserveOriginalWhenKeysAreAbsent(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[], [
            'capture_sections' => 'business,traffic,quality_psi',
            'approved_mappings_path' => 'docs/approved.json',
        ]]);

        self::assertSame('all', $options['capture_sections']);
        self::assertSame('all', $options['profile_sections']);
        self::assertSame('docs/approved.json', $options['approved_mappings_path']);
    }

    public function testCtripProfileCaptureConfigOptionsDefaultToDefaultPreset(): void
    {
        $controller = $this->controller();

        $options = $this->invokeNonPublic($controller, 'buildCtripProfileCaptureConfigOptions', [[], []]);

        self::assertSame('all', $options['capture_sections']);
        self::assertSame('all', $options['profile_sections']);
    }

    public function testCtripRoomCountRequiresPositiveCanonicalInteger(): void
    {
        $controller = $this->controller();

        self::assertSame(88, $this->invokeNonPublic(
            $controller,
            'requiredPositiveCtripRoomCount',
            ['88', '酒店实际房量']
        ));

        foreach (['', '0', '-1', '1.5', 'abc', true, 1000001] as $invalid) {
            try {
                $this->invokeNonPublic(
                    $controller,
                    'requiredPositiveCtripRoomCount',
                    [$invalid, '酒店实际房量']
                );
                self::fail('Invalid Ctrip room count must fail.');
            } catch (\think\exception\HttpException $e) {
                self::assertSame(422, $e->getStatusCode());
                self::assertStringContainsString('酒店实际房量', $e->getMessage());
            }
        }
    }

    public function testCtripProfileCaptureFieldDefaultsCoverLatestTaskFieldsAndGaps(): void
    {
        $controller = $this->controller();

        $fields = $this->invokeNonPublic($controller, 'defaultCtripProfileCaptureFields');
        $modules = $this->invokeNonPublic($controller, 'defaultCtripProfileCaptureModules');
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['field_key']] = $field;
        }
        self::assertArrayNotHasKey('room_type', $modules);
        self::assertArrayNotHasKey('competitor_hotel_list', $byKey);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', $modules['business_overview']['page_url']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/businessreport/weekReport?microJump=true', $modules['business_weekly_overview']['page_url']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true', $modules['sales_report']['page_url']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true', $modules['traffic_report']['page_url']);
        self::assertSame('竞争圈动态-竞争圈概览', $modules['competitor_overview']['label']);
        self::assertSame('https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true', $modules['competitor_overview']['page_url']);
        self::assertSame('用户行为-IM看板', $modules['im_board']['label']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?goto=im', $modules['im_board']['page_url']);
        self::assertSame('经营收益数据', $modules['business_overview']['primary_category']);
        self::assertSame('经营收益数据', $modules['sales_report']['primary_category']);
        self::assertSame('流量转化数据', $modules['traffic_report']['primary_category']);
        self::assertSame('流量转化数据', $modules['ads_pyramid']['primary_category']);
        self::assertSame('服务质量数据', $modules['comment_review']['primary_category']);
        self::assertSame('服务质量数据', $modules['quality_psi']['primary_category']);
        self::assertSame('服务质量数据', $modules['im_board']['primary_category']);
        self::assertSame('竞争力数据', $modules['competitor_rank']['primary_category']);
        foreach ($modules as $module) {
            self::assertNotSame('', trim((string)($module['page_url'] ?? '')));
            self::assertContains($module['primary_category'], ['流量转化数据', '经营收益数据', '服务质量数据', '竞争力数据']);
        }

        foreach ([
            'visitor_count',
            'visitor_rank',
            'visitor_count_last_week',
            'competitor_avg_visitor',
            'qunar_visitor_count',
            'qunar_visitor_rank',
            'qunar_visitor_count_last_week',
            'qunar_competitor_avg_visitor',
            'order_count',
            'realtime_booking_orders',
            'realtime_booking_orders_last_week',
            'realtime_booking_orders_rank',
            'order_count_sync',
            'order_count_rank',
            'competitor_avg_orders',
            'ctrip_order_count',
            'ctrip_order_count_sync',
            'ctrip_order_count_rank',
            'qunar_order_count',
            'qunar_order_count_sync',
            'qunar_order_count_rank',
            'elong_order_count',
            'elong_order_count_sync',
            'elong_order_count_rank',
            'order_amount',
            'order_amount_last_week',
            'amount_rank',
            'book_order_num_rank',
            'comment_score_rank',
            'conversion_rank',
            'room_nights',
            'in_house_room_nights',
            'in_house_room_nights_last_week',
            'in_house_room_nights_rank',
            'room_nights_last_week',
            'quantity_rank',
            'occupied_rooms',
            'occupied_rooms_sync',
            'occupied_rooms_rank',
            'competitor_avg_occupied_rooms',
            'avg_price',
            'avg_price_last_week',
            'avg_price_rank',
            'close_rate',
            'close_rate_last_week',
            'close_rate_rank',
            'occupancy_rate',
            'occupancy_rate_sync',
            'occupancy_rate_rank',
            'competition_rank',
            'competition_profile_order_count',
            'competition_profile_order_amount',
            'competition_profile_room_nights',
            'competition_profile_occupancy_rate',
            'competition_profile_app_visitor',
            'competition_profile_app_conversion_rate',
            'competition_profile_list_exposure',
            'competition_profile_detail_visitor',
            'competition_profile_order_page_visitor',
            'competition_profile_list_to_detail_rate',
            'competition_profile_order_fill_rate',
            'competition_profile_psi_score',
            'competition_profile_ctrip_rating',
            'seq_rank',
            'target_date',
            'search_window',
            'compare_scope',
            'future_search_pv',
            'future_search_uv',
            'future_search_order_count',
            'future_search_conversion_rate',
            'list_exposure',
            'competitor_list_exposure',
            'detail_visitor',
            'competitor_detail_visitor',
            'flow_rate',
            'competitor_flow_rate',
            'order_page_visitor',
            'competitor_order_page_visitor',
            'order_fill_rate',
            'competitor_order_fill_rate',
            'order_submit_user',
            'competitor_order_submit_user',
            'deal_rate',
            'competitor_deal_rate',
            'last_week_checkout_room_nights',
            'last_week_checkout_sales',
            'last_week_checkout_room_price',
            'last_week_book_quantity',
            'last_week_book_room_nights',
            'last_week_book_sales',
            'weekly_self_list_exposure',
            'weekly_self_detail_exposure',
            'weekly_self_order_filling_num',
            'weekly_self_order_submit_num',
            'weekly_self_flow_rate',
            'weekly_self_order_fill_rate',
            'weekly_self_deal_rate',
            'weekly_competitor_list_exposure',
            'weekly_competitor_detail_exposure',
            'weekly_competitor_order_filling_num',
            'weekly_competitor_order_submit_num',
            'weekly_competitor_flow_rate',
            'weekly_competitor_order_fill_rate',
            'weekly_competitor_deal_rate',
            'top_competitor_list_exposure',
            'top_competitor_detail_exposure',
            'top_competitor_order_filling_num',
            'top_competitor_order_submit_num',
            'top_competitor_flow_rate',
            'top_competitor_order_fill_rate',
            'top_competitor_deal_rate',
            'weekly_order_page_visitor',
            'weekly_competitor_avg_order_page_visitor',
            'weekly_top_competitor_order_page_visitor',
            'weekly_submit_user',
            'weekly_competitor_avg_submit_user',
            'weekly_top_competitor_submit_user',
            'last_week_comment_score',
            'last_week_good_add',
            'last_week_bad_add',
            'last_week_price_score',
            'flow_lost_order_num',
            'flow_lost_room_nights',
            'flow_lost_amount',
            'top_flow_hotel',
            'top_flow_hotel_browse_rate',
            'top_flow_hotel_order_rate',
            'top_hot_room',
            'top_hot_room_nights',
            'top_hot_room_sale_percent',
            'hot_words_count',
            'top_hot_words',
            'hot_hotels_count',
            'top_hot_hotels',
            'psi_score',
            'service_score_rank',
            'ctrip_comment_count',
            'qunar_comment_count',
            'elong_comment_count',
            'zx_comment_count',
            'ctrip_rating',
            'review_environment_score',
            'review_facility_score',
            'review_service_score',
            'review_cleanliness_score',
            'review_photo_count',
            'review_photo_rate',
            'comment_score_summary',
            'comment_unreply_count',
            'comment_good_rate',
            'reply_rate',
            'reply_rank',
            'five_min_reply_rate',
            'manual_reply_rate',
            'robot_resolution_rate',
            'im_rank',
            'session_count',
            'manual_session_count',
            'robot_session_count',
            'im_order_conversion_rate',
            'hotel_collect',
            'hotel_collect_rank',
            'ad_cost',
        ] as $requiredKey) {
            self::assertArrayHasKey($requiredKey, $byKey);
        }

        foreach ([
            'notice_count',
            'notice_title',
            'notice_text',
            'target_url',
            'diagnosis_score',
            'diagnosis_level',
            'advice_text',
            'comment_rows',
            'good_review_count',
            'qunar_list_exposure',
            'qunar_flow_rate',
            'page_views',
            'flow_conversion_rate',
        ] as $skippedKey) {
            self::assertArrayNotHasKey($skippedKey, $byKey);
        }
        self::assertSame('comment_review', $byKey['bad_review_count']['section']);
        self::assertSame('getCommentNumV2 / getCommentList', $byKey['bad_review_count']['source_interface']);

        self::assertSame('confirmed', $byKey['ad_cost']['status']);
        self::assertTrue($byKey['ad_cost']['enabled']);
        self::assertStringContainsString('todayCost', $byKey['ad_cost']['source_keys']);
        self::assertStringContainsString('cashCost', $byKey['ad_cost']['source_keys']);
        self::assertStringContainsString('bonusCost', $byKey['ad_cost']['source_keys']);
        self::assertStringContainsString('queryFlowTransforNewV1', $byKey['flow_rate']['source_interface']);
        self::assertSame('data.amount', $byKey['order_amount']['json_path']);
        self::assertSame('data.quantity', $byKey['room_nights']['json_path']);
        self::assertSame('data.visitorTotal', $byKey['visitor_count']['json_path']);
        self::assertSame('data.occupiedRooms', $byKey['occupied_rooms']['json_path']);
        self::assertSame('data.orderQuantity', $byKey['order_count']['json_path']);
        self::assertSame('needs_parser', $byKey['realtime_booking_orders']['status']);
        self::assertSame('candidate:data.bookOrderNum', $byKey['realtime_booking_orders']['json_path']);
        self::assertSame('needs_parser', $byKey['in_house_room_nights']['status']);
        self::assertSame('candidate:data.bookQuantity', $byKey['in_house_room_nights']['json_path']);
        self::assertSame('data.occupancyRate', $byKey['occupancy_rate']['json_path']);
        self::assertSame('data.serviceScore / data.psiScoreBo.totalScore', $byKey['psi_score']['json_path']);
        self::assertSame('data.serviceScoreRank', $byKey['service_score_rank']['json_path']);
        self::assertSame('competitor_overview', $byKey['competition_profile_order_count']['section']);
        self::assertStringContainsString('competitionprofile', $byKey['competition_profile_order_count']['page_url']);
        self::assertSame('getManagementData', $byKey['competition_profile_order_count']['source_interface']);
        self::assertSame('dataList[indexType=0].val', $byKey['competition_profile_order_count']['json_path']);
        self::assertStringContainsString('online_daily_data.book_order_num', $byKey['competition_profile_order_count']['storage_field']);
        self::assertStringContainsString('raw_data.metrics', $byKey['competition_profile_order_count']['storage_field']);
        self::assertStringContainsString('计数差 <=1', $byKey['competition_profile_order_count']['notes']);
        self::assertSame('getManagementData', $byKey['competition_profile_app_conversion_rate']['source_interface']);
        self::assertSame('dataList[indexType=5].val', $byKey['competition_profile_app_conversion_rate']['json_path']);
        self::assertSame('getFlowData / getFlowSource', $byKey['competition_profile_list_exposure']['source_interface']);
        self::assertStringContainsString('listExposure', $byKey['competition_profile_list_exposure']['source_keys']);
        self::assertStringContainsString('online_daily_data.list_exposure', $byKey['competition_profile_list_exposure']['storage_field']);
        self::assertSame('dataList[indexType=10].val', $byKey['competition_profile_order_fill_rate']['json_path']);
        self::assertSame('getServiceData', $byKey['competition_profile_psi_score']['source_interface']);
        self::assertSame('dataList[indexType=12].val', $byKey['competition_profile_psi_score']['json_path']);
        self::assertStringContainsString('queryFlowTransforNewV1', $byKey['flow_rate']['request_url']);
        self::assertStringContainsString('flowdata', $byKey['flow_rate']['page_url']);
        self::assertStringContainsString('hotelId=当前携程酒店ID', $byKey['flow_rate']['json_path']);
        self::assertStringContainsString('当前携程酒店ID', $byKey['flow_rate']['ownership_rule']);
        self::assertSame('online_daily_data.flow_rate', $byKey['flow_rate']['storage_field']);
        self::assertStringContainsString('detailExposure / listExposure', $byKey['flow_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_flow_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_flow_rate']['json_path']);
        self::assertStringContainsString('竞争圈平均', $byKey['competitor_flow_rate']['ownership_rule']);
        self::assertStringContainsString('flowRate', $byKey['competitor_flow_rate']['source_keys']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_detail_visitor']['transform_rule']);
        self::assertStringContainsString('detailExposure', $byKey['competitor_detail_visitor']['source_keys']);
        self::assertStringContainsString('orderFillingNum / detailExposure', $byKey['order_fill_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_order_fill_rate']['transform_rule']);
        self::assertStringContainsString('orderFillingNum / detailExposure', $byKey['competitor_order_fill_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_order_page_visitor']['transform_rule']);
        self::assertStringContainsString('orderFillingNum', $byKey['competitor_order_page_visitor']['source_keys']);
        self::assertStringContainsString('orderSubmitNum / orderFillingNum', $byKey['deal_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_deal_rate']['transform_rule']);
        self::assertStringContainsString('orderSubmitNum / orderFillingNum', $byKey['competitor_deal_rate']['transform_rule']);
        self::assertStringContainsString('hotelId=-1', $byKey['competitor_order_submit_user']['transform_rule']);
        self::assertStringContainsString('orderSubmitNum', $byKey['competitor_order_submit_user']['source_keys']);
        self::assertSame('business_weekly_overview', $byKey['weekly_self_list_exposure']['section']);
        self::assertSame('data.myHotel.totalListExposure', $byKey['weekly_self_list_exposure']['json_path']);
        self::assertSame('getLastWeekReportV1', $byKey['last_week_book_sales']['source_interface']);
        self::assertSame('data.lastWeekBookSales', $byKey['last_week_book_sales']['json_path']);
        self::assertSame('getUserBehaviorV1 / getUserBehavorV1', $byKey['last_week_comment_score']['source_interface']);
        self::assertSame('data.lossOrderVo.ordernum', $byKey['flow_lost_order_num']['json_path']);
        self::assertSame('getHotRoomsV1', $byKey['top_hot_room']['source_interface']);
        self::assertSame('count(data[])', $byKey['hot_words_count']['json_path']);
        self::assertSame('data[0:10]', $byKey['top_hot_words']['json_path']);
        self::assertSame('data[0:10]', $byKey['top_hot_hotels']['json_path']);
        self::assertSame('queryUserSex', $byKey['user_sex']['source_interface']);
        self::assertSame('data[].name', $byKey['user_sex']['json_path']);
        self::assertSame('queryUserPriceInfo', $byKey['price_sensitivity']['source_interface']);
        self::assertSame('data.titleList[]', $byKey['price_sensitivity']['json_path']);
        self::assertSame('queryUserSource', $byKey['source_city']['source_interface']);
        self::assertSame('data.cities[].name', $byKey['source_city']['json_path']);
        self::assertSame('queryUserAge', $byKey['avg_user_age']['source_interface']);
        self::assertSame('data.avg', $byKey['avg_user_age']['json_path']);
        self::assertStringContainsString('queryUserFeatures', $byKey['user_age']['source_interface']);
        self::assertSame('queryUserFeatures / queryUserTravelTime', $byKey['travel_time']['source_interface']);
        self::assertSame('data[].traveltime / data.titleList[] / data[].name', $byKey['travel_time']['json_path']);
        self::assertSame('getOrderDistribution', $byKey['booking_hour']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['booking_hour']['json_path']);
        self::assertSame('queryUserStar', $byKey['hotel_star_preference']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['hotel_star_preference']['json_path']);
        self::assertSame('queryUserFeatures', $byKey['price_band']['source_interface']);
        self::assertSame('data[].consumer', $byKey['price_band']['json_path']);
        self::assertSame('queryUserPrice', $byKey['consumption_power']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['consumption_power']['json_path']);
        self::assertSame('queryUserBookingDays', $byKey['avg_booking_days']['source_interface']);
        self::assertSame('data.avg', $byKey['avg_booking_days']['json_path']);
        self::assertSame('queryUserBookingDays', $byKey['booking_days']['source_interface']);
        self::assertSame('data.titleList[]', $byKey['booking_days']['json_path']);
        self::assertSame('queryUserStayDays', $byKey['avg_stay_days']['source_interface']);
        self::assertSame('data.avg', $byKey['avg_stay_days']['json_path']);
        self::assertSame('queryUserStayDays', $byKey['stay_days']['source_interface']);
        self::assertSame('data.titleList[]', $byKey['stay_days']['json_path']);
        self::assertSame('queryOrderType', $byKey['booking_method']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['booking_method']['json_path']);
        self::assertSame('queryUserOrders', $byKey['order_hotel_count']['source_interface']);
        self::assertSame('data.titleList[] / data[].name', $byKey['order_hotel_count']['json_path']);
        self::assertSame('queryUserPoint', $byKey['order_preference']['source_interface']);
        self::assertSame('data.titleList[]', $byKey['order_preference']['json_path']);
        self::assertSame('queryUserPoint', $byKey['preference_frequency']['source_interface']);
        self::assertSame('data.userColumnBos[].titleList[]', $byKey['preference_frequency']['json_path']);
        self::assertSame('percent', $byKey['distribution_share']['value_type']);
        self::assertStringContainsString('data.valueList[]', $byKey['distribution_share']['json_path']);
        self::assertSame('getCommentsScoreV2 / getCommentNumV2', $byKey['ctrip_comment_count']['source_interface']);
        self::assertSame('data.ctripCommentCount / ctripCount.commentCount', $byKey['ctrip_comment_count']['json_path']);
        self::assertSame('getCommentsScoreV2 / getCommentNumV2', $byKey['qunar_comment_count']['source_interface']);
        self::assertSame('data.qunarCommentCount / qunarCount.commentCount', $byKey['qunar_comment_count']['json_path']);
        self::assertSame('getCommentsScoreV2 / getCommentNumV2', $byKey['elong_comment_count']['source_interface']);
        self::assertSame('data.elongCommentCount / elongCount.commentCount', $byKey['elong_comment_count']['json_path']);
        self::assertSame('getDayReportServerQuantity / getCommentsScoreV2', $byKey['ctrip_rating']['source_interface']);
        self::assertSame('data.ctripRatingall', $byKey['ctrip_rating']['json_path']);
        self::assertSame('getCommentsScoreV2', $byKey['qunar_rating']['source_interface']);
        self::assertSame('data.qunarRatingall', $byKey['qunar_rating']['json_path']);
        self::assertSame('getCommentsScoreV2', $byKey['elong_rating']['source_interface']);
        self::assertSame('traffic_report', $byKey['elong_rating']['section']);
        self::assertSame('data.elongRatingall', $byKey['elong_rating']['json_path']);
        self::assertArrayNotHasKey('ctrip_comment_id', $byKey);
        self::assertArrayNotHasKey('qunar_comment_id', $byKey);
        self::assertArrayNotHasKey('elong_comment_id', $byKey);
        self::assertSame('getCommentNumV2', $byKey['zx_comment_count']['source_interface']);
        self::assertSame('zxCount.commentCount', $byKey['zx_comment_count']['json_path']);
        self::assertSame('getCommentsScoreV2 / getCommentNumV2', $byKey['comment_response_rate']['source_interface']);
        self::assertSame('data.responseRate / {channel}Count.responseRate', $byKey['comment_response_rate']['json_path']);
        self::assertSame('getCommentNumV2', $byKey['comment_unreply_count']['source_interface']);
        self::assertSame('{channel}Count.unReplyCount', $byKey['comment_unreply_count']['json_path']);
        self::assertStringContainsString('restapi/soa2/26353/getCommentNumV2', $byKey['comment_unreply_count']['request_url']);
        self::assertStringContainsString('ctripCount.unReplyCount', $byKey['comment_unreply_count']['source_keys']);
        self::assertSame('getCommentNumV2', $byKey['comment_good_rate']['source_interface']);
        self::assertSame('{channel}Count.goodRate', $byKey['comment_good_rate']['json_path']);
        self::assertStringContainsString('restapi/soa2/26353/getCommentNumV2', $byKey['comment_good_rate']['request_url']);
        self::assertStringContainsString('ctripCount.goodRate', $byKey['comment_good_rate']['source_keys']);
        self::assertSame('comment_review', $byKey['review_environment_score']['section']);
        self::assertSame('getHotelRating', $byKey['review_environment_score']['source_interface']);
        self::assertSame('confirmed', $byKey['review_environment_score']['status']);
        self::assertSame('ratingInfo.ratingLocation / ctripRatings.ratingLocation / elongRatings.ratingLocation / ratingInfo.scoreInfo.subScores[type=ratingLocation].scoreSimple / elongRatings.scoreInfo.subScores[type=ratingLocation].score', $byKey['review_environment_score']['json_path']);
        self::assertStringContainsString('ratingLocation', $byKey['review_environment_score']['source_keys']);
        self::assertSame('comment_review', $byKey['review_facility_score']['section']);
        self::assertSame('getHotelRating', $byKey['review_facility_score']['source_interface']);
        self::assertSame('confirmed', $byKey['review_facility_score']['status']);
        self::assertSame('ratingInfo.ratingFacility / ctripRatings.ratingFacility / elongRatings.ratingFacility / ratingInfo.scoreInfo.subScores[type=ratingFacility].scoreSimple / elongRatings.scoreInfo.subScores[type=ratingFacility].score', $byKey['review_facility_score']['json_path']);
        self::assertStringContainsString('ratingFacility', $byKey['review_facility_score']['source_keys']);
        self::assertSame('comment_review', $byKey['review_service_score']['section']);
        self::assertSame('getHotelRating', $byKey['review_service_score']['source_interface']);
        self::assertSame('confirmed', $byKey['review_service_score']['status']);
        self::assertSame('ratingInfo.ratingService / ctripRatings.ratingService / elongRatings.ratingService / ratingInfo.scoreInfo.subScores[type=ratingService].scoreSimple / elongRatings.scoreInfo.subScores[type=ratingService].score', $byKey['review_service_score']['json_path']);
        self::assertStringContainsString('ratingService', $byKey['review_service_score']['source_keys']);
        self::assertSame('comment_review', $byKey['review_cleanliness_score']['section']);
        self::assertSame('getHotelRating', $byKey['review_cleanliness_score']['source_interface']);
        self::assertSame('confirmed', $byKey['review_cleanliness_score']['status']);
        self::assertSame('ratingInfo.ratingRoom / ctripRatings.ratingRoom / elongRatings.ratingRoom / ratingInfo.scoreInfo.subScores[type=ratingRoom].scoreSimple / elongRatings.scoreInfo.subScores[type=ratingRoom].score', $byKey['review_cleanliness_score']['json_path']);
        self::assertStringContainsString('ratingRoom', $byKey['review_cleanliness_score']['source_keys']);
        self::assertSame('confirmed', $byKey['review_photo_count']['status']);
        self::assertSame('data.hasPicCount / {channel}Count.hasPicCount', $byKey['review_photo_count']['json_path']);
        self::assertStringContainsString('restapi/soa2/26353/getCommentNumV2', $byKey['review_photo_count']['request_url']);
        self::assertStringContainsString('ctripCount.hasPicCount', $byKey['review_photo_count']['source_keys']);
        self::assertStringContainsString('raw_data.metrics.review_photo_count', $byKey['review_photo_count']['storage_field']);
        self::assertSame('confirmed', $byKey['review_photo_rate']['status']);
        self::assertSame('derived:data.hasPicCount / data.commentCount / {channel}Count.hasPicCount / {channel}Count.commentCount', $byKey['review_photo_rate']['json_path']);
        self::assertStringContainsString('hasPicCount / commentCount * 100', $byKey['review_photo_rate']['notes']);
        self::assertStringContainsString('raw_data.metrics.review_photo_rate', $byKey['review_photo_rate']['storage_field']);
        self::assertSame('im_board', $byKey['five_min_reply_rate']['section']);
        self::assertSame('getImIndex', $byKey['five_min_reply_rate']['source_interface']);
        self::assertSame('data.replyRate5m / data.fiveMinReplyRate / data.replyRate', $byKey['five_min_reply_rate']['json_path']);
        self::assertStringContainsString('raw_data.metrics.five_min_reply_rate', $byKey['five_min_reply_rate']['storage_field']);
        self::assertSame('im_board', $byKey['manual_reply_rate']['section']);
        self::assertSame('getImIndex', $byKey['manual_reply_rate']['source_interface']);
        self::assertSame('data.manualReplyRate / data.humanReplyRate / data.manualreplyrate5m', $byKey['manual_reply_rate']['json_path']);
        self::assertSame('im_board', $byKey['robot_resolution_rate']['section']);
        self::assertSame('getImIndex', $byKey['robot_resolution_rate']['source_interface']);
        self::assertSame('data.robotResolutionRate / data.robotResolveRate / data.aisolverate', $byKey['robot_resolution_rate']['json_path']);
        self::assertSame('im_board', $byKey['im_rank']['section']);
        self::assertSame('getImIndex', $byKey['im_rank']['source_interface']);
        self::assertStringContainsString('raw_data.rank_metrics.im_rank', $byKey['im_rank']['storage_field']);
        self::assertSame('im_board', $byKey['session_count']['section']);
        self::assertSame('getImDateDistribute / getImSessionDistribute / getImOrderConversionDetail', $byKey['session_count']['source_interface']);
        self::assertStringContainsString('data[].sessionCount', $byKey['session_count']['json_path']);
        self::assertSame('im_board', $byKey['manual_session_count']['section']);
        self::assertSame('getImSessionDistribute / getImOrderConversionDetail', $byKey['manual_session_count']['source_interface']);
        self::assertSame('im_board', $byKey['robot_session_count']['section']);
        self::assertSame('getImSessionDistribute', $byKey['robot_session_count']['source_interface']);
        self::assertSame('im_board', $byKey['im_order_conversion_rate']['section']);
        self::assertSame('getImOrderConversionRateByDay / getImOrderConversionDetail', $byKey['im_order_conversion_rate']['source_interface']);
        self::assertStringContainsString('raw_data.metrics.im_order_conversion_rate', $byKey['im_order_conversion_rate']['storage_field']);
        self::assertSame('im_board', $this->invokeNonPublic($controller, 'classifyCtripProfileCaptureSectionByPageUrl', [
            'https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?goto=im',
            '',
        ]));
    }

    public function testCtripProfileCaptureModuleDefaultsRefreshLegacySystemLabelsOnly(): void
    {
        $controller = $this->controller();
        $modules = $this->invokeNonPublic($controller, 'defaultCtripProfileCaptureModules');

        $modules['competitor_overview']['label'] = '竞争圈动态-概览';
        [$merged, $changed] = $this->invokeNonPublic($controller, 'mergeDefaultCtripProfileCaptureModules', [$modules]);
        self::assertTrue($changed);
        self::assertSame('竞争圈动态-竞争圈概览', $merged['competitor_overview']['label']);

        $modules['competitor_overview']['label'] = '自定义竞争圈概览';
        [$merged, $changed] = $this->invokeNonPublic($controller, 'mergeDefaultCtripProfileCaptureModules', [$modules]);
        self::assertFalse($changed);
        self::assertSame('自定义竞争圈概览', $merged['competitor_overview']['label']);
    }

    public function testCtripProfileFieldSimpleEvidenceCreatesFieldConfig(): void
    {
        $controller = $this->controller();

        $payload = [
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
            'request_url' => 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
            'json' => 'response[hotelId=6866634].detailExposure',
            'target_value' => 'detailExposure',
            'value_meaning' => '详情页访客量',
            'section' => 'traffic_report',
        ];

        $prepared = $this->invokeNonPublic($controller, 'prepareCtripProfileFieldSaveData', [$payload, [], true]);

        self::assertTrue($this->invokeNonPublic($controller, 'hasRequiredCtripProfileFieldEvidence', [$prepared]));
        self::assertSame('detailexposure', $prepared['field_key']);
        self::assertSame('详情页访客量', $prepared['field_name']);
        self::assertSame('detailExposure', $prepared['source_keys']);
        self::assertSame('needs_parser', $prepared['status']);

        $normalized = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [$prepared]);

        self::assertSame($payload['page_url'], $normalized['page_url']);
        self::assertSame($payload['request_url'], $normalized['request_url']);
        self::assertSame($payload['json'], $normalized['json_path']);
        self::assertSame('detailExposure', $normalized['target_value']);
        self::assertSame('详情页访客量', $normalized['value_meaning']);
        self::assertSame('traffic_report', $normalized['section']);
        self::assertSame('needs_parser', $normalized['status']);
    }

    public function testCtripProfileAutoFetchFieldCandidatesCanBeMergedIntoFieldDirectory(): void
    {
        $controller = $this->controller();

        $payload = [
            'standard_rows' => [
                [
                    'capture_section' => 'business_overview',
                    'endpoint_id' => 'platform_notifications',
                    'data_type' => 'business',
                    'dimension' => 'catalog:business_overview:platform_notifications:new_notice_title:notifyList.0',
                    'raw_data' => [
                        'section' => 'business_overview',
                        'endpoint_id' => 'platform_notifications',
                        'facts' => [
                            [
                                'metric_key' => 'new_notice_title',
                                'metric_label' => '新通知标题',
                                'value' => '到账提醒',
                                'source_key' => 'title',
                                'source_path' => 'notifyList.0.title',
                            ],
                        ],
                        'metric_status' => 'non_numeric_fact',
                    ],
                ],
                [
                    'capture_section' => 'business_overview',
                    'endpoint_id' => 'business_realtime',
                    'data_type' => 'business',
                    'dimension' => 'catalog:business_overview:business_realtime:order_count',
                    'raw_data' => [
                        'section' => 'business_overview',
                        'endpoint_id' => 'business_realtime',
                        'facts' => [
                            [
                                'metric_key' => 'order_count',
                                'metric_label' => '订单数',
                                'value' => 18,
                                'source_key' => 'orderQuantity',
                                'source_path' => 'data.orderQuantity',
                            ],
                        ],
                    ],
                ],
                [
                    'capture_section' => 'ads_pyramid',
                    'endpoint_id' => 'queryCampaignReportList',
                    'data_type' => 'advertising',
                    'dimension' => 'catalog:ads_pyramid:queryCampaignReportList:ad_cost',
                    'raw_data' => [
                        'section' => 'ads_pyramid',
                        'endpoint_id' => 'queryCampaignReportList',
                        'facts' => [
                            [
                                'metric_key' => 'ad_cost',
                                'metric_label' => '广告花费',
                                'value' => 128.5,
                                'source_key' => 'todayCost',
                                'source_path' => 'records.0.todayCost',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $candidates = $this->invokeNonPublic($controller, 'extractCtripProfileFieldCandidatesFromPayload', [$payload, '2026-06-03 20:05:26']);
        self::assertCount(3, $candidates);

        $fields = [
            'profile_field_order_count' => [
                'id' => 'profile_field_order_count',
                'field_key' => 'order_count',
                'field_name' => '订单数',
                'section' => 'business_overview',
                'data_type' => 'business',
                'source_interface' => 'business_realtime',
                'source_keys' => 'orderQuantity',
                'value_type' => 'integer',
                'unit' => '单',
                'transform_rule' => '直接取整数',
                'status' => 'confirmed',
                'enabled' => true,
                'notes' => '',
                'sort_order' => 10,
                'created_at' => '2026-06-01 00:00:00',
                'update_time' => '2026-06-01 00:00:00',
                'user_id' => null,
            ],
        ];

        $syncResult = $this->invokeNonPublic($controller, 'mergeCtripProfileAutoFetchFieldCandidates', [&$fields, $candidates]);

        self::assertSame(2, $syncResult['discovered_count']);
        self::assertSame(1, $syncResult['skipped_count']);
        self::assertSame(1, $syncResult['matched_count']);
        self::assertSame(1, $syncResult['added_count']);
        self::assertArrayNotHasKey('profile_field_new_notice_title', $fields);
        self::assertArrayHasKey('profile_field_ad_cost', $fields);
        self::assertSame('ad_cost', $fields['profile_field_ad_cost']['field_key']);
        self::assertSame('广告花费', $fields['profile_field_ad_cost']['field_name']);
        self::assertSame('pending', $fields['profile_field_ad_cost']['status']);
        self::assertFalse($fields['profile_field_ad_cost']['enabled']);
        self::assertSame('queryCampaignReportList', $fields['profile_field_ad_cost']['source_interface']);
        self::assertStringContainsString('records.0.todayCost', $fields['profile_field_ad_cost']['source_keys']);
    }

    public function testCtripProfileAutoFetchFieldCandidatesAreScopedBySection(): void
    {
        $controller = $this->controller();

        $fields = [
            'profile_field_order_count' => [
                'id' => 'profile_field_order_count',
                'field_key' => 'order_count',
                'field_name' => '订单数',
                'section' => 'business_overview',
                'data_type' => 'business',
                'source_interface' => 'business_realtime',
                'source_keys' => 'orderQuantity',
                'value_type' => 'integer',
                'unit' => '单',
                'transform_rule' => '直接取整数',
                'status' => 'confirmed',
                'enabled' => true,
                'notes' => '',
                'sort_order' => 10,
                'created_at' => '2026-06-01 00:00:00',
                'update_time' => '2026-06-01 00:00:00',
                'user_id' => null,
            ],
        ];
        $candidates = [
            [
                'field_key' => 'order_count',
                'field_name' => '订单数',
                'section' => 'business_overview',
                'data_type' => 'business',
                'source_interface' => 'business_realtime',
                'source_keys' => 'orderQuantity',
                'value_type' => 'integer',
                'unit' => '单',
                'status' => 'pending',
                'enabled' => false,
            ],
            [
                'field_key' => 'order_count',
                'field_name' => '销售数据订单数',
                'section' => 'sales_report',
                'data_type' => 'business',
                'source_interface' => 'sales_report',
                'source_keys' => 'orderCount',
                'value_type' => 'integer',
                'unit' => '单',
                'status' => 'pending',
                'enabled' => false,
            ],
        ];

        $syncResult = $this->invokeNonPublic($controller, 'mergeCtripProfileAutoFetchFieldCandidates', [&$fields, $candidates]);

        self::assertSame(2, $syncResult['discovered_count']);
        self::assertSame(1, $syncResult['matched_count']);
        self::assertSame(1, $syncResult['added_count']);
        self::assertArrayHasKey('profile_field_sales_report_order_count', $fields);
        self::assertSame('order_count', $fields['profile_field_sales_report_order_count']['field_key']);
        self::assertSame('sales_report', $fields['profile_field_sales_report_order_count']['section']);
        self::assertFalse($fields['profile_field_sales_report_order_count']['enabled']);
    }

    public function testCtripProfileCaptureFieldSectionIsClassifiedByPageUrl(): void
    {
        $controller = $this->controller();

        $outlineField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_service_score_rank',
            'field_key' => 'service_score_rank',
            'field_name' => 'Service score rank',
            'section' => 'quality_psi',
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('business_overview', $outlineField['section']);

        $weeklyField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_weekly_order_page_visitor',
            'field_key' => 'weekly_order_page_visitor',
            'field_name' => 'Weekly order page visitor',
            'section' => 'traffic_report',
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/weekReport?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('business_weekly_overview', $weeklyField['section']);

        $salesField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_order_amount',
            'field_key' => 'order_amount',
            'field_name' => 'Order amount',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('sales_report', $salesField['section']);

        $trafficField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_detail_visitor',
            'field_key' => 'detail_visitor',
            'field_name' => 'Detail visitor',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('traffic_report', $trafficField['section']);

        $commentField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_comment_rows',
            'field_key' => 'comment_rows',
            'field_name' => 'Comment rows',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/comment/commentList?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('comment_review', $commentField['section']);

        $userProfileField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_user_profile',
            'field_key' => 'user_profile',
            'field_name' => 'User profile',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/ebkgrowth/datacenter/userbehavior/user?microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('user_profile', $userProfileField['section']);

        $psiField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_psi_score',
            'field_key' => 'psi_score',
            'field_name' => 'PSI score',
            'section' => 'business_overview',
            'page_url' => 'https://ebooking.ctrip.com/toolcenter/psi/index?fromType=menu&microJump=true',
            'enabled' => true,
        ]]);
        self::assertSame('quality_psi', $psiField['section']);

        $unknownField = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_unknown',
            'field_key' => 'unknown',
            'field_name' => 'Unknown',
            'section' => 'quality_psi',
            'page_url' => 'https://ebooking.ctrip.com/example/unknown',
            'enabled' => true,
        ]]);
        self::assertSame('quality_psi', $unknownField['section']);
    }

    public function testCtripProfileDeletedDefaultModuleRestoresFromDuplicatePageUrl(): void
    {
        $controller = $this->controller();

        $defaultUrl = 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true';
        $modules = [
            'competitor_overview' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureModule', [[
                'id' => 'competitor_overview',
                'label' => '竞争圈动态-竞争圈概览',
                'page_url' => $defaultUrl,
                'primary_category' => '竞争力数据',
                'enabled' => false,
                'system' => true,
                'sort_order' => 60,
                'deleted_at' => '2026-06-04 19:00:14',
            ]]),
            'module_2de12be6' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureModule', [[
                'id' => 'module_2de12be6',
                'label' => '竞争圈概览',
                'page_url' => $defaultUrl,
                'primary_category' => '竞争力数据',
                'enabled' => true,
                'sort_order' => 5,
            ]]),
        ];

        [$merged, $changed] = $this->invokeNonPublic($controller, 'mergeDefaultCtripProfileCaptureModules', [$modules]);

        self::assertTrue($changed);
        self::assertSame('', $merged['competitor_overview']['deleted_at']);
        self::assertTrue($merged['competitor_overview']['enabled']);
        self::assertSame('竞争圈动态-竞争圈概览', $merged['competitor_overview']['label']);
        self::assertNotSame('', $merged['module_2de12be6']['deleted_at']);
        self::assertFalse($merged['module_2de12be6']['enabled']);
    }

    public function testCtripProfileDeletedAndDisabledFieldsStayOutOfCaptureScope(): void
    {
        $controller = $this->controller();

        $fields = [
            'profile_field_order_count' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
                'id' => 'profile_field_order_count',
                'field_key' => 'order_count',
                'field_name' => 'Order Count',
                'enabled' => true,
                'status' => 'confirmed',
            ]]),
            'profile_field_order_amount' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
                'id' => 'profile_field_order_amount',
                'field_key' => 'order_amount',
                'field_name' => 'Order Amount',
                'enabled' => false,
                'status' => 'confirmed',
            ]]),
            'profile_field_room_nights' => $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
                'id' => 'profile_field_room_nights',
                'field_key' => 'room_nights',
                'field_name' => 'Room Nights',
                'enabled' => true,
                'status' => 'confirmed',
                'deleted_at' => '2026-06-04 17:30:00',
                'deleted_by' => 7,
            ]]),
        ];

        $active = $this->invokeNonPublic($controller, 'activeCtripProfileCaptureFields', [$fields]);
        self::assertArrayHasKey('profile_field_order_count', $active);
        self::assertArrayHasKey('profile_field_order_amount', $active);
        self::assertArrayNotHasKey('profile_field_room_nights', $active);
        self::assertFalse($fields['profile_field_room_nights']['enabled']);
        self::assertSame('paused', $fields['profile_field_room_nights']['status']);

        $enabledMap = $this->invokeNonPublic($controller, 'ctripProfileEnabledFieldKeyMap', [$fields]);
        self::assertArrayHasKey('order_count', $enabledMap);
        self::assertArrayNotHasKey('order_amount', $enabledMap);
        self::assertArrayNotHasKey('room_nights', $enabledMap);

        $payload = $this->invokeNonPublic($controller, 'buildCtripProfileFieldConfigPayload', [$fields]);
        self::assertSame(['order_count'], $payload['allowed_field_keys']);
        self::assertSame(['business_overview'], $payload['allowed_sections']);
        self::assertCount(1, $payload['fields']);
        self::assertSame('order_count', $payload['fields'][0]['field_key']);

        self::assertSame(
            ['business_overview'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'default'], $payload, false])
        );
        self::assertSame(
            ['business_overview'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'business_overview,traffic_report'], $payload, false])
        );
        self::assertSame(
            [],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'traffic_report'], $payload, false])
        );
    }

    public function testCtripProfileCaptureSectionAliasesIncludeCommentReview(): void
    {
        $controller = $this->controller();
        $payload = [
            'allowed_sections' => ['business_overview', 'comment_review'],
            'allowed_field_keys' => ['order_count', 'comment_unreply_count'],
            'fields' => [],
        ];

        self::assertSame(
            ['comment_review'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'comment'], $payload, false])
        );
        self::assertSame(
            ['comment_review'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'review'], $payload, false])
        );
        self::assertSame(
            ['business_overview', 'comment_review'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileCaptureSectionsForRun', [['sections' => 'business,comment'], $payload, false])
        );
    }

    public function testCtripProfileFieldSampleVerificationStatusIsNormalized(): void
    {
        $controller = $this->controller();

        $matched = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_order_count',
            'field_key' => 'order_count',
            'field_name' => 'Order Count',
            'status' => 'confirmed',
            'sample_verification_status' => 'matched',
            'sample_verified_at' => '2026-06-03 23:48:00',
            'sample_verified_by' => 7,
            'verified_sample_value' => '18',
            'verified_sample_source_key' => 'orderQuantity',
            'verified_sample_source_path' => 'data.orderQuantity',
            'verified_sample_endpoint_id' => 'fetchCapacityOverviewV4',
            'verified_sample_data_date' => '2026-06-03',
            'verified_sample_hotel_name' => '门店 西安天诚',
            'verified_sample_captured_at' => '2026-06-04 13:31:26',
        ]]);
        self::assertSame('matched', $matched['sample_verification_status']);
        self::assertSame('2026-06-03 23:48:00', $matched['sample_verified_at']);
        self::assertSame(7, $matched['sample_verified_by']);
        self::assertSame('18', $matched['verified_sample_value']);
        self::assertSame('orderQuantity', $matched['verified_sample_source_key']);
        self::assertSame('data.orderQuantity', $matched['verified_sample_source_path']);
        self::assertSame('fetchCapacityOverviewV4', $matched['verified_sample_endpoint_id']);
        self::assertSame('2026-06-03', $matched['verified_sample_data_date']);
        self::assertSame('门店 西安天诚', $matched['verified_sample_hotel_name']);
        self::assertSame('2026-06-04 13:31:26', $matched['verified_sample_captured_at']);

        $mismatched = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_order_amount',
            'field_key' => 'order_amount',
            'field_name' => 'Order Amount',
            'status' => 'needs_parser',
            'sampleVerificationStatus' => 'mismatch',
            'sampleVerifiedAt' => '2026-06-03 23:49:00',
            'sampleVerifiedBy' => 8,
        ]]);
        self::assertSame('mismatched', $mismatched['sample_verification_status']);
        self::assertSame('2026-06-03 23:49:00', $mismatched['sample_verified_at']);
        self::assertSame(8, $mismatched['sample_verified_by']);

        $invalid = $this->invokeNonPublic($controller, 'normalizeCtripProfileCaptureField', [[
            'id' => 'profile_field_room_nights',
            'field_key' => 'room_nights',
            'field_name' => 'Room Nights',
            'status' => 'pending',
            'sample_verification_status' => 'unknown',
            'sample_verified_at' => '2026-06-03 23:50:00',
            'sample_verified_by' => 9,
            'verified_sample_value' => '3',
            'verified_sample_source_key' => 'quantity',
        ]]);
        self::assertSame('unverified', $invalid['sample_verification_status']);
        self::assertSame('', $invalid['sample_verified_at']);
        self::assertNull($invalid['sample_verified_by']);
        self::assertSame('', $invalid['verified_sample_value']);
        self::assertSame('', $invalid['verified_sample_source_key']);

        $summary = $this->invokeNonPublic($controller, 'summarizeCtripProfileCaptureFields', [[$matched, $mismatched, $invalid]]);
        self::assertSame(1, $summary['sample_verification_counts']['matched']);
        self::assertSame(1, $summary['sample_verification_counts']['mismatched']);
        self::assertSame(1, $summary['sample_verification_counts']['unverified']);
        self::assertSame(1, $summary['confirmed_field_count']);
        self::assertSame(2, $summary['doubtful_field_count']);
    }

    public function testCtripProfileFieldSampleVerificationStatusControlsFieldStatus(): void
    {
        $controller = $this->controller();

        self::assertSame(
            'confirmed',
            $this->invokeNonPublic($controller, 'statusForCtripProfileFieldSampleVerification', ['matched', 'needs_parser'])
        );
        self::assertSame(
            'needs_parser',
            $this->invokeNonPublic($controller, 'statusForCtripProfileFieldSampleVerification', ['mismatched', 'confirmed'])
        );
        self::assertSame(
            'paused',
            $this->invokeNonPublic($controller, 'statusForCtripProfileFieldSampleVerification', ['unverified', 'paused'])
        );
        self::assertSame(
            'pending',
            $this->invokeNonPublic($controller, 'statusForCtripProfileFieldSampleVerification', ['unverified', 'invalid_status'])
        );
    }

    public function testCtripProfileTrafficFunnelSamplesResolveConcreteValues(): void
    {
        $controller = $this->controller();
        $raw = [
            'response' => [
                [
                    'date' => '2026-06-01',
                    'hotelId' => 134396668,
                    'listExposure' => 1297,
                    'detailExposure' => 231,
                    'flowRate' => 17.81,
                    'orderFillingNum' => 9,
                    'orderSubmitNum' => 7,
                ],
                [
                    'date' => '2026-06-01',
                    'hotelId' => -1,
                    'listExposure' => 799,
                    'detailExposure' => 172,
                    'flowRate' => 21.5,
                    'orderFillingNum' => 10,
                    'orderSubmitNum' => 6,
                ],
            ],
        ];

        self::assertSame(
            [1297.0, 'listExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['page_views', [], $raw])
        );
        self::assertSame(
            [799.0, 'listExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_list_exposure', [], $raw])
        );
        self::assertSame(
            ['17.81', 'detailExposure / listExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['flow_rate', [], $raw])
        );
        self::assertSame(
            ['3.90', 'orderFillingNum / detailExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['order_fill_rate', [], $raw])
        );
        self::assertSame(
            ['77.78', 'orderSubmitNum / orderFillingNum', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['deal_rate', [], $raw])
        );
        self::assertSame(
            ['21.53', 'detailExposure / listExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_flow_rate', [], $raw])
        );
        self::assertSame(
            ['5.81', 'orderFillingNum / detailExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_order_fill_rate', [], $raw])
        );
        self::assertSame(
            ['60.00', 'orderSubmitNum / orderFillingNum', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_deal_rate', [], $raw])
        );
        self::assertSame(
            [1297.0, 'listExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_list_exposure', [], $raw])
        );
        self::assertSame(
            [799.0, 'listExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_list_exposure', [], $raw])
        );
        self::assertSame(
            ['17.81', 'detailExposure / listExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_flow_rate', [], $raw])
        );
        self::assertSame(
            ['21.53', 'detailExposure / listExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_flow_rate', [], $raw])
        );
        self::assertSame(
            ['3.90', 'orderFillingNum / detailExposure', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_order_fill_rate', [], $raw])
        );
        self::assertSame(
            ['5.81', 'orderFillingNum / detailExposure', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_order_fill_rate', [], $raw])
        );
        self::assertSame(
            ['77.78', 'orderSubmitNum / orderFillingNum', 'raw_data.response.[0]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_deal_rate', [], $raw])
        );
        self::assertSame(
            ['60.00', 'orderSubmitNum / orderFillingNum', 'raw_data.response.[1]'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_deal_rate', [], $raw])
        );

        $storedQunarCompetitorRow = [
            'id' => 99,
            'source' => 'qunar',
            'platform' => 'Qunar',
            'compare_type' => 'competitor',
            'list_exposure' => 799,
            'detail_exposure' => 172,
            'flow_rate' => 21.5,
            'order_filling_num' => 10,
            'order_submit_num' => 6,
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure+detail_visitor+flow_rate:0',
        ];
        self::assertSame(
            [799.0, 'listExposure', 'online_daily_data#99'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_list_exposure', $storedQunarCompetitorRow, []])
        );
        self::assertSame(
            ['21.53', 'detailExposure / listExposure', 'online_daily_data#99'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['qunar_competitor_flow_rate', $storedQunarCompetitorRow, []])
        );
    }

    public function testCtripProfileOnlineDailySamplesResolveLegacyFieldAliases(): void
    {
        $controller = $this->controller();
        $row = [
            'id' => 9419,
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'compare_type' => 'self',
            'data_value' => 34,
            'book_order_num' => 2,
        ];
        $raw = [
            'row' => [
                'raw_data' => [
                    'metrics' => [
                        'visitor_count' => 15,
                        'conversion_rate' => 100,
                    ],
                ],
            ],
        ];
        $rawMap = $this->invokeNonPublic($controller, 'flattenCtripProfileRawValues', [$raw]);

        $lastVisitorKeys = array_merge(
            ['last_visitor_total', 'lastVisitorTotal'],
            $this->invokeNonPublic($controller, 'onlineDailyDataSampleAliases', ['last_visitor_total'])
        );
        self::assertSame(
            [15, 'visitor_count', 'online_daily_data#9419'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', ['last_visitor_total', $row, $raw, $rawMap, $lastVisitorKeys])
        );

        $closeRateKeys = array_merge(
            ['close_rate', 'closeRate'],
            $this->invokeNonPublic($controller, 'onlineDailyDataSampleAliases', ['close_rate'])
        );
        self::assertSame(
            [100, 'conversion_rate', 'online_daily_data#9419'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', ['close_rate', $row, $raw, $rawMap, $closeRateKeys])
        );

        $rankRow = [
            'id' => 9977,
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'compare_type' => 'self',
            'data_type' => 'ranking',
            'dimension' => 'catalog:business_overview:business_hotel_seq:seq_rank:data',
        ];
        $rankRaw = [
            'row' => [
                'raw_data' => [
                    'facts' => [
                        [
                            'metric_key' => 'seq_rank',
                            'metric_label' => '实时排名',
                            'value' => 550,
                            'source_key' => 'rank',
                            'source_path' => 'data.rank',
                        ],
                        [
                            'metric_key' => 'seq_rank',
                            'metric_label' => '实时排名',
                            'value' => 0,
                            'source_key' => 'competitorRank',
                            'source_path' => 'data.competitorRank',
                        ],
                    ],
                    'metrics' => [
                        'seq_rank' => null,
                    ],
                    'rank_metrics' => [
                        'seq_rank' => null,
                    ],
                ],
            ],
        ];
        $rankRawMap = $this->invokeNonPublic($controller, 'flattenCtripProfileRawValues', [$rankRaw]);
        self::assertSame(
            [550, 'rank', 'data.rank'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', ['seq_rank', $rankRow, $rankRaw, $rankRawMap, ['seq_rank', 'rank']])
        );
    }

    public function testCtripProfileSampleBucketUsesSectionForRepeatedMetricKey(): void
    {
        $controller = $this->controller();
        $scopes = [
            'order_amount' => [
                'business_overview:order_amount' => true,
                'sales_report:order_amount' => true,
            ],
            'flow_rate' => [
                'traffic_report:flow_rate' => true,
            ],
        ];

        self::assertFalse($this->invokeNonPublic($controller, 'shouldSkipCtripProfileOnlineDailySampleSection', [
            'ctrip_comment_count',
            'business_overview',
            'traffic_report',
            ['ctrip_comment_count' => 1],
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'shouldSkipCtripProfileOnlineDailySampleSection', [
            'order_amount',
            'business_overview',
            'sales_report',
            ['order_amount' => 2],
        ]));
        self::assertSame(
            'sales_report:order_amount',
            $this->invokeNonPublic($controller, 'ctripProfileSampleBucketKeyForRow', ['order_amount', 'sales_report', $scopes])
        );
        self::assertNull(
            $this->invokeNonPublic($controller, 'ctripProfileSampleBucketKeyForRow', ['order_amount', '', $scopes])
        );
        self::assertSame(
            'traffic_report:flow_rate',
            $this->invokeNonPublic($controller, 'ctripProfileSampleBucketKeyForRow', ['flow_rate', '', $scopes])
        );
    }

    public function testCtripProfileOnlineDailySampleSectionResolvesFromRawOrDimension(): void
    {
        $controller = $this->controller();

        self::assertSame(
            'traffic_report',
            $this->invokeNonPublic($controller, 'ctripProfileSampleSectionFromOnlineDailyRow', [
                ['dimension' => 'catalog:sales_report:manual_checkout:order_amount:self'],
                ['capture_section' => 'traffic_report'],
            ])
        );
        self::assertSame(
            'sales_report',
            $this->invokeNonPublic($controller, 'ctripProfileSampleSectionFromOnlineDailyRow', [
                ['dimension' => 'catalog:sales_report:manual_checkout:order_amount:self'],
                [],
            ])
        );
        self::assertSame(
            '',
            $this->invokeNonPublic($controller, 'ctripProfileSampleSectionFromOnlineDailyRow', [
                ['dimension' => ''],
                [],
            ])
        );
    }

    public function testCtripProfileTrafficFunnelSamplesIgnoreNonFunnelTrafficRows(): void
    {
        $controller = $this->controller();

        $visitorTitleRow = [
            'id' => 9289,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'competitor',
            'hotel_id' => '6866634',
            'list_exposure' => 0,
            'detail_exposure' => 15,
            'flow_rate' => 0,
            'order_filling_num' => 0,
            'order_submit_num' => 0,
            'dimension' => 'catalog:business_overview:business_visitor_title:visitor_count+visitor_rank+competitor_avg_visitor:root',
        ];
        $flowTransformRow = [
            'id' => 9287,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'hotel_id' => '6866634',
            'list_exposure' => 258,
            'detail_exposure' => 24,
            'flow_rate' => 9.3,
            'order_filling_num' => 6,
            'order_submit_num' => 6,
            'dimension' => 'catalog:business_overview:business_flow_transform:date+list_exposure+detail_visitor:0',
        ];

        self::assertNull($this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['competitor_list_exposure', $visitorTitleRow, []]));
        self::assertSame(
            [258.0, 'listExposure', 'online_daily_data#9287'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileTrafficDerivedSample', ['page_views', $flowTransformRow, []])
        );
    }

    public function testCtripProfileTrafficFunnelFieldsPreferOnlineDailySamples(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfilePrefersOnlineDailySamples', ['page_views']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfilePrefersOnlineDailySamples', ['flow_conversion_rate']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfilePrefersOnlineDailySamples', ['qunar_competitor_deal_rate']));
        self::assertFalse($this->invokeNonPublic($controller, 'ctripProfilePrefersOnlineDailySamples', ['order_amount']));
    }

    public function testCtripProfileTrafficScopeDoesNotTreatCompetitorRowsAsSelf(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => 'competitor',
            'hotel_id' => '6866634',
        ], 'self']));
        self::assertFalse($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => '',
            'hotel_id' => '-1',
        ], 'self']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => 'self',
            'hotel_id' => '6866634',
        ], 'self']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => 'competitor',
            'hotel_id' => '6866634',
        ], 'competitor']));
        self::assertTrue($this->invokeNonPublic($controller, 'ctripProfileTrafficRowMatchesScope', [[
            'compare_type' => '',
            'hotel_id' => '-1',
        ], 'competitor']));
    }

    public function testCtripProfilePreferredOnlineDailySamplesDoNotFallbackToWrongScopeGenericValues(): void
    {
        $controller = $this->controller();
        $competitorRow = [
            'id' => 9289,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'competitor',
            'hotel_id' => '6866634',
            'list_exposure' => 0,
        ];

        self::assertNull($this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', [
            'page_views',
            $competitorRow,
            [],
            [],
            ['page_views', 'listExposure', 'list_exposure'],
        ]));
        self::assertSame(
            [0, 'list_exposure', 'online_daily_data#9289'],
            $this->invokeNonPublic($controller, 'resolveCtripProfileOnlineDailyFieldSample', [
                'custom_metric',
                $competitorRow,
                [],
                [],
                ['list_exposure'],
            ])
        );
    }

    public function testCtripProfileTrafficRowSelectionDoesNotFallbackCompetitorAverageToSelf(): void
    {
        $controller = $this->controller();

        self::assertNull($this->invokeNonPublic($controller, 'selectCtripProfileTrafficRow', [[
            ['row' => ['hotelId' => '-1', 'listExposure' => 1463], 'path' => 'raw_data.row'],
        ], 'self']));
        self::assertSame(
            ['row' => ['listExposure' => 258], 'path' => 'raw_data.row'],
            $this->invokeNonPublic($controller, 'selectCtripProfileTrafficRow', [[
                ['row' => ['listExposure' => 258], 'path' => 'raw_data.row'],
            ], 'self'])
        );
    }

    public function testCtripProfileCaptureGateArgsDefaultToFieldCoverageThreshold(): void
    {
        $controller = $this->controller();

        $defaultArgs = $this->invokeNonPublic($controller, 'appendCtripCaptureGateArgs', [['node'], []]);

        self::assertContains('--min-field-coverage-rate=80', $defaultArgs);
        self::assertNotContains('--max-missing-fields=0', $defaultArgs);

        $customArgs = $this->invokeNonPublic($controller, 'appendCtripCaptureGateArgs', [['node'], [
            'minFieldCoverageRate' => '65.5',
            'maxMissingFields' => 4,
            'requireFieldCoverage' => true,
        ]]);

        self::assertContains('--min-field-coverage-rate=65.5', $customArgs);
        self::assertContains('--max-missing-fields=4', $customArgs);
        self::assertContains('--require-field-coverage', $customArgs);
    }

    public function testCtripLoginPreparationModeSkipsCaptureGateImport(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isCtripLoginOnlyRequest', [[
            'login_only' => true,
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripLoginOnlyRequest', [[
            'authOnly' => '1',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripLoginOnlyRequest', [[
            'login_only' => false,
        ]]));

        $args = $this->invokeNonPublic($controller, 'appendCtripLoginOnlyArg', [['node'], [
            'prepare_profile' => 'true',
        ]]);
        self::assertContains('--login-only=true', $args);

        $payload = $this->invokeNonPublic($controller, 'buildCtripLoginOnlyResponsePayload', [[
            'mode' => 'login_only',
            'profile_id' => '63',
            'auth_status' => ['status' => 'logged_in', 'message' => 'Ctrip profile is logged in.'],
            'capture_gate' => ['status' => 'skipped', 'reason' => 'login_only'],
            'pages' => [['name' => 'auth', 'ok' => true]],
        ], 'runtime/ctrip_capture/login_only.json', 'stdout text']);

        self::assertSame('login_only', $payload['mode']);
        self::assertSame('logged_in', $payload['auth_status']['status']);
        self::assertSame('skipped', $payload['capture_gate']['status']);
        self::assertSame(0, $payload['saved_count']);
        self::assertSame(0, $payload['row_count']);
        self::assertSame('runtime/ctrip_capture/login_only.json', $payload['output']);
    }

    public function testCtripCaptureDiagnosisSummaryGroupsCapturedMetricsForDiagnosis(): void
    {
        $controller = $this->controller();

        $summary = $this->invokeNonPublic($controller, 'buildCtripCaptureDiagnosisSummary', [[
            'catalog_facts' => [
                ['metric_key' => 'order_count'],
                ['metric_key' => 'list_exposure'],
                ['metric_key' => 'five_min_reply_rate'],
                ['metric_key' => 'user_age'],
            ],
            'standard_rows' => [
                [
                    'data_type' => 'business',
                    'capture_section' => 'business_overview',
                    'metric_key' => 'avg_price|tensity',
                    'dimension' => 'catalog:business_overview:business_realtime:order_amount:root',
                    'raw_data' => [
                        'metrics' => [
                            'room_nights' => 3,
                            'competitor_average' => 5,
                        ],
                    ],
                ],
            ],
        ]]);

        self::assertSame('ready', $summary['status']);
        self::assertContains('收益销售', $summary['available_groups']);
        self::assertContains('流量转化', $summary['available_groups']);
        self::assertContains('服务质量/IM', $summary['available_groups']);
        self::assertContains('辅助事实', $summary['available_groups']);
        self::assertContains('商旅BPI', $summary['missing_groups']);

        $revenue = current(array_filter($summary['groups'], static fn(array $group): bool => $group['name'] === '收益销售'));
        self::assertIsArray($revenue);
        self::assertSame('available', $revenue['status']);
        self::assertContains('order_count', $revenue['captured_metric_keys']);
        self::assertContains('order_amount', $revenue['captured_metric_keys']);
        self::assertContains('room_nights', $revenue['captured_metric_keys']);
        self::assertContains('avg_price', $revenue['captured_metric_keys']);
        self::assertContains('tensity', $revenue['captured_metric_keys']);

        $labels = array_column($summary['captured_metrics'], 'label', 'key');
        self::assertSame('预订订单数', $labels['order_count']);
        self::assertSame('5分钟回复率', $labels['five_min_reply_rate']);
    }

    public function testCtripEndpointEvidenceBundleBuildsFromDevtoolsFieldsAndRedactsSecrets(): void
    {
        $controller = $this->controller();

        $bundle = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceBundleFromRequest', [[
            'request_url' => 'https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch?_fxpcqlniredt=abc',
            'method' => 'post',
            'headers_json' => json_encode([
                'Cookie' => 'SESSION=secret-cookie',
                'Authorization' => 'Bearer secret-token',
                'Content-Type' => 'application/json',
            ], JSON_UNESCAPED_UNICODE),
            'payload_json' => json_encode([
                'nodeId' => 'ctrip-1001',
                'startDate' => '2026-05-31',
                'endDate' => '2026-05-31',
            ], JSON_UNESCAPED_UNICODE),
            'response_json' => json_encode([
                'data' => [
                    'orderList' => [[
                        'orderId' => 'CTRIP-ORDER-001',
                        'guestName' => 'Alice Zhang',
                        'guestPhone' => '90000005678',
                        'orderAmount' => '588.00',
                    ]],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'page_context_json' => json_encode(['page' => '订单管理', 'tab' => '订单明细'], JSON_UNESCAPED_UNICODE),
            'params_json' => json_encode(['hotel_id' => 'ctrip-1001', 'data_date' => '2026-05-31'], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('https://ebooking.ctrip.com/restapi/soa2/12345/orderDetailSearch?_fxpcqlniredt=abc', $bundle['request_url']);
        self::assertSame('POST', $bundle['method']);
        self::assertSame('ctrip-1001', $bundle['payload']['nodeId']);
        self::assertSame('588.00', $bundle['response']['data']['orderList'][0]['orderAmount']);
        self::assertSame('[REDACTED]', $bundle['headers']['Cookie']);
        self::assertSame('[REDACTED]', $bundle['headers']['Authorization']);

        $encoded = json_encode($bundle, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('secret-cookie', $encoded);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('CTRIP-ORDER-001', $encoded);
        self::assertStringNotContainsString('Alice Zhang', $encoded);
        self::assertStringNotContainsString('90000005678', $encoded);
    }

    public function testCtripEndpointEvidenceBundleRejectsNonCtripUrl(): void
    {
        $controller = $this->controller();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('携程接口证据只允许');

        $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceBundleFromRequest', [[
            'request_url' => 'https://evil.test/restapi/orderDetailSearch',
            'payload_json' => '{"hotelId":"ctrip-1001"}',
            'response_json' => '{"data":{}}',
        ]]);
    }

    public function testCtripCookieApiReadsCookieFromDevtoolsHeaderFormats(): void
    {
        $controller = $this->controller();

        $fromCookieLine = $this->invokeNonPublic($controller, 'readCtripCookieHeaderFromRequest', [[
            'cookies' => "Host: ebooking.ctrip.com\nCookie: foo=abc; bar=def\nAccept: application/json",
        ]]);
        self::assertSame('foo=abc; bar=def', $fromCookieLine);

        $fromHeadersJson = $this->invokeNonPublic($controller, 'readCtripCookieHeaderFromRequest', [[
            'headers_json' => json_encode([
                'Cookie' => 'foo=json; bar=1',
                'Accept' => 'application/json',
            ], JSON_UNESCAPED_UNICODE),
        ]]);
        self::assertSame('foo=json; bar=1', $fromHeadersJson);

        $fromCurl = $this->invokeNonPublic($controller, 'readCtripCookieHeaderFromRequest', [[
            'cookie' => "curl 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo' -H 'Cookie: foo=curl; bar=2'",
        ]]);
        self::assertSame('foo=curl; bar=2', $fromCurl);

        $missing = $this->invokeNonPublic($controller, 'readCtripCookieHeaderFromRequest', [[
            'headers_json' => "Accept: application/json\nUser-Agent: Mozilla/5.0",
        ]]);
        self::assertSame('', $missing);
    }

    public function testCtripCookieApiReadinessExposesNotReadyNextAction(): void
    {
        $controller = $this->controller();

        $readiness = $this->invokeNonPublic($controller, 'buildCtripCookieApiReadiness', [[
            'auth_status' => ['ok' => false, 'status' => 'no_json_response'],
            'errors' => [['error' => 'cookie_or_permission_failed']],
        ], [
            'standard_rows' => 0,
        ], [
            'saved_count' => 0,
        ], true]);

        self::assertSame('not_ready', $readiness['status']);
        self::assertFalse($readiness['is_ready']);
        self::assertStringContainsString('Cookie', $readiness['next_action']);

        $ready = $this->invokeNonPublic($controller, 'buildCtripCookieApiReadiness', [[
            'auth_status' => ['ok' => true],
        ], [
            'standard_rows' => 2,
        ], [
            'saved_count' => 2,
        ], true]);

        self::assertSame('ready', $ready['status']);
        self::assertTrue($ready['is_ready']);
        self::assertSame('', $ready['warning']);
    }

    public function testCtripEndpointEvidenceValidationPayloadExposesCatalogPreviewRows(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceValidationPayload', [[
            'evidence_status' => 'complete_redacted',
            'catalog_ready' => true,
            'safe_to_catalog' => true,
            'candidate_section' => 'homepage',
            'candidate_label' => '首页实时概览',
            'data_type' => 'business',
            'missing_evidence' => [],
            'field_mapping_draft' => ['ready_for_mapping' => true],
            'catalog_preview' => [
                'formal_endpoint' => true,
                'catalog_fact_count' => 6,
                'standard_row_count' => 1,
                'metric_keys' => ['order_amount', 'visitor_count'],
                'standard_rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'amount' => 309.0,
                    'book_order_num' => 1,
                    'raw_data' => [
                        'source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData',
                    ],
                ]],
            ],
        ], [
            'input_path' => 'runtime/ctrip_endpoint_evidence/input.json',
            'output_path' => 'reports/ctrip_endpoint_evidence.json',
            'markdown_path' => 'docs/ctrip_endpoint_evidence.md',
        ], [
            'mappings' => [],
        ], 'docs/ctrip_approved_mapping.candidate.json', '', 'node stdout']);

        self::assertSame('complete_redacted', $payload['evidence_status']);
        self::assertSame(6, $payload['catalog_preview']['catalog_fact_count']);
        self::assertSame(1, $payload['catalog_preview']['standard_row_count']);
        self::assertSame(['order_amount', 'visitor_count'], $payload['catalog_preview']['metric_keys']);
        self::assertSame(309.0, $payload['catalog_preview']['standard_rows'][0]['amount']);
        self::assertSame('https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData', $payload['catalog_preview']['standard_rows'][0]['raw_data']['source_url']);
        self::assertSame('docs/ctrip_approved_mapping.candidate.json', $payload['paths']['candidate_mapping']);
        self::assertSame(['mappings' => []], $payload['candidate_mapping']);
    }

    public function testCtripEndpointEvidenceCatalogPreviewImportPlanDefaultsToPreviewOnly(): void
    {
        $controller = $this->controller();

        $plan = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceCatalogPreviewImportPlan', [[
            'catalog_ready' => true,
            'safe_to_catalog' => true,
            'catalog_preview' => [
                'standard_rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'amount' => 309.0,
                ]],
            ],
        ], [
            'system_hotel_id' => 7,
        ]]);

        self::assertFalse($plan['requested']);
        self::assertTrue($plan['available']);
        self::assertFalse($plan['can_save']);
        self::assertSame(1, $plan['row_count']);
        self::assertSame(0, $plan['saved_count']);
        self::assertSame(7, $plan['system_hotel_id']);
        self::assertSame('2026-05-31', $plan['data_date']);
        self::assertSame([], $plan['rows']);
    }

    public function testCtripEndpointEvidenceCatalogPreviewImportPlanAllowsExplicitSafeImport(): void
    {
        $controller = $this->controller();

        $plan = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceCatalogPreviewImportPlan', [[
            'catalog_ready' => true,
            'safe_to_catalog' => true,
            'catalog_preview' => [
                'standard_rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'capture_section' => 'homepage',
                    'endpoint_id' => 'homepage_realtime',
                    'dimension' => 'catalog:homepage:homepage_realtime:order_amount:root',
                    'amount' => 309.0,
                    'raw_data' => ['metrics' => ['order_amount' => 309.0]],
                ]],
            ],
        ], [
            'save_standard_rows' => true,
            'system_hotel_id' => 7,
            'data_date' => '2026-05-31',
            'ctrip_hotel_id' => 'ctrip-1001',
        ]]);

        self::assertTrue($plan['requested']);
        self::assertTrue($plan['available']);
        self::assertTrue($plan['can_save']);
        self::assertSame(1, $plan['row_count']);
        self::assertSame(0, $plan['saved_count']);
        self::assertSame(7, $plan['system_hotel_id']);
        self::assertSame('2026-05-31', $plan['data_date']);
        self::assertSame('ctrip-1001', $plan['request_hotel_id']);
        self::assertSame(309.0, $plan['rows'][0]['amount']);
    }

    public function testCtripEndpointEvidenceCatalogPreviewImportPlanRejectsUnsafeImport(): void
    {
        $controller = $this->controller();

        $plan = $this->invokeNonPublic($controller, 'buildCtripEndpointEvidenceCatalogPreviewImportPlan', [[
            'catalog_ready' => false,
            'safe_to_catalog' => false,
            'catalog_preview' => [
                'standard_rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'amount' => 309.0,
                ]],
            ],
        ], [
            'saveStandardRows' => '1',
            'system_hotel_id' => 7,
        ]]);

        self::assertTrue($plan['requested']);
        self::assertTrue($plan['available']);
        self::assertFalse($plan['can_save']);
        self::assertSame(0, $plan['saved_count']);
        self::assertSame([], $plan['rows']);
        self::assertStringContainsString('not catalog ready', $plan['message']);
    }

    public function testCtripStandardRowsKeepNonLegacyCatalogSectionsImportable(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [[
            'standard_rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => '长沙智选假日酒店',
                    'data_date' => '2026-05-31',
                    'data_type' => 'quality',
                    'capture_section' => 'quality_psi',
                    'endpoint_id' => 'psi_overview',
                    'dimension' => 'catalog:quality_psi:psi_overview:psi_score:root',
                    'data_value' => 4.54,
                    'raw_data' => [
                        'source' => 'ctrip_catalog_facts',
                        'metrics' => ['psi_score' => '4.54'],
                    ],
                ],
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => '长沙智选假日酒店',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'capture_section' => 'business_overview',
                    'endpoint_id' => 'business_realtime',
                    'dimension' => 'catalog:business_overview:business_realtime:order_count:root',
                    'book_order_num' => 3,
                    'raw_data' => ['metrics' => ['order_count' => 3]],
                ],
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'capture_section' => 'business_overview',
                    'endpoint_id' => 'business_realtime',
                    'dimension' => 'catalog:business_overview:business_realtime:avg_price:root',
                    'data_value' => 312.5,
                    'raw_data' => ['metrics' => ['avg_price' => 312.5]],
                ],
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-06-06',
                    'data_type' => 'business',
                    'capture_section' => 'market_calendar',
                    'endpoint_id' => 'hot_calendar',
                    'dimension' => 'catalog:market_calendar:hot_calendar:hot_spot_name:0',
                    'raw_data' => [
                        'fact_only' => true,
                        'metric_status' => 'non_numeric_fact',
                        'metrics' => ['hot_spot_name' => 'Concert A'],
                    ],
                ],
            ],
        ], 7, '2026-05-31', 'ctrip-1001', null, ['psi_score', 'avg_price', 'hot_spot_name', 'order_count']]);

        self::assertCount(3, $rows);
        self::assertSame('quality', $rows[0]['data_type']);
        self::assertSame(4.54, $rows[0]['data_value']);
        self::assertSame(7, $rows[0]['system_hotel_id']);
        self::assertStringContainsString('"capture_section":"quality_psi"', $rows[0]['raw_data']);
        self::assertStringContainsString('"psi_score":"4.54"', $rows[0]['raw_data']);
        $avgPriceRow = current(array_filter($rows, static fn(array $row): bool => ($row['dimension'] ?? '') === 'catalog:business_overview:business_realtime:avg_price:root'));
        self::assertIsArray($avgPriceRow);
        self::assertSame(312.5, $avgPriceRow['data_value']);
        self::assertStringContainsString('"avg_price":312.5', $avgPriceRow['raw_data']);
        self::assertFalse((bool)current(array_filter($rows, static fn(array $row): bool => ($row['dimension'] ?? '') === 'catalog:business_overview:business_realtime:order_count:root')));
        $calendarRow = current(array_filter($rows, static fn(array $row): bool => ($row['dimension'] ?? '') === 'catalog:market_calendar:hot_calendar:hot_spot_name:0'));
        self::assertIsArray($calendarRow);
        self::assertSame('market_calendar', json_decode($calendarRow['raw_data'], true)['capture_section']);
        self::assertStringContainsString('"fact_only":true', $calendarRow['raw_data']);
        self::assertSame(0.0, $calendarRow['amount']);
    }

    public function testCtripStandardRowsKeepStableEndpointProvenance(): void
    {
        $controller = $this->controller();
        $payload = [
            'standard_rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-31',
                    'data_type' => 'quality',
                    'capture_section' => 'quality_psi',
                    'endpoint_id' => 'psi_overview',
                    'source_url' => 'https://ebooking.ctrip.com/restapi/soa2/24306/getHotelPsiV2?x-traceID=trace-1',
                    'dimension' => 'catalog:quality_psi:psi_overview:psi_score:root',
                    'data_value' => 4.54,
                    'raw_data' => [
                        'source' => 'ctrip_catalog_facts',
                        'metrics' => ['psi_score' => '4.54'],
                    ],
                ],
            ],
        ];

        $rows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$payload, 7, '2026-05-31', 'ctrip-1001', null, ['psi_score', 'psi_rank']]);

        self::assertCount(1, $rows);
        self::assertSame('browser_profile', $rows[0]['ingestion_method']);
        self::assertArrayHasKey('source_trace_id', $rows[0]);
        self::assertMatchesRegularExpression('/^ctrip:[a-f0-9]{64}$/', $rows[0]['source_trace_id']);
        self::assertLessThanOrEqual(80, strlen($rows[0]['source_trace_id']));

        $rawData = json_decode($rows[0]['raw_data'], true);
        self::assertSame('quality_psi', $rawData['capture_section']);
        self::assertSame('psi_overview', $rawData['endpoint_id']);
        self::assertSame('https://ebooking.ctrip.com/restapi/soa2/24306/getHotelPsiV2?x-traceID=trace-1', $rawData['source_url']);

        $sameRows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$payload, 7, '2026-05-31', 'ctrip-1001', null, ['psi_score', 'psi_rank']]);
        self::assertSame($rows[0]['source_trace_id'], $sameRows[0]['source_trace_id']);

        $changedPayload = $payload;
        $changedPayload['standard_rows'][0]['dimension'] = 'catalog:quality_psi:psi_overview:psi_rank:root';
        $changedRows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$changedPayload, 7, '2026-05-31', 'ctrip-1001', null, ['psi_score', 'psi_rank']]);
        self::assertNotSame($rows[0]['source_trace_id'], $changedRows[0]['source_trace_id']);
    }

    public function testCtripProfileStandardRowsPreserveQunarTrafficSourceAndPlatform(): void
    {
        $controller = $this->controller();
        $payload = [
            'standard_rows' => [[
                'hotel_id' => '-1',
                'hotel_name' => '竞争圈平均',
                'source' => 'qunar',
                'platform' => 'Qunar',
                'data_date' => '2026-06-01',
                'data_type' => 'traffic',
                'capture_section' => 'traffic_report',
                'endpoint_id' => 'traffic_flow_transform',
                'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure+detail_visitor+flow_rate:1',
                'compare_type' => 'competitor',
                'list_exposure' => 799,
                'detail_exposure' => 172,
                'flow_rate' => 21.5,
                'order_filling_num' => 10,
                'order_submit_num' => 6,
                'raw_data' => [
                    'source' => 'ctrip_catalog_facts',
                    'metrics' => ['flow_rate' => 21.5],
                ],
            ]],
        ];

        $rows = $this->invokeNonPublic($controller, 'extractCtripStandardRows', [$payload, 7, '2026-06-01', '134396668', null, ['list_exposure', 'detail_visitor', 'flow_rate']]);

        self::assertCount(1, $rows);
        self::assertSame('qunar', $rows[0]['source']);
        self::assertSame('Qunar', $rows[0]['platform']);
        self::assertSame('competitor', $rows[0]['compare_type']);
        self::assertSame(799, $rows[0]['list_exposure']);
        self::assertSame(172, $rows[0]['detail_exposure']);
        self::assertSame(21.5, $rows[0]['flow_rate']);
        self::assertSame(10, $rows[0]['order_filling_num']);
        self::assertSame(6, $rows[0]['order_submit_num']);
    }

    public function testCtripCaptureCountsExposeStandardRowsByTypeAndSection(): void
    {
        $controller = $this->controller();

        $counts = $this->invokeNonPublic($controller, 'buildCtripCaptureCounts', [[
            'business' => [['hotelId' => 'ctrip-1001', 'dataDate' => '2026-05-31', 'orderAmount' => 100]],
            'traffic' => [
                ['hotelId' => 'ctrip-1001', 'date' => '2026-05-31', 'listExposure' => 10],
                ['hotelId' => 'ctrip-1001', 'date' => '2026-05-31', 'detailUv' => 2],
            ],
            'catalog_facts' => [['metric_key' => 'psi_score']],
            'responses' => [['url' => 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2']],
            'xhr_urls' => [['url' => 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2']],
            'pages' => [
                [
                    'name' => 'sales_report',
                    'interactions' => [
                        ['text' => '销售数据', 'clicked' => true],
                        ['text' => '房型', 'clicked' => false, 'skipped' => 'not_visible'],
                    ],
                ],
                [
                    'name' => 'traffic_report',
                    'interactions' => [
                        ['text' => '手机APP', 'clicked' => true],
                        ['text' => '电脑网页版', 'clicked' => false, 'error' => 'detached'],
                    ],
                ],
            ],
            'endpoint_candidates' => [
                ['candidate_section' => 'orders_detail', 'candidate_label' => '订单明细'],
                ['candidate_section' => 'price_inventory', 'candidate_label' => '价格房态'],
                ['candidate_section' => 'orders_detail', 'candidate_label' => '订单明细'],
                ['candidate_section' => '', 'candidate_label' => ''],
            ],
            'p3_evidence_drafts' => [
                ['candidate_section' => 'orders_detail', 'evidence_status' => 'complete_redacted', 'catalog_ready' => true],
                ['candidate_section' => 'orders_detail', 'evidence_status' => 'incomplete', 'catalog_ready' => false],
                ['candidate_section' => 'promotion', 'evidence_status' => 'complete_redacted', 'catalog_ready' => true],
                ['candidate_section' => '', 'evidence_status' => '', 'catalog_ready' => false],
            ],
            'standard_rows' => [
                ['data_type' => 'quality', 'capture_section' => 'quality_psi'],
                ['data_type' => 'advertising', 'capture_section' => 'ads_pyramid'],
                ['data_type' => 'business', 'capture_section' => 'market_calendar'],
                ['data_type' => '', 'capture_section' => ''],
            ],
        ]]);

        self::assertSame(1, $counts['business']);
        self::assertSame(2, $counts['traffic']);
        self::assertSame(4, $counts['standard_rows']);
        self::assertSame(1, $counts['standard_by_data_type']['quality']);
        self::assertSame(1, $counts['standard_by_data_type']['advertising']);
        self::assertSame(1, $counts['standard_by_data_type']['business']);
        self::assertSame(1, $counts['standard_by_data_type']['unknown']);
        self::assertSame(1, $counts['standard_by_section']['quality_psi']);
        self::assertSame(1, $counts['standard_by_section']['ads_pyramid']);
        self::assertSame(1, $counts['standard_by_section']['market_calendar']);
        self::assertSame(1, $counts['standard_by_section']['unknown']);
        self::assertSame(2, $counts['pages']);
        self::assertSame(4, $counts['interaction_planned']);
        self::assertSame(2, $counts['interaction_clicked']);
        self::assertSame(1, $counts['interaction_skipped']);
        self::assertSame(1, $counts['interaction_error']);
        self::assertSame(2, $counts['interaction_by_section']['sales_report']['planned']);
        self::assertSame(1, $counts['interaction_by_section']['sales_report']['clicked']);
        self::assertSame(1, $counts['interaction_by_section']['sales_report']['skipped']);
        self::assertSame(1, $counts['interaction_by_section']['traffic_report']['error']);
        self::assertSame(4, $counts['endpoint_candidates']);
        self::assertSame(2, $counts['candidate_by_section']['orders_detail']);
        self::assertSame(1, $counts['candidate_by_section']['price_inventory']);
        self::assertSame(1, $counts['candidate_by_section']['unknown']);
        self::assertSame(4, $counts['p3_evidence_drafts']);
        self::assertSame(2, $counts['p3_evidence_ready']);
        self::assertSame(2, $counts['p3_evidence_by_section']['orders_detail']);
        self::assertSame(1, $counts['p3_evidence_by_section']['promotion']);
        self::assertSame(1, $counts['p3_evidence_by_section']['unknown']);
        self::assertSame(2, $counts['p3_evidence_by_status']['complete_redacted']);
        self::assertSame(1, $counts['p3_evidence_by_status']['incomplete']);
        self::assertSame(1, $counts['p3_evidence_by_status']['unknown']);
    }

    public function testCtripCaptureGateFailureBlocksSuccessfulImport(): void
    {
        $controller = $this->controller();

        $failed = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [[
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['auth_session', 'endpoint_coverage'],
            ],
        ]]);

        self::assertFalse($failed['accepted']);
        self::assertSame('fail', $failed['status']);
        self::assertSame(['auth_session', 'endpoint_coverage'], $failed['failed_check_ids']);

        $missing = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [[]]);
        self::assertFalse($missing['accepted']);
        self::assertSame('missing', $missing['status']);

        $passed = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [[
            'capture_gate' => [
                'status' => 'pass',
                'failed_check_ids' => [],
            ],
        ]]);

        self::assertTrue($passed['accepted']);
        self::assertSame('pass', $passed['status']);
    }

    public function testAutoFetchUsesCurrentBrowserProfileSourceOnly(): void
    {
        $controller = $this->controller();

        $sources = [
            ['id' => 10, 'platform' => 'ctrip'],
            ['id' => 9, 'platform' => 'ctrip'],
        ];
        $selected = $this->invokeNonPublic($controller, 'selectCurrentBrowserProfileDataSources', [$sources]);

        self::assertCount(1, $selected);
        self::assertSame(10, $selected[0]['id']);
    }

    public function testAutoFetchRejectsReadyBrowserProfileSourcesWithoutAuthoritativeReusableProof(): void
    {
        $controller = $this->controller();

        $sources = [
            ['id' => 14, 'platform' => 'ctrip', 'ingestion_method' => 'browser_profile', 'status' => 'waiting_config', 'enabled' => 1],
            ['id' => 13, 'platform' => 'ctrip', 'ingestion_method' => 'browser_profile', 'status' => 'success', 'enabled' => 1],
            ['id' => 12, 'platform' => 'ctrip', 'ingestion_method' => 'browser_profile', 'status' => 'ready', 'enabled' => 1],
            ['id' => 11, 'platform' => 'ctrip', 'ingestion_method' => 'browser_profile', 'status' => 'disabled', 'enabled' => 0],
            ['id' => 10, 'platform' => 'meituan', 'ingestion_method' => 'browser_profile', 'status' => 'success', 'enabled' => 1],
        ];

        $filtered = $this->invokeNonPublic($controller, 'filterCollectableBrowserProfileDataSources', [$sources, 'ctrip']);

        self::assertSame([], array_column($filtered, 'id'));
    }

    public function testCtripSoftCoverageGateFailureCanContinueWithWarning(): void
    {
        $controller = $this->controller();
        $payload = [
            'auth_status' => ['ok' => true, 'status' => 'logged_in'],
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['field_coverage'],
            ],
            'responses' => [['url' => 'https://ebooking.ctrip.com/restapi/test']],
            'business' => [['amount' => 1288.5]],
            'standard_rows' => [
                [
                    'capture_section' => 'business_overview',
                    'data_type' => 'business',
                    'amount' => 1288.5,
                ],
            ],
        ];

        $decision = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [$payload]);
        self::assertFalse($decision['accepted']);
        self::assertSame(['field_coverage'], $decision['failed_check_ids']);

        $canContinue = $this->invokeNonPublic($controller, 'canContinueCtripCaptureWithSoftGateWarning', [$payload, $decision]);
        self::assertTrue($canContinue);

        $warning = $this->invokeNonPublic($controller, 'buildCtripCaptureGateWarning', [$decision]);
        self::assertSame('warning', $warning['level']);
        self::assertSame(['field_coverage'], $warning['failed_check_ids']);
        self::assertSame([], $warning['blocking_failed_check_ids']);

        $endpointPayload = $payload;
        $endpointPayload['capture_gate']['failed_check_ids'] = ['endpoint_coverage'];
        $endpointDecision = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [$endpointPayload]);
        self::assertFalse($endpointDecision['accepted']);
        self::assertSame(['endpoint_coverage'], $endpointDecision['failed_check_ids']);
        self::assertTrue($this->invokeNonPublic($controller, 'canContinueCtripCaptureWithSoftGateWarning', [$endpointPayload, $endpointDecision]));

        $endpointWarning = $this->invokeNonPublic($controller, 'buildCtripCaptureGateWarning', [$endpointDecision]);
        self::assertSame(['endpoint_coverage'], $endpointWarning['failed_check_ids']);
        self::assertSame([], $endpointWarning['blocking_failed_check_ids']);

        $hardDecision = $this->invokeNonPublic($controller, 'buildCtripCaptureGateDecision', [[
            'capture_gate' => [
                'status' => 'fail',
                'failed_check_ids' => ['field_coverage', 'standard_rows'],
            ],
        ]]);
        self::assertFalse($this->invokeNonPublic($controller, 'canContinueCtripCaptureWithSoftGateWarning', [$payload, $hardDecision]));
    }

    public function testNormalizeCtripCookieApiPayloadDefaultsForPostEndpoints(): void
    {
        $controller = $this->controller();

        $scanFlowPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $scanFlowPayload['hostType'] ?? '');
        self::assertSame('EBK', $scanFlowPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $scanFlowPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $scanFlowPayload['endDate'] ?? '');

        $bpiPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://bbk.ctripbiz.cn/api/getBbkComprehensiveTable',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $bpiPayload['hostType'] ?? '');
        self::assertSame('2026-06-10', $bpiPayload['date'] ?? '');
        self::assertSame('2026-06-10', $bpiPayload['reportDate'] ?? '');

        $userPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryUserSex',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $userPayload['hostType'] ?? '');
        self::assertSame('EBK', $userPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $userPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $userPayload['endDate'] ?? '');
        self::assertSame('24588', $userPayload['hotelId'] ?? '');

        $imPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/getImIndex',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $imPayload['hostType'] ?? '');
        self::assertSame('EBK', $imPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $imPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $imPayload['endDate'] ?? '');
        self::assertSame('24588', $imPayload['hotelId'] ?? '');

        $competingRankPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/getCompetingRank',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $competingRankPayload['hostType'] ?? '');
        self::assertSame('EBK', $competingRankPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $competingRankPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $competingRankPayload['endDate'] ?? '');
        self::assertSame('24588', $competingRankPayload['nodeId'] ?? '');

        $orderTrendPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryOrderTrendV1',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $orderTrendPayload['hostType'] ?? '');
        self::assertSame('EBK', $orderTrendPayload['platform'] ?? '');
        self::assertSame('2026-06-10', $orderTrendPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $orderTrendPayload['endDate'] ?? '');

        $tripartitePayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/getTripartiteOrderLoss',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $tripartitePayload['hostType'] ?? '');
        self::assertSame('EBK', $tripartitePayload['platform'] ?? '');
        self::assertSame('2026-06-10', $tripartitePayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $tripartitePayload['endDate'] ?? '');
        self::assertSame('24588', $tripartitePayload['hotelId'] ?? '');

        $campaignPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/pyramidad/api/queryCampaignSummaryReport',
            'POST',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('HE', $campaignPayload['hostType'] ?? '');
        self::assertSame('EBK', $campaignPayload['platform'] ?? '');
        self::assertSame(1, $campaignPayload['pageIndex'] ?? 0);
        self::assertSame(20, $campaignPayload['pageSize'] ?? 0);
        self::assertSame('2026-06-10', $campaignPayload['startDate'] ?? '');
        self::assertSame('2026-06-10', $campaignPayload['endDate'] ?? '');
    }

    public function testNormalizeCtripCookieApiPayloadDefaultsKeepsExistingPayloadWhenProvided(): void
    {
        $controller = $this->controller();

        $payloadWithManual = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
            'POST',
            ['hostType' => 'CUSTOM', 'startDate' => '2026-01-01', 'endDate' => '2026-01-02'],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame('CUSTOM', $payloadWithManual['hostType'] ?? '');
        self::assertSame('2026-01-01', $payloadWithManual['startDate'] ?? '');
        self::assertSame('2026-01-02', $payloadWithManual['endDate'] ?? '');

        $getPayload = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiPayloadDefaults', [
            'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
            'GET',
            [],
            '2026-06-10',
            '24588',
        ]);
        self::assertSame([], $getPayload);
    }

    public function testNormalizeCtripCookieApiEndpointsFromRequestSupportsJsonListAndDefaults(): void
    {
        $controller = $this->controller();

        $endpoints = $this->invokeNonPublic($controller, 'normalizeCtripCookieApiEndpointsFromRequest', [
            [
                'data_date' => '2026-06-10',
                'endpoints_json' => json_encode([
                    [
                        'request_url' => 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo',
                        'method' => 'GET',
                    ],
                    [
                        'request_url' => 'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
                        'method' => 'POST',
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
            '2026-06-10',
            '24588',
        ]);
        self::assertCount(2, $endpoints);
        self::assertSame('GET', $endpoints[0]['method']);
        self::assertSame('POST', $endpoints[1]['method']);
        self::assertSame([], $endpoints[0]['payload']);
        self::assertSame('HE', $endpoints[1]['payload']['hostType'] ?? '');
        self::assertSame('2026-06-10', $endpoints[1]['payload']['startDate'] ?? '');
    }

    public function testCtripCookieApiTrafficPresetBuildsTodayMetricsAndFourTrustedSearchRequests(): void
    {
        $controller = $this->controller();

        $endpoints = $this->invokeNonPublic($controller, 'buildCtripCookieApiPresetEndpoints', [
            'traffic_report',
            'VAULT_SPIDERKEY_SENTINEL',
        ]);

        self::assertCount(7, $endpoints);
        $realtimeEndpoint = $endpoints[0];
        self::assertSame('https://ebooking.ctrip.com/datacenter/api/biddingajax/fetchCurrentHotelSeqInfoV1', $realtimeEndpoint['request_url']);
        self::assertSame('POST', $realtimeEndpoint['method']);
        self::assertSame('traffic_report', $realtimeEndpoint['section']);

        self::assertSame('https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchVisitorTitleV2', $endpoints[1]['request_url']);
        self::assertSame('https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate', $endpoints[2]['request_url']);
        self::assertSame('POST', $endpoints[1]['method']);
        self::assertSame('POST', $endpoints[2]['method']);

        $searchEndpoints = array_slice($endpoints, 3);
        self::assertSame([0, 3, 0, 3], array_column(array_column($searchEndpoints, 'payload'), 'dataType'));
        self::assertSame(['0', '0', '1', '1'], array_column(array_column($searchEndpoints, 'payload'), 'searchType'));
        foreach ($searchEndpoints as $endpoint) {
            self::assertSame('POST', $endpoint['method']);
            self::assertStringContainsString('querySearchFlowDetails', $endpoint['request_url']);
            self::assertSame('traffic_report', $endpoint['section']);
            self::assertSame('Ctrip', $endpoint['payload']['platform']);
            self::assertSame('', $endpoint['payload']['fingerPrintKeys']);
            self::assertSame('2.0', $endpoint['payload']['spiderVersion']);
            self::assertSame('VAULT_SPIDERKEY_SENTINEL', $endpoint['payload']['spiderkey']);
        }
    }

    public function testCtripSearchOpportunityPayloadCombinesFourScopesAndPreservesZeroValues(): void
    {
        $controller = $this->controller();
        $rows = [];
        foreach ([
            ['cumulative', 'self', 0, 3, 0.0],
            ['cumulative', 'competitor_avg', 10, 7, 2.87],
            ['yesterday', 'self', 0, 0, 0.0],
            ['yesterday', 'competitor_avg', 4, 3, 1.25],
        ] as [$window, $scope, $pv, $uv, $conversion]) {
            $rows[] = [
                'data_date' => '2026-07-11',
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => $window === 'cumulative' ? 'browser_profile' : 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'captured_at' => '2026-07-11T08:00:00+08:00',
                    'metric_status' => 'partial',
                    'missing_fields' => ['future_search_order_count'],
                    'dimension_values' => [
                        'target_date' => '2026-07-12',
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $uv,
                        'future_search_conversion_rate' => $conversion,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [$rows, '2026-07-11']);

        self::assertSame('ready', $payload['status']);
        self::assertSame('ctrip_ota_channel', $payload['source_scope']);
        self::assertSame(4, $payload['scope_count']);
        self::assertSame('field_missing', $payload['order_data_status']);
        self::assertSame(['browser_profile', 'ctrip_cookie_api'], $payload['ingestion_methods']);
        self::assertCount(1, $payload['dates']);
        self::assertSame('2026-07-12', $payload['window_start_date']);
        self::assertSame('2026-07-12', $payload['window_end_date']);
        self::assertSame(0, $payload['dates'][0]['cumulative']['self']['pv']);
        self::assertSame(7, $payload['dates'][0]['cumulative']['competitor_avg']['uv']);
        self::assertSame(1.25, $payload['dates'][0]['yesterday']['competitor_avg']['conversion_rate']);
        self::assertNull($payload['dates'][0]['yesterday']['self']['order_count']);

        array_pop($rows);
        $partial = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [$rows, '2026-07-11']);
        self::assertSame('partial', $partial['status']);
        self::assertContains('yesterday:competitor_avg', $partial['missing_scopes']);
    }

    public function testCtripSearchOpportunityUsesObservedDatesAndKeepsHistoricalSelfReferenceSeparate(): void
    {
        $controller = $this->controller();
        $makeRow = static function (
            string $dataDate,
            string $targetDate,
            string $window,
            string $scope,
            int $pv,
            int $uv
        ): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'captured_at' => $dataDate . 'T12:00:00Z',
                    'dimension_values' => [
                        'target_date' => $targetDate,
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $uv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 1.5,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };

        $currentRows = [
            $makeRow('2026-07-11', '2026-07-11', 'cumulative', 'self', 99, 88),
            $makeRow('2026-07-11', '2026-07-12', 'cumulative', 'self', 8, 6),
            $makeRow('2026-07-11', '2026-07-12', 'cumulative', 'competitor_avg', 10, 7),
            $makeRow('2026-07-11', '2026-07-12', 'yesterday', 'self', 3, 3),
            $makeRow('2026-07-11', '2026-07-12', 'yesterday', 'competitor_avg', 7, 5),
        ];
        $referenceRows = [
            $makeRow('2026-07-10', '2026-07-12', 'cumulative', 'self', 66, 51),
            $makeRow('2026-07-10', '2026-07-12', 'cumulative', 'competitor_avg', 312, 205),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            $currentRows,
            '2026-07-11',
            $referenceRows,
            '2026-07-10',
        ]);

        self::assertCount(2, $payload['dates']);
        self::assertSame('2026-07-11', $payload['dates'][0]['target_date']);
        self::assertSame('2026-07-12', $payload['dates'][1]['target_date']);
        self::assertSame('2026-07-10', $payload['reference_capture_date']);
        self::assertSame(66, $payload['dates'][1]['cumulative']['self_reference']['pv']);
        self::assertArrayNotHasKey('self_reference', $payload['dates'][0]['cumulative']);
        self::assertSame(0, $payload['reference_covered_gap_count']);
        self::assertSame('partial', $payload['status']);
    }

    public function testCtripSearchOpportunityCurrentViewUsesOnlyLatestSameDayCaptureBatch(): void
    {
        $controller = $this->controller();
        $makeRow = static function (int $id, string $capturedAt, string $targetDate, string $scope, int $pv): array {
            return [
                'id' => $id,
                'data_date' => '2026-07-12',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'captured_at' => $capturedAt,
                    'dimension_values' => [
                        'target_date' => $targetDate,
                        'search_window' => 'cumulative',
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $pv,
                        'future_search_conversion_rate' => 1.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };

        $selection = $this->invokeNonPublic($controller, 'selectLatestCtripSearchOpportunityCaptureBatch', [[
            $makeRow(1, '2026-07-11T19:45:40.425Z', '2026-07-11', 'self', 1652),
            $makeRow(2, '2026-07-11T19:45:40.425Z', '2026-07-11', 'competitor_avg', 1023),
            $makeRow(3, '2026-07-12T07:13:05.000Z', '2026-07-12', 'self', 1486),
            $makeRow(4, '2026-07-12T07:13:05.000Z', '2026-07-12', 'competitor_avg', 808),
        ]]);

        self::assertSame('2026-07-12T07:13:05.000Z', $selection['latest_captured_at']);
        self::assertSame(2, $selection['capture_batch_count']);
        self::assertSame(2, $selection['historical_row_count']);
        self::assertSame([3, 4], array_column($selection['rows'], 'id'));
    }

    public function testCtripSearchOpportunityKeepsObservedCumulativeAndYesterdayStartDates(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $targetDate, string $window, string $scope, int $pv): array {
            return [
                'data_date' => '2026-07-12',
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => $targetDate,
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $pv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 1.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };
        $rows = [
            $makeRow('2026-07-11', 'cumulative', 'self', 10),
            $makeRow('2026-07-11', 'cumulative', 'competitor_avg', 20),
            $makeRow('2026-07-12', 'yesterday', 'self', 3),
            $makeRow('2026-07-12', 'yesterday', 'competitor_avg', 5),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [$rows, '2026-07-12']);

        self::assertSame('2026-07-11', $payload['window_start_date']);
        self::assertSame('2026-07-12', $payload['window_end_date']);
        self::assertSame(10, $payload['dates'][0]['cumulative']['self']['pv']);
        self::assertSame(3, $payload['dates'][1]['yesterday']['self']['pv']);
    }

    public function testCtripSearchOpportunityPromotesPreviousSnapshotIntoMissingYesterdayScopes(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $targetDate, string $window, string $scope, int $pv): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => $targetDate,
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $pv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 1.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };
        $currentRows = [
            $makeRow('2026-07-12', '2026-07-12', 'cumulative', 'self', 8),
            $makeRow('2026-07-12', '2026-07-12', 'cumulative', 'competitor_avg', 10),
        ];
        $referenceRows = [
            $makeRow('2026-07-11', '2026-07-12', 'yesterday', 'self', 3),
            $makeRow('2026-07-11', '2026-07-12', 'yesterday', 'competitor_avg', 7),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            $currentRows,
            '2026-07-12',
            $referenceRows,
            '2026-07-11',
        ]);

        self::assertSame(3, $payload['dates'][0]['yesterday']['self']['pv']);
        self::assertSame(7, $payload['dates'][0]['yesterday']['competitor_avg']['pv']);
        self::assertSame('historical_reference', $payload['dates'][0]['yesterday']['self']['metric_status']);
    }

    public function testCtripSearchOpportunityUsesLatestHistoricalYesterdayValueAcrossSnapshots(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $window, string $scope, int $pv): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => '2026-07-11',
                        'search_window' => $window,
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $pv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 1.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };
        $currentRows = [
            $makeRow('2026-07-12', 'cumulative', 'self', 20),
            $makeRow('2026-07-12', 'cumulative', 'competitor_avg', 30),
        ];
        $referenceRows = [
            $makeRow('2026-07-10', 'yesterday', 'self', 5),
            $makeRow('2026-07-10', 'yesterday', 'competitor_avg', 8),
            $makeRow('2026-07-11', 'yesterday', 'self', 6),
            $makeRow('2026-07-11', 'yesterday', 'competitor_avg', 9),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            $currentRows,
            '2026-07-12',
            $referenceRows,
            '2026-07-11',
        ]);

        self::assertSame(6, $payload['dates'][0]['yesterday']['self']['pv']);
        self::assertSame(9, $payload['dates'][0]['yesterday']['competitor_avg']['pv']);
        self::assertSame('2026-07-11', $payload['dates'][0]['yesterday']['self']['reference_capture_date']);
    }

    public function testCtripSearchOpportunityDerivesMissingYesterdayPvUvFromCumulativeDelta(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $scope, int $pv, int $uv): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => '2026-07-11',
                        'search_window' => 'cumulative',
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => $pv,
                        'future_search_uv' => $uv,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 2.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };
        $currentRows = [
            $makeRow('2026-07-12', 'self', 249, 144),
            $makeRow('2026-07-12', 'competitor_avg', 162, 107),
        ];
        $referenceRows = [
            $makeRow('2026-07-11', 'self', 244, 140),
            $makeRow('2026-07-11', 'competitor_avg', 160, 105),
        ];

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            $currentRows,
            '2026-07-12',
            $referenceRows,
            '2026-07-11',
        ]);

        self::assertSame(5, $payload['dates'][0]['yesterday']['self']['pv']);
        self::assertSame(4, $payload['dates'][0]['yesterday']['self']['uv']);
        self::assertSame(2, $payload['dates'][0]['yesterday']['competitor_avg']['pv']);
        self::assertSame(2, $payload['dates'][0]['yesterday']['competitor_avg']['uv']);
        self::assertNull($payload['dates'][0]['yesterday']['self']['conversion_rate']);
        self::assertSame('derived_from_cumulative_delta', $payload['dates'][0]['yesterday']['self']['metric_status']);
    }

    public function testCtripSearchOpportunityDoesNotPromoteUnchangedCumulativeSnapshotsAsZeroYesterdayFacts(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $scope): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => '2026-07-11',
                        'search_window' => 'cumulative',
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => 100,
                        'future_search_uv' => 80,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 2.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            [$makeRow('2026-07-12', 'self'), $makeRow('2026-07-12', 'competitor_avg')],
            '2026-07-12',
            [$makeRow('2026-07-11', 'self'), $makeRow('2026-07-11', 'competitor_avg')],
            '2026-07-11',
        ]);

        self::assertArrayNotHasKey('yesterday', $payload['dates'][0]);
    }

    public function testCtripSearchOpportunityDateValidationRejectsEmptyAggregateSentinel(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['0']));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['']));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['2026-07-11']));
    }

    public function testCtripSearchOpportunityLatestDateKeepsTheFullDateString(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();
        $query->valueResult = '2026-07-11';

        $latestDate = $this->invokeNonPublic($controller, 'resolveLatestCtripSearchOpportunityDate', [$query]);

        self::assertSame('2026-07-11', $latestDate);
        self::assertSame([
            ['order', 'data_date', 'desc'],
            ['value', 'data_date'],
        ], $query->calls);
    }

    public function testCtripSearchOpportunityPreviousDateUsesTheLatestEarlierCapture(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();
        $query->valueResult = '2026-07-10';

        $previousDate = $this->invokeNonPublic($controller, 'resolvePreviousCtripSearchOpportunityDate', [
            $query,
            '2026-07-11',
        ]);

        self::assertSame('2026-07-10', $previousDate);
        self::assertSame([
            ['where', 'data_date', '<', '2026-07-11'],
            ['order', 'data_date', 'desc'],
            ['value', 'data_date'],
        ], $query->calls);
    }
}

final class OnlineDataQuerySpy
{
    /**
     * @var array<int, array<int, mixed>>
     */
    public array $calls = [];

    public mixed $valueResult = null;

    public function where(string $field, mixed $value, mixed $thirdValue = null): self
    {
        $this->calls[] = func_num_args() === 3
            ? ['where', $field, $value, $thirdValue]
            : ['where', $field, $value];
        return $this;
    }

    public function whereNull(string $field): self
    {
        $this->calls[] = ['whereNull', $field];
        return $this;
    }

    public function whereIn(string $field, array $values): self
    {
        $this->calls[] = ['whereIn', $field, $values];
        return $this;
    }

    public function whereBetween(string $field, array $values): self
    {
        $this->calls[] = ['whereBetween', $field, $values];
        return $this;
    }

    public function order(string $field, string $direction): self
    {
        $this->calls[] = ['order', $field, $direction];
        return $this;
    }

    public function value(string $field): mixed
    {
        $this->calls[] = ['value', $field];
        return $this->valueResult;
    }
}
