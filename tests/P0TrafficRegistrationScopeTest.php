<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class P0TrafficRegistrationScopeTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../scripts/register_p0_ota_traffic_data_sources.php';

    public function testExplicitHotelScopeDoesNotDependOnTargetDateRows(): void
    {
        $resolve = $this->registrationFunction('resolve_registration_hotel_scope');

        $result = $resolve(
            ['ctrip', 'meituan'],
            7,
            [['id' => 7, 'tenant_id' => 11, 'status' => 1]],
            [],
            []
        );

        self::assertSame(['ctrip' => [7], 'meituan' => [7]], $result);
    }

    public function testAutomaticScopeRequiresAnActiveBindingForTheSameProfileSource(): void
    {
        $resolve = $this->registrationFunction('resolve_registration_hotel_scope');
        $profileHash = hash('sha256', 'profile-7');

        $result = $resolve(
            ['ctrip'],
            null,
            [['id' => 7, 'tenant_id' => 11, 'status' => 1]],
            [[
                'tenant_id' => 11,
                'system_hotel_id' => 7,
                'platform' => 'ctrip',
                'profile_key_hash' => $profileHash,
                'binding_status' => 'active',
            ]],
            [[
                'tenant_id' => 11,
                'system_hotel_id' => 7,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'waiting_config',
                'profile_key_hash' => $profileHash,
            ]]
        );

        self::assertSame(['ctrip' => [7]], $result);
    }

    public function testAutomaticScopeKeepsValidBindingForGapReportingButRejectsDisabledHotel(): void
    {
        $resolve = $this->registrationFunction('resolve_registration_hotel_scope');
        $crossTenantHash = hash('sha256', 'cross-tenant');
        $disabledHash = hash('sha256', 'disabled');

        $result = $resolve(
            ['ctrip'],
            null,
            [
                ['id' => 8, 'tenant_id' => 12, 'status' => 1],
                ['id' => 9, 'tenant_id' => 13, 'status' => 0],
            ],
            [
                [
                    'tenant_id' => 12,
                    'system_hotel_id' => 8,
                    'platform' => 'ctrip',
                    'profile_key_hash' => $crossTenantHash,
                    'binding_status' => 'active',
                ],
                [
                    'tenant_id' => 13,
                    'system_hotel_id' => 9,
                    'platform' => 'ctrip',
                    'profile_key_hash' => $disabledHash,
                    'binding_status' => 'active',
                ],
            ],
            [
                [
                    'tenant_id' => 99,
                    'system_hotel_id' => 8,
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'ingestion_method' => 'browser_profile',
                    'enabled' => 1,
                    'status' => 'waiting_config',
                    'profile_key_hash' => $crossTenantHash,
                ],
                [
                    'tenant_id' => 13,
                    'system_hotel_id' => 9,
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'ingestion_method' => 'browser_profile',
                    'enabled' => 1,
                    'status' => 'waiting_config',
                    'profile_key_hash' => $disabledHash,
                ],
            ]
        );

        self::assertSame(['ctrip' => [8]], $result);
    }

    public function testEmptyTargetScopeIsIncompleteWithAnExplicitReason(): void
    {
        $state = $this->registrationFunction('registration_target_scope_state');

        self::assertSame([
            'status' => 'incomplete',
            'reason' => 'no_target_hotel_scope',
        ], $state([]));
        self::assertSame([
            'status' => 'ready',
            'reason' => '',
        ], $state([7]));
    }

    public function testCliDeclaresAndValidatesPositiveSystemHotelId(): void
    {
        $source = (string)file_get_contents(self::SCRIPT);

        self::assertStringContainsString("'system-hotel-id' => null", $source);
        self::assertStringContainsString('Invalid --system-hotel-id, expected a positive integer.', $source);
        self::assertStringContainsString('registration_hotel_scope_rows(', $source);
        self::assertStringContainsString("'no_target_hotel_scope'", $source);
    }

    public function testDisabledManagedSourceIsBlockedInsteadOfDuplicated(): void
    {
        $policy = $this->registrationFunction('existing_source_registration_policy');
        $managedConfig = json_encode(['registered_by' => 'p0_ota_field_loop'], JSON_THROW_ON_ERROR);

        self::assertSame('blocked_disabled_managed_source', $policy([
            'status' => 'disabled',
            'config_json' => $managedConfig,
        ]));
        self::assertSame('update_managed_source', $policy([
            'status' => 'waiting_config',
            'config_json' => $managedConfig,
        ]));
        self::assertSame('keep_user_source', $policy([
            'status' => 'success',
            'config_json' => '{}',
        ]));
    }

    public function testProfileBindingHashUsesTheAuthoritativeCanonicalizer(): void
    {
        $hash = $this->registrationFunction('browser_profile_key_hash');
        $profileKey = str_repeat('profile.with.dots-', 8) . 'tail';
        $canonical = \app\service\BrowserProfileCaptureRequestService::safeFilePart($profileKey);

        self::assertSame(hash('sha256', $canonical), $hash($profileKey));
        self::assertLessThanOrEqual(80, strlen($canonical));
        self::assertStringNotContainsString('.', $canonical);
    }

    /** @return callable */
    private function registrationFunction(string $name): callable
    {
        if (!function_exists($name)) {
            $source = (string)file_get_contents(self::SCRIPT);
            $needle = 'function ' . $name . '(';
            $start = strpos($source, $needle);
            if ($start !== false) {
                $open = strpos($source, '{', $start);
                self::assertIsInt($open, 'Function opening brace is missing for ' . $name);
                $depth = 0;
                $length = strlen($source);
                $end = null;
                for ($index = $open; $index < $length; $index++) {
                    if ($source[$index] === '{') {
                        $depth++;
                    } elseif ($source[$index] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $index;
                            break;
                        }
                    }
                }
                self::assertIsInt($end, 'Function closing brace is missing for ' . $name);
                eval(substr($source, $start, $end - $start + 1));
            }
        }

        self::assertTrue(function_exists($name), 'Missing registration behavior: ' . $name);
        return $name;
    }
}
