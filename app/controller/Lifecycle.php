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

        return $this->success([
            'generated_at' => date('Y-m-d H:i:s'),
            'stages' => [
                $this->investmentStage(),
                $this->openingStage($hotelIds),
                $this->operationStage($hotelIds),
                $this->revenueStage($hotelIds),
                $this->transferStage($hotelIds),
            ],
        ]);
    }

    private function investmentStage(): array
    {
        $count = $this->safeInt(fn() => Db::name('feasibility_reports')->whereNull('deleted_at')->count());
        $latest = $this->safeRow(fn() => Db::name('feasibility_reports')->whereNull('deleted_at')->order('id', 'desc')->find());

        return $this->stage('investment', 'Investment', $count > 0 ? 'active' : 'pending', [
            ['label' => 'reports', 'value' => $count],
            ['label' => 'latest_grade', 'value' => (string)($latest['conclusion_grade'] ?? '-')],
            ['label' => 'latest_project', 'value' => (string)($latest['project_name'] ?? '-')],
        ]);
    }

    private function openingStage(array $hotelIds): array
    {
        $projectQuery = fn() => $this->withHotelIds(Db::name('opening_projects'), $hotelIds, 'hotel_id');
        $projectIds = $this->safeArray(fn() => $projectQuery()->column('id'));
        $taskQuery = function () use ($projectIds, $hotelIds) {
            $query = Db::name('opening_tasks');
            if (!empty($hotelIds)) {
                return empty($projectIds) ? $query->whereRaw('1=0') : $query->whereIn('project_id', array_map('intval', $projectIds));
            }
            return $query;
        };
        $projectCount = $this->safeInt(fn() => $projectQuery()->count());
        $openTaskCount = $this->safeInt(fn() => $taskQuery()->whereIn('status', ['todo', 'doing', 'blocked'])->count());
        $overdueTaskCount = $this->safeInt(fn() => $taskQuery()->where('deadline', '<', date('Y-m-d'))->whereNotIn('status', ['done'])->count());
        $avgScore = $this->safeFloat(fn() => $projectQuery()->avg('overall_score'));

        return $this->stage('opening', 'Opening', $projectCount > 0 ? 'active' : 'pending', [
            ['label' => 'projects', 'value' => $projectCount],
            ['label' => 'open_tasks', 'value' => $openTaskCount],
            ['label' => 'overdue_tasks', 'value' => $overdueTaskCount],
            ['label' => 'avg_score', 'value' => round($avgScore, 1)],
        ]);
    }

    private function operationStage(array $hotelIds): array
    {
        $alerts = $this->safeInt(fn() => $this->withHotelIds(Db::name('operation_alerts'), $hotelIds, 'hotel_id')->where('status', 'unread')->whereNull('deleted_at')->count());
        $actions = $this->safeInt(fn() => $this->withHotelIds(Db::name('operation_action_tracks'), $hotelIds, 'hotel_id')->where('status', 'active')->whereNull('deleted_at')->count());
        $onlineRows = $this->safeInt(fn() => $this->withHotelIds(Db::name('online_daily_data'), $hotelIds, 'system_hotel_id')->count());

        return $this->stage('operation', 'Operation', ($alerts + $actions + $onlineRows) > 0 ? 'active' : 'pending', [
            ['label' => 'unread_alerts', 'value' => $alerts],
            ['label' => 'active_actions', 'value' => $actions],
            ['label' => 'ota_rows', 'value' => $onlineRows],
        ]);
    }

    private function revenueStage(array $hotelIds): array
    {
        $pending = $this->safeInt(fn() => $this->withHotelIds(Db::name('price_suggestions'), $hotelIds, 'hotel_id')->where('status', 1)->count());
        $applied = $this->safeInt(fn() => $this->withHotelIds(Db::name('price_suggestions'), $hotelIds, 'hotel_id')->where('status', 4)->count());
        $forecasts = $this->safeInt(fn() => $this->withHotelIds(Db::name('demand_forecasts'), $hotelIds, 'hotel_id')->where('forecast_date', '>=', date('Y-m-d'))->count());

        return $this->stage('revenue', 'Revenue', ($pending + $applied + $forecasts) > 0 ? 'active' : 'pending', [
            ['label' => 'pending_prices', 'value' => $pending],
            ['label' => 'applied_prices', 'value' => $applied],
            ['label' => 'future_forecasts', 'value' => $forecasts],
        ]);
    }

    private function transferStage(array $hotelIds): array
    {
        $simulations = $this->safeInt(fn() => Db::name('strategy_simulation_records')->count());
        $competitorLogs = $this->safeInt(fn() => $this->withHotelIds(Db::name('competitor_price_log'), $hotelIds, 'hotel_id')->count());

        return $this->stage('transfer', 'Transfer', ($simulations + $competitorLogs) > 0 ? 'active' : 'pending', [
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
            'status_text' => $status === 'active' ? 'linked' : 'waiting_data',
            'metrics' => $metrics,
        ];
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

    private function safeInt(callable $reader): int
    {
        try {
            return (int)$reader();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function safeFloat(callable $reader): float
    {
        try {
            return (float)$reader();
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function safeRow(callable $reader): array
    {
        try {
            $row = $reader();
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function safeArray(callable $reader): array
    {
        try {
            $value = $reader();
            return is_array($value) ? $value : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
