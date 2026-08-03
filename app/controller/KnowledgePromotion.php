<?php
declare(strict_types=1);

namespace app\controller;

use app\service\HotelScopeService;
use app\service\KnowledgePromotionService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

/**
 * Authenticated controller surface for the formal knowledge-promotion
 * workbench. Route registration is intentionally kept outside this class.
 */
final class KnowledgePromotion extends Base
{
    private KnowledgePromotionService $promotionService;
    private HotelScopeService $hotelScope;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->promotionService = new KnowledgePromotionService();
        $this->hotelScope = new HotelScopeService();
    }

    public function createCandidate(): Response
    {
        try {
            $input = $this->requestData();
            $hotelIds = $this->accessibleHotels('operation.execute');
            $sourceVersionId = (int)($input['source_sop_candidate_version_id'] ?? 0);
            if ($sourceVersionId <= 0) {
                throw new InvalidArgumentException('请选择需要晋级的候选SOP版本');
            }
            $source = Db::name('hotel_operating_sop_versions')
                ->where('id', $sourceVersionId)
                ->whereIn('hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->find();
            if (!is_array($source)) {
                throw new RuntimeException('operating SOP version not found');
            }
            return $this->success($this->promotionService->createFromSopCandidate(
                $sourceVersionId,
                (int)$source['tenant_id'],
                $hotelIds,
                $this->currentUserId(),
                (string)($input['idempotency_key'] ?? '')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '正式知识候选建立失败'), $this->status($e));
        }
    }

    public function candidates(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId > 0 && !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('无权查看该酒店的知识晋级候选');
            }
            return $this->success($this->promotionService->listCandidates(
                $hotelId > 0 ? $this->tenantForHotel($hotelId) : $this->currentTenantId(),
                $hotelIds,
                $hotelId > 0 ? $hotelId : null,
                $this->nullableString($this->request->param('workflow_status', null))
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '知识晋级工作台查询失败'), $this->status($e));
        }
    }

    public function readCandidate(int $id): Response
    {
        try {
            return $this->success($this->promotionService->readCandidate(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '知识晋级候选回读失败'), $this->status($e));
        }
    }

    public function events(int $id): Response
    {
        try {
            return $this->success($this->promotionService->listEvents(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '知识晋级事件查询失败'), $this->status($e));
        }
    }

    public function createRevision(int $id): Response
    {
        try {
            [$tenantId, $hotelIds] = $this->writeScopeForCandidate($id);
            return $this->success($this->promotionService->createRevision(
                $id,
                $tenantId,
                $hotelIds,
                $this->requestData(),
                $this->currentUserId()
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '候选修订保存失败'), $this->status($e));
        }
    }

    public function submit(int $id): Response
    {
        try {
            [$tenantId, $hotelIds] = $this->writeScopeForCandidate($id);
            return $this->success($this->promotionService->submit(
                $id,
                $tenantId,
                $hotelIds,
                $this->requestData(),
                $this->currentUserId()
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '候选提交审核失败'), $this->status($e));
        }
    }

    public function review(int $id): Response
    {
        try {
            [$tenantId, $hotelIds] = $this->writeScopeForCandidate($id);
            return $this->success($this->promotionService->review(
                $id,
                $tenantId,
                $hotelIds,
                $this->requestData(),
                $this->currentUserId()
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '候选审核失败'), $this->status($e));
        }
    }

    public function withdraw(int $id): Response
    {
        try {
            [$tenantId, $hotelIds] = $this->writeScopeForCandidate($id);
            return $this->success($this->promotionService->withdraw(
                $id,
                $tenantId,
                $hotelIds,
                $this->requestData(),
                $this->currentUserId()
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '候选撤回或正式版本停用失败'), $this->status($e));
        }
    }

    /** @return array{0:int,1:list<int>} */
    private function writeScopeForCandidate(int $candidateId): array
    {
        $hotelIds = $this->accessibleHotels('operation.execute');
        $candidate = $this->promotionService->readCandidate($candidateId, 0, $hotelIds);
        return [(int)$candidate['tenant_id'], $hotelIds];
    }

    /** @return list<int> */
    private function accessibleHotels(string $capability): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            $this->hotelScope->accessibleHotelIds($this->currentUser, $capability)
        ), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new RuntimeException('暂无可访问酒店');
        }
        return $ids;
    }

    private function tenantForHotel(int $hotelId): int
    {
        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->where('status', 1)->value('tenant_id');
        if ($tenantId <= 0) {
            throw new RuntimeException('酒店租户身份缺失');
        }
        return $tenantId;
    }

    private function currentTenantId(): int
    {
        if (!$this->currentUser || $this->currentUser->isSuperAdmin()) {
            return 0;
        }
        return max(0, (int)($this->currentUser->tenant_id ?? 0));
    }

    private function currentUserId(): int
    {
        $id = (int)($this->currentUser->id ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('未登录');
        }
        return $id;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function status(Throwable $e): int
    {
        if ($e->getMessage() === '未登录') {
            return 401;
        }
        if (str_contains($e->getMessage(), '无权') || str_contains($e->getMessage(), '租户身份不一致')) {
            return 403;
        }
        if (str_contains(strtolower($e->getMessage()), 'not found')) {
            return 404;
        }
        return $e instanceof InvalidArgumentException ? 422 : 500;
    }

    private function safeMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        if ($message !== '' && (
            $e instanceof InvalidArgumentException
            || $e instanceof RuntimeException
            || preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1
        )) {
            return $message;
        }
        return $fallback;
    }
}
