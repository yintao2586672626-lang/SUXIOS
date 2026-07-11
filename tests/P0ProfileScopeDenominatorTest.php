<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class P0ProfileScopeDenominatorTest extends TestCase
{
    private const REGISTRATION_SCRIPT = __DIR__ . '/../scripts/register_p0_ota_traffic_data_sources.php';
    private const VERIFIER_SCRIPT = __DIR__ . '/../scripts/verify_p0_ota_field_loop_closure.php';

    public function testRegistrationIncludesActiveBindingBackedByNonTrafficProfileSource(): void
    {
        $resolve = $this->loadFunction(self::REGISTRATION_SCRIPT, 'resolve_registration_hotel_scope');
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
                'data_type' => 'business',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'profile_key_hash' => $profileHash,
            ]]
        );

        self::assertSame(['ctrip' => [7]], $result);
    }

    public function testRegistrationKeepsActiveBindingWithoutSourceInTheDenominator(): void
    {
        $resolve = $this->loadFunction(self::REGISTRATION_SCRIPT, 'resolve_registration_hotel_scope');

        $result = $resolve(
            ['ctrip'],
            null,
            [['id' => 63, 'tenant_id' => 63, 'status' => 1]],
            [[
                'tenant_id' => 63,
                'system_hotel_id' => 63,
                'platform' => 'ctrip',
                'profile_key_hash' => hash('sha256', 'profile-63'),
                'binding_status' => 'active',
            ]],
            []
        );

        self::assertSame(['ctrip' => [63]], $result);
    }

    public function testVerifierUsesBindingsAsDenominatorAndReportsMissingProfileSource(): void
    {
        $resolve = $this->loadFunction(self::VERIFIER_SCRIPT, 'p0_resolve_profile_scope_denominator');
        $profile7 = hash('sha256', 'profile-7');
        $profile63 = hash('sha256', 'profile-63');

        $result = $resolve(
            'ctrip',
            0,
            [
                ['id' => 7, 'tenant_id' => 7, 'status' => 1],
                ['id' => 63, 'tenant_id' => 63, 'status' => 1],
            ],
            [
                ['tenant_id' => 7, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'profile_key_hash' => $profile7, 'binding_status' => 'active'],
                ['tenant_id' => 63, 'system_hotel_id' => 63, 'platform' => 'ctrip', 'profile_key_hash' => $profile63, 'binding_status' => 'active'],
            ],
            [[
                'tenant_id' => 7,
                'system_hotel_id' => 7,
                'platform' => 'ctrip',
                'data_type' => 'business',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'profile_key_hash' => $profile7,
            ]]
        );

        self::assertSame([7, 63], $result['system_hotel_ids'] ?? null);
        self::assertSame([7], $result['matched_profile_source_hotel_ids'] ?? null);
        self::assertSame([63], $result['missing_profile_source_hotel_ids'] ?? null);
        self::assertSame('incomplete', $result['status'] ?? null);
    }

    public function testVerifierDoesNotDropBindingWhenOnlyCrossTenantOrDisabledSourcesExist(): void
    {
        $resolve = $this->loadFunction(self::VERIFIER_SCRIPT, 'p0_resolve_profile_scope_denominator');
        $profileHash = hash('sha256', 'profile-80');

        $result = $resolve(
            'ctrip',
            0,
            [['id' => 80, 'tenant_id' => 80, 'status' => 1]],
            [[
                'tenant_id' => 80,
                'system_hotel_id' => 80,
                'platform' => 'ctrip',
                'profile_key_hash' => $profileHash,
                'binding_status' => 'active',
            ]],
            [
                [
                    'tenant_id' => 999,
                    'system_hotel_id' => 80,
                    'platform' => 'ctrip',
                    'data_type' => 'business',
                    'ingestion_method' => 'browser_profile',
                    'enabled' => 1,
                    'status' => 'ready',
                    'profile_key_hash' => $profileHash,
                ],
                [
                    'tenant_id' => 80,
                    'system_hotel_id' => 80,
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'ingestion_method' => 'browser_profile',
                    'enabled' => 0,
                    'status' => 'disabled',
                    'profile_key_hash' => $profileHash,
                ],
            ]
        );

        self::assertSame([80], $result['system_hotel_ids'] ?? null);
        self::assertSame([], $result['matched_profile_source_hotel_ids'] ?? null);
        self::assertSame([80], $result['missing_profile_source_hotel_ids'] ?? null);
    }

    public function testVerifierProfileScopeClosureFailsWhenAnyBoundHotelLacksTrafficSourceOrRows(): void
    {
        $closure = $this->loadFunction(self::VERIFIER_SCRIPT, 'p0_profile_scope_traffic_closure');

        $result = $closure(
            [
                'status' => 'incomplete',
                'system_hotel_ids' => [7, 63],
                'missing_profile_source_hotel_ids' => [63],
            ],
            [
                'traffic_source_rows' => [[
                    'system_hotel_id' => 7,
                    'enabled' => true,
                    'ingestion_method' => 'browser_profile',
                    'profile_binding_status' => 'ready',
                ]],
            ],
            [
                'system_hotel_ids' => [7],
                'hotel_scoped_closure_status' => 'ready',
            ]
        );

        self::assertSame('incomplete', $result['status'] ?? null);
        self::assertSame([63], $result['missing_profile_source_hotel_ids'] ?? null);
        self::assertSame([63], $result['missing_traffic_source_hotel_ids'] ?? null);
        self::assertSame([63], $result['missing_target_date_traffic_hotel_ids'] ?? null);
    }

    public function testExplicitHotelClosureUsesTheAlreadyScopedFieldFactStatus(): void
    {
        $closure = $this->loadFunction(self::VERIFIER_SCRIPT, 'p0_profile_scope_traffic_closure');

        $result = $closure(
            [
                'status' => 'ready',
                'system_hotel_ids' => [7],
                'missing_profile_source_hotel_ids' => [],
            ],
            [
                'traffic_source_rows' => [[
                    'system_hotel_id' => 7,
                    'enabled' => true,
                    'ingestion_method' => 'browser_profile',
                    'profile_binding_status' => 'ready',
                ]],
            ],
            [
                'status' => 'ready',
                'system_hotel_ids' => [7],
                'hotel_scoped_closure_status' => 'not_loaded',
            ]
        );

        self::assertSame('ready', $result['status'] ?? null);
        self::assertSame('ready', $result['hotel_scoped_field_fact_status'] ?? null);
    }

    public function testWrongProfileTrafficSourceDoesNotSatisfyTheBoundHotelScope(): void
    {
        $closure = $this->loadFunction(self::VERIFIER_SCRIPT, 'p0_profile_scope_traffic_closure');

        $result = $closure(
            [
                'status' => 'ready',
                'system_hotel_ids' => [7],
                'missing_profile_source_hotel_ids' => [],
            ],
            [
                'traffic_source_rows' => [[
                    'system_hotel_id' => 7,
                    'enabled' => true,
                    'ingestion_method' => 'browser_profile',
                    'profile_binding_status' => 'blocked',
                ]],
            ],
            [
                'status' => 'ready',
                'system_hotel_ids' => [7],
                'hotel_scoped_closure_status' => 'not_loaded',
            ]
        );

        self::assertSame([7], $result['missing_traffic_source_hotel_ids'] ?? null);
        self::assertSame('incomplete', $result['status'] ?? null);
    }

    /** @return callable */
    private function loadFunction(string $path, string $name): callable
    {
        if (!function_exists($name)) {
            $definition = $this->extractFunctionDefinition((string)file_get_contents($path), $name);
            self::assertNotSame('', $definition, 'Missing behavior: ' . $name);
            eval($definition);
        }

        self::assertTrue(function_exists($name), 'Missing behavior: ' . $name);
        return $name;
    }

    private function extractFunctionDefinition(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        if (!is_int($start)) {
            return '';
        }
        $brace = strpos($source, '{', $start);
        if (!is_int($brace)) {
            return '';
        }
        $depth = 0;
        for ($index = $brace, $length = strlen($source); $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $index - $start + 1);
                }
            }
        }
        return '';
    }
}
