<?php
declare(strict_types=1);

namespace app\controller;

use app\model\AgentLog;
use app\model\PriceSuggestion;
use app\service\OperationManagementService;
use app\service\RevenueAiOverviewService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

class RevenueAi extends Base
{
    public function overview(): Response
    {
        try {
            return $this->success((new RevenueAiOverviewService())->overview($this->filters()), 'success');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->httpCode($e));
        }
    }

    public function reviewPriceSuggestion(int $id = 0): Response
    {
        try {
            $id = $this->routeId($id);
            $input = $this->requestData();
            $action = strtolower(trim((string)($input['action'] ?? $this->request->param('action', 'approve'))));
            if (!in_array($action, ['approve', 'approve_with_changes', 'reject'], true)) {
                return $this->priceSuggestionError('price_suggestion_review_action_invalid', 422, $id);
            }

            $suggestion = $this->loadPriceSuggestion($id);
            [$hotelIds, $hotelId] = $this->priceSuggestionHotelScope($suggestion);
            unset($hotelIds);

            if ((int)$suggestion->status !== PriceSuggestion::STATUS_PENDING) {
                return $this->priceSuggestionError(
                    'price_suggestion_not_pending_review',
                    409,
                    $id,
                    $suggestion->toArray()
                );
            }

            $remark = $this->boundedText((string)($input['remark'] ?? $this->request->param('remark', '')), 500);
            $userId = (int)($this->currentUser->id ?? 0);
            $approvedPrice = $action === 'approve_with_changes'
                ? $this->approvedPriceFromInput($input, $suggestion->toArray())
                : null;
            if ($action === 'approve_with_changes') {
                $message = '定价建议已修改后批准，仍需转运营执行并记录人工 OTA 执行证据';
            } elseif ($action === 'approve') {
                $message = '定价建议已批准，仍需转运营执行并记录人工 OTA 执行证据';
            } else {
                $message = '定价建议已拒绝，未写入 OTA';
            }

            $payload = Db::transaction(function () use ($suggestion, $userId, $remark, $action, $id, $hotelId, $message, $approvedPrice): array {
                $this->recordPriceSuggestionManualReview($suggestion, $action, $userId, $remark, $approvedPrice);

                $fresh = PriceSuggestion::find($id) ?: $suggestion;
                $payload = $this->priceSuggestionReviewPayload($fresh->toArray(), $action);
                $logAction = match ($action) {
                    'approve_with_changes' => 'revenue_ai_price_approve_with_changes',
                    'approve' => 'revenue_ai_price_approve',
                    default => 'revenue_ai_price_reject',
                };
                $this->recordRevenueAiPriceLog(
                    $hotelId,
                    $logAction,
                    $message,
                    [
                        'suggestion_id' => $id,
                        'status' => $payload['status'],
                        'review_version' => $payload['review_version'],
                        'original_suggested_price' => $payload['original_suggested_price'],
                        'approved_price' => $payload['approved_price'],
                        'auto_write_ota' => false,
                        'local_price_updated' => false,
                    ]
                );

                return $payload;
            });

            return $this->success($payload, $message);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->httpCode($e));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'price suggestion review failed'), $this->httpCodeFromThrowable($e));
        }
    }

    public function createPriceSuggestionExecutionIntent(int $id = 0): Response
    {
        try {
            $id = $this->routeId($id);
            $suggestion = $this->loadPriceSuggestion($id);
            [$hotelIds, $hotelId] = $this->priceSuggestionHotelScope($suggestion);

            if ((int)$suggestion->status !== PriceSuggestion::STATUS_APPROVED) {
                return $this->priceSuggestionError(
                    'price_suggestion_not_approved',
                    409,
                    $id,
                    $suggestion->toArray()
                );
            }

            $input = $this->requestData();
            $service = new OperationManagementService();
            $existing = $this->existingPriceSuggestionExecutionIntent($id, $hotelIds, $service);
            if ($existing !== null) {
                return $this->success(
                    $this->priceSuggestionExecutionIntentPayload($suggestion->toArray(), $existing, true),
                    '执行意图已存在'
                );
            }

            $intentInput = $service->buildPriceSuggestionExecutionIntentInput($suggestion->toArray(), [
                'platform' => (string)($input['platform'] ?? $input['channel'] ?? $this->request->param('platform', $this->request->param('channel', ''))),
                'room_type_key' => (string)($input['room_type_key'] ?? $this->request->param('room_type_key', '')),
                'rate_plan_key' => (string)($input['rate_plan_key'] ?? $this->request->param('rate_plan_key', '')),
                'expected_metric' => (string)($input['expected_metric'] ?? $this->request->param('expected_metric', 'orders')),
                'expected_delta' => (float)($input['expected_delta'] ?? $this->request->param('expected_delta', 0)),
                'risk_level' => (string)($input['risk_level'] ?? $this->request->param('risk_level', 'medium')),
            ]);
            $intent = $service->createExecutionIntent($hotelIds, $hotelId, $intentInput, (int)($this->currentUser->id ?? 0));
            $payload = $this->priceSuggestionExecutionIntentPayload($suggestion->toArray(), $intent, false);

            $this->recordRevenueAiPriceLog(
                $hotelId,
                'revenue_ai_price_execution_intent_create',
                'Revenue AI created execution intent from approved price suggestion: ' . $id,
                [
                    'suggestion_id' => $id,
                    'execution_intent_id' => (int)($intent['id'] ?? 0),
                    'platform' => (string)($intentInput['platform'] ?? ''),
                    'auto_write_ota' => false,
                    'local_price_updated' => false,
                ]
            );

            return $this->success($payload, '执行意图已创建');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->httpCode($e));
        } catch (InvalidArgumentException $e) {
            return $this->error($this->safeErrorMessage($e, 'execution intent input invalid'), 422);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'execution intent create failed'), $this->httpCodeFromThrowable($e));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录', 401);
        }

        $data = $this->requestData();
        foreach (['business_date', 'hotel_id', 'platform', 'channel', 'enabled_channels'] as $key) {
            $value = $this->request->param($key, null);
            if ($value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }
        $enabledChannels = $this->requestedEnabledChannels($data);
        if ($enabledChannels !== []) {
            $data['enabled_channels'] = $enabledChannels;
        }
        $permittedHotelIds = array_values(array_unique(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        )));
        $isSuperAdmin = $this->currentUser->isSuperAdmin();
        $requestedHotelId = isset($data['hotel_id']) && ctype_digit((string)$data['hotel_id'])
            ? (int)$data['hotel_id']
            : 0;

        if (!$isSuperAdmin) {
            if ($permittedHotelIds === []) {
                throw new RuntimeException('暂无可访问酒店', 403);
            }
            if ($requestedHotelId > 0 && !in_array($requestedHotelId, $permittedHotelIds, true)) {
                throw new RuntimeException('hotel_id is outside permitted scope', 403);
            }
        }

        $data['permitted_hotel_ids'] = $permittedHotelIds;
        $data['is_super_admin'] = $isSuperAdmin;

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function requestedEnabledChannels(array $data): array
    {
        $raw = $data['enabled_channels'] ?? $data['platform'] ?? $data['channel'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $items = is_array($raw) ? $raw : preg_split('/[,|]/', (string)$raw);
        $channels = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $channel = strtolower(trim((string)$item));
            if ($channel === '') {
                continue;
            }
            if (!in_array($channel, ['ctrip', 'meituan'], true)) {
                throw new RuntimeException('revenue_ai_channel_invalid', 422);
            }
            $channels[] = $channel;
        }

        return array_values(array_unique($channels));
    }

    private function httpCode(RuntimeException $e): int
    {
        $code = $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : 500;
    }

    private function httpCodeFromThrowable(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException) {
            return 422;
        }
        $code = (int)$e->getCode();
        return $code >= 400 && $code <= 599 ? $code : 500;
    }

    private function safeErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim((string)$e->getMessage());
        if ($message !== '' && (
            preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1
            || str_starts_with($message, 'price_suggestion_')
            || str_contains($message, 'hotel_id')
        )) {
            return $message;
        }

        return $fallback;
    }

    private function routeId(int $id): int
    {
        $resolved = $id > 0 ? $id : (int)$this->request->param('id', 0);
        if ($resolved <= 0) {
            throw new RuntimeException('price_suggestion_id_invalid', 422);
        }

        return $resolved;
    }

    private function loadPriceSuggestion(int $id): PriceSuggestion
    {
        $suggestion = PriceSuggestion::find($id);
        if (!$suggestion) {
            throw new RuntimeException('price_suggestion_not_found', 404);
        }

        return $suggestion;
    }

    /**
     * @return array{0:array<int, int>, 1:int}
     */
    private function priceSuggestionHotelScope(PriceSuggestion $suggestion): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录', 401);
        }

        $hotelId = (int)$suggestion->hotel_id;
        if ($hotelId <= 0) {
            throw new RuntimeException('price_suggestion_hotel_missing', 422);
        }

        if ($this->currentUser->isSuperAdmin()) {
            return [[$hotelId], $hotelId];
        }

        $permittedHotelIds = array_values(array_unique(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        )));
        if ($permittedHotelIds === []) {
            throw new RuntimeException('暂无可访问酒店', 403);
        }
        if (!in_array($hotelId, $permittedHotelIds, true)) {
            throw new RuntimeException('price_suggestion_hotel_not_permitted', 403);
        }

        return [$permittedHotelIds, $hotelId];
    }

    /**
     * @param array<string, mixed> $suggestion
     * @return array<string, mixed>
     */
    private function priceSuggestionReviewPayload(array $suggestion, string $action): array
    {
        $id = (int)($suggestion['id'] ?? 0);
        $statusCode = (int)($suggestion['status'] ?? 0);
        $manualReview = $this->latestManualReview($suggestion);
        $modifiedReview = ($manualReview['action'] ?? '') === 'approve_with_changes';

        return [
            'suggestion_id' => $id,
            'hotel_id' => (int)($suggestion['hotel_id'] ?? 0),
            'action' => $action,
            'status_code' => $statusCode,
            'status' => $this->priceSuggestionStatusKey($statusCode),
            'status_label' => $this->priceSuggestionStatusLabel($statusCode),
            'manual_review_required' => true,
            'advisory_only' => true,
            'auto_write_ota' => false,
            'local_price_updated' => false,
            'ota_write' => false,
            'modified_review' => $modifiedReview,
            'original_suggested_price' => $manualReview['original_suggested_price'] ?? $this->positiveMoneyValue($suggestion['suggested_price'] ?? null),
            'approved_price' => $manualReview['approved_price'] ?? null,
            'review_version' => $manualReview['version'] ?? null,
            'review_storage' => $manualReview === [] ? null : 'price_suggestions.factors.manual_review_versions',
            'manual_review' => $manualReview === [] ? null : $manualReview,
            'allowed_endpoint' => $statusCode === PriceSuggestion::STATUS_APPROVED
                ? '/api/revenue-ai/price-suggestions/' . $id . '/execution-intent'
                : null,
            'next_action' => $statusCode === PriceSuggestion::STATUS_APPROVED
                ? 'create_execution_intent'
                : 'none',
            'forbidden_actions' => ['apply_price', 'ota_write', 'update_room_type_base_price'],
        ];
    }

    /**
     * @param array<string, mixed> $suggestion
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function priceSuggestionExecutionIntentPayload(array $suggestion, array $intent, bool $existing): array
    {
        return [
            'suggestion_id' => (int)($suggestion['id'] ?? 0),
            'hotel_id' => (int)($suggestion['hotel_id'] ?? 0),
            'status_code' => (int)($suggestion['status'] ?? 0),
            'status' => $this->priceSuggestionStatusKey((int)($suggestion['status'] ?? 0)),
            'execution_intent' => $intent,
            'execution_intent_existing' => $existing,
            'source_module' => 'price_suggestion',
            'target_page' => 'ops-track',
            'target_action' => 'approve_intent',
            'target_id' => (int)($intent['id'] ?? 0),
            'target_kind' => 'intent',
            'manual_review_required' => true,
            'advisory_only' => true,
            'auto_write_ota' => false,
            'local_price_updated' => false,
            'ota_write' => false,
            'next_action' => 'operation_execution_manual_evidence',
            'forbidden_actions' => ['apply_price', 'ota_write', 'update_room_type_base_price'],
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>|null
     */
    private function existingPriceSuggestionExecutionIntent(int $suggestionId, array $hotelIds, OperationManagementService $service): ?array
    {
        if ($suggestionId <= 0 || $hotelIds === [] || !$service->tableExists('operation_execution_intents')) {
            return null;
        }

        $row = Db::name('operation_execution_intents')
            ->where('source_module', 'price_suggestion')
            ->where('source_record_id', $suggestionId)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->find();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $suggestion
     */
    private function priceSuggestionError(string $reason, int $code, int $id = 0, array $suggestion = []): Response
    {
        $statusCode = (int)($suggestion['status'] ?? 0);

        return $this->error($reason, $code, [
            'reason' => $reason,
            'suggestion_id' => $id,
            'hotel_id' => (int)($suggestion['hotel_id'] ?? 0),
            'status_code' => $statusCode,
            'status' => $this->priceSuggestionStatusKey($statusCode),
            'manual_review_required' => true,
            'advisory_only' => true,
            'auto_write_ota' => false,
            'local_price_updated' => false,
            'ota_write' => false,
            'forbidden_actions' => ['apply_price', 'ota_write', 'update_room_type_base_price'],
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordRevenueAiPriceLog(int $hotelId, string $action, string $message, array $context): void
    {
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_REVENUE,
            $action,
            $message,
            AgentLog::LEVEL_INFO,
            $context,
            (int)($this->currentUser->id ?? 0)
        );
    }

    private function boundedText(string $value, int $limit): string
    {
        $text = trim($value);
        if ($limit <= 0 || $text === '') {
            return '';
        }

        return mb_substr($text, 0, $limit);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $suggestion
     */
    private function approvedPriceFromInput(array $input, array $suggestion): float
    {
        $raw = $input['approved_price'] ?? $input['target_price'] ?? null;
        if (is_string($raw)) {
            $raw = preg_replace('/[^\d.\-]/', '', $raw) ?? '';
        }
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            throw new InvalidArgumentException('price_suggestion_approved_price_invalid');
        }

        $approvedPrice = round((float)$raw, 2);
        if ($approvedPrice <= 0) {
            throw new InvalidArgumentException('price_suggestion_approved_price_invalid');
        }

        $minPrice = $this->positiveMoneyValue($suggestion['min_price'] ?? null);
        if ($minPrice !== null && $approvedPrice < $minPrice) {
            throw new InvalidArgumentException('price_suggestion_approved_price_below_min_price');
        }

        $maxPrice = $this->positiveMoneyValue($suggestion['max_price'] ?? null);
        if ($maxPrice !== null && $approvedPrice > $maxPrice) {
            throw new InvalidArgumentException('price_suggestion_approved_price_above_max_price');
        }

        return $approvedPrice;
    }

    private function recordPriceSuggestionManualReview(
        PriceSuggestion $suggestion,
        string $action,
        int $userId,
        string $remark,
        ?float $approvedPrice = null
    ): void
    {
        $state = $this->buildManualReviewState($suggestion->toArray(), $action, $userId, $remark, $approvedPrice);
        $suggestion->status = $this->manualReviewStatusAfter($action);
        $suggestion->applied_by = $userId;
        $suggestion->remark = $remark;
        $suggestion->factors = $state['factors'];
        $suggestion->save();
    }

    /**
     * @param array<string, mixed> $suggestion
     * @return array{factors:array<string, mixed>, review:array<string, mixed>}
     */
    private function buildManualReviewState(
        array $suggestion,
        string $action,
        int $userId,
        string $remark,
        ?float $approvedPrice = null
    ): array {
        if (!in_array($action, ['approve', 'approve_with_changes', 'reject'], true)) {
            throw new InvalidArgumentException('price_suggestion_review_action_invalid');
        }

        $factors = $this->arrayValue($suggestion['factors'] ?? []);
        $versions = is_array($factors['manual_review_versions'] ?? null)
            ? array_values(array_filter($factors['manual_review_versions'], 'is_array'))
            : [];
        $originalPrice = $this->positiveMoneyValue($suggestion['suggested_price'] ?? null);
        $finalApprovedPrice = match ($action) {
            'reject' => null,
            'approve_with_changes' => $approvedPrice,
            default => $originalPrice,
        };
        $priceDelta = $originalPrice !== null && $finalApprovedPrice !== null
            ? round($finalApprovedPrice - $originalPrice, 2)
            : null;
        $review = [
            'version' => count($versions) + 1,
            'action' => $action,
            'status_after' => $this->priceSuggestionStatusKey($this->manualReviewStatusAfter($action)),
            'original_suggested_price' => $originalPrice,
            'approved_price' => $finalApprovedPrice,
            'price_delta' => $priceDelta,
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'remark' => $remark,
            'auto_write_ota' => false,
            'local_price_updated' => false,
            'ota_write' => false,
            'version_storage' => 'price_suggestions.factors.manual_review_versions',
        ];
        $versions[] = $review;
        $factors['manual_review_versions'] = $versions;
        $factors['manual_review'] = $review;

        return ['factors' => $factors, 'review' => $review];
    }

    private function manualReviewStatusAfter(string $action): int
    {
        return match ($action) {
            'approve', 'approve_with_changes' => PriceSuggestion::STATUS_APPROVED,
            'reject' => PriceSuggestion::STATUS_REJECTED,
            default => throw new InvalidArgumentException('price_suggestion_review_action_invalid'),
        };
    }

    /**
     * @param array<string, mixed> $suggestion
     * @return array<string, mixed>
     */
    private function latestManualReview(array $suggestion): array
    {
        $factors = $this->arrayValue($suggestion['factors'] ?? []);
        if (is_array($factors['manual_review'] ?? null)) {
            return $this->normalizeManualReview($factors['manual_review']);
        }

        $versions = is_array($factors['manual_review_versions'] ?? null)
            ? array_values($factors['manual_review_versions'])
            : [];
        $last = end($versions);

        return is_array($last) ? $this->normalizeManualReview($last) : [];
    }

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function normalizeManualReview(array $review): array
    {
        $review['version'] = (int)($review['version'] ?? 0);
        $review['original_suggested_price'] = $this->positiveMoneyValue($review['original_suggested_price'] ?? null);
        $review['approved_price'] = $this->positiveMoneyValue($review['approved_price'] ?? null);
        $review['auto_write_ota'] = false;
        $review['local_price_updated'] = false;

        return $review;
    }

    private function positiveMoneyValue(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = preg_replace('/[^\d.\-]/', '', $value) ?? '';
        }
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = round((float)$value, 2);

        return $number > 0 ? $number : null;
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function priceSuggestionStatusKey(int $status): string
    {
        return match ($status) {
            PriceSuggestion::STATUS_PENDING => 'pending_review',
            PriceSuggestion::STATUS_APPROVED => 'approved',
            PriceSuggestion::STATUS_REJECTED => 'rejected',
            PriceSuggestion::STATUS_APPLIED => 'applied',
            PriceSuggestion::STATUS_EXPIRED => 'expired',
            default => 'unknown',
        };
    }

    private function priceSuggestionStatusLabel(int $status): string
    {
        return match ($status) {
            PriceSuggestion::STATUS_PENDING => '待审核',
            PriceSuggestion::STATUS_APPROVED => '已批准',
            PriceSuggestion::STATUS_REJECTED => '已拒绝',
            PriceSuggestion::STATUS_APPLIED => '已应用',
            PriceSuggestion::STATUS_EXPIRED => '已过期',
            default => '未知',
        };
    }
}
