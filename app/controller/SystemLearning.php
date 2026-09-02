<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AiSuggestionCalibrationService;
use app\service\HotelScopeService;
use app\service\OperatingQuestionService;
use app\service\PreciseQueryRouterService;
use app\service\UserGuidanceJourneyService;
use app\service\UserLearningMemoryService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

/** Authenticated, user-owned learning controls for the existing system guide. */
final class SystemLearning extends Base
{
    private UserLearningMemoryService $memory;
    private UserGuidanceJourneyService $journeys;
    private AiSuggestionCalibrationService $calibration;
    private HotelScopeService $hotelScope;
    private PreciseQueryRouterService $preciseQueries;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->memory = new UserLearningMemoryService();
        $this->journeys = new UserGuidanceJourneyService();
        $this->calibration = new AiSuggestionCalibrationService();
        $this->hotelScope = new HotelScopeService();
        $this->preciseQueries = new PreciseQueryRouterService();
    }

    public function context(): Response
    {
        try {
            [$tenantId, $userId, $hotelId] = $this->scope(
                (int)$this->request->param('hotel_id', 0),
                true
            );
            $global = $this->memory->listPreferences(
                $tenantId,
                $userId,
                'global',
                null,
                null,
                false,
                true
            );
            $hotel = $hotelId !== null
                ? $this->memory->listPreferences(
                    $tenantId,
                    $userId,
                    'hotel',
                    $hotelId,
                    null,
                    false,
                    true
                )
                : ['status' => 'not_applicable', 'count' => 0, 'items' => []];
            $items = array_values(array_merge(
                is_array($global['items'] ?? null) ? $global['items'] : [],
                is_array($hotel['items'] ?? null) ? $hotel['items'] : []
            ));
            $consumable = array_values(array_filter(
                $items,
                static fn(array $item): bool => ($item['consumable'] ?? false) === true
            ));
            $candidates = array_values(array_filter(
                $items,
                static fn(array $item): bool => ($item['candidate'] ?? false) === true
            ));
            $readyCandidates = array_values(array_filter(
                $candidates,
                static fn(array $item): bool => ($item['learning_status'] ?? '') === 'inferred'
            ));

            return $this->success([
                'contract_version' => 'system_user_learning_context.v1',
                'status' => $this->contextStatus($global, $hotel),
                'scope' => [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'hotel_id' => $hotelId,
                ],
                'preferences' => [
                    'status' => $this->contextStatus($global, $hotel),
                    'count' => count($items),
                    'items' => $items,
                    'consumable_count' => count($consumable),
                    'consumable_items' => $consumable,
                    'candidate_count' => count($candidates),
                    'candidate_items' => $candidates,
                    'ready_candidate_count' => count($readyCandidates),
                    'ready_candidate_items' => $readyCandidates,
                ],
                'journey' => $this->journeys->readActive($tenantId, $userId, $hotelId),
                'resume_card' => $this->journeys->readResumeCard($tenantId, $userId, $hotelId),
                'calibration' => $hotelId !== null
                    ? $this->safeCalibrationSummary($tenantId, $userId, $hotelId)
                    : ['status' => 'hotel_scope_required'],
                'learning_policy' => $this->learningPolicy(),
            ], '个人经营副驾学习上下文已回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '个人学习上下文读取失败'), $this->status($e));
        }
    }

    public function savePreference(): Response
    {
        try {
            $input = $this->requestData();
            $scopeType = strtolower(trim((string)($input['scope'] ?? 'global')));
            $hotelIdInput = $scopeType === 'hotel'
                ? (int)($input['hotel_id'] ?? 0)
                : (int)($input['context_hotel_id'] ?? 0);
            [$tenantId, $userId, $hotelId] = $this->scope($hotelIdInput, $scopeType === 'global');
            [$key, $value] = $this->validatedPreference(
                (string)($input['preference_key'] ?? ''),
                $input['value'] ?? null
            );
            $result = $this->memory->confirmPreference(
                tenantId: $tenantId,
                userId: $userId,
                scope: $scopeType,
                preferenceKey: $key,
                value: $value,
                idempotencyKey: $this->idempotencyKey($input),
                hotelId: $scopeType === 'hotel' ? $hotelId : null,
                sourceContext: [
                    'content_classification' => 'user_preference',
                    'source_ref' => 'system_guidance_preference_control',
                    'surface' => 'system_guidance',
                    'reason_code' => 'explicit_user_confirmation',
                ]
            );
            return $this->memoryWriteResponse($result, '偏好已确认并精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '偏好保存失败'), $this->status($e));
        }
    }

    public function revokePreference(): Response
    {
        try {
            $input = $this->requestData();
            $scopeType = strtolower(trim((string)($input['scope'] ?? 'global')));
            $hotelIdInput = $scopeType === 'hotel'
                ? (int)($input['hotel_id'] ?? 0)
                : (int)($input['context_hotel_id'] ?? 0);
            [$tenantId, $userId, $hotelId] = $this->scope($hotelIdInput, $scopeType === 'global');
            [$key] = $this->validatedPreference((string)($input['preference_key'] ?? ''), 'standard', false);
            $result = $this->memory->revokePreference(
                tenantId: $tenantId,
                userId: $userId,
                scope: $scopeType,
                preferenceKey: $key,
                idempotencyKey: $this->idempotencyKey($input),
                hotelId: $scopeType === 'hotel' ? $hotelId : null
            );
            return $this->memoryWriteResponse($result, '偏好已撤销并精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '偏好撤销失败'), $this->status($e));
        }
    }

    public function resetPreferences(): Response
    {
        try {
            $input = $this->requestData();
            $scopeType = strtolower(trim((string)($input['scope'] ?? 'global')));
            $hotelIdInput = $scopeType === 'hotel'
                ? (int)($input['hotel_id'] ?? 0)
                : (int)($input['context_hotel_id'] ?? 0);
            [$tenantId, $userId, $hotelId] = $this->scope($hotelIdInput, $scopeType === 'global');
            $result = $this->memory->resetScope(
                tenantId: $tenantId,
                userId: $userId,
                scope: $scopeType,
                idempotencyKey: $this->idempotencyKey($input),
                hotelId: $scopeType === 'hotel' ? $hotelId : null
            );
            return $this->memoryWriteResponse($result, '当前作用域偏好已重置');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '偏好重置失败'), $this->status($e));
        }
    }

    public function saveJourney(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $userId, $hotelId] = $this->scope((int)($input['hotel_id'] ?? 0), true);
            $result = $this->journeys->save(
                $tenantId,
                $userId,
                $hotelId,
                is_array($input['journey'] ?? null) ? $input['journey'] : $input,
                $userId
            );
            return $this->success($result, '任务路线已保存并精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '任务路线保存失败'), $this->status($e));
        }
    }

    public function archiveJourney(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $userId, $hotelId] = $this->scope((int)($input['hotel_id'] ?? 0), true);
            return $this->success(
                $this->journeys->archiveActive($tenantId, $userId, $hotelId, $userId),
                '任务路线已归档'
            );
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '任务路线归档失败'), $this->status($e));
        }
    }

    public function transitionJourney(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $userId, $hotelId] = $this->scope(
                (int)($input['hotel_id'] ?? 0),
                true
            );
            $result = $this->journeys->transitionExact(
                $tenantId,
                $userId,
                $hotelId,
                max(0, (int)($input['journey_id'] ?? 0)),
                (string)($input['expected_content_digest'] ?? ''),
                (string)($input['action'] ?? ''),
                $userId
            );
            return $this->success($result, '续办卡状态已更新并精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '续办卡状态更新失败'), $this->status($e));
        }
    }

    public function recordSuggestionFeedback(): Response
    {
        try {
            $input = $this->requestData();
            $preciseQueryId = max(0, (int)($input['precise_query_id'] ?? 0));
            if ($preciseQueryId <= 0) {
                throw new InvalidArgumentException('precise_query_id is required');
            }
            $accessibleHotels = $this->accessibleHotels();
            $readback = $this->preciseQueries->read(
                $preciseQueryId,
                $this->currentTenantId(),
                $accessibleHotels
            );
            $hotelId = max(0, (int)($readback['parsed_scope']['hotel_id'] ?? 0));
            if ($hotelId <= 0 && is_array($readback['operating_question'] ?? null)) {
                $hotelId = max(0, (int)($readback['operating_question']['hotel_id'] ?? 0));
            }
            $storedQuestion = Db::name(OperatingQuestionService::TABLE)
                ->where('id', $preciseQueryId)
                ->where('created_by', (int)($this->currentUser->id ?? 0))
                ->find();
            if (!is_array($storedQuestion)) {
                throw new RuntimeException('precise query is not owned by the current user', 404);
            }
            if ($hotelId <= 0) {
                $hotelId = max(0, (int)($storedQuestion['hotel_id'] ?? 0));
            }
            if ($hotelId <= 0 || !in_array($hotelId, $accessibleHotels, true)) {
                throw new InvalidArgumentException('feedback requires one accessible hotel scope');
            }
            [$tenantId, $userId] = $this->scope($hotelId, false);
            $digest = strtolower(trim((string)($readback['content_digest'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new RuntimeException('precise query readback digest is invalid');
            }
            if (!hash_equals($digest, strtolower(trim((string)($storedQuestion['content_digest'] ?? ''))))) {
                throw new RuntimeException('precise query stored digest does not match exact readback');
            }
            $suggestionKey = 'precise_query_' . $preciseQueryId;
            $routeType = (string)($readback['route_type'] ?? 'unknown');
            $answer = is_array($readback['answer'] ?? null) ? $readback['answer'] : [];
            [$feedbackStatus, $reasonCode] = $this->validatedSuggestionFeedback(
                (string)($input['feedback_status'] ?? ''),
                (string)($input['reason_code'] ?? '')
            );
            $snapshot = $this->calibration->freezeSuggestion([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelId,
                'suggestion_key' => $suggestionKey,
                'scenario' => 'system_guidance_' . preg_replace('/[^a-z0-9_]+/i', '_', $routeType),
                'source_key' => 'precise_query',
                'source_version' => (string)($readback['contract_version'] ?? 'precise_query.v1'),
                'evidence_digest' => $digest,
                'suggestion_payload' => [
                    'precise_query_id' => $preciseQueryId,
                    'route_type' => $routeType,
                    'intent_key' => (string)($readback['intent_key'] ?? ''),
                    'topic_key' => (string)($answer['topic_key'] ?? ''),
                    'assistant_mode' => (string)($answer['assistant_mode'] ?? ''),
                    'status' => (string)($readback['status'] ?? ''),
                ],
                'confidence' => $this->confidenceValue((string)($answer['confidence'] ?? '')),
                'idempotency_key' => 'freeze_precise_query_' . $preciseQueryId,
            ]);
            $feedback = $this->calibration->appendFeedback([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelId,
                'suggestion_key' => $suggestionKey,
                'feedback_status' => $feedbackStatus,
                'reason_code' => $reasonCode,
                'feedback_payload' => [
                    'surface' => 'system_guidance',
                    'precise_query_id' => $preciseQueryId,
                ],
                'idempotency_key' => $this->idempotencyKey($input),
            ]);
            $learningSignal = $this->recordBoundedPreferenceSignal(
                $tenantId,
                $userId,
                $hotelId,
                $input,
                $preciseQueryId
            );
            return $this->success([
                'snapshot' => $snapshot,
                'feedback' => $feedback,
                'preference_signal' => $learningSignal,
                'calibration' => $this->safeCalibrationSummary($tenantId, $userId, $hotelId),
            ], '建议反馈已保存并精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '建议反馈保存失败'), $this->status($e));
        }
    }

    /** @return array{0:int,1:int,2:?int} */
    private function scope(int $hotelId, bool $allowNoHotel): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }
        $userId = max(0, (int)($this->currentUser->id ?? 0));
        if ($userId <= 0) {
            throw new RuntimeException('用户身份缺失');
        }
        if ($hotelId > 0) {
            $accessible = $this->accessibleHotels();
            if (!in_array($hotelId, $accessible, true)) {
                throw new RuntimeException('无权访问该酒店学习上下文');
            }
            $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->where('status', 1)->value('tenant_id');
            if ($tenantId <= 0) {
                throw new RuntimeException('酒店租户身份缺失');
            }
            return [$tenantId, $userId, $hotelId];
        }
        if (!$allowNoHotel) {
            throw new InvalidArgumentException('hotel_id is required');
        }
        $tenantId = $this->currentTenantId();
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('请选择一家酒店后再使用个人学习功能');
        }
        return [$tenantId, $userId, null];
    }

    /** @return list<int> */
    private function accessibleHotels(): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            $this->hotelScope->accessibleHotelIds($this->currentUser, 'operation.view')
        ), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new RuntimeException('暂无可访问酒店');
        }
        return $ids;
    }

    private function currentTenantId(): int
    {
        if (!$this->currentUser || $this->currentUser->isSuperAdmin()) {
            return 0;
        }
        return max(0, (int)($this->currentUser->tenant_id ?? 0));
    }

    /** @return array{0:string,1:mixed} */
    private function validatedPreference(string $key, mixed $value, bool $validateValue = true): array
    {
        $key = strtolower(trim($key));
        $allowed = [
            'response_detail' => ['standard', 'concise', 'detailed'],
            'answer_order' => ['standard', 'conclusion_first', 'steps_first'],
            'daily_focus' => ['standard', 'single_priority'],
            'preferred_platform' => ['ctrip', 'meituan', 'all_ota'],
        ];
        if (!isset($allowed[$key])) {
            throw new InvalidArgumentException('不支持的个人偏好类型');
        }
        if ($validateValue && (!is_string($value) || !in_array(strtolower(trim($value)), $allowed[$key], true))) {
            throw new InvalidArgumentException('个人偏好值无效');
        }
        return [$key, $validateValue ? strtolower(trim((string)$value)) : $value];
    }

    /** @param array<string,mixed> $input */
    private function idempotencyKey(array $input): string
    {
        $key = trim((string)($input['idempotency_key'] ?? $input['client_request_id'] ?? ''));
        if ($key === '' || strlen($key) > 96 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D', $key) !== 1) {
            throw new InvalidArgumentException('idempotency_key is required and invalid');
        }
        return $key;
    }

    /** @return array<string,mixed> */
    private function safeCalibrationSummary(int $tenantId, int $userId, int $hotelId): array
    {
        try {
            return $this->calibration->summarize([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelId,
            ], ['minimum_samples' => 3]);
        } catch (Throwable $e) {
            return [
                'status' => $this->isMissingTable($e) ? 'migration_required' : 'unavailable',
                'reason_code' => $this->isMissingTable($e)
                    ? 'ai_suggestion_calibration_tables_missing'
                    : 'ai_suggestion_calibration_unavailable',
            ];
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    private function recordBoundedPreferenceSignal(
        int $tenantId,
        int $userId,
        int $hotelId,
        array $input,
        int $preciseQueryId
    ): ?array {
        $reason = strtolower(trim((string)($input['reason_code'] ?? '')));
        if ($reason !== 'too_long') {
            return null;
        }
        return $this->memory->recordRepeatedSignal(
            tenantId: $tenantId,
            userId: $userId,
            scope: 'global',
            preferenceKey: 'response_detail',
            value: 'concise',
            idempotencyKey: 'calibration_feedback_precise_query_' . $preciseQueryId,
            minimumSignals: 3,
            sourceContext: [
                'content_classification' => 'interaction_pattern',
                'source_ref' => 'precise_query#' . $preciseQueryId,
                'surface' => 'system_guidance',
                'reason_code' => 'too_long',
            ]
        );
    }

    /** @param array<string,mixed> $global @param array<string,mixed> $hotel */
    private function contextStatus(array $global, array $hotel): string
    {
        foreach ([$global, $hotel] as $result) {
            if (($result['status'] ?? '') === 'migration_required') {
                return 'migration_required';
            }
        }
        return 'ready';
    }

    /** @return array<string,bool|string> */
    private function learningPolicy(): array
    {
        return [
            'preference_changes_facts' => false,
            'preference_changes_permissions' => false,
            'preference_changes_approval' => false,
            'automatic_prompt_activation' => false,
            'automatic_model_fine_tuning' => false,
            'candidate_requires_explicit_confirmation' => true,
            'candidate_minimum_repeated_signals' => 3,
            'external_write_authorized' => false,
            'current_request_overrides_saved_preference' => true,
        ];
    }

    private function confidenceValue(string $value): ?float
    {
        return match (strtolower(trim($value))) {
            'high' => 0.8,
            'medium' => 0.5,
            'low' => 0.2,
            default => null,
        };
    }

    /** @return array{0:string,1:string} */
    private function validatedSuggestionFeedback(string $status, string $reason): array
    {
        $status = strtolower(trim($status));
        $reason = strtolower(trim($reason));
        $allowed = [
            'accepted' => ['useful'],
            'modified' => ['too_long'],
            'rejected' => ['wrong_focus', 'not_actionable'],
            'needs_more_evidence' => ['more_evidence'],
            'deferred' => ['not_now'],
        ];
        if (!isset($allowed[$status]) || !in_array($reason, $allowed[$status], true)) {
            throw new InvalidArgumentException('suggestion feedback status and reason do not match');
        }
        return [$status, $reason];
    }

    /** @param array<string,mixed> $result */
    private function memoryWriteResponse(array $result, string $successMessage): Response
    {
        if (($result['status'] ?? '') === 'migration_required'
            || ($result['migration_required'] ?? false) === true
        ) {
            return $this->error(
                '个人学习数据表未就绪，请先执行数据库迁移',
                503,
                $result
            );
        }
        return $this->success($result, $successMessage);
    }

    private function isMissingTable(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'no such table')
            || str_contains($message, "doesn't exist")
            || str_contains($message, 'base table or view not found');
    }

    private function status(Throwable $e): int
    {
        if ($e->getMessage() === '未登录') {
            return 401;
        }
        if (str_contains($e->getMessage(), '无权') || str_contains($e->getMessage(), '暂无可访问')) {
            return 403;
        }
        if ($e->getCode() === 404) {
            return 404;
        }
        if ($e->getCode() === 409 || str_contains(strtolower($e->getMessage()), 'idempotency')) {
            return 409;
        }
        if ($e->getCode() === 503 || $this->isMissingTable($e)) {
            return 503;
        }
        return $e instanceof InvalidArgumentException ? 422 : 500;
    }

    private function safeMessage(Throwable $e, string $fallback): string
    {
        $status = $this->status($e);
        if ($status === 503 && ($e->getCode() === 503 || $this->isMissingTable($e))) {
            return '个人学习数据表未就绪，请先执行数据库迁移';
        }
        return $status < 500 ? $e->getMessage() : $fallback;
    }
}
