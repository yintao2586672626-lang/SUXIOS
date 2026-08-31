<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\facade\Db;

/**
 * Read-only Meituan keyword/advertising projection from strict online_daily_data facts.
 *
 * This service deliberately does not create an execution intent, change a campaign,
 * or call Meituan. It exposes at most one human-review draft from one exact fact scope.
 */
final class MeituanMarketingFactProjectionService
{
    public const CONTRACT_VERSION = 'meituan_marketing_fact_projection.v1';
    public const REVIEW_CONTRACT_VERSION = 'meituan_marketing_effect_observation.v1';

    /** @var Closure(int,int,string):array<int,array<string,mixed>> */
    private Closure $rowReader;

    /** @param null|Closure(int,int,string):array<int,array<string,mixed>> $rowReader */
    public function __construct(?Closure $rowReader = null)
    {
        $this->rowReader = $rowReader ?? static function (
            int $tenantId,
            int $hotelId,
            string $businessDate
        ): array {
            return Db::name('online_daily_data')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('data_date', $businessDate)
                ->whereIn('data_type', ['search_keyword', 'advertising'])
                ->where('history_status', 'success')
                ->where('validation_status', 'verified')
                ->where('readback_verified', 1)
                ->order('id', 'asc')
                ->select()
                ->toArray();
        };
    }

    /** @return array<string,mixed> */
    public function project(int $tenantId, int $hotelId, string $businessDate): array
    {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('meituan_marketing_scope_invalid');
        }
        $businessDate = $this->date($businessDate);
        $rows = ($this->rowReader)($tenantId, $hotelId, $businessDate);

