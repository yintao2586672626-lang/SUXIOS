<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\controller\concern\CollectionReliabilityConcern;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class OtaMetadataSafetyTest extends TestCase
{
    use ReflectionHelper;

    public function testCookieAlertRecordContainsOnlyFixedSafeReasonText(): void
    {
        $harness = new class {
            use CollectionReliabilityConcern;
        };

        $alert = $this->invokeNonPublic($harness, 'buildCookieAlertRecord', [
            'ctrip',
            'cfg-58',
            58,
        ]);

        self::assertSame('ota_credential_reauthorization_required', $alert['reason_code']);
        self::assertSame('OTA authorization is unavailable. Reauthenticate the platform account before collection.', $alert['message']);
        self::assertSame('cfg-58', $alert['name']);
        self::assertArrayNotHasKey('upstream_message', $alert);
        self::assertArrayNotHasKey('error', $alert);
    }

    public function testCookieAlertRecordSanitizesAnUnsafeDisplayName(): void
    {
        $harness = new class {
            use CollectionReliabilityConcern;
        };

        $alert = $this->invokeNonPublic($harness, 'buildCookieAlertRecord', [
            'ctrip',
            'Cookie: sid=UNSAFE_NAME_SECRET',
            58,
        ]);

        self::assertSame('ctrip', $alert['name']);
        self::assertStringNotContainsString('UNSAFE_NAME_SECRET', (string)json_encode($alert, JSON_UNESCAPED_UNICODE));
    }

    public function testLegacyCookieAlertsAreSanitizedBeforeTheyCanBePersistedAgain(): void
    {
        $harness = new class {
            use CollectionReliabilityConcern;
        };

        $alerts = $this->invokeNonPublic($harness, 'sanitizeCookieAlertsForStorage', [[
            'legacy-key' => [
                'platform' => 'ctrip',
                'name' => 'cfg-58',
                'hotel_id' => 58,
                'message' => 'Cookie: sid=LEGACY_COOKIE_SECRET',
                'error' => 'Authorization: Bearer LEGACY_AUTH_SECRET',
                'created_at' => '2026-07-10 09:30:00',
            ],
        ]]);

        self::assertCount(1, $alerts);
        $encoded = json_encode($alerts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('LEGACY_COOKIE_SECRET', (string)$encoded);
        self::assertStringNotContainsString('LEGACY_AUTH_SECRET', (string)$encoded);
        self::assertSame('ota_credential_reauthorization_required', array_values($alerts)[0]['reason_code']);
        self::assertSame('2026-07-10 09:30:00', array_values($alerts)[0]['created_at']);
    }

    public function testLegacyCookieAlertsAreSanitizedOnEveryReadPath(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/CollectionReliabilityConcern.php');

        self::assertMatchesRegularExpression(
            '/private function getCookieAlerts\(\): array[\s\S]*?sanitizeCookieAlertsForStorage\(\$data\)[\s\S]*?\n\s*}/',
            $source
        );
    }

    public function testAgentCookieWarningSanitizerWhitelistsFieldsAndRebuildsLegacyText(): void
    {
        $reflection = new ReflectionClass(Agent::class);
        $agent = $reflection->newInstanceWithoutConstructor();

        $alerts = $this->invokeNonPublic($agent, 'sanitizeCookieWarningAlerts', [[
            [
                'platform' => 'ctrip',
                'name' => 'cfg-58',
                'hotel_id' => 58,
                'message' => 'Cookie: sid=LEGACY_COOKIE_SECRET',
                'reason_code' => 'untrusted_reason',
                'created_at' => '2026-07-10 09:30:00',
                'auth_data' => ['token' => 'LEGACY_TOKEN_SECRET'],
                'unexpected' => 'LEGACY_EXTRA_SECRET',
            ],
        ]]);

        self::assertCount(1, $alerts);
        self::assertSame([
            'platform',
            'name',
            'hotel_id',
            'reason_code',
            'message',
            'created_at',
            'next_action',
            'reauthorize_entry',
        ], array_keys($alerts[0]));
        self::assertSame('ota_credential_reauthorization_required', $alerts[0]['reason_code']);
        self::assertSame('OTA authorization is unavailable. Reauthenticate the platform account before collection.', $alerts[0]['message']);

        $encoded = json_encode($alerts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['LEGACY_COOKIE_SECRET', 'LEGACY_TOKEN_SECRET', 'LEGACY_EXTRA_SECRET', 'untrusted_reason'] as $secret) {
            self::assertStringNotContainsString($secret, (string)$encoded);
        }
    }
}
