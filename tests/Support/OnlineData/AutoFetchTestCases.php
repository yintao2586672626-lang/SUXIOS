<?php
declare(strict_types=1);

namespace Tests\Support\OnlineData;

use app\controller\OnlineData;
use app\command\PlatformProfileLogin;
use app\service\BrowserProfileCaptureRequestService;
use app\service\CtripTrafficDisplayService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\OnlineDataQuerySpy;
use Tests\Support\ReflectionHelper;
use think\App;

trait AutoFetchTestCases
{

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
        $projectRoot = dirname(__DIR__, 3);
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

    public function testAutoFetchSuccessRequiresExactCurrentRunCoreReadbackReceipt(): void
    {
        $controller = $this->controller();
        $valid = [
            'readback_verified' => true,
            'p0_status' => 'ready',
            'sync_task_id' => 901,
            'data_source_id' => 101,
            'started_at' => '2026-07-20 08:00:00',
            'row_ids' => [7001, 7002],
            'source_trace_ids' => ['f4c8e90d2c3b4a5f'],
            'verified_metric_keys' => ['revenue', 'room_nights', 'adr'],
        ];

        self::assertTrue($this->invokeNonPublic($controller, 'autoFetchRunReadbackCoreVerified', [$valid]));
        self::assertFalse($this->invokeNonPublic($controller, 'autoFetchRunReadbackCoreVerified', [array_merge($valid, [
            'sync_task_id' => 0,
        ])]));
        self::assertFalse($this->invokeNonPublic($controller, 'autoFetchRunReadbackCoreVerified', [array_merge($valid, [
            'verified_metric_keys' => ['revenue', 'room_nights'],
        ])]));
        self::assertFalse($this->invokeNonPublic($controller, 'autoFetchRunReadbackCoreVerified', [array_merge($valid, [
            'source_trace_ids' => [],
        ])]));

        $selected = $this->invokeNonPublic($controller, 'selectAutoFetchRunReadback', [[
            ['saved_count' => 99],
            ['run_readback' => array_merge($valid, ['sync_task_id' => 900])],
            ['run_readback' => $valid],
        ]]);
        self::assertSame(901, $selected['sync_task_id']);

        // A platform result is successful only when this run both wrote rows
        // and returned an exact, source-bound core-metric readback receipt.
        self::assertTrue($this->invokeNonPublic($controller, 'autoFetchPlatformRunSucceeded', [1, $valid]));
        self::assertFalse($this->invokeNonPublic($controller, 'autoFetchPlatformRunSucceeded', [0, $valid]));
        self::assertFalse($this->invokeNonPublic($controller, 'autoFetchPlatformRunSucceeded', [1, array_merge($valid, [
            'verified_metric_keys' => ['revenue', 'room_nights'],
        ])]));
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
        $projectRoot = dirname(__DIR__, 3);
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
        $projectRoot = dirname(__DIR__, 3);
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
        $projectRoot = dirname(__DIR__, 3);
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
}
