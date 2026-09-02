<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Persists one immutable, deterministic operating broadcast from the current
 * strict OTA field readback. It does not call an LLM, send messages, or write
 * OTA/PMS data. Broadcast readiness and competition/revenue analysis readiness
 * deliberately remain separate states.
 */
final class AiDailyReportBroadcastSnapshotService
{
    private const TABLE = 'ai_daily_report_broadcast_snapshots';

    public const TEMPLATE_VERSION = 'trusted_operations_broadcast.v1';

    private const PLATFORMS = ['ctrip', 'meituan'];
    private const FACT_STATUSES = ['strict_readback', 'verified_calculation'];
    private const FAILURE_STATUSES = [
        'source_missing', 'readback_failed',
        'collection_failed', 'login_expired', 'date_mismatch',
    ];
    private const FACT_FIELDS = [
        'exposure',
        'visits',
        'conversion',
        'order_count',
        'room_nights',
        'revenue',
        'adr',
    ];
    private const FIELD_LABELS = [
        'exposure' => '曝光人数',
        'visits' => '访客',
        'conversion' => '曝光到访率',
        'order_count' => '订单量',
        'room_nights' => '间夜',
        'revenue' => '收入',
        'adr' => 'ADR',
    ];
    private const FIELD_UNITS = [
        'exposure' => 'users',
        'visits' => 'users',
        'conversion' => 'percent',
        'order_count' => 'orders',
        'room_nights' => 'room_nights',
        'revenue' => 'CNY',
        'adr' => 'CNY',
    ];

    /** @var Closure(int,string):array<string,mixed> */
    private Closure $closureReader;

    /** @var Closure(int):array<string,mixed>|null */
    private Closure $hotelReader;

    /** @var Closure():DateTimeImmutable */
    private Closure $clock;

