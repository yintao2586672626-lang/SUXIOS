<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Cache;

final class MeituanRankCandidateService
{
    private const TTL_SECONDS = 300;
    private const CANDIDATE_ID_PATTERN = '/^[a-f0-9]{32}$/D';

    private Closure $reader;
    private Closure $writer;
    private Closure $deleter;
    private Closure $clock;
    private Closure $idGenerator;

    public function __construct(
        ?callable $reader = null,
        ?callable $writer = null,
        ?callable $deleter = null,
        ?callable $clock = null,
        ?callable $idGenerator = null
    ) {
        $this->reader = Closure::fromCallable($reader ?? static fn(string $key): mixed => Cache::get($key));
        $this->writer = Closure::fromCallable($writer ?? static fn(string $key, array $value, int $ttl): bool => Cache::set($key, $value, $ttl));
        $this->deleter = Closure::fromCallable($deleter ?? static fn(string $key): bool => Cache::delete($key));
        $this->clock = Closure::fromCallable($clock ?? static fn(): int => time());
        $this->idGenerator = Closure::fromCallable($idGenerator ?? static fn(): string => bin2hex(random_bytes(16)));
    }

    /**
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $payload
     * @return array{candidate_id:string,expires_in:int,value_mode:string}
     */
    public function issue(array $binding, array $payload): array
    {
        $binding = $this->normalizeBinding($binding);
        $validation = $this->validatePayload($binding, $payload);

        $candidateId = strtolower(trim((string)($this->idGenerator)()));
        if (preg_match(self::CANDIDATE_ID_PATTERN, $candidateId) !== 1) {
            throw new RuntimeException('Unable to generate a valid Meituan rank candidate identifier.');
        }

        $stored = [
            'binding' => $binding,
            'payload' => $payload,
            'state' => 'issued',
            'result' => null,
            'expires_at' => ($this->clock)() + self::TTL_SECONDS,
        ];
        if (!(bool)($this->writer)($this->cacheKey($candidateId), $stored, self::TTL_SECONDS)) {
            throw new RuntimeException('Unable to persist Meituan rank candidate.');
        }

        return [
            'candidate_id' => $candidateId,
            'expires_in' => self::TTL_SECONDS,
            'value_mode' => (string)($validation['value_mode'] ?? 'raw'),
        ];
    }

    /**
     * @param array<string, mixed> $binding
     * @return array<string, mixed>|null
     */
    public function beginCommit(string $candidateId, array $binding): ?array
    {
        $candidate = $this->readBoundCandidate($candidateId, $binding);
        if ($candidate === null) {
            return null;
        }
        [$key, $stored] = $candidate;
        $state = (string)($stored['state'] ?? 'issued');
        if ($state === 'committed') {
            $result = is_array($stored['result'] ?? null) ? $stored['result'] : null;
            return $result === null ? null : ['status' => 'committed', 'result' => $result];
        }
        if ($state !== 'issued') {
            return null;
        }

        $payload = is_array($stored['payload'] ?? null) ? $stored['payload'] : null;
        if ($payload === null) {
            return null;
        }
        $stored['state'] = 'committing';
        $stored['commit_started_at'] = ($this->clock)();
        if (!$this->writeStored($key, $stored)) {
            return null;
        }

        return ['status' => 'started', 'payload' => $payload];
    }

    /** @param array<string, mixed> $binding @param array<string, mixed> $result */
    public function completeCommit(string $candidateId, array $binding, array $result): bool
    {
        if ($result === []) {
            return false;
        }
        $candidate = $this->readBoundCandidate($candidateId, $binding);
        if ($candidate === null) {
            return false;
        }
        [$key, $stored] = $candidate;
        if ((string)($stored['state'] ?? '') !== 'committing') {
            return false;
        }
        $stored['state'] = 'committed';
        $stored['result'] = $result;
        $stored['committed_at'] = ($this->clock)();
        unset($stored['payload']);
        return $this->writeStored($key, $stored);
    }

    /** @param array<string, mixed> $binding */
    public function releaseCommit(string $candidateId, array $binding): bool
    {
        $candidate = $this->readBoundCandidate($candidateId, $binding);
        if ($candidate === null) {
            return false;
        }
        [$key, $stored] = $candidate;
        if ((string)($stored['state'] ?? '') !== 'committing') {
            return false;
        }
        $stored['state'] = 'issued';
        unset($stored['commit_started_at']);
        return $this->writeStored($key, $stored);
    }

