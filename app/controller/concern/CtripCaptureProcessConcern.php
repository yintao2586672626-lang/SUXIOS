<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\BrowserProfileCaptureRequestService;

trait CtripCaptureProcessConcern
{
    private function runMeituanCaptureProcess(array $args, string $cwd, int $timeoutSeconds): array
    {
        $result = BrowserProfileCaptureRequestService::runCaptureProcess(
            $args,
            $cwd,
            $timeoutSeconds,
            ['label' => 'Meituan browser capture']
        );
        if (($result['process_started'] ?? null) === false) {
            $result['message'] = '无法启动美团抓取进程';
        } elseif (($result['process_tree_exit_confirmed'] ?? null) !== true) {
            $result['message'] = '美团浏览器抓取进程树退出未确认，Profile 已保持锁定';
        } elseif (($result['timed_out'] ?? null) === true) {
            $result['message'] = '美团浏览器抓取超时，请确认弹出的浏览器已完成登录并能访问目标后台页面';
        } elseif (($result['cancelled'] ?? null) === true) {
            $result['message'] = '美团浏览器抓取已取消';
        } elseif (($result['success'] ?? null) !== true
            && !in_array((int)($result['exit_code'] ?? -1), [0, -1], true)
        ) {
            $result['message'] = '美团浏览器抓取失败，退出码 ' . (int)$result['exit_code'];
        }
        return $result;
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
