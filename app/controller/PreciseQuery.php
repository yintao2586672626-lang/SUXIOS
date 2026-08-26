<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ApiExceptionMapper;
use app\service\HotelScopeService;
use app\service\PreciseQueryRouterService;
use RuntimeException;
use think\Response;
use Throwable;

final class PreciseQuery extends Base
{
    private const BUSINESS_ERRORS = [
        '未登录' => 401,
        '暂无可访问酒店' => 403,
        '精准查数问题不存在或无权访问' => 404,
        '精准查数幂等键已用于不同内容' => 409,
    ];

    private PreciseQueryRouterService $router;
    private HotelScopeService $hotelScope;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->router = new PreciseQueryRouterService();
        $this->hotelScope = new HotelScopeService();
    }

    public function create(): Response
    {
        try {
            return $this->success($this->router->route(
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view'),
                (int)($this->currentUser->id ?? 0),
                $this->requestData()
            ));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, '宿析精准查数失败', self::BUSINESS_ERRORS);
        }
    }

    public function read(int $id): Response
    {
        try {
            return $this->success($this->router->read(
                $id,
                $this->currentTenantId(),
                $this->accessibleHotels('operation.view')
            ));
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, '宿析精准查数回读失败', self::BUSINESS_ERRORS);
        }
    }

    public function lexicon(): Response
    {
        try {
            $this->accessibleHotels('operation.view');
            return $this->success($this->router->lexiconMetadata());
        } catch (Throwable $e) {
            return ApiExceptionMapper::response($e, '精准查数词库状态读取失败', self::BUSINESS_ERRORS);
        }
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

    private function currentTenantId(): int
    {
        if (!$this->currentUser || $this->currentUser->isSuperAdmin()) {
            return 0;
        }
        return max(0, (int)($this->currentUser->tenant_id ?? 0));
    }
}
