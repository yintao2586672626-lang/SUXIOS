<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;
use Throwable;

/** Owns the competition-bundle write/readback contract outside the report hotspot. */
final class AiDailyCompetitionBundlePersistenceService
{
    public const CONTRACT_VERSION = 'ai_daily_report.competition_bundle_persistence.v1';
    private const REPORT_TABLE = 'ai_daily_reports';

    /** @param array<string,mixed> $bundle @return array<string,mixed> */
    public static function buildContract(array $bundle): array
    {
        $bundleId = trim((string)($bundle['bundle_id'] ?? ''));
        $sourceFingerprint = trim((string)($bundle['source_fingerprint'] ?? ''));
        $contentDigest = trim((string)($bundle['content_digest'] ?? ''));
        $renderContract = is_array($bundle['report_document']['render_contract'] ?? null)
            ? $bundle['report_document']['render_contract']
            : [];
        $recalculatedDigest = OtaCompetitionAnalysisBundleService::contentDigest($bundle);

        if ($bundleId === ''
            || $sourceFingerprint === ''
            || $contentDigest === ''
            || !hash_equals($contentDigest, $recalculatedDigest)
            || !hash_equals($bundleId, trim((string)($renderContract['bundle_id'] ?? '')))
            || !hash_equals($sourceFingerprint, trim((string)($renderContract['source_fingerprint'] ?? '')))
            || !hash_equals($contentDigest, trim((string)($renderContract['content_digest'] ?? '')))
            || ($renderContract['exact_readback_required'] ?? false) !== true
        ) {
            throw new RuntimeException('competition_bundle_invalid_before_persistence');
        }

        return [
            'schema_version' => self::CONTRACT_VERSION,
            'exact_readback_required' => true,
            'bundle_id' => $bundleId,
            'source_fingerprint' => $sourceFingerprint,
            'content_digest' => $contentDigest,
        ];
    }

    /**
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed> $payload
     * @return array{id:int,receipt:array<string,mixed>}
     */
    public static function persistReport(
        ?array $existing,
        array $payload,
        string $now,
        int $hotelId,
        string $reportDate
    ): array {
        return Db::transaction(static function () use ($existing, $payload, $now, $hotelId, $reportDate): array {
            if (is_array($existing)) {
                $id = (int)$existing['id'];
                Db::name(self::REPORT_TABLE)
                    ->where('id', $id)
                    ->where('hotel_id', $hotelId)
                    ->where('report_date', $reportDate)
                    ->whereNull('deleted_at')
                    ->update($payload);
            } else {
                $insertPayload = $payload;
                $insertPayload['created_at'] = $now;
                $id = (int)Db::name(self::REPORT_TABLE)->insertGetId($insertPayload);
            }

            $row = Db::name(self::REPORT_TABLE)
                ->where('id', $id)
                ->where('hotel_id', $hotelId)
                ->where('report_date', $reportDate)
                ->whereNull('deleted_at')
                ->find();
            if (!is_array($row)) {
                throw new RuntimeException('competition_report_scope_mismatch');
            }
            $receipt = self::receipt(self::decode((string)($row['snapshot_json'] ?? '')));
            if (($receipt['exact_readback_verified'] ?? false) !== true) {
                throw new RuntimeException((string)($receipt['status'] ?? 'competition_bundle_readback_failed'));
            }
            return ['id' => $id, 'receipt' => $receipt];
        });
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $row @return array<string,mixed> */
    public static function receiptForReport(array $snapshot, array $row): array
    {
        return array_merge(self::receipt($snapshot), [
            'report_id' => (int)($row['id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'report_date' => (string)($row['report_date'] ?? ''),
        ]);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public static function receipt(array $snapshot): array
    {
        $bundle = is_array($snapshot['competition_circle_bundle'] ?? null)
            ? $snapshot['competition_circle_bundle']
            : [];
        $contract = is_array($snapshot['competition_circle_bundle_persistence'] ?? null)
            ? $snapshot['competition_circle_bundle_persistence']
            : [];
        $actualBundleId = trim((string)($bundle['bundle_id'] ?? ''));
        $actualSourceFingerprint = trim((string)($bundle['source_fingerprint'] ?? ''));
        $actualStoredDigest = trim((string)($bundle['content_digest'] ?? ''));
        $renderContract = is_array($bundle['report_document']['render_contract'] ?? null)
            ? $bundle['report_document']['render_contract']
            : [];
        $base = [
            'schema_version' => self::CONTRACT_VERSION,
            'exact_readback_required' => true,
            'exact_readback_verified' => false,
            'bundle_id' => $actualBundleId,
            'source_fingerprint' => $actualSourceFingerprint,
            'content_digest' => $actualStoredDigest,
        ];
        if ($bundle === []) {
            return array_merge($base, [
                'status' => 'competition_bundle_missing',
                'failure_reasons' => ['competition_bundle_missing'],
            ]);
        }
        if ($contract === []) {
            return array_merge($base, [
                'status' => 'legacy_unverified',
                'failure_reasons' => ['competition_content_digest_missing'],
            ]);
        }

        $expectedBundleId = trim((string)($contract['bundle_id'] ?? ''));
        $expectedSourceFingerprint = trim((string)($contract['source_fingerprint'] ?? ''));
        $expectedDigest = trim((string)($contract['content_digest'] ?? ''));
        $recalculatedDigest = OtaCompetitionAnalysisBundleService::contentDigest($bundle);
        $failureReasons = [];
        if ($expectedBundleId === '' || $actualBundleId === ''
            || !hash_equals($expectedBundleId, $actualBundleId)) {
            $failureReasons[] = 'competition_bundle_id_mismatch';
        }
        if ($expectedSourceFingerprint === '' || $actualSourceFingerprint === ''
            || !hash_equals($expectedSourceFingerprint, $actualSourceFingerprint)) {
            $failureReasons[] = 'competition_source_fingerprint_mismatch';
        }
        if ($expectedDigest === '' || $actualStoredDigest === '') {
            $failureReasons[] = 'competition_content_digest_missing';
        } elseif (!hash_equals($expectedDigest, $actualStoredDigest)
            || !hash_equals($actualStoredDigest, $recalculatedDigest)) {
            $failureReasons[] = 'competition_content_digest_mismatch';
        }
        if ((string)($contract['schema_version'] ?? '') !== self::CONTRACT_VERSION
            || ($contract['exact_readback_required'] ?? false) !== true
            || ($renderContract['exact_readback_required'] ?? false) !== true
            || !hash_equals($actualBundleId, trim((string)($renderContract['bundle_id'] ?? '')))
            || !hash_equals($actualSourceFingerprint, trim((string)($renderContract['source_fingerprint'] ?? '')))
            || !hash_equals($actualStoredDigest, trim((string)($renderContract['content_digest'] ?? '')))
        ) {
            $failureReasons[] = 'competition_render_contract_identity_mismatch';
        }

        $failureReasons = array_values(array_unique($failureReasons));
        if ($failureReasons !== []) {
            return array_merge($base, [
                'status' => $failureReasons[0],
                'failure_reasons' => $failureReasons,
            ]);
        }
        return array_merge($base, [
            'status' => 'exact_readback_verified',
            'exact_readback_verified' => true,
            'failure_reasons' => [],
        ]);
    }

    /** @return array<string,mixed> */
    private static function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('competition_bundle_readback_json_invalid', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('competition_bundle_readback_json_invalid');
        }
        return $value;
    }
}
