<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Persists immutable presentation bundles and verifies exact database
 * readback. Artifact generation is a local report export only; it never
 * publishes, sends messages, or writes OTA/PMS data.
 */
final class AiDailyReportPresentationArtifactService
{
    private const TABLE = 'ai_report_presentation_artifacts';

    public function __construct(
        private readonly AiDailyReportPresentationRendererService $renderer = new AiDailyReportPresentationRendererService()
    ) {
    }

    /**
     * @param array<string,mixed> $storedSpec
     * @return array<string,mixed>
     */
    public function saveAndReadback(array $storedSpec, int $userId, bool $includeBundle = true): array
    {
        $this->assertStoredSpec($storedSpec);
        $spec = $storedSpec['spec'];
        $rendered = $this->renderer->render($spec);
        $specRecordId = (int)$storedSpec['record_id'];
        $rendererVersion = (string)$rendered['renderer_version'];
        $identity = [
            'tenant_id' => (int)$storedSpec['tenant_id'],
            'hotel_ids' => [(int)$storedSpec['hotel_id']],
            'report_id' => (int)$storedSpec['report_id'],
            'audience' => (string)$storedSpec['audience'],
            'presentation_spec_id' => $specRecordId,
        ];

        return Db::transaction(function () use (
            $storedSpec,
            $rendered,
            $specRecordId,
            $rendererVersion,
            $userId,
            $includeBundle,
            $identity
        ): array {
            $created = false;
            $id = 0;
            try {
                $id = (int)Db::name(self::TABLE)->insertGetId([
                    'tenant_id' => (int)$storedSpec['tenant_id'],
                    'hotel_id' => (int)$storedSpec['hotel_id'],
                    'report_id' => (int)$storedSpec['report_id'],
                    'presentation_spec_id' => $specRecordId,
                    'audience' => (string)$storedSpec['audience'],
                    'format' => 'bundle_zip',
                    'renderer_version' => $rendererVersion,
                    'spec_fingerprint' => (string)$rendered['spec_fingerprint'],
                    'content_sha256' => (string)$rendered['content_sha256'],
                    'content_bytes' => (int)$rendered['content_bytes'],
                    'mime_type' => (string)$rendered['mime_type'],
                    'artifact_filename' => (string)$rendered['filename'],
                    'manifest_json' => (string)$rendered['manifest_json'],
                    'artifact_blob' => (string)$rendered['bundle'],
                    'render_status' => 'rendered_pending_readback',
                    'created_by' => max(0, $userId),
                ]);
                if ($id <= 0) {
                    throw new RuntimeException('AI daily report presentation artifact save failed');
                }
                $created = true;
            } catch (Throwable $error) {
                if (!$this->isDuplicateKeyConflict($error)) {
                    throw $error;
                }
            }

            $query = Db::name(self::TABLE)
                ->where('tenant_id', (int)$storedSpec['tenant_id'])
                ->where('hotel_id', (int)$storedSpec['hotel_id'])
                ->where('report_id', (int)$storedSpec['report_id'])
                ->where('audience', (string)$storedSpec['audience'])
                ->where('presentation_spec_id', $specRecordId)
                ->where('renderer_version', $rendererVersion);
            if ($id > 0) {
                $query->where('id', $id);
            }
            $row = $query->find();
            if (!is_array($row)) {
                throw new RuntimeException('AI daily report presentation artifact readback failed');
            }

            if ($created) {
                $this->normalizeStoredRow($row, $rendered, true, false, $identity, true);
                $updated = Db::name(self::TABLE)
                    ->where('id', (int)$row['id'])
                    ->where('render_status', 'rendered_pending_readback')
                    ->update(['render_status' => 'rendered_and_readback_verified']);
                if ($updated !== 1) {
                    throw new RuntimeException('AI daily report presentation artifact status finalize failed');
                }
            }

            $finalRow = Db::name(self::TABLE)
                ->where('id', (int)$row['id'])
                ->where('tenant_id', (int)$storedSpec['tenant_id'])
                ->where('hotel_id', (int)$storedSpec['hotel_id'])
                ->where('report_id', (int)$storedSpec['report_id'])
                ->find();
            if (!is_array($finalRow)) {
                throw new RuntimeException('AI daily report presentation artifact final readback failed');
            }

            return $this->normalizeStoredRow(
                $finalRow,
                $rendered,
                $created,
                $includeBundle,
                $identity
            );
        });
    }

