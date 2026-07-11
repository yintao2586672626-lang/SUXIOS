<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class P0FieldLoopVerifierContractTest extends TestCase
{
    public function testStoredValueReadinessOnlyRequiresCompleteFactsToHaveStoredValues(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $liveActionQueueVerifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_phase1_live_action_queue_runtime.mjs');

        self::assertStringContainsString('(int)($facts[\'stored_value_present_count\'] ?? 0) < $completeCount', $p0Verifier);
        self::assertStringContainsString('$storedValuePresentCount >= $completeFactCount', $p0Verifier);
        self::assertStringContainsString('Number(fieldFacts.stored_value_present_count ?? 0) >= Number(fieldFacts.complete_fact_count ?? 0)', $liveActionQueueVerifier);

        self::assertStringNotContainsString("|| (int)(\$facts['stored_value_missing_count'] ?? 0) !== 0", $p0Verifier);
        self::assertStringNotContainsString('$storedValuePresentCount >= $completeFactCount && $storedValueMissingCount === 0', $p0Verifier);
        self::assertStringNotContainsString('&& Number(fieldFacts.stored_value_missing_count ?? -1) === 0', $liveActionQueueVerifier);
    }

    public function testExternalTrafficEvidenceRequiresRowLevelTargetDate(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$rowDate = trim((string)($row[\'target_date\'] ?? \'\'));', $p0Verifier);
        self::assertStringContainsString('target_date_missing', $p0Verifier);
        self::assertStringNotContainsString('$rowDate = trim((string)($row[\'target_date\'] ?? $scope[\'date\'] ?? \'\'));', $p0Verifier);
    }

    public function testExternalTrafficEvidenceRequiresRowLevelSystemHotelIdWhenScoped(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$rowSystemHotelId = (int)($row[\'system_hotel_id\'] ?? 0);', $p0Verifier);
        self::assertStringContainsString('system_hotel_id_missing', $p0Verifier);
        self::assertStringNotContainsString('$rowSystemHotelId = (int)($row[\'system_hotel_id\'] ?? $scope[\'system_hotel_id\'] ?? $data[\'system_hotel_id\'] ?? 0);', $p0Verifier);
    }

    public function testExternalTrafficEvidenceRequiresRowLevelPlatform(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$platform = strtolower(trim((string)($row[\'platform\'] ?? \'\')));', $p0Verifier);
        self::assertStringContainsString('platform_missing', $p0Verifier);
        self::assertStringNotContainsString('$platform = strtolower(trim((string)($row[\'platform\'] ?? $scope[\'platform\'] ?? \'\')));', $p0Verifier);
    }

    public function testExternalTrafficEvidenceCollectorDoesNotSynthesizeRowPlatformFromContainerKey(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringNotContainsString('if ($platformHint !== null && !isset($value[\'platform\'])) {', $p0Verifier);
        self::assertStringNotContainsString('$value[\'platform\'] = $platformHint;', $p0Verifier);
    }

    public function testExternalTrafficEvidenceRequiresRowLevelScopePolicy(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$scopePolicy = trim((string)($row[\'scope_policy\'] ?? $row[\'source_scope\'] ?? \'\'));', $p0Verifier);
        self::assertStringContainsString('scope_policy_missing', $p0Verifier);
        self::assertStringNotContainsString('$scopePolicy = trim((string)($row[\'scope_policy\'] ?? $row[\'source_scope\'] ?? $scope[\'source_scope\'] ?? $scope[\'scope_policy\'] ?? \'\'));', $p0Verifier);
    }

    public function testExternalTrafficEvidenceUnknownRowsMakeOverallEvidenceInvalid(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$base[\'status\'] = $unknownIssues !== [] ? \'invalid\' : (', $p0Verifier);
        self::assertStringNotContainsString('$base[\'status\'] = $validPlatforms === count($platforms) && $unknownIssues === [] ? \'valid\' : ($validPlatforms > 0 ? \'partial\' : \'invalid\');', $p0Verifier);
    }

    public function testExternalTrafficEvidenceUnknownRowsKeepSensitiveFlagAndRowIssues(): void
    {
        $evidencePath = tempnam(sys_get_temp_dir(), 'p0_external_evidence_');
        self::assertIsString($evidencePath);

        $payload = [
            'traffic_evidence' => [[
                'target_date' => '2026-06-25',
                'system_hotel_id' => 7,
                'scope_policy' => 'ota_channel_only',
                'sensitive_values_exposed' => false,
                'capture_evidence' => [
                    'source_trace_id' => 'trace-unknown-platform',
                    'source_url_hash' => 'hash-unknown-platform',
                ],
                'debug_url' => 'https://example.invalid/raw-sensitive-url',
                'field_facts' => [],
                'ui_status' => [
                    'field_fact_status' => 'ready',
                    'raw_data_exposed' => false,
                    'missing_count' => 0,
                    'stored_value_missing_count' => 0,
                ],
                'traffic_closure_chain' => [],
                'traffic_closure_chain_policy' => 'pre-import source proof only; P0 remains incomplete until target-date rows are ingested',
            ]],
        ];

        file_put_contents($evidencePath, json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
            $command = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg($script)
                . ' --date=2026-06-25 --platform=ctrip --system-hotel-id=7 '
                . escapeshellarg('--traffic-evidence=' . $evidencePath)
                . ' 2>&1';
            exec($command, $output, $exitCode);

            self::assertSame(2, $exitCode, implode("\n", $output));
            $decoded = json_decode(implode("\n", $output), true);
            self::assertIsArray($decoded);

            $external = $decoded['external_traffic_evidence'] ?? null;
            self::assertIsArray($external);
            self::assertSame('invalid', $external['status'] ?? null);
            self::assertTrue($external['sensitive_values_exposed'] ?? null);

            $issues = $external['issues'] ?? [];
            self::assertIsArray($issues);
            self::assertSame('traffic_evidence_platform_not_selected', $issues[0]['code'] ?? null);
            self::assertIsArray($issues[0]['row_issues'] ?? null);

            $rowIssueCodes = array_map(
                static fn(array $issue): string => (string)($issue['code'] ?? ''),
                $issues[0]['row_issues']
            );
            self::assertContains('platform_missing', $rowIssueCodes);
            self::assertContains('sensitive_material_present', $rowIssueCodes);
        } finally {
            if (is_file($evidencePath)) {
                unlink($evidencePath);
            }
        }
    }

    public function testExternalTrafficEvidenceMalformedRowsAreNotSilentlyIgnored(): void
    {
        $evidencePath = tempnam(sys_get_temp_dir(), 'p0_external_evidence_');
        self::assertIsString($evidencePath);

        $payload = [
            'traffic_evidence' => [[
                'platform' => 'ctrip',
                'target_date' => '2026-06-25',
                'system_hotel_id' => 7,
                'scope_policy' => 'ota_channel_only',
                'sensitive_values_exposed' => false,
                'debug_url' => 'https://example.invalid/raw-sensitive-url',
            ]],
        ];

        file_put_contents($evidencePath, json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
            $command = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg($script)
                . ' --date=2026-06-25 --platform=ctrip --system-hotel-id=7 '
                . escapeshellarg('--traffic-evidence=' . $evidencePath)
                . ' 2>&1';
            exec($command, $output, $exitCode);

            self::assertSame(2, $exitCode, implode("\n", $output));
            $decoded = json_decode(implode("\n", $output), true);
            self::assertIsArray($decoded);

            $external = $decoded['external_traffic_evidence'] ?? null;
            self::assertIsArray($external);
            self::assertSame('invalid', $external['status'] ?? null);
            self::assertTrue($external['sensitive_values_exposed'] ?? null);

            $platform = $external['platforms']['ctrip'] ?? null;
            self::assertIsArray($platform);
            self::assertSame(1, $platform['evidence_rows'] ?? null);
            self::assertSame('invalid', $platform['status'] ?? null);

            $rowIssueCodes = array_map(
                static fn(array $issue): string => (string)($issue['code'] ?? ''),
                $platform['issues'] ?? []
            );
            self::assertContains('field_facts_missing', $rowIssueCodes);
            self::assertContains('source_trace_id_missing', $rowIssueCodes);
            self::assertContains('sensitive_material_present', $rowIssueCodes);
        } finally {
            if (is_file($evidencePath)) {
                unlink($evidencePath);
            }
        }
    }

    public function testHotelScopedNextStepsProjectSafeCredentialMetadataFailureCodes(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $start = strpos($verifier, 'function p0_platform_traffic_gate_next_steps');
        $end = strpos($verifier, "\n/**", is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($verifier, $start, $end - $start);

        self::assertStringContainsString("'credential_metadata_status' =>", $method);
        self::assertStringContainsString("'credential_metadata_reason' =>", $method);
        self::assertStringNotContainsString("'credential_ref' =>", $method);
        self::assertStringNotContainsString("'credential_status' =>", $method);
    }

    public function testBrowserProfileCredentialResolutionKeepsScopeChecksButDoesNotRequireVault(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $start = strpos($verifier, 'function p0_resolve_source_credential_metadata');
        $end = strpos($verifier, "\n/**", is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($verifier, $start, $end - $start);

        self::assertStringContainsString('p0_is_browser_profile_ingestion_method', $method);
        self::assertStringContainsString("'reason' => 'data_source_tenant_scope_mismatch'", $method);
        self::assertStringContainsString("'reason' => 'source_config_projection_conflict'", $method);
        self::assertStringContainsString("'status' => 'not_required'", $method);
        self::assertStringContainsString("'reason' => 'browser_profile_vault_not_required'", $method);

        $tenantCheck = strpos($method, "'reason' => 'data_source_tenant_scope_mismatch'");
        $identityCheck = strpos($method, "'reason' => 'source_config_projection_conflict'");
        $profileReturn = strpos($method, "'reason' => 'browser_profile_vault_not_required'");
        self::assertIsInt($tenantCheck);
        self::assertIsInt($identityCheck);
        self::assertIsInt($profileReturn);
        self::assertLessThan($profileReturn, $tenantCheck);
        self::assertLessThan($profileReturn, $identityCheck);
    }

    public function testBrowserProfileSourceReadinessRequiresProfileDirectoryLoginAndPlatformIdentity(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString("in_array(\$method, ['browser_profile', 'profile_browser'], true)", $verifier);
        self::assertStringContainsString('p0_traffic_profile_dir_present($platform, $config)', $verifier);
        self::assertStringContainsString("\$platform === 'meituan'", $verifier);
        self::assertStringContainsString("['store_id', 'storeId', 'profile_id', 'profileId']", $verifier);
        self::assertStringContainsString("return 'profile_not_prepared';", $verifier);
        self::assertStringContainsString("return 'platform_hotel_identifier_missing';", $verifier);
        self::assertStringContainsString("'traffic_profile_dir_present_count' =>", $verifier);
        self::assertStringContainsString("'traffic_profile_platform_hotel_identifier_count' =>", $verifier);
    }

    public function testBrowserProfileClosureDoesNotInheritManualCredentialRequirements(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $pathStart = strpos($verifier, 'function p0_traffic_closure_path_options');
        $pathEnd = strpos($verifier, "\n/**", is_int($pathStart) ? $pathStart : 0);
        $availabilityStart = strpos($verifier, 'function p0_traffic_availability_status');
        $availabilityEnd = strpos($verifier, "\n/**", is_int($availabilityStart) ? $availabilityStart : 0);

        self::assertIsInt($pathStart);
        self::assertIsInt($pathEnd);
        self::assertIsInt($availabilityStart);
        self::assertIsInt($availabilityEnd);

        $pathMethod = substr($verifier, $pathStart, $pathEnd - $pathStart);
        $availabilityMethod = substr($verifier, $availabilityStart, $availabilityEnd - $availabilityStart);
        self::assertStringContainsString('$profilePreparationMissing = [];', $pathMethod);
        self::assertStringNotContainsString('$profileMissing = array_values(array_filter(', $pathMethod);
        self::assertStringContainsString("\$manualMissing[] = 'ota_credential_metadata_blocked';", $pathMethod);
        self::assertStringContainsString("\$manualMissing[] = 'ota_credential_metadata_migration_required';", $pathMethod);
        self::assertStringContainsString("\$manualMissing[] = 'ready_ota_credential_metadata';", $pathMethod);

        $profileMissingStart = strpos($pathMethod, '$profilePreparationMissing = [];');
        $profileMissingEnd = strpos($pathMethod, '$evidenceMissing = [];');
        self::assertIsInt($profileMissingStart);
        self::assertIsInt($profileMissingEnd);
        $profileMissingBlock = substr($pathMethod, $profileMissingStart, $profileMissingEnd - $profileMissingStart);
        self::assertStringNotContainsString('ota_credential_metadata_', $profileMissingBlock);
        self::assertStringNotContainsString('ready_ota_credential_metadata', $profileMissingBlock);
        self::assertStringContainsString("'traffic_browser_profile_count'", $availabilityMethod);
        self::assertStringContainsString("'traffic_profile_dir_present_count'", $availabilityMethod);

        $profileBranch = strpos($availabilityMethod, "'traffic_browser_profile_count'");
        $credentialBranch = strpos($availabilityMethod, "'credential_metadata_status'");
        self::assertIsInt($profileBranch);
        self::assertIsInt($credentialBranch);
        self::assertLessThan($credentialBranch, $profileBranch);
    }

    public function testLiveInspectorLatestAvailableExcludesDatesAfterCurrentShanghaiDate(): void
    {
        $inspector = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'inspect_phase1_ota_live_closure.php');
        $start = strpos($inspector, 'function query_latest_available_source_rows');
        $end = strpos($inspector, "\n/**", is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($inspector, $start, $end - $start);

        self::assertStringContainsString("new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))", $method);
        self::assertStringContainsString("->where('data_date', '<=', \$currentDate)", $method);

        $builder = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build_phase1_ota_live_closure_evidence.php');
        $builderStart = strpos($builder, 'function query_latest_available_source_rows');
        $builderEnd = strpos($builder, "\nfunction ", is_int($builderStart) ? $builderStart + 1 : 0);

        self::assertIsInt($builderStart);
        self::assertIsInt($builderEnd);
        $builderMethod = substr($builder, $builderStart, $builderEnd - $builderStart);

        self::assertStringContainsString("new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))", $builderMethod);
        self::assertStringContainsString("->where('data_date', '<=', \$currentDate)", $builderMethod);
    }

    public function testBrowserProfileReadinessRequiresCurrentSessionProofOnTheSameSource(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('function p0_traffic_current_session_verified', $verifier);
        self::assertStringContainsString("'current_session_probe_performed'", $verifier);
        self::assertStringContainsString("'current_session_verified'", $verifier);
        self::assertStringContainsString("'historical_login_metadata_present'", $verifier);
        self::assertStringContainsString('$profilePreparedForProbe = $isBrowserProfileSource', $verifier);
        self::assertStringContainsString('&& $profileDirPresent', $verifier);
        self::assertStringContainsString('&& $platformHotelIdentifierPresent', $verifier);
        self::assertStringContainsString('$credentialMetadataAllowsActions = $credentialReady || $credentialNotRequired;', $verifier);
        self::assertStringContainsString('&& $credentialMetadataAllowsActions', $verifier);
        self::assertStringContainsString('$profileFlowReady = $profilePreparedForProbe && $currentSessionVerified;', $verifier);
        self::assertStringContainsString("'traffic_profile_flow_ready_count' =>", $verifier);

        $pathStart = strpos($verifier, 'function p0_traffic_closure_path_options');
        $pathEnd = strpos($verifier, "\n/**", is_int($pathStart) ? $pathStart : 0);
        self::assertIsInt($pathStart);
        self::assertIsInt($pathEnd);
        $pathMethod = substr($verifier, $pathStart, $pathEnd - $pathStart);
        self::assertStringContainsString("(int)(\$sources['traffic_profile_flow_ready_count'] ?? 0)", $pathMethod);
        self::assertStringContainsString("(\$profileFlowReady ? 'ready_for_sync' : 'ready_for_session_probe')", $pathMethod);
        $profileBranchEnd = strpos($pathMethod, "'mode' => 'manual_cookie_api'");
        self::assertIsInt($profileBranchEnd);
        self::assertStringNotContainsString('ready_to_attempt', substr($pathMethod, 0, $profileBranchEnd));
    }

    public function testProfileBindingOwnershipIsAuthoritativeAndNeverExposesTheProfileKeyHash(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $register = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php');

        foreach ([$verifier, $register] as $script) {
            self::assertStringContainsString('ota_profile_bindings', $script);
            self::assertStringContainsString('profile_binding_table_missing', $script);
            self::assertStringContainsString('profile_binding_missing', $script);
            self::assertStringContainsString('profile_binding_scope_mismatch', $script);
            self::assertStringContainsString('profile_scope_conflict_across_hotel_or_tenant', $script);
            self::assertStringContainsString("'binding_status', 'active'", $script);
        }

        $rowStart = strpos($verifier, '$trafficSourceRow = [');
        $rowEnd = strpos($verifier, '];', is_int($rowStart) ? $rowStart : 0);
        self::assertIsInt($rowStart);
        self::assertIsInt($rowEnd);
        self::assertStringNotContainsString("'profile_key_hash' =>", substr($verifier, $rowStart, $rowEnd - $rowStart));
    }

    public function testProfileKeyCanonicalizationUsesTheRuntimeProfilePathContractEverywhere(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $register = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php');

        $verifierHashStart = strpos($verifier, 'function p0_profile_key_hash');
        $verifierHashEnd = strpos($verifier, "\n}", is_int($verifierHashStart) ? $verifierHashStart : 0);
        self::assertIsInt($verifierHashStart);
        self::assertIsInt($verifierHashEnd);
        $verifierHash = substr($verifier, $verifierHashStart, $verifierHashEnd - $verifierHashStart);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::safeFilePart($profileKey)', $verifierHash);
        self::assertStringNotContainsString("preg_replace('/[^a-zA-Z0-9_.-]+'", $verifierHash);

        $profileDirStart = strpos($register, 'function browser_profile_dir_present');
        $profileDirEnd = strpos($register, "\n}", is_int($profileDirStart) ? $profileDirStart : 0);
        self::assertIsInt($profileDirStart);
        self::assertIsInt($profileDirEnd);
        $profileDir = substr($register, $profileDirStart, $profileDirEnd - $profileDirStart);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::safeFilePart($profileKey)', $profileDir);
        self::assertStringNotContainsString("preg_replace('/[^a-zA-Z0-9_.-]+'", $profileDir);
    }

    public function testP0RegistrationUsesStrictShanghaiDateAndExcludesForecastOnlyHotels(): void
    {
        $register = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php');

        self::assertStringContainsString("DateTimeImmutable::createFromFormat('!Y-m-d'", $register);
        self::assertStringContainsString('Invalid --date, expected a real calendar date in YYYY-MM-DD.', $register);
        self::assertStringContainsString('--date must not be later than the current Asia/Shanghai date.', $register);
        self::assertStringContainsString('set_exception_handler(', $register);
        self::assertStringContainsString('exit(2);', $register);
        self::assertStringContainsString("->whereNotIn('data_type', ['traffic_forecast'])", $register);
    }

    public function testP0RegistrationTreatsHistoricalProfileMetadataAsProbePreparationOnly(): void
    {
        $register = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php');
        $start = strpos($register, 'function build_source_spec');
        $end = strpos($register, "\n/**", is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($register, $start, $end - $start);
        self::assertStringContainsString("'manual_login_state_verified' => false", $method);
        self::assertStringContainsString("'current_session_probe_performed' => false", $method);
        self::assertStringContainsString("'current_session_verified' => false", $method);
        self::assertStringContainsString("'session_probe_status' => 'ready_for_session_probe'", $method);
        self::assertStringContainsString("'login_evidence_scope' => 'historical_metadata_only'", $method);
        self::assertStringNotContainsString('apply_profile_login_inheritance(', $method);
    }

    public function testP0VerifierRejectsAnEmptyNormalizedPlatformSelection(): void
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --date=2026-07-10 ' . escapeshellarg('--platform=,')
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        self::assertIsArray($decoded);
        self::assertSame('failed', $decoded['status'] ?? null);
        self::assertSame('p0_field_loop_verifier_runtime_error', $decoded['issues'][0]['code'] ?? null);
        self::assertStringContainsString('at least one', (string)($decoded['issues'][0]['message'] ?? ''));
    }

    public function testP0RegistrationRejectsAnEmptyNormalizedPlatformSelection(): void
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --date=2026-07-10 ' . escapeshellarg('--platform=,')
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(2, $exitCode, implode("\n", $output));
        self::assertStringContainsString('at least one', implode("\n", $output));
        self::assertStringNotContainsString('"status": "ready"', implode("\n", $output));
    }

    public function testP0VerifierRejectsInvalidCalendarDates(): void
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --date=2026-02-31 --platform=ctrip 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        self::assertIsArray($decoded);
        self::assertSame('failed', $decoded['status'] ?? null);
        self::assertStringContainsString('real calendar date', (string)($decoded['issues'][0]['message'] ?? ''));
    }

    public function testStoredTrafficClosureIsPerHotelAllRowsAndExcludesForecastPeriods(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString("isset(\$columns['data_period'])", $verifier);
        self::assertStringContainsString("->whereOr('data_period', 'not in', ['next_7_days', 'next_30_days', 'forecast', 'future_forecast'])", $verifier);
        self::assertStringContainsString("(int)\$base['ui_status_incomplete_rows'] === 0", $verifier);
        self::assertStringContainsString("'hotel_scoped_field_fact_closures' => []", $verifier);
        self::assertStringContainsString("'hotel_scoped_closure_status'", $verifier);
        self::assertStringContainsString("p0_traffic_field_fact_closure(\$platform, \$targetDate, \$hotelId)", $verifier);
        self::assertStringContainsString("\$base['status'] = 'hotel_scoped_incomplete';", $verifier);
    }

    public function testStoredTrafficIdentifierMatchesTheAuthoritativeProfileSourceWithoutRawOutput(): void
    {
        $this->loadPlatformIdentifierHelpers();

        $rawIdentifier = 'raw-ctrip-hotel-7001';
        $authority = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, [[
            'id' => 91,
            'tenant_id' => 70,
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => true,
            'profile_binding_status' => 'ready',
            'config' => ['hotelId' => $rawIdentifier],
        ]]);
        $comparison = p0_compare_row_platform_hotel_identifier(
            ['capture' => ['hotel_id' => $rawIdentifier]],
            'ctrip',
            $authority
        );

        self::assertSame('ready', $authority['status'] ?? null);
        self::assertSame('matched', $comparison['status'] ?? null);
        self::assertTrue($comparison['matched'] ?? false);
        self::assertSame(1, $comparison['row_identifier_count'] ?? null);
        self::assertNotSame('', $comparison['expected_identifier_hash'] ?? '');
        self::assertSame($comparison['expected_identifier_hash'] ?? null, $comparison['row_identifier_hash'] ?? null);
        self::assertStringNotContainsString($rawIdentifier, json_encode([$authority, $comparison], JSON_UNESCAPED_SLASHES));
    }

    public function testStoredTrafficIdentifierMismatchFailsClosedWithoutRawOutput(): void
    {
        $this->loadPlatformIdentifierHelpers();

        $expectedRawIdentifier = 'raw-meituan-poi-7001';
        $storedRawIdentifier = 'raw-meituan-poi-7002';
        $authority = p0_authoritative_profile_identifier_resolution('meituan', 7, 70, [[
            'id' => 92,
            'tenant_id' => 70,
            'system_hotel_id' => 7,
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'ingestion_method' => 'profile_browser',
            'status' => 'success',
            'enabled' => 1,
            'profile_binding_status' => 'ready',
            'config' => ['poiId' => $expectedRawIdentifier],
        ]]);
        $comparison = p0_compare_row_platform_hotel_identifier(
            ['source_row' => ['poi_id' => $storedRawIdentifier]],
            'meituan',
            $authority
        );

        self::assertSame('mismatch', $comparison['status'] ?? null);
        self::assertSame('platform_hotel_identifier_mismatch', $comparison['reason'] ?? null);
        self::assertFalse($comparison['matched'] ?? true);
        self::assertStringNotContainsString($expectedRawIdentifier, json_encode([$authority, $comparison], JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString($storedRawIdentifier, json_encode([$authority, $comparison], JSON_UNESCAPED_SLASHES));
    }

    public function testCanonicalHotelIdentifierTakesPriorityOverAComplementaryNodeIdentifier(): void
    {
        $this->loadPlatformIdentifierHelpers();

        $authority = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, [[
            'id' => 95,
            'tenant_id' => 70,
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => true,
            'profile_binding_status' => 'ready',
            'config' => [
                'hotel_id' => 'raw-canonical-hotel',
                'node_id' => 'raw-complementary-node',
            ],
        ]]);

        self::assertSame('ready', $authority['status'] ?? null);
        self::assertSame(1, $authority['identifier_count'] ?? null);
        self::assertStringNotContainsString('raw-canonical-hotel', json_encode($authority, JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('raw-complementary-node', json_encode($authority, JSON_UNESCAPED_SLASHES));
    }

    public function testStoredTrafficIdentifierRejectsMissingOrAmbiguousAuthoritativeProfileSources(): void
    {
        $this->loadPlatformIdentifierHelpers();

        $missing = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, []);
        self::assertSame('missing', $missing['status'] ?? null);
        self::assertSame('authoritative_profile_source_missing', $missing['reason'] ?? null);

        $wrongTenant = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, [[
            'id' => 90,
            'tenant_id' => 71,
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => true,
            'profile_binding_status' => 'ready',
            'config' => ['hotel_id' => 'raw-cross-tenant-hotel'],
        ]]);
        self::assertSame('blocked', $wrongTenant['status'] ?? null);
        self::assertSame('profile_source_tenant_scope_mismatch', $wrongTenant['reason'] ?? null);
        self::assertStringNotContainsString('raw-cross-tenant-hotel', json_encode($wrongTenant, JSON_UNESCAPED_SLASHES));

        $source = static function (int $id, string $identifier): array {
            return [
                'id' => $id,
                'tenant_id' => 70,
                'system_hotel_id' => 7,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'browser_profile',
                'status' => 'ready',
                'enabled' => true,
                'profile_binding_status' => 'ready',
                'config' => ['hotel_id' => $identifier],
            ];
        };
        $ambiguous = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, [
            $source(93, 'raw-ctrip-hotel-a'),
            $source(94, 'raw-ctrip-hotel-b'),
        ]);

        self::assertSame('ambiguous', $ambiguous['status'] ?? null);
        self::assertSame('authoritative_profile_identifier_ambiguous', $ambiguous['reason'] ?? null);
        self::assertSame(2, $ambiguous['identifier_count'] ?? null);
        self::assertArrayNotHasKey('expected_identifier_hash', $ambiguous);
        self::assertStringNotContainsString('raw-ctrip-hotel-a', json_encode($ambiguous, JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('raw-ctrip-hotel-b', json_encode($ambiguous, JSON_UNESCAPED_SLASHES));
    }

    public function testStoredTrafficClosureRequiresEveryRowIdentifierToMatchAProfileAuthority(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $closureStart = strpos($verifier, 'function p0_traffic_field_fact_closure');
        $closureEnd = strpos($verifier, "\n/**", is_int($closureStart) ? $closureStart + 1 : 0);
        $resolverStart = strpos($verifier, 'function p0_authoritative_profile_identifier_from_db');
        $resolverEnd = strpos($verifier, "\n/**", is_int($resolverStart) ? $resolverStart + 1 : 0);

        self::assertIsInt($closureStart);
        self::assertIsInt($closureEnd);
        self::assertIsInt($resolverStart);
        self::assertIsInt($resolverEnd);
        $closure = substr($verifier, $closureStart, $closureEnd - $closureStart);
        $resolver = substr($verifier, $resolverStart, $resolverEnd - $resolverStart);

        self::assertStringContainsString('p0_authoritative_profile_identifier_from_db($platform, $rowSystemHotelId)', $closure);
        self::assertStringContainsString('p0_compare_row_platform_hotel_identifier($raw, $platform, $identifierAuthority)', $closure);
        self::assertStringContainsString("'platform_hotel_identifier_matched_rows' => 0", $closure);
        self::assertStringContainsString("'platform_hotel_identifier_mismatch_rows' => 0", $closure);
        self::assertStringContainsString("'platform_hotel_identifier_match_reason_counts' => []", $closure);
        self::assertStringContainsString("\$base['platform_hotel_identifier_matched_rows'] === (int)\$base['traffic_row_count']", $closure);
        self::assertStringContainsString("\$base['platform_hotel_identifier_match_status'] = \$allIdentifiersMatched ? 'matched' : 'unmatched';", $closure);
        self::assertStringContainsString("\$base['status'] = 'platform_hotel_identifier_mismatch';", $closure);

        self::assertStringContainsString("->where('system_hotel_id', \$systemHotelId)", $resolver);
        self::assertStringContainsString("->where('enabled', 1)", $resolver);
        self::assertStringContainsString("->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])", $resolver);
        self::assertStringContainsString('p0_safe_platform_config_projection', $resolver);
        self::assertStringContainsString('p0_profile_binding_scope_status', $resolver);
        self::assertStringNotContainsString('secret_json', $resolver);
        self::assertStringNotContainsString('ota_credentials', $resolver);
    }

    private function loadPlatformIdentifierHelpers(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach ([
            'p0_platform_hotel_identifier_keys',
            'p0_platform_hotel_identifier_hashes',
            'p0_authoritative_profile_identifier_resolution',
            'p0_compare_row_platform_hotel_identifier',
        ] as $functionName) {
            if (function_exists(__NAMESPACE__ . '\\' . $functionName) || function_exists($functionName)) {
                continue;
            }
            $definition = $this->extractFunctionDefinition($verifier, $functionName);
            self::assertNotSame('', $definition, 'Missing pure verifier helper: ' . $functionName);
            eval($definition);
        }
    }

    private function extractFunctionDefinition(string $source, string $functionName): string
    {
        $start = strpos($source, 'function ' . $functionName . '(');
        if (!is_int($start)) {
            return '';
        }
        $brace = strpos($source, '{', $start);
        if (!is_int($brace)) {
            return '';
        }
        $depth = 0;
        $length = strlen($source);
        for ($index = $brace; $index < $length; $index++) {
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
