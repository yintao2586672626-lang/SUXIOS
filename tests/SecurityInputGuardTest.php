<?php
declare(strict_types=1);

namespace Tests;

use app\controller\CompetitorApi;
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
}
