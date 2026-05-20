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
        foreach (['source', 'data_type', 'hotel_id', 'system_hotel_id', 'start_date', 'end_date', 'limit'] as $key) {
            $value = $this->request->param($key, null);
            if ($value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    private function httpCode(RuntimeException $e): int
    {
        $code = $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : 500;
    }
}
