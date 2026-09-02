<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OperatingOpportunityLabService;
use app\service\OperatingOpportunityApprovalService;
use app\service\WeeklyOperatingPlanSnapshotService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

final class OperatingOpportunity extends Base
{
    private OperatingOpportunityLabService $service;
    private OperatingOpportunityApprovalService $approvalService;
    private WeeklyOperatingPlanSnapshotService $weeklyPlanService;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new OperatingOpportunityLabService();
        $this->approvalService = new OperatingOpportunityApprovalService($this->service);
        $this->weeklyPlanService = new WeeklyOperatingPlanSnapshotService();
    }

    public function overview(): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            $businessDate = trim((string)$this->request->param('business_date', date('Y-m-d')));
            $overview = $this->service->overview(
                $tenantId,
                $hotelId,
                $businessDate,
                (int)($this->currentUser->id ?? 0)
            );
            $runs = array_values((array)($overview['latest_runs'] ?? []));
            if (is_array($overview['today_saved_run'] ?? null)) {
                $runs[] = $overview['today_saved_run'];
            }
            try {
                $overview['approval_links'] = $this->approvalService->linkedApprovals($tenantId, $hotelId, $runs);
                $overview['approval_readback_status'] = 'ready';
                $overview['approval_readback_gap'] = null;
            } catch (Throwable $approvalError) {
                $overview['approval_links'] = [];
                $overview['approval_readback_status'] = 'unavailable';
                $overview['approval_readback_gap'] = '待审批状态暂不可回读；计算记录仍可查看，但不要重复送审。';
            }
            return $this->success($overview);
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '读取经营机会失败'), $this->statusCode($e));
        }
    }

    public function evaluate(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );
            return $this->success($this->service->evaluateAndSave(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                $input
            ), '计算结果已保存并完成精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营机会计算失败'), $this->statusCode($e));
        }
    }

    public function priority(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );
            return $this->success($this->service->saveDailyPriority(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                (string)($input['business_date'] ?? ''),
                (string)($input['idempotency_key'] ?? '')
            ), '今日一件事已保存并完成精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '生成今日一件事失败'), $this->statusCode($e));
        }
    }

    public function dailyPreviewFeedback(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.view',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );
            return $this->success($this->service->recordDailyPreviewFeedback(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                (string)($input['business_date'] ?? ''),
                (string)($input['expected_selection_digest'] ?? ''),
                (string)($input['expected_context_digest'] ?? ''),
                (string)($input['expected_decision_digest'] ?? ''),
                (string)($input['feedback_status'] ?? ''),
                (string)($input['reason_code'] ?? ''),
                (string)($input['idempotency_key'] ?? '')
            ), '个人预览反馈已保存并精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '个人预览反馈保存失败'), $this->statusCode($e));
        }
    }

    public function read(int $id): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            return $this->success($this->service->readRun($tenantId, $hotelId, $id));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '读取经营机会记录失败'), $this->statusCode($e));
        }
    }

    public function pendingApproval(int $id): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );
            return $this->success($this->approvalService->createPendingApproval(
                $tenantId,
                $hotelId,
                $id,
                (int)($this->currentUser->id ?? 0),
                trim((string)($input['business_date'] ?? '')),
                trim((string)($input['expected_input_digest'] ?? '')),
                trim((string)($input['expected_result_digest'] ?? ''))
            ), '人工待审批已保存并精确回读；尚未审批、未创建任务、未写OTA');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '创建经营机会待审批失败'), $this->statusCode($e));
        }
    }

    public function weeklyPlanLatest(): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            $weekEnd = trim((string)$this->request->param('week_end', ''));
            if ($weekEnd === '') {
                $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
                $weekEnd = $today->modify('-' . (int)$today->format('N') . ' days')->format('Y-m-d');
            }
            return $this->success($this->weeklyPlanService->readLatest(
                $tenantId,
                $hotelId,
                $weekEnd
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '读取周度经营计划失败'), $this->statusCode($e));
        }
    }

    public function weeklyPlanRead(int $id): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            return $this->success($this->weeklyPlanService->readExact(
                $tenantId,
                $hotelId,
                $id
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '读取周度经营计划快照失败'), $this->statusCode($e));
        }
    }

    /** @return array{0:int,1:int} */
    private function resolveSingleHotelScope(string $capability, int $inputHotelId = 0): array
    {
        if (!$this->currentUser) throw new RuntimeException('未登录');
        $hotelId = $inputHotelId > 0
            ? $inputHotelId
            : (int)$this->request->param('hotel_id', $this->request->param('system_hotel_id', 0));
        if ($hotelId <= 0) throw new InvalidArgumentException('请选择单个酒店');
        $permitted = array_values(array_filter(array_map('intval', $this->currentUser->getPermittedHotelIds()), static fn(int $id): bool => $id > 0));
        if (!in_array($hotelId, $permitted, true) || !$this->currentUser->hasHotelPermission($hotelId, $capability)) {
            throw new RuntimeException($capability === 'operation.execute' ? '无权限保存该酒店经营机会结果' : '无权查看该酒店经营机会结果');
        }
        return [$this->service->hotelTenantId($hotelId), $hotelId];
    }

    private function statusCode(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException) return 422;
        if ((int)$e->getCode() === 409) return 409;
        if ((int)$e->getCode() === 404) return 404;
        if ((int)$e->getCode() === 503) return 503;
        $message = trim($e->getMessage());
        if ($message === '未登录') return 401;
        if (str_contains($message, '无权限') || str_contains($message, '无权')) return 403;
        if (str_contains($message, '不存在') || str_contains($message, '已停用')) return 404;
        if (str_contains($message, '数据表未就绪') || str_contains($message, '租户边界未就绪')) return 503;
        return 500;
    }

    private function safeMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        return $message !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1 ? $message : $fallback;
    }
}
