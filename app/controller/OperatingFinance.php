<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AutomationRunMonitorService;
use app\service\BookingDemandPlanningService;
use app\service\MonthlyOperatingFinanceService;
use app\service\OperatingBlockerRecoveryService;
use app\service\OtaSettlementReconciliationService;
use app\service\OtaSettlementFileParserService;
use app\service\WecomTaskReceiptService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

final class OperatingFinance extends Base
{
    public function overview(): Response
    {
        try {
            $hotelId = (int)$this->request->param('hotel_id', 0);
            [$tenantId, $hotel, $permittedHotelIds] = $this->resolveHotelScope($hotelId, 'operation.view');
            $businessDate = $this->date((string)$this->request->param('business_date', date('Y-m-d')), 'business_date');
            $periodMonth = $this->month((string)$this->request->param('period_month', substr($businessDate, 0, 7)));
            $stayDate = $this->date((string)$this->request->param('stay_date', date('Y-m-d', strtotime($businessDate . ' +1 day'))), 'stay_date');
            $platform = strtolower(trim((string)$this->request->param('platform', 'ctrip')));
            if (!in_array($platform, ['ctrip', 'meituan', 'dingdandao_pms', 'manual_all_channels'], true)) {
                throw new InvalidArgumentException('platform_invalid');
            }
            [$periodStart, $periodEnd] = $this->monthWindow($periodMonth);

            $monitor = $this->module('automation_monitor', fn(): array => (new AutomationRunMonitorService())->overview(
                [$hotel],
                $businessDate,
                (int)$this->currentUser->id
            ));
            $recovery = $this->module('blocker_recovery', function () use ($tenantId, $hotelId, $businessDate, $monitor): array {
                return (new OperatingBlockerRecoveryService())->build(
                    ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'business_date' => $businessDate],
                    $this->recoveryEvidence($tenantId, $hotelId, $businessDate, $monitor)
                );
            });
            $booking = $this->module('booking_pace', fn(): array => (new BookingDemandPlanningService())->bookingOverview(
                $tenantId,
                $permittedHotelIds,
                $hotelId,
                $platform,
                $stayDate
            ));
            $demandPlan = $this->module('booking_demand_plan', fn(): array => (new BookingDemandPlanningService())->demandPlan(
                $tenantId,
                $permittedHotelIds,
                $hotelId,
                $platform,
                $businessDate
            ));
            $demand = $this->demandCalendarFromPlan($demandPlan, $tenantId, $hotelId);
            $monthly = $this->module('monthly_finance', fn(): array => (new MonthlyOperatingFinanceService())->latestForHotel(
                $tenantId,
                $permittedHotelIds,
                $hotelId,
                $periodMonth
            ));
            $tenantHotelIds = $this->tenantPermittedHotels($tenantId, $permittedHotelIds, 'operation.view');
            $portfolio = $this->module('portfolio', fn(): array => (new MonthlyOperatingFinanceService())->portfolioOverview(
                $tenantId,
                $tenantHotelIds,
                $periodMonth
            ));
            $settlement = in_array($platform, ['ctrip', 'meituan'], true)
                ? $this->module('settlement', fn(): array => (new OtaSettlementReconciliationService())->latestForScope(
                    $tenantId,
                    $hotelId,
                    $platform,
                    $periodStart,
                    $periodEnd
                ))
                : [
                    'contract_version' => OtaSettlementReconciliationService::CONTRACT_VERSION,
                    'status' => 'not_applicable',
                    'batch_status' => 'not_applicable',
                    'reason_code' => 'settlement_platform_not_supported',
                    'scope' => [
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'platform' => $platform,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                    ],
                    'readback_verified' => false,
                    'external_write_count' => 0,
                ];
            $wecomReceipt = $this->module('wecom_task_receipt', fn(): array => $this->wecomReceiptSummary($tenantId, $hotelId));

            return $this->success([
                'contract_version' => 'operating_finance_control_center.v1',
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'hotel_name' => (string)$hotel['name'],
                'business_date' => $businessDate,
                'period_month' => $periodMonth,
                'stay_date' => $stayDate,
                'platform' => $platform,
                'settlement' => $settlement,
                'recovery' => $recovery,
                'booking_pace' => $booking,
                'booking_demand_plan' => $demandPlan,
                'demand_calendar' => $demand,
                'wecom_task_receipt' => $wecomReceipt,
                'monthly_finance' => $monthly,
                'portfolio' => $portfolio,
                'boundaries' => [
                    'automatic_approval' => false,
                    'automatic_external_send' => false,
                    'automatic_ota_write' => false,
                    'automatic_pms_write' => false,
                    'external_write_count' => 0,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营财务与恢复中心读取失败'), $this->statusCode($e));
        }
    }

    public function saveOnBooksSnapshot(): Response
    {
        try {
            $input = $this->requestData();
            $input['source_method'] = 'manual_entry';
            $input['quality_status'] = filter_var($input['operator_attested'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? 'manual_confirmed'
                : 'unverified';
            unset($input['operator_attested'], $input['readback_verified']);
            [$tenantId, , $permittedHotelIds] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0), 'operation.execute');
            $saved = (new BookingDemandPlanningService())->saveOnBooksSnapshot(
                $tenantId,
                $permittedHotelIds,
                (int)$input['hotel_id'],
                $input,
                (int)$this->currentUser->id
            );
            return $this->success($saved, '在手预订快照已保存并精确回读；未执行调价或库存写入');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '在手预订快照保存失败'), $this->statusCode($e));
        }
    }

