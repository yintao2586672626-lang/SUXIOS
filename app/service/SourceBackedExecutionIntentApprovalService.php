<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class SourceBackedExecutionIntentApprovalService
{
    private const TABLES = [
        'expansion' => 'expansion_records',
        'opening' => 'opening_projects',
        'transfer_decision' => 'transfer_records',
        'feasibility_report' => 'feasibility_reports',
        'price_suggestion' => 'price_suggestions',
        'operation_alert' => 'operation_alerts',
    ];

    public static function supports(string $sourceModule): bool
    {
        return isset(self::TABLES[$sourceModule]);
    }

    /** @param array<string, mixed> $intent */
    public function assertCurrent(array $intent): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        if (!self::supports($sourceModule) || $sourceRecordId <= 0 || $hotelId <= 0) {
            throw new \InvalidArgumentException('source-backed execution intent identity is invalid');
        }

        $sourceQuery = Db::name(self::TABLES[$sourceModule])->where('id', $sourceRecordId);
        if ($sourceModule !== 'price_suggestion') {
            $sourceQuery->whereNull('deleted_at');
        }
        $sourceRow = $sourceQuery->lock(true)->find();
        $hotelTenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?: 0);
        $this->assertCurrentAgainstLockedRows($intent, is_array($sourceRow) ? $sourceRow : [], $hotelTenantId);
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $sourceRow */
    public function assertCurrentAgainstLockedRows(
        array $intent,
        array $sourceRow,
        int $hotelTenantId
    ): void {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        if (!self::supports($sourceModule)
            || $sourceRecordId <= 0
            || $hotelId <= 0
            || (int)($sourceRow['id'] ?? 0) !== $sourceRecordId
        ) {
            throw new \InvalidArgumentException('source-backed execution intent identity is invalid');
        }
        $intentTenantId = (int)($intent['tenant_id'] ?? 0);
        $sourceTenantId = (int)($sourceRow['tenant_id'] ?? 0);
        if ($hotelTenantId <= 0
            || $intentTenantId <= 0
            || $sourceTenantId <= 0
            || $intentTenantId !== $hotelTenantId
            || $sourceTenantId !== $hotelTenantId
        ) {
            throw new \InvalidArgumentException('source-backed execution record is missing or outside the hotel tenant scope');
        }

        if ($sourceModule === 'price_suggestion') {
            $this->assertCurrentPriceSuggestion($intent, $sourceRow);
            return;
        }
        if ($sourceModule === 'operation_alert') {
            $this->assertCurrentOperationAlert($intent, $sourceRow);
            return;
        }

        $dates = [
            'date_start' => (string)($intent['date_start'] ?? ''),
            'date_end' => (string)($intent['date_end'] ?? ''),
        ];
        $createdBy = (int)($intent['created_by'] ?? 0);
        $currentInput = match ($sourceModule) {
            'expansion' => (new ExpansionService())->buildExecutionIntentInput(
                $sourceRow,
                $hotelId,
                $dates
            ),
            'opening' => (new OpeningService())->currentExecutionIntentInput(
                $sourceRecordId,
                [$hotelId],
                $createdBy,
                true,
                $dates,
                true
            ),
            'transfer_decision' => $this->currentTransferInput($sourceRecordId, $hotelId, $createdBy, $dates),
            'feasibility_report' => $this->currentFeasibilityInput($sourceRecordId, $hotelId, $createdBy, $dates),
        };

        foreach (['source_module', 'source_record_id', 'hotel_id', 'platform', 'object_type', 'action_type'] as $field) {
            if ((string)($currentInput[$field] ?? '') !== (string)($intent[$field] ?? '')) {
                throw new \InvalidArgumentException('source-backed execution identity changed; create a new execution intent');
            }
        }
        $storedEvidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $currentEvidence = is_array($currentInput['evidence'] ?? null) ? $currentInput['evidence'] : [];
        $storedDigest = strtolower(trim((string)($storedEvidence['source_snapshot_digest'] ?? '')));
        $currentDigest = strtolower(trim((string)($currentEvidence['source_snapshot_digest'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $currentDigest) !== 1
            || !hash_equals($storedDigest, $currentDigest)
        ) {
            throw new \InvalidArgumentException('source-backed execution snapshot changed; create a new execution intent');
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $sourceRow */
    private function assertCurrentPriceSuggestion(array $intent, array $sourceRow): void
    {
        $suggestionDate = trim((string)($sourceRow['suggestion_date'] ?? ''));
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $suggestionDate, new \DateTimeZone('Asia/Shanghai'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ((int)($sourceRow['hotel_id'] ?? 0) !== (int)($intent['hotel_id'] ?? 0)
            || (int)($sourceRow['room_type_id'] ?? 0) <= 0
            || (int)($sourceRow['status'] ?? 0) !== \app\model\PriceSuggestion::STATUS_APPROVED
            || (int)($sourceRow['applied_by'] ?? 0) <= 0
            || (float)($sourceRow['current_price'] ?? 0) <= 0
            || (float)($sourceRow['suggested_price'] ?? 0) <= 0
            || $parsedDate === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $parsedDate->format('Y-m-d') !== $suggestionDate
        ) {
            throw new \InvalidArgumentException('approved price suggestion source is incomplete or no longer approved');
        }
        foreach ([
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
        ] as $field => $expected) {
            if (strtolower(trim((string)($intent[$field] ?? ''))) !== $expected) {
                throw new \InvalidArgumentException('price suggestion execution identity changed; create a new execution intent');
            }
        }

        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        (new PriceSuggestionOtaTargetMappingService())->assertCurrent($sourceRow, $intent);
        $expectedTargetPrice = $this->approvedPriceSuggestionTarget($sourceRow);
        if ((float)($currentValue['current_price'] ?? 0) !== (float)$sourceRow['current_price']
            || (int)($currentValue['room_type_id'] ?? 0) !== (int)$sourceRow['room_type_id']
            || (float)($targetValue['target_price'] ?? 0) !== $expectedTargetPrice
            || (float)($targetValue['min_price'] ?? 0) !== (float)($sourceRow['min_price'] ?? 0)
            || (float)($targetValue['max_price'] ?? 0) !== (float)($sourceRow['max_price'] ?? 0)
            || (int)($targetValue['room_type_id'] ?? 0) !== (int)$sourceRow['room_type_id']
            || trim((string)($evidence['source_business_date'] ?? '')) !== $suggestionDate
        ) {
            throw new \InvalidArgumentException('approved price suggestion content changed; create a new execution intent');
        }

        $storedDigest = strtolower(trim((string)($evidence['source_snapshot_digest'] ?? '')));
        $currentDigest = SourceBackedExecutionIntentIdentityService::priceSuggestionSnapshotDigest($sourceRow);
        if (preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
            || !hash_equals($storedDigest, $currentDigest)
        ) {
            throw new \InvalidArgumentException('source-backed execution snapshot changed; create a new execution intent');
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $sourceRow */
    private function assertCurrentOperationAlert(array $intent, array $sourceRow): void
    {
        if ((int)($sourceRow['hotel_id'] ?? 0) !== (int)($intent['hotel_id'] ?? 0)) {
            throw new \InvalidArgumentException('operation alert execution identity changed; create a new execution intent');
        }
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $storedDigest = strtolower(trim((string)($evidence['source_snapshot_digest'] ?? '')));
        $currentDigest = SourceBackedExecutionIntentIdentityService::operationAlertSnapshotDigest($sourceRow);
        if (preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
            || !hash_equals($storedDigest, $currentDigest)
        ) {
            throw new \InvalidArgumentException('operation alert source snapshot changed; create a new execution intent');
        }
    }

    /** @param array<string,mixed> $sourceRow */
    private function approvedPriceSuggestionTarget(array $sourceRow): float
    {
        $factors = $sourceRow['factors'] ?? [];
        if (is_string($factors)) {
            $decoded = json_decode($factors, true);
            $factors = is_array($decoded) ? $decoded : [];
        }
        if (is_array($factors['manual_review'] ?? null)) {
            $review = $factors['manual_review'];
        } else {
            $versions = is_array($factors['manual_review_versions'] ?? null)
                ? array_values($factors['manual_review_versions'])
                : [];
            $review = $versions === [] ? [] : end($versions);
        }
        $approvedPrice = is_array($review) && ($review['action'] ?? '') === 'approve_with_changes'
            ? ($review['approved_price'] ?? null)
            : null;
        if (is_string($approvedPrice)) {
            $approvedPrice = preg_replace('/[^\d.\-]/', '', $approvedPrice) ?? '';
        }
        if ($approvedPrice !== null && $approvedPrice !== '' && is_numeric($approvedPrice)
            && (float)$approvedPrice > 0
        ) {
            return round((float)$approvedPrice, 2);
        }
        return (float)$sourceRow['suggested_price'];
    }

    /** @param array<string, string> $dates @return array<string, mixed> */
    private function currentTransferInput(int $recordId, int $hotelId, int $createdBy, array $dates): array
    {
        $service = new TransferDecisionService();
        return $service->buildExecutionIntentInput(
            $service->detail($recordId, [$hotelId], $createdBy, true),
            $dates
        );
    }

    /** @param array<string, string> $dates @return array<string, mixed> */
    private function currentFeasibilityInput(int $recordId, int $hotelId, int $createdBy, array $dates): array
    {
        $service = new FeasibilityReportService();
        $record = $service->detail($recordId, $createdBy, true);
        if (!is_array($record) || $service->executionHotelId($record) !== $hotelId) {
            throw new \InvalidArgumentException('feasibility report hotel scope changed');
        }
        return $service->buildExecutionIntentInput($record, $hotelId, $dates);
    }
}
