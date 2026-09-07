<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Pure, bounded replay of declared evidence. Uploaded evidence is never attested as OTA fact. */
final class TemporalForecastReplayService
{
    public const VERSION = 'temporal_replay.v1';
    public const DEFINITION = 'net_stay_room_nights_excluding_cancelled';
    public const SOURCE_REFERENCE_PATTERN = '/^(?:synthetic|manual)[-_][A-Za-z0-9][A-Za-z0-9_-]{0,155}$/D';

    public function __construct(private ?TemporalInsightService $forecast = null)
    {
        $this->forecast ??= new TemporalInsightService();
    }

    public function run(array $input, array $scope): array
    {
        $this->scope($scope);
        if (($input['schema_version'] ?? '') !== self::VERSION
            || ($input['metric_definition'] ?? '') !== self::DEFINITION
            || ($input['unit'] ?? '') !== 'room_nights'
            || ($input['date_basis'] ?? '') !== 'stay_date'
            || !in_array($input['source_kind'] ?? '', ['synthetic', 'manual_unverified'], true)) {
            throw new InvalidArgumentException('需要 temporal_replay.v1、入住日净间夜（排除取消）、room_nights 单位及 synthetic/manual_unverified 来源。');
        }
        $asOf = $this->timestamp($input['as_of_at'] ?? '');
        $evaluation = $this->timestamp($input['evaluation_at'] ?? '');
        if ($asOf > $evaluation) {
            throw new InvalidArgumentException('评价时点不能早于预测时点。');
        }
        $asOfDate = $asOf->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $start = $this->date($input['backtest_start'] ?? '');
        $end = $this->date($input['backtest_end'] ?? '');
        if ($start > $end || $end >= $asOfDate || $this->days($start, $end) > 366) {
            throw new InvalidArgumentException('回测起止须有序、早于预测日，跨度不超过366天。');
        }
        $rows = $input['observations'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 3000) {
            throw new InvalidArgumentException('observations 必须为最多3000条历史版本的列表。');
        }
        $versions = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('历史版本格式错误。');
            foreach ($scope as $key => $value) {
                if (!isset($row[$key]) || $row[$key] !== $value) {
                    throw new InvalidArgumentException('历史版本范围不匹配：' . $key);
                }
            }
            $date = $this->date($row['business_date'] ?? '');
            $available = $this->timestamp($row['available_at'] ?? '');
            $availableDate = $available->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
            if ($availableDate <= $date) throw new InvalidArgumentException('最终入住间夜不能在入住日结束前可见。');
            if (!in_array($row['quality_status'] ?? '', ['ready', 'missing', 'failed'], true)
                || !is_string($row['source_ref'] ?? null) || !preg_match(self::SOURCE_REFERENCE_PATTERN, $row['source_ref'])) {
                throw new InvalidArgumentException('历史版本需有质量状态；source_ref 仅接受 synthetic- 或 manual- 开头的字母数字/下划线/连字符引用编号。');
            }
            $value = $row['value'] ?? null;
            if ($row['quality_status'] === 'ready' && (!is_numeric($value) || !is_finite((float)$value)
                || (float)$value < 0 || (float)$value > 1000000 || floor((float)$value) !== (float)$value)) {
                throw new InvalidArgumentException('有效间夜必须是非负有限整数；缺失使用 missing 和 null。');
            }
            if ($row['quality_status'] !== 'ready' && $value !== null) throw new InvalidArgumentException('缺失或失败版本的 value 必须为 null。');
            $key = $date . '|' . $available->format('U.u');
            if (isset($versions[$key])) throw new InvalidArgumentException('同业务日同可见时刻存在重复/冲突版本。');
            $row['_available'] = $available->format('U.u');
            $versions[$key] = $row;
        }
        $versions = array_values($versions);
        usort($versions, static fn($a, $b) => (float)$a['_available'] <=> (float)$b['_available']);
        $visibleTraining = $this->window($this->select($versions, $asOf, $asOfDate, true), $asOfDate);
        $quality = ['ready' => 0, 'missing' => 0, 'failed' => 0, 'unobserved' => 56 - count($visibleTraining)];
        foreach ($visibleTraining as $row) $quality[$row['quality_status']]++;
        $training = array_filter($visibleTraining, static fn($row) => $row['quality_status'] === 'ready');
        $hasUnavailableTraining = $quality['missing'] + $quality['failed'] > 0;
        $allActuals = $this->select($versions, $evaluation, $evaluation->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d'));
        $forecasts = [];
        $comparisons = [];
        foreach ([7, 14, 30] as $horizon) {
            $plan = $this->plan($training, $asOfDate, $horizon);
            if ($hasUnavailableTraining && $plan['status'] === 'ready') $plan['status'] = 'partial';
            $forecasts[$horizon] = $plan;
            $folds = [];
            $pairs = [];
            $missingActuals = 0;
            for ($origin = $start; $this->shift($origin, $horizon) <= $end; $origin = $this->shift($origin, $horizon)) {
                // Replay at the same Shanghai wall time as the declared forecast origin.
                $cutoff = new DateTimeImmutable($origin . 'T' . $asOf->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('H:i:s.uP'));
                $train = $this->window($this->select($versions, $cutoff, $origin), $origin);
                $foldPlan = $this->plan($train, $origin, $horizon);
                $foldPoints = [];
                foreach ($foldPlan['points'] as $point) {
                    $actual = $allActuals[$point['target_date']]['value'] ?? null;
                    $point['actual_value'] = $actual;
                    $point['actual_source_ref'] = $allActuals[$point['target_date']]['source_ref'] ?? null;
                    $point['actual_available_at'] = $allActuals[$point['target_date']]['available_at'] ?? null;
                    if ($actual === null) $missingActuals++;
                    if ($actual !== null && $point['baseline_weekly'] !== null && $point['baseline_mean7'] !== null) $pairs[] = $point;
                    $foldPoints[] = $point;
                }
                $folds[] = ['origin_at' => $this->timeIdentity($cutoff), 'training_sample_count' => count($train),
                    'training_refs' => array_column($train, 'source_ref'), 'training_max_available_at' => $this->latestAt($train),
                    'status' => $foldPlan['status'], 'points' => $foldPoints];
            }
            $stats = $this->statistics($pairs);
            $completeFolds = count(array_filter($folds, static fn($fold) => count($fold['points']) === $horizon
                && count(array_filter($fold['points'], static fn($point) => $point['actual_value'] !== null
                    && $point['baseline_weekly'] !== null && $point['baseline_mean7'] !== null)) === $horizon));
            $stats['fold_count'] = count($folds);
            $stats['complete_fold_count'] = $completeFolds;
            $stats['missing_actual_count'] = $missingActuals;
            $stats['expected_point_count'] = count($folds) * $horizon;
            $stats['unpaired_point_count'] = $stats['expected_point_count'] - count($pairs);
            $stats['assessment'] = (new RevenueForecastReadinessService())->assessReplay($stats, $input['source_kind']);
            $comparisons[$horizon] = $stats + ['folds' => $folds];
        }
        return ['schema_version' => self::VERSION, 'scope' => $scope, 'source_kind' => $input['source_kind'],
            'data_status' => $training === [] ? 'blocked' : ($hasUnavailableTraining || count($training) < 28 ? 'partial' : 'unverified'),
            'as_of_at' => $this->timeIdentity($asOf), 'evaluation_at' => $this->timeIdentity($evaluation),
            'metric_definition' => self::DEFINITION, 'unit' => 'room_nights', 'date_basis' => 'stay_date',
            'input_evidence' => ['version_count' => count($versions), 'training_sample_count' => count($training),
                'training_quality' => $quality,
                'training_refs' => array_column($training, 'source_ref'), 'training_max_available_at' => $this->latestAt($training),
                'excluded_after_as_of_count' => count(array_filter($versions, static fn($r) => (float)$r['_available'] > (float)$asOf->format('U.u')))],
            'forecasts' => $forecasts, 'comparisons' => $comparisons,
            'method' => 'coarse_trend_v1; rolling origins, nonoverlapping targets within each horizon; last 56 calendar days only',
            'interval_semantics' => '启发式范围，未校准；覆盖率仅为同折样本外实际落入范围的比例，不承诺名义概率。',
            'confidence_semantics' => 'confidence_score 是未校准的规则就绪指数，不是预测准确率或命中概率。',
            'applicability' => ['仅当前渠道、门店及房型范围的净入住间夜；不是未受库存限制的潜在需求。',
                '至少7个有效历史日才出预测；近期不足7日或历史不足28日显示部分。',
                '每个周期至少3个完整时间折且所有目标可配对才比较优劣；不代表统计显著性。',
                '导入的可见时刻与来源尚未经采集链核验；synthetic 结果仅为工具验收。',
                '历史拟合、样本外预测与调价因果效果不同；无自动调价或审批。'],
            'automatic_price_write' => false, 'causality_claimed' => false];
    }

