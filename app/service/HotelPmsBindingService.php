<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Owns the single active PMS choice for one hotel.
 *
 * Provider-specific integrations remain independent so their historical
 * captures and identity evidence are preserved. This service only decides
 * which one is currently active for the hotel.
 */
final class HotelPmsBindingService
{
    public const PROVIDER_NONE = 'none';
    public const PROVIDER_DINGDANDAO = 'dingdandao_pms';
    public const PROVIDER_MEITUAN_CLOUD = 'meituan_cloud_pms';

    private const DINGDANDAO_TABLE = 'dingdandao_pms_integrations';
    private const MEITUAN_CLOUD_TABLE = 'meituan_cloud_pms_integrations';

    /** @return array<string,mixed> */
    public function status(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $businessDate = ''
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);

        $dingdandao = (new DingdandaoPmsIntegrationService())->status(
            $tenantId,
            $hotelId,
            $userId,
            $businessDate
        );
        $meituanCloud = (new MeituanCloudPmsIntegrationService())->status(
            $tenantId,
            $hotelId,
            $userId,
            $businessDate
        );
        $sources = [
            self::PROVIDER_DINGDANDAO => $this->sourceSummary($dingdandao),
            self::PROVIDER_MEITUAN_CLOUD => $this->sourceSummary($meituanCloud),
        ];
        $enabledProviders = array_keys(array_filter(
            $sources,
            static fn(array $source): bool => ($source['enabled'] ?? false) === true
        ));

        $bindingStatus = match (count($enabledProviders)) {
            0 => 'unconfigured',
            1 => 'configured',
            default => 'conflict',
        };
        $selectedProvider = count($enabledProviders) === 1
            ? (string)$enabledProviders[0]
            : null;
        $selectedSource = match ($selectedProvider) {
            self::PROVIDER_DINGDANDAO => $dingdandao,
            self::PROVIDER_MEITUAN_CLOUD => $meituanCloud,
            default => null,
        };
        $blockers = match ($bindingStatus) {
            'unconfigured' => [[
                'code' => 'hotel_pms_unconfigured',
                'message' => '当前门店尚未配置使用的 PMS，请在门店管理中选择。',
            ]],
            'conflict' => [[
                'code' => 'hotel_pms_multiple_sources_enabled',
                'message' => '历史配置中有两套 PMS 同时启用，请在门店管理中明确保留一个。',
            ]],
            default => [],
        };

