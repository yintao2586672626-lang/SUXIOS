<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use app\service\OnlineDailyDataPersistenceService;

function revalidate_env_map(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('.env not found: ' . $path);
    }
    $map = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '[')) {
            continue;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
            $map[$matches[1]] = trim($matches[2], " \t\n\r\0\x0B\"'");
        }
    }
    return $map;
}

function revalidate_env_value(array $env, array $names, string $default = ''): string
{
    foreach ($names as $name) {
        if (isset($env[$name]) && $env[$name] !== '') {
            return (string)$env[$name];
        }
    }
    return $default;
}

try {
    $execute = in_array('--execute', $argv, true);
    $root = dirname(__DIR__);
    $env = revalidate_env_map($root . DIRECTORY_SEPARATOR . '.env');
    $host = revalidate_env_value($env, ['DB_HOST', 'DATABASE_HOSTNAME', 'HOSTNAME'], '127.0.0.1');
    $port = revalidate_env_value($env, ['DB_PORT', 'DATABASE_PORT', 'HOSTPORT'], '3306');
    $database = revalidate_env_value($env, ['DB_NAME', 'DB_DATABASE', 'DATABASE']);
    $user = revalidate_env_value($env, ['DB_USER', 'DB_USERNAME', 'USERNAME'], 'root');
    $password = revalidate_env_value($env, ['DB_PASS', 'DB_PASSWORD', 'PASSWORD']);
    if ($database === '') {
        throw new RuntimeException('DB_NAME is empty');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $fields = [
        'id', 'source', 'hotel_id', 'data_date', 'data_type', 'system_hotel_id',
        'amount', 'quantity', 'book_order_num', 'data_value',
        'comment_score', 'qunar_comment_score',
        'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
        'validation_status', 'validation_flags',
    ];
    $rows = $pdo->query('SELECT `' . implode('`,`', $fields) . '` FROM `online_daily_data` ORDER BY `id`')->fetchAll();
    $update = $pdo->prepare('UPDATE `online_daily_data` SET `validation_status` = :status, `validation_flags` = :flags, `update_time` = `update_time` WHERE `id` = :id');
    $scanned = 0;
    $changed = 0;
    $statusCounts = [];
    $changedStatusCounts = [];

    if ($execute) {
        $pdo->beginTransaction();
    }

    foreach ($rows as $row) {
        $scanned++;
        $next = OnlineDailyDataPersistenceService::buildValidationFields($row);
        $status = (string)$next['validation_status'];
        $flags = (string)$next['validation_flags'];
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        if ((string)($row['validation_status'] ?? '') === $status && (string)($row['validation_flags'] ?? '') === $flags) {
            continue;
        }
        $changed++;
        $changedStatusCounts[$status] = ($changedStatusCounts[$status] ?? 0) + 1;
        if ($execute) {
            $update->execute([
                ':status' => $status,
                ':flags' => $flags,
                ':id' => (int)$row['id'],
            ]);
        }
    }

    if ($execute) {
        $pdo->commit();
    }

    echo 'online_daily_data revalidation ' . ($execute ? 'executed' : 'dry-run') . PHP_EOL;
    echo 'scanned=' . $scanned . ' changed=' . $changed . PHP_EOL;
    echo 'status_counts=' . json_encode($statusCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo 'changed_status_counts=' . json_encode($changedStatusCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'online_daily_data revalidation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
