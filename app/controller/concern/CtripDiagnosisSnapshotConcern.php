<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\BrowserProfileCaptureRequestService;

trait CtripDiagnosisSnapshotConcern
{
    private function buildCtripPartialCaptureErrorPayload(string $outputPath): array
    {
        if (!is_file($outputPath)) {
            return [
                'available' => false,
                'output' => $outputPath,
            ];
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return [
                'available' => false,
                'output' => $outputPath,
                'parse_error' => true,
            ];
        }

        return [
            'available' => true,
            'output' => $outputPath,
            'auth_status' => $payload['auth_status'] ?? null,
            'capture_gate' => $payload['capture_gate'] ?? null,
            'capture_audit' => $payload['capture_audit'] ?? null,
            'captured_counts' => $this->buildCtripCaptureCounts($payload),
            'diagnosis_summary' => $this->buildCtripCaptureDiagnosisSummary($payload),
            'pages' => $payload['pages'] ?? [],
            'xhr_urls' => array_slice($payload['xhr_urls'] ?? [], 0, 20),
        ];
    }

    private function buildLatestCtripDiagnosisSnapshot(string $profileId = ''): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $captureDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        if (!is_dir($captureDir)) {
            return [
                'available' => false,
                'reason' => 'capture_dir_missing',
                'capture_dir' => $captureDir,
            ];
        }

        $profileNeedle = $profileId !== '' ? BrowserProfileCaptureRequestService::safeFilePart($profileId) : '';
        $snapshotFiles = $this->filterCtripCaptureFilesByProfile(
            glob($captureDir . DIRECTORY_SEPARATOR . '*.diagnosis.snapshot.json') ?: [],
            $profileNeedle
        );
        $latestSnapshot = $this->latestFileByMtime($snapshotFiles);
        if ($latestSnapshot !== '') {
            $snapshot = $this->readJsonFile($latestSnapshot);
            if (is_array($snapshot)) {
                return array_merge($snapshot, [
                    'available' => true,
                    'source' => 'diagnosis_snapshot',
                    'snapshot_path' => $latestSnapshot,
                    'profile_filter' => $profileId,
                ]);
            }
        }

        $rawFiles = array_values(array_filter(
            glob($captureDir . DIRECTORY_SEPARATOR . '*.json') ?: [],
            static function (string $file): bool {
                $name = strtolower(basename($file));
                return !str_contains($name, '.summary.')
                    && !str_contains($name, '.audit.')
                    && !str_contains($name, '.snapshot.')
                    && !str_starts_with($name, 'direct_verify_');
            }
        ));
        $rawFiles = $this->filterCtripCaptureFilesByProfile($rawFiles, $profileNeedle);
        usort($rawFiles, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        $payloads = [];
        $sourceFiles = [];
        foreach (array_slice($rawFiles, 0, 8) as $file) {
            $payload = $this->readJsonFile($file);
            if (!is_array($payload)) {
                continue;
            }
            if ($profileId !== '' && trim((string)($payload['profile_id'] ?? '')) !== '' && trim((string)$payload['profile_id']) !== $profileId) {
                continue;
            }
            if (
                $this->countCtripPayloadSection($payload, 'responses') <= 0
                && $this->countCtripPayloadSection($payload, 'catalog_facts') <= 0
                && $this->countCtripPayloadSection($payload, 'standard_rows') <= 0
            ) {
                continue;
            }
            $payloads[] = $payload;
            $sourceFiles[] = $file;
        }

        if ($payloads === []) {
            return [
                'available' => false,
                'reason' => 'capture_files_missing',
                'capture_dir' => $captureDir,
                'profile_filter' => $profileId,
            ];
        }

        return $this->aggregateCtripDiagnosisSnapshot($payloads, $sourceFiles, $profileId);
    }

    private function filterCtripCaptureFilesByProfile(array $files, string $profileNeedle): array
    {
        if ($profileNeedle === '') {
            return $files;
        }
        return array_values(array_filter(
            $files,
            static fn(string $file): bool => str_contains(strtolower(basename($file)), strtolower($profileNeedle))
        ));
    }

