<?php
declare(strict_types=1);

namespace app\service;

final class BrowserProfileCaptureRequestService
{
    public const MEITUAN_DEFAULT_SECTIONS = 'traffic,orders';
    public const MEITUAN_FULL_SECTIONS = 'traffic,orders,ads,reviews';

    public static function safeFilePart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value) ?: 'default';
        return substr($safe, 0, 80);
    }

    public static function uniqueCaptureRunToken(string $timePrefix = ''): string
    {
        $now = microtime(true);
        $seconds = (int)floor($now);
        $micros = max(0, min(999999, (int)floor(($now - $seconds) * 1000000)));
        $timePrefix = preg_replace('/[^0-9]+/', '', $timePrefix) ?: date('YmdHis', $seconds);
        return substr($timePrefix, 0, 20)
            . '_' . str_pad((string)$micros, 6, '0', STR_PAD_LEFT)
            . '_' . bin2hex(random_bytes(8));
    }

    public static function timeoutSeconds($value): int
    {
        return max(60, min(900, (int)$value));
    }

    public static function loginTimeoutMs($value): int
    {
        return max(30000, min(600000, (int)$value));
    }

    /**
     * @param list<string> $args
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function runCaptureProcess(
        array $args,
        string $cwd,
        int $timeoutSeconds,
        array $options = [],
        ?callable $injectedRunner = null
    ): array {
        if ($injectedRunner !== null) {
            try {
                return self::normalizeInjectedProcessResult((array)call_user_func(
                    $injectedRunner,
                    $args,
                    $cwd,
                    $timeoutSeconds
                ));
            } catch (\Throwable) {
                return [
                    'success' => false,
                    'status_code' => 'injected_process_runner_failed',
                    'message' => 'Injected browser capture process runner failed.',
                    'stdout' => '',
                    'stderr' => '',
                    'exit_code' => -1,
                    'process_started' => true,
                    'process_pid' => 0,
                    'process_tree_exit_confirmed' => false,
                    'termination' => [
                        'contract' => BrowserCaptureProcessRunner::TERMINATION_CONTRACT,
                        'requested' => false,
                        'reason' => 'injected_runner_error',
                        'platform' => 'injected',
                        'pid' => 0,
                        'confirmed_exited' => false,
                        'confirmation_source' => 'unconfirmed',
                        'errors' => ['injected_runner_error'],
                    ],
                ];
            }
        }

        return (new BrowserCaptureProcessRunner())->run($args, $cwd, $timeoutSeconds, $options);
    }

    /** @param array<string,mixed> $result */
    public static function processTreeExitConfirmed(array $result): bool
    {
        if (($result['process_started'] ?? null) === false) {
            return true;
        }
        return ($result['process_tree_exit_confirmed'] ?? null) === true
            && ($result['termination']['confirmed_exited'] ?? null) === true;
    }

    /** @param array<string,mixed> $payload */
    public static function createEphemeralCaptureJson(array $payload, string $category = 'config'): string
    {
        $path = self::createEphemeralCaptureFile($category, 'json');
        if ($path === '') {
            return '';
        }
        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            @unlink($path);
            return '';
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            @unlink($path);
            return '';
        }
        $written = false;
        try {
            $written = flock($handle, LOCK_EX)
                && fwrite($handle, $json) === strlen($json)
                && fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if (!$written) {
            @unlink($path);
            return '';
        }
        if (!@chmod($path, 0600)) {
            @unlink($path);
            return '';
        }
        return $path;
    }

    public static function createEphemeralCaptureFile(string $category, string $extension): string
    {
        $category = preg_replace('/[^a-z0-9_-]+/i', '-', trim($category)) ?: 'artifact';
        $extension = preg_replace('/[^a-z0-9]+/i', '', trim($extension)) ?: 'tmp';
        try {
            $path = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
                . 'suxi-browser-' . strtolower($category) . '-' . self::uniqueCaptureRunToken()
                . '.' . strtolower($extension);
        } catch (\Throwable) {
            return '';
        }
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            return '';
        }
        fclose($handle);
        if (!@chmod($path, 0600)) {
            @unlink($path);
            return '';
        }
        return $path;
    }

    public static function prepareEphemeralCaptureFileForWrite(string $path, bool $truncate = false): bool
    {
        if ($path === '' || is_link($path)) {
            return false;
        }
        $tempRoot = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($path));
        if (!is_string($tempRoot) || !is_string($parent)) {
            return false;
        }
        $tempRoot = rtrim(str_replace('\\', '/', $tempRoot), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        $sameParent = PHP_OS_FAMILY === 'Windows'
            ? hash_equals(strtolower($tempRoot), strtolower($parent))
            : hash_equals($tempRoot, $parent);
        $name = basename($path);
        $validName = PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(strtolower($name), 'suxi-browser-')
            : str_starts_with($name, 'suxi-browser-');
        if (!$sameParent || !$validName) {
            return false;
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            return false;
        }
        try {
            if ($truncate && (!@ftruncate($handle, 0) || !@rewind($handle) || !@fflush($handle))) {
                return false;
            }
            return @chmod($path, 0600);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Delete ephemeral support files only after the full process tree is
     * confirmed gone. Otherwise attach them to the same quarantine cleanup
     * receipt as stdout/stderr spool files.
     *
     * @param array<string,mixed> $runResult
     * @param list<string> $paths
     * @return array<string,mixed>
     */
    public static function settleEphemeralCaptureArtifacts(array $runResult, array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            array_map('strval', $paths),
            static fn(string $path): bool => trim($path) !== ''
        )));
        if ($paths === []) {
            return $runResult;
        }
        if (self::processTreeExitConfirmed($runResult)) {
            BrowserCaptureProcessRunner::cleanupRecordedSpoolArtifacts($paths);
            return $runResult;
        }
        $runResult['spool_artifacts'] = array_values(array_unique(array_merge(
            is_array($runResult['spool_artifacts'] ?? null) ? $runResult['spool_artifacts'] : [],
            $paths
        )));
        return $runResult;
    }

    /** @param array<string,mixed> $runResult @param list<string> $paths @return array<string,mixed> */
    public static function quarantineEphemeralArtifactsIfUnconfirmed(array $runResult, array $paths): array
    {
        if (self::processTreeExitConfirmed($runResult)) {
            return $runResult;
        }
        $runResult['spool_artifacts'] = array_values(array_unique(array_merge(
            is_array($runResult['spool_artifacts'] ?? null) ? $runResult['spool_artifacts'] : [],
            array_values(array_filter(array_map('strval', $paths), static fn(string $path): bool => trim($path) !== ''))
        )));
        return $runResult;
    }

    /**
     * Acquire one logical Profile lock. A prior unconfirmed process-tree exit
     * leaves a persistent quarantine marker, so a later PHP request cannot
     * reuse the Profile merely because the original flock handle was closed by
     * request shutdown.
     *
     * @return resource|null
     */
    public static function acquireProfileCaptureLock(
        string $projectRoot,
        string $platform,
        string $profileKey
    ) {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return null;
        }
        $dir = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $safeProfileKey = self::safeFilePart($profileKey);
        $path = $dir . DIRECTORY_SEPARATOR . 'profile_capture_' . $platform . '_' . $safeProfileKey . '.lock';
        $handle = fopen($path, 'c+');
        if (!is_resource($handle)) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        rewind($handle);
        $existing = json_decode((string)stream_get_contents($handle), true);
        if (is_array($existing) && ($existing['state'] ?? '') === 'termination_unconfirmed') {
            $recovery = self::recoverQuarantineOnLockedHandle(
                $handle,
                $existing
            );
            if (($recovery['recovered'] ?? false) !== true) {
                flock($handle, LOCK_UN);
                fclose($handle);
                return null;
            }
        }

        self::writeProfileLockPayload($handle, [
            'state' => 'active',
            'platform' => $platform,
            'profile_key_hash' => hash('sha256', $safeProfileKey),
            'owner_pid' => getmypid(),
            'locked_at' => date('c'),
        ]);
        return $handle;
    }

    /**
     * Controlled recovery entry for a persistent Profile quarantine. It never
     * clears a marker merely because the old PHP flock disappeared: the
     * recorded process tree must be supported and observed empty first.
     *
     * @return array<string,mixed>
     */
    public static function recoverProfileCaptureLock(
        string $projectRoot,
        string $platform,
        string $profileKey
    ): array {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return ['recovered' => false, 'status_code' => 'profile_lock_platform_invalid'];
        }
        $dir = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($dir)) {
            return ['recovered' => true, 'status_code' => 'profile_lock_absent'];
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'profile_capture_' . $platform . '_'
            . self::safeFilePart($profileKey) . '.lock';
        if (!is_file($path)) {
            return ['recovered' => true, 'status_code' => 'profile_lock_absent'];
        }
        $handle = fopen($path, 'c+');
        if (!is_resource($handle)) {
            return ['recovered' => false, 'status_code' => 'profile_lock_open_failed'];
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return ['recovered' => false, 'status_code' => 'profile_lock_busy'];
        }
        try {
            rewind($handle);
            $existing = json_decode((string)stream_get_contents($handle), true);
            if (!is_array($existing) || ($existing['state'] ?? '') !== 'termination_unconfirmed') {
                return ['recovered' => true, 'status_code' => 'profile_lock_not_quarantined'];
            }
            return self::recoverQuarantineOnLockedHandle(
                $handle,
                $existing
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param resource|null $lock */
    public static function releaseProfileCaptureLock($lock): void
    {
        if (!is_resource($lock)) {
            return;
        }
        self::writeProfileLockPayload($lock, [
            'state' => 'released',
            'released_at' => date('c'),
        ]);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * @param resource|null $lock
     * @param array<string,mixed> $runResult
     */
    public static function finalizeProfileCaptureLock($lock, array $runResult): bool
    {
        if (!is_resource($lock)) {
            return true;
        }
        if (self::processTreeExitConfirmed($runResult)) {
            self::releaseProfileCaptureLock($lock);
            return true;
        }

        $termination = is_array($runResult['termination'] ?? null) ? $runResult['termination'] : [];
        $processTree = is_array($runResult['process_tree'] ?? null) ? $runResult['process_tree'] : [];
        $processTree['supported'] = ($processTree['supported'] ?? false) === true;
        $processTree['platform'] = trim((string)($processTree['platform'] ?? $termination['platform'] ?? PHP_OS_FAMILY));
        $processTree['strategy'] = trim((string)($processTree['strategy'] ?? ''));
        $processTree['root_pid'] = max(0, (int)($processTree['root_pid'] ?? $runResult['process_pid'] ?? $termination['pid'] ?? 0));
        $processTree['root_identity'] = trim((string)($processTree['root_identity'] ?? ''));
        $processTree['group_id'] = max(0, (int)($processTree['group_id'] ?? 0));
        $processTree['tracked_members'] = self::compactProcessMembers($processTree['tracked_members'] ?? $termination['tracked_descendants'] ?? []);
        $processTree['survivors'] = self::compactProcessMembers($processTree['survivors'] ?? $termination['surviving_descendants'] ?? []);
        $processTree['exited'] = false;
        $spoolArtifacts = array_values(array_slice(array_filter(
            array_map('strval', is_array($runResult['spool_artifacts'] ?? null) ? $runResult['spool_artifacts'] : []),
            static fn(string $path): bool => trim($path) !== ''
        ), 0, 8));
        self::writeProfileLockPayload($lock, [
            'state' => 'termination_unconfirmed',
            'quarantined_at' => date('c'),
            'process_pid' => max(0, (int)($runResult['process_pid'] ?? $termination['pid'] ?? 0)),
            'process_tree' => $processTree,
            'spool_artifacts' => $spoolArtifacts,
            'termination' => [
                'contract' => (string)($termination['contract'] ?? BrowserCaptureProcessRunner::TERMINATION_CONTRACT),
                'reason' => (string)($termination['reason'] ?? 'unknown'),
                'platform' => (string)($termination['platform'] ?? PHP_OS_FAMILY),
                'confirmed_exited' => false,
                'confirmation_source' => (string)($termination['confirmation_source'] ?? 'unconfirmed'),
                'tracked_descendants' => $processTree['tracked_members'],
                'surviving_descendants' => $processTree['survivors'],
                'errors' => array_values(array_slice(array_map(
                    'strval',
                    is_array($termination['errors'] ?? null) ? $termination['errors'] : []
                ), 0, 10)),
            ],
        ]);
        // Deliberately do not unlock or close here. PHP may eventually close
        // the OS handle, but the persistent quarantine marker above remains a
        // logical lock for subsequent requests until an operator verifies the
        // process tree and clears the marker.
        return false;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private static function normalizeInjectedProcessResult(array $result): array
    {
        if (array_key_exists('exit_code', $result)
            && (int)$result['exit_code'] === -1
            && ($result['success'] ?? false) === true
        ) {
            $result['success'] = false;
            $result['status_code'] = 'process_exit_unknown';
            $result['message'] = 'Browser capture exit code could not be verified.';
        }
        if (isset($result['process_tree_exit_confirmed'], $result['termination'])) {
            return $result;
        }
        $result['process_started'] = $result['process_started'] ?? true;
        $result['process_tree_exit_confirmed'] = true;
        $result['status_code'] = $result['status_code'] ?? (($result['success'] ?? false) ? 'ok' : 'injected_process_failed');
        $result['termination'] = [
            'contract' => BrowserCaptureProcessRunner::TERMINATION_CONTRACT,
            'requested' => false,
            'reason' => 'injected_runner_completed',
            'platform' => 'injected',
            'pid' => 0,
            'grace_ms' => 0,
            'force_grace_ms' => 0,
            'soft' => ['attempted' => false, 'accepted' => false, 'strategy' => '', 'exit_code' => null],
            'force' => ['attempted' => false, 'accepted' => false, 'strategy' => '', 'exit_code' => null],
            'confirmed_exited' => true,
            'confirmation_source' => 'injected_runner_contract',
            'observed_exit_code' => (int)($result['exit_code'] ?? -1),
            'close_deferred' => false,
            'errors' => [],
        ];
        return $result;
    }

    /** @return array<string,mixed> */
    private static function recoverQuarantineOnLockedHandle(
        $handle,
        array $marker
    ): array {
        $processTree = is_array($marker['process_tree'] ?? null) ? $marker['process_tree'] : [];
        try {
            $inspection = BrowserCaptureProcessRunner::inspectRecordedProcessTree($processTree);
        } catch (\Throwable) {
            $inspection = ['status' => 'unknown', 'alive' => true, 'supported' => false, 'survivors' => []];
        }
        if (($inspection['supported'] ?? false) !== true
            || ($inspection['status'] ?? '') !== 'exited'
            || ($inspection['alive'] ?? true) !== false
        ) {
            return [
                'recovered' => false,
                'status_code' => ($inspection['status'] ?? '') === 'alive'
                    ? 'profile_process_tree_still_alive'
                    : 'profile_process_tree_exit_unconfirmed',
                'inspection' => $inspection,
            ];
        }

        $artifacts = array_values(array_filter(
            array_map('strval', is_array($marker['spool_artifacts'] ?? null) ? $marker['spool_artifacts'] : []),
            static fn(string $path): bool => trim($path) !== ''
        ));
        try {
            $cleanup = BrowserCaptureProcessRunner::cleanupRecordedSpoolArtifacts($artifacts);
        } catch (\Throwable) {
            $cleanup = ['removed' => 0, 'rejected' => 0, 'failed' => 1];
        }
        if ((int)($cleanup['failed'] ?? 0) > 0 || (int)($cleanup['rejected'] ?? 0) > 0) {
            return [
                'recovered' => false,
                'status_code' => 'profile_quarantine_artifact_cleanup_failed',
                'inspection' => $inspection,
                'artifact_cleanup' => $cleanup,
            ];
        }

        self::writeProfileLockPayload($handle, [
            'state' => 'termination_recovered',
            'recovered_at' => date('c'),
            'process_tree' => $processTree,
            'tree_inspection' => $inspection,
            'artifact_cleanup' => $cleanup,
        ]);
        return [
            'recovered' => true,
            'status_code' => 'profile_process_tree_exit_recovered',
            'inspection' => $inspection,
            'artifact_cleanup' => $cleanup,
        ];
    }

    /** @return list<array{pid:int,identity:string,parent_pid:int}> */
    private static function compactProcessMembers(mixed $members): array
    {
        $safe = [];
        foreach (is_array($members) ? $members : [] as $member) {
            if (!is_array($member)) {
                continue;
            }
            $pid = max(0, (int)($member['pid'] ?? 0));
            $identity = trim((string)($member['identity'] ?? ''));
            if ($pid <= 0 || $identity === '') {
                continue;
            }
            $safe[$pid . ':' . $identity] = [
                'pid' => $pid,
                'identity' => substr($identity, 0, 128),
                'parent_pid' => max(0, (int)($member['parent_pid'] ?? 0)),
            ];
            if (count($safe) >= 256) {
                break;
            }
        }
        return array_values($safe);
    }

    /** @param resource $handle @param array<string,mixed> $payload */
    private static function writeProfileLockPayload($handle, array $payload): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string)json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        fflush($handle);
    }

    public static function resolveNodeBinary(): string
    {
        $configured = trim((string)(getenv('NODE_BINARY') ?: (function_exists('env') ? env('NODE_BINARY', '') : '')));
        $candidates = array_filter([
            $configured,
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

    public static function resolveChromePath(): string
    {
        $configured = trim((string)(getenv('CHROME_PATH') ?: (function_exists('env') ? env('CHROME_PATH', '') : '')));
        return $configured !== '' && is_file($configured) ? $configured : '';
    }

    public static function resolveMeituanStoreId(array $requestData): string
    {
        return trim((string)($requestData['store_id'] ?? $requestData['storeId'] ?? $requestData['poi_id'] ?? ''));
    }

    public static function resolveMeituanPoiId(array $requestData): string
    {
        return trim((string)($requestData['poi_id'] ?? $requestData['poiId'] ?? ''));
    }

    public static function resolveMeituanPoiName(array $requestData): string
    {
        return trim((string)($requestData['poi_name'] ?? $requestData['poiName'] ?? ''));
    }

    public static function resolveMeituanAdsUrl(array $requestData): string
    {
        return trim((string)($requestData['ads_url'] ?? $requestData['adsUrl'] ?? ''));
    }

    public static function normalizeMeituanSections(array $requestData): string
    {
        $value = $requestData['sections'] ?? $requestData['capture_sections'] ?? $requestData['captureSections'] ?? '';
        return self::normalizeMeituanProfileSections($value, '');
    }

    public static function normalizeMeituanProfileSections($value, string $fallback = self::MEITUAN_DEFAULT_SECTIONS): string
    {
        $raw = is_array($value)
            ? implode(',', array_map(static fn($item): string => (string)$item, $value))
            : trim((string)$value);
        $raw = preg_replace('/[^a-zA-Z,_\-\s]+/', '', $raw) ?: '';
        $items = preg_split('/[,\s]+/', strtolower($raw)) ?: [];
        $sections = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (in_array($item, ['full', 'complete', 'all'], true)) {
                foreach (explode(',', self::MEITUAN_FULL_SECTIONS) as $fullSection) {
                    $sections[$fullSection] = true;
                }
                continue;
            }
            if (in_array($item, ['default', 'core'], true)) {
                foreach (explode(',', self::MEITUAN_DEFAULT_SECTIONS) as $defaultSection) {
                    $sections[$defaultSection] = true;
                }
                continue;
            }
            $normalized = match ($item) {
                'ad', 'ads', 'advertising' => 'ads',
                'order', 'orders' => 'orders',
                'review', 'reviews', 'comment', 'comments', 'review_data' => 'reviews',
                'traffic', 'flow', 'flow_data', 'flowdata', 'businessdata', 'business_data',
                'business', 'overview', 'realtime', 'realtime_snapshot', 'peer_rank', 'peerrank',
                'competitor_rank', 'competitorrank', 'traffic_analysis', 'trafficanalysis',
                'flow_analysis', 'flowanalysis', 'traffic_forecast', 'trafficforecast',
                'flow_forecast', 'flowforecast', 'search_keyword', 'search_keywords',
                'searchkeyword', 'searchkeywords', 'room_type', 'room_types', 'roomtype',
                'roomtypes', 'product', 'products' => 'traffic',
                'order_flow', 'orderflow', 'order_loss', 'orderloss', 'loss_order', 'lossorder' => 'order_flow',
                default => '',
            };
            if ($normalized !== '') {
                $sections[$normalized] = true;
            }
        }

        return implode(',', array_keys($sections)) ?: $fallback;
    }

    /**
     * Event rows use their own event date; cumulative report rows must match the requested target date.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public static function mismatchedMeituanTargetDates(array $rows, string $targetDate): array
    {
        $targetDate = trim($targetDate);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) !== 1) {
            return [];
        }
        $mismatches = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dataType = trim((string)($row['data_type'] ?? ''));
            $raw = self::meituanDateEvidencePayload($row);
            if (self::hasVerifiedMeituanEventDate($row, $raw, $dataType)) {
                continue;
            }
            $rowDate = trim((string)($row['data_date'] ?? ''));
            if ($rowDate !== '' && $rowDate !== $targetDate) {
                $mismatches[] = $rowDate;
            }
        }
        return array_values(array_unique($mismatches));
    }

    /**
     * Cumulative Meituan rows need row/request/response/page date evidence. A capture-context
     * fallback date only describes the requested context and must not prove platform data date.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public static function unverifiedMeituanTargetDateRows(array $rows, string $targetDate): array
    {
        $targetDate = trim($targetDate);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) !== 1) {
            return [];
        }
        $unverified = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $dataType = trim((string)($row['data_type'] ?? ''));
            $raw = self::meituanDateEvidencePayload($row);
            if (self::hasVerifiedMeituanEventDate($row, $raw, $dataType)) {
                continue;
            }
            if (in_array($dataType, ['order', 'review', 'traffic_forecast'], true)) {
                $unverified[] = ($dataType !== '' ? $dataType : 'row') . ':' . $index;
                continue;
            }
            $rowDate = trim((string)($row['data_date'] ?? $row['dataDate'] ?? $row['date'] ?? ''));
            if ($rowDate !== $targetDate) {
                continue;
            }
            $source = trim((string)($raw['date_source'] ?? $raw['dateSource'] ?? $row['date_source'] ?? $row['dateSource'] ?? ''));
            if ($source === '' && self::hasExplicitMeituanRowDate($raw)) {
                $source = 'row';
            }
            if (!self::isAuthoritativeMeituanDateSource($source)) {
                $dataType = trim((string)($row['data_type'] ?? 'row')) ?: 'row';
                $unverified[] = $dataType . ':' . $index;
            }
        }
        return $unverified;
    }

    /**
     * Drop only non-core supplemental forecast rows that cannot prove their own
     * forecast date. Core traffic/order/review rows remain subject to the normal
     * target-date gate and are never silently accepted.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{rows:array<int,array<string,mixed>>,dropped_count:int,dropped_types:array<int,string>}
     */
    public static function filterUnverifiedMeituanSupplementalRows(array $rows, string $targetDate): array
    {
        $dropIndexes = [];
        foreach (self::unverifiedMeituanTargetDateRows($rows, $targetDate) as $issue) {
            if (preg_match('/^traffic_forecast:(\d+)$/D', $issue, $matches) === 1) {
                $dropIndexes[(int)$matches[1]] = true;
            }
        }

        if ($dropIndexes === []) {
            return [
                'rows' => array_values($rows),
                'dropped_count' => 0,
                'dropped_types' => [],
            ];
        }

        $filtered = [];
        foreach ($rows as $index => $row) {
            if (isset($dropIndexes[$index])) {
                continue;
            }
            $filtered[] = $row;
        }

        return [
            'rows' => $filtered,
            'dropped_count' => count($dropIndexes),
            'dropped_types' => ['traffic_forecast'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function meituanDateEvidencePayload(array $row): array
    {
        $raw = $row;
        if (isset($row['raw_data']) && is_string($row['raw_data'])) {
            $decoded = json_decode($row['raw_data'], true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        } elseif (isset($row['raw_data']) && is_array($row['raw_data'])) {
            $raw = $row['raw_data'];
        }
        if (is_array($raw['row'] ?? null)) {
            $raw = array_merge($raw, $raw['row']);
        }
        foreach (['date_source', 'dateSource'] as $key) {
            if (!array_key_exists($key, $raw) && array_key_exists($key, $row)) {
                $raw[$key] = $row[$key];
            }
        }
        return $raw;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $raw */
    private static function hasVerifiedMeituanEventDate(array $row, array $raw, string $dataType): bool
    {
        if (!in_array($dataType, ['order', 'review', 'traffic_forecast'], true)) {
            return false;
        }
        $source = trim((string)($raw['date_source'] ?? $raw['dateSource'] ?? $row['date_source'] ?? $row['dateSource'] ?? ''));
        if (!self::isAuthoritativeMeituanDateSource($source)) {
            return false;
        }

        $dateKeys = match ($dataType) {
            'order' => ['orderDate', 'order_date', 'bookingDate', 'booking_date', 'orderTime', 'order_time', 'createTime', 'buyTime', 'purchaseTime', 'purchase_time', 'data_date', 'dataDate', 'date'],
            'review' => ['reviewDate', 'review_date', 'commentDate', 'comment_date', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'submitTime', 'submit_time', 'data_date', 'dataDate', 'date'],
            default => ['forecastDate', 'forecast_date', 'targetDate', 'target_date', 'data_date', 'dataDate', 'date'],
        };
        if (!self::hasAnyMeituanValue($raw, $dateKeys)) {
            return false;
        }
        if ($dataType === 'traffic_forecast') {
            return true;
        }

        $identityKeys = $dataType === 'order'
            ? ['order_id', 'orderId', 'order_no', 'orderNo', 'booking_id', 'bookingId', 'order_id_hash', 'order_no_hash', 'booking_id_hash']
            : ['review_id', 'reviewId', 'comment_id', 'commentId', 'review_id_hash', 'comment_id_hash'];
        if (self::hasAnyMeituanValue($raw, $identityKeys)) {
            return true;
        }

        // Aggregate rows intentionally do not carry order/review identities.
        // Their explicit row date plus aggregate metric is authoritative for
        // the requested day without expanding into private detail storage.
        $aggregateKeys = $dataType === 'order'
            ? ['orders', 'order_count', 'orderCount', 'book_order_num', 'bookOrderNum', 'room_nights', 'roomNights']
            : ['comment_count', 'commentCount', 'review_count', 'reviewCount', 'bad_review_count', 'badReviewCount'];
        return self::hasAnyMeituanValue($raw, $aggregateKeys);
    }

    /** @param array<string, mixed> $row @param array<int, string> $keys */
    private static function hasAnyMeituanValue(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $gate */
    public static function isConfirmedEmptyMeituanCaptureGate(array $gate): bool
    {
        if (($gate['status'] ?? '') !== 'pass') {
            return false;
        }
        $statuses = is_array($gate['section_statuses'] ?? null) ? $gate['section_statuses'] : [];
        return $statuses !== []
            && count(array_filter(
                $statuses,
                static fn($status): bool => !in_array($status, ['empty_confirmed', 'not_applicable'], true)
            )) === 0;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, scalar|null> $expectedIdentifiers
     * @return array{ok:bool,status_code:string,validation_status:string,source_validation:bool}
     */
    public static function assessMeituanPlatformIdentity(array $payload, array $expectedIdentifiers = []): array
    {
        $validation = is_array($payload['platform_identity_validation'] ?? null)
            ? $payload['platform_identity_validation']
            : [];
        $status = strtolower(trim((string)($validation['status'] ?? '')));
        $validatedIdentifier = trim((string)($validation['validated_identifier'] ?? ''));
        $sourceValidation = ($validation['source_validation'] ?? false) === true;
        if ($status !== 'matched' || !$sourceValidation || $validatedIdentifier === '') {
            $mismatch = in_array($status, ['mismatch', 'ambiguous'], true);
            return [
                'ok' => false,
                'status_code' => $mismatch ? 'meituan_platform_identity_mismatch' : 'meituan_platform_identity_unverified',
                'validation_status' => $status !== '' ? $status : 'missing',
                'source_validation' => false,
            ];
        }

        $expected = [];
        foreach ($expectedIdentifiers as $identifier) {
            if (!is_scalar($identifier)) {
                continue;
            }
            $identifier = trim((string)$identifier);
            if ($identifier !== '') {
                $expected[$identifier] = true;
            }
        }
        if ($expected !== [] && !isset($expected[$validatedIdentifier])) {
            return [
                'ok' => false,
                'status_code' => 'meituan_platform_identity_mismatch',
                'validation_status' => 'expected_mismatch',
                'source_validation' => false,
            ];
        }

        return [
            'ok' => true,
            'status_code' => 'ready',
            'validation_status' => 'matched',
            'source_validation' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $rows
     * @return array{ok:bool,status_code:string,mismatched_dates:array<int,string>,unverified_rows:array<int,string>,empty_confirmed:bool}
     */
    public static function assessMeituanPersistenceGate(array $payload, array $rows, string $targetDate, array $expectedPlatformIdentifiers = []): array
    {
        $result = [
            'ok' => false,
            'status_code' => 'meituan_capture_unverified',
            'mismatched_dates' => [],
            'unverified_rows' => [],
            'empty_confirmed' => false,
        ];
        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        if ($authStatus === []) {
            $result['status_code'] = 'meituan_auth_unverified';
            return $result;
        }
        if (($authStatus['ok'] ?? false) !== true) {
            $result['status_code'] = 'meituan_login_expired';
            return $result;
        }
        $gate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
        if ($gate === [] || ($gate['status'] ?? 'fail') !== 'pass') {
            $result['status_code'] = 'meituan_capture_gate_failed';
            return $result;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $targetDate) !== 1) {
            $result['status_code'] = 'meituan_target_date_invalid';
            return $result;
        }
        $identity = self::assessMeituanPlatformIdentity($payload, $expectedPlatformIdentifiers);
        if (($identity['ok'] ?? false) !== true) {
            $result['status_code'] = (string)($identity['status_code'] ?? 'meituan_platform_identity_unverified');
            return $result;
        }

        $mismatchedDates = self::mismatchedMeituanTargetDates($rows, $targetDate);
        if ($mismatchedDates !== []) {
            $result['status_code'] = 'meituan_target_date_mismatch';
            $result['mismatched_dates'] = $mismatchedDates;
            return $result;
        }
        $unverifiedRows = self::unverifiedMeituanTargetDateRows($rows, $targetDate);
        if ($unverifiedRows !== []) {
            $result['status_code'] = 'meituan_target_date_unverified';
            $result['unverified_rows'] = $unverifiedRows;
            return $result;
        }
        if ($rows === []) {
            if (!self::isConfirmedEmptyMeituanCaptureGate($gate)) {
                $result['status_code'] = 'meituan_capture_no_rows';
                return $result;
            }
            $result['ok'] = true;
            $result['status_code'] = 'empty_confirmed';
            $result['empty_confirmed'] = true;
            return $result;
        }

        $result['ok'] = true;
        $result['status_code'] = 'ready';
        return $result;
    }

    /** @param array<string, mixed> $row */
    private static function hasExplicitMeituanRowDate(array $row): bool
    {
        foreach (['data_date', 'dataDate', 'date', 'statDate', 'stat_date', 'reportDate', 'day'] as $key) {
            if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function isAuthoritativeMeituanDateSource(string $source): bool
    {
        $source = strtolower(trim($source));
        return $source === 'row'
            || str_starts_with($source, 'row.')
            || str_starts_with($source, 'request.')
            || str_starts_with($source, 'response.')
            || str_starts_with($source, 'page.');
    }

    public static function buildMeituanPlan(
        array $requestData,
        string $projectRoot,
        string $nodeBinary,
        bool $loginOnly,
        ?int $systemHotelId,
        string $timestamp,
        string $chromePath = ''
    ): array {
        $storeId = self::resolveMeituanStoreId($requestData);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'meituan_capture_' . self::safeFilePart($storeId) . '_' . self::uniqueCaptureRunToken($timestamp) . '.json';

        $args = [
            $nodeBinary,
            $scriptPath,
            '--store-id=' . $storeId,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . (string)self::loginTimeoutMs($requestData['login_timeout_ms'] ?? 300000),
        ];

        if ($systemHotelId) {
            $args[] = '--system-hotel-id=' . (string)$systemHotelId;
        }

        $poiId = self::resolveMeituanPoiId($requestData);
        if ($poiId !== '') {
            $args[] = '--poi-id=' . $poiId;
        }

        $poiName = self::resolveMeituanPoiName($requestData);
        if ($poiName !== '') {
            $args[] = '--poi-name=' . $poiName;
        }

        $adsUrl = self::resolveMeituanAdsUrl($requestData);
        if ($adsUrl !== '') {
            $args[] = '--ads-url=' . $adsUrl;
        }

        $captureSections = self::normalizeMeituanSections($requestData);
        if ($captureSections !== '') {
            $args[] = '--sections=' . $captureSections;
        }
        $dataDate = trim((string)($requestData['data_date'] ?? $requestData['dataDate'] ?? $requestData['target_date'] ?? $requestData['targetDate'] ?? ''));
        if ($dataDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDate) !== 1) {
            throw new \InvalidArgumentException('Invalid Meituan capture target date.', 422);
        }
        if (!$loginOnly && $dataDate === '') {
            $period = strtolower(trim((string)($requestData['data_period'] ?? $requestData['dataPeriod'] ?? '')));
            $dataDate = $period === 'realtime_snapshot'
                ? date('Y-m-d')
                : date('Y-m-d', strtotime('-1 day'));
        }
        if (!$loginOnly && $dataDate !== '') {
            $args[] = '--data-date=' . $dataDate;
        }
        $dataPeriod = trim((string)($requestData['data_period'] ?? $requestData['dataPeriod'] ?? ''));
        if ($dataPeriod !== '') {
            $args[] = '--data-period=' . $dataPeriod;
        }
        $snapshotTime = trim((string)($requestData['snapshot_time'] ?? $requestData['snapshotTime'] ?? ''));
        if ($snapshotTime !== '') {
            $args[] = '--snapshot-time=' . $snapshotTime;
        }

        if ($loginOnly) {
            $args[] = '--login-only=true';
        }

        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        return [
            'store_id' => $storeId,
            'poi_id' => $poiId,
            'data_date' => $dataDate,
            'script_path' => $scriptPath,
            'output_dir' => $outputDir,
            'output_path' => $outputPath,
            'timeout_seconds' => self::timeoutSeconds($requestData['timeout_seconds'] ?? 600),
            'args' => $args,
        ];
    }

    public static function resolveCtripHotelId(array $requestData): string
    {
        return trim((string)($requestData['hotel_id'] ?? $requestData['hotelId'] ?? $requestData['ctrip_hotel_id'] ?? ''));
    }

    public static function resolveCtripProfileId(array $requestData, int $systemHotelId, string $hotelId): string
    {
        $profileId = trim((string)($requestData['profile_id'] ?? $requestData['profileId'] ?? $hotelId));
        return $profileId !== '' ? $profileId : 'system_' . (string)$systemHotelId;
    }

    public static function resolveCtripHotelName(array $requestData): string
    {
        return trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? ''));
    }

    public static function buildCtripBasePlan(
        array $requestData,
        string $projectRoot,
        string $nodeBinary,
        int $systemHotelId,
        string $dataDate,
        string $timestamp
    ): array {
        $hotelId = self::resolveCtripHotelId($requestData);
        $profileId = self::resolveCtripProfileId($requestData, $systemHotelId, $hotelId);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_browser_capture_' . self::safeFilePart($profileId) . '_' . self::uniqueCaptureRunToken($timestamp) . '.json';

        $args = [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--data-date=' . $dataDate,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . (string)self::loginTimeoutMs($requestData['login_timeout_ms'] ?? 300000),
            '--login-url=https://ebooking.ctrip.com/home/mainland',
        ];

        if ($hotelId !== '') {
            $args[] = '--hotel-id=' . $hotelId;
        }

        $hotelName = self::resolveCtripHotelName($requestData);
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }

        return [
            'hotel_id' => $hotelId,
            'profile_id' => $profileId,
            'script_path' => $scriptPath,
            'output_dir' => $outputDir,
            'output_path' => $outputPath,
            'timeout_seconds' => self::timeoutSeconds($requestData['timeout_seconds'] ?? 600),
            'args' => $args,
        ];
    }

    public static function buildCtripAutoArgs(
        string $nodeBinary,
        string $scriptPath,
        string $profileId,
        int $systemHotelId,
        string $dataDate,
        string $outputPath,
        array $sectionsList,
        int $sectionConcurrency,
        bool $interactiveBrowser,
        string $capturePlan = 'full'
    ): array {
        return [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--data-date=' . $dataDate,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . ($interactiveBrowser ? '300000' : '30000'),
            '--sections=' . implode(',', $sectionsList),
            '--section-concurrency=' . (string)$sectionConcurrency,
            '--capture-plan=' . self::normalizeCtripCapturePlan($capturePlan),
            $interactiveBrowser ? '--headless=false' : '--headless=true',
        ];
    }

    public static function normalizeCtripCapturePlan(mixed $value): string
    {
        $plan = strtolower(trim(str_replace(['-', ' '], '_', (string)$value)));
        return match ($plan) {
            'realtime', 'broadcast', 'realtime_broadcast' => 'realtime_broadcast',
            'past', 'history', 'historical', 'historical_review', 'past_review' => 'historical_review',
            'intraday', 'trend', 'hourly_trend', 'traffic_trend', 'intraday_trend' => 'intraday_trend',
            'future', 'search_demand', 'future_demand' => 'future_demand',
            default => 'full',
        };
    }

    public static function normalizeProfileSections($value, string $fallback): string
    {
        $raw = is_array($value)
            ? implode(',', array_map(static fn($item): string => (string)$item, $value))
            : (string)$value;
        $raw = preg_replace('/[^a-zA-Z,_\-\s]+/', '', $raw) ?: '';
        $items = preg_split('/[,\s]+/', strtolower($raw)) ?: [];
        $sections = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $sections[$item] = true;
        }

        return implode(',', array_keys($sections)) ?: $fallback;
    }

    public static function buildMeituanAutoArgs(
        array $config,
        string $nodeBinary,
        string $scriptPath,
        int $systemHotelId,
        string $storeId,
        string $outputPath,
        bool $interactiveBrowser,
        string $chromePath = '',
        string $dataDate = ''
    ): array {
        $args = [
            $nodeBinary,
            $scriptPath,
            '--store-id=' . $storeId,
            '--output=' . $outputPath,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--login-timeout-ms=' . ($interactiveBrowser ? '300000' : '30000'),
            $interactiveBrowser ? '--headless=false' : '--headless=true',
            '--sections=' . self::normalizeProfileSections(
                self::normalizeMeituanProfileSections($config['profile_sections'] ?? $config['capture_sections'] ?? self::MEITUAN_DEFAULT_SECTIONS),
                self::MEITUAN_DEFAULT_SECTIONS
            ),
        ];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dataDate)) === 1) {
            $args[] = '--data-date=' . trim($dataDate);
        }

        $poiId = trim((string)($config['poi_id'] ?? $config['poiId'] ?? ''));
        if ($poiId !== '') {
            $args[] = '--poi-id=' . $poiId;
        }
        $poiName = trim((string)($config['name'] ?? $config['hotel_name'] ?? ''));
        if ($poiName !== '') {
            $args[] = '--poi-name=' . $poiName;
        }
        $adsUrl = trim((string)($config['ads_url'] ?? $config['adsUrl'] ?? ''));
        if ($adsUrl !== '') {
            $args[] = '--ads-url=' . $adsUrl;
        }
        $dataPeriod = trim((string)($config['data_period'] ?? $config['dataPeriod'] ?? ''));
        if ($dataPeriod !== '') {
            $args[] = '--data-period=' . $dataPeriod;
        }
        $snapshotTime = trim((string)($config['snapshot_time'] ?? $config['snapshotTime'] ?? ''));
        if ($snapshotTime !== '') {
            $args[] = '--snapshot-time=' . $snapshotTime;
        }
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        return $args;
    }
}
