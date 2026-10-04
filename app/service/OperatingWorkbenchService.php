<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use think\facade\Db;

final class OperatingWorkbenchService
{
    private OperatingWorkbenchSnapshotService $store;
    private OperatingWorkbenchMetricsService $metrics;
    public function __construct()
    {
        $this->store = new OperatingWorkbenchSnapshotService();
        $this->metrics = new OperatingWorkbenchMetricsService();
    }

    public function overview(int $tenant, array $permitted, array $ids, string $month, string $date): array
    {
        OperatingWorkbenchMetricsService::month($month); OperatingWorkbenchMetricsService::date($date);
        if ($ids === [] || count($ids) > 20 || count(array_unique($ids)) !== count($ids)) throw new InvalidArgumentException('workbench_hotel_selection_invalid');
        if ($date < $month . '-01' || substr($date, 0, 7) !== $month) throw new InvalidArgumentException('workbench_date_month_mismatch');
        $today = (new DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        if ($date > $today) throw new InvalidArgumentException('workbench_future_date');
        $items = [];
        foreach ($ids as $hotel) {
            $scope = $this->store->scope($tenant, $permitted, $hotel, 'budget_' . $month);
            $budget = $this->store->latest($scope);
            if (!$budget) $budget = $this->legacyBudget($tenant, $hotel, $month);
            $facts = $this->metrics->hotelFacts($tenant, $hotel, $month . '-01', $date);
            $daily = $this->metrics->summarize([end($facts['series'])], 1);
            $items[] = ['hotel_id' => $hotel, 'hotel_name' => Db::name('hotels')->where('id', $hotel)->where('tenant_id', $tenant)->value('name'),
                'budget' => $budget, 'month' => $facts, 'daily' => $daily, 'comparison' => $this->metrics->compare($facts, $budget)];
        }
        return ['contract_version' => OperatingWorkbenchSnapshotService::CONTRACT, 'tenant_id' => $tenant, 'hotel_ids' => $ids,
            'period_month' => $month, 'business_date' => $date, 'items' => $items, 'external_write_count' => 0,
            'boundary' => '同租户所选酒店、同账期；预算是人工假设，缺失日期不补零。出租率和线上线下收入为人工日报口径。'];
    }

    public function report(int $tenant, array $permitted, int $hotel, string $end): array
    {
        OperatingWorkbenchMetricsService::date($end);
        $scope = $this->store->scope($tenant, $permitted, $hotel, 'report_' . $end);
        $start = (new DateTimeImmutable($end))->modify('-6 days')->format('Y-m-d');
        $facts = $this->metrics->hotelFacts($tenant, $hotel, $start, $end);
        $month = substr($end, 0, 7);
        $monthData = $this->overview($tenant, $permitted, [$hotel], $month, $end)['items'][0];
        $weeklyTarget = $monthData['budget']['inputs']['weekly_target'] ?? null;
        $actual = $facts['admitted_revenue'];
        return ['period_start' => $start, 'period_end' => $end, 'facts' => $facts, 'month' => $monthData,
            'weekly_target' => $weeklyTarget, 'weekly_completion_percent' => $actual !== null && $weeklyTarget > 0 ? OperatingWorkbenchMetricsService::finite($actual / $weeklyTarget * 100) : null,
            'human_judgment' => '', 'manager_note' => '', 'actions' => [], 'human_review_status' => 'pending',
            'source_quality' => $facts['status'], 'automatic_prefill' => true, 'generated_at' => date('c'), 'scope' => $scope,
            'prior_snapshot' => $this->store->latest($scope), 'external_write_count' => 0];
    }

    public function save(int $tenant, array $permitted, int $hotel, int $actor, array $input): array
    {
        $type = $input['type'] ?? '';
        $body = is_array($input['inputs'] ?? null) ? $input['inputs'] : [];
        if ($type === 'budget') {
            $body = $this->metrics->budget($body); $kind = 'budget_' . $body['period_month'];
            foreach ($body['tasks'] as $task) if (substr($task['due_date'], 0, 7) !== $body['period_month']) throw new InvalidArgumentException('workbench_task_outside_month');
        } elseif ($type === 'report') {
            $end = OperatingWorkbenchMetricsService::date((string)($body['period_end'] ?? ''));
            $requestDigest = hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $reportScope = $this->store->scope($tenant, $permitted, $hotel, 'report_' . $end);
            $replay = $this->store->reportReplay($reportScope, (string)($input['idempotency_key'] ?? ''), $actor, $requestDigest);
            if ($replay) return $replay;
            $generated = $this->report($tenant, $permitted, $hotel, (string)($body['period_end'] ?? ''));
            unset($generated['prior_snapshot']);
            $generated['human_judgment'] = OperatingWorkbenchMetricsService::text($body['human_judgment'] ?? '', 5000);
            $generated['manager_note'] = OperatingWorkbenchMetricsService::text($body['manager_note'] ?? '', 2000);
            $generated['actions'] = $this->metrics->tasks($body['actions'] ?? []);
            $generated['human_review_status'] = ($body['human_review_confirmed'] ?? false) === true ? 'reviewed' : 'pending';
            $generated['human_reviewed_by'] = $generated['human_review_status'] === 'reviewed' ? $actor : null;
            $generated['request_digest'] = $requestDigest;
            $kind = 'report_' . $generated['period_end']; $body = $generated;
        } elseif ($type === 'appeal') {
            $case = (string)($input['case_key'] ?? ''); $kind = 'appeal_' . $case;
            $scope = $this->store->scope($tenant, $permitted, $hotel, $kind);
            $previous = $this->store->latest($scope);
            $body = (new ReviewAppealWorkbenchService())->normalize($body, $previous, $actor);
        } else throw new InvalidArgumentException('workbench_type_invalid');
        $scope = $this->store->scope($tenant, $permitted, $hotel, $kind);
        return $this->store->save($scope, $body, $actor, (string)($input['idempotency_key'] ?? ''), (int)($input['expected_snapshot_id'] ?? 0));
    }

    private function legacyBudget(int $tenant, int $hotel, string $month): ?array
    {
        $row = Db::name('monthly_tasks')->where('tenant_id', $tenant)->where('hotel_id', $hotel)
            ->where('year', (int)substr($month, 0, 4))->where('month', (int)substr($month, 5, 2))->where('status', 1)->order('id', 'desc')->find();
        if (!$row) return null;
        $raw = json_decode((string)$row['task_data'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($raw)) throw new InvalidArgumentException('workbench_legacy_budget_invalid');
        return ['snapshot_id' => 0, 'readback_verified' => false, 'legacy_monthly_task_id' => (int)$row['id'],
            'inputs' => ['period_month' => $month, 'revenue_budget' => OperatingWorkbenchMetricsService::number($raw['revenue_budget'] ?? $raw['revenue_target'] ?? null),
                'online_target' => OperatingWorkbenchMetricsService::number($raw['online_revenue_target'] ?? null),
                'offline_target' => OperatingWorkbenchMetricsService::number($raw['offline_revenue_target'] ?? null),
                'other_revenue_target' => null, 'break_even_revenue' => null, 'weekly_target' => null, 'tax_basis' => 'unknown',
                'source_ref' => 'monthly_tasks#' . $row['id'], 'source_quality' => 'legacy_manual_unverified', 'tasks' => []]];
    }
}
