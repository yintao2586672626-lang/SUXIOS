<?php
declare(strict_types=1);

// Standalone local MariaDB probe. Only creates and drops its own uniquely named test database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$database = 'investment_payback_test_' . bin2hex(random_bytes(8));
if (!preg_match('/^investment_payback_test_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Invalid isolated test database');
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', $options);
$created = false;
try {
    $server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    $created = true;
    $dsn = "mysql:host=127.0.0.1;port=3306;dbname=$database;charset=utf8mb4";
    $first = new PDO($dsn, 'root', '', $options);
    $second = new PDO($dsn, 'root', '', $options);
    $first->exec('CREATE TABLE projects (id INT PRIMARY KEY) ENGINE=InnoDB');
    $first->exec('CREATE TABLE entries (id INT PRIMARY KEY, project_id INT NOT NULL, amount DECIMAL(16,2) NOT NULL, INDEX(project_id)) ENGINE=InnoDB');
    $first->exec('INSERT INTO projects (id) VALUES (1)');
    foreach ([$first, $second] as $connection) {
        $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    // The later importer establishes its old snapshot before the first importer commits.
    $second->beginTransaction();
    $second->query('SELECT id FROM projects WHERE id=1')->fetchAll();
    $first->beginTransaction();
    $first->query('SELECT id FROM projects WHERE id=1 FOR UPDATE')->fetchAll();
    $first->exec('INSERT INTO entries (id, project_id, amount) VALUES (1, 1, 123.45)');
    $first->commit();
    // Locking the project does not refresh a later ordinary SELECT's snapshot.
    $second->query('SELECT id FROM projects WHERE id=1 FOR UPDATE')->fetchAll();
    $snapshotCount = count($second->query('SELECT id FROM entries WHERE project_id=1 AND amount=123.45')->fetchAll());
    $currentCount = count($second->query('SELECT id FROM entries WHERE project_id=1 AND amount=123.45 FOR UPDATE')->fetchAll());
    $second->rollBack();
    if ($snapshotCount !== 0 || $currentCount !== 1) {
        throw new RuntimeException('Current-read isolation assertion failed');
    }
    echo json_encode(['synthetic' => true, 'repeatable_read_old_snapshot_count' => $snapshotCount,
        'for_update_current_count' => $currentCount, 'passed' => true], JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    foreach ([$first ?? null, $second ?? null] as $connection) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    $first = $second = null;
    if ($created) {
        $server->exec("DROP DATABASE `$database`");
    }
}
