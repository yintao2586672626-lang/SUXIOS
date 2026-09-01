<?php
declare(strict_types=1);

namespace app\service\concern;

use app\service\OperatingLoopKernelService;

trait AiDailyReportReadinessConcern
{
    /** @return array<string,mixed> */
    private function reportKernelSummary(array $report): array
    {
        $scope = is_array($report['report_scope'] ?? null) ? $report['report_scope'] : [];
        $hotelId = (int)($report['hotel_id'] ?? $scope['hotel_id'] ?? 0);
        $businessDate = substr(trim((string)($report['report_date'] ?? $scope['report_date'] ?? '')), 0, 10);
        $tenantId = (int)($report['tenant_id'] ?? 0);
        if ($tenantId <= 0 && $hotelId > 0) {
            try {
                $tenantId = (int)($this->resolveHotelTenantId($hotelId) ?? 0);
            } catch (\Throwable $e) {
                $tenantId = 0;
            }
        }
        if ($hotelId <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) !== 1) {
            return [
                'kernel_id' => null,
                'revision' => 0,
                'authoritative_state' => 'not_started',
                'readback_verified' => false,
                'next_action' => ['action' => '先确认酒店和业务日期，再读取权威经营闭环'],
                'source_policy' => 'hotel_operating_cycle_kernel_only',
            ];
        }

        try {
            return (new OperatingLoopKernelService())->currentForHotelDate($tenantId, $hotelId, $businessDate);
        } catch (\Throwable $e) {
            return [
                'kernel_id' => null,
                'revision' => 0,
                'authoritative_state' => 'not_started',
                'readback_verified' => false,
                'next_action' => ['action' => '权威经营闭环暂不可回读，请先核对内核状态'],
                'source_policy' => 'hotel_operating_cycle_kernel_only',
            ];
        }
    }

    public function readinessSummaryFromRows(array $rows, array $hotelIds = [], ?int $hotelId = null): array
    {
        $reports = $this->enrichReportRows($rows, $hotelIds, $hotelId);
        $summary = [
            'record_count' => count($reports),
            'best_score' => 0,
            'best_status_label' => '',
            'closed_loop_count' => 0,
            'transferred_count' => 0,
            'evidence_ready_count' => 0,
            'reviewed_count' => 0,
            'roi_ready_count' => 0,
            'missing_evidence' => [],
        ];

        foreach ($reports as $report) {
            $readiness = is_array($report['report_readiness'] ?? null) ? $report['report_readiness'] : [];
            if (($readiness['closed_loop'] ?? false) === true) {
                $summary['closed_loop_count']++;
            }
            $summary['transferred_count'] += (int)($readiness['transferred_count'] ?? 0);
            $summary['evidence_ready_count'] += (int)($readiness['evidence_ready_count'] ?? 0);
            $summary['reviewed_count'] += (int)($readiness['reviewed_count'] ?? 0);
            $summary['roi_ready_count'] += (int)($readiness['roi_ready_count'] ?? 0);
            if ((int)($readiness['score'] ?? 0) >= (int)$summary['best_score']) {
                $summary['best_score'] = (int)($readiness['score'] ?? 0);
                $summary['best_status_label'] = (string)($readiness['status_label'] ?? '');
                $summary['missing_evidence'] = array_slice((array)($readiness['missing_evidence'] ?? []), 0, 4);
            }
        }

        return $summary;
    }

}
