<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Builds evidence-bound investigation drafts from one exact canonical OTA row.
 *
 * The service is intentionally local-only: it reads existing facts and may
 * persist a JSON draft set, but it never creates an operation intent, executes
 * an OTA action, or calls an external collector.
 */
final class CanonicalOtaInvestigationDraftService
{
    public const SCHEMA_VERSION = 'canonical_ota_investigation_drafts.v2';

    private const PROMOTION_VERSION = 'ota_canonical_history_promotion.v3';

    /** @var array<int,string> */
    private const REQUIRED_TRAFFIC_METRICS = [
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
    ];

    /** @var array<string,string> */
    private const EXPECTED_STORAGE_FIELDS = [
        'list_exposure' => 'online_daily_data.list_exposure',
        'detail_exposure' => 'online_daily_data.detail_exposure',
        'flow_rate' => 'online_daily_data.flow_rate',
        'order_filling_num' => 'online_daily_data.order_filling_num',
        'order_submit_num' => 'online_daily_data.order_submit_num',
    ];

    /** @var \Closure(array<string,mixed>):(?array<string,mixed>) */
    private \Closure $rowLoader;

    /** @var \Closure(array<string,mixed>):(?array<string,mixed>) */
    private \Closure $taskLoader;

    /** @var \Closure(array<string,mixed>,array<string,mixed>):string */
    private \Closure $platformIdentityDigestResolver;

    private string $storageRoot;

