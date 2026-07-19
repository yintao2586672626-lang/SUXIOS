<?php
declare(strict_types=1);

namespace Tests;

use app\controller\admin\CompetitorWechatRobotController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SensitiveResponseBoundaryTest extends TestCase
{
    public function testSystemConfigResponsesAreMaskedAndPreserveSentinelsAreNotWritten(): void
    {
        $root = dirname(__DIR__);
        $controller = (string)file_get_contents($root . '/app/controller/SystemConfigController.php');
        $frontend = (string)file_get_contents($root . '/public/app-main.js');

        self::assertStringContainsString('SystemConfig::valueForResponse($requestedKey, $value)', $controller);
        self::assertStringContainsString('SystemConfig::valueForResponse($key, $row->config_value)', $controller);
        self::assertGreaterThanOrEqual(2, substr_count($controller, 'SystemConfig::shouldPreserveSensitiveInput'));
        self::assertGreaterThanOrEqual(3, substr_count($controller, 'Db::transaction('));
        self::assertStringNotContainsString("debugLog('systemConfigForm:', systemConfigForm.value)", $frontend);
    }

    public function testWechatRobotWritesOnlyProtectedWebhookAndRevealsOnlyAtSendBoundary(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/admin/CompetitorWechatRobotController.php'
        );

        self::assertGreaterThanOrEqual(3, substr_count($source, "'webhook' => \$storedWebhook"));
        self::assertStringContainsString("\$insert['webhook'] = '';", $source);
        self::assertStringContainsString("insertGetId(\$insert)", $source);
        self::assertStringNotContainsString("'webhook' => \$webhook", $source);
        self::assertStringNotContainsString("postJson((string)\$robot['webhook']", $source);
        self::assertGreaterThanOrEqual(3, substr_count($source, 'revealRobotWebhook('));
        self::assertGreaterThanOrEqual(2, substr_count($source, 'postJson($webhook, $payload)'));

        $method = new ReflectionMethod(CompetitorWechatRobotController::class, 'formatRobotListRow');
        $methodSource = implode("\n", array_slice(
            file($method->getFileName(), FILE_IGNORE_NEW_LINES),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        self::assertStringNotContainsString('revealRobotWebhook', $methodSource);
        self::assertStringContainsString('key=******', $methodSource);

        $updateMethod = new ReflectionMethod(
            CompetitorWechatRobotController::class,
            'resolveStoredRobotWebhookForUpdate'
        );
        $updateMethodSource = implode("\n", array_slice(
            file($updateMethod->getFileName(), FILE_IGNORE_NEW_LINES),
            $updateMethod->getStartLine() - 1,
            $updateMethod->getEndLine() - $updateMethod->getStartLine() + 1
        ));
        self::assertStringNotContainsString('revealRobotWebhook', $updateMethodSource);
        self::assertStringContainsString('protectRobotWebhookForStorage', $updateMethodSource);
    }
}
