<?php
declare(strict_types=1);

use app\service\OperatingQuestionAiAnswerService;
use app\service\OperatingQuestionExecutionBridgeService;
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
    'business-date:',
    'question:',
    'user-id::',
    'confirm-persist:',
]);
$tenantId = (int)($options['tenant-id'] ?? 0);
$hotelId = (int)($options['hotel-id'] ?? 0);
$platform = strtolower(trim((string)($options['platform'] ?? '')));
$businessDate = trim((string)($options['business-date'] ?? ''));
$question = trim((string)($options['question'] ?? ''));
$userId = max(0, (int)($options['user-id'] ?? 0));
$confirmed = in_array(strtolower(trim((string)($options['confirm-persist'] ?? ''))), [
    '1', 'true', 'yes',
], true);

if (!$confirmed) {
    fwrite(STDERR, "Refusing to persist: pass --confirm-persist=1 after fixing the exact hotel/date scope.\n");
    exit(2);
}
if ($tenantId <= 0 || $hotelId <= 0 || $platform === '' || $businessDate === '' || $question === '') {
    fwrite(STDERR, "tenant-id, hotel-id, platform, business-date and question are required.\n");
    exit(2);
}

$ai = new OperatingQuestionAiAnswerService();
$questionService = new OperatingQuestionService(
    null,
    static fn(array $payload): array => $ai->generate($payload)
);
$bridge = new OperatingQuestionExecutionBridgeService($questionService);

try {
    $saved = $questionService->create(
        $tenantId,
        $hotelId,
        $question,
        $platform,
        $businessDate,
        $businessDate,
        $userId,
        OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY
    );
    $questionRow = is_array($saved['question'] ?? null) ? $saved['question'] : [];
    $questionId = (int)($questionRow['id'] ?? 0);
    if (($saved['created'] ?? false) !== true || $questionId <= 0) {
        throw new RuntimeException('Fresh operating question was not persisted.');
    }

    $readback = $questionService->read($questionId, $tenantId, [$hotelId]);
    if (!hash_equals((string)($questionRow['content_digest'] ?? ''), (string)($readback['content_digest'] ?? ''))
        || (string)($questionRow['answer_summary'] ?? '') !== (string)($readback['answer_summary'] ?? '')
    ) {
        throw new RuntimeException('Question ID readback content or summary mismatch.');
    }

    $runtime = is_array($readback['answer']['ai_runtime'] ?? null)
        ? $readback['answer']['ai_runtime']
        : [];
    if ((string)($readback['answer_status'] ?? '') !== 'answered_by_grounded_ai'
        || !OperatingQuestionAiAnswerService::directCallProofReady($runtime)
        || !OperatingQuestionAiAnswerService::directCallReceiptFreshNow($runtime)
        || (string)($runtime['external_llm_call_status'] ?? '') !== OperatingQuestionAiAnswerService::DIRECT_CALL_STATUS
    ) {
        throw new RuntimeException('Fresh direct DeepSeek V4 Pro answer proof was not accepted.');
    }

    $actions = is_array($readback['answer']['action_drafts'] ?? null)
        ? array_values(array_filter($readback['answer']['action_drafts'], 'is_array'))
        : [];
    if ($actions === []) {
        throw new RuntimeException('The accepted answer did not produce an eligible action draft.');
    }

    $bridgeResult = $bridge->createIntent($questionId, 0, $tenantId, [$hotelId], $userId);
    $intent = is_array($bridgeResult['execution_intent'] ?? null)
        ? $bridgeResult['execution_intent']
        : [];
    $currentAction = $bridge->assertIntentCurrent($intent);
    $intentBoundaries = is_array($intent['evidence']['boundaries'] ?? null)
        ? $intent['evidence']['boundaries']
        : [];
    if ((string)($intent['status'] ?? '') !== 'pending_approval'
        || ($intent['target_value']['auto_write_ota'] ?? true) !== false
        || ($intent['evidence']['automatic_execution'] ?? true) !== false
        || ($intent['evidence']['automatic_ota_write'] ?? true) !== false
        || ($intent['evidence']['external_message'] ?? true) !== false
        || ($intentBoundaries['ota_write'] ?? true) !== false
        || ($intentBoundaries['external_message'] ?? true) !== false
    ) {
        throw new RuntimeException('Action intent escaped the pending-approval/no-external-write boundary.');
    }

    $registry = Db::name('hotel_operating_question_model_responses')
        ->where('question_id', $questionId)
        ->find();
    if (!is_array($registry)
        || (string)($registry['provider_response_id'] ?? '') !== (string)($runtime['provider_response_id'] ?? '')
    ) {
        throw new RuntimeException('Provider response registry readback mismatch.');
    }

    echo json_encode([
        'scope' => [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'business_date' => $businessDate,
            'source_scope' => 'ota_channel',
        ],
        'question' => [
            'id' => $questionId,
            'created' => true,
            'persistence_status' => (string)($saved['persistence_status'] ?? ''),
            'answer_status' => (string)($readback['answer_status'] ?? ''),
            'answer_summary' => (string)($readback['answer_summary'] ?? ''),
            'content_digest' => (string)($readback['content_digest'] ?? ''),
            'fact_refs' => array_values((array)($readback['fact_refs'] ?? [])),
            'missing_information' => array_values((array)($readback['answer']['missing_information'] ?? [])),
            'action_draft_count' => count($actions),
        ],
        'model_receipt' => [
            'provider' => (string)($runtime['provider'] ?? ''),
            'model_key' => (string)($runtime['model_key'] ?? ''),
            'configured_model' => (string)($runtime['configured_model'] ?? ''),
            'response_model' => (string)($runtime['response_model'] ?? ''),
            'provider_response_id' => (string)($runtime['provider_response_id'] ?? ''),
            'provider_created_at' => (int)($runtime['provider_created_at'] ?? 0),
            'provider_endpoint_origin' => (string)($runtime['provider_endpoint_origin'] ?? ''),
            'http_status' => (int)($runtime['http_status'] ?? 0),
            'provider_attempt_count' => (int)($runtime['provider_attempt_count'] ?? 0),
            'transport_retry_attempts' => (int)($runtime['transport_retry_attempts'] ?? -1),
            'finish_reason' => (string)($runtime['finish_reason'] ?? ''),
            'thinking_mode' => (string)($runtime['thinking_mode'] ?? ''),
            'reasoning_effort' => (string)($runtime['reasoning_effort'] ?? ''),
            'fallback_used' => (bool)($runtime['fallback_used'] ?? true),
            'cache_hit' => (bool)($runtime['cache_hit'] ?? true),
            'degraded' => (bool)($runtime['degraded'] ?? true),
            'external_llm_call_status' => (string)($runtime['external_llm_call_status'] ?? ''),
        ],
        'model_response_registry' => [
            'readback_verified' => true,
            'question_id' => (int)($registry['question_id'] ?? 0),
            'provider_response_id_matches' => true,
        ],
        'action_intent' => [
            'id' => (int)($intent['id'] ?? 0),
            'status' => (string)($intent['status'] ?? ''),
            'source_question_id' => (int)($bridgeResult['source_question_id'] ?? 0),
            'expected_metric' => (string)($intent['expected_metric'] ?? ''),
            'expected_unit' => (string)($currentAction['expected_unit'] ?? ''),
            'fact_reread_current' => true,
            'automatic_execution' => false,
            'automatic_ota_write' => false,
            'external_message' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
