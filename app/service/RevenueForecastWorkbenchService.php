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
        $this->root ??= runtime_path() . 'revenue-forecast-workbench';
    }

    public function preview(array $input, array $scope): array
    {
        if (!is_array($input['evidence'] ?? null) || (isset($input['scenario']) && !is_array($input['scenario']))) {
            throw new InvalidArgumentException('证据和情景须为对象。');
        }
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
                $envelope = $this->json(['id' => $id, 'created_at' => gmdate(DATE_ATOM), 'payload' => $payload]);
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
        $payload = $doc['payload'] ?? null;
        if (!is_array($payload) || ($payload['schema_version'] ?? '') !== 'revenue_forecast_document.v1') {
            throw new RuntimeException('方案版本不支持；保留原文件，不升级为已验证方案。');
        }
        if (($doc['id'] ?? '') !== $id || $this->json($payload['scope'] ?? []) !== $this->json($scope)
            || !hash_equals($id, hash('sha256', $this->json($payload)))) {
            throw new RuntimeException('方案身份或内容校验失败。');
        }
        return $doc + ['readback_verified' => true, 'automatic_price_write' => false, 'causality_claimed' => false];
    }

    public function history(array $scope): array
    {
        $dir = $this->directory($scope);
        if (!is_dir($dir)) return [];
        $paths = glob($dir . '/*.json') ?: [];
        usort($paths, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        $out = [];
        foreach (array_slice($paths, 0, 50) as $path) {
            $id = basename($path, '.json');
            try {
                $doc = $this->read($id, $scope);
                $out[] = ['id' => $id, 'status' => 'readback_verified', 'created_at' => $doc['created_at'],
                    'as_of_at' => $doc['payload']['result']['replay']['as_of_at'],
                    'source_kind' => $doc['payload']['result']['replay']['source_kind']];
            } catch (\Throwable) { $out[] = ['id' => $id, 'status' => 'unverified_or_unsupported']; }
        }
        return $out;
    }

    private function directory(array $scope): string
    {
        (new TemporalForecastReplayService())->scope($scope);
        return $this->root . '/' . hash('sha256', $this->json($scope));
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
