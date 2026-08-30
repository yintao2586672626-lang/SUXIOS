<?php
declare(strict_types=1);

namespace app\service\operation;

trait OperationExecutionAssigneeConcern
{
    private function prepareExecutionFlowQuery($query, array $filters): array
    {
        $assigneeId = max(0, (int)($filters['_assignee_id'] ?? 0));
        if ($assigneeId > 0) {
            $intentTable = (string)$query->getTable();
            $taskTable = (string)\think\facade\Db::name('operation_execution_tasks')->getTable();
            $intentAlias = 'assignee_intent';
            $taskAlias = 'assignee_task';
            $intentTargetValueColumn = $this->qualifiedExecutionFlowColumn($intentAlias, 'target_value_json');
            $query->whereIn(
                $intentTable . '.id',
                static function ($intentQuery) use (
                    $assigneeId,
                    $intentAlias,
                    $intentTable,
                    $intentTargetValueColumn,
                    $taskAlias,
                    $taskTable
                ): void {
                    $intentQuery->table([$intentTable => $intentAlias])
                        ->field($intentAlias . '.id')
                        ->whereRaw(
                            "(COALESCE(JSON_EXTRACT({$intentTargetValueColumn}, '$.workflow_schedule.assignee_id'), JSON_EXTRACT({$intentTargetValueColumn}, '$.assignee_id')) + 0 = (? + 0))",
                            [$assigneeId]
                        )->whereExists(static function ($taskQuery) use ($intentAlias, $taskTable, $taskAlias): void {
                            $taskQuery->table([$taskTable => $taskAlias])
                                ->field($taskAlias . '.id')
                                ->whereColumn($taskAlias . '.intent_id', $intentAlias . '.id')
                                ->whereColumn($taskAlias . '.hotel_id', $intentAlias . '.hotel_id')
                                ->whereColumn($taskAlias . '.tenant_id', $intentAlias . '.tenant_id')
                                ->where($taskAlias . '.tenant_id', '>', 0)
                                ->whereNull($taskAlias . '.deleted_at')
                                ->where(static function ($actionable) use ($taskAlias): void {
                                    $actionable->whereIn($taskAlias . '.status', [
                                        'pending_execute', 'executing', 'blocked', 'failed',
                                    ])->whereOr(static function ($review) use ($taskAlias): void {
                                        $review->where($taskAlias . '.status', 'executed')
                                            ->where(static function ($result) use ($taskAlias): void {
                                                $result->whereNull($taskAlias . '.result_status')
                                                    ->whereOr($taskAlias . '.result_status', '')
                                                    ->whereOr($taskAlias . '.result_status', 'observing');
                                            });
                                    });
                                });
                        });
                }
            );
        }
        $matchedTotal = (int)(clone $query)->count();
        $limit = max(1, min(500, (int)($filters['limit'] ?? 100)));
        $intentRows = $query->order('id', 'desc')->limit($limit)->select()->toArray();
        $truncated = $matchedTotal > count($intentRows);
        return compact('assigneeId', 'matchedTotal', 'limit', 'intentRows', 'truncated');
    }

    private function qualifiedExecutionFlowColumn(string $table, string $column): string
    {
        return '`' . str_replace('`', '``', $table) . '`.`' . str_replace('`', '``', $column) . '`';
    }

    private function scopeExecutionFlowItemsToAssignee(
        array $items,
        int $assigneeId,
        int $limit,
        int $matchedTotal,
        bool $truncated,
        array &$dataGaps
    ): array {
        if ($assigneeId <= 0) {
            return compact('items', 'matchedTotal', 'truncated') + ['summaryItems' => $items];
        }
        $loadedItemCount = count($items);
        $summaryItems = array_values(array_filter($items, static function (array $item) use ($assigneeId): bool {
            return (int)($item['execution']['task_id'] ?? 0) > 0
                && (int)($item['assignment']['assignee_id'] ?? 0) === $assigneeId
                && trim((string)($item['next_action']['key'] ?? '')) !== 'none';
        }));
        $items = array_slice($summaryItems, 0, $limit);
        if (count($summaryItems) !== $loadedItemCount) {
            $dataGaps[] = [
                'code' => 'operation_my_tasks_readback_excluded',
                'message' => ($loadedItemCount - count($summaryItems))
                    . ' SQL-scoped task rows failed assignment or actionable readback checks',
            ];
        }
        if ($matchedTotal > count($items)) {
            $truncated = true;
            $dataGaps[] = [
                'code' => 'operation_my_tasks_truncated',
                'message' => "my tasks returned {$limit} of {$matchedTotal} matched tasks",
            ];
        }
        return compact('items', 'summaryItems', 'matchedTotal', 'truncated');
    }

    public function myExecutionTasks(
        array $hotelIds,
        ?int $hotelId,
        int $currentUserId,
        array $filters = []
    ): array {
        if ($currentUserId <= 0) {
            throw new \InvalidArgumentException('current user is required');
        }
        unset($filters['assignee_id'], $filters['user_id'], $filters['_assignee_id']);
        $filters['_assignee_id'] = $currentUserId;
        $result = $this->executionFlow($hotelIds, $hotelId, $filters);
        $result['scope'] = [
            'user_id' => $currentUserId,
            'hotel_id' => $hotelId,
            'hotel_ids' => array_values(array_map('intval', $hotelIds)),
        ];
        return $result;
    }
}
