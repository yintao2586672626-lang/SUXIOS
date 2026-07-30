<?php
declare(strict_types=1);

namespace app\service;

/**
 * Keeps Ctrip collection recipes and evidence paths out of ordinary-account
 * responses. The browser receives business state; implementation metadata
 * remains server-side and is available only to the super-admin maintenance
 * surface.
 */
final class CtripImplementationExposurePolicy
{
    /** @var array<int, string> */
    private const PROFILE_FIELD_KEYS = [
        'id',
        'field_key',
        'field_name',
        'section',
        'data_type',
        'value_meaning',
        'value_type',
        'unit',
        'status',
        'enabled',
        'sample_verification_status',
        'sort_order',
        'update_time',
        'latest_sample_note',
    ];

    /** @var array<int, string> */
    private const PROFILE_MODULE_KEYS = [
        'id',
        'label',
        'enabled',
        'system',
        'sort_order',
        'primary_category',
        'field_count',
        'enabled_field_count',
    ];

    /** @var array<int, string> */
    private const CONFIG_SUMMARY_KEYS = [
        'id',
        'config_id',
        'name',
        'hotel_id',
        'system_hotel_id',
        'ctrip_hotel_id',
        'ctripHotelId',
        'ota_hotel_id',
        'platform_hotel_id',
        'hotel_room_count',
        'competitor_room_count',
        'capture_sections',
        'profile_sections',
        'credential_status',
        'credential_status_label',
        'credential_level',
        'credential_level_label',
        'has_cookies',
        'verification_status',
        'verification_status_label',
        'configuration_saved',
        'configuration_verified',
        'verified_at',
        'config_status',
        'update_time',
        'created_at',
        'history_count',
        'active_config_count',
        'duplicate_current_count',
        'duplicate_status',
    ];

    public static function canViewImplementation(mixed $user): bool
    {
        return is_object($user)
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin() === true;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    public static function profileFields(array $fields): array
    {
        return array_values(array_map(
            static fn(array $field): array => self::pick($field, self::PROFILE_FIELD_KEYS),
            array_values(array_filter($fields, 'is_array'))
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function profileModules(array $payload): array
    {
        $sanitize = static fn(mixed $modules): array => array_values(array_map(
            static fn(array $module): array => self::pick($module, self::PROFILE_MODULE_KEYS),
            array_values(array_filter(is_array($modules) ? $modules : [], 'is_array'))
        ));

        return [
            'modules' => $sanitize($payload['modules'] ?? []),
            'all_modules' => $sanitize($payload['all_modules'] ?? []),
            'implementation_visibility' => 'redacted',
            'collection_contract' => 'task_scoped',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     * @return array<int, array<string, mixed>>
     */
    public static function configList(array $configs): array
    {
        return array_values(array_map(
            static fn(array $config): array => self::config($config),
            array_values(array_filter($configs, 'is_array'))
        ));
    }

    /** @param array<string, mixed> $config */
    public static function config(array $config): array
    {
        return array_merge(self::pick($config, self::CONFIG_SUMMARY_KEYS), [
            'implementation_visibility' => 'redacted',
            'collection_contract' => 'task_scoped',
        ]);
    }

    /** @param array<string, mixed> $status */
    public static function profileStatus(array $status): array
    {
        return array_merge(self::pick($status, [
            'exists',
            'cookie_probe_requested',
            'login_probe_requested',
            'cookie_extractable',
            'cookie_count',
            'last_modified_at',
            'last_login_check_time',
            'status',
            'status_code',
            'current_status',
            'next_action',
        ]), [
            'implementation_visibility' => 'redacted',
            'collection_contract' => 'task_scoped',
        ]);
    }

    /** @param array<string, mixed> $payload */
    public static function cookieCaptureResult(array $payload): array
    {
        $result = self::pick($payload, [
            'status',
            'is_ready',
            'next_action',
            'warning',
            'saved_count',
            'row_count',
            'counts',
            'captured_counts',
            'save_status',
            'persistence_status',
            'standard_data_type_counts',
            'standard_section_counts',
            'request_count',
            'cookie_source',
            'error_count',
            'database_readback',
            'readback_verified',
        ]);

        $authStatus = is_array($payload['auth_status'] ?? null)
            ? self::pick($payload['auth_status'], ['ok', 'status', 'message'])
            : [];
        if ($authStatus !== []) {
            $result['auth_status'] = $authStatus;
        }

        $identity = is_array($payload['identity_check'] ?? null)
            ? self::pick($payload['identity_check'], ['ok', 'status', 'warning', 'message'])
            : [];
        if ($identity !== []) {
            $result['identity_check'] = $identity;
        }

        $result['implementation_visibility'] = 'redacted';
        $result['collection_contract'] = 'task_scoped';
        return $result;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private static function pick(array $source, array $keys): array
    {
        return array_intersect_key($source, array_fill_keys($keys, true));
    }
}
