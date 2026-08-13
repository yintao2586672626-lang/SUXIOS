<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Maintains the independent hotel binding for Meituan Cloud PMS and exposes
 * the collection/fact gates without coupling it to Dingdandao.
 */
final class MeituanCloudPmsIntegrationService
{
    private const INTEGRATION_TABLE = 'meituan_cloud_pms_integrations';
    private const CAPTURE_TABLE = 'meituan_cloud_pms_captures';

    /** @var callable|null */
    private $afterInitialConfigRead;

    public function __construct(?callable $afterInitialConfigRead = null)
    {
        $this->afterInitialConfigRead = $afterInitialConfigRead;
    }

    /** @return array<string,mixed> */
    public function status(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $businessDate = ''
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        $config = $this->configRow($tenantId, $hotelId);
        $capture = $this->captureForStatus($tenantId, $hotelId, $businessDate);
        $latestCapture = trim($businessDate) === ''
            ? $capture
            : $this->captureForStatus($tenantId, $hotelId, '');
        $profile = $this->profileStatus($hotelId, $userId);

        return [
            'provider' => MeituanCloudPmsCaptureService::PROVIDER,
            'provider_label' => '美团云 PMS',
            'source_url' => MeituanCloudPmsCaptureService::SOURCE_URL,
            'source_scope' => MeituanCloudPmsCaptureService::SOURCE_SCOPE,
            'source_role' => 'independent_real_pms_source',
            'field_coverage' => [
                'provider_hotel_identity',
                'estimated_room_revenue',
                'adr',
                'revpar',
                'sold_room_nights',
                'total_rooms',
                'available_rooms',
                'room_type_available_rooms',
                'occupancy_rate_percent',
                'room_type_inventory',
            ],
            'config' => $this->publicConfig($config, $latestCapture ?? $capture),
            'profile' => $profile,
            'capture' => $capture,
            'latest_capture' => $latestCapture,
            'collection_gate' => $this->collectionGate($config, $profile),
            'fact_gate' => $this->factGate($config, $capture),
        ];
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
        $this->assertTableReady();

        $providerHotelId = $this->textOrNull($input['provider_hotel_id'] ?? null, 120);
        $providerHotelName = $this->textOrNull($input['provider_hotel_name'] ?? null, 160);
        $enabled = $this->boolValue($input['status'] ?? false);
        if ($enabled && $providerHotelName === null) {
            throw new \InvalidArgumentException('meituan_cloud_pms_binding_required');
        }

        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $userId,
            $providerHotelId,
            $providerHotelName,
            $enabled,
            $now
        ): void {
            $existing = Db::name(self::INTEGRATION_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', MeituanCloudPmsCaptureService::PROVIDER)
                ->lock(true)
                ->find();
            $values = [
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => $providerHotelName,
                'source_url' => MeituanCloudPmsCaptureService::SOURCE_URL,
                'status' => $enabled ? 1 : 0,
                'updated_by' => $userId,
                'update_time' => $now,
            ];
            if (is_array($existing)) {
                Db::name(self::INTEGRATION_TABLE)
                    ->where('id', (int)$existing['id'])
                    ->update($values);
                return;
            }
            $id = (int)Db::name(self::INTEGRATION_TABLE)->insertGetId($values + [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider' => MeituanCloudPmsCaptureService::PROVIDER,
                'created_by' => $userId,
                'create_time' => $now,
            ]);
            if ($id <= 0) {
                throw new \RuntimeException('meituan_cloud_pms_config_save_failed');
            }
        });

