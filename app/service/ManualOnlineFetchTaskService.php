<?php
declare(strict_types=1);

namespace app\service;

final class ManualOnlineFetchTaskService
{
    private const COMMAND_NAME = 'online-data:manual-fetch-once';

    public function createTask(string $platform, int $hotelId, string $startDate, string $endDate, array $requestData, array $context): array
    {
        $platform = $this->normalizePlatform($platform);
        $authorization = trim((string)($context['authorization'] ?? ''));
        if ($platform === '' || $hotelId <= 0 || $authorization === '') {
            return [];
        }

        $projectRoot = $this->projectRoot();
        $phpBinary = $this->resolvePhpCliBinary();
        $thinkPath = $projectRoot . DIRECTORY_SEPARATOR . 'think';
        if ($phpBinary === '' || !is_file($thinkPath)) {
            return [];
        }

        $taskId = 'manual_' . $platform . '_fetch_' . $hotelId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'manual_fetch_tasks' . DIRECTORY_SEPARATOR . $taskId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [];
        }

        $body = $requestData;
        $body['system_hotel_id'] = $hotelId;
        $body['start_date'] = $startDate;
        $body['end_date'] = $endDate;
        $body['async'] = false;
        $body['background_task'] = true;

        $inputPath = $dir . DIRECTORY_SEPARATOR . 'input.json';
        $task = [
            'task_id' => $taskId,
            'hotel_id' => $hotelId,
            'user_id' => (int)($context['user_id'] ?? 0),
            'platform' => $platform,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'api_url' => (string)($context['api_url'] ?? ''),
            'authorization' => $authorization,
            'body' => $body,
            'input' => $inputPath,
            'log' => $dir . DIRECTORY_SEPARATOR . 'launcher.log',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (file_put_contents($inputPath, json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            return [];
        }

        return $task;
    }

    public function launchTask(array $task): bool
    {
        $projectRoot = $this->projectRoot();
        $phpBinary = $this->resolvePhpCliBinary();
        $thinkPath = $projectRoot . DIRECTORY_SEPARATOR . 'think';
        $inputPath = (string)($task['input'] ?? '');
        if ($phpBinary === '' || !is_file($thinkPath) || !is_file($inputPath)) {
            return false;
        }

        $dir = dirname($inputPath);
        $taskId = (string)($task['task_id'] ?? '');
        if ($taskId === '') {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $batPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.bat';
            $inputFile = basename($inputPath);
            $lines = [
                '@echo off',
                'setlocal',
                'set "TASK_DIR=%~dp0"',
                'pushd "%TASK_DIR%..\..\.." || exit /b 1',
                $this->quoteWindowsBatchArg($phpBinary)
                    . ' "%CD%\think"'
                    . ' "' . self::COMMAND_NAME . '"'
                    . ' "--task-id=' . $taskId . '"'
                    . ' "--input=%TASK_DIR%' . $inputFile . '"'
                    . ' >> "%TASK_DIR%launcher.log" 2>&1',
                'set "EXIT_CODE=%ERRORLEVEL%"',
                'popd',
                'exit /b %EXIT_CODE%',
            ];
            if (file_put_contents($batPath, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
                return false;
            }
            $launchCommand = '$bat=' . $this->quotePowerShellSingleQuotedString($batPath)
                . '; Start-Process -WindowStyle Hidden -FilePath "cmd.exe" -ArgumentList @("/d","/c","call",$bat)';
            $handle = @popen(
                'powershell.exe -NoProfile -ExecutionPolicy Bypass -EncodedCommand '
                    . $this->encodeWindowsPowerShellCommand($launchCommand),
                'r'
            );
            if (!is_resource($handle)) {
                return false;
            }
            pclose($handle);
            return true;
        }

        $shellPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.sh';
        $command = 'cd ' . escapeshellarg($projectRoot)
            . ' && ' . escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($thinkPath)
            . ' ' . self::COMMAND_NAME
            . ' --task-id=' . escapeshellarg($taskId)
            . ' --input=' . escapeshellarg($inputPath)
            . ' >> ' . escapeshellarg((string)($task['log'] ?? '')) . ' 2>&1';
        if (file_put_contents($shellPath, "#!/bin/sh\n" . $command . "\n") === false) {
            return false;
        }
        @chmod($shellPath, 0755);
        $handle = @popen('sh ' . escapeshellarg($shellPath) . ' >/dev/null 2>&1 &', 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);
        return true;
    }

    private function normalizePlatform(string $platform): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($platform))) ?: '';
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function resolvePhpCliBinary(): string
    {
        $configured = trim((string)(getenv('PHP_CLI_BINARY') ?: env('PHP_CLI_BINARY', '')));
        $candidates = array_filter([
            $configured,
            'C:\\xampp\\php\\php.exe',
            PHP_BINARY,
            'php',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'php' || is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function quoteWindowsBatchArg(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function quotePowerShellSingleQuotedString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function encodeWindowsPowerShellCommand(string $command): string
    {
        if (function_exists('mb_convert_encoding')) {
            return base64_encode((string)mb_convert_encoding($command, 'UTF-16LE', 'UTF-8'));
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-16LE//IGNORE', $command);
            if (is_string($converted)) {
                return base64_encode($converted);
            }
        }

        return base64_encode($command);
    }
}
