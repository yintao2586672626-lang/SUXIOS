<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\OperationLog;
use app\model\SystemConfig;
use app\service\HotelScopeService;
use app\service\OperatingLoopKernelService;
use app\service\PermissionService;
use InvalidArgumentException;
use think\facade\Db;
use think\Response;

class Compass extends Base
{
    private const LAYOUT_KEY = 'compass_layout';

    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        $this->requireHotel();
    }

    public function index(): Response
    {
        $this->checkPermission();

        // The SPA Compass is the only user-facing authority surface. Keeping a
        // second server-rendered dashboard here would allow two pages to imply
        // different operating states.
        return redirect('/');
    }

    public function apiIndex(): Response
    {
        $this->checkPermission();
        $hotelId = $this->resolveHotelId((string)$this->request->get('hotel_id', ''));
        $businessDate = trim((string)$this->request->get('business_date', ''));
        if (!$this->validBusinessDate($businessDate)) {
            return $this->error('business_date 必须是明确的 YYYY-MM-DD 业务日期', 422);
        }
        return $this->success($this->buildCompassData($hotelId, $businessDate));
    }

    public function saveLayout(): Response
    {
        $this->checkPermission();

        $data = $this->request->post();
        $order = $data['order'] ?? [];
        $hidden = $data['hidden'] ?? [];
        $quickEntries = $data['quick_entries'] ?? [];
        if (!is_array($order) || !is_array($hidden) || !is_array($quickEntries)) {
            return json(['code' => 400, 'message' => '参数错误']);
        }

        $allowed = $this->getDefaultLayout()['order'];
        $order = array_values(array_filter($order, fn($key) => in_array($key, $allowed, true)));
        $hidden = array_values(array_filter($hidden, fn($key) => in_array($key, $allowed, true)));
        if (empty($order)) {
            $order = $allowed;
        }
        $defaultQuickEntries = $this->getDefaultQuickEntries();
        $quickAllowed = $defaultQuickEntries['order'];
        $quickOrder = $quickEntries['order'] ?? $quickAllowed;
        $quickHidden = $quickEntries['hidden'] ?? [];
        if (!is_array($quickOrder)) {
            $quickOrder = $quickAllowed;
        }
        if (!is_array($quickHidden)) {
            $quickHidden = [];
        }
        $quickOrder = array_values(array_filter($quickOrder, fn($key) => in_array($key, $quickAllowed, true)));
        $quickHidden = array_values(array_filter($quickHidden, fn($key) => in_array($key, $quickAllowed, true)));
        foreach ($quickAllowed as $key) {
            if (!in_array($key, $quickOrder, true)) {
                $quickOrder[] = $key;
            }
        }

        SystemConfig::setValue($this->layoutConfigKey(), json_encode([
            'order' => $order,
            'hidden' => $hidden,
            'quick_entries' => [
                'order' => $quickOrder,
                'hidden' => $quickHidden,
            ],
        ], JSON_UNESCAPED_UNICODE), $this->layoutConfigDescription());

        OperationLog::record('compass', 'update_layout', '更新门店罗盘板块排序', $this->currentUser->id);

        return $this->success(null, '保存成功');
    }

    public function apiSaveLayout(): Response
    {
        return $this->saveLayout();
    }

    private function getLayoutConfig(): array
    {
        $default = $this->getDefaultLayout();
        $raw = SystemConfig::getValue($this->layoutConfigKey(), '');
        if (!$raw && $this->currentUser && !$this->currentUser->isSuperAdmin()) {
            $raw = SystemConfig::getValue(self::LAYOUT_KEY, '');
        }
        if (!$raw) {
            return $default;
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return $default;
        }
        $order = isset($data['order']) && is_array($data['order']) ? $data['order'] : $default['order'];
        $hidden = isset($data['hidden']) && is_array($data['hidden']) ? $data['hidden'] : [];
        $quickEntries = isset($data['quick_entries']) && is_array($data['quick_entries']) ? $data['quick_entries'] : $default['quick_entries'];
        $allowed = $default['order'];
        $order = array_values(array_filter($order, fn($key) => in_array($key, $allowed, true)));
        $hidden = array_values(array_filter($hidden, fn($key) => in_array($key, $allowed, true)));
        if (empty($order)) {
            $order = $default['order'];
        }
        $quickAllowed = $default['quick_entries']['order'];
        $quickOrder = isset($quickEntries['order']) && is_array($quickEntries['order']) ? $quickEntries['order'] : $quickAllowed;
        $quickHidden = isset($quickEntries['hidden']) && is_array($quickEntries['hidden']) ? $quickEntries['hidden'] : [];
        $quickOrder = array_values(array_filter($quickOrder, fn($key) => in_array($key, $quickAllowed, true)));
        $quickHidden = array_values(array_filter($quickHidden, fn($key) => in_array($key, $quickAllowed, true)));
        foreach ($quickAllowed as $key) {
            if (!in_array($key, $quickOrder, true)) {
                $quickOrder[] = $key;
            }
        }
        return [
            'order' => $order,
            'hidden' => $hidden,
            'quick_entries' => [
                'order' => $quickOrder,
                'hidden' => $quickHidden,
            ],
        ];
    }

    private function layoutConfigKey(): string
    {
        if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
            $userId = max(0, (int)($this->currentUser->id ?? 0));
            return self::LAYOUT_KEY . '_user_' . $userId;
        }

        return self::LAYOUT_KEY;
    }

    private function layoutConfigDescription(): string
    {
        return $this->currentUser && !$this->currentUser->isSuperAdmin()
            ? '门店罗盘用户板块布局'
            : '门店罗盘板块布局';
    }

    private function getDefaultLayout(): array
    {
        return [
            'order' => ['weather', 'todo', 'metrics', 'alerts', 'holiday'],
            'hidden' => [],
            'quick_entries' => $this->getDefaultQuickEntries(),
        ];
    }

    private function getDefaultQuickEntries(): array
    {
        return [
            'order' => ['online-data', 'operation-diagnosis', 'strategy-simulation', 'ai-tools', 'hotel-management', 'system-settings'],
            'hidden' => [],
        ];
    }

    private function buildCompassData(int $hotelId, string $businessDate = ''): array
    {
        if (!$this->validBusinessDate($businessDate)) {
            throw new InvalidArgumentException('business_date 必须是明确的 YYYY-MM-DD 业务日期');
        }
        $hotel = $hotelId > 0
            ? Db::name('hotels')->where('id', $hotelId)->where('status', 1)->field(['id', 'tenant_id'])->find()
            : null;
        $tenantId = is_array($hotel) ? (int)($hotel['tenant_id'] ?? 0) : 0;
        $operatingLoop = (new OperatingLoopKernelService())->currentForHotelDate(
            $tenantId,
            $hotelId,
            $businessDate
        );
        $legacyPanels = [
            'weather' => [],
            'todos' => [],
            'metrics' => [
                'day' => (object)[],
                'week' => (object)[],
                'month' => (object)[],
                'data_status' => 'not_loaded',
                'source_policy' => 'diagnostic_only_no_operating_authority',
            ],
            'alerts' => [],
            'holidays' => [],
        ];

        return array_merge($legacyPanels, [
            'layout' => $this->getLayoutConfig(),
            'operating_loop' => $operatingLoop,
            'authority_status' => [
                'state' => (string)($operatingLoop['authoritative_state'] ?? 'not_started'),
                'readback_verified' => ($operatingLoop['readback_verified'] ?? false) === true,
                'kernel_id' => $operatingLoop['kernel_id'] ?? null,
                'revision' => (int)($operatingLoop['revision'] ?? 0),
            ],
            'drilldowns' => array_merge(['diagnostic_only' => true], $legacyPanels),
            'contract_status' => [
                'todos' => 'not_loaded',
                'weather' => 'not_loaded',
                'metrics' => 'not_loaded',
                'alerts' => 'not_loaded',
                'holidays' => 'not_loaded',
                'weather_source_policy' => 'compass_contract_only_no_weather_facts',
                'source_policy' => 'hotel_operating_cycle_kernel_only',
            ],
        ]);
    }

    private function resolveHotelId(string $hotelIdParam): int
    {
        $hotelId = $hotelIdParam !== ''
            ? (int)$hotelIdParam
            : (int)($this->currentUser->hotel_id ?? 0);
        if ($hotelId <= 0) {
            abort(403, '您未关联酒店，请联系管理员');
        }
        $hotelScope = new HotelScopeService();
        $authorization = (new PermissionService($hotelScope))->authorize(
            $this->currentUser,
            'operation.view',
            $hotelId
        );
        if (($authorization['allowed'] ?? false) !== true) {
            abort(403, '无权查看该酒店经营闭环');
        }
        return $hotelId;
    }

    private function validBusinessDate(string $businessDate): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        return $date !== false && $date->format('Y-m-d') === $businessDate;
    }

}
