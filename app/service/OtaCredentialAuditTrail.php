<?php
declare(strict_types=1);

namespace app\service;

use app\exception\OtaCredentialAuditException;
use app\model\OtaCredential;
use Throwable;
use think\facade\Db;

final class OtaCredentialAuditTrail
{
    private const TABLE = 'ota_credential_audit_logs';
    private const MAX_APPEND_ATTEMPTS = 3;
    private const VERSION_EVENTS = [
        'credential_baseline_imported',
        'credential_created',
        'credential_rotated',
    ];
    private const EVENT_TYPES = [
        'credential_baseline_imported',
        'credential_created',
        'credential_rotated',
        'credential_store',
        'execution_access_started',
        'execution_access',
        'metadata_verify_started',
        'metadata_verify',
        'credential_revoked',
        'credential_deleted',
    ];

    public function ensureBaseline(OtaCredential $credential): int
    {
        $recordVersion = $this->credentialRecordVersion($credential);
        $scopeVersion = $this->latestCredentialVersion($credential);
        if ($recordVersion > 0) {
            return $scopeVersion;
        }

        $nextVersion = $scopeVersion + 1;
        $this->appendForCredential(
            $credential,
            'credential_baseline_imported',
            'success',
            '',
            0,
            $nextVersion
        );
        return $nextVersion;
    }

    public function recordCredentialVersion(OtaCredential $credential, string $eventType, int $actorId): int
    {
        if (!in_array($eventType, ['credential_created', 'credential_rotated'], true)) {
            throw new OtaCredentialAuditException('OTA credential audit event is invalid.');
        }

        $currentVersion = $this->latestCredentialVersion($credential);
        $recordVersion = $this->credentialRecordVersion($credential);
        $nextVersion = $currentVersion + 1;
        if ($eventType === 'credential_created' && $recordVersion !== 0) {
            throw new OtaCredentialAuditException('OTA credential creation audit already exists.');
        }
        if ($eventType === 'credential_rotated' && $recordVersion === 0) {
            throw new OtaCredentialAuditException('OTA credential rotation requires a baseline version.');
        }

        $this->appendForCredential($credential, $eventType, 'success', '', $actorId, $nextVersion);
        return $nextVersion;
    }

    public function recordScopeEvent(
        ?OtaCredential $credential,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $configId,
        string $eventType,
        string $outcome,
        string $failureCode = '',
        int $actorId = 0
    ): array {
        $credentialId = $credential ? (int)$credential->id : 0;
        $credentialVersion = $credential ? $this->latestCredentialVersion($credential) : 0;
        $payloadDigest = $credential
            ? hash('sha256', (string)$credential->encrypted_payload)
            : '';

        return $this->append(
            $credentialId,
            $tenantId,
            $hotelId,
            $platform,
            $configId,
            $eventType,
            $outcome,
            $failureCode,
            $actorId,
            $credentialVersion,
            $payloadDigest
        );
    }

    public function verifyScopeChain(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $configId
    ): bool {
        try {
            $scope = $this->normalizeScope($tenantId, $hotelId, $platform, $configId);
            $rows = Db::name(self::TABLE)
                ->where('tenant_id', $scope['tenant_id'])
                ->where('system_hotel_id', $scope['system_hotel_id'])
                ->where('platform', $scope['platform'])
                ->where('config_id_hash', $scope['config_id_hash'])
                ->order('event_sequence', 'asc')
                ->select()
                ->toArray();
        } catch (Throwable) {
            return false;
        }

        if ($rows === []) {
            return false;
        }

        $previousHash = '';
        $expectedSequence = 1;
        $credentialVersion = 0;
        foreach ($rows as $row) {
            if ((int)($row['event_sequence'] ?? 0) !== $expectedSequence) {
                return false;
            }
            if (!hash_equals($previousHash, (string)($row['previous_entry_hash'] ?? ''))) {
                return false;
            }

            $eventType = (string)($row['event_type'] ?? '');
            $rowVersion = (int)($row['credential_version'] ?? 0);
            if (in_array($eventType, self::VERSION_EVENTS, true)) {
                if ($rowVersion !== $credentialVersion + 1) {
                    return false;
                }
                $credentialVersion = $rowVersion;
            } elseif ($rowVersion !== 0 && $rowVersion !== $credentialVersion) {
                return false;
            }

            $expectedHash = $this->entryHash($this->canonicalFromRow($row));
            if (!hash_equals($expectedHash, (string)($row['entry_hash'] ?? ''))) {
                return false;
            }
            $previousHash = (string)$row['entry_hash'];
            $expectedSequence++;
        }

        return true;
    }

    private function appendForCredential(
        OtaCredential $credential,
        string $eventType,
        string $outcome,
        string $failureCode,
        int $actorId,
        int $credentialVersion
    ): array {
        return $this->append(
            (int)$credential->id,
            (int)$credential->tenant_id,
            (int)$credential->system_hotel_id,
            (string)$credential->platform,
            (string)$credential->config_id,
            $eventType,
            $outcome,
            $failureCode,
            $actorId,
            $credentialVersion,
            hash('sha256', (string)$credential->encrypted_payload)
        );
    }

    private function append(
        int $credentialId,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $configId,
        string $eventType,
        string $outcome,
        string $failureCode,
        int $actorId,
        int $credentialVersion,
        string $payloadDigest
    ): array {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new OtaCredentialAuditException('OTA credential audit event is invalid.');
        }
        if (!in_array($outcome, ['success', 'failed'], true)) {
            throw new OtaCredentialAuditException('OTA credential audit outcome is invalid.');
        }
        $failureCode = strtolower(trim($failureCode));
        if ($failureCode !== '' && preg_match('/^[a-z0-9_]{1,80}$/D', $failureCode) !== 1) {
            throw new OtaCredentialAuditException('OTA credential audit failure code is invalid.');
        }

