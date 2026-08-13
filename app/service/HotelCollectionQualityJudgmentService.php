<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Persists one fail-closed quality judgment over a public collection receipt.
 *
 * The production reader is HotelCollectionRunReceiptService::readGroup(), so
 * this service never reads raw OTA/PMS payloads or accepts caller-supplied
 * business facts. OTA row counts remain ota_channel facts; the PMS receipt is
 * reported separately and never upgrades this into a whole-hotel outcome.
 */
final class HotelCollectionQualityJudgmentService
{
    public const TABLE = 'hotel_collection_quality_judgments';
    public const SCHEMA_VERSION = 1;
    public const CONTRACT_VERSION = 'hotel_collection_quality_judgment.v1';

    /** @var array<int,string> */
    private const EXPECTED_OTA_SOURCES = ['ctrip', 'meituan'];

    /** @var array<int,string> */
    private const FORBIDDEN_PUBLIC_RECEIPT_KEYS = [
        'access_token',
        'authorization',
        'cookie',
        'cookies',
        'headers',
        'password',
        'profile_path',
        'raw_data',
        'receipt_json',
        'refresh_token',
        'secret',
        'token',
    ];

    /** @var callable(string,int,int,string):array<string,mixed> */
    private $publicReceiptReader;

    private int $freshnessMaxAgeDays;
    private DateTimeZone $timezone;

    /**
     * The callable is an isolated test seam. Production always uses the
     * authoritative, secret-free public receipt reader.
     *
     * @param null|callable(string,int,int,string):array<string,mixed> $publicReceiptReader
     */
    public function __construct(
        ?callable $publicReceiptReader = null,
        int $freshnessMaxAgeDays = 1
    ) {
        if ($freshnessMaxAgeDays < 0 || $freshnessMaxAgeDays > 31) {
            throw new InvalidArgumentException('hotel_collection_quality_freshness_window_invalid');
        }
        if ($publicReceiptReader === null) {
            $receiptService = new HotelCollectionRunReceiptService();
            $publicReceiptReader = static fn(
                string $dispatcherRunId,
                int $tenantId,
                int $hotelId,
                string $businessDate
            ): array => $receiptService->readGroup(
                $dispatcherRunId,
                $tenantId,
                $hotelId,
                $businessDate
            );
        }
        $this->publicReceiptReader = $publicReceiptReader;
        $this->freshnessMaxAgeDays = $freshnessMaxAgeDays;
        $this->timezone = new DateTimeZone('Asia/Shanghai');
    }

    /**
     * Re-read the exact public run receipt, judge it, persist it, and return
     * the independently verified database readback.
     *
     * @return array<string,mixed>
     */
    public function assessAndPersist(
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        ?DateTimeImmutable $assessedAt = null
    ): array {
        [$dispatcherRunId, $tenantId, $hotelId, $businessDate] = $this->scope(
            $dispatcherRunId,
            $tenantId,
            $hotelId,
            $businessDate
        );
        $this->assertTableReady();

        try {
            $receipt = call_user_func(
                $this->publicReceiptReader,
                $dispatcherRunId,
                $tenantId,
                $hotelId,
                $businessDate
            );
        } catch (Throwable $error) {
            throw new RuntimeException(
                'hotel_collection_quality_public_receipt_read_failed',
                0,
                $error
            );
        }
        if (!is_array($receipt)) {
            throw new RuntimeException('hotel_collection_quality_public_receipt_invalid');
        }
        $this->assertPublicReceiptContract(
            $receipt,
            $dispatcherRunId,
            $tenantId,
            $hotelId,
            $businessDate
        );

        $assessmentTime = ($assessedAt ?? new DateTimeImmutable('now', $this->timezone))
            ->setTimezone($this->timezone);
        $judgment = $this->judge($receipt, $assessmentTime);
        $judgmentId = $this->persist($judgment);
        $readback = $this->read(
            $tenantId,
            $hotelId,
            $businessDate,
            $dispatcherRunId
        );
        if (($readback['readback_verified'] ?? false) !== true
            || (int)($readback['persistence']['judgment_id'] ?? 0) !== $judgmentId
            || !hash_equals(
                (string)$judgment['judgment_digest'],
                (string)($readback['judgment_digest'] ?? '')
            )
        ) {
            throw new RuntimeException('hotel_collection_quality_readback_failed');
        }
        return $readback;
    }

