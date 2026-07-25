<?php
declare(strict_types=1);

namespace app\service\platform;

use app\contract\DataSourceAdapter;

/**
 * Accepts already-normalized, credential-free facts uploaded by an authorized
 * account owner's local collector. This adapter never opens a browser and
 * never asks the server credential vault for an OTA session.
 */
final class LocalCollectorDataSourceAdapter implements DataSourceAdapter
{
    public function supports(array $source): bool
    {
        return strtolower(trim((string)($source['ingestion_method'] ?? ''))) === 'local_collector';
    }

    public function fetch(array $source, array $options = []): array
    {
        if (($options['local_collector_verified'] ?? false) !== true) {
            return [
                'status' => 'permission_denied',
                'status_code' => 'local_collector_proof_missing',
                'error_code' => 'local_collector_proof_missing',
                'message' => '本机采集器任务证明缺失，服务器拒绝接收结果。',
                'payload' => [],
            ];
        }

        $payload = $options['payload'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($payload)) {
            return [
                'status' => 'failed',
                'status_code' => 'local_collector_payload_missing',
                'error_code' => 'local_collector_payload_missing',
                'message' => '本机采集器没有上传可验证的业务结果。',
                'payload' => [],
            ];
        }

        $rows = is_array($payload['rows'] ?? null) ? array_values($payload['rows']) : [];
        if ($rows === []) {
            return [
                'status' => 'failed',
                'status_code' => 'zero_rows',
                'error_code' => 'zero_rows',
                'message' => '本机采集器未返回业务行，未写入空数据。',
                'payload' => $payload,
            ];
        }

        $payload['source_method'] = 'local_account_profile';
        $payload['collection_mode'] = 'local_collector';
        return [
            'status' => 'success',
            'message' => '本机采集器结果已通过设备任务边界。',
            'payload' => $payload,
        ];
    }
}
