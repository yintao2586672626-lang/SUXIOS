<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Promotes one verified Dingdandao capture into the daily operating-target
 * record without changing the user-authored target amount.
 */
final class DingdandaoOperatingTargetSyncService
{
    public function __construct(
        private readonly ?DingdandaoOperatingTargetCaptureService $captures = null,
        private readonly ?OperatingTargetService $targets = null
    ) {
    }

    /** @return array<string,mixed> */
    public function syncVerifiedCapture(
        int $tenantId,
        int $hotelId,
        int $userId,
        int $captureId
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0 || $captureId <= 0) {
            throw new \InvalidArgumentException('dingdandao_target_sync_scope_invalid');
        }

        $capture = ($this->captures ?? new DingdandaoOperatingTargetCaptureService())
            ->read($tenantId, $hotelId, $captureId);
        $this->assertVerifiedCapture($capture, $tenantId, $hotelId, $captureId);

        $targetDate = (string)$capture['business_date'];
        $targetService = $this->targets ?? new OperatingTargetService();
        $before = $targetService->current($tenantId, $hotelId, $targetDate);
        $existingRecord = is_array($before['record'] ?? null) ? $before['record'] : null;
        $existingFacts = is_array($existingRecord['facts'] ?? null)
            ? $existingRecord['facts']
            : [];
        $targetRevenue = $existingFacts['target_revenue'] ?? null;
        $existingScope = trim((string)($existingFacts['fact_scope'] ?? ''));
        if ($targetRevenue !== null && $existingScope !== 'accommodation_room_fee') {
            throw new \RuntimeException('dingdandao_target_scope_mismatch');
        }

        $sourceReference = '订单来了住宿数据中心 / capture:' . $captureId;
        $input = [
            'target_date' => $targetDate,
            'target_revenue' => $targetRevenue,
            'actual_revenue' => $capture['summary']['total_room_fee'],
            'sold_room_nights' => $capture['summary']['sold_room_nights'],
            'sellable_room_nights' => $capture['summary']['derived_sellable_room_nights'],
            'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'pms',
            'source_reference' => $sourceReference,
            'quality_status' => 'verified',
            'quality_reason' => DingdandaoOperatingTargetCaptureService::RENDER_SCOPE_NOTE
                . ' 已通过门店身份、日期、汇总/明细对账和数据库回读校验。',
            'fact_captured_at' => $capture['captured_at'],
            'change_reason' => '订单来了今日住宿经营事实自动同步，capture_id=' . $captureId,
        ];

        if ($existingRecord !== null
            && $this->sameFacts($existingFacts, $input)
        ) {
            return $this->result($existingRecord, $captureId, 'idempotent');
        }

        return Db::transaction(function () use (
            $targetService,
            $tenantId,
            $hotelId,
            $userId,
            $targetDate,
            $captureId,
            $input
        ): array {
            $saved = $targetService->save($tenantId, $hotelId, $userId, $input);
            $readback = $targetService->current($tenantId, $hotelId, $targetDate);
            $record = is_array($readback['record'] ?? null) ? $readback['record'] : null;
            if ($record === null
                || (int)($record['tenant_id'] ?? 0) !== $tenantId
                || (int)($record['hotel_id'] ?? 0) !== $hotelId
                || (string)($record['target_date'] ?? '') !== $targetDate
                || !$this->sameFacts((array)($record['facts'] ?? []), $input)
                || (int)($record['revision_no'] ?? 0) !== (int)($saved['revision_no'] ?? 0)
            ) {
                throw new \RuntimeException('dingdandao_target_sync_readback_failed');
            }

            return $this->result(
                $record,
                $captureId,
                ($saved['revision_no'] ?? 0) === 1 ? 'created' : 'updated'
            );
        });
    }

    /** @param array<string,mixed> $capture */
    private function assertVerifiedCapture(
        array $capture,
        int $tenantId,
        int $hotelId,
        int $captureId
    ): void {
        if ((int)($capture['id'] ?? 0) !== $captureId
            || (int)($capture['tenant_id'] ?? 0) !== $tenantId
            || (int)($capture['hotel_id'] ?? 0) !== $hotelId
            || ($capture['provider'] ?? '') !== DingdandaoOperatingTargetCaptureService::PROVIDER
            || ($capture['capture_status'] ?? '') !== 'verified'
            || ($capture['quality_status'] ?? '') !== 'verified'
            || ($capture['identity_status'] ?? '') !== 'matched'
            || ($capture['reconciliation_status'] ?? '') !== 'matched'
            || ($capture['readback_status'] ?? '') !== 'readback_verified'
            || !is_array($capture['summary'] ?? null)
            || ($capture['summary']['total_room_fee'] ?? null) === null
            || ($capture['summary']['sold_room_nights'] ?? null) === null
            || ($capture['summary']['derived_sellable_room_nights'] ?? null) === null
        ) {
            throw new \RuntimeException('dingdandao_target_sync_capture_not_verified');
        }
    }

    /** @param array<string,mixed> $facts @param array<string,mixed> $input */
    private function sameFacts(array $facts, array $input): bool
    {
        return $this->sameNumber($facts['target_revenue'] ?? null, $input['target_revenue'])
            && $this->sameNumber($facts['actual_revenue'] ?? null, $input['actual_revenue'])
            && $this->sameNumber($facts['sold_room_nights'] ?? null, $input['sold_room_nights'])
            && $this->sameNumber($facts['sellable_room_nights'] ?? null, $input['sellable_room_nights'])
            && (string)($facts['fact_scope'] ?? '') === (string)$input['fact_scope']
            && (string)($facts['source_type'] ?? '') === (string)$input['source_type']
            && (string)($facts['source_reference'] ?? '') === (string)$input['source_reference']
            && (string)($facts['quality_status'] ?? '') === (string)$input['quality_status']
            && (string)($facts['fact_captured_at'] ?? '') === (string)$input['fact_captured_at'];
    }

    private function sameNumber(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }
        return abs((float)$left - (float)$right) < 0.005;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function result(array $record, int $captureId, string $syncStatus): array
    {
        $calculation = is_array($record['calculation'] ?? null)
            ? $record['calculation']
            : [];
        $status = (string)($calculation['status'] ?? 'partial');
        return [
            'status' => $status,
            'sync_status' => $syncStatus,
            'capture_id' => $captureId,
            'record_id' => (int)($record['id'] ?? 0),
            'revision_no' => (int)($record['revision_no'] ?? 0),
            'target_date' => (string)($record['target_date'] ?? ''),
            'fact_scope' => (string)($record['facts']['fact_scope'] ?? ''),
            'source_type' => (string)($record['facts']['source_type'] ?? ''),
            'source_reference' => (string)($record['facts']['source_reference'] ?? ''),
            'quality_status' => (string)($record['facts']['quality_status'] ?? ''),
            'send_eligible' => $status === 'ready',
            'gaps' => is_array($calculation['gaps'] ?? null)
                ? $calculation['gaps']
                : [],
        ];
    }
}
