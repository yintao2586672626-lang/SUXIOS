<?php
declare(strict_types=1);

namespace app\service;

/**
 * Runs strict authority verification and canonical history promotion as one
 * bounded scheduler finalization step. Each OTA is verified independently so
 * one blocked platform never causes another platform's proven rows to be
 * promoted under the wrong scope.
 */
final class OtaCanonicalHistoryPromotionCoordinator
{
    /** @var callable(int,string,array<int,string>,string):array<string,mixed> */
    private $verifier;

    /** @var callable(array<string,mixed>,array<string,mixed>,string,int,int):array<string,mixed> */
    private $promoter;

    public function __construct(?callable $verifier = null, ?callable $promoter = null)
    {
        $this->verifier = $verifier ?? static fn(
            int $hotelId,
            string $targetDate,
            array $platforms,
            string $anchorHash
        ): array => (new P0OtaFieldLoopVerifierRunner())->verify(
            $hotelId,
            $targetDate,
            $platforms,
            $anchorHash
        );
        $this->promoter = $promoter ?? static fn(
            array $collectionReceipt,
            array $verifierReceipt,
            string $platform,
            int $expectedTenantId,
            int $expectedHotelId
        ): array => (new OtaCanonicalHistoryPromotionService())->promote(
            $collectionReceipt,
            $verifierReceipt,
            $platform,
            $expectedTenantId,
            $expectedHotelId
        );
    }

    /** @return array<string,mixed> */
    public function finalize(
        array $collectionReceipt,
        int $expectedTenantId,
        int $expectedHotelId
    ): array
    {
        $hotelId = (int)($collectionReceipt['hotel_id'] ?? 0);
        $targetDate = substr(trim((string)($collectionReceipt['target_date'] ?? '')), 0, 10);
        $anchorHash = strtolower(trim((string)($collectionReceipt['collection_anchor_hash'] ?? '')));
        $requiredPlatforms = $this->platforms($collectionReceipt['required_platforms'] ?? []);
        $sourceTaskPlatforms = [];
        foreach (is_array($collectionReceipt['source_tasks'] ?? null)
            ? $collectionReceipt['source_tasks']
            : [] as $task
        ) {
            if (!is_array($task)
                || (int)($task['data_source_id'] ?? 0) <= 0
                || (int)($task['sync_task_id'] ?? 0) <= 0
                || $this->positiveIds($task['row_ids'] ?? []) === []
            ) {
                continue;
            }
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $sourceTaskPlatforms[$platform] = (int)($sourceTaskPlatforms[$platform] ?? 0) + 1;
            }
        }
        if ($expectedTenantId <= 0
            || $expectedHotelId <= 0
            || $hotelId !== $expectedHotelId
            || !$this->validDate($targetDate)
            || preg_match('/^[a-f0-9]{64}$/D', $anchorHash) !== 1
            || $requiredPlatforms === []
            || array_diff($requiredPlatforms, array_keys($sourceTaskPlatforms)) !== []
            || array_filter(
                $sourceTaskPlatforms,
                static fn(int $count, string $platform): bool =>
                    in_array($platform, $requiredPlatforms, true) && $count !== 1,
                ARRAY_FILTER_USE_BOTH
            ) !== []
        ) {
            return $this->blocked(
                'canonical_history_finalization_scope_invalid',
                $hotelId,
                $expectedTenantId,
                $targetDate,
                $requiredPlatforms,
                $anchorHash
            );
        }

        $overallVerifier = $this->verify(
            $hotelId,
            $targetDate,
            $requiredPlatforms,
            $anchorHash
        );
        $platformResults = [];
        $promotedPlatforms = [];
        $blockedPlatforms = [];
        foreach ($requiredPlatforms as $platform) {
            $platformVerifier = count($requiredPlatforms) === 1
                ? $overallVerifier
                : $this->verify($hotelId, $targetDate, [$platform], $anchorHash);
            $promotion = $this->verifierReady($platformVerifier, $hotelId, $targetDate, [$platform], $anchorHash)
                ? $this->promote(
                    $collectionReceipt,
                    $platformVerifier,
                    $platform,
                    $expectedTenantId,
                    $expectedHotelId
                )
                : [
                    'status' => 'blocked',
                    'reason' => 'canonical_history_platform_verifier_not_ready',
                    'promoted_count' => 0,
                    'readback_verified' => false,
                    'sensitive_values_exposed' => false,
                ];
            $promotionReady = strtolower(trim((string)($promotion['status'] ?? ''))) === 'verified'
                && ($promotion['readback_verified'] ?? false) === true
                && (int)($promotion['tenant_id'] ?? 0) === $expectedTenantId
                && (int)($promotion['system_hotel_id'] ?? 0) === $hotelId
                && strtolower(trim((string)($promotion['platform'] ?? ''))) === $platform
                && substr(trim((string)($promotion['target_date'] ?? '')), 0, 10) === $targetDate
                && ($promotion['sensitive_values_exposed'] ?? true) === false;
            if ($promotionReady) {
                $promotedPlatforms[] = $platform;
            } else {
                $blockedPlatforms[] = $platform;
            }
            $platformResults[$platform] = [
                'verifier' => $platformVerifier,
                'promotion' => $promotion,
                'status' => $promotionReady ? 'verified' : 'blocked',
            ];
        }
        sort($promotedPlatforms, SORT_STRING);
        sort($blockedPlatforms, SORT_STRING);
        $overallReady = $this->verifierReady(
            $overallVerifier,
            $hotelId,
            $targetDate,
            $requiredPlatforms,
            $anchorHash
        );
        $complete = $overallReady && $promotedPlatforms === $requiredPlatforms;

