<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Source-inspired mechanisms. Manual observations are never live OTA or audited hotel facts. */
final class HotelLearningMechanismService
{
    public const CONTRACT = 'hotel_learning.v1';
    public const KINDS = ['profile' => 'jhira_profile', 'ota_scene' => 'jhira_ota_scene', 'market_sample' => 'jhira_market',
        'operating_review' => 'jhira_review', 'geo_observation' => 'jhira_geo', 'consumables_reconciliation' => 'consumables_actual',
        'investment_target' => 'jhira_target', 'contract_review' => 'jhira_contract'];

    public function calculate(string $mode, array $input): array
    {
        $this->rejectPrivateKeys($input);
        $result = match ($mode) {
            'profile' => $this->profile($input), 'ota_scene' => $this->ota($input),
            'market_sample' => $this->market($input), 'operating_review' => $this->review($input),
            'geo_observation' => $this->geo($input),
            'consumables_reconciliation' => (new ConsumablesOperationalReconciliationService())->calculate($input),
            'investment_target' => $this->target($input),
            'contract_review' => $this->contract($input),
            default => throw new InvalidArgumentException('请选择有效的业务方法'),
        };
        return array_replace(['inputs' => $input, 'source_quality' => 'manual_reference'], $result,
            ['contract_version' => self::CONTRACT, 'mode' => $mode, 'delivery_label' => 'source_inspired',
             'decision_safe' => false, 'external_write_authorized' => false]);
    }

    private function target(array $input): array
    {
        $scenario = (array)($input['scenario'] ?? []);
        $this->rejectActualReferences($scenario);
        $normalized = (new InvestmentScenarioCalculator())->normalize($scenario);
        $result = (new InvestmentScenarioTargetSolver())->solve($normalized, (array)($input['request'] ?? []));
        $result['inputs'] = ['scenario' => $normalized, 'request' => $result['request']];
        return $result;
    }

