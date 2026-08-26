#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\AiDailyReportPresentationArtifactService;
use app\service\AiDailyReportPresentationRendererService;
use app\service\AiDailyReportPresentationSpecService;
use app\service\AiDailyReportService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

$options = getopt('', ['output-dir::']);
$outputDirectory = trim((string)($options['output-dir'] ?? ''));
$errors = [];
$summary = [];

try {
    $candidate = Db::name('ai_daily_reports')
        ->alias('report')
        ->join('hotels hotel', 'hotel.id = report.hotel_id')
        ->whereNull('report.deleted_at')
        ->where('hotel.tenant_id', '>', 0)
        ->field('report.id,report.hotel_id,report.tenant_id,report.report_date')
        ->order('report.id', 'desc')
        ->find();
    if (!is_array($candidate)) {
        throw new RuntimeException('no persisted AI daily report is available for artifact verification');
    }

    $reportId = (int)($candidate['id'] ?? 0);
    $hotelId = (int)($candidate['hotel_id'] ?? 0);
    $reportDate = trim((string)($candidate['report_date'] ?? ''));
    $report = (new AiDailyReportService())->read($reportId, [$hotelId]);
    if (!is_array($report)
        || (int)($report['id'] ?? 0) !== $reportId
        || (int)($report['hotel_id'] ?? 0) !== $hotelId
    ) {
        throw new RuntimeException('tenant/hotel-scoped AI daily report read failed');
    }

    $specService = new AiDailyReportPresentationSpecService();
    $artifactService = new AiDailyReportPresentationArtifactService();
    $renderer = new AiDailyReportPresentationRendererService();
    $tenantId = $specService->resolveTenantScope($report);
    $storedSpec = $specService->saveAndReadback($report, 'owner', 0);
    $first = $artifactService->saveAndReadback($storedSpec, 0, true);
    $second = $artifactService->saveAndReadback($storedSpec, 0, false);
    $latest = $artifactService->readLatest(
        $reportId,
        [$hotelId],
        $tenantId,
        'owner',
        true,
        (int)$storedSpec['record_id'],
        (string)$storedSpec['spec_fingerprint']
    );
    $exact = $artifactService->readExact(
        $reportId,
        (int)($first['artifact_id'] ?? 0),
        [$hotelId],
        $tenantId,
        false
    );

    if (!is_array($latest) || !is_array($exact)) {
        $errors[] = 'artifact_latest_readback_missing';
    } else {
        foreach ([$first, $second, $latest, $exact] as $index => $artifact) {
            if (($artifact['artifact_readback_verified'] ?? false) !== true) {
                $errors[] = 'artifact_readback_unverified:' . $index;
            }
            if (($artifact['render_status'] ?? '') !== 'rendered_and_readback_verified') {
                $errors[] = 'artifact_render_status_mismatch:' . $index;
            }
            if (($artifact['authorization']['external_write_authorized'] ?? true) !== false
                || ($artifact['authorization']['ota_write_authorized'] ?? true) !== false
                || ($artifact['authorization']['pms_write_authorized'] ?? true) !== false
                || ($artifact['authorization']['publish_authorized'] ?? true) !== false
            ) {
                $errors[] = 'artifact_authorization_boundary_mismatch:' . $index;
            }
        }
        if ((int)($first['artifact_id'] ?? 0) !== (int)($second['artifact_id'] ?? -1)
            || (int)($first['artifact_id'] ?? 0) !== (int)($latest['artifact_id'] ?? -1)
            || (int)($first['artifact_id'] ?? 0) !== (int)($exact['artifact_id'] ?? -1)
        ) {
            $errors[] = 'artifact_idempotency_mismatch';
        }
        if (!hash_equals(
            (string)($first['content_sha256'] ?? ''),
            (string)($latest['content_sha256'] ?? '')
        )) {
            $errors[] = 'artifact_content_hash_mismatch';
        }
        if ((int)($storedSpec['record_id'] ?? 0) !== (int)($latest['presentation_spec_id'] ?? -1)
            || !hash_equals(
                (string)($storedSpec['spec_fingerprint'] ?? ''),
                (string)($latest['spec_fingerprint'] ?? '')
            )
        ) {
            $errors[] = 'artifact_spec_binding_mismatch';
        }

        $bundleBase64 = trim((string)($latest['bundle_base64'] ?? ''));
        $bundle = base64_decode($bundleBase64, true);
        if (!is_string($bundle)
            || !hash_equals((string)($latest['content_sha256'] ?? ''), hash('sha256', $bundle))
            || strlen($bundle) !== (int)($latest['content_bytes'] ?? -1)
        ) {
            $errors[] = 'artifact_bundle_payload_mismatch';
        } elseif (($renderer->verifyBundle($bundle, (array)($latest['manifest'] ?? []))['status'] ?? '') !== 'pass') {
            $errors[] = 'artifact_bundle_contract_failed';
        }

        if ($outputDirectory !== '' && is_string($bundle)) {
            $outputDirectory = normalizeOutputDirectory($outputDirectory);
            $written = writeArtifactFiles(
                $outputDirectory,
                (string)($latest['filename'] ?? 'suxios-ai-daily-bundle.zip'),
                $bundle,
                (array)($latest['manifest'] ?? [])
            );
            $summary['output_files'] = $written;
        }
    }

    $trainingSpec = $specService->build($report, 'training');
    $trainingJson = json_encode($trainingSpec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $trainingSource = is_array($trainingSpec['source_report'] ?? null) ? $trainingSpec['source_report'] : [];
    if (!array_key_exists('tenant_id', $trainingSource)
        || !array_key_exists('report_id', $trainingSource)
        || !array_key_exists('hotel_id', $trainingSource)
        || !array_key_exists('business_date', $trainingSource)
        || $trainingSource['tenant_id'] !== null
        || $trainingSource['report_id'] !== null
        || $trainingSource['hotel_id'] !== null
        || $trainingSource['business_date'] !== null
        || ($trainingSpec['source_report']['anonymization_status'] ?? '') !== 'identity_fields_removed_content_review_required'
    ) {
        $errors[] = 'training_identity_boundary_mismatch';
    }
    if ($reportDate !== '' && str_contains($trainingJson, $reportDate)) {
        $errors[] = 'training_exact_business_date_leaked';
    }
    if (in_array('HUMAN_DECISION', array_column((array)($trainingSpec['evidence_ledger'] ?? []), 'class'), true)) {
        $errors[] = 'training_human_decision_leaked';
    }

    $summary = array_merge([
        'report_id' => $reportId,
        'hotel_id' => $hotelId,
        'business_date' => $reportDate,
        'presentation_spec_id' => (int)($storedSpec['record_id'] ?? 0),
        'spec_storage_status' => (string)($storedSpec['storage_status'] ?? ''),
        'spec_fingerprint' => (string)($storedSpec['spec_fingerprint'] ?? ''),
        'adapter_version' => (string)($storedSpec['spec']['adapter_version'] ?? ''),
        'artifact_id' => (int)($first['artifact_id'] ?? 0),
        'artifact_storage_status_first' => (string)($first['storage_status'] ?? ''),
        'artifact_storage_status_second' => (string)($second['storage_status'] ?? ''),
        'renderer_version' => (string)($first['renderer_version'] ?? ''),
        'artifact_filename' => (string)($first['filename'] ?? ''),
        'content_sha256' => (string)($first['content_sha256'] ?? ''),
        'content_bytes' => (int)($first['content_bytes'] ?? 0),
        'artifact_readback_verified' => ($first['artifact_readback_verified'] ?? false) === true,
        'bundle_contract_status' => 'pass',
        'training_anonymization_status' => (string)($trainingSpec['source_report']['anonymization_status'] ?? ''),
        'human_review_status' => (string)($first['manifest']['contract']['human_review_status'] ?? ''),
        'external_write_authorized' => false,
    ], $summary);
} catch (Throwable $exception) {
    $errors[] = 'exception:' . get_class($exception) . ':' . $exception->getMessage();
}

$result = [
    'status' => $errors === [] ? 'pass' : 'fail',
    'summary' => $summary,
    'errors' => $errors,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);

function normalizeOutputDirectory(string $value): string
{
    $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($value));
    if ($candidate === '') {
        throw new RuntimeException('artifact output directory is empty');
    }
    if (!preg_match('/^[A-Za-z]:\\\\/', $candidate)) {
        $candidate = dirname(__DIR__) . DIRECTORY_SEPARATOR . $candidate;
    }
    if (!is_dir($candidate) && !mkdir($candidate, 0775, true) && !is_dir($candidate)) {
        throw new RuntimeException('unable to create artifact output directory');
    }
    $resolved = realpath($candidate);
    if (!is_string($resolved) || $resolved === '') {
        throw new RuntimeException('unable to resolve artifact output directory');
    }
    return $resolved;
}

