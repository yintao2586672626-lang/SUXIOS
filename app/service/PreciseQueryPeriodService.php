<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

/** Pure Shanghai calendar compilation and deterministic, coverage-aware aggregation. */
final class PreciseQueryPeriodService
{
    public function compile(string $query, array $scope, DateTimeImmutable $now): ?array
    {
        $now = $now->setTimezone(new DateTimeZone('Asia/Shanghai'))->setTime(0, 0);
        $query = str_replace((string)($scope['hotel_name'] ?? '\0'), '', $query);
        $comparison = preg_match('/环比|同比|对比|相比|比较|比(?:昨天|前天|上周|上月|上一期)/u', $query) === 1;
        // Cross-platform comparisons belong to the existing fail-closed route.
        if ($comparison && PreciseQueryLexicon::platform($query) === 'all_ota') return null;
        $parts = $comparison ? preg_split('/(?:与|和|跟)?(?:对比|相比|比较|比)(?=昨天|前天|上周|上月|上一期|20[0-9]{2}|[0-9一二三四五六七八九十]+月)|(?:与|和|跟)(?=昨天|前天|上周|上月|20[0-9]{2}|[0-9]+月)/u', $query, 2) : [$query];
        $primary = $this->window($parts[0], $scope, $now);
        $inheritedComparison = !$comparison && ($scope['context_verified'] ?? false)
            && !empty($scope['comparison']) && ($primary['date_source'] ?? '') === 'current_selected_scope';
        if ($inheritedComparison) $comparison = true;
        if ($primary === null && !$comparison) return null;
        if ($primary === null && $comparison) {
            $primary = $this->window('', $scope, $now);
            if ($primary === null) return $this->clarify('comparison_period_required', '请指定要比较的业务日期或期间，例如“昨天比前天订单额”。');
        }
        if (isset($primary['clarifying_question'])) return $primary;
        $baseline = $inheritedComparison ? $scope['comparison'] : null;
        if ($comparison) {
            if (count($parts) === 2) $baseline = $this->window($parts[1], [], $now);
            if ($baseline === null && preg_match('/环比|上一期/u', $query)) {
                $end = new DateTimeImmutable($primary['date_start'], new DateTimeZone('Asia/Shanghai'));
                $baseline = $this->range($end->modify('-' . $primary['expected_days'] . ' days')->format('Y-m-d'), $end->modify('-1 day')->format('Y-m-d'), 'previous_equal_period');
            }
            if ($baseline === null) return $this->clarify('comparison_period_required', '请明确比较的另一期间；同比请写出上一年的日期范围。');
            if (isset($baseline['clarifying_question'])) return $baseline;
        }
        if (!$comparison && ($primary['date_grain'] ?? '') === 'day'
            && in_array($primary['date_source'], ['explicit_day','current_selected_scope'], true)
            && !preg_match('/(?:到|至|~|～)\s*(?:今天|今日|昨天|昨日|前天|[0-9])/u', $query)) return null;
        return $primary + ['comparison' => $baseline, 'timezone' => 'Asia/Shanghai'];
    }

