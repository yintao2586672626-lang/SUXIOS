<?php
declare(strict_types=1);

namespace app\service\platform;

use app\contract\DataSourceAdapter;

final class ManualImportDataSourceAdapter implements DataSourceAdapter
{
    public function supports(array $source): bool
    {
        return in_array((string)($source['ingestion_method'] ?? 'manual'), ['manual', 'import_json', 'import_csv', 'import_excel'], true);
    }

    public function fetch(array $source, array $options = []): array
    {
        $payload = $options['payload'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($payload)) {
            $config = $source['config'] ?? [];
            $payload = $config['payload'] ?? $config['sample_payload'] ?? null;
        }

        if (!is_array($payload)) {
            return [
                'status' => 'waiting_config',
                'message' => 'No import payload was provided.',
                'payload' => [],
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Import payload accepted.',
            'payload' => $payload,
        ];
    }
}
