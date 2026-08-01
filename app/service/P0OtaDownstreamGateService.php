<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;

class P0OtaDownstreamGateService
{
    private const BLOCKED_STAGE_KEYS = [
        'revenue_analysis',
        'ai_decision_advice',
        'operation_closure',
    ];

    private const ALLOWED_CLAIMS_WHEN_BLOCKED = [
        'structure_ready_or_reference_only',
        'historical_rows_reference_only',
        'no_whole_hotel_or_downstream_closure_claim',
    ];

    /** @var null|callable(int, string, string):array<string, mixed> */
    private $continuousTrustResolver;
    /** @var null|callable(string, int, array<int, string>):array<string, mixed> */
    private $datasetResolver;
    /** @var null|callable(int, string):array<string, mixed> */
    private $authorityReceiptResolver;

    public function __construct(
        ?callable $continuousTrustResolver = null,
        ?callable $datasetResolver = null,
        ?callable $authorityReceiptResolver = null
    ) {
        $this->continuousTrustResolver = $continuousTrustResolver;
        $this->datasetResolver = $datasetResolver;
        $this->authorityReceiptResolver = $authorityReceiptResolver;
    }

    /**
     * Resolve the normal API gate from exact-date persisted facts.
     *
     * The authoritative CLI verifier remains the release/operator proof. This
     * runtime path re-evaluates the same fail-closed hotel/date/platform facts
     * and never executes a shell command from a web request.
     *
     * @param null|array<string, mixed> $dataset
     * @param array<int, mixed> $platforms
     * @return array<string, mixed>
     */
    public function resolveRuntime(
        string $businessDate,
        ?int $hotelId,
        ?array $dataset = null,
        array $platforms = []
    ): array {
        $platforms = $this->platformList($platforms);
        if ($platforms === []) {
            $platforms = ['ctrip', 'meituan'];
        }

        $scopeMissing = [];
        if (!$this->validBusinessDate($businessDate)) {
            $scopeMissing[] = 'target_date_invalid';
        }
        if ($hotelId === null || $hotelId <= 0) {
            $scopeMissing[] = 'system_hotel_id_required';
        }
        if ($scopeMissing !== []) {
            array_unshift($scopeMissing, 'p0_field_loop_verifier_ready');
            return $this->blocked(
                $businessDate,
                $hotelId,
                $scopeMissing,
                'runtime_scope_incomplete',
                '',
                $platforms,
                $this->runtimeMetadata($businessDate, $hotelId, [])
            );
        }

        try {
            $dataset ??= $this->loadDataset($businessDate, $hotelId, $platforms);
            $continuousTrust = is_callable($this->continuousTrustResolver)
                ? (array)($this->continuousTrustResolver)($hotelId, $businessDate, $businessDate)
                : (new DualOtaContinuousTrustService())->inspectHotel($hotelId, $businessDate, $businessDate);
            $authorityReceipt = is_callable($this->authorityReceiptResolver)
                ? (array)($this->authorityReceiptResolver)($hotelId, $businessDate)
                : $this->loadAuthorityReceipt($hotelId, $businessDate);
        } catch (\Throwable) {
            return $this->blocked(
                $businessDate,
                $hotelId,
                ['p0_field_loop_verifier_ready', 'runtime_continuous_trust_unavailable'],
                'runtime_continuous_trust_unavailable',
                '',
                $platforms,
                $this->runtimeMetadata($businessDate, $hotelId, [])
            );
        }

        return $this->fromContinuousTrust(
            $businessDate,
            $hotelId,
            $dataset,
            $continuousTrust,
            $platforms,
            $authorityReceipt
        );
    }