/**
 * @param array<string,mixed> $manifest
 * @return array<int,string>
 */
function writeArtifactFiles(string $directory, string $bundleName, string $bundle, array $manifest): array
{
    $bundleName = safeArtifactFilename($bundleName);
    $written = [];
    $bundlePath = $directory . DIRECTORY_SEPARATOR . $bundleName;
    writeExactFile($bundlePath, $bundle);
    $written[] = $bundlePath;

    $archivePath = tempnam(sys_get_temp_dir(), 'suxios-artifact-output-');
    if (!is_string($archivePath) || file_put_contents($archivePath, $bundle, LOCK_EX) !== strlen($bundle)) {
        throw new RuntimeException('unable to stage artifact bundle for output');
    }
    $zip = new ZipArchive();
    try {
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('artifact bundle cannot be opened for output');
        }
        $componentNames = ['manifest.json', 'presentation-spec.json'];
        foreach (['html', 'pptx'] as $key) {
            $name = trim((string)($manifest['components'][$key]['filename'] ?? ''));
            if ($name !== '') {
                $componentNames[] = $name;
            }
        }
        foreach (array_values(array_unique($componentNames)) as $name) {
            $safeName = safeArtifactFilename($name);
            $content = $zip->getFromName($name);
            if (!is_string($content)) {
                throw new RuntimeException('artifact output component missing: ' . $name);
            }
            $path = $directory . DIRECTORY_SEPARATOR . $safeName;
            writeExactFile($path, $content);
            $written[] = $path;
        }
        $zip->close();
    } finally {
        if (is_file($archivePath)) {
            unlink($archivePath);
        }
    }
    return $written;
}

function safeArtifactFilename(string $name): string
{
    $name = basename(str_replace('\\', '/', trim($name)));
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if (!is_string($safe) || $safe === '' || $safe === '.' || $safe === '..') {
        throw new RuntimeException('artifact output filename is invalid');
    }
    return $safe;
}

function writeExactFile(string $path, string $content): void
{
    if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
        throw new RuntimeException('artifact output write failed: ' . $path);
    }
}
