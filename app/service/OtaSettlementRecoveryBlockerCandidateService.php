<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/**
 * Selects at most one read-only recovery candidate from an exact OTA
 * settlement readback. It never creates an execution intent or performs an
 * OTA, PMS, accounting, approval, messaging, or credential action.
 */
final class OtaSettlementRecoveryBlockerCandidateService
{
    public const CONTRACT_VERSION = 'ota_settlement_recovery_blocker_candidate.v1';

    /** @param array<string,mixed> $readback @return array<string,mixed> */
    public function build(array $readback): array
    {
        $scope = $this->scope((array)($readback['scope'] ?? []));
        $candidate = null;

        if ((string)($readback['read_status'] ?? '') === 'missing') {
            $candidate = $this->candidate(
                $scope,
                $readback,
                'settlement_export_missing',
                '补齐当前酒店、平台与账期的结算导出证据',
                'import_same_scope_settlement_export',
                null,
                [],
                null
            );
        } elseif (($readback['readback_verified'] ?? false) !== true) {
            $candidate = $this->candidate(
                $scope,
                $readback,
                'settlement_readback_unverified',
                '核验当前结算批次的精确保存回读',
                'verify_exact_settlement_readback',
                null,
                [],
                null
            );
        } else {
            $candidate = $this->fromLines($scope, $readback);
            if ($candidate === null) {
                $sourceQuality = (string)($readback['source']['source_quality_status'] ?? '');
                if ($sourceQuality !== 'verified_export' && $sourceQuality !== 'synthetic_test_only') {
                    $candidate = $this->candidate(
                        $scope,
                        $readback,
                        'settlement_source_quality_review_required',
                        '核验结算导出文件的来源身份与账期范围',
                        'verify_settlement_export_source_identity',
                        null,
                        ['source_quality_status:' . ($sourceQuality !== '' ? $sourceQuality : 'missing')],
                        null
                    );
                }
            }
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $candidate === null ? 'ready' : 'blocked',
            'scope' => $scope,
            'candidate_count' => $candidate === null ? 0 : 1,
            'selected_count' => $candidate === null ? 0 : 1,
            'selected' => $candidate,
            'selection_policy' => [
                'maximum_selected' => 1,
                'order' => [
                    'invalid_line',
                    'net_revenue_basis_gap',
                    'ranked_reconciliation_discrepancy',
                    'partial_line_gap',
                    'source_quality_gap',
                ],
                'purpose' => 'recovery_attention_only',
            ],
            'boundaries' => $this->boundaries(),
        ];
    }

    /**
     * @param array<string,int|string> $scope
     * @param array<string,mixed> $readback
     * @return array<string,mixed>|null
     */
    private function fromLines(array $scope, array $readback): ?array
    {
        $lines = array_values(array_filter(
            (array)($readback['lines'] ?? []),
            static fn(mixed $line): bool => is_array($line)
        ));

        foreach ($lines as $line) {
            if ((string)($line['quality_status'] ?? '') !== 'invalid') {
                continue;
            }
            return $this->candidate(
                $scope,
                $readback,
                'settlement_line_invalid',
                '修复结算导出中的无效行后重新导入同一账期',
                'repair_invalid_settlement_line_and_reimport',
                $line,
                $this->gapCodes($line),
                null
            );
        }

        foreach ($lines as $line) {
            $gaps = $this->gapCodes($line);
            if ((string)($line['quality_status'] ?? '') !== 'partial'
                || (string)($line['net_revenue_basis'] ?? '') !== 'missing'
            ) {
                continue;
            }
            return $this->candidate(
                $scope,
                $readback,
                'net_revenue_basis_missing',
                '补齐可证明的渠道净收入口径；结算金额不得代替净收入',
                'complete_net_revenue_basis_evidence',
                $line,
                $gaps,
                null
            );
        }

        $ranked = array_values(array_filter(
            (array)($readback['ranked_discrepancies'] ?? []),
            static fn(mixed $line): bool => is_array($line)
        ));
        if ($ranked !== []) {
            $line = $ranked[0];
            $amount = isset($line['discrepancy_amount']) && is_numeric($line['discrepancy_amount'])
                ? (float)$line['discrepancy_amount']
                : null;
            return $this->candidate(
                $scope,
                $readback,
                'settlement_reconciliation_discrepancy',
                '复核当前账期金额差异最大的结算行',
                'review_top_settlement_discrepancy',
                $line,
                $this->gapCodes($line),
                $amount
            );
        }

        foreach ($lines as $line) {
            if ((string)($line['quality_status'] ?? '') !== 'partial') {
                continue;
            }
            return $this->candidate(
                $scope,
                $readback,
                'settlement_fact_gap',
                '补齐当前结算行缺失的同范围事实或匹配证据',
                'complete_same_scope_settlement_fact',
                $line,
                $this->gapCodes($line),
                null
            );
        }

        return null;
    }

