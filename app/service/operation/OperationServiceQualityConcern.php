<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\OnlineDataFieldFactService;
use app\service\OnlineDataTrustStatusService;
use app\service\OperationHolidayCalendarService;
use app\service\OtaStandardEtlService;
use app\service\OtaTrafficAttributionService;
use think\facade\Db;
use Throwable;

trait OperationServiceQualityConcern
{

    private function buildServiceQuality(array $hotelIds, string $date): array
    {
        return $this->buildServiceQualityFromRows($this->onlineRows($hotelIds, $date, $date));
    }

    private function buildServiceQualityFromRows(array $rows): array
    {
        $base = [
            'avg_psi_score' => null,
            'avg_service_score' => null,
            'sample_count' => 0,
            'psi_sample_count' => 0,
            'service_score_sample_count' => 0,
            'data_status' => self::DATA_PENDING,
            'score_scale' => 'unknown',
            'threshold_80_eligible' => false,
            'data_gaps' => [],
        ];

        $psiScores = [];
        $serviceScores = [];
        foreach ($rows as $row) {
            $dataType = strtolower((string)($row['data_type'] ?? ''));
            if (!in_array($dataType, ['quality', 'service', 'service_quality', 'psi'], true)) {
                continue;
            }
            if (!$this->isTrustedSelfOtaFactRow($row)) {
                continue;
            }

            $raw = $this->decodeJson((string)($row['raw_data'] ?? ''));
            $psi = $this->nestedOnlineMetric($raw, ['psiScore', 'psi_score', 'psi', 'serviceQualityScore', 'qualityScore']);
            if ($psi === null && str_contains(strtolower((string)($row['dimension'] ?? '')), ':psi_score')) {
                $psi = $this->firstNumericMetric($row, ['data_value']);
            }
            $serviceScore = $this->nestedOnlineMetric($raw, ['serviceScore', 'service_score', 'dayReportServiceScore', 'service_score_value']);

            if ($psi !== null && $psi > 0) {
                $psiScores[] = $psi;
                $base['psi_sample_count']++;
            }
            if ($serviceScore !== null && $serviceScore > 0) {
                $serviceScores[] = $serviceScore;
                $base['service_score_sample_count']++;
            }
            if (($psi !== null && $psi > 0) || ($serviceScore !== null && $serviceScore > 0)) {
                $base['sample_count']++;
            }
        }

        if ($base['sample_count'] <= 0) {
            return $base;
        }

        $base['avg_psi_score'] = $psiScores !== [] ? $this->avg($psiScores) : null;
        $base['avg_service_score'] = $serviceScores !== [] ? $this->avg($serviceScores) : null;
        $scores = array_merge($psiScores, $serviceScores);
        $base['threshold_80_eligible'] = $this->scoresUseHundredPointScale($scores);
        $base['score_scale'] = $base['threshold_80_eligible'] ? '0_100' : 'unknown';
        $base['data_status'] = $base['threshold_80_eligible'] ? self::DATA_OK : 'partial';
        $base['data_gaps'] = $base['threshold_80_eligible'] ? [] : ['service_quality_scale_unknown'];

        return $base;
    }

    /** @param array<string, mixed> $raw @param array<int, string> $keys */
    private function nestedOnlineMetric(array $raw, array $keys): ?float
    {
        $payloads = [$raw];
        foreach ([
            $raw['row'] ?? null,
            $raw['raw_data'] ?? null,
            $raw['row']['raw_data'] ?? null,
        ] as $payload) {
            if (is_array($payload)) {
                $payloads[] = $payload;
            }
        }

        foreach ($payloads as $payload) {
            $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
            $value = $this->firstNumericMetric($metrics, $keys);
            if ($value === null) {
                $value = $this->firstNumericMetric($payload, $keys);
            }
            if ($value !== null) {
                return $value;
            }

            foreach ((array)($payload['facts'] ?? []) as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if (!in_array($metricKey, array_map('strtolower', $keys), true)) {
                    continue;
                }
                $factValue = $fact['value'] ?? null;
                if (is_numeric($factValue)) {
                    return (float)$factValue;
                }
            }
        }

        return null;
    }

    /** @param array<int, mixed> $scores */
    private function scoresUseHundredPointScale(array $scores): bool
    {
        $scores = array_values(array_filter($scores, static fn($value): bool => is_numeric($value) && (float)$value > 0));
        if ($scores === []) {
            return false;
        }
        foreach ($scores as $score) {
            $score = (float)$score;
            if ($score <= 10 || $score > 100) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $serviceQuality */
    private function serviceQualityThresholdEligible(array $serviceQuality): bool
    {
        if (array_key_exists('threshold_80_eligible', $serviceQuality)) {
            return $serviceQuality['threshold_80_eligible'] === true;
        }
        return $this->scoresUseHundredPointScale([
            $serviceQuality['avg_psi_score'] ?? null,
            $serviceQuality['avg_service_score'] ?? null,
        ]);
    }

    private function buildHoliday(string $date): array
    {
        return (new OperationHolidayCalendarService())->build($date);
    }

    private function averageOnlineMetrics(array $hotelIds, string $date, int $days): array
    {
        $start = date('Y-m-d', strtotime($date . ' -' . $days . ' days'));
        $end = date('Y-m-d', strtotime($date . ' -1 day'));
        $rows = $this->latestOnlineFlowRows($this->onlineRows($hotelIds, $start, $end));
        if (empty($rows)) {
            return [];
        }

        $byDate = [];
        foreach ($rows as $row) {
            $day = (string)$row['data_date'];
            $metrics = $this->onlineFlowMetrics($row);
            $byDate[$day]['exposure'] = ($byDate[$day]['exposure'] ?? 0) + $metrics['exposure'];
            $byDate[$day]['visitors'] = ($byDate[$day]['visitors'] ?? 0) + $metrics['visitors'];
            $byDate[$day]['views'] = ($byDate[$day]['views'] ?? 0) + $metrics['views'];
            $byDate[$day]['orders'] = ($byDate[$day]['orders'] ?? 0) + $metrics['orders'];
        }

        $count = max(1, count($byDate));
        $sum = ['exposure' => 0, 'visitors' => 0, 'views' => 0, 'orders' => 0];
        foreach ($byDate as $metric) {
            foreach ($sum as $key => $value) {
                $sum[$key] += (float)($metric[$key] ?? 0);
            }
        }

        $exposure = $sum['exposure'] / $count;
        $visitors = $sum['visitors'] / $count;
        $views = $sum['views'] / $count;
        $orders = $sum['orders'] / $count;

        return [
            'exposure' => $exposure,
            'visitors' => $visitors,
            'views' => $views,
            'orders' => $orders,
            'view_rate' => $exposure > 0 ? $views / $exposure * 100 : 0,
            'order_rate' => $visitors > 0 ? $orders / $visitors * 100 : 0,
            'data_status' => $exposure > 0 && ($visitors > 0 || $views > 0) ? self::DATA_OK : 'partial',
        ];
    }

}
