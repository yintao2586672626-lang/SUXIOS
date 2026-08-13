<?php
declare(strict_types=1);

namespace app\service;

use app\model\OperationLog;
use think\facade\Db;

/**
 * Persists an explicit operator confirmation that the current dual-OTA
 * receipt was rendered and checked on the Online Data page.
 *
 * This evidence is deliberately orthogonal to OTA truth. It can describe a
 * verified, blocked, partial or unverified receipt, but it never promotes the
 * receipt's acceptance_status or claim_allowed value.
 */
final class DualOtaPageVerificationService
{
    public const CONTRACT_VERSION = 'suxios.dual_ota_page_verification.v2';
    public const DESCRIPTION_PREFIX = 'dual_ota_page:v2:';
    public const MODULE = 'online_data';
    public const ACTION = 'confirm_dual_ota_page_verification';

    private const PLATFORMS = ['ctrip', 'meituan'];
    private const MAX_EVIDENCE_ROWS = 200;

    /**
     * Attach the latest exact page-confirmation evidence to every evaluated
     * day. Missing or stale evidence fails closed without changing OTA truth.
     *
     * @param array<string, mixed> $trust
     * @return array<string, mixed>
     */
    public function attach(array $trust, int $tenantId, int $hotelId): array
    {
        if ($tenantId <= 0 || $hotelId <= 0 || (int)($trust['hotel_id'] ?? 0) !== $hotelId) {
            return $trust;
        }

        try {
            $evidenceRows = $this->loadEvidenceRows($tenantId, $hotelId);
            return self::attachEvidenceRows($trust, $tenantId, $hotelId, $evidenceRows);
        } catch (\Throwable) {
            // Page evidence is supplementary, so its failure never changes
            // OTA truth. It must still be visible as unverified instead of
            // being mistaken for a page that has simply not been checked yet.
            return self::markEvidenceUnavailable(
                self::attachEvidenceRows($trust, $tenantId, $hotelId, [])
            );
        }
    }

    /**
     * Persist and exactly read back one explicit page confirmation.
     *
     * @param array<string, mixed> $trust
     * @param array<string, mixed> $clientConfirmation
     * @return array<string, mixed>
     */
    public function confirm(
        array $trust,
        int $tenantId,
        int $hotelId,
        int $userId,
        array $clientConfirmation
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('A positive tenant, hotel and user scope is required.');
        }
        if ((int)($trust['hotel_id'] ?? 0) !== $hotelId) {
            throw new \RuntimeException('The page receipt hotel scope changed. Refresh the page and confirm again.', 409);
        }