    /**
     * @param array<string,int|string> $scope
     * @param array<string,mixed> $readback
     * @param array<string,mixed>|null $line
     * @param list<string> $gapCodes
     * @return array<string,mixed>
     */
    private function candidate(
        array $scope,
        array $readback,
        string $reasonCode,
        string $title,
        string $nextActionCode,
        ?array $line,
        array $gapCodes,
        ?float $discrepancyAmount
    ): array {
        $batchId = isset($readback['batch_id']) && is_numeric($readback['batch_id'])
            ? (int)$readback['batch_id']
            : null;
        $batchFingerprint = $this->sha256OrNull($readback['batch_fingerprint'] ?? null);
        $sourceLineNo = isset($line['source_line_no']) && is_numeric($line['source_line_no'])
            ? (int)$line['source_line_no']
            : null;
        $sourceLineSha256 = $this->sha256OrNull($line['source_line_sha256'] ?? null);
        $lineFingerprint = $this->sha256OrNull($line['line_fingerprint'] ?? null);
        $discrepancyBasis = $line === null
            ? null
            : $this->textOrNull($line['discrepancy_basis'] ?? null);
        sort($gapCodes, SORT_STRING);

        $identity = [
            'scope' => $scope,
            'batch_id' => $batchId,
            'batch_fingerprint' => $batchFingerprint,
            'source_line_no' => $sourceLineNo,
            'source_line_sha256' => $sourceLineSha256,
            'reason_code' => $reasonCode,
        ];

        return [
            'candidate_id' => 'settlement-recovery-' . substr(hash(
                'sha256',
                json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            ), 0, 24),
            'candidate_type' => 'settlement_reconciliation_recovery',
            'status' => 'blocked',
            'reason_code' => $reasonCode,
            'title' => $title,
            'scope' => $scope,
            'batch_id' => $batchId,
            'source_line_no' => $sourceLineNo,
            'gap_codes' => array_values(array_unique($gapCodes)),
            'financial_impact' => [
                'status' => $discrepancyAmount === null ? 'not_calculable' : 'exact_readback_discrepancy',
                'metric_key' => $discrepancyAmount === null ? null : 'reconciliation_absolute_difference',
                'value' => $discrepancyAmount,
                'unit' => $discrepancyAmount === null ? null : 'CNY',
                'basis' => $discrepancyAmount === null ? 'missing' : $discrepancyBasis,
                'is_loss_claim' => false,
                'is_net_revenue_claim' => false,
            ],
            'evidence_refs' => [
                'batch_fingerprint' => $batchFingerprint,
                'source_line_sha256' => $sourceLineSha256,
                'line_fingerprint' => $lineFingerprint,
            ],
            'next_action_code' => $nextActionCode,
            'required_evidence' => $this->requiredEvidence($reasonCode),
            'execution' => [
                'mode' => 'instruction_only',
                'human_review_required' => true,
                'execution_intent_created' => false,
                'task_created' => false,
            ],
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @return list<string> */
    private function requiredEvidence(string $reasonCode): array
    {
        return match ($reasonCode) {
            'settlement_export_missing' => [
                'same_tenant_hotel_platform_period_export',
                'pii_free_parser_readback',
            ],
            'settlement_readback_unverified' => [
                'exact_batch_fingerprint_readback',
                'exact_line_fingerprint_readback',
            ],
            'settlement_line_invalid' => [
                'corrected_same_scope_export_line',
                'new_append_only_batch_readback',
            ],
            'net_revenue_basis_missing' => [
                'source_direct_net_revenue_or_aligned_gross_commission_basis',
                'refund_and_subsidy_treatment_evidence',
            ],
            'settlement_reconciliation_discrepancy' => [
                'same_basis_ota_amount',
                'same_basis_pms_amount',
                'human_reconciliation_note',
            ],
            'settlement_source_quality_review_required' => [
                'source_identity_attestation',
                'same_scope_period_confirmation',
            ],
            default => [
                'same_scope_missing_fact',
                'new_append_only_batch_readback',
            ],
        };
    }

    /** @param array<string,mixed> $line @return list<string> */
    private function gapCodes(array $line): array
    {
        return array_values(array_filter(array_map(
            static fn(mixed $gap): string => trim((string)$gap),
            (array)($line['gap_codes'] ?? [])
        ), static fn(string $gap): bool => $gap !== ''));
    }

    /** @param array<string,mixed> $scope @return array<string,int|string> */
    private function scope(array $scope): array
    {
        $tenantId = (int)($scope['tenant_id'] ?? 0);
        $hotelId = (int)($scope['hotel_id'] ?? 0);
        $sourceHotelId = (int)($scope['source_hotel_id'] ?? $hotelId);
        $platform = strtolower(trim((string)($scope['platform'] ?? '')));
        $periodStart = trim((string)($scope['period_start'] ?? ''));
        $periodEnd = trim((string)($scope['period_end'] ?? ''));
        if ($tenantId <= 0 || $hotelId <= 0 || $sourceHotelId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || !$this->isDate($periodStart)
            || !$this->isDate($periodEnd)
            || $periodStart > $periodEnd
        ) {
            throw new InvalidArgumentException('ota_settlement_recovery_scope_invalid');
        }
        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_hotel_id' => $sourceHotelId,
            'platform' => $platform,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /** @return array<string,bool|int> */
    private function boundaries(): array
    {
        return [
            'maximum_candidates' => 1,
            'read_only' => true,
            'pii_included' => false,
            'settlement_amount_is_net_revenue' => false,
            'whole_hotel_gop_claimed' => false,
            'causality_claimed' => false,
            'automatic_approval' => false,
            'automatic_external_send' => false,
            'automatic_ota_write' => false,
            'automatic_pms_write' => false,
            'automatic_accounting_write' => false,
            'external_write_count' => 0,
        ];
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function sha256OrNull(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
    }

    private function textOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
