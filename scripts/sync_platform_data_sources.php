<?php
declare(strict_types=1);

use app\service\PlatformDataSyncService;
use think\App;
use think\facade\Db;

require __DIR__ . '/../vendor/autoload.php';

$app = new App();
$app->initialize();

$options = [
    'limit' => 20,
    'source_id' => 0,
];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $options['limit'] = max(1, min(200, (int)substr($arg, 8)));
    }
    if (str_starts_with($arg, '--source-id=')) {
        $options['source_id'] = max(0, (int)substr($arg, 12));
    }
}

$systemUser = new class {
    public int $id = 1;

    public function isSuperAdmin(): bool
    {
        return true;
    }
};

$query = Db::name('platform_data_sources')
    ->where('enabled', 1)
    ->where('status', '<>', 'disabled')
    ->order('last_sync_time', 'asc')
    ->order('id', 'asc')
    ->limit($options['limit']);

if ($options['source_id'] > 0) {
    $query->where('id', $options['source_id']);
}

$sources = $query->select()->toArray();
$service = new PlatformDataSyncService();
$summary = [
    'checked' => count($sources),
    'synced' => 0,
    'skipped' => 0,
    'failed' => 0,
    'results' => [],
];

foreach ($sources as $source) {
    $config = json_decode((string)($source['config_json'] ?? ''), true);
    $method = (string)($source['ingestion_method'] ?? '');
    if (in_array($method, ['manual', 'import_json', 'import_csv', 'import_excel'], true)) {
        $hasImportPayload = is_array($config) && (isset($config['payload']) || isset($config['sample_payload']));
        if (!$hasImportPayload) {
            $summary['skipped']++;
            $summary['results'][] = [
                'data_source_id' => (int)$source['id'],
                'status' => 'waiting_config',
                'message' => 'Import source has no scheduled payload; use manual upload or configure payload.',
            ];
            continue;
        }
    }

    $result = $service->syncDataSource($systemUser, (int)$source['id'], ['trigger_type' => 'cron']);
    $summary['results'][] = $result;
    if (($result['status'] ?? '') === 'success') {
        $summary['synced']++;
    } elseif (($result['status'] ?? '') === 'waiting_config') {
        $summary['skipped']++;
    } else {
        $summary['failed']++;
    }
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($summary['failed'] > 0 ? 1 : 0);
