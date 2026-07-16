<?php
declare(strict_types=1);

namespace app\controller;

use think\Response;
use think\facade\Db;

class Lifecycle extends Base
{
    public function overview(): Response
    {
        $hotelIds = $this->resolveHotelIds();
        $stages = [
            $this->investmentStage(),
            $this->openingStage($hotelIds),
            $this->operationStage($hotelIds),
            $this->revenueStage($hotelIds),
            $this->transferStage($hotelIds),
        ];
        $stageStatuses = array_column($stages, 'status');
        $overviewStatus = in_array('unavailable', $stageStatuses, true) || in_array('partial', $stageStatuses, true)
            ? 'partial'
            : 'ready';

        return $this->success([
            'generated_at' => date('Y-m-d H:i:s'),
            'status' => $overviewStatus,
            'scope_note' => '仅汇总当前账号可读取的模块记录；记录数不代表数据来源已验证，也不代表业务动作已执行。',
            'stages' => $stages,
        ]);
    }

    private function investmentStage(): array
    {
        $countRead = $this->readInt(fn() => Db::name('feasibility_reports')->whereNull('deleted_at')->count());
        $latestRead = $this->readRow(fn() => Db::name('feasibility_reports')->whereNull('deleted_at')->order('id', 'desc')->find());
        $count = $countRead['value'];
        $latest = is_array($latestRead['value']) ? $latestRead['value'] : [];

        return $this->stage('investment', '投资测算', $this->stageStatus([$countRead, $latestRead], ($count ?? 0) > 0), [
            ['label' => 'reports', 'value' => $count],
            ['label' => 'latest_grade', 'value' => $latest['conclusion_grade'] ?? null],
            ['label' => 'latest_project', 'value' => $latest['project_name'] ?? null],
        ]);
    }

    private function openingStage(array $hotelIds): array
    {
        $projectQuery = fn() => Db::name('opening_projects');
        $projectIdsRead = $this->readArray(fn() => $projectQuery()->column('id'));
        $projectIds = $projectIdsRead['value'];
        $taskQuery = function () use ($projectIds) {
            $query = Db::name('opening_tasks');
            return empty($projectIds) ? $query->whereRaw('1=0') : $query->whereIn('project_id', array_map('intval', $projectIds));
        };
        $projectCountRead = $this->readInt(fn() => $projectQuery()->count());
        $openTaskRead = $projectIdsRead['ok']
            ? $this->readInt(fn() => $taskQuery()->whereIn('status', ['todo', 'doing', 'blocked'])->count())
            : $this->failedRead();
        $overdueTaskRead = $projectIdsRead['ok']
            ? $this->readInt(fn() => $taskQuery()->where('deadline', '<', date('Y-m-d'))->whereNotIn('status', ['done'])->count())
            : $this->failedRead();
        $avgScoreRead = $this->readFloat(fn() => $projectQuery()->avg('overall_score'));
        $projectCount = $projectCountRead['value'];
        $openTaskCount = $openTaskRead['value'];
        $overdueTaskCount = $overdueTaskRead['value'];
        $avgScore = $avgScoreRead['value'];

        return $this->stage('opening', '筹开管理', $this->stageStatus([
            $projectIdsRead,
            $projectCountRead,
            $openTaskRead,
            $overdueTaskRead,
            $avgScoreRead,
        ], ($projectCount ?? 0) > 0), [
            ['label' => 'projects', 'value' => $projectCount],
            ['label' => 'open_tasks', 'value' => $openTaskCount],
            ['label' => 'overdue_tasks', 'value' => $overdueTaskCount],
            ['label' => 'avg_score', 'value' => $avgScore === null ? null : round($avgScore, 1)],
        ]);
    }

    private function operationStage(array $hotelIds): array
    {
        $alertsRead = $this->readInt(fn() => $this->withHotelIds(Db::name('operation_alerts'), $hotelIds, 'hotel_id')->where('status', 'unread')->whereNull('deleted_at')->count());
        $actionsRead = $this->readInt(fn() => $this->withHotelIds(Db::name('operation_action_tracks'), $hotelIds, 'hotel_id')->where('status', 'active')->whereNull('deleted_at')->count());
        $onlineRowsRead = $this->readInt(fn() => $this->withHotelIds(Db::name('online_daily_data'), $hotelIds, 'system_hotel_id')->count());
        $alerts = $alertsRead['value'];
        $actions = $actionsRead['value'];
        $onlineRows = $onlineRowsRead['value'];

        return $this->stage('operation', '运营管理', $this->stageStatus(
            [$alertsRead, $actionsRead, $onlineRowsRead],
            (($alerts ?? 0) + ($actions ?? 0) + ($onlineRows ?? 0)) > 0
        ), [
            ['label' => 'unread_alerts', 'value' => $alerts],
            ['label' => 'active_actions', 'value' => $actions],
            ['label' => 'ota_rows', 'value' => $onlineRows],
        ]);
    }

