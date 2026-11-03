<?php
declare(strict_types=1);

namespace app\controller;

use app\service\CampaignOperationsService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

final class CampaignOperations extends Base
{
    private CampaignOperationsService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new CampaignOperationsService();
    }

    public function overview(): Response
    {
        return $this->handle(function (): array {
            [$tenantId, $hotelId] = $this->scope('operation.view');
            return $this->service->overview($tenantId, $hotelId, (string)$this->request->param('business_date', date('Y-m-d')));
        });
    }

    public function save(): Response
    {
        return $this->handle(function (): array {
            $input = $this->requestData();
            if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 65536) throw new InvalidArgumentException('单次记录内容超出上限');
            [$tenantId, $hotelId] = $this->scope('operation.execute', (int)($input['hotel_id'] ?? 0));
            return $this->service->save($tenantId, $hotelId, (int)$this->currentUser->id, $input);
        });
    }

    public function read(int $id): Response
    {
        return $this->handle(function () use ($id): array {
            [$tenantId, $hotelId] = $this->scope('operation.view');
            return $this->service->read($tenantId, $hotelId, $id);
        });
    }

    public function handoverAction(int $id): Response
    {
        return $this->handle(function () use ($id): array {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->scope('operation.execute', (int)($input['hotel_id'] ?? 0));
            return $this->service->handoverAction($tenantId, $hotelId, (int)$this->currentUser->id, $id, $input);
        });
    }

    public function artifact(int $id): Response
    {
        return $this->handle(function () use ($id): array {
            [$tenantId, $hotelId] = $this->scope('operation.view');
            return $this->service->artifact($tenantId, $hotelId, $id, (string)$this->request->param('format', 'html'));
        });
    }

    private function scope(string $permission, int $hotelId = 0): array
    {
        if (!$this->currentUser) throw new RuntimeException('未登录', 401);
        if ($hotelId <= 0) $hotelId = (int)$this->request->param('hotel_id', 0);
        if ($hotelId <= 0) throw new InvalidArgumentException('请选择单个酒店');
        $ids = array_map('intval', $this->currentUser->getPermittedHotelIds());
        if (!in_array($hotelId, $ids, true) || !$this->currentUser->hasHotelPermission($hotelId, $permission)) {
            throw new RuntimeException('无权操作该酒店业务工作区', 403);
        }
        return [$this->service->hotelTenantId($hotelId), $hotelId];
    }

    private function handle(callable $action): Response
    {
        try { return $this->success($action()); }
        catch (Throwable $e) {
            $code = $e instanceof InvalidArgumentException ? 422 : (int)$e->getCode();
            if (!in_array($code, [401, 403, 404, 409, 422, 503], true)) $code = 500;
            $message = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException)
                && preg_match('/[\x{4e00}-\x{9fff}]/u', $e->getMessage()) ? $e->getMessage() : '业务工作区操作失败，请刷新后重试';
            return $this->error($message, $code);
        }
    }
}
