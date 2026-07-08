<?php
declare(strict_types=1);

namespace Tests;

use app\controller\CompetitorApi;
use app\controller\OperationLogController;
use app\controller\SystemConfigController;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

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

    public function testTemporaryOtaCookieFilesArePermissionRestricted(): void
    {
        $projectRoot = dirname(__DIR__);
        $sources = [
            'Ctrip browser Profile adapter' => $projectRoot . '/app/service/platform/CtripBrowserProfileDataSourceAdapter.php',
            'Meituan browser Profile adapter' => $projectRoot . '/app/service/platform/MeituanBrowserProfileDataSourceAdapter.php',
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
}
