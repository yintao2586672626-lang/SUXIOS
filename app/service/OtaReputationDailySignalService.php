<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use think\facade\Db;

/**
 * Projects only exact-date, exact-hotel, readback-verified OTA reputation
 * aggregates into deterministic operating signals. It never reads review text,
 * infers reviewer identity, predicts a score, or performs an external write.
 */
final class OtaReputationDailySignalService
{
    public const CONTRACT_VERSION = 'ota_reputation_daily_signal.v1';

    /** @var Closure(int,int,string,string):array<int,array<string,mixed>> */
    private Closure $rowReader;

    public function __construct(?callable $rowReader = null)
    {
        $this->rowReader = $rowReader !== null
            ? Closure::fromCallable($rowReader)
            : static fn(int $tenantId, int $hotelId, string $startDate, string $endDate): array =>
                Db::name('online_daily_data')
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('data_date', '>=', $startDate)
                    ->where('data_date', '<=', $endDate)
                    ->whereIn('data_type', ['review', 'reviews', 'comment', 'comments'])
                    ->order('data_date', 'desc')
                    ->order('id', 'desc')
                    ->select()
                    ->toArray();
    }

    /** @return array<string,mixed> */
    public function build(int $tenantId, int $hotelId, string $businessDate): array
    {
        $date = $this->date($businessDate);
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('口碑信号缺少租户或酒店身份');
        }
        $previousDate = $date->modify('-1 day')->format('Y-m-d');
        $rows = ($this->rowReader)($tenantId, $hotelId, $previousDate, $businessDate);
        $projected = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || (int)($row['tenant_id'] ?? 0) !== $tenantId
                || (int)($row['system_hotel_id'] ?? 0) !== $hotelId
                || !in_array((string)($row['data_date'] ?? ''), [$previousDate, $businessDate], true)
                || !in_array(strtolower(trim((string)($row['data_type'] ?? ''))), ['review', 'reviews', 'comment', 'comments'], true)
            ) {
                continue;
            }
            $source = $this->source((string)($row['source'] ?? $row['platform'] ?? ''));
            if (!in_array($source, ['ctrip', 'meituan'], true) || !$this->trustworthy($row)) {
                continue;
            }
            $snapshot = $this->project($row, $source);
            if ($snapshot !== null) {
                $projected[] = $snapshot;
            }
        }

        $signals = [];
        $platforms = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $current = $this->selectSnapshot($projected, $platform, $businessDate);
            $previous = $this->selectSnapshot($projected, $platform, $previousDate);
            $platforms[$platform] = [
                'status' => $current === null ? 'no_current_strict_fact' : 'strict_fact_available',
                'current_record_ref' => $current['record_ref'] ?? null,
                'previous_record_ref' => $previous['record_ref'] ?? null,
            ];
            if ($current !== null) {
                array_push($signals, ...$this->signals($current, $previous, $tenantId, $hotelId, $businessDate));
            }
        }

        $stable = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'previous_business_date' => $previousDate,
            'signal_identities' => array_map(static fn(array $signal): array => [
                'signal_key' => $signal['signal_key'],
                'platform' => $signal['platform'],
                'metric_key' => $signal['metric_key'],
                'current_value' => $signal['current_value'],
                'previous_value' => $signal['previous_value'],
                'record_ref' => $signal['record_ref'],
                'previous_record_ref' => $signal['previous_record_ref'],
            ], $signals),
        ];

        return $stable + [
            'status' => $signals === [] ? 'no_actionable_signal' : 'actionable_signals_available',
            'signals' => $signals,
            'platforms' => $platforms,
            'source_digest' => hash('sha256', $this->canonicalJson($stable)),
            'boundary' => [
                'metric_scope' => 'ota_channel_reputation',
                'review_text_read' => false,
                'reviewer_identity_inferred' => false,
                'prediction_performed' => false,
                'automatic_reply' => false,
                'automatic_appeal' => false,
                'external_write_count' => 0,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function signals(array $current, ?array $previous, int $tenantId, int $hotelId, string $businessDate): array
    {
        $signals = [];
        $common = [
            'platform' => $current['platform'],
            'business_date' => $businessDate,
            'record_id' => $current['record_id'],
            'record_ref' => $current['record_ref'],
            'previous_record_ref' => $previous['record_ref'] ?? '',
            'fact_refs' => array_values(array_filter([$current['record_ref'], $previous['record_ref'] ?? null])),
            'source_method' => $current['source_method'],
            'collected_at' => $current['collected_at'],
        ];

        if (is_numeric($current['unreplied_count'] ?? null) && (int)$current['unreplied_count'] > 0) {
            $signals[] = $this->signal($common, [
                'kind' => 'unreplied_reviews',
                'metric_key' => 'comment_unreply_count',
                'metric_label' => '未回复点评数',
                'unit' => 'reviews',
                'current_value' => (int)$current['unreplied_count'],
                'previous_value' => $previous['unreplied_count'] ?? null,
                'problem' => sprintf('%s有 %d 条已验证的未回复点评需要人工核对', $current['platform_label'], (int)$current['unreplied_count']),
                'action_title' => '核对并人工处理未回复点评',
                'ranking' => ['impact' => 82, 'urgency' => 96, 'evidence_strength' => 100, 'execution_cost' => 20],
            ], $tenantId, $hotelId, $businessDate);
        }

        if ($previous !== null
            && is_numeric($current['bad_review_count'] ?? null)
            && is_numeric($previous['bad_review_count'] ?? null)
            && (int)$current['bad_review_count'] > (int)$previous['bad_review_count']
        ) {
            $delta = (int)$current['bad_review_count'] - (int)$previous['bad_review_count'];
            $signals[] = $this->signal($common, [
                'kind' => 'bad_reviews_increased',
                'metric_key' => 'bad_review_count',
                'metric_label' => '低分/差评累计数',
                'unit' => 'reviews',
                'current_value' => (int)$current['bad_review_count'],
                'previous_value' => (int)$previous['bad_review_count'],
                'problem' => sprintf('%s低分/差评累计数较前一业务日增加 %d 条', $current['platform_label'], $delta),
                'action_title' => '核对新增低分/差评证据',
                'ranking' => ['impact' => 90, 'urgency' => 94, 'evidence_strength' => 100, 'execution_cost' => 24],
            ], $tenantId, $hotelId, $businessDate);
        }

        if ($previous !== null
            && is_numeric($current['score'] ?? null)
            && is_numeric($previous['score'] ?? null)
            && (float)$current['score'] < (float)$previous['score']
        ) {
            $signals[] = $this->signal($common, [
                'kind' => 'score_declined',
                'metric_key' => 'comment_score',
                'metric_label' => '平台点评原始分',
                'unit' => 'platform_score',
                'current_value' => (float)$current['score'],
                'previous_value' => (float)$previous['score'],
                'problem' => sprintf('%s点评原始分由 %.2f 降至 %.2f', $current['platform_label'], (float)$previous['score'], (float)$current['score']),
                'action_title' => '核对评分下降对应的点评事实',
                'ranking' => ['impact' => 84, 'urgency' => 82, 'evidence_strength' => 100, 'execution_cost' => 24],
            ], $tenantId, $hotelId, $businessDate);
        }

        return $signals;
    }

    /** @return array<string,mixed> */
    private function signal(array $common, array $specific, int $tenantId, int $hotelId, string $businessDate): array
    {
        $signal = $common + $specific + [
            'signal_key' => sprintf('signal:%s:reputation:%s', $common['platform'], $specific['kind']),
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'metric_scope' => 'ota_channel_reputation',
        ];
        $signal['snapshot_digest'] = hash('sha256', $this->canonicalJson([
            'contract_version' => self::CONTRACT_VERSION,
            'signal_key' => $signal['signal_key'],
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'platform' => $signal['platform'],
            'metric_key' => $signal['metric_key'],
            'current_value' => $signal['current_value'],
            'previous_value' => $signal['previous_value'],
            'fact_refs' => $signal['fact_refs'],
        ]));
        return $signal;
    }

    /** @param list<array<string,mixed>> $snapshots */
    private function selectSnapshot(array $snapshots, string $platform, string $dataDate): ?array
    {
        $matches = array_values(array_filter($snapshots, static fn(array $snapshot): bool =>
            ($snapshot['platform'] ?? '') === $platform && ($snapshot['data_date'] ?? '') === $dataDate
        ));
        usort($matches, static function (array $left, array $right): int {
            $primaryOrder = (int)($right['primary_channel'] ?? false) <=> (int)($left['primary_channel'] ?? false);
            return $primaryOrder !== 0 ? $primaryOrder : ((int)$right['record_id'] <=> (int)$left['record_id']);
        });
        return $matches[0] ?? null;
    }

    /** @return ?array<string,mixed> */
    private function project(array $row, string $source): ?array
    {
        $raw = $this->raw((string)($row['raw_data'] ?? ''));
        $channel = trim((string)($raw['comment_channel'] ?? $raw['channelName'] ?? $raw['channel'] ?? ''));
        $primaryChannel = $channel === '' || ($source === 'ctrip'
            ? in_array(mb_strtolower($channel, 'UTF-8'), ['ctrip', '携程'], true)
            : in_array(mb_strtolower($channel, 'UTF-8'), ['meituan', '美团', '点评聚合'], true));
        if (!$primaryChannel) {
            return null;
        }
        $score = $this->number($row, $raw, ['comment_score', 'commentScore', 'score', 'rating']);
        $badReviewCount = $this->number($row, $raw, ['bad_review_count', 'badReviewCount', 'negativeCount', 'noRecommendCount']);
        $unrepliedCount = $this->number($row, $raw, ['comment_unreply_count', 'unReplyCount', 'unrepliedCount']);
        if ($score === null && $badReviewCount === null && $unrepliedCount === null) {
            return null;
        }
        return [
            'platform' => $source,
            'platform_label' => $source === 'ctrip' ? '携程' : '美团',
            'primary_channel' => $primaryChannel,
            'record_id' => (int)$row['id'],
            'record_ref' => 'online_daily_data#' . (int)$row['id'],
            'data_date' => (string)$row['data_date'],
            'score' => $score !== null && $score > 0 ? round($score, 4) : null,
            'bad_review_count' => $badReviewCount !== null ? max(0, (int)round($badReviewCount)) : null,
            'unreplied_count' => $unrepliedCount !== null ? max(0, (int)round($unrepliedCount)) : null,
            'source_method' => trim((string)($row['ingestion_method'] ?? $raw['acquisition_method'] ?? '')),
            'collected_at' => trim((string)($row['snapshot_time'] ?? $row['update_time'] ?? $row['create_time'] ?? '')),
        ];
    }

    private function trustworthy(array $row): bool
    {
        if (!in_array($row['readback_verified'] ?? null, [1, '1', true], true)) {
            return false;
        }
        if (trim((string)($row['hotel_id'] ?? '')) === ''
            || trim((string)($row['source_trace_id'] ?? '')) === ''
            || trim((string)($row['ingestion_method'] ?? '')) === ''
        ) {
            return false;
        }
        $status = strtolower(trim((string)($row['validation_status'] ?? '')));
        return in_array($status, ['normal', 'valid', 'verified'], true);
    }

    /** @return array<string,mixed> */
    private function raw(string $json): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            return [];
        }
        if (is_array($raw['row'] ?? null)) {
            $raw = array_merge($raw, $raw['row']);
        }
        foreach (['metrics', 'dimension_values'] as $key) {
            if (is_array($raw[$key] ?? null)) {
                $raw = array_merge($raw, $raw[$key]);
            }
        }
        return $raw;
    }

    private function number(array $row, array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            foreach ([$raw, $row] as $source) {
                if (!array_key_exists($key, $source) || !is_numeric($source[$key])) {
                    continue;
                }
                return (float)$source[$key];
            }
        }
        return null;
    }

    private function source(string $value): string
    {
        return match (strtolower(trim($value))) {
            'ctrip', 'trip', 'xc' => 'ctrip',
            'meituan', 'mt' => 'meituan',
            default => '',
        };
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d') !== trim($value)
        ) {
            throw new InvalidArgumentException('口碑信号业务日期无效');
        }
        return $date;
    }

    private function canonicalJson(mixed $value): string
    {
        return (string)json_encode(
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
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
