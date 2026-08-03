<?php
declare(strict_types=1);

namespace app\service;

/**
 * Canonical SHA-256 authority for persisted knowledge content.
 *
 * Formal knowledge digests are compared on every read and before an operation
 * intent is approved or executed. Keeping the canonicalization in one service
 * prevents the writer and runtime gate from silently hashing different JSON.
 */
final class KnowledgeContentDigestService
{
    public function digest(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        ));
    }

    public function isValid(string $digest): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($digest))) === 1;
    }

    public function matches(string $storedDigest, mixed $value): bool
    {
        $storedDigest = strtolower(trim($storedDigest));
        return $this->isValid($storedDigest)
            && hash_equals($storedDigest, $this->digest($value));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
