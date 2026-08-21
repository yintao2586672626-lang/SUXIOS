<?php
declare(strict_types=1);

namespace app\controller\concern;

trait AgentOtaDiagnosisActionConcern
{
    private function buildOtaDiagnosisActions(bool $hasTraffic, bool $hasCompetitor, bool $hasAdvertising, bool $hasServiceQuality, array $metrics, array $dataGaps = []): array
    {
        if ($this->blockingOtaDiagnosisDataGaps($dataGaps, ['metrics' => $metrics]) !== []) {
            return [];
        }

        $actions = [];
        if ($hasTraffic && array_key_exists('list_exposure', $metrics) && $metrics['list_exposure'] !== null && (float)$metrics['list_exposure'] === 0.0) {
            $actions[] = '检查目标日期门店可售状态、列表页内容完整性和平台曝光入口，确认目标平台列表曝光为0的原因。';
        }
        if ($hasTraffic && (float)($metrics['list_exposure'] ?? 0) > 0 && is_numeric($metrics['detail_rate'] ?? null) && (float)$metrics['detail_rate'] < 5) {
            $actions[] = '优先优化列表页主图、标题卖点和页面信息呈现，提升曝光到访问转化。';
        }
        if ($hasTraffic && (float)($metrics['detail_visitors'] ?? 0) > 0 && is_numeric($metrics['order_rate'] ?? null) && (float)$metrics['order_rate'] < 3) {
            $actions[] = '检查详情页房型、取消政策、促销和价格阶梯，降低访问后的下单阻力。';
        }
        if ($hasAdvertising && (float)($metrics['advertising_roas'] ?? 0) > 0 && (float)$metrics['advertising_roas'] < 3) {
            $actions[] = '复核OTA广告投放词、出价和落地房型，ROAS低于3时先控预算再优化转化链路。';
        }
        if ($hasServiceQuality
            && $this->otaDiagnosisHundredPointScoreEligible($metrics['avg_psi_score'] ?? null)
            && (float)$metrics['avg_psi_score'] < 85
        ) {
            $actions[] = '把OTA服务质量分作为转化背景信号，先排查服务响应、到店履约和平台服务质量扣分项。';
        }
        if ($actions === [] && $this->blockingOtaDiagnosisDataGaps($dataGaps, ['metrics' => $metrics]) !== []) {
            $actions[] = '先补齐缺失的数据源，再按曝光、访问、订单、广告效率、服务质量顺序复盘。';
        }
        return $actions;
    }

    private function otaDiagnosisHundredPointScoreEligible(mixed $score): bool
    {
        if (!is_numeric($score)) {
            return false;
        }

        $score = (float)$score;
        return $score > 10 && $score <= 100;
    }
}