    public function __construct(
        ?callable $closureReader = null,
        ?callable $hotelReader = null,
        ?callable $clock = null
    ) {
        $this->closureReader = $closureReader !== null
            ? Closure::fromCallable($closureReader)
            : static fn(int $hotelId, string $businessDate): array =>
                (new AiDailyReportBroadcastFactService())->build($hotelId, $businessDate);
        $this->hotelReader = $hotelReader !== null
            ? Closure::fromCallable($hotelReader)
            : static fn(int $hotelId): ?array => Db::name('hotels')
                ->where('id', $hotelId)
                ->field('id,tenant_id,name')
                ->find();
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone(date_default_timezone_get() ?: 'Asia/Shanghai')
            );
    }

    /** @return array<string,mixed> */
    public function preview(int $hotelId, string $businessDate): array
    {
        $businessDate = $this->date($businessDate);
        $hotel = ($this->hotelReader)($hotelId);
        if (!is_array($hotel)
            || (int)($hotel['id'] ?? 0) !== $hotelId
            || (int)($hotel['tenant_id'] ?? 0) <= 0
        ) {
            throw new RuntimeException('AI daily report broadcast hotel scope not found', 404);
        }

        $closure = ($this->closureReader)($hotelId, $businessDate);
        if (!is_array($closure)) {
            throw new RuntimeException('AI daily report broadcast strict facts unavailable', 422);
        }

        return $this->buildDraft(
            $hotel,
            $closure,
            ($this->clock)()->format('Y-m-d H:i:s')
        );
    }

    /**
     * Generates and immediately reads the exact stored snapshot back. Repeating
     * the same trigger with unchanged facts reuses the immutable saved version.
     *
     * @return array<string,mixed>
     */
    public function generateAndReadback(
        int $hotelId,
        string $businessDate,
        int $userId = 0,
        string $generationTrigger = 'manual'
    ): array {
        $generationTrigger = strtolower(trim($generationTrigger));
        if (!in_array($generationTrigger, ['manual', 'background'], true)) {
            throw new InvalidArgumentException('AI daily report broadcast trigger is invalid');
        }

        $draft = $this->preview($hotelId, $businessDate);
        if (($draft['facts_broadcast_status'] ?? '') !== 'facts_broadcast_ready') {
            $draft['persisted'] = false;
            $draft['snapshot_id'] = null;
            $draft['version_no'] = null;
            $draft['readback_verified'] = false;
            $draft['final_text'] = '';
            $draft['status_message'] = ($draft['facts_broadcast_status'] ?? '') === 'collection_failed'
                ? '当前事实采集失败，不生成空洞经营建议。'
                : '当前没有可确认事实，等待数据后再生成可信播报。';
            return $draft;
        }
        if (!$this->tableExists(self::TABLE)) {
            throw new RuntimeException('AI daily report broadcast snapshot table does not exist');
        }

        $tenantId = (int)$draft['tenant_id'];
        $factsFingerprint = (string)$draft['facts_fingerprint'];
        $existing = $this->snapshotByFacts(
            $tenantId,
            $hotelId,
            $businessDate,
            $generationTrigger,
            $factsFingerprint
        );
        if (is_array($existing)) {
            return $this->normalizeStoredRow($existing, false, true);
        }

        $createdId = 0;
        try {
            $createdId = (int)Db::transaction(function () use (
                $draft,
                $tenantId,
                $hotelId,
                $businessDate,
                $generationTrigger,
                $factsFingerprint,
                $userId
            ): int {
                $duplicate = $this->snapshotByFacts(
                    $tenantId,
                    $hotelId,
                    $businessDate,
                    $generationTrigger,
                    $factsFingerprint,
                    true
                );
                if (is_array($duplicate)) {
                    return (int)($duplicate['id'] ?? 0);
                }

                $latest = Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('business_date', $businessDate)
                    ->lock(true)
                    ->order('version_no', 'desc')
                    ->order('id', 'desc')
                    ->find();
                $version = max(1, (int)($latest['version_no'] ?? 0) + 1);
                $viewStatus = $generationTrigger === 'background' ? 'pending_view' : 'ready';
                $snapshotFingerprint = $this->snapshotFingerprint(
                    $draft,
                    $version,
                    $generationTrigger,
                    $viewStatus
                );

                return (int)Db::name(self::TABLE)->insertGetId([
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'business_date' => $businessDate,
                    'version_no' => $version,
                    'facts_broadcast_status' => (string)$draft['facts_broadcast_status'],
                    'analysis_status' => (string)$draft['analysis_status'],
                    'view_status' => $viewStatus,
                    'generation_trigger' => $generationTrigger,
                    'template_version' => self::TEMPLATE_VERSION,
                    'hotel_name_snapshot' => (string)$draft['hotel_name'],
                    'data_cutoff_at' => $draft['data_cutoff_at'],
                    'facts_fingerprint' => $factsFingerprint,
                    'snapshot_fingerprint' => $snapshotFingerprint,
                    'final_text_sha256' => hash('sha256', (string)$draft['final_text']),
                    'facts_json' => $this->canonicalJson($draft['facts']),
                    'fact_refs_json' => $this->canonicalJson($draft['fact_refs']),
                    'missing_items_json' => $this->canonicalJson($draft['missing_items']),
                    'source_status_json' => $this->canonicalJson($draft['source_status']),
                    'final_text' => (string)$draft['final_text'],
                    'generated_at' => (string)$draft['generated_at'],
                    'created_by' => max(0, $userId),
                ]);
            });
        } catch (Throwable $error) {
            if (!$this->isDuplicateKeyConflict($error)) {
                throw $error;
            }
        }

        $row = $createdId > 0
            ? Db::name(self::TABLE)->where('id', $createdId)->find()
            : $this->snapshotByFacts(
                $tenantId,
                $hotelId,
                $businessDate,
                $generationTrigger,
                $factsFingerprint
            );
        if (!is_array($row)) {
            throw new RuntimeException('AI daily report broadcast snapshot readback failed');
        }

        return $this->normalizeStoredRow($row, $createdId > 0, $createdId <= 0);
    }

    /** @return array<string,mixed> */
    public function latestOrPreview(int $hotelId, string $businessDate, array $hotelIds): array
    {
        $stored = $this->readLatest($hotelId, $businessDate, $hotelIds);
        if (is_array($stored)) {
            return $stored;
        }

        $preview = $this->preview($hotelId, $businessDate);
        $preview['persisted'] = false;
        $preview['snapshot_id'] = null;
        $preview['version_no'] = null;
        $preview['readback_verified'] = false;
        $preview['final_text'] = '';
        $preview['status_message'] = ($preview['facts_broadcast_status'] ?? '') === 'facts_broadcast_ready'
            ? '已找到严格回读事实，点击“生成可信播报”后正式保存。'
            : (($preview['facts_broadcast_status'] ?? '') === 'collection_failed'
                ? '事实采集失败；不会生成空洞经营建议。'
                : '等待严格回读事实；不会用 0、旧数据或默认值补位。');
        return $preview;
    }

    /** @return array<string,mixed>|null */
    public function readLatest(int $hotelId, string $businessDate, array $hotelIds): ?array
    {
        $businessDate = $this->date($businessDate);
        $hotelIds = $this->permittedHotelIds($hotelIds);
        if (!in_array($hotelId, $hotelIds, true) || !$this->tableExists(self::TABLE)) {
            return null;
        }

        $hotel = ($this->hotelReader)($hotelId);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return null;
        }
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->order('version_no', 'desc')
            ->order('id', 'desc')
            ->find();

        return is_array($row) ? $this->normalizeStoredRow($row, false, false) : null;
    }

    /** @return array<string,mixed>|null */
    public function readExact(int $snapshotId, array $hotelIds): ?array
    {
        if ($snapshotId <= 0 || !$this->tableExists(self::TABLE)) {
            return null;
        }
        $hotelIds = $this->permittedHotelIds($hotelIds);
        $row = Db::name(self::TABLE)
            ->where('id', $snapshotId)
            ->whereIn('hotel_id', $hotelIds)
            ->find();

        return is_array($row) ? $this->normalizeStoredRow($row, false, false) : null;
    }

    /**
     * Pure deterministic adapter used by preview, persistence and focused tests.
     * Numeric values are copied from the strict closure contract; this method
     * formats them but never calculates a new business metric.
     *
     * @param array<string,mixed> $hotel
     * @param array<string,mixed> $closure
     * @return array<string,mixed>
     */
    public function buildDraft(array $hotel, array $closure, string $generatedAt): array
    {
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $businessDate = $this->date((string)($closure['business_date'] ?? ''));
        if ($hotelId <= 0
            || $tenantId <= 0
            || (int)($closure['hotel_id'] ?? 0) !== $hotelId
            || (int)($closure['tenant_id'] ?? 0) !== $tenantId
        ) {
            throw new RuntimeException('AI daily report broadcast strict fact scope mismatch', 422);
        }
        if (!$this->isDateTime($generatedAt)) {
            throw new InvalidArgumentException('AI daily report broadcast generated_at is invalid');
        }

        $platforms = is_array($closure['platforms'] ?? null) ? $closure['platforms'] : [];
        $candidates = [];
        $sourceStatus = [];
        $collectionFailed = false;
        foreach (self::PLATFORMS as $platform) {
            $platformRow = is_array($platforms[$platform] ?? null) ? $platforms[$platform] : [];
            $fields = is_array($platformRow['fields'] ?? null) ? $platformRow['fields'] : [];
            $platformFailure = in_array((string)($platformRow['status'] ?? ''), self::FAILURE_STATUSES, true);
            foreach ($fields as $field) {
                if (in_array((string)($field['status'] ?? ''), self::FAILURE_STATUSES, true)) {
                    $platformFailure = true;
                    break;
                }
            }
            $collectionFailed = $collectionFailed || $platformFailure;
            $sourceStatus[$platform] = [
                'label' => $this->platformLabel($platform),
                'identity_status' => (string)($platformRow['identity_status'] ?? 'unknown'),
                'platform_status' => (string)($platformRow['platform_status'] ?? 'unknown'),
                'target_date_status' => (string)($platformRow['target_date_status'] ?? 'unknown'),
                'exact_run_readback_status' => (string)($platformRow['exact_run_readback_status'] ?? 'unknown'),
                'closure_status' => (string)($platformRow['status'] ?? 'missing'),
                'analysis_status' => (string)($platformRow['revenue_analysis']['status'] ?? 'blocked'),
                'collection_failed' => $platformFailure,
                'selected_fact_count' => 0,
            ];

            foreach (self::FACT_FIELDS as $metricKey) {
                $field = is_array($fields[$metricKey] ?? null) ? $fields[$metricKey] : [];
                if (!$this->fieldIsFact($field)) {
                    continue;
                }
                $refs = $this->sourceRefs($field['source_record_refs'] ?? []);
                if ($refs === []) {
                    continue;
                }
                $candidates[] = [
                    'platform' => $platform,
                    'platform_label' => $this->platformLabel($platform),
                    'metric_key' => $metricKey,
                    'label' => $this->fieldLabel($platform, $metricKey),
                    'value' => $this->numericValue($field['value']),
                    'unit' => self::FIELD_UNITS[$metricKey],
                    'status' => (string)$field['status'],
                    'source_refs' => $refs,
                    'revenue_analysis_consumable' => ($field['revenue_analysis_consumable'] ?? false) === true,
                ];
            }
        }

        $preferred = array_values(array_filter(
            $candidates,
            static fn(array $fact): bool => ($fact['revenue_analysis_consumable'] ?? false) === true
        ));
        if ($preferred !== []) {
            $facts = $preferred;
        } else {
            $moneyUncertain = $this->moneyCaliberUncertain($platforms);
            $facts = array_values(array_filter(
                $candidates,
                static fn(array $fact): bool => !$moneyUncertain
                    || !in_array((string)$fact['metric_key'], ['revenue', 'adr'], true)
            ));
        }
        usort($facts, fn(array $left, array $right): int =>
            $this->factPriority($left) <=> $this->factPriority($right));
        $facts = array_slice($facts, 0, 5);
        foreach ($facts as $fact) {
            $platform = (string)$fact['platform'];
            $sourceStatus[$platform]['selected_fact_count']++;
        }

        $mergedFactRefs = [];
        foreach ($facts as $fact) {
            $mergedFactRefs = array_merge($mergedFactRefs, (array)($fact['source_refs'] ?? []));
        }
        $factRefs = $this->sourceRefs($mergedFactRefs);
        $missingItems = $this->missingItems($platforms);
        $analysisReady = (string)($closure['status'] ?? '') === 'ready';
        foreach (self::PLATFORMS as $platform) {
            $analysisReady = $analysisReady
                && (string)($sourceStatus[$platform]['analysis_status'] ?? '') === 'ready';
        }
        $analysisStatus = $analysisReady ? 'analysis_ready' : 'analysis_blocked';
        $factsStatus = $facts !== []
            ? 'facts_broadcast_ready'
            : ($collectionFailed ? 'collection_failed' : 'waiting_data');
        $dataCutoffAt = $this->dataCutoffAt($facts, $platforms);
        $hotelName = trim((string)($hotel['name'] ?? '')) ?: ('Hotel ' . $hotelId);
        $factSentence = $facts === [] ? '' : $this->factsSentence($facts);
        $missingSentence = $this->missingSentence($platforms, $analysisStatus);
        $attention = $facts === [] ? null : $this->attentionText($platforms, $analysisStatus);
        $sourceStatusText = $this->sourceStatusText($sourceStatus, $analysisStatus);
        $finalText = $facts === [] ? '' : implode("\n", [
            '可信经营播报',
            sprintf('门店：%s（Hotel %d）', $hotelName, $hotelId),
            '业务日期：' . $businessDate,
            '已确认事实：' . $factSentence . '。',
            '异常/缺失：' . $missingSentence,
            '今天最需要关注的一件事：' . $attention,
            '数据截止时间：' . ($dataCutoffAt ?: '未返回') . '。',
            '来源状态：' . $sourceStatusText,
        ]);

        $draft = [
            'snapshot_id' => null,
            'version_no' => null,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'business_date' => $businessDate,
            'generated_at' => $generatedAt,
            'data_cutoff_at' => $dataCutoffAt,
            'facts_broadcast_status' => $factsStatus,
            'analysis_status' => $analysisStatus,
            'status_label' => $factsStatus === 'facts_broadcast_ready'
                ? '严格事实可播报'
                : ($factsStatus === 'collection_failed' ? '采集失败' : '等待数据'),
            'template_version' => self::TEMPLATE_VERSION,
            'facts' => $facts,
            'fact_refs' => $factRefs,
            'missing_items' => $missingItems,
            'source_status' => $sourceStatus,
            'final_text' => $finalText,
            'final_text_sha256' => $finalText === '' ? null : hash('sha256', $finalText),
            'today_attention' => $attention,
            'can_generate' => $factsStatus === 'facts_broadcast_ready',
            'can_use' => false,
            'persisted' => false,
            'readback_verified' => false,
            'authorization' => [
                'local_snapshot_write_authorized' => true,
                'external_delivery_authorized' => false,
                'wecom_send_authorized' => false,
                'ota_write_authorized' => false,
                'pms_write_authorized' => false,
                'scheduled_push_authorized' => false,
            ],
        ];
        $draft['facts_fingerprint'] = $this->factsFingerprint($draft);

        return $draft;
    }

    /** @return array<string,mixed>|null */
    private function snapshotByFacts(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $generationTrigger,
        string $factsFingerprint,
        bool $lock = false
    ): ?array {
        $query = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->where('template_version', self::TEMPLATE_VERSION)
            ->where('generation_trigger', $generationTrigger)
            ->where('facts_fingerprint', $factsFingerprint);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function normalizeStoredRow(array $row, bool $created, bool $reused): array
    {
        $facts = $this->decodeJson((string)($row['facts_json'] ?? ''));
        $factRefs = $this->decodeJson((string)($row['fact_refs_json'] ?? ''));
        $missingItems = $this->decodeJson((string)($row['missing_items_json'] ?? ''));
        $sourceStatus = $this->decodeJson((string)($row['source_status_json'] ?? ''));
        $normalized = [
            'snapshot_id' => (int)($row['id'] ?? 0),
            'version_no' => (int)($row['version_no'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'hotel_name' => (string)($row['hotel_name_snapshot'] ?? ''),
            'business_date' => substr((string)($row['business_date'] ?? ''), 0, 10),
            'generated_at' => (string)($row['generated_at'] ?? ''),
            'data_cutoff_at' => $this->nullableText($row['data_cutoff_at'] ?? null),
            'facts_broadcast_status' => (string)($row['facts_broadcast_status'] ?? ''),
            'analysis_status' => (string)($row['analysis_status'] ?? ''),
            'status_label' => (string)($row['facts_broadcast_status'] ?? '') === 'facts_broadcast_ready'
                ? '严格事实可播报'
                : '不可播报',
            'view_status' => (string)($row['view_status'] ?? ''),
            'generation_trigger' => (string)($row['generation_trigger'] ?? ''),
            'template_version' => (string)($row['template_version'] ?? ''),
            'facts_fingerprint' => (string)($row['facts_fingerprint'] ?? ''),
            'snapshot_fingerprint' => (string)($row['snapshot_fingerprint'] ?? ''),
            'facts' => $facts,
            'fact_refs' => $factRefs,
            'missing_items' => $missingItems,
            'source_status' => $sourceStatus,
            'final_text' => (string)($row['final_text'] ?? ''),
            'final_text_sha256' => (string)($row['final_text_sha256'] ?? ''),
            'can_generate' => true,
            'can_use' => true,
            'persisted' => true,
            'created' => $created,
            'reused' => $reused,
            'authorization' => [
                'local_snapshot_write_authorized' => true,
                'external_delivery_authorized' => false,
                'wecom_send_authorized' => false,
                'ota_write_authorized' => false,
                'pms_write_authorized' => false,
                'scheduled_push_authorized' => false,
            ],
        ];

        $calculatedFactsFingerprint = $this->factsFingerprint($normalized);
        $calculatedSnapshotFingerprint = $this->snapshotFingerprint(
            $normalized,
            (int)$normalized['version_no'],
            (string)$normalized['generation_trigger'],
            (string)$normalized['view_status']
        );
        $normalized['readback_verified'] = (int)$normalized['snapshot_id'] > 0
            && (int)$normalized['tenant_id'] > 0
            && (int)$normalized['hotel_id'] > 0
            && $this->isDate((string)$normalized['business_date'])
            && $this->isDateTime((string)$normalized['generated_at'])
            && (string)$normalized['facts_broadcast_status'] === 'facts_broadcast_ready'
            && (string)$normalized['template_version'] === self::TEMPLATE_VERSION
            && hash_equals((string)$normalized['facts_fingerprint'], $calculatedFactsFingerprint)
            && hash_equals((string)$normalized['snapshot_fingerprint'], $calculatedSnapshotFingerprint)
            && hash_equals((string)$normalized['final_text_sha256'], hash('sha256', (string)$normalized['final_text']))
            && trim((string)$normalized['final_text']) !== '';

        if ($normalized['readback_verified'] !== true) {
            throw new RuntimeException('AI daily report broadcast snapshot readback identity mismatch');
        }
        $normalized['can_use'] = true;

        return $normalized;
    }

    /** @param array<string,mixed> $draft */
    private function factsFingerprint(array $draft): string
    {
        return hash('sha256', $this->canonicalJson([
            'tenant_id' => (int)($draft['tenant_id'] ?? 0),
            'hotel_id' => (int)($draft['hotel_id'] ?? 0),
            'business_date' => (string)($draft['business_date'] ?? ''),
            'template_version' => (string)($draft['template_version'] ?? self::TEMPLATE_VERSION),
            'data_cutoff_at' => $draft['data_cutoff_at'] ?? null,
            'facts_broadcast_status' => (string)($draft['facts_broadcast_status'] ?? ''),
            'analysis_status' => (string)($draft['analysis_status'] ?? ''),
            'facts' => array_values((array)($draft['facts'] ?? [])),
            'fact_refs' => array_values((array)($draft['fact_refs'] ?? [])),
            'missing_items' => array_values((array)($draft['missing_items'] ?? [])),
            'source_status' => (array)($draft['source_status'] ?? []),
            'final_text' => (string)($draft['final_text'] ?? ''),
        ]));
    }

    /** @param array<string,mixed> $draft */
    private function snapshotFingerprint(
        array $draft,
        int $version,
        string $generationTrigger,
        string $viewStatus
    ): string {
        return hash('sha256', $this->canonicalJson([
            'facts_fingerprint' => (string)($draft['facts_fingerprint'] ?? $this->factsFingerprint($draft)),
            'version_no' => $version,
            'hotel_name' => (string)($draft['hotel_name'] ?? ''),
            'generated_at' => (string)($draft['generated_at'] ?? ''),
            'generation_trigger' => $generationTrigger,
            'view_status' => $viewStatus,
            'final_text_sha256' => hash('sha256', (string)($draft['final_text'] ?? '')),
        ]));
    }

    /** @param array<string,mixed> $field */
    private function fieldIsFact(array $field): bool
    {
        return in_array((string)($field['status'] ?? ''), self::FACT_STATUSES, true)
            && $this->numericValue($field['value'] ?? null) !== null;
    }

    private function numericValue(mixed $value): int|float|null
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            return null;
        }
        return floor($number) === $number ? (int)$number : $number;
    }

    /** @param array<string,mixed> $platforms */
    private function moneyCaliberUncertain(array $platforms): bool
    {
        foreach (self::PLATFORMS as $platform) {
            $fields = (array)($platforms[$platform]['fields'] ?? []);
            foreach (['revenue', 'adr'] as $metricKey) {
                if ((string)($fields[$metricKey]['status'] ?? '') === 'caliber_uncertain') {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $fact */
    private function factPriority(array $fact): int
    {
        $fieldOrder = array_flip(self::FACT_FIELDS);
        $platformOrder = ['meituan' => 0, 'ctrip' => 1];
        return (($fact['revenue_analysis_consumable'] ?? false) === true ? 0 : 100)
            + (($platformOrder[(string)($fact['platform'] ?? '')] ?? 9) * 20)
            + ($fieldOrder[(string)($fact['metric_key'] ?? '')] ?? 19);
    }

    /** @param array<int,array<string,mixed>> $facts */
    private function factsSentence(array $facts): string
    {
        $groups = [];
        foreach ($facts as $fact) {
            $groups[(string)$fact['platform']][] = $fact;
        }
        $sentences = [];
        foreach (self::PLATFORMS as $platform) {
            $rows = $groups[$platform] ?? [];
            if ($rows === []) {
                continue;
            }
            $parts = [];
            foreach ($rows as $index => $fact) {
                $prefix = $index === 0 ? $this->platformLabel($platform) : '';
                $parts[] = $prefix
                    . (string)$fact['label']
                    . ' '
                    . $this->formatFactValue((string)$fact['metric_key'], $fact['value']);
            }
            $sentences[] = implode('、', $parts);
        }
        return implode('；', $sentences);
    }

    private function formatFactValue(string $metricKey, mixed $value): string
    {
        $number = (float)$value;
        if ($metricKey === 'conversion') {
            return number_format($number, 2, '.', ',') . '%';
        }
        if (in_array($metricKey, ['revenue', 'adr'], true)) {
            return '¥' . number_format($number, 2, '.', ',');
        }
        if (floor($number) === $number) {
            return number_format($number, 0, '.', ',');
        }
        return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
    }

    /** @param array<string,mixed> $platforms */
    private function missingItems(array $platforms): array
    {
        $items = [];
        foreach (self::PLATFORMS as $platform) {
            $fields = (array)($platforms[$platform]['fields'] ?? []);
            foreach (['exposure', 'visits', 'conversion', 'revenue', 'adr'] as $metricKey) {
                $field = is_array($fields[$metricKey] ?? null) ? $fields[$metricKey] : [];
                if ($this->fieldIsFact($field)) {
                    continue;
                }
                $status = (string)($field['status'] ?? 'missing');
                $items[] = [
                    'code' => $platform . '_' . $metricKey . '_' . ($status ?: 'missing'),
                    'platform' => $platform,
                    'platform_label' => $this->platformLabel($platform),
                    'metric_key' => $metricKey,
                    'label' => self::FIELD_LABELS[$metricKey],
                    'status' => $status ?: 'missing',
                    'message' => $this->platformLabel($platform)
                        . self::FIELD_LABELS[$metricKey]
                        . $this->missingStatusText($status),
                ];
            }
        }
        return $items;
    }

    /** @param array<string,mixed> $platforms */
    private function missingSentence(array $platforms, string $analysisStatus): string
    {
        $ctripExposure = (array)($platforms['ctrip']['fields']['exposure'] ?? []);
        $headlines = [];
        if (!$this->fieldIsFact($ctripExposure)) {
            $headlines[] = in_array((string)($ctripExposure['status'] ?? ''), self::FAILURE_STATUSES, true)
                ? '携程曝光人数采集失败'
                : '携程曝光人数事实缺失';
        }
        if ($this->moneyCaliberUncertain($platforms)
            || !$this->fieldIsFact((array)($platforms['ctrip']['fields']['revenue'] ?? []))
            || !$this->fieldIsFact((array)($platforms['meituan']['fields']['revenue'] ?? []))
        ) {
            $headlines[] = '收入口径未确认';
        }
        if ($headlines === []) {
            $headlines[] = '当前未发现关键事实缺口';
        }
        $text = implode('、', array_values(array_unique($headlines)));
        if ($analysisStatus === 'analysis_blocked') {
            $text .= '，因此暂不生成双平台竞争和收益结论。';
        } else {
            $text .= '。';
        }
        return $text;
    }

    /** @param array<string,mixed> $platforms */
    private function attentionText(array $platforms, string $analysisStatus): string
    {
        $ctripExposureMissing = !$this->fieldIsFact((array)($platforms['ctrip']['fields']['exposure'] ?? []));
        $moneyUnconfirmed = $this->moneyCaliberUncertain($platforms)
            || !$this->fieldIsFact((array)($platforms['meituan']['fields']['revenue'] ?? []));
        if ($ctripExposureMissing && $moneyUnconfirmed) {
            return '优先补齐携程曝光人数事实并核对收入口径；补齐前只跟踪已确认的美团渠道事实。';
        }
        if ($ctripExposureMissing) {
            return '优先补齐携程曝光人数事实；补齐前不形成双平台竞争判断。';
        }
        if ($moneyUnconfirmed) {
            return '优先核对收入口径；确认前不形成收益结论。';
        }
        return $analysisStatus === 'analysis_blocked'
            ? '优先补齐竞争分析所需的同口径事实；现阶段只使用已确认事实。'
            : '继续核对最新严格回读事实，经营动作仍由人工确认。';
    }

    /**
     * @param array<int,array<string,mixed>> $facts
     * @param array<string,mixed> $platforms
     */
    private function dataCutoffAt(array $facts, array $platforms): ?string
    {
        $selectedPlatforms = array_values(array_unique(array_map(
            static fn(array $fact): string => (string)($fact['platform'] ?? ''),
            $facts
        )));
        $values = [];
        foreach ($selectedPlatforms as $platform) {
            $value = (string)($platforms[$platform]['fields']['collected_at']['value'] ?? '');
            if ($this->isDateTime($value)) {
                $values[] = $value;
            }
        }
        sort($values, SORT_STRING);
        return $values === [] ? null : end($values);
    }

    /** @param array<string,mixed> $sourceStatus */
    private function sourceStatusText(array $sourceStatus, string $analysisStatus): string
    {
        $parts = [];
        foreach (['meituan', 'ctrip'] as $platform) {
            $row = (array)($sourceStatus[$platform] ?? []);
            if ((int)($row['selected_fact_count'] ?? 0) > 0) {
                $parts[] = $this->platformLabel($platform) . '严格回读事实可用';
            } elseif (($row['collection_failed'] ?? false) === true) {
                $parts[] = $this->platformLabel($platform) . '采集失败';
            } else {
                $parts[] = $this->platformLabel($platform) . '关键事实不完整';
            }
        }
        $parts[] = $analysisStatus === 'analysis_ready' ? '竞争分析已就绪' : '竞争分析仍受阻';
        return implode('；', $parts) . '。';
    }

    private function fieldLabel(string $platform, string $metricKey): string
    {
        if ($metricKey === 'visits' && $platform === 'meituan') {
            return '商详访客';
        }
        return self::FIELD_LABELS[$metricKey] ?? $metricKey;
    }

    private function platformLabel(string $platform): string
    {
        return $platform === 'meituan' ? '美团' : ($platform === 'ctrip' ? '携程' : $platform);
    }

    private function missingStatusText(string $status): string
    {
        return match ($status) {
            'caliber_uncertain' => '口径未确认',
            'collection_failed' => '采集失败',
            'login_expired' => '登录失效',
            'date_mismatch' => '日期不符',
            'platform_not_provided' => '平台未提供',
            default => '事实缺失',
        };
    }

    /** @return list<string> */
    private function sourceRefs(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $refs = [];
        foreach ($values as $value) {
            $ref = trim((string)$value);
            if (preg_match('/^online_daily_data#[1-9][0-9]*$/D', $ref) === 1) {
                $refs[] = $ref;
            }
        }
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_NATURAL);
        return $refs;
    }

    /** @return list<int> */
    private function permittedHotelIds(array $hotelIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            throw new RuntimeException('no permitted hotel');
        }
        return $ids;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if (!$this->isDate($value)) {
            throw new InvalidArgumentException('AI daily report broadcast date is invalid');
        }
        return $value;
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function isDateTime(string $value): bool
    {
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $dateTime instanceof DateTimeImmutable
            && $dateTime->format('Y-m-d H:i:s') === $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'") !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function isDuplicateKeyConflict(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate entry')
                || str_contains($message, 'integrity constraint violation: 1062')
            ) {
                return true;
            }
        }
        return false;
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function canonicalJson(mixed $value): string
    {
        $encoded = json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($encoded)) {
            throw new RuntimeException('AI daily report broadcast JSON encoding failed');
        }
        return $encoded;
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
