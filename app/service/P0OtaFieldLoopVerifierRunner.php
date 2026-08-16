<?php
declare(strict_types=1);

namespace app\service;

/**
 * Executes the authoritative P0 verifier from a local trusted scheduler and
 * reduces its output to a bounded, credential-free receipt.
 *
 * Web request paths must consume the stored receipt; they must not execute the
 * verifier process themselves.
 */
final class P0OtaFieldLoopVerifierRunner
{
    /** @var null|callable(array<int,string>, string, int):array<string,mixed> */
    private $processRunner;
    /** @var null|callable(int, string, string):array<string,mixed> */
    private $continuousTrustResolver;

    public function __construct(
        ?callable $processRunner = null,
        ?callable $continuousTrustResolver = null,
        private readonly ?string $projectRoot = null
    ) {
        $this->processRunner = $processRunner;
        $this->continuousTrustResolver = $continuousTrustResolver;
    }

    /**
     * @param array<int, mixed> $platforms
     * @return array<string, mixed>
     */
    public function verify(
        int $hotelId,
        string $targetDate,
        array $platforms = ['ctrip', 'meituan'],
        string $collectionAnchorHash = '',
        int $timeoutSeconds = 60
    ): array
    {
        $platforms = $this->platformList($platforms);
        $collectionAnchorHash = strtolower(trim($collectionAnchorHash));
        $timeoutSeconds = max(1, min(60, $timeoutSeconds));
        if ($hotelId <= 0
            || !$this->validDate($targetDate)
            || $platforms === []
            || preg_match('/^[a-f0-9]{64}$/D', $collectionAnchorHash) !== 1
        ) {
            return $this->failedReceipt(
                $hotelId,
                $targetDate,
                $platforms,
                'verifier_scope_invalid'
            );
        }

        $root = $this->projectRoot ?? dirname(__DIR__, 2);
        $script = $root . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
        if (!is_file($script)) {
            return $this->failedReceipt(
                $hotelId,
                $targetDate,
                $platforms,
                'p0_verifier_script_missing'
            );
        }

        $arguments = [
            PHP_BINARY,
            $script,
            '--format=json',
            '--date=' . $targetDate,
            '--platform=' . implode(',', $platforms),
            '--system-hotel-id=' . $hotelId,
        ];

        try {
            $process = is_callable($this->processRunner)
                ? (array)($this->processRunner)($arguments, $root, $timeoutSeconds)
                : $this->runProcess($arguments, $root, $timeoutSeconds);
        } catch (\Throwable $exception) {
            return $this->failedReceipt(
                $hotelId,
                $targetDate,
                $platforms,
                'p0_verifier_process_failed',
                get_debug_type($exception)
            );
        }

        $exitCode = (int)($process['exit_code'] ?? 1);
        $stdout = trim((string)($process['stdout'] ?? ''));
        if ($stdout === '' || strlen($stdout) > 4_000_000) {
            return $this->failedReceipt(
                $hotelId,
                $targetDate,
                $platforms,
                'p0_verifier_output_invalid',
                '',
                $exitCode
            );
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            return $this->failedReceipt(
                $hotelId,
                $targetDate,
                $platforms,
                'p0_verifier_output_invalid',
                '',
                $exitCode
            );
        }

        $scope = is_array($decoded['scope'] ?? null) ? $decoded['scope'] : [];
        $scopePlatforms = $this->platformList($scope['platforms'] ?? []);
        $scopeMatches = substr(trim((string)($scope['date'] ?? '')), 0, 10) === $targetDate
            && (int)($scope['system_hotel_id'] ?? 0) === $hotelId
            && $scopePlatforms === $platforms;
        $verifierStatus = strtolower(trim((string)($decoded['status'] ?? 'failed')));
        if (!in_array($verifierStatus, ['passed', 'incomplete', 'failed'], true)) {
            $verifierStatus = 'failed';
        }

        $platformStatuses = [];
        $verifiedPlatforms = [];
        foreach (is_array($decoded['platforms'] ?? null) ? $decoded['platforms'] : [] as $platformRow) {
            if (!is_array($platformRow)) {
                continue;
            }
            $platform = strtolower(trim((string)($platformRow['platform'] ?? '')));
            if (!in_array($platform, $platforms, true)) {
                continue;
            }
            $trafficGate = is_array($platformRow['p0_traffic_gate'] ?? null)
                ? $platformRow['p0_traffic_gate']
                : [];
            $status = strtolower(trim((string)($trafficGate['status'] ?? 'missing')));
            $platformStatuses[$platform] = $status;
            if ($status === 'ready') {
                $verifiedPlatforms[] = $platform;
            }
        }
        ksort($platformStatuses, SORT_STRING);
        sort($verifiedPlatforms, SORT_STRING);

        $summary = is_array($decoded['summary'] ?? null) ? $decoded['summary'] : [];
        $summaryReady = (int)($summary['p0_platforms_ready'] ?? -1) === count($platforms)
            && (int)($summary['p0_platforms_incomplete'] ?? -1) === 0
            && (int)($summary['traffic_gates_ready'] ?? -1) === count($platforms)
            && (int)($summary['traffic_gates_incomplete'] ?? -1) === 0;
        $issueCodes = $this->issueCodes($decoded['issues'] ?? []);
        $storageScopeResult = $this->platformStorageScopeReceipts(
            $decoded['traffic_evidence_availability'] ?? [],
            $hotelId,
            $targetDate,
            $platforms
        );
        $platformStorageScopes = $storageScopeResult['scopes'];
        foreach ($storageScopeResult['issue_codes'] as $storageScopeIssue) {
            $issueCodes[] = $storageScopeIssue;
        }

        try {
            $continuousTrust = is_callable($this->continuousTrustResolver)
                ? (array)($this->continuousTrustResolver)($hotelId, $targetDate, $targetDate)
                : (new DualOtaContinuousTrustService())->inspectHotel($hotelId, $targetDate, $targetDate);
        } catch (\Throwable) {
            $continuousTrust = [
                'status' => 'partial',
                'hotel_id' => $hotelId,
                'start_date' => $targetDate,
                'end_date' => $targetDate,
                'days' => [],
                'reason' => 'continuous_trust_evaluation_failed',
            ];
        }
        $continuousMissing = $this->continuousMissingSteps(
            $continuousTrust,
            $hotelId,
            $targetDate,
            $platforms
        );
        $continuousReady = $continuousMissing === [];
        foreach ($continuousMissing as $missing) {
            $issueCodes[] = $missing;
        }
        $issueCodes = array_values(array_unique($issueCodes));
        sort($issueCodes, SORT_STRING);

        // Exact fact authority is decided by the bounded
        // hotel/platform/date/storage receipt. Continuous collection health is
        // reported alongside it, but cannot overwrite a proven historical
        // fact or block canonical promotion of that exact row set.
        $authorityReady = $exitCode === 0
            && $verifierStatus === 'passed'
            && $scopeMatches
            && $summaryReady
            && $verifiedPlatforms === $platforms
            && array_keys($platformStorageScopes) === $platforms
            && $storageScopeResult['issue_codes'] === [];

        if (!$scopeMatches) {
            $issueCodes[] = 'p0_verifier_scope_mismatch';
        }
        if (!$summaryReady) {
            $issueCodes[] = 'p0_verifier_summary_incomplete';
        }
        if ($exitCode !== 0) {
            $issueCodes[] = 'p0_verifier_nonzero_exit';
        }
        $issueCodes = array_values(array_unique($issueCodes));
        sort($issueCodes, SORT_STRING);

        return [
            'schema_version' => 2,
            'verification_source' => 'external_p0_verifier',
            'script' => 'scripts/verify_p0_ota_field_loop_closure.php',
            'status' => $authorityReady
                ? 'passed'
                : ($verifierStatus === 'failed' || $exitCode === 1 ? 'failed' : 'incomplete'),
            'exit_code' => $exitCode,
            'authority_ready' => $authorityReady,
            'target_date' => $targetDate,
            'hotel_id' => $hotelId,
            'required_platforms' => $platforms,
            'verified_platforms' => $verifiedPlatforms,
            'collection_anchor_hash' => $collectionAnchorHash,
            'platform_statuses' => $platformStatuses,
            'platform_storage_scopes' => $platformStorageScopes,
            'p0_platforms_ready' => (int)($summary['p0_platforms_ready'] ?? 0),
            'traffic_gates_ready' => (int)($summary['traffic_gates_ready'] ?? 0),
            'continuous_trust_status' => strtolower(trim((string)($continuousTrust['status'] ?? 'partial'))),
            'continuous_trust_ready' => $continuousReady,
            'continuous_trust_missing_steps' => $continuousMissing,
            'issue_codes' => $issueCodes,
            'verifier_report_hash' => hash('sha256', $stdout),
            'checked_at' => date('Y-m-d H:i:s'),
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * Preserve only the bounded exact storage identities proven by the strict
     * verifier. Raw rows, platform identifiers, URLs, and response payloads
     * are intentionally excluded from this receipt.
     *
     * @param mixed $availability
     * @param array<int, string> $platforms
     * @return array{scopes:array<string,array<string,mixed>>,issue_codes:array<int,string>}
     */
    private function platformStorageScopeReceipts(
        mixed $availability,
        int $hotelId,
        string $targetDate,
        array $platforms
    ): array {
        $scopes = [];
        $issues = [];
        foreach (is_array($availability) ? $availability : [] as $platformEvidence) {
            if (!is_array($platformEvidence)) {
                continue;
            }
            $platform = strtolower(trim((string)($platformEvidence['platform'] ?? '')));
            if (!in_array($platform, $platforms, true)) {
                continue;
            }
            if (isset($scopes[$platform])) {
                $issues[] = $platform . '_p0_storage_scope_ambiguous';
                unset($scopes[$platform]);
                continue;
            }
            $fieldFacts = is_array($platformEvidence['traffic_field_fact_closure'] ?? null)
                ? $platformEvidence['traffic_field_fact_closure']
                : [];
            $storageScope = is_array($fieldFacts['storage_scope'] ?? null)
                ? $fieldFacts['storage_scope']
                : [];
            $tenantId = (int)($storageScope['tenant_id'] ?? 0);
            $dataSourceId = (int)($storageScope['data_source_id'] ?? 0);
            $syncTaskId = (int)($storageScope['sync_task_id'] ?? 0);
            $scopeHotelId = (int)($storageScope['system_hotel_id'] ?? 0);
            $scopePlatform = strtolower(trim((string)($storageScope['platform'] ?? '')));
            $requiredMetrics = $this->metricKeyList($fieldFacts['required_metric_keys'] ?? []);
            $completeMetrics = $this->metricKeyList($fieldFacts['complete_metric_keys'] ?? []);
            $missingMetrics = $this->metricKeyList($fieldFacts['missing_metric_keys'] ?? []);
            $sampleRowIds = [];
            foreach (is_array($fieldFacts['sample_metric_rows'] ?? null) ? $fieldFacts['sample_metric_rows'] : [] as $sample) {
                $rowId = is_array($sample) ? (int)($sample['row_id'] ?? 0) : 0;
                if ($rowId > 0) {
                    $sampleRowIds[] = $rowId;
                }
            }
            $sampleRowIds = array_values(array_unique($sampleRowIds));
            sort($sampleRowIds, SORT_NUMERIC);

            $observedProvenanceStatus = strtolower(trim((string)(
                $fieldFacts['observed_traffic_metric_provenance_status'] ?? ''
            )));
            $syntheticProvenanceMissingRows = (int)(
                $fieldFacts['synthetic_normalization_provenance_missing_rows'] ?? -1
            );
            if ($observedProvenanceStatus !== 'ready' || $syntheticProvenanceMissingRows !== 0) {
                $issues[] = $platform . '_synthetic_normalization_provenance_missing';
                continue;
            }

            $scopeReady = strtolower(trim((string)($platformEvidence['status'] ?? ''))) === 'ready'
                && (int)($platformEvidence['system_hotel_id'] ?? 0) === $hotelId
                && strtolower(trim((string)($fieldFacts['status'] ?? ''))) === 'ready'
                && substr(trim((string)($fieldFacts['target_date'] ?? '')), 0, 10) === $targetDate
                && (int)($fieldFacts['system_hotel_id'] ?? 0) === $hotelId
                && strtolower(trim((string)($storageScope['status'] ?? ''))) === 'ready'
                && $tenantId > 0
                && $dataSourceId > 0
                && $syncTaskId > 0
                && $scopeHotelId === $hotelId
                && $scopePlatform === $platform
                && $requiredMetrics !== []
                && array_diff($requiredMetrics, $completeMetrics) === []
                && $missingMetrics === []
                && (int)($fieldFacts['authoritative_traffic_row_count'] ?? 0) > 0
                && strtolower(trim((string)($fieldFacts['readback_status'] ?? ''))) === 'ready'
                && $sampleRowIds !== [];
            if (!$scopeReady) {
                $issues[] = $platform . '_p0_storage_scope_missing';
                continue;
            }

            $scopes[$platform] = [
                'tenant_id' => $tenantId,
                'data_source_id' => $dataSourceId,
                'sync_task_id' => $syncTaskId,
                'system_hotel_id' => $scopeHotelId,
                'platform' => $platform,
                'target_date' => $targetDate,
                'selection_basis' => preg_replace(
                    '/[^a-z0-9_]+/',
                    '_',
                    strtolower(trim((string)($storageScope['selection_basis'] ?? '')))
                ) ?: '',
                'authoritative_traffic_row_count' => max(0, (int)($fieldFacts['authoritative_traffic_row_count'] ?? 0)),
                'sample_row_ids' => $sampleRowIds,
                'required_metric_keys' => $requiredMetrics,
                'complete_metric_keys' => $completeMetrics,
                'missing_metric_keys' => [],
                'nonzero_required_metric_rows' => max(0, (int)($fieldFacts['nonzero_required_metric_rows'] ?? 0)),
                'explicit_zero_confirmed_rows' => max(0, (int)($fieldFacts['explicit_zero_confirmed_rows'] ?? 0)),
                'observed_traffic_metric_provenance_status' => 'ready',
                'synthetic_normalization_provenance_missing_rows' => 0,
                'readback_status' => 'ready',
            ];
        }
        ksort($scopes, SORT_STRING);
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);
        return ['scopes' => $scopes, 'issue_codes' => $issues];
    }

    /** @return array<int, string> */
    private function metricKeyList(mixed $value): array
    {
        $keys = [];
        foreach (is_array($value) ? $value : [] as $key) {
            $key = strtolower(trim((string)$key));
            if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $key) === 1) {
                $keys[$key] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * @param array<int, string> $arguments
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runProcess(array $arguments, string $cwd, int $timeoutSeconds): array
    {
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start P0 verifier.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        do {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new \RuntimeException('P0 verifier timed out.');
            }
            usleep(20_000);
        } while (true);

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = (int)($status['exitcode'] ?? -1);
        $closedCode = proc_close($process);
        if ($exitCode < 0 && is_int($closedCode)) {
            $exitCode = $closedCode;
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => substr($stdout, 0, 4_000_000),
            'stderr' => substr($stderr, 0, 8_000),
        ];
    }

    /**
     * @param array<string, mixed> $continuous
     * @param array<int, string> $platforms
     * @return array<int, string>
     */
    private function continuousMissingSteps(
        array $continuous,
        int $hotelId,
        string $targetDate,
        array $platforms
    ): array {
        $missing = [];
        if ((int)($continuous['hotel_id'] ?? 0) !== $hotelId
            || substr(trim((string)($continuous['start_date'] ?? '')), 0, 10) !== $targetDate
            || substr(trim((string)($continuous['end_date'] ?? '')), 0, 10) !== $targetDate
            || strtolower(trim((string)($continuous['metric_scope'] ?? ''))) !== 'ota_channel'
            || strtolower(trim((string)($continuous['tenant_scope_status'] ?? ''))) !== 'verified'
        ) {
            $missing[] = 'continuous_trust_scope_mismatch';
        }
        $targetDay = [];
        foreach (is_array($continuous['days'] ?? null) ? $continuous['days'] : [] as $day) {
            if (is_array($day) && (string)($day['date'] ?? '') === $targetDate) {
                $targetDay = $day;
                break;
            }
        }
        $rows = [];
        foreach (is_array($targetDay['platforms'] ?? null) ? $targetDay['platforms'] : [] as $row) {
            if (is_array($row)) {
                $rows[strtolower(trim((string)($row['platform'] ?? '')))] = $row;
            }
        }
        foreach ($platforms as $platform) {
            $row = is_array($rows[$platform] ?? null) ? $rows[$platform] : [];
            if (strtolower(trim((string)($row['status'] ?? ''))) !== 'verified') {
                $missing[] = $platform . '_continuous_trust_not_verified';
            }
            foreach (is_array($row['missing_steps'] ?? null) ? $row['missing_steps'] : [] as $step) {
                $step = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string)$step))) ?? '';
                if ($step !== '') {
                    $missing[] = $platform . '_' . trim($step, '_') . '_not_ready';
                }
            }
        }
        return array_values(array_unique($missing));
    }

    /** @return array<int, string> */
    private function issueCodes(mixed $issues): array
    {
        $codes = [];
        foreach (is_array($issues) ? $issues : [] as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $code = preg_replace(
                '/[^a-z0-9_]+/',
                '_',
                strtolower(trim((string)($issue['code'] ?? '')))
            ) ?? '';
            if ($code !== '') {
                $codes[] = trim($code, '_');
            }
        }
        return array_values(array_unique($codes));
    }

    /** @return array<int, string> */
    private function platformList(mixed $platforms): array
    {
        $values = is_array($platforms)
            ? $platforms
            : (is_string($platforms) ? explode(',', $platforms) : []);
        $result = [];
        foreach ($values as $platform) {
            $platform = strtolower(trim((string)$platform));
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $result[$platform] = true;
            }
        }
        $result = array_keys($result);
        sort($result, SORT_STRING);
        return $result;
    }

    private function validDate(string $targetDate): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $targetDate,
            new \DateTimeZone('Asia/Shanghai')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        return $parsed instanceof \DateTimeImmutable
            && ($errors === false
                || ((int)($errors['warning_count'] ?? 0) === 0
                    && (int)($errors['error_count'] ?? 0) === 0))
            && $parsed->format('Y-m-d') === $targetDate;
    }

    /**
     * @param array<int, string> $platforms
     * @return array<string, mixed>
     */
    private function failedReceipt(
        int $hotelId,
        string $targetDate,
        array $platforms,
        string $issueCode,
        string $failureType = '',
        int $exitCode = 1
    ): array {
        return [
            'schema_version' => 2,
            'verification_source' => 'external_p0_verifier',
            'script' => 'scripts/verify_p0_ota_field_loop_closure.php',
            'status' => 'failed',
            'exit_code' => $exitCode,
            'authority_ready' => false,
            'target_date' => $targetDate,
            'hotel_id' => $hotelId > 0 ? $hotelId : null,
            'required_platforms' => $platforms,
            'verified_platforms' => [],
            'platform_statuses' => [],
            'platform_storage_scopes' => [],
            'p0_platforms_ready' => 0,
            'traffic_gates_ready' => 0,
            'continuous_trust_status' => 'not_evaluated',
            'continuous_trust_ready' => false,
            'continuous_trust_missing_steps' => [],
            'issue_codes' => [$issueCode],
            'failure_type' => $failureType,
            'checked_at' => date('Y-m-d H:i:s'),
            'sensitive_values_exposed' => false,
        ];
    }
}