        $scope = $this->normalizeScope($tenantId, $hotelId, $platform, $configId);
        $this->assertSchemaAvailable();
        $lastFailure = null;

        for ($attempt = 1; $attempt <= self::MAX_APPEND_ATTEMPTS; $attempt++) {
            try {
                $latest = Db::name(self::TABLE)
                    ->where('tenant_id', $scope['tenant_id'])
                    ->where('system_hotel_id', $scope['system_hotel_id'])
                    ->where('platform', $scope['platform'])
                    ->where('config_id_hash', $scope['config_id_hash'])
                    ->order('event_sequence', 'desc')
                    ->lock(true)
                    ->find();
                $occurredAt = date('Y-m-d H:i:s');
                $canonical = [
                    'credential_id' => max(0, $credentialId),
                    'tenant_id' => $scope['tenant_id'],
                    'system_hotel_id' => $scope['system_hotel_id'],
                    'platform' => $scope['platform'],
                    'config_id_hash' => $scope['config_id_hash'],
                    'event_sequence' => (int)($latest['event_sequence'] ?? 0) + 1,
                    'credential_version' => max(0, $credentialVersion),
                    'event_type' => $eventType,
                    'outcome' => $outcome,
                    'failure_code' => $failureCode,
                    'actor_id' => max(0, $actorId),
                    'payload_digest' => preg_match('/^[a-f0-9]{64}$/D', $payloadDigest) === 1 ? $payloadDigest : '',
                    'previous_entry_hash' => (string)($latest['entry_hash'] ?? ''),
                    'occurred_at' => $occurredAt,
                ];
                $row = $canonical + ['entry_hash' => $this->entryHash($canonical)];
                $row['id'] = (int)Db::name(self::TABLE)->insertGetId($row);
                return $row;
            } catch (OtaCredentialAuditException $error) {
                throw $error;
            } catch (Throwable $error) {
                $lastFailure = $error;
                if ($attempt < self::MAX_APPEND_ATTEMPTS && $this->isUniqueConflict($error)) {
                    continue;
                }
                break;
            }
        }

        throw new OtaCredentialAuditException(
            'OTA credential audit append failed.',
            503,
            $lastFailure
        );
    }

    private function latestCredentialVersion(OtaCredential $credential): int
    {
        $this->assertSchemaAvailable();
        $scope = $this->normalizeScope(
            (int)$credential->tenant_id,
            (int)$credential->system_hotel_id,
            (string)$credential->platform,
            (string)$credential->config_id
        );
        try {
            return (int)Db::name(self::TABLE)
                ->where('tenant_id', $scope['tenant_id'])
                ->where('system_hotel_id', $scope['system_hotel_id'])
                ->where('platform', $scope['platform'])
                ->where('config_id_hash', $scope['config_id_hash'])
                ->whereIn('event_type', self::VERSION_EVENTS)
                ->where('outcome', 'success')
                ->max('credential_version');
        } catch (Throwable $error) {
            throw new OtaCredentialAuditException('OTA credential audit version lookup failed.', 503, $error);
        }
    }

    private function credentialRecordVersion(OtaCredential $credential): int
    {
        $this->assertSchemaAvailable();
        try {
            return (int)Db::name(self::TABLE)
                ->where('credential_id', (int)$credential->id)
                ->whereIn('event_type', self::VERSION_EVENTS)
                ->where('outcome', 'success')
                ->max('credential_version');
        } catch (Throwable $error) {
            throw new OtaCredentialAuditException('OTA credential audit record version lookup failed.', 503, $error);
        }
    }

    private function assertSchemaAvailable(): void
    {
        try {
            Db::query('SELECT 1 FROM `' . self::TABLE . '` LIMIT 1');
        } catch (Throwable $error) {
            throw new OtaCredentialAuditException(
                'OTA credential audit schema is unavailable; run the registered migration first.',
                503,
                $error
            );
        }
    }

    /** @return array{tenant_id:int,system_hotel_id:int,platform:string,config_id_hash:string} */
    private function normalizeScope(int $tenantId, int $hotelId, string $platform, string $configId): array
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            $platform = 'invalid';
        }

        return [
            'tenant_id' => max(0, $tenantId),
            'system_hotel_id' => max(0, $hotelId),
            'platform' => $platform,
            'config_id_hash' => hash('sha256', $configId),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, int|string> */
    private function canonicalFromRow(array $row): array
    {
        return [
            'credential_id' => (int)($row['credential_id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'system_hotel_id' => (int)($row['system_hotel_id'] ?? 0),
            'platform' => (string)($row['platform'] ?? ''),
            'config_id_hash' => (string)($row['config_id_hash'] ?? ''),
            'event_sequence' => (int)($row['event_sequence'] ?? 0),
            'credential_version' => (int)($row['credential_version'] ?? 0),
            'event_type' => (string)($row['event_type'] ?? ''),
            'outcome' => (string)($row['outcome'] ?? ''),
            'failure_code' => (string)($row['failure_code'] ?? ''),
            'actor_id' => (int)($row['actor_id'] ?? 0),
            'payload_digest' => (string)($row['payload_digest'] ?? ''),
            'previous_entry_hash' => (string)($row['previous_entry_hash'] ?? ''),
            'occurred_at' => (string)($row['occurred_at'] ?? ''),
        ];
    }

    /** @param array<string, int|string> $canonical */
    private function entryHash(array $canonical): string
    {
        try {
            return hash('sha256', json_encode(
                $canonical,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        } catch (Throwable $error) {
            throw new OtaCredentialAuditException('OTA credential audit hash generation failed.', 503, $error);
        }
    }

    private function isUniqueConflict(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'constraint failed');
    }
}
