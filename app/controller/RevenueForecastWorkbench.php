<?php
declare(strict_types=1);

namespace app\controller;

use app\service\RevenueForecastWorkbenchService;
use app\service\TemporalForecastReplayService;
use InvalidArgumentException;
use think\App;
use think\facade\Db;
use think\Response;

final class RevenueForecastWorkbench extends Base
{
    public function __construct(App $app, private ?RevenueForecastWorkbenchService $service = null)
    {
        parent::__construct($app);
        $this->service ??= new RevenueForecastWorkbenchService();
    }

    public function preview(): Response { return $this->perform('preview'); }
    public function save(): Response { return $this->perform('save'); }
    public function history(): Response { return $this->perform('history'); }
    public function detail(string $id): Response { return $this->perform('read', $id); }
    public function context(): Response { return $this->perform('context'); }

    private function perform(string $operation, string $id = ''): Response
    {
        try {
            if (!$this->currentUser) return $this->error('请先登录。', 401);
            $input = in_array($operation, ['preview', 'save'], true) ? $this->requestData() : $this->request->get();
            if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 2000000) throw new InvalidArgumentException('证据输入超过2MB。');
            $submittedHotel = $input['hotel_id'] ?? null;
            if (!is_int($submittedHotel) && !(is_string($submittedHotel) && ctype_digit($submittedHotel))) throw new InvalidArgumentException('酒店编号须为正整数。');
            $hotelId = filter_var($submittedHotel, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$hotelId) throw new InvalidArgumentException('请选择酒店。');
            if (!$this->currentUser->isSuperAdmin() && !in_array($hotelId, array_map('intval', $this->currentUser->getPermittedHotelIds()), true)) {
                return $this->error('无权访问该酒店。', 403);
            }
            // Resolve tenant from the live hotel binding, never from submitted evidence.
            $tenantId = $this->resolveTenant($hotelId);
            if ($tenantId <= 0) return $this->error('酒店租户绑定不可用。', 422);
            $scope = ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'platform' => $input['platform'] ?? '',
                'platform_store_id' => $input['platform_store_id'] ?? '', 'room_scope' => $input['room_scope'] ?? ''];
            foreach (['platform_store_id', 'room_scope'] as $key) {
                if (is_string($scope[$key])) $scope[$key] = trim($scope[$key]);
            }
            (new TemporalForecastReplayService())->scope($scope);
            $payload = ['evidence' => $input['evidence'] ?? []];
            if (isset($input['scenario'])) $payload['scenario'] = $input['scenario'];
            $data = match ($operation) {
                'context' => ['scope' => $scope, 'source_status' => 'manual_unverified'],
                'preview' => $this->service->preview($payload, $scope),
                'save' => $this->service->save($payload, $scope),
                'history' => ['scope' => $scope, 'items' => $this->service->history($scope)],
                'read' => $this->service->read($id, $scope),
            };
            return $this->success($data);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        catch (\Throwable $e) { return $this->error('预测方案处理失败；未确认保存或回读成功，请重试或核对输入。', 500); }
    }

    private function resolveTenant(int $hotelId): int
    {
        return (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
    }
}
