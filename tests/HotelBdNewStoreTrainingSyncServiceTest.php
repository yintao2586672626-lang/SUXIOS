<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelBdNewStoreTrainingSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class HotelBdNewStoreTrainingSyncServiceTest extends TestCase
{
    public function testGeneratedPackValidatesWithoutDatabaseWrite(): void
    {
        $result = (new HotelBdNewStoreTrainingSyncService())->sync(false);

        self::assertSame('validated', $result['status']);
        self::assertFalse($result['persisted']);
        self::assertSame('suxios.hotel_bd_new_store_training.v1', $result['pack_key']);
        self::assertSame('d1a935d4d1bcfa025819836afa2c8eaaff4104b44b1168737f8d670b324ec2a1', $result['pack_sha256']);
        self::assertSame(6, $result['entry_count']);
        self::assertSame(3, $result['golden_case_count']);
        self::assertFalse($result['source_file_verification']['verified']);
        self::assertTrue($result['boundary']['reference_only']);
        self::assertFalse($result['boundary']['decision_safe']);
        self::assertFalse($result['boundary']['task_draft_safe']);
        self::assertFalse($result['boundary']['external_write_authorized']);
    }

    public function testUnsafeBoundaryIsRejected(): void
    {
        $pack = $this->loadPack();
        $pack['boundary']['task_draft_safe'] = true;
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsafe_hotel_bd_training_boundary:task_draft_safe');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testSignedRemoteAssetRetentionIsRejected(): void
    {
        $pack = $this->loadPack();
        $pack['source_document']['signed_remote_asset_url_retained'] = true;
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsafe_hotel_bd_training_source_retention:signed_remote_asset_url_retained');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testMismatchedSourceFingerprintIsRejected(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'suxios-bd-training-source-');
        self::assertIsString($source);
        file_put_contents($source, 'not the supplied training document');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_source_fingerprint_mismatch');
            (new HotelBdNewStoreTrainingSyncService(null, $source))->sync(false);
        } finally {
            @unlink($source);
        }
    }

    public function testLegacyContentKeyRemainsIdempotentlyAddressable(): void
    {
        $method = new ReflectionMethod(HotelBdNewStoreTrainingSyncService::class, 'contentSeedKey');
        $service = new HotelBdNewStoreTrainingSyncService();

        self::assertSame('preferred', $method->invoke($service, [
            'seed_key' => 'preferred',
            'content_key' => 'legacy-content',
            'key' => 'legacy-key',
        ]));
        self::assertSame('legacy-content', $method->invoke($service, [
            'content_key' => 'legacy-content',
            'key' => 'legacy-key',
        ]));
        self::assertSame('legacy-key', $method->invoke($service, ['key' => 'legacy-key']));
        self::assertSame('', $method->invoke($service, []));
    }

    public function testPersistRequiresExactSourceBeforeDatabaseAccess(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hotel_bd_training_source_verification_required');

        (new HotelBdNewStoreTrainingSyncService())->sync(true);
    }

    public function testCustomPackCannotBePersisted(): void
    {
        $temporary = $this->writeTemporaryJson($this->loadPack());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_untrusted_pack_persist_forbidden');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(true);
        } finally {
            @unlink($temporary);
        }
    }

    public function testUnknownEntryFieldIsRejectedBeforePersistence(): void
    {
        $pack = $this->loadPack();
        $pack['entries'][0]['unexpected_note'] = 'untrusted payload';
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_schema_keys_invalid:entry:bd_diagnosis_before_selling');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testCredentialLikePayloadIsRejectedCaseInsensitively(): void
    {
        $pack = $this->loadPack();
        $pack['entries'][0]['body_markdown'] .= "\naUtHoRiZaTiOn: Bearer abcdefghijklmnop";
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_sensitive_value:root.entries.0.body_markdown');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testSensitiveNestedKeyIsRejected(): void
    {
        $pack = $this->loadPack();
        $pack['golden_cases'][0]['input']['access_token'] = 'abcdefghijklmnop';
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_sensitive_key:root.golden_cases.0.input');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testRemoteUrlPayloadIsRejected(): void
    {
        $pack = $this->loadPack();
        $pack['entries'][0]['body_markdown'] .= "\nhttps://example.invalid/private";
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_sensitive_value:root.entries.0.body_markdown');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testCanonicalHashDetectsContentDriftButIgnoresMapOrder(): void
    {
        $method = new ReflectionMethod(HotelBdNewStoreTrainingSyncService::class, 'canonicalHash');
        $service = new HotelBdNewStoreTrainingSyncService();

        $baseline = $method->invoke($service, ['title' => '受控参考', 'decision_safe' => false]);
        $reordered = $method->invoke($service, ['decision_safe' => false, 'title' => '受控参考']);
        $drifted = $method->invoke($service, ['title' => '被篡改', 'decision_safe' => false]);

        self::assertSame($baseline, $reordered);
        self::assertNotSame($baseline, $drifted);
    }

    public function testOnlyExplicitInactiveLifecycleStatesCanBeSkipped(): void
    {
        $method = new ReflectionMethod(HotelBdNewStoreTrainingSyncService::class, 'isSupportedInactiveLifecycle');
        $service = new HotelBdNewStoreTrainingSyncService();

        self::assertTrue($method->invoke($service, 'superseded'));
        self::assertTrue($method->invoke($service, 'retired'));
        self::assertTrue($method->invoke($service, 'stale'));
        self::assertFalse($method->invoke($service, ''));
        self::assertFalse($method->invoke($service, 'unknown'));
        self::assertFalse($method->invoke($service, 'ACTIVE'));
    }

    /** @return array<string, mixed> */
    private function loadPack(): array
    {
        $path = dirname(__DIR__) . '/docs/knowledge/hotel-bd-new-store-training/knowledge-pack.json';
        $pack = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($pack);
        return $pack;
    }

    /** @param array<string, mixed> $pack */
    private function writeTemporaryJson(array $pack): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'suxios-bd-training-pack-');
        self::assertIsString($temporary);
        file_put_contents(
            $temporary,
            json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        return $temporary;
    }
}