        return $this->status($tenantId, $hotelId, $userId, $businessDate);
    }

    /**
     * @return array{
     *   expected_provider_hotel_id:?string,
     *   expected_provider_hotel_name:string,
     *   configured:bool
     * }
     */
    public function captureExpectation(
        int $tenantId,
        int $hotelId,
        string $systemHotelName
    ): array {
        $config = $this->configRow($tenantId, $hotelId);
        $configured = is_array($config)
            && (int)($config['status'] ?? 0) === 1
            && trim((string)($config['provider_hotel_name'] ?? '')) !== '';
        return [
            'expected_provider_hotel_id' => $configured
                ? $this->textOrNull($config['provider_hotel_id'] ?? null, 120)
                : null,
            'expected_provider_hotel_name' => $configured
                ? (string)$config['provider_hotel_name']
                : trim($systemHotelName),
            'configured' => $configured,
        ];
    }

    /** @return array<string,mixed> */
    public function prefill(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $businessDate
    ): array {
        $this->assertScope($tenantId, $hotelId, $userId);
        $prefill = (new MeituanCloudPmsCaptureService())
            ->prefill($tenantId, $hotelId, $businessDate);
        $capture = is_array($prefill['capture'] ?? null) ? $prefill['capture'] : null;
        $gate = $this->factGate($this->configRow($tenantId, $hotelId), $capture);
        if (($gate['allowed'] ?? false) !== true) {
            return [
                'status' => 'blocked',
                'prefill' => null,
                'capture' => $capture,
                'gaps' => $gate['blockers'],
                'fact_gate' => $gate,
            ];
        }
        $prefill['fact_gate'] = $gate;
        return $prefill;
    }

    /**
     * @param array<string,mixed> $capture
     * @return array<string,mixed>|null
     */
    public function recordCapture(
        int $tenantId,
        int $hotelId,
        int $userId,
        array $capture
    ): ?array {
        $this->assertScope($tenantId, $hotelId, $userId);
        if (!$this->tableExists(self::INTEGRATION_TABLE)
            || (string)($capture['provider'] ?? '') !== MeituanCloudPmsCaptureService::PROVIDER
            || (int)($capture['hotel_id'] ?? 0) !== $hotelId
            || (int)($capture['tenant_id'] ?? 0) !== $tenantId
        ) {
            return null;
        }
        $initialConfig = $this->configRow($tenantId, $hotelId);
        if (!is_array($initialConfig)) {
            return null;
        }
        if ($this->afterInitialConfigRead !== null) {
            call_user_func($this->afterInitialConfigRead, $initialConfig);
        }

        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $userId,
            $capture
        ): ?array {
            $config = Db::name(self::INTEGRATION_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', MeituanCloudPmsCaptureService::PROVIDER)
                ->lock(true)
                ->find();
            if (!is_array($config)) {
                return null;
            }

            $now = date('Y-m-d H:i:s');
            $values = [
                'last_capture_id' => (int)($capture['id'] ?? 0) ?: null,
                'last_capture_business_date' => $capture['business_date'] ?? null,
                'last_capture_status' => $capture['quality_status'] ?? 'unverified',
                'last_readback_status' => $capture['readback_status'] ?? 'unverified',
                'updated_by' => $userId,
                'update_time' => $now,
            ];
            Db::name(self::INTEGRATION_TABLE)
                ->where('id', (int)$config['id'])
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', MeituanCloudPmsCaptureService::PROVIDER)
                ->update($values);

            $currentProviderHotelId = trim((string)($config['provider_hotel_id'] ?? ''));
            $capturedProviderHotelId = $this->textOrNull(
                $capture['provider_hotel_id'] ?? null,
                120
            );
            $canLearnProviderHotelId = $currentProviderHotelId === ''
                && $capturedProviderHotelId !== null
                && ($this->factGate($config, $capture)['allowed'] ?? false) === true;
            if ($canLearnProviderHotelId) {
                $claim = Db::name(self::INTEGRATION_TABLE)
                    ->where('id', (int)$config['id'])
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('provider', MeituanCloudPmsCaptureService::PROVIDER);
                if (($config['provider_hotel_id'] ?? null) === null) {
                    $claim->whereNull('provider_hotel_id');
                } else {
                    $claim->where('provider_hotel_id', (string)$config['provider_hotel_id']);
                }
                $claim->update([
                    'provider_hotel_id' => $capturedProviderHotelId,
                    'updated_by' => $userId,
                    'update_time' => $now,
                ]);
            }

            $current = Db::name(self::INTEGRATION_TABLE)
                ->where('id', (int)$config['id'])
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', MeituanCloudPmsCaptureService::PROVIDER)
                ->lock(true)
                ->find();
            return is_array($current) ? $current : null;
        });
    }

    /** @return array<string,mixed>|null */
    private function configRow(int $tenantId, int $hotelId): ?array
    {
        if (!$this->tableExists(self::INTEGRATION_TABLE)) {
            return null;
        }
        $row = Db::name(self::INTEGRATION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('provider', MeituanCloudPmsCaptureService::PROVIDER)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function captureForStatus(
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): ?array {
        if (!$this->tableExists(self::CAPTURE_TABLE)) {
            return null;
        }
        $query = Db::name(self::CAPTURE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->order('id', 'desc');
        if (trim($businessDate) !== '') {
            $query->where('business_date', $this->date($businessDate));
        }
        $row = $query->find();
        return is_array($row)
            ? (new MeituanCloudPmsCaptureService())->read(
                $tenantId,
                $hotelId,
                (int)$row['id']
            )
            : null;
    }

    /** @return array<string,mixed>|null */
    private function profileStatus(int $hotelId, int $userId): ?array
    {
        try {
            $status = (new CloudBrowserProfileService())->status(
                $hotelId,
                $userId,
                MeituanCloudPmsCaptureService::PROFILE_PLATFORM
            );
            $profile = $status['profiles'][0] ?? null;
            return is_array($profile) ? $profile : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed>|null $config
     * @param array<string,mixed>|null $capture
     * @return array<string,mixed>
     */
    private function publicConfig(?array $config, ?array $capture): array
    {
        return [
            'id' => (int)($config['id'] ?? 0),
            'configured' => is_array($config),
            'provider_hotel_id' => $config['provider_hotel_id']
                ?? $capture['provider_hotel_id']
                ?? null,
            'provider_hotel_name' => $config['provider_hotel_name']
                ?? $capture['provider_hotel_name']
                ?? null,
            'status' => (int)($config['status'] ?? 0) === 1,
            'last_capture_id' => $this->positiveIntOrNull($config['last_capture_id'] ?? null),
            'last_capture_business_date' => $config['last_capture_business_date'] ?? null,
            'last_capture_status' => $config['last_capture_status'] ?? null,
            'last_readback_status' => $config['last_readback_status'] ?? null,
            'updated_at' => $config['update_time'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed>|null $config
     * @param array<string,mixed>|null $profile
     * @return array{allowed:bool,status:string,blockers:list<array{code:string,message:string}>}
     */
    private function collectionGate(?array $config, ?array $profile): array
    {
        $blockers = [];
        if (!is_array($config) || (int)($config['status'] ?? 0) !== 1) {
            $blockers[] = $this->blocker(
                'meituan_cloud_pms_integration_disabled',
                '请先启用美团云 PMS 独立数据源并保存门店绑定。'
            );
        }
        if (trim((string)($config['provider_hotel_name'] ?? '')) === '') {
            $blockers[] = $this->blocker(
                'meituan_cloud_pms_binding_missing',
                '请维护美团云 PMS 门店名称，用于采集时逐店验真。'
            );
        }
        if (!is_array($profile)
            || (string)($profile['authorization_status'] ?? '') !== CloudBrowserProfileService::READY_TO_COLLECT
        ) {
            $blockers[] = $this->blocker(
                'meituan_cloud_pms_profile_not_ready',
                '美团云 PMS 云端登录会话尚未验证或已过期。'
            );
        }
        return [
            'allowed' => $blockers === [],
            'status' => $blockers === [] ? 'ready_to_collect' : 'blocked',
            'blockers' => $this->uniqueBlockers($blockers),
        ];
    }

    /**
     * @param array<string,mixed>|null $config
     * @param array<string,mixed>|null $capture
     * @return array{allowed:bool,status:string,blockers:list<array{code:string,message:string}>}
     */
    private function factGate(?array $config, ?array $capture): array
    {
        $blockers = [];
        if (!is_array($config) || (int)($config['status'] ?? 0) !== 1) {
            $blockers[] = $this->blocker(
                'meituan_cloud_pms_integration_disabled',
                '美团云 PMS 独立数据源未启用。'
            );
        }
        if (!is_array($capture)) {
            $blockers[] = $this->blocker(
                'meituan_cloud_capture_missing',
                '目标日期尚无美团云 PMS 采集记录。'
            );
        } else {
            foreach ([
                'quality_status' => 'verified',
                'capture_status' => 'verified',
                'identity_status' => 'matched',
                'date_status' => 'matched',
                'reconciliation_status' => 'matched',
                'readback_status' => 'readback_verified',
            ] as $field => $expected) {
                if ((string)($capture[$field] ?? '') !== $expected) {
                    $blockers[] = $this->blocker(
                        'meituan_cloud_' . $field . '_blocked',
                        '美团云 PMS 事实未通过' . $field . '门禁。'
                    );
                }
            }
            $boundId = trim((string)($config['provider_hotel_id'] ?? ''));
            $captureId = trim((string)($capture['provider_hotel_id'] ?? ''));
            if ($boundId !== '' && ($captureId === '' || !hash_equals($boundId, $captureId))) {
                $blockers[] = $this->blocker(
                    'meituan_cloud_provider_hotel_id_mismatch',
                    '采集返回的美团云 PMS 门店ID与已绑定门店不一致。'
                );
            }
            if (!$this->sameText(
                (string)($config['provider_hotel_name'] ?? ''),
                (string)($capture['provider_hotel_name'] ?? '')
            )) {
                $blockers[] = $this->blocker(
                    'meituan_cloud_provider_hotel_name_mismatch',
                    '采集返回的美团云 PMS 门店名称与已绑定门店不一致。'
                );
            }
        }
        return [
            'allowed' => $blockers === [],
            'status' => $blockers === [] ? 'verified_fact_ready' : 'blocked',
            'blockers' => $this->uniqueBlockers($blockers),
        ];
    }

    /** @return array{code:string,message:string} */
    private function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /**
     * @param list<array{code:string,message:string}> $blockers
     * @return list<array{code:string,message:string}>
     */
    private function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker['code']] = $blocker;
        }
        return array_values($unique);
    }

    private function assertScope(int $tenantId, int $hotelId, int $userId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('meituan_cloud_pms_scope_invalid');
        }
    }

    private function assertTableReady(): void
    {
        if (!$this->tableExists(self::INTEGRATION_TABLE)) {
            throw new \RuntimeException('meituan_cloud_pms_table_missing');
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

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($validated) && $validated > 0 ? $validated : null;
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

    private function sameText(string $left, string $right): bool
    {
        $normalize = static fn(string $value): string =>
            mb_strtolower(preg_replace('/\s+/u', '', trim($value)) ?? '', 'UTF-8');
        $left = $normalize($left);
        $right = $normalize($right);
        return $left !== '' && $right !== '' && hash_equals($left, $right);
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('meituan_cloud_pms_date_invalid');
        }
        return $value;
    }
}
