<?php
declare(strict_types=1);

namespace app\service;

class OtaInsightAnalysisService
{
    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    public function analyzeMetrics(array $metrics): array
    {
        $totals = (array)($metrics['totals'] ?? []);
        $traffic = (array)($metrics['traffic'] ?? []);
        $price = (array)($metrics['competitor_price'] ?? []);

        return [
            'status' => ($metrics['status'] ?? '') === 'ready' ? 'ready' : 'insufficient_data',
            'generated_at' => date('Y-m-d H:i:s'),
            'model_policy' => [
                'model_type' => 'deterministic_rules',
                'excluded_models' => ['LSTM', 'ARIMA', 'neural_network'],
                'reason' => 'Use transparent rules first for ADR, RevPAR, Net RevPAR, cancellation, traffic conversion, and competitor price gap.',
            ],
            'modules' => [
                $this->adrModule($totals),
                $this->revparModule($totals),
                $this->netRevparModule($totals),
                $this->cancellationModule($totals),
                $this->trafficModule($traffic),
                $this->priceGapModule($price),
            ],
            'data_gaps' => $metrics['data_gaps'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function adrModule(array $totals): array
    {
        $adr = $totals['adr'] ?? null;
        if ($adr === null) {
            return $this->module(
                'adr',
                'missing_data',
                'P0',
                'ADR cannot be calculated because room nights are missing or zero.',
                'Backfill revenue and room_nights before calculating ADR.'
            );
        }

        return $this->module(
            'adr',
            'available',
            'P1',
            'ADR is calculated from standardized OTA revenue and room nights.',
            'Break down ADR by platform and hotel, then review abnormal high or low price dates.',
            ['adr' => (float)$adr]
        );
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function revparModule(array $totals): array
    {
        $revpar = $totals['revpar'] ?? null;
        if ($revpar === null) {
            return $this->module(
                'revpar',
                'missing_data',
                'P0',
                'RevPAR cannot be calculated because available room nights are missing.',
                'Backfill available_room_nights before calculating RevPAR.'
            );
        }

        return $this->module(
            'revpar',
            'available',
            'P1',
            'RevPAR is calculated from standardized room revenue and available room nights.',
            'Split RevPAR movement into ADR and OCC before creating price or inventory actions.',
            ['revpar' => (float)$revpar]
        );
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function netRevparModule(array $totals): array
    {
        $netRevpar = $totals['net_revpar'] ?? null;
        if ($netRevpar === null) {
            return $this->module(
                'net_revpar',
                'missing_data',
                'P0',
                'Net RevPAR cannot be calculated because net revenue or available room nights are missing.',
                'Backfill commission amount, commission rate, or platform net revenue before evaluating after-commission yield.'
            );
        }

        return $this->module(
            'net_revpar',
            'available',
            'P1',
            'Net RevPAR is calculated from after-commission revenue and available room nights.',
            'Compare Net RevPAR by platform to identify channels with weak after-commission yield.',
            [
                'net_revpar' => (float)$netRevpar,
                'commission_rate' => $totals['commission_rate'] ?? null,
            ]
        );
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function cancellationModule(array $totals): array
    {
        $rate = $totals['cancellation_rate'] ?? null;
        if ($rate === null) {
            return $this->module(
                'cancellation_rate',
                'missing_data',
                'P0',
                'Cancellation rate is not available because cancellation fields are missing.',
                'Add order cancellation fields, at least cancel_order_num or cancel_rate.'
            );
        }

        $priority = (float)$rate >= 30 ? 'P0' : ((float)$rate >= 15 ? 'P1' : 'P2');
        return $this->module(
            'cancellation_rate',
            'available',
            $priority,
            'Cancellation rate is calculated from OTA order facts.',
            'Review cancellation policy, prepayment rules, and competitor price on high-cancellation dates.',
            ['cancellation_rate' => (float)$rate]
        );
    }

    /**
     * @param array<string, mixed> $traffic
     * @return array<string, mixed>
     */
    private function trafficModule(array $traffic): array
    {
        $flowRate = $traffic['avg_flow_rate'] ?? null;
        $submitRate = $traffic['avg_submit_rate'] ?? null;
        if ($flowRate === null && $submitRate === null) {
            return $this->module(
                'traffic_conversion',
                'missing_data',
                'P0',
                'Traffic conversion cannot be evaluated without traffic facts.',
                'Backfill exposure, detail visit, order filling, and order submit counts.'
            );
        }

        $watch = ($flowRate !== null && (float)$flowRate < 15) || ($submitRate !== null && (float)$submitRate < 20);
        return $this->module(
            'traffic_conversion',
            $watch ? 'watch' : 'available',
            $watch ? 'P1' : 'P2',
            'Traffic conversion is calculated from standardized OTA traffic facts.',
            'For low conversion, inspect ranking, hero image, price display, and room availability first.',
            [
                'avg_flow_rate' => $flowRate,
                'avg_submit_rate' => $submitRate,
            ]
        );
    }

    /**
     * @param array<string, mixed> $price
     * @return array<string, mixed>
     */
    private function priceGapModule(array $price): array
    {
        $gap = $price['avg_price_gap'] ?? null;
        $competitor = $price['avg_competitor_price'] ?? null;
        if ($gap === null || $competitor === null || (float)$competitor <= 0) {
            return $this->module(
                'competitor_price_gap',
                'missing_data',
                'P0',
                'Competitor price gap is unavailable because price fields are missing.',
                'Add our_price and competitor_price before evaluating price gaps.'
            );
        }

        $gapRate = round((float)$gap / (float)$competitor * 100, 2);
        $watch = abs($gapRate) >= 5;
        return $this->module(
            'competitor_price_gap',
            $watch ? 'watch' : 'available',
            $watch ? 'P1' : 'P2',
            'Competitor price gap is calculated from standardized OTA daily facts.',
            'When the gap exceeds 5%, compare the same room type, cancellation policy, and breakfast package.',
            [
                'avg_price_gap' => (float)$gap,
                'avg_price_gap_rate' => $gapRate,
            ]
        );
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function module(string $key, string $status, string $priority, string $summary, string $action, array $metrics = []): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'priority' => $priority,
            'summary' => $summary,
            'recommended_action' => $action,
            'metrics' => $metrics,
        ];
    }
}