        return [
            'binding_status' => $bindingStatus,
            'binding_status_label' => match ($bindingStatus) {
                'configured' => '已配置唯一 PMS',
                'conflict' => '配置冲突',
                default => '尚未配置 PMS',
            },
            'selected_provider' => $selectedProvider,
            'selected_provider_label' => $selectedProvider !== null
                ? (string)($sources[$selectedProvider]['provider_label'] ?? '')
                : null,
            'sources' => $sources,
            'selected_source' => $selectedSource,
            'blockers' => $blockers,
        ];
    }

    /**
     * Read the selected PMS for multiple authorized hotel rows without loading
     * capture, profile or delivery details per hotel.
     *
     * @param array<int|string,mixed> $hotelIds
     * @return array<int,array<string,mixed>>
     */
    public function selectionSummaries(array $hotelIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $hotelId): bool => $hotelId > 0
        )));
        if ($ids === []) {
            return [];
        }

        $this->assertTablesReady();
        $dingdandaoEnabled = array_fill_keys(array_map(
            'intval',
            Db::name(self::DINGDANDAO_TABLE)
                ->whereIn('hotel_id', $ids)
                ->where('provider', self::PROVIDER_DINGDANDAO)
                ->where('status', 1)
                ->column('hotel_id')
        ), true);
        $meituanCloudEnabled = array_fill_keys(array_map(
            'intval',
            Db::name(self::MEITUAN_CLOUD_TABLE)
                ->whereIn('hotel_id', $ids)
                ->where('provider', self::PROVIDER_MEITUAN_CLOUD)
                ->where('status', 1)
                ->column('hotel_id')
        ), true);

        $summaries = [];
        foreach ($ids as $hotelId) {
            $dingdandao = isset($dingdandaoEnabled[$hotelId]);
            $meituanCloud = isset($meituanCloudEnabled[$hotelId]);
            if ($dingdandao && $meituanCloud) {
                $summaries[$hotelId] = [
                    'binding_status' => 'conflict',
                    'selected_provider' => null,
                    'selected_provider_label' => 'PMS 配置冲突',
                ];
            } elseif ($dingdandao) {
                $summaries[$hotelId] = [
                    'binding_status' => 'configured',
                    'selected_provider' => self::PROVIDER_DINGDANDAO,
                    'selected_provider_label' => '订单来了 PMS',
                ];
            } elseif ($meituanCloud) {
                $summaries[$hotelId] = [
                    'binding_status' => 'configured',
                    'selected_provider' => self::PROVIDER_MEITUAN_CLOUD,
                    'selected_provider_label' => '美团云 PMS',
                ];
            } else {
                $summaries[$hotelId] = [
                    'binding_status' => 'unconfigured',
                    'selected_provider' => null,
                    'selected_provider_label' => '未配置 PMS',
                ];
            }
        }

        return $summaries;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(
        int $tenantId,
        int $hotelId,
        int $userId,
        array $input,
        string $businessDate = ''
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        $provider = $this->provider(
            $input['provider'] ?? $input['pms_provider'] ?? self::PROVIDER_NONE
        );
        $providerHotelId = $this->textOrNull($input['provider_hotel_id'] ?? null, 120);
        $providerHotelName = $this->textOrNull($input['provider_hotel_name'] ?? null, 160);
        if ($provider !== self::PROVIDER_NONE && $providerHotelName === null) {
            throw new \InvalidArgumentException('hotel_pms_binding_required');
        }

        $this->assertTablesReady();
        $this->ensureIntegrationRows($tenantId, $hotelId, $userId);
        $now = date('Y-m-d H:i:s');

        Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $userId,
            $provider,
            $providerHotelId,
            $providerHotelName,
            $now
        ): void {
            $dingdandao = Db::name(self::DINGDANDAO_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', self::PROVIDER_DINGDANDAO)
                ->lock(true)
                ->find();
            $meituanCloud = Db::name(self::MEITUAN_CLOUD_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', self::PROVIDER_MEITUAN_CLOUD)
                ->lock(true)
                ->find();
            if (!is_array($dingdandao) || !is_array($meituanCloud)) {
                throw new \RuntimeException('hotel_pms_binding_rows_missing');
            }

            $audit = [
                'updated_by' => $userId,
                'update_time' => $now,
            ];
            $dingdandaoValues = $audit + [
                'status' => $provider === self::PROVIDER_DINGDANDAO ? 1 : 0,
            ];
            if ($provider !== self::PROVIDER_DINGDANDAO) {
                $dingdandaoValues['auto_push_enabled'] = 0;
            } else {
                $dingdandaoValues['provider_hotel_id'] = $providerHotelId;
                $dingdandaoValues['provider_hotel_name'] = $providerHotelName;
                $dingdandaoValues['source_url'] = DingdandaoOperatingTargetCaptureService::SOURCE_URL;
            }

            $meituanValues = $audit + [
                'status' => $provider === self::PROVIDER_MEITUAN_CLOUD ? 1 : 0,
            ];
            if ($provider === self::PROVIDER_MEITUAN_CLOUD) {
                $meituanValues['provider_hotel_id'] = $providerHotelId;
                $meituanValues['provider_hotel_name'] = $providerHotelName;
                $meituanValues['source_url'] = MeituanCloudPmsCaptureService::SOURCE_URL;
            }

            Db::name(self::DINGDANDAO_TABLE)
                ->where('id', (int)$dingdandao['id'])
                ->update($dingdandaoValues);
            Db::name(self::MEITUAN_CLOUD_TABLE)
                ->where('id', (int)$meituanCloud['id'])
                ->update($meituanValues);
        });

        return $this->status($tenantId, $hotelId, $userId, $businessDate);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function sourceSummary(array $source): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        return [
            'provider' => (string)($source['provider'] ?? ''),
            'provider_label' => (string)($source['provider_label'] ?? ''),
            'configured' => ($config['configured'] ?? false) === true,
            'enabled' => ($config['status'] ?? false) === true,
            'provider_hotel_id' => $config['provider_hotel_id'] ?? null,
            'provider_hotel_name' => $config['provider_hotel_name'] ?? null,
            'updated_at' => $config['updated_at'] ?? null,
        ];
    }

    private function ensureIntegrationRows(int $tenantId, int $hotelId, int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->ensureRow(self::DINGDANDAO_TABLE, $tenantId, $hotelId, self::PROVIDER_DINGDANDAO, [
            'source_url' => DingdandaoOperatingTargetCaptureService::SOURCE_URL,
            'robot_id' => null,
            'status' => 0,
            'auto_push_enabled' => 0,
            'created_by' => $userId,
            'updated_by' => $userId,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $this->ensureRow(self::MEITUAN_CLOUD_TABLE, $tenantId, $hotelId, self::PROVIDER_MEITUAN_CLOUD, [
            'source_url' => MeituanCloudPmsCaptureService::SOURCE_URL,
            'status' => 0,
            'created_by' => $userId,
            'updated_by' => $userId,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /** @param array<string,mixed> $values */
    private function ensureRow(
        string $table,
        int $tenantId,
        int $hotelId,
        string $provider,
        array $values
    ): void {
        $query = static fn() => Db::name($table)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('provider', $provider);
        if (is_array($query()->find())) {
            return;
        }
        try {
            $id = (int)Db::name($table)->insertGetId($values + [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider' => $provider,
            ]);
            if ($id <= 0) {
                throw new \RuntimeException('hotel_pms_binding_row_create_failed');
            }
        } catch (\Throwable $error) {
            if (!is_array($query()->find())) {
                throw $error;
            }
        }
    }

    private function provider(mixed $value): string
    {
        $provider = strtolower(trim((string)$value));
        if ($provider === '') {
            return self::PROVIDER_NONE;
        }
        if (!in_array($provider, [
            self::PROVIDER_NONE,
            self::PROVIDER_DINGDANDAO,
            self::PROVIDER_MEITUAN_CLOUD,
        ], true)) {
            throw new \InvalidArgumentException('hotel_pms_provider_invalid');
        }
        return $provider;
    }

    private function assertScope(int $tenantId, int $hotelId, int $userId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('hotel_pms_scope_invalid');
        }
    }

    private function assertTablesReady(): void
    {
        if (!$this->tableExists(self::DINGDANDAO_TABLE)
            || !$this->tableExists(self::MEITUAN_CLOUD_TABLE)
        ) {
            throw new \RuntimeException('hotel_pms_tables_missing');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Db::getTableInfo($table, 'fields') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function textOrNull(mixed $value, int $limit): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$value) ?? '';
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        $text = str_replace(['<', '>'], ['＜', '＞'], $text);
        $text = mb_substr($text, 0, max(1, $limit), 'UTF-8');
        return $text === '' ? null : $text;
    }
}
