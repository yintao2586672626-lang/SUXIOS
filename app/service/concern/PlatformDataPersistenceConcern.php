<?php
declare(strict_types=1);

namespace app\service\concern;

use app\contract\DataSourceAdapter;
use app\service\OnlineDailyDataPersistenceService;
use app\service\platform\ApiDataSourceAdapter;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\LocalCollectorDataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use RuntimeException;
use think\facade\Cache;
use think\facade\Db;

trait PlatformDataPersistenceConcern
{
    private function storeRawRecord(array $source, int $taskId, array $payload, ?int $httpStatus): void
    {
        $dataType = $this->normalizeDataType((string)($source['data_type'] ?? ''));
        if ($dataType === 'review') {
            $allowReviewSummary = $this->payloadRequestsReviewDetailStorage($payload)
                && $this->isReviewCollectionAllowed($source, $payload, $dataType);
            $payload = $this->sanitizeReviewPayloadForStorage($payload, $allowReviewSummary);
        } else {
            $payload = $this->sanitizePayloadForStorage($payload, $dataType);
        }
        $rawRecord = $this->buildRawRecordPayload($payload);
        $data = [
            'data_source_id' => (int)$source['id'],
            'sync_task_id' => $taskId,
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0) ?: null,
            'platform' => (string)$source['platform'],
            'data_type' => (string)$source['data_type'],
            'ingestion_method' => (string)$source['ingestion_method'],
            'payload_hash' => $rawRecord['payload_hash'],
            'raw_payload' => $rawRecord['raw_payload'],
            'http_status' => $httpStatus,
            'received_at' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
        ];
        if (isset($this->tableColumns('platform_data_raw_records')['tenant_id'])) {
            $data['tenant_id'] = $this->resolveSourceTenantId($source);
        }

