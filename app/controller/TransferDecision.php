<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OperationManagementService;
use app\service\TransferDecisionService;
use InvalidArgumentException;
use RuntimeException;
use think\App;
use think\Response;
use think\facade\Db;
use Throwable;

class TransferDecision extends Base
{
    private TransferDecisionService $service;

    public function __construct(App $app, ?TransferDecisionService $service = null)
    {
        parent::__construct($app);
        $this->service = $service ?: new TransferDecisionService();
    }

    public function source(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            $date = $this->normalizeDate((string)$this->request->param('date', $this->currentBusinessDate()));

            return $this->success($this->service->buildSourcePayload($hotelIds, $hotelId, $date));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $failureCode = $this->sourceFailureCode($e);
            if ($failureCode !== null) {
                return $this->error('转让测算来源数据暂时不可用', 503, [
                    'status_code' => $failureCode,
                ]);
            }
            return $this->error($this->safeErrorMessage($e, '获取转让测算来源数据失败'), 400);
        } catch (Throwable $e) {
            return $this->error('获取转让测算来源数据失败', 500);
        }
    }

    public function pricing(): Response
    {
        try {
            $input = $this->request->post();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $snapshot = $this->payloadSnapshot($input);
            $recordHotelId = $this->recordHotelId($input, $snapshot, $hotelIds, $hotelId);
            unset($input['snapshot'], $input['data_snapshot']);

            $result = $this->service->calculateAssetPricing($input);
            if (($result['status'] ?? '') === 'insufficient_data') {
                return $this->success($result, '输入校验完成；关键字段缺失，未生成估值。');
            }
            $result['record_id'] = $this->service->saveRecord('pricing', $input, $result, $snapshot, $recordHotelId, (int)($this->currentUser->id ?? 0));
            $result['decision_readiness'] = $this->service->buildDecisionReadiness('pricing', $input, $result, $snapshot, $recordHotelId);
            return $this->success($result, '情景估值已生成；请查看数据来源、假设与尽调要求。');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            $status = $this->pricingFailureStatusCode($e);
            return $this->error(
                $status === 422 ? 'AI评估暂时不可用，未生成模型结论' : '资产定价计算失败',
                $status,
                ['status_code' => $status === 422 ? 'transfer_pricing_ai_unavailable' : 'transfer_pricing_failed']
            );
        }
    }

    public function timing(): Response
    {
        try {
            $input = $this->request->post();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $snapshot = $this->payloadSnapshot($input);
            $recordHotelId = $this->recordHotelId($input, $snapshot, $hotelIds, $hotelId);
            unset($input['snapshot'], $input['data_snapshot']);

            $result = $this->service->calculateTransferTiming($input);
            if (($result['status'] ?? '') === 'insufficient_data') {
                return $this->success($result, '输入校验完成；关键趋势缺失，未生成时机评分。');
            }
            $result['record_id'] = $this->service->saveRecord('timing', $input, $result, $snapshot, $recordHotelId, (int)($this->currentUser->id ?? 0));
            $result['decision_readiness'] = $this->service->buildDecisionReadiness('timing', $input, $result, $snapshot, $recordHotelId);
            return $this->success($result, '规则时机情景已生成；结论仍需数据核验与人工尽调。');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('时机推演计算失败', 500, [
                'status_code' => 'transfer_timing_failed',
            ]);
        }
    }

    public function dashboard(): Response
    {
        try {
            $input = $this->request->post();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $snapshot = $this->payloadSnapshot($input);
            $recordHotelId = $this->recordHotelId($input, $snapshot, $hotelIds, $hotelId);
            $dashboardInput = [
                'pricing' => is_array($input['pricing'] ?? null) ? $input['pricing'] : [],
                'timing' => is_array($input['timing'] ?? null) ? $input['timing'] : [],
                'metrics' => is_array($input['metrics'] ?? null) ? $input['metrics'] : [],
                'pricing_input' => is_array($input['pricing_input'] ?? null) ? $input['pricing_input'] : [],
                'timing_input' => is_array($input['timing_input'] ?? null) ? $input['timing_input'] : [],
            ];

            $result = $this->service->buildTransferDashboard($dashboardInput['pricing'], $dashboardInput['timing'], $dashboardInput['metrics']);
            $result['record_id'] = $this->service->saveRecord('dashboard', $dashboardInput, $result, $snapshot, $recordHotelId, (int)($this->currentUser->id ?? 0));
            $result['decision_readiness'] = $this->service->buildDecisionReadiness('dashboard', $dashboardInput, $result, $snapshot, $recordHotelId);
            return $this->success($result, '转让决策汇总已生成；是否可决策以证据门禁状态为准。');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('数据看板生成失败', 500, [
                'status_code' => 'transfer_dashboard_failed',
            ]);
        }
    }

    public function records(): Response
    {
        try {
            [$hotelIds] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            $list = $this->service->records($hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            return $this->success(['list' => $list]);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取转让记录失败'), 400);
        }
    }

    public function detail(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('转让记录ID无效', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            return $this->success($this->service->detail($id, $hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin()));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取转让记录详情失败'), 400);
        }
    }

    public function createExecutionIntent(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('transfer record id is invalid', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            $record = $this->service->detail($id, $hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            $hotelId = (int)($record['hotel_id'] ?? 0);
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'operation.execute',
                'operation.execute permission is required for this hotel'
            )) !== null) {
                return $denied;
            }
            $userId = (int)($this->currentUser->id ?? 0);
            $dateOverrides = [];
            foreach (['date_start', 'date_end'] as $dateField) {
                if ($this->request->has($dateField)) {
                    $dateOverrides[$dateField] = $this->request->param($dateField);
                }
            }
            $result = Db::transaction(function () use ($record, $id, $hotelIds, $userId, $dateOverrides): array {
                $operationService = new OperationManagementService();
                // Preflight detail is UX-only. Re-authorize and lock the current
                // hotel/tenant/source identity before deriving or writing an intent.
                $record = $this->service->lockExecutionTrackingSource(
                    $id,
                    $hotelIds,
                    (int)$record['hotel_id']
                );
                $input = $this->service->buildExecutionIntentInput($record, $dateOverrides);
                $intent = $operationService->createExecutionIntent(
                    $hotelIds,
                    (int)$record['hotel_id'],
                    $input,
                    $userId,
                    false,
                    null,
                    true
                );
                $updatedRecord = $this->service->attachExecutionTracking(
                    $id,
                    $hotelIds,
                    $userId,
                    $this->currentUser->isSuperAdmin(),
                    [
                        'execution_intent_id' => (int)($intent['id'] ?? 0),
                        'hotel_id' => (int)$record['hotel_id'],
                        'status' => (string)($intent['status'] ?? ''),
                    ]
                );

                return [
                    'execution_intent' => $intent,
                    'record' => $updatedRecord,
                ];
            });

            return $this->success($result, 'execution intent created');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->error($this->safeErrorMessage($e, 'transfer record not found'), 404);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'create transfer execution intent failed'), 500);
        }
    }

    public function archive(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('转让记录ID无效', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            $archived = $this->service->archive($id, $hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            if (!$archived) {
                return $this->error('转让记录不存在或无权归档', 404);
            }

            return $this->success(['id' => $id], '转让记录已归档');
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '转让记录归档失败'), 400);
        }
    }

    private function resolveHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }

        $hotelId = $inputHotelId > 0 ? $inputHotelId : (int)$this->request->param('hotel_id', 0);
        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permitted)) {
            throw new RuntimeException('暂无可访问酒店');
        }

        if ($hotelId > 0) {
            if (!in_array($hotelId, $permitted, true)) {
                throw new RuntimeException('无权查看该酒店数据');
            }
            return [[$hotelId], $hotelId];
        }

        return [$permitted, count($permitted) === 1 ? $permitted[0] : null];
    }

    private function payloadSnapshot(array $input): array
    {
        $hasSnapshot = array_key_exists('snapshot', $input);
        $hasDataSnapshot = array_key_exists('data_snapshot', $input);
        if ($hasSnapshot && !is_array($input['snapshot'])) {
            throw new InvalidArgumentException('transfer snapshot identity scope mismatch');
        }
        if ($hasDataSnapshot && !is_array($input['data_snapshot'])) {
            throw new InvalidArgumentException('transfer snapshot identity scope mismatch');
        }
        if ($hasSnapshot && $hasDataSnapshot && $input['snapshot'] !== $input['data_snapshot']) {
            throw new InvalidArgumentException('transfer snapshot identity scope mismatch');
        }

        return $hasSnapshot
            ? $input['snapshot']
            : ($hasDataSnapshot ? $input['data_snapshot'] : []);
    }

    private function recordHotelId(array $input, array $snapshot, array $hotelIds, ?int $hotelId): int
    {
        $inputHotelId = (int)($input['hotel_id'] ?? 0);
        $snapshotHotelId = (int)($snapshot['hotel_id'] ?? 0);
        if ($inputHotelId > 0 && $snapshotHotelId > 0 && $inputHotelId !== $snapshotHotelId) {
            throw new InvalidArgumentException('transfer hotel scope mismatch');
        }
        $candidate = $inputHotelId > 0 ? $inputHotelId : $snapshotHotelId;
        if ($candidate > 0 && in_array($candidate, $hotelIds, true)) {
            return $candidate;
        }

        if ($hotelId !== null && $hotelId > 0) {
            return $hotelId;
        }

        throw new InvalidArgumentException('请先选择酒店');
    }

    private function normalizeDate(string $date): string
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('日期格式不正确');
        }

        return $date;
    }

    private function currentBusinessDate(?\DateTimeInterface $now = null): string
    {
        $timestamp = $now?->getTimestamp() ?? time();
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
    }

    private function safeErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        if ($message !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1) {
            return $message;
        }

        return $fallback;
    }

    private function sourceFailureCode(RuntimeException $e): ?string
    {
        $message = trim($e->getMessage());
        if (preg_match('/^(transfer_source_(?:schema_check|read)_failed):(daily_reports|online_daily_data|hotels)$/D', $message) !== 1) {
            return null;
        }

        return $message;
    }

    private function pricingFailureStatusCode(Throwable $e): int
    {
        $message = $e->getMessage();
        if (preg_match('/AI.*(调用失败|治理|配置|API|SECRET|LLM|模型)/u', $message) === 1) {
            return 422;
        }

        return 500;
    }
}
