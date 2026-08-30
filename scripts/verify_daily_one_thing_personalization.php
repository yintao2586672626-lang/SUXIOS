<?php
declare(strict_types=1);

use app\service\DailyOneThingPersonalizationService;
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

$tenantId = 991001;
$userId = 991002;
$hotelId = 991003;
$businessDate = '2026-08-29';
$memory = new UserLearningMemoryService();
$confirmed = $memory->confirmPreference(
    tenantId: $tenantId,
    userId: $userId,
    scope: 'hotel',
    preferenceKey: 'preferred_platform',
    value: 'ctrip',
    idempotencyKey: 'daily_personalization_confirm_ctrip',
    hotelId: $hotelId,
    sourceContext: [
        'content_classification' => 'user_preference',
        'source_ref' => 'daily_personalization_verifier',
        'surface' => 'verification',
        'reason_code' => 'explicit_user_confirmation',
    ]
);
if (($confirmed['preference']['consumable'] ?? false) !== true) {
    throw new RuntimeException('Confirmed platform preference did not become consumable.');
}

$candidate = static function (string $key, string $platform) use (
    $tenantId,
    $userId,
    $hotelId,
    $businessDate
): array {
    return [
        'candidate_key' => $key,
        'source_type' => 'strict_fact_signal',
        'problem' => $platform . ' 当前经营事实需要优先核对',
        'fact_basis' => [[
            'statement' => '同酒店同平台同日期事实已精确回读。',
            'evidence_ref' => 'online_daily_data#verification',
            'quality_status' => 'strict_readback',
        ]],
        'recommended_action' => [
            'type' => 'human_reviewed_operating_check',
            'object' => $platform . '_fact_scope',
            'title' => '只读核对一项事实',
            'description' => '先核对事实，再由用户决定是否执行后续动作。',
            'steps' => ['打开同范围页面只读核对。', '把真实证据绑定原任务。'],
        ],
        'expected_observation_metric' => [
            'key' => 'detail_exposure',
            'label' => '详情曝光',
            'unit' => 'exposure_count',
            'baseline_value' => 10,
            'aggregation' => 'latest',
        ],
        'scope' => [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'business_date' => $businessDate,
            'metric_scope' => 'ota_channel',
            'scope_note' => '只属于当前平台当前酒店当前日期，不扩大为全酒店结论。',
        ],
        'risk' => [
            'level' => 'low',
            'summary' => '风险是误用不同范围事实。',
            'controls' => ['只读核对，不自动写平台。'],
            'stop_conditions' => ['身份或日期不一致时停止。'],
        ],
        'responsibility' => [
            'owner_id' => $userId,
            'owner_label' => '当前确认人',
            'due_at' => '2026-08-29 23:00:00',
            'review_at' => '2026-08-30 10:00:00',
        ],
        'ranking' => [
            'impact' => 80,
            'urgency' => 80,
            'evidence_strength' => 90,
            'execution_cost' => 20,
            'reasons' => [],
        ],
        'source' => [
            'record_id' => 101,
            'record_ref' => 'online_daily_data#verification',
            'snapshot_digest' => str_repeat('a', 64),
            'fact_refs' => ['online_daily_data#verification'],
            'gap_codes' => [],
        ],
        'external_write_boundary' => [
            'automatic_ctrip_write' => false,
            'automatic_meituan_write' => false,
            'automatic_pms_write' => false,
            'automatic_wecom_message' => false,
            'automatic_execution' => false,
        ],
    ];
};

$candidates = [
    $candidate('signal:a:meituan', 'meituan'),
    $candidate('signal:z:ctrip', 'ctrip'),
];
$service = new DailyOneThingPersonalizationService();
$preview = $service->select(
    $candidates,
    $businessDate,
    $tenantId,
    $userId,
    $hotelId
);
if (($preview['selected']['candidate_key'] ?? '') !== 'signal:z:ctrip'
    || ($preview['personalization_receipt']['status'] ?? '') !== 'applied'
    || ($preview['personalization_receipt']['selection_changed'] ?? false) !== true
    || ($preview['personalization_receipt']['facts_changed'] ?? true) !== false
    || ($preview['personalization_receipt']['external_write_authorized'] ?? true) !== false
) {
    throw new RuntimeException('Confirmed platform preference did not safely personalize the exact base tie.');
}

$feedback = $service->recordFeedback(
    $tenantId,
    $userId,
    $hotelId,
    $businessDate,
    (array)$preview['selected'],
    (array)$preview['personalization_receipt'],
    str_repeat('d', 64),
    'accepted',
    'useful',
    'daily_personalization_feedback_1'
);
if (($feedback['readback_verified'] ?? false) !== true
    || ($feedback['adjustments']['status'] ?? '') !== 'insufficient_samples'
    || ($feedback['adjustments']['minimum_samples'] ?? 0) !== 20
) {
    throw new RuntimeException('Daily personalization feedback did not preserve the 20-sample gate.');
}

$memory->revokePreference(
    tenantId: $tenantId,
    userId: $userId,
    scope: 'hotel',
    preferenceKey: 'preferred_platform',
    idempotencyKey: 'daily_personalization_revoke_ctrip',
    hotelId: $hotelId
);
$afterRevoke = (new DailyOneThingPersonalizationService())->select(
    $candidates,
    $businessDate,
    $tenantId,
    $userId,
    $hotelId
);
if (($afterRevoke['selected']['candidate_key'] ?? '') !== 'signal:a:meituan'
    || ($afterRevoke['personalization_receipt']['selection_changed'] ?? true) !== false
) {
    throw new RuntimeException('Revoking the preference did not restore the default base selection.');
}

echo json_encode([
    'status' => 'pass',
    'database' => $databaseName,
    'base_tie_group_size' => $preview['personalization_receipt']['base_tie_group_size'],
    'personalized_selected' => $preview['selected']['candidate_key'],
    'selection_changed' => true,
    'feedback_sample_count' => $feedback['adjustments']['items'][0]['sample_count'] ?? null,
    'feedback_minimum_samples' => $feedback['adjustments']['minimum_samples'],
    'after_revoke_selected' => $afterRevoke['selected']['candidate_key'],
    'hotel_shared_daily_item_changed' => false,
    'execution_intent_created' => false,
    'external_write_count' => 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
