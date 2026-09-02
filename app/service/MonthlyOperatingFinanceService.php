<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class MonthlyOperatingFinanceService
{
    public const TABLE = 'hotel_monthly_operating_finance_snapshots';
    public const CONTRACT_VERSION = 'hotel_monthly_operating_finance.v1';
    public const PORTFOLIO_CONTRACT_VERSION = 'hotel_operating_portfolio.v1';
    public const METRIC_DEFINITION_VERSION = 'hotel_monthly_operating_finance_metrics.v1';

    private const FACT_SCOPES = ['ota_channel', 'accommodation_room_fee', 'whole_hotel'];
    private const INPUT_KEYS = [
        'ota_net_revenue',
        'room_operating_revenue',
        'non_room_operating_revenue',
        'departmental_expense',
        'undistributed_operating_expense',
        'rent_expense',
        'other_fixed_cash_cost',
        'budget_total_operating_revenue',
        'budget_gop',
    ];

    /** @var callable(callable():array<string,mixed>):array<string,mixed> */
    private $transactionRunner;

    public function __construct(?callable $transactionRunner = null)
    {
        $this->transactionRunner = $transactionRunner
            ?? static fn(callable $callback): array => Db::transaction($callback);
    }

    /** @return array<string,mixed> */
    public function calculate(string $factScope, array $rawInputs): array
    {
        $factScope = $this->factScope($factScope);
        $inputs = [];
        foreach (self::INPUT_KEYS as $key) {
            $inputs[$key] = $this->nullableNumber(
                $rawInputs[$key] ?? null,
                $key,
                $key !== 'budget_gop'
            );
        }
        $missing = [];
        $totalRevenue = null;
        $gop = null;
        $gopMargin = null;
        $ownerCashProxy = null;
        $roomContribution = null;

        if ($factScope === 'ota_channel') {
            if ($inputs['ota_net_revenue'] === null) {
                $missing[] = 'ota_net_revenue_missing';
            }
            $recognizedRevenue = $inputs['ota_net_revenue'];
            $missing[] = 'whole_hotel_revenue_scope_unavailable';
            $missing[] = 'gop_not_calculable_from_ota_channel_scope';
        } else {
            if ($inputs['room_operating_revenue'] === null) {
                $missing[] = 'room_operating_revenue_missing';
            }
            $recognizedRevenue = $inputs['room_operating_revenue'];
            if ($factScope === 'accommodation_room_fee'
                && $inputs['room_operating_revenue'] !== null
                && $inputs['departmental_expense'] !== null
                && $inputs['undistributed_operating_expense'] !== null
            ) {
                $roomContribution = round(
                    $inputs['room_operating_revenue']
                    - $inputs['departmental_expense']
                    - $inputs['undistributed_operating_expense'],
                    2
                );
            } else {
                if ($inputs['departmental_expense'] === null) {
                    $missing[] = 'departmental_expense_missing';
                }
                if ($inputs['undistributed_operating_expense'] === null) {
                    $missing[] = 'undistributed_operating_expense_missing';
                }
            }
            if ($factScope === 'whole_hotel') {
                if ($inputs['non_room_operating_revenue'] === null) {
                    $missing[] = 'non_room_operating_revenue_missing';
                } elseif ($inputs['room_operating_revenue'] !== null) {
                    $totalRevenue = round(
                        $inputs['room_operating_revenue'] + $inputs['non_room_operating_revenue'],
                        2
                    );
                    $recognizedRevenue = $totalRevenue;
                }
                if ($totalRevenue !== null
                    && $inputs['departmental_expense'] !== null
                    && $inputs['undistributed_operating_expense'] !== null
                ) {
                    $gop = round(
                        $totalRevenue
                        - $inputs['departmental_expense']
                        - $inputs['undistributed_operating_expense'],
                        2
                    );
                    $gopMargin = $totalRevenue > 0 ? round($gop / $totalRevenue * 100, 2) : null;
                } else {
                    $missing[] = 'gop_input_coverage_incomplete';
                }
                if ($gop !== null
                    && $inputs['rent_expense'] !== null
                    && $inputs['other_fixed_cash_cost'] !== null
                ) {
                    $ownerCashProxy = round(
                        $gop - $inputs['rent_expense'] - $inputs['other_fixed_cash_cost'],
                        2
                    );
                } else {
                    if ($inputs['rent_expense'] === null) {
                        $missing[] = 'rent_expense_missing';
                    }
                    if ($inputs['other_fixed_cash_cost'] === null) {
                        $missing[] = 'other_fixed_cash_cost_missing';
                    }
                }
            } else {
                $missing[] = 'whole_hotel_non_room_revenue_scope_unavailable';
                $missing[] = 'gop_not_calculable_from_room_only_scope';
            }
        }

        $totalRevenueVariance = null;
        $gopVariance = null;
        if ($factScope === 'whole_hotel') {
            $totalRevenueVariance = $totalRevenue !== null && $inputs['budget_total_operating_revenue'] !== null
                ? round($totalRevenue - $inputs['budget_total_operating_revenue'], 2)
                : null;
            $gopVariance = $gop !== null && $inputs['budget_gop'] !== null
                ? round($gop - $inputs['budget_gop'], 2)
                : null;
            if ($inputs['budget_total_operating_revenue'] === null) {
                $missing[] = 'budget_total_operating_revenue_missing';
            }
            if ($inputs['budget_gop'] === null) {
                $missing[] = 'budget_gop_missing';
            }
        }

        $missing = array_values(array_unique($missing));
        $ready = $factScope === 'whole_hotel' && $totalRevenue !== null && $gop !== null;
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'fact_scope' => $factScope,
            'status' => $ready ? 'ready' : ($recognizedRevenue !== null ? 'partial' : 'blocked'),
            'inputs' => $inputs,
            'recognized_revenue' => $recognizedRevenue,
            'total_operating_revenue' => $totalRevenue,
            'room_operating_contribution' => $roomContribution,
            'gop' => $gop,
            'gop_margin_percent' => $gopMargin,
            'owner_cash_proxy_before_tax_capex_and_financing' => $ownerCashProxy,
            'budget_total_operating_revenue_variance' => $totalRevenueVariance,
            'budget_gop_variance' => $gopVariance,
            'missing_items' => $missing,
            'formulas' => [
                'total_operating_revenue' => 'room_operating_revenue + non_room_operating_revenue',
                'gop' => 'total_operating_revenue - departmental_expense - undistributed_operating_expense',
                'gop_margin_percent' => 'gop / total_operating_revenue * 100',
                'owner_cash_proxy_before_tax_capex_and_financing' => 'gop - rent_expense - other_fixed_cash_cost',
                'budget_variance' => 'actual - budget',
            ],
            'boundaries' => [
                'ota_settlement_is_not_whole_hotel_revenue' => true,
                'owner_cash_proxy_is_not_accounting_cash_flow' => true,
                'tax_capex_financing_and_depreciation_excluded' => true,
                'tax_capex_financing_depreciation_working_capital_and_debt_service_excluded' => true,
                'automatic_approval' => false,
                'external_write_count' => 0,
            ],
        ];
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function saveSnapshot(
        int $tenantId,
        array $permittedHotelIds,
        int $hotelId,
        string $periodMonth,
        string $factScope,
        array $inputs,
        array $sourceRefs,
        array $sourceMeta,
        string $clientIdempotencyKey,
        int $actorId
    ): array {
        $tenantId = $this->resolveHotelScope($tenantId, $permittedHotelIds, $hotelId);
        if ($actorId <= 0) {
            throw new InvalidArgumentException('monthly_operating_finance_actor_required');
        }
        $periodMonth = $this->month($periodMonth);
        $factScope = $this->factScope($factScope);
        $sourceRefs = $this->sourceRefs($sourceRefs);
        $sourceMeta = $this->sourceMeta($sourceMeta);
        $calculation = $this->calculate($factScope, $inputs);
        $normalizedInputs = $calculation['inputs'];
        $results = $calculation;
        unset($results['inputs'], $results['missing_items']);
        $missing = $calculation['missing_items'];
        $content = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_hotel_id' => $hotelId,
            'period_month' => $periodMonth,
            'fact_scope' => $factScope,
            'source' => $sourceMeta,
            'source_refs' => $sourceRefs,
            'inputs' => $normalizedInputs,
            'results' => $results,
            'missing_items' => $missing,
        ];
        $contentDigest = $this->contentDigest($content);
        $idempotencyKey = $this->idempotencyKey($clientIdempotencyKey);
        $now = $this->now();

        $runTransaction = $this->transactionRunner;
        try {
            return $runTransaction(function () use (
                $tenantId,
                $hotelId,
                $periodMonth,
                $factScope,
                $sourceMeta,
                $sourceRefs,
                $normalizedInputs,
                $results,
                $missing,
                $contentDigest,
                $idempotencyKey,
                $actorId,
                $now
            ): array {
                $existing = Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('period_month', $periodMonth)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lock(true)
                    ->find();
                if ($existing) {
                    return $this->verifiedIdempotentReplay($existing, $contentDigest);
                }
                $version = (int)Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('period_month', $periodMonth)
                    ->lock(true)
                    ->max('version_no') + 1;
                $id = (int)Db::name(self::TABLE)->insertGetId([
                    'contract_version' => self::CONTRACT_VERSION,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'source_hotel_id' => $hotelId,
                    'period_month' => $periodMonth,
                    'version_no' => max(1, $version),
                    'fact_scope' => $factScope,
                    'source_method' => $sourceMeta['source_method'],
                    'source_quality_status' => $sourceMeta['source_quality_status'],
                    'currency' => $sourceMeta['currency'],
                    'tax_basis' => $sourceMeta['tax_basis'],
                    'metric_definition_version' => $sourceMeta['metric_definition_version'],
                    'source_refs_json' => $this->json($sourceRefs),
                    'inputs_json' => $this->json($normalizedInputs),
                    'results_json' => $this->json($results),
                    'missing_items_json' => $this->json($missing),
                    'idempotency_key' => $idempotencyKey,
                    'content_digest' => $contentDigest,
                    'created_by' => $actorId,
                    'created_at' => $now,
                ]);
                $saved = $this->readSnapshot($tenantId, $hotelId, $id);
                if (!hash_equals($saved['content_digest'], $contentDigest)) {
                    throw new RuntimeException('monthly_operating_finance_readback_mismatch');
                }
                return $saved + ['idempotent' => false];
            });
        } catch (Throwable $error) {
            if (!$this->isDuplicateKeyConflict($error)) {
                throw $error;
            }
            $winner = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('period_month', $periodMonth)
                ->where('idempotency_key', $idempotencyKey)
                ->find();
            if (!is_array($winner)) {
                throw $error;
            }
            return $this->verifiedIdempotentReplay($winner, $contentDigest);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function verifiedIdempotentReplay(array $row, string $contentDigest): array
    {
        $saved = $this->hydrate($row);
        if (!hash_equals($saved['content_digest'], $contentDigest)) {
            throw new RuntimeException('monthly_operating_finance_idempotency_conflict', 409);
        }
        return $saved + ['idempotent' => true];
    }

    /** @return array<string,mixed> */
    public function readSnapshot(int $tenantId, int $hotelId, int $id): array
    {
        $row = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!$row) {
            throw new RuntimeException('monthly_operating_finance_snapshot_not_found', 404);
        }
        return $this->hydrate($row);
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function latestForHotel(
        int $tenantId,
        array $permittedHotelIds,
        int $hotelId,
        string $periodMonth
    ): array {
        $tenantId = $this->resolveHotelScope($tenantId, $permittedHotelIds, $hotelId);
        $periodMonth = $this->month($periodMonth);
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('period_month', $periodMonth)
            ->order('version_no', 'desc')
            ->order('id', 'desc')
            ->find();
        return $row ? $this->hydrate($row) : [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'period_month' => $periodMonth,
            'status' => 'missing',
            'missing_items' => ['monthly_operating_finance_snapshot_missing'],
            'external_write_count' => 0,
        ];
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function portfolioOverview(int $tenantId, array $permittedHotelIds, string $periodMonth): array
    {
        $periodMonth = $this->month($periodMonth);
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $permittedHotelIds), static fn(int $id): bool => $id > 0)));
        sort($hotelIds);
        if ($tenantId <= 0 || $hotelIds === []) {
            throw new InvalidArgumentException('portfolio_hotel_scope_required');
        }
        $hotels = Db::name('hotels')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $hotelIds)
            ->field('id,name,tenant_id')
            ->select()
            ->toArray();
        $hotelNames = [];
        foreach ($hotels as $hotel) {
            $hotelNames[(int)$hotel['id']] = (string)($hotel['name'] ?? ('酒店 ' . (int)$hotel['id']));
        }
        if (count($hotelNames) !== count($hotelIds)) {
            throw new RuntimeException('portfolio_contains_out_of_tenant_hotel', 403);
        }
        $rows = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->whereIn('hotel_id', $hotelIds)
            ->where('period_month', $periodMonth)
            ->order('version_no', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $latest = [];
        foreach ($rows as $row) {
            $hotelId = (int)$row['hotel_id'];
            $latest[$hotelId] ??= $this->hydrate($row);
        }
        $items = [];
        foreach ($hotelIds as $hotelId) {
            if (!isset($latest[$hotelId])) {
                $items[] = [
                    'hotel_id' => $hotelId,
                    'hotel_name' => $hotelNames[$hotelId],
                    'status' => 'missing',
                    'fact_scope' => null,
                    'gop' => null,
                    'gop_margin_percent' => null,
                    'source_quality_status' => null,
                    'currency' => null,
                    'tax_basis' => null,
                    'metric_definition_version' => null,
                    'missing_items' => ['monthly_operating_finance_snapshot_missing'],
                    'rank' => null,
                ];
                continue;
            }
            $snapshot = $latest[$hotelId];
            $items[] = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelNames[$hotelId],
                'status' => (string)($snapshot['results']['status'] ?? 'blocked'),
                'fact_scope' => $snapshot['fact_scope'],
                'gop' => $snapshot['results']['gop'] ?? null,
                'gop_margin_percent' => $snapshot['results']['gop_margin_percent'] ?? null,
                'source_quality_status' => $snapshot['source']['source_quality_status'] ?? 'unverified',
                'currency' => $snapshot['source']['currency'] ?? null,
                'tax_basis' => $snapshot['source']['tax_basis'] ?? 'unknown',
                'metric_definition_version' => $snapshot['source']['metric_definition_version'] ?? null,
                'missing_items' => $snapshot['missing_items'],
                'snapshot_ref' => self::TABLE . '#' . $snapshot['id'],
                'rank' => null,
            ];
        }
        $allComparable = count($items) >= 2 && array_reduce(
            $items,
            static fn(bool $carry, array $item): bool => $carry
                && $item['status'] === 'ready'
                && $item['fact_scope'] === 'whole_hotel'
                && is_numeric($item['gop_margin_percent'])
                && $item['source_quality_status'] === 'operator_attested'
                && $item['currency'] === 'CNY'
                && in_array($item['tax_basis'], ['tax_inclusive', 'tax_exclusive'], true)
                && $item['metric_definition_version'] === self::METRIC_DEFINITION_VERSION,
            true
        );
        if ($allComparable) {
            $comparisonSignatures = array_values(array_unique(array_map(
                static fn(array $item): string => implode('|', [
                    (string)$item['currency'],
                    (string)$item['tax_basis'],
                    (string)$item['metric_definition_version'],
                ]),
                $items
            )));
            $allComparable = count($comparisonSignatures) === 1;
        }
        if ($allComparable) {
            $order = $items;
            usort($order, static function (array $a, array $b): int {
                $marginOrder = round((float)$b['gop_margin_percent'], 2)
                    <=> round((float)$a['gop_margin_percent'], 2);
                return $marginOrder !== 0
                    ? $marginOrder
                    : (int)$a['hotel_id'] <=> (int)$b['hotel_id'];
            });
            $rankByHotel = [];
            $previousMargin = null;
            $currentRank = 0;
            foreach ($order as $index => $item) {
                $margin = round((float)$item['gop_margin_percent'], 2);
                if ($previousMargin === null || $margin !== $previousMargin) {
                    $currentRank = $index + 1;
                }
                $rankByHotel[(int)$item['hotel_id']] = $currentRank;
                $previousMargin = $margin;
            }
            foreach ($items as &$item) {
                $item['rank'] = $rankByHotel[(int)$item['hotel_id']];
            }
            unset($item);
        }
        return [
            'contract_version' => self::PORTFOLIO_CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'period_month' => $periodMonth,
            'status' => $items === [] ? 'empty' : ($allComparable ? 'ready' : 'partial'),
            'ranking_status' => $allComparable ? 'same_scope_manual_snapshot_comparable' : 'blocked_incomplete_or_mixed_scope',
            'comparison_basis' => $allComparable ? [
                'fact_scope' => 'whole_hotel',
                'source_quality_status' => 'operator_attested',
                'currency' => 'CNY',
                'tax_basis' => $items[0]['tax_basis'],
                'metric_definition_version' => self::METRIC_DEFINITION_VERSION,
                'period_month' => $periodMonth,
            ] : null,
            'items' => $items,
            'hotel_count' => count($items),
            'employee_evaluation_authorized' => false,
            'cross_tenant_data_included' => false,
            'external_write_count' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $sourceRefs = $this->decodeList((string)$row['source_refs_json'], 'source_refs_json');
        $inputs = $this->decodeObject((string)$row['inputs_json'], 'inputs_json');
        $results = $this->decodeObject((string)$row['results_json'], 'results_json');
        $missing = $this->decodeList((string)$row['missing_items_json'], 'missing_items_json');
        $content = [
            'contract_version' => (string)$row['contract_version'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'source_hotel_id' => (int)$row['source_hotel_id'],
            'period_month' => (string)$row['period_month'],
            'fact_scope' => (string)$row['fact_scope'],
            'source' => [
                'source_method' => (string)$row['source_method'],
                'source_quality_status' => (string)$row['source_quality_status'],
                'currency' => (string)$row['currency'],
                'tax_basis' => (string)$row['tax_basis'],
                'metric_definition_version' => (string)$row['metric_definition_version'],
            ],
            'source_refs' => $sourceRefs,
            'inputs' => $inputs,
            'results' => $results,
            'missing_items' => $missing,
        ];
        $digest = $this->contentDigest($content);
        if (!hash_equals((string)$row['content_digest'], $digest)) {
            throw new RuntimeException('monthly_operating_finance_content_digest_mismatch');
        }
        return [
            'id' => (int)$row['id'],
            ...$content,
            'version_no' => (int)$row['version_no'],
            'idempotency_key' => (string)$row['idempotency_key'],
            'content_digest' => $digest,
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'readback_verified' => true,
            'external_write_count' => 0,
        ];
    }

    /** @param list<int> $permittedHotelIds */
    private function resolveHotelScope(int $tenantId, array $permittedHotelIds, int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('hotel_scope_required');
        }
        $permitted = array_values(array_unique(array_filter(array_map('intval', $permittedHotelIds), static fn(int $id): bool => $id > 0)));
        if (!in_array($hotelId, $permitted, true)) {
            throw new RuntimeException('hotel_outside_permitted_scope', 403);
        }
        $row = Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id')->find();
        if (!$row) {
            throw new RuntimeException('hotel_not_found', 404);
        }
        $actualTenant = (int)($row['tenant_id'] ?? 0);
        if ($actualTenant <= 0 || ($tenantId > 0 && $tenantId !== $actualTenant)) {
            throw new RuntimeException('hotel_tenant_scope_mismatch', 403);
        }
        return $actualTenant;
    }

    private function factScope(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::FACT_SCOPES, true)) {
            throw new InvalidArgumentException('monthly_operating_finance_fact_scope_invalid');
        }
        return $value;
    }

    private function month(string $value): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m', trim($value), new DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new InvalidArgumentException('period_month_invalid');
        }
        return $parsed->format('Y-m');
    }

    private function nullableNumber(mixed $value, string $field, bool $nonNegative = true): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || ($nonNegative && $number < 0)) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        return round($number, 2);
    }

    /** @return list<string> */
    private function sourceRefs(array $refs): array
    {
        $result = [];
        foreach ($refs as $ref) {
            $text = trim((string)$ref);
            if ($text === '' || strlen($text) > 180 || !preg_match('/^[A-Za-z0-9_:#.\/-]+$/', $text)) {
                throw new InvalidArgumentException('monthly_operating_finance_source_ref_invalid');
            }
            $result[] = $text;
        }
        $result = array_values(array_unique($result));
        if ($result === [] || count($result) > 50) {
            throw new InvalidArgumentException('monthly_operating_finance_source_refs_invalid');
        }
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return array{source_method:string,source_quality_status:string,currency:string,tax_basis:string,metric_definition_version:string} */
    private function sourceMeta(array $meta): array
    {
        $sourceMethod = strtolower(trim((string)($meta['source_method'] ?? '')));
        if ($sourceMethod !== 'manual_entry') {
            throw new InvalidArgumentException('monthly_operating_finance_source_method_invalid');
        }
        $sourceQuality = strtolower(trim((string)($meta['source_quality_status'] ?? '')));
        if (!in_array($sourceQuality, ['operator_attested', 'unverified'], true)) {
            throw new InvalidArgumentException('monthly_operating_finance_source_quality_invalid');
        }
        $currency = strtoupper(trim((string)($meta['currency'] ?? '')));
        if ($currency !== 'CNY') {
            throw new InvalidArgumentException('monthly_operating_finance_currency_invalid');
        }
        $taxBasis = strtolower(trim((string)($meta['tax_basis'] ?? '')));
        if (!in_array($taxBasis, ['tax_inclusive', 'tax_exclusive', 'unknown'], true)) {
            throw new InvalidArgumentException('monthly_operating_finance_tax_basis_invalid');
        }
        return [
            'source_method' => $sourceMethod,
            'source_quality_status' => $sourceQuality,
            'currency' => $currency,
            'tax_basis' => $taxBasis,
            'metric_definition_version' => self::METRIC_DEFINITION_VERSION,
        ];
    }

    private function idempotencyKey(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 180) {
            throw new InvalidArgumentException('idempotency_key_invalid');
        }
        return hash('sha256', 'monthly-operating-finance-idempotency-v1|' . $value);
    }

    /** @return list<mixed> */
    private function decodeList(string $value, string $field): array
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException($field . '_invalid');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $value, string $field): array
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($field . '_invalid');
        }
        return $decoded;
    }

    private function json(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    /** @param array<string,mixed> $content */
    private function contentDigest(array $content): string
    {
        unset($content['hotel_id']);
        return $this->digest($content);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function isDuplicateKeyConflict(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate entry')
                || str_contains($message, 'integrity constraint violation: 1062')
                || str_contains($message, 'unique constraint failed')
            ) {
                return true;
            }
        }
        return false;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s.u');
    }
}