    /**
     * Read one exact tenant/hotel/date/run judgment. A missing or damaged row
     * is returned as fail-closed and can never expose an available conclusion.
     *
     * @return array<string,mixed>
     */
    public function read(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $dispatcherRunId
    ): array {
        [$dispatcherRunId, $tenantId, $hotelId, $businessDate] = $this->scope(
            $dispatcherRunId,
            $tenantId,
            $hotelId,
            $businessDate
        );
        $this->assertTableReady();
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->where('dispatcher_run_id', $dispatcherRunId)
            ->find();
        if (!is_array($row)) {
            return $this->missingReadback(
                $tenantId,
                $hotelId,
                $businessDate,
                $dispatcherRunId
            );
        }

        $payload = $this->decodeJson((string)($row['judgment_json'] ?? ''));
        if (!$this->storedReadbackMatches($row, $payload)) {
            return $this->conflictedReadback($row);
        }
        $payload['readback_verified'] = true;
        $payload['persistence'] = [
            'table' => self::TABLE,
            'judgment_id' => (int)$row['id'],
            'saved' => true,
            'readback_row_count' => 1,
            'readback_verified' => true,
        ];
        return $payload;
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function judge(array $receipt, DateTimeImmutable $assessedAt): array
    {
        $tenantId = (int)$receipt['tenant_id'];
        $hotelId = (int)$receipt['system_hotel_id'];
        $businessDate = (string)$receipt['business_date'];
        $dispatcherRunId = (string)$receipt['dispatcher_run_id'];
        $runReceiptId = max(0, (int)($receipt['id'] ?? 0));
        $runStatus = $this->code((string)($receipt['status'] ?? 'missing'));
        $runExists = $runReceiptId > 0 && $runStatus !== 'missing';

        $missingItems = [];
        $conflictItems = [];
        if (!$runExists) {
            $missingItems[] = 'collection_run_missing';
        } elseif ($runStatus !== 'succeeded') {
            $missingItems[] = 'collection_run_not_succeeded:' . ($runStatus ?: 'unknown');
        }

        $ledgerVerified = ($receipt['ledger_structure_verified'] ?? false) === true;
        $runReadbackVerified = ($receipt['readback_verified'] ?? false) === true;
        if ($runExists && !$ledgerVerified) {
            if ($runStatus === 'succeeded') {
                $conflictItems[] = 'collection_run_ledger_unverified';
            } else {
                $missingItems[] = 'collection_run_ledger_unverified';
            }
        }
        if ($runExists && !$runReadbackVerified) {
            if ($runStatus === 'succeeded') {
                $conflictItems[] = 'collection_run_readback_unverified';
            } else {
                $missingItems[] = 'collection_run_readback_unverified';
            }
        }

        $anchorHash = $this->digest((string)($receipt['collection_anchor_hash'] ?? ''));
        $trustDigest = $this->digest((string)($receipt['trust_receipt_digest'] ?? ''));
        if ($anchorHash === '') {
            $missingItems[] = 'collection_anchor_digest_missing';
        }
        if ($trustDigest === '') {
            $missingItems[] = 'collection_trust_digest_missing';
        }

        $sourceAssessment = $this->assessSources(
            is_array($receipt['source_receipts'] ?? null)
                ? $receipt['source_receipts']
                : []
        );
        $missingItems = array_merge($missingItems, $sourceAssessment['missing_items']);
        $conflictItems = array_merge($conflictItems, $sourceAssessment['conflict_items']);

        $pmsAssessment = $this->assessPms(
            is_array($receipt['pms_receipt'] ?? null)
                ? $receipt['pms_receipt']
                : []
        );
        $missingItems = array_merge($missingItems, $pmsAssessment['missing_items']);
        $conflictItems = array_merge($conflictItems, $pmsAssessment['conflict_items']);

        $freshness = $this->freshness(
            $businessDate,
            (string)($receipt['finished_at'] ?? ''),
            $sourceAssessment['source_scope'],
            $assessedAt
        );
        $missingItems = array_merge($missingItems, $freshness['missing_items']);
        $conflictItems = array_merge($conflictItems, $freshness['conflict_items']);

        $missingItems = $this->uniqueCodes($missingItems);
        $conflictItems = $this->uniqueCodes($conflictItems);
        $sourceScope = $sourceAssessment['source_scope'];
        $sourceScopeHash = $this->hashValue($sourceScope);

        $evidenceProjection = [
            'upstream_schema_version' => (int)($receipt['schema_version'] ?? 0),
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'dispatcher_run_id' => $dispatcherRunId,
            'collection_run_receipt_id' => $runReceiptId > 0 ? $runReceiptId : null,
            'run_status' => $runStatus !== '' ? $runStatus : 'missing',
            'ledger_structure_verified' => $ledgerVerified,
            'run_readback_verified' => $runReadbackVerified,
            'collection_anchor_hash' => $anchorHash !== '' ? $anchorHash : null,
            'trust_receipt_digest' => $trustDigest !== '' ? $trustDigest : null,
            'run_finished_at' => $this->safeTimestamp((string)($receipt['finished_at'] ?? '')),
            'source_scope' => $sourceScope,
            'pms_scope' => $pmsAssessment['pms_scope'],
        ];
        $evidenceDigest = $this->hashValue($evidenceProjection);

        $conclusionStatus = 'available';
        if ($conflictItems !== []) {
            $conclusionStatus = 'conflicted';
        } elseif (!$runExists && $sourceScope === []) {
            $conclusionStatus = 'missing';
        } elseif ($missingItems !== []) {
            $conclusionStatus = 'partial';
        } elseif (($freshness['status'] ?? '') !== 'fresh') {
            $conclusionStatus = 'stale';
        }
        $claimAllowed = $conclusionStatus === 'available';
        $reasonCodes = match ($conclusionStatus) {
            'available' => ['exact_public_collection_receipts_readback_verified'],
            'stale' => ['business_date_stale'],
            'missing' => $missingItems !== [] ? $missingItems : ['collection_evidence_missing'],
            default => $this->uniqueCodes(array_merge($conflictItems, $missingItems)),
        };

        $judgment = [
            'schema_version' => self::SCHEMA_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'dispatcher_run_id' => $dispatcherRunId,
            'collection_run_receipt_id' => $runReceiptId > 0 ? $runReceiptId : null,
            'evidence_scope' => [
                'judgment_scope' => 'exact_collection_evidence',
                'ota_metric_scope' => 'ota_channel',
                'pms_metric_scope' => 'whole_hotel_accommodation',
                'whole_hotel_conclusion_allowed' => false,
                'business_outcome_claimed' => false,
            ],
            'collection_run' => [
                'status' => $runStatus !== '' ? $runStatus : 'missing',
                'ledger_structure_verified' => $ledgerVerified,
                'readback_verified' => $runReadbackVerified,
                'finished_at' => $this->safeTimestamp((string)($receipt['finished_at'] ?? '')),
            ],
            'expected_ota_sources' => self::EXPECTED_OTA_SOURCES,
            'source_scope' => $sourceScope,
            'pms_scope' => $pmsAssessment['pms_scope'],
            'counts' => [
                'source_receipt_count' => count($sourceScope),
                'saved_row_count' => (int)$sourceAssessment['saved_row_count'],
                'readback_row_count' => (int)$sourceAssessment['readback_row_count'],
                'missing_count' => count($missingItems),
                'conflict_count' => count($conflictItems),
            ],
            'missing_items' => $missingItems,
            'conflict_items' => $conflictItems,
            'freshness' => [
                'status' => (string)$freshness['status'],
                'age_days' => $freshness['age_days'],
                'max_age_days' => $this->freshnessMaxAgeDays,
                'basis' => 'business_date_age',
                'collection_finished_at' => $freshness['collection_finished_at'],
                'assessed_at' => $assessedAt->format('Y-m-d H:i:s'),
            ],
            'conclusion' => [
                'status' => $conclusionStatus,
                'claim_allowed' => $claimAllowed,
                'reason_codes' => $reasonCodes,
                'whole_hotel_conclusion_allowed' => false,
                'business_outcome_claimed' => false,
            ],
            'source_scope_hash' => $sourceScopeHash,
            'evidence_digest' => $evidenceDigest,
            'sensitive_values_exposed' => false,
        ];
        $judgment['judgment_digest'] = $this->hashValue($judgment);
        return $judgment;
    }

    /**
     * @param array<int,mixed> $receipts
     * @return array<string,mixed>
     */
    private function assessSources(array $receipts): array
    {
        $byPlatform = [];
        $missingItems = [];
        $conflictItems = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) {
                $conflictItems[] = 'source_receipt_invalid';
                continue;
            }
            $platform = $this->code((string)($receipt['platform'] ?? ''));
            if (!in_array($platform, self::EXPECTED_OTA_SOURCES, true)) {
                $conflictItems[] = 'source_platform_unexpected:' . ($platform ?: 'missing');
                continue;
            }
            if (isset($byPlatform[$platform])) {
                $conflictItems[] = 'source_receipt_duplicated:' . $platform;
                continue;
            }
            $byPlatform[$platform] = $this->publicSourceScope($receipt, $platform);
        }