    public function saveDemandEvent(): Response
    {
        try {
            $input = $this->requestData();
            $input['source_method'] = 'manual_reference';
            $input['source_status'] = 'reference_only';
            [$tenantId, , $permittedHotelIds] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0), 'operation.execute');
            $saved = (new BookingDemandPlanningService())->saveDemandEvent(
                $tenantId,
                $permittedHotelIds,
                (int)$input['hotel_id'],
                $input,
                (int)$this->currentUser->id
            );
            return $this->success($saved, '本地需求事件已作为参考事实保存；不会自动形成调价结论');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '本地需求事件保存失败'), $this->statusCode($e));
        }
    }

    public function saveMonthlyFinance(): Response
    {
        try {
            $input = $this->requestData();
            $hotelId = (int)($input['hotel_id'] ?? 0);
            [$tenantId, , $permittedHotelIds] = $this->resolveHotelScope($hotelId, 'operation.execute');
            $saved = (new MonthlyOperatingFinanceService())->saveSnapshot(
                $tenantId,
                $permittedHotelIds,
                $hotelId,
                (string)($input['period_month'] ?? ''),
                (string)($input['fact_scope'] ?? ''),
                is_array($input['inputs'] ?? null) ? $input['inputs'] : [],
                is_array($input['source_refs'] ?? null) ? $input['source_refs'] : [],
                [
                    'source_method' => 'manual_entry',
                    'source_quality_status' => filter_var($input['operator_attested'] ?? false, FILTER_VALIDATE_BOOLEAN)
                        ? 'operator_attested'
                        : 'unverified',
                    'currency' => 'CNY',
                    'tax_basis' => (string)($input['tax_basis'] ?? 'unknown'),
                ],
                (string)($input['idempotency_key'] ?? ''),
                (int)$this->currentUser->id
            );
            return $this->success($saved, '月度经营财务快照已保存并精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '月度经营财务保存失败'), $this->statusCode($e));
        }
    }

    public function importSettlement(): Response
    {
        try {
            $input = $this->requestData();
            $hotelId = (int)($input['hotel_id'] ?? 0);
            [$tenantId] = $this->resolveHotelScope($hotelId, 'operation.execute');
            $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
            $scope = is_array($input['scope'] ?? null) ? $input['scope'] : [];
            $scope['tenant_id'] = $tenantId;
            $scope['hotel_id'] = $hotelId;
            $scope['source_method'] = 'manual_export';
            $scope['source_quality_status'] = filter_var(
                $scope['operator_attested'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ) ? 'operator_attested' : 'unverified';
            $scope['source_evidence_sha256'] = '';
            unset($scope['operator_attested']);
            $saved = (new OtaSettlementReconciliationService())->importAndReadback(
                $scope,
                $lines,
                (int)$this->currentUser->id
            );
            return $this->settlementImportResponse(
                $saved,
                'OTA结算批次已保存并精确回读；未写入OTA、PMS或财务系统'
            );
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, 'OTA结算批次导入失败'), $this->statusCode($e));
        }
    }

    public function importSettlementFile(): Response
    {
        $path = '';
        try {
            $input = $this->requestData();
            $hotelId = (int)($input['hotel_id'] ?? 0);
            [$tenantId] = $this->resolveHotelScope($hotelId, 'operation.execute');
            $file = $this->request->file('file');
            if (!$file) {
                throw new InvalidArgumentException('请选择结算 JSON、CSV 或 XLSX 文件');
            }
            $path = method_exists($file, 'getPathname') ? (string)$file->getPathname() : '';
            $name = method_exists($file, 'getOriginalName') ? (string)$file->getOriginalName() : '';
            $parsed = (new OtaSettlementFileParserService())->parse(
                $path,
                $name,
                (string)($input['amount_scope'] ?? 'settlement')
            );
            $platform = strtolower(trim((string)($input['platform'] ?? '')));
            $periodStart = (string)($input['period_start'] ?? '');
            $periodEnd = (string)($input['period_end'] ?? '');
            $saved = (new OtaSettlementReconciliationService())->importAndReadback([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'file_sha256' => $parsed['file_sha256'],
                'source_evidence_sha256' => '',
                'source_method' => 'manual_export',
                'source_quality_status' => filter_var($input['operator_attested'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    ? 'operator_attested'
                    : 'unverified',
                'parser_version' => $parsed['parser_version'],
            ], $parsed['lines'], (int)$this->currentUser->id);
            $saved['file_parser'] = [
                'contract_version' => $parsed['contract_version'],
                'parser_version' => $parsed['parser_version'],
                'row_count' => $parsed['row_count'],
                'file_sha256' => $parsed['file_sha256'],
                'original_filename_retained' => false,
            ];
            return $this->settlementImportResponse(
                $saved,
                '结算文件已解析、保存并精确回读；原文件名和文件内容未进入结算事实表'
            );
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, 'OTA结算文件导入失败'), $this->statusCode($e));
        } finally {
            if ($path !== '' && is_uploaded_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @param array<string,mixed> $saved */
    private function settlementImportResponse(array $saved, string $availableMessage): Response
    {
        if (($saved['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('ota_settlement_import_readback_unverified', 409);
        }
        $batchStatus = strtolower(trim((string)($saved['batch_status'] ?? '')));
        if (!in_array($batchStatus, ['available', 'partial', 'invalid'], true)) {
            throw new RuntimeException('ota_settlement_import_status_invalid', 409);
        }
        $netRevenue = $saved['totals']['net_revenue']['value'] ?? null;
        $saved['request_status'] = 'saved_and_readback_verified';
        $saved['business_result_status'] = $batchStatus;
        $saved['business_success'] = $batchStatus === 'available';
        $saved['usable_net_revenue_fact_created'] = $batchStatus !== 'invalid' && $netRevenue !== null;
        $saved['warning_code'] = match ($batchStatus) {
            'invalid' => 'settlement_attempt_invalid_no_usable_fact',
            'partial' => 'settlement_batch_partial_review_required',
            default => null,
        };

        $message = match ($batchStatus) {
            'invalid' => '结算失败尝试已留痕并精确回读；未形成可用净收入事实，也未写入OTA、PMS或财务系统',
            'partial' => '结算批次已保存并精确回读，但仅部分可用；请按缺口修正后再用于经营判断',
            default => $availableMessage,
        };
        return $this->success($saved, $message);
    }

    /** @return array{0:int,1:array<string,mixed>,2:list<int>} */
    private function resolveHotelScope(int $hotelId, string $capability): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录', 401);
        }
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('请选择单个酒店');
        }
        $permitted = array_values(array_unique(array_filter(
            array_map('intval', (array)$this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        )));
        if (!in_array($hotelId, $permitted, true)
            || !$this->currentUser->hasHotelPermission($hotelId, $capability)
        ) {
            throw new RuntimeException('无权访问该酒店经营财务范围', 403);
        }
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->field('id,tenant_id,name,status')
            ->find();
        if (!$hotel || (int)($hotel['tenant_id'] ?? 0) <= 0) {
            throw new RuntimeException('酒店租户范围不可用', 404);
        }
        return [(int)$hotel['tenant_id'], $hotel, $permitted];
    }

    /** @param list<int> $permittedHotelIds @return list<int> */
    private function tenantPermittedHotels(int $tenantId, array $permittedHotelIds, string $capability): array
    {
        $result = [];
        foreach ($permittedHotelIds as $hotelId) {
            if ($hotelId > 0 && $this->currentUser->hasHotelPermission($hotelId, $capability)) {
                $result[] = $hotelId;
            }
        }
        if ($result === []) {
            return [];
        }
        return array_values(array_map('intval', Db::name('hotels')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $result)
            ->column('id')));
    }

    /** @return array<int,array<string,mixed>> */
    private function recoveryEvidence(int $tenantId, int $hotelId, string $businessDate, array $monitor): array
    {
        $evidence = [];
        try {
            $probe = Db::query('SELECT 1 AS runtime_ready');
            $ready = (int)($probe[0]['runtime_ready'] ?? $probe[0]['RUNTIME_READY'] ?? 0) === 1;
            $evidence[] = [
                'source' => 'database',
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'status' => $ready ? 'ready' : 'blocked',
                'reason_code' => $ready ? 'ready' : 'database_read_probe_failed',
                'evidence_quality' => 'verified',
                'business_impact' => 'critical',
                'evidence_ref' => 'database_read_probe',
                'observed_at' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable) {
            $evidence[] = [
                'source' => 'database',
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'status' => 'unavailable',
                'reason_code' => 'database_runtime_unavailable',
                'evidence_quality' => 'partial',
                'business_impact' => 'critical',
                'evidence_ref' => 'database_read_probe',
            ];
        }
        $row = is_array($monitor['rows'][0] ?? null) ? $monitor['rows'][0] : [];
        foreach (['pms', 'ctrip', 'meituan'] as $source) {
            $state = is_array($row[$source] ?? null) ? $row[$source] : [];
            $status = strtolower(trim((string)($state['status'] ?? 'missing')));
            $evidence[] = [
                'source' => $source,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'status' => ($state['ready'] ?? false) === true ? 'ready' : $status,
                'reason_code' => ($state['ready'] ?? false) === true ? 'ready' : $this->sourceReasonCode($source, $status),
                'evidence_quality' => ($state['ready'] ?? false) === true ? 'verified' : 'partial',
                'business_impact' => $source === 'pms' ? 'critical' : 'high',
                'evidence_ref' => 'automation_monitor:' . $source,
                'observed_at' => date('Y-m-d H:i:s'),
            ];
        }
        $robotConfigured = ($row['wechat_robot_configured'] ?? false) === true;
        $pushStatus = strtolower(trim((string)($row['push_status'] ?? 'missing')));
        $evidence[] = [
            'source' => 'wecom',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'status' => $robotConfigured && in_array($pushStatus, ['sent', 'waiting'], true) ? 'ready' : $pushStatus,
            'reason_code' => !$robotConfigured ? 'robot_binding_missing' : ($pushStatus === 'failed' ? 'delivery_failed' : ($pushStatus ?: 'delivery_missing')),
            'evidence_quality' => 'partial',
            'business_impact' => 'medium',
            'evidence_ref' => 'automation_monitor:wecom',
            'observed_at' => date('Y-m-d H:i:s'),
        ];
        return $evidence;
    }

    private function sourceReasonCode(string $source, string $status): string
    {
        if (str_contains($status, 'binding')) {
            return $source . '_' . $status;
        }
        if (str_contains($status, 'login') || str_contains($status, 'session')) {
            return $source . '_' . $status;
        }
        return match ($status) {
            'collection_failed', 'failed', 'error', 'unavailable' => $source . '_collection_failed',
            'pending_readback' => $source . '_readback_missing',
            'blocked' => $source . '_source_blocked',
            default => $source . '_target_data_missing',
        };
    }

    /** @return array<string,mixed> */
    private function wecomReceiptSummary(int $tenantId, int $hotelId): array
    {
        $count = (int)Db::name('wecom_task_receipts')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->count();
        $latestIdentity = $count > 0
            ? Db::name('wecom_task_receipts')
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->field('id,task_id,source_event_id')
                ->order('id', 'desc')
                ->find()
            : null;
        $latest = null;
        if (is_array($latestIdentity)) {
            $verified = (new WecomTaskReceiptService())->read(
                (int)$latestIdentity['id'],
                $tenantId,
                $hotelId,
                (int)$latestIdentity['task_id'],
                (int)$latestIdentity['source_event_id']
            );
            $latest = array_intersect_key($verified, array_flip([
                'id', 'task_id', 'source_event_id', 'reported_status', 'reported_amount_status',
                'created_at', 'readback_verified', 'persistence_status',
            ]));
        }
        return [
            'contract_version' => 'wecom_task_receipt_overview.v1',
            'status' => $latest !== null ? 'ready' : 'sender_mapping_and_verified_event_required',
            'receipt_count' => $count,
            'latest' => $latest,
            'structured_payload_only' => true,
            'approval_created' => false,
            'task_status_changed' => false,
            'external_send_performed' => false,
            'external_write_count' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function module(string $key, callable $loader): array
    {
        try {
            $result = $loader();
            return is_array($result) ? $result : ['status' => 'blocked', 'reason_code' => $key . '_result_invalid'];
        } catch (Throwable $e) {
            return [
                'status' => 'blocked',
                'reason_code' => str_contains(strtolower($e->getMessage()), 'table')
                    ? $key . '_migration_required'
                    : $key . '_unavailable',
                'message' => '该模块暂不可用；缺口保持阻断，未使用旧数据或默认值。',
                'external_write_count' => 0,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function demandCalendarFromPlan(array $plan, int $tenantId, int $hotelId): array
    {
        $window = null;
        foreach ((array)($plan['windows'] ?? []) as $candidate) {
            if (is_array($candidate) && (string)($candidate['window_key'] ?? '') === 'next_7_days') {
                $window = $candidate;
                break;
            }
        }
        if (!is_array($window)) {
            return [
                'contract_version' => BookingDemandPlanningService::CALENDAR_CONTRACT,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'start_date' => null,
                'end_date' => null,
                'status' => 'blocked',
                'reason_code' => 'booking_demand_plan_unavailable',
                'events' => [],
                'event_count' => 0,
                'reference_only' => true,
                'causality_claimed' => false,
                'automatic_pricing' => false,
                'external_write_count' => 0,
            ];
        }
        $events = array_values(array_filter((array)($window['events'] ?? []), 'is_array'));
        return [
            'contract_version' => BookingDemandPlanningService::CALENDAR_CONTRACT,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'start_date' => (string)($window['start_date'] ?? ''),
            'end_date' => (string)($window['end_date'] ?? ''),
            'status' => $events === [] ? 'empty' : 'ready',
            'events' => $events,
            'event_count' => count($events),
            'reference_only' => true,
            'causality_claimed' => false,
            'automatic_pricing' => false,
            'external_write_count' => 0,
        ];
    }

    /** @return array{0:string,1:string} */
    private function monthWindow(string $periodMonth): array
    {
        $start = new \DateTimeImmutable($periodMonth . '-01', new \DateTimeZone('Asia/Shanghai'));
        return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }

    private function date(string $value, string $field): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new \DateTimeZone('Asia/Shanghai'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        return $parsed->format('Y-m-d');
    }

    private function month(string $value): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m', trim($value), new \DateTimeZone('Asia/Shanghai'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new InvalidArgumentException('period_month_invalid');
        }
        return $parsed->format('Y-m');
    }

    private function statusCode(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException) return 422;
        if (in_array((int)$e->getCode(), [401, 403, 404, 409, 422, 503], true)) return (int)$e->getCode();
        return 500;
    }

    private function safeMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        return $message !== '' && (preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1 || preg_match('/^[a-z0-9_:-]+$/i', $message) === 1)
            ? $message
            : $fallback;
    }
}
