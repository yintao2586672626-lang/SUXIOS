<?php
declare(strict_types=1);
namespace app\service;
use app\exception\OtaCredentialAuditException;
use app\model\Hotel;
use app\model\OtaCredential;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class OtaCredentialVault
{
    private OtaCredentialEnvelope $envelope;
    private OtaCredentialAuditTrail $auditTrail;
    private string $keyId;

    public function __construct(
        ?OtaCredentialEnvelope $envelope = null,
        ?string $keyId = null,
        ?OtaCredentialAuditTrail $auditTrail = null
    )
    {
        $this->envelope = $envelope ?? new OtaCredentialEnvelope((string) env('OTA_CREDENTIAL_KEY_B64', ''), (string) env('OTA_CREDENTIAL_KEY_ID', ''));
        $this->auditTrail = $auditTrail ?? new OtaCredentialAuditTrail();
        $this->keyId = $keyId ?? $this->envelope->keyId();
        if (trim($this->keyId) === '' || !hash_equals($this->envelope->keyId(), $this->keyId)) {
            throw new RuntimeException('OTA credential key identifier does not match envelope.');
        }
    }

    private function scope(int $t, int $h, string $p, string $c): string
    {
        $this->validate($p, $c);
        return "tenant:$t:hotel:$h:$p:$c";
    }

    private function validate(string $p, string $c): void
    {
        if (!in_array($p, ['ctrip', 'meituan'], true) || preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $c) !== 1) {
            throw new RuntimeException('Invalid credential locator.');
        }
    }

    private function requireHotelScope(int $tenantId, int $hotelId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new RuntimeException('Hotel scope not found.');
        }

        $found = Hotel::runInTenantScope(
            $tenantId,
            static fn(): bool => (bool)Hotel::where('id', $hotelId)
                ->where('tenant_id', $tenantId)
                ->find()
        );
        if (!$found) {
            throw new RuntimeException('Hotel scope not found.');
        }
    }

    private function locate(int $t, int $h, string $p, string $c, bool $allowRevoked = false, bool $lock = false): OtaCredential
    {
        $this->scope($t, $h, $p, $c);
        $this->requireHotelScope($t, $h);
        $query = OtaCredential::where('tenant_id', $t)->where('system_hotel_id', $h)->where('platform', $p)->where('config_id', $c);
        $r = $query->lock($lock)->find();
        if (!$r) {
            throw new RuntimeException('Credential not found.');
        }
        if (!$allowRevoked && (string) $r->credential_status !== 'ready') {
            throw new RuntimeException('Credential is not ready for execution.');
        }
        return $r;
    }
    public function store(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $configId,
        array $payload,
        int $actorId
    ): array {
        $record = $this->transactionalCredentialOperation(
            function () use ($tenantId, $hotelId, $platform, $configId, $payload, $actorId): OtaCredential {
                $scope = $this->scope($tenantId, $hotelId, $platform, $configId);
                $this->requireHotelScope($tenantId, $hotelId);
                $now = date('Y-m-d H:i:s');
                $data = [
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'platform' => $platform,
                    'config_id' => $configId,
                    'encrypted_payload' => $this->envelope->encrypt($payload, $scope),
                    'payload_version' => 1,
                    'key_id' => $this->keyId,
                    'secret_mask' => $this->secretMask($payload),
                    'credential_status' => 'ready',
                    'created_by' => $actorId,
                    'rotated_at' => $now,
                    'last_used_at' => null,
                    'revoked_at' => null,
                    'update_time' => $now,
                ];
                $record = OtaCredential::where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', $platform)
                    ->where('config_id', $configId)
                    ->lock(true)
                    ->find();
                if ($record) {
                    $this->auditTrail->ensureBaseline($record);
                    $record->save($data);
                    $this->auditTrail->recordCredentialVersion($record, 'credential_rotated', $actorId);
                    return $record;
                }

                $data['create_time'] = $now;
                $record = OtaCredential::create($data);
                $this->auditTrail->recordCredentialVersion($record, 'credential_created', $actorId);
                return $record;
            },
            $tenantId,
            $hotelId,
            $platform,
            $configId,
            'credential_store',
            $actorId,
            'Credential audit unavailable; credential change was not committed.'
        );

        return $this->meta($record);
    }
    private function meta(OtaCredential $r): array
    {
        return [
            'credential_ref' => (int) $r->id,
            'tenant_id' => (int) $r->tenant_id,
            'system_hotel_id' => (int) $r->system_hotel_id,
            'platform' => (string) $r->platform,
            'config_id' => (string) $r->config_id,
            'payload_version' => (int) $r->payload_version,
            'key_id' => (string) $r->key_id,
            'secret_mask' => (string) $r->secret_mask,
            'credential_status' => (string) $r->credential_status,
            'rotated_at' => $r->rotated_at,
            'last_used_at' => $r->last_used_at,
            'revoked_at' => $r->revoked_at,
            'create_time' => $r->create_time,
            'update_time' => $r->update_time,
        ];
    }

    public function metadata(int $t, int $h, string $p, string $c): array
    {
        return $this->meta($this->locate($t, $h, $p, $c, true));
    }

    /**
     * Inspect whether a scoped credential is structurally executable without
     * decrypting or releasing any credential material.
     */
    public function inspectExecutionMetadataWithoutDecryption(int $t, int $h, string $p, string $c): array
    {
        $record = $this->locate($t, $h, $p, $c);
        if ((int)$record->payload_version !== 1
            || !hash_equals($this->keyId, (string)$record->key_id)
        ) {
            throw new RuntimeException('Credential cryptographic metadata is not executable.');
        }

        return $this->meta($record);
    }

    public function withPayloadForExecution(int $t, int $h, string $p, string $c, callable $consumer): mixed
    {
        $actorId = $this->currentActorId();
        $this->prepareCredentialAccess($t, $h, $p, $c, 'execution_access', $actorId);
        $payload = $this->transactionalCredentialOperation(
            function () use ($t, $h, $p, $c, $actorId): array {
                $record = $this->locate($t, $h, $p, $c, false, true);
                $payload = $this->payloadForExecution($record, $this->scope($t, $h, $p, $c));
                $record->last_used_at = date('Y-m-d H:i:s');
                $record->save();
                $this->auditTrail->recordScopeEvent(
                    $record,
                    $t,
                    $h,
                    $p,
                    $c,
                    'execution_access',
                    'success',
                    '',
                    $actorId
                );
                return $payload;
            },
            $t,
            $h,
            $p,
            $c,
            'execution_access',
            $actorId,
            'Credential audit unavailable; credential payload was not released.'
        );

        return $consumer($payload);
    }

    public function verifiedMetadataForExecution(int $t, int $h, string $p, string $c): array
    {
        $actorId = $this->currentActorId();
        $this->prepareCredentialAccess($t, $h, $p, $c, 'metadata_verify', $actorId);
        $record = $this->transactionalCredentialOperation(
            function () use ($t, $h, $p, $c, $actorId): OtaCredential {
                $record = $this->locate($t, $h, $p, $c, false, true);
                $this->payloadForExecution($record, $this->scope($t, $h, $p, $c));
                $this->auditTrail->recordScopeEvent(
                    $record,
                    $t,
                    $h,
                    $p,
                    $c,
                    'metadata_verify',
                    'success',
                    '',
                    $actorId
                );
                return $record;
            },
            $t,
            $h,
            $p,
            $c,
            'metadata_verify',
            $actorId,
            'Credential audit unavailable; credential verification was not released.'
        );

        return $this->meta($record);
    }

    /** @return array<string|int, mixed> */
    private function payloadForExecution(OtaCredential $record, string $scope): array
    {
        if ((int)$record->payload_version !== 1
            || !hash_equals($this->keyId, (string)$record->key_id)
        ) {
            throw new RuntimeException('Credential cryptographic metadata is not executable.');
        }
        return $this->envelope->decrypt((string)$record->encrypted_payload, $scope);
    }

    public function revoke(int $t, int $h, string $p, string $c): array
    {
        $actorId = $this->currentActorId();
        $record = $this->transactionalCredentialOperation(
            function () use ($t, $h, $p, $c, $actorId): OtaCredential {
                $record = $this->locate($t, $h, $p, $c, true, true);
                $this->auditTrail->ensureBaseline($record);
                if ((string)$record->credential_status !== 'revoked'
                    || (string)$record->encrypted_payload !== ''
                    || (string)$record->secret_mask !== ''
                    || $record->revoked_at === null
                ) {
                    $record->credential_status = 'revoked';
                    $record->encrypted_payload = '';
                    $record->secret_mask = '';
                    $record->revoked_at = $record->revoked_at ?? date('Y-m-d H:i:s');
                    $record->save();
                }
                $this->auditTrail->recordScopeEvent(
                    $record,
                    $t,
                    $h,
                    $p,
                    $c,
                    'credential_revoked',
                    'success',
                    '',
                    $actorId
                );
                return $record;
            },
            $t,
            $h,
            $p,
            $c,
            'credential_revoked',
            $actorId,
            'Credential audit unavailable; revocation was not committed.'
        );

        return $this->meta($record);
    }

    public function delete(int $t, int $h, string $p, string $c): bool
    {
        $actorId = $this->currentActorId();
        return $this->transactionalCredentialOperation(
            function () use ($t, $h, $p, $c, $actorId): bool {
                $this->scope($t, $h, $p, $c);
                $this->requireHotelScope($t, $h);
                $record = OtaCredential::where('tenant_id', $t)
                    ->where('system_hotel_id', $h)
                    ->where('platform', $p)
                    ->where('config_id', $c)
                    ->lock(true)
                    ->find();
                if (!$record) {
                    $this->auditTrail->recordScopeEvent(
                        null,
                        $t,
                        $h,
                        $p,
                        $c,
                        'credential_deleted',
                        'failed',
                        'credential_not_found',
                        $actorId
                    );
                    return false;
                }
                $this->auditTrail->ensureBaseline($record);
                $deleted = (bool)$record->delete();
                $this->auditTrail->recordScopeEvent(
                    $record,
                    $t,
                    $h,
                    $p,
                    $c,
                    'credential_deleted',
                    $deleted ? 'success' : 'failed',
                    $deleted ? '' : 'credential_delete_failed',
                    $actorId
                );
                return $deleted;
            },
            $t,
            $h,
            $p,
            $c,
            'credential_deleted',
            $actorId,
            'Credential audit unavailable; deletion was not committed.'
        );
    }

    private function prepareCredentialAccess(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $configId,
        string $eventType,
        int $actorId
    ): void {
        $this->transactionalCredentialOperation(
            function () use ($tenantId, $hotelId, $platform, $configId, $eventType, $actorId): void {
                $record = $this->locate($tenantId, $hotelId, $platform, $configId, false, true);
                $this->auditTrail->ensureBaseline($record);
                $this->auditTrail->recordScopeEvent(
                    $record,
                    $tenantId,
                    $hotelId,
                    $platform,
                    $configId,
                    $eventType . '_started',
                    'success',
                    '',
                    $actorId
                );
            },
            $tenantId,
            $hotelId,
            $platform,
            $configId,
            $eventType,
            $actorId,
            'Credential audit unavailable; credential access was blocked before decryption.'
        );
    }

    private function transactionalCredentialOperation(
        callable $operation,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $configId,
        string $failureEventType,
        int $actorId,
        string $auditFailureMessage
    ): mixed {
        try {
            return Db::transaction($operation);
        } catch (Throwable $error) {
            $this->recordFailureAfterRollback(
                $tenantId,
                $hotelId,
                $platform,
                $configId,
                $failureEventType,
                $error,
                $actorId
            );
            if ($error instanceof OtaCredentialAuditException) {
                throw new RuntimeException($auditFailureMessage, 503, $error);
            }
            throw $error;
        }
    }

    private function recordFailureAfterRollback(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $configId,
        string $eventType,
        Throwable $originalError,
        int $actorId
    ): void {
        try {
            Db::transaction(function () use (
                $tenantId,
                $hotelId,
                $platform,
                $configId,
                $eventType,
                $originalError,
                $actorId
            ): void {
                $record = OtaCredential::where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', $platform)
                    ->where('config_id', $configId)
                    ->lock(true)
                    ->find();
                $this->auditTrail->recordScopeEvent(
                    $record ?: null,
                    $tenantId,
                    $hotelId,
                    $platform,
                    $configId,
                    $eventType,
                    'failed',
                    $this->auditFailureCode($originalError, $eventType),
                    $actorId
                );
            });
        } catch (Throwable) {
            // The original business/audit exception remains authoritative.
            // Never replace it with a secondary failure while recording it.
        }
    }

    private function auditFailureCode(Throwable $error, string $eventType): string
    {
        if ($error instanceof OtaCredentialAuditException) {
            return 'audit_write_failed';
        }
        $message = strtolower($error->getMessage());
        return match (true) {
            str_contains($message, 'invalid credential locator') => 'invalid_locator',
            str_contains($message, 'hotel scope') => 'hotel_scope_not_found',
            str_contains($message, 'not found') => 'credential_not_found',
            str_contains($message, 'not ready') => 'credential_not_ready',
            str_contains($message, 'cryptographic metadata') => 'cryptographic_metadata_invalid',
            in_array($eventType, ['execution_access', 'metadata_verify'], true) => 'decrypt_failed',
            str_contains($message, 'decrypt'),
            str_contains($message, 'ciphertext'),
            str_contains($message, 'authentication tag') => 'decrypt_failed',
            default => 'credential_operation_failed',
        };
    }

    /** @param array<string, mixed> $payload */
    private function secretMask(array $payload): string
    {
        foreach (['cookies', 'cookie', 'token', 'spidertoken', 'mtgsig'] as $key) {
            if (empty($payload[$key])) {
                continue;
            }
            $value = $payload[$key];
            $secret = is_scalar($value)
                ? (string)$value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $length = strlen((string)$secret);
            return $length <= 8
                ? str_repeat('*', $length ?: 1)
                : substr((string)$secret, 0, 2) . '****' . substr((string)$secret, -2);
        }

        return '';
    }

    private function currentActorId(): int
    {
        try {
            return max(0, (int)(request()->user->id ?? 0));
        } catch (Throwable) {
            return 0;
        }
    }
}



