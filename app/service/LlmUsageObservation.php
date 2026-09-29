<?php
declare(strict_types=1);

namespace app\service;

/** Provider receipts only. Model-authored content and character counts are never billing evidence. */
final class LlmUsageObservation
{
    private const TOKEN_FIELDS = ['prompt_tokens', 'completion_tokens', 'total_tokens', 'cached_tokens', 'reasoning_tokens'];

    public static function receipt(mixed $response, bool $dispatched = true): array
    {
        $empty = array_fill_keys(self::TOKEN_FIELDS, null);
        if (!$dispatched) {
            return ['status' => 'not_called'] + $empty;
        }
        $body = is_string($response) ? json_decode($response, true) : null;
        $usage = is_array($body) ? ($body['usage'] ?? null) : null;
        if (!is_array($usage) || $usage === []) {
            return ['status' => 'unavailable'] + $empty;
        }
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
            if (!self::isCount($usage[$field] ?? null)) {
                return ['status' => 'invalid'] + $empty;
            }
        }
        if ($usage['prompt_tokens'] > PHP_INT_MAX - $usage['completion_tokens']
            || $usage['prompt_tokens'] + $usage['completion_tokens'] !== $usage['total_tokens']) {
            return ['status' => 'invalid'] + $empty;
        }
        $cached = $usage['prompt_tokens_details']['cached_tokens'] ?? $usage['prompt_cache_hit_tokens'] ?? null;
        $reasoning = $usage['completion_tokens_details']['reasoning_tokens'] ?? null;
        $cached = self::isCount($cached) && $cached <= $usage['prompt_tokens'] ? $cached : null;
        if (isset($usage['prompt_cache_hit_tokens'], $usage['prompt_tokens_details']['cached_tokens'])
            && $usage['prompt_cache_hit_tokens'] !== $usage['prompt_tokens_details']['cached_tokens']) {
            $cached = null;
        }
        return [
            'status' => 'reported',
            'prompt_tokens' => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'total_tokens' => $usage['total_tokens'],
            'cached_tokens' => $cached,
            'reasoning_tokens' => self::isCount($reasoning) && $reasoning <= $usage['completion_tokens'] ? $reasoning : null,
        ];
    }

    /** One provider's transport attempts, including retries; never a cumulative cross-provider total. */
    public static function summarize(array $receipts, ?int $elapsedMs = null): array
    {
        $sent = array_values(array_filter($receipts, static fn(array $r): bool => $r['status'] !== 'not_called'));
        $reported = array_values(array_filter($sent, static fn(array $r): bool => $r['status'] === 'reported'));
        $allReported = count($sent) > 0 && count($reported) === count($sent);
        $summary = [
            'version' => 'llm_usage.v1',
            'scope' => 'provider_attempt_group',
            'status' => $sent === [] ? 'not_called' : ($allReported ? 'reported' : ($reported === [] ? 'unavailable' : 'partial')),
            'attempt_count' => count($receipts),
            'dispatched_count' => count($sent),
            'reported_count' => count($reported),
            'invalid_count' => count(array_filter($sent, static fn(array $r): bool => $r['status'] === 'invalid')),
            'elapsed_ms' => $elapsedMs,
            'cost_amount' => null,
            'cost_currency' => null,
            'cost_status' => 'pricing_unavailable',
        ];
        foreach (self::TOKEN_FIELDS as $field) {
            $values = array_column($reported, $field);
            $known = array_values(array_filter($values, static fn(mixed $v): bool => $v !== null));
            $sum = array_sum($known);
            $sum = $known !== [] && is_int($sum) ? $sum : null;
            $summary['observed_' . $field] = $sum;
            $summary[$field] = $allReported && count($known) === count($sent) ? $sum : null;
        }
        return $summary;
    }

    public static function legacy(): array
    {
        return array_replace(self::summarize([]), [
            'status' => 'legacy_unknown', 'attempt_count' => null, 'dispatched_count' => null,
            'reported_count' => null, 'invalid_count' => null,
        ]);
    }

    private static function isCount(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }
}
