<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Verifies the gateway-owned append-only receipt chain.
 *
 * The chain path is deployment-owned configuration. Callers may identify one
 * receipt but can never provide receipt content or redirect verification to a
 * caller-controlled file.
 */
final class CloudBrowserReceiptChainVerifier
{
    private const DEFAULT_CHAIN_PATH =
        '/var/lib/suxios-cloud-browser/receipts/chain.jsonl';
    private const MAX_CHAIN_BYTES = 8_388_608;
    private const MAX_CHAIN_RECORDS = 20_000;
    private const MAX_RECORD_BYTES = 65_536;

    private string $chainPath;

    public function __construct(?string $trustedChainPath = null)
    {
        $configured = trim((string)(
            $trustedChainPath
                ?? getenv('SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN')
                ?: self::DEFAULT_CHAIN_PATH
        ));
        if ($configured === '' || str_contains($configured, "\0")) {
            throw new RuntimeException(
                'cloud_browser_receipt_chain_path_invalid'
            );
        }
        $this->chainPath = $configured;
    }

    /**
     * @return array<string,mixed>
     */
    public function verifyDingdandaoBindingLeaseClosed(
        string $receiptId,
        string $receiptHash,
        string $profileLeaseId,
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $targetDate,
        string $providerHotelIdFingerprint,
        DateTimeImmutable $loginVerifiedAt,
        DateTimeImmutable $now
    ): array {
        return $this->verifyDingdandaoBindingReceipt(
            $receiptId,
            $receiptHash,
            $profileLeaseId,
            $profilePublicId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $providerHotelIdFingerprint,
            $loginVerifiedAt,
            $now,
            $targetDate,
            true
        );
    }

    /**
     * Revalidates the durable activation evidence before a collection claim.
     * Unlike activation, a binding receipt may remain valid for the rest of
     * the verified browser session, so only chronology and exact scope are
     * checked here; the 30-minute activation freshness window is not reused.
     *
     * @return array<string,mixed>
     */
    public function verifyDingdandaoBindingForCollection(
        string $receiptId,
        string $receiptHash,
        string $profileLeaseId,
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $providerHotelIdFingerprint,
        DateTimeImmutable $loginVerifiedAt,
        DateTimeImmutable $now
    ): array {
        return $this->verifyDingdandaoBindingReceipt(
            $receiptId,
            $receiptHash,
            $profileLeaseId,
            $profilePublicId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $providerHotelIdFingerprint,
            $loginVerifiedAt,
            $now,
            null,
            false
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function verifyDingdandaoBindingReceipt(
        string $receiptId,
        string $receiptHash,
        string $profileLeaseId,
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $providerHotelIdFingerprint,
        DateTimeImmutable $loginVerifiedAt,
        DateTimeImmutable $now,
        ?string $requiredTargetDate,
        bool $requireFreshActivation
    ): array {
        if (preg_match('/^cbr_[A-Za-z0-9_-]{16,96}$/D', $receiptId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $receiptHash) !== 1
            || preg_match('/^cbpl_[A-Za-z0-9_-]{16,64}$/D', $profileLeaseId)
                !== 1
            || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profilePublicId)
                !== 1
            || $tenantId <= 0
            || $hotelId <= 0
            || $ownerUserId <= 0
            || ($requiredTargetDate !== null
                && !$this->validDate($requiredTargetDate))
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                $providerHotelIdFingerprint
            ) !== 1
        ) {
            throw new RuntimeException(
                'dingdandao_binding_activation_receipt_invalid'
            );
        }

        $records = $this->verifiedRecords();
        $matches = array_values(array_filter(
            $records,
            static fn(array $record): bool =>
                (string)$record['receipt_id'] === $receiptId
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException(
                'dingdandao_binding_activation_receipt_invalid'
            );
        }
        $record = $matches[0];
        $payload = is_array($record['payload'] ?? null)
            ? $record['payload']
            : [];
        $expected = [
            'profile_lease_id' => $profileLeaseId,
            'profile_id' => $profilePublicId,
            'platform' => 'dingdandao',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'owner_user_id' => $ownerUserId,
            'lease_kind' => 'binding_identity',
            'access_mode' => 'read_only',
            'outcome' => 'completed',
            'session_owner' => 'gateway_profile_lease',
            'owned_browser_closed' => true,
            'user_browser_closed' => false,
            'profile_encrypted_at_rest' => true,
            'sensitive_values_exposed' => false,
            'activation_requested' => true,
            'provider_hotel_id_fingerprint' =>
                $providerHotelIdFingerprint,
        ];
        if ($requiredTargetDate !== null) {
            $expected['target_date'] = $requiredTargetDate;
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $payload)
                || $payload[$key] !== $value
            ) {
                throw new RuntimeException(
                    'dingdandao_binding_activation_receipt_invalid'
                );
            }
        }
        if ((string)$record['kind'] !== 'profile_lease_closed'
            || !hash_equals($receiptHash, (string)$record['receipt_hash'])
        ) {
            throw new RuntimeException(
                'dingdandao_binding_activation_receipt_invalid'
            );
        }