    private function rejectActualReferences(array $input): void
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && (($key === 'procurement_reference' && $value !== null)
                || (str_starts_with($key, 'cost_evidence_') && !empty($value)))) {
                throw new InvalidArgumentException('手填目标反求不能采用未经项目接口校验的实际成本或采购引用；请在投资项目原情景中明确采用');
            }
            if (is_string($value) && str_starts_with($value, 'actual-evidence-')) throw new InvalidArgumentException('手填情景不能伪造实际成本证据');
            if (is_array($value)) $this->rejectActualReferences($value);
        }
    }

    private function contract(array $input): array
    {
        $asOf = $this->date($input['as_of'] ?? '', true);
        $months = $this->number($input['payback_months'] ?? null, '假设回本月份', 0, 720);
        $constraints = (new InvestmentScenarioCashPlanner())->normalizeConstraints((array)($input['constraints'] ?? []));
        $forward = ['input' => ['as_of' => $asOf], 'status' => $months === null ? 'partial' : 'ready',
            'scenario_payback' => $months === null ? null : ['total_years' => $months / 12]];
        $calendar = (new InvestmentContractDateConstraintService())->evaluate($constraints, $forward);
        return ['status' => $months === null || $calendar['contract_missing_fields'] ? 'partial' : 'evaluated_assumption',
            'inputs' => ['as_of' => $asOf, 'payback_months' => $months, 'constraints' => $constraints],
            'calendar' => $calendar, 'source_quality' => 'scenario_assumption'];
    }

    private function profile(array $input): array
    {
        $fields = $this->rows($input['fields'] ?? [], 100);
        $known = []; $gaps = []; $seen = [];
        foreach ($fields as $row) {
            $key = $this->text($row['key'] ?? '', 100);
            if ($key === '' || isset($seen[$key])) throw new InvalidArgumentException('基础资料字段标识不能为空或重复');
            $seen[$key] = true;
            if (preg_match('/password|passwd|cookie|token|secret|credential|mtgsig|phone|contact/i', $key)) throw new InvalidArgumentException('基础资料不接收凭证或私人联系方式');
            $value = $this->text($row['value'] ?? '', 2000);
            $source = $this->text($row['source_ref'] ?? '', 1000);
            $date = $this->date($row['as_of'] ?? '', false);
            $quality = $this->enum($row['quality'] ?? 'unverified', ['unverified', 'manual_reference', 'operator_attested']);
            $status = $value === '' ? 'missing' : ($source === '' || $date === null ? 'unverified' : $quality);
            $known[] = ['key' => $key, 'value' => $value, 'unit' => $this->text($row['unit'] ?? '', 100),
                'source_ref' => $source, 'as_of' => $date, 'quality' => $quality, 'status' => $status];
            if (in_array($status, ['missing', 'unverified'], true)) $gaps[] = $key;
        }
        return ['status' => $fields === [] ? 'missing' : ($gaps ? 'partial' : 'recorded'), 'fields' => $known, 'inputs' => ['fields' => $known],
            'field_count' => count($known), 'missing_items' => $gaps, 'contains_verified_hotel_fact' => false];
    }

    private function ota(array $input): array
    {
        $rawScene = (array)($input['scene'] ?? []); $scene = []; $gaps = [];
        foreach (['keyword', 'location', 'device', 'login_state', 'sort', 'filters', 'observed_at', 'source_ref', 'platform_store_id'] as $key) {
            $scene[$key] = $this->text($rawScene[$key] ?? '', 1000);
            if ($scene[$key] === '') $gaps[] = 'scene.' . $key;
        }
        $scene['check_in'] = $this->date($rawScene['check_in'] ?? '', true);
        $scene['check_out'] = $this->date($rawScene['check_out'] ?? '', true);
        if ($scene['check_out'] <= $scene['check_in']) throw new InvalidArgumentException('离店日期必须晚于入住日期');
        $scene['page_capacity'] = $this->integer($rawScene['page_capacity'] ?? null, '每页条数', 1, 1000);
        if ($scene['page_capacity'] === null) $gaps[] = 'scene.page_capacity';
        $status = $this->enum($input['visibility'] ?? 'unknown', ['observed', 'not_seen_in_range', 'unknown']);
        $min = $this->integer($input['rank_min'] ?? null, '排名下界', 1, 100000);
        $max = $this->integer($input['rank_max'] ?? null, '排名上界', 1, 100000);
        if ($status === 'observed' && ($min === null || $max === null || $max < $min)) throw new InvalidArgumentException('已观测排名必须填写有效区间');
        if ($status !== 'observed') { $min = null; $max = null; }
        $range = $this->integer($input['observed_through_rank'] ?? null, '观察范围', 1, 100000);
        if ($status === 'not_seen_in_range' && $range === null) $gaps[] = 'observed_through_rank';
        $rate = $this->rate($input['conversion_rate'] ?? null, (string)($input['rate_unit'] ?? ''));
        $price = $this->number($input['price'] ?? null, '可成交价', 0, 1000000);
        if ($price === null) $gaps[] = 'price';
        $rawTerms = (array)($input['price_terms'] ?? []); $terms = [];
        foreach (['room_type', 'cancellation', 'breakfast', 'guest_count', 'membership', 'tax_basis', 'payment'] as $key) {
            $terms[$key] = $this->text($rawTerms[$key] ?? '', 300);
            if ($terms[$key] === '') $gaps[] = 'price_terms.' . $key;
        }
        $comparable = $gaps === [] && $status === 'observed';
        $context = array_diff_key($scene, array_flip(['observed_at', 'source_ref', 'platform_store_id']));
        $context['observed_business_date'] = substr($scene['observed_at'], 0, 10);
        $normalized = ['scene' => $scene, 'visibility' => $status, 'rank_min' => $min, 'rank_max' => $max,
            'observed_through_rank' => $range, 'conversion_rate' => $rate, 'rate_unit' => 'percentage_point', 'price' => $price, 'price_terms' => $terms];
        return ['status' => $gaps ? 'partial' : 'recorded', 'scene' => $scene, 'scene_fingerprint' => $this->hash($scene),
            'inputs' => $normalized,
            'visibility' => $status, 'rank_min' => $min, 'rank_max' => $max, 'observed_through_rank' => $range,
            'conversion_percentage_point' => $rate, 'price' => $price, 'price_terms' => $terms,
            'comparison_key' => $comparable ? $this->hash(['scene' => $context, 'terms' => $terms]) : null,
            'comparison_ready' => $comparable, 'missing_items' => $gaps,
            'rank_proves_exposure' => false, 'contains_verified_ota_fact' => false];
    }

    private function market(array $input): array
    {
        $rows = $this->rows($input['hotels'] ?? [], 100); $weights = (array)($input['weights'] ?? []);
        $metrics = ['traffic', 'conversion', 'revenue']; $sum = 0.0;
        foreach ($metrics as $metric) {
            $weights[$metric] = $this->number($weights[$metric] ?? null, '评分权重', 0, 1);
            if ($weights[$metric] === null) throw new InvalidArgumentException('评分权重需明确填写，不自动采用外站默认');
            $sum += $weights[$metric];
        }
        if (abs($sum - 1) > 0.000001) throw new InvalidArgumentException('三个评分权重合计必须为1');
        $version = $this->text($input['model_version'] ?? '', 100);
        $sample = $this->text($input['sample_ref'] ?? '', 1000);
        $sceneKey = $this->text($input['comparison_key'] ?? '', 100);
        if ($version === '' || $sample === '' || $sceneKey === '') throw new InvalidArgumentException('评分必须标明算法版本、样本来源和同口径场景');
        $seen = []; $ranges = []; $coverage = []; $items = []; $missing = [];
        foreach ($rows as $row) {
            $id = $this->text($row['platform_store_id'] ?? '', 100);
            if ($id === '' || isset($seen[$id])) throw new InvalidArgumentException('商圈样本需唯一平台酒店ID，不能仅按名称合并');
            $rowKey = $this->text($row['comparison_key'] ?? '', 100);
            if ($rowKey !== $sceneKey) throw new InvalidArgumentException('每家样本必须属于相同的完整场景口径');
            $seen[$id] = true; $item = ['platform_store_id' => $id, 'name' => $this->text($row['name'] ?? '', 200), 'comparison_key' => $rowKey];
            foreach (['traffic', 'revenue'] as $key) $item[$key] = $this->number($row[$key] ?? null, '样本指标', 0, 1.0e12);
            $item['conversion'] = $this->rate($row['conversion'] ?? null, (string)($row['rate_unit'] ?? ''));
            $item['rate_unit'] = 'percentage_point';
            $items[] = $item;
        }
        foreach ($metrics as $key) {
            $values = array_values(array_filter(array_column($items, $key), static fn($v): bool => $v !== null));
            $coverage[$key] = ['present' => count($values), 'total' => count($items)];
            $ranges[$key] = $values ? ['min' => min($values), 'max' => max($values)] : null;
        }
        $normalizedItems = $items;
        $incompleteSample = count($items) < 2;
        foreach ($metrics as $key) if ($weights[$key] > 0 && ($coverage[$key]['present'] !== count($items) || $ranges[$key] === null || $ranges[$key]['min'] === $ranges[$key]['max'])) $incompleteSample = true;
        foreach ($items as &$item) {
            $score = 0.0; $scores = []; $usable = !$incompleteSample;
            foreach ($metrics as $key) {
                $range = $ranges[$key];
                if ($weights[$key] === 0.0) { $scores[$key] = null; continue; }
                if ($item[$key] === null || $range === null || $range['max'] === $range['min']) {
                    $usable = false; $scores[$key] = null; $missing[] = $item['platform_store_id'] . '.' . $key;
                } else {
                    $scores[$key] = ($item[$key] - $range['min']) / ($range['max'] - $range['min']) * 100;
                    $score += $scores[$key] * $weights[$key];
                }
            }
            $item['scores'] = $scores; $item['reference_score'] = $usable ? round($score, 4) : null;
        }
        unset($item);
        $sampleFingerprint = $this->hash(['sample_ref' => $sample, 'items' => $normalizedItems, 'ids' => array_keys($seen), 'ranges' => $ranges,
            'weights' => $weights, 'model_version' => $version, 'comparison_key' => $sceneKey]);
        return ['status' => !$items ? 'missing' : ($missing || count($items) < 2 ? 'partial' : 'calculated_reference'),
            'items' => $items, 'weights' => $weights, 'ranges' => $ranges, 'coverage' => $coverage,
            'sample_fingerprint' => $sampleFingerprint, 'comparison_key' => $sceneKey, 'model_version' => $version,
            'inputs' => ['hotels' => $normalizedItems, 'weights' => $weights, 'model_version' => $version, 'sample_ref' => $sample, 'comparison_key' => $sceneKey],
            'missing_items' => array_values(array_unique($missing)), 'official_platform_score' => false, 'automatic_grading' => false];
    }

    private function review(array $input): array
    {
        $actual = (array)($input['actual'] ?? []); $plan = (array)($input['plan'] ?? []); $gaps = []; $rows = [];
        $start = $this->date($input['period_start'] ?? '', true); $end = $this->date($input['period_end'] ?? '', true);
        if ($end < $start) throw new InvalidArgumentException('复盘结束日期不能早于开始日期');
        $normalized = [];
        foreach (['actual', 'plan'] as $side) {
            $data = $side === 'actual' ? $actual : $plan;
            foreach (['source_ref', 'basis', 'period_start', 'period_end'] as $key) {
                $normalized[$side][$key] = $this->text($data[$key] ?? '', 1000);
            }
            foreach (['source_ref', 'basis'] as $key) if ($normalized[$side][$key] === '') $gaps[] = $side . '.' . $key;
            if ($normalized[$side]['period_start'] !== $start || $normalized[$side]['period_end'] !== $end) $gaps[] = $side . '.period_mismatch';
        }
        foreach (['available_room_nights', 'sold_room_nights', 'revenue', 'operating_cost', 'debt_service', 'project_net_cash', 'investor_received_cash'] as $metric) {
            $low = in_array($metric, ['project_net_cash', 'investor_received_cash'], true) ? -1.0e12 : 0;
            $a = $this->number($actual[$metric] ?? null, '实际值', $low, 1.0e12);
            $p = $this->number($plan[$metric] ?? null, '计划值', $low, 1.0e12);
            if ($a === null || $p === null) $gaps[] = $metric;
            $rows[] = ['metric' => $metric, 'actual' => $a, 'plan' => $p,
                'difference' => $a !== null && $p !== null ? round($a - $p, 6) : null];
            $normalized['actual'][$metric] = $a; $normalized['plan'][$metric] = $p;
        }
        $byKey = array_column($rows, null, 'metric');
        foreach (['actual', 'plan'] as $side) {
            $available = $byKey['available_room_nights'][$side]; $sold = $byKey['sold_room_nights'][$side];
            if ($available !== null && $sold !== null && $sold > $available) throw new InvalidArgumentException('已售间夜不能大于同期间可售间夜');
        }
        $actualRevenue = $byKey['revenue']['actual']; $actualCost = $byKey['operating_cost']['actual'];
        $scopeAligned = !in_array('actual.period_mismatch', $gaps, true) && !in_array('plan.period_mismatch', $gaps, true)
            && $normalized['actual']['basis'] === $normalized['plan']['basis'] && $normalized['actual']['basis'] !== '';
        if (!$scopeAligned) { $gaps[] = 'scope_mismatch'; foreach ($rows as &$row) $row['difference'] = null; unset($row); }
        return ['status' => $gaps || !$scopeAligned ? 'partial' : 'compared', 'rows' => $rows, 'scope_aligned' => $scopeAligned,
            'inputs' => ['period_start' => $start, 'period_end' => $end] + $normalized,
            'period_start' => $start, 'period_end' => $end,
            'actual_cost_ratio' => $scopeAligned && $actualRevenue > 0 && $actualCost !== null ? $actualCost / $actualRevenue : null,
            'missing_items' => array_values(array_unique($gaps)), 'annualized_as_actual' => false,
            'project_cash_equals_investor_recovery' => false];
    }

    private function geo(array $input): array
    {
        $gaps = []; $record = [];
        foreach (['question', 'model', 'model_version', 'region', 'network', 'observed_at', 'response_summary', 'source_ref'] as $key) {
            $record[$key] = $this->text($input[$key] ?? '', $key === 'response_summary' ? 12000 : 1000);
            if ($record[$key] === '') $gaps[] = $key;
        }
        $record['citations'] = $this->rows($input['citations'] ?? [], 40);
        foreach ($record['citations'] as &$citation) {
            $url = $this->text($citation['url'] ?? '', 2000);
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) throw new InvalidArgumentException('引用需有效的HTTP或HTTPS来源链接');
            $citation = ['url' => $url, 'fact_consistency' => $this->enum($citation['fact_consistency'] ?? 'unverified', ['consistent', 'inconsistent', 'unverified'])];
        }
        unset($citation);
        return ['status' => $gaps ? 'partial' : 'recorded', 'record' => $record, 'missing_items' => $gaps,
            'inputs' => $record,
            'experiment_key' => $this->hash(array_intersect_key($record, array_flip(['question', 'model', 'model_version', 'region', 'network']))),
            'order_attribution_verified' => false, 'marketing_effect_claimed' => false, 'model_called' => false];
    }

    private function rate(mixed $value, string $unit): ?float
    {
        $number = $this->number($value, '转化率', 0, 100);
        if ($number === null) return null;
        if (!in_array($unit, ['fraction', 'percentage_point'], true)) throw new InvalidArgumentException('转化率必须明确比例或百分点单位');
        if ($unit === 'fraction' && $number > 1) throw new InvalidArgumentException('比例单位转化率必须在0至1之间');
        return $unit === 'fraction' ? $number * 100 : $number;
    }
    private function rejectPrivateKeys(array $input): void
    {
        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match('/password|passwd|cookie|token|secret|credential|mtgsig/i', $key)) throw new InvalidArgumentException('业务资料不接收凭证');
            if (is_array($value)) $this->rejectPrivateKeys($value);
        }
    }
    private function number(mixed $value, string $label, float $min, float $max): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < $min || (float)$value > $max) throw new InvalidArgumentException($label . '数值无效');
        return (float)$value;
    }
    private function integer(mixed $value, string $label, int $min, int $max): ?float
    {
        $number = $this->number($value, $label, $min, $max);
        if ($number !== null && floor($number) !== $number) throw new InvalidArgumentException($label . '必须为整数');
        return $number;
    }
    private function rows(mixed $rows, int $max): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $max) throw new InvalidArgumentException('记录列表格式或数量无效');
        foreach ($rows as $row) if (!is_array($row)) throw new InvalidArgumentException('记录行格式无效');
        return $rows;
    }
    private function text(mixed $value, int $limit): string
    {
        if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('文本字段格式无效');
        $text = trim((string)$value);
        if (mb_strlen($text) > $limit) throw new InvalidArgumentException('文本超过长度限制');
        return $text;
    }
    private function date(mixed $value, bool $required): ?string
    {
        $text = $this->text($value, 20);
        if ($text === '' && !$required) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) throw new InvalidArgumentException('请输入有效业务日期');
        return $text;
    }
    private function enum(mixed $value, array $values): string
    {
        $text = $this->text($value, 100);
        if (!in_array($text, $values, true)) throw new InvalidArgumentException('状态值无效');
        return $text;
    }
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