    /**
     * Pure mapping used by the runtime adapter and focused regression tests.
     *
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $continuousTrust
     * @param array<int, mixed> $platforms
     * @param array<string, mixed> $authorityReceipt
     * @return array<string, mixed>
     */
    public function fromContinuousTrust(
        string $businessDate,
        int $hotelId,
        array $dataset,
        array $continuousTrust,
        array $platforms = [],
        array $authorityReceipt = []
    ): array {
        $platforms = $this->platformList($platforms);
        if ($platforms === []) {
            $platforms = ['ctrip', 'meituan'];
        }

        $missingInputs = $this->datasetMissingInputs($dataset, $platforms, $businessDate, $hotelId);
        $trustScopeReady = true;
        if ((int)($continuousTrust['hotel_id'] ?? 0) !== $hotelId) {
            $missingInputs[] = 'runtime_continuous_trust_hotel_mismatch';
            $trustScopeReady = false;
        }
        if ((string)($continuousTrust['metric_scope'] ?? '') !== 'ota_channel') {
            $missingInputs[] = 'runtime_continuous_trust_scope_unverified';
            $trustScopeReady = false;
        }
        if ((string)($continuousTrust['tenant_scope_status'] ?? '') !== 'verified') {
            $missingInputs[] = 'tenant_scope_unverified';
            $trustScopeReady = false;
        }

        $targetDay = [];
        foreach ($this->list($continuousTrust['days'] ?? []) as $day) {
            if (is_array($day) && (string)($day['date'] ?? '') === $businessDate) {
                $targetDay = $day;
                break;
            }
        }
        if ($targetDay === []) {
            $missingInputs[] = 'target_date_continuous_trust_missing';
        }

        $platformRows = [];
        foreach ($this->list($targetDay['platforms'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $platform = strtolower(trim((string)($row['platform'] ?? '')));
            if (in_array($platform, $platforms, true)) {
                $platformRows[$platform] = $row;
            }
        }

        $verifiedPlatforms = [];
        $upstreamStatuses = [];
        foreach ($platforms as $platform) {
            $row = is_array($platformRows[$platform] ?? null) ? $platformRows[$platform] : [];
            if ($row === []) {
                $missingInputs[] = $platform . '_continuous_trust_missing';
                $upstreamStatuses[] = 'partial';
                continue;
            }

            $status = strtolower(trim((string)($row['status'] ?? 'partial')));
            $upstreamStatuses[] = $status;
            $targetDateMatches = (string)($row['target_date'] ?? '') === $businessDate;
            if (!$targetDateMatches) {
                $missingInputs[] = $platform . '_continuous_trust_target_date_mismatch';
            }
            if ($status === 'collection_failed') {
                $missingInputs[] = $platform . '_collection_failed';
            }
            foreach ($this->stringList($row['missing_steps'] ?? []) as $step) {
                $missingInputs[] = $platform . '_' . $this->safeKey($step) . '_not_ready';
            }
            if (strtolower(trim((string)($row['p0_status'] ?? 'blocked'))) !== 'ready') {
                $missingInputs[] = $platform . '_p0_field_loop_not_ready';
            }
            if ($status === 'verified'
                && strtolower(trim((string)($row['p0_status'] ?? ''))) === 'ready'
                && $this->stringList($row['missing_steps'] ?? []) === []
                && $targetDateMatches
                && $trustScopeReady
                && !in_array($platform . '_target_date_ota_rows', $missingInputs, true)
                && !in_array($platform . '_target_date_traffic_rows', $missingInputs, true)
            ) {
                $verifiedPlatforms[] = $platform;
            }
        }

        $authority = $this->authorityReceiptStatus(
            $authorityReceipt,
            $businessDate,
            $hotelId,
            $platforms
        );
        foreach ($authority['missing_inputs'] as $missingInput) {
            $missingInputs[] = $missingInput;
        }
        $missingInputs = array_values(array_unique(array_filter($missingInputs)));
        $metadata = $authority['metadata'] !== []
            ? $authority['metadata']
            : $this->runtimeMetadata($businessDate, $hotelId, $verifiedPlatforms);
        if ($missingInputs === []
            && $verifiedPlatforms === $platforms
            && $authority['ready'] === true
        ) {
            return $this->normalize([
                'status' => 'ready',
                'current_upstream_status' => 'ready',
                'scope_policy' => 'exact_date_external_p0_verifier_and_runtime_trust_before_downstream_claims',
                ...$metadata,
            ], $businessDate, $hotelId, $platforms);
        }

        array_unshift($missingInputs, 'p0_field_loop_verifier_ready');
        $currentStatus = in_array('collection_failed', $upstreamStatuses, true)
            ? 'collection_failed'
            : 'partial';
        return $this->blocked(
            $businessDate,
            $hotelId,
            array_values(array_unique($missingInputs)),
            $currentStatus,
            '',
            $platforms,
            $metadata
        );
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    public function blockedForDataset(string $businessDate, ?int $hotelId, array $dataset, array $platforms = []): array
    {
        $dailyRows = count($this->list($dataset['fact_ota_daily'] ?? []));
        $trafficRows = count($this->list($dataset['fact_ota_traffic'] ?? []));
        $missingInputs = ['p0_field_loop_verifier_ready'];
        if ($dailyRows <= 0) {
            $missingInputs[] = 'target_date_ota_rows';
        }
        if ($trafficRows <= 0) {
            $missingInputs[] = 'target_date_traffic_rows';
        }

        return $this->blocked($businessDate, $hotelId, $missingInputs, 'not_verified', '', $platforms);
    }

    /**
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    public function normalize(array $gate, string $businessDate = '', ?int $hotelId = null, array $platforms = []): array
    {
        $status = trim((string)($gate['status'] ?? ''));
        if ($status === 'ready') {
            $verification = $this->verificationMetadata(
                $gate,
                $businessDate,
                $hotelId,
                $platforms
            );
            return array_merge([
                'status' => 'ready',
                'current_upstream_status' => trim((string)($gate['current_upstream_status'] ?? 'ready')),
                'required_upstream_status' => trim((string)($gate['required_upstream_status'] ?? 'ready')),
                'required_gate_command' => trim((string)($gate['required_gate_command'] ?? $this->verifierCommand($businessDate, $hotelId, $platforms))),
                'scope_policy' => trim((string)($gate['scope_policy'] ?? 'ota_channel_gate_before_downstream_claims')),
                'blocking_missing_inputs' => [],
                'blocked_stage_keys' => [],
                'stages' => $this->stageRows('ready'),
                'allowed_claims' => ['p0_ota_field_loop_ready_for_downstream_claims'],
            ], $verification);
        }

        $missingInputs = $this->stringList($gate['blocking_missing_inputs'] ?? []);
        if ($missingInputs === []) {
            $missingInputs = ['p0_field_loop_verifier_ready'];
        }

        return $this->blocked(
            $businessDate,
            $hotelId,
            $missingInputs,
            trim((string)($gate['current_upstream_status'] ?? 'incomplete')),
            trim((string)($gate['required_gate_command'] ?? '')),
            $platforms,
            $this->verificationMetadata($gate, $businessDate, $hotelId, $platforms)
        );
    }

    /**
     * Converts an already-normalized P0 gate into a safe, canonical quality summary.
     * It contains only blocker codes and scope metadata; no raw response or credentials.
     *
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    public function collectionQuality(array $gate): array
    {
        $normalized = $this->normalize($gate);
        $gateStatus = (string)($normalized['status'] ?? 'blocked_by_p0_ota_gate');
        $flags = $this->stringList($normalized['blocking_missing_inputs'] ?? []);

        return [
            'primary_quality_state' => $gateStatus === 'ready'
                ? 'available'
                : $this->collectionQualityState($flags),
            'quality_flags' => $flags,
            'metric_scope' => 'ota_channel',
            'evidence' => [
                'p0_downstream_gate_status' => $gateStatus,
                'current_upstream_status' => (string)($normalized['current_upstream_status'] ?? ''),
            ],
            'next_action' => $gateStatus === 'ready'
                ? ''
                : 'run_p0_ota_field_loop_verifier',
        ];
    }

    /**
     * @param array<int, string> $missingInputs
     * @return array<string, mixed>
     */
    private function blocked(
        string $businessDate,
        ?int $hotelId,
        array $missingInputs,
        string $currentStatus,
        string $command = '',
        array $platforms = [],
        array $verificationMetadata = []
    ): array
    {
        return array_merge([
            'status' => 'blocked_by_p0_ota_gate',
            'current_upstream_status' => $currentStatus !== '' ? $currentStatus : 'incomplete',
            'required_upstream_status' => 'ready',
            'required_gate_command' => $command !== '' ? $command : $this->verifierCommand($businessDate, $hotelId, $platforms),
            'scope_policy' => 'ota_channel_gate_before_downstream_claims',
            'blocking_missing_inputs' => array_values(array_unique($missingInputs)),
            'blocked_stage_keys' => self::BLOCKED_STAGE_KEYS,
            'stages' => $this->stageRows('blocked_by_p0_ota_gate'),
            'allowed_claims' => self::ALLOWED_CLAIMS_WHEN_BLOCKED,
        ], $verificationMetadata);
    }

    /**
     * @param array<int, string> $platforms
     * @return array<string, mixed>
     */
    private function loadDataset(string $businessDate, int $hotelId, array $platforms): array
    {
        if (is_callable($this->datasetResolver)) {
            return (array)($this->datasetResolver)($businessDate, $hotelId, $platforms);
        }

        $dailyFacts = [];
        $trafficFacts = [];
        $etl = new OtaStandardEtlService();
        foreach ($platforms as $platform) {
            $dataset = $etl->buildDataset([
                'start_date' => $businessDate,
                'end_date' => $businessDate,
                'source' => $platform,
                'system_hotel_id' => $hotelId,
                'permitted_hotel_ids' => [$hotelId],
                'limit' => 5000,
            ]);
            $dailyFacts = array_merge($dailyFacts, $this->list($dataset['fact_ota_daily'] ?? []));
            $trafficFacts = array_merge($trafficFacts, $this->list($dataset['fact_ota_traffic'] ?? []));
        }

        return [
            'fact_ota_daily' => $dailyFacts,
            'fact_ota_traffic' => $trafficFacts,
        ];
    }

    /**
     * @param array<string, mixed> $dataset
     * @param array<int, string> $platforms
     * @return array<int, string>
     */
    private function datasetMissingInputs(
        array $dataset,
        array $platforms,
        string $businessDate,
        int $hotelId
    ): array
    {
        $dailyFacts = $this->list($dataset['fact_ota_daily'] ?? []);
        $trafficFacts = $this->list($dataset['fact_ota_traffic'] ?? []);
        $missing = [];
        foreach ($platforms as $platform) {
            if (!$this->hasScopedPlatformFact($dailyFacts, $platform, $businessDate, $hotelId)) {
                $missing[] = $platform . '_target_date_ota_rows';
            }
            if (!$this->hasScopedPlatformFact($trafficFacts, $platform, $businessDate, $hotelId)) {
                $missing[] = $platform . '_target_date_traffic_rows';
            }
        }
        return $missing;
    }

    /**
     * @param array<int, mixed> $facts
     */
    private function hasScopedPlatformFact(
        array $facts,
        string $platform,
        string $businessDate,
        int $hotelId
    ): bool
    {
        $hotelKey = 'system:' . $hotelId;
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $factPlatform = strtolower(trim((string)($fact['platform_key'] ?? $fact['platform'] ?? $fact['source'] ?? '')));
            if ($factPlatform === $platform
                && (string)($fact['date_key'] ?? '') === $businessDate
                && (string)($fact['hotel_key'] ?? '') === $hotelKey
            ) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    private function loadAuthorityReceipt(int $hotelId, string $businessDate): array
    {
        $receipt = Cache::get(
            "online_data_historical_executed_{$hotelId}_{$businessDate}",
            []
        );
        return is_array($receipt) ? $receipt : [];
    }

    /**
     * @param array<string, mixed> $receipt
     * @param array<int, string> $platforms
     * @return array{ready:bool,missing_inputs:array<int,string>,metadata:array<string,mixed>}
     */
    private function authorityReceiptStatus(
        array $receipt,
        string $businessDate,
        int $hotelId,
        array $platforms
    ): array {
        if ($receipt === []) {
            return [
                'ready' => false,
                'missing_inputs' => ['p0_authority_verifier_receipt_missing'],
                'metadata' => [],
            ];
        }

        if (!is_array($receipt['authority_verifier'] ?? null)) {
            return [
                'ready' => false,
                'missing_inputs' => ['p0_authority_collection_receipt_invalid'],
                'metadata' => [],
            ];
        }
        $verifier = $receipt['authority_verifier'];
        $missing = [];
        if ((int)($receipt['schema_version'] ?? 0) < 3
            || strtolower(trim((string)($receipt['data_period'] ?? ''))) !== 'historical_daily'
            || ($receipt['collection_complete'] ?? false) !== true
            || ($receipt['exportable_snapshot_complete'] ?? false) !== true
            || ($receipt['dual_ota_p0_complete'] ?? false) !== true
        ) {
            $missing[] = 'p0_authority_collection_receipt_not_ready';
        }
        if (substr(trim((string)($receipt['target_date'] ?? '')), 0, 10) !== $businessDate
            || (int)($receipt['hotel_id'] ?? 0) !== $hotelId
            || $this->platformList($receipt['required_platforms'] ?? []) !== $platforms
        ) {
            $missing[] = 'p0_authority_collection_scope_mismatch';
        }
        if (!$this->collectionSourceTasksReady($receipt['source_tasks'] ?? [], $platforms)) {
            $missing[] = 'p0_authority_collection_source_task_anchor_missing';
        }
        $collectionAnchorHash = strtolower(trim((string)(
            $receipt['collection_anchor_hash'] ?? ''
        )));
        $verifierAnchorHash = strtolower(trim((string)(
            $verifier['collection_anchor_hash'] ?? ''
        )));
        if (preg_match('/^[a-f0-9]{64}$/D', $collectionAnchorHash) !== 1
            || !hash_equals($collectionAnchorHash, $verifierAnchorHash)
        ) {
            $missing[] = 'p0_authority_collection_anchor_mismatch';
        }
        if (strtolower(trim((string)($verifier['verification_source'] ?? ''))) !== 'external_p0_verifier') {
            $missing[] = 'p0_authority_verification_source_invalid';
        }
        if (strtolower(trim((string)($verifier['status'] ?? ''))) !== 'passed'
            || ($verifier['authority_ready'] ?? false) !== true
            || (int)($verifier['exit_code'] ?? -1) !== 0
        ) {
            $missing[] = 'p0_authority_verifier_not_ready';
        }
        if (substr(trim((string)($verifier['target_date'] ?? '')), 0, 10) !== $businessDate
            || (int)($verifier['hotel_id'] ?? 0) !== $hotelId
        ) {
            $missing[] = 'p0_authority_verifier_scope_mismatch';
        }
        $requiredPlatforms = $this->platformList($verifier['required_platforms'] ?? []);
        $verifiedPlatforms = $this->platformList($verifier['verified_platforms'] ?? []);
        if ($requiredPlatforms === []
            || array_diff($platforms, $requiredPlatforms) !== []
            || array_diff($platforms, $verifiedPlatforms) !== []
        ) {
            $missing[] = 'p0_authority_verified_platforms_incomplete';
        }
        if ((int)($verifier['p0_platforms_ready'] ?? -1) !== count($requiredPlatforms)
            || (int)($verifier['traffic_gates_ready'] ?? -1) !== count($requiredPlatforms)
        ) {
            $missing[] = 'p0_authority_verifier_summary_inconsistent';
        }
        if (strtolower(trim((string)($verifier['continuous_trust_status'] ?? ''))) !== 'verified'
            || $this->stringList($verifier['continuous_trust_missing_steps'] ?? []) !== []
        ) {
            $missing[] = 'p0_authority_persisted_trust_not_ready';
        }
        $reportHash = strtolower(trim((string)($verifier['verifier_report_hash'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $reportHash) !== 1) {
            $missing[] = 'p0_authority_evidence_anchor_missing';
        }
        if (($verifier['sensitive_values_exposed'] ?? true) !== false) {
            $missing[] = 'p0_authority_receipt_sensitive';
        }

        $missing = array_values(array_unique($missing));
        $metadata = [
            'verification_source' => 'external_p0_verifier',
            'target_date' => $businessDate,
            'hotel_id' => $hotelId,
            'verified_platforms' => array_values(array_intersect($platforms, $verifiedPlatforms)),
            'source_scope' => 'ota_channel',
            'verifier_report_hash' => preg_match('/^[a-f0-9]{64}$/D', $reportHash) === 1
                ? $reportHash
                : '',
            'verifier_checked_at' => trim((string)($verifier['checked_at'] ?? '')),
            'sensitive_values_exposed' => false,
        ];
        return [
            'ready' => $missing === [],
            'missing_inputs' => $missing,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param mixed $sourceTasks
     * @param array<int, string> $platforms
     */
    private function collectionSourceTasksReady(mixed $sourceTasks, array $platforms): bool
    {
        if (!is_array($sourceTasks)) {
            return false;
        }
        $readyPlatforms = [];
        foreach ($sourceTasks as $task) {
            if (!is_array($task)
                || strtolower(trim((string)($task['collection_status'] ?? ''))) !== 'success'
                || strtolower(trim((string)($task['p0_status'] ?? ''))) !== 'ready'
                || (int)($task['data_source_id'] ?? 0) <= 0
                || (int)($task['sync_task_id'] ?? 0) <= 0
                || $this->positiveIds($task['row_ids'] ?? []) === []
            ) {
                continue;
            }
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            if (in_array($platform, $platforms, true)) {
                $readyPlatforms[$platform] = true;
            }
        }
        $readyPlatforms = array_keys($readyPlatforms);
        sort($readyPlatforms, SORT_STRING);
        return $readyPlatforms === $platforms;
    }

    /**
     * @param array<int, string> $verifiedPlatforms
     * @return array<string, mixed>
     */
    private function runtimeMetadata(string $businessDate, ?int $hotelId, array $verifiedPlatforms): array
    {
        return [
            'verification_source' => 'runtime_continuous_trust',
            'target_date' => $this->validBusinessDate($businessDate) ? $businessDate : '',
            'hotel_id' => $hotelId !== null && $hotelId > 0 ? $hotelId : null,
            'verified_platforms' => $this->platformList($verifiedPlatforms),
            'source_scope' => 'ota_channel',
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $gate
     * @param array<int, mixed> $platforms
     * @return array<string, mixed>
     */
    private function verificationMetadata(
        array $gate,
        string $businessDate,
        ?int $hotelId,
        array $platforms
    ): array {
        $source = strtolower(trim((string)($gate['verification_source'] ?? '')));
        if (!in_array($source, ['runtime_continuous_trust', 'external_p0_verifier'], true)) {
            return [];
        }

        $targetDate = trim((string)($gate['target_date'] ?? $businessDate));
        $metadataHotelId = (int)($gate['hotel_id'] ?? $hotelId ?? 0);
        $verifiedPlatforms = $this->platformList(
            is_array($gate['verified_platforms'] ?? null) ? $gate['verified_platforms'] : $platforms
        );
        return [
            'verification_source' => $source,
            'target_date' => $this->validBusinessDate($targetDate) ? $targetDate : '',
            'hotel_id' => $metadataHotelId > 0 ? $metadataHotelId : null,
            'verified_platforms' => $verifiedPlatforms,
            'source_scope' => 'ota_channel',
            'verifier_report_hash' => preg_match(
                '/^[a-f0-9]{64}$/D',
                strtolower(trim((string)($gate['verifier_report_hash'] ?? '')))
            ) === 1
                ? strtolower(trim((string)$gate['verifier_report_hash']))
                : '',
            'verifier_checked_at' => trim((string)($gate['verifier_checked_at'] ?? '')),
            'sensitive_values_exposed' => false,
        ];
    }

    private function validBusinessDate(string $businessDate): bool
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($businessDate), $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $date->format('Y-m-d') === trim($businessDate)
            && $date <= new \DateTimeImmutable('today', $timezone);
    }

    private function safeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        return trim($value, '_') ?: 'unknown_step';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function stageRows(string $status): array
    {
        $labels = [
            'revenue_analysis' => '收益分析',
            'ai_decision_advice' => 'AI 决策建议',
            'operation_closure' => '运营闭环',
        ];
        $rows = [];
        foreach ($labels as $key => $label) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'boundary' => $status === 'ready'
                    ? 'P0 OTA field-loop verifier is ready for this downstream claim boundary.'
                    : 'Do not claim this downstream stage as truly closed until the P0 OTA field-loop verifier is ready.',
            ];
        }
        return $rows;
    }

    private function verifierCommand(string $businessDate, ?int $hotelId, array $platforms = []): string
    {
        $date = trim($businessDate);
        $platforms = $this->platformList($platforms);
        $command = 'npm.cmd run verify:p0-ota-field-loop';
        if ($date !== '') {
            $command .= ' -- --date=' . $date;
            if ($platforms !== []) {
                $command .= ' --platform=' . implode(',', $platforms);
            }
            if ($hotelId !== null) {
                $command .= ' --system-hotel-id=' . $hotelId;
            }
        } elseif ($platforms !== []) {
            $command .= ' -- --platform=' . implode(',', $platforms);
            if ($hotelId !== null) {
                $command .= ' --system-hotel-id=' . $hotelId;
            }
        }
        return $command;
    }

    /**
     * @param array<int, mixed> $platforms
     * @return array<int, string>
     */
    private function platformList(mixed $platforms): array
    {
        if (!is_array($platforms)) {
            return [];
        }
        $items = [];
        foreach ($platforms as $platform) {
            $text = strtolower(trim((string)$platform));
            if (in_array($text, ['ctrip', 'meituan'], true)) {
                $items[] = $text;
            }
        }
        $items = array_values(array_unique($items));
        sort($items, SORT_STRING);
        return $items;
    }

    /**
     * @param array<int, string> $flags
     */
    private function collectionQualityState(array $flags): string
    {
        $normalized = array_map(static fn(string $flag): string => strtolower(trim($flag)), $flags);
        if ($this->hasFlagFragment($normalized, ['binding', 'poi', 'platform_hotel_identifier'])) {
            return 'binding_missing';
        }
        if ($this->hasFlagFragment($normalized, ['permission_denied', 'unauthorized'])) {
            return 'permission_denied';
        }
        if ($this->hasFlagFragment($normalized, ['collection_failed', 'sync_completed_without_saved_rows', 'etl_write_not_confirmed', 'snapshot_not_saved', 'parse_failed'])) {
            return 'collection_failed';
        }
        if ($normalized !== [] && $this->hasOnlyStaleFlags($normalized)) {
            return 'stale';
        }

        return 'unverified';
    }

    /**
     * @param array<int, string> $flags
     * @param array<int, string> $fragments
     */
    private function hasFlagFragment(array $flags, array $fragments): bool
    {
        foreach ($flags as $flag) {
            foreach ($fragments as $fragment) {
                if (str_contains($flag, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $flags
     */
    private function hasOnlyStaleFlags(array $flags): bool
    {
        foreach ($flags as $flag) {
            if (!str_contains($flag, 'stale')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return array_values(array_unique($items));
    }

    /** @return array<int, int> */
    private function positiveIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $value),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