        return [
            'schema_version' => 1,
            'status' => $complete ? 'verified' : ($promotedPlatforms !== [] ? 'partial' : 'blocked'),
            'reason' => $complete ? '' : 'canonical_history_platforms_incomplete',
            'hotel_id' => $hotelId,
            'tenant_id' => $expectedTenantId,
            'target_date' => $targetDate,
            'required_platforms' => $requiredPlatforms,
            'promoted_platforms' => $promotedPlatforms,
            'blocked_platforms' => $blockedPlatforms,
            'collection_anchor_hash' => $anchorHash,
            'overall_verifier' => $overallVerifier,
            'platform_results' => $platformResults,
            'canonical_history_complete' => $complete,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<int,string> $platforms @return array<string,mixed> */
    private function verify(
        int $hotelId,
        string $targetDate,
        array $platforms,
        string $anchorHash
    ): array {
        try {
            $result = ($this->verifier)($hotelId, $targetDate, $platforms, $anchorHash);
            return is_array($result) ? $result : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function promote(
        array $collection,
        array $verifier,
        string $platform,
        int $expectedTenantId,
        int $expectedHotelId
    ): array
    {
        try {
            $result = ($this->promoter)(
                $collection,
                $verifier,
                $platform,
                $expectedTenantId,
                $expectedHotelId
            );
            return is_array($result) ? $result : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int,string> $platforms */
    private function verifierReady(
        array $receipt,
        int $hotelId,
        string $targetDate,
        array $platforms,
        string $anchorHash
    ): bool {
        return ($receipt['authority_ready'] ?? false) === true
            && strtolower(trim((string)($receipt['verification_source'] ?? ''))) === 'external_p0_verifier'
            && strtolower(trim((string)($receipt['status'] ?? ''))) === 'passed'
            && (int)($receipt['exit_code'] ?? -1) === 0
            && (int)($receipt['hotel_id'] ?? 0) === $hotelId
            && substr(trim((string)($receipt['target_date'] ?? '')), 0, 10) === $targetDate
            && $this->platforms($receipt['required_platforms'] ?? []) === $platforms
            && $this->platforms($receipt['verified_platforms'] ?? []) === $platforms
            && hash_equals($anchorHash, strtolower(trim((string)($receipt['collection_anchor_hash'] ?? ''))))
            && ($receipt['sensitive_values_exposed'] ?? true) === false;
    }

    /** @return array<string,mixed> */
    private function blocked(
        string $reason,
        int $hotelId,
        int $tenantId,
        string $targetDate,
        array $platforms,
        string $anchorHash
    ): array {
        return [
            'schema_version' => 1,
            'status' => 'blocked',
            'reason' => $reason,
            'hotel_id' => $hotelId > 0 ? $hotelId : null,
            'tenant_id' => $tenantId > 0 ? $tenantId : null,
            'target_date' => $targetDate,
            'required_platforms' => $platforms,
            'promoted_platforms' => [],
            'blocked_platforms' => $platforms,
            'collection_anchor_hash' => preg_match('/^[a-f0-9]{64}$/D', $anchorHash) === 1
                ? $anchorHash
                : '',
            'overall_verifier' => [],
            'platform_results' => [],
            'canonical_history_complete' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<int,string> */
    private function platforms(mixed $value): array
    {
        $platforms = array_values(array_unique(array_filter(array_map(
            static fn(mixed $platform): string => strtolower(trim((string)$platform)),
            is_array($value) ? $value : []
        ), static fn(string $platform): bool => in_array($platform, ['ctrip', 'meituan'], true))));
        sort($platforms, SORT_STRING);
        return $platforms;
    }

    /** @return array<int,int> */
    private function positiveIds(mixed $value): array
    {
        return array_values(array_filter(array_map(
            'intval',
            is_array($value) ? $value : []
        ), static fn(int $id): bool => $id > 0));
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
}
