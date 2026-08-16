<?php
declare(strict_types=1);

use app\service\OtaPlatformHotelIdentityClaimService;
use think\App;

require __DIR__ . '/../vendor/autoload.php';

(new App())->initialize();

/** @param array<int,string> $arguments @return array<string,mixed> */
function canonical_identity_claim_options(array $arguments): array
{
    $options = [
        'tenant-id' => 0,
        'hotel-id' => 0,
        'source-id' => 0,
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
    foreach (['tenant-id', 'hotel-id', 'source-id'] as $key) {
        $value = (string)$options[$key];
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('canonical_identity_claim_scope_invalid');
        }
        $options[$key] = (int)$value;
    }
    return $options;
}

/** @param array<string,mixed> $receipt */
function canonical_identity_claim_output(array $receipt, int $exitCode): never
{
    echo json_encode(
        $receipt,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), PHP_EOL;
    exit($exitCode);
}

try {
    $options = canonical_identity_claim_options($argv);
    $tenantId = (int)$options['tenant-id'];
    $hotelId = (int)$options['hotel-id'];
    $sourceId = (int)$options['source-id'];
    $service = new OtaPlatformHotelIdentityClaimService();
    $requiredConfirmation = OtaPlatformHotelIdentityClaimService::executionConfirmation(
        $tenantId,
        $hotelId,
        $sourceId
    );

    if (($options['execute'] ?? false) !== true) {
        $receipt = $service->preflight($tenantId, $hotelId, $sourceId);
        $receipt['required_execute_confirmation'] = $requiredConfirmation;
        canonical_identity_claim_output($receipt, ($receipt['claim_ready'] ?? false) === true ? 0 : 2);
    }
    if (!hash_equals($requiredConfirmation, (string)($options['confirm'] ?? ''))) {
        canonical_identity_claim_output([
            'contract_version' => OtaPlatformHotelIdentityClaimService::CONTRACT_VERSION,
            'mode' => 'execute',
            'status' => 'blocked',
            'claim_ready' => false,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'data_source_id' => $sourceId,
            'platform' => OtaPlatformHotelIdentityClaimService::PLATFORM,
            'blockers' => [['code' => 'canonical_identity_claim_confirmation_mismatch']],
            'required_execute_confirmation' => $requiredConfirmation,
            'sensitive_values_exposed' => false,
        ], 2);
    }

    $receipt = $service->execute($tenantId, $hotelId, $sourceId);
    canonical_identity_claim_output($receipt, ($receipt['claim_ready'] ?? false) === true ? 0 : 2);
} catch (Throwable) {
    canonical_identity_claim_output([
        'contract_version' => OtaPlatformHotelIdentityClaimService::CONTRACT_VERSION,
        'mode' => in_array('--execute', $argv, true) ? 'execute' : 'preflight',
        'status' => 'blocked',
        'claim_ready' => false,
        'blockers' => [['code' => 'canonical_identity_claim_cli_failed']],
        'sensitive_values_exposed' => false,
    ], 2);
}
