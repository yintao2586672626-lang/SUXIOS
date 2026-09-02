<?php
declare(strict_types=1);

namespace app\controller;

use app\service\SystemUsageAssistantService;
use app\service\HotelScopeService;
use app\service\UserGuidanceJourneyService;
use app\service\UserPreferenceContextService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use think\facade\Db;
use Throwable;

final class SystemGuidance extends Base
{
    private SystemUsageAssistantService $assistant;
    private HotelScopeService $hotelScope;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->assistant = new SystemUsageAssistantService();
        $this->hotelScope = new HotelScopeService();
    }

    public function guide(): Response
    {
        try {
            if (!$this->currentUser) {
                throw new RuntimeException('未登录');
            }
            $input = $this->requestData();
            $userId = max(0, (int)($this->currentUser->id ?? 0));
            $input['user_id'] = $userId;
            $input['preference_context'] = $this->serverPreferenceContext($input, $userId);
            $input['active_journey'] = $this->serverActiveJourney($input, $userId);
            return $this->success($this->assistant->guide($input), '智能引导已生成');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e), $this->status($e));
        }
    }

    private function status(Throwable $e): int
    {
        if ($e->getMessage() === '未登录') {
            return 401;
        }
        return $e instanceof InvalidArgumentException ? 422 : 500;
    }

    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof InvalidArgumentException || $e->getMessage() === '未登录') {
            return $e->getMessage();
        }
        return '智能引导暂不可用，请稍后重试';
    }

    /** @return array<string,mixed> */
    private function serverPreferenceContext(array $input, int $userId): array
    {
        $scope = is_array($input['current_scope'] ?? null) ? $input['current_scope'] : [];
        $hotelId = max(0, (int)($scope['hotel_id'] ?? 0));
        $accessible = array_values(array_unique(array_filter(array_map(
            'intval',
            $this->hotelScope->accessibleHotelIds($this->currentUser, 'operation.view')
        ), static fn(int $id): bool => $id > 0)));
        if (!in_array($hotelId, $accessible, true)) {
            $hotelId = 0;
        }
        $tenantId = $this->currentUser->isSuperAdmin()
            ? 0
            : max(0, (int)($this->currentUser->tenant_id ?? 0));
        if ($hotelId > 0) {
            $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->where('status', 1)->value('tenant_id');
        }
        return (new UserPreferenceContextService())->build(
            $tenantId,
            $userId,
            $hotelId > 0 ? $hotelId : null
        );
    }

    /** @return array<string,mixed> */
    private function serverActiveJourney(array $input, int $userId): array
    {
        $scope = is_array($input['current_scope'] ?? null) ? $input['current_scope'] : [];
        $hotelId = max(0, (int)($scope['hotel_id'] ?? 0));
        $accessible = array_values(array_unique(array_filter(array_map(
            'intval',
            $this->hotelScope->accessibleHotelIds($this->currentUser, 'operation.view')
        ), static fn(int $id): bool => $id > 0)));
        if (!in_array($hotelId, $accessible, true)) {
            $hotelId = 0;
        }
        $tenantId = $this->currentUser->isSuperAdmin()
            ? 0
            : max(0, (int)($this->currentUser->tenant_id ?? 0));
        if ($hotelId > 0) {
            $tenantId = (int)Db::name('hotels')
                ->where('id', $hotelId)
                ->where('status', 1)
                ->value('tenant_id');
        }
        $active = (new UserGuidanceJourneyService())->readActive(
            $tenantId,
            $userId,
            $hotelId > 0 ? $hotelId : null
        );
        return ($active['data_status'] ?? '') === 'ready'
            && is_array($active['journey'] ?? null)
                ? $active['journey']
                : [];
    }
}
