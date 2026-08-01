<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\CompetitorApi;
use app\controller\OperationLogController;
use app\controller\SystemConfigController;
use app\model\SystemConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\ReflectionHelper;
use think\exception\HttpException;

final class SecurityInputGuardTest extends TestCase
{
    use ReflectionHelper;

    private function controller(string $className): object
    {
        $reflection = new ReflectionClass($className);
        return $reflection->newInstanceWithoutConstructor();
    }

    public function testCompetitorScreenshotGuardAcceptsSmallImageAndRejectsUnsafePayloads(): void
    {
        $controller = $this->controller(CompetitorApi::class);
        $pngDataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
        $relativePath = $this->invokeNonPublic($controller, 'saveBase64Image', [$pngDataUri]);
        $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        try {
            self::assertStringEndsWith('.png', $relativePath);
            self::assertFileExists($absolutePath);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('截图base64内容超过限制');
            $this->invokeNonPublic($controller, 'saveBase64Image', [str_repeat('A', 2796405)]);
        } finally {
            @unlink($absolutePath);
        }
    }

    public function testCompetitorScreenshotGuardRejectsInvalidImageMime(): void
    {
        $controller = $this->controller(CompetitorApi::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('截图必须是有效图片');
        $this->invokeNonPublic($controller, 'saveBase64Image', [base64_encode('plain text')]);
    }

    public function testCompetitorExternalAuditTextMasksSensitiveValues(): void
    {
        $controller = $this->controller(CompetitorApi::class);
        $input = 'device token=raw-token Cookie: sid=raw-cookie Authorization: Bearer raw-auth 13812345678 123456789012345';

        $masked = $this->invokeNonPublic($controller, 'sanitizeExternalAuditText', [$input]);

        foreach (['raw-token', 'raw-cookie', 'raw-auth', '13812345678', '123456789012345'] as $secret) {
            self::assertStringNotContainsString($secret, (string)$masked);
        }
        self::assertStringContainsString('****', (string)$masked);
        self::assertLessThanOrEqual(83, mb_strlen((string)$masked, 'UTF-8'));
    }

    public function testCompetitorPriceExtractionPrefersPriceOverRoomCounts(): void
    {
        $controller = $this->controller(CompetitorApi::class);

        self::assertSame(388.0, $this->invokeNonPublic($controller, 'extractPrice', ['房型2 标准间 ¥388/晚']));
        self::assertSame(388.0, $this->invokeNonPublic($controller, 'extractPrice', ['房型2 标准间 388/晚']));
        self::assertSame(428.0, $this->invokeNonPublic($controller, 'extractPrice', ['价格：428元，含早2份']));
        self::assertSame(1288.0, $this->invokeNonPublic($controller, 'extractPrice', ['豪华房 ￥1,288/晚']));
        self::assertSame(1288.0, $this->invokeNonPublic($controller, 'extractPrice', ['豪华房 ￥１，２８８/晚']));
    }

    public function testCompetitorReportPriceGuardRejectsMissingOrZeroPrice(): void
    {
        $controller = $this->controller(CompetitorApi::class);

        self::assertSame(0.0, $this->invokeNonPublic($controller, 'extractPrice', ['房型2 标准间 暂无报价']));
        self::assertSame(388.0, $this->invokeNonPublic($controller, 'extractPrice', ['388']));
        self::assertFalse($this->invokeNonPublic($controller, 'isValidReportPrice', [0.0]));
        self::assertFalse($this->invokeNonPublic($controller, 'isValidReportPrice', [-1.0]));
        self::assertTrue($this->invokeNonPublic($controller, 'isValidReportPrice', [388.0]));
        self::assertTrue($this->invokeNonPublic($controller, 'allowsMissingPriceForAvailability', ['sold_out']));
        self::assertTrue($this->invokeNonPublic($controller, 'allowsMissingPriceForAvailability', ['unavailable']));
        self::assertFalse($this->invokeNonPublic($controller, 'allowsMissingPriceForAvailability', ['bookable']));
    }

    public function testCompetitorRateContextRequiresComparablePublicRateDimensions(): void
    {
        $controller = $this->controller(CompetitorApi::class);
        $complete = $this->invokeNonPublic($controller, 'normalizeCompetitorRateContext', [[
            'ota_hotel_id' => '100',
            'collected_at' => '2026-07-17 10:20:30',
            'source_method' => 'local_browser_profile',
            'source_ref' => 'https://hotels.ctrip.com/hotels/100.html?token=must-not-persist',
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-22',
            'adults' => 2,
            'children' => 0,
            'room_type_key' => 'standard-room',
            'rate_plan_key' => 'public-flex',
            'breakfast' => 'none',
            'cancellation_policy' => 'free-before-18',
            'payment_mode' => 'pay-at-hotel',
            'tax_fee_included' => false,
            'price_basis' => 'room_per_night',
            'currency' => 'cny',
            'availability' => 'available',
        ], 'ctrip', 388.0]);

        self::assertSame('valid', $complete['validation_status']);
        self::assertSame(2, $complete['nights']);
        self::assertSame(0, $complete['tax_fee_included']);
        self::assertSame('CNY', $complete['currency']);
        self::assertSame('https://hotels.ctrip.com/hotels/100.html', $complete['source_ref']);
        self::assertSame(64, strlen($complete['availability_scope_key']));
        self::assertSame(64, strlen($complete['comparison_key']));
        self::assertSame(64, strlen($complete['content_hash']));

        $soldOut = $this->invokeNonPublic($controller, 'normalizeCompetitorRateContext', [[
            'ota_hotel_id' => '100',
            'collected_at' => '2026-07-17 11:20:30',
            'source_method' => 'local_browser_profile',
            'source_ref' => 'https://hotels.ctrip.com/hotels/100.html',
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-22',
            'adults' => 2,
            'children' => 0,
            'room_type_key' => 'standard-room',
            'rate_plan_key' => 'public-flex',
            'breakfast' => 'none',
            'cancellation_policy' => 'free-before-18',
            'payment_mode' => 'pay-at-hotel',
            'tax_fee_included' => false,
            'price_basis' => 'room_per_night',
            'currency' => 'cny',
            'availability' => 'sold_out',
        ], 'ctrip', null]);
        self::assertSame('valid', $soldOut['validation_status']);
        self::assertSame($complete['availability_scope_key'], $soldOut['availability_scope_key']);
        self::assertSame($complete['comparison_key'], $soldOut['comparison_key']);

        $otherSurface = $this->invokeNonPublic($controller, 'normalizeCompetitorRateContext', [[
            'ota_hotel_id' => '100',
            'collected_at' => '2026-07-17 11:25:30',
            'source_method' => 'local_browser_profile',
            'source_ref' => 'https://hotels.ctrip.com/hotels/100/other-surface',
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-22',
            'adults' => 2,
            'children' => 0,
            'room_type_key' => 'other-room',
            'ota_product_id' => 'other-product',
            'rate_plan_key' => 'public-flex',
            'breakfast' => 'none',
            'cancellation_policy' => 'free-before-18',
            'payment_mode' => 'pay-at-hotel',
            'tax_fee_included' => false,
            'price_basis' => 'room_per_night',
            'currency' => 'cny',
            'availability' => 'available',
        ], 'ctrip', 388.0]);
        self::assertNotSame($complete['availability_scope_key'], $otherSurface['availability_scope_key']);
        self::assertNotSame($complete['comparison_key'], $otherSurface['comparison_key']);

        $incomplete = $this->invokeNonPublic($controller, 'normalizeCompetitorRateContext', [[
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-20',
        ], 'ctrip', 388.0]);
        self::assertSame('incomplete', $incomplete['validation_status']);
        self::assertSame('', $incomplete['comparison_key']);
        self::assertStringContainsString('valid_stay_window', $incomplete['failure_reason']);
        self::assertStringContainsString('ota_hotel_id', $incomplete['failure_reason']);

        try {
            $this->invokeNonPublic($controller, 'normalizeCompetitorRateContext', [[
                'ota_hotel_id' => '100',
                'source_ref' => 'https://www.meituan.com/hotel/100',
            ], 'ctrip', 388.0]);
            self::fail('Expected a cross-platform source URL to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('来源 URL 与上报平台不一致', $exception->getMessage());
        }
        self::assertStringContainsString('readback', (string)file_get_contents(dirname(__DIR__) . '/app/controller/CompetitorApi.php'));
    }

    public function testBrowserProfileAdaptersNeverCreateCookieFiles(): void
    {
        $projectRoot = dirname(__DIR__);
        $sources = [
            'Ctrip browser Profile adapter' => $projectRoot . '/app/service/platform/CtripBrowserProfileDataSourceAdapter.php',
            'Meituan browser Profile adapter' => $projectRoot . '/app/service/platform/MeituanBrowserProfileDataSourceAdapter.php',
        ];

        foreach ($sources as $label => $path) {
            $source = (string)file_get_contents($path);
            self::assertStringNotContainsString('--cookies-file', $source, $label . ' must not pass Cookie files to a capture process');
            self::assertStringNotContainsString('createCookieFile', $source, $label . ' must not materialize stored Cookie values');
        }
    }

    public function testLegacyCookieFileWritersArePermissionRestricted(): void
    {
        $projectRoot = dirname(__DIR__);
        $sources = [
            'Profile capture concern' => $projectRoot . '/app/controller/concern/PlatformProfileCaptureConcern.php',
            'Chromium Cookie extractor' => $projectRoot . '/scripts/extract_chromium_cookie_header.php',
        ];

        foreach ($sources as $label => $path) {
            $source = (string)file_get_contents($path);
            self::assertStringContainsString('file_put_contents', $source, $label . ' must write the temporary Cookie file explicitly');
            self::assertStringContainsString('chmod($path, 0600)', $source, $label . ' must restrict temporary Cookie file permissions after writing');
        }
    }

    public function testSystemConfigImportGuardsValidateFileAndSchema(): void
    {
        $controller = $this->controller(SystemConfigController::class);
        $path = tempnam(sys_get_temp_dir(), 'system_config_import_');
        file_put_contents($path, json_encode(['configs' => ['site_name' => 'SUXIOS']], JSON_UNESCAPED_UNICODE));

        try {
            self::assertNull($this->invokeNonPublic($controller, 'validateSystemConfigImportFile', [$path, 'config.json', filesize($path)]));
            self::assertSame('仅支持JSON配置文件', $this->invokeNonPublic($controller, 'validateSystemConfigImportFile', [$path, 'config.txt', filesize($path)]));
            self::assertSame('配置文件超过1MB', $this->invokeNonPublic($controller, 'validateSystemConfigImportFile', [$path, 'config.json', 1024 * 1024 + 1]));
            self::assertNull($this->invokeNonPublic($controller, 'validateSystemConfigImportData', [['configs' => ['site_name' => 'SUXIOS']]]));
            self::assertSame('配置文件configs必须是对象', $this->invokeNonPublic($controller, 'validateSystemConfigImportData', [['configs' => ['value']]]));
            self::assertSame('配置项key格式错误', $this->invokeNonPublic($controller, 'validateSystemConfigImportData', [['configs' => ['bad key' => 'value']]]));
        } finally {
            @unlink($path);
        }
    }

    public function testSystemConfigImportDetectsRedactedExportPlaceholders(): void
    {
        $controller = $this->controller(SystemConfigController::class);

        self::assertTrue($this->invokeNonPublic($controller, 'containsRedactedExportSecretPlaceholder', ['[REDACTED]']));
        self::assertTrue($this->invokeNonPublic($controller, 'containsRedactedExportSecretPlaceholder', ['{"headers":{"Authorization":"[REDACTED]"}}']));
        self::assertTrue($this->invokeNonPublic($controller, 'containsRedactedExportSecretPlaceholder', [['cookie' => '[REDACTED]']]));
        self::assertFalse($this->invokeNonPublic($controller, 'containsRedactedExportSecretPlaceholder', ['normal config value']));
        self::assertFalse($this->invokeNonPublic($controller, 'containsRedactedExportSecretPlaceholder', ['{"label":"normal"}']));
    }

    public function testProtectedOtaConfigKeysAreCaseInsensitiveAndFilteredFromGeneralResponses(): void
    {
        $controller = $this->controller(SystemConfigController::class);
        $configs = [
            'system_name' => 'SUXIOS',
            'ctrip_config_list' => 'ctrip-cookie-secret',
            'meituan_config_list' => 'meituan-token-secret',
            'data_config_ctrip_comments' => 'legacy-comment-secret',
            'data_config_internal_notes' => '{"cookies":"legacy-unknown-platform-secret"}',
            'online_data_cookies_global' => 'legacy-global-cookie-secret',
        ];

        self::assertTrue(SystemConfig::isProtectedOtaKey('ctrip_config_list'));
        self::assertTrue(SystemConfig::isProtectedOtaKey(' MEITUAN_CONFIG_LIST '));
        self::assertTrue(SystemConfig::isProtectedOtaKey('data_config_ctrip_comments'));
        self::assertTrue(SystemConfig::isProtectedOtaKey('data_config_meituan_business'));
        self::assertTrue(SystemConfig::isProtectedOtaKey('online_data_cookies_hotel_58'));
        self::assertTrue(SystemConfig::isProtectedOtaKey('online_data_cookies_global'));
        self::assertTrue(SystemConfig::isProtectedOtaKey('data_config_internal_notes'));
        self::assertFalse(SystemConfig::isProtectedOtaKey('system_name'));
        self::assertFalse($this->invokeNonPublic($controller, 'canReadConfigKey', ['ctrip_config_list']));
        self::assertSame(
            ['system_name' => 'SUXIOS'],
            $this->invokeNonPublic($controller, 'filterProtectedOtaConfigs', [$configs])
        );
    }

    public function testGeneralConfigGuardRejectsProtectedOtaKeyWith403(): void
    {
        $controller = $this->controller(SystemConfigController::class);

        try {
            $this->invokeNonPublic($controller, 'guardProtectedOtaKey', ['ctrip_config_list']);
            self::fail('Protected OTA config key must be rejected');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function testGeneralConfigBulkGuardRejectsProtectedOtaKeyBeforeWrites(): void
    {
        $controller = $this->controller(SystemConfigController::class);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Forbidden');
        $this->invokeNonPublic($controller, 'guardProtectedOtaKeys', [[
            'system_name' => 'renamed-before-guard',
            'meituan_config_list' => 'meituan-token-secret',
        ]]);
    }

    public function testClearingProtectedOtaCachesPreservesUnrelatedProcessCacheEntries(): void
    {
        $reflection = new ReflectionClass(SystemConfig::class);
        $property = $reflection->getProperty('valueCache');
        $property->setAccessible(true);
        $original = $property->getValue();
        $property->setValue(null, [
            'ctrip_config_list' => ['found' => true, 'value' => 'ctrip-cookie-secret'],
            'meituan_config_list' => ['found' => true, 'value' => 'meituan-token-secret'],
            'CTRIP_CONFIG_LIST' => ['found' => true, 'value' => 'uppercase-ctrip-secret'],
            ' meituan_config_list ' => ['found' => true, 'value' => 'spaced-meituan-secret'],
            'data_config_internal_notes' => ['found' => true, 'value' => 'unknown-platform-secret'],
            'system_name' => ['found' => true, 'value' => 'SUXIOS'],
        ]);

        try {
            SystemConfig::clearProtectedOtaCaches();
            $cache = $property->getValue();
            self::assertArrayNotHasKey('ctrip_config_list', $cache);
            self::assertArrayNotHasKey('meituan_config_list', $cache);
            self::assertArrayNotHasKey('CTRIP_CONFIG_LIST', $cache);
            self::assertArrayNotHasKey(' meituan_config_list ', $cache);
            self::assertArrayNotHasKey('data_config_internal_notes', $cache);
            self::assertSame(['found' => true, 'value' => 'SUXIOS'], $cache['system_name']);
        } finally {
            $property->setValue(null, $original);
        }
    }

    public function testGeneralConfigFullReadsExcludeProtectedOtaRowsAtDatabaseBoundary(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/SystemConfigController.php');
        $helper = 'getAllConfigsWithoutProtectedOtaCache';

        self::assertSame(0, substr_count($source, 'SystemConfig::getAllConfigs()'));
        self::assertSame(3, substr_count($source, '$this->' . $helper . '()'));
        $helperPosition = strpos($source, 'private function ' . $helper . '()');
        $finallyPosition = strpos($source, 'finally', (int)$helperPosition);
        $clearPosition = strpos($source, 'SystemConfig::clearProtectedOtaCaches();', (int)$helperPosition);
        self::assertNotFalse($helperPosition);
        self::assertNotFalse($finallyPosition);
        self::assertNotFalse($clearPosition);
        self::assertStringContainsString("LOWER(config_key) NOT LIKE 'data_config_%'", $source);
        self::assertStringNotContainsString("LOWER(config_key) NOT LIKE 'data_config_ctrip%'", $source);
        self::assertStringNotContainsString("LOWER(config_key) NOT LIKE 'data_config_meituan%'", $source);
        self::assertStringContainsString("LOWER(config_key) NOT LIKE 'online_data_cookies_%'", $source);
        self::assertLessThan($clearPosition, $finallyPosition);
    }

    public function testSystemConfigImportGuardsProtectedKeysBeforeWrites(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/SystemConfigController.php');
        $importPosition = strpos($source, 'public function import()');
        $guardPosition = strpos($source, '$this->guardProtectedOtaKeys($data[\'configs\']);', (int)$importPosition);
        $writeLoopPosition = strpos($source, 'foreach ($data[\'configs\'] as $key => $value)', (int)$importPosition);

        self::assertNotFalse($importPosition);
        self::assertNotFalse($guardPosition);
        self::assertNotFalse($writeLoopPosition);
        self::assertLessThan($writeLoopPosition, $guardPosition);
    }

    public function testHighRiskOperationLogSummaryRowsAreWhitelistedAndRedacted(): void
    {
        $controller = $this->controller(OperationLogController::class);
        $row = [
            'id' => 12,
            'module' => 'online_data',
            'action' => 'save_cookies',
            'description' => 'Cookie: sessionid=raw-cookie; Authorization: Bearer sk-test-secret token=raw-token',
            'error_info' => 'Failed with spidertoken=raw-spider&key=raw-webhook and phone 13812345678',
            'extra_data' => json_encode(['headers' => ['Cookie' => 'raw-cookie']], JSON_UNESCAPED_UNICODE),
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla raw-cookie',
            'create_time' => '2026-07-08 10:00:00',
            'audit_type' => 'acquisition',
            'risk_priority' => 'high',
            'risk_title' => '配置变更动作',
            'user' => [
                'id' => 5,
                'username' => '13812345678',
                'realname' => 'Alice token=raw-user-token',
                'password' => 'raw-password',
            ],
            'hotel' => [
                'id' => 7,
                'name' => '测试门店',
            ],
        ];

        $summary = $this->invokeNonPublic($controller, 'sanitizeHighRiskSummaryRow', [$row]);
        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE);

        self::assertIsArray($summary);
        self::assertArrayNotHasKey('extra_data', $summary);
        self::assertArrayNotHasKey('ip', $summary);
        self::assertArrayNotHasKey('user_agent', $summary);
        self::assertArrayNotHasKey('password', $summary['user']);
        foreach (['raw-cookie', 'sk-test-secret', 'raw-token', 'raw-spider', 'raw-webhook', '13812345678', 'raw-user-token', 'raw-password'] as $secret) {
            self::assertStringNotContainsString($secret, (string)$encoded);
        }
        self::assertStringContainsString('****', (string)$encoded);
        self::assertSame('high', $summary['risk_priority']);
    }

    public function testOperationLogGuardRejectsUnauthenticatedAccess(): void
    {
        $controller = $this->controller(OperationLogController::class);

        try {
            $this->invokeNonPublic($controller, 'requireSuperAdminAccess');
            self::fail('Unauthenticated operation-log access must be rejected.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->getStatusCode());
        }
    }

    public function testOperationLogGuardRejectsNonSuperAdminAccess(): void
    {
        $controller = $this->controller(OperationLogController::class);
        $currentUser = new ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, new class {
            public function isSuperAdmin(): bool
            {
                return false;
            }
        });

        try {
            $this->invokeNonPublic($controller, 'requireSuperAdminAccess');
            self::fail('Non-super-admin operation-log access must be rejected.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }
}
