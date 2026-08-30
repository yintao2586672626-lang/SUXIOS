<?php
declare(strict_types=1);

namespace app\service;

/** Builds the only preference context accepted by AI-facing services. */
final class UserPreferenceContextService
{
    public function __construct(
        private readonly UserLearningMemoryService $memory = new UserLearningMemoryService()
    ) {
    }

    /** @return array<string,mixed> */
    public function build(int $tenantId, int $userId, ?int $hotelId): array
    {
        if ($tenantId <= 0 || $userId <= 0 || ($hotelId !== null && $hotelId <= 0)) {
            return $this->unavailable('invalid_authenticated_scope');
        }
        $global = $this->memory->listPreferences(
            $tenantId,
            $userId,
            'global',
            null,
            null,
            false,
            false
        );
        if (($global['status'] ?? '') === 'migration_required') {
            return $this->unavailable('migration_required', true);
        }
        $hotel = $hotelId !== null
            ? $this->memory->listPreferences(
                $tenantId,
                $userId,
                'hotel',
                $hotelId,
                null,
                false,
                false
            )
            : ['status' => 'not_applicable', 'items' => []];
        if (($hotel['status'] ?? '') === 'migration_required') {
            return $this->unavailable('migration_required', true);
        }

        $byKey = [];
        foreach ([
            ...(is_array($global['items'] ?? null) ? $global['items'] : []),
            ...(is_array($hotel['items'] ?? null) ? $hotel['items'] : []),
        ] as $item) {
            if (!is_array($item) || ($item['consumable'] ?? false) !== true) {
                continue;
            }
            $key = (string)($item['preference_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $item;
            }
        }
        return [
            'contract_version' => 'user_preference_context.v1',
            'status' => 'ready',
            'source' => 'server_exact_readback_only',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hotel_id' => $hotelId,
            'count' => count($byKey),
            'items' => array_values($byKey),
            'client_preference_context_accepted' => false,
            'fact_changed' => false,
            'permission_changed' => false,
            'approval_changed' => false,
            'external_write_authorized' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason, bool $migrationRequired = false): array
    {
        return [
            'contract_version' => 'user_preference_context.v1',
            'status' => $migrationRequired ? 'migration_required' : 'unavailable',
            'reason_code' => $reason,
            'count' => 0,
            'items' => [],
            'client_preference_context_accepted' => false,
            'fact_changed' => false,
            'permission_changed' => false,
            'approval_changed' => false,
            'external_write_authorized' => false,
        ];
    }
}