    /**
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $payload
     * @return array{dimension_count:int,row_count:int,target_poi_matched:bool,value_mode:string,derived_dimension_count:int,self_only_dimension_count:int}
     */
    public function validatePayload(array $binding, array $payload): array
    {
        $binding = $this->normalizeBinding($binding);
        $responseData = is_array($payload['response_data'] ?? null) ? $payload['response_data'] : [];
        if ($responseData === []) {
            throw new InvalidArgumentException('Invalid Meituan rank candidate payload.');
        }

        $rows = MeituanRankDataExtractionService::extractForPersistence($responseData);
        $dimensions = [];
        $targetPoiMatched = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dimension = trim((string)($row['_dimName'] ?? $row['dimension'] ?? ''));
            if ($dimension === '') {
                $dimension = 'unknown';
            }
            $dimensions[$dimension][] = $row;
            $poiId = trim((string)($row['poiId'] ?? $row['poi_id'] ?? $row['shopId'] ?? $row['shop_id'] ?? $row['hotelId'] ?? ''));
            if ($poiId !== '' && hash_equals($binding['poi_id'], $poiId)) {
                $targetPoiMatched = true;
            }
            $rawDate = $row['date'] ?? $row['dataDate'] ?? $row['statDate'] ?? $row['stat_date'] ?? null;
            if ($rawDate !== null && trim((string)$rawDate) !== '') {
                $normalizedDate = $this->normalizeCandidateDate($rawDate);
                if ($normalizedDate === null || $normalizedDate < $binding['start_date'] || $normalizedDate > $binding['end_date']) {
                    throw new InvalidArgumentException('Meituan rank candidate contains an invalid or out-of-range date.');
                }
            }
        }

        if (!$targetPoiMatched) {
            throw new InvalidArgumentException('Meituan rank candidate does not contain the bound target POI.');
        }
        if (count($dimensions) < 2) {
            throw new InvalidArgumentException('Meituan rank candidate is incomplete: fewer than two dimensions returned.');
        }
        if (!$this->hasExpectedRankDimensions($dimensions, $binding['rank_type'])) {
            throw new InvalidArgumentException('Meituan rank candidate is incomplete: expected dimensions were not returned.');
        }

        $isStayOrSales = in_array($binding['rank_type'], ['P_RZ', 'P_XS'], true);
        $isTodayRealtimeStay = $binding['date_range'] === '0' && $binding['rank_type'] === 'P_RZ';
        $requiresAbsoluteRows = $binding['date_range'] === '0' && $binding['rank_type'] === 'P_XS';
        $selfMetricValues = is_array($payload['self_metric_values'] ?? null) ? $payload['self_metric_values'] : [];
        $derivedDimensionCount = 0;
        $selfOnlyDimensionCount = 0;
        foreach ($dimensions as $dimension => $dimensionRows) {
            if ($dimensionRows === []) {
                throw new InvalidArgumentException('Meituan rank candidate is incomplete: empty dimension.');
            }
            if ($requiresAbsoluteRows) {
                foreach ($dimensionRows as $row) {
                    $value = $row['dataValue'] ?? $row['data_value'] ?? null;
                    if ($value === null || $value === '') {
                        throw new InvalidArgumentException('Meituan rank candidate is incomplete: absolute values are missing.');
                    }
                }
                continue;
            }
            if ($isStayOrSales) {
                $missingAbsoluteRows = [];
                $targetPercent = null;
                $targetRowPresent = false;
                $dimensionHasRankOrPercentSignal = false;
                foreach ($dimensionRows as $row) {
                    $value = $row['dataValue'] ?? $row['data_value'] ?? null;
                    $percent = $this->candidateNumber($row['percent'] ?? null);
                    $rank = $this->candidateNumber($row['rank'] ?? $row['ranking'] ?? null);
                    $poiId = trim((string)($row['poiId'] ?? $row['poi_id'] ?? $row['shopId'] ?? $row['shop_id'] ?? $row['hotelId'] ?? ''));
                    if ($poiId !== '' && hash_equals($binding['poi_id'], $poiId)) {
                        $targetRowPresent = true;
                        if ($percent !== null && $percent > 0) {
                            $targetPercent = $percent;
                        }
                    }
                    if (($rank !== null && $rank > 0) || ($percent !== null && $percent >= 0)) {
                        $dimensionHasRankOrPercentSignal = true;
                    }
                    if ($value !== null && $value !== '') {
                        continue;
                    }
                    if (!$isTodayRealtimeStay && ($percent === null || $percent < 0)) {
                        throw new InvalidArgumentException('Meituan rank candidate is incomplete: a percent-only row has no usable percent.');
                    }
                    $missingAbsoluteRows[] = $row;
                }
                if ($missingAbsoluteRows !== []) {
                    $anchorField = $this->selfMetricFieldForRankDimension(
                        $binding['rank_type'],
                        (string)$dimension,
                        $dimensionRows
                    );
                    $anchorValue = $anchorField !== ''
                        ? $this->candidateNumber($selfMetricValues[$anchorField] ?? null)
                        : null;
                    if ($isTodayRealtimeStay) {
                        if ($anchorField === '' || !$targetRowPresent || !$dimensionHasRankOrPercentSignal || $anchorValue === null || $anchorValue <= 0) {
                            throw new InvalidArgumentException('Meituan rank candidate is incomplete: realtime self-only values require a positive self metric anchor and target rank signal.');
                        }
                        $selfOnlyDimensionCount++;
                        continue;
                    }
                    if ($anchorField === '' || $targetPercent === null || $targetPercent <= 0 || $anchorValue === null || $anchorValue <= 0) {
                        throw new InvalidArgumentException('Meituan rank candidate is incomplete: percent-only values require a positive self metric anchor and self percent.');
                    }
                    $derivedDimensionCount++;
                }
                continue;
            }
            $hasSignal = false;
            foreach ($dimensionRows as $row) {
                $value = $row['dataValue'] ?? $row['data_value'] ?? null;
                $percent = $row['percent'] ?? null;
                if (($value !== null && $value !== '') || (is_numeric($percent) && (float)$percent > 0)) {
                    $hasSignal = true;
                    break;
                }
            }
            if (!$hasSignal) {
                throw new InvalidArgumentException('Meituan rank candidate is incomplete: a dimension has no data signal.');
            }
        }