    /**
     * Read the latest exact artifact in the already-authorized report scope.
     * This method never silently regenerates a missing artifact.
     *
     * @param array<int,int> $hotelIds
     * @return array<string,mixed>|null
     */
    public function readLatest(
        int $reportId,
        array $hotelIds,
        int $tenantId,
        string $audience = 'owner',
        bool $includeBundle = false
    ): ?array {
        $audience = strtolower(trim($audience));
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('presentation tenant scope is required');
        }
        if ($reportId <= 0 || $hotelIds === [] || !in_array($audience, ['owner', 'expert', 'training'], true)) {
            return null;
        }

        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('report_id', $reportId)
            ->whereIn('hotel_id', $hotelIds)
            ->where('audience', $audience)
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return null;
        }

        return $this->normalizeStoredRow($row, null, false, $includeBundle, [
            'tenant_id' => $tenantId,
            'hotel_ids' => $hotelIds,
            'report_id' => $reportId,
            'audience' => $audience,
        ]);
    }

    /**
     * Read one immutable artifact by its exact ID inside the already-authorized
     * report and hotel scope. Historical renderer versions remain readable.
     *
     * @param array<int,int> $hotelIds
     * @return array<string,mixed>|null
     */
    public function readExact(
        int $reportId,
        int $artifactId,
        array $hotelIds,
        int $tenantId,
        bool $includeBundle = false
    ): ?array {
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('presentation tenant scope is required');
        }
        if ($reportId <= 0 || $artifactId <= 0 || $hotelIds === []) {
            return null;
        }

        $row = Db::name(self::TABLE)
            ->where('id', $artifactId)
            ->where('tenant_id', $tenantId)
            ->where('report_id', $reportId)
            ->whereIn('hotel_id', $hotelIds)
            ->find();
        if (!is_array($row)) {
            return null;
        }

        return $this->normalizeStoredRow($row, null, false, $includeBundle, [
            'tenant_id' => $tenantId,
            'hotel_ids' => $hotelIds,
            'report_id' => $reportId,
        ]);
    }

    /** @param array<string,mixed> $storedSpec */
    private function assertStoredSpec(array $storedSpec): void
    {
        $spec = $storedSpec['spec'] ?? null;
        if (($storedSpec['readback_verified'] ?? false) !== true
            || !is_array($spec)
            || (int)($storedSpec['record_id'] ?? 0) <= 0
            || (int)($storedSpec['tenant_id'] ?? 0) <= 0
            || (int)($storedSpec['hotel_id'] ?? 0) <= 0
            || (int)($storedSpec['report_id'] ?? 0) <= 0
        ) {
            throw new RuntimeException('verified saved PresentationSpec is required before rendering');
        }
        $audience = (string)($spec['deck']['audience'] ?? '');
        if ($audience !== (string)($storedSpec['audience'] ?? '')) {
            throw new RuntimeException('presentation audience scope mismatch');
        }
        if (!hash_equals(
            (string)($storedSpec['spec_fingerprint'] ?? ''),
            (string)($spec['spec_fingerprint'] ?? '')
        )) {
            throw new RuntimeException('presentation spec wrapper fingerprint mismatch');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $expected
     * @return array<string,mixed>
     */
    private function normalizeStoredRow(
        array $row,
        ?array $expected,
        bool $created,
        bool $includeBundle,
        array $expectedIdentity = [],
        bool $allowPendingStatus = false
    ): array {
        $manifestRaw = $row['manifest_json'] ?? null;
        $manifest = is_array($manifestRaw) ? $manifestRaw : json_decode((string)$manifestRaw, true);
        $bundle = $row['artifact_blob'] ?? null;
        if (!is_array($manifest) || !is_string($bundle) || $bundle === '') {
            throw new RuntimeException('AI daily report presentation artifact stored payload is invalid');
        }

        $storedSha = strtolower(trim((string)($row['content_sha256'] ?? '')));
        $actualSha = hash('sha256', $bundle);
        $storedBytes = (int)($row['content_bytes'] ?? -1);
        $verification = $this->renderer->verifyBundle($bundle, $manifest);
        $expectedTenantId = (int)($expectedIdentity['tenant_id'] ?? 0);
        $expectedHotelIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($expectedIdentity['hotel_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        $expectedReportId = (int)($expectedIdentity['report_id'] ?? 0);
        $expectedAudience = trim((string)($expectedIdentity['audience'] ?? ''));
        $expectedSpecId = (int)($expectedIdentity['presentation_spec_id'] ?? 0);
        if ($expectedTenantId <= 0 || $expectedHotelIds === [] || $expectedReportId <= 0) {
            throw new RuntimeException('AI daily report presentation artifact expected identity is incomplete');
        }
        $rowIdentityVerified = (int)($row['tenant_id'] ?? 0) === $expectedTenantId
            && in_array((int)($row['hotel_id'] ?? 0), $expectedHotelIds, true)
            && (int)($row['report_id'] ?? 0) === $expectedReportId
            && ($expectedAudience === '' || (string)($row['audience'] ?? '') === $expectedAudience)
            && ($expectedSpecId <= 0 || (int)($row['presentation_spec_id'] ?? 0) === $expectedSpecId);

        $specRow = Db::name('ai_report_presentation_specs')
            ->where('id', (int)($row['presentation_spec_id'] ?? 0))
            ->find();
        $specPayload = null;
        if (is_array($specRow)) {
            $specRaw = $specRow['spec_json'] ?? null;
            $specPayload = is_array($specRaw) ? $specRaw : json_decode((string)$specRaw, true);
        }
        $specFingerprint = is_array($specPayload)
            ? strtolower(trim((string)($specPayload['spec_fingerprint'] ?? '')))
            : '';
        $specWithoutFingerprint = is_array($specPayload) ? $specPayload : [];
        unset($specWithoutFingerprint['spec_fingerprint']);
        $calculatedSpecFingerprint = is_array($specPayload)
            ? hash('sha256', $this->canonicalJson($specWithoutFingerprint))
            : '';
        $specValidation = is_array($specPayload)
            ? (new AiDailyReportPresentationSpecService())->validate($specPayload)
            : ['status' => 'fail'];
        $specSource = is_array($specPayload['source_report'] ?? null) ? $specPayload['source_report'] : [];
        $specAudience = (string)($specPayload['deck']['audience'] ?? '');
        $isCurrentAdapter = (string)($specPayload['adapter_version'] ?? '') === AiDailyReportPresentationSpecService::ADAPTER_VERSION;
        $embeddedSourceIdentityVerified = $specAudience === 'training'
            ? (($specSource['hotel_id'] ?? null) === null
                && ($specSource['report_id'] ?? null) === null
                && (!$isCurrentAdapter || ($specSource['tenant_id'] ?? null) === null))
            : ((int)($specSource['hotel_id'] ?? 0) === (int)($row['hotel_id'] ?? 0)
                && (int)($specSource['report_id'] ?? 0) === (int)($row['report_id'] ?? 0)
                && (!$isCurrentAdapter
                    || (int)($specSource['tenant_id'] ?? 0) === (int)($row['tenant_id'] ?? 0)));
        $specIdentityVerified = is_array($specRow)
            && (int)($specRow['id'] ?? 0) === (int)($row['presentation_spec_id'] ?? 0)
            && (int)($specRow['tenant_id'] ?? 0) === (int)($row['tenant_id'] ?? 0)
            && (int)($specRow['hotel_id'] ?? 0) === (int)($row['hotel_id'] ?? 0)
            && (int)($specRow['report_id'] ?? 0) === (int)($row['report_id'] ?? 0)
            && (string)($specRow['audience'] ?? '') === (string)($row['audience'] ?? '')
            && hash_equals(
                strtolower((string)($specRow['spec_fingerprint'] ?? '')),
                strtolower((string)($row['spec_fingerprint'] ?? ''))
            )
            && ($specValidation['status'] ?? '') === 'pass'
            && preg_match('/^[a-f0-9]{64}$/', $specFingerprint) === 1
            && hash_equals($specFingerprint, $calculatedSpecFingerprint)
            && hash_equals($specFingerprint, strtolower((string)($row['spec_fingerprint'] ?? '')))
            && $embeddedSourceIdentityVerified;
        $expectedRenderStatus = $allowPendingStatus
            ? 'rendered_pending_readback'
            : 'rendered_and_readback_verified';
        $verified = preg_match('/^[a-f0-9]{64}$/', $storedSha) === 1
            && hash_equals($storedSha, $actualSha)
            && $storedBytes === strlen($bundle)
            && ($verification['status'] ?? '') === 'pass'
            && $rowIdentityVerified
            && $specIdentityVerified
            && (string)($row['render_status'] ?? '') === $expectedRenderStatus;

        if ($expected !== null) {
            $verified = $verified
                && hash_equals($storedSha, (string)($expected['content_sha256'] ?? ''))
                && hash_equals(
                    hash('sha256', $this->canonicalJson($manifest)),
                    hash('sha256', (string)($expected['manifest_json'] ?? ''))
                )
                && hash_equals(
                    (string)($row['spec_fingerprint'] ?? ''),
                    (string)($expected['spec_fingerprint'] ?? '')
                )
                && (string)($row['artifact_filename'] ?? '') === (string)($expected['filename'] ?? '');
        }

        if (!$verified) {
            throw new RuntimeException('AI daily report presentation artifact exact readback verification failed');
        }

        $result = [
            'artifact_id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'report_id' => (int)($row['report_id'] ?? 0),
            'presentation_spec_id' => (int)($row['presentation_spec_id'] ?? 0),
            'audience' => (string)($row['audience'] ?? ''),
            'format' => (string)($row['format'] ?? ''),
            'renderer_version' => (string)($row['renderer_version'] ?? ''),
            'spec_fingerprint' => (string)($row['spec_fingerprint'] ?? ''),
            'content_sha256' => $storedSha,
            'content_bytes' => $storedBytes,
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'filename' => (string)($row['artifact_filename'] ?? ''),
            'render_status' => (string)($row['render_status'] ?? ''),
            'storage_status' => $created ? 'saved' : 'already_saved',
            'artifact_readback_verified' => true,
            'manifest' => $manifest,
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'authorization' => [
                'external_write_authorized' => false,
                'ota_write_authorized' => false,
                'pms_write_authorized' => false,
                'publish_authorized' => false,
            ],
        ];
        if ($includeBundle) {
            $result['bundle_base64'] = base64_encode($bundle);
        }
        return $result;
    }

    private function isDuplicateKeyConflict(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate entry')
                || str_contains($message, 'error 1062')
                || str_contains($message, 'errno: 1062')
                || str_contains($message, 'uk_ai_report_presentation_artifact_renderer')
            ) {
                return true;
            }
        }
        return false;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
