#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\HotelManagerInterviewKnowledgeSyncService;
use think\App;
use think\facade\Config;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
$projectRoot = dirname(__DIR__);
foreach (spl_autoload_functions() ?: [] as $autoloadFunction) {
    $loader = is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null;
    if ($loader instanceof \Composer\Autoload\ClassLoader) {
        $loader->setPsr4('app\\', [$projectRoot . DIRECTORY_SEPARATOR . 'app']);
    }
}
(new App($projectRoot))->initialize();

$options = getopt('', ['source-interview:', 'source-distillation:']);
$sourcePaths = [
    'manager_interview_questions' => trim((string)($options['source-interview'] ?? '')),
    'distillation_controller_prompt' => trim((string)($options['source-distillation'] ?? '')),
];
$databasePath = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR . 'suxios-hotel-manager-interview-verify-' . getmypid() . '.sqlite';
$originalConfig = Config::get('database');

try {
    if (in_array('', $sourcePaths, true)) {
        throw new RuntimeException('hotel_manager_interview_both_sources_required');
    }
    @unlink($databasePath);
    $config = $originalConfig;
    $config['default'] = 'sqlite';
    $config['connections']['sqlite'] = [
        'type' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'fields_strict' => false,
    ];
    Config::set($config, 'database');
    Db::connect(null, true);

    Db::execute('CREATE TABLE knowledge_units (
        unit_id INTEGER PRIMARY KEY AUTOINCREMENT,
        hotel_id INTEGER NOT NULL,
        stable_key TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        source TEXT NOT NULL,
        status TEXT NOT NULL,
        description TEXT NOT NULL,
        tags TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        lifecycle_status TEXT NOT NULL,
        lifecycle_reason TEXT NOT NULL,
        known_knowns TEXT NOT NULL,
        known_unknowns TEXT NOT NULL,
        truth_profile_version TEXT NOT NULL,
        reviewed_at TEXT NOT NULL,
        review_due_at TEXT NOT NULL,
        current_chunk_id INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    Db::execute('CREATE TABLE knowledge_chunks (
        chunk_id INTEGER PRIMARY KEY AUTOINCREMENT,
        unit_id INTEGER NOT NULL,
        version_no INTEGER NOT NULL,
        lifecycle_status TEXT NOT NULL,
        content_digest TEXT NOT NULL,
        superseded_by_chunk_id INTEGER,
        published_at TEXT NOT NULL,
        retired_at TEXT,
        type TEXT NOT NULL,
        content TEXT NOT NULL,
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL
    )');

    $service = new HotelManagerInterviewKnowledgeSyncService(null, $sourcePaths);
    $first = $service->sync(true);
    $second = $service->sync(true);
    $firstReadback = (array)($first['readback'] ?? []);
    $secondReadback = (array)($second['readback'] ?? []);
    $firstIds = array_column((array)($firstReadback['chunk_readback'] ?? []), 'chunk_id');
    $secondIds = array_column((array)($secondReadback['chunk_readback'] ?? []), 'chunk_id');
    sort($firstIds);
    sort($secondIds);
    $verified = ($first['status'] ?? '') === 'success'
        && ($second['status'] ?? '') === 'success'
        && ($firstReadback['readback_verified'] ?? false) === true
        && ($secondReadback['readback_verified'] ?? false) === true
        && (int)($secondReadback['readback_active_chunk_count'] ?? 0) === 15
        && (int)($secondReadback['unsafe_chunk_count'] ?? -1) === 0
        && (int)($secondReadback['mismatch_count'] ?? -1) === 0
        && $firstIds === $secondIds
        && count($secondIds) === 15;
    if (!$verified) {
        throw new RuntimeException('hotel_manager_interview_temp_readback_verification_failed');
    }
    echo json_encode([
        'status' => 'success',
        'database_scope' => 'dedicated_temporary_sqlite_deleted_after_verification',
        'source_file_verification' => $second['source_file_verification'],
        'entry_count' => $second['entry_count'],
        'interview_question_count' => $second['interview_question_count'],
        'golden_case_count' => $second['golden_case_count'],
        'unit_id' => $secondReadback['unit_id'],
        'current_chunk_id' => $secondReadback['current_chunk_id'],
        'readback_verified' => true,
        'idempotent_chunk_ids' => true,
        'active_chunk_count' => $secondReadback['readback_active_chunk_count'],
        'unsafe_chunk_count' => $secondReadback['unsafe_chunk_count'],
        'mismatch_count' => $secondReadback['mismatch_count'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
} finally {
    try {
        Db::connect('sqlite')->close();
    } catch (Throwable) {
    }
    Config::set($originalConfig, 'database');
    @unlink($databasePath);
}
