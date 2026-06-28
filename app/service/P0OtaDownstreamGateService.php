<?php
declare(strict_types=1);

namespace app\service;

class P0OtaDownstreamGateService
{
    private const BLOCKED_STAGE_KEYS = [
        'revenue_analysis',
        'ai_decision_advice',
        'operation_closure',
        'investment_judgment',
    ];

    private const ALLOWED_CLAIMS_WHEN_BLOCKED = [
        'structure_ready_or_reference_only',
        'historical_rows_reference_only',
        'no_whole_hotel_or_downstream_closure_claim',
    ];

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    public function blockedForDataset(string $businessDate, ?int $hotelId, array $dataset, array $platforms = []): array
    {
        $dailyRows = count($this->list($dataset['fact_ota_daily'] ?? []));
        $trafficRows = count($this->list($dataset['fact_ota_traffic'] ?? []));
        $missingInputs = ['p0_field_loop_verifier_ready'];
        if ($dailyRows <= 0) {
            $missingInputs[] = 'target_date_ota_rows';
        }
        if ($trafficRows <= 0) {
            $missingInputs[] = 'target_date_traffic_rows';
        }

        return $this->blocked($businessDate, $hotelId, $missingInputs, 'not_verified', '', $platforms);
    }

    /**
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    public function normalize(array $gate, string $businessDate = '', ?int $hotelId = null, array $platforms = []): array
    {
        $status = trim((string)($gate['status'] ?? ''));
        if ($status === 'ready') {
            return [
                'status' => 'ready',
                'current_upstream_status' => trim((string)($gate['current_upstream_status'] ?? 'ready')),
                'required_upstream_status' => trim((string)($gate['required_upstream_status'] ?? 'ready')),
                'required_gate_command' => trim((string)($gate['required_gate_command'] ?? $this->verifierCommand($businessDate, $hotelId, $platforms))),
                'scope_policy' => trim((string)($gate['scope_policy'] ?? 'ota_channel_gate_before_downstream_claims')),
                'blocking_missing_inputs' => [],
                'blocked_stage_keys' => [],
                'stages' => $this->stageRows('ready'),
                'allowed_claims' => ['p0_ota_field_loop_ready_for_downstream_claims'],
            ];
        }

        $missingInputs = $this->stringList($gate['blocking_missing_inputs'] ?? []);
        if ($missingInputs === []) {
            $missingInputs = ['p0_field_loop_verifier_ready'];
        }

        return $this->blocked(
            $businessDate,
            $hotelId,
            $missingInputs,
            trim((string)($gate['current_upstream_status'] ?? 'incomplete')),
            trim((string)($gate['required_gate_command'] ?? '')),
            $platforms
        );
    }

    /**
     * @param array<int, string> $missingInputs
     * @return array<string, mixed>
     */
    private function blocked(
        string $businessDate,
        ?int $hotelId,
        array $missingInputs,
        string $currentStatus,
        string $command = '',
        array $platforms = []
    ): array
    {
        return [
            'status' => 'blocked_by_p0_ota_gate',
            'current_upstream_status' => $currentStatus !== '' ? $currentStatus : 'incomplete',
            'required_upstream_status' => 'ready',
            'required_gate_command' => $command !== '' ? $command : $this->verifierCommand($businessDate, $hotelId, $platforms),
            'scope_policy' => 'ota_channel_gate_before_downstream_claims',
            'blocking_missing_inputs' => array_values(array_unique($missingInputs)),
            'blocked_stage_keys' => self::BLOCKED_STAGE_KEYS,
            'stages' => $this->stageRows('blocked_by_p0_ota_gate'),
            'allowed_claims' => self::ALLOWED_CLAIMS_WHEN_BLOCKED,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function stageRows(string $status): array
    {
        $labels = [
            'revenue_analysis' => '收益分析',
            'ai_decision_advice' => 'AI 决策建议',
            'operation_closure' => '运营闭环',
            'investment_judgment' => '投资判断',
        ];
        $rows = [];
        foreach ($labels as $key => $label) {
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'boundary' => $status === 'ready'
                    ? 'P0 OTA field-loop verifier is ready for this downstream claim boundary.'
                    : 'Do not claim this downstream stage as truly closed until the P0 OTA field-loop verifier is ready.',
            ];
        }
        return $rows;
    }

    private function verifierCommand(string $businessDate, ?int $hotelId, array $platforms = []): string
    {
        $date = trim($businessDate);
        $platforms = $this->platformList($platforms);
        $command = 'npm.cmd run verify:p0-ota-field-loop';
        if ($date !== '') {
            $command .= ' -- --date=' . $date;
            if ($platforms !== []) {
                $command .= ' --platform=' . implode(',', $platforms);
            }
            if ($hotelId !== null) {
                $command .= ' --system-hotel-id=' . $hotelId;
            }
        } elseif ($platforms !== []) {
            $command .= ' -- --platform=' . implode(',', $platforms);
            if ($hotelId !== null) {
                $command .= ' --system-hotel-id=' . $hotelId;
            }
        }
        return $command;
    }

    /**
     * @param array<int, mixed> $platforms
     * @return array<int, string>
     */
    private function platformList(array $platforms): array
    {
        $items = [];
        foreach ($platforms as $platform) {
            $text = strtolower(trim((string)$platform));
            if (in_array($text, ['ctrip', 'meituan'], true)) {
                $items[] = $text;
            }
        }
        return array_values(array_unique($items));
    }

    /**
     * @return array<int, mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return array_values(array_unique($items));
    }
}