    private function select(array $rows, DateTimeImmutable $cutoff, string $beforeDate, bool $includeUnavailable = false): array
    {
        $selected = [];
        foreach ($rows as $row) {
            if ((float)$row['_available'] <= (float)$cutoff->format('U.u') && $row['business_date'] < $beforeDate) {
                $selected[$row['business_date']] = $row;
            }
        }
        // Latest missing/failed revisions invalidate an older value instead of falling back.
        if (!$includeUnavailable) $selected = array_filter($selected, static fn($r) => $r['quality_status'] === 'ready');
        ksort($selected);
        return $selected;
    }

    private function plan(array $selected, string $origin, int $horizon): array
    {
        $selected = array_filter($selected, fn($r) => $r['business_date'] >= $this->shift($origin, -56));
        $series = array_map(static fn($r) => ['date' => $r['business_date'], 'ota_room_nights' => (float)$r['value']], array_values($selected));
        $plan = $this->forecast->buildForecastPlan($series, $origin, $horizon);
        $recent = [];
        for ($i = 1; $i <= 7; $i++) {
            $value = $selected[$this->shift($origin, -$i)]['value'] ?? null;
            if ($value !== null) $recent[] = (float)$value;
        }
        $mean = count($recent) === 7 ? array_sum($recent) / 7 : null;
        $points = [];
        foreach ($plan['points'] as $point) {
            if ($point['metric_key'] !== 'ota_room_nights') continue;
            $weeklyDate = $point['target_date'];
            while ($weeklyDate >= $origin) $weeklyDate = $this->shift($weeklyDate, -7);
            $point['baseline_weekly'] = isset($selected[$weeklyDate]) ? (float)$selected[$weeklyDate]['value'] : null;
            $point['baseline_mean7'] = $mean;
            $points[] = $point;
        }
        return ['status' => $points === [] ? 'insufficient_data' : (count($series) < 28 || $mean === null ? 'partial' : 'ready'),
            'sample_count' => count($series), 'recent_sample_count' => count($recent),
            'total_predicted_room_nights' => $points === [] ? null : array_sum(array_column($points, 'predicted_value')),
            'points' => $points];
    }