        Db::name('platform_data_raw_records')->insert($data);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{payload_hash: string, raw_payload: string}
     */
    private function buildRawRecordPayload(array $payload): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            $raw = json_encode([
                '_raw_payload_encoding_failed' => true,
                'json_error' => json_last_error_msg(),
                'payload_keys' => array_slice(array_map('strval', array_keys($payload)), 0, 80),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $payloadHash = hash('sha256', $raw);
        if (strlen($raw) <= self::RAW_RECORD_PAYLOAD_LIMIT_BYTES) {
            return ['payload_hash' => $payloadHash, 'raw_payload' => $raw];
        }

        $summary = $this->summarizeLargeRawPayload($payload, strlen($raw), $payloadHash);
        $boundedRaw = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($boundedRaw === false || strlen($boundedRaw) > self::RAW_RECORD_PAYLOAD_LIMIT_BYTES) {
            $boundedRaw = json_encode([
                '_raw_payload_truncated' => true,
                'reason' => 'raw_payload_exceeds_db_packet_safe_limit',
                'original_payload_bytes' => strlen($raw),
                'stored_payload_limit_bytes' => self::RAW_RECORD_PAYLOAD_LIMIT_BYTES,
                'payload_hash' => $payloadHash,
                'payload_keys' => array_slice(array_map('strval', array_keys($payload)), 0, 80),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return ['payload_hash' => $payloadHash, 'raw_payload' => $boundedRaw];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function summarizeLargeRawPayload(array $payload, int $originalBytes, string $payloadHash): array
    {
        $trace = [];
        foreach (['profile_id', 'hotel_id', 'hotel_name', 'system_hotel_id', 'source', 'mode', 'captured_at', 'default_data_date', 'data_period', 'snapshot_time', 'snapshot_bucket', 'output'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string)$payload[$key]) !== '') {
                $trace[$key] = mb_substr((string)$payload[$key], 0, 500);
            }
        }
        if (isset($payload['outputs']) && is_array($payload['outputs'])) {
            $trace['outputs'] = array_slice(array_values(array_filter(array_map(
                static fn($item): string => is_scalar($item) ? (string)$item : '',
                $payload['outputs']
            ), static fn(string $item): bool => $item !== '')), 0, 20);
        }

        $meta = [];
        foreach (['data_source_capture', 'sync_summary', 'auth_status', 'capture_gate', 'capture_gate_warning', 'capture_execution', 'cookie_injection'] as $key) {
            if (array_key_exists($key, $payload)) {
                $meta[$key] = $this->compactRawPayloadMetaValue($payload[$key]);
            }
        }

        return [
            '_raw_payload_truncated' => true,
            'reason' => 'raw_payload_exceeds_db_packet_safe_limit',
            'original_payload_bytes' => $originalBytes,
            'stored_payload_limit_bytes' => self::RAW_RECORD_PAYLOAD_LIMIT_BYTES,
            'payload_hash' => $payloadHash,
            'payload_keys' => array_slice(array_map('strval', array_keys($payload)), 0, 80),
            'payload_counts' => $this->rawPayloadCollectionCounts($payload),
            'trace' => $trace,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private function rawPayloadCollectionCounts(array $payload): array
    {
        $counts = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $counts[(string)$key] = count($value);
            }
        }
        return $counts;
    }

    private function compactRawPayloadMetaValue(mixed $value, int $depth = 0): mixed
    {
        if (is_scalar($value) || $value === null) {
            return is_string($value) && mb_strlen($value) > 500 ? mb_substr($value, 0, 500) : $value;
        }
        if (!is_array($value)) {
            return null;
        }
        if ($depth >= 3) {
            return ['_array_count' => count($value)];
        }

        $compact = [];
        $index = 0;
        foreach ($value as $key => $item) {
            if ($index >= 30) {
                $compact['_truncated_item_count'] = count($value) - $index;
                break;
            }
            $compact[$key] = $this->compactRawPayloadMetaValue($item, $depth + 1);
            $index++;
        }
        return $compact;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{attempted_count:int,saved_count:int,inserted_count:int,updated_count:int,deduplicated_count:int,readback_count:int,readback_verified:bool,row_ids:array<int,int>}
     */
    private function saveNormalizedRows(array $rows): array
    {
        return $this->normalizedRowPersistence->save(
            $rows,
            $this->tableColumns('online_daily_data')
        );
    }

    /** @param array<string, mixed> $row */
    private function persistenceIdentityHash(array $row): string
    {
        return $this->normalizedRowPersistence->identityHash($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractBusinessRows(array $payload): array
    {
        $rows = $payload['rows']
            ?? $payload['list']
            ?? $payload['items']
            ?? $payload['records']
            ?? $payload['orderList']
            ?? $payload['campaignList']
            ?? null;
        if ($rows === null && isset($payload['data']) && is_array($payload['data'])) {
            $rows = $payload['data']['rows']
                ?? $payload['data']['list']
                ?? $payload['data']['items']
                ?? $payload['data']['records']
                ?? $payload['data']['orderList']
                ?? $payload['data']['campaignList']
                ?? $payload['data'];
        }
        if (!is_array($rows)) {
            return [];
        }
        if ($rows !== [] && array_keys($rows) !== range(0, count($rows) - 1)) {
            $rows = [$rows];
        }
        return $rows;
    }

    private function normalizeDataType(string $value): string
    {
        $value = trim($value);
        $value = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
        $value = strtolower((string)preg_replace('/[\s\-.]+/', '_', $value));
        $value = (string)preg_replace('/_+/', '_', $value);
        $value = trim($value, '_');
        if (in_array($value, ['business', 'business_data', 'businessdata', 'trade_data', 'tradedata', 'overview', 'summary', 'core'], true)) {
            return 'business';
        }
        if (in_array($value, ['peer_rank', 'peerrank', 'competitor_rank', 'competitorrank', 'competition', 'rank', 'ranking', 'rankings', 'peer'], true)) {
            return 'peer_rank';
        }
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        if (in_array($value, ['review_data', 'reviewdata'], true)) {
            return 'review';
        }
        if (in_array($value, ['order', 'orders', 'order_list', 'order-list'], true)) {
            return 'order';
        }
        if (in_array($value, ['ad', 'ads', 'advertising', 'advertisement', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['search_keyword', 'search_keywords', 'searchkeyword', 'searchkeywords', 'search_key_word', 'search_key_words', 'keyword', 'keywords', 'search_word', 'search_words', 'hot_word', 'hot_words'], true)) {
            return 'search_keyword';
        }
        if (in_array($value, ['quality', 'service', 'service_quality', 'psi'], true)) {
            return 'quality';
        }
        if (in_array($value, ['flow', 'flow_data', 'flowdata', 'traffic', 'traffic_data', 'trafficdata'], true)) {
            return 'traffic';
        }
        if (in_array($value, ['traffic_analysis', 'trafficanalysis', 'flow_analysis', 'flowanalysis'], true)) {
            return 'traffic_analysis';
        }
        if (in_array($value, ['traffic_forecast', 'trafficforecast', 'flow_forecast', 'flowforecast', 'forecast'], true)) {
            return 'traffic_forecast';
        }
        if (in_array($value, ['room_type', 'room_types', 'roomtype', 'roomtypes', 'product', 'products'], true)) {
            return 'room_type';
        }
        if (in_array($value, ['platform_identity', 'platformidentity', 'identity', 'partner_id', 'partnerid', 'poi_id', 'poiid'], true)) {
            return 'platform_identity';
        }
        return $value !== '' ? $value : 'business';
    }

    private function isCommentDataType(string $dataType): bool
    {
        return $this->normalizeDataType($dataType) === 'review';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $payload
     */
    private function isReviewCollectionAllowed(array $source, array $payload = [], string $dataType = ''): bool
    {
        $effectiveDataType = $dataType !== '' ? $dataType : (string)($source['data_type'] ?? '');
        if (!$this->isCommentDataType($effectiveDataType)) {
            return true;
        }

        if (!$this->payloadRequestsReviewDetailStorage($payload)) {
            return true;
        }

        $config = $this->decodeConfig($source['config_json'] ?? $source['config'] ?? []);
        foreach (['allow_review', 'authorized_review_collection', 'review_collection_enabled'] as $key) {
            if ($this->truthy($payload[$key] ?? null) || $this->truthy($config[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function payloadRequestsReviewDetailStorage(array $payload): bool
    {
        foreach (['review_detail_collection', 'reviewDetailCollection', 'store_review_text', 'storeReviewText', 'store_comment_text', 'storeCommentText'] as $key) {
            if ($this->truthy($payload[$key] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $text = strtolower(trim((string)$value));
        return in_array($text, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function amountValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'advertising') {
            return $this->nullableNumericValue($row, ['todayCost', 'cost', 'cashCost', 'bonusCost', 'ad_cost', 'adCost', 'spend', 'amount'])
                ?? ($preserveMissing ? null : 0.0);
        }
        if ($dataType === 'order') {
            return $this->nullableNumericValue($row, ['totalAmount', 'orderAmount', 'payAmount', 'roomAmount', 'amount', 'order_amount', 'room_revenue', 'revenue'])
                ?? ($preserveMissing ? null : 0.0);
        }
        if ($preserveMissing && in_array($dataType, ['review', 'peer_rank'], true)) {
            return null;
        }
        $amount = $this->nullableNumericValue($row, ['amount', 'checkoutRevenue', 'checkout_revenue', 'revenue', 'order_amount', 'orderAmount', 'room_revenue', 'bookAmount', 'saleAmount', 'totalAmount']);
        return $amount ?? ($preserveMissing ? null : 0.0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function quantityValue(array $row, string $dataType, bool $preserveMissing = false): ?int
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($preserveMissing && $dataType === 'peer_rank') {
            return null;
        }
        if ($dataType === 'order') {
            $roomCount = $this->nullableNumericValue($row, ['roomCount', 'room_count']);
            $nights = $this->nullableNumericValue($row, ['nights', 'night_count', 'nightCount']);
            if ($roomCount !== null && $roomCount > 0 && $nights !== null && $nights > 0) {
                return (int)round($roomCount * $nights);
            }
            if ($preserveMissing) {
                $quantity = $this->nullableNumericValue($row, ['quantity', 'room_nights', 'roomNights', 'nights', 'night_count', 'nightCount']);
                return $quantity === null ? null : (int)round($quantity);
            }
        }
        if ($dataType === 'review') {
            $count = $this->nullableNumericValue($row, ['review_count', 'reviewCount', 'comment_count', 'commentCount', 'count', 'quantity']);
            if ($preserveMissing) {
                return $count === null ? null : (int)round($count);
            }
            $count = $count ?? 0.0;
            return $count > 0 ? (int)round($count) : 1;
        }

        $quantity = $this->nullableNumericValue($row, [
            'quantity',
            'mt_pay_rooms',
            'pay_rooms',
            'payRooms',
            'payRoomNum',
            'pay_room_num',
            'room_nights',
            'roomNights',
            'nights',
            'night_count',
            'checkoutRoomNights',
            'checkout_room_nights',
            'checkOutQuantity',
            'bookQuantity',
        ]);
        return $quantity === null
            ? ($preserveMissing ? null : 0)
            : (int)round($quantity);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dataValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'review') {
            return $this->nullableNumericValue($row, [
                'bad_review_count',
                'badReviewCount',
                'negativeCommentCount',
                'negativeCount',
                'badCount',
                'lowScoreCount',
                'noRecommendCount',
                'data_value',
                'dataValue',
            ]) ?? ($preserveMissing ? null : 0.0);
        }
        if ($preserveMissing && $dataType === 'peer_rank') {
            return null;
        }

        if ($dataType === 'advertising') {
            return $this->nullableNumericValue($row, ['roas', 'roi', 'data_value', 'dataValue']);
        }

        $explicit = $this->nullableNumericValue($row, ['data_value', 'dataValue', 'value', 'metric_value', 'averagePrice', 'avgPrice', 'avg_price']);
        if ($explicit !== null) {
            return $explicit;
        }
        if ($dataType === 'quality') {
            return $this->nullableNumericValue($row, ['serviceScore', 'psiScore', 'imScore', 'score'])
                ?? ($preserveMissing ? null : 0.0);
        }
        if ($dataType === 'peer_rank') {
            return $this->numericValue($row, ['rank', 'ranking', 'rankValue', 'rank_value', 'rankPercent', 'rank_percent']);
        }
        if ($dataType === 'order') {
            $quantity = $this->quantityValue($row, $dataType, $preserveMissing);
            $amount = $this->amountValue($row, $dataType, $preserveMissing);
            if ($quantity !== null && $quantity > 0 && $amount !== null) {
                return round($amount / $quantity, 2);
            }
            return $preserveMissing ? null : 0.0;
        }

        return $preserveMissing ? null : 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function orderCountValue(array $row, string $dataType, bool $preserveMissing = false): ?int
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($preserveMissing && in_array($dataType, ['review', 'peer_rank'], true)) {
            return null;
        }
        $count = $this->nullableNumericValue($row, ['book_order_num', 'orders', 'order_count', 'orderCount', 'bookOrderNum', 'orderNum', 'orderQuantity', 'bookings', 'bookingCount']);
        if ($count !== null) {
            return (int)round($count);
        }
        if ($preserveMissing) {
            return null;
        }
        if ($dataType === 'order' && $this->firstOrderIdentifier($row) !== '') {
            return 1;
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function commentScoreValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        if ($this->normalizeDataType($dataType) !== 'review') {
            return $this->nullableNumericValue($row, ['comment_score']);
        }
        return $this->nullableNumericValue($row, [
            'comment_score',
            'commentScore',
            'score',
            'star',
            'rating',
            'rate',
            'totalScore',
            'overallScore',
        ])
            ?? ($preserveMissing ? null : 0.0);
    }

    /**
     * `flow_rate` is a funnel-stage metric. Advertising rows use CTR
     * (impressions to clicks); CVR stays in raw_data as a separate stage.
     *
     * @param array<string, mixed> $row
     */
    private function flowRateValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        $dataType = $this->normalizeDataType($dataType);
        $keys = $dataType === 'advertising'
            ? ['flow_rate', 'flowRate', 'ctr']
            : ['browse_to_pay_rate', 'browsePayRate', 'browse_pay_rate', 'payOrderPerIntention', 'flow_rate', 'flowRate', 'cvr', 'conversion_rate', 'conversionRate', 'convertionRate', 'avgConversionsRate', 'orderConversionRate', 'dealRate'];
        return $this->nullableNumericValue($row, $keys) ?? ($preserveMissing ? null : 0.0);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayloadForStorage(array $payload, string $dataType = ''): array
    {
        return $this->sanitizePayloadNode($payload, $this->normalizeDataType($dataType) === 'order');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeReviewPayloadForStorage(array $payload, bool $allowSummary = false): array
    {
        $privateValues = $this->reviewPrivateScalarValues($payload);
        $sanitized = $this->removeReviewPrivateFields(
            $this->sanitizePayloadForStorage($payload, 'review'),
            $privateValues
        );
        if ($allowSummary) {
            $summary = $this->reviewSummaryText($payload, $privateValues);
            if ($summary !== '') {
                $sanitized['review_summary'] = $summary;
            }
        }
        return $sanitized;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function removeReviewPrivateFields(array $node, array $privateValues = []): array
    {
        $clean = [];
        foreach ($node as $key => $value) {
            $keyText = (string)$key;
            if ($this->isReviewPrivateKey($keyText)) {
                continue;
            }
            $clean[$key] = is_array($value)
                ? $this->sanitizeReviewArray($value, $privateValues)
                : (is_string($value) ? $this->sanitizeReviewScalar($value, $privateValues) : $value);
        }
        return $clean;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sanitizeReviewArray(array $value, array $privateValues = []): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            $keyText = (string)$key;
            if ($this->isReviewPrivateKey($keyText)) {
                continue;
            }
            $clean[$key] = is_array($item)
                ? $this->sanitizeReviewArray($item, $privateValues)
                : (is_string($item) ? $this->sanitizeReviewScalar($item, $privateValues) : $item);
        }
        return $clean;
    }

    private function isReviewPrivateKey(string $key): bool
    {
        return preg_match('/content|commentContent|comment_text|review_text|reviewer|review[_-]?id|comment[_-]?id|reply|guest|customer|userName|username|nick|phone|mobile|tel|email|certificate|idcard|id_card|identity|openid|avatar|order[_-]?(id|no|number)|room(type|name)|photo|image|pic/i', $key) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reviewSummaryText(array $payload, array $privateValues = []): string
    {
        $text = $this->stringValue($payload, ['review_summary', 'summary', 'content', 'commentContent', 'comment_text', 'review_text']);
        if ($text === '') {
            return '';
        }
        $text = $this->sanitizeReviewScalar($text, $privateValues);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return mb_substr($text, 0, 120);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function reviewPrivateScalarValues(array $node): array
    {
        $values = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->reviewPrivateScalarValues($value));
                continue;
            }
            if (!$this->isReviewIdentityKey((string)$key) || !is_scalar($value)) {
                continue;
            }
            $text = trim((string)$value);
            if ($text !== '') {
                $values[] = $text;
            }
        }
        $values = array_values(array_unique($values));
        usort($values, static fn(string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        return $values;
    }

    private function isReviewIdentityKey(string $key): bool
    {
        return preg_match('/reviewer|guest|customer|user[_-]?name|username|nick|phone|mobile|tel|email|certificate|idcard|id_card|identity|openid|order[_-]?(id|no|number)/i', $key) === 1;
    }

    /** @param array<int, string> $privateValues */
    private function sanitizeReviewScalar(string $value, array $privateValues = []): string
    {
        $sanitized = $value;
        foreach ($privateValues as $privateValue) {
            if ($privateValue !== '') {
                $sanitized = str_ireplace($privateValue, '[redacted]', $sanitized);
            }
        }
        $sanitized = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/(?<!\d)(?:\+?86[\s-]*)?1[3-9](?:[\s-]*\d){9}(?!\d)/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/(?<!\d)\d{17}[\dXx](?!\d)/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b\d{6,}\b/', '[redacted]', $sanitized) ?? $sanitized;
        return trim($sanitized);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function reviewValidationFlags(array $row, array $payload, string $dataDate, string $collectionStatus): array
    {
        $flags = [];
        $score = $this->nullableNumericValue($row, [
            'comment_score',
            'commentScore',
            'score',
            'star',
            'rating',
            'rate',
            'totalScore',
            'overallScore',
        ]);
        if ($score === null) {
            $flags[] = 'field_missing:comment_score';
        }

        $targetDate = $this->normalizeDate($payload['target_date'] ?? $payload['targetDate'] ?? null);
        if ($targetDate !== null && $dataDate !== $targetDate) {
            $flags[] = 'data_date_stale:' . $dataDate;
        }
        if ($collectionStatus === 'stale') {
            $flags[] = 'collection_status:stale';
        }
        return $flags;
    }

    /** @param array<int, string> $flags */
    private function reviewValidationStatus(array $flags): string
    {
        if ($flags === []) {
            return 'normal';
        }
        $hasStale = count(array_filter($flags, static fn(string $flag): bool => str_contains($flag, 'stale'))) > 0;
        $hasMissing = count(array_filter($flags, static fn(string $flag): bool => str_starts_with($flag, 'field_missing:'))) > 0;
        if ($hasStale && $hasMissing) {
            return 'quarantined';
        }
        return $hasStale ? 'stale' : 'incomplete';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reviewDimensionValue(array $row): string
    {
        $tags = $row['tags'] ?? $row['labels'] ?? $row['tag_list'] ?? null;
        if (is_array($tags)) {
            $values = array_values(array_filter(array_map(static fn(mixed $item): string => trim((string)$item), $tags), static fn(string $item): bool => $item !== ''));
            return implode(',', array_slice($values, 0, 8));
        }
        return $this->stringValue($row, ['tag', 'label', 'sentiment']);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function sanitizePayloadNode(array $node, bool $orderContext): array
    {
        $sanitized = [];
        foreach ($node as $key => $value) {
            $keyText = (string)$key;
            if ($this->isSensitiveConfigKey($keyText)) {
                continue;
            }

            $childOrderContext = $orderContext || $this->isOrderContainerKey($keyText);
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayloadArray($value, $childOrderContext);
                continue;
            }

            if ($childOrderContext || $this->isOrderPiiKey($keyText)) {
                $this->appendRedactedOrderField($sanitized, $keyText, $value);
                continue;
            }

            $sanitized[$key] = $this->sanitizePayloadScalar($keyText, $value);
        }
        return $sanitized;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sanitizePayloadArray(array $value, bool $orderContext): array
    {
        if ($value === []) {
            return [];
        }
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $sanitized[$key] = $this->sanitizePayloadNode($item, $orderContext);
            } else {
                $keyText = (string)$key;
                if ($this->isSensitiveConfigKey($keyText)) {
                    continue;
                }
                if ($orderContext || $this->isOrderPiiKey($keyText)) {
                    $this->appendRedactedOrderField($sanitized, $keyText, $item);
                } else {
                    $sanitized[$key] = $this->sanitizePayloadScalar($keyText, $item);
                }
            }
        }
        return $sanitized;
    }

    private function sanitizePayloadScalar(string $key, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $text = trim($value);
        if ($text === ''
            || (preg_match('/(?:url|uri|href|link)/i', $key) !== 1
                && preg_match('~^https?://~i', $text) !== 1)
        ) {
            return $value;
        }

        $parts = parse_url($text);
        if (!is_array($parts)) {
            return preg_replace('/[?#].*$/', '', $text) ?? '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if ($host === '') {
            return preg_replace('/[?#].*$/', '', $path !== '' ? $path : $text) ?? '';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $prefix = in_array($scheme, ['http', 'https'], true) ? $scheme . '://' : '';
        return $prefix . $host . $port . $path;
    }

    /**
     * @param array<mixed> $target
     */
    private function appendRedactedOrderField(array &$target, string $key, mixed $value): void
    {
        if ($this->isOrderIdKey($key)) {
            $text = trim((string)$value);
            if ($text !== '') {
                $target[$this->redactedFieldName($key, 'hash')] = hash('sha256', 'ota_order|' . $text);
            }
            return;
        }
        if ($this->isPhoneKey($key)) {
            $masked = $this->maskPhone((string)$value);
            if ($masked !== '') {
                $target[$this->redactedFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isGuestNameKey($key)) {
            $masked = $this->maskName((string)$value);
            if ($masked !== '') {
                $target[$this->redactedFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isSensitiveOrderTextKey($key)) {
            return;
        }

        $target[$key] = $value;
    }

    private function isOrderContainerKey(string $key): bool
    {
        return preg_match('/order[_-]?(list|rows|items|data|detail|details|info)|orders/i', $key) === 1;
    }

    private function isOrderPiiKey(string $key): bool
    {
        return $this->isOrderIdKey($key)
            || $this->isPhoneKey($key)
            || $this->isGuestNameKey($key)
            || $this->isSensitiveOrderTextKey($key);
    }

    private function isOrderIdKey(string $key): bool
    {
        return preg_match('/^(order[_-]?(id|no|num|number|sn)|booking[_-]?(id|no|number))$/i', $key) === 1;
    }

    private function isPhoneKey(string $key): bool
    {
        return preg_match('/(phone|mobile|tel)$/i', $key) === 1;
    }

    private function isGuestNameKey(string $key): bool
    {
        return preg_match('/(guest|customer|contact|user|traveller|passenger)[_-]?name$/i', $key) === 1;
    }

    private function isSensitiveOrderTextKey(string $key): bool
    {
        return preg_match('/(certificate|credential|id[_-]?card|card[_-]?no|passport|remark|memo|note|address)/i', $key) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function firstOrderIdentifier(array $row): string
    {
        foreach (['orderId', 'order_id', 'orderNo', 'order_no', 'orderNumber', 'bookingId', 'booking_id'] as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
    }

    private function redactedFieldName(string $key, string $suffix): string
    {
        if ($this->isOrderIdKey($key)) {
            return 'order_id_hash';
        }
        $name = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $name = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $name = trim($name, '_');
        return ($name !== '' ? $name : 'field') . '_' . $suffix;
    }

    private function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }
        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    private function maskName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 1) . '***';
    }

    private function sanitizeSourceRow(array $row): array
    {
        $config = $this->decodeConfig($row['config_json'] ?? []);
        $isOta = $this->isOtaPlatform((string)($row['platform'] ?? ''));
        $profileMethod = strtolower(trim((string)($row['ingestion_method'] ?? '')));
        $isBrowserProfile = $isOta && in_array($profileMethod, ['browser_profile', 'profile_browser'], true);
        $isLocalCollector = $isOta && $profileMethod === 'local_collector';
        $row['current_session_verified'] = $isBrowserProfile
            ? $this->profileSessionProofService->isCurrentVerified($row)
            : ($isLocalCollector && $this->truthy($config['current_session_verified'] ?? false));
        $profileReuseState = $isBrowserProfile
            ? $this->profileSessionProofService->profileReuseState($row)
            : ($isLocalCollector
                ? [
                    'status' => $row['current_session_verified'] ? 'current_session_verified' : 'unverified',
                    'is_reusable' => $row['current_session_verified'],
                    'age_days' => null,
                    'days_until_forced_login' => null,
                    'warning' => false,
                ]
                : [
                'status' => 'unverified',
                'is_reusable' => false,
                'age_days' => null,
                'days_until_forced_login' => 0,
                'warning' => false,
            ]);
        $row['profile_reusable'] = (bool)($profileReuseState['is_reusable'] ?? false);
        $row['profile_reuse_status'] = (string)($profileReuseState['status'] ?? 'unverified');
        $row['profile_reuse_warning'] = (bool)($profileReuseState['warning'] ?? false);
        $row['profile_age_days'] = isset($profileReuseState['age_days']) ? (int)$profileReuseState['age_days'] : null;
        $row['days_until_forced_login'] = max(0, (int)($profileReuseState['days_until_forced_login'] ?? 0));
        $secret = $isOta ? [] : $this->decodeConfig($row['secret_json'] ?? []);
        unset($row['config_json']);
        unset($row['secret_json']);
        $row['config'] = $this->sanitizeConfigForResponse($config);
        if ($isOta) {
            $row['config_id'] = trim((string)($config['config_id'] ?? ''));
            $row['credential_ref'] = (int)($config['credential_ref'] ?? 0) ?: null;
            $row['credential_status'] = trim((string)($config['credential_status'] ?? $config['status'] ?? ''));
            $row['has_secret'] = array_key_exists('has_secret', $config)
                ? $this->truthy($config['has_secret'])
                : (int)($config['credential_ref'] ?? 0) > 0;
            $row['has_cookies'] = $this->truthy($config['has_cookies'] ?? false);
        } else {
            $row['has_secret'] = !empty($secret);
            $row['has_cookies'] = isset($secret['cookies']) && trim((string)$secret['cookies']) !== '';
        }
        unset($row['cookies_preview']);
        if (array_key_exists('last_error', $row)) {
            $row['last_error'] = $this->safeSyncTaskMessage((string)($row['last_sync_status'] ?? $row['status'] ?? ''), (string)$row['last_error']);
        }
        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseJsonImportFile(string $path): array
    {
        $content = file_get_contents($path);
        $decoded = json_decode((string)$content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON import file is invalid.', 422);
        }
        if ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            return array_values(array_filter($decoded, 'is_array'));
        }
        return $this->extractBusinessRows($decoded);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvImportFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('CSV import file cannot be read.', 422);
        }

        $headers = [];
        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $cells = array_map(static fn($value): string => trim((string)$value), $cells);
            if ($this->isBlankRow($cells)) {
                continue;
            }
            if ($headers === []) {
                $headers = $this->normalizeHeaderRow($cells);
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? '';
            }
            if (!$this->isBlankRow(array_values($row))) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseXlsxImportFile(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('XLSX import requires PHP ZipArchive extension.', 422);
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('XLSX import file cannot be opened.', 422);
        }

        try {
            $this->validateXlsxArchive($zip);
            $sharedStrings = $this->readXlsxSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new RuntimeException('XLSX sheet1.xml was not found.', 422);
            }
            $sheet = simplexml_load_string((string)$sheetXml, 'SimpleXMLElement', LIBXML_NONET);
            if (!$sheet) {
                throw new RuntimeException('XLSX sheet1.xml is invalid.', 422);
            }

            $matrix = [];
            $rowCount = 0;
            foreach ($sheet->sheetData->row as $rowNode) {
                $rowCount++;
                if ($rowCount > self::IMPORT_XLSX_MAX_ROWS) {
                    throw new RuntimeException('XLSX import exceeds the maximum row count.', 422);
                }
                if (count($rowNode->c) > self::IMPORT_XLSX_MAX_COLUMNS) {
                    throw new RuntimeException('XLSX import exceeds the maximum column count.', 422);
                }
                $row = [];
                foreach ($rowNode->c as $cellNode) {
                    $ref = (string)($cellNode['r'] ?? '');
                    $columnIndex = $this->xlsxColumnIndex($ref);
                    if ($columnIndex < 0) {
                        continue;
                    }
                    $type = (string)($cellNode['t'] ?? '');
                    $value = (string)($cellNode->v ?? '');
                    if ($type === 's') {
                        $value = $sharedStrings[(int)$value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string)($cellNode->is->t ?? '');
                    }
                    $row[$columnIndex] = trim($value);
                }
                if (!$this->isBlankRow($row)) {
                    ksort($row);
                    $matrix[] = $row;
                }
            }
        } finally {
            $zip->close();
        }

        if (empty($matrix)) {
            return [];
        }

        $headers = $this->normalizeHeaderRow(array_values(array_shift($matrix)));
        $rows = [];
        foreach ($matrix as $cells) {
            $cells = array_values($cells);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? '';
            }
            if (!$this->isBlankRow(array_values($row))) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function validateXlsxArchive(\ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0) {
            throw new RuntimeException('XLSX import archive is empty.', 422);
        }
        if ($zip->numFiles > self::IMPORT_XLSX_MAX_ARCHIVE_ENTRIES) {
            throw new RuntimeException('XLSX import archive contains too many entries.', 422);
        }

        $totalUncompressedBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new RuntimeException('XLSX import archive entry is invalid.', 422);
            }

            $entryName = str_replace('\\', '/', trim((string)($stat['name'] ?? '')));
            if ($entryName === ''
                || str_starts_with($entryName, '/')
                || preg_match('/^[A-Za-z]:\//', $entryName) === 1
                || in_array('..', explode('/', $entryName), true)
            ) {
                throw new RuntimeException('XLSX import archive contains an unsafe path.', 422);
            }

            if (isset($stat['encryption_method'])
                && (int)$stat['encryption_method'] !== \ZipArchive::EM_NONE
            ) {
                throw new RuntimeException('Encrypted XLSX import entries are not supported.', 422);
            }

            $entryBytes = max(0, (int)($stat['size'] ?? 0));
            if ($entryBytes > self::IMPORT_XLSX_MAX_ENTRY_BYTES) {
                throw new RuntimeException('XLSX import archive entry is too large.', 422);
            }
            $totalUncompressedBytes += $entryBytes;
            if ($totalUncompressedBytes > self::IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('XLSX import archive expands beyond the allowed size.', 422);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function readXlsxSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $shared = simplexml_load_string((string)$xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$shared) {
            return [];
        }

        $strings = [];
        foreach ($shared->si as $item) {
            if (count($strings) >= self::IMPORT_XLSX_MAX_SHARED_STRINGS) {
                throw new RuntimeException('XLSX import contains too many shared strings.', 422);
            }
            if (isset($item->t)) {
                $strings[] = (string)$item->t;
                continue;
            }
            $text = '';
            foreach ($item->r as $run) {
                $text .= (string)($run->t ?? '');
            }
            $strings[] = $text;
        }
        return $strings;
    }

    private function xlsxColumnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return -1;
        }
        $letters = strtoupper($matches[1]);
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
            if ($index > self::IMPORT_XLSX_MAX_COLUMNS) {
                return -1;
            }
        }
        return $index - 1;
    }

    /**
     * @param array<int, mixed> $cells
     * @return array<int, string>
     */
    private function normalizeHeaderRow(array $cells): array
    {
        return array_map(static function ($value): string {
            $header = trim((string)$value);
            return preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        }, $cells);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function sanitizeConfigForResponse(array $config): array
    {
        foreach ($config as $key => $value) {
            $normalized = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', (string)$key), '_'));
            if (in_array($normalized, ['profile_key_hash', 'current_session_probe_profile_key_hash'], true)) {
                unset($config[$key]);
                continue;
            }
            if ($this->isSensitiveConfigKey((string)$key)) {
                $config[$key] = '[configured]';
                continue;
            }
            if (is_array($value)) {
                $config[$key] = $this->sanitizeConfigForResponse($value);
            } elseif (strtolower((string)$key) === 'headers' && is_string($value)) {
                $config[$key] = $this->sanitizeHeaderString($value);
            }
        }
        return $config;
    }

    private function sanitizeHeaderString(string $headers): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $headers) ?: [];
        $sanitized = [];
        foreach ($lines as $line) {
            [$name] = array_pad(explode(':', (string)$line, 2), 2, '');
            $sanitized[] = $this->isSensitiveConfigKey($name) ? trim($name) . ': ' . '[configured]' : $line;
        }
        return implode("\n", $sanitized);
    }

    private function isSensitiveConfigKey(string $key): bool
    {
        $normalized = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', $key), '_'));
        if (in_array($normalized, [
            'has_secret', 'secret_mask', 'has_cookies', 'cookie_configured',
            'has_profile_cookie_source', 'profile_cookie_source', 'profile_cookie_source_candidate', 'cookie_source',
            'authorization_policy', 'requires_explicit_authorization',
        ], true)) {
            return false;
        }
        return preg_match('/cookie|authorization|auth[-_]?data|token|api[-_]?key|secret|password|spider[-_]?(?:token|key)|mtgsig|user[-_]?(?:token|sign)|_mtsi_eb_u/i', $key) === 1;
    }

    private function stringContainsCredentialMaterial(string $value): bool
    {
        return preg_match('/["\']?(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|auth_data|token|access_token|refresh_token|spidertoken|spiderkey|mtgsig|usertoken|usersign|password)["\']?\s*[:=]/i', $value) === 1
            || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value) === 1;
    }

    private function decodeConfig($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeDate($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $value = str_replace('/', '-', $value);
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d', $time);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function numericValue(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = str_replace([',', '%', '￥', '¥', ' '], '', (string)$row[$key]);
            if ($value === '') {
                continue;
            }
            return is_numeric($value) ? (float)$value : 0.0;
        }
        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function nullableNumericValue(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            $value = str_replace([',', '%', ' ', "\u{00A0}", '元', '￥', '¥'], '', (string)$row[$key]);
            if ($value === '') {
                continue;
            }
            return is_numeric($value) ? (float)$value : null;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function integerMetricValue(array $row, array $keys, bool $preserveMissing = false): ?int
    {
        $value = $this->nullableNumericValue($row, $keys);
        if ($value === null) {
            return $preserveMissing ? null : 0;
        }
        return (int)round($value);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function stringValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
    }

    private function buildTraceId(array $source, array $row, string $date, ?int $syncTaskId, string $snapshotBucket = ''): string
    {
        $orderIdentifier = $this->firstOrderIdentifier($row);
        $parts = [
            $source['id'] ?? '',
            $source['platform'] ?? '',
            $source['data_type'] ?? '',
            $date,
            $row['hotel_id'] ?? $row['hotelId'] ?? $row['poi_id'] ?? $row['poiId'] ?? '',
            $row['dimension'] ?? $row['_dimName'] ?? '',
            $snapshotBucket,
            $orderIdentifier !== '' ? hash('sha256', 'ota_order|' . $orderIdentifier) : '',
            $syncTaskId ?? '',
        ];
        return substr(hash('sha256', implode('|', array_map('strval', $parts))), 0, 64);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $source
     * @return array{data_period: string, snapshot_time: ?string, snapshot_bucket: string, is_final: int}
     */
    private function resolveDataPeriodMetadata(array $row, array $payload, array $source, string $date): array
    {
        $period = $this->normalizeDataPeriod(
            $row['data_period']
            ?? $row['dataPeriod']
            ?? $payload['data_period']
            ?? $payload['dataPeriod']
            ?? $source['data_period']
            ?? ''
        );

        if ($period === '') {
            $period = $this->looksLikeRealtimeRow($row, $payload, $source, $date) ? 'realtime_snapshot' : 'historical_daily';
        }

        $dataType = $this->normalizeDataType((string)(
            $row['data_type']
            ?? $row['dataType']
            ?? $payload['data_type']
            ?? $payload['dataType']
            ?? $source['data_type']
            ?? ''
        ));
        if ($dataType === 'traffic_forecast'
            || OnlineDailyDataPersistenceService::isFutureTargetRow($row, $payload, $source)
        ) {
            $period = 'next_30_days';
        } elseif ($date === date('Y-m-d') && $period === 'historical_daily') {
            $period = 'realtime_snapshot';
        }

        $providedCaptureValue = $this->firstCaptureDateTimeValue([
            $row['snapshot_time'] ?? null,
            $row['snapshotTime'] ?? null,
            $row['captured_at'] ?? null,
            $row['capturedAt'] ?? null,
            $payload['snapshot_time'] ?? null,
            $payload['snapshotTime'] ?? null,
            $payload['captured_at'] ?? null,
            $payload['capturedAt'] ?? null,
        ]);
        $providedSnapshotTime = $this->normalizeCaptureDateTime($providedCaptureValue);
        $captureTimeProvided = $providedCaptureValue !== null;
        $snapshotTime = null;
        $snapshotBucket = '';
        if ($period === 'realtime_snapshot') {
            // Missing realtime capture metadata may use the actual persistence
            // clock. An explicitly supplied but invalid value must stay null
            // so it cannot be disguised as a trustworthy current timestamp.
            $snapshotTime = $captureTimeProvided
                ? $providedSnapshotTime
                : date('Y-m-d H:i:s');
            if ($snapshotTime !== null) {
                $snapshotBucket = date('YmdHi', strtotime($snapshotTime) ?: time());
            }
        } elseif ($period === 'historical_daily') {
            // A historical capture time is provenance, not an identity bucket.
            // Preserve it only when the collector actually supplied one; never
            // fabricate a historical timestamp from the current clock.
            $snapshotTime = $providedSnapshotTime;
        }

        return [
            'data_period' => $period,
            'snapshot_time' => $snapshotTime,
            'snapshot_bucket' => $snapshotBucket,
            'is_final' => $period === 'historical_daily' ? 1 : 0,
        ];
    }

    private function normalizeDataPeriod($value): string
    {
        $value = strtolower(str_replace(['-', ' '], '_', trim((string)$value)));
        return match ($value) {
            'realtime', 'real_time', 'realtime_snapshot', 'today_realtime', 'live', 'snapshot' => 'realtime_snapshot',
            'historical', 'history', 'historical_daily', 'daily', 'fixed', 'final' => 'historical_daily',
            'next_30_days', 'next30days', 'future_forecast', 'forecast', 'forecast_window' => 'next_30_days',
            default => '',
        };
    }

    /** @param array<int,mixed> $values */
    private function firstCaptureDateTimeValue(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return null;
    }

    private function normalizeCaptureDateTime($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '' || preg_match(
            '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D',
            $value
        ) !== 1) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (is_array($errors)
                && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0)
            ) {
                return null;
            }
            return $time->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Generic payload timestamp normalization retained for non-provenance
     * fields. Capture provenance must use normalizeCaptureDateTime().
     */
    private function normalizeDateTime($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    private function applySyncOptionPeriodMetadata($payload, array $options): array
    {
        $payload = is_array($payload) ? $payload : [];
        $period = $this->normalizeDataPeriod($options['data_period'] ?? $options['dataPeriod'] ?? '');
        if ($period !== '' && empty($payload['data_period'])) {
            $payload['data_period'] = $period;
        }

        $dataDate = $this->normalizeDate($options['data_date'] ?? $options['dataDate'] ?? $options['target_date'] ?? $options['targetDate'] ?? null);
        if ($dataDate !== null && empty($payload['data_date'])) {
            $payload['data_date'] = $dataDate;
        }

        $snapshotValue = $this->firstCaptureDateTimeValue([
            $options['snapshot_time'] ?? null,
            $options['snapshotTime'] ?? null,
        ]);
        $snapshotTime = $this->normalizeCaptureDateTime($snapshotValue);
        if ($snapshotValue !== null && empty($payload['snapshot_time'])) {
            // Keep an invalid supplied value visible to the downstream strict
            // resolver; do not silently drop it and fall back to the clock.
            $payload['snapshot_time'] = $snapshotTime ?? $snapshotValue;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $source
     */
    private function looksLikeRealtimeRow(array $row, array $payload, array $source, string $date): bool
    {
        if ($date !== date('Y-m-d')) {
            return false;
        }

        $signals = [
            $row['endpoint_id'] ?? '',
            $row['_endpoint_id'] ?? '',
            $row['source_url'] ?? '',
            $row['_source_url'] ?? '',
            $row['dimension'] ?? '',
            $payload['endpoint_id'] ?? '',
            $payload['source_url'] ?? '',
            $source['data_type'] ?? '',
        ];
        $text = strtolower(implode('|', array_map(static fn($value): string => (string)$value, $signals)));
        foreach (['realtime', 'real_time', 'today', 'current', 'rank', 'inventory', 'price'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function tableColumns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        $this->columns[$table] = array_fill_keys(array_column($rows, 'Field'), true);
        return $this->columns[$table];
    }

    private function assertCanUseHotel($user, int $hotelId, string $permission): void
    {
        if (!$user) {
            throw new RuntimeException('Unauthenticated.', 401);
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            $this->resolveHotelTenantId($hotelId);
            return;
        }
        $tenantId = (int)($user->tenant_id ?? 0);
        if ($tenantId <= 0
            || $hotelId <= 0
            || !method_exists($user, 'hasHotelPermission')
            || !$user->hasHotelPermission($hotelId, $permission)
        ) {
            throw new RuntimeException('Forbidden.', 403);
        }
        try {
            $authoritativeTenantId = $this->resolveHotelTenantId($hotelId);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Forbidden.', 403, $exception);
        }
        if ($authoritativeTenantId !== $tenantId) {
            throw new RuntimeException('Forbidden.', 403);
        }
    }

    private function applySourceScope($query, $user): void
    {
        $this->applySourceTenantScope($query, $user);
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    private function applySourceTenantScope($query, $user): void
    {
        if (!$user) {
            throw new RuntimeException('Unauthenticated.', 401);
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }
        $tenantId = (int)($user->tenant_id ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('Authenticated tenant context is required.', 403);
        }
        $query->where('tenant_id', $tenantId);
    }

    private function applyTaskScope($query, $user): void
    {
        if (!$user) {
            throw new RuntimeException('Unauthenticated.', 401);
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }
        $tenantId = (int)($user->tenant_id ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('Authenticated tenant context is required.', 403);
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->where('tenant_id', $tenantId);
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    /** @param array<string, mixed> $source @return array{0:int,1:int} */
    private function assertStoredSourceTenant(array $source): array
    {
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $tenantId = $this->resolveHotelTenantId($hotelId);
        if ((int)($source['tenant_id'] ?? 0) !== $tenantId) {
            throw new RuntimeException('Data source tenant scope does not match its hotel.', 409);
        }

        return [$tenantId, $hotelId];
    }

    /** @param array<string, mixed> $source @return array{0:int,1:int} */
    private function assertStoredSourceTenantForActor(array $source, $user): array
    {
        try {
            return $this->assertStoredSourceTenant($source);
        } catch (\Throwable $exception) {
            if (!$user || !method_exists($user, 'isSuperAdmin') || !$user->isSuperAdmin()) {
                throw new RuntimeException('Data source not found.', 404, $exception);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $source */
    private function applyStoredSourceIdentity($query, array $source): void
    {
        $sourceId = (int)($source['id'] ?? 0);
        if ($sourceId <= 0) {
            throw new RuntimeException('Data source identity is missing.', 422);
        }
        [$tenantId, $hotelId] = $this->assertStoredSourceTenant($source);
        $query->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId);
    }

    /** @param array<string, mixed> $source */
    private function applyTaskSourceIdentity($query, array $source, ?int $tenantId = null, ?int $hotelId = null): void
    {
        $sourceId = (int)($source['id'] ?? 0);
        if ($sourceId <= 0) {
            throw new RuntimeException('Data source identity is missing.', 422);
        }
        if ($tenantId === null || $hotelId === null) {
            [$tenantId, $hotelId] = $this->assertStoredSourceTenant($source);
        }
        $query->where('data_source_id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId);
    }

    private function logSync(int $taskId, array $source, string $level, string $event, string $message, array $context = []): void
    {
        $adapterStatus = (string)($context['sync_diagnostics']['adapter_status'] ?? '');
        $message = $this->safeSyncTaskMessage($adapterStatus, $message);
        $context = $this->sanitizeSyncTaskStats($context, $adapterStatus);
        $data = [
            'sync_task_id' => $taskId,
            'data_source_id' => (int)($source['id'] ?? 0) ?: null,
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0) ?: null,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'create_time' => date('Y-m-d H:i:s'),
        ];
        if (isset($this->tableColumns('platform_data_sync_logs')['tenant_id'])) {
            $data['tenant_id'] = (int)($source['tenant_id'] ?? 0) ?: null;
        }

        Db::name('platform_data_sync_logs')->insert($data);
    }
}
