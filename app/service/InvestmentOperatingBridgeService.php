<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Read-only bridge to existing investor cash ledgers; operating profit is never cash recovery. */
final class InvestmentOperatingBridgeService
{
    public const CONTRACT_VERSION = 'investment_operating_bridge.v1';
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 100;
    private const AMOUNT_FIELDS = [
        'actual_invested' => 'invested_amount',
        'net_actual_recovered' => 'net_recovered_amount',
        'unrecovered' => 'unrecovered_amount',
        'excess_return' => 'excess_recovered_amount',
    ];

    /** Reader accepts the existing projects() filters and returns its list/pagination response. */
    public function __construct(
        private ?InvestmentPaybackService $ledger = null,
        private $projectReader = null,
        private $clock = null
    ) {
        if ($projectReader !== null && !is_callable($projectReader)) throw new InvalidArgumentException('investment_bridge_reader_invalid');
        if ($clock !== null && !is_callable($clock)) throw new InvalidArgumentException('investment_bridge_clock_invalid');
    }

    public function overview(int $tenantId, array $permittedHotelIds, int $hotelId, string $periodMonth): array
    {
        if ($tenantId <= 0 || $hotelId <= 0 || !in_array($hotelId, array_map('intval', $permittedHotelIds), true)) {
            throw new RuntimeException('investment_bridge_hotel_scope_denied', 403);
        }
        if (!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D', $periodMonth)) {
            throw new InvalidArgumentException('investment_bridge_period_month_invalid');
        }
        $today = $this->clock === null ? InvestmentPaybackCalculator::today() : (string)($this->clock)();
        InvestmentPaybackCalculator::date($today, '投资资金连接当前日期');
        $monthStart = $periodMonth . '-01';
        $monthEnd = (new DateTimeImmutable($monthStart, new DateTimeZone('Asia/Shanghai')))->format('Y-m-t');
        $asOf = min($monthEnd, $today);
        $reply = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'period_month' => $periodMonth,
            'period_start' => $monthStart, 'requested_period_end' => $monthEnd,
            'effective_as_of' => $asOf, 'current_date' => $today,
            'cutoff_status' => $monthStart > $today ? 'future_month_not_started' : ($monthEnd > $today ? 'current_month_to_date' : 'month_end'),
            'amount_basis' => 'cumulative_investor_cash_through_effective_as_of',
            'project_scope' => 'all_accessible_linked_projects_including_archived',
            'status' => 'blocked', 'read_status' => 'not_read', 'reason_code' => null,
            'projects' => null, 'totals' => null, 'recorded_totals' => null,
            'coverage' => ['read_complete' => false, 'pages_read' => 0, 'scanned_project_count' => 0, 'linked_project_count' => 0, 'summable_project_count' => 0, 'history_complete_project_count' => 0],
            'quality' => ['source_quality_status' => 'manual_unverified', 'history_complete' => false, 'issues' => [], 'actual_cash_independently_verified' => false],
            'formulas' => [
                'actual_invested' => 'sum(linked project investor_cash invested_amount)',
                'net_actual_recovered' => 'sum(linked project investor_cash net_recovered_amount)',
                'unrecovered' => 'sum(each linked project unrecovered_amount; excess from one project never offsets another)',
                'excess_return' => 'sum(linked project excess_recovered_amount)',
            ],
            'boundaries' => [
                'gop_is_actual_recovery' => false, 'scenario_is_actual_cash' => false,
                'amounts_are_cumulative_not_monthly_cashflow' => true,
                'cross_investor_aggregation_allowed' => false,
                'cross_project_cash_deduplication_independently_verified' => false,
                'automatic_approval' => false, 'external_write_count' => 0, 'actual_cash_written' => false,
            ],
        ];
        if ($monthStart > $today) {
            $reply['status'] = 'not_started';
            $reply['read_status'] = 'not_applicable';
            $reply['reason_code'] = 'future_accounting_period_not_started';
            return $reply;
        }
        try {
            [$rows, $complete, $pages, $readIssues] = $this->readProjects($tenantId, $hotelId, $asOf);
            $projects = []; $seen = [];
            foreach ($rows as $row) {
                if (!is_array($row) || (int)($row['tenant_id'] ?? 0) !== $tenantId) {
                    throw new RuntimeException('investment_bridge_reader_scope_mismatch');
                }
                $rawId = $row['id'] ?? null;
                if (!is_int($rawId) && !is_string($rawId)) {
                    throw new RuntimeException('investment_bridge_project_identity_invalid');
                }
                $idText = (string)$rawId;
                $maxIdText = (string)PHP_INT_MAX;
                if (!preg_match('/^[1-9]\d*$/D', $idText) || strlen($idText) > strlen($maxIdText)
                    || (strlen($idText) === strlen($maxIdText) && strcmp($idText, $maxIdText) > 0)) {
                    throw new RuntimeException('investment_bridge_project_identity_invalid');
                }
                $id = (int)$rawId;
                if (isset($seen[$id])) throw new RuntimeException('investment_bridge_project_identity_invalid');
                $seen[$id] = true;
                // Defend against a reader that ignores the hotel filter without exposing its rows.
                if ((int)($row['hotel_id'] ?? 0) !== $hotelId) continue;
                $projects[] = $this->project($row, $asOf);
            }
            $reply['read_status'] = 'available';
            $reply['projects'] = $projects;
            $reply['coverage'] = [
                'read_complete' => $complete, 'pages_read' => $pages,
                'scanned_project_count' => count($rows), 'linked_project_count' => count($projects),
                'summable_project_count' => count(array_filter($projects, static fn(array $row): bool => $row['amounts_complete'])),
                'history_complete_project_count' => count(array_filter($projects, static fn(array $row): bool => $row['history_complete'])),
            ];
            return $this->aggregate($reply, $readIssues);
        } catch (Throwable $error) {
            $reply['status'] = 'read_failed';
            $reply['read_status'] = 'failed';
            $reply['reason_code'] = 'investment_cash_read_failed';
            $reply['quality']['issues'] = ['investment_cash_read_failed'];
            // Never return a successful empty list or expose exception/connection material.
            $reply['projects'] = null; $reply['totals'] = null; $reply['recorded_totals'] = null;
            return $reply;
        }
    }

    private function readProjects(int $tenantId, int $hotelId, string $asOf): array
    {
        if ($this->projectReader === null && $this->ledger === null) throw new RuntimeException('investment_bridge_reader_missing');
        $rows = []; $expectedTotal = null;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $filters = ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'as_of' => $asOf, 'include_archived' => true, 'page_size' => self::PAGE_SIZE, 'page' => $page];
            $result = $this->projectReader === null ? $this->ledger->projects($filters) : ($this->projectReader)($filters);
            if (!is_array($result) || !is_array($result['list'] ?? null) || !array_is_list($result['list'])
                || !is_array($result['pagination'] ?? null) || !is_int($result['pagination']['total'] ?? null)
                || $result['pagination']['total'] < 0 || count($result['list']) > self::PAGE_SIZE) {
                throw new RuntimeException('investment_bridge_reader_response_invalid');
            }
            $total = $result['pagination']['total'];
            $expectedTotal ??= $total;
            if ($total !== $expectedTotal) return [$rows, false, $page, ['project_list_changed_during_read']];
            if ($result['list'] === [] && count($rows) < $total) return [$rows, false, $page, ['project_pagination_incomplete']];
            array_push($rows, ...$result['list']);
            if (count($rows) > $total) throw new RuntimeException('investment_bridge_reader_count_invalid');
            if (count($rows) === $total) return [$rows, true, $page, []];
        }
        return [$rows, false, self::MAX_PAGES, ['project_pagination_limit_reached']];
    }

    private function project(array $row, string $asOf): array
    {
        $summary = $row['summary'] ?? null;
        if (!is_array($summary)) throw new RuntimeException('investment_bridge_summary_missing');
        $issues = is_array($summary['data_quality']['issues'] ?? null) ? $summary['data_quality']['issues'] : [];
        $scopeCompatible = ($row['basis'] ?? null) === 'investor_cash' && ($summary['basis'] ?? null) === 'investor_cash'
            && ($row['currency'] ?? null) === 'CNY' && ($summary['currency'] ?? null) === 'CNY'
            && ($summary['as_of'] ?? null) === $asOf;
        if (!$scopeCompatible) $issues[] = 'project_cash_scope_incompatible';
        $amounts = []; $complete = $scopeCompatible;
        foreach (self::AMOUNT_FIELDS as $key => $field) {
            $value = $summary[$field] ?? null;
            $amounts[$key] = null;
            if ($value === null || $value === '') {
                $complete = false; $issues[] = $field . '_missing';
            } else {
                $amounts[$key] = InvestmentPaybackCalculator::yuan(InvestmentPaybackCalculator::fen($value, $key === 'net_actual_recovered', $field));
            }
        }
        $historyComplete = ($summary['data_quality']['history_complete'] ?? false) === true
            && is_string($summary['data_quality']['history_complete_through'] ?? null)
            && $summary['data_quality']['history_complete_through'] >= $asOf;
        if (!$historyComplete) $issues[] = 'history_not_checked_through_cutoff';
        $investor = trim((string)($row['investor_name'] ?? ''));
        if ($investor === '') $issues[] = 'investor_identity_missing';
        return [
            'project_id' => (int)$row['id'], 'project_name' => (string)($row['project_name'] ?? ''),
            'investor_name' => $investor, 'hotel_id' => (int)$row['hotel_id'], 'tenant_id' => (int)$row['tenant_id'],
            'basis' => $row['basis'] ?? null, 'currency' => $row['currency'] ?? null,
            'archived_at' => $row['archived_at'] ?? null, 'status' => $row['status'] ?? null,
            'summary' => $summary, 'amounts' => $amounts, 'amounts_complete' => $complete,
            'scope_compatible' => $scopeCompatible, 'history_complete' => $historyComplete,
            'source_quality_status' => 'manual_unverified', 'issues' => array_values(array_unique($issues)),
        ];
    }

    private function aggregate(array $reply, array $readIssues): array
    {
        $projects = $reply['projects'];
        $issues = $readIssues;
        if ($projects === []) {
            $reply['status'] = $reply['coverage']['read_complete'] ? 'missing' : 'partial';
            $reply['reason_code'] = $reply['coverage']['read_complete'] ? 'linked_investment_project_missing' : 'project_read_coverage_incomplete';
            $reply['quality']['issues'] = array_merge($issues, [$reply['coverage']['read_complete'] ? 'linked_investment_project_missing' : 'linked_investment_project_not_established']);
            return $reply;
        }
        $investors = array_values(array_unique(array_column($projects, 'investor_name')));
        $sameInvestor = count($investors) === 1 && $investors[0] !== '';
        $scopeCompatible = array_reduce($projects, static fn(bool $ok, array $project): bool => $ok && $project['scope_compatible'], true);
        if (!$sameInvestor) $issues[] = 'cross_investor_or_unknown_identity_not_summable';
        if (!$scopeCompatible) $issues[] = 'project_cash_scope_incompatible';
        $historyComplete = $reply['coverage']['read_complete'] && array_reduce($projects, static fn(bool $ok, array $project): bool => $ok && $project['history_complete'], true);
        $allAmounts = count($projects) === $reply['coverage']['summable_project_count'];
        foreach ($projects as $project) $issues = array_merge($issues, $project['issues']);
        $reply['quality']['history_complete'] = $historyComplete;
        $reply['quality']['investor_names'] = $investors;
        $reply['quality']['issues'] = array_values(array_unique($issues));
        if ($sameInvestor && $scopeCompatible && $reply['coverage']['summable_project_count'] > 0) {
            $totals = array_fill_keys(array_keys(self::AMOUNT_FIELDS), 0);
            foreach ($projects as $project) {
                if (!$project['amounts_complete']) continue;
                foreach ($totals as $field => $sum) {
                    $fen = InvestmentPaybackCalculator::fen($project['amounts'][$field], $field === 'net_actual_recovered');
                    if (($fen > 0 && $sum > PHP_INT_MAX - $fen) || ($fen < 0 && $sum < PHP_INT_MIN - $fen)) {
                        throw new RuntimeException('investment_bridge_total_overflow');
                    }
                    $totals[$field] = $sum + $fen;
                }
            }
            $reply['recorded_totals'] = array_map(static fn(int $value): string => InvestmentPaybackCalculator::yuan($value), $totals);
            if ($allAmounts && $historyComplete) $reply['totals'] = $reply['recorded_totals'];
        }
        $reply['status'] = !$sameInvestor || !$scopeCompatible ? 'blocked' : ($reply['totals'] !== null ? 'ready' : 'partial');
        $reply['reason_code'] = $reply['status'] === 'ready' ? 'manual_ledger_records_only' : ($reply['status'] === 'blocked' ? 'project_cash_comparison_scope_mismatch' : 'project_cash_history_or_coverage_incomplete');
        return $reply;
    }
}