    private function window(string $query, array $scope, DateTimeImmutable $now): ?array
    {
        $year = (int)$now->format('Y');
        $cn = ['一'=>1,'二'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9,'十'=>10,'十一'=>11,'十二'=>12,'十四'=>14,'三十'=>30,'三十一'=>31];
        $query = preg_replace_callback('/[一二三四五六七八九十]+(?=天|月)/u', static fn(array $m): string => (string)($cn[$m[0]] ?? $m[0]), $query);
        if (preg_match('/(?:最近|近|过去|过去的)([0-9]+)天/u', $query, $m)) {
            $days = (int)$m[1];
            if ($days < 1 || $days > 31) return $this->clarify('period_limit', '单次支持1至31个业务日，请缩小日期范围。');
            return $this->range($now->modify('-' . $days . ' days')->format('Y-m-d'), $now->modify('-1 day')->format('Y-m-d'), 'completed_recent_days');
        }
        if (preg_match('/上个?月|本月|这个月/u', $query, $m)) {
            $start = $now->modify(str_starts_with($m[0], '上') ? 'first day of last month' : 'first day of this month');
            $end = str_starts_with($m[0], '上') ? $start->modify('last day of this month') : $now->modify('-1 day');
            return $this->range($start->format('Y-m-d'), $end->format('Y-m-d'), 'completed_calendar_month');
        }
        if (preg_match('/上周|本周|这周/u', $query, $m)) {
            $start = $now->modify('monday this week');
            if ($m[0] === '上周') $start = $start->modify('-7 days');
            $end = $m[0] === '上周' ? $start->modify('+6 days') : $now->modify('-1 day');
            return $this->range($start->format('Y-m-d'), $end->format('Y-m-d'), 'completed_calendar_week');
        }
        $datePattern = '(?:(20[0-9]{2})[-/.年])?([0-9]{1,2})[-/月]([0-9]{1,2})(?:日|号)?';
        preg_match_all('~(?<![0-9])' . $datePattern . '(?![0-9])~u', $query, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $dates = [];
        foreach ($matches as $m) {
            $date = sprintf('%04d-%02d-%02d', ($m[1][0] ?? '') !== '' ? (int)$m[1][0] : $year, (int)$m[2][0], (int)$m[3][0]);
            if (($m[1][0] ?? '') === '' && $date > $now->format('Y-m-d')) return $this->clarify('month_day_year_ambiguous', '日期没有年份且落在未来，请补充年份。');
            $dates[] = ['date' => $date, 'offset' => $m[0][1]];
        }
        preg_match_all('/今天|今日|昨天|昨日|前天/u', $query, $relative, PREG_OFFSET_CAPTURE);
        foreach ($relative[0] as $m) {
            $days = in_array($m[0], ['今天','今日'], true) ? 0 : ($m[0] === '前天' ? 2 : 1);
            $dates[] = ['date'=>$now->modify('-' . $days . ' days')->format('Y-m-d'), 'offset'=>$m[1]];
        }
        usort($dates, static fn(array $a,array $b): int => $a['offset'] <=> $b['offset']);
        $dates = array_values(array_unique(array_column($dates, 'date')));
        if (count($dates) === 1 && preg_match('/(?:到|至|~|～)\s*([0-9]{1,2})[日号]/u', $query, $m)) $dates[] = substr($dates[0],0,8) . str_pad($m[1],2,'0',STR_PAD_LEFT);
        if (count($dates) > 1) {
            if (!preg_match('/到|至|~|～/u', $query)) return $this->clarify('distinct_dates_need_operator', '这些日期需要相加还是对比？请使用“到”表示期间，或“对比”表示比较。');
            if (count($dates) > 2) return $this->clarify('multiple_periods', '单次请给出一个起止期间，或两个明确的比较期间。');
            return $this->range($dates[0], $dates[1], 'explicit_range');
        }
        if (count($dates) === 1) return $this->range($dates[0], $dates[0], 'explicit_day');
        if (preg_match('/(?:(20[0-9]{2})年)?([0-9]{1,2})月/u', $query, $m)) {
            $start = sprintf('%04d-%02d-01', ($m[1] ?? '') !== '' ? (int)$m[1] : $year, (int)$m[2]);
            if (!$this->validDate($start)) return $this->clarify('business_date_invalid', '业务月份无效，请重新说明。');
            if (($m[1] ?? '') === '' && $start > $now->format('Y-m-d')) return $this->clarify('month_day_year_ambiguous', '请补充要查询的年份。');
            return $this->range($start, (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d'), 'explicit_month');
        }
        if (preg_match('/季度|全年|今年|去年|周[一二三四五六日天]|星期/u', $query)) return $this->clarify('period_needs_explicit_dates', '请明确起止业务日期，单次支持最多31天。');
        $start = (string)($scope['date_start'] ?? $scope['business_date'] ?? '');
        $end = (string)($scope['date_end'] ?? $start);
        return $start !== '' ? $this->range($start, $end, 'current_selected_scope') : null;
    }

    private function range(string $start, string $end, string $source): array
    {
        if (!$this->validDate($start) || !$this->validDate($end) || $start > $end) return $this->clarify('business_date_invalid', '起止业务日期无效或尚无已完成业务日，请明确日期。');
        $days = (int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
        if ($days > 31) return $this->clarify('period_limit', '单次支持最多31个业务日，请缩小日期范围。');
        $dates = [];
        for ($day = new DateTimeImmutable($start); $day->format('Y-m-d') <= $end; $day = $day->modify('+1 day')) $dates[] = $day->format('Y-m-d');
        return ['date_start'=>$start, 'date_end'=>$end, 'business_date'=>$start === $end ? $start : null, 'date_grain'=>$start === $end ? 'day' : 'period', 'date_source'=>$source, 'expected_days'=>$days, 'dates'=>$dates];
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Shanghai'));
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function clarify(string $reason, string $question): array
    {
        return ['reason'=>$reason, 'clarifying_question'=>$question];
    }

    /** Daily entries are already scope-checked by the router, never LLM text. */
    public function aggregate(array $window, array $daily, string $metricKey): array
    {
        $missing = []; $valid = []; $identities = []; $refs = []; $gaps = []; $seenRefs = []; $reusedSource = false;
        foreach ($window['dates'] as $date) {
            $fact = $daily[$date] ?? [];
            $value = $fact['value'] ?? null;
            $identity = [(string)($fact['metric']['semantic_key'] ?? ''), (string)($fact['unit'] ?? ''), (string)($fact['platform_store_id'] ?? '')];
            $usable = is_numeric($value) && is_finite((float)$value) && $identity[0] !== '' && $identity[1] !== '' && $identity[2] !== ''
                && ($fact['readback_status'] ?? '') === 'readback_verified' && !empty($fact['source_records']) && !empty($fact['collected_at']);
            if (!$usable) {
                $missing[] = $date;
                $gaps[] = ['date'=>$date, 'code'=>'daily_fact_unavailable', 'message'=>(string)($fact['blocked_reason'] ?? '缺少同口径、同门店且已回读的日事实')];
                continue;
            }
            $valid[$date] = $fact; $identities[json_encode($identity)] = true;
            foreach ($fact['source_records'] as $ref) {
                if (isset($seenRefs[$ref]) && $seenRefs[$ref] !== $date) $reusedSource = true;
                $seenRefs[$ref] = $date;
            }
            $refs = array_merge($refs, $fact['source_records']);
        }
        $incomparable = count($identities) > 1 || $reusedSource;
        $sum = null;
        if ($valid !== [] && !$incomparable) $sum = round(array_sum(array_column($valid, 'value')), 2);
        $complete = count($missing) === 0 && !$incomparable;
        return [
            'date_start'=>$window['date_start'], 'date_end'=>$window['date_end'],
            'status'=>$incomparable ? 'blocked' : ($complete ? 'ready' : ($valid === [] ? 'blocked' : 'partial')),
            'value'=>$complete ? $sum : null, 'partial_value'=>!$complete ? $sum : null,
            'subtotal_label'=>$metricKey === 'amount' ? '非全期间金额' : '非全期间小计',
            'unit'=>$valid !== [] && !$incomparable ? reset($valid)['unit'] : null,
            'identity'=>$valid !== [] && !$incomparable ? json_decode(array_key_first($identities),true) : null,
            'coverage'=>['available_days'=>count($valid),'expected_days'=>count($window['dates']),'missing_dates'=>$missing],
            'daily_facts'=>array_values($daily), 'source_records'=>array_values(array_unique($refs)),
            'formula'=>$sum !== null ? implode(' + ', array_column($valid, 'value')) . ' = ' . $sum . ' ' . reset($valid)['unit']
                : '无可计算的同口径日值；不生成汇总数字',
            'blocked_reason'=>$incomparable ? '期间内门店、指标语义或单位不一致，或同一来源记录重复归入不同日期，不可相加。' : ($missing !== [] ? '期间数据不全，仅可展示已核验日的小计。' : null),
            'data_gaps'=>$gaps,
        ];
    }

    public function compare(array $current, array $baseline): array
    {
        $comparable = $current['status'] === 'ready' && $baseline['status'] === 'ready'
            && $current['identity'] === $baseline['identity']
            && $current['coverage']['expected_days'] === $baseline['coverage']['expected_days'];
        $delta = $comparable ? round($current['value'] - $baseline['value'], 2) : null;
        $zero = $comparable && (float)$baseline['value'] === 0.0;
        return ['current'=>$current, 'baseline'=>$baseline, 'comparable'=>$comparable,
            'difference'=>$delta, 'change_percent'=>$comparable && !$zero ? round($delta / $baseline['value'] * 100, 2) : null,
            'change_status'=>!$comparable ? 'not_comparable' : ($zero ? 'zero_denominator' : 'ready'),
            'formula'=>'差额 = 本期 − 对比期；变化率 = 差额 ÷ 对比期 × 100%',
            'blocked_reason'=>!$comparable ? '双方须同酒店、同平台、同门店、同指标口径、同单位、同天数且覆盖完整。' : ($zero ? '对比期为0，变化率不可计算；差额仍可核对。' : null)];
    }
}
