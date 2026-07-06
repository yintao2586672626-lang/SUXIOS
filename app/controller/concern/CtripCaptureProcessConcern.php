<?php

namespace app\controller\concern;

trait CtripCaptureProcessConcern
{
    private function runMeituanCaptureProcess(array $args, string $cwd, int $timeoutSeconds): array
    {
        $command = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['success' => false, 'message' => '无法启动美团抓取进程', 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = time();
        $timedOut = false;

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $startedAt > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(250000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            return [
                'success' => false,
                'message' => '美团浏览器抓取超时，请确认弹出的浏览器已完成登录并能访问目标后台页面',
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }
        if ($exitCode !== 0 && $exitCode !== -1) {
            return [
                'success' => false,
                'message' => '美团浏览器抓取失败，退出码 ' . $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        return ['success' => true, 'message' => 'ok', 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function appendCtripApprovedMappingsArg(array $args, array $source, string $projectRoot): array
    {
        $approvedMappings = $this->resolveCtripApprovedMappingsPath($source, $projectRoot);
        if ($approvedMappings['configured'] && $approvedMappings['path'] !== '') {
            $args[] = '--approved-mappings=' . $approvedMappings['path'];
        }

        return [
            'args' => $args,
            'approved_mappings' => $approvedMappings,
            'error' => $approvedMappings['configured'] && $approvedMappings['path'] === '' ? (string)$approvedMappings['error'] : '',
        ];
    }

    private function appendCtripCaptureGateArgs(array $args, array $source): array
    {
        $fieldCoverageRate = $this->firstPresentCtripConfigValue($source, [
            'min_field_coverage_rate',
            'minFieldCoverageRate',
            'field_coverage_rate',
            'fieldCoverageRate',
        ], 80);
        if (is_numeric($fieldCoverageRate)) {
            $rate = max(0.0, min(100.0, (float)$fieldCoverageRate));
            $args[] = '--min-field-coverage-rate=' . $this->formatCtripCaptureGateNumber($rate);
        }

        $maxMissingFields = $this->firstPresentCtripConfigValue($source, [
            'max_missing_fields',
            'maxMissingFields',
        ], null);
        if ($maxMissingFields !== null && $maxMissingFields !== '' && is_numeric($maxMissingFields)) {
            $args[] = '--max-missing-fields=' . (string)max(0, (int)$maxMissingFields);
        }

        $requireFieldCoverage = $this->firstPresentCtripConfigValue($source, [
            'require_field_coverage',
            'requireFieldCoverage',
        ], null);
        if ($requireFieldCoverage !== null && $this->meituanBool($requireFieldCoverage)) {
            $args[] = '--require-field-coverage';
        }

        return $args;
    }

    private function isCtripLoginOnlyRequest(array $source): bool
    {
        foreach (['login_only', 'loginOnly', 'auth_only', 'authOnly', 'prepare_profile', 'prepareProfile'] as $key) {
            if (array_key_exists($key, $source) && $this->meituanBool($source[$key])) {
                return true;
            }
        }

        return false;
    }

    private function appendCtripLoginOnlyArg(array $args, array $source): array
    {
        if ($this->isCtripLoginOnlyRequest($source)) {
            $args[] = '--login-only=true';
        }

        return $args;
    }

    private function buildCtripLoginOnlyResponsePayload(array $payload, string $outputPath, string $stdout): array
    {
        return [
            'mode' => (string)($payload['mode'] ?? 'login_only'),
            'profile_id' => (string)($payload['profile_id'] ?? ''),
            'auth_status' => $payload['auth_status'] ?? null,
            'capture_gate' => $payload['capture_gate'] ?? null,
            'pages' => $payload['pages'] ?? [],
            'saved_count' => 0,
            'row_count' => 0,
            'counts' => [
                'business' => 0,
                'traffic' => 0,
                'standard_rows' => 0,
            ],
            'output' => $outputPath,
            'stdout' => $stdout,
        ];
    }

    private function formatCtripCaptureGateNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.000001) {
            return (string)(int)round($value);
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
