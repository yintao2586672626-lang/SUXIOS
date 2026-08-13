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
    use \Tests\Support\OnlineData\CtripTestCases;
    use \Tests\Support\OnlineData\MeituanTestCases;
    use \Tests\Support\OnlineData\ProfileTestCases;
    use \Tests\Support\OnlineData\AutoFetchTestCases;

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

    public function testStoredOnlineDataOnlyPassesAfterReadbackVerification(): void
    {
        $controller = $this->controller();

        self::assertSame(
            ['code' => 'success', 'label' => '已入库并回读'],
            $this->invokeNonPublic($controller, 'buildOnlineDataStorageStatus', [[
                'validation_status' => 'normal',
                'readback_verified' => 1,
            ]])
        );
        self::assertSame(
            ['code' => 'unverified', 'label' => '未回读验证'],
            $this->invokeNonPublic($controller, 'buildOnlineDataStorageStatus', [[
                'validation_status' => 'normal',
                'readback_verified' => 0,
            ]])
        );
        self::assertSame(
            ['code' => 'failed', 'label' => '入库校验失败'],
            $this->invokeNonPublic($controller, 'buildOnlineDataStorageStatus', [[
                'validation_status' => 'failed',
                'readback_verified' => 1,
            ]])
        );

        foreach (['invalid', 'collection_failed', 'permission_denied', 'binding_missing', 'mismatch'] as $status) {
            self::assertSame(
                ['code' => 'failed', 'label' => '入库校验失败'],
                $this->invokeNonPublic($controller, 'buildOnlineDataStorageStatus', [[
                    'validation_status' => $status,
                    'readback_verified' => 1,
                ]]),
                $status
            );
            self::assertSame(
                'failed',
                $this->invokeNonPublic($controller, 'resolveHistoryStatus', [[
                    'validation_status' => $status,
                    'readback_verified' => 1,
                ], '{}']),
                $status
            );
        }

        self::assertSame(
            ['code' => 'unverified', 'label' => '未回读验证'],
            $this->invokeNonPublic($controller, 'buildOnlineDataStorageStatus', [[
                'validation_status' => 'stale',
                'readback_verified' => 1,
            ]])
        );
        self::assertSame(
            'unverified',
            $this->invokeNonPublic($controller, 'resolveHistoryStatus', [[
                'validation_status' => 'stale',
                'readback_verified' => 1,
            ], '{}'])
        );
    }

    public function testStoredForecastDataHasReadableHistoryLabelAndMetric(): void
    {
        $controller = $this->controller();

        self::assertSame('未来预测', $this->invokeNonPublic($controller, 'historyDataTypeLabel', ['traffic_forecast']));
        self::assertSame('订单流转', $this->invokeNonPublic($controller, 'historyDataTypeLabel', ['order_flow']));
        self::assertSame(
            'T+1预测 29.00',
            $this->invokeNonPublic($controller, 'buildHistoryMetricSummary', [[
                'data_type' => 'traffic_forecast',
                'dimension' => 'flow_forecast_1',
                'data_value' => '29.00',
            ], ''])
        );
        self::assertContains(
            'traffic_forecast',
            $this->invokeNonPublic($controller, 'expandOnlineHistoryKeywordTerms', ['未来预测'])
        );
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

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 58 && $permission === 'can_fetch_online_data';
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

            public function hasHotelPermission(int $hotelId, string $permission): bool
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

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 58 && $permission === 'can_fetch_online_data';
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

            public function hasHotelPermission(int $hotelId, string $permission): bool
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

            public function hasHotelPermission(int $hotelId, string $permission): bool
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

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 118 && $permission === 'can_fetch_online_data';
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

    public function testDailyDataTypeFiltersSupportStrictMultiTypeTabs(): void
    {
        $controller = $this->controller();

        self::assertSame(
            ['traffic', 'traffic_analysis'],
            $this->invokeNonPublic($controller, 'normalizeOnlineDataTypeFilters', ['', 'traffic, traffic_analysis'])
        );
        self::assertSame(
            ['order'],
            $this->invokeNonPublic($controller, 'normalizeOnlineDataTypeFilters', ['ORDER', 'traffic,advertising'])
        );
        self::assertSame(
            ['peer_rank', 'advertising'],
            $this->invokeNonPublic($controller, 'normalizeOnlineDataTypeFilters', ['', ['peer_rank', 'advertising', 'peer_rank']])
        );
        self::assertSame(
            [],
            $this->invokeNonPublic($controller, 'normalizeOnlineDataTypeFilters', ['', 'traffic;drop'])
        );
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
                'truth' => $this->verifiedOtaTruth(),
            ],
            [
                'data_type' => 'quality',
                'data_value' => 86.5,
                'raw_data' => json_encode(['serviceScore' => 91], JSON_UNESCAPED_UNICODE),
                'truth' => $this->verifiedOtaTruth(),
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
                'truth' => $this->verifiedOtaTruth(),
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

    public function testBackendDerivesRealtimePeerStayValuesFromSelfActualsAndPercents(): void
    {
        $controller = $this->controller();

        $rows = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplayHotels', [[
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => '入住间夜榜',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 13.64, 'rank' => 8],
                            ['poiId' => 'RIVAL', 'poiName' => 'Rival Hotel', 'dataValue' => null, 'percent' => 100, 'rank' => 1],
                        ],
                    ],
                    [
                        'dimName' => '房费收入榜',
                        'aiMetricName' => 'P_RZ_ROOM_PAY',
                        'roundRanks' => [
                            ['poiId' => 'SELF', 'poiName' => 'Self Hotel', 'dataValue' => null, 'percent' => 20.08, 'rank' => 9],
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
                'roomNights' => 6,
                'roomRevenue' => 1303,
            ],
        ]]);

        $rowsByPoi = [];
        foreach ($rows as $row) {
            $rowsByPoi[$row['poiId']] = $row;
        }

        self::assertSame('6', $rowsByPoi['SELF']['roomNightsText']);
        self::assertSame('1,303', $rowsByPoi['SELF']['roomRevenueText']);
        self::assertSame(44.0, $rowsByPoi['RIVAL']['roomNights']);
        self::assertSame('44', $rowsByPoi['RIVAL']['roomNightsText']);
        self::assertSame(6489.0, $rowsByPoi['RIVAL']['roomRevenue']);
        self::assertSame('6,489', $rowsByPoi['RIVAL']['roomRevenueText']);
        self::assertSame('按本店值和美团百分比推导', $rowsByPoi['RIVAL']['metricSourceStatus']['roomNights']);
        self::assertSame('按本店值和美团百分比推导', $rowsByPoi['RIVAL']['metricSourceStatus']['roomRevenue']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['roomNights']['method']);
        self::assertSame('self_value_times_row_percent_div_self_percent', $rowsByPoi['RIVAL']['metricDerived']['roomRevenue']['method']);

        $summary = $this->invokeNonPublic($controller, 'buildMeituanBusinessDisplaySummary', [$rows, ['date_range' => '0']]);
        self::assertSame('daily_09_previous_day', $summary['data_freshness']['update_policy']);
        self::assertSame('09:00', $summary['data_freshness']['update_time']);
        self::assertFalse($summary['data_freshness']['settlement_basis']);
        self::assertStringContainsString('每日9点更新前日数据', $summary['source_notice']);
        self::assertStringContainsString('不作结算依据', $summary['source_notice']);
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

        $canonical = $this->invokeNonPublic($controller, 'mergeOnlineDataHotelList', [[
            ['system_hotel_id' => 7, 'hotel_id' => 'ota-a', 'hotel_name' => 'Stale OTA Hotel Name'],
            ['system_hotel_id' => 7, 'hotel_id' => 'ota-b', 'hotel_name' => 'Another Historical Name'],
        ], [
            7 => 'Canonical System Hotel',
        ]]);

        self::assertCount(1, $canonical);
        self::assertSame(7, $canonical[0]['id']);
        self::assertSame('Canonical System Hotel', $canonical[0]['hotel_name']);

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
        $sourceUrlHash = str_repeat('d', 64);
        $raw = [
            'source_trace_id' => 'ctrip:test-trace',
            'source_url_hash' => $sourceUrlHash,
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
                        'source_url_hash' => $sourceUrlHash,
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

    public function testP0TrafficRequirementsRespectPlatformSemantics(): void
    {
        $controller = $this->controller();

        self::assertSame(
            ['list_exposure', 'detail_exposure', 'flow_rate'],
            $this->invokeNonPublic($controller, 'phase1P0TrafficRequiredMetricKeys', ['meituan'])
        );
        self::assertSame(
            ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'],
            $this->invokeNonPublic($controller, 'phase1P0TrafficRequiredMetricKeys', ['ctrip'])
        );
        self::assertSame(
            ['online_daily_data.list_exposure', 'online_daily_data.detail_exposure', 'online_daily_data.flow_rate'],
            $this->invokeNonPublic($controller, 'phase1P0TrafficRequiredStorageFields', ['meituan'])
        );
        self::assertSame(
            ['list_exposure', 'detail_exposure', 'flow_rate'],
            $this->invokeNonPublic($controller, 'collectionStatusRequiredTrafficMetrics', ['meituan'])
        );

        $meituanClosure = $this->invokeNonPublic($controller, 'collectionStatusTrafficFieldFactClosure', [[
            'data_source_id' => 68,
            'field_facts' => [
                $this->completeTrafficFieldFact('list_exposure'),
                $this->completeTrafficFieldFact('detail_exposure'),
                $this->completeTrafficFieldFact('flow_rate'),
            ],
        ], 'meituan']);
        self::assertTrue($meituanClosure['complete']);
        self::assertSame([], $meituanClosure['missing_metric_keys']);
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

        self::assertSame('unverified', $row['status']);
        self::assertSame('hotel_operating_cycle_kernel_only', $row['source_policy']);
        self::assertCount(8, $row['workflow_chain']);
        $chain = $row['diagnostic_workflow_chain'];
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

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 7 && $permission === 'can_view_online_data';
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

    /** @return array<string, mixed> */
    private function completeTrafficFieldFact(string $metricKey): array
    {
        return [
            'metric_key' => $metricKey,
            'status' => 'captured',
            'source_path' => 'data.myHotel.' . $metricKey,
            'storage_field' => 'online_daily_data.' . $metricKey,
            'stored_value_present' => true,
            'capture_evidence' => [
                'source_trace_id' => 'trace-platform-contract',
                'source_url_hash' => str_repeat('a', 64),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function verifiedOtaTruth(): array
    {
        return [
            'status' => 'verified',
            'metric_scope' => 'ota_channel',
            'platform' => 'meituan',
            'data_date' => '2026-07-18',
            'source' => ['method' => 'browser_profile'],
            'persistence' => ['stored' => true, 'readback_verified' => true],
            'failure_reason' => '',
        ];
    }
}

final class OnlineDataQuerySpy
{
    /**
     * @var array<int, array<int, mixed>>
     */
    public array $calls = [];

    public mixed $valueResult = null;

    public function where(mixed $field, mixed $value = null, mixed $thirdValue = null): self
    {
        if ($field instanceof \Closure) {
            $nested = new self();
            $field($nested);
            $this->calls[] = ['whereGroup', $nested->calls];
            return $this;
        }
        $this->calls[] = func_num_args() === 3
            ? ['where', $field, $value, $thirdValue]
            : ['where', $field, $value];
        return $this;
    }

    public function whereOr(string $field, mixed $value): self
    {
        $this->calls[] = ['whereOr', $field, $value];
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
