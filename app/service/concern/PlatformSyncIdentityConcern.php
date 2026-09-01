<?php
declare(strict_types=1);

namespace app\service\concern;

trait PlatformSyncIdentityConcern
{
    private function syncTaskOtaStoreIdentifier(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId']
            : ['ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'hotel_code', 'hotelCode', 'hotel_id', 'hotelId'];
        foreach ($keys as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function syncTaskProfileIdentifier(array $config): string
    {
        foreach (['profile_id', 'profileId', 'stable_profile_id', 'stableProfileId', 'profile_binding_key', 'profileBindingKey', 'profile_key_hash'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $payload */
    private function syncPayloadPlatformHotelIdentifierReady(array $source, array $payload): bool
    {
        $identity = is_array($payload['platform_identity_validation'] ?? null)
            ? $payload['platform_identity_validation']
            : [];
        if ($identity === [] && is_array($payload['data_source_capture']['platform_identity_validation'] ?? null)) {
            $identity = $payload['data_source_capture']['platform_identity_validation'];
        }
        if (strtolower(trim((string)($identity['status'] ?? ''))) !== 'matched'
            || ($identity['source_validation'] ?? false) !== true
            || ($identity['sensitive_values_exposed'] ?? false) === true
        ) {
            return false;
        }

        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $keys = $this->otaHotelIdentifierKeys($platform);
        $config = is_array($source['config'] ?? null)
            ? $source['config']
            : $this->decodeConfig($source['config_json'] ?? []);
        $expected = trim((string)$this->stringValue($source, $keys));
        if ($expected === '') {
            $expected = trim((string)$this->stringValue($config, $keys));
        }
        $observed = trim((string)($identity['validated_identifier'] ?? ''));
        return $expected !== ''
            && $observed !== ''
            && $this->otaHotelIdentifiersMatch($expected, $observed);
    }
}