    private function revenueStage(array $hotelIds): array
    {
        $pendingRead = $this->readInt(fn() => $this->withHotelIds(Db::name('price_suggestions'), $hotelIds, 'hotel_id')->where('status', 1)->count());
        $appliedRead = $this->readInt(fn() => $this->withHotelIds(Db::name('price_suggestions'), $hotelIds, 'hotel_id')->where('status', 4)->count());
        $forecastsRead = $this->readInt(fn() => $this->withHotelIds(Db::name('demand_forecasts'), $hotelIds, 'hotel_id')->where('forecast_date', '>=', date('Y-m-d'))->count());
        $pending = $pendingRead['value'];
        $applied = $appliedRead['value'];
        $forecasts = $forecastsRead['value'];

        return $this->stage('revenue', '收益分析', $this->stageStatus(
            [$pendingRead, $appliedRead, $forecastsRead],
            (($pending ?? 0) + ($applied ?? 0) + ($forecasts ?? 0)) > 0
        ), [
            ['label' => 'pending_prices', 'value' => $pending],
            ['label' => 'applied_prices', 'value' => $applied],
            ['label' => 'future_forecasts', 'value' => $forecasts],
        ]);
    }

    private function transferStage(array $hotelIds): array
    {
        $simulationsRead = $this->readInt(fn() => Db::name('strategy_simulation_records')->count());
        $competitorLogsRead = $this->readInt(fn() => $this->withHotelIds(Db::name('competitor_price_log'), $hotelIds, 'hotel_id')->count());
        $simulations = $simulationsRead['value'];
        $competitorLogs = $competitorLogsRead['value'];

        return $this->stage('transfer', '转让决策', $this->stageStatus(
            [$simulationsRead, $competitorLogsRead],
            (($simulations ?? 0) + ($competitorLogs ?? 0)) > 0
        ), [
            ['label' => 'strategy_simulations', 'value' => $simulations],
            ['label' => 'competitor_price_logs', 'value' => $competitorLogs],
        ]);
    }

    private function stage(string $key, string $title, string $status, array $metrics): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'status' => $status,
            'status_text' => [
                'active' => '有可读记录',
                'pending' => '暂无记录',
                'partial' => '部分来源不可用',
                'unavailable' => '来源不可用',
            ][$status] ?? '状态未核验',
            'metrics' => $metrics,
        ];
    }

    private function stageStatus(array $reads, bool $hasRecords): string
    {
        $availableCount = count(array_filter($reads, static fn(array $read): bool => !empty($read['ok'])));
        if ($availableCount === 0) {
            return 'unavailable';
        }
        if ($availableCount < count($reads)) {
            return 'partial';
        }
        return $hasRecords ? 'active' : 'pending';
    }

    private function resolveHotelIds(): array
    {
        if (!$this->currentUser || $this->currentUser->isSuperAdmin()) {
            return [];
        }
        $ids = array_map('intval', $this->currentUser->getPermittedHotelIds());
        return empty($ids) ? [0] : $ids;
    }

    private function withHotelIds($query, array $hotelIds, string $field)
    {
        $hotelIds = array_values(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0));
        return empty($hotelIds) ? $query : $query->whereIn($field, $hotelIds);
    }

    private function readInt(callable $reader): array
    {
        try {
            return ['ok' => true, 'value' => (int)$reader()];
        } catch (\Throwable $e) {
            return $this->failedRead();
        }
    }

    private function readFloat(callable $reader): array
    {
        try {
            $value = $reader();
            return ['ok' => true, 'value' => $value === null ? null : (float)$value];
        } catch (\Throwable $e) {
            return $this->failedRead();
        }
    }

    private function readRow(callable $reader): array
    {
        try {
            $row = $reader();
            return ['ok' => true, 'value' => is_array($row) ? $row : []];
        } catch (\Throwable $e) {
            return $this->failedRead();
        }
    }

    private function readArray(callable $reader): array
    {
        try {
            $value = $reader();
            return ['ok' => true, 'value' => is_array($value) ? $value : []];
        } catch (\Throwable $e) {
            return $this->failedRead();
        }
    }

    private function failedRead(): array
    {
        return ['ok' => false, 'value' => null];
    }
}
