<?php
declare(strict_types=1);
namespace app\controller;

use app\service\HotelLearningMechanismService;
use app\service\HotelLearningSnapshotService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\Response;

/** Local source-inspired business records. No external action or real investment entry. */
final class HotelLearning extends Base
{
    public function overview(): Response { return $this->run('overview'); }
    public function preview(): Response { return $this->run('preview'); }
    public function save(): Response { return $this->run('save'); }
    public function read(int $id): Response { return $this->run('read', $id); }

    private function run(string $action, int $id = 0): Response
    {
        try {
            $request = in_array($action, ['save', 'preview'], true) ? $this->requestData() : $this->request->param();
            $hotelValue = $request['hotel_id'] ?? null;
            if (!is_scalar($hotelValue) || is_bool($hotelValue) || !preg_match('/^[1-9]\d{0,9}$/D', (string)$hotelValue)) throw new InvalidArgumentException('请选择有效酒店');
            $hotel = (int)$hotelValue;
            foreach (['mode', 'period_month', 'platform'] as $field) {
                if (!is_string($request[$field] ?? null)) throw new InvalidArgumentException('业务方法、月份和平台须为有效文本');
            }
            $mode = (string)($request['mode'] ?? '');
            $capability = in_array($mode, ['investment_target', 'contract_review'], true) ? 'investment.simulate' : ($action === 'save' ? 'operation.execute' : 'operation.view');
            if ($denied = $this->hotelCapabilityDeniedResponse($hotel, $capability, '当前账号没有该酒店的业务权限')) return $denied;
            $store = new HotelLearningSnapshotService();
            $scope = $store->scope((int)($this->currentUser->tenant_id ?? 0), $this->currentUser->getPermittedHotelIds(), $hotel,
                (string)($request['period_month'] ?? ''), (string)($request['platform'] ?? ''), $mode);
            if ($action === 'overview') {
                $writeCapability = in_array($mode, ['investment_target', 'contract_review'], true) ? 'investment.simulate' : 'operation.execute';
                $allowed = (new \app\service\PermissionService())->authorize($this->currentUser, $writeCapability, $hotel);
                return $this->success(['scope' => $scope, 'latest' => $store->latest($scope), 'history' => $store->history($scope), 'can_execute' => ($allowed['allowed'] ?? false) === true]);
            }
            if ($action === 'read') return $this->success($store->read($scope, $id));
            if (!is_array($request['inputs'] ?? null)) throw new InvalidArgumentException('请填写业务输入');
            if ($action === 'save' && !is_string($request['idempotency_key'] ?? null)) throw new InvalidArgumentException('保存请求标识须为有效文本，请重试');
            $inputs = $request['inputs'];
            $result = (new HotelLearningMechanismService())->calculate($mode, $inputs);
            $this->validateDates($mode, $result['inputs'], $scope['period_month']);
            if ($action === 'preview') return $this->success(['scope' => $scope, 'inputs' => $result['inputs'], 'result' => $result,
                'status' => $result['status'], 'readback_verified' => false]);
            return $this->success($store->save($scope, $result, (string)($request['idempotency_key'] ?? ''), (int)$this->currentUser->id), '业务版本已保存并准确回读');
        } catch (Throwable $e) {
            $code = $e instanceof InvalidArgumentException ? 422 : ($e instanceof RuntimeException && in_array($e->getCode(), [403, 404, 409], true) ? $e->getCode() : 500);
            return $this->error($code === 500 ? '业务记录暂时不可用，请重试；未保存成功' : $e->getMessage(), $code);
        }
    }

    private function validateDates(string $mode, array $input, string $month): void
    {
        $dates = [];
        if ($mode === 'consumables_reconciliation') foreach ($input['items'] ?? [] as $item) {
            if (($item['enabled'] ?? false) === true && !empty($item['source_date'])) $dates[] = $item['source_date'];
        }
        if ($mode === 'profile') foreach ($input['fields'] ?? [] as $field) if (!empty($field['as_of'])) $dates[] = $field['as_of'];
        if ($mode === 'investment_target' && !empty($input['scenario']['as_of'])) $dates[] = $input['scenario']['as_of'];
        if ($mode === 'contract_review' && !empty($input['as_of'])) $dates[] = $input['as_of'];
        if (in_array($mode, ['ota_scene', 'geo_observation'], true)) {
            $time = $mode === 'ota_scene' ? ($input['scene']['observed_at'] ?? '') : ($input['observed_at'] ?? '');
            if ($time !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:[+]08:00)?$/', (string)$time)) throw new InvalidArgumentException('观察时点需完整日期和时间，按北京时间填写');
                if ((int)substr((string)$time, 11, 2) > 23 || (int)substr((string)$time, 14, 2) > 59 || (strlen((string)$time) >= 19 && (int)substr((string)$time, 17, 2) > 59)) throw new InvalidArgumentException('观察时点无效');
                $parsed = date_create_immutable((string)$time);
                if (!$parsed || $parsed->format('Y-m-d') !== substr((string)$time, 0, 10)) throw new InvalidArgumentException('观察时点无效');
                $dates[] = substr((string)$time, 0, 10);
            }
        }
        if ($mode === 'operating_review') { $dates[] = $input['period_start'] ?? ''; $dates[] = $input['period_end'] ?? ''; }
        foreach ($dates as $date) if (substr((string)$date, 0, 7) !== $month) throw new InvalidArgumentException('输入的来源或基准日期必须属于当前业务月份');
    }
}
