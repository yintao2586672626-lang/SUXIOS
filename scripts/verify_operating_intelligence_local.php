<?php
declare(strict_types=1);

use app\service\OperatingQuestionService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App(dirname(__DIR__));
$app->initialize();

$options = getopt('', [
    'tenant-id:',
    'hotel-id:',
    'platform:',
    'date-start:',
    'date-end:',
    'question:',
    'persist-question::',
]);
$tenantId = (int)($options['tenant-id'] ?? 0);
$hotelId = (int)($options['hotel-id'] ?? 0);
$platform = trim((string)($options['platform'] ?? ''));
$dateStart = trim((string)($options['date-start'] ?? ''));
$dateEnd = trim((string)($options['date-end'] ?? ''));
$question = trim((string)($options['question'] ?? ''));
$persist = in_array(strtolower((string)($options['persist-question'] ?? '0')), ['1', 'true', 'yes'], true);

if ($tenantId <= 0 || $hotelId <= 0 || $platform === '' || $dateStart === '' || $dateEnd === '' || $question === '') {
    fwrite(STDERR, "tenant-id, hotel-id, platform, date-start, date-end and question are required.\n");
    exit(2);
}

$service = new OperatingQuestionService();
$transactionOpen = false;
try {
    if (!$persist) {
        Db::startTrans();
        $transactionOpen = true;
    }
    $saved = $service->create(
        $tenantId,
        $hotelId,
        $question,
        $platform,
        $dateStart,
        $dateEnd,
        0
    );
    $row = $saved['question'];
    $readback = $service->read((int)$row['id'], $tenantId, [$hotelId]);
    if ((string)$readback['content_digest'] !== (string)$row['content_digest']) {
        throw new RuntimeException('local verifier exact readback digest mismatch');
    }
    if ($transactionOpen) {
        Db::rollback();
        $transactionOpen = false;
    }

    $failureCheck = null;
    Db::startTrans();
    $transactionOpen = true;
    try {
        $missing = $service->create(
            $tenantId,
            $hotelId,
            '验证缺事实时是否保持明确阻塞状态',
            $platform,
            '2099-12-31',
            '2099-12-31',
            0
        );
        $failureCheck = [
            'answer_status' => $missing['question']['answer_status'],
            'data_gap_code' => $missing['question']['data_gaps'][0]['code'] ?? null,
            'persistence_status' => $missing['persistence_status'],
        ];
    } finally {
        Db::rollback();
        $transactionOpen = false;
    }

    echo json_encode([
        'database_scope' => 'local_only',
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'platform' => $platform,
        'date_start' => $dateStart,
        'date_end' => $dateEnd,
        'question_id' => $persist ? (int)$row['id'] : null,
        'question_persisted' => $persist,
        'persistence_status' => $saved['persistence_status'],
        'answer_status' => $row['answer_status'],
        'answer_summary' => $row['answer_summary'],
        'fact_count' => (int)($row['answer']['evidence_counts']['facts'] ?? 0),
        'fact_reference_count' => count($row['fact_refs'] ?? []),
        'memory_reference_count' => count($row['memory_refs'] ?? []),
        'knowledge_reference_count' => count($row['knowledge_refs'] ?? []),
        'execution_reference_count' => count($row['execution_refs'] ?? []),
        'failure_state_check' => $failureCheck,
        'write_boundaries' => $saved['write_boundaries'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($transactionOpen) {
        Db::rollback();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
