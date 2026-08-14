<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Secret-free onboarding projection for one hotel's PMS + Ctrip + Meituan
 * collection identities and its hotel-scoped WeCom delivery plan.
 *
 * This service only manages metadata. It never starts a browser, collects OTA
 * data, tests a robot, enables a plan, or sends a message.
 */
final class HotelThreeSourceOnboardingService
{
    public const CONTRACT_VERSION = 'hotel_three_source_onboarding.v1';

    private const OTA_PLATFORMS = ['ctrip', 'meituan'];
    private const PROFILE_METHODS = ['browser_profile', 'profile_browser'];

    /** @var callable|null */
    private $collectionPlanLoader;

    public function __construct(
        private readonly ?CloudBrowserProfileService $profileService = null,
        private readonly ?PlatformDataSyncService $dataSourceService = null,
        private readonly ?OtaProfileBindingService $bindingService = null,
        private readonly ?HotelPmsBindingService $pmsBindingService = null,
        private readonly ?WechatNotificationBindingService $wechatBindingService = null,
        ?callable $collectionPlanLoader = null
    ) {
        $this->collectionPlanLoader = $collectionPlanLoader;
    }

    /** @return array<string,mixed> */
    public function status(int $tenantId, int $hotelId, int $ownerUserId): array
    {
        $hotel = $this->hotel($tenantId, $hotelId, $ownerUserId);
        $pms = $this->pmsStatus($tenantId, $hotelId);

        $profiles = [];
        foreach (['ctrip', 'meituan', 'dingdandao', 'meituan_cloud_pms'] as $platform) {
            $profiles[$platform] = $this->profileStatus($hotelId, $ownerUserId, $platform);
        }

        $bindings = [];
        foreach (self::OTA_PLATFORMS as $platform) {
            $bindings[$platform] = $this->otaBindingStatus(
                $tenantId,
                $hotelId,
                $ownerUserId,
                $platform,
                $profiles[$platform]['profile'] ?? null
            );
        }

        $wechat = $this->wechatStatus($hotelId, $ownerUserId);
        $plan = $this->deliveryPlanStatus($tenantId, $hotelId, $ownerUserId);
        $collectionPlan = $this->collectionPlanStatus($hotel, $ownerUserId);
        $otaPlatforms = $this->requiredOtaPlatforms((string)($hotel['ota_channel_strategy'] ?? 'none'));
        $sources = $this->sourceMap($pms, $profiles, $bindings, $otaPlatforms);
        $sourceBlockers = $this->blockers($pms, $profiles, $bindings, $otaPlatforms);
        $sourceReady = $sourceBlockers === [];
        $collectionPlanReady = $this->collectionPlanReady($collectionPlan);
        $blockers = $sourceBlockers;
        if (!$collectionPlanReady) {
            $blockers[] = [
                'code' => 'collection_plan_not_active',
                'action' => 'activate_collection_plan',
            ];
        }
        $status = !$sourceReady
            ? 'blocked'
            : ($collectionPlanReady ? 'ready' : 'needs_collection_plan');

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'hotel_name' => (string)$hotel['name'],
            'status' => $status,
            'overall_status' => $status,
            'onboarding_status' => $status,
            'ready' => $sourceReady && $collectionPlanReady,
            'source_status' => $sourceReady ? 'ready' : 'blocked',
            'source_ready' => $sourceReady,
            'collection_plan_ready' => $collectionPlanReady,
            'ota_channel_strategy' => (string)($hotel['ota_channel_strategy'] ?? 'none'),
            'required_platforms' => array_keys($sources),
            'sources' => $sources,
            'platforms' => $sources,
            'source_statuses' => $sources,
            'pms' => $pms,
            'platform_bindings' => $bindings,
            'profiles' => $profiles,
            'delivery' => [
                'wechat' => $wechat,
                'hourly_plan' => $plan,
            ],
            'collection_plan' => $collectionPlan,
            'blockers' => $blockers,
            'next_action' => $blockers[0]['action'] ?? 'none',
            'external_action_performed' => false,
        ];
    }

    /**
     * @param object $actor
     * @return array<string,mixed>
     */
    public function bindPlatform(
        object $actor,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $platformHotelId,
        string $platformHotelName
    ): array {
        $ownerUserId = (int)($actor->id ?? 0);
        $hotel = $this->hotel($tenantId, $hotelId, $ownerUserId);
        $this->assertActorScope($actor, $tenantId);
        $platform = $this->platform($platform);
        $platformHotelId = $this->text($platformHotelId, 120, 'platform_hotel_id');
        $platformHotelName = $this->text($platformHotelName, 160, 'platform_hotel_name');

        return Db::transaction(function () use (
            $actor,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $hotel,
            $platform,
            $platformHotelId,
            $platformHotelName
        ): array {
            $profile = $this->profiles()->ensureProfile($hotelId, $ownerUserId, $platform);
            $profileId = trim((string)($profile['profile_id'] ?? ''));
            if (!str_starts_with($profileId, 'cbp_')) {
                throw new RuntimeException('hotel_three_source_profile_ensure_failed', 500);
            }

            $existingSource = $this->existingBrowserProfileSource(
                $tenantId,
                $hotelId,
                $ownerUserId,
                $platform
            );
            if ((int)($existingSource['id'] ?? 0) > 0) {
                $this->migrateLegacyProfileBinding(
                    $existingSource,
                    $profile,
                    $hotelId,
                    $platform,
                    $platformHotelId,
                    $profileId,
                    $ownerUserId
                );
            }

            $saved = $this->dataSources()->saveOperatorConfirmedBrowserProfileDataSource($actor, [
                'id' => (int)($existingSource['id'] ?? 0),
                'name' => sprintf('%s - %s Cloud Profile', (string)$hotel['name'], ucfirst($platform)),
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'data_type' => 'traffic',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'config' => [
                    'platform_hotel_id' => $platformHotelId,
                    'hotel_name' => $platformHotelName,
                    'profile_binding_key' => $profileId,
                    'stable_profile_id' => $profileId,
                    'profile_id' => $profileId,
                    'source_method' => 'cloud_browser_profile',
                    'platform_hotel_identity_source' => 'operator_confirmed_onboarding',
                    'platform_hotel_identity_checked_at' => date('Y-m-d H:i:s'),
                ] + ($platform === 'ctrip' ? [
                    'ctrip_hotel_id' => $platformHotelId,
                    'hotel_id' => $platformHotelId,
                ] : [
                    'store_id' => $platformHotelId,
                    'poi_id' => $platformHotelId,
                    'poi_name' => $platformHotelName,
                    'store_name' => $platformHotelName,
                ]),
                'secret' => [],
            ]);
            $sourceId = (int)($saved['id'] ?? 0);
            if ($sourceId <= 0) {
                throw new RuntimeException('hotel_three_source_data_source_save_failed', 500);
            }

            $readback = $this->exactBindingReadback(
                $sourceId,
                $tenantId,
                $hotelId,
                $ownerUserId,
                $platform,
                $platformHotelId,
                $platformHotelName,
                $profileId
            );

            return [
                'contract_version' => self::CONTRACT_VERSION,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'binding_status' => 'readback_verified',
                'readback_verified' => true,
                'data_source' => $readback,
                'profile' => $profile,
                'next_action' => ($profile['authorization_status'] ?? '') === CloudBrowserProfileService::READY_TO_COLLECT
                    ? 'profile_ready'
                    : 'request_login',
                'credentials_accepted' => false,
                'browser_started' => false,
                'collection_performed' => false,
                'message_sent' => false,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function existingBrowserProfileSource(
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $platform
    ): array {
        $rows = Db::name('platform_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('user_id', $ownerUserId)
            ->where('platform', $platform)
            ->where('enabled', 1)
            ->whereIn('ingestion_method', self::PROFILE_METHODS)
            ->where('status', '<>', 'disabled')
            ->field('id,config_json')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if (count($rows) > 1) {
            throw new RuntimeException('hotel_three_source_browser_profile_source_conflict', 409);
        }
        return is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $profile */
    private function migrateLegacyProfileBinding(
        array $source,
        array $profile,
        int $hotelId,
        string $platform,
        string $platformHotelId,
        string $profileId,
        int $actorUserId
    ): void {
        try {
            $this->bindings()->assertBound($hotelId, $platform, $profileId);
            return;
        } catch (RuntimeException) {
        }

        $config = $this->decodeConfig($source['config_json'] ?? null);
        $sourceProfileKey = $this->profileKey($platform, $config);
        $storedPlatformHotelId = trim((string)($config['platform_hotel_id'] ?? ''));
        $legacyKey = preg_match('/^[0-9]{1,120}$/D', $sourceProfileKey) === 1
            ? $sourceProfileKey
            : (hash_equals($profileId, $sourceProfileKey)
                && hash_equals($platformHotelId, $storedPlatformHotelId)
                && preg_match('/^[0-9]{1,120}$/D', $platformHotelId) === 1
                    ? $platformHotelId
                    : '');
        if ($legacyKey === '') {
            return;
        }
        try {
            $this->bindings()->assertBound($hotelId, $platform, $legacyKey);
        } catch (RuntimeException) {
            return;
        }

        $readyAt = strtotime(trim((string)($profile['ready_at'] ?? '')));
        $expiresAt = strtotime(trim((string)($profile['session_expires_at'] ?? '')));
        if (strtolower(trim((string)($profile['authorization_status'] ?? ''))) !== CloudBrowserProfileService::READY_TO_COLLECT
            || $readyAt === false
            || $readyAt > time()
            || $expiresAt === false
            || $expiresAt <= time()
        ) {
            throw new RuntimeException('hotel_three_source_cloud_profile_not_ready_for_legacy_rotation', 409);
        }
        $this->bindings()->rotateLegacyToCloudProfile(
            $hotelId,
            $platform,
            $legacyKey,
            $profileId,
            $actorUserId
        );
    }

    /** @return array<string,mixed> */
    private function exactBindingReadback(
        int $sourceId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $platform,
        string $platformHotelId,
        string $platformHotelName,
        string $profileId
    ): array {
        $row = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        if (!is_array($row)
            || (int)($row['tenant_id'] ?? 0) !== $tenantId
            || (int)($row['system_hotel_id'] ?? 0) !== $hotelId
            || (int)($row['user_id'] ?? 0) !== $ownerUserId
            || strtolower(trim((string)($row['platform'] ?? ''))) !== $platform
            || !in_array(strtolower(trim((string)($row['ingestion_method'] ?? ''))), self::PROFILE_METHODS, true)
            || (int)($row['enabled'] ?? 0) !== 1
            || strtolower(trim((string)($row['status'] ?? ''))) === 'disabled'
            || !$this->emptySecret($row['secret_json'] ?? null)
        ) {
            throw new RuntimeException('hotel_three_source_binding_readback_scope_mismatch', 409);
        }
        $config = $this->decodeConfig($row['config_json'] ?? null);
        $storedProfileId = $this->profileKey($platform, $config);
        if (!hash_equals($platformHotelId, trim((string)($config['platform_hotel_id'] ?? '')))
            || !hash_equals($platformHotelName, trim((string)($config['hotel_name'] ?? '')))
            || !hash_equals($profileId, $storedProfileId)
            || !str_starts_with($storedProfileId, 'cbp_')
        ) {
            throw new RuntimeException('hotel_three_source_binding_readback_value_mismatch', 409);
        }
        $binding = $this->bindings()->assertBound($hotelId, $platform, $profileId);
        $profile = $this->profiles()->status($hotelId, $ownerUserId, $platform)['profiles'][0] ?? null;
        if (!is_array($profile) || !hash_equals($profileId, trim((string)($profile['profile_id'] ?? '')))) {
            throw new RuntimeException('hotel_three_source_profile_readback_mismatch', 409);
        }

        return [
            'id' => $sourceId,
            'platform' => $platform,
            'platform_hotel_id' => $platformHotelId,
            'platform_hotel_name' => $platformHotelName,
            'ingestion_method' => 'browser_profile',
            'status' => strtolower(trim((string)$row['status'])),
            'enabled' => true,
            'profile_id' => $profileId,
            'profile_binding_status' => (string)($binding['binding_status'] ?? ''),
            'secret_stored' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function otaBindingStatus(
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $platform,
        mixed $profile
    ): array {
        try {
            $rows = Db::name('platform_data_sources')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('user_id', $ownerUserId)
                ->where('platform', $platform)
                ->whereIn('ingestion_method', self::PROFILE_METHODS)
                ->where('enabled', 1)
                ->where('status', '<>', 'disabled')
                ->order('id', 'desc')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return $this->missingBinding($platform, 'platform_data_sources_unavailable');
        }

        $profileId = is_array($profile) ? trim((string)($profile['profile_id'] ?? '')) : '';
        $selected = null;
        foreach ($rows as $row) {
            $config = $this->decodeConfig($row['config_json'] ?? null, false);
            $key = $this->profileKey($platform, $config);
            if ($profileId !== '' && hash_equals($profileId, $key)) {
                $selected = [$row, $config, $key];
                break;
            }
        }
        if (!is_array($selected)) {
            return $this->missingBinding(
                $platform,
                $rows === []
                    ? 'browser_profile_data_source_missing'
                    : 'cloud_profile_data_source_mismatch'
            );
        }

        [$row, $config, $key] = $selected;
        $exactProfile = $profileId !== '' && hash_equals($profileId, $key);
        try {
            $binding = $key !== '' ? $this->bindings()->assertBound($hotelId, $platform, $key) : null;
            $registered = is_array($binding);
        } catch (\Throwable) {
            $registered = false;
        }
        $verified = $key !== ''
            && $exactProfile
            && str_starts_with($key, 'cbp_')
            && $registered
            && $this->emptySecret($row['secret_json'] ?? null);

        return [
            'platform' => $platform,
            'binding_status' => $verified ? 'readback_verified' : 'invalid',
            'readback_verified' => $verified,
            'binding_type' => 'cloud_profile',
            'data_source_id' => (int)($row['id'] ?? 0),
            'platform_hotel_id' => trim((string)($config['platform_hotel_id'] ?? '')) ?: null,
            'platform_hotel_name' => trim((string)($config['hotel_name'] ?? '')) ?: null,
            'profile_id' => $key !== '' ? $key : null,
            'profile_exact_match' => $exactProfile,
            'secret_stored' => !$this->emptySecret($row['secret_json'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function pmsStatus(int $tenantId, int $hotelId): array
    {
        try {
            $summary = $this->pms()->selectionSummaries([$hotelId])[$hotelId] ?? [
                'binding_status' => 'unconfigured',
                'selected_provider' => null,
            ];
            $provider = (string)($summary['selected_provider'] ?? '');
            if (in_array($provider, [
                HotelPmsBindingService::PROVIDER_DINGDANDAO,
                HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD,
            ], true)) {
                $table = $provider === HotelPmsBindingService::PROVIDER_DINGDANDAO
                    ? 'dingdandao_pms_integrations'
                    : 'meituan_cloud_pms_integrations';
                $row = Db::name($table)
                    ->field('provider_hotel_id,provider_hotel_name')
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('provider', $provider)
                    ->where('status', 1)
                    ->find();
                if (is_array($row)) {
                    $summary['provider_hotel_id'] = trim((string)($row['provider_hotel_id'] ?? '')) ?: null;
                    $summary['provider_hotel_name'] = trim((string)($row['provider_hotel_name'] ?? '')) ?: null;
                }
            }
            return $summary;
        } catch (\Throwable) {
            return [
                'binding_status' => 'unknown',
                'selected_provider' => null,
                'failure_code' => 'hotel_pms_tables_unavailable',
            ];
        }
    }

    /** @return array<string,mixed> */
    private function profileStatus(int $hotelId, int $ownerUserId, string $platform): array
    {
        try {
            $profile = $this->profiles()->status($hotelId, $ownerUserId, $platform)['profiles'][0] ?? null;
            return [
                'platform' => $platform,
                'profile_status' => is_array($profile) ? (string)($profile['authorization_status'] ?? 'unknown') : 'missing',
                'ready' => is_array($profile)
                    && ($profile['authorization_status'] ?? '') === CloudBrowserProfileService::READY_TO_COLLECT,
                'profile' => $profile,
            ];
        } catch (\Throwable) {
            return [
                'platform' => $platform,
                'profile_status' => 'unknown',
                'ready' => false,
                'profile' => null,
                'failure_code' => 'cloud_browser_profiles_unavailable',
            ];
        }
    }

    /** @return array<string,mixed> */
    private function wechatStatus(int $hotelId, int $ownerUserId): array
    {
        try {
            return $this->wechat()->status($hotelId, $ownerUserId);
        } catch (\Throwable) {
            return [
                'hotel_id' => $hotelId,
                'binding_status' => 'unknown',
                'binding' => null,
                'failure_code' => 'wechat_binding_unavailable',
            ];
        }
    }

    /** @return array<string,mixed> */
    private function deliveryPlanStatus(int $tenantId, int $hotelId, int $ownerUserId): array
    {
        try {
            $rows = Db::name('manual_notifications')
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('created_by', $ownerUserId)
                ->order('id', 'desc')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                if (!ManualNotificationService::isStrictThreeSourceHourlyPlan($row)) {
                    continue;
                }
                return [
                    'plan_status' => 'configured',
                    'id' => (int)($row['id'] ?? 0),
                    'enabled' => (int)($row['enabled'] ?? 0) === 1,
                    'schedule_status' => (string)($row['schedule_status'] ?? 'saved_only'),
                    'trigger_type' => 'hourly_on_the_hour',
                ];
            }
            return ['plan_status' => 'missing', 'id' => null, 'enabled' => false, 'schedule_status' => null];
        } catch (\Throwable) {
            return [
                'plan_status' => 'unknown',
                'id' => null,
                'enabled' => false,
                'schedule_status' => null,
                'failure_code' => 'manual_notification_plan_unavailable',
            ];
        }
    }

    /** @param array<string,mixed> $hotel @return array<string,mixed> */
    private function collectionPlanStatus(array $hotel, int $ownerUserId): array
    {
        try {
            if ($this->collectionPlanLoader !== null) {
                $result = call_user_func($this->collectionPlanLoader, $hotel, $ownerUserId);
                if (!is_array($result)) {
                    throw new RuntimeException('hotel_collection_plan_readback_invalid');
                }
                return $result;
            }
            return (new HotelCollectionPlanService())->read($hotel, $ownerUserId);
        } catch (\Throwable) {
            return [
                'status' => 'unknown',
                'readback_verified' => false,
                'execution_authorized' => false,
                'failure_code' => 'hotel_collection_plan_unavailable',
            ];
        }
    }

    /** @param array<string,mixed> $plan */
    private function collectionPlanReady(array $plan): bool
    {
        return strtolower(trim((string)($plan['status'] ?? ''))) === 'active_ready'
            && strtolower(trim((string)($plan['plan_status'] ?? ''))) === 'active'
            && ($plan['enabled'] ?? false) === true
            && ($plan['active_slot'] ?? false) === true
            && ($plan['readback_verified'] ?? false) === true
            && ($plan['execution_authorized'] ?? false) === true;
    }

    /**
     * @param array<int,string> $otaPlatforms
     * @return array<string,array<string,mixed>>
     */
    private function sourceMap(array $pms, array $profiles, array $bindings, array $otaPlatforms): array
    {
        $sources = [];
        foreach ($otaPlatforms as $platform) {
            $profileProjection = $profiles[$platform] ?? [];
            $profile = is_array($profileProjection['profile'] ?? null)
                ? $profileProjection['profile']
                : null;
            $binding = $bindings[$platform] ?? $this->missingBinding($platform, 'browser_profile_data_source_missing');
            $authorizationStatus = (string)($profileProjection['profile_status'] ?? 'missing');
            $profileReady = ($profileProjection['ready'] ?? false) === true;
            $bindingReady = ($binding['readback_verified'] ?? false) === true;
            $sources[$platform] = [
                'platform' => $platform,
                'status' => $bindingReady
                    ? ($profileReady ? 'ready' : $authorizationStatus)
                    : 'missing_binding',
                'profile_ready' => $profileReady,
                'authorization_status' => $authorizationStatus,
                'profile' => $profile,
                'binding' => $binding,
                'platform_hotel_id' => $binding['platform_hotel_id'] ?? null,
                'platform_hotel_name' => $binding['platform_hotel_name'] ?? null,
                'detail' => $bindingReady
                    ? ($profileReady ? 'binding_and_profile_ready' : 'profile_login_required')
                    : (string)($binding['failure_code'] ?? 'binding_readback_missing'),
            ];
        }

        $provider = (string)($pms['selected_provider'] ?? '');
        $pmsPlatform = match ($provider) {
            HotelPmsBindingService::PROVIDER_DINGDANDAO => 'dingdandao',
            HotelPmsBindingService::PROVIDER_MEITUAN_CLOUD => 'meituan_cloud_pms',
            default => '',
        };
        if ($pmsPlatform !== '') {
            $profileProjection = $profiles[$pmsPlatform] ?? [];
            $profile = is_array($profileProjection['profile'] ?? null)
                ? $profileProjection['profile']
                : null;
            $authorizationStatus = (string)($profileProjection['profile_status'] ?? 'missing');
            $profileReady = ($profileProjection['ready'] ?? false) === true;
            $identityReady = trim((string)($pms['provider_hotel_id'] ?? '')) !== ''
                && trim((string)($pms['provider_hotel_name'] ?? '')) !== '';
            $binding = [
                'binding_status' => $identityReady ? 'readback_verified' : 'missing',
                'readback_verified' => $identityReady,
                'provider' => $provider,
                'provider_hotel_id' => $pms['provider_hotel_id'] ?? null,
                'provider_hotel_name' => $pms['provider_hotel_name'] ?? null,
                'platform_hotel_id' => $pms['provider_hotel_id'] ?? null,
                'platform_hotel_name' => $pms['provider_hotel_name'] ?? null,
            ];
            $sources[$pmsPlatform] = [
                'platform' => $pmsPlatform,
                'status' => $identityReady
                    ? ($profileReady ? 'ready' : $authorizationStatus)
                    : 'missing_binding',
                'profile_ready' => $profileReady,
                'authorization_status' => $authorizationStatus,
                'profile' => $profile,
                'binding' => $binding,
                'platform_hotel_id' => $pms['provider_hotel_id'] ?? null,
                'platform_hotel_name' => $pms['provider_hotel_name'] ?? null,
                'detail' => !$identityReady
                    ? 'pms_hotel_identity_missing'
                    : ($profileReady ? 'binding_and_profile_ready' : 'profile_login_required'),
            ];
        }

        return $sources;
    }

    /** @return array<int,string> */
    private function requiredOtaPlatforms(string $strategy): array
    {
        return match (strtolower(trim($strategy))) {
            'ctrip_only' => ['ctrip'],
            'meituan_only' => ['meituan'],
            'dual' => ['ctrip', 'meituan'],
            default => [],
        };
    }

    /**
     * @param array<int,string> $otaPlatforms
     * @return array<int,array{code:string,action:string}>
     */
    private function blockers(array $pms, array $profiles, array $bindings, array $otaPlatforms): array
    {
        $blockers = [];
        if (($pms['binding_status'] ?? '') === 'configured') {
            $profilePlatform = ($pms['selected_provider'] ?? '') === HotelPmsBindingService::PROVIDER_DINGDANDAO
                ? 'dingdandao'
                : 'meituan_cloud_pms';
            if (trim((string)($pms['provider_hotel_id'] ?? '')) === ''
                || trim((string)($pms['provider_hotel_name'] ?? '')) === ''
            ) {
                $blockers[] = ['code' => 'pms_binding_not_ready', 'action' => 'configure_pms'];
            }
            if (($profiles[$profilePlatform]['ready'] ?? false) !== true) {
                $blockers[] = ['code' => $profilePlatform . '_profile_not_ready', 'action' => 'request_' . $profilePlatform . '_login'];
            }
        } else {
            $blockers[] = ['code' => 'pms_binding_not_ready', 'action' => 'configure_pms'];
        }
        foreach ($otaPlatforms as $platform) {
            if (($bindings[$platform]['readback_verified'] ?? false) !== true) {
                $blockers[] = ['code' => $platform . '_binding_not_ready', 'action' => 'bind_' . $platform];
                continue;
            }
            if (($profiles[$platform]['ready'] ?? false) !== true) {
                $blockers[] = ['code' => $platform . '_profile_not_ready', 'action' => 'request_' . $platform . '_login'];
            }
        }
        return $blockers;
    }

    /** @return array<string,mixed> */
    private function hotel(int $tenantId, int $hotelId, int $ownerUserId): array
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $ownerUserId <= 0) {
            throw new InvalidArgumentException('hotel_three_source_scope_invalid');
        }
        $hotel = Db::name('hotels')->where('id', $hotelId)->find();
        if (!is_array($hotel)
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['status'] ?? 0) !== 1
        ) {
            throw new RuntimeException('hotel_three_source_hotel_scope_mismatch', 409);
        }
        return $hotel;
    }

    private function assertActorScope(object $actor, int $tenantId): void
    {
        $isSuperAdmin = method_exists($actor, 'isSuperAdmin') && $actor->isSuperAdmin();
        if (!$isSuperAdmin && (int)($actor->tenant_id ?? 0) !== $tenantId) {
            throw new RuntimeException('hotel_three_source_actor_scope_mismatch', 403);
        }
    }

    private function platform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, self::OTA_PLATFORMS, true)) {
            throw new InvalidArgumentException('hotel_three_source_platform_invalid');
        }
        return $platform;
    }

    private function text(string $value, int $maxLength, string $field): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('hotel_three_source_' . $field . '_invalid');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function decodeConfig(mixed $value, bool $strict = true): array
    {
        try {
            $decoded = is_array($value) ? $value : json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            if ($strict) {
                throw new RuntimeException('hotel_three_source_source_config_invalid', 409, $error);
            }
            return [];
        }
        if (!is_array($decoded)) {
            if ($strict) {
                throw new RuntimeException('hotel_three_source_source_config_invalid', 409);
            }
            return [];
        }
        return $decoded;
    }

    /** @param array<string,mixed> $config */
    private function profileKey(string $platform, array $config): string
    {
        $keys = [
            'profile_binding_key',
            'profileBindingKey',
            'stable_profile_id',
            'stableProfileId',
            'profile_id',
            'profileId',
            'browser_profile_id',
            'browserProfileId',
        ];
        $values = [];
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                $value = trim((string)$config[$key]);
                $values[$value] = true;
            }
        }
        return count($values) === 1 ? (string)array_key_first($values) : '';
    }

    private function emptySecret(mixed $value): bool
    {
        if ($value === null || trim((string)$value) === '') {
            return true;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) && $decoded === [];
    }

    /** @return array<string,mixed> */
    private function missingBinding(string $platform, string $code): array
    {
        return [
            'platform' => $platform,
            'binding_status' => 'missing',
            'readback_verified' => false,
            'data_source_id' => null,
            'failure_code' => $code,
        ];
    }

    private function profiles(): CloudBrowserProfileService
    {
        return $this->profileService ?? new CloudBrowserProfileService();
    }

    private function dataSources(): PlatformDataSyncService
    {
        return $this->dataSourceService ?? new PlatformDataSyncService();
    }

    private function bindings(): OtaProfileBindingService
    {
        return $this->bindingService ?? new OtaProfileBindingService();
    }

    private function pms(): HotelPmsBindingService
    {
        return $this->pmsBindingService ?? new HotelPmsBindingService();
    }

    private function wechat(): WechatNotificationBindingService
    {
        return $this->wechatBindingService ?? new WechatNotificationBindingService();
    }
}
