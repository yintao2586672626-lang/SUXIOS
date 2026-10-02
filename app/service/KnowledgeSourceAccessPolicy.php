<?php
declare(strict_types=1);

namespace app\service;

/** Read access only; sharing a formal source never grants modification rights. */
final class KnowledgeSourceAccessPolicy
{
    public const FORMAL_SOURCE = 'formal_operating_sop';

    public function isGlobalSystemUnit(array $unit): bool
    {
        return (int)($unit['hotel_id'] ?? 0) === 0 && (int)($unit['created_by'] ?? 0) === 0
            && ($unit['status'] ?? '') === 'done';
    }

    public function isSharedFormalUnit(array $unit): bool
    {
        return (int)($unit['hotel_id'] ?? 0) > 0 && ($unit['source'] ?? '') === self::FORMAL_SOURCE
            && ($unit['status'] ?? '') === 'done';
    }

    public function canReadUnit(array $unit, int $actorId, array $permittedHotelIds, int $tenantId = 0, bool $superAdmin = false): bool
    {
        if ($superAdmin) return true;
        if ((int)($unit['tenant_id'] ?? 0) > 0 && (int)$unit['tenant_id'] !== $tenantId) return false;
        if ($this->isGlobalSystemUnit($unit)) return true;
        $hotelId = (int)($unit['hotel_id'] ?? 0);
        return $hotelId > 0 && in_array($hotelId, $permittedHotelIds, true)
            && ($this->isSharedFormalUnit($unit) || (int)($unit['created_by'] ?? 0) === $actorId);
    }
}
