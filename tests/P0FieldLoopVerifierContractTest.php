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
}