    private function window(array $selected, string $origin): array
    {
        return array_filter($selected, fn($r) => $r['business_date'] >= $this->shift($origin, -56));
    }

    private function statistics(array $pairs): array
    {
        $n = count($pairs);
        $metrics = [];
        foreach (['model' => 'predicted_value', 'weekly' => 'baseline_weekly', 'mean7' => 'baseline_mean7'] as $key => $field) {
            $absolute = $square = $bias = $actualSum = 0.0;
            foreach ($pairs as $p) {
                $error = $p[$field] - $p['actual_value'];
                $absolute += abs($error); $square += $error ** 2; $bias += $error; $actualSum += $p['actual_value'];
            }
            $metrics[$key] = ['mae' => $n ? $absolute / $n : null, 'rmse' => $n ? sqrt($square / $n) : null,
                'bias' => $n ? $bias / $n : null, 'wape_percent' => $actualSum > 0 ? 100 * $absolute / $actualSum : null];
        }
        $hits = count(array_filter($pairs, static fn($p) => $p['actual_value'] >= $p['lower_bound'] && $p['actual_value'] <= $p['upper_bound']));
        return ['sample_count' => $n, 'metrics' => $metrics, 'interval_coverage_percent' => $n ? 100 * $hits / $n : null,
            'interval_hit_count' => $hits, 'nominal_coverage_percent' => null];
    }

    private function latestAt(array $rows): ?string
    {
        if ($rows === []) return null;
        usort($rows, static fn($a, $b) => (float)$b['_available'] <=> (float)$a['_available']);
        return $rows[0]['available_at'];
    }

    public function scope(array $scope): void
    {
        $keys = array_keys($scope);
        sort($keys);
        if ($keys !== ['hotel_id', 'platform', 'platform_store_id', 'room_scope', 'tenant_id']
            || !is_int($scope['tenant_id']) || $scope['tenant_id'] <= 0 || !is_int($scope['hotel_id']) || $scope['hotel_id'] <= 0
            || !in_array($scope['platform'], ['ctrip', 'meituan'], true)) throw new InvalidArgumentException('酒店/租户/平台范围无效。');
        foreach (['platform_store_id', 'room_scope'] as $key) {
            if (!is_string($scope[$key]) || trim($scope[$key]) === '' || strlen($scope[$key]) > 100) throw new InvalidArgumentException('需明确平台门店和房型范围。');
        }
    }

    private function date(mixed $value): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('日期须为 YYYY-MM-DD。');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('日期无效。');
        return $value;
    }

    private function timeIdentity(DateTimeImmutable $value): string
    {
        return $value->format($value->format('u') === '000000' ? DATE_ATOM : 'Y-m-d\TH:i:s.uP');
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value)) throw new InvalidArgumentException('时点须为有效时钟及偏移范围的 RFC3339 时间。');
        try { $date = new DateTimeImmutable($value); } catch (\Throwable) { throw new InvalidArgumentException('时点无效。'); }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('时点无效。');
        return $date;
    }

    private function shift(string $date, int $days): string { return (new DateTimeImmutable($date))->modify("{$days} days")->format('Y-m-d'); }
    private function days(string $a, string $b): int { return (int)(new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->format('%a'); }
}
