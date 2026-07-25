<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Maintains the legacy Ctrip metric-fact table as a query projection of the
 * already verified online_daily_data row.  The daily row and its field_facts
 * remain the sole source of truth; this table never creates business facts.
 */
final class CtripMetricFactProjectionService
{
    /** @param array<int,array<string,mixed>> $dailyRows @return array{projected:int,skipped:int,available:bool} */
    public function project(array $dailyRows): array
    {
        if (!$this->available()) {
            return ['projected' => 0, 'skipped' => count($dailyRows), 'available' => false];
        }

        $projected = 0;
        $skipped = 0;
        foreach ($dailyRows as $daily) {
            if (strtolower(trim((string)($daily['source'] ?? $daily['platform'] ?? ''))) !== 'ctrip') {
                $skipped++;
                continue;
            }
            $raw = $this->decode($daily['raw_data'] ?? null);
            $facts = is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [];
            foreach ($facts as $fact) {
                if (!is_array($fact)
                    || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                    || ($fact['stored_value_present'] ?? false) !== true) {
                    continue;
                }
                $metric = trim((string)($fact['metric_key'] ?? ''));
                $sourcePath = trim((string)($fact['source_path'] ?? ''));
                if ($metric === '' || $sourcePath === '') {
                    $skipped++;
                    continue;
                }
                $value = $this->storedValue($daily, (string)($fact['storage_field'] ?? ''));
                if ($value === null) {
                    $skipped++;
                    continue;
                }
                $sourceHash = hash('sha256', implode('|', [
                    (string)($daily['id'] ?? ''), $metric, $sourcePath,
                    (string)($daily['source_trace_id'] ?? ''),
                ]));
                $row = [
                    'run_id' => max(0, (int)($daily['sync_task_id'] ?? 0)) ?: null,
                    'tenant_id' => max(0, (int)($daily['tenant_id'] ?? 0)) ?: null,
                    'system_hotel_id' => max(0, (int)($daily['system_hotel_id'] ?? 0)) ?: null,
                    'ota_hotel_id' => substr((string)($daily['hotel_id'] ?? ''), 0, 64),
                    'hotel_name' => substr((string)($daily['hotel_name'] ?? ''), 0, 160),
                    'data_date' => (string)($daily['data_date'] ?? ''),
                    'source' => 'ctrip',
                    'capture_section' => substr((string)($raw['row']['section'] ?? $raw['capture_section'] ?? ''), 0, 80),
                    'endpoint_id' => substr((string)($raw['row']['endpoint_id'] ?? $raw['endpoint_id'] ?? ''), 0, 120),
                    'metric_key' => substr($metric, 0, 120),
                    'metric_label' => substr((string)($fact['metric_label'] ?? $metric), 0, 160),
                    'category' => substr((string)($fact['data_type'] ?? $daily['data_type'] ?? ''), 0, 60),
                    'data_type' => substr((string)($fact['data_type'] ?? $daily['data_type'] ?? ''), 0, 50),
                    'metric_scope' => 'ota_channel',
                    'value_type' => is_numeric($value) ? 'number' : 'text',
                    'value_decimal' => is_numeric($value) ? (float)$value : null,
                    'value_text' => is_numeric($value) ? null : substr((string)$value, 0, 1000),
                    'source_key' => substr((string)($fact['source_key'] ?? ''), 0, 160),
                    'source_path' => substr($sourcePath, 0, 700),
                    'source_hash' => $sourceHash,
                    'raw_data' => json_encode([
                        'projection_of_online_daily_data_id' => (int)($daily['id'] ?? 0),
                        'field_fact' => $fact,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'capture_status' => 'projected_from_verified_daily_fact',
                    'captured_at' => (string)($raw['captured_at'] ?? $daily['update_time'] ?? date('Y-m-d H:i:s')),
                ];
                $existing = Db::name('ota_ctrip_metric_facts')
                    ->where('system_hotel_id', $row['system_hotel_id'])
                    ->where('data_date', $row['data_date'])
                    ->where('capture_section', $row['capture_section'])
                    ->where('endpoint_id', $row['endpoint_id'])
                    ->where('metric_key', $row['metric_key'])
                    ->where('source_hash', $sourceHash)
                    ->find();
                if (is_array($existing)) {
                    Db::name('ota_ctrip_metric_facts')->where('id', (int)$existing['id'])->update($row);
                } else {
                    Db::name('ota_ctrip_metric_facts')->insert($row);
                }
                $projected++;
            }
        }
        return ['projected' => $projected, 'skipped' => $skipped, 'available' => true];
    }

    private function available(): bool
    {
        try {
            Db::name('ota_ctrip_metric_facts')->limit(1)->select();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function storedValue(array $daily, string $storageField): mixed
    {
        $field = preg_replace('/^online_daily_data\./', '', trim($storageField)) ?? '';
        if ($field === '' || str_contains($field, '.')) return null;
        return array_key_exists($field, $daily) ? $daily[$field] : null;
    }
}
