<?php
declare(strict_types=1);

namespace tests;

use app\service\CloudBrowserReceiptChainVerifier;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CloudBrowserReceiptChainVerifierTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir()
            . '/cloud_browser_receipt_verifier_' . getmypid() . '_'
            . spl_object_id($this) . '.jsonl';
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testExactBindingLeaseReceiptPasses(): void
    {
        $record = $this->writeValidChain();

        $verified = $this->verifier()->verifyDingdandaoBindingLeaseClosed(
            (string)$record['receipt_id'],
            (string)$record['receipt_hash'],
            'cbpl_binding_lease_123456789',
            'cbp_dingdandao_profile_123456',
            1,
            5,
            7,
            '2026-07-27',
            str_repeat('a', 64),
            new DateTimeImmutable('2026-07-27 09:59:00 Asia/Shanghai'),
            new DateTimeImmutable('2026-07-27 10:00:00 Asia/Shanghai')
        );

        self::assertSame($record['receipt_id'], $verified['receipt_id']);
        self::assertSame(
            'profile_lease_closed',
            $verified['kind']
        );
    }

    public function testAnyHistoricalTamperBreaksWholeChain(): void
    {
        $records = $this->validRecords();
        $records[0]['payload']['status'] = 'tampered';
        $this->writeRecords($records);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'cloud_browser_receipt_chain_integrity_failed'
        );
        $this->verifyTarget($records[1]);
    }

    public function testWrongScopeAndFingerprintCannotActivate(): void
    {
        $record = $this->writeValidChain();

        foreach ([
            ['hotel' => 6, 'fingerprint' => str_repeat('a', 64)],
            ['hotel' => 5, 'fingerprint' => str_repeat('b', 64)],
        ] as $case) {
            try {
                $this->verifier()->verifyDingdandaoBindingLeaseClosed(
                    (string)$record['receipt_id'],
                    (string)$record['receipt_hash'],
                    'cbpl_binding_lease_123456789',
                    'cbp_dingdandao_profile_123456',
                    1,
                    $case['hotel'],
                    7,
                    '2026-07-27',
                    $case['fingerprint'],
                    new DateTimeImmutable(
                        '2026-07-27 09:59:00 Asia/Shanghai'
                    ),
                    new DateTimeImmutable(
                        '2026-07-27 10:00:00 Asia/Shanghai'
                    )
                );
                self::fail('receipt scope mismatch must be rejected');
            } catch (RuntimeException $error) {
                self::assertSame(
                    'dingdandao_binding_activation_receipt_invalid',
                    $error->getMessage()
                );
            }
        }
    }

    public function testOldReceiptAndDuplicateIdAreRejected(): void
    {
        $records = $this->validRecords();
        $records[1]['occurred_at'] = '2026-07-27T00:00:00.000Z';
        $records[1] = $this->sign($records[1]);
        $this->writeRecords($records);
        try {
            $this->verifyTarget($records[1]);
            self::fail('old receipt must be rejected');
        } catch (RuntimeException $error) {
            self::assertSame(
                'dingdandao_binding_activation_receipt_invalid',
                $error->getMessage()
            );
        }

        $records = $this->validRecords();
        $records[0]['receipt_id'] = $records[1]['receipt_id'];
        $records[0] = $this->sign($records[0]);
        $records[1]['prev_hash'] = $records[0]['receipt_hash'];
        $records[1] = $this->sign($records[1]);
        $this->writeRecords($records);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'cloud_browser_receipt_chain_integrity_failed'
        );
        $this->verifyTarget($records[1]);
    }

    /** @return array<string,mixed> */
    private function writeValidChain(): array
    {
        $records = $this->validRecords();
        $this->writeRecords($records);
        return $records[1];
    }

    /** @return list<array<string,mixed>> */
    private function validRecords(): array
    {
        $opened = $this->sign([
            'receipt_id' => 'cbr_opened_receipt_123456789',
            'kind' => 'profile_lease_opened',
            'occurred_at' => '2026-07-27T01:59:00.000Z',
            'prev_hash' => null,
            'payload' => [
                'profile_lease_id' =>
                    'cbpl_binding_lease_123456789',
                'profile_id' => 'cbp_dingdandao_profile_123456',
                'platform' => 'dingdandao',
                'status' => 'open',
            ],
        ]);
        $closed = $this->sign([
            'receipt_id' => 'cbr_closed_receipt_123456789',
            'kind' => 'profile_lease_closed',
            'occurred_at' => '2026-07-27T02:00:00.000Z',
            'prev_hash' => $opened['receipt_hash'],
            'payload' => [
                'profile_lease_id' =>
                    'cbpl_binding_lease_123456789',
                'profile_id' => 'cbp_dingdandao_profile_123456',
                'platform' => 'dingdandao',
                'tenant_id' => 1,
                'hotel_id' => 5,
                'owner_user_id' => 7,
                'target_date' => '2026-07-27',
                'lease_kind' => 'binding_identity',
                'access_mode' => 'read_only',
                'outcome' => 'completed',
                'session_owner' => 'gateway_profile_lease',
                'owned_browser_closed' => true,
                'user_browser_closed' => false,
                'profile_encrypted_at_rest' => true,
                'sensitive_values_exposed' => false,
                'activation_requested' => true,
                'provider_hotel_id_fingerprint' => str_repeat('a', 64),
            ],
        ]);
        return [$opened, $closed];
    }

    /** @param list<array<string,mixed>> $records */
    private function writeRecords(array $records): void
    {
        $lines = array_map(
            static fn(array $record): string => json_encode(
                $record,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ),
            $records
        );
        file_put_contents($this->path, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function sign(array $record): array
    {
        unset($record['receipt_hash']);
        $record['receipt_hash'] = hash(
            'sha256',
            $this->canonical($record)
        );
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function verifyTarget(array $record): void
    {
        $this->verifier()->verifyDingdandaoBindingLeaseClosed(
            (string)$record['receipt_id'],
            (string)$record['receipt_hash'],
            'cbpl_binding_lease_123456789',
            'cbp_dingdandao_profile_123456',
            1,
            5,
            7,
            '2026-07-27',
            str_repeat('a', 64),
            new DateTimeImmutable('2026-07-27 09:59:00 Asia/Shanghai'),
            new DateTimeImmutable('2026-07-27 10:00:00 Asia/Shanghai')
        );
    }

    private function verifier(): CloudBrowserReceiptChainVerifier
    {
        return new CloudBrowserReceiptChainVerifier($this->path);
    }

    private function canonical(mixed $value): string
    {
        if (!is_array($value)) {
            return (string)json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            );
        }
        if (array_is_list($value)) {
            return '[' . implode(',', array_map(
                fn(mixed $entry): string => $this->canonical($entry),
                $value
            )) . ']';
        }
        ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $entry) {
            $parts[] = json_encode(
                (string)$key,
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
            ) . ':' . $this->canonical($entry);
        }
        return '{' . implode(',', $parts) . '}';
    }
}
