<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

/** Immutable advisory documents, outside live OTA/PMS and price_suggestions tables. */
final class RevenueForecastWorkbenchService
{
    public function __construct(private ?string $root = null)
    {
    }

    public function storageRoot(): string
    {
        return $this->root ??= $this->defaultStorageRoot();
    }

    private function defaultStorageRoot(): string
    {
        $configured = getenv('SUXIOS_FORECAST_PLAN_PATH');
        $configured = trim((string)($configured === false ? env('SUXIOS_FORECAST_PLAN_PATH', '') : $configured));
        $project = str_replace('\\', '/', rtrim(root_path(), '/\\'));
        $policy = LocalStatePathPolicy::resolve();
        $persistentRequired = $policy['persistent_paths_required'] || preg_match('~/releases/[^/]+$~i', $project);
        if ($configured === '') {
            if ($persistentRequired) throw new RuntimeException('方案持久化目录未配置；请将 SUXIOS_FORECAST_PLAN_PATH 配置到发布目录之外。');
            return runtime_path() . 'revenue-forecast-workbench';
        }
        $normalized = str_replace('\\', '/', rtrim($configured, '/\\'));
        if (str_contains($configured, "\0") || !preg_match('~^(?:/|[A-Za-z]:/)~', $normalized)
            || $normalized === '' || preg_match('~^(?:/|[A-Za-z]:)$~', $normalized)
            || preg_match('~/(?:\.{1,2}|releases|current)(?:/|$)~i', $normalized)) {
            throw new RuntimeException('方案持久化目录必须是发布目录之外的绝对路径。');
        }
        // Resolve an existing ancestor too, so an external-looking symlink cannot point into this release.
        $ancestor = $configured;
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
        foreach (array_filter([$policy['cache_path'], $policy['lock_path']]) as $statePath) {
            $state = strtolower(str_replace('\\', '/', rtrim((string)(realpath($statePath) ?: $statePath), '/\\'))) . '/';
            $plan = strtolower($normalized) . '/';
            $resolvedAncestor = strtolower(str_replace('\\', '/', (string)realpath($ancestor))) . '/';
            if (str_starts_with($plan, $state) || str_starts_with($state, $plan) || str_starts_with($resolvedAncestor, $state)) throw new RuntimeException('方案持久化目录必须与缓存及锁目录分离。');
        }
        foreach ([$normalized, str_replace('\\', '/', (string)realpath($ancestor))] as $candidate) {
            $base = strtolower(str_replace('\\', '/', (string)(realpath($project) ?: $project)));
            if (strtolower($candidate) === $base || str_starts_with(strtolower($candidate), $base . '/')
                || preg_match('~/(?:releases|current)(?:/|$)~i', $candidate)) {
                throw new RuntimeException('方案持久化目录不得位于应用或发布目录内。');
            }
        }
        return $configured;
    }

    public function preview(array $input, array $scope): array
    {
        $this->assertInputContract($input);
        $replay = (new TemporalForecastTrialService())->replayEvidence($input['evidence'] ?? [], $scope);
        return ['replay' => $replay,
            'scenario' => isset($input['scenario']) ? (new RevenuePricingRecommendationService())->simulateForecastScenario($replay, $input['scenario']) : null];
    }