        $canonical = [];
        $rejectedReasonCounts = [];
        $supersededRows = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $this->incrementReason($rejectedReasonCounts, 'row_not_array');
                continue;
            }
            $reason = $this->trustRejectionReason($row, $tenantId, $hotelId, $businessDate);
            if ($reason !== '') {
                $this->incrementReason($rejectedReasonCounts, $reason);
                continue;
            }
            $identity = $this->scopeIdentity($row);
            if ($identity === null) {
                $this->incrementReason($rejectedReasonCounts, 'marketing_scope_identity_missing');
                continue;
            }
            $dataType = strtolower(trim((string)$row['data_type']));
            $platformHotelId = trim((string)$row['hotel_id']);
            $key = implode('|', [
                $dataType,
                $platformHotelId,
                $identity['object_type'],
                $identity['object_key'],
            ]);
            if (!isset($canonical[$key])) {
                $canonical[$key] = ['row' => $row, 'identity' => $identity];
                continue;
            }
            $supersededRows++;
            if ($this->isLaterSnapshot($row, $canonical[$key]['row'])) {
                $canonical[$key] = ['row' => $row, 'identity' => $identity];
            }
        }

        $projections = [];
        foreach ($canonical as $item) {
            $projections[] = $this->projectRow(
                $item['row'],
                $item['identity'],
                $tenantId,
                $hotelId,
                $businessDate
            );
        }
        usort($projections, [$this, 'compareProjections']);

        $advertising = array_values(array_filter(
            $projections,
            static fn(array $item): bool => ($item['fact_type'] ?? '') === 'advertising'
        ));
        $gapCodes = [];
        foreach ($advertising as $item) {
            if (($item['metrics']['roas_status'] ?? '') !== 'calculated') {
                $gapCodes[] = (string)($item['metrics']['roas_status'] ?? 'roas_not_calculable');
            }
        }
        if ($projections === []) {
            $gapCodes[] = 'strict_meituan_marketing_fact_missing';
        } elseif ($advertising === []) {
            $gapCodes[] = 'strict_meituan_advertising_fact_missing';
        }
        $gapCodes = array_values(array_unique(array_filter($gapCodes)));

        $calculatedRoasCount = count(array_filter(
            $advertising,
            static fn(array $item): bool => ($item['metrics']['roas_status'] ?? '') === 'calculated'
        ));
        $status = $projections === []
            ? 'blocked'
            : ($advertising !== []
                && $calculatedRoasCount === count($advertising)
                && $rejectedReasonCounts === []
                    ? 'ready'
                    : 'partial');

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $status,
            'scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'platform' => 'meituan',
                'business_date' => $businessDate,
                'timezone' => 'Asia/Shanghai',
                'metric_scope' => 'ota_channel',
            ],
            'projections' => $projections,
            'pending_review_draft' => $this->pendingReviewDraft($projections),
            'data_quality' => [
                'source_table' => 'online_daily_data',
                'source_row_count' => count($rows),
                'strict_projection_count' => count($projections),
                'superseded_snapshot_count' => $supersededRows,
                'rejected_reason_counts' => $rejectedReasonCounts,
                'gap_codes' => $gapCodes,
            ],
            'human_confirmation_required' => true,
            'decision_eligible' => false,
            'writeback_allowed' => false,
            'auto_budget_change_allowed' => false,
            'auto_bid_change_allowed' => false,
            'external_write_count' => 0,
        ];
    }

    /** @param array<string,mixed> $row */
    private function trustRejectionReason(
        array $row,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): string {
        if ((int)($row['tenant_id'] ?? 0) !== $tenantId) {
            return 'tenant_scope_mismatch';
        }
        if ((int)($row['system_hotel_id'] ?? 0) !== $hotelId) {
            return 'hotel_scope_mismatch';
        }
        if (trim((string)($row['data_date'] ?? '')) !== $businessDate) {
            return 'business_date_mismatch';
        }
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if (!in_array($dataType, ['search_keyword', 'advertising'], true)) {
            return 'data_type_not_allowed';
        }
        if (!$this->isMeituanRow($row)) {
            return 'platform_scope_mismatch';
        }
        if ((int)($row['id'] ?? 0) <= 0 || trim((string)($row['hotel_id'] ?? '')) === '') {
            return 'source_identity_missing';
        }
        if (strtolower(trim((string)($row['history_status'] ?? ''))) !== 'success'
            || strtolower(trim((string)($row['validation_status'] ?? ''))) !== 'verified'
            || (int)($row['readback_verified'] ?? 0) !== 1
        ) {
            return 'strict_readback_gate_failed';
        }
        if (trim((string)($row['source_trace_id'] ?? '')) === '') {
            return 'source_trace_missing';
        }
        $snapshotTime = trim((string)($row['snapshot_time'] ?? ''));
        if ($snapshotTime === '' || strtotime($snapshotTime) === false) {
            return 'snapshot_time_missing';
        }
        if (in_array(strtolower(trim((string)($row['ingestion_method'] ?? ''))), [
            '',
            'legacy',
            'manual',
            'manual_import',
            'manual_override',
            'user_provided',
            'user_provided_unverified',
            'import_csv',
            'import_json',
        ], true)) {
            return 'verified_source_method_missing';
        }
        return '';
    }

    /** @param array<string,mixed> $row */
    private function isMeituanRow(array $row): bool
    {
        $platforms = [];
        foreach (['platform', 'source'] as $field) {
            $value = $this->platform((string)($row[$field] ?? ''));
            if ($value !== '') {
                $platforms[] = $value;
            }
        }
        $platforms = array_values(array_unique($platforms));
        return $platforms === ['meituan'];
    }

    /** @param array<string,mixed> $row @return null|array{object_type:string,object_key:string,object_label:string,keyword:string,campaign_id:string} */
    private function scopeIdentity(array $row): ?array
    {
        $raw = $this->raw($row['raw_data'] ?? []);
        $campaignId = $this->text($raw, ['campaign_id', 'campaignId', 'campaignID']);
        $keyword = $this->text($raw, ['keyword', 'search_keyword', 'searchKeyword', 'searchWord', 'search_word']);
        if ($keyword === '' && strtolower(trim((string)($row['data_type'] ?? ''))) === 'search_keyword') {
            $dimension = trim((string)($row['dimension'] ?? ''));
            if ($dimension !== '' && strtolower($dimension) !== 'search_keyword') {
                $keyword = $dimension;
            }
        }
        if ($campaignId !== '') {
            return [
                'object_type' => 'campaign',
                'object_key' => $this->normalizedKey($campaignId),
                'object_label' => $campaignId,
                'keyword' => $keyword,
                'campaign_id' => $campaignId,
            ];
        }
        if ($keyword !== '') {
            return [
                'object_type' => 'keyword',
                'object_key' => $this->normalizedKey($keyword),
                'object_label' => $keyword,
                'keyword' => $keyword,
                'campaign_id' => '',
            ];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array{object_type:string,object_key:string,object_label:string,keyword:string,campaign_id:string} $identity
     * @return array<string,mixed>
     */
    private function projectRow(
        array $row,
        array $identity,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $raw = $this->raw($row['raw_data'] ?? []);
        $factType = strtolower(trim((string)$row['data_type']));
        $impressions = $this->integer($row, $raw, ['list_exposure', 'impressions', 'exposure_count', 'exposureCount']);
        $clicks = $this->integer($row, $raw, ['detail_exposure', 'clicks', 'click_count', 'clickCount']);
        $spend = null;
        $attributedOrderAmount = null;
        $spendBasis = '';
        $orderAmountBasis = '';
        $basisStatus = 'not_applicable';
        $roas = null;
        $roasStatus = 'not_applicable';
        if ($factType === 'advertising') {
            $storedSpend = $this->number($row, [], ['amount']);
            $rawSpend = $this->number([], $raw, ['spend', 'cost', 'ad_cost', 'adCost', 'todayCost']);
            $spendConflict = $storedSpend !== null
                && $rawSpend !== null
                && abs($storedSpend - $rawSpend) > 0.01;
            $spend = $spendConflict ? null : ($storedSpend ?? $rawSpend);
            $attributedOrderAmount = $this->number([], $raw, [
                'attributed_order_amount',
                'attributedOrderAmount',
                'order_amount',
                'orderAmount',
                'attributed_revenue',
                'attributedRevenue',
            ]);
            $sharedBasis = $this->text($raw, [
                'attribution_basis',
                'attributionBasis',
                'attribution_window',
                'attributionWindow',
                'conversion_window',
                'conversionWindow',
                'reporting_basis',
                'reportingBasis',
            ]);
            $spendBasis = $this->text($raw, ['spend_basis', 'spendBasis', 'cost_basis', 'costBasis']) ?: $sharedBasis;
            $orderAmountBasis = $this->text($raw, [
                'attributed_order_amount_basis',
                'attributedOrderAmountBasis',
                'order_amount_basis',
                'orderAmountBasis',
                'attributed_revenue_basis',
                'attributedRevenueBasis',
            ]) ?: $sharedBasis;
            [$basisStatus, $roasStatus, $roas] = $this->roas(
                $spend,
                $attributedOrderAmount,
                $spendBasis,
                $orderAmountBasis
            );
            if ($spendConflict) {
                $basisStatus = 'conflict';
                $roasStatus = 'spend_value_conflict';
                $roas = null;
            }
        }

        $platformHotelId = trim((string)$row['hotel_id']);
        $scope = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => 'meituan',
            'platform_hotel_id' => $platformHotelId,
            'business_date' => $businessDate,
            'object_type' => $identity['object_type'],
            'object_key' => $identity['object_key'],
            'object_label' => $identity['object_label'],
            'metric_scope' => 'ota_channel',
        ];
        return [
            'fact_type' => $factType,
            'fact_scope_key' => hash('sha256', json_encode(
                $scope,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )),
            'scope' => $scope,
            'campaign_id' => $identity['campaign_id'] !== '' ? $identity['campaign_id'] : null,
            'keyword' => $identity['keyword'] !== '' ? $identity['keyword'] : null,
            'metrics' => [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr_percent' => $impressions !== null && $impressions > 0 && $clicks !== null
                    ? round($clicks / $impressions * 100, 4)
                    : null,
                'spend' => $spend,
                'attributed_order_amount' => $attributedOrderAmount,
                'currency' => $factType === 'advertising' ? 'CNY' : null,
                'spend_basis' => $spendBasis !== '' ? $spendBasis : null,
                'attributed_order_amount_basis' => $orderAmountBasis !== '' ? $orderAmountBasis : null,
                'basis_status' => $basisStatus,
                'roas' => $roas,
                'roas_formula' => $roas !== null ? 'attributed_order_amount / spend' : null,
                'roas_status' => $roasStatus,
            ],
            'quality_status' => 'verified',
            'evidence_type' => 'strict_readback_fact',
            'evidence_refs' => ['online_daily_data#' . (int)$row['id']],
            'source_trace_id' => trim((string)$row['source_trace_id']),
            'snapshot_time' => trim((string)$row['snapshot_time']),
            'data_period' => trim((string)($row['data_period'] ?? '')),
            'decision_eligible' => false,
        ];
    }

    /** @return array{0:string,1:string,2:?float} */
    private function roas(
        ?float $spend,
        ?float $attributedOrderAmount,
        string $spendBasis,
        string $orderAmountBasis
    ): array {
        if ($spend === null) {
            return ['unknown', 'spend_missing', null];
        }
        if ($attributedOrderAmount === null) {
            return ['unknown', 'attributed_order_amount_missing', null];
        }
        if ($spendBasis === '' || $orderAmountBasis === '') {
            return ['missing', 'attribution_basis_missing', null];
        }
        if ($this->normalizedKey($spendBasis) !== $this->normalizedKey($orderAmountBasis)) {
            return ['mismatch', 'attribution_basis_mismatch', null];
        }
        if ($spend <= 0) {
            return ['aligned', 'spend_not_positive', null];
        }
        return ['aligned', 'calculated', round($attributedOrderAmount / $spend, 4)];
    }

    /** @param array<int,array<string,mixed>> $projections @return null|array<string,mixed> */
    private function pendingReviewDraft(array $projections): ?array
    {
        if ($projections === []) {
            return null;
        }
        $selected = null;
        foreach ($projections as $projection) {
            if (($projection['fact_type'] ?? '') === 'advertising') {
                $selected = $projection;
                break;
            }
        }
        $selected ??= $projections[0];
        $roasStatus = (string)($selected['metrics']['roas_status'] ?? 'not_applicable');
        return [
            'contract_version' => self::REVIEW_CONTRACT_VERSION,
            'status' => 'pending_review',
            'draft_type' => 'effect_observation',
            'scope' => $selected['scope'],
            'observation' => [
                'fact_type' => $selected['fact_type'],
                'metrics' => $selected['metrics'],
                'quality_status' => $selected['quality_status'],
                'evidence_refs' => $selected['evidence_refs'],
            ],
            'review_reason' => $roasStatus === 'calculated'
                ? '同范围同归因口径的广告消费与归因订单额齐全；仅供人工判断继续、调整或停止。'
                : '广告效果口径不完整；先由人工核对缺失字段或归因口径，不生成投放结论。',
            'human_decision_options' => $roasStatus === 'calculated'
                ? ['continue', 'adjust', 'stop']
                : ['request_data_completion', 'dismiss'],
            'system_recommendation' => null,
            'causality_claimed' => false,
            'human_confirmation_required' => true,
            'operation_intent_created' => false,
            'operation_task_created' => false,
            'auto_execution_allowed' => false,
            'external_write_count' => 0,
        ];
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private function isLaterSnapshot(array $candidate, array $current): bool
    {
        $candidateTime = strtotime((string)($candidate['snapshot_time'] ?? '')) ?: 0;
        $currentTime = strtotime((string)($current['snapshot_time'] ?? '')) ?: 0;
        return $candidateTime > $currentTime
            || ($candidateTime === $currentTime && (int)($candidate['id'] ?? 0) > (int)($current['id'] ?? 0));
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareProjections(array $left, array $right): int
    {
        $leftType = ($left['fact_type'] ?? '') === 'advertising' ? 0 : 1;
        $rightType = ($right['fact_type'] ?? '') === 'advertising' ? 0 : 1;
        if ($leftType !== $rightType) {
            return $leftType <=> $rightType;
        }
        $leftWeight = $leftType === 0
            ? ($left['metrics']['spend'] ?? -1)
            : ($left['metrics']['impressions'] ?? -1);
        $rightWeight = $rightType === 0
            ? ($right['metrics']['spend'] ?? -1)
            : ($right['metrics']['impressions'] ?? -1);
        return $rightWeight <=> $leftWeight
            ?: strcmp((string)($left['fact_scope_key'] ?? ''), (string)($right['fact_scope_key'] ?? ''));
    }

    /** @param array<string,int> $reasons */
    private function incrementReason(array &$reasons, string $reason): void
    {
        $reasons[$reason] = (int)($reasons[$reason] ?? 0) + 1;
        ksort($reasons);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $raw @param array<int,string> $keys */
    private function number(array $row, array $raw, array $keys): ?float
    {
        foreach ([$row, $raw] as $source) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $source) && is_numeric($source[$key])) {
                    return round((float)$source[$key], 4);
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $raw @param array<int,string> $keys */
    private function integer(array $row, array $raw, array $keys): ?int
    {
        $value = $this->number($row, $raw, $keys);
        return $value === null ? null : max(0, (int)round($value));
    }

    /** @param array<string,mixed> $raw @param array<int,string> $keys */
    private function text(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $raw) && !is_array($raw[$key]) && !is_object($raw[$key])) {
                $value = trim((string)$raw[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function raw(mixed $value): array
    {
        $raw = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($raw)) {
            return [];
        }
        if ($raw === []) {
            return [];
        }
        foreach (['detail', 'metrics', 'data'] as $key) {
            if (is_array($raw[$key] ?? null)) {
                $raw = array_merge($raw, $raw[$key]);
            }
        }
        return $raw;
    }

    private function platform(string $value): string
    {
        $value = mb_strtolower(trim($value));
        return in_array($value, ['meituan', '美团'], true) ? 'meituan' : $value;
    }

    private function normalizedKey(string $value): string
    {
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $value)));
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('meituan_marketing_business_date_invalid');
        }
        return $value;
    }
}