        $sourceScope = [];
        $savedRowCount = 0;
        $readbackRowCount = 0;
        foreach (self::EXPECTED_OTA_SOURCES as $platform) {
            if (!isset($byPlatform[$platform])) {
                $missingItems[] = 'source_receipt_missing:' . $platform;
                continue;
            }
            $source = $byPlatform[$platform];
            $sourceScope[] = $source;
            $savedRowCount += (int)$source['saved_row_count'];
            $readbackRowCount += (int)$source['readback_row_count'];

            if ((string)$source['status'] !== 'success') {
                $missingItems[] = 'source_not_success:' . $platform;
            }
            if ($source['data_source_id'] === null) {
                $missingItems[] = 'source_identity_missing:' . $platform;
            }
            if ((int)$source['saved_row_count'] <= 0) {
                $missingItems[] = 'source_saved_rows_missing:' . $platform;
            }
            if ((int)$source['saved_row_count'] !== (int)$source['readback_row_count']) {
                $conflictItems[] = 'source_saved_readback_count_conflict:' . $platform;
            }
            if (($source['readback_verified'] ?? false) !== true) {
                $missingItems[] = 'source_readback_unverified:' . $platform;
            }
            if ($source['finished_at'] === null) {
                $missingItems[] = 'source_finished_at_missing:' . $platform;
            }
            if ((string)$source['status'] === 'success'
                && ((string)$source['failure_stage'] !== '' || (string)$source['failure_code'] !== '')
            ) {
                $conflictItems[] = 'source_success_failure_conflict:' . $platform;
            }
        }

