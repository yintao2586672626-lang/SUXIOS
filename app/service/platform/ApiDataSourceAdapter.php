<?php
declare(strict_types=1);

namespace app\service\platform;

use app\contract\DataSourceAdapter;

final class ApiDataSourceAdapter implements DataSourceAdapter
{
    public function supports(array $source): bool
    {
        return (string)($source['ingestion_method'] ?? '') === 'api';
    }

    public function fetch(array $source, array $options = []): array
    {
        $config = $source['config'] ?? [];
        $secret = $source['secret'] ?? [];
        $url = trim((string)($options['url'] ?? $config['url'] ?? $config['request_url'] ?? ''));
        if ($url === '') {
            return [
                'status' => 'waiting_config',
                'message' => 'API URL is not configured.',
                'payload' => [],
            ];
        }

        $validationError = $this->validateUrl($url, $config);
        if ($validationError !== null) {
            return [
                'status' => 'failed',
                'message' => $validationError,
                'payload' => [],
            ];
        }

        $method = strtoupper((string)($options['method'] ?? $config['method'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST'], true)) {
            return [
                'status' => 'failed',
                'message' => 'Only GET and POST API sources are supported.',
                'payload' => [],
            ];
        }
        if (!function_exists('curl_init')) {
            return [
                'status' => 'failed',
                'message' => 'PHP cURL extension is not enabled.',
                'payload' => [],
            ];
        }

        $headers = $this->buildHeaders($config, $secret);
        $body = $options['body'] ?? $config['payload'] ?? $config['payload_json'] ?? null;
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)($body ?? ''));
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            return [
                'status' => 'failed',
                'message' => 'API request failed: ' . $error,
                'payload' => [],
                'http_status' => $statusCode,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [
                'status' => 'failed',
                'message' => 'API response is not valid JSON.',
                'payload' => ['raw' => mb_substr((string)$raw, 0, 1000)],
                'http_status' => $statusCode,
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'status' => 'failed',
                'message' => 'API returned HTTP ' . $statusCode,
                'payload' => $decoded,
                'http_status' => $statusCode,
            ];
        }

        return [
            'status' => 'success',
            'message' => 'API request completed.',
            'payload' => $decoded,
            'http_status' => $statusCode,
        ];
    }

    private function validateUrl(string $url, array $config): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme !== 'https') {
            return 'API source must use HTTPS.';
        }
        if ($host === '' || $this->isPrivateHost($host)) {
            return 'API source host is not allowed.';
        }

        $allowedHosts = $config['allowed_hosts'] ?? [];
        if (is_string($allowedHosts)) {
            $allowedHosts = array_filter(array_map('trim', explode(',', $allowedHosts)));
        }
        if (is_array($allowedHosts) && !empty($allowedHosts)) {
            $allowedHosts = array_map(static fn($item): string => strtolower((string)$item), $allowedHosts);
            if (!in_array($host, $allowedHosts, true)) {
                return 'API source host is outside allowed_hosts.';
            }
        }

        return null;
    }

    private function isPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            return true;
        }
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @return array<int, string>
     */
    private function buildHeaders(array $config, array $secret): array
    {
        $headers = ['Accept: application/json'];
        $configHeaders = $config['headers'] ?? [];
        if (is_string($configHeaders)) {
            $decoded = json_decode($configHeaders, true);
            $configHeaders = is_array($decoded) ? $decoded : [];
        }
        if (is_array($configHeaders)) {
            foreach ($configHeaders as $name => $value) {
                $headers[] = (string)$name . ': ' . (string)$value;
            }
        }
        if (!empty($secret['token'])) {
            $headers[] = 'Authorization: Bearer ' . (string)$secret['token'];
        }
        if (!empty($secret['api_key'])) {
            $headers[] = 'X-API-Key: ' . (string)$secret['api_key'];
        }
        if (!empty($secret['cookies'])) {
            $headers[] = 'Cookie: ' . (string)$secret['cookies'];
        }
        return $headers;
    }
}