        $targetDate = self::assertDate((string)($clientConfirmation['target_date'] ?? ''));
        $contract = self::canonicalContract($trust, $tenantId, $hotelId, $targetDate);
        $contractHash = self::contractHash($contract);
        self::assertClientConfirmation($clientConfirmation, $contract, $contractHash);

        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $userId,
            $targetDate,
            $contract,
            $contractHash
        ): array {
            $hotel = Db::name('hotels')
                ->where('id', $hotelId)
                ->where('tenant_id', $tenantId)
                ->field('id,tenant_id')
                ->lock(true)
                ->find();
            if (!is_array($hotel)) {
                throw new \RuntimeException('The hotel tenant scope changed. Refresh the page and confirm again.', 409);
            }

            $description = self::description($targetDate, $contractHash);
            $existing = $this->findExactEvidenceRow($tenantId, $hotelId, $description);
            if (is_array($existing)) {
                return $this->assertExactReadback($existing, $tenantId, $hotelId, $targetDate, $contractHash);
            }

            $log = OperationLog::record(
                self::MODULE,
                self::ACTION,
                $description,
                $userId,
                $hotelId,
                null,
                [
                    'contract_version' => self::CONTRACT_VERSION,
                    'contract_hash' => $contractHash,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'target_date' => $targetDate,
                    'surface' => 'online_data.dual_ota_continuous_trust',
                    'contract' => $contract,
                    'outcome' => 'success',
                ]
            );
            $logId = (int)($log->id ?? 0);
            if ($logId <= 0) {
                throw new \RuntimeException('The page confirmation was not saved.', 500);
            }

            $readback = $this->findEvidenceRowById($logId, $tenantId, $hotelId, $description);
            if (!is_array($readback)) {
                throw new \RuntimeException('The page confirmation could not be read back exactly.', 500);
            }

            return $this->assertExactReadback($readback, $tenantId, $hotelId, $targetDate, $contractHash);
        });
    }

    /**
     * Pure evidence projector used by the database adapter and focused tests.
     *
     * @param array<string, mixed> $trust
     * @param array<int, array<string, mixed>> $evidenceRows
     * @return array<string, mixed>
     */
    public static function attachEvidenceRows(
        array $trust,
        int $tenantId,
        int $hotelId,
        array $evidenceRows
    ): array {
        $days = is_array($trust['days'] ?? null) ? $trust['days'] : [];
        foreach ($days as $dayIndex => $day) {
            if (!is_array($day)) {
                continue;
            }
            $targetDate = trim((string)($day['date'] ?? ''));
            try {
                $contract = self::canonicalContract($trust, $tenantId, $hotelId, $targetDate);
                $contractHash = self::contractHash($contract);
            } catch (\Throwable) {
                continue;
            }

            $state = self::resolveEvidenceState(
                $evidenceRows,
                $tenantId,
                $hotelId,
                $targetDate,
                $contractHash
            );
            $days[$dayIndex]['page_verification'] = array_merge($state, [
                'contract_version' => self::CONTRACT_VERSION,
                'contract_hash' => $contractHash,
            ]);

            $platforms = is_array($day['platforms'] ?? null) ? $day['platforms'] : [];
            foreach ($platforms as $platformIndex => $platform) {
                if (!is_array($platform)) {
                    continue;
                }
                $receipt = is_array($platform['acceptance_receipt'] ?? null)
                    ? $platform['acceptance_receipt']
                    : [];
                $receipt['live_page_verification_status'] = $state['status'];
                $receipt['live_page_verification_reason'] = $state['reason'];
                $receipt['live_page_contract_hash'] = $contractHash;
                $receipt['live_page_verified_at'] = $state['verified_at'];
                $receipt['live_page_verified_by_user_id'] = $state['verified_by_user_id'];
                $receipt['live_page_verification_receipt_id'] = $state['receipt_id'];
                $platforms[$platformIndex]['acceptance_receipt'] = $receipt;
                $pageStatusEvidence = is_array($platform['page_status_evidence'] ?? null)
                    ? $platform['page_status_evidence']
                    : [];
                $pageStatusEvidence['live_page_verification_status'] = $state['status'];
                $pageStatusEvidence['live_page_verification_reason'] = $state['reason'];
                $pageStatusEvidence['live_page_contract_hash'] = $contractHash;
                $platforms[$platformIndex]['page_status_evidence'] = $pageStatusEvidence;
            }
            $days[$dayIndex]['platforms'] = $platforms;
        }
        $trust['days'] = $days;

        $endDate = trim((string)($trust['end_date'] ?? ''));
        $current = null;
        foreach ($days as $day) {
            if (is_array($day) && (string)($day['date'] ?? '') === $endDate) {
                $current = $day['page_verification'] ?? null;
                break;
            }
        }
        $trust['page_verification'] = is_array($current)
            ? $current
            : [
                'status' => 'not_evaluated',
                'reason' => 'page_confirmation_not_recorded',
                'contract_version' => self::CONTRACT_VERSION,
                'contract_hash' => null,
                'receipt_id' => null,
                'verified_at' => null,
                'verified_by_user_id' => null,
            ];

        return $trust;
    }

    /**
     * Fail closed when the page-confirmation evidence store cannot be read.
     * The exact contract hash remains available so the operator can see which
     * displayed receipt was affected, while every OTA acceptance fact remains
     * untouched.
     *
     * @param array<string, mixed> $trust
     * @return array<string, mixed>
     */
    private static function markEvidenceUnavailable(array $trust): array
    {
        $reason = 'page_confirmation_evidence_unavailable';
        $days = is_array($trust['days'] ?? null) ? $trust['days'] : [];
        foreach ($days as $dayIndex => $day) {
            if (!is_array($day) || !is_array($day['page_verification'] ?? null)) {
                continue;
            }
            $days[$dayIndex]['page_verification'] = array_merge($day['page_verification'], [
                'status' => 'unverified',
                'reason' => $reason,
                'receipt_id' => null,
                'verified_at' => null,
                'verified_by_user_id' => null,
            ]);

            $platforms = is_array($day['platforms'] ?? null) ? $day['platforms'] : [];
            foreach ($platforms as $platformIndex => $platform) {
                if (!is_array($platform)) {
                    continue;
                }
                $receipt = is_array($platform['acceptance_receipt'] ?? null)
                    ? $platform['acceptance_receipt']
                    : [];
                $receipt['live_page_verification_status'] = 'unverified';
                $receipt['live_page_verification_reason'] = $reason;
                $receipt['live_page_verified_at'] = null;
                $receipt['live_page_verified_by_user_id'] = null;
                $receipt['live_page_verification_receipt_id'] = null;
                $platforms[$platformIndex]['acceptance_receipt'] = $receipt;

                $pageStatusEvidence = is_array($platform['page_status_evidence'] ?? null)
                    ? $platform['page_status_evidence']
                    : [];
                $pageStatusEvidence['live_page_verification_status'] = 'unverified';
                $pageStatusEvidence['live_page_verification_reason'] = $reason;
                $platforms[$platformIndex]['page_status_evidence'] = $pageStatusEvidence;
            }
            $days[$dayIndex]['platforms'] = $platforms;
        }
        $trust['days'] = $days;

        $current = is_array($trust['page_verification'] ?? null)
            ? $trust['page_verification']
            : [
                'contract_version' => self::CONTRACT_VERSION,
                'contract_hash' => null,
            ];
        $trust['page_verification'] = array_merge($current, [
            'status' => 'unverified',
            'reason' => $reason,
            'receipt_id' => null,
            'verified_at' => null,
            'verified_by_user_id' => null,
        ]);

        return $trust;
    }

    /**
     * Build a deterministic, safe projection of what the page must display.
     * Raw payloads, configuration, Profile identifiers and credentials are
     * intentionally excluded.
     *
     * @param array<string, mixed> $trust
     * @return array<string, mixed>
     */
    public static function canonicalContract(
        array $trust,
        int $tenantId,
        int $hotelId,
        string $targetDate
    ): array {
        $targetDate = self::assertDate($targetDate);
        if ($tenantId <= 0 || $hotelId <= 0 || (int)($trust['hotel_id'] ?? 0) !== $hotelId) {
            throw new \InvalidArgumentException('The page receipt is missing exact tenant or hotel scope.');
        }

        $targetDay = null;
        foreach (is_array($trust['days'] ?? null) ? $trust['days'] : [] as $day) {
            if (is_array($day) && trim((string)($day['date'] ?? '')) === $targetDate) {
                $targetDay = $day;
                break;
            }
        }
        if (!is_array($targetDay)) {
            throw new \RuntimeException('The target-date page receipt is not available.', 409);
        }

        $platformsByName = [];
        foreach (is_array($targetDay['platforms'] ?? null) ? $targetDay['platforms'] : [] as $platformRow) {
            if (!is_array($platformRow)) {
                continue;
            }
            $platform = strtolower(trim((string)($platformRow['platform'] ?? '')));
            if (in_array($platform, self::PLATFORMS, true)) {
                $platformsByName[$platform] = $platformRow;
            }
        }
        if (array_keys($platformsByName) === []) {
            throw new \RuntimeException('The dual-OTA page receipt has no platform evidence.', 409);
        }

        $platformContracts = [];
        foreach (self::PLATFORMS as $platform) {
            $platformRow = $platformsByName[$platform] ?? null;
            if (!is_array($platformRow)) {
                throw new \RuntimeException('The dual-OTA page receipt is incomplete.', 409);
            }
            $receipt = is_array($platformRow['acceptance_receipt'] ?? null)
                ? $platformRow['acceptance_receipt']
                : [];
            $strategy = is_array($receipt['capture_strategy'] ?? null) ? $receipt['capture_strategy'] : [];
            $readbackScope = is_array($receipt['run_readback_scope'] ?? null)
                ? $receipt['run_readback_scope']
                : [];
            $counts = is_array($receipt['counts'] ?? null) ? $receipt['counts'] : [];
            $fields = is_array($receipt['critical_fields'] ?? null) ? $receipt['critical_fields'] : [];
            if (!is_array($fields['complete'] ?? null) || !is_array($fields['missing'] ?? null)) {
                throw new \RuntimeException('The page receipt is missing critical-field evidence.', 409);
            }
            $failureReason = self::nullableText($platformRow['failure_reason'] ?? null);

            $platformContracts[] = [
                'platform' => $platform,
                'acceptance_status' => self::text($platformRow['acceptance_status'] ?? $receipt['status'] ?? ''),
                'p0_status' => self::nullableText($platformRow['p0_status'] ?? null),
                'missing_metric_keys' => self::sortedStrings($platformRow['missing_metric_keys'] ?? []),
                'failure_reason_digest' => $failureReason === null ? null : hash('sha256', $failureReason),
                'system_hotel_id' => self::nullablePositiveInt($receipt['system_hotel_id'] ?? null),
                'platform_hotel_id' => self::nullableText($receipt['platform_hotel_id'] ?? null),
                'platform_hotel_status' => self::nullableText($receipt['platform_hotel_status'] ?? null),
                'target_date' => self::nullableText($receipt['target_date'] ?? null),
                'observed_target_date' => self::nullableText($receipt['observed_target_date'] ?? null),
                'target_date_status' => self::nullableText($receipt['target_date_status'] ?? null),
                'captured_at' => self::nullableText($receipt['captured_at'] ?? null),
                'source_method' => self::nullableText($receipt['source_method'] ?? null),
                'capture_strategy' => [
                    'selected' => self::nullableText($strategy['selected'] ?? null),
                    'status' => self::nullableText($strategy['status'] ?? null),
                    'response_evidence_type' => self::nullableText($strategy['response_evidence_type'] ?? null),
                ],
                'data_source_id' => self::nullablePositiveInt($receipt['data_source_id'] ?? null),
                'sync_task_id' => self::nullablePositiveInt($receipt['sync_task_id'] ?? null),
                'sync_task_status' => self::nullableText($receipt['sync_task_status'] ?? null),
                'data_period' => self::nullableText($receipt['data_period'] ?? null),
                'run_readback_scope' => [
                    'status' => self::nullableText($readbackScope['status'] ?? null),
                    'data_period' => self::nullableText($readbackScope['data_period'] ?? null),
                    'receipt_row_count' => self::nullableNonNegativeInt($readbackScope['receipt_row_count'] ?? null),
                    'receipt_current_row_count' => self::nullableNonNegativeInt($readbackScope['receipt_current_row_count'] ?? null),
                    'receipt_missing_row_count' => self::nullableNonNegativeInt($readbackScope['receipt_missing_row_count'] ?? null),
                    'receipt_identity_mismatch_count' => self::nullableNonNegativeInt($readbackScope['receipt_identity_mismatch_count'] ?? null),
                    'authoritative_row_count' => self::nullableNonNegativeInt($readbackScope['authoritative_row_count'] ?? null),
                    'mismatched_row_count' => self::nullableNonNegativeInt($readbackScope['mismatched_row_count'] ?? null),
                ],
                'counts' => [
                    'saved' => self::nullableNonNegativeInt($counts['saved'] ?? null),
                    'readback' => self::nullableNonNegativeInt($counts['readback'] ?? null),
                    'saved_readback_match' => self::nullableBool($counts['saved_readback_match'] ?? null),
                    'target_saved' => self::nullableNonNegativeInt($counts['target_saved'] ?? null),
                    'target_readback' => self::nullableNonNegativeInt($counts['target_readback'] ?? null),
                    'target_saved_readback_match' => self::nullableBool($counts['target_saved_readback_match'] ?? null),
                ],
                'critical_fields' => [
                    'complete' => self::sortedStrings($fields['complete']),
                    'missing' => self::sortedStrings($fields['missing']),
                    'status' => self::nullableText($fields['status'] ?? null),
                ],
                'claim_allowed' => ($receipt['claim_allowed'] ?? false) === true,
                'reason_codes' => self::sortedStrings($receipt['reason_codes'] ?? []),
            ];
            if ($platformContracts[array_key_last($platformContracts)]['data_source_id'] === null
                || $platformContracts[array_key_last($platformContracts)]['sync_task_id'] === null
            ) {
                throw new \RuntimeException('The page receipt is missing an exact platform source or task.', 409);
            }
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'hotel_name' => self::text($trust['hotel_name'] ?? ''),
            'target_date' => $targetDate,
            'day_acceptance_status' => self::text($targetDay['acceptance_status'] ?? 'unverified'),
            'platforms' => $platformContracts,
        ];
    }

    /** @param array<string, mixed> $contract */
    public static function contractHash(array $contract): string
    {
        $json = json_encode(
            $contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        return hash('sha256', $json);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function resolveEvidenceState(
        array $rows,
        int $tenantId,
        int $hotelId,
        string $targetDate,
        string $contractHash
    ): array {
        $datePrefix = self::descriptionPrefix($targetDate);
        $sawDateEvidence = false;
        $sawInvalidEvidence = false;

        foreach ($rows as $row) {
            if (!is_array($row)
                || (int)($row['hotel_id'] ?? 0) !== $hotelId
                || self::text($row['module'] ?? '') !== self::MODULE
                || self::text($row['action'] ?? '') !== self::ACTION
            ) {
                continue;
            }
            $description = self::text($row['description'] ?? '');
            $extra = self::decodeExtraData($row['extra_data'] ?? null);
            $extraTenantId = (int)($extra['tenant_id'] ?? 0);
            $extraHotelId = (int)($extra['hotel_id'] ?? 0);
            if ((array_key_exists('tenant_id', $row) && (int)($row['tenant_id'] ?? 0) !== $tenantId)
                || ($extraTenantId > 0 && $extraTenantId !== $tenantId)
                || ($extraHotelId > 0 && $extraHotelId !== $hotelId)
            ) {
                continue;
            }
            $rowDate = self::text($extra['target_date'] ?? '');
            if (!str_starts_with($description, $datePrefix) && $rowDate !== $targetDate) {
                continue;
            }
            $sawDateEvidence = true;
            $evidenceVersion = self::text($extra['contract_version'] ?? '');
            if ($evidenceVersion !== '' && $evidenceVersion !== self::CONTRACT_VERSION) {
                continue;
            }

            $decoded = self::decodeEvidence($row, $tenantId, $hotelId);
            if ($decoded === null) {
                $sawInvalidEvidence = true;
                continue;
            }
            if ($decoded['target_date'] !== $targetDate) {
                $sawInvalidEvidence = true;
                continue;
            }
            if ($decoded['contract_hash'] !== $contractHash) {
                continue;
            }

            return [
                'status' => 'verified',
                'reason' => 'exact_page_confirmation_readback_verified',
                'receipt_id' => $decoded['receipt_id'],
                'verified_at' => $decoded['verified_at'],
                'verified_by_user_id' => $decoded['verified_by_user_id'],
            ];
        }

        return [
            'status' => $sawDateEvidence ? 'unverified' : 'not_evaluated',
            'reason' => $sawDateEvidence
                ? ($sawInvalidEvidence ? 'invalid_page_confirmation_evidence' : 'stale_page_confirmation')
                : 'page_confirmation_not_recorded',
            'receipt_id' => null,
            'verified_at' => null,
            'verified_by_user_id' => null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadEvidenceRows(int $tenantId, int $hotelId): array
    {
        $query = Db::name('operation_logs')
            ->where('hotel_id', $hotelId)
            ->where('module', self::MODULE)
            ->where('action', self::ACTION);
        if ($this->operationLogHasTenantColumn()) {
            $query->where('tenant_id', $tenantId);
        }

        return $query
            ->field($this->operationLogFields())
            ->order('id', 'desc')
            ->limit(self::MAX_EVIDENCE_ROWS)
            ->select()
            ->toArray();
    }

    /** @return array<string, mixed>|null */
    private function findExactEvidenceRow(int $tenantId, int $hotelId, string $description): ?array
    {
        $query = Db::name('operation_logs')
            ->where('hotel_id', $hotelId)
            ->where('module', self::MODULE)
            ->where('action', self::ACTION)
            ->where('description', $description);
        if ($this->operationLogHasTenantColumn()) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->field($this->operationLogFields())->order('id', 'desc')->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findEvidenceRowById(
        int $id,
        int $tenantId,
        int $hotelId,
        string $description
    ): ?array {
        $query = Db::name('operation_logs')
            ->where('id', $id)
            ->where('hotel_id', $hotelId)
            ->where('module', self::MODULE)
            ->where('action', self::ACTION)
            ->where('description', $description);
        if ($this->operationLogHasTenantColumn()) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->field($this->operationLogFields())->find();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function assertExactReadback(
        array $row,
        int $tenantId,
        int $hotelId,
        string $targetDate,
        string $contractHash
    ): array {
        $decoded = self::decodeEvidence($row, $tenantId, $hotelId);
        if ($decoded === null
            || $decoded['target_date'] !== $targetDate
            || $decoded['contract_hash'] !== $contractHash
        ) {
            throw new \RuntimeException('The page confirmation readback did not match the exact receipt.', 500);
        }

        return [
            'status' => 'verified',
            'reason' => 'exact_page_confirmation_readback_verified',
            'contract_version' => self::CONTRACT_VERSION,
            'contract_hash' => $contractHash,
            'target_date' => $targetDate,
            'receipt_id' => $decoded['receipt_id'],
            'verified_at' => $decoded['verified_at'],
            'verified_by_user_id' => $decoded['verified_by_user_id'],
            'readback_verified' => true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function decodeEvidence(array $row, int $tenantId, int $hotelId): ?array
    {
        if ((int)($row['id'] ?? 0) <= 0
            || (int)($row['hotel_id'] ?? 0) !== $hotelId
            || self::text($row['module'] ?? '') !== self::MODULE
            || self::text($row['action'] ?? '') !== self::ACTION
        ) {
            return null;
        }
        if (array_key_exists('tenant_id', $row) && (int)($row['tenant_id'] ?? 0) !== $tenantId) {
            return null;
        }

        $extra = self::decodeExtraData($row['extra_data'] ?? null);
        $contract = is_array($extra['contract'] ?? null) ? $extra['contract'] : null;
        $contractHash = strtolower(self::text($extra['contract_hash'] ?? ''));
        $targetDate = self::text($extra['target_date'] ?? '');
        if (($extra['contract_version'] ?? '') !== self::CONTRACT_VERSION
            || (int)($extra['tenant_id'] ?? 0) !== $tenantId
            || (int)($extra['hotel_id'] ?? 0) !== $hotelId
            || !is_array($contract)
            || !preg_match('/^[a-f0-9]{64}$/D', $contractHash)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $targetDate)
        ) {
            return null;
        }
        try {
            if (self::contractHash($contract) !== $contractHash) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        if (self::text($row['description'] ?? '') !== self::description($targetDate, $contractHash)) {
            return null;
        }

        return [
            'receipt_id' => (int)$row['id'],
            'contract_hash' => $contractHash,
            'target_date' => $targetDate,
            'verified_at' => self::nullableText($row['create_time'] ?? null),
            'verified_by_user_id' => self::nullablePositiveInt($row['user_id'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $contract
     */
    private static function assertClientConfirmation(array $client, array $contract, string $contractHash): void
    {
        $providedHash = strtolower(trim((string)($client['contract_hash'] ?? '')));
        if (!hash_equals($contractHash, $providedHash)) {
            throw new \RuntimeException('The page receipt changed. Refresh the page and confirm again.', 409);
        }

        $providedAnchors = [];
        foreach (is_array($client['platforms'] ?? null) ? $client['platforms'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $platform = strtolower(trim((string)($row['platform'] ?? '')));
            if (!in_array($platform, self::PLATFORMS, true)) {
                continue;
            }
            $providedAnchors[$platform] = [
                'data_source_id' => self::nullablePositiveInt($row['data_source_id'] ?? null),
                'sync_task_id' => self::nullablePositiveInt($row['sync_task_id'] ?? null),
            ];
        }

        $expectedAnchors = [];
        foreach ($contract['platforms'] as $row) {
            $expectedAnchors[(string)$row['platform']] = [
                'data_source_id' => $row['data_source_id'],
                'sync_task_id' => $row['sync_task_id'],
            ];
        }
        foreach (self::PLATFORMS as $platform) {
            if (!isset($providedAnchors[$platform], $expectedAnchors[$platform])
                || $providedAnchors[$platform] !== $expectedAnchors[$platform]
            ) {
                throw new \RuntimeException('The page receipt task or source changed. Refresh the page and confirm again.', 409);
            }
        }
    }

    private function operationLogHasTenantColumn(): bool
    {
        return in_array('tenant_id', array_keys(Db::getFields('operation_logs')), true);
    }

    private function operationLogFields(): string
    {
        $available = array_keys(Db::getFields('operation_logs'));
        $fields = array_values(array_intersect(
            ['id', 'tenant_id', 'user_id', 'hotel_id', 'module', 'action', 'description', 'extra_data', 'create_time'],
            $available
        ));
        if (!in_array('extra_data', $fields, true)) {
            throw new \RuntimeException('The operation log evidence schema is unavailable.');
        }
        return implode(',', $fields);
    }

    /** @return array<string, mixed> */
    private static function decodeExtraData(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function description(string $targetDate, string $contractHash): string
    {
        return self::descriptionPrefix($targetDate) . $contractHash;
    }

    private static function descriptionPrefix(string $targetDate): string
    {
        return self::DESCRIPTION_PREFIX . $targetDate . ':';
    }

    private static function assertDate(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('target_date must use YYYY-MM-DD.');
        }
        return $value;
    }

    private static function text(mixed $value): string
    {
        return trim((string)$value);
    }

    private static function nullableText(mixed $value): ?string
    {
        $value = self::text($value);
        return $value !== '' ? $value : null;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private static function nullableNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $value = (int)$value;
        return $value >= 0 ? $value : null;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** @return array<int, string> */
    private static function sortedStrings(mixed $value): array
    {
        $values = is_array($value) ? $value : [];
        $values = array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            $values
        ), static fn(string $item): bool => $item !== '')));
        sort($values, SORT_STRING);
        return $values;
    }
}
