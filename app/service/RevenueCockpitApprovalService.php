<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turns the exact visible revenue-cockpit scope into one persisted pending
 * approval. It rebuilds evidence from the server overview and deliberately
 * stops before approval, task creation, OTA writes, collection, or messaging.
 */
final class RevenueCockpitApprovalService
{
    /** @var Closure(int,int,string,int,array<int,array<string,mixed>>):array<string,mixed> */
    private Closure $creator;

    public function __construct(?callable $creator = null)
    {
        $this->creator = $creator !== null
            ? Closure::fromCallable($creator)
            : static fn(
                int $tenantId,
                int $hotelId,
                string $businessDate,
                int $actorId,
                array $evidenceRefs
            ): array => (new OperatingApprovalIntentService())->createPendingApproval(
                $tenantId,
                $hotelId,
                $businessDate,
                $actorId,
                $evidenceRefs
            );
    }

    /** @return array<string,mixed> */
    public function createFromOverview(
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform,
        int $actorId
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('revenue_cockpit_approval_scope_invalid');
        }
        $businessDate = $this->date($businessDate);
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('revenue_cockpit_approval_platform_invalid');
        }

        $factLayer = is_array($overview['three_source_fact_layer'] ?? null)
            ? $overview['three_source_fact_layer']
            : [];
        $hotel = is_array($factLayer['hotel'] ?? null) ? $factLayer['hotel'] : [];
        if ((int)($overview['hotel_id'] ?? $hotel['system_hotel_id'] ?? 0) !== $hotelId
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($overview['business_date'] ?? '') !== $businessDate
            || (string)($factLayer['business_date'] ?? '') !== $businessDate
        ) {
            throw new RuntimeException('revenue_cockpit_approval_overview_scope_mismatch', 422);
        }
        $sources = is_array($factLayer['sources'] ?? null) ? $factLayer['sources'] : [];
        $strictEvidence = is_array($overview['cockpit_strict_evidence'] ?? null)
            ? $overview['cockpit_strict_evidence']
            : [];
        if ((string)($strictEvidence['contract_version'] ?? '') !== 'revenue_cockpit_strict_evidence.v1'
            || (int)($strictEvidence['tenant_id'] ?? 0) !== $tenantId
            || (int)($strictEvidence['hotel_id'] ?? 0) !== $hotelId
            || (string)($strictEvidence['business_date'] ?? '') !== $businessDate
        ) {
            throw new RuntimeException('revenue_cockpit_strict_evidence_missing', 422);
        }
        $strictPlatforms = is_array($strictEvidence['platforms'] ?? null)
            ? $strictEvidence['platforms']
            : [];
        $selectedPlatforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        $refs = [];
        foreach ($selectedPlatforms as $selectedPlatform) {
            $sourceKey = $selectedPlatform . '_ota';
            $source = is_array($sources[$sourceKey] ?? null) ? $sources[$sourceKey] : [];
            $provenance = is_array($source['source'] ?? null) ? $source['source'] : [];
            $strictPlatform = is_array($strictPlatforms[$selectedPlatform] ?? null)
                ? $strictPlatforms[$selectedPlatform]
                : [];
            $rowIds = $this->positiveIds($strictPlatform['accepted_row_ids'] ?? []);
            if ((string)($source['data_status'] ?? '') !== 'readback_verified'
                || (string)($source['business_date'] ?? '') !== $businessDate
                || (string)($source['actual_business_date'] ?? '') !== $businessDate
                || (string)($provenance['table'] ?? '') !== 'online_daily_data'
                || (string)($provenance['data_date'] ?? '') !== $businessDate
                || (string)($provenance['platform'] ?? '') !== $selectedPlatform
                || (string)($provenance['readback_status'] ?? '') !== 'readback_verified'
                || ($strictPlatform['source_strict_readback'] ?? false) !== true
                || $rowIds === []
            ) {
                throw new RuntimeException(
                    'revenue_cockpit_' . $selectedPlatform . '_evidence_not_readback_verified',
                    422
                );
            }
            $refs[] = [
                'role' => 'supporting_fact',
                'source_kind' => 'formal_record',
                'table' => 'online_daily_data',
                'row_ids' => $rowIds,
                'platform' => $selectedPlatform,
                'business_date' => $businessDate,
                'fact_scope' => 'ota_channel',
                'readback_verified' => true,
                'verification_status' => 'readback_verified',
            ];
        }

        $pms = is_array($sources['dingdandao_pms'] ?? null) ? $sources['dingdandao_pms'] : [];
        $pmsProvenance = is_array($pms['source'] ?? null) ? $pms['source'] : [];
        $pmsRecordId = (int)($pmsProvenance['record_id'] ?? 0);
        if ((string)($pms['data_status'] ?? '') === 'readback_verified'
            && (string)($pms['business_date'] ?? '') === $businessDate
            && (string)($pms['actual_business_date'] ?? '') === $businessDate
            && (string)($pmsProvenance['table'] ?? '') === 'dingdandao_operating_target_captures'
            && (string)($pmsProvenance['data_date'] ?? '') === $businessDate
            && (string)($pmsProvenance['readback_status'] ?? '') === 'readback_verified'
            && $pmsRecordId > 0
        ) {
            $refs[] = [
                'role' => 'supporting_fact',
                'source_kind' => 'formal_record',
                'table' => 'dingdandao_operating_target_captures',
                'row_ids' => [$pmsRecordId],
                'platform' => 'dingdandao_pms',
                'business_date' => $businessDate,
                'fact_scope' => 'whole_hotel_accommodation',
                'readback_verified' => true,
                'verification_status' => 'readback_verified',
            ];
        }

        $payload = ($this->creator)(
            $tenantId,
            $hotelId,
            $businessDate,
            $actorId,
            $refs
        );
        if ((string)($payload['status'] ?? '') !== 'pending_approval'
            || (string)($payload['persistence_status'] ?? '') !== 'readback_verified'
            || ($payload['execution_task_created'] ?? true) !== false
            || ($payload['external_action_triggered'] ?? true) !== false
        ) {
            throw new RuntimeException('revenue_cockpit_approval_readback_invalid');
        }
        $payload['cockpit_scope'] = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'platform' => $platform,
            'source_scope' => 'pms_whole_hotel_accommodation_plus_selected_ota_channels',
            'evidence_ref_count' => count($refs),
        ];
        $payload['boundaries'] = [
            'human_approval_required' => true,
            'automatic_collection' => false,
            'automatic_approval' => false,
            'automatic_execution' => false,
            'operation_task_created' => false,
            'ota_write' => false,
            'external_message' => false,
        ];
        return $payload;
    }

    /** @return list<int> */
    private function positiveIds(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('revenue_cockpit_approval_business_date_invalid');
        }
        return $value;
    }
}
