<?php
declare(strict_types=1);

namespace app\controller;

use app\middleware\RetiredFeatureReadOnly;

use app\model\StrategySimulationRecord;
use app\service\SimulationExecutionBridgeService;
use app\service\SimulationExecutionReadinessService;
use think\facade\Db;
use think\Response;
use Throwable;

class StrategySimulation extends Base
{

    public function simulate(): Response
    {
        return $this->retiredWriteResponse();
    }

    public function records(): Response
    {
        try {
            $missingTables = $this->getMissingStrategyTables();
            if (!empty($missingTables)) {
                return $this->error('战略推演数据表缺失: ' . implode(', ', $missingTables), 500);
            }

            $query = StrategySimulationRecord::whereNull('deleted_at');
            $this->applyTenantScope($query);
            if (!$this->currentUser->isSuperAdmin()) {
                $query->where('created_by', (int)($this->currentUser->id ?? 0));
            }

            $rows = $query->order('id', 'desc')->limit(30)->select()->toArray();
            $list = array_values(array_map(fn(array $row): array => $this->formatRecord($row, false), $rows));
            $list = (new SimulationExecutionBridgeService())->attachToRecords(
                $list,
                'strategy_simulation',
                $this->executionBridgeHotelIds()
            );

            return $this->success([
                'list' => $list,
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取战略推演记录失败: ' . $e->getMessage(), 400);
        }
    }

    public function detail(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('战略推演记录ID无效', 422);
            }

            $query = StrategySimulationRecord::where('id', $id)->whereNull('deleted_at');
            $this->applyTenantScope($query);
            if (!$this->currentUser->isSuperAdmin()) {
                $query->where('created_by', (int)($this->currentUser->id ?? 0));
            }

            $row = $query->find();
            if (!$row) {
                return $this->error('战略推演记录不存在或无权访问', 404);
            }

            $record = $this->formatRecord($row->toArray(), true);
            $record = (new SimulationExecutionBridgeService())->attachToRecord(
                $record,
                'strategy_simulation',
                $this->executionBridgeHotelIds()
            );

            return $this->success($record);
        } catch (\Throwable $e) {
            return $this->error('获取战略推演详情失败: ' . $e->getMessage(), 400);
        }
    }

    public function createExecutionIntent(int $id): Response
    {
        return $this->retiredWriteResponse();
    }

    public function archive(int $id): Response
    {
        return $this->retiredWriteResponse();
    }

    /** @return array{target_date:string,start:string,end:string,basis:string} */

    /** @param array<int, int|string> $permittedHotelIds */

    private function formatRecord(array $row, bool $withDetail): array
    {
        $input = $this->decodeJson($row['input_json'] ?? []);
        if (empty($input)) {
            $input = [
                'project_name' => $row['project_name'] ?? '',
                'city_tier' => '',
                'city' => $row['city'] ?? '',
                'district' => $row['district'] ?? '',
                'address' => $row['address'] ?? '',
                'property_area' => (float)($row['property_area'] ?? 0),
                'room_count' => (int)($row['room_count'] ?? 0),
                'monthly_rent' => (float)($row['monthly_rent'] ?? 0),
                'decoration_budget' => (float)($row['decoration_budget'] ?? 0),
                'lease_years' => (int)($row['lease_years'] ?? 0),
                'rent_free_months' => (int)($row['rent_free_months'] ?? 0),
                'business_type' => $row['business_type'] ?? '',
                'target_customer' => $row['target_customer'] ?? '',
                'target_hotel_level' => $row['target_hotel_level'] ?? '',
                'competitor_count' => (int)($row['competitor_count'] ?? 0),
            ];
        }

        $scoreJson = $this->decodeJson($row['score_json'] ?? []);
        $recommendation = $this->decodeJson($row['recommendation_json'] ?? []);
        $risk = $this->decodeJson($row['risk_json'] ?? []);
        $dataSnapshot = $this->decodeJson($row['data_snapshot_json'] ?? []);
        $totalScore = array_key_exists('total_score', $scoreJson) && is_numeric($scoreJson['total_score'])
            ? (int)$scoreJson['total_score']
            : null;
        $scoreItems = $scoreJson['items'] ?? $scoreJson;

        $record = [
            'id' => (int)($row['id'] ?? 0),
            'record_id' => (int)($row['id'] ?? 0),
            '_execution_source_tenant_id' => (int)($row['tenant_id'] ?? 0),
            'project_name' => (string)($row['project_name'] ?? ($input['project_name'] ?? '')),
            'city_tier' => (string)($input['city_tier'] ?? ''),
            'city' => (string)($row['city'] ?? ($input['city'] ?? '')),
            'district' => (string)($row['district'] ?? ($input['district'] ?? '')),
            'total_score' => $totalScore,
            'score_type' => (string)($scoreJson['score_type'] ?? 'legacy_rule_score'),
            'score_semantics' => (string)($scoreJson['score_semantics'] ?? '历史规则分，不等同真实市场热度或投资成功率'),
            'decision_ready' => ($scoreJson['decision_ready'] ?? false) === true,
            'data_gaps' => array_values((array)($scoreJson['data_gaps'] ?? [])),
            'risk_level' => (string)($risk['risk_level'] ?? ''),
            'decision' => (string)($recommendation['decision'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'execution_readiness' => (new SimulationExecutionReadinessService())->buildStrategyReadiness($input, $scoreJson, $recommendation, $risk, $dataSnapshot),
        ];

        if (!$withDetail) {
            return $record;
        }

        return array_merge($record, [
            'input' => $input,
            'scores' => $scoreItems,
            'recommendation' => $recommendation,
            'risk' => $risk,
            'data_snapshot' => $dataSnapshot,
        ]);
    }

    private function applyTenantScope($query): void
    {
        if ($this->currentUser->isSuperAdmin()) {
            return;
        }

        $tenantId = $this->tenantIdForCurrentUser();
        if ($tenantId === null) {
            $query->where('tenant_id', -1);
            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    private function ensureLogin(): void
    {
        if (!$this->currentUser) {
            throw new \RuntimeException('please login first');
        }
    }

    /**
     * @return array{0:array<int, int>, 1:int}
     */

    private function executionBridgeHotelIds(): array
    {
        if (!$this->currentUser) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            'intval',
            $this->currentUser->getPermittedHotelIds()
        ))));
    }

    private function tenantIdForCurrentUser(): ?int
    {
        $userId = (int)($this->currentUser->id ?? 0);
        if ($userId <= 0) {
            return null;
        }

        try {
            $row = Db::name('users')->where('id', $userId)->field('tenant_id,hotel_id')->find();
            if (!$row) {
                return null;
            }

            $tenantId = (int)($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                return $tenantId;
            }

            $hotelId = (int)($row['hotel_id'] ?? 0);
            if ($hotelId <= 0) {
                return null;
            }

            $hotelTenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
            return $hotelTenantId > 0 ? $hotelTenantId : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array{eligible:bool,status:string,reason_codes:array<int,string>,platform:string,collected_at:string,source_method:string,trace_id:string} */

    private function getMissingStrategyTables(): array
    {
        $requiredTables = [
            'strategy_simulation_records',
            'strategy_data_snapshots',
        ];

        return array_values(array_filter($requiredTables, fn (string $table): bool => !$this->tableExists($table)));
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->select();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $data = json_decode($value, true);
        return is_array($data) ? $data : [];
    }

    private function retiredWriteResponse(): Response
    {
        if (!$this->currentUser) {
            return $this->error('请先登录', 401);
        }

        return RetiredFeatureReadOnly::response('战略推演');
    }

}
