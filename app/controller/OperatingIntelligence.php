<?php
declare(strict_types=1);

namespace app\controller;

use app\service\HotelScopeService;
use app\service\OperatingQuestionService;
use app\service\OperatingSopService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

final class OperatingIntelligence extends Base
{
    private OperatingQuestionService $questionService;
    private OperatingSopService $sopService;
    private HotelScopeService $hotelScope;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->questionService = new OperatingQuestionService();
        $this->sopService = new OperatingSopService();
        $this->hotelScope = new HotelScopeService();
    }

    public function createQuestion(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelId, $tenantId] = $this->resolveHotel((int)($input['hotel_id'] ?? 0), 'operation.view');
            return $this->success($this->questionService->create(
                $tenantId,
                $hotelId,
                (string)($input['question'] ?? ''),
                (string)($input['platform'] ?? ''),
                (string)($input['date_start'] ?? ''),
                (string)($input['date_end'] ?? ''),
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营问题保存失败'), $this->status($e));
        }
    }

    public function questions(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId > 0 && !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('无权查看该酒店经营问题');
            }
            $tenantId = $hotelId > 0 ? $this->tenantForHotel($hotelId) : $this->currentTenantId();
            return $this->success($this->questionService->list(
                $tenantId,
                $hotelIds,
                $hotelId > 0 ? $hotelId : null
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营问题查询失败'), $this->status($e));
        }
    }

    public function readQuestion(int $id): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            return $this->success($this->questionService->read(
                $id,
                $this->currentTenantId(),
                $hotelIds
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营问题回读失败'), $this->status($e));
        }
    }

    public function createSop(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelId, $tenantId] = $this->resolveHotel((int)($input['hotel_id'] ?? 0), 'operation.execute');
            $memoryIds = is_array($input['source_memory_ids'] ?? null) ? $input['source_memory_ids'] : [];
            return $this->success($this->sopService->createCandidate(
                $tenantId,
                $hotelId,
                $memoryIds,
                $input,
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '候选SOP保存失败'), $this->status($e));
        }
    }

    public function sops(): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.view');
            $hotelId = (int)$this->request->param('hotel_id', 0);
            if ($hotelId > 0 && !in_array($hotelId, $hotelIds, true)) {
                throw new RuntimeException('无权查看该酒店SOP');
            }
            return $this->success($this->sopService->listVersions(
                $hotelId > 0 ? $this->tenantForHotel($hotelId) : $this->currentTenantId(),
                $hotelIds,
                $hotelId > 0 ? $hotelId : null
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营SOP查询失败'), $this->status($e));
        }
    }

    public function readSop(int $id): Response
    {
        try {
            return $this->success($this->sopService->readVersion(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营SOP回读失败'), $this->status($e));
        }
    }

    public function validateSop(int $id): Response
    {
        try {
            $hotelIds = $this->accessibleHotels('operation.execute');
            $current = $this->sopService->readVersion($id, 0, $hotelIds);
            return $this->success($this->sopService->validateVersion(
                $id,
                (int)$current['tenant_id'],
                $hotelIds,
                $this->requestData(),
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营SOP验证失败'), $this->status($e));
        }
    }

    public function replicateSop(int $id): Response
    {
        try {
            $input = $this->requestData();
            $hotelIds = $this->accessibleHotels('operation.execute');
            $source = $this->sopService->readVersion($id, 0, $hotelIds);
            return $this->success($this->sopService->replicate(
                $id,
                (int)$source['tenant_id'],
                $hotelIds,
                (int)($input['target_hotel_id'] ?? 0),
                (int)($this->currentUser->id ?? 0)
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '跨店复制草稿保存失败'), $this->status($e));
        }
    }

    public function readReplication(int $id): Response
    {
        try {
            return $this->success($this->sopService->readReplication(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '跨店复制草稿回读失败'), $this->status($e));
        }
    }

    /** @return array{0:int,1:int} */
    private function resolveHotel(int $hotelId, string $capability): array
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('请选择单个酒店');
        }
        $hotelIds = $this->accessibleHotels($capability);
        if (!in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权访问或操作该酒店');
        }
        return [$hotelId, $this->tenantForHotel($hotelId)];
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
