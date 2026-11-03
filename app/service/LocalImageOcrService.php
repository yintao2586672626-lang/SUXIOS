<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

/** Local Windows OCR only. Recognized content is a review candidate, never a financial fact. */
final class LocalImageOcrService
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;
    private const MAX_OUTPUT_BYTES = 2 * 1024 * 1024;
    private const TIMEOUT_SECONDS = 30;

    /**
     * No network, model, credentials, database or source-file writes are used.
     * @return array{text:string,lines:array,language:string,source_method:string,image_width:int,image_height:int}
     */
    public function recognize(string $path): array
    {
        $path = $this->validateImage($path);
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('当前环境不支持本机 Windows 图片识别，请使用表格或手工录入', 503);
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('本机 OCR 进程能力不可用，请使用表格或手工录入', 503);
        }
        $windowsDirectory = (string)getenv('SystemRoot');
        $executable = $windowsDirectory . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'recognize_payback_image.ps1';
        if (!preg_match('/^[A-Za-z]:[\\\\\/]/', $windowsDirectory) || !is_file($executable) || !is_file($script)) {
            throw new RuntimeException('本机 Windows OCR 运行环境未就绪，请使用表格或手工录入', 503);
        }

        // Array arguments bypass cmd.exe. Uploaded filenames never become executable shell text.
        $result = $this->runProcess([$executable, '-NoProfile', '-NonInteractive', '-File', $script, '-Path', $path]);
        try {
            $decoded = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('本机 OCR 未返回有效结果，请重试或使用表格录入', 503);
        }
        if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'ready' || $result['exit_code'] !== 0) {
            $error = is_array($decoded) ? (string)($decoded['error_code'] ?? '') : '';
            $messages = [
                'ocr_runtime_unsupported' => ['当前 Windows 环境不支持本机 OCR，请使用表格或手工录入', 503],
                'ocr_chinese_language_missing' => ['本机未安装中文 OCR 语言包，请使用表格或手工录入', 503],
                'ocr_engine_unavailable' => ['本机中文 OCR 引擎不可用，请使用表格或手工录入', 503],
                'image_dimensions_unsupported' => ['图片尺寸超过本机 OCR 限制，请裁剪后重试', 422],
                'image_decode_failed' => ['本机无法解码图片，请改用 PNG 或 JPEG 后重试', 422],
                'ocr_no_text' => ['图片中未识别到文字，请使用清晰图片或表格录入', 422],
                'invalid_local_image' => ['图片文件不可读取或格式无效', 422],
                'ocr_recognition_failed' => ['本机 OCR 识别失败，请重试或使用表格录入', 503],
            ];
            [$message, $code] = $messages[$error] ?? ['本机 OCR 运行失败，请重试或使用表格录入', 503];
            throw new RuntimeException($message, $code);
        }
        if (!is_string($decoded['text'] ?? null) || trim($decoded['text']) === ''
            || !is_array($decoded['lines'] ?? null) || $decoded['lines'] === []
            || !is_string($decoded['language'] ?? null) || !str_starts_with($decoded['language'], 'zh-')
            || ($decoded['source_method'] ?? null) !== 'local_windows_ocr'
            || (int)($decoded['image_width'] ?? 0) <= 0 || (int)($decoded['image_height'] ?? 0) <= 0) {
            throw new RuntimeException('本机 OCR 结果不完整，请重试或使用表格录入', 503);
        }
        foreach ($decoded['lines'] as $line) {
            if (!is_array($line) || !is_string($line['text'] ?? null) || !is_array($line['words'] ?? null)
                || !$this->validRectangle($line)) {
                throw new RuntimeException('本机 OCR 文字位置结果无效', 503);
            }
            foreach ($line['words'] as $word) {
                if (!is_array($word) || !is_string($word['text'] ?? null) || !$this->validRectangle($word)) {
                    throw new RuntimeException('本机 OCR 文字位置结果无效', 503);
                }
            }
        }
        return [
            'text' => $decoded['text'],
            'lines' => $decoded['lines'],
            'language' => $decoded['language'],
            'source_method' => 'local_windows_ocr',
            'image_width' => (int)($decoded['image_width'] ?? 0),
            'image_height' => (int)($decoded['image_height'] ?? 0),
        ];
    }

    private function validateImage(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/^[\\\\\/]{2}/', $path)
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $path)) {
            throw new InvalidArgumentException('仅支持本机上传图片文件', 422);
        }
        $resolved = realpath($path);
        if (!is_string($resolved) || preg_match('/^[\\\\\/]{2}/', $resolved) || !is_file($resolved) || !is_readable($resolved)) {
            throw new InvalidArgumentException('图片文件不可读取', 422);
        }
        $bytes = filesize($resolved);
        if (!is_int($bytes) || $bytes <= 0 || $bytes > self::MAX_BYTES) {
            throw new InvalidArgumentException('图片不能为空且不能超过 10 MB', 422);
        }
        // Validate bytes rather than the upload temp-file extension; PHP temp names often have no extension.
        $image = @getimagesize($resolved);
        if (!is_array($image) || !in_array($image['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new InvalidArgumentException('仅支持有效 PNG、JPEG 或 WebP 图片', 422);
        }
        $width = (int)($image[0] ?? 0);
        $height = (int)($image[1] ?? 0);
        if ($width <= 0 || $height <= 0 || $width > 10_000 || $height > 10_000 || $width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException('图片尺寸过大，请裁剪后重试', 422);
        }
        return $resolved;
    }

    private function validRectangle(array $value): bool
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!is_numeric($value[$key] ?? null) || !is_finite((float)$value[$key]) || (float)$value[$key] < 0) {
                return false;
            }
        }
        return true;
    }

    /** @return array{stdout:string,exit_code:int} */
    private function runProcess(array $arguments): array
    {
        // File handles avoid blocking Windows anonymous-pipe reads, so the deadline is enforced even on a hung decoder.
        $outputPath = tempnam(sys_get_temp_dir(), 'suxios_ocr_out_');
        $errorPath = tempnam(sys_get_temp_dir(), 'suxios_ocr_err_');
        if (!is_string($outputPath) || !is_string($errorPath)) {
            foreach ([$outputPath, $errorPath] as $temporaryPath) {
                if (is_string($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }
            throw new RuntimeException('无法创建本机 OCR 临时输出', 503);
        }
        $process = null;
        $pipes = [];
        try {
            $process = @proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['file', $outputPath, 'w'], 2 => ['file', $errorPath, 'w']], $pipes,
                dirname(__DIR__, 2), null, ['bypass_shell' => true, 'create_no_window' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('本机 OCR 进程启动失败', 503);
            }
            fclose($pipes[0]);
            $started = microtime(true);
            while (true) {
                $status = proc_get_status($process);
                clearstatcache(true, $outputPath);
                clearstatcache(true, $errorPath);
                if ((int)filesize($outputPath) > self::MAX_OUTPUT_BYTES || (int)filesize($errorPath) > 65_536) {
                    proc_terminate($process);
                    throw new RuntimeException('本机 OCR 输出超过处理限制，请裁剪后重试', 422);
                }
                if (!$status['running']) {
                    $exitCode = (int)$status['exitcode'];
                    break;
                }
                if (microtime(true) - $started >= self::TIMEOUT_SECONDS) {
                    proc_terminate($process);
                    throw new RuntimeException('本机 OCR 识别超时，请裁剪图片或使用表格录入', 503);
                }
                usleep(50_000);
            }
            $stdout = file_get_contents($outputPath);
            return ['stdout' => is_string($stdout) ? ltrim($stdout, "\xEF\xBB\xBF") : '', 'exit_code' => $exitCode];
        } finally {
            if (is_resource($process)) {
                proc_close($process);
            }
            @unlink($outputPath);
            @unlink($errorPath);
        }
    }
}
