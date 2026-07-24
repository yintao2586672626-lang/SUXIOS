<?php
declare(strict_types=1);

namespace app\service;

final class OperationAuditSanitizerService
{
    /** @var array<int, string> */
    private const SENSITIVE_KEY_SUFFIXES = [
        'authorization',
        'password',
        'passwd',
        'cookie',
        'cookies',
        'token',
        'apikey',
        'appkey',
        'accesskey',
        'authdata',
        'headers',
        'spidertoken',
        'spiderkey',
        'mtgsig',
        'usersign',
        'usertoken',
        'setcookie',
        'accesstoken',
        'refreshtoken',
        'clientsecret',
        'appsecret',
        'secret',
        'secretjson',
        'credential',
        'credentials',
        'jsessionid',
        'sessionid',
        'sessiontoken',
    ];

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public function sanitizeArray(array $values, int $stringLimit = 120): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            if ($this->isSensitiveKey((string)$key)) {
                if ($this->isNonSecretStatusScalar((string)$key, $value)) {
                    $safe[$key] = $value;
                    continue;
                }
                $safe[$key] = '***';
                continue;
            }
            $safe[$key] = $this->sanitizeValue($value, $stringLimit);
        }

        return $safe;
    }

    public function sanitizeValue(mixed $value, int $stringLimit = 120): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeArray($value, $stringLimit);
        }
        if (is_string($value)) {
            return $this->sanitizeText($value, $stringLimit);
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[object]';
    }

    public function sanitizeText(string $value, int $limit = 120): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $encoded = json_encode(
                    $this->sanitizeArray($decoded, $limit),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if (is_string($encoded)) {
                    $trimmed = $encoded;
                }
            }
        }

        $patterns = [
            '/\bAuthorization\s*:\s*Bearer\s+[^\s,;]+/iu' => 'Authorization=***',
            '/\bBearer\s+[A-Za-z0-9._\-]{8,}/u' => 'Bearer ***',
            '/\b(password|passwd|cookie|cookies|token|session[_-]?id|session[_-]?token|jsessionid|sid|api[_-]?key|access[_-]?key|spider[_-]?token|spider[_-]?key|mtg[_-]?sig|user[_-]?sign|user[_-]?token|set[_-]?cookie|access[_-]?token|refresh[_-]?token|client[_-]?secret|app[_-]?secret|secret)\s*[:=]\s*["\']?[^"\'\s,;}]+/iu' => '$1=***',
            '/([?&](?:password|passwd|cookie|token|session[_-]?id|session[_-]?token|jsessionid|sid|api[_-]?key|authorization|spider[_-]?token|spider[_-]?key|mtg[_-]?sig|user[_-]?sign|user[_-]?token|access[_-]?token|refresh[_-]?token|secret)=)[^&#\s]+/iu' => '$1***',
            '/sk-[A-Za-z0-9_-]{8,}/u' => 'sk-***',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $trimmed = preg_replace($pattern, $replacement, $trimmed) ?? $trimmed;
        }

        return mb_substr($trimmed, 0, max(0, $limit));
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $key));
        if ($normalized === '') {
            return false;
        }
        if ($normalized === 'sid') {
            return true;
        }
        foreach (self::SENSITIVE_KEY_SUFFIXES as $suffix) {
            if ($normalized === $suffix || str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isNonSecretStatusScalar(string $key, mixed $value): bool
    {
        if (!(is_bool($value) || is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return false;
        }

        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $key));
        foreach (['status', 'enabled', 'configured', 'count', 'present', 'valid', 'probe', 'available'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}