        $occurredAt = $this->receiptTime((string)$record['occurred_at']);
        $shanghai = new DateTimeZone('Asia/Shanghai');
        $nowShanghai = $now->setTimezone($shanghai);
        $receiptTargetDate = trim((string)($payload['target_date'] ?? ''));
        if ($occurredAt < $loginVerifiedAt
            || $occurredAt > $now->modify('+5 minutes')
            || ($requireFreshActivation
                && $occurredAt < $now->modify('-30 minutes'))
            || !$this->validDate($receiptTargetDate)
            || $occurredAt->setTimezone($shanghai)->format('Y-m-d')
                !== $receiptTargetDate
            || ($requiredTargetDate !== null
                && ($receiptTargetDate !== $requiredTargetDate
                    || $nowShanghai->format('Y-m-d')
                        !== $requiredTargetDate))
        ) {
            throw new RuntimeException(
                'dingdandao_binding_activation_receipt_invalid'
            );
        }

        return $record;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function verifiedRecords(): array
    {
        clearstatcache(true, $this->chainPath);
        $size = @filesize($this->chainPath);
        if (!is_int($size)
            || $size <= 0
            || $size > self::MAX_CHAIN_BYTES
            || !is_file($this->chainPath)
            || !is_readable($this->chainPath)
        ) {
            throw new RuntimeException(
                'cloud_browser_receipt_chain_integrity_failed'
            );
        }
        $handle = @fopen($this->chainPath, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException(
                'cloud_browser_receipt_chain_integrity_failed'
            );
        }

        $records = [];
        $ids = [];
        $previousHash = null;
        try {
            while (($line = fgets($handle, self::MAX_RECORD_BYTES + 1))
                !== false
            ) {
                if (strlen($line) > self::MAX_RECORD_BYTES
                    || count($records) >= self::MAX_CHAIN_RECORDS
                ) {
                    throw new RuntimeException(
                        'cloud_browser_receipt_chain_integrity_failed'
                    );
                }
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    throw new RuntimeException(
                        'cloud_browser_receipt_chain_integrity_failed'
                    );
                }
                try {
                    $record = json_decode(
                        $line,
                        true,
                        128,
                        JSON_THROW_ON_ERROR
                    );
                } catch (\Throwable) {
                    throw new RuntimeException(
                        'cloud_browser_receipt_chain_integrity_failed'
                    );
                }
                if (!is_array($record)
                    || array_is_list($record)
                    || !$this->validRecordShape($record)
                ) {
                    throw new RuntimeException(
                        'cloud_browser_receipt_chain_integrity_failed'
                    );
                }
                $receiptId = (string)$record['receipt_id'];
                if (isset($ids[$receiptId])) {
                    throw new RuntimeException(
                        'cloud_browser_receipt_chain_integrity_failed'
                    );
                }
                $ids[$receiptId] = true;
                $unsigned = $record;
                unset($unsigned['receipt_hash']);
                $computed = hash('sha256', $this->canonical($unsigned));
                if ($record['prev_hash'] !== $previousHash
                    || !hash_equals(
                        (string)$record['receipt_hash'],
                        $computed
                    )
                ) {
                    throw new RuntimeException(
                        'cloud_browser_receipt_chain_integrity_failed'
                    );
                }
                $previousHash = (string)$record['receipt_hash'];
                $records[] = $record;
            }
            if (!feof($handle) || $records === []) {
                throw new RuntimeException(
                    'cloud_browser_receipt_chain_integrity_failed'
                );
            }
        } finally {
            fclose($handle);
        }
        return $records;
    }

    /** @param array<string,mixed> $record */
    private function validRecordShape(array $record): bool
    {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'kind',
            'occurred_at',
            'payload',
            'prev_hash',
            'receipt_hash',
            'receipt_id',
        ]) {
            return false;
        }
        return preg_match(
            '/^cbr_[A-Za-z0-9_-]{16,96}$/D',
            (string)($record['receipt_id'] ?? '')
        ) === 1
            && preg_match(
                '/^[a-z][a-z0-9_-]{2,40}$/D',
                (string)($record['kind'] ?? '')
            ) === 1
            && preg_match(
                '/^[a-f0-9]{64}$/D',
                (string)($record['receipt_hash'] ?? '')
            ) === 1
            && ($record['prev_hash'] === null
                || preg_match(
                    '/^[a-f0-9]{64}$/D',
                    (string)$record['prev_hash']
                ) === 1)
            && is_array($record['payload'])
            && !$this->containsSensitiveKey($record['payload'])
            && $this->receiptTimeOrNull((string)$record['occurred_at'])
                instanceof DateTimeImmutable;
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(
                    fn(mixed $entry): string => $this->canonical($entry),
                    $value
                )) . ']';
            }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $key => $entry) {
                $parts[] = $this->json((string)$key)
                    . ':' . $this->canonical($entry);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return $this->json($value);
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        );
    }

    /** @param array<mixed> $value */
    private function containsSensitiveKey(array $value): bool
    {
        foreach ($value as $key => $entry) {
            if (is_string($key)
                && preg_match(
                    '/cookie|password|authorization(?!_status)|'
                        . '(^|_)(token|secret|headers?|raw|html|har)(_|$)|'
                        . 'profile[_-]?path|localstorage|sessionstorage/i',
                    $key
                ) === 1
            ) {
                return true;
            }
            if (is_array($entry) && $this->containsSensitiveKey($entry)) {
                return true;
            }
        }
        return false;
    }

    private function receiptTime(string $value): DateTimeImmutable
    {
        $time = $this->receiptTimeOrNull($value);
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException(
                'cloud_browser_receipt_chain_integrity_failed'
            );
        }
        return $time;
    }

    private function receiptTimeOrNull(string $value): ?DateTimeImmutable
    {
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',
            $value
        ) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value;
    }
}