        return [
            'dimension_count' => count($dimensions),
            'row_count' => count($rows),
            'target_poi_matched' => true,
            'value_mode' => $selfOnlyDimensionCount > 0
                ? 'self_only'
                : ($derivedDimensionCount > 0 ? 'derived' : 'raw'),
            'derived_dimension_count' => $derivedDimensionCount,
            'self_only_dimension_count' => $selfOnlyDimensionCount,
        ];
    }

    private function candidateNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = is_string($value)
            ? str_replace([',', '%', ' '], '', trim($value))
            : $value;
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function selfMetricFieldForRankDimension(string $rankType, string $dimension, array $rows): string
    {
        $metricName = '';
        foreach ($rows as $row) {
            $candidate = trim((string)($row['_aiMetricName'] ?? $row['aiMetricName'] ?? ''));
            if ($candidate !== '') {
                $metricName = $candidate;
                break;
            }
        }
        $label = $dimension . '|' . strtoupper($metricName);
        if ($rankType === 'P_RZ') {
            if (preg_match('/入住.*间夜|P_RZ.*(NIGHT|ROOM_COUNT)/iu', $label) === 1) {
                return 'roomNights';
            }
            if (preg_match('/房费.*收入|P_RZ.*(REVENUE|AMOUNT|AMT|ROOM_PAY)/iu', $label) === 1) {
                return 'roomRevenue';
            }
        }
        if ($rankType === 'P_XS') {
            if (preg_match('/销售.*间夜|P_XS.*(NIGHT|ROOM_COUNT)/iu', $label) === 1) {
                return 'salesRoomNights';
            }
            if (preg_match('/销售额|P_XS.*(REVENUE|AMOUNT|AMT|ROOM_PAY)/iu', $label) === 1) {
                return 'sales';
            }
        }
        return '';
    }

    /** @param array<string, mixed> $binding */
    private function normalizeBinding(array $binding): array
    {
        $normalized = [
            'actor_id' => (int)($binding['actor_id'] ?? 0),
            'config_id' => trim((string)($binding['config_id'] ?? '')),
            'system_hotel_id' => (int)($binding['system_hotel_id'] ?? 0),
            'poi_id' => trim((string)($binding['poi_id'] ?? '')),
            'start_date' => trim((string)($binding['start_date'] ?? '')),
            'end_date' => trim((string)($binding['end_date'] ?? '')),
            'date_range' => trim((string)($binding['date_range'] ?? '')),
            'rank_type' => strtoupper(trim((string)($binding['rank_type'] ?? ''))),
        ];
        if ($normalized['actor_id'] <= 0
            || $normalized['system_hotel_id'] <= 0
            || $normalized['config_id'] === ''
            || strlen($normalized['config_id']) > 128
            || $normalized['poi_id'] === ''
            || strlen($normalized['poi_id']) > 128
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $normalized['start_date']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $normalized['end_date']) !== 1
            || $normalized['start_date'] > $normalized['end_date']
            || !in_array($normalized['date_range'], ['0', '1', '7', '30', 'custom'], true)
            || !in_array($normalized['rank_type'], ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'], true)
        ) {
            throw new InvalidArgumentException('Invalid Meituan rank candidate binding.');
        }

        return $normalized;
    }

    /** @param array<string, mixed> $binding */
    private function bindingDigest(array $binding): string
    {
        return hash('sha256', json_encode($binding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function cacheKey(string $candidateId): string
    {
        return 'meituan_rank_candidate_' . $candidateId;
    }

    /** @param array<string, mixed> $binding @return array{0:string,1:array<string,mixed>}|null */
    private function readBoundCandidate(string $candidateId, array $binding): ?array
    {
        $candidateId = strtolower(trim($candidateId));
        if (preg_match(self::CANDIDATE_ID_PATTERN, $candidateId) !== 1) {
            return null;
        }
        try {
            $binding = $this->normalizeBinding($binding);
        } catch (InvalidArgumentException) {
            return null;
        }

        $key = $this->cacheKey($candidateId);
        $stored = ($this->reader)($key);
        if (!is_array($stored)) {
            return null;
        }
        if ((int)($stored['expires_at'] ?? 0) < ($this->clock)()) {
            ($this->deleter)($key);
            return null;
        }
        $storedBinding = is_array($stored['binding'] ?? null) ? $stored['binding'] : [];
        if (!hash_equals($this->bindingDigest($storedBinding), $this->bindingDigest($binding))) {
            return null;
        }
        return [$key, $stored];
    }

    /** @param array<string, mixed> $stored */
    private function writeStored(string $key, array $stored): bool
    {
        $ttl = max(1, (int)($stored['expires_at'] ?? 0) - ($this->clock)());
        return (bool)($this->writer)($key, $stored, $ttl);
    }

    private function normalizeCandidateDate(mixed $value): ?string
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{10,13}$/D', trim($value)) === 1)) {
            $timestamp = (int)$value;
            if ($timestamp > 9_999_999_999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return $timestamp > 0 ? date('Y-m-d', $timestamp) : null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        foreach (['Y-m-d', 'Y-m-d H:i:s', 'Y/m/d', 'Y/m/d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    /** @param array<string, array<int, array<string, mixed>>> $dimensions */
    private function hasExpectedRankDimensions(array $dimensions, string $rankType): bool
    {
        $labels = [];
        foreach ($dimensions as $dimension => $rows) {
            $aiMetricName = '';
            foreach ($rows as $row) {
                $candidate = trim((string)($row['_aiMetricName'] ?? $row['aiMetricName'] ?? ''));
                if ($candidate !== '') {
                    $aiMetricName = $candidate;
                    break;
                }
            }
            $labels[] = $dimension . '|' . $aiMetricName;
        }
        $requiredPatterns = match ($rankType) {
            'P_RZ' => [
                '/入住.*间夜|P_RZ.*(NIGHT|ROOM)/iu',
                '/房费.*收入|P_RZ.*(REVENUE|AMOUNT|AMT)/iu',
            ],
            'P_XS' => [
                '/销售.*间夜|P_XS.*(NIGHT|ROOM)/iu',
                '/销售额|P_XS.*(REVENUE|AMOUNT|AMT)/iu',
            ],
            'P_ZH' => [
                '/浏览.*转化|P_ZH.*(VIEW|BROWSE)/iu',
                '/支付.*转化|P_ZH.*(PAY|PAYMENT)/iu',
            ],
            'P_LL' => [
                '/曝光|P_LL.*(EXPOSURE|IMPRESSION)/iu',
                '/浏览|P_LL.*(VIEW|VISIT|BROWSE)/iu',
            ],
            default => [],
        };
        foreach ($requiredPatterns as $pattern) {
            $matched = false;
            foreach ($labels as $label) {
                if (preg_match($pattern, $label) === 1) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return $requiredPatterns !== [];
    }
}
