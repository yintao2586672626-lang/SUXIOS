<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Hotel;
use app\service\AutomationRunMonitorService;
use app\service\CloudMessageTaskOverviewService;
use app\service\ManualNotificationService;
use app\service\WechatRobotDeliveryService;
use think\Response;

final class ManualNotification extends Base
{
    public function metadata(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report', $input);
        try {
            $metadata = (new ManualNotificationService())->metadata(
                (string)($input['business_date'] ?? $this->request->get('business_date', '')),
                $tenantId,
                $hotelId,
                (int)($input['robot_id'] ?? $this->request->get('robot_id', 0))
            );
            $metadata['automatic_tasks'] = (new CloudMessageTaskOverviewService())
                ->overview($tenantId, $hotelId);
            return $this->success(
                $metadata
            );
        } catch (\InvalidArgumentException) {
            return $this->error('业务日期格式无效', 422);
        } catch (\RuntimeException) {
            return $this->error('自动发送任务状态暂时无法读取', 503);
        }
    }

    public function monitor(): Response
    {
        if (!$this->currentUser) {
            abort(401, '请先登录');
        }

        $businessDate = (string)$this->request->get('business_date', date('Y-m-d'));
        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            fn(int $hotelId): bool => $hotelId > 0
                && (
                    $this->currentUser->isSuperAdmin()
                    || $this->currentUser->hasHotelPermission($hotelId, 'can_view_report')
                )
        )));
        $hotels = $hotelIds === []
            ? []
            : Hotel::whereIn('id', $hotelIds)
                ->where('status', 1)
                ->field('id,tenant_id,name,status')
                ->select()
                ->toArray();

        try {
            return $this->success(
                (new AutomationRunMonitorService())->overview(
                    $hotels,
                    $businessDate,
                    (int)$this->currentUser->id
                )
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请选择有效的数据日期', 422);
        } catch (\RuntimeException) {
            return $this->error('企业微信机器人绑定状态暂时无法读取', 503);
        }
    }

    public function history(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        return $this->success(
            (new ManualNotificationService())->history(
                $tenantId,
                $hotelId,
                (int)$this->request->get('limit', 50)
            )
        );
    }

    public function dispatchHistory(): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        try {
            return $this->success(
                (new ManualNotificationService())->dispatchHistory(
                    $tenantId,
                    $hotelId,
                    (int)$this->request->get('limit', 50)
                )
            );
        } catch (\RuntimeException) {
            return $this->error('发送历史表尚未安装或无法回读', 503);
        }
    }

    public function read(int $id): Response
    {
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report');
        try {
            return $this->success((new ManualNotificationService())->read($tenantId, $hotelId, $id));
        } catch (\RuntimeException) {
            return $this->error('通知不存在或当前账号无权访问', 404);
        }
    }

    public function preview(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_view_report', $input);
        $hotel = Hotel::find($hotelId);
        try {
            return $this->success(
                (new ManualNotificationService())->preview(
                    $hotelId,
                    (string)($hotel->name ?? '未命名酒店'),
                    $input,
                    $tenantId
                )
            );
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->inputError($error->getMessage()), 422);
        }
    }

    public function save(): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $hotel = Hotel::find($hotelId);
        try {
            $result = (new ManualNotificationService())->save(
                $tenantId,
                $hotelId,
                (int)$this->currentUser->id,
                (string)($hotel->name ?? '未命名酒店'),
                $input
            );
            return $this->success(
                $result,
                '通知已保存并完成回读；计划发送需先完成一次真实测试'
            );
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->inputError($error->getMessage()), 422);
        } catch (\Throwable) {
            return $this->error('通知保存失败，未生成成功回执', 500);
        }
    }

    public function testPush(int $id): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $hotel = Hotel::find($hotelId);
        $service = new ManualNotificationService(
            static fn(int $targetHotelId, int $robotId, array $payload, array $context = []): array =>
                (new WechatRobotDeliveryService())->deliverToPlanRobot(
                    (int)($context['tenant_id'] ?? 0),
                    $targetHotelId,
                    $robotId,
                    (string)($context['robot_name'] ?? ''),
                    (int)($context['owner_user_id'] ?? 0),
                    (string)($context['mode'] ?? 'test'),
                    $payload
                )
        );
        try {
            $result = $service->testPush(
                $tenantId,
                $hotelId,
                $id,
                (int)$this->currentUser->id,
                ($input['confirmed'] ?? false) === true,
                (int)($input['target_robot_id'] ?? 0),
                (string)($input['target_robot_name'] ?? ''),
                (string)($hotel->name ?? '未命名酒店'),
                (string)($input['idempotency_key'] ?? '')
            );
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->inputError($error->getMessage()), 422);
        } catch (\RuntimeException) {
            return $this->error('通知不存在或当前账号无权访问', 404);
        }

        return (string)($result['delivery_status'] ?? '') === 'sent'
            ? $this->success($result, '测试消息已发送到所选企业微信机器人')
            : $this->error(
                (string)($result['message'] ?? '测试消息未送达'),
                (string)($result['delivery_status'] ?? '') === 'blocked' ? 409 : 502,
                $result
            );
    }

    public function retryDispatch(int $dispatchId): Response
    {
        $input = $this->requestData();
        [$hotelId, $tenantId] = $this->authorizedScope('can_fill_daily_report', $input);
        $service = new ManualNotificationService(
            static fn(int $targetHotelId, int $robotId, array $payload, array $context = []): array =>
                (new WechatRobotDeliveryService())->deliverToPlanRobot(
                    (int)($context['tenant_id'] ?? 0),
                    $targetHotelId,
                    $robotId,
                    (string)($context['robot_name'] ?? ''),
                    (int)($context['owner_user_id'] ?? 0),
                    (string)($context['mode'] ?? ''),
                    $payload
                )
        );
        try {
            $result = $service->retryDispatch(
                $tenantId,
                $hotelId,
                $dispatchId,
                (int)$this->currentUser->id,
                ($input['confirmed'] ?? false) === true
            );
        } catch (\InvalidArgumentException $error) {
            return $this->error($this->inputError($error->getMessage()), 422);
        } catch (\RuntimeException) {
            return $this->error('发送记录不存在、不可重试或发送器未配置', 409);
        }
        return (string)($result['delivery_status'] ?? '') === 'sent'
            ? $this->success($result, '失败发送已显式重试并取得送达回执')
            : $this->error('显式重试未确认送达', 502, $result);
    }

    /** @param array<string, mixed>|null $input @return array{0:int,1:int} */
    private function authorizedScope(string $capability, ?array $input = null): array
    {
        if (!$this->currentUser) {
            abort(401, '请先登录');
        }
        $input ??= $this->requestData();
        $hotelId = (int)($this->request->get('hotel_id', 0) ?: ($input['hotel_id'] ?? 0));
        if ($hotelId <= 0) {
            abort(422, '请选择酒店');
        }
        $hotel = Hotel::find($hotelId);
        if (!$hotel) {
            abort(404, '酒店不存在或当前账号无权访问');
        }
        $tenantId = (int)$hotel->tenant_id;
        if ($tenantId <= 0) {
            abort(422, '酒店缺少有效租户归属');
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $this->currentUser->hasHotelPermissionOrFail(
                $hotelId,
                $capability,
                '当前账号没有该酒店的通知权限'
            );
        }
        return [$hotelId, $tenantId];
    }

    private function inputError(string $code): string
    {
        return match ($code) {
            'manual_notification_type_invalid' => '请选择有效的通知类型',
            'manual_notification_date_invalid' => '请选择有效的业务日期',
            'manual_notification_content_required' => '请填写通知名称和正文',
            'operating_daily_custom_template_required' => '请填写自定义微信标题和正文',
            'operating_daily_custom_variable_invalid' => '自定义模板包含不支持的数据变量',
            'manual_notification_body_chinese_only' => '通知正文只允许中文、数字和常用标点',
            'manual_notification_send_method_invalid' => '请选择有效的发送方式',
            'manual_notification_trigger_invalid' => '请选择有效的发送触发方式',
            'manual_notification_operating_daily_fixed_time_required'
                => '经营日报不支持间隔或整点循环，请选择每日固定时间',
            'manual_notification_source_scope_invalid' => '请选择携程、美团、订单来了或三源汇总',
            'manual_notification_content_section_invalid' => '所选发送内容不属于当前数据源',
            'manual_notification_content_sections_required' => '至少选择一项要发送的内容',
            'manual_notification_schedule_required' => '每日固定时间必须配置触发时间',
            'manual_notification_schedule_invalid' => '计划发送时间格式无效',
            'manual_notification_interval_invalid' => '循环发送间隔必须是 5 到 1440 分钟',
            'manual_notification_interval_window_invalid' => '循环发送结束时间必须晚于开始时间',
            'manual_notification_business_date_rule_invalid' => '请选择今日累计或昨日数据日期规则',
            'manual_notification_weekdays_required' => '至少选择一个生效星期',
            'manual_notification_effective_range_invalid' => '生效结束日期不能早于开始日期',
            'manual_notification_hourly_window_invalid' => '小时播报必须设置有效的整点起止时段',
            'manual_notification_test_confirmation_required' => '请明确确认本次测试推送',
            'manual_notification_test_target_forbidden' => '所选机器人未启用、作用域不匹配或不属于当前酒店',
            'manual_notification_test_method_forbidden' => '当前通知发送方式不是企业微信测试机器人',
            'manual_notification_target_required' => '正式计划必须保存目标机器人编号和名称',
            'manual_notification_target_binding_invalid' => '目标机器人未启用、作用域不匹配或酒店归属无效',
            'manual_notification_idempotency_key_invalid' => '立即测试缺少有效幂等键，请刷新页面后重试',
            'manual_notification_retry_confirmation_required' => '请明确确认本次失败发送重试',
            'manual_notification_retry_status_forbidden' => '只有明确失败的发送可以重试',
            'manual_notification_retry_limit_reached' => '该发送已达到最大重试次数',
            'manual_notification_retry_target_changed',
            'manual_notification_retry_target_invalid' => '计划机器人已变化或当前不可用，拒绝重试',
            'manual_notification_formal_plan_inactive' => '正式计划已停用或尚未通过测试，拒绝重试',
            default => '通知输入不合法',
        };
    }
}
