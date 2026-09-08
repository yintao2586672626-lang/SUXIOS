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
            $page = 1;
            if ($operation === 'history') {
                $submittedPage = $input['page'] ?? 1;
                if (!is_int($submittedPage) && !(is_string($submittedPage) && ctype_digit($submittedPage))) throw new InvalidArgumentException('历史页码须为正整数。');
                $page = filter_var($submittedPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($page === false) throw new InvalidArgumentException('历史页码须为正整数。');
            }
            $payload = ['evidence' => $input['evidence'] ?? []];
            if (isset($input['scenario'])) $payload['scenario'] = $input['scenario'];
            $data = match ($operation) {
                'context' => ['scope' => $scope, 'source_status' => 'manual_unverified'],
                'preview' => $this->service->preview($payload, $scope),
                'save' => $this->service->save($payload, $scope),
                'history' => ['scope' => $scope] + $this->service->historyPage($scope, $page),
                'read' => $this->service->read($id, $scope),
            };
            return $this->success($data);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        catch (\Throwable $e) {
            $message = match ($operation) {
                'preview' => '回测计算失败；本次没有生成结果，请重试。',
                'save' => '方案保存失败；未确认保存成功，请先读取历史核对，再重试保存。',
                'history' => '历史方案读取失败；尚未取得历史列表，请重试或联系维护人员检查存储。',
                'read' => '方案回读校验失败；当前文件未确认为可用，请重试或重新导入原始证据。',
                default => '范围核对失败；请重试或联系维护人员。',
            };
            return $this->error($message, 500, ['operation' => $operation, 'status' => 'failed']);
        }
    }

    private function resolveTenant(int $hotelId): int
    {
        return (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
    }
}
