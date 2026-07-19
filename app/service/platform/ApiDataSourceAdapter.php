<?php
declare(strict_types=1);

namespace app\service\platform;

use app\contract\DataSourceAdapter;
use app\service\OutboundUrlGuard;
use InvalidArgumentException;

final class ApiDataSourceAdapter implements DataSourceAdapter
{
    private OutboundUrlGuard $urlGuard;

    public function __construct(?OutboundUrlGuard $urlGuard = null)
    {
        $this->urlGuard = $urlGuard ?? new OutboundUrlGuard();
    }

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

        $validation = $this->validateUrl($url, $config);
        if ($validation['error'] !== null) {
            return [
                'status' => 'failed',
                'message' => $validation['error'],
                'payload' => [],
            ];
        }
        $target = $validation['target'];

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

        $ch = curl_init($target['url']);
        if ($ch === false) {
            return [
                'status' => 'failed',
                'message' => 'API request failed.',
                'payload' => [],
            ];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => $target['curl_resolve'],
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
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
                'message' => 'API request failed.',
                'payload' => [],
                'http_status' => $statusCode,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return [
                'status' => 'failed',
                'message' => 'API response is not valid JSON.',
                'payload' => [],
                'http_status' => $statusCode,
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'status' => 'failed',
                'message' => 'API returned HTTP ' . $statusCode,
                'payload' => [],
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

    /**
     * @return array{error:?string,target:?array}
     */
    private function validateUrl(string $url, array $config): array
    {
        try {
            $target = $this->urlGuard->validate($url);
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage() === OutboundUrlGuard::ERROR_HTTPS_REQUIRED
                ? 'API source must use HTTPS.'
                : 'API source host is not allowed.';
            return ['error' => $message, 'target' => null];
        }
        $host = (string)$target['host'];

        $allowedHosts = $config['allowed_hosts'] ?? [];
        if (is_string($allowedHosts)) {
            $allowedHosts = array_filter(array_map('trim', explode(',', $allowedHosts)));
        }
        if (is_array($allowedHosts) && !empty($allowedHosts)) {
            $allowedHosts = array_map(
                static fn($item): string => strtolower(rtrim(trim((string)$item), '.')),
                $allowedHosts
            );
            if (!in_array($host, $allowedHosts, true)) {
                return ['error' => 'API source host is outside allowed_hosts.', 'target' => null];
            }
        }

        return ['error' => null, 'target' => $target];
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
        $authorization = $secret['authorization'] ?? $secret['authorization_header'] ?? null;
        if (is_scalar($authorization) && trim((string)$authorization) !== '') {
            $headers[] = 'Authorization: ' . $this->validatedHeaderValue($authorization);
        } elseif (!empty($secret['token'])) {
            $headers[] = 'Authorization: Bearer ' . $this->validatedHeaderValue($secret['token']);
        }
        if (!empty($secret['api_key'])) {
            $headers[] = 'X-API-Key: ' . $this->validatedHeaderValue($secret['api_key']);
        }
        if (!empty($secret['cookies'])) {
            $headers[] = 'Cookie: ' . $this->validatedHeaderValue($secret['cookies']);
        }
        $secretHeaders = $secret['headers'] ?? [];
        if (is_string($secretHeaders)) {
            $decoded = json_decode($secretHeaders, true);
            $secretHeaders = is_array($decoded) ? $decoded : [];
        }
        if (is_array($secretHeaders)) {
            foreach ($secretHeaders as $name => $value) {
                $name = trim((string)$name);
                if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,100}$/D', $name) !== 1 || !is_scalar($value)) {
                    throw new \RuntimeException('Stored OTA credential contains an invalid header entry.');
                }
                $headers[] = $name . ': ' . $this->validatedHeaderValue($value);
            }
        }
        return $headers;
    }

    private function validatedHeaderValue(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '' || preg_match('/[\r\n]/', $value) === 1) {
            throw new \RuntimeException('Stored OTA credential contains an invalid header value.');
        }
        return $value;
    }
}