    public function save(array $input, array $scope): array
    {
        $result = $this->preview($input, $scope);
        $payload = ['schema_version' => 'revenue_forecast_document.v1', 'scope' => $scope, 'input' => $input, 'result' => $result];
        $json = $this->json($payload);
        $id = hash('sha256', $json);
        $dir = $this->directory($scope);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('方案存储目录不可用。');
        $lock = @fopen($dir . '/write.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('方案保存锁不可用，请重试。');
        try {
            $path = $dir . '/' . $id . '.json';
            $replay = is_file($path);
            if (!$replay) {
                $document = ['id' => $id, 'created_at' => gmdate(DATE_ATOM), 'payload' => $payload];
                $envelope = $this->json($document + ['envelope_sha256' => hash('sha256', $this->json($document))]);
                $temporary = $dir . '/' . $id . '.' . bin2hex(random_bytes(4)) . '.tmp';
                try {
                    if (file_put_contents($temporary, $envelope, LOCK_EX) !== strlen($envelope) || !rename($temporary, $path)) {
                        throw new RuntimeException('方案保存失败。');
                    }
                } finally { if (is_file($temporary)) @unlink($temporary); }
            }
            $readback = $this->read($id, $scope);
            if ($this->json($readback['payload']) !== $json) throw new RuntimeException('方案精确回读不一致。');
            return $readback + ['idempotent_replay' => $replay];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function read(string $id, array $scope): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new InvalidArgumentException('方案ID无效。');
        $path = $this->directory($scope) . '/' . $id . '.json';
        if (!is_file($path)) throw new InvalidArgumentException('当前范围内找不到该方案。');
        try { $doc = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new RuntimeException('方案已损坏，未通过回读校验。'); }
        $keys = is_array($doc) ? array_keys($doc) : []; sort($keys);
        if ($keys !== ['created_at', 'envelope_sha256', 'id', 'payload']) throw new RuntimeException('方案封装版本不支持或含额外字段；保留原文件，请重新导入证据保存。');
        $created = is_string($doc['created_at']) ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $doc['created_at']) : false;
        $envelopeDigest = $doc['envelope_sha256'];
        $envelope = $doc; unset($envelope['envelope_sha256']);
        if (!$created || $created->format(DATE_ATOM) !== $doc['created_at'] || !is_string($envelopeDigest)
            || !hash_equals(hash('sha256', $this->json($envelope)), $envelopeDigest)) throw new RuntimeException('方案封装元数据校验失败。');
        $payload = $doc['payload'] ?? null;
        if (!is_array($payload) || ($payload['schema_version'] ?? '') !== 'revenue_forecast_document.v1') {
            throw new RuntimeException('方案版本不支持；保留原文件，不升级为已验证方案。');
        }
        if (($doc['id'] ?? '') !== $id || $this->json($payload['scope'] ?? []) !== $this->json($scope)
            || !hash_equals($id, hash('sha256', $this->json($payload)))) {
            throw new RuntimeException('方案身份或内容校验失败。');
        }
        $this->assertInputContract($payload['input'] ?? []);
        return array_replace($doc, ['readback_verified' => true, 'automatic_price_write' => false, 'causality_claimed' => false]);
    }

    public function history(array $scope, int $page = 1): array
    {
        return $this->historyPage($scope, $page)['items'];
    }

    public function historyPage(array $scope, int $page = 1): array
    {
        if ($page < 1) throw new InvalidArgumentException('历史页码须为正整数。');
        $dir = $this->directory($scope);
        $ancestor = $dir;
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
        if (!is_dir($ancestor) || !is_readable($ancestor)) throw new RuntimeException('历史存储不可读取。');
        $paths = is_dir($dir) ? glob($dir . '/*.json') : [];
        if ($paths === false) throw new RuntimeException('历史文件列表读取失败。');
        usort($paths, static fn($a, $b) => (filemtime($b) <=> filemtime($a)) ?: strcmp($b, $a));
        $total = count($paths);
        $totalPages = max(1, (int)ceil($total / 50));
        if ($page > $totalPages) throw new InvalidArgumentException('历史页码超出范围，请重新读取第一页。');
        $out = [];
        foreach (array_slice($paths, ($page - 1) * 50, 50) as $path) {
            $id = basename($path, '.json');
            try {
                $doc = $this->read($id, $scope);
                $out[] = ['id' => $id, 'status' => 'readback_verified', 'created_at' => $doc['created_at'],
                    'as_of_at' => $doc['payload']['result']['replay']['as_of_at'],
                    'source_kind' => $doc['payload']['result']['replay']['source_kind']];
            } catch (\Throwable) { $out[] = ['id' => $id, 'status' => 'unverified_or_unsupported']; }
        }
        return ['items' => $out, 'page' => $page, 'page_size' => 50, 'total' => $total, 'total_pages' => $totalPages];
    }

    /** Only the declared contract may enter storage or leave legacy storage. Never echo rejected values. */
    private function assertInputContract(array $input): void
    {
        $check = static function (array $object, array $allowed, array $containers = []): void {
            if (array_diff(array_keys($object), $allowed) !== []) {
                throw new InvalidArgumentException('导入内容含未声明字段；请仅保留证据和情景约定字段，不得包含凭证。');
            }
            foreach ($object as $key => $value) {
                if (!in_array($key, $containers, true) && !is_scalar($value) && $value !== null) {
                    throw new InvalidArgumentException('导入字段不允许嵌套内容；不得包含凭证。');
                }
            }
        };
        $check($input, ['evidence', 'scenario'], ['evidence', 'scenario']);
        if (!is_array($input['evidence'] ?? null) || (array_key_exists('scenario', $input) && !is_array($input['scenario']))) {
            throw new InvalidArgumentException('证据和情景须为对象。');
        }
        $evidence = $input['evidence'];
        $check($evidence, ['schema_version', 'source_kind', 'metric_definition', 'date_basis', 'unit', 'as_of_at', 'evaluation_at', 'backtest_start', 'backtest_end', 'observations'], ['observations']);
        if (!is_array($evidence['observations'] ?? null) || !array_is_list($evidence['observations']) || count($evidence['observations']) > 3000) {
            throw new InvalidArgumentException('observations 必须为最多3000条历史版本的列表。');
        }
        $scopeKeys = array_flip(['tenant_id', 'hotel_id', 'platform', 'platform_store_id', 'room_scope']);
        $scopeValidator = new TemporalForecastReplayService();
        foreach ($evidence['observations'] as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('历史版本格式错误。');
            $check($row, ['tenant_id', 'hotel_id', 'platform', 'platform_store_id', 'room_scope', 'business_date', 'available_at', 'quality_status', 'value', 'source_ref']);
            $scopeValidator->scope(array_intersect_key($row, $scopeKeys));
            TemporalForecastReplayService::assertSourceReference($row['source_ref'] ?? null, $evidence['source_kind'] ?? null);
        }
        if (isset($input['scenario'])) $check($input['scenario'], ['horizon_days', 'current_price', 'proposed_price', 'elasticity', 'inventory_room_nights', 'inventory_scope', 'price_unit']);
    }

    private function directory(array $scope): string
    {
        (new TemporalForecastReplayService())->scope($scope);
        return $this->storageRoot() . '/' . hash('sha256', $this->json($scope));
    }

    private function json(mixed $value): string
    {
        $normalize = function ($item) use (&$normalize) {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
