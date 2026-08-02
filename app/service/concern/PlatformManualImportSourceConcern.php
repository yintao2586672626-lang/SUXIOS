<?php
declare(strict_types=1);

namespace app\service\concern;

use RuntimeException;
use think\facade\Db;

trait PlatformManualImportSourceConcern
{
    /** @param array<string, mixed> $source */
    private function isManualImportSource(array $source): bool
    {
        return in_array(
            strtolower(trim((string)($source['ingestion_method'] ?? ''))),
            self::MANUAL_IMPORT_METHODS,
            true
        );
    }

    /**
     * Manual imports must never inherit an execution option that could invoke
     * a source-side request. Only the submitted rows reach the import adapter.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function manualImportAdapterOptions(array $options): array
    {
        return [
            'trigger_type' => (string)($options['trigger_type'] ?? 'manual_import'),
            'payload' => is_array($options['payload'] ?? null) ? $options['payload'] : [],
        ];
    }

    /**
     * A selected capture/API source is an identity reference only. Browser
     * assist keeps its own credentialless source and never executes the
     * selected source's adapter.
     *
     * @param array<string, mixed> $selectedSource
     * @param array<string, mixed> $payload
     */
    private function resolveBrowserAssistImportSourceId($user, array $selectedSource, array $payload): int
    {
        $scope = $selectedSource !== [] ? $selectedSource : $payload;
        $lookup = [
            'system_hotel_id' => (int)($scope['system_hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
            'data_type' => strtolower(trim((string)($scope['data_type'] ?? 'business'))),
        ];
        $sourceId = $this->reusableBrowserAssistSourceId($user, $lookup);
        if ($sourceId > 0) {
            $this->loadSource($sourceId, $user);
            return $sourceId;
        }

        $source = $this->saveDataSource($user, [
            'name' => 'Browser assist import',
            'platform' => $lookup['platform'] !== '' ? $lookup['platform'] : 'custom',
            'data_type' => $lookup['data_type'] !== '' ? $lookup['data_type'] : 'business',
            'system_hotel_id' => $lookup['system_hotel_id'],
            'ingestion_method' => 'browser_assist_dom',
        ]);
        return (int)$source['id'];
    }

    /**
     * @param array<string, mixed> $selectedSource
     * @param array<string, mixed> $payload
     */
    private function resolveDedicatedManualImportSourceId($user, array $selectedSource, array $payload): int
    {
        $scope = $selectedSource !== [] ? $selectedSource : $payload;
        $hotelId = (int)($scope['system_hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($scope['platform'] ?? 'custom')));
        $dataType = strtolower(trim((string)($scope['data_type'] ?? 'business')));
        if ($platform === '') {
            $platform = 'custom';
        }
        if ($dataType === '') {
            $dataType = 'business';
        }
        $this->assertCanUseHotel($user, $hotelId, 'can_fetch_online_data');
        $tenantId = $this->resolveHotelTenantId($hotelId);
        $platformHotelId = $this->resolveManualImportPlatformHotelId($selectedSource, $payload, $platform);

        $query = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('data_type', $dataType)
            ->whereIn('ingestion_method', self::MANUAL_IMPORT_METHODS)
            ->where('enabled', 1)
            ->order('id', 'desc');
        $this->applySourceTenantScope($query, $user);
        foreach ($query->select()->toArray() as $candidate) {
            $config = $this->decodeConfig($candidate['config_json'] ?? []);
            if ((string)($config['manual_import_contract'] ?? '') !== self::MANUAL_IMPORT_SOURCE_CONTRACT) {
                continue;
            }
            $candidatePlatformHotelId = trim((string)($config['platform_hotel_id'] ?? ''));
            if ($this->isOtaPlatform($platform)
                && !$this->otaHotelIdentifiersMatch($platformHotelId, $candidatePlatformHotelId)
            ) {
                continue;
            }
            $this->loadSource((int)$candidate['id'], $user);
            return (int)$candidate['id'];
        }

        $config = [
            'manual_import_contract' => self::MANUAL_IMPORT_SOURCE_CONTRACT,
            'source_method' => 'manual_import',
        ];
        if ($platformHotelId !== '') {
            $config['platform_hotel_id'] = $platformHotelId;
        }
        $actorId = (int)($user->id ?? 0);
        $now = date('Y-m-d H:i:s');
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'user_id' => $actorId ?: null,
            'name' => sprintf('%s manual import - %s', $platform, $dataType),
            'platform' => $platform,
            'data_type' => $dataType,
            'ingestion_method' => 'manual',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_json' => '{}',
            'created_by' => $actorId ?: null,
            'updated_by' => $actorId ?: null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $readback = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('data_type', $dataType)
            ->where('ingestion_method', 'manual')
            ->find();
        if (!is_array($readback) || (int)($readback['id'] ?? 0) !== $sourceId) {
            throw new RuntimeException('manual_import_source_readback_failed', 500);
        }
        $this->loadSource($sourceId, $user);
        return $sourceId;
    }

    /**
     * @param array<string, mixed> $selectedSource
     * @param array<string, mixed> $payload
     */
    private function resolveManualImportPlatformHotelId(array $selectedSource, array $payload, string $platform): string
    {
        if (!$this->isOtaPlatform($platform)) {
            return '';
        }
        $keys = $this->otaHotelIdentifierKeys($platform);
        $sourceConfig = $this->decodeConfig($selectedSource['config'] ?? $selectedSource['config_json'] ?? []);
        $expected = $this->stringValue($selectedSource, $keys);
        if ($expected === '') {
            $expected = $this->stringValue($sourceConfig, $keys);
        }
        if ($expected === '') {
            $expected = $this->stringValue($payload, $keys);
        }

        $observed = [];
        foreach ($this->extractBusinessRows($payload) as $row) {
            if (!is_array($row) || $this->isCompetitorOtaIdentityRow($row, $keys)) {
                continue;
            }
            $identifier = trim($this->stringValue($row, $keys));
            if ($identifier !== '') {
                $observed[strtolower($identifier)] = $identifier;
            }
        }
        $observed = array_values($observed);
        if (count($observed) !== 1) {
            throw new RuntimeException($observed === [] ? 'binding_unverified' : 'binding_mismatch', 422);
        }
        if ($expected === '') {
            $expected = $observed[0];
        }
        if (!$this->otaHotelIdentifiersMatch($expected, $observed[0])) {
            throw new RuntimeException('binding_mismatch', 409);
        }
        return $expected;
    }

    /** @param array<string, mixed> $payload */
    private function reusableBrowserAssistSourceId($user, array $payload): int
    {
        $hotelId = (int)($payload['system_hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($payload['platform'] ?? '')));
        $dataType = strtolower(trim((string)($payload['data_type'] ?? '')));
        if ($hotelId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || $dataType === ''
        ) {
            return 0;
        }
        $query = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('data_type', $dataType)
            ->where('ingestion_method', 'browser_assist_dom')
            ->where('enabled', 1);
        $this->applySourceTenantScope($query, $user);
        return max(0, (int)($query->order('id', 'desc')->value('id') ?? 0));
    }
}
