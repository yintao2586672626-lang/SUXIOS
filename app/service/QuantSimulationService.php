<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

class QuantSimulationService
{
    public function calculateAndSave(array $payload, int $userId): array
    {
        $this->ensureTable();

        $input = $this->normalizeInput($payload['input'] ?? $payload);
        $projectName = trim((string)($payload['project_name'] ?? $payload['projectName'] ?? '量化模拟项目'));
        if ($projectName === '') {
            $projectName = '量化模拟项目';
        }

        $result = $this->calculateSimulation($input);
        $scenarios = $this->buildScenarios($input);
        $riskHints = $this->buildRiskHints($result);
        $now = date('Y-m-d H:i:s');

        $id = (int)Db::name('quant_simulation_records')->insertGetId([
            'project_name' => $projectName,
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'scenarios_json' => json_encode($scenarios, JSON_UNESCAPED_UNICODE),
            'risk_hints_json' => json_encode($riskHints, JSON_UNESCAPED_UNICODE),
            'monthly_net_cashflow' => (float)$result['monthlyNetCashflow'],
            'payback_months' => $result['paybackMonths'],
            'risk_level' => (string)$result['riskLevel'],
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->detail($id, $userId, true);
    }

    public function records(int $userId, bool $isSuperAdmin): array
    {
        $this->ensureTable();

        $query = Db::name('quant_simulation_records')->whereNull('deleted_at');
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $rows = $query->order('id', 'desc')->limit(30)->select()->toArray();
        return array_values(array_map(fn(array $row): array => $this->formatRecord($row, false), $rows));
    }

    public function detail(int $id, int $userId, bool $isSuperAdmin): array
    {
        $this->ensureTable();

        $query = Db::name('quant_simulation_records')->where('id', $id)->whereNull('deleted_at');
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $row = $query->find();
        if (!$row) {
            throw new \RuntimeException('量化模拟记录不存在或无权访问');
        }

        return $this->formatRecord($row, true);
    }

    public function archive(int $id, int $userId, bool $isSuperAdmin): bool
    {
        $this->ensureTable();

        $query = Db::name('quant_simulation_records')->where('id', $id)->whereNull('deleted_at');
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $now = date('Y-m-d H:i:s');
        $affected = $query->update([
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
        if ((int)$affected <= 0) {
            throw new \RuntimeException('量化模拟记录不存在或无权访问');
        }

        return true;
    }

    public function ensureTable(): void
    {
        Db::execute("
            CREATE TABLE IF NOT EXISTS quant_simulation_records (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                project_name VARCHAR(120) NOT NULL DEFAULT '',
                input_json JSON DEFAULT NULL,
                result_json JSON DEFAULT NULL,
                scenarios_json JSON DEFAULT NULL,
                risk_hints_json JSON DEFAULT NULL,
                monthly_net_cashflow DECIMAL(14,2) NOT NULL DEFAULT 0,
                payback_months DECIMAL(10,2) DEFAULT NULL,
                risk_level VARCHAR(30) NOT NULL DEFAULT '',
                created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_quant_sim_created_by (created_by, id),
                INDEX idx_quant_sim_risk_level (risk_level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function calculateSimulation(array $input): array
    {
        $roomCount = (float)$input['roomCount'];
        $adr = (float)$input['adr'];
        $occupancyRate = $this->percentToDecimal((float)$input['occupancyRate']);
        $otaRate = $this->percentToDecimal((float)$input['otaCommissionRate']);
        $availableRoomNights = $roomCount * 30;
        $roomRevenue = $availableRoomNights * $occupancyRate * $adr;
        $monthlyRevenue = $roomRevenue + (float)$input['otherIncome'];
        $otaCommission = $roomRevenue * $otaRate;
        $monthlyCost =
            (float)$input['monthlyRent']
            + (float)$input['laborCost']
            + (float)$input['utilityCost']
            + $otaCommission
            + (float)$input['consumableCost']
            + (float)$input['maintenanceCost']
            + (float)$input['otherFixedCost'];
        $monthlyNetCashflow = $monthlyRevenue - $monthlyCost;
        $totalInvestment =
            (float)$input['decorationInvestment']
            + (float)$input['furnitureInvestment']
            + (float)$input['openingCost']
            + (float)$input['otherInvestment'];
        $revPAR = $adr * $occupancyRate;
        $paybackMonths = $monthlyNetCashflow > 0 ? $totalInvestment / $monthlyNetCashflow : null;
        $rentRatio = $monthlyRevenue > 0 ? (float)$input['monthlyRent'] / $monthlyRevenue : 0;
        $breakEvenOccupancy = $roomCount > 0 && $adr > 0 ? $monthlyCost / ($roomCount * 30 * $adr) : 0;

        return [
            'availableRoomNights' => round($availableRoomNights, 2),
            'roomRevenue' => round($roomRevenue, 2),
            'monthlyRevenue' => round($monthlyRevenue, 2),
            'otaCommission' => round($otaCommission, 2),
            'monthlyCost' => round($monthlyCost, 2),
            'monthlyNetCashflow' => round($monthlyNetCashflow, 2),
            'totalInvestment' => round($totalInvestment, 2),
            'revPAR' => round($revPAR, 2),
            'paybackMonths' => $paybackMonths === null ? null : round($paybackMonths, 2),
            'rentRatio' => round($rentRatio, 4),
            'breakEvenOccupancy' => round($breakEvenOccupancy, 4),
            'riskLevel' => $this->calculateRiskLevel($monthlyNetCashflow, $paybackMonths, $rentRatio, $breakEvenOccupancy),
        ];
    }

    private function buildScenarios(array $input): array
    {
        return [
            $this->calculateScenario($input, '保守情景'),
            $this->calculateScenario($input, '基准情景'),
            $this->calculateScenario($input, '乐观情景'),
        ];
    }

    private function calculateScenario(array $input, string $scenarioType): array
    {
        $scenarioInput = $input;
        if ($scenarioType === '保守情景') {
            $scenarioInput['adr'] = max(1, (float)$input['adr'] - 20);
            $scenarioInput['occupancyRate'] = max(0, (float)$input['occupancyRate'] - 10);
            $scenarioInput['otherIncome'] = max(0, (float)$input['otherIncome'] - 1700);
        } elseif ($scenarioType === '乐观情景') {
            $scenarioInput['adr'] = max(1, (float)$input['adr'] + 20);
            $scenarioInput['occupancyRate'] = min(100, (float)$input['occupancyRate'] + 8);
            $scenarioInput['otherIncome'] = max(0, (float)$input['otherIncome'] + 2000);
        }

        return array_merge(['scenarioType' => $scenarioType], $this->calculateSimulation($scenarioInput));
    }

    private function buildRiskHints(array $result): array
    {
        $rentRisk = $result['rentRatio'] >= 0.4
            ? ['riskLevel' => '高风险', 'content' => '租金占比超过40%，需压降租金或提高ADR。', 'className' => 'bg-red-50 border-red-100 text-red-800']
            : ($result['rentRatio'] >= 0.3
                ? ['riskLevel' => '中高风险', 'content' => '租金占比超过30%，需持续压降租金或提高ADR。', 'className' => 'bg-orange-50 border-orange-100 text-orange-800']
                : ['riskLevel' => '低风险', 'content' => '租金占比处于相对可控区间。', 'className' => 'bg-green-50 border-green-100 text-green-800']);

        $paybackRisk = $result['paybackMonths'] === null
            ? ['riskLevel' => '高风险', 'content' => '当前月净现金流为负，项目暂不可回本。', 'className' => 'bg-red-50 border-red-100 text-red-800']
            : ($result['paybackMonths'] <= 18
                ? ['riskLevel' => '低风险', 'content' => '回本周期可控。', 'className' => 'bg-green-50 border-green-100 text-green-800']
                : ($result['paybackMonths'] <= 30
                    ? ['riskLevel' => '中风险', 'content' => '回本周期偏长，需关注现金流稳定性。', 'className' => 'bg-yellow-50 border-yellow-100 text-yellow-800']
                    : ['riskLevel' => '高风险', 'content' => '回本周期过长，需重新评估投资规模。', 'className' => 'bg-red-50 border-red-100 text-red-800']));

        $breakEvenRisk = $result['breakEvenOccupancy'] >= 0.65
            ? ['riskLevel' => '高风险', 'content' => '保本入住率过高，需要长期高入住才能盈利。', 'className' => 'bg-red-50 border-red-100 text-red-800']
            : ($result['breakEvenOccupancy'] >= 0.55
                ? ['riskLevel' => '中风险', 'content' => '保本入住率偏高，需持续跟踪淡季入住。', 'className' => 'bg-yellow-50 border-yellow-100 text-yellow-800']
                : ['riskLevel' => '低风险', 'content' => '保本入住率处于相对安全区间。', 'className' => 'bg-green-50 border-green-100 text-green-800']);

        return [
            array_merge(['title' => '租金压力'], $rentRisk),
            array_merge(['title' => '回本周期'], $paybackRisk),
            array_merge(['title' => '保本边界'], $breakEvenRisk),
        ];
    }

    private function normalizeInput(array $raw): array
    {
        $input = [
            'roomCount' => $this->number($raw, 'roomCount', 'room_count'),
            'decorationInvestment' => $this->number($raw, 'decorationInvestment', 'decoration_investment'),
            'furnitureInvestment' => $this->number($raw, 'furnitureInvestment', 'equipment_investment'),
            'openingCost' => $this->number($raw, 'openingCost', 'pre_opening_cost'),
            'otherInvestment' => $this->number($raw, 'otherInvestment', 'other_investment'),
            'adr' => $this->number($raw, 'adr', 'adr'),
            'occupancyRate' => $this->number($raw, 'occupancyRate', 'occupancy_rate'),
            'otherIncome' => $this->number($raw, 'otherIncome', 'other_income'),
            'monthlyRent' => $this->number($raw, 'monthlyRent', 'monthly_rent'),
            'laborCost' => $this->number($raw, 'laborCost', 'labor_cost'),
            'utilityCost' => $this->number($raw, 'utilityCost', 'utility_cost'),
            'otaCommissionRate' => $this->number($raw, 'otaCommissionRate', 'ota_commission_rate'),
            'consumableCost' => $this->number($raw, 'consumableCost', 'consumable_cost'),
            'maintenanceCost' => $this->number($raw, 'maintenanceCost', 'maintenance_cost'),
            'otherFixedCost' => $this->number($raw, 'otherFixedCost', 'other_fixed_cost'),
        ];

        if ($input['roomCount'] <= 0) {
            throw new \InvalidArgumentException('房间数必须大于0');
        }
        if ($input['adr'] <= 0) {
            throw new \InvalidArgumentException('ADR必须大于0');
        }
        if ($input['occupancyRate'] < 0 || $input['occupancyRate'] > 100) {
            throw new \InvalidArgumentException('入住率必须在0到100之间');
        }
        if ($input['otaCommissionRate'] < 0 || $input['otaCommissionRate'] > 100) {
            throw new \InvalidArgumentException('OTA佣金率必须在0到100之间');
        }
        foreach ($input as $key => $value) {
            if (!in_array($key, ['occupancyRate', 'otaCommissionRate'], true) && $value < 0) {
                throw new \InvalidArgumentException('量化模拟输入不能为负数');
            }
        }

        return $input;
    }

    private function calculateRiskLevel(float $monthlyNetCashflow, ?float $paybackMonths, float $rentRatio, float $breakEvenOccupancy): string
    {
        if ($monthlyNetCashflow <= 0 || $paybackMonths === null || $breakEvenOccupancy >= 0.65) {
            return '高风险';
        }
        if ($rentRatio >= 0.4 || $paybackMonths > 30) {
            return '高风险';
        }
        if ($rentRatio >= 0.3) {
            return '中高风险';
        }
        if ($paybackMonths > 18 || $breakEvenOccupancy >= 0.55) {
            return '中风险';
        }

        return '低风险';
    }

    private function formatRecord(array $row, bool $withDetail): array
    {
        $result = $this->decodeJson($row['result_json'] ?? '');
        $record = [
            'id' => (int)$row['id'],
            'project_name' => (string)($row['project_name'] ?? ''),
            'monthly_net_cashflow' => (float)($row['monthly_net_cashflow'] ?? 0),
            'payback_months' => $row['payback_months'] === null ? null : (float)$row['payback_months'],
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'summary' => [
                'monthlyRevenue' => (float)($result['monthlyRevenue'] ?? 0),
                'monthlyNetCashflow' => (float)($result['monthlyNetCashflow'] ?? 0),
                'paybackMonths' => $result['paybackMonths'] ?? null,
                'riskLevel' => (string)($result['riskLevel'] ?? ($row['risk_level'] ?? '')),
            ],
        ];

        if ($withDetail) {
            $record['input'] = $this->decodeJson($row['input_json'] ?? '');
            $record['result'] = $result;
            $record['scenarios'] = $this->decodeJson($row['scenarios_json'] ?? '');
            $record['risk_hints'] = $this->decodeJson($row['risk_hints_json'] ?? '');
        }

        return $record;
    }

    private function number(array $raw, string $camel, string $snake): float
    {
        return round((float)($raw[$camel] ?? $raw[$snake] ?? 0), 4);
    }

    private function percentToDecimal(float $value): float
    {
        return $value / 100;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
