<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationDispatchLedgerService;
use app\service\ManualNotificationBusinessPayloadService;
use app\service\ManualNotificationConditionRuleService;
use app\service\ManualNotificationScheduleService;
use app\service\ManualNotificationService;
use app\service\OperatingDailyReportPayloadService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/manual_notification_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, notification_type VARCHAR(40) NOT NULL, template_type VARCHAR(40) NOT NULL, source_scope VARCHAR(32) NOT NULL DEFAULT "combined", content_sections VARCHAR(512) NOT NULL DEFAULT "", business_date VARCHAR(10) NOT NULL, business_date_rule VARCHAR(24) NOT NULL DEFAULT "today", title VARCHAR(120) NOT NULL, body TEXT NOT NULL, send_method VARCHAR(32) NOT NULL, trigger_type VARCHAR(32) NOT NULL, interval_minutes INTEGER NULL, planned_send_at DATETIME NULL, active_weekdays VARCHAR(20) NOT NULL DEFAULT "1,2,3,4,5,6,7", effective_from VARCHAR(10) NULL, effective_to VARCHAR(10) NULL, hourly_start_time VARCHAR(8) NOT NULL DEFAULT "09:00:00", hourly_end_time VARCHAR(8) NOT NULL DEFAULT "22:00:00", condition_type VARCHAR(32) NOT NULL DEFAULT "always", condition_threshold REAL NULL, condition_step REAL NULL, enabled INTEGER NOT NULL, schedule_status VARCHAR(32) NOT NULL, last_test_status VARCHAR(32) NOT NULL, last_test_message VARCHAR(255) NULL, last_tested_at DATETIME NULL, last_tested_by INTEGER NULL, test_robot_id INTEGER NULL, test_robot_name VARCHAR(120) NULL, created_by INTEGER NOT NULL, create_time DATETIME NOT NULL, update_time DATETIME NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL, webhook TEXT NULL, status INTEGER NOT NULL, owner_user_id INTEGER NULL, notification_scope VARCHAR(40) NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_dispatches (id INTEGER PRIMARY KEY AUTOINCREMENT, notification_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, dispatch_window VARCHAR(32) NOT NULL, delivery_mode VARCHAR(16) NOT NULL, trigger_type VARCHAR(32) NOT NULL, request_kind VARCHAR(32) NOT NULL, business_date VARCHAR(10) NULL, payload_fingerprint VARCHAR(64) NULL, tested_plan_fingerprint VARCHAR(64) NULL, condition_rule_fingerprint VARCHAR(64) NULL, condition_trigger_bucket REAL NULL, condition_observed_value REAL NULL, source_snapshot_refs_json TEXT NULL, source_snapshot_fingerprint VARCHAR(64) NULL, operating_target_record_id INTEGER NULL, snapshot_revision_no INTEGER NULL, render_contract_version VARCHAR(48) NULL, payload_snapshot_json TEXT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, next_retry_at DATETIME NULL, last_attempt_at DATETIME NULL, response_reference VARCHAR(120) NULL, robot_id INTEGER NOT NULL, robot_name VARCHAR(120) NOT NULL, status VARCHAR(24) NOT NULL, result_code VARCHAR(64) NOT NULL, result_message VARCHAR(255) NULL, claimed_at DATETIME NOT NULL, dispatched_at DATETIME NULL, create_time DATETIME NOT NULL, update_time DATETIME NOT NULL, UNIQUE(notification_id, dispatch_window, delivery_mode))');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_rule_states (id INTEGER PRIMARY KEY AUTOINCREMENT, notification_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, business_date VARCHAR(10) NOT NULL, condition_type VARCHAR(32) NOT NULL, rule_fingerprint VARCHAR(64) NOT NULL, highest_triggered_bucket REAL NULL, pending_trigger_bucket REAL NULL, pending_dispatch_id INTEGER NULL, pending_claimed_at DATETIME NULL, last_observed_value REAL NULL, last_observed_at DATETIME NULL, last_triggered_at DATETIME NULL, last_dispatch_id INTEGER NULL, create_time DATETIME NOT NULL, update_time DATETIME NOT NULL, UNIQUE(notification_id, business_date, rule_fingerprint))');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_dispatch_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, dispatch_id INTEGER NOT NULL, notification_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, attempt_no INTEGER NOT NULL, request_kind VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, result_code VARCHAR(64) NOT NULL, result_message VARCHAR(255) NULL, payload_fingerprint VARCHAR(64) NULL, response_reference VARCHAR(120) NULL, attempted_at DATETIME NOT NULL, create_time DATETIME NOT NULL, UNIQUE(dispatch_id, attempt_no))');
        Db::name('manual_notification_dispatch_attempts')->delete(true);
        Db::name('manual_notification_rule_states')->delete(true);
        Db::name('manual_notification_schedule_dispatches')->delete(true);
        Db::name('manual_notifications')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 9, 'name' => '敦煌漠蓝新']);
    }

    public function testTemplatesUseChineseMissingAndPendingLabels(): void
    {
        $metadata = (new ManualNotificationService())->metadata('2026-07-26');

        self::assertSame(
            [
                'operating_daily_report',
                'ctrip_temporal_report',
                'operating_target_report',
                'ai_analysis_result',
                'anomaly_alert',
                'task_notification',
                'today_revenue_management',
                'future_room_status',
                'daily_review',
                'blank_custom',
            ],
            array_column($metadata['types'], 'key')
        );
        $types = array_column($metadata['types'], null, 'key');
        self::assertStringContainsString('缺失', $metadata['types'][0]['body']);
        self::assertStringContainsString('待配置', $types['future_room_status']['body']);
        self::assertTrue($types['ctrip_temporal_report']['dynamic']);
        self::assertSame(
            '携程 OTA 过去复盘、如今监控与未来需求',
            $types['ctrip_temporal_report']['data_scope_label']
        );
        self::assertContains('{酒店名称}', $metadata['variables']);
        self::assertSame(
            ['common', 'custom'],
            array_column($metadata['daily_content_template_modes'], 'key')
        );
        self::assertSame(
            '今日经营数据汇总｜PMS＋OTA',
            $metadata['daily_custom_template']['title']
        );
        self::assertStringContainsString(
            '平台采集快照，不代表发送时点状态',
            $metadata['daily_custom_template']['body']
        );
        self::assertStringNotContainsString('实时', $metadata['daily_custom_template']['body']);
        self::assertContains('{携程访客量}', $metadata['daily_template_variables']);
        self::assertNotContains('{携程实时访客量}', $metadata['daily_template_variables']);
        self::assertContains('{美团支付订单}', $metadata['daily_template_variables']);
        self::assertNotContains(
            ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
            array_column($metadata['types'], 'key')
        );
        self::assertSame(
            ['today', 'yesterday'],
            array_column($metadata['business_date_rules'], 'key')
        );
        self::assertSame(range(1, 7), array_column($metadata['weekdays'], 'key'));
        self::assertSame(
            ['combined', 'ctrip', 'meituan', 'dingdandao_pms'],
            array_column($metadata['source_scopes'], 'key')
        );
        self::assertContains(
            'meituan_conversion',
            array_column($metadata['content_sections'], 'key')
        );
        self::assertContains(
            'interval_minutes',
            array_column($metadata['trigger_types'], 'key')
        );
        self::assertSame(
            ['always', 'occupancy_ladder', 'full_house'],
            array_column($metadata['condition_rules'], 'key')
        );
        self::assertSame(
            ['manual_test', 'daily_fixed_time'],
            array_column($metadata['operating_daily_trigger_types'], 'key')
        );
        self::assertStringContainsString('不补发', $metadata['fixed_policies']['missed_window']);
        self::assertStringContainsString('明确失败', $metadata['fixed_policies']['retry']);
        self::assertStringContainsString('重复送达', $metadata['fixed_policies']['retry']);
        self::assertSame(
            '经营目标与已核验经营事实',
            $types['operating_target_report']['data_scope_label']
        );
        self::assertSame('not_deployed', $metadata['scheduler_status']);
        self::assertTrue($types['today_revenue_management']['dynamic']);
        self::assertTrue($types['future_room_status']['dynamic']);
        self::assertTrue($types['daily_review']['dynamic']);
    }

    public function testBusinessTemplateUsesVerifiedFactsInsteadOfSavedPlaceholder(): void
    {
        $service = new ManualNotificationService(
            null,
            null,
            null,
            null,
            null,
            null,
            $this->businessPayloads()
        );

        $preview = $service->preview(
            80,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' => 'today_revenue_management',
                'template_type' => 'today_revenue_management',
                'title' => '保存时占位标题',
                'body' => '保存时占位正文，不得作为事实发送。',
            ]),
            9
        );

        self::assertTrue($preview['dynamic_report']);
        self::assertTrue($preview['report_gate']['allowed']);
        self::assertNotSame('', $preview['preview_fingerprint']);
        self::assertSame(
            ManualNotificationBusinessPayloadService::FACT_ENVELOPE_VERSION,
            $preview['fact_envelope']['contract_version']
        );
        self::assertStringContainsString(
            '房费｜¥8745.60',
            $preview['payload']['markdown']['content']
        );
        self::assertStringNotContainsString(
            '保存时占位正文',
            $preview['payload']['markdown']['content']
        );
    }

    public function testBlockedBusinessTemplateDoesNotClaimPreviewAvailability(): void
    {
        $service = new ManualNotificationService(
            null,
            null,
            null,
            null,
            null,
            null,
            $this->businessPayloads()
        );

        $preview = $service->preview(
            80,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' => 'future_room_status',
                'template_type' => 'future_room_status',
            ]),
            9
        );

        self::assertTrue($preview['dynamic_report']);
        self::assertFalse($preview['report_gate']['allowed']);
        self::assertSame(
            'business_message_dingdandao_pms_not_verified',
            $preview['reason_code']
        );
        self::assertSame('preview_unavailable', $preview['delivery_status']);
        self::assertNull($preview['payload']);
    }

    public function testBusinessTemplateTestPushUsesVerifiedFactsAndDispatchLedger(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
        ]);
        $calls = [];
        $service = new ManualNotificationService(
            static function (int $hotelId, int $robotId, array $payload) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload];
                return ['delivery_status' => 'sent'];
            },
            null,
            null,
            null,
            null,
            null,
            $this->businessPayloads()
        );
        $saved = $service->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' => 'today_revenue_management',
                'template_type' => 'today_revenue_management',
                'title' => '保存时占位标题',
                'body' => '保存时占位正文，不得作为事实发送。',
            ])
        );

        $result = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'business-message-test-1'
        );

        self::assertSame('sent', $result['delivery_status']);
        self::assertSame(
            $saved['record']['updated_at'],
            Db::name('manual_notifications')
                ->where('id', (int)$saved['record']['id'])
                ->value('update_time'),
            'A test receipt must not make its own plan version appear newer than the dispatch.'
        );
        self::assertCount(1, $calls);
        self::assertStringContainsString(
            '房费｜¥8745.60',
            $calls[0][2]['markdown']['content']
        );
        self::assertStringNotContainsString(
            '保存时占位正文',
            $calls[0][2]['markdown']['content']
        );
        self::assertSame(
            ManualNotificationBusinessPayloadService::RENDER_CONTRACT_VERSIONS[
                'today_revenue_management'
            ],
            Db::name('manual_notification_schedule_dispatches')
                ->where('notification_id', (int)$saved['record']['id'])
                ->value('render_contract_version')
        );
    }

    public function testSuccessfulOldVersionTestCannotAuthorizeAConcurrentlyEditedPlan(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 27,
            'store_id' => 80,
            'name' => 'formal-operations-robot',
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
        ]);
        $notificationId = 0;
        $service = new ManualNotificationService(
            static function () use (&$notificationId): array {
                Db::name('manual_notifications')
                    ->where('id', $notificationId)
                    ->update([
                        'body' => '发送期间保存的新版本正文',
                        'schedule_status' => 'awaiting_test',
                        'last_test_status' => 'never_tested',
                        'update_time' => '2026-07-26 18:00:01',
                    ]);
                return [
                    'delivery_status' => 'sent',
                    'response_reference' => 'wecom:errcode=0',
                ];
            }
        );
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'send_method' => 'wecom_formal',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26T18:00',
            'enabled' => true,
            'target_robot_id' => 27,
            'target_robot_name' => 'formal-operations-robot',
        ]));
        $notificationId = (int)$saved['record']['id'];

        $result = $service->testPush(
            9,
            80,
            $notificationId,
            7,
            true,
            27,
            'formal-operations-robot',
            '敦煌漠蓝新',
            'concurrent-plan-version-test'
        );

        self::assertSame('sent', $result['delivery_status']);
        self::assertTrue($result['plan_changed_since_test']);
        self::assertFalse($result['formal_group_delivery_allowed']);
        self::assertStringContainsString('计划已修改', $result['message']);
        $stored = Db::name('manual_notifications')->where('id', $notificationId)->find();
        self::assertSame('发送期间保存的新版本正文', $stored['body']);
        self::assertSame('awaiting_test', $stored['schedule_status']);
        self::assertSame('never_tested', $stored['last_test_status']);
    }

    public function testOperatingDailyCustomTemplateKeepsCombinedCompatibilityAndCommonCanChooseSource(): void
    {
        $service = new ManualNotificationService();
        $custom = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'template_type' => ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
            'notification_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'source_scope' => 'ctrip',
            'content_sections' => ['ctrip_traffic'],
            'title' => '门店自定义快报',
            'body' => "门店：{酒店名称}\n携程访客：{携程访客量}",
        ]));

        self::assertSame(
            ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            $custom['record']['notification_type']
        );
        self::assertSame(
            ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
            $custom['record']['template_type']
        );
        self::assertSame('custom', $custom['record']['content_template_mode']);
        self::assertSame('combined', $custom['record']['source_scope']);
        self::assertSame(
            ['ctrip_traffic'],
            $custom['record']['content_sections']
        );
        self::assertSame('门店自定义快报', $custom['record']['title']);

        $common = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'id' => $custom['record']['id'],
            'template_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'notification_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'source_scope' => 'meituan',
            'content_sections' => ['meituan_traffic'],
            'title' => $custom['record']['title'],
            'body' => $custom['record']['body'],
        ]));

        self::assertSame('common', $common['record']['content_template_mode']);
        self::assertSame('meituan', $common['record']['source_scope']);
        self::assertSame(['meituan_traffic'], $common['record']['content_sections']);
        self::assertSame('门店自定义快报', $common['record']['title']);
        self::assertSame($custom['record']['body'], $common['record']['body']);
        self::assertSame(1, $service->history(9, 80)['total']);
    }

    public function testOperatingDailyCustomTemplateMustMatchSectionsAndDynamicDateRule(): void
    {
        $service = new ManualNotificationService();
        try {
            $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
                'template_type' =>
                    ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
                'content_sections' => ['ctrip_traffic'],
                'title' => '门店自定义快报',
                'body' => '房费：{住宿客房房费}',
            ]));
            self::fail('Unselected-section variables must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(
                'operating_daily_custom_variable_section_mismatch',
                $error->getMessage()
            );
        }

        try {
            $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
                'template_type' =>
                    ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
                'content_sections' => ['ctrip_traffic'],
                'business_date_rule' => 'yesterday',
                'title' => '2026-07-28 经营快报',
                'body' => '携程访客：{携程访客量}',
            ]));
            self::fail('A rolling plan must not freeze a literal date.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(
                'operating_daily_dynamic_date_literal_forbidden',
                $error->getMessage()
            );
        }
    }

    public function testLegacyDynamicDateLiteralBlocksBeforeSourcePreparation(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $events = [];
        $preparer = new ManualNotificationScheduleService(
            meituanTemporalRefresher: static function () use (&$events): array {
                $events[] = 'meituan';
                return ['status' => 'ready'];
            },
            ctripTemporalRefresher: static function () use (&$events): array {
                $events[] = 'ctrip';
                return ['status' => 'ready'];
            },
            pmsSourceRefresher: static function () use (&$events): array {
                $events[] = 'pms';
                return ['status' => 'ready'];
            }
        );
        $service = new ManualNotificationService(
            testDispatcher: static function () use (&$events): array {
                $events[] = 'send';
                return ['delivery_status' => 'sent'];
            },
            sourcePreparationService: $preparer
        );
        $saved = $service->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'template_type' =>
                    ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
                'notification_type' =>
                    ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'source_scope' => 'combined',
                'content_sections' => ['ctrip_traffic'],
                'business_date_rule' => 'yesterday',
                'title' => '昨日经营快报',
                'body' => '携程访客：{携程访客量}',
            ])
        );
        Db::name('manual_notifications')
            ->where('id', (int)$saved['record']['id'])
            ->update(['title' => '2026-07-28 经营快报']);

        $result = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'legacy-fixed-date-contract'
        );

        self::assertSame([], $events);
        self::assertSame('blocked', $result['delivery_status']);
        self::assertSame('blocked', $result['dispatch']['status']);
        self::assertSame(
            'operating_daily_dynamic_date_literal_forbidden',
            $result['dispatch']['result_code']
        );
        self::assertNull($result['dispatch']['next_retry_at']);
    }

    public function testScheduleRulesAreSavedReadBackAndValidated(): void
    {
        $service = new ManualNotificationService();
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'trigger_type' => 'hourly_on_the_hour',
            'business_date_rule' => 'yesterday',
            'active_weekdays' => [1, 2, 3, 4, 5],
            'effective_from' => '2026-07-20',
            'effective_to' => '2026-08-31',
            'hourly_start_time' => '09:00',
            'hourly_end_time' => '22:00',
            'enabled' => true,
        ]));

        self::assertSame('yesterday', $saved['record']['business_date_rule']);
        self::assertSame('发送前一天数据', $saved['record']['business_date_rule_label']);
        self::assertSame([1, 2, 3, 4, 5], $saved['record']['active_weekdays']);
        self::assertSame('工作日', $saved['record']['active_weekdays_label']);
        self::assertSame('2026-07-20', $saved['record']['effective_from']);
        self::assertSame('2026-08-31', $saved['record']['effective_to']);
        self::assertSame('09:00', $saved['record']['hourly_start_time']);
        self::assertSame('22:00', $saved['record']['hourly_end_time']);
        self::assertSame('任务执行正文', $saved['record']['data_scope_label']);
        self::assertSame('awaiting_test', $saved['record']['schedule_status']);
        self::assertNull($saved['record']['next_run_at']);
    }

    public function testOperatingDailyPlanRejectsMinuteInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'manual_notification_operating_daily_fixed_time_required'
        );

        $service = new ManualNotificationService(
            null,
            null,
            null,
            null,
            null,
            new OperatingDailyReportPayloadService(
                null,
                static fn(): array => [],
                static fn(): ?array => null
            )
        );
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'template_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'notification_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'title' => '携程实时经营播报',
            'body' => '正文按发送时同店同日可信事实动态生成。',
            'source_scope' => 'ctrip',
            'content_sections' => ['ctrip_traffic', 'ctrip_market'],
            'trigger_type' => 'interval_minutes',
            'interval_minutes' => 30,
            'hourly_start_time' => '09:15',
            'hourly_end_time' => '22:45',
            'enabled' => true,
        ]));
    }

    public function testHotel80StrictThreeSourceFormalPlanSupportsThirtyMinuteInterval(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 27,
            'store_id' => 80,
            'name' => '正式三源群',
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $service = new ManualNotificationService(
            null,
            null,
            null,
            null,
            null,
            new OperatingDailyReportPayloadService(
                null,
                static fn(): array => [],
                static fn(): ?array => null
            )
        );

        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'template_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'notification_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'title' => '酒店80三源经营数据',
            'body' => '订单来了 PMS、携程、美团同日真实数据汇总。',
            'source_scope' => 'combined',
            'content_sections' => [
                'pms_summary',
                'pms_efficiency',
                'ctrip_traffic',
                'meituan_traffic',
            ],
            'send_method' => 'wecom_formal',
            'trigger_type' => 'interval_minutes',
            'interval_minutes' => 30,
            'hourly_start_time' => '00:00',
            'enabled' => true,
            'target_robot_id' => 27,
            'target_robot_name' => '正式三源群',
        ]));

        self::assertSame('interval_minutes', $saved['record']['trigger_type']);
        self::assertSame(30, $saved['record']['interval_minutes']);
        self::assertSame('awaiting_test', $saved['record']['schedule_status']);
        self::assertNull($saved['record']['schedule_block_reason_code']);
        self::assertSame(
            ['pms_summary', 'pms_efficiency', 'ctrip_traffic', 'meituan_traffic'],
            $saved['record']['content_sections']
        );
        self::assertTrue(
            ManualNotificationService::isStrictThreeSourceIntervalPlan([
                ...$saved['record'],
                'hotel_id' => 80,
            ])
        );
    }

    public function testOperatingDailyCustomPlanRejectsHourlyLoop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'manual_notification_operating_daily_fixed_time_required'
        );

        (new ManualNotificationService())->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'template_type' =>
                    ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
                'notification_type' =>
                    ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'title' => '三源经营日报',
                'body' => '酒店：{酒店名称}，日期：{经营日期}',
                'trigger_type' => 'hourly_on_the_hour',
                'hourly_start_time' => '09:00',
                'hourly_end_time' => '22:00',
            ])
        );
    }

    public function testOrdinaryPlanStillSupportsMinuteInterval(): void
    {
        $saved = (new ManualNotificationService())->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'trigger_type' => 'interval_minutes',
                'interval_minutes' => 30,
                'hourly_start_time' => '09:15',
                'hourly_end_time' => '22:45',
            ])
        );

        self::assertSame('task_notification', $saved['record']['template_type']);
        self::assertSame('interval_minutes', $saved['record']['trigger_type']);
        self::assertSame(30, $saved['record']['interval_minutes']);
    }

    public function testLegacyOperatingDailyLoopReadsBackAsBlockedWithoutNextRun(): void
    {
        $service = new ManualNotificationService();
        $saved = $service->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput()
        );
        $id = (int)$saved['record']['id'];
        Db::name('manual_notifications')->where('id', $id)->update([
            'notification_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'template_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'trigger_type' => 'interval_minutes',
            'interval_minutes' => 30,
            'enabled' => 1,
            'schedule_status' => 'schedule_enabled',
        ]);

        $record = $service->history(9, 80)['list'][0];

        self::assertTrue($record['enabled']);
        self::assertFalse($record['schedule_effective_enabled']);
        self::assertSame('blocked', $record['schedule_status']);
        self::assertSame(
            '旧循环计划已停用，请改为每日固定时间',
            $record['schedule_status_label']
        );
        self::assertSame(
            'operating_daily_fixed_time_required',
            $record['schedule_block_reason_code']
        );
        self::assertNull($record['next_run_at']);
    }

    public function testHourlyScheduleRejectsInvalidBusinessWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('manual_notification_hourly_window_invalid');

        (new ManualNotificationService())->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'trigger_type' => 'hourly_on_the_hour',
                'hourly_start_time' => '22:00',
                'hourly_end_time' => '09:00',
            ])
        );
    }

    public function testScheduleRejectsEmptyWeekdaySelection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('manual_notification_weekdays_required');

        (new ManualNotificationService())->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput(['active_weekdays' => []])
        );
    }

    public function testSaveReadsBackHistoryAndKeepsUnknownValuesExplicit(): void
    {
        $service = new ManualNotificationService();
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'enabled' => true,
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26T18:00',
        ]));

        self::assertTrue($saved['readback_verified']);
        self::assertSame('awaiting_test', $saved['record']['schedule_status']);
        self::assertSame('等待一次真实测试成功后启用', $saved['preview']['schedule_status_label']);
        self::assertStringContainsString('敦煌漠蓝新', $saved['preview']['payload']['markdown']['content']);
        self::assertStringContainsString('未取得', $saved['record']['body']);
        self::assertStringNotContainsString('：0', $saved['record']['body']);

        $history = $service->history(9, 80);
        self::assertSame(1, $history['total']);
        self::assertSame($saved['record']['id'], $history['list'][0]['id']);

        $updated = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'id' => $saved['record']['id'],
            'title' => '更新后的今日收益管理',
            'body' => "【今日收益管理】\n酒店：{酒店名称}\n经营结果：未取得\n下一步：待配置",
        ]));
        self::assertSame('updated', $updated['operation']);
        self::assertSame($saved['record']['id'], $updated['record']['id']);
        self::assertSame('更新后的今日收益管理', $updated['record']['title']);
        self::assertSame(1, $service->history(9, 80)['total']);

        $this->expectException(\RuntimeException::class);
        $service->read(10, 80, (int)$saved['record']['id']);
    }

    public function testExplicitTestPushSucceedsOnlyThroughInjectedDispatcher(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
        ]);
        $calls = [];
        $service = new ManualNotificationService(
            static function (int $hotelId, int $robotId, array $payload) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload];
                return ['delivery_status' => 'sent'];
            }
        );
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput());

        $result = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'service-test-success-1'
        );

        self::assertSame('sent', $result['delivery_status']);
        self::assertCount(1, $calls);
        self::assertSame([80, 1], array_slice($calls[0], 0, 2));
        self::assertStringContainsString('明确点击的测试推送', $calls[0][2]['markdown']['content']);
        self::assertFalse($result['formal_group_delivery_allowed']);
        self::assertSame(1, Db::name('manual_notification_dispatch_attempts')->count());

        $replay = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'service-test-success-1'
        );
        self::assertTrue($replay['idempotent_replay']);
        self::assertCount(1, $calls);
    }

    public function testOperatingDailyImmediateTestPreparesExactThreeSourceBatchOnce(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
        ]);
        $events = [];
        $preparer = new ManualNotificationScheduleService(
            meituanTemporalRefresher: static function (
                array $row,
                string $businessDate
            ) use (&$events): array {
                $events[] = 'meituan';
                return [
                    'status' => 'ready',
                    'target_date' => $businessDate,
                    'sync_task_id' => 22,
                    'saved_count' => 2,
                    'readback_verified' => true,
                ];
            },
            ctripTemporalRefresher: static function (
                array $row,
                string $businessDate
            ) use (&$events): array {
                $events[] = 'ctrip';
                return [
                    'status' => 'ready',
                    'target_date' => $businessDate,
                    'sync_task_id' => 21,
                    'saved_count' => 3,
                    'readback_verified' => true,
                ];
            },
            pmsSourceRefresher: static function (
                array $row,
                string $businessDate
            ) use (&$events): array {
                $events[] = 'pms';
                return [
                    'status' => 'ready',
                    'target_date' => $businessDate,
                    'capture_id' => 301,
                    'saved_count' => 1,
                    'readback_verified' => true,
                ];
            }
        );
        $service = new ManualNotificationService(
            testDispatcher: static function () use (&$events): array {
                $events[] = 'send';
                return ['delivery_status' => 'sent'];
            },
            operatingDailyPayloads:
                $this->operatingDailyThreeSourcePayloads(),
            sourcePreparationService: $preparer
        );
        $saved = $service->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' =>
                    ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'template_type' =>
                    ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'source_scope' => 'combined',
                'content_sections' => [
                    'pms_summary',
                    'ctrip_traffic',
                    'meituan_traffic',
                ],
                'title' => '三源经营日报',
                'body' => '按勾选内容动态生成。',
            ])
        );

        $result = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'operating-daily-three-source-once'
        );

        self::assertSame(['pms', 'meituan', 'ctrip', 'send'], $events);
        self::assertSame('sent', $result['delivery_status']);
        self::assertSame(
            'operating_daily_prepared_snapshot_matched',
            $result['prepared_snapshot_gate']['reason_code']
        );
        self::assertSame(
            301,
            $result['dispatch']['source_snapshot_refs']['pms']['record_id']
        );

        $replay = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'operating-daily-three-source-once'
        );
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame(['pms', 'meituan', 'ctrip', 'send'], $events);
    }

    public function testOperatingDailyImmediateTestRejectsPreparedSnapshotMismatch(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
        ]);
        $sendCalls = 0;
        $service = new ManualNotificationService(
            testDispatcher: static function () use (&$sendCalls): array {
                $sendCalls++;
                return ['delivery_status' => 'sent'];
            },
            operatingDailyPayloads:
                $this->operatingDailyThreeSourcePayloads(),
            sourcePreparationService: new ManualNotificationScheduleService(
                meituanTemporalRefresher: static fn(
                    array $row,
                    string $businessDate
                ): array => [
                    'status' => 'ready',
                    'target_date' => $businessDate,
                    'sync_task_id' => 22,
                    'saved_count' => 2,
                    'readback_verified' => true,
                ],
                ctripTemporalRefresher: static fn(
                    array $row,
                    string $businessDate
                ): array => [
                    'status' => 'ready',
                    'target_date' => $businessDate,
                    'sync_task_id' => 21,
                    'saved_count' => 3,
                    'readback_verified' => true,
                ],
                pmsSourceRefresher: static fn(
                    array $row,
                    string $businessDate
                ): array => [
                    'status' => 'ready',
                    'target_date' => $businessDate,
                    'capture_id' => 999,
                    'saved_count' => 1,
                    'readback_verified' => true,
                ]
            )
        );
        $saved = $service->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' =>
                    ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'template_type' =>
                    ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'source_scope' => 'combined',
                'content_sections' => [
                    'pms_summary',
                    'ctrip_traffic',
                    'meituan_traffic',
                ],
                'title' => '三源经营日报',
                'body' => '按勾选内容动态生成。',
            ])
        );

        $result = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'operating-daily-snapshot-mismatch'
        );

        self::assertSame(0, $sendCalls);
        self::assertSame('blocked', $result['delivery_status']);
        self::assertSame(
            'operating_daily_pms_prepared_snapshot_mismatch',
            $result['dispatch']['result_code']
        );
        self::assertFalse($result['prepared_snapshot_gate']['allowed']);
        self::assertSame(
            'blocked',
            Db::name('manual_notifications')
                ->where('id', (int)$saved['record']['id'])
                ->value('last_test_status')
        );
    }

    public function testMeituanBlankCustomPreviewOmitsInternalDeliveryMetadata(): void
    {
        $preview = (new ManualNotificationService())->preview(
            80,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' => 'blank_custom',
                'template_type' => 'blank_custom',
                'source_scope' => 'meituan',
                'title' => '美团今日实时数据｜本地采集测试',
                'body' => "门店：{酒店名称}\n业务日：{经营日期}\n采集完成：2026-07-30 01:26:11",
            ]),
            9
        );

        self::assertSame(
            "美团今日实时数据｜本地采集测试\n"
                . "门店：敦煌漠蓝新\n"
                . "业务日：2026-07-26\n"
                . '采集完成：2026-07-30 01:26:11',
            $preview['payload']['markdown']['content']
        );
        foreach (['当前模式', '酒店：', '通知类型', '计划发送', '发送触发', '状态：'] as $label) {
            self::assertStringNotContainsString($label, $preview['payload']['markdown']['content']);
        }
    }

    public function testFailedDispatcherIsPersistedWithoutFalseSuccess(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
        ]);
        $service = new ManualNotificationService(
            static fn(): array => ['delivery_status' => 'failed']
        );
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput());
        $result = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'service-test-failed-1'
        );

        self::assertSame('failed', $result['delivery_status']);
        self::assertSame(
            'failed',
            Db::name('manual_notifications')->where('id', $saved['record']['id'])->value('last_test_status')
        );
        self::assertStringContainsString('正式群未触发', $result['message']);
    }

    public function testTestPushRejectsMissingConfirmationAndWrongHotel(): void
    {
        $service = new ManualNotificationService();
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput());

        try {
            $service->testPush(
                9,
                80,
                (int)$saved['record']['id'],
                7,
                false,
                ManualNotificationService::TEST_ROBOT_ID,
                ManualNotificationService::TEST_ROBOT_NAME,
                '敦煌漠蓝新',
                'missing-confirmation'
            );
            self::fail('Missing confirmation must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_test_confirmation_required', $error->getMessage());
        }

        try {
            $service->testPush(
                9,
                80,
                (int)$saved['record']['id'],
                7,
                true,
                2,
                ManualNotificationService::TEST_ROBOT_NAME,
                '敦煌漠蓝新',
                'wrong-robot-id'
            );
            self::fail('Any robot outside the bound id 1 must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_test_target_forbidden', $error->getMessage());
        }

        try {
            $service->testPush(
                9,
                80,
                (int)$saved['record']['id'],
                7,
                true,
                ManualNotificationService::TEST_ROBOT_ID,
                '任意其他机器人',
                '敦煌漠蓝新',
                'wrong-robot-name'
            );
            self::fail('Any robot name outside the bound 漠蓝测试 must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_test_target_forbidden', $error->getMessage());
        }

        $previewOnly = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'send_method' => 'manual_preview',
        ]));
        try {
            $service->testPush(
                9,
                80,
                (int)$previewOnly['record']['id'],
                7,
                true,
                ManualNotificationService::TEST_ROBOT_ID,
                ManualNotificationService::TEST_ROBOT_NAME,
                '敦煌漠蓝新',
                'preview-method-test'
            );
            self::fail('Preview-only notifications must not enter the test dispatcher.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_test_method_forbidden', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('manual_notification_test_target_forbidden');
        $service->testPush(
            9,
            81,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '其他酒店',
            'wrong-hotel-test'
        );
    }

    public function testFormalScheduleFailureAndExplicitRetryPersistRealAttempts(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 27,
            'store_id' => 80,
            'name' => '正式经营群',
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $testService = new ManualNotificationService(
            static fn(): array => ['delivery_status' => 'sent', 'response_reference' => 'test:ok']
        );
        $saved = $testService->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'notification_type' => 'anomaly_alert',
            'title' => '价格规则异常',
            'body' => "异常编号：A-17\n业务服务正文：价格规则未回读",
            'send_method' => 'wecom_formal',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26T18:00',
            'enabled' => true,
            'target_robot_id' => 27,
            'target_robot_name' => '正式经营群',
        ]));
        self::assertSame('awaiting_test', $saved['record']['schedule_status']);
        $tested = $testService->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            27,
            '正式经营群',
            '敦煌漠蓝新',
            'formal-plan-test-001'
        );
        self::assertSame('schedule_enabled', $tested['schedule_status']);
        self::assertTrue($tested['formal_group_delivery_allowed']);
        $testHistory = $testService->dispatchHistory(9, 80);
        self::assertSame('价格规则异常', $testHistory['list'][0]['plan_title']);
        self::assertSame('异常预警', $testHistory['list'][0]['template_type_label']);
        self::assertSame('三源并列（兼容原计划）', $testHistory['list'][0]['source_scope_label']);

        $scheduled = (new ManualNotificationScheduleService(
            static fn(): array => [
                'delivery_status' => 'failed',
                'error' => 'provider_rejected',
            ]
        ))->runDue(
            new DateTimeImmutable('2026-07-26 18:01:00', new DateTimeZone('Asia/Shanghai')),
            true,
            ManualNotificationScheduleService::MODE_FORMAL
        );
        self::assertSame('dispatch_failed', $scheduled['status']);
        self::assertSame('failed', $scheduled['results'][0]['status']);
        $formalDispatchId = (int)$scheduled['results'][0]['dispatch_id'];
        self::assertNotNull(
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', $formalDispatchId)
                ->value('next_retry_at')
        );

        $retried = (new ManualNotificationService(
            static fn(): array => [
                'delivery_status' => 'sent',
                'response_reference' => 'wecom:errcode=0',
            ]
        ))->retryDispatch(9, 80, $formalDispatchId, 7, true);
        self::assertSame('sent', $retried['delivery_status']);
        self::assertSame('formal', $retried['delivery_mode']);
        self::assertTrue($retried['formal_group_delivery_allowed']);
        self::assertSame(2, $retried['dispatch']['attempt_count']);
        self::assertSame(
            2,
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', $formalDispatchId)
                ->count()
        );
    }

    public function testLegacyAlwaysTestRetryRestoresFormalScheduleState(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 28,
            'store_id' => 80,
            'name' => '旧计划测试群',
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $failedService = new ManualNotificationService(
            static fn(): array => [
                'delivery_status' => 'failed',
                'error' => 'temporary_provider_failure',
            ]
        );
        $saved = $failedService->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' => 'anomaly_alert',
                'title' => '旧计划测试重试',
                'body' => "异常编号：legacy-retry\n业务服务正文：等待重试",
                'send_method' => 'wecom_formal',
                'trigger_type' => 'daily_fixed_time',
                'planned_send_at' => '2026-07-26T18:00',
                'enabled' => true,
                'target_robot_id' => 28,
                'target_robot_name' => '旧计划测试群',
            ])
        );
        $failed = $failedService->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            28,
            '旧计划测试群',
            '敦煌漠蓝新',
            'legacy-always-test-retry-001'
        );
        self::assertSame('failed', $failed['delivery_status']);
        $dispatchId = (int)$failed['dispatch']['id'];
        $plan = $failedService->read(
            9,
            80,
            (int)$saved['record']['id']
        );
        $legacyFingerprint =
            ManualNotificationService::legacyPlanFingerprint($plan);
        Db::name('manual_notification_schedule_dispatches')
            ->where('id', $dispatchId)
            ->update(['tested_plan_fingerprint' => $legacyFingerprint]);

        $retried = (new ManualNotificationService(
            static fn(): array => [
                'delivery_status' => 'sent',
                'response_reference' => 'wecom:errcode=0',
            ]
        ))->retryDispatch(9, 80, $dispatchId, 7, true);

        self::assertSame('sent', $retried['delivery_status']);
        $readback = $failedService->read(
            9,
            80,
            (int)$saved['record']['id']
        );
        self::assertSame('schedule_enabled', $readback['schedule_status']);
        self::assertSame('sent', $readback['last_test_status']);
    }

    public function testInactiveRobotDoesNotPreventPausingExistingPlan(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 31,
            'store_id' => 80,
            'name' => '停用前经营群',
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $service = new ManualNotificationService();
        $input = $this->validInput([
            'send_method' => 'wecom_formal',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26T18:00',
            'enabled' => true,
            'target_robot_id' => 31,
            'target_robot_name' => '停用前经营群',
        ]);
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $input);
        Db::name('competitor_wechat_robot')->where('id', 31)->update(['status' => 0]);

        $paused = $service->save(9, 80, 7, '敦煌漠蓝新', array_replace($input, [
            'id' => (int)$saved['record']['id'],
            'enabled' => false,
        ]));
        self::assertFalse($paused['record']['enabled']);
        self::assertSame('saved_only', $paused['record']['schedule_status']);
        self::assertSame(31, $paused['record']['target_robot_id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('manual_notification_target_binding_invalid');
        $service->save(9, 80, 7, '敦煌漠蓝新', array_replace($input, [
            'id' => (int)$saved['record']['id'],
            'enabled' => true,
        ]));
    }

    public function testOutcomeUnknownCanOnlyBeRetriedAfterExplicitConfirmation(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 27,
            'store_id' => 80,
            'name' => 'formal-operations-robot',
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $activationService = new ManualNotificationService(
            static fn(): array => ['delivery_status' => 'sent', 'response_reference' => 'test:ok']
        );
        $saved = $activationService->save(9, 80, 7, 'lease-recovery-hotel', $this->validInput([
            'notification_type' => 'anomaly_alert',
            'template_type' => 'anomaly_alert',
            'title' => 'lease recovery alert',
            'body' => 'A persisted alert body',
            'send_method' => 'wecom_formal',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26T18:00',
            'enabled' => true,
            'target_robot_id' => 27,
            'target_robot_name' => 'formal-operations-robot',
        ]));
        $activationService->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            27,
            'formal-operations-robot',
            'lease-recovery-hotel',
            'lease-recovery-activation-test'
        );

        $ledger = new ManualNotificationDispatchLedgerService();
        $startedAt = new DateTimeImmutable(
            '2026-07-26 18:00:00',
            new DateTimeZone('Asia/Shanghai')
        );
        $claim = $ledger->claim(
            (int)$saved['record']['id'],
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_FORMAL,
            'daily_fixed_time',
            'scheduled',
            27,
            'formal-operations-robot',
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'markdown',
                    'markdown' => ['content' => 'persisted retry payload'],
                ],
                'tested_plan_fingerprint' =>
                    ManualNotificationService::planFingerprint(
                        $activationService->read(
                            9,
                            80,
                            (int)$saved['record']['id']
                        )
                    ),
            ],
            $startedAt
        );
        $attempt = $ledger->beginAttempt((int)$claim['dispatch']['id'], $startedAt);
        self::assertTrue($attempt['allowed']);
        self::assertSame(
            1,
            $ledger->recoverExpiredSending(
                $startedAt->modify('+3 minutes'),
                ManualNotificationScheduleService::MODE_FORMAL,
                80
            )
        );

        $calls = [];
        $retryService = new ManualNotificationService(
            static function (int $hotelId, int $robotId, array $payload, array $context) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload, $context];
                return [
                    'delivery_status' => 'sent',
                    'response_reference' => 'wecom:errcode=0',
                ];
            },
            null,
            $ledger
        );
        try {
            $retryService->retryDispatch(9, 80, (int)$claim['dispatch']['id'], 7, false);
            self::fail('An ambiguous delivery must require explicit operator confirmation.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(
                'manual_notification_retry_confirmation_required',
                $error->getMessage()
            );
        }

        $retried = $retryService->retryDispatch(
            9,
            80,
            (int)$claim['dispatch']['id'],
            7,
            true
        );
        self::assertSame('sent', $retried['delivery_status']);
        self::assertCount(1, $calls);
        self::assertSame('explicit_retry', $calls[0][3]['request_kind']);
        self::assertSame(
            'explicit_retry',
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', (int)$claim['dispatch']['id'])
                ->where('attempt_no', 2)
                ->value('request_kind')
        );
        self::assertSame(2, $retried['dispatch']['attempt_count']);
    }

    public function testRetryCannotAuthorizeAChangedPlanWithOldPayload(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $service = new ManualNotificationService(
            static fn(): array => [
                'delivery_status' => 'failed',
                'error' => 'provider_rejected',
            ]
        );
        $saved = $service->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'title' => '原计划标题',
                'body' => '原计划正文',
            ])
        );
        $failed = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'retry-plan-change-001'
        );
        self::assertSame('failed', $failed['delivery_status']);
        $dispatchId = (int)$failed['dispatch']['id'];
        Db::name('manual_notifications')
            ->where('id', (int)$saved['record']['id'])
            ->update([
                'title' => '已修改的新计划标题',
                'update_time' => '2026-07-30 13:30:00',
            ]);
        $retryCalls = 0;
        $retryService = new ManualNotificationService(
            static function () use (&$retryCalls): array {
                $retryCalls++;
                return ['delivery_status' => 'sent'];
            }
        );

        try {
            $retryService->retryDispatch(
                9,
                80,
                $dispatchId,
                7,
                true
            );
            self::fail('A frozen payload must not authorize a changed plan.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(
                'manual_notification_retry_plan_changed',
                $error->getMessage()
            );
        }
        self::assertSame(0, $retryCalls);
        self::assertSame(
            1,
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', $dispatchId)
                ->count()
        );
    }

    private function operatingDailyThreeSourcePayloads(): OperatingDailyReportPayloadService
    {
        $pmsResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date
        ): array {
            if ($tenantId !== 9 || $hotelId !== 80) {
                return [];
            }
            $sourceApiPath = '/api/verified';
            $providerHotelId = 'provider-hotel-80';
            $captureEvidence =
                \app\service\DingdandaoOperatingTargetCaptureService::
                expectedCaptureEvidence(
                    $sourceApiPath,
                    $date,
                    $providerHotelId,
                    'full_diagnostic'
                );
            if (!is_array($captureEvidence)) {
                throw new \RuntimeException(
                    'dingdandao_test_capture_evidence_invalid'
                );
            }
            return [
                'id' => 301,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider' => 'dingdandao_pms',
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => '敦煌漠蓝新',
                'business_date' => $date,
                'source_url' =>
                    \app\service\DingdandaoOperatingTargetCaptureService::
                    SOURCE_URL,
                'source_api_path' => $sourceApiPath,
                'source_scope' => 'today_only',
                'collection_mode' => 'full_diagnostic',
                'capture_method' => 'network_response',
                'capture_strategy' => 'verified_endpoint_recipe',
                'capture_status' => 'verified',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'identity_status' => 'matched',
                'reconciliation_status' => 'matched',
                'source_trace_id' =>
                    (string)$captureEvidence['source_trace_id'],
                'source_fingerprint' => str_repeat('b', 64),
                'detail_row_count' => 25,
                'captured_at' => $date . ' 17:30:00',
                'summary' => [
                    'total_room_fee' => 8745.66,
                    'sold_room_nights' => 15,
                    'average_daily_room_nights' => 15.0,
                    'derived_sellable_room_nights' => 15,
                    'occupancy_rate_percent' => 100.0,
                    'adr' => 583.04,
                    'revpar' => 583.04,
                ],
                'capture_evidence' => $captureEvidence,
                'gaps' => [],
            ];
        };
        $rowResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date,
            string $source,
            string $dataType,
            ?string $dimension
        ): ?array {
            if ($tenantId !== 9 || $hotelId !== 80) {
                return null;
            }
            if ($source === 'ctrip'
                && $dataType === 'traffic'
                && $dimension === 'realtime:ctrip'
            ) {
                return self::trustedOtaFixture([
                    'id' => 101,
                    'source' => 'ctrip',
                    'data_source_id' => 7,
                    'sync_task_id' => 21,
                    'source_trace_id' => 'ctrip:trace-101',
                    'snapshot_time' => $date . ' 17:40:00',
                    'readback_verified' => 1,
                    'data_period' => 'realtime_snapshot',
                    'is_final' => 0,
                    'detail_exposure' => 58,
                    'book_order_num' => 0,
                    'quantity' => 4,
                    'raw_data' => [
                        'metrics' => ['last_week_visitors' => 195],
                    ],
                ], $date, 'ctrip', 'traffic', $dimension, [
                    'realtime_visitors',
                    'last_week_visitors',
                    'booking_order_count',
                    'in_house_room_nights',
                ]);
            }
            if ($source === 'meituan'
                && $dataType === 'traffic'
                && $dimension === null
            ) {
                return self::trustedOtaFixture([
                    'id' => 64381,
                    'platform' => 'meituan',
                    'data_source_id' => 8,
                    'sync_task_id' => 22,
                    'source_trace_id' => 'meituan:trace-64381',
                    'validation_status' => 'verified',
                    'snapshot_time' => $date . ' 17:45:00',
                    'readback_verified' => 1,
                    'data_period' => 'realtime_snapshot',
                    'is_final' => 0,
                    'list_exposure' => 471,
                    'detail_exposure' => 77,
                    'raw_data' => [
                        'exposure_to_browse_rate' => 16.35,
                    ],
                ], $date, 'meituan', 'traffic', $dimension, [
                    'list_exposure',
                    'detail_exposure',
                ]);
            }
            return null;
        };

        return new OperatingDailyReportPayloadService(
            null,
            $pmsResolver,
            $rowResolver,
            null,
            static fn(): array => [
                'configured' => true,
                'expected_provider_hotel_id' => 'provider-hotel-80',
                'expected_provider_hotel_name' => '敦煌漠蓝新',
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $metricKeys
     * @return array<string, mixed>
     */
    private static function trustedOtaFixture(
        array $row,
        string $date,
        string $source,
        string $dataType,
        ?string $dimension,
        array $metricKeys
    ): array {
        $trace = trim((string)($row['source_trace_id'] ?? ''));
        $captureEvidence = [
            'source_trace_id' => $trace,
            'source_url_hash' => hash(
                'sha256',
                'https://fixture.suxios.test/' . $source . '/' . $dataType
            ),
        ];
        $raw = is_array($row['raw_data'] ?? null)
            ? $row['raw_data']
            : [];
        $raw['source_trace_id'] = $trace;
        $raw['hotel_id'] = 'provider-hotel-80';
        $raw['capture_evidence'] = $captureEvidence;
        $raw['field_facts'] = array_map(
            static fn(string $metricKey): array => [
                'metric_key' => $metricKey,
                'status' => 'captured',
                'source_path' => 'fixture.metrics.' . $metricKey,
                'storage_field' =>
                    'online_daily_data.raw_data.facts.metric_key='
                    . $metricKey,
                'stored_value_present' => true,
                'value' => 1,
                'capture_evidence' => $captureEvidence,
            ],
            $metricKeys
        );
        return array_replace($row, [
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'hotel_id' => 'provider-hotel-80',
            'source' => $source,
            'platform' => $source,
            'data_date' => $date,
            'data_type' => $dataType,
            'dimension' => $dimension ?? '',
            'validation_status' => 'verified',
            'validation_flags' => '[]',
            'ingestion_method' => 'browser_profile',
            'raw_data' => $raw,
        ]);
    }

    private function businessPayloads(): ManualNotificationBusinessPayloadService
    {
        return new ManualNotificationBusinessPayloadService(
            static fn(string $type, int $hotelId, string $date): array => [
                'contract_version' => 'manual_notification_business_preview.v1',
                'hotel' => ['id' => $hotelId, 'tenant_id' => 9, 'name' => '敦煌漠蓝新'],
                'business_date' => $date,
                'section' => [
                    'key' => $type,
                    'status' => 'partial',
                    'facts' => [],
                    'forecasts' => [],
                    'gaps' => [],
                    'message_data' => [
                        'contract_version' => 'three_source_today_message_facts.v1',
                        'data_status' => 'partial',
                        'business_date' => $date,
                        'sources' => [
                            'dingdandao_pms' => [
                                'contract_version' => 'dingdandao_today_message_facts.v1',
                                'data_status' => 'readback_verified',
                                'facts' => [
                                    'room_fee' => 8745.6,
                                    'sold_room_nights' => 15,
                                    'sellable_room_nights' => 16,
                                    'remaining_sellable_room_nights' => 1,
                                    'occupancy_rate_percent' => 93.75,
                                    'adr' => 583.04,
                                    'revpar' => 546.6,
                                ],
                                'source' => [
                                    'captured_at' => '2026-07-26 18:35:00',
                                ],
                            ],
                            'ctrip_ota' => ['data_status' => 'pending_collection'],
                            'meituan_ota' => ['data_status' => 'collection_failed'],
                        ],
                        'aggregation_policy' => [
                            'pms_plus_ota_revenue_addition_allowed' => false,
                            'missing_source_value' => null,
                        ],
                    ],
                ],
            ]
        );
    }

    public function testBusinessConditionRuleSavesReadsBackAndChangesPlanFingerprint(): void
    {
        $service = new ManualNotificationService();
        $saved = $service->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' => 'today_revenue_management',
                'send_method' => 'manual_preview',
                'condition_type' => 'occupancy_ladder',
                'condition_threshold' => 20,
                'condition_step' => 5,
            ])
        );

        self::assertTrue($saved['readback_verified']);
        self::assertSame('occupancy_ladder', $saved['record']['condition_type']);
        self::assertSame(20.0, $saved['record']['condition_threshold']);
        self::assertSame(5.0, $saved['record']['condition_step']);
        self::assertNull($saved['record']['condition_state']);
        $changed = $saved['record'];
        $changed['condition_threshold'] = 25;
        self::assertNotSame(
            ManualNotificationService::planFingerprint($saved['record']),
            ManualNotificationService::planFingerprint($changed)
        );
        $always = $saved['record'];
        $always['condition_type'] = ManualNotificationConditionRuleService::ALWAYS;
        $always['condition_threshold'] = null;
        $always['condition_step'] = null;
        $legacy = ManualNotificationService::legacyPlanFingerprint($always);
        self::assertNotSame(
            ManualNotificationService::planFingerprint($always),
            $legacy
        );
        self::assertTrue(
            ManualNotificationService::planFingerprintMatches($legacy, $always)
        );
        self::assertFalse(
            ManualNotificationService::planFingerprintMatches(
                $legacy,
                $saved['record']
            )
        );
    }

    public function testBusinessConditionRuleRejectsTemplateWithoutVerifiedFacts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'manual_notification_condition_template_unsupported'
        );
        (new ManualNotificationService())->save(
            9,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput([
                'condition_type' => 'full_house',
            ])
        );
    }

    public function testDynamicTodayPreviewUsesResolvedCurrentBusinessDate(): void
    {
        $service = new ManualNotificationService(
            null,
            null,
            null,
            null,
            null,
            $this->operatingDailyThreeSourcePayloads()
        );
        $preview = $service->preview(
            80,
            '敦煌漠蓝新',
            $this->validInput([
                'notification_type' =>
                    ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'template_type' =>
                    ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
                'source_scope' => 'combined',
                'content_sections' => [
                    'pms_summary',
                    'pms_efficiency',
                    'ctrip_traffic',
                    'meituan_traffic',
                ],
                'business_date' => '2026-07-26',
                'business_date_rule' => 'today',
            ]),
            9
        );

        self::assertSame(
            (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
                ->format('Y-m-d'),
            $preview['business_date']
        );
        self::assertNotSame('2026-07-26', $preview['business_date']);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return array_replace([
            'notification_type' => 'task_notification',
            'business_date' => '2026-07-26',
            'title' => '{经营日期} 任务通知',
            'body' => "【任务通知】\n酒店：{酒店名称}\n任务事实：未取得\n下一步：待配置",
            'send_method' => 'wecom_test',
            'trigger_type' => 'manual_test',
            'planned_send_at' => '',
            'business_date_rule' => 'today',
            'active_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'effective_from' => '',
            'effective_to' => '',
            'hourly_start_time' => '09:00',
            'hourly_end_time' => '22:00',
            'enabled' => false,
        ], $overrides);
    }
}
