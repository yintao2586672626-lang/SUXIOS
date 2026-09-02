<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OtaInsightAnalysisService;
use app\service\OperationOptimizationExecutionBridgeService;
use app\service\OperationOptimizationWorkbenchService;
use app\service\MeituanMarketingFactProjectionService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

class OtaStandard extends Base
{
    public function dataset(): Response
    {
        try {
            $dataset = (new OtaStandardEtlService())->buildDataset($this->filters());
            if ($dataset['status'] === 'empty') {
                return $this->error('No OTA rows matched the requested scope.', 422, $dataset['data_quality'] ?? []);
            }
            return $this->success($dataset, 'success');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->httpCode($e));
        }
    }

    public function revenueMetrics(): Response
    {
        try {
            $dataset = (new OtaStandardEtlService())->buildDataset($this->decisionFilters());
            if ($dataset['status'] === 'empty') {
                if ($this->truthy($this->request->param('include_missing_state', false))) {
                    return $this->success([
                        'status' => 'data_missing',
                        'metric_scope' => 'ota_channel',
                        'formal_metrics_available' => false,
                        'data_quality' => $dataset['data_quality'] ?? [],
                        'data_gaps' => [[
                            'code' => 'ota_rows_missing',
                            'message' => 'No OTA rows matched the requested scope.',
                        ]],
                    ], 'No OTA rows matched the requested scope.');
                }
                return $this->error('No OTA rows matched the requested scope.', 422, $dataset['data_quality'] ?? []);
            }
            $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            return $this->success($metrics, 'success');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->httpCode($e));
        }
    }

    public function analysis(): Response
    {
        try {
            $dataset = (new OtaStandardEtlService())->buildDataset($this->decisionFilters());
            if ($dataset['status'] === 'empty') {
                return $this->error('No OTA rows matched the requested scope.', 422, $dataset['data_quality'] ?? []);
            }
            $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);
            return $this->success([
                'metrics' => $metrics,
                'analysis' => $analysis,
            ], 'success');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), $this->httpCode($e));
        }
    }

    public function operationOptimizer(): Response
    {
        try {
            $filters = $this->decisionFilters();
            $dataset = (new OtaStandardEtlService())->buildDataset($filters);
            $workbench = (new OperationOptimizationWorkbenchService())->build($dataset, [
                'hotel_id' => (int)($filters['system_hotel_id'] ?? 0),
                'start_date' => (string)($filters['start_date'] ?? ''),
                'end_date' => (string)($filters['end_date'] ?? ''),
            ]);
            $hotelId = (int)($filters['system_hotel_id'] ?? 0);
            $workbench = (new OperationOptimizationExecutionBridgeService())
                ->hydrate($workbench, [$hotelId], $hotelId);
            $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
            $workbench['meituan_marketing'] = (new MeituanMarketingFactProjectionService())->project(
                $tenantId,
                $hotelId,
                (string)($filters['end_date'] ?? '')
            );
            return $this->success($workbench, 'success');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), $this->httpCode($e));
        }
    }

    public function createOperationOptimizerExecutionIntent(): Response
    {
        try {
            $input = $this->requestData();
            $filters = $this->decisionFilters();
            $hotelId = (int)($filters['system_hotel_id'] ?? 0);
            if ($hotelId <= 0) {
                throw new RuntimeException('system_hotel_id is required', 422);
            }
            if (!$this->currentUser
                || $this->currentUser->hasHotelPermission($hotelId, 'operation.execute') !== true
            ) {
                throw new RuntimeException('operation.execute permission is required', 403);
            }

            $dataset = (new OtaStandardEtlService())->buildDataset($filters);
            $workbench = (new OperationOptimizationWorkbenchService())->build($dataset, [
                'hotel_id' => $hotelId,
                'start_date' => (string)($filters['start_date'] ?? ''),
                'end_date' => (string)($filters['end_date'] ?? ''),
            ]);
            $recommendationId = trim((string)($input['recommendation_id'] ?? ''));
            $intent = (new OperationOptimizationExecutionBridgeService())->createFromWorkbench(
                $workbench,
                $recommendationId,
                [$hotelId],
                $hotelId,
                (int)($this->currentUser->id ?? 0)
            );

            return $this->success([
                'recommendation_id' => $recommendationId,
                'execution_intent' => $intent,
                'readback_status' => 'readback_verified',
            ], 'operation optimizer execution intent created');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), $this->httpCode($e));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        $data = $this->requestData();
        foreach (['source', 'data_type', 'hotel_id', 'system_hotel_id', 'start_date', 'end_date', 'limit', 'portfolio'] as $key) {
            $value = $this->request->param($key, null);
            if ($value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }
        return $this->authorizeHotelFilters($data);
    }

    /**
     * Formal metrics, analysis and downstream operating decisions must only
     * consume rows that passed the complete cockpit fact gate. This server-side
     * override prevents clients from weakening the evidence boundary.
     *
     * @return array<string, mixed>
     */
    private function decisionFilters(): array
    {
        $filters = $this->filters();
        $filters['strict_readback_only'] = true;
        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function authorizeHotelFilters(array $filters): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('Unauthenticated', 401);
        }

        $permittedHotelIds = array_values(array_unique(array_filter(
            array_map('intval', (array)$this->currentUser->getPermittedHotelIds()),
            static fn(int $hotelId): bool => $hotelId > 0
        )));
        sort($permittedHotelIds);
        $isSuperAdmin = $this->currentUser->isSuperAdmin();
        $portfolio = $this->truthy($filters['portfolio'] ?? false);
        $requestedHotelId = $this->positiveHotelId($filters['system_hotel_id'] ?? null);

        if (!$isSuperAdmin && $permittedHotelIds === []) {
            throw new RuntimeException('No permitted hotels', 403);
        }
        if (!$isSuperAdmin && $requestedHotelId !== null && !in_array($requestedHotelId, $permittedHotelIds, true)) {
            throw new RuntimeException('system_hotel_id is outside permitted scope', 403);
        }
        if ($requestedHotelId === null) {
            if (!$isSuperAdmin && count($permittedHotelIds) === 1) {
                $requestedHotelId = $permittedHotelIds[0];
            } elseif (!$portfolio) {
                throw new RuntimeException('hotel_scope_required_for_multi_hotel_user', 422);
            }
        }

        if ($requestedHotelId !== null) {
            $filters['system_hotel_id'] = $requestedHotelId;
        }
        if (!$isSuperAdmin) {
            $filters['permitted_hotel_ids'] = $permittedHotelIds;
        }
        $filters['portfolio'] = $portfolio;

        return $filters;
    }

    private function positiveHotelId(mixed $value): ?int
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (!ctype_digit($text) || (int)$text <= 0) {
            throw new RuntimeException('Invalid system_hotel_id, expected positive integer', 422);
        }
        return (int)$text;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function httpCode(Throwable $e): int
    {
        $code = $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : 500;
    }
}
