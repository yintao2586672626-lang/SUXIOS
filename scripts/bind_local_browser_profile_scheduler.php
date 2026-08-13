<?php
declare(strict_types=1);

use app\service\LocalBrowserProfileSchedulerBindingService;
use think\App;

require __DIR__ . '/../vendor/autoload.php';

(new App())->initialize();

/** @param array<int,string> $arguments @return array<string,mixed> */
function local_profile_scheduler_binding_options(array $arguments): array
{
    $options = [
        'tenant-id' => 0,
        'hotel-id' => 0,
        'user-id' => 0,
        'ctrip-source-id' => 0,
        'meituan-source-id' => 0,
        'device-id' => '',
        'execute' => false,
        'confirm' => '',
    ];
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--execute') {
            $options['execute'] = true;
            continue;
        }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($argument, 2), 2);
        if (array_key_exists($key, $options)) {
            $options[$key] = trim($value);
        }
    }
    foreach (['tenant-id', 'hotel-id', 'user-id', 'ctrip-source-id', 'meituan-source-id'] as $key) {
        $value = (string)$options[$key];
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('local_profile_scheduler_binding_scope_invalid');
        }
        $options[$key] = (int)$value;
    }
    if (preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D',
        (string)$options['device-id']
    ) !== 1) {
        throw new InvalidArgumentException('local_profile_scheduler_binding_scope_invalid');
    }
    return $options;
}

/** @param array<string,mixed> $receipt */
function local_profile_scheduler_binding_output(array $receipt, int $exitCode): never
{
    echo json_encode(
        $receipt,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), PHP_EOL;
    exit($exitCode);
}

try {
    $options = local_profile_scheduler_binding_options($argv);
    $tenantId = (int)$options['tenant-id'];
    $hotelId = (int)$options['hotel-id'];
    $userId = (int)$options['user-id'];
    $ctripSourceId = (int)$options['ctrip-source-id'];
    $meituanSourceId = (int)$options['meituan-source-id'];
    $deviceId = (string)$options['device-id'];
    $service = new LocalBrowserProfileSchedulerBindingService();
    $requiredConfirmation = LocalBrowserProfileSchedulerBindingService::executionConfirmation(
        $tenantId,
        $hotelId,
        $userId,
        $ctripSourceId,
        $meituanSourceId
    );

    if (($options['execute'] ?? false) !== true) {
        $receipt = $service->preflight(
            $tenantId,
            $hotelId,
            $userId,
            $ctripSourceId,
            $meituanSourceId,
            $deviceId
        );
        $receipt['required_execute_confirmation'] = $requiredConfirmation;
        local_profile_scheduler_binding_output(
            $receipt,
            ($receipt['binding_ready'] ?? false) === true ? 0 : 2
        );
    }

    if (!hash_equals($requiredConfirmation, (string)($options['confirm'] ?? ''))) {
        local_profile_scheduler_binding_output([
            'contract_version' => LocalBrowserProfileSchedulerBindingService::CONTRACT_VERSION,
            'mode' => 'execute',
            'status' => 'blocked',
            'binding_ready' => false,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'user_id' => $userId,
            'source_ids' => [
                'ctrip' => $ctripSourceId,
                'meituan' => $meituanSourceId,
            ],
            'blockers' => [['code' => 'local_profile_scheduler_binding_confirmation_mismatch']],
            'required_execute_confirmation' => $requiredConfirmation,
            'database_write_performed' => false,
            'ota_collection_performed' => false,
            'profile_opened' => false,
            'sensitive_values_exposed' => false,
        ], 2);
    }

    $receipt = $service->execute(
        $tenantId,
        $hotelId,
        $userId,
        $ctripSourceId,
        $meituanSourceId,
        $deviceId
    );
    local_profile_scheduler_binding_output(
        $receipt,
        ($receipt['binding_ready'] ?? false) === true
            && ($receipt['write']['readback_verified'] ?? false) === true
            ? 0
            : 2
    );
} catch (Throwable) {
    local_profile_scheduler_binding_output([
        'contract_version' => LocalBrowserProfileSchedulerBindingService::CONTRACT_VERSION,
        'mode' => in_array('--execute', $argv, true) ? 'execute' : 'preflight',
        'status' => 'blocked',
        'binding_ready' => false,
        'blockers' => [['code' => 'local_profile_scheduler_binding_cli_failed']],
        'database_write_performed' => false,
        'ota_collection_performed' => false,
        'profile_opened' => false,
        'sensitive_values_exposed' => false,
    ], 2);
}
