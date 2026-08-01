<?php
declare(strict_types=1);

namespace Tests\Evals\SeventhBatch;

use app\service\OtaCredentialEnvelope;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Tc104CredentialCiphertextTamperL8EvalTest extends TestCase
{
    private const ENVELOPE_PREFIX = 'ota-cred:v1:';

    #[DataProvider('l8ScenarioProvider')]
    public function testCiphertextOrTagTamperingIsRejectedForL8Scenario(
        string $caseId,
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState,
        string $tamperedField
    ): void {
        $context = $this->fixtureContext(
            $caseId,
            $actorScope,
            $dataCompleteness,
            $freshness,
            $upstreamState
        );

        self::assertSame(
            [
                'actor_scope' => $actorScope,
                'data_completeness' => $dataCompleteness,
                'freshness' => $freshness,
                'upstream_state' => $upstreamState,
            ],
            $context['factors'],
            $caseId . ' must preserve the exact L8 factor tuple.'
        );
        self::assertSame($actorScope === 'authorized', $context['permission_allowed']);
        self::assertSame(
            $dataCompleteness === 'missing_required' ? ['username'] : [],
            $context['missing_required_fields']
        );
        self::assertSame($freshness === 'stale', $context['is_stale']);
        self::assertSame($upstreamState === 'success', $context['upstream_succeeded']);

        $service = new OtaCredentialEnvelope(
            base64_encode(hash('sha256', 'tc-104-isolated-synthetic-key', true)),
            'tc-104-fixture-key'
        );
        $scope = 'fixture:tc-104:' . $caseId;
        $credentials = $this->syntheticCredentials($caseId, $dataCompleteness);
        $envelope = $service->encrypt($credentials, $scope);

        self::assertSame(
            $credentials,
            $service->decrypt($envelope, $scope),
            $caseId . ' requires a valid production-boundary baseline before tampering.'
        );

        $metadata = $this->decodeEnvelope($envelope);
        $originalField = $metadata[$tamperedField] ?? null;
        self::assertIsString($originalField);
        $tamperedBinary = $this->decodeCanonicalBase64($originalField);
        self::assertNotSame('', $tamperedBinary);
        $tamperedBinary[0] = chr(ord($tamperedBinary[0]) ^ 1);
        $metadata[$tamperedField] = base64_encode($tamperedBinary);
        self::assertNotSame($originalField, $metadata[$tamperedField]);

        $exception = null;
        try {
            $service->decrypt($this->encodeEnvelope($metadata), $scope);
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(
            RuntimeException::class,
            $exception,
            $caseId . ' must reject a tampered ' . $tamperedField . '.'
        );
        self::assertSame(
            'OTA credential envelope authentication failed.',
            $exception->getMessage()
        );
        self::assertStringNotContainsString(
            $credentials['cookie'],
            $exception->getMessage(),
            $caseId . ' must not return partial credential material.'
        );
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string}>
     */
    public static function l8ScenarioProvider(): iterable
    {
        yield 'DX-0825 authorized-complete-fresh-success ciphertext' => [
            'DX-0825', 'authorized', 'complete', 'fresh', 'success', 'ciphertext',
        ];
        yield 'DX-0826 authorized-complete-stale-failure tag' => [
            'DX-0826', 'authorized', 'complete', 'stale', 'failure', 'tag',
        ];
        yield 'DX-0827 authorized-missing_required-fresh-failure ciphertext' => [
            'DX-0827', 'authorized', 'missing_required', 'fresh', 'failure', 'ciphertext',
        ];
        yield 'DX-0828 authorized-missing_required-stale-success tag' => [
            'DX-0828', 'authorized', 'missing_required', 'stale', 'success', 'tag',
        ];
        yield 'DX-0829 restricted-complete-fresh-failure ciphertext' => [
            'DX-0829', 'restricted', 'complete', 'fresh', 'failure', 'ciphertext',
        ];
        yield 'DX-0830 restricted-complete-stale-success tag' => [
            'DX-0830', 'restricted', 'complete', 'stale', 'success', 'tag',
        ];
        yield 'DX-0831 restricted-missing_required-fresh-success ciphertext' => [
            'DX-0831', 'restricted', 'missing_required', 'fresh', 'success', 'ciphertext',
        ];
        yield 'DX-0832 restricted-missing_required-stale-failure tag' => [
            'DX-0832', 'restricted', 'missing_required', 'stale', 'failure', 'tag',
        ];
    }

    /**
     * The four business factors are observable fixture evidence. The production
     * envelope remains responsible only for its real encryption/decryption boundary.
     *
     * @return array{
     *     case_id: string,
     *     factors: array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string},
     *     permission_allowed: bool,
     *     missing_required_fields: list<string>,
     *     is_stale: bool,
     *     upstream_succeeded: bool
     * }
     */
    private function fixtureContext(
        string $caseId,
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'case_id' => $caseId,
            'factors' => [
                'actor_scope' => $actorScope,
                'data_completeness' => $dataCompleteness,
                'freshness' => $freshness,
                'upstream_state' => $upstreamState,
            ],
            'permission_allowed' => $actorScope === 'authorized',
            'missing_required_fields' => $dataCompleteness === 'missing_required'
                ? ['username']
                : [],
            'is_stale' => $freshness === 'stale',
            'upstream_succeeded' => $upstreamState === 'success',
        ];
    }

    /** @return array<string, string> */
    private function syntheticCredentials(string $caseId, string $dataCompleteness): array
    {
        $credentials = [
            'cookie' => 'synthetic-cookie-' . strtolower($caseId),
        ];
        if ($dataCompleteness === 'complete') {
            $credentials['username'] = 'synthetic-operator';
        }

        return $credentials;
    }

    /** @return array<string, mixed> */
    private function decodeEnvelope(string $envelope): array
    {
        if (!str_starts_with($envelope, self::ENVELOPE_PREFIX)) {
            self::fail('Production encryption returned an unexpected envelope prefix.');
        }

        $encoded = substr($envelope, strlen(self::ENVELOPE_PREFIX));
        $json = base64_decode(
            strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4),
            true
        );
        if ($json === false) {
            self::fail('Production encryption returned invalid envelope Base64.');
        }

        try {
            $metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Production encryption returned invalid envelope JSON: ' . $exception->getMessage());
        }
        if (!is_array($metadata)) {
            self::fail('Production encryption returned non-object envelope metadata.');
        }

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private function encodeEnvelope(array $metadata): string
    {
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::ENVELOPE_PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function decodeCanonicalBase64(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false || base64_encode($decoded) !== $value) {
            self::fail('Production encryption returned a non-canonical cryptographic field.');
        }

        return $decoded;
    }
}