        return [
            'source_scope' => $sourceScope,
            'saved_row_count' => $savedRowCount,
            'readback_row_count' => $readbackRowCount,
            'missing_items' => $this->uniqueCodes($missingItems),
            'conflict_items' => $this->uniqueCodes($conflictItems),
        ];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function publicSourceScope(array $receipt, string $platform): array
    {
        $sourceId = max(0, (int)($receipt['data_source_id'] ?? 0));
        return [
            'platform' => $platform,
            'metric_scope' => 'ota_channel',
            'source_receipt_id' => max(0, (int)($receipt['id'] ?? 0)) ?: null,
            'data_source_id' => $sourceId > 0 ? $sourceId : null,
            'ingestion_method' => $this->code((string)($receipt['ingestion_method'] ?? '')),
            'status' => $this->code((string)($receipt['status'] ?? '')),
            'saved_row_count' => max(0, (int)($receipt['saved_row_count'] ?? 0)),
            'readback_row_count' => max(0, (int)($receipt['readback_row_count'] ?? 0)),
            'readback_verified' => ($receipt['readback_verified'] ?? false) === true,
            'failure_stage' => $this->code((string)($receipt['failure_stage'] ?? '')),
            'failure_code' => $this->code((string)($receipt['failure_code'] ?? '')),
            'finished_at' => $this->safeTimestamp((string)($receipt['finished_at'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function assessPms(array $receipt): array
    {
        $provider = $this->code((string)($receipt['provider'] ?? ''));
        $status = $this->code((string)($receipt['status'] ?? 'missing'));
        $captureId = trim((string)($receipt['capture_id'] ?? ''));
        $readbackVerified = ($receipt['readback_verified'] ?? false) === true;
        $missingItems = [];
        $conflictItems = [];

        $verified = $provider === 'dingdandao_pms'
            && $status === 'verified'
            && $captureId !== ''
            && $readbackVerified;
        if (!$verified) {
            if ($status === 'conflict'
                || ($status === 'verified' && ($provider !== 'dingdandao_pms'
                    || $captureId === '' || !$readbackVerified))
            ) {
                $conflictItems[] = 'pms_receipt_conflicted';
            } else {
                $missingItems[] = 'pms_receipt_unverified:' . ($status ?: 'missing');
            }
        }

        return [
            'pms_scope' => [
                'provider' => $verified ? $provider : ($provider !== '' ? $provider : null),
                'metric_scope' => 'whole_hotel_accommodation',
                'status' => $status !== '' ? $status : 'missing',
                'capture_id' => $verified ? $captureId : null,
                'readback_verified' => $verified,
                'used_as_business_outcome' => false,
            ],
            'missing_items' => $missingItems,
            'conflict_items' => $conflictItems,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sourceScope
     * @return array<string,mixed>
     */
    private function freshness(
        string $businessDate,
        string $runFinishedAt,
        array $sourceScope,
        DateTimeImmutable $assessedAt
    ): array {
        $missingItems = [];
        $conflictItems = [];
        $businessDay = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $businessDate,
            $this->timezone
        );
        $assessmentDay = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $assessedAt->format('Y-m-d'),
            $this->timezone
        );
        $ageDays = $businessDay instanceof DateTimeImmutable
            && $assessmentDay instanceof DateTimeImmutable
            ? (int)$businessDay->diff($assessmentDay)->format('%r%a')
            : null;
        if ($ageDays === null) {
            $conflictItems[] = 'business_date_invalid';
        } elseif ($ageDays < 0) {
            $conflictItems[] = 'business_date_in_future';
        }

        $finishedAt = $this->timestamp($runFinishedAt);
        if (!$finishedAt instanceof DateTimeImmutable) {
            $missingItems[] = 'collection_finished_at_missing';
        } elseif ($finishedAt->getTimestamp() > $assessedAt->getTimestamp() + 300) {
            $conflictItems[] = 'collection_finished_at_in_future';
        }
        foreach ($sourceScope as $source) {
            $sourceFinishedAt = $this->timestamp((string)($source['finished_at'] ?? ''));
            if ($sourceFinishedAt instanceof DateTimeImmutable
                && $sourceFinishedAt->getTimestamp() > $assessedAt->getTimestamp() + 300
            ) {
                $conflictItems[] = 'source_finished_at_in_future:'
                    . (string)($source['platform'] ?? 'unknown');
            }
        }

        $status = 'unknown';
        if ($ageDays !== null && $ageDays < 0) {
            $status = 'future';
        } elseif ($ageDays !== null && $finishedAt instanceof DateTimeImmutable) {
            $status = $ageDays <= $this->freshnessMaxAgeDays ? 'fresh' : 'stale';
        }
        return [
            'status' => $status,
            'age_days' => $ageDays,
            'collection_finished_at' => $finishedAt instanceof DateTimeImmutable
                ? $finishedAt->setTimezone($this->timezone)->format('Y-m-d H:i:s')
                : null,
            'missing_items' => $this->uniqueCodes($missingItems),
            'conflict_items' => $this->uniqueCodes($conflictItems),
        ];
    }

    /** @param array<string,mixed> $judgment */
    private function persist(array $judgment): int
    {
        $now = (string)$judgment['freshness']['assessed_at'];
        $values = [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => (int)$judgment['tenant_id'],
            'system_hotel_id' => (int)$judgment['system_hotel_id'],
            'business_date' => (string)$judgment['business_date'],
            'dispatcher_run_id' => (string)$judgment['dispatcher_run_id'],
            'collection_run_receipt_id' => $judgment['collection_run_receipt_id'],
            'source_scope_hash' => (string)$judgment['source_scope_hash'],
            'saved_row_count' => (int)$judgment['counts']['saved_row_count'],
            'readback_row_count' => (int)$judgment['counts']['readback_row_count'],
            'missing_count' => (int)$judgment['counts']['missing_count'],
            'conflict_count' => (int)$judgment['counts']['conflict_count'],
            'freshness_status' => (string)$judgment['freshness']['status'],
            'conclusion_status' => (string)$judgment['conclusion']['status'],
            'evidence_digest' => (string)$judgment['evidence_digest'],
            'judgment_digest' => (string)$judgment['judgment_digest'],
            'judgment_json' => $this->json($judgment),
            'assessed_at' => $now,
            'update_time' => $now,
        ];

        return Db::transaction(function () use ($values, $now): int {
            $existing = Db::name(self::TABLE)
                ->where('dispatcher_run_id', (string)$values['dispatcher_run_id'])
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                foreach (['tenant_id', 'system_hotel_id', 'business_date', 'dispatcher_run_id'] as $field) {
                    if ((string)($existing[$field] ?? '') !== (string)$values[$field]) {
                        throw new RuntimeException('hotel_collection_quality_scope_conflict');
                    }
                }
                Db::name(self::TABLE)
                    ->where('id', (int)$existing['id'])
                    ->update($values);
                return (int)$existing['id'];
            }
            $values['create_time'] = $now;
            $id = (int)Db::name(self::TABLE)->insertGetId($values);
            if ($id <= 0) {
                throw new RuntimeException('hotel_collection_quality_save_failed');
            }
            return $id;
        });
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $payload */
    private function storedReadbackMatches(array $row, array $payload): bool
    {
        if ($payload === []
            || (int)($row['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || (int)($payload['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || (string)($payload['contract_version'] ?? '') !== self::CONTRACT_VERSION
            || ($payload['sensitive_values_exposed'] ?? null) !== false
        ) {
            return false;
        }
        foreach ([
            'tenant_id' => 'tenant_id',
            'system_hotel_id' => 'system_hotel_id',
            'business_date' => 'business_date',
            'dispatcher_run_id' => 'dispatcher_run_id',
            'collection_run_receipt_id' => 'collection_run_receipt_id',
            'source_scope_hash' => 'source_scope_hash',
            'evidence_digest' => 'evidence_digest',
            'judgment_digest' => 'judgment_digest',
        ] as $rowField => $payloadField) {
            if ((string)($row[$rowField] ?? '') !== (string)($payload[$payloadField] ?? '')) {
                return false;
            }
        }
        foreach ([
            'saved_row_count' => 'saved_row_count',
            'readback_row_count' => 'readback_row_count',
            'missing_count' => 'missing_count',
            'conflict_count' => 'conflict_count',
        ] as $rowField => $countField) {
            if ((int)($row[$rowField] ?? -1) !== (int)($payload['counts'][$countField] ?? -2)) {
                return false;
            }
        }
        if ((string)($row['freshness_status'] ?? '') !== (string)($payload['freshness']['status'] ?? '')
            || (string)($row['conclusion_status'] ?? '') !== (string)($payload['conclusion']['status'] ?? '')
            || (string)($row['assessed_at'] ?? '') !== (string)($payload['freshness']['assessed_at'] ?? '')
            || $this->digest((string)($row['source_scope_hash'] ?? '')) === ''
            || $this->digest((string)($row['evidence_digest'] ?? '')) === ''
            || $this->digest((string)($row['judgment_digest'] ?? '')) === ''
        ) {
            return false;
        }

        $storedDigest = (string)$payload['judgment_digest'];
        unset($payload['judgment_digest']);
        return hash_equals($storedDigest, $this->hashValue($payload));
    }

    /** @param array<string,mixed> $receipt */
    private function assertPublicReceiptContract(
        array $receipt,
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): void {
        if ((int)($receipt['schema_version'] ?? 0) !== HotelCollectionRunReceiptService::SCHEMA_VERSION
            || (string)($receipt['dispatcher_run_id'] ?? '') !== $dispatcherRunId
            || (int)($receipt['tenant_id'] ?? 0) !== $tenantId
            || (int)($receipt['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($receipt['business_date'] ?? '') !== $businessDate
            || ($receipt['automatic_device_substitution'] ?? null) !== false
            || ($receipt['sensitive_values_exposed'] ?? null) !== false
            || !is_array($receipt['source_receipts'] ?? null)
            || $this->containsForbiddenPublicReceiptKey($receipt)
        ) {
            throw new RuntimeException('hotel_collection_quality_public_receipt_contract_invalid');
        }
        foreach ($receipt['source_receipts'] as $sourceReceipt) {
            if (!is_array($sourceReceipt)
                || ($sourceReceipt['automatic_device_substitution'] ?? null) !== false
                || ($sourceReceipt['sensitive_values_exposed'] ?? null) !== false
            ) {
                throw new RuntimeException('hotel_collection_quality_public_receipt_contract_invalid');
            }
        }
    }

    private function containsForbiddenPublicReceiptKey(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key)
                && in_array(strtolower(trim($key)), self::FORBIDDEN_PUBLIC_RECEIPT_KEYS, true)
            ) {
                return true;
            }
            if ($this->containsForbiddenPublicReceiptKey($item)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:string,1:int,2:int,3:string} */
    private function scope(
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $dispatcherRunId = $this->uuid($dispatcherRunId);
        $businessDate = $this->date($businessDate);
        if ($dispatcherRunId === '' || $tenantId <= 0 || $hotelId <= 0 || $businessDate === '') {
            throw new InvalidArgumentException('hotel_collection_quality_scope_invalid');
        }
        return [$dispatcherRunId, $tenantId, $hotelId, $businessDate];
    }

    /** @return array<string,mixed> */
    private function missingReadback(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $dispatcherRunId
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'dispatcher_run_id' => $dispatcherRunId,
            'source_scope' => [],
            'counts' => [
                'source_receipt_count' => 0,
                'saved_row_count' => 0,
                'readback_row_count' => 0,
                'missing_count' => 1,
                'conflict_count' => 0,
            ],
            'missing_items' => ['quality_judgment_missing'],
            'conflict_items' => [],
            'freshness' => ['status' => 'unknown'],
            'conclusion' => [
                'status' => 'missing',
                'claim_allowed' => false,
                'whole_hotel_conclusion_allowed' => false,
                'business_outcome_claimed' => false,
                'reason_codes' => ['quality_judgment_missing'],
            ],
            'judgment_digest' => null,
            'readback_verified' => false,
            'persistence' => [
                'table' => self::TABLE,
                'judgment_id' => null,
                'saved' => false,
                'readback_row_count' => 0,
                'readback_verified' => false,
            ],
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function conflictedReadback(array $row): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'system_hotel_id' => (int)($row['system_hotel_id'] ?? 0),
            'business_date' => (string)($row['business_date'] ?? ''),
            'dispatcher_run_id' => (string)($row['dispatcher_run_id'] ?? ''),
            'source_scope' => [],
            'counts' => [
                'source_receipt_count' => 0,
                'saved_row_count' => max(0, (int)($row['saved_row_count'] ?? 0)),
                'readback_row_count' => max(0, (int)($row['readback_row_count'] ?? 0)),
                'missing_count' => max(0, (int)($row['missing_count'] ?? 0)),
                'conflict_count' => max(1, (int)($row['conflict_count'] ?? 0)),
            ],
            'missing_items' => [],
            'conflict_items' => ['quality_judgment_readback_mismatch'],
            'freshness' => ['status' => 'unknown'],
            'conclusion' => [
                'status' => 'conflicted',
                'claim_allowed' => false,
                'whole_hotel_conclusion_allowed' => false,
                'business_outcome_claimed' => false,
                'reason_codes' => ['quality_judgment_readback_mismatch'],
            ],
            'judgment_digest' => $this->digest((string)($row['judgment_digest'] ?? '')) ?: null,
            'readback_verified' => false,
            'persistence' => [
                'table' => self::TABLE,
                'judgment_id' => max(0, (int)($row['id'] ?? 0)) ?: null,
                'saved' => true,
                'readback_row_count' => 1,
                'readback_verified' => false,
            ],
            'sensitive_values_exposed' => false,
        ];
    }

    private function assertTableReady(): void
    {
        try {
            $fields = Db::getTableInfo(self::TABLE, 'fields');
        } catch (Throwable $error) {
            throw new RuntimeException('hotel_collection_quality_table_missing', 0, $error);
        }
        $required = [
            'id',
            'tenant_id',
            'system_hotel_id',
            'business_date',
            'dispatcher_run_id',
            'source_scope_hash',
            'saved_row_count',
            'readback_row_count',
            'missing_count',
            'conflict_count',
            'freshness_status',
            'conclusion_status',
            'evidence_digest',
            'judgment_digest',
            'judgment_json',
            'assessed_at',
        ];
        if (!is_array($fields) || array_diff($required, array_map('strval', $fields)) !== []) {
            throw new RuntimeException('hotel_collection_quality_table_missing');
        }
    }

    /** @param array<int,mixed> $codes @return array<int,string> */
    private function uniqueCodes(array $codes): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn(mixed $code): string => $this->code((string)$code),
            $codes
        ))));
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function json(array $value): string
    {
        return (string)json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    private function hashValue(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $keys = array_keys($value);
        $isList = $value === [] || $keys === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function safeTimestamp(string $value): ?string
    {
        $timestamp = $this->timestamp($value);
        return $timestamp instanceof DateTimeImmutable
            ? $timestamp->setTimezone($this->timezone)->format('Y-m-d H:i:s')
            : null;
    }

    private function timestamp(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, $this->timezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $value
        ) === 1 ? $value : '';
    }

    private function date(string $value): string
    {
        $value = substr(trim($value), 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $value
            : '';
    }

    private function code(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:-]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 120), '_');
    }

    private function digest(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
    }
}
