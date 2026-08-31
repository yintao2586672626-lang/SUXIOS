<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Persists PII-free OTA settlement facts and their bounded OTA/PMS matching
 * result. This service never reads credentials and never writes to an OTA,
 * PMS, accounting system, or external messaging channel.
 */
final class OtaSettlementReconciliationService
{
    public const CONTRACT_VERSION = 'ota_settlement_reconciliation.v1';

    private const BATCH_TABLE = 'ota_settlement_import_batches';
    private const LINE_TABLE = 'ota_settlement_line_facts';
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const SOURCE_METHODS = [
        'manual_export',
        'authorized_api_export',
        'synthetic_test_fixture',
    ];
    private const SOURCE_QUALITY_STATUSES = [
        'verified_export',
        'operator_attested',
        'unverified',
        'synthetic_test_only',
    ];
    private const AMOUNT_SCOPES = ['booking', 'stay', 'settlement', 'adjustment'];
    private const MATCH_STATUSES = [
        'matched',
        'amount_mismatch',
        'date_mismatch',
        'ota_only',
        'pms_only',
        'unmatched',
        'not_evaluated',
    ];
    private const COMPARISON_BASES = [
        'gross_amount',
        'room_revenue',
        'settlement_amount',
        'net_revenue',
    ];
    private const DIRECT_DISCREPANCY_BASES = [
        'source_direct_gross',
        'source_direct_settlement',
        'source_direct_net_revenue',
        'source_direct_refund',
    ];

    /** @var callable():DateTimeImmutable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('Asia/Shanghai')
        );
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    public function importAndReadback(array $scope, array $lines, int $userId = 0): array
    {
        $scope = $this->normalizeScope($scope);
        $normalizedLines = $this->normalizeLines($scope, $lines);
        $summary = $this->summarize($scope, $normalizedLines);
        $importedAt = ($this->clock)()
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
        $existing = $this->findByScopeFile($scope);
        $predecessor = $existing === null ? $this->findLatestByScopeFileAnyVersion($scope) : null;
        if (is_array($predecessor)) {
            $this->assertStoredIntegrity($predecessor);
        }

        $batch = [
            'contract_version' => self::CONTRACT_VERSION,
            ...$scope,
            ...$summary['batch_fields'],
            'source_hotel_id' => is_array($existing)
                ? (int)$existing['batch']['source_hotel_id']
                : (is_array($predecessor) ? (int)$predecessor['batch']['source_hotel_id'] : (int)$scope['source_hotel_id']),
            'supersedes_batch_id' => is_array($existing)
                ? $existing['batch']['supersedes_batch_id']
                : (is_array($predecessor) ? (int)$predecessor['batch']['id'] : null),
            'supersession_reason' => is_array($existing)
                ? $existing['batch']['supersession_reason']
                : (is_array($predecessor) ? $this->supersessionReason($predecessor['batch'], $scope) : null),
            'external_write_authorized' => 0,
            'imported_by' => max(0, $userId),
            'imported_at' => $importedAt,
        ];
        $batch['batch_fingerprint'] = $this->batchFingerprint($batch, $normalizedLines);

        if (is_array($existing)) {
            $this->assertReplayMatches($existing, $batch, $normalizedLines);
            return $this->presentReadback($existing, true);
        }

        $stored = null;
        $reused = false;
        try {
            $stored = $this->persist($batch, $normalizedLines);
        } catch (Throwable $error) {
            if (!$this->isDuplicateKeyConflict($error)) {
                throw $error;
            }
            $stored = $this->findByScopeFile($scope);
            $reused = true;
        }

        if (!is_array($stored)) {
            throw new RuntimeException('ota_settlement_readback_failed');
        }
        $this->assertReplayMatches($stored, $batch, $normalizedLines);
        return $this->presentReadback($stored, $reused);
    }

    /**
     * Returns the latest exact saved batch for one tenant/hotel/platform/period
     * scope. It is read-only and never substitutes another period or hotel.
     *
     * @return array<string,mixed>
     */
    public function latestForScope(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $periodStart,
        string $periodEnd
    ): array {
        $scope = $this->normalizeReadScope(
            $tenantId,
            $hotelId,
            $platform,
            $periodStart,
            $periodEnd
        );
        $row = Db::name(self::BATCH_TABLE)
            ->where('tenant_id', $scope['tenant_id'])
            ->where('hotel_id', $scope['hotel_id'])
            ->where('platform', $scope['platform'])
            ->where('period_start', $scope['period_start'])
            ->where('period_end', $scope['period_end'])
            ->order('imported_at', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return $this->missingReadback($scope);
        }
        $attempt = $this->readBatch((int)$row['id']);
        if (!is_array($attempt)) {
            throw new RuntimeException('ota_settlement_readback_failed');
        }
        $this->assertStoredIntegrity($attempt);
        $display = $attempt;
        $projectionStatus = 'latest_attempt';
        if ((string)$attempt['batch']['batch_status'] === 'invalid') {
            $fallback = Db::name(self::BATCH_TABLE)
                ->where('tenant_id', $scope['tenant_id'])
                ->where('hotel_id', $scope['hotel_id'])
                ->where('platform', $scope['platform'])
                ->where('period_start', $scope['period_start'])
                ->where('period_end', $scope['period_end'])
                ->where('batch_status', '<>', 'invalid')
                ->order('imported_at', 'desc')
                ->order('id', 'desc')
                ->find();
            if (is_array($fallback)) {
                $fallbackStored = $this->readBatch((int)$fallback['id']);
                if (!is_array($fallbackStored)) {
                    throw new RuntimeException('ota_settlement_readback_failed');
                }
                $this->assertStoredIntegrity($fallbackStored);
                $display = $fallbackStored;
                $projectionStatus = 'latest_non_invalid_with_newer_invalid_attempt';
            }
        }
        $result = $this->presentReadback($display, false);
        $result['projection_status'] = $projectionStatus;
        $result['latest_attempt'] = [
            'batch_id' => (int)$attempt['batch']['id'],
            'batch_status' => (string)$attempt['batch']['batch_status'],
            'source_quality_status' => (string)$attempt['batch']['source_quality_status'],
            'imported_at' => (string)$attempt['batch']['imported_at'],
            'readback_verified' => true,
        ];
        return $result;
    }

