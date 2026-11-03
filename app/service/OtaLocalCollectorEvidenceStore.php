<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

/** Immutable, credential-free business inputs, outside public and log storage. */
final class OtaLocalCollectorEvidenceStore
{
    public function __construct(private readonly ?string $directory = null)
    {
    }

    public function put(array $scope, string $json, string $hash): array
    {
        if (strlen($json) > OtaLocalCollectorService::MAX_RESULT_BYTES
            || !hash_equals($hash, hash('sha256', $json))) {
            throw new RuntimeException('回传业务证据大小或指纹不匹配。', 422);
        }
        $path = $this->path($scope, $hash);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('回传业务证据目录不可写。', 503);
        }
        if (!is_file($path)) {
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
            $stream = @fopen($temporary, 'xb');
            if ($stream === false) {
                throw new RuntimeException('回传业务证据暂存失败。', 503);
            }
            try {
                @chmod($temporary, 0600);
                if (fwrite($stream, $json) !== strlen($json) || !fflush($stream)
                    || (function_exists('fsync') && !fsync($stream))) {
                    throw new RuntimeException('回传业务证据写入未完成。', 503);
                }
            } finally {
                fclose($stream);
            }
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('回传业务证据持久化失败。', 503);
            }
        }
        $this->read($scope, $hash);
        return ['status' => 'retained', 'result_hash' => $hash, 'bytes' => strlen($json), 'replayable' => true];
    }

    public function read(array $scope, string $hash): array
    {
        $path = $this->path($scope, $hash);
        $size = @filesize($path);
        if ($size === false || $size > OtaLocalCollectorService::MAX_RESULT_BYTES) {
            throw new RuntimeException('回传业务证据不存在或超过允许大小。', 404);
        }
        $json = @file_get_contents($path);
        if (!is_string($json) || !hash_equals($hash, hash('sha256', $json))) {
            throw new RuntimeException('回传业务证据指纹校验失败。', 409);
        }
        $result = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException('回传业务证据结构无效。', 422);
        }
        return $result;
    }

    private function path(array $scope, string $hash): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('回传业务证据指纹无效。', 422);
        }
        $parts = [];
        foreach (['tenant_id', 'device_id', 'task_id', 'attempt'] as $key) {
            $value = filter_var($scope[$key] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value <= 0) {
                throw new RuntimeException('回传业务证据范围缺失。', 422);
            }
            $parts[] = (string)$value;
        }
        $base = $this->directory ?? root_path() . 'storage/local_collector/evidence';
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts)
            . DIRECTORY_SEPARATOR . $hash . '.json';
    }
}
