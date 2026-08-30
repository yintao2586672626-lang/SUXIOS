<?php
declare(strict_types=1);

use app\service\AiSuggestionCalibrationService;
use app\service\UserGuidanceJourneyService;
use app\service\UserLearningMemoryService;

require dirname(__DIR__) . '/vendor/autoload.php';

$databaseName = trim((string)getenv('SUXI_E2E_DB_NAME'));
if (trim((string)getenv('SUXI_E2E_DB_OVERRIDE')) !== '1'
    || preg_match('/(?:^|[_-])e2e(?:$|[_-])/iD', $databaseName) !== 1
) {
    fwrite(STDERR, "Dedicated SUXI_E2E_DB_OVERRIDE database is required.\n");
    exit(2);
}

$app = new think\App(dirname(__DIR__));
$app->initialize();

$tenantId = 990001;
$userId = 990002;
$hotelId = 990003;
$runKey = 'system_user_learning_verifier_v1';

$memory = new UserLearningMemoryService();
$signals = [];
foreach ([1, 2, 3] as $number) {
    $signals[] = $memory->recordRepeatedSignal(
        tenantId: $tenantId,
        userId: $userId,
        scope: 'global',
        preferenceKey: 'response_detail',
        value: 'concise',
        idempotencyKey: $runKey . '_signal_' . $number,
        minimumSignals: 3,
        sourceContext: [
            'content_classification' => 'interaction_pattern',
            'source_ref' => 'ai_suggestion_feedback#' . $number,
            'surface' => 'verification',
            'reason_code' => 'too_long',
        ]
    );
}
if (($signals[0]['candidate_ready'] ?? true) !== false
    || ($signals[1]['candidate_ready'] ?? true) !== false
    || ($signals[2]['candidate_ready'] ?? false) !== true
    || ($signals[2]['preference']['consumable'] ?? true) !== false
) {
    throw new RuntimeException('Repeated signal candidate threshold verification failed.');
}
$confirmed = $memory->confirmPreference(
    tenantId: $tenantId,
    userId: $userId,
    scope: 'global',
    preferenceKey: 'response_detail',
    value: 'concise',
    idempotencyKey: $runKey . '_preference',
    sourceContext: [
        'content_classification' => 'user_preference',
        'source_ref' => 'system_user_learning_verifier',
        'surface' => 'verification',
        'reason_code' => 'explicit_user_confirmation',
    ]
);
$preferences = $memory->listPreferences(
    $tenantId,
    $userId,
    'global',
    null,
    null,
    false,
    false
);
if (($confirmed['readback']['exact_readback_verified'] ?? false) !== true
    || ($preferences['items'][0]['consumable'] ?? false) !== true
) {
    throw new RuntimeException('Preference exact readback verification failed.');
}

$journeys = new UserGuidanceJourneyService();
$journey = $journeys->save($tenantId, $userId, $hotelId, [
    'goal' => '核对数据后生成经营日报',
    'original_query' => '继续上次任务',
    'active_key' => 'data-health',
    'journey_keys' => ['data-health', 'ai-daily-report'],
    'current_step_status' => 'blocked',
    'blocker_code' => 'verified_fact_missing',
    'blocker_summary' => '等待同酒店同平台同日期的严格保存回读',
], $userId);
$journeyReadback = $journeys->readActive($tenantId, $userId, $hotelId);
$resumeCard = $journeys->readResumeCard($tenantId, $userId, $hotelId);
if (($journey['persistence_status'] ?? '') !== 'readback_verified'
    || (string)($journeyReadback['journey']['content_digest'] ?? '')
        !== (string)($journey['journey']['content_digest'] ?? '')
    || ($resumeCard['data_status'] ?? '') !== 'ready'
    || ($resumeCard['card']['readback_verified'] ?? false) !== true
) {
    throw new RuntimeException('Journey exact readback verification failed.');
}

$calibration = new AiSuggestionCalibrationService();
$snapshot = $calibration->freezeSuggestion([
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'hotel_id' => $hotelId,
    'suggestion_key' => 'verifier_suggestion',
    'scenario' => 'system_guidance_navigation',
    'source_key' => 'verification',
    'source_version' => 'v1',
    'evidence_digest' => hash('sha256', 'verified-suggestion-evidence'),
    'suggestion_payload' => ['topic_key' => 'data-health', 'assistant_mode' => 'guide'],
    'confidence' => 0.8,
    'idempotency_key' => $runKey . '_snapshot',
]);
$feedback = $calibration->appendFeedback([
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'hotel_id' => $hotelId,
    'suggestion_key' => 'verifier_suggestion',
    'feedback_status' => 'accepted',
    'reason_code' => 'useful',
    'feedback_payload' => ['surface' => 'verification'],
    'idempotency_key' => $runKey . '_feedback',
]);
$summary = $calibration->summarize([
    'tenant_id' => $tenantId,
    'user_id' => $userId,
    'hotel_id' => $hotelId,
], ['minimum_samples' => 3]);
if (($snapshot['readback_verified'] ?? false) !== true
    || ($feedback['readback_verified'] ?? false) !== true
    || ($summary['status'] ?? '') !== 'insufficient_samples'
    || ($summary['counts']['feedback_sample_count'] ?? 0) !== 1
    || ($summary['feedback_ranking']['minimum_samples_per_topic'] ?? 0) !== 20
) {
    throw new RuntimeException('Suggestion calibration verification failed.');
}

$transition = $journeys->transitionExact(
    $tenantId,
    $userId,
    $hotelId,
    (int)$resumeCard['card']['journey_id'],
    (string)$resumeCard['card']['content_digest'],
    'ignore',
    $userId
);
if (($transition['status'] ?? '') !== 'exact_readback_verified'
    || ($transition['journey']['lifecycle_status'] ?? '') !== 'archived'
    || ($transition['boundaries']['business_completion_claimed'] ?? true) !== false
    || ($journeys->readResumeCard($tenantId, $userId, $hotelId)['data_status'] ?? '') !== 'empty'
) {
    throw new RuntimeException('Resume card exact transition verification failed.');
}

echo json_encode([
    'status' => 'pass',
    'database' => $databaseName,
    'preference_readback' => 'exact_readback_verified',
    'preference_consumable' => true,
    'candidate_threshold' => $signals[2]['signal_count'] ?? null,
    'candidate_required_confirmation' => true,
    'journey_readback' => 'readback_verified',
    'journey_active_key' => $journeyReadback['journey']['active_key'] ?? null,
    'suggestion_snapshot_readback' => true,
    'feedback_readback' => true,
    'calibration_status' => $summary['status'],
    'feedback_sample_count' => $summary['counts']['feedback_sample_count'],
    'ranking_minimum_samples_per_topic' => $summary['feedback_ranking']['minimum_samples_per_topic'],
    'resume_transition' => $transition['action'],
    'business_completion_claimed' => false,
    'automatic_activation' => false,
    'external_write_authorized' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
