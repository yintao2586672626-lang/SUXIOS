<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

final class MeituanManualIdentityService
{
    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $storedConfig
     * @return array{partner_id:string,poi_id:string,shop_id:string}
     */
    public function resolve(array $requestData, array $storedConfig, string $section): array
    {
        $section = strtolower(trim($section));
        if (!in_array($section, ['traffic', 'orders', 'ads'], true)) {
            throw new InvalidArgumentException('invalid_meituan_manual_section', 400);
        }

        $partnerId = $this->firstText($storedConfig, ['partner_id', 'partnerId']);
        $poiId = $this->firstText($storedConfig, ['poi_id', 'poiId', 'store_id', 'storeId']);
        $shopId = $this->firstText($storedConfig, ['shop_id', 'shopId']);
        if ($shopId === '') {
            $shopId = $poiId;
        }

        if ($poiId === '' || (in_array($section, ['traffic', 'orders'], true) && $partnerId === '')) {
            throw new InvalidArgumentException('meituan_platform_identity_missing', 409);
        }

        $requestPartnerId = $this->firstText($requestData, ['partner_id', 'partnerId']);
        $requestPoiId = $this->firstText($requestData, ['poi_id', 'poiId']);
        $requestShopId = $this->firstText($requestData, ['shop_id', 'shopId']);
        if (($requestPartnerId !== '' && !hash_equals($partnerId, $requestPartnerId))
            || ($requestPoiId !== '' && !hash_equals($poiId, $requestPoiId))
            || ($requestShopId !== '' && !hash_equals($shopId, $requestShopId))
        ) {
            throw new InvalidArgumentException('meituan_platform_identity_mismatch', 409);
        }

        return [
            'partner_id' => $partnerId,
            'poi_id' => $poiId,
            'shop_id' => $shopId,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $storedConfig
     * @return array{store_id:string,poi_id:string,shop_id:string}
     */
    public function resolveCapturedPayloadIdentity(array $payload, array $storedConfig): array
    {
        $storeId = $this->firstText($storedConfig, [
            'profile_binding_key',
            'stable_profile_id',
            'store_id',
            'storeId',
            'poi_id',
            'poiId',
            'profile_id',
            'profileId',
        ]);
        $poiId = $this->firstText($storedConfig, ['poi_id', 'poiId']);
        if ($poiId === '') {
            $poiId = $storeId;
        }
        $shopId = $this->firstText($storedConfig, ['shop_id', 'shopId']);
        if ($shopId === '') {
            $shopId = $poiId;
        }
        if ($storeId === '' || $poiId === '') {
            throw new InvalidArgumentException('meituan_platform_identity_missing', 409);
        }

        $payloadStoreId = $this->firstText($payload, ['store_id', 'storeId', 'profile_id', 'profileId']);
        $payloadPoiId = $this->firstText($payload, ['poi_id', 'poiId']);
        $payloadShopId = $this->firstText($payload, ['shop_id', 'shopId']);
        if ($payloadStoreId === '' && $payloadPoiId === '') {
            throw new InvalidArgumentException('meituan_captured_payload_identity_missing', 409);
        }
        if (($payloadStoreId !== '' && !hash_equals($storeId, $payloadStoreId))
            || ($payloadPoiId !== '' && !hash_equals($poiId, $payloadPoiId))
            || ($payloadShopId !== '' && !hash_equals($shopId, $payloadShopId))
        ) {
            throw new InvalidArgumentException('meituan_platform_identity_mismatch', 409);
        }

        return [
            'store_id' => $storeId,
            'poi_id' => $poiId,
            'shop_id' => $shopId,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstText(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source) || $source[$key] === null) {
                continue;
            }
            $value = trim((string)$source[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
