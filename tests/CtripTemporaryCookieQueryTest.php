<?php
declare(strict_types=1);

namespace tests;

use app\controller\concern\OnlineDataManualFetchConcern;
use PHPUnit\Framework\TestCase;

final class CtripTemporaryCookieQueryTest extends TestCase
{
    public function testTemporaryCookieSchemaAcceptsOnlyOneShotQueryFields(): void
    {
        $harness = new class {
            use OnlineDataManualFetchConcern;

            public function sanitize(array $payload): array
            {
                return $this->sanitizeCtripTemporaryCookieRequestData($payload);
            }
        };

        $payload = $harness->sanitize([
            'cookies' => 'session=temporary',
            'url' => 'https://ebooking.ctrip.com/api/report',
            'node_id' => '24588',
            'start_date' => '2026-07-11',
            'end_date' => '2026-07-11',
            'auto_save' => false,
        ]);

        self::assertSame('session=temporary', $payload['cookies']);
        self::assertArrayNotHasKey('config_id', $payload);
        self::assertArrayNotHasKey('system_hotel_id', $payload);

        foreach (['config_id', 'system_hotel_id', 'auth_data'] as $forbiddenField) {
            try {
                $harness->sanitize(['cookies' => 'session=temporary', $forbiddenField => 'forbidden']);
                self::fail('Temporary query must reject field: ' . $forbiddenField);
            } catch (\InvalidArgumentException $e) {
                self::assertSame(400, $e->getCode());
            }
        }
    }

    public function testTemporaryEndpointForcesDisplayOnlyExecutionWithoutHotelBinding(): void
    {
        $root = dirname(__DIR__);
        $route = (string)file_get_contents($root . '/route/app.php');
        $source = (string)file_get_contents($root . '/app/controller/concern/OnlineDataManualFetchConcern.php');

        self::assertStringContainsString(
            "Route::post('/fetch-ctrip-temporary-cookie', 'OnlineData/fetchCtripTemporaryCookie');",
            $route
        );
        self::assertStringContainsString('public function fetchCtripTemporaryCookie(): Response', $source);
        self::assertStringContainsString("\$requestData['auto_save'] = false;", $source);
        self::assertStringContainsString("['cookies' => \$cookies]", $source);
        self::assertMatchesRegularExpression(
            '/executeCtripManualFetch\(\s*\$requestData,\s*\$credentialPayload,\s*0\s*\)/s',
            $source
        );
        self::assertStringContainsString("'save_status' => \$persistenceOutcome['save_status'] !== ''", $source);
        self::assertStringContainsString(": (\$autoSave ? 'saved_or_empty' : 'display_only'))", $source);
        self::assertStringContainsString('buildCtripPersistenceState(', $source);
        self::assertStringContainsString('buildCtripManualFetchPersistenceOutcome(', $source);
        self::assertStringContainsString('$readbackVerified', $source);
    }
}
