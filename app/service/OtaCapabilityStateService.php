<?php
declare(strict_types=1);

namespace app\service;

final class OtaCapabilityStateService
{
    private const CAPABILITY_ALIASES = [
        'business' => ['business', 'businessdata'],
        'orders' => ['order', 'orders', 'orderdata'],
        'reviews' => ['review', 'reviews', 'reviewdata'],
    ];

    /**
     * @param array<int, array<string, mixed>> $resourceStatuses
     * @return array<string, string>
     */
    public function evaluate(string $profileStatus, array $resourceStatuses): array
    {
        $profileStatus = $this->status($profileStatus);
        $report = [];

        foreach (self::CAPABILITY_ALIASES as $capability => $aliases) {
            if (in_array($profileStatus, ['permission_denied', 'no_permission', 'unauthorized', 'forbidden'], true)) {
                $report[$capability] = 'permission_denied';
                continue;
            }
            if ($profileStatus !== 'logged_in') {
                $report[$capability] = 'unverified';
                continue;
            }
            $report[$capability] = $this->resourceState($aliases, $resourceStatuses);
        }

        return $report;
    }

    /**
     * @param array<int, string> $aliases
     * @param array<int, array<string, mixed>> $resourceStatuses
     */
    private function resourceState(array $aliases, array $resourceStatuses): string
    {
        $matched = [];
        foreach ($resourceStatuses as $resourceStatus) {
            $resource = $this->status($resourceStatus['resource'] ?? '');
            $dataType = $this->status($resourceStatus['dataType'] ?? $resourceStatus['data_type'] ?? '');
            if (in_array($resource, $aliases, true) || in_array($dataType, $aliases, true)) {
                $matched[] = $resourceStatus;
            }
        }

        if ($matched === []) {
            return 'unverified';
        }

        $states = [];
        foreach ($matched as $resourceStatus) {
            $collectionStatus = $this->status($resourceStatus['collectionStatus'] ?? $resourceStatus['collection_status'] ?? '');
            $etlStatus = $this->status($resourceStatus['etlStatus'] ?? $resourceStatus['etl_status'] ?? '');
            $missingReason = $this->status($resourceStatus['missingReason'] ?? $resourceStatus['missing_reason'] ?? '');

            if (in_array($collectionStatus, ['permission_denied', 'no_permission', 'unauthorized', 'forbidden'], true)
                || $missingReason === 'permission_denied') {
                $states[] = 'permission_denied';
                continue;
            }
            if (in_array($collectionStatus, ['capability_unavailable', 'unsupported'], true)
                || $missingReason === 'capability_unavailable') {
                $states[] = 'capability_unavailable';
                continue;
            }
            if (in_array($collectionStatus, ['failed', 'capture_failed'], true)
                || in_array($etlStatus, ['capture_failed', 'capture_success_not_stored', 'normalized_not_stored', 'not_stored'], true)) {
                $states[] = 'collection_failed';
                continue;
            }
            if ($collectionStatus === 'ready'
                && $etlStatus === 'stored_displayable'
                && $this->nonNegativeInt($resourceStatus['storedRowCount'] ?? $resourceStatus['stored_row_count'] ?? 0) > 0) {
                $states[] = 'verified';
                continue;
            }
            $states[] = 'unverified';
        }

        foreach (['permission_denied', 'capability_unavailable', 'collection_failed', 'verified'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'unverified';
    }

    private function nonNegativeInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    private function status(mixed $value): string
    {
        return strtolower(trim((string)$value));
    }
}
