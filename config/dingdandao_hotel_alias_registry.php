<?php
declare(strict_types=1);

/**
 * User-confirmed system-to-provider hotel-name aliases.
 *
 * This registry is source-controlled evidence only. Provider hotel IDs,
 * credentials, and browser session material belong in controlled runtime
 * configuration and must never be added here.
 */
return [
    'schema_version' => 'suxios_hotel_provider_alias_registry.v1',
    'version' => '2026-07-27.1',
    'aliases' => [
        [
            'tenant_id' => 1,
            'hotel_id' => 5,
            'system_name' => '敦煌漠蓝新',
            'provider' => 'dingdandao',
            'provider_name' => '敦煌漠蓝',
            'status' => 'user_confirmed',
            'confirmed_date' => '2026-07-27',
            'source_reference' => 'user_explicit_confirmation',
        ],
    ],
];
