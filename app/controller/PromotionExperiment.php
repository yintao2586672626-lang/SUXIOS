<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OperatingOpportunityLabService;
use app\service\PaidTrafficReturnService;
use app\service\PromotionExperimentAssessmentService;
use app\service\PromotionExperimentService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

final class PromotionExperiment extends Base
{
    public function preview(): Response { return $this->write(false); }
    public function save(): Response { return $this->write(true); }

    public function history(): Response
    {
        return $this->respond(fn() => (new PromotionExperimentService())->history($this->scope($this->request->get(), false)));
    }

    public function read(int $id): Response
    {
        return $this->respond(fn() => (new PromotionExperimentService())->read($this->scope($this->request->get(), false), $id));
    }

    private function write(bool $save): Response
    {
        return $this->respond(function () use ($save) {
            $request = $this->requestData();
            $scope = $this->scope($request['scope'] ?? [], $save);
            $input = $request['input'] ?? [];
            if (!is_array($input)) throw new InvalidArgumentException('实验输入格式无效');
            if (isset($input['scope']) && $input['scope'] != $scope) throw new InvalidArgumentException('输入范围与请求不一致');
            $input['scope'] = $scope;
            // Public manual endpoints cannot self-certify trusted OTA or financial facts.
            if (!is_array($input['records'] ?? [])) throw new InvalidArgumentException('推广记录格式无效');
            foreach ($input['records'] ?? [] as $i => $row) {
                if (!is_array($row)) throw new InvalidArgumentException('推广记录格式无效');
                // Empty manual forms inherit the authenticated scope; explicit conflicting identities are rejected below.
                foreach (['tenant_id', 'system_hotel_id'] as $field) if (!array_key_exists($field, $row)) $input['records'][$i][$field] = $scope[$field];
                $input['records'][$i]['source_method'] = 'manual_import';
                $input['records'][$i]['source_quality'] = 'manual_unverified';
            }
            if (!is_array($input['observation'] ?? [])) throw new InvalidArgumentException('观察记录格式无效');
            $input['observation']['source_method'] = 'manual_input';
            $input['observation']['source_quality'] = 'manual_unverified';
            if (!$save) return (new PromotionExperimentAssessmentService())->evaluate($input);
            $request['input'] = $input;
            return (new PromotionExperimentService())->save($scope, (int)$this->currentUser->id, $request);
        });
    }

    private function scope(array $input, bool $write): array
    {
        if (!$this->currentUser) throw new RuntimeException('未登录', 401);
        $hotel = filter_var($input['system_hotel_id'] ?? $input['hotel_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$hotel || $hotel <= 0) throw new InvalidArgumentException('请选择单个酒店');
        $allowed = array_map('intval', $this->currentUser->getPermittedHotelIds());
        if (!in_array($hotel, $allowed, true) || !$this->currentUser->hasHotelPermission($hotel, $write ? 'operation.execute' : 'operation.view')) throw new RuntimeException('无权访问该酒店推广实验', 403);
        $tenant = (new OperatingOpportunityLabService())->hotelTenantId($hotel);
        if (isset($input['tenant_id']) && (int)$input['tenant_id'] !== $tenant) throw new RuntimeException('租户范围不匹配', 403);
        return PaidTrafficReturnService::scope(array_replace($input, ['tenant_id' => $tenant, 'system_hotel_id' => $hotel]));
    }

    private function respond(callable $action): Response
    {
        try { return $this->success($action()); }
        catch (Throwable $e) {
            $code = $e instanceof InvalidArgumentException ? 422 : (in_array($e->getCode(), [401, 403, 404, 409], true) ? $e->getCode() : 503);
            return $this->error($code === 503 ? '推广实验服务暂不可用，请核对专属数据表并重试；未确认保存成功' : $e->getMessage(), $code);
        }
    }
}
