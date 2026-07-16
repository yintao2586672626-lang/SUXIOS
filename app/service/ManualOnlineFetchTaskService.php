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
        $authorizationEnv = $this->authorizationEnvName($taskId);
        $task = [
            'task_id' => $taskId,
            'hotel_id' => $hotelId,
            'user_id' => (int)($context['user_id'] ?? 0),
            'platform' => $platform,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'api_url' => (string)($context['api_url'] ?? ''),
            'authorization' => $authorization,
            'authorization_env' => $authorizationEnv,
            'body' => $body,
            'input' => $inputPath,
            'log' => $dir . DIRECTORY_SEPARATOR . 'launcher.log',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $persistedTask = $task;
        unset($persistedTask['authorization']);
        $encodedTask = json_encode($persistedTask, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($encodedTask) || file_put_contents($inputPath, $encodedTask) === false) {
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
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
        $taskId = trim((string)($task['task_id'] ?? ''));
        $authorization = trim((string)($task['authorization'] ?? ''));
        $authorizationEnv = trim((string)($task['authorization_env'] ?? ''));
        if ($phpBinary === '' || !is_file($thinkPath) || !is_file($inputPath)) {
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
            return false;
        }

        $dir = dirname($inputPath);
        if (!$this->isValidTaskId($taskId)
            || $authorization === ''
            || preg_match('/^SUXI_MANUAL_FETCH_AUTH_[A-F0-9]{24}$/', $authorizationEnv) !== 1
        ) {
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
            return false;
        }

        $previousAuthorization = getenv($authorizationEnv);
        if (!putenv($authorizationEnv . '=' . $authorization)) {
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
            return false;
        }

        try {
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
                    'if exist "%TASK_DIR%' . $inputFile . '" del /f /q "%TASK_DIR%' . $inputFile . '"',
                    'popd',
                    'exit /b %EXIT_CODE%',
                ];
                if (file_put_contents($batPath, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
                    $this->cleanupLaunchArtifacts($inputPath, $taskId);
                    return false;
                }
                $launched = $this->launchWindowsBatchFile($batPath);
                if (!$launched) {
                    $this->cleanupLaunchArtifacts($inputPath, $taskId);
                }
                return $launched;
            }

            $shellPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.sh';
            $command = 'cd ' . escapeshellarg($projectRoot)
                . ' && ' . escapeshellarg($phpBinary)
                . ' ' . escapeshellarg($thinkPath)
                . ' ' . self::COMMAND_NAME
                . ' --task-id=' . escapeshellarg($taskId)
                . ' --input=' . escapeshellarg($inputPath)
                . ' >> ' . escapeshellarg((string)($task['log'] ?? '')) . ' 2>&1';
            $shellScript = "#!/bin/sh\n"
                . $command . "\n"
                . 'exit_code=$?' . "\n"
                . 'rm -f -- ' . escapeshellarg($inputPath) . "\n"
                . 'exit $exit_code' . "\n";
            if (file_put_contents($shellPath, $shellScript) === false) {
                $this->cleanupLaunchArtifacts($inputPath, $taskId);
                return false;
            }
            @chmod($shellPath, 0755);
            $handle = @popen('sh ' . escapeshellarg($shellPath) . ' >/dev/null 2>&1 &', 'r');
            if (!is_resource($handle)) {
                $this->cleanupLaunchArtifacts($inputPath, $taskId);
                return false;
            }
            pclose($handle);
            return true;
        } finally {
            if ($previousAuthorization === false) {
                putenv($authorizationEnv);
            } else {
                putenv($authorizationEnv . '=' . $previousAuthorization);
            }
        }
    }

    private function authorizationEnvName(string $taskId): string
    {
        return 'SUXI_MANUAL_FETCH_AUTH_' . strtoupper(substr(hash('sha256', $taskId), 0, 24));
    }

    private function isValidTaskId(string $taskId): bool
    {
        return preg_match('/^manual_[a-z0-9_]+_fetch_\d+_\d{14}_[a-f0-9]{8}$/', $taskId) === 1;
    }

    private function cleanupLaunchArtifacts(string $inputPath, string $taskId): void
    {
        if ($inputPath === '' || basename($inputPath) !== 'input.json') {
            return;
        }

        $taskRoot = realpath($this->projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'manual_fetch_tasks');
        $dir = realpath(dirname($inputPath));
        if ($taskRoot === false
            || $dir === false
            || !str_starts_with($dir, rtrim($taskRoot, "\\/") . DIRECTORY_SEPARATOR)
        ) {
            return;
        }

        @unlink($dir . DIRECTORY_SEPARATOR . 'input.json');
        if ($this->isValidTaskId($taskId)) {
            @unlink($dir . DIRECTORY_SEPARATOR . $taskId . '.bat');
            @unlink($dir . DIRECTORY_SEPARATOR . $taskId . '.sh');
        }
        if ((glob($dir . DIRECTORY_SEPARATOR . '*') ?: []) === []) {
            @rmdir($dir);
        }
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

    private function launchWindowsBatchFile(string $batPath): bool
    {
        $launcherPath = $this->createWindowsBatchLauncher($batPath);
        if ($launcherPath !== '' && $this->launchWindowsScriptHost($launcherPath)) {
            return true;
        }

        if ($launcherPath !== '' && is_file($launcherPath)) {
            @unlink($launcherPath);
        }
        $this->appendWindowsLauncherDiagnostic($batPath, 'wscript launcher did not confirm execution; falling back to cmd start.');

        return $this->launchWindowsBatchFileWithStart($batPath);
    }

    private function launchWindowsScriptHost(string $launcherPath): bool
    {
        $wscript = $this->resolveWindowsScriptHost();
        if ($wscript === '') {
            return false;
        }

        $handle = @popen($this->quoteWindowsBatchArg($wscript) . ' //B //Nologo ' . $this->quoteWindowsBatchArg($launcherPath), 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);

        for ($i = 0; $i < 15; $i++) {
            if (!is_file($launcherPath)) {
                return true;
            }
            usleep(100000);
        }

        return false;
    }

    private function launchWindowsBatchFileWithStart(string $batPath): bool
    {
        $cmd = getenv('COMSPEC') ?: 'cmd.exe';
        $command = $this->quoteWindowsBatchArg($cmd)
            . ' /d /c start "" /D '
            . $this->quoteWindowsBatchArg(dirname($batPath))
            . ' '
            . $this->quoteWindowsBatchArg($batPath);

        $handle = @popen($command, 'r');
        if (!is_resource($handle)) {
            $this->appendWindowsLauncherDiagnostic($batPath, 'cmd start launcher failed to start.');
            return false;
        }
        pclose($handle);
        return true;
    }

    private function resolveWindowsScriptHost(): string
    {
        $systemRoot = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), "\\/");
        $candidates = array_filter([
            $systemRoot !== '' ? $systemRoot . '\\System32\\wscript.exe' : '',
            'C:\\Windows\\System32\\wscript.exe',
            'wscript.exe',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'wscript.exe' || is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function appendWindowsLauncherDiagnostic(string $batPath, string $message): void
    {
        $dir = dirname($batPath);
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'launcher.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }

    private function createWindowsBatchLauncher(string $batPath): string
    {
        $tempDir = rtrim(sys_get_temp_dir(), "\\/");
        if ($tempDir === '' || !is_dir($tempDir) || !is_writable($tempDir)) {
            return '';
        }

        $launcherPath = $tempDir . DIRECTORY_SEPARATOR . 'suxi-bg-launch-' . bin2hex(random_bytes(8)) . '.vbs';
        $command = 'cmd.exe /d /c call "' . $batPath . '"';
        $script = implode("\r\n", [
            'Set sh = CreateObject("WScript.Shell")',
            'sh.Run "' . str_replace('"', '""', $command) . '", 0, False',
            'On Error Resume Next',
            'CreateObject("Scripting.FileSystemObject").DeleteFile WScript.ScriptFullName, True',
            '',
        ]);
        $encoded = $this->encodeUtf16LeWithBom($script);
        if ($encoded === '' || file_put_contents($launcherPath, $encoded) === false) {
            return '';
        }

        return $launcherPath;
    }

    private function encodeUtf16LeWithBom(string $text): string
    {
        if (function_exists('mb_convert_encoding')) {
            return "\xFF\xFE" . (string)mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-16LE', $text);
            if (is_string($converted)) {
                return "\xFF\xFE" . $converted;
            }
        }

        return '';
    }
}