    public function __construct(
        ?callable $rowLoader = null,
        ?callable $taskLoader = null,
        ?string $storageRoot = null,
        ?callable $platformIdentityDigestResolver = null
    ) {
        $this->rowLoader = $rowLoader !== null
            ? \Closure::fromCallable($rowLoader)
            : static function (array $scope): ?array {
                $row = Db::name('online_daily_data')
                    ->where('id', (int)$scope['row_id'])
                    ->where('tenant_id', (int)$scope['tenant_id'])
                    ->where('system_hotel_id', (int)$scope['hotel_id'])
                    ->where('data_source_id', (int)$scope['data_source_id'])
                    ->where('sync_task_id', (int)$scope['task_id'])
                    ->where('source', (string)$scope['platform'])
                    ->where('platform', (string)$scope['platform'])
                    ->where('data_date', (string)$scope['target_date'])
                    ->where('data_period', (string)$scope['data_period'])
                    ->find();

                return is_array($row) ? $row : null;
            };
        $this->taskLoader = $taskLoader !== null
            ? \Closure::fromCallable($taskLoader)
            : static function (array $scope): ?array {
                $task = Db::name('platform_data_sync_tasks')
                    ->where('id', (int)$scope['task_id'])
                    ->where('tenant_id', (int)$scope['tenant_id'])
                    ->where('system_hotel_id', (int)$scope['hotel_id'])
                    ->where('data_source_id', (int)$scope['data_source_id'])
                    ->where('platform', (string)$scope['platform'])
                    ->find();

                return is_array($task) ? $task : null;
            };
        $this->platformIdentityDigestResolver = $platformIdentityDigestResolver !== null
            ? \Closure::fromCallable($platformIdentityDigestResolver)
            : fn(array $row, array $scope): string => $this->recomputePlatformHotelIdentityDigest(
                $row,
                $scope
            );

        $root = $storageRoot ?? (
            rtrim(runtime_path(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'canonical_ota_investigation_drafts'
        );
        $this->storageRoot = $this->normalizeAbsolutePath($root);
        if ($this->storageRoot === '' || $this->isFilesystemRoot($this->storageRoot)) {
            throw new \InvalidArgumentException('canonical_draft_storage_root_invalid');
        }
        $this->storageRoot = $this->canonicalizeFromExistingAncestor($this->storageRoot);
    }

    /** @param array<string,mixed> $scope */
    public function preflight(array $scope): array
    {
        return $this->run($scope, false);
    }

    /** @param array<string,mixed> $scope */
    public function execute(array $scope): array
    {
        return $this->run($scope, true);
    }

    /** @param array<string,mixed> $scope */
    public function run(array $scope, bool $execute = false): array
    {
        $scope = $this->normalizeScope($scope);
        $prepared = $this->prepareDraftSet($scope);
        $path = $this->targetPath($scope, (string)$prepared['draft_set']['draft_set_id']);

        if (!$execute) {
            $existing = $this->readExistingDraftSetIfPresent(
                $path,
                $scope,
                (string)$prepared['draft_set']['content_digest']
            );
            $idempotent = is_array($existing);
            $draftSet = $idempotent ? $existing : $prepared['draft_set'];
            return [
                'status' => 'ready',
                'execute' => false,
                'would_write' => !$idempotent,
                'idempotent' => $idempotent,
                'readback_verified' => $idempotent,
                'draft_count' => count($draftSet['drafts']),
                'content_digest' => (string)$draftSet['content_digest'],
                'planned_path' => $path,
                'scope' => $scope,
                'draft_set' => $draftSet,
            ];
        }

        $saved = $this->saveAtomically($path, $prepared['draft_set']);

        return [
            'status' => 'saved',
            'execute' => true,
            'would_write' => false,
            'idempotent' => $saved['idempotent'],
            'readback_verified' => true,
            'draft_count' => count($saved['draft_set']['drafts']),
            'content_digest' => (string)$saved['draft_set']['content_digest'],
            'path' => $path,
            'scope' => $scope,
            'draft_set' => $saved['draft_set'],
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{draft_set:array<string,mixed>}
     */
    private function prepareDraftSet(array $scope): array
    {
        $row = ($this->rowLoader)($scope);
        if (!is_array($row)) {
            throw new RuntimeException('canonical_row_not_found_in_exact_scope');
        }
        $this->assertCanonicalRow($row, $scope);
        $this->assertObservedTrafficMetricProvenance($row);
        $this->assertCanonicalRowValidationStatus($row);
        $this->assertCanonicalRowAttribution($row, $scope);
        $metricFacts = $this->validatedTrafficMetricFacts($row);

        $task = ($this->taskLoader)($scope);
        if (!is_array($task)) {
            throw new RuntimeException('canonical_task_not_found_in_exact_scope');
        }
        $stats = $this->assertCanonicalTask($task, $scope);
        $runReadback = $this->assertRunReadback($stats, $scope);
        $promotion = $this->assertPromotionReceipt($stats, $scope, $metricFacts);
        $this->assertCurrentAuthorityBinding($row, $scope, $promotion, $metricFacts);

        $evidenceRef = [
            'canonical_row' => 'online_daily_data#' . $scope['row_id'],
            'sync_task' => 'platform_data_sync_tasks#' . $scope['task_id'],
            'tenant_id' => $scope['tenant_id'],
            'hotel_id' => $scope['hotel_id'],
            'platform' => $scope['platform'],
            'data_source_id' => $scope['data_source_id'],
            'sync_task_id' => $scope['task_id'],
            'row_id' => $scope['row_id'],
            'target_date' => $scope['target_date'],
            'data_period' => $scope['data_period'],
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => true,
            'p0_status' => 'ready',
            'traffic_attribution' => 'authoritative_p0',
            'promotion_version' => self::PROMOTION_VERSION,
            'promotion_content_digest' => (string)$promotion['content_digest'],
            'authoritative_fact_digest' => (string)$promotion['authoritative_fact_digest'],
            'promotion_verified_at' => (string)$promotion['verified_at'],
            'run_readback_row_ids' => $this->positiveIds($runReadback['row_ids'] ?? []),
            'traffic_metric_values' => $metricFacts['values'],
            'traffic_value_status' => $metricFacts['value_status'],
            'nonzero_required_metric_rows' => $metricFacts['nonzero_required_metric_rows'],
            'explicit_zero_confirmed_rows' => $metricFacts['explicit_zero_confirmed_rows'],
        ];

        $basis = [
            'schema_version' => self::SCHEMA_VERSION,
            'scope' => $scope,
            'promotion_content_digest' => (string)$promotion['content_digest'],
            'authoritative_fact_digest' => (string)$promotion['authoritative_fact_digest'],
            'action_codes' => array_column($this->draftDefinitions(), 'action_code'),
        ];
        $idempotencyKey = $this->digest($basis);
        $draftSet = [
            'schema_version' => self::SCHEMA_VERSION,
            'draft_set_id' => 'canonical_ota_investigation_' . substr($idempotencyKey, 0, 24),
            'idempotency_key' => $idempotencyKey,
            'scope' => $scope,
            'draft_status' => 'blocked_by_missing_assignment_due_review',
            'approval_status' => 'not_submitted',
            'execution_status' => 'not_authorized',
            'causality_claimed' => false,
            'source_fact' => $evidenceRef,
            'draft_count' => 4,
            'drafts' => $this->buildDrafts($scope, $evidenceRef),
            'storage_policy' => 'local_runtime_json_only',
            'protected_boundary' => 'Investigation drafts only. No DB intent, OTA mutation, collection, approval, execution, or outcome claim is authorized.',
        ];
        $draftSet['content_digest'] = $this->digest($draftSet);
        $this->assertDraftSet($draftSet, $scope);

        return ['draft_set' => $draftSet];
    }

    /** @param array<string,mixed> $scope */
    private function assertCanonicalRow(array $row, array $scope): void
    {
        $matches = (int)($row['id'] ?? 0) === $scope['row_id']
            && (int)($row['tenant_id'] ?? 0) === $scope['tenant_id']
            && (int)($row['system_hotel_id'] ?? 0) === $scope['hotel_id']
            && (int)($row['data_source_id'] ?? 0) === $scope['data_source_id']
            && (int)($row['sync_task_id'] ?? 0) === $scope['task_id']
            && strtolower(trim((string)($row['source'] ?? ''))) === $scope['platform']
            && strtolower(trim((string)($row['platform'] ?? ''))) === $scope['platform']
            && (string)($row['data_date'] ?? '') === $scope['target_date']
            && strtolower(trim((string)($row['data_period'] ?? ''))) === $scope['data_period']
            && strtolower(trim((string)($row['data_type'] ?? ''))) === 'traffic';
        if (!$matches) {
            throw new RuntimeException('canonical_row_scope_mismatch');
        }
    }

    /** @param array<string,mixed> $row */
    private function assertCanonicalRowValidationStatus(array $row): void
    {
        if (strtolower(trim((string)($row['validation_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($row['history_status'] ?? ''))) !== 'success'
            || (int)($row['readback_verified'] ?? 0) !== 1
        ) {
            throw new RuntimeException('canonical_row_validation_gate_failed');
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $scope */
    private function assertCanonicalRowAttribution(array $row, array $scope): void
    {
        if (!OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic($row, $scope['platform'])) {
            throw new RuntimeException('canonical_row_not_authoritative_p0_traffic');
        }
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function assertCanonicalTask(array $task, array $scope): array
    {
        $matches = (int)($task['id'] ?? 0) === $scope['task_id']
            && (int)($task['tenant_id'] ?? 0) === $scope['tenant_id']
            && (int)($task['system_hotel_id'] ?? 0) === $scope['hotel_id']
            && (int)($task['data_source_id'] ?? 0) === $scope['data_source_id']
            && strtolower(trim((string)($task['platform'] ?? ''))) === $scope['platform']
            && strtolower(trim((string)($task['data_type'] ?? ''))) === 'traffic';
        if (!$matches) {
            throw new RuntimeException('canonical_task_scope_mismatch');
        }
        if (strtolower(trim((string)($task['status'] ?? ''))) !== 'success') {
            throw new RuntimeException('canonical_task_not_success');
        }

        $stats = $this->decodeJsonObject($task['stats_json'] ?? null, 'canonical_task_stats_invalid');
        if (($stats['readback_verified'] ?? null) !== true) {
            throw new RuntimeException('canonical_task_readback_not_verified');
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $stats
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function assertRunReadback(array $stats, array $scope): array
    {
        $readback = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
        $matches = ($readback['readback_verified'] ?? null) === true
            && (int)($readback['sync_task_id'] ?? 0) === $scope['task_id']
            && (int)($readback['data_source_id'] ?? 0) === $scope['data_source_id']
            && (int)($readback['system_hotel_id'] ?? 0) === $scope['hotel_id']
            && strtolower(trim((string)($readback['platform'] ?? ''))) === $scope['platform']
            && (string)($readback['target_date'] ?? '') === $scope['target_date']
            && strtolower(trim((string)($readback['data_period'] ?? ''))) === $scope['data_period'];
        if (!$matches) {
            throw new RuntimeException('canonical_run_readback_scope_mismatch');
        }
        if (strtolower(trim((string)($readback['p0_status'] ?? ''))) !== 'ready'
            || strtolower(trim((string)($readback['field_fact_status'] ?? ''))) !== 'ready'
            || strtolower(trim((string)($readback['page_field_fact_status'] ?? ''))) !== 'ready'
            || strtolower(trim((string)($readback['platform_hotel_identifier_status'] ?? ''))) !== 'ready'
        ) {
            throw new RuntimeException('canonical_run_readback_not_p0_ready');
        }
        if (!in_array($scope['row_id'], $this->positiveIds($readback['row_ids'] ?? []), true)) {
            throw new RuntimeException('canonical_run_readback_row_missing');
        }
        $requiredMetrics = self::REQUIRED_TRAFFIC_METRICS;
        sort($requiredMetrics, SORT_STRING);
        if ($this->normalizedStringSet($readback['required_traffic_metric_keys'] ?? []) !== $requiredMetrics
            || $this->normalizedStringSet($readback['complete_traffic_metric_keys'] ?? []) !== $requiredMetrics
            || $this->normalizedStringSet($readback['missing_traffic_metric_keys'] ?? []) !== []
        ) {
            throw new RuntimeException('canonical_run_readback_metric_gate_failed');
        }

        return $readback;
    }

    /**
     * @param array<string,mixed> $stats
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $metricFacts
     * @return array<string,mixed>
     */
    private function assertPromotionReceipt(array $stats, array $scope, array $metricFacts): array
    {
        $receipt = is_array($stats['canonical_history_promotion'] ?? null)
            ? $stats['canonical_history_promotion']
            : [];
        $matches = (string)($receipt['version'] ?? '') === self::PROMOTION_VERSION
            && (int)($receipt['tenant_id'] ?? 0) === $scope['tenant_id']
            && (int)($receipt['system_hotel_id'] ?? 0) === $scope['hotel_id']
            && strtolower(trim((string)($receipt['platform'] ?? ''))) === $scope['platform']
            && (string)($receipt['target_date'] ?? '') === $scope['target_date']
            && strtolower(trim((string)($receipt['data_period'] ?? ''))) === $scope['data_period']
            && (int)($receipt['data_source_id'] ?? 0) === $scope['data_source_id']
            && (int)($receipt['sync_task_id'] ?? 0) === $scope['task_id']
            && $this->positiveIds($receipt['row_ids'] ?? []) === [$scope['row_id']];
        if (!$matches) {
            throw new RuntimeException('canonical_promotion_receipt_scope_mismatch');
        }
        if ((int)($receipt['nonzero_required_metric_rows'] ?? -1)
                !== (int)$metricFacts['nonzero_required_metric_rows']
            || (int)($receipt['explicit_zero_confirmed_rows'] ?? -1)
                !== (int)$metricFacts['explicit_zero_confirmed_rows']
            || (int)$metricFacts['nonzero_required_metric_rows']
                + (int)$metricFacts['explicit_zero_confirmed_rows'] !== 1
            || strtolower(trim((string)(
                $receipt['observed_traffic_metric_provenance_status'] ?? ''
            ))) !== 'ready'
            || (int)($receipt['synthetic_normalization_provenance_missing_rows'] ?? -1) !== 0
            || ($receipt['sensitive_values_exposed'] ?? true) !== false
        ) {
            throw new RuntimeException('canonical_promotion_receipt_fact_gate_failed');
        }
        foreach ([
            'collection_anchor_hash',
            'verifier_report_hash',
            'authoritative_fact_digest',
            'platform_hotel_identity_digest',
            'content_digest',
        ] as $hashField) {
            if (preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)($receipt[$hashField] ?? '')))) !== 1) {
                throw new RuntimeException('canonical_promotion_receipt_hash_invalid');
            }
        }
        $contentDigest = strtolower(trim((string)$receipt['content_digest']));
        if (!hash_equals($contentDigest, $this->digest($receipt))) {
            throw new RuntimeException('canonical_promotion_receipt_content_digest_invalid');
        }
        if (trim((string)($receipt['verified_at'] ?? '')) === '') {
            throw new RuntimeException('canonical_promotion_receipt_verified_at_missing');
        }

        return $receipt;
    }

    /**
     * Revalidate every persisted traffic metric against its structured raw
     * source fact. Values are descriptive evidence only; their magnitude or
     * ratio is never treated as a causal explanation.
     *
     * @param array<string,mixed> $row
     * @return array{
     *   values:array<string,string>,
     *   value_status:string,
     *   nonzero_required_metric_rows:int,
     *   explicit_zero_confirmed_rows:int
     * }
     */
    private function validatedTrafficMetricFacts(array $row): array
    {
        $raw = $this->decodeRawData($row);
        $sourceRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        if ($sourceRow === []) {
            throw new RuntimeException('canonical_authoritative_metric_source_row_invalid');
        }

        $factsByMetric = [];
        foreach (is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [] as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metric = strtolower(trim((string)($fact['metric_key'] ?? '')));
            if (!in_array($metric, self::REQUIRED_TRAFFIC_METRICS, true)) {
                continue;
            }
            if (isset($factsByMetric[$metric])) {
                throw new RuntimeException('canonical_authoritative_metric_fact_ambiguous:' . $metric);
            }
            $factsByMetric[$metric] = $fact;
        }

        $values = [];
        $hasNonzero = false;
        foreach (self::REQUIRED_TRAFFIC_METRICS as $metric) {
            $fact = $factsByMetric[$metric] ?? null;
            $sourceKey = is_array($fact) ? trim((string)($fact['source_key'] ?? '')) : '';
            $sourcePath = is_array($fact) ? trim((string)($fact['source_path'] ?? '')) : '';
            if (!array_key_exists($metric, $row)
                || !is_numeric($row[$metric])
                || !is_finite((float)$row[$metric])
                || !is_array($fact)
                || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                || $sourceKey === ''
                || !array_key_exists($sourceKey, $sourceRow)
                || !is_numeric($sourceRow[$sourceKey])
                || !is_finite((float)$sourceRow[$sourceKey])
                || preg_match('/[.\[\/]/', $sourcePath) !== 1
                || trim((string)($fact['storage_field'] ?? '')) !== self::EXPECTED_STORAGE_FIELDS[$metric]
                || ($fact['stored_value_present'] ?? null) !== true
                || abs((float)$sourceRow[$sourceKey] - (float)$row[$metric]) > 0.000001
            ) {
                throw new RuntimeException('canonical_authoritative_metric_fact_invalid:' . $metric);
            }
            $values[$metric] = sprintf('%.8F', (float)$row[$metric]);
            $hasNonzero = $hasNonzero || abs((float)$row[$metric]) > 0.000001;
        }
        ksort($values, SORT_STRING);

        return [
            'values' => $values,
            'value_status' => $hasNonzero ? 'nonzero' : 'explicit_zero',
            'nonzero_required_metric_rows' => $hasNonzero ? 1 : 0,
            'explicit_zero_confirmed_rows' => $hasNonzero ? 0 : 1,
        ];
    }

    /** @param array<string,mixed> $row */
    private function assertObservedTrafficMetricProvenance(array $row): void
    {
        $raw = $this->decodeRawData($row);
        $sourceRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $observed = $sourceRow['_observed_traffic_metric_keys'] ?? null;
        if (!is_array($observed)
            || !array_is_list($observed)
            || count($observed) !== count(self::REQUIRED_TRAFFIC_METRICS)
        ) {
            throw new RuntimeException('synthetic_normalization_provenance_missing');
        }

        $keys = [];
        foreach ($observed as $key) {
            if (!is_string($key)
                || $key !== trim($key)
                || preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $key) !== 1
                || isset($keys[$key])
            ) {
                throw new RuntimeException('synthetic_normalization_provenance_missing');
            }
            $keys[$key] = true;
        }
        $actual = array_keys($keys);
        $expected = self::REQUIRED_TRAFFIC_METRICS;
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('synthetic_normalization_provenance_missing');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $promotion
     * @param array<string,mixed> $metricFacts
     */
    private function assertCurrentAuthorityBinding(
        array $row,
        array $scope,
        array $promotion,
        array $metricFacts
    ): void
    {
        $currentFactDigest = $this->authoritativeFactDigest($row, $metricFacts);
        if (!hash_equals(
            strtolower(trim((string)$promotion['authoritative_fact_digest'])),
            $currentFactDigest
        )) {
            throw new RuntimeException('canonical_authoritative_fact_digest_mismatch');
        }

        $identityDigest = strtolower(trim((string)(
            ($this->platformIdentityDigestResolver)($row, $scope)
        )));
        if (preg_match('/^[a-f0-9]{64}$/D', $identityDigest) !== 1
            || !hash_equals(
                strtolower(trim((string)$promotion['platform_hotel_identity_digest'])),
                $identityDigest
            )
        ) {
            throw new RuntimeException('canonical_platform_hotel_identity_mismatch');
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed>|null $metricFacts */
    private function authoritativeFactDigest(array $row, ?array $metricFacts = null): string
    {
        $rawJson = trim((string)($row['raw_data'] ?? ''));
        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        if ($rawJson === '' || $traceId === '') {
            throw new RuntimeException('canonical_authoritative_fact_evidence_missing');
        }
        $this->decodeRawData($row);

        $metricFacts ??= $this->validatedTrafficMetricFacts($row);
        $metrics = is_array($metricFacts['values'] ?? null) ? $metricFacts['values'] : [];
        if (count($metrics) !== count(self::REQUIRED_TRAFFIC_METRICS)
            || !in_array((string)($metricFacts['value_status'] ?? ''), ['nonzero', 'explicit_zero'], true)
        ) {
            throw new RuntimeException('canonical_authoritative_fact_metric_profile_invalid');
        }
        $requiredMetrics = self::REQUIRED_TRAFFIC_METRICS;
        sort($requiredMetrics, SORT_STRING);

        return $this->digest([
            'required_metric_keys' => $requiredMetrics,
            'rows' => [[
                'id' => (int)($row['id'] ?? 0),
                'source_trace_digest' => hash('sha256', $traceId),
                'raw_data_digest' => hash('sha256', $rawJson),
                'metric_values' => $metrics,
                'observed_traffic_metric_keys' => $requiredMetrics,
                'value_status' => (string)$metricFacts['value_status'],
            ]],
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeRawData(array $row): array
    {
        $rawJson = trim((string)($row['raw_data'] ?? ''));
        if ($rawJson === '') {
            throw new RuntimeException('canonical_authoritative_raw_data_invalid');
        }
        try {
            $raw = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('canonical_authoritative_raw_data_invalid', 0, $exception);
        }
        if (!is_array($raw) || $raw === []) {
            throw new RuntimeException('canonical_authoritative_raw_data_invalid');
        }
        return $raw;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $scope */
    private function recomputePlatformHotelIdentityDigest(array $row, array $scope): string
    {
        $hotelTenantId = (int)Db::name('hotels')
            ->where('id', $scope['hotel_id'])
            ->value('tenant_id');
        if ($hotelTenantId !== $scope['tenant_id']) {
            throw new RuntimeException('canonical_platform_hotel_tenant_scope_mismatch');
        }

        $selectedSource = Db::name('platform_data_sources')
            ->where('id', $scope['data_source_id'])
            ->where('tenant_id', $scope['tenant_id'])
            ->where('system_hotel_id', $scope['hotel_id'])
            ->where('platform', 'ctrip')
            ->find();
        if (!is_array($selectedSource)
            || (int)($selectedSource['enabled'] ?? 0) !== 1
            || strtolower(trim((string)($selectedSource['status'] ?? ''))) === 'disabled'
            || !in_array(strtolower(trim((string)($selectedSource['ingestion_method'] ?? ''))), [
                'browser_profile', 'profile_browser',
            ], true)
            || !array_key_exists('config_json', $selectedSource)
        ) {
            throw new RuntimeException('canonical_platform_source_identity_invalid');
        }

        $selectedConfig = $this->decodedConfig($selectedSource['config_json']);
        $selectedProfileHash = $this->profileKeyHash($selectedConfig);
        if ($selectedProfileHash === '') {
            throw new RuntimeException('canonical_platform_profile_key_missing');
        }

        $profileSources = Db::name('platform_data_sources')
            ->where('platform', 'ctrip')
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->where('enabled', 1)
            ->where('status', '<>', 'disabled')
            ->select()
            ->toArray();
        $profileScopes = [];
        $authorityHashes = [];
        $authoritySourceIds = [];
        $identifierScopes = [];
        foreach ($profileSources as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $config = $this->decodedConfig($candidate['config_json'] ?? null);
            $profileHash = $this->profileKeyHash($config);
            $candidateHotelId = (int)($candidate['system_hotel_id'] ?? 0);
            $candidateTenantId = $candidateHotelId > 0
                ? (int)Db::name('hotels')->where('id', $candidateHotelId)->value('tenant_id')
                : 0;
            if ($profileHash === $selectedProfileHash) {
                if ($candidateHotelId <= 0 || $candidateTenantId <= 0) {
                    throw new RuntimeException('canonical_platform_profile_scope_metadata_missing');
                }
                $profileScopes[$candidateTenantId . ':' . $candidateHotelId] = true;
            }

            $trafficCapable = OtaTrafficAttributionService::sourceCanProvideTraffic($candidate, $config);
            $sourceTenantReady = $candidateTenantId > 0
                && (int)($candidate['tenant_id'] ?? 0) === $candidateTenantId;
            $bindingReady = $trafficCapable
                && $sourceTenantReady
                && $this->activeProfileBindingMatches(
                    $profileHash,
                    $candidateTenantId,
                    $candidateHotelId
                );
            $identifierHashes = $trafficCapable ? $this->platformIdentifierHashes($config) : [];
            if ($bindingReady && $identifierHashes !== []) {
                $scopeKey = $candidateTenantId . ':' . $candidateHotelId;
                foreach ($identifierHashes as $identifierHash) {
                    $identifierScopes[$identifierHash][$scopeKey] = true;
                }
            }
            if ($candidateHotelId !== $scope['hotel_id'] || !$trafficCapable) {
                continue;
            }
            if ((int)($candidate['tenant_id'] ?? 0) !== $scope['tenant_id']
                || $candidateTenantId !== $scope['tenant_id']
            ) {
                throw new RuntimeException('canonical_platform_profile_source_tenant_scope_mismatch');
            }
            if (!$bindingReady) {
                throw new RuntimeException('canonical_platform_profile_binding_unverified');
            }
            if ($identifierHashes === []) {
                throw new RuntimeException('canonical_platform_profile_identifier_missing');
            }
            foreach ($identifierHashes as $identifierHash) {
                $authorityHashes[$identifierHash] = true;
            }
            $authoritySourceIds[] = (int)($candidate['id'] ?? 0);
        }
        foreach ($identifierScopes as $scopes) {
            if (count($scopes) > 1) {
                throw new RuntimeException('canonical_platform_identifier_scope_conflict');
            }
        }
        if (count($profileScopes) !== 1) {
            throw new RuntimeException('canonical_platform_profile_scope_conflict');
        }
        $authoritySourceIds = $this->positiveIds($authoritySourceIds);
        if (!in_array($scope['data_source_id'], $authoritySourceIds, true)
            || count($authorityHashes) !== 1
        ) {
            throw new RuntimeException('canonical_platform_profile_identifier_ambiguous');
        }
        $expectedIdentifierHash = (string)array_key_first($authorityHashes);

        $raw = $this->decodeRawData($row);
        $rowIdentifierHashes = $this->platformIdentifierHashes($raw);
        if (count($rowIdentifierHashes) !== 1
            || !hash_equals($expectedIdentifierHash, $rowIdentifierHashes[0])
        ) {
            throw new RuntimeException('canonical_platform_hotel_identifier_mismatch');
        }
        $boundedRows = [[
            'id' => (int)($row['id'] ?? 0),
            'identifier_match_digest' => hash(
                'sha256',
                $expectedIdentifierHash . "\0" . $rowIdentifierHashes[0]
            ),
        ]];

        return $this->digest([
            'authority_source_ids' => $authoritySourceIds,
            'expected_identifier_digest' => hash('sha256', $expectedIdentifierHash),
            'profile_scope_digest' => hash('sha256', $scope['tenant_id'] . ':' . $scope['hotel_id']),
            'rows' => $boundedRows,
        ]);
    }

    private function activeProfileBindingMatches(
        string $profileKeyHash,
        int $tenantId,
        int $hotelId
    ): bool {
        if ($profileKeyHash === '') {
            return false;
        }
        $bindings = Db::name('ota_profile_bindings')
            ->where('platform', 'ctrip')
            ->where('profile_key_hash', $profileKeyHash)
            ->where('binding_status', 'active')
            ->select()
            ->toArray();
        return count($bindings) === 1
            && (int)($bindings[0]['tenant_id'] ?? 0) === $tenantId
            && (int)($bindings[0]['system_hotel_id'] ?? 0) === $hotelId;
    }

    /** @return array<string,mixed> */
    private function decodedConfig(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $config */
    private function profileKeyHash(array $config): string
    {
        $profileKey = '';
        foreach (['profile_id', 'profileId'] as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                $profileKey = trim((string)$config[$key]);
                break;
            }
        }
        if ($profileKey === '') {
            return '';
        }
        $safeFilePart = BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        return $safeFilePart === '' || $safeFilePart === 'default'
            ? ''
            : hash('sha256', $safeFilePart);
    }

    /** @param array<string,mixed> $container @return array<int,string> */
    private function platformIdentifierHashes(array $container): array
    {
        $priorityGroups = [['hotelid', 'ctriphotelid', 'masterhotelid'], ['nodeid']];
        $keyPriorities = [];
        foreach ($priorityGroups as $priority => $keys) {
            foreach ($keys as $key) {
                $keyPriorities[$key] = $priority;
            }
        }
        $hashesByPriority = array_fill(0, count($priorityGroups), []);
        $visited = 0;
        $visit = static function (array $value, int $depth) use (
            &$visit,
            &$hashesByPriority,
            &$visited,
            $keyPriorities
        ): void {
            if ($depth > 12 || $visited >= 10000) {
                return;
            }
            foreach ($value as $key => $item) {
                $visited++;
                if ($visited > 10000) {
                    return;
                }
                $normalizedKey = strtolower((string)preg_replace(
                    '/[^a-z0-9]+/i',
                    '',
                    (string)$key
                ));
                if (isset($keyPriorities[$normalizedKey])
                    && (is_string($item) || is_int($item) || is_float($item))
                    && trim((string)$item) !== ''
                ) {
                    $priority = (int)$keyPriorities[$normalizedKey];
                    $hashesByPriority[$priority][hash(
                        'sha256',
                        'ctrip' . "\0" . trim((string)$item)
                    )] = true;
                }
                if (is_array($item)) {
                    $visit($item, $depth + 1);
                }
            }
        };
        $visit($container, 0);
        foreach ($hashesByPriority as $hashes) {
            if ($hashes !== []) {
                $result = array_keys($hashes);
                sort($result, SORT_STRING);
                return $result;
            }
        }
        return [];
    }

    /** @return array<int,array<string,mixed>> */
    private function draftDefinitions(): array
    {
        return [
            [
                'action_code' => 'check_list_to_detail_mathematical_consistency',
                'title' => '核查列表曝光到详情曝光的数学一致性',
                'action_text' => '仅使用当前精确权威行，对列表曝光、详情曝光及已观测流量率做描述性算术核查；分母为零时记录为不可计算，不推断原因、成效或平台行为。',
                'acceptance_criteria' => [
                    '记录精确输入事实和计算公式，不补默认值或替代值',
                    '只报告数学一致、数学不一致或不可计算，不把比率或零值解释为原因',
                ],
            ],
            [
                'action_code' => 'investigate_detail_to_order_fill_breakpoint',
                'title' => '调查详情曝光到订单填写的断点',
                'action_text' => '核查当前精确行中详情曝光与订单填写之间的证据边界；只有在数学定义成立时才记录描述性转换比率，不把比率或任一指标解释为原因。',
                'acceptance_criteria' => [
                    '每项观察均绑定精确行、任务、来源、平台、酒店和业务日期',
                    '缺少转换证据时明确记录 unknown，不归因、不声称运营成效',
                ],
            ],
            [
                'action_code' => 'investigate_fill_to_submit_chain',
                'title' => '调查订单填写到订单提交链路',
                'action_text' => '核查精确证据集能否分别支撑订单填写与订单提交这两个观测事实；只记录描述性算术，不解释差值、不声称转化成效，也不提交任何 OTA 动作。',
                'acceptance_criteria' => [
                    '分别记录链路两端的精确证据和仍不可验证的证据缺口',
                    '即使任一观测值为零，原因仍保持 unknown，成效仍保持未声称',
                ],
            ],
            [
                'action_code' => 'prepare_same_scope_recollection_and_entry_eligibility_check',
                'title' => '准备同范围复采与入口资格核查',
                'action_text' => '核查相同租户、酒店、来源、平台、业务日期和周期下的复采前置条件与入口资格，仅形成对照清单；本草稿不触发采集、不修改入口且不授权执行。',
                'acceptance_criteria' => [
                    '拟议对照范围与当前 canonical scope 和 promotion receipt 精确一致',
                    '采集与外部写入保持 not_authorized，资格观察不作为原因或成效结论',
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $evidenceRef
     * @return array<int,array<string,mixed>>
     */
    private function buildDrafts(array $scope, array $evidenceRef): array
    {
        $drafts = [];
        foreach ($this->draftDefinitions() as $index => $definition) {
            $drafts[] = [
                'draft_id' => sprintf('%s-%02d', $definition['action_code'], $index + 1),
                'hotel_id' => $scope['hotel_id'],
                'platform' => $scope['platform'],
                'target_date' => $scope['target_date'],
                'action_code' => $definition['action_code'],
                'action_kind' => 'investigation_check',
                'title' => $definition['title'],
                'action_text' => $definition['action_text'],
                'acceptance_criteria' => $definition['acceptance_criteria'],
                'causality_claimed' => false,
                'outcome_claimed' => false,
                'cause_status' => 'unknown_requires_investigation',
                'assignee' => null,
                'due' => null,
                'reviewer' => null,
                'review_at' => null,
                'assignment_status' => 'blocked_by_missing_assignee',
                'due_status' => 'blocked_by_missing_due',
                'review_status' => 'blocked_by_missing_reviewer_and_review_at',
                'approval_status' => 'blocked_by_missing_assignment_due_review',
                'execution_status' => 'not_authorized',
                'evidence_refs' => [$evidenceRef],
                'protected_boundary' => 'Investigation/check only; no execution, external write, causal claim, or outcome claim.',
            ];
        }
        return $drafts;
    }

    /** @param array<string,mixed> $draftSet @param array<string,mixed> $scope */
    private function assertDraftSet(array $draftSet, array $scope): void
    {
        if ((string)($draftSet['schema_version'] ?? '') !== self::SCHEMA_VERSION
            || !is_array($draftSet['scope'] ?? null)
            || $draftSet['scope'] !== $scope
            || (int)($draftSet['draft_count'] ?? 0) !== 4
            || !is_array($draftSet['drafts'] ?? null)
            || count($draftSet['drafts']) !== 4
            || ($draftSet['causality_claimed'] ?? true) !== false
            || (string)($draftSet['execution_status'] ?? '') !== 'not_authorized'
        ) {
            throw new RuntimeException('canonical_investigation_draft_set_invalid');
        }
        $digest = strtolower(trim((string)($draftSet['content_digest'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals($digest, $this->digest($draftSet))
        ) {
            throw new RuntimeException('canonical_investigation_draft_content_digest_invalid');
        }

        $codes = [];
        foreach ($draftSet['drafts'] as $draft) {
            if (!is_array($draft)
                || (int)($draft['hotel_id'] ?? 0) !== $scope['hotel_id']
                || strtolower(trim((string)($draft['platform'] ?? ''))) !== $scope['platform']
                || (string)($draft['target_date'] ?? '') !== $scope['target_date']
                || (string)($draft['action_kind'] ?? '') !== 'investigation_check'
                || ($draft['causality_claimed'] ?? true) !== false
                || ($draft['outcome_claimed'] ?? true) !== false
                || (string)($draft['execution_status'] ?? '') !== 'not_authorized'
            ) {
                throw new RuntimeException('canonical_investigation_draft_boundary_invalid');
            }
            if (!array_key_exists('assignee', $draft)
                || !array_key_exists('due', $draft)
                || !array_key_exists('reviewer', $draft)
                || !array_key_exists('review_at', $draft)
                || $draft['assignee'] !== null
                || $draft['due'] !== null
                || $draft['reviewer'] !== null
                || $draft['review_at'] !== null
                || (string)($draft['assignment_status'] ?? '') !== 'blocked_by_missing_assignee'
                || (string)($draft['due_status'] ?? '') !== 'blocked_by_missing_due'
                || (string)($draft['review_status'] ?? '') !== 'blocked_by_missing_reviewer_and_review_at'
                || (string)($draft['approval_status'] ?? '') !== 'blocked_by_missing_assignment_due_review'
            ) {
                throw new RuntimeException('canonical_investigation_draft_assignment_contract_invalid');
            }
            $evidence = is_array($draft['evidence_refs'][0] ?? null) ? $draft['evidence_refs'][0] : [];
            $trafficValues = is_array($evidence['traffic_metric_values'] ?? null)
                ? $evidence['traffic_metric_values']
                : [];
            $trafficKeys = array_keys($trafficValues);
            sort($trafficKeys, SORT_STRING);
            $requiredTrafficKeys = self::REQUIRED_TRAFFIC_METRICS;
            sort($requiredTrafficKeys, SORT_STRING);
            $valueStatus = (string)($evidence['traffic_value_status'] ?? '');
            $expectedNonzeroRows = $valueStatus === 'nonzero' ? 1 : 0;
            $expectedZeroRows = $valueStatus === 'explicit_zero' ? 1 : 0;
            if ((int)($evidence['tenant_id'] ?? 0) !== $scope['tenant_id']
                || (int)($evidence['hotel_id'] ?? 0) !== $scope['hotel_id']
                || strtolower(trim((string)($evidence['platform'] ?? ''))) !== $scope['platform']
                || (string)($evidence['target_date'] ?? '') !== $scope['target_date']
                || strtolower(trim((string)($evidence['data_period'] ?? ''))) !== $scope['data_period']
                || (int)($evidence['row_id'] ?? 0) !== $scope['row_id']
                || (int)($evidence['sync_task_id'] ?? 0) !== $scope['task_id']
                || (int)($evidence['data_source_id'] ?? 0) !== $scope['data_source_id']
                || (string)($evidence['validation_status'] ?? '') !== 'verified'
                || (string)($evidence['history_status'] ?? '') !== 'success'
                || ($evidence['readback_verified'] ?? false) !== true
                || (string)($evidence['promotion_version'] ?? '') !== self::PROMOTION_VERSION
                || !in_array($valueStatus, ['nonzero', 'explicit_zero'], true)
                || $trafficKeys !== $requiredTrafficKeys
                || (int)($evidence['nonzero_required_metric_rows'] ?? -1) !== $expectedNonzeroRows
                || (int)($evidence['explicit_zero_confirmed_rows'] ?? -1) !== $expectedZeroRows
            ) {
                throw new RuntimeException('canonical_investigation_draft_evidence_invalid');
            }
            $code = trim((string)($draft['action_code'] ?? ''));
            if ($code === '' || isset($codes[$code])) {
                throw new RuntimeException('canonical_investigation_draft_code_invalid');
            }
            $codes[$code] = true;
        }
        if (array_keys($codes) !== array_column($this->draftDefinitions(), 'action_code')) {
            throw new RuntimeException('canonical_investigation_draft_code_invalid');
        }
    }

    /**
     * @param array<string,mixed> $draftSet
     * @return array{idempotent:bool,draft_set:array<string,mixed>}
     */
    private function saveAtomically(string $path, array $draftSet): array
    {
        $directory = dirname($path);
        $lockPath = $path . '.lock';
        $this->assertExistingPathChainSafe($directory);
        $this->assertRegularFileOrAbsent($path);
        $this->assertRegularFileOrAbsent($lockPath);
        $this->ensureDirectorySafely($directory);

        // Re-check every filesystem object immediately before the first fopen.
        $this->assertExistingPathChainSafe($directory);
        $this->assertRegularFileOrAbsent($path);
        $this->assertRegularFileOrAbsent($lockPath);
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('canonical_investigation_draft_lock_open_failed');
        }
        $temporaryPath = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('canonical_investigation_draft_lock_failed');
            }
            $this->assertExistingPathChainSafe($directory);
            $this->assertRegularFileOrAbsent($path);
            $this->assertRegularFileOrAbsent($lockPath);
            if (is_file($path)) {
                $existing = $this->readDraftSet($path, $draftSet['scope']);
                if (!hash_equals((string)$draftSet['content_digest'], (string)$existing['content_digest'])) {
                    throw new RuntimeException('canonical_investigation_draft_idempotency_conflict');
                }
                return ['idempotent' => true, 'draft_set' => $existing];
            }

            $json = json_encode(
                $draftSet,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $this->assertExistingPathChainSafe($directory);
            $this->assertRegularFileOrAbsent($path);
            $this->assertPathNotLinkOrJunction($temporaryPath);
            $temporary = fopen($temporaryPath, 'xb');
            if ($temporary === false) {
                throw new RuntimeException('canonical_investigation_draft_temp_open_failed');
            }
            try {
                $offset = 0;
                $length = strlen($json);
                while ($offset < $length) {
                    $written = fwrite($temporary, substr($json, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('canonical_investigation_draft_temp_write_failed');
                    }
                    $offset += $written;
                }
                if (!fflush($temporary)) {
                    throw new RuntimeException('canonical_investigation_draft_temp_flush_failed');
                }
                if (function_exists('fsync') && !fsync($temporary)) {
                    throw new RuntimeException('canonical_investigation_draft_temp_sync_failed');
                }
            } finally {
                fclose($temporary);
            }
            $this->assertExistingPathChainSafe($directory);
            $this->assertRegularFileOrAbsent($lockPath);
            $this->assertRegularFileOrAbsent($temporaryPath);
            $this->assertRegularFileOrAbsent($path);
            if (@lstat($path) !== false) {
                throw new RuntimeException('canonical_investigation_draft_concurrent_target_conflict');
            }
            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException('canonical_investigation_draft_atomic_rename_failed');
            }
            $temporaryPath = null;

            $readback = $this->readDraftSet($path, $draftSet['scope']);
            if ($readback !== $draftSet) {
                throw new RuntimeException('canonical_investigation_draft_exact_readback_mismatch');
            }
            return ['idempotent' => false, 'draft_set' => $readback];
        } finally {
            try {
                if (is_string($temporaryPath) && @lstat($temporaryPath) !== false) {
                    $this->assertPathNotLinkOrJunction($temporaryPath);
                    @unlink($temporaryPath);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>|null
     */
    private function readExistingDraftSetIfPresent(
        string $path,
        array $scope,
        string $expectedDigest
    ): ?array {
        $this->assertExistingPathChainSafe(dirname($path));
        $this->assertRegularFileOrAbsent($path);
        $this->assertRegularFileOrAbsent($path . '.lock');
        if (@lstat($path) === false) {
            return null;
        }
        $existing = $this->readDraftSet($path, $scope);
        if (!hash_equals($expectedDigest, (string)$existing['content_digest'])) {
            throw new RuntimeException('canonical_investigation_draft_idempotency_conflict');
        }
        return $existing;
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function readDraftSet(string $path, array $scope): array
    {
        $this->assertExistingPathChainSafe(dirname($path));
        $this->assertRegularFileOrAbsent($path);
        $this->assertResolvedPathWithinStorageRoot(dirname($path));
        $this->assertResolvedPathWithinStorageRoot($path);
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('canonical_investigation_draft_readback_failed');
        }
        try {
            $draftSet = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('canonical_investigation_draft_readback_json_invalid', 0, $exception);
        }
        if (!is_array($draftSet)) {
            throw new RuntimeException('canonical_investigation_draft_readback_invalid');
        }
        $this->assertDraftSet($draftSet, $scope);
        return $draftSet;
    }

    /** @param array<string,mixed> $scope */
    private function targetPath(array $scope, string $draftSetId): string
    {
        if (preg_match('/^canonical_ota_investigation_[a-f0-9]{24}$/D', $draftSetId) !== 1) {
            throw new RuntimeException('canonical_investigation_draft_set_id_invalid');
        }
        $segments = [
            str_replace('-', '', $scope['target_date']),
            'tenant_' . $scope['tenant_id'],
            'hotel_' . $scope['hotel_id'],
            $scope['platform'],
            'source_' . $scope['data_source_id'],
            'task_' . $scope['task_id'],
            'row_' . $scope['row_id'],
            $draftSetId . '.json',
        ];
        $path = $this->storageRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        if (!$this->pathWithinRoot($path, $this->storageRoot)) {
            throw new RuntimeException('canonical_investigation_draft_path_scope_invalid');
        }
        return $path;
    }

    private function ensureDirectorySafely(string $directory): void
    {
        $directory = $this->normalizeAbsolutePath($directory);
        if (!$this->pathWithinRoot($directory, $this->storageRoot)) {
            throw new RuntimeException('canonical_investigation_draft_path_scope_invalid');
        }
        $this->assertExistingPathChainSafe($directory);

        $missing = [];
        $current = $directory;
        while (!is_dir($current)) {
            if (@lstat($current) !== false) {
                $this->assertPathNotLinkOrJunction($current);
                throw new RuntimeException('canonical_investigation_draft_directory_path_invalid');
            }
            $missing[] = $current;
            $parent = $this->normalizeAbsolutePath(dirname($current));
            if ($parent === '' || $parent === $current) {
                throw new RuntimeException('canonical_investigation_draft_directory_parent_missing');
            }
            $current = $parent;
        }
        $this->assertExistingPathChainSafe($current);

        foreach (array_reverse($missing) as $candidate) {
            $parent = dirname($candidate);
            $this->assertExistingPathChainSafe($parent);
            $this->assertPathNotLinkOrJunction($candidate);
            if (!mkdir($candidate, 0775, false) && !is_dir($candidate)) {
                throw new RuntimeException('canonical_investigation_draft_directory_create_failed');
            }
            $this->assertExistingPathChainSafe($candidate);
            if ($this->pathWithinRoot($candidate, $this->storageRoot)) {
                $this->assertResolvedPathWithinStorageRoot($candidate);
            }
        }
        $this->assertResolvedPathWithinStorageRoot($directory);
    }

    private function assertExistingPathChainSafe(string $path): void
    {
        $current = $this->normalizeAbsolutePath($path);
        if ($current === '') {
            throw new RuntimeException('canonical_investigation_draft_path_invalid');
        }
        while (true) {
            $this->assertPathNotLinkOrJunction($current);
            $parent = $this->normalizeAbsolutePath(dirname($current));
            if ($parent === '' || $parent === $current) {
                break;
            }
            $current = $parent;
        }
    }

    private function assertRegularFileOrAbsent(string $path): void
    {
        $this->assertPathNotLinkOrJunction($path);
        if (@lstat($path) !== false && !is_file($path)) {
            throw new RuntimeException('canonical_investigation_draft_file_type_invalid');
        }
    }

    private function assertPathNotLinkOrJunction(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('canonical_investigation_draft_link_rejected');
        }
        if (@lstat($path) === false) {
            return;
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('canonical_investigation_draft_link_rejected');
        }
        if ($this->sameCanonicalPath($resolved, $path)) {
            return;
        }
        $parent = dirname($path);
        $resolvedParent = realpath($parent);
        $expected = $resolvedParent === false
            ? ''
            : rtrim($resolvedParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
        if ($expected === '' || !$this->sameCanonicalPath($resolved, $expected)) {
            throw new RuntimeException('canonical_investigation_draft_link_rejected');
        }
    }

    private function canonicalizeFromExistingAncestor(string $path): string
    {
        $missingSegments = [];
        $current = $path;
        while (@lstat($current) === false) {
            $missingSegments[] = basename($current);
            $parent = $this->normalizeAbsolutePath(dirname($current));
            if ($parent === '' || $parent === $current) {
                throw new RuntimeException('canonical_investigation_draft_storage_ancestor_missing');
            }
            $current = $parent;
        }
        $probe = $current;
        while (true) {
            if (is_link($probe)) {
                throw new RuntimeException('canonical_investigation_draft_link_rejected');
            }
            $parent = $this->normalizeAbsolutePath(dirname($probe));
            if ($parent === '' || $parent === $probe) {
                break;
            }
            $probe = $parent;
        }
        $this->assertPathNotLinkOrJunction($current);
        $resolved = realpath($current);
        if ($resolved === false) {
            throw new RuntimeException('canonical_investigation_draft_storage_ancestor_invalid');
        }
        foreach (array_reverse($missingSegments) as $segment) {
            $resolved = rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
        }
        return $this->normalizeAbsolutePath($resolved);
    }

    private function sameCanonicalPath(string $left, string $right): bool
    {
        $left = $this->normalizeAbsolutePath($left);
        $right = $this->normalizeAbsolutePath($right);
        if (DIRECTORY_SEPARATOR === '\\') {
            $left = strtolower($left);
            $right = strtolower($right);
        }
        return $left === $right;
    }

    private function assertResolvedPathWithinStorageRoot(string $path): void
    {
        $this->assertExistingPathChainSafe($this->storageRoot);
        $this->assertExistingPathChainSafe($path);
        $root = realpath($this->storageRoot);
        $resolved = realpath($path);
        if ($root === false || $resolved === false || !$this->pathWithinRoot($resolved, $root)) {
            throw new RuntimeException('canonical_investigation_draft_resolved_path_scope_invalid');
        }
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function normalizeScope(array $scope): array
    {
        $required = [
            'tenant_id',
            'hotel_id',
            'data_source_id',
            'task_id',
            'row_id',
            'platform',
            'target_date',
            'data_period',
        ];
        foreach ($required as $field) {
            if (!array_key_exists($field, $scope)) {
                throw new \InvalidArgumentException('canonical_scope_field_missing:' . $field);
            }
        }

        $normalized = [
            'tenant_id' => $this->positiveInteger($scope['tenant_id'], 'tenant_id'),
            'hotel_id' => $this->positiveInteger($scope['hotel_id'], 'hotel_id'),
            'data_source_id' => $this->positiveInteger($scope['data_source_id'], 'data_source_id'),
            'task_id' => $this->positiveInteger($scope['task_id'], 'task_id'),
            'row_id' => $this->positiveInteger($scope['row_id'], 'row_id'),
            'platform' => strtolower(trim((string)$scope['platform'])),
            'target_date' => trim((string)$scope['target_date']),
            'data_period' => strtolower(trim((string)$scope['data_period'])),
        ];
        if ($normalized['platform'] !== 'ctrip') {
            throw new \InvalidArgumentException('canonical_scope_platform_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized['target_date']);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $normalized['target_date']) {
            throw new \InvalidArgumentException('canonical_scope_target_date_invalid');
        }
        if (preg_match('/^[a-z0-9_]{1,40}$/D', $normalized['data_period']) !== 1) {
            throw new \InvalidArgumentException('canonical_scope_data_period_invalid');
        }
        return $normalized;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new \InvalidArgumentException('canonical_scope_positive_integer_required:' . $field);
        }
        return (int)$validated;
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(mixed $value, string $error): array
    {
        if (is_array($value)) {
            return $value;
        }
        try {
            $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($error);
        }
        return $decoded;
    }

    /** @return array<int,int> */
    private function positiveIds(mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($value) ? $value : []
        ), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array<int,string> */
    private function normalizedStringSet(mixed $value): array
    {
        $items = array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => strtolower(trim((string)$item)),
            is_array($value) ? $value : []
        ), static fn(string $item): bool => $item !== '')));
        sort($items, SORT_STRING);
        return $items;
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z]:\//', $path) !== 1 && !str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                return '';
            }
            $path = str_replace('\\', '/', $cwd) . '/' . $path;
        }

        $prefix = '/';
        if (preg_match('/^([A-Za-z]:)\//', $path, $matches) === 1) {
            $prefix = strtoupper($matches[1]) . '/';
            $path = substr($path, 3);
        } else {
            $path = ltrim($path, '/');
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        if ($segments === []) {
            return str_replace('/', DIRECTORY_SEPARATOR, $prefix);
        }
        $normalized = $prefix . implode('/', $segments);
        return str_replace('/', DIRECTORY_SEPARATOR, rtrim($normalized, '/'));
    }

    private function pathWithinRoot(string $path, string $root): bool
    {
        $path = $this->normalizeAbsolutePath($path);
        $root = $this->normalizeAbsolutePath($root);
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function isFilesystemRoot(string $path): bool
    {
        $path = str_replace('\\', '/', rtrim($path, '/'));
        return $path === '' || $path === '/' || preg_match('/^[A-Za-z]:$/D', $path) === 1;
    }
}
