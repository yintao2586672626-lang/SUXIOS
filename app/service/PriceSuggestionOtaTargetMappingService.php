<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class PriceSuggestionOtaTargetMappingService
{
    public const FACTOR_KEY = 'ota_target_mapping';

    /** @param array<string,mixed> $source @param array<string,mixed> $requested */
    public function confirmedMapping(array $source, array $requested = []): array
    {
        $factors = $this->jsonArray($source['factors'] ?? []);
        $mapping = is_array($factors[self::FACTOR_KEY] ?? null) ? $factors[self::FACTOR_KEY] : [];
        $normalized = [
            'mapping_record_id' => trim((string)($mapping['mapping_record_id'] ?? '')),
            'mapping_version' => trim((string)($mapping['mapping_version'] ?? '')),
            'status' => strtolower(trim((string)($mapping['status'] ?? ''))),
            'tenant_id' => (int)($mapping['tenant_id'] ?? 0),
            'hotel_id' => (int)($mapping['hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($mapping['platform'] ?? ''))),
            'room_type_id' => (int)($mapping['room_type_id'] ?? 0),
            'room_type_key' => trim((string)($mapping['room_type_key'] ?? '')),
            'rate_plan_key' => trim((string)($mapping['rate_plan_key'] ?? '')),
            'confirmed_by' => (int)($mapping['confirmed_by'] ?? 0),
            'confirmed_at' => trim((string)($mapping['confirmed_at'] ?? '')),
        ];
        $storedDigest = strtolower(trim((string)($mapping['mapping_digest'] ?? '')));
        $sourceTenantId = (int)($source['tenant_id'] ?? 0);
        $sourceHotelId = (int)($source['hotel_id'] ?? 0);
        $sourceRoomTypeId = (int)($source['room_type_id'] ?? 0);
        if ($normalized['mapping_record_id'] === ''
            || $normalized['mapping_version'] === ''
            || $normalized['status'] !== 'confirmed'
            || $normalized['platform'] !== 'ctrip'
            || $normalized['tenant_id'] <= 0
            || $normalized['hotel_id'] <= 0
            || $normalized['room_type_id'] <= 0
            || $normalized['room_type_key'] === ''
            || $normalized['rate_plan_key'] === ''
            || $normalized['confirmed_by'] <= 0
            || !$this->validConfirmedAt($normalized['confirmed_at'])
            || $normalized['confirmed_by'] !== (int)($source['applied_by'] ?? 0)
            || $normalized['tenant_id'] !== $sourceTenantId
            || $normalized['hotel_id'] !== $sourceHotelId
            || $normalized['room_type_id'] !== $sourceRoomTypeId
        ) {
            throw new \InvalidArgumentException(
                'price suggestion requires a confirmed OTA target mapping bound to its tenant, hotel, platform, and room type'
            );
        }
        $computedDigest = self::mappingDigest($normalized);
        if (preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
            || !hash_equals($storedDigest, $computedDigest)
        ) {
            throw new \InvalidArgumentException('price suggestion OTA target mapping digest is invalid');
        }
        foreach (['room_type_key', 'rate_plan_key'] as $field) {
            $requestedValue = trim((string)($requested[$field] ?? ''));
            if ($requestedValue !== '' && !hash_equals($normalized[$field], $requestedValue)) {
                throw new \InvalidArgumentException('requested ' . $field . ' does not match the confirmed OTA target mapping');
            }
        }
        $requestedPlatform = strtolower(trim((string)($requested['platform'] ?? $requested['channel'] ?? '')));
        if ($requestedPlatform !== '' && !hash_equals($normalized['platform'], $requestedPlatform)) {
            throw new \InvalidArgumentException('requested platform does not match the confirmed OTA target mapping');
        }
        $normalized['mapping_digest'] = $computedDigest;
        $normalized['mapping_source'] = 'price_suggestions.factors.' . self::FACTOR_KEY;

        return $normalized;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $intent */
    public function assertCurrent(array $source, array $intent): array
    {
        $mapping = $this->confirmedMapping($source);
        $target = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $storedMapping = is_array($evidence['ota_target_mapping'] ?? null)
            ? $evidence['ota_target_mapping']
            : [];
        foreach (['room_type_key', 'rate_plan_key'] as $field) {
            if (!hash_equals($mapping[$field], trim((string)($target[$field] ?? '')))) {
                throw new \InvalidArgumentException('price suggestion OTA target mapping changed; create a new execution intent');
            }
        }
        foreach (['mapping_record_id', 'mapping_version', 'mapping_digest'] as $field) {
            if (!hash_equals($mapping[$field], trim((string)($storedMapping[$field] ?? '')))) {
                throw new \InvalidArgumentException('price suggestion OTA target mapping changed; create a new execution intent');
            }
        }
        try {
            $roomType = Db::name('room_types')
                ->where('id', $mapping['room_type_id'])
                ->where('tenant_id', $mapping['tenant_id'])
                ->where('hotel_id', $mapping['hotel_id'])
                ->where('is_enabled', 1)
                ->lock(true)
                ->find();
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(
                'authoritative OTA target mapping cannot be verified because room type tenant scope is unavailable',
                0,
                $exception
            );
        }
        if (!is_array($roomType)) {
            throw new \InvalidArgumentException(
                'confirmed OTA target mapping no longer matches an enabled room type in the current tenant and hotel'
            );
        }

        return $mapping;
    }

    /** @param array<string,mixed> $mapping */
    public static function mappingDigest(array $mapping): string
    {
        $identity = [
            'mapping_record_id' => trim((string)($mapping['mapping_record_id'] ?? '')),
            'mapping_version' => trim((string)($mapping['mapping_version'] ?? '')),
            'status' => strtolower(trim((string)($mapping['status'] ?? ''))),
            'tenant_id' => (int)($mapping['tenant_id'] ?? 0),
            'hotel_id' => (int)($mapping['hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($mapping['platform'] ?? ''))),
            'room_type_id' => (int)($mapping['room_type_id'] ?? 0),
            'room_type_key' => trim((string)($mapping['room_type_key'] ?? '')),
            'rate_plan_key' => trim((string)($mapping['rate_plan_key'] ?? '')),
            'confirmed_by' => (int)($mapping['confirmed_by'] ?? 0),
            'confirmed_at' => trim((string)($mapping['confirmed_at'] ?? '')),
        ];
        $encoded = json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $encoded);
    }

    private function validConfirmedAt(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $parsed->format('Y-m-d H:i:s') === $value;
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
