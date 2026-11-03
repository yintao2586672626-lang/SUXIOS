<?php
declare(strict_types=1);

namespace Tests;

use app\service\LocalImageOcrService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LocalImageOcrServiceTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testRejectsRemoteAndStreamPathsBeforeStartingOcr(): void
    {
        foreach (['https://example.invalid/finance.png', 'file:///C:/finance.png', '\\\\server\\finance.png', "invalid\0.png"] as $path) {
            try {
                (new LocalImageOcrService())->recognize($path);
                self::fail('Remote/stream path was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function testRejectsTextDisguisedAsImage(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, 'synthetic file, not image bytes');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('仅支持有效');
        (new LocalImageOcrService())->recognize($path);
    }

    public function testRejectsOversizedFileBeforeDecoding(): void
    {
        $path = $this->temporaryPath();
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, 10 * 1024 * 1024 + 1));
        fclose($handle);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不能超过 10 MB');
        (new LocalImageOcrService())->recognize($path);
    }

    public function testNativeOcrRecognizesSyntheticChineseDatesAmountsAndCoordinates(): void
    {
        // PHP upload temp files have no extension; metacharacters exercise literal path arguments.
        $path = $this->temporaryPath(" quote' & (table) [synthetic]");
        $this->generateImage($path);
        $hashBefore = hash_file('sha256', $path);
        try {
            $result = (new LocalImageOcrService())->recognize($path);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 503 && preg_match('/环境|语言包|引擎|进程能力/', $exception->getMessage())) {
                self::markTestSkipped($exception->getMessage());
            }
            throw $exception;
        }
        $compactText = preg_replace('/\s+/u', '', $result['text']);
        // The Chinese engine can return fullwidth financial punctuation; the original OCR text stays intact.
        $compactText = strtr($compactText, ['，' => ',', '．' => '.']);
        $compactText = preg_replace('/(?<=\d)·(?=\d)/u', '.', $compactText);
        self::assertSame('local_windows_ocr', $result['source_method']);
        self::assertStringStartsWith('zh-', $result['language']);
        foreach (['日期', '收入', '支出', '2026年09月29日', '2026年09月30日', '12,345.67', '2,000.00'] as $expected) {
            self::assertStringContainsString($expected, $compactText);
        }
        self::assertSame(1800, $result['image_width']);
        self::assertSame(500, $result['image_height']);
        self::assertNotEmpty($result['lines']);
        foreach ($result['lines'] as $line) {
            self::assertGreaterThan(0, $line['width']);
            self::assertGreaterThan(0, $line['height']);
            self::assertNotEmpty($line['words']);
            foreach ($line['words'] as $word) {
                self::assertGreaterThanOrEqual(0, $word['x']);
                self::assertGreaterThanOrEqual(0, $word['y']);
                self::assertGreaterThan(0, $word['width']);
                self::assertGreaterThan(0, $word['height']);
            }
        }
        self::assertSame($hashBefore, hash_file('sha256', $path), 'OCR must not change the uploaded source.');
    }

    public function testNativeBlankImageFailsInsteadOfEmptySuccess(): void
    {
        $path = $this->temporaryPath('.png');
        $this->generateImage($path, true);
        try {
            (new LocalImageOcrService())->recognize($path);
            self::fail('Blank image must not return empty success.');
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 503 && preg_match('/环境|语言包|引擎|进程能力/', $exception->getMessage())) {
                self::markTestSkipped($exception->getMessage());
            }
            self::assertSame(422, $exception->getCode());
            self::assertStringContainsString('未识别到文字', $exception->getMessage());
        }
    }

    private function temporaryPath(string $suffix = ''): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suxios_ocr_test_' . bin2hex(random_bytes(8)) . $suffix;
        $this->temporaryFiles[] = $path;
        return $path;
    }

    private function generateImage(string $path, bool $blank = false): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
            self::markTestSkipped('Native synthetic image fixture requires Windows PowerShell.');
        }
        $executable = (string)getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($executable)) {
            self::markTestSkipped('Windows PowerShell is unavailable.');
        }
        $arguments = [$executable, '-NoProfile', '-NonInteractive', '-File', __DIR__ . '/fixtures/generate_payback_ocr_image.ps1', '-Path', $path];
        if ($blank) {
            $arguments[] = '-Blank';
        }
        $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true, 'create_no_window' => true]);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $error);
        self::assertFileExists($path);
    }
}
