<?php
declare(strict_types=1);

namespace app\service\platform;

use app\contract\DataSourceAdapter;

final class CtripBrowserProfileDataSourceAdapter implements DataSourceAdapter
{
    private string $projectRoot;
    private string $nodeBinary;

    /** @var callable|null */
    private $processRunner;

    public function __construct(?string $projectRoot = null, ?string $nodeBinary = null, ?callable $processRunner = null)
    {
        $this->projectRoot = $projectRoot ?: dirname(__DIR__, 3);
        $this->nodeBinary = $nodeBinary ?: $this->resolveNodeBinary();
        $this->processRunner = $processRunner;
    }

    public function supports(array $source): bool
    {
        return strtolower((string)($source['platform'] ?? '')) === 'ctrip'
            && in_array((string)($source['ingestion_method'] ?? ''), ['browser_profile', 'profile_browser'], true);
    }

    public function fetch(array $source, array $options = []): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $secret = is_array($source['secret'] ?? null) ? $source['secret'] : [];
        $systemHotelId = (int)($source['system_hotel_id'] ?? 0);
        $profileId = $this->firstString($options, $config, ['profile_id', 'profileId', 'browser_profile_id', 'browserProfileId']);
        if ($profileId === '') {
            return [
                'status' => 'waiting_config',
                'message' => 'Ctrip browser Profile ID is not configured.',
                'payload' => [],
            ];
        }

        $interactive = $this->truthy($options['interactive_browser'] ?? $options['interactiveBrowser'] ?? false);
        $profileDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $this->safeName($profileId);
        if (!is_dir($profileDir) && !$interactive) {
            return [
                'status' => 'waiting_config',
                'message' => 'Ctrip browser Profile is not prepared: storage/ctrip_profile_' . $this->safeName($profileId),
                'payload' => [],
            ];
        }

