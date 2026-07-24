<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OtaInsightAnalysisService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use RuntimeException;
use think\Response;

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
            $dataset = (new OtaStandardEtlService())->buildDataset($this->filters());
            if ($dataset['status'] === 'empty') {
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
            $dataset = (new OtaStandardEtlService())->buildDataset($this->filters());
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

    private function httpCode(RuntimeException $e): int
    {
        $code = $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : 500;
    }
}