    /** @param array<string,mixed> $scope */
    private function normalizeScope(array $scope): array
    {
        $tenantId = (int)($scope['tenant_id'] ?? 0);
        $hotelId = (int)($scope['hotel_id'] ?? 0);
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('ota_settlement_scope_invalid');
        }

        $platform = strtolower(trim((string)($scope['platform'] ?? '')));
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException('ota_settlement_platform_invalid');
        }
        $periodStart = $this->date((string)($scope['period_start'] ?? ''));
        $periodEnd = $this->date((string)($scope['period_end'] ?? ''));
        if ($periodStart > $periodEnd) {
            throw new InvalidArgumentException('ota_settlement_period_invalid');
        }

        $fileSha256 = $this->sha256((string)($scope['file_sha256'] ?? ''), false);
        $sourceEvidenceSha256 = $this->sha256(
            (string)($scope['source_evidence_sha256'] ?? ''),
            true
        );
        $sourceMethod = strtolower(trim((string)($scope['source_method'] ?? '')));
        if (!in_array($sourceMethod, self::SOURCE_METHODS, true)) {
            throw new InvalidArgumentException('ota_settlement_source_method_invalid');
        }
        $sourceQualityStatus = strtolower(trim((string)($scope['source_quality_status'] ?? '')));
        if (!in_array($sourceQualityStatus, self::SOURCE_QUALITY_STATUSES, true)) {
            throw new InvalidArgumentException('ota_settlement_source_quality_invalid');
        }
        if ($sourceMethod === 'synthetic_test_fixture'
            && $sourceQualityStatus !== 'synthetic_test_only'
        ) {
            throw new InvalidArgumentException('ota_settlement_synthetic_source_quality_invalid');
        }
        if ($sourceMethod === 'manual_export' && $sourceQualityStatus === 'verified_export') {
            throw new InvalidArgumentException('ota_settlement_manual_export_cannot_self_verify_source_identity');
        }

        $parserVersion = trim((string)($scope['parser_version'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._:-]{1,80}$/D', $parserVersion) !== 1) {
            throw new InvalidArgumentException('ota_settlement_parser_version_invalid');
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_hotel_id' => $hotelId,
            'platform' => $platform,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'file_sha256' => $fileSha256,
            'source_evidence_sha256' => $sourceEvidenceSha256,
            'source_method' => $sourceMethod,
            'source_quality_status' => $sourceQualityStatus,
            'parser_version' => $parserVersion,
        ];
    }

    /** @return array{tenant_id:int,hotel_id:int,platform:string,period_start:string,period_end:string} */
    private function normalizeReadScope(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $periodStart,
        string $periodEnd
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('ota_settlement_scope_invalid');
        }
        $platform = strtolower(trim($platform));
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new InvalidArgumentException('ota_settlement_platform_invalid');
        }
        $periodStart = $this->date($periodStart);
        $periodEnd = $this->date($periodEnd);
        if ($periodStart > $periodEnd) {
            throw new InvalidArgumentException('ota_settlement_period_invalid');
        }
        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function normalizeLines(array $scope, array $lines): array
    {
        $normalized = [];
        $lineNumbers = [];
        $sourceHashes = [];
        foreach (array_values($lines) as $index => $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('ota_settlement_line_invalid');
            }
            $row = $this->normalizeLine($scope, $line, $index + 1);
            $lineNo = (int)$row['source_line_no'];
            $sourceHash = (string)$row['source_line_sha256'];
            if (isset($lineNumbers[$lineNo])) {
                throw new InvalidArgumentException('ota_settlement_source_line_no_duplicate');
            }
            if (isset($sourceHashes[$sourceHash])) {
                throw new InvalidArgumentException('ota_settlement_source_line_hash_duplicate');
            }
            $lineNumbers[$lineNo] = true;
            $sourceHashes[$sourceHash] = true;
            $normalized[] = $row;
        }
        usort(
            $normalized,
            static fn(array $left, array $right): int =>
                (int)$left['source_line_no'] <=> (int)$right['source_line_no']
        );
        return $normalized;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function normalizeLine(array $scope, array $line, int $fallbackLineNo): array
    {
        $gaps = [];
        $invalid = false;
        $sourceLineNo = array_key_exists('source_line_no', $line)
            ? filter_var($line['source_line_no'], FILTER_VALIDATE_INT)
            : $fallbackLineNo;
        if (!is_int($sourceLineNo) || $sourceLineNo <= 0) {
            throw new InvalidArgumentException('ota_settlement_source_line_no_invalid');
        }

        $businessDate = $this->lineDate(
            $line['business_date'] ?? null,
            (string)$scope['period_start'],
            (string)$scope['period_end'],
            $gaps,
            $invalid
        );
        $amountScope = strtolower(trim((string)($line['amount_scope'] ?? '')));
        if ($amountScope === '') {
            $amountScope = null;
            $gaps[] = 'amount_scope_missing';
            $invalid = true;
        } elseif (!in_array($amountScope, self::AMOUNT_SCOPES, true)) {
            $amountScope = null;
            $gaps[] = 'amount_scope_invalid';
            $invalid = true;
        }

        $otaRefHash = $this->referenceHash(
            $line['ota_order_ref'] ?? null,
            (string)$scope['platform'],
            'ota_order_ref',
            $gaps,
            $invalid
        );
        $pmsRefHash = $this->referenceHash(
            $line['pms_stay_ref'] ?? null,
            'pms',
            'pms_stay_ref',
            $gaps,
            $invalid
        );

        [$grossAmount, $grossBasis] = $this->moneyComponent(
            $line,
            'gross_amount',
            ['source_direct'],
            true,
            $gaps,
            $invalid
        );
        [$commissionAmount, $commissionBasis] = $this->moneyComponent(
            $line,
            'commission_amount',
            ['source_direct'],
            true,
            $gaps,
            $invalid
        );
        [$subsidyAmount, $subsidyBasis] = $this->moneyComponent(
            $line,
            'subsidy_amount',
            ['source_direct'],
            true,
            $gaps,
            $invalid
        );
        [$refundAmount, $refundBasis] = $this->moneyComponent(
            $line,
            'refund_amount',
            ['source_direct'],
            true,
            $gaps,
            $invalid
        );
        [$settlementAmount, $settlementBasis] = $this->moneyComponent(
            $line,
            'settlement_amount',
            ['source_direct'],
            false,
            $gaps,
            $invalid
        );
        [$netRevenue, $netRevenueBasis] = $this->moneyComponent(
            $line,
            'net_revenue',
            ['source_direct'],
            false,
            $gaps,
            $invalid
        );

        $netRevenueFormula = null;
        $derivation = strtolower(trim((string)($line['net_revenue_derivation'] ?? '')));
        if ($netRevenue !== null && $derivation !== '') {
            $gaps[] = 'net_revenue_direct_and_derived_conflict';
            $invalid = true;
        } elseif ($netRevenue === null && $netRevenueBasis !== 'invalid' && $derivation !== '') {
            if ($derivation !== 'gross_minus_commission') {
                $netRevenueBasis = 'invalid';
                $gaps[] = 'net_revenue_derivation_invalid';
                $invalid = true;
            } elseif ($amountScope === null
                || $grossAmount === null
                || $commissionAmount === null
                || !$this->componentExcludedFromFormula($subsidyAmount, $subsidyBasis)
                || !$this->componentExcludedFromFormula($refundAmount, $refundBasis)
            ) {
                $netRevenueBasis = 'missing';
                $gaps[] = 'net_revenue_derivation_prerequisites_missing';
            } else {
                $netRevenue = $this->money($grossAmount - $commissionAmount);
                $netRevenueBasis = 'derived_aligned_gross_minus_commission';
                $netRevenueFormula = 'gross_amount - commission_amount';
                $gaps = array_values(array_filter(
                    $gaps,
                    static fn(string $gap): bool => $gap !== 'net_revenue_missing'
                ));
            }
        }
        if ($settlementAmount !== null && $netRevenue === null) {
            $gaps[] = 'settlement_amount_not_net_revenue';
        }

        $matchStatus = strtolower(trim((string)($line['match_status'] ?? 'not_evaluated')));
        if (!in_array($matchStatus, self::MATCH_STATUSES, true)) {
            $matchStatus = 'not_evaluated';
            $gaps[] = 'match_status_invalid';
            $invalid = true;
        }
        if (!$this->matchReferencesAreConsistent($matchStatus, $otaRefHash, $pmsRefHash)) {
            $gaps[] = 'match_reference_scope_invalid';
            $invalid = true;
        }

        [$otaComparisonAmount, $otaComparisonInvalid] = $this->optionalMoney(
            $line['ota_comparison_amount'] ?? null,
            false
        );
        [$pmsComparisonAmount, $pmsComparisonInvalid] = $this->optionalMoney(
            $line['pms_comparison_amount'] ?? null,
            false
        );
        if ($otaComparisonInvalid || $pmsComparisonInvalid) {
            $gaps[] = 'comparison_amount_invalid';
            $invalid = true;
        }
        $comparisonBasis = strtolower(trim((string)($line['comparison_basis'] ?? '')));
        $comparisonBasis = $comparisonBasis === '' ? null : $comparisonBasis;

        [$directDiscrepancy, $directDiscrepancyInvalid] = $this->optionalMoney(
            $line['discrepancy_amount'] ?? null,
            true
        );
        if ($directDiscrepancyInvalid) {
            $gaps[] = 'discrepancy_amount_invalid';
            $invalid = true;
        }
        $directDiscrepancyBasis = strtolower(trim((string)($line['discrepancy_basis'] ?? '')));
        $discrepancyAmount = null;
        $discrepancyBasis = 'missing';
        $hasComparisonAmount = $otaComparisonAmount !== null || $pmsComparisonAmount !== null;
        if ($directDiscrepancy !== null && $hasComparisonAmount) {
            $gaps[] = 'discrepancy_direct_and_derived_conflict';
            $discrepancyBasis = 'invalid';
            $invalid = true;
        } elseif ($directDiscrepancy !== null) {
            if (!in_array($directDiscrepancyBasis, self::DIRECT_DISCREPANCY_BASES, true)) {
                $gaps[] = 'discrepancy_basis_invalid';
                $discrepancyBasis = 'invalid';
                $invalid = true;
            } else {
                $discrepancyAmount = $directDiscrepancy;
                $discrepancyBasis = $directDiscrepancyBasis;
            }
        } elseif ($otaComparisonAmount !== null && $pmsComparisonAmount !== null) {
            if ($comparisonBasis === null
                || !in_array($comparisonBasis, self::COMPARISON_BASES, true)
            ) {
                $gaps[] = 'comparison_basis_invalid';
                $discrepancyBasis = 'invalid';
                $invalid = true;
            } else {
                $discrepancyAmount = $this->money(abs($otaComparisonAmount - $pmsComparisonAmount));
                $discrepancyBasis = 'derived_absolute_difference:' . $comparisonBasis;
            }
        } elseif ($hasComparisonAmount) {
            $gaps[] = 'comparison_amounts_incomplete';
        }

        if ($matchStatus === 'matched' && $discrepancyAmount !== null && $discrepancyAmount > 0) {
            $gaps[] = 'matched_with_nonzero_discrepancy';
            $invalid = true;
        }
        if ($matchStatus === 'amount_mismatch'
            && ($discrepancyAmount === null || $discrepancyAmount <= 0)
        ) {
            $gaps[] = 'amount_mismatch_without_positive_discrepancy';
            $invalid = true;
        }

        $moneyValues = [
            $grossAmount,
            $commissionAmount,
            $subsidyAmount,
            $refundAmount,
            $settlementAmount,
            $netRevenue,
        ];
        if (count(array_filter($moneyValues, static fn(?float $value): bool => $value !== null)) === 0) {
            $gaps[] = 'monetary_fact_missing';
            $invalid = true;
        }

        $gaps = array_values(array_unique($gaps));
        sort($gaps, SORT_STRING);
        $qualityStatus = $invalid
            ? 'invalid'
            : (
                $netRevenue === null
                || in_array($matchStatus, ['not_evaluated', 'unmatched'], true)
                || (
                    in_array($matchStatus, ['amount_mismatch', 'date_mismatch', 'ota_only', 'pms_only'], true)
                    && $discrepancyAmount === null
                )
                    ? 'partial'
                    : 'available'
            );

        $row = [
            'source_line_no' => $sourceLineNo,
            'source_line_sha256' => '',
            'business_date' => $businessDate,
            'amount_scope' => $amountScope,
            'ota_order_ref_sha256' => $otaRefHash,
            'pms_stay_ref_sha256' => $pmsRefHash,
            'gross_amount' => $grossAmount,
            'gross_amount_basis' => $grossBasis,
            'commission_amount' => $commissionAmount,
            'commission_amount_basis' => $commissionBasis,
            'subsidy_amount' => $subsidyAmount,
            'subsidy_amount_basis' => $subsidyBasis,
            'refund_amount' => $refundAmount,
            'refund_amount_basis' => $refundBasis,
            'settlement_amount' => $settlementAmount,
            'settlement_amount_basis' => $settlementBasis,
            'net_revenue' => $netRevenue,
            'net_revenue_basis' => $netRevenueBasis,
            'net_revenue_formula' => $netRevenueFormula,
            'match_status' => $matchStatus,
            'ota_comparison_amount' => $otaComparisonAmount,
            'pms_comparison_amount' => $pmsComparisonAmount,
            'comparison_basis' => $comparisonBasis,
            'discrepancy_amount' => $discrepancyAmount,
            'discrepancy_basis' => $discrepancyBasis,
            'quality_status' => $qualityStatus,
            'gap_codes' => $gaps,
        ];

        $providedSourceHash = trim((string)($line['source_line_sha256'] ?? ''));
        if ($providedSourceHash !== '') {
            if (preg_match('/^[a-fA-F0-9]{64}$/D', $providedSourceHash) !== 1) {
                $row['gap_codes'][] = 'source_line_sha256_invalid';
                sort($row['gap_codes'], SORT_STRING);
                $row['quality_status'] = 'invalid';
                $providedSourceHash = '';
            } else {
                $providedSourceHash = strtolower($providedSourceHash);
            }
        }
        $row['source_line_sha256'] = $providedSourceHash !== ''
            ? $providedSourceHash
            : hash('sha256', $this->canonicalJson([
                'file_sha256' => $scope['file_sha256'],
                'source_line_no' => $sourceLineNo,
                'sanitized_fact' => $row,
            ]));
        $row['line_fingerprint'] = $this->lineFingerprint($row);
        return $row;
    }

    /**
     * @param array<string,mixed> $line
     * @param list<string> $allowedValueBases
     * @param list<string> $gaps
     * @return array{0:?float,1:string}
     */
    private function moneyComponent(
        array $line,
        string $field,
        array $allowedValueBases,
        bool $nonNegative,
        array &$gaps,
        bool &$invalid
    ): array {
        $basisField = $field . '_basis';
        $basis = strtolower(trim((string)($line[$basisField] ?? '')));
        [$value, $valueInvalid] = $this->optionalMoney($line[$field] ?? null, $nonNegative);
        if ($valueInvalid) {
            $gaps[] = $field . '_invalid';
            $invalid = true;
            return [null, 'invalid'];
        }
        if ($value === null) {
            if ($basis === 'not_applicable') {
                return [null, 'not_applicable'];
            }
            if ($basis !== '' && $basis !== 'missing') {
                $gaps[] = $basisField . '_invalid';
                $invalid = true;
                return [null, 'invalid'];
            }
            $gaps[] = $field . '_missing';
            return [null, 'missing'];
        }
        if (!in_array($basis, $allowedValueBases, true)) {
            $gaps[] = $basisField . '_invalid';
            $invalid = true;
            return [null, 'invalid'];
        }
        return [$value, $basis];
    }

    private function componentExcludedFromFormula(?float $value, string $basis): bool
    {
        return $basis === 'not_applicable' || ($value !== null && $value === 0.0);
    }

    private function matchReferencesAreConsistent(
        string $status,
        ?string $otaReference,
        ?string $pmsReference
    ): bool {
        return match ($status) {
            'matched', 'amount_mismatch', 'date_mismatch' =>
                $otaReference !== null && $pmsReference !== null,
            'ota_only' => $otaReference !== null && $pmsReference === null,
            'pms_only' => $otaReference === null && $pmsReference !== null,
            default => true,
        };
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<array<string,mixed>> $lines
     * @return array{batch_fields:array<string,mixed>}
     */
    private function summarize(array $scope, array $lines): array
    {
        $statusCounts = ['available' => 0, 'partial' => 0, 'invalid' => 0];
        foreach ($lines as $line) {
            $statusCounts[(string)$line['quality_status']]++;
        }
        if ($lines === [] || $statusCounts['invalid'] === count($lines)) {
            $batchStatus = 'invalid';
        } elseif ($statusCounts['available'] === count($lines)
            && $scope['source_method'] === 'authorized_api_export'
            && $scope['source_quality_status'] === 'verified_export'
        ) {
            $batchStatus = 'available';
        } else {
            $batchStatus = 'partial';
        }

        $batchFields = [
            'batch_status' => $batchStatus,
            'line_count' => count($lines),
            'available_line_count' => $statusCounts['available'],
            'partial_line_count' => $statusCounts['partial'],
            'invalid_line_count' => $statusCounts['invalid'],
        ];
        foreach ([
            'gross_amount',
            'commission_amount',
            'subsidy_amount',
            'refund_amount',
            'settlement_amount',
            'net_revenue',
        ] as $field) {
            [$value, $basis] = $this->completeTotal($lines, $field);
            $batchFields[$field . '_total'] = $value;
            $batchFields[$field . '_total_basis'] = $basis;
        }
        return ['batch_fields' => $batchFields];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array{0:?float,1:string}
     */
    private function completeTotal(array $lines, string $field): array
    {
        if ($lines === []) {
            return [null, 'missing'];
        }
        if (array_filter(
            $lines,
            static fn(array $line): bool => (string)($line['quality_status'] ?? '') === 'invalid'
        ) !== []) {
            return [null, 'partial'];
        }
        $values = [];
        $bases = [];
        foreach ($lines as $line) {
            $basis = (string)($line[$field . '_basis'] ?? 'missing');
            $bases[] = $basis;
            if ($line[$field] !== null) {
                $values[] = (float)$line[$field];
            }
        }
        if ($values === []) {
            if (count(array_filter($bases, static fn(string $basis): bool => $basis === 'not_applicable')) === count($bases)) {
                return [null, 'not_applicable'];
            }
            return [null, in_array('invalid', $bases, true) ? 'invalid' : 'missing'];
        }
        if (count($values) !== count($lines)) {
            return [null, 'partial'];
        }
        $derived = count(array_filter(
            $bases,
            static fn(string $basis): bool => str_starts_with($basis, 'derived_')
        ));
        $basis = $derived === 0
            ? 'complete_source_direct'
            : ($derived === count($bases) ? 'complete_derived' : 'complete_mixed');
        return [$this->money(array_sum($values)), $basis];
    }

    /**
     * @param array<string,mixed> $batch
     * @param list<array<string,mixed>> $lines
     * @return array{batch:array<string,mixed>,lines:list<array<string,mixed>>}
     */
    private function persist(array $batch, array $lines): array
    {
        return Db::transaction(function () use ($batch, $lines): array {
            $batchId = (int)Db::name(self::BATCH_TABLE)->insertGetId($batch);
            if ($batchId <= 0) {
                throw new RuntimeException('ota_settlement_batch_write_failed');
            }
            foreach ($lines as $line) {
                $storedLine = $line;
                $storedLine['batch_id'] = $batchId;
                $storedLine['gap_codes_json'] = $this->canonicalJson($storedLine['gap_codes']);
                unset($storedLine['gap_codes']);
                Db::name(self::LINE_TABLE)->insert($storedLine);
            }
            $stored = $this->readBatch($batchId);
            if (!is_array($stored)) {
                throw new RuntimeException('ota_settlement_readback_failed');
            }
            $this->assertReplayMatches($stored, $batch, $lines);
            return $stored;
        });
    }

    /** @param array<string,mixed> $scope */
    private function findByScopeFile(array $scope): ?array
    {
        $row = Db::name(self::BATCH_TABLE)
            ->where('tenant_id', $scope['tenant_id'])
            ->where('hotel_id', $scope['hotel_id'])
            ->where('platform', $scope['platform'])
            ->where('period_start', $scope['period_start'])
            ->where('period_end', $scope['period_end'])
            ->where('file_sha256', $scope['file_sha256'])
            ->where('parser_version', $scope['parser_version'])
            ->where('source_quality_status', $scope['source_quality_status'])
            ->find();
        if (!is_array($row)) {
            return null;
        }
        return $this->readBatch((int)$row['id']);
    }

    /** @param array<string,mixed> $scope @return array{batch:array<string,mixed>,lines:list<array<string,mixed>>}|null */
    private function findLatestByScopeFileAnyVersion(array $scope): ?array
    {
        $row = Db::name(self::BATCH_TABLE)
            ->where('tenant_id', $scope['tenant_id'])
            ->where('hotel_id', $scope['hotel_id'])
            ->where('platform', $scope['platform'])
            ->where('period_start', $scope['period_start'])
            ->where('period_end', $scope['period_end'])
            ->where('file_sha256', $scope['file_sha256'])
            ->order('imported_at', 'desc')
            ->order('id', 'desc')
            ->find();
        return is_array($row) ? $this->readBatch((int)$row['id']) : null;
    }

    /** @param array<string,mixed> $previous @param array<string,mixed> $scope */
    private function supersessionReason(array $previous, array $scope): string
    {
        $parserChanged = (string)($previous['parser_version'] ?? '') !== (string)$scope['parser_version'];
        $qualityChanged = (string)($previous['source_quality_status'] ?? '') !== (string)$scope['source_quality_status'];
        if ($parserChanged && $qualityChanged) return 'parser_and_source_quality_revision';
        if ($parserChanged) return 'parser_revision';
        if ($qualityChanged) return 'source_quality_revision';
        return 'same_file_reprocessing';
    }

    /** @return array{batch:array<string,mixed>,lines:list<array<string,mixed>>}|null */
    private function readBatch(int $batchId): ?array
    {
        $batch = Db::name(self::BATCH_TABLE)->where('id', $batchId)->find();
        if (!is_array($batch)) {
            return null;
        }
        $lines = Db::name(self::LINE_TABLE)
            ->where('batch_id', $batchId)
            ->order('source_line_no', 'asc')
            ->select()
            ->toArray();
        return [
            'batch' => $this->normalizeStoredBatch($batch),
            'lines' => array_map(fn(array $line): array => $this->normalizeStoredLine($line), $lines),
        ];
    }

    /**
     * @param array{batch:array<string,mixed>,lines:list<array<string,mixed>>} $stored
     * @param array<string,mixed> $expectedBatch
     * @param list<array<string,mixed>> $expectedLines
     */
    private function assertReplayMatches(array $stored, array $expectedBatch, array $expectedLines): void
    {
        $this->assertStoredIntegrity($stored);
        $storedBatch = $stored['batch'];
        $storedLines = $stored['lines'];
        if (count($storedLines) !== count($expectedLines)) {
            throw new RuntimeException('ota_settlement_readback_line_count_mismatch');
        }
        foreach ($storedLines as $index => $storedLine) {
            if (!isset($expectedLines[$index])
                || !hash_equals(
                    (string)$expectedLines[$index]['line_fingerprint'],
                    (string)$storedLine['line_fingerprint']
                )
            ) {
                throw new RuntimeException('ota_settlement_readback_line_mismatch');
            }
        }
        $storedFingerprint = $this->batchFingerprint($storedBatch, $storedLines);
        if (!hash_equals((string)$expectedBatch['batch_fingerprint'], $storedFingerprint)) {
            throw new RuntimeException('ota_settlement_checksum_replay_content_mismatch');
        }
    }

    /** @param array{batch:array<string,mixed>,lines:list<array<string,mixed>>} $stored */
    private function assertStoredIntegrity(array $stored): void
    {
        $storedBatch = $stored['batch'];
        $storedLines = $stored['lines'];
        if ((int)($storedBatch['external_write_authorized'] ?? 1) !== 0) {
            throw new RuntimeException('ota_settlement_external_write_boundary_violated');
        }
        if ((int)($storedBatch['line_count'] ?? -1) !== count($storedLines)) {
            throw new RuntimeException('ota_settlement_readback_line_count_mismatch');
        }
        foreach ($storedLines as $storedLine) {
            if (!hash_equals(
                (string)$storedLine['line_fingerprint'],
                $this->lineFingerprint($storedLine)
            )) {
                throw new RuntimeException('ota_settlement_readback_line_mismatch');
            }
        }
        if (!hash_equals(
            (string)$storedBatch['batch_fingerprint'],
            $this->batchFingerprint($storedBatch, $storedLines)
        )) {
            throw new RuntimeException('ota_settlement_readback_batch_mismatch');
        }
    }

    /**
     * @param array{batch:array<string,mixed>,lines:list<array<string,mixed>>} $stored
     * @return array<string,mixed>
     */
    private function presentReadback(array $stored, bool $reused): array
    {
        $batch = $stored['batch'];
        $lines = $stored['lines'];
        $ranked = array_values(array_filter(
            $lines,
            static fn(array $line): bool => (string)($line['quality_status'] ?? '') !== 'invalid'
                && $line['discrepancy_amount'] !== null
                && (float)$line['discrepancy_amount'] > 0
                && (string)$line['match_status'] !== 'matched'
        ));
        usort($ranked, static function (array $left, array $right): int {
            $amountOrder = (float)$right['discrepancy_amount'] <=> (float)$left['discrepancy_amount'];
            return $amountOrder !== 0
                ? $amountOrder
                : (int)$left['source_line_no'] <=> (int)$right['source_line_no'];
        });
        $rankByLine = [];
        foreach ($ranked as $index => &$line) {
            $line['discrepancy_rank'] = $index + 1;
            $rankByLine[(int)$line['source_line_no']] = $index + 1;
        }
        unset($line);
        foreach ($lines as &$line) {
            $line['discrepancy_rank'] = $rankByLine[(int)$line['source_line_no']] ?? null;
        }
        unset($line);

        $totals = [];
        foreach ([
            'gross_amount',
            'commission_amount',
            'subsidy_amount',
            'refund_amount',
            'settlement_amount',
            'net_revenue',
        ] as $field) {
            $totals[$field] = [
                'value' => $batch[$field . '_total'],
                'basis' => $batch[$field . '_total_basis'],
            ];
        }

        $result = [
            'contract_version' => self::CONTRACT_VERSION,
            'batch_id' => (int)$batch['id'],
            'supersedes_batch_id' => $batch['supersedes_batch_id'],
            'supersession_reason' => $batch['supersession_reason'],
            'batch_fingerprint' => (string)$batch['batch_fingerprint'],
            'batch_status' => (string)$batch['batch_status'],
            'read_status' => 'available',
            'reused' => $reused,
            'readback_verified' => true,
            'scope' => [
                'tenant_id' => (int)$batch['tenant_id'],
                'hotel_id' => (int)$batch['hotel_id'],
                'source_hotel_id' => (int)$batch['source_hotel_id'],
                'platform' => (string)$batch['platform'],
                'period_start' => (string)$batch['period_start'],
                'period_end' => (string)$batch['period_end'],
            ],
            'source' => [
                'file_sha256' => (string)$batch['file_sha256'],
                'source_evidence_sha256' => $batch['source_evidence_sha256'],
                'source_method' => (string)$batch['source_method'],
                'source_quality_status' => (string)$batch['source_quality_status'],
                'parser_version' => (string)$batch['parser_version'],
            ],
            'counts' => [
                'line_count' => (int)$batch['line_count'],
                'available' => (int)$batch['available_line_count'],
                'partial' => (int)$batch['partial_line_count'],
                'invalid' => (int)$batch['invalid_line_count'],
            ],
            'totals' => $totals,
            'basis_ledger' => $this->basisLedger($totals),
            'lines' => $lines,
            'ranked_discrepancies' => $ranked,
            'authorization' => [
                'external_write_authorized' => false,
                'ota_write_authorized' => false,
                'pms_write_authorized' => false,
                'accounting_write_authorized' => false,
            ],
        ];
        $result['recovery_blocker'] = (new OtaSettlementRecoveryBlockerCandidateService())->build($result);
        return $result;
    }

    /**
     * @param array{tenant_id:int,hotel_id:int,platform:string,period_start:string,period_end:string} $scope
     * @return array<string,mixed>
     */
    private function missingReadback(array $scope): array
    {
        $totals = [];
        foreach ([
            'gross_amount',
            'commission_amount',
            'subsidy_amount',
            'refund_amount',
            'settlement_amount',
            'net_revenue',
        ] as $field) {
            $totals[$field] = ['value' => null, 'basis' => 'missing'];
        }
        $result = [
            'contract_version' => self::CONTRACT_VERSION,
            'batch_id' => null,
            'batch_fingerprint' => null,
            'batch_status' => 'missing',
            'read_status' => 'missing',
            'reused' => false,
            'readback_verified' => false,
            'scope' => $scope,
            'source' => null,
            'counts' => [
                'line_count' => 0,
                'available' => 0,
                'partial' => 0,
                'invalid' => 0,
            ],
            'totals' => $totals,
            'basis_ledger' => $this->basisLedger($totals),
            'lines' => [],
            'ranked_discrepancies' => [],
            'authorization' => [
                'external_write_authorized' => false,
                'ota_write_authorized' => false,
                'pms_write_authorized' => false,
                'accounting_write_authorized' => false,
            ],
        ];
        $result['recovery_blocker'] = (new OtaSettlementRecoveryBlockerCandidateService())->build($result);
        return $result;
    }

    /** @param array<string,array{value:?float,basis:string}> $totals @return array<string,mixed> */
    private function basisLedger(array $totals): array
    {
        return [
            'contract_version' => 'ota_settlement_financial_basis_ledger.v1',
            'metric_scope' => 'ota_channel_settlement',
            'components' => [
                'order_gross_amount' => [
                    'metric_key' => 'gross_amount',
                    ...$totals['gross_amount'],
                ],
                'commission_amount' => [
                    'metric_key' => 'commission_amount',
                    ...$totals['commission_amount'],
                ],
                'refund_amount' => [
                    'metric_key' => 'refund_amount',
                    ...$totals['refund_amount'],
                ],
                'adjustment' => [
                    'metric_key' => 'subsidy_amount',
                    ...$totals['subsidy_amount'],
                    'component_scope' => 'platform_subsidy_only',
                    'generic_adjustment_amount_claimed' => false,
                ],
                'settlement_amount' => [
                    'metric_key' => 'settlement_amount',
                    ...$totals['settlement_amount'],
                ],
                'net_revenue' => [
                    'metric_key' => 'net_revenue',
                    ...$totals['net_revenue'],
                ],
            ],
            'boundaries' => [
                'components_are_interchangeable' => false,
                'generic_adjustment_amount_claimed' => false,
                'settlement_amount_is_net_revenue' => false,
                'whole_hotel_gop_claimed' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $row */
    private function normalizeStoredBatch(array $row): array
    {
        foreach ([
            'id', 'tenant_id', 'hotel_id', 'source_hotel_id', 'line_count', 'available_line_count',
            'partial_line_count', 'invalid_line_count', 'external_write_authorized', 'imported_by',
        ] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['supersedes_batch_id'] = ($row['supersedes_batch_id'] ?? null) === null
            ? null
            : (int)$row['supersedes_batch_id'];
        $row['supersession_reason'] = $this->nullableText($row['supersession_reason'] ?? null);
        foreach ([
            'gross_amount_total', 'commission_amount_total', 'subsidy_amount_total',
            'refund_amount_total', 'settlement_amount_total', 'net_revenue_total',
        ] as $field) {
            $row[$field] = $this->storedMoney($row[$field] ?? null);
        }
        $row['source_evidence_sha256'] = $this->nullableText($row['source_evidence_sha256'] ?? null);
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function normalizeStoredLine(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['batch_id'] = (int)($row['batch_id'] ?? 0);
        $row['source_line_no'] = (int)($row['source_line_no'] ?? 0);
        foreach ([
            'gross_amount', 'commission_amount', 'subsidy_amount', 'refund_amount',
            'settlement_amount', 'net_revenue', 'ota_comparison_amount',
            'pms_comparison_amount', 'discrepancy_amount',
        ] as $field) {
            $row[$field] = $this->storedMoney($row[$field] ?? null);
        }
        foreach ([
            'business_date', 'amount_scope', 'ota_order_ref_sha256', 'pms_stay_ref_sha256',
            'net_revenue_formula', 'comparison_basis',
        ] as $field) {
            $row[$field] = $this->nullableText($row[$field] ?? null);
        }
        $row['gap_codes'] = $this->decodeJson((string)($row['gap_codes_json'] ?? '[]'));
        unset($row['gap_codes_json'], $row['created_at']);
        return $row;
    }

    /**
     * @param array<string,mixed> $batch
     * @param list<array<string,mixed>> $lines
     */
    private function batchFingerprint(array $batch, array $lines): string
    {
        $payload = [];
        foreach ([
            'contract_version', 'tenant_id', 'source_hotel_id', 'platform', 'period_start', 'period_end',
            'file_sha256', 'source_evidence_sha256', 'source_method', 'source_quality_status',
            'parser_version', 'supersedes_batch_id', 'supersession_reason', 'batch_status', 'line_count', 'available_line_count',
            'partial_line_count', 'invalid_line_count', 'gross_amount_total',
            'gross_amount_total_basis', 'commission_amount_total', 'commission_amount_total_basis',
            'subsidy_amount_total', 'subsidy_amount_total_basis', 'refund_amount_total',
            'refund_amount_total_basis', 'settlement_amount_total', 'settlement_amount_total_basis',
            'net_revenue_total', 'net_revenue_total_basis', 'external_write_authorized',
        ] as $field) {
            $payload[$field] = $batch[$field] ?? null;
        }
        $payload['line_fingerprints'] = array_column($lines, 'line_fingerprint');
        return hash('sha256', $this->canonicalJson($payload));
    }

    /** @param array<string,mixed> $line */
    private function lineFingerprint(array $line): string
    {
        $payload = [];
        foreach ([
            'source_line_no', 'source_line_sha256', 'business_date', 'amount_scope',
            'ota_order_ref_sha256', 'pms_stay_ref_sha256', 'gross_amount', 'gross_amount_basis',
            'commission_amount', 'commission_amount_basis', 'subsidy_amount',
            'subsidy_amount_basis', 'refund_amount', 'refund_amount_basis',
            'settlement_amount', 'settlement_amount_basis', 'net_revenue',
            'net_revenue_basis', 'net_revenue_formula', 'match_status',
            'ota_comparison_amount', 'pms_comparison_amount', 'comparison_basis',
            'discrepancy_amount', 'discrepancy_basis', 'quality_status', 'gap_codes',
        ] as $field) {
            $payload[$field] = $line[$field] ?? null;
        }
        return hash('sha256', $this->canonicalJson($payload));
    }

    /**
     * @param list<string> $gaps
     */
    private function lineDate(
        mixed $value,
        string $periodStart,
        string $periodEnd,
        array &$gaps,
        bool &$invalid
    ): ?string {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            $gaps[] = 'business_date_missing';
            $invalid = true;
            return null;
        }
        try {
            $date = $this->date($text);
        } catch (InvalidArgumentException) {
            $gaps[] = 'business_date_invalid';
            $invalid = true;
            return null;
        }
        if ($date < $periodStart || $date > $periodEnd) {
            $gaps[] = 'business_date_outside_period';
            $invalid = true;
        }
        return $date;
    }

    /**
     * @param list<string> $gaps
     */
    private function referenceHash(
        mixed $value,
        string $namespace,
        string $field,
        array &$gaps,
        bool &$invalid
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            $gaps[] = $field . '_invalid';
            $invalid = true;
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text, 'UTF-8') > 512) {
            $gaps[] = $field . '_invalid';
            $invalid = true;
            return null;
        }
        return hash('sha256', $namespace . '|' . $text);
    }

    /** @return array{0:?float,1:bool} */
    private function optionalMoney(mixed $value, bool $nonNegative): array
    {
        if ($value === null || $value === '') {
            return [null, false];
        }
        if (is_bool($value)
            || (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value)))
        ) {
            return [null, true];
        }
        $number = (float)$value;
        if (!is_finite($number) || ($nonNegative && $number < 0)) {
            return [null, true];
        }
        return [$this->money($number), false];
    }

    private function storedMoney(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : $this->money((float)$value);
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }

    private function sha256(string $value, bool $nullable): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' && $nullable) {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('ota_settlement_sha256_invalid');
        }
        return $value;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new DateTimeZone('Asia/Shanghai')
        );
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('ota_settlement_date_invalid');
        }
        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }

    /** @return list<string> */
    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('ota_settlement_gap_codes_readback_invalid');
        }
        return array_values(array_map('strval', $decoded));
    }

    private function canonicalJson(mixed $value): string
    {
        $encoded = json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        );
        return $encoded;
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
                || str_contains($message, 'unique constraint failed')
                || str_contains($message, 'integrity constraint violation: 1062')
            ) {
                return true;
            }
        }
        return false;
    }
}