        $scriptPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return [
                'status' => 'failed',
                'message' => 'Ctrip browser capture script was not found.',
                'payload' => [],
            ];
        }
        if ($this->nodeBinary === '') {
            return [
                'status' => 'failed',
                'message' => 'Node.js is not configured for Ctrip browser capture.',
                'payload' => [],
            ];
        }

        $safeProfileId = $this->safeName($profileId);
        $lock = $this->acquireLock('ctrip', $safeProfileId);
        if ($lock === null) {
            return [
                'status' => 'failed',
                'message' => 'Ctrip browser Profile capture is already running for profile_id=' . $profileId,
                'payload' => ['lock_key' => 'ctrip:' . $safeProfileId],
            ];
        }

        $outputDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'platform_data_sources';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            $this->releaseLock($lock);
            return [
                'status' => 'failed',
                'message' => 'Cannot create Ctrip browser capture output directory.',
                'payload' => [],
            ];
        }

        $dataDate = $this->normalizeDate((string)($options['data_date'] ?? $options['dataDate'] ?? $config['data_date'] ?? $config['dataDate'] ?? ''));
        if ($dataDate === '') {
            $dataDate = date('Y-m-d', strtotime('-1 day'));
        }
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_browser_source_' . $this->safeName($profileId) . '_' . date('YmdHis') . '.json';
        $sections = $this->sanitizeSections($this->firstString($options, $config, ['capture_sections', 'captureSections', 'sections', 'profile_sections'], 'business_overview'));
        $hotelId = $this->firstString($options, $config, ['hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId']);
        $hotelName = $this->firstString($options, $config, ['hotel_name', 'hotelName', 'name']);
        $timeoutSeconds = max(60, min(900, (int)($options['timeout_seconds'] ?? $options['timeoutSeconds'] ?? ($interactive ? 600 : 120))));

        $args = [
            $this->nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--data-date=' . $dataDate,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . ($interactive ? '300000' : '30000'),
            '--sections=' . $sections,
            $interactive ? '--headless=false' : '--headless=true',
        ];
        if ($hotelId !== '') {
            $args[] = '--hotel-id=' . $hotelId;
        }
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }

        $cookieFile = $this->createCookieFile((string)($secret['cookies'] ?? $secret['cookie'] ?? ''));
        if ($cookieFile !== '') {
            $args[] = '--cookies-file=' . $cookieFile;
        }

        try {
            $runResult = $this->runProcess($args, $this->projectRoot, $timeoutSeconds);
        } finally {
            if ($cookieFile !== '' && is_file($cookieFile)) {
                @unlink($cookieFile);
            }
            $this->releaseLock($lock);
        }

        if (!is_file($outputPath)) {
            $message = $this->buildProcessFailureMessage(
                'Ctrip browser capture did not produce an output file',
                $runResult
            );
            return [
                'status' => 'failed',
                'message' => $message,
                'payload' => [
                    'error_summary' => $message,
                    'stdout' => $this->trimLog((string)($runResult['stdout'] ?? '')),
                    'stderr' => $this->trimLog((string)($runResult['stderr'] ?? '')),
                ],
            ];
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return [
                'status' => 'failed',
                'message' => 'Ctrip browser capture output is not valid JSON.',
                'payload' => ['output' => $outputPath],
            ];
        }
        $payload['output'] = $outputPath;
        $payload['data_source_capture'] = [
            'platform' => 'ctrip',
            'acquisition_method' => 'browser_profile',
            'profile_id' => $profileId,
            'capture_sections' => $sections,
            'data_date' => $dataDate,
            'captured_by' => 'platform_data_source_sync',
        ];

        $authOk = (bool)($payload['auth_status']['ok'] ?? false);
        if (!$authOk) {
            return [
                'status' => 'waiting_config',
                'message' => (string)($payload['auth_status']['message'] ?? 'Ctrip login session is not ready; open the Profile and complete login.'),
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }

        $gate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
        $gateWarning = null;
        if (($gate['status'] ?? 'fail') !== 'pass') {
            $failedCheckIds = $this->captureGateFailedCheckIds($gate);
            if (!$this->canContinueWithSoftCaptureGateWarning($payload, $failedCheckIds)) {
                $failedIds = implode(',', $failedCheckIds);
                return [
                    'status' => 'failed',
                    'message' => 'Ctrip browser capture gate failed' . ($failedIds !== '' ? ': ' . $failedIds : '.'),
                    'payload' => $this->compactFailurePayload($payload, $runResult),
                ];
            }
            $gateWarning = $this->buildCaptureGateWarning($gate, $failedCheckIds);
        }

        $rows = $this->buildRows($payload, $source, $systemHotelId, $dataDate, $hotelId !== '' ? $hotelId : $profileId);
        if (empty($rows)) {
            return [
                'status' => 'failed',
                'message' => 'Ctrip browser capture completed but no business rows were parsed.',
                'payload' => $this->compactFailurePayload($payload, $runResult),
            ];
        }

        if ($gateWarning !== null) {
            $payload['capture_gate_warning'] = $gateWarning;
        }
        $payload['rows'] = $rows;
        $payload['sync_summary'] = [
            'row_count' => count($rows),
            'standard_row_count' => count(is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : []),
            'business_count' => count(is_array($payload['business'] ?? null) ? $payload['business'] : []),
            'traffic_count' => count(is_array($payload['traffic'] ?? null) ? $payload['traffic'] : []),
        ];

        return [
            'status' => 'success',
            'message' => 'Ctrip browser Profile capture completed.' . ($gateWarning !== null ? ' Capture gate warning retained.' : ''),
            'payload' => $payload,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $payload, array $source, int $systemHotelId, string $dataDate, string $fallbackHotelId): array
    {
        $rows = [];
        foreach (['standard_rows', 'business', 'traffic'] as $section) {
            $sectionRows = is_array($payload[$section] ?? null) ? $payload[$section] : [];
            foreach ($sectionRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['source'] = 'ctrip';
                $row['platform'] = $row['platform'] ?? 'ctrip';
                $row['system_hotel_id'] = $row['system_hotel_id'] ?? $systemHotelId;
                $row['hotel_id'] = $row['hotel_id'] ?? $row['hotelId'] ?? $fallbackHotelId;
                $row['hotel_name'] = $row['hotel_name'] ?? $row['hotelName'] ?? $source['name'] ?? '';
                $row['data_date'] = $this->normalizeDate((string)($row['data_date'] ?? $row['dataDate'] ?? $row['date'] ?? '')) ?: $dataDate;
                if (!isset($row['data_type'])) {
                    $row['data_type'] = $section === 'traffic' ? 'traffic' : 'business';
                }
                $row['acquisition_method'] = 'browser_profile';
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function compactFailurePayload(array $payload, array $runResult): array
    {
        return [
            'auth_status' => $payload['auth_status'] ?? null,
            'capture_gate' => $payload['capture_gate'] ?? null,
            'capture_gate_warning' => $payload['capture_gate_warning'] ?? null,
            'capture_audit' => $payload['capture_audit'] ?? null,
            'pages' => $payload['pages'] ?? [],
            'xhr_urls' => array_slice(is_array($payload['xhr_urls'] ?? null) ? $payload['xhr_urls'] : [], 0, 20),
            'output' => $payload['output'] ?? '',
            'stdout' => $this->trimLog((string)($runResult['stdout'] ?? '')),
            'stderr' => $this->trimLog((string)($runResult['stderr'] ?? '')),
        ];
    }

    private function captureGateFailedCheckIds(array $gate): array
    {
        return array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            is_array($gate['failed_check_ids'] ?? null) ? $gate['failed_check_ids'] : []
        )));
    }

    private function captureGateBlockingFailedCheckIds(array $failedCheckIds): array
    {
        $softCheckIds = ['field_coverage', 'endpoint_coverage'];
        return array_values(array_filter(
            $failedCheckIds,
            static fn($checkId): bool => !in_array((string)$checkId, $softCheckIds, true)
        ));
    }

    private function canContinueWithSoftCaptureGateWarning(array $payload, array $failedCheckIds): bool
    {
        if ($failedCheckIds === [] || $this->captureGateBlockingFailedCheckIds($failedCheckIds) !== []) {
            return false;
        }
        if (!(bool)($payload['auth_status']['ok'] ?? false)) {
            return false;
        }

        return $this->countPayloadRows($payload, 'standard_rows') > 0
            && (
                $this->countPayloadRows($payload, 'business') > 0
                || $this->countPayloadRows($payload, 'traffic') > 0
                || $this->countPayloadRows($payload, 'responses') > 0
            );
    }

    private function buildCaptureGateWarning(array $gate, array $failedCheckIds): array
    {
        return [
            'level' => 'warning',
            'message' => 'Ctrip browser Profile captured usable rows, but capture gate coverage has gaps. Saved captured rows and kept diagnostics for missing coverage.',
            'status' => (string)($gate['status'] ?? 'unknown'),
            'failed_check_ids' => $failedCheckIds,
            'blocking_failed_check_ids' => $this->captureGateBlockingFailedCheckIds($failedCheckIds),
        ];
    }

    private function countPayloadRows(array $payload, string $section): int
    {
        return is_array($payload[$section] ?? null) ? count($payload[$section]) : 0;
    }

    private function runProcess(array $args, string $cwd, int $timeoutSeconds): array
    {
        if ($this->processRunner !== null) {
            return (array)call_user_func($this->processRunner, $args, $cwd, $timeoutSeconds);
        }

        $command = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['success' => false, 'message' => 'Cannot start Ctrip browser capture process.', 'stdout' => '', 'stderr' => ''];
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
            return ['success' => false, 'message' => 'Ctrip browser capture timed out.', 'stdout' => $stdout, 'stderr' => $stderr];
        }
        if ($exitCode !== 0 && $exitCode !== -1) {
            return ['success' => false, 'message' => 'Ctrip browser capture exited with code ' . $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        return ['success' => true, 'message' => 'ok', 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function createCookieFile(string $cookies): string
    {
        $cookies = trim($cookies);
        if ($cookies === '') {
            return '';
        }
        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'secret';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'ctrip_browser_profile_cookie_' . bin2hex(random_bytes(6)) . '.txt';
        return file_put_contents($path, $cookies) === false ? '' : $path;
    }

    private function acquireLock(string $platform, string $profileId)
    {
        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'profile_capture_' . $platform . '_' . $this->safeName($profileId) . '.lock';
        $handle = fopen($path, 'c+');
        if (!$handle) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        ftruncate($handle, 0);
        fwrite($handle, json_encode(['platform' => $platform, 'profile_id' => $profileId, 'pid' => getmypid(), 'locked_at' => date('c')], JSON_UNESCAPED_SLASHES));
        return $handle;
    }

    private function releaseLock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function resolveNodeBinary(): string
    {
        $candidates = array_filter([
            trim((string)(getenv('NODE_BINARY') ?: '')),
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            getenv('USERPROFILE') ? getenv('USERPROFILE') . '\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\node\\bin\\node.exe' : '',
            'node',
        ]);
        foreach ($candidates as $candidate) {
            if ($candidate === 'node' || is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function firstString(array $options, array $config, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $options[$key] ?? $config[$key] ?? null;
            if ($value !== null && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return $default;
    }

    private function sanitizeSections(string $sections): string
    {
        $sections = strtolower(preg_replace('/[^a-z,_\-\s]+/i', '', $sections) ?: '');
        $parts = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\s]+/', $sections) ?: []))));
        return implode(',', $parts) ?: 'business_overview';
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    private function safeName(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($value)) ?: 'default';
    }

    private function trimLog(string $value): string
    {
        $value = trim($value);
        return mb_strlen($value) > 4000 ? mb_substr($value, -4000) : $value;
    }

    private function buildProcessFailureMessage(string $prefix, array $runResult): string
    {
        $message = trim((string)($runResult['message'] ?? 'unknown error'));
        $summary = $this->extractProcessErrorSummary(
            (string)($runResult['stderr'] ?? ''),
            (string)($runResult['stdout'] ?? '')
        );
        $result = $prefix . ($message !== '' ? ': ' . $message : '');
        return $summary !== '' ? $result . ' | ' . $summary : $result;
    }

    private function extractProcessErrorSummary(string $stderr, string $stdout): string
    {
        $text = trim($stderr) !== '' ? $stderr : $stdout;
        $text = trim((string)preg_replace('/\e\[[\d;]*m/', '', $text));
        if ($text === '') {
            return '';
        }
        if (stripos($text, 'spawn EPERM') !== false) {
            return 'browser_runtime_error=spawn EPERM; check browser executable permission and scheduled-task runtime account.';
        }
        if (stripos($text, 'spawn EACCES') !== false) {
            return 'browser_runtime_error=spawn EACCES; check browser executable permission and scheduled-task runtime account.';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $text) ?: [])));
        foreach ($lines as $line) {
            if (stripos($line, 'Error') !== false || stripos($line, 'Exception') !== false || stripos($line, 'failed') !== false) {
                return mb_substr($line, 0, 240);
            }
        }
        return mb_substr((string)end($lines), 0, 240);
    }
}
