<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\OnlineDataAnalysisReportService;
use app\service\OnlineDataCorrectionLedgerService;
use think\Response;
use think\facade\Db;

trait OnlineDataRecordConcern
{
    public function updateData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $id = intval($this->request->param('id', 0));
        if ($id <= 0) {
            return $this->error('无效的数据ID');
        }

        // 查询数据
        $data = Db::name('online_daily_data')->where('id', $id)->find();
        if (!$data) {
            return $this->error('数据不存在');
        }

        // 权限检查：非超级管理员只能修改自己酒店的数据
        $permittedHotelIds = null;
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (!in_array($data['system_hotel_id'], $permittedHotelIds)) {
                return $this->error('无权修改该数据');
            }
        }

        // 人工修改必须留下明确的未复核标记，不能继续继承平台事实的可信状态。
        $updateData = [];
        $suppliedFields = [];
        $fields = ['amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score'];
        foreach ($fields as $field) {
            if ($this->request->has($field)) {
                $suppliedFields[] = $field;
                try {
                    $value = $this->normalizeOnlineDataManualValue($field, $this->request->post($field));
                } catch (\InvalidArgumentException $e) {
                    return $this->error($e->getMessage());
                }
                if ($this->onlineDataManualValueChanged($data[$field] ?? null, $value, $field)) {
                    $updateData[$field] = $value;
                }
            }
        }

        if ($suppliedFields === []) {
            return $this->error('没有要更新的数据');
        }

        if ($updateData === []) {
            return $this->success([
                'id' => $id,
                'updated_fields' => [],
                'trust_status' => (string)($data['validation_status'] ?? 'unknown'),
                'requires_review' => (string)($data['validation_status'] ?? '') === 'unverified',
            ], '数据未变化');
        }

        $now = date('Y-m-d H:i:s');
        $validationFlags = json_decode((string)($data['validation_flags'] ?? '[]'), true);
        if (!is_array($validationFlags)) {
            $validationFlags = [];
        }
        $validationFlags[] = [
            'code' => 'manual_override_unverified',
            'fields' => array_keys($updateData),
            'operator_id' => (int)$this->currentUser->id,
            'changed_at' => $now,
        ];
        $updateData['ingestion_method'] = 'manual_override';
        $updateData['validation_status'] = 'unverified';
        $updateData['validation_flags'] = json_encode($validationFlags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updateData['update_time'] = $now;

        try {
            $ledgerResult = (new OnlineDataCorrectionLedgerService())->update(
                $id,
                $updateData,
                (int)$this->currentUser->id,
                $permittedHotelIds,
                trim((string)$this->request->post('reason', 'manual_correction'))
            );
            $updatedFields = array_values(array_intersect($fields, array_keys($updateData)));
            OperationLog::record(
                'online_data',
                'update',
                '人工修正线上数据ID: ' . $id . '，待复核字段: ' . implode(',', $updatedFields),
                $this->currentUser->id,
                $data['system_hotel_id']
            );
            return $this->success([
                'id' => $id,
                'updated_fields' => $updatedFields,
                'ledger_id' => (int)($ledgerResult['ledger_id'] ?? 0),
                'trust_status' => 'unverified',
                'requires_review' => true,
            ], '已保存为人工修正，复核前不进入可信收益');
        } catch (\Throwable $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除线上数据
     */
    public function deleteData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $id = intval($this->request->param('id', 0));
        if ($id <= 0) {
            return $this->error('无效的数据ID');
        }

        // 查询数据
        $data = Db::name('online_daily_data')->where('id', $id)->find();
        if (!$data) {
            return $this->error('数据不存在');
        }

        // 权限检查：非超级管理员只能删除自己酒店的数据
        $permittedHotelIds = null;
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (!in_array($data['system_hotel_id'], $permittedHotelIds)) {
                return $this->error('无权删除该数据');
            }
        }

        try {
            $result = (new OnlineDataCorrectionLedgerService())->delete(
                $id,
                (int)$this->currentUser->id,
                $permittedHotelIds,
                trim((string)$this->request->post('reason', 'manual_delete'))
            );
            OperationLog::record('online_data', 'delete', '删除线上数据ID: ' . $id, $this->currentUser->id, $data['system_hotel_id']);
            return $this->success($result, '删除成功，已生成可恢复账本');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量删除线上数据
     */
    public function batchDelete(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $ids = $this->request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return $this->error('请选择要删除的数据');
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);

        if (empty($ids)) {
            return $this->error('无效的数据ID');
        }

        $permittedHotelIds = $this->currentUser->isSuperAdmin()
            ? null
            : $this->currentUser->getPermittedHotelIds();

        try {
            $result = (new OnlineDataCorrectionLedgerService())->batchDelete(
                $ids,
                (int)$this->currentUser->id,
                $permittedHotelIds,
                trim((string)$this->request->post('reason', 'manual_batch_delete'))
            );
            $deletedCount = (int)($result['deleted_count'] ?? 0);
            OperationLog::record('online_data', 'batch_delete', '批量删除线上数据: ' . $deletedCount . '条', $this->currentUser->id);
            return $this->success($result, '删除成功，已生成可恢复账本');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    public function correctionLedger(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $page = max(1, (int)$this->request->param('page', 1));
        $pageSize = max(1, min(100, (int)$this->request->param('page_size', 20)));
        $query = Db::name('online_data_correction_ledger');
        if (!$this->currentUser->isSuperAdmin()) {
            $query->whereIn('system_hotel_id', $this->currentUser->getPermittedHotelIds());
        }
        $total = (int)(clone $query)->count();
        $rows = $query
            ->field('id,online_data_id,tenant_id,system_hotel_id,operator_id,operation,changed_fields_json,reason,restorable,restored_at,restored_by,created_at')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        foreach ($rows as &$row) {
            $fields = json_decode((string)($row['changed_fields_json'] ?? '[]'), true);
            $row['changed_fields'] = is_array($fields) ? $fields : [];
            unset($row['changed_fields_json']);
            $row['can_restore'] = (int)($row['restorable'] ?? 0) === 1
                && trim((string)($row['restored_at'] ?? '')) === '';
        }
        unset($row);

        return $this->success([
            'list' => $rows,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function restoreData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $ledgerId = (int)$this->request->post('ledger_id', $this->request->param('ledger_id', 0));
        if ($ledgerId <= 0) {
            return $this->error('无效的恢复账本ID');
        }
        $permittedHotelIds = $this->currentUser->isSuperAdmin()
            ? null
            : $this->currentUser->getPermittedHotelIds();
        try {
            $result = (new OnlineDataCorrectionLedgerService())->restore(
                $ledgerId,
                (int)$this->currentUser->id,
                $permittedHotelIds
            );
            OperationLog::record(
                'online_data',
                'restore',
                '从更正账本恢复线上数据ID: ' . (int)($result['id'] ?? 0),
                $this->currentUser->id
            );
            return $this->success($result, '数据已恢复并完成回读校验');
        } catch (\Throwable $e) {
            return $this->error('恢复失败: ' . $e->getMessage());
        }
    }

    /**
     * AI智能分析
     * 基于携程/美团数据进行智能分析，提供经营建议
     */
    public function aiAnalysis(): Response
    {
        $this->checkPermission();

        $hotels = $this->request->post('hotels', []);
        if (empty($hotels) || !is_array($hotels)) {
            return $this->error('请提供要分析的酒店数据');
        }

        try {
            // 此入口展示的是客户端选中的竞对 POI。保留预览能力，但不得冒充可信收益或经营建议。
            $normalizedHotels = [];
            $totals = [
                'roomNights' => null,
                'roomRevenue' => null,
                'sales' => null,
                'exposure' => null,
                'views' => null,
                'viewConversion' => null,
                'payConversion' => null,
            ];
            $knownCounts = array_fill_keys(array_keys($totals), 0);
            $alignedRevenue = 0.0;
            $alignedRoomNights = 0.0;
            $alignedPriceRows = 0;
            foreach ($hotels as $hotel) {
                if (!is_array($hotel)) {
                    continue;
                }
                $normalized = [
                    'poiId' => trim((string)($hotel['poiId'] ?? '')),
                    'hotelName' => trim((string)($hotel['hotelName'] ?? '未命名酒店')),
                ];
                foreach (array_keys($totals) as $field) {
                    $value = $this->nullableOnlineDataPreviewNumber($hotel[$field] ?? null);
                    $normalized[$field] = $value;
                    if ($value !== null) {
                        $totals[$field] = (float)($totals[$field] ?? 0) + $value;
                        $knownCounts[$field]++;
                    }
                }
                if ($normalized['roomRevenue'] !== null && $normalized['roomNights'] !== null && $normalized['roomNights'] > 0) {
                    $alignedRevenue += $normalized['roomRevenue'];
                    $alignedRoomNights += $normalized['roomNights'];
                    $alignedPriceRows++;
                }
                $normalizedHotels[] = $normalized;
            }

            $hotelCount = count($normalizedHotels);
            if ($hotelCount <= 0) {
                return $this->error('没有可预览的酒店数据');
            }
            $average = static function (string $field) use ($totals, $knownCounts): ?float {
                return $knownCounts[$field] > 0 ? (float)$totals[$field] / $knownCounts[$field] : null;
            };

            $top5ByRoomNights = array_values(array_filter(
                $normalizedHotels,
                static fn(array $hotel): bool => $hotel['roomNights'] !== null
            ));
            usort($top5ByRoomNights, static fn(array $a, array $b): int => $b['roomNights'] <=> $a['roomNights']);
            $top5ByRoomNights = array_slice($top5ByRoomNights, 0, 5);

            $top5ByRevenue = array_values(array_filter(
                $normalizedHotels,
                static fn(array $hotel): bool => $hotel['roomRevenue'] !== null
            ));
            usort($top5ByRevenue, static fn(array $a, array $b): int => $b['roomRevenue'] <=> $a['roomRevenue']);
            $top5ByRevenue = array_slice($top5ByRevenue, 0, 5);

            $coverage = [];
            $dataGaps = [];
            foreach ($knownCounts as $field => $knownCount) {
                $coverage[$field] = [
                    'known' => $knownCount,
                    'missing' => max(0, $hotelCount - $knownCount),
                    'status' => $knownCount === $hotelCount ? 'complete' : ($knownCount > 0 ? 'partial' : 'missing'),
                ];
                if ($knownCount < $hotelCount) {
                    $dataGaps[] = ['code' => $field . '_missing', 'missing_count' => $hotelCount - $knownCount];
                }
            }

            $report = $this->generateAnalysisReport([
                'hotel_count' => $hotelCount,
                'total_room_nights' => $totals['roomNights'],
                'total_room_revenue' => $totals['roomRevenue'],
                'total_sales' => $totals['sales'],
                'total_exposure' => $totals['exposure'],
                'total_views' => $totals['views'],
                'avg_room_nights' => $average('roomNights'),
                'avg_room_revenue' => $average('roomRevenue'),
                'avg_price_per_night' => $alignedPriceRows > 0 && $alignedRoomNights > 0
                    ? $alignedRevenue / $alignedRoomNights
                    : null,
                'avg_view_conversion' => $average('viewConversion'),
                'avg_pay_conversion' => $average('payConversion'),
                'top5_by_room_nights' => $top5ByRoomNights,
                'top5_by_revenue' => $top5ByRevenue,
                'trust_status' => 'unverified_client_preview',
                'metric_scope' => 'ota_competitor_sample',
            ], false);

            OperationLog::record('online_data', 'ai_analysis_preview', '未验证竞对数据预览: ' . $hotelCount . '家酒店', $this->currentUser->id);

            return $this->success([
                'report' => $report,
                'summary' => "已生成 {$hotelCount} 家竞对酒店的未验证数据预览；客户端展示值未经过来源校验，不能用于收益或经营决策。",
                'trust_status' => 'unverified_client_preview',
                'metric_scope' => 'ota_competitor_sample',
                'decision_use' => [
                    'revenue_analysis' => false,
                    'ai_decision_support' => false,
                    'operation_management' => false,
                ],
                'reason_codes' => ['client_supplied_metrics_not_source_verified'],
                'data' => [
                    'hotel_count' => $hotelCount,
                    'total_room_nights' => $totals['roomNights'],
                    'total_room_revenue' => $totals['roomRevenue'],
                    'avg_price_per_night' => $alignedPriceRows > 0 && $alignedRoomNights > 0
                        ? round($alignedRevenue / $alignedRoomNights, 2)
                        : null,
                    'coverage' => $coverage,
                    'data_gaps' => $dataGaps,
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->error('分析失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成分析报告HTML
     */
    private function generateAnalysisReport(array $data, bool $includeSuggestions = true): string
    {
        return (new OnlineDataAnalysisReportService())->render($data, $includeSuggestions);
    }

    private function normalizeOnlineDataManualValue(string $field, mixed $value): int|float|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($field . ' 必须是数字或留空');
        }

        $number = (float)$value;
        if (!is_finite($number)) {
            throw new \InvalidArgumentException($field . ' 数值无效');
        }
        if (in_array($field, ['quantity', 'book_order_num'], true)) {
            if ($number < 0 || floor($number) !== $number) {
                throw new \InvalidArgumentException($field . ' 必须是非负整数或留空');
            }
            return (int)$number;
        }
        if (in_array($field, ['comment_score', 'qunar_comment_score'], true)) {
            if ($number < 0 || $number > 5) {
                throw new \InvalidArgumentException($field . ' 必须在 0 到 5 之间或留空');
            }
            return $number;
        }
        if ($number < 0) {
            throw new \InvalidArgumentException($field . ' 必须是非负数或留空');
        }
        return $number;
    }

    private function onlineDataManualValueChanged(mixed $current, int|float|null $next, string $field): bool
    {
        if ($next === null) {
            return !($current === null || (is_string($current) && trim($current) === ''));
        }
        if (!is_numeric($current)) {
            return true;
        }
        if (in_array($field, ['quantity', 'book_order_num'], true)) {
            return (int)$current !== (int)$next;
        }
        return abs((float)$current - (float)$next) > 0.000001;
    }

    private function nullableOnlineDataPreviewNumber(mixed $value): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '') || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return is_finite($number) ? $number : null;
    }

}
