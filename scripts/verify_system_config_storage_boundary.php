<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App(dirname(__DIR__)))->initialize();

$generalKeys = array_values(array_unique(array_map(
    'strval',
    Db::name('system_config')->order('config_key', 'asc')->column('config_key')
)));
$otaKeys = array_values(array_unique(array_map(
    'strval',
    Db::name('system_configs')->order('config_key', 'asc')->column('config_key')
)));
$overlap = array_values(array_intersect($generalKeys, $otaKeys));
$singularForbiddenKey = static function (string $key): bool {
    return in_array($key, ['ctrip_config_list', 'meituan_config_list'], true)
        || str_starts_with($key, 'data_config_')
        || str_starts_with($key, 'online_data_cookies_');
};
$otaMetadataKey = static function (string $key): bool {
    return in_array($key, ['ctrip_config_list', 'meituan_config_list'], true)
        || preg_match('/^(?:ctrip|meituan|ota)_/', $key) === 1
        || str_starts_with($key, 'data_config_')
        || str_starts_with($key, 'online_data_cookies_');
};
$generalOtaKeys = array_values(array_filter($generalKeys, $singularForbiddenKey));
$otaGeneralKeys = array_values(array_filter($otaKeys, static fn(string $key): bool => !$otaMetadataKey($key)));
$ready = $overlap === [] && $generalOtaKeys === [] && $otaGeneralKeys === [];

echo json_encode([
    'status' => $ready ? 'ready' : 'incomplete',
    'boundary' => [
        'system_config' => 'public_and_general_application_settings',
        'system_configs' => 'ota_metadata_only_no_plaintext_credentials',
    ],
    'violations' => [
        'duplicate_keys' => $overlap,
        'ota_keys_in_system_config' => $generalOtaKeys,
        'general_keys_in_system_configs' => $otaGeneralKeys,
    ],
    'sensitive_value_policy' => 'keys_only_no_values_printed',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

exit($ready ? 0 : 2);