    private function latestFileByMtime(array $files): string
    {
        $files = array_values(array_filter($files, 'is_file'));
        if ($files === []) {
            return '';
        }
        usort($files, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        return $files[0];
    }

    private function readJsonFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function aggregateCtripDiagnosisSnapshot(array $payloads, array $sourceFiles, string $profileId = ''): array
    {
        $metricLabels = $this->ctripCaptureDiagnosisMetricLabels();
        $groupMap = [];
        foreach ($this->ctripCaptureDiagnosisGroups() as $groupName => $expectedMetricKeys) {
            $groupMap[$groupName] = [
                'name' => $groupName,
                'status' => 'missing',
                'captured_count' => 0,
                'expected_count' => count($expectedMetricKeys),
                'captured_metric_keys' => [],
                'captured_metrics' => [],
            ];
        }

        $counts = [
            'responses' => 0,
            'catalog_facts' => 0,
            'standard_rows' => 0,
            'pages' => 0,
            'endpoint_candidates' => 0,
            'p3_evidence_drafts' => 0,
        ];
        $capturedMetrics = [];
        $sectionCounts = [];
        $authStatuses = [];

        foreach ($payloads as $payload) {
            $summary = $this->buildCtripCaptureDiagnosisSummary($payload);
            $payloadCounts = $this->buildCtripCaptureCounts($payload);
            foreach ($counts as $key => $value) {
                $counts[$key] += (int)($payloadCounts[$key] ?? 0);
            }
            foreach (is_array($payloadCounts['standard_by_section'] ?? null) ? $payloadCounts['standard_by_section'] : [] as $section => $count) {
                $section = (string)$section;
                $sectionCounts[$section] = ($sectionCounts[$section] ?? 0) + (int)$count;
            }
            foreach (is_array($summary['captured_metric_keys'] ?? null) ? $summary['captured_metric_keys'] : [] as $metricKey) {
                $capturedMetrics[(string)$metricKey] = true;
            }
            foreach (is_array($summary['groups'] ?? null) ? $summary['groups'] : [] as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $groupName = (string)($group['name'] ?? '');
                if ($groupName === '' || !isset($groupMap[$groupName])) {
                    continue;
                }
                foreach (is_array($group['captured_metric_keys'] ?? null) ? $group['captured_metric_keys'] : [] as $metricKey) {
                    $groupMap[$groupName]['captured_metric_keys'][(string)$metricKey] = true;
                }
            }
            $authStatuses[] = $payload['auth_status'] ?? null;
        }

        foreach ($groupMap as $groupName => $group) {
            $keys = array_keys($group['captured_metric_keys']);
            sort($keys);
            $groupMap[$groupName]['status'] = $keys !== [] ? 'available' : 'missing';
            $groupMap[$groupName]['captured_count'] = count($keys);
            $groupMap[$groupName]['captured_metric_keys'] = $keys;
            $groupMap[$groupName]['captured_metrics'] = array_map(
                fn(string $metricKey): array => [
                    'key' => $metricKey,
                    'label' => $metricLabels[$metricKey] ?? $metricKey,
                ],
                $keys
            );
        }

        $groups = array_values($groupMap);
        $availableGroups = array_values(array_map(
            static fn(array $group): string => (string)$group['name'],
            array_filter($groups, static fn(array $group): bool => ($group['status'] ?? '') === 'available')
        ));
        $missingGroups = array_values(array_map(
            static fn(array $group): string => (string)$group['name'],
            array_filter($groups, static fn(array $group): bool => ($group['status'] ?? '') !== 'available')
        ));
        ksort($capturedMetrics);
        ksort($sectionCounts);

        return [
            'available' => true,
            'source' => 'raw_capture_files',
            'status' => $counts['standard_rows'] > 0 && $availableGroups !== [] ? 'ready' : 'not_ready',
            'profile_filter' => $profileId,
            'generated_at' => date(DATE_ATOM),
            'inputs' => array_map(
                static fn(string $file): array => ['path' => $file, 'name' => basename($file)],
                $sourceFiles
            ),
            'auth_statuses' => $authStatuses,
            'counts' => $counts,
            'available_groups' => $availableGroups,
            'missing_groups' => $missingGroups,
            'diagnosis_summary' => [
                'status' => $counts['standard_rows'] > 0 && $availableGroups !== [] ? 'ready' : 'not_ready',
                'available_groups' => $availableGroups,
                'missing_groups' => $missingGroups,
                'groups' => $groups,
                'captured_metric_keys' => array_keys($capturedMetrics),
                'captured_metrics' => array_map(
                    fn(string $metricKey): array => [
                        'key' => $metricKey,
                        'label' => $metricLabels[$metricKey] ?? $metricKey,
                    ],
                    array_keys($capturedMetrics)
                ),
            ],
            'standard_section_counts' => $sectionCounts,
        ];
    }
}
