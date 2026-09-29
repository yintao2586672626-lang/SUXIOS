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

trait AutoFetchReceiptTestCases
{

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

}
