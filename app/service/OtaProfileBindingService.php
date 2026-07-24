<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class OtaProfileBindingService
{
    public function __construct(private readonly ?string $projectRoot = null)
    {
    }

    /** @return array<string, mixed> */
    public function claim(
        int $systemHotelId,
        string $platform,
        string $profileKey,
        int $actorId = 0,
        bool $allowExistingProfileDirectory = false
    ): array
    {
        $scope = $this->hotelScope($systemHotelId);
        $identity = $this->profileIdentity($platform, $profileKey);
        $this->assertBindingTableAvailable();

        return Db::transaction(function () use ($scope, $identity, $actorId, $allowExistingProfileDirectory): array {
            $existing = Db::name('ota_profile_bindings')
                ->where('platform', $identity['platform'])
                ->where('profile_key_hash', $identity['profile_key_hash'])
                ->lock(true)
                ->find();

            if (is_array($existing)) {
                $this->assertBindingScopeMatches($existing, $scope);
                $this->assertNoOtherActiveBinding($scope, $identity, (int)$existing['id']);
                if (strtolower(trim((string)($existing['binding_status'] ?? ''))) !== 'active') {
                    Db::name('ota_profile_bindings')->where('id', (int)$existing['id'])->update([
                        'binding_status' => 'active',
                        'bound_by' => $actorId > 0 ? $actorId : null,
                        'revoked_by' => null,
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                    $existing['binding_status'] = 'active';
                    $existing['bound_by'] = $actorId > 0 ? $actorId : null;
                    $existing['revoked_by'] = null;
                }

                return $this->compactBinding($existing);
            }

            $this->assertNoOtherActiveBinding($scope, $identity);
            $this->assertSourceMetadataDoesNotConflict($scope, $identity);
            if (!$allowExistingProfileDirectory
                && is_dir($this->profileDirectory($identity['platform'], $identity['canonical_key']))) {
                throw new RuntimeException('Existing OTA profile directory is unbound; explicit local rebind is required.', 409);
            }

            $now = date('Y-m-d H:i:s');
            try {
                $id = (int)Db::name('ota_profile_bindings')->insertGetId([
                    'tenant_id' => $scope['tenant_id'],
                    'system_hotel_id' => $scope['system_hotel_id'],
                    'platform' => $identity['platform'],
                    'profile_key_hash' => $identity['profile_key_hash'],
                    'binding_status' => 'active',
                    'bound_by' => $actorId > 0 ? $actorId : null,
                    'revoked_by' => null,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (\Throwable $e) {
                $raced = Db::name('ota_profile_bindings')
                    ->where('platform', $identity['platform'])
                    ->where('profile_key_hash', $identity['profile_key_hash'])
                    ->find();
                if (!is_array($raced)) {
                    throw new RuntimeException('OTA profile binding could not be registered.', 409, $e);
                }
                $this->assertBindingScopeMatches($raced, $scope);
                return $this->compactBinding($raced);
            }

            return [
                'id' => $id,
                'tenant_id' => $scope['tenant_id'],
                'system_hotel_id' => $scope['system_hotel_id'],
                'platform' => $identity['platform'],
                'profile_key_hash' => $identity['profile_key_hash'],
                'binding_status' => 'active',
            ];
        });
    }

    /** @return array<string, mixed> */
    public function assertBound(int $systemHotelId, string $platform, string $profileKey): array
    {
        $scope = $this->hotelScope($systemHotelId);
        $identity = $this->profileIdentity($platform, $profileKey);
        $this->assertBindingTableAvailable();

        $binding = Db::name('ota_profile_bindings')
            ->where('platform', $identity['platform'])
            ->where('profile_key_hash', $identity['profile_key_hash'])
            ->find();
        if (!is_array($binding)) {
            throw new RuntimeException('OTA profile binding is not registered.', 409);
        }
        $this->assertBindingScopeMatches($binding, $scope);
        if (strtolower(trim((string)($binding['binding_status'] ?? ''))) !== 'active') {
            throw new RuntimeException('OTA profile binding is not active.', 409);
        }

        return $this->compactBinding($binding);
    }

    /** @return array<string, mixed> */
    public function revoke(int $systemHotelId, string $platform, string $profileKey, int $actorId = 0): array
    {
        $scope = $this->hotelScope($systemHotelId);
        $identity = $this->profileIdentity($platform, $profileKey);
        $this->assertBindingTableAvailable();

        return Db::transaction(function () use ($scope, $identity, $actorId): array {
            $binding = Db::name('ota_profile_bindings')
                ->where('platform', $identity['platform'])
                ->where('profile_key_hash', $identity['profile_key_hash'])
                ->lock(true)
                ->find();
            if (!is_array($binding)) {
                throw new RuntimeException('OTA profile binding is not registered.', 409);
            }
            $this->assertBindingScopeMatches($binding, $scope);
            Db::name('ota_profile_bindings')->where('id', (int)$binding['id'])->update([
                'binding_status' => 'revoked',
                'revoked_by' => $actorId > 0 ? $actorId : null,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            $binding['binding_status'] = 'revoked';
            $binding['revoked_by'] = $actorId > 0 ? $actorId : null;
            return $this->compactBinding($binding);
        });
    }

    /** @return array{tenant_id:int,system_hotel_id:int} */
    private function hotelScope(int $systemHotelId): array
    {
        if ($systemHotelId <= 0) {
            throw new RuntimeException('OTA profile hotel scope is missing.', 422);
        }
        $row = Db::name('hotels')->field('id,tenant_id')->where('id', $systemHotelId)->find();
        $tenantId = is_array($row) ? (int)($row['tenant_id'] ?? 0) : 0;
        if (!is_array($row) || $tenantId <= 0) {
            throw new RuntimeException('OTA profile tenant scope is missing.', 422);
        }
        return ['tenant_id' => $tenantId, 'system_hotel_id' => $systemHotelId];
    }

    /** @return array{platform:string,canonical_key:string,profile_key_hash:string} */
    private function profileIdentity(string $platform, string $profileKey): array
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new RuntimeException('Unsupported OTA profile platform.', 422);
        }
        $profileKey = trim($profileKey);
        if ($profileKey === '') {
            throw new RuntimeException('OTA profile binding key is missing.', 422);
        }
        $canonical = BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        if ($canonical === '' || $canonical === 'default') {
            throw new RuntimeException('OTA profile binding key is invalid.', 422);
        }
        return [
            'platform' => $platform,
            'canonical_key' => $canonical,
            'profile_key_hash' => hash('sha256', $canonical),
        ];
    }

    /** @param array<string,mixed> $binding @param array{tenant_id:int,system_hotel_id:int} $scope */
    private function assertBindingScopeMatches(array $binding, array $scope): void
    {
        if ((int)($binding['tenant_id'] ?? 0) !== $scope['tenant_id']
            || (int)($binding['system_hotel_id'] ?? 0) !== $scope['system_hotel_id']
        ) {
            throw new RuntimeException('OTA profile binding belongs to another tenant or hotel.', 409);
        }
    }

    /**
     * @param array{tenant_id:int,system_hotel_id:int} $scope
     * @param array{platform:string,canonical_key:string,profile_key_hash:string} $identity
     */
    private function assertSourceMetadataDoesNotConflict(array $scope, array $identity): void
    {
        $rows = Db::name('platform_data_sources')
            ->field('tenant_id,system_hotel_id,platform,ingestion_method,config_json')
            ->where('platform', $identity['platform'])
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->where('enabled', 1)
            ->where('status', '<>', 'disabled')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            if (!is_array($config)) {
                continue;
            }
            $candidate = $this->sourceProfileKey($identity['platform'], $config);
            if ($candidate === '' || hash('sha256', BrowserProfileCaptureRequestService::safeFilePart($candidate)) !== $identity['profile_key_hash']) {
                continue;
            }
            $sourceHotelId = (int)($row['system_hotel_id'] ?? 0);
            $sourceTenantId = $sourceHotelId > 0
                ? (int)Db::name('hotels')->where('id', $sourceHotelId)->value('tenant_id')
                : 0;
            if ($sourceTenantId !== $scope['tenant_id'] || $sourceHotelId !== $scope['system_hotel_id']) {
                throw new RuntimeException('OTA profile source metadata conflicts across tenant or hotel scopes.', 409);
            }
        }
    }

    /**
     * @param array{tenant_id:int,system_hotel_id:int} $scope
     * @param array{platform:string,canonical_key:string,profile_key_hash:string} $identity
     */
    private function assertNoOtherActiveBinding(array $scope, array $identity, int $excludeId = 0): void
    {
        $query = Db::name('ota_profile_bindings')
            ->where('tenant_id', $scope['tenant_id'])
            ->where('system_hotel_id', $scope['system_hotel_id'])
            ->where('platform', $identity['platform'])
            ->where('binding_status', 'active');
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        if (is_array($query->lock(true)->find())) {
            throw new RuntimeException('This hotel and platform already has an active Profile binding; revoke it before replacement.', 409);
        }
    }

    /** @param array<string,mixed> $config */
    private function sourceProfileKey(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId', 'profile_id', 'profileId']
            : ['profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    private function profileDirectory(string $platform, string $canonicalKey): string
    {
        $root = $this->projectRoot ?? dirname(__DIR__, 2);
        return $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $platform . '_profile_' . $canonicalKey;
    }

    private function assertBindingTableAvailable(): void
    {
        try {
            Db::name('ota_profile_bindings')->field('id')->limit(1)->select();
        } catch (\Throwable $e) {
            throw new RuntimeException('OTA profile binding table is missing; migration required.', 503, $e);
        }
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function compactBinding(array $binding): array
    {
        return [
            'id' => (int)($binding['id'] ?? 0),
            'tenant_id' => (int)($binding['tenant_id'] ?? 0),
            'system_hotel_id' => (int)($binding['system_hotel_id'] ?? 0),
            'platform' => strtolower((string)($binding['platform'] ?? '')),
            'profile_key_hash' => (string)($binding['profile_key_hash'] ?? ''),
            'binding_status' => strtolower((string)($binding['binding_status'] ?? '')),
        ];
    }
}
