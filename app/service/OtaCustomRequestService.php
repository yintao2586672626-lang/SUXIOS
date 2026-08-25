<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class OtaCustomRequestService
{
    public const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    private const MAX_REQUEST_BYTES = 2 * 1024 * 1024;
    private const MAX_HEADER_BYTES = 32 * 1024;
    private const ALLOWED_METHODS = ['GET', 'POST'];
    private const ALLOWED_HOST_SUFFIXES = [
        'ctrip.com',
        'ctripbiz.com',
        'ctripbiz.cn',
        'meituan.com',
    ];
    private const FORBIDDEN_REQUEST_HEADERS = [
        'connection',
        'content-length',
        'host',
        'proxy-connection',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    private OutboundUrlGuard $urlGuard;

    /** @var null|callable(array<string,mixed>,string,array<int,string>,string,int):array<string,mixed> */
    private $transport;

    public function __construct(?OutboundUrlGuard $urlGuard = null, ?callable $transport = null)
    {
        $this->urlGuard = $urlGuard ?? new OutboundUrlGuard();
        $this->transport = $transport;
    }

    /** @return array<string,mixed> */
    public function request(string $url, string $method, string $headersText, string $body): array
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return $this->failure('method_not_allowed', 'Only GET and POST custom OTA requests are allowed.');
        }
        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            return $this->failure('request_too_large', 'Custom OTA request body exceeds the size limit.');
        }

        try {
            $target = $this->urlGuard->validate($url);
        } catch (InvalidArgumentException) {
            return $this->failure('url_not_allowed', 'Custom OTA request URL is not allowed.');
        }
        if (!$this->isAllowedOtaHost((string)($target['host'] ?? ''))) {
            return $this->failure('url_not_allowed', 'Custom OTA request URL is not allowed.');
        }

        try {
            $headers = $this->parseRequestHeaders($headersText);
        } catch (RuntimeException $exception) {
            return $this->failure('headers_not_allowed', $exception->getMessage());
        }

        try {
            $response = $this->transport !== null
                ? ($this->transport)($target, $method, $headers, $body, self::MAX_RESPONSE_BYTES)
                : $this->executeCurl($target, $method, $headers, $body);
        } catch (Throwable) {
            return $this->failure('request_failed', 'Custom OTA request failed.');
        }
        if (!is_array($response)) {
            return $this->failure('request_failed', 'Custom OTA request failed.');
        }

        $status = max(0, (int)($response['status'] ?? 0));
        $responseHeaders = $this->normalizeResponseHeaders($response['response_headers'] ?? []);
        if (($response['success'] ?? false) !== true) {
            $errorCode = trim((string)($response['error_code'] ?? 'request_failed'));
            $error = trim((string)($response['error'] ?? 'Custom OTA request failed.'));
            return $this->failure(
                $errorCode !== '' ? $errorCode : 'request_failed',
                $error !== '' ? $error : 'Custom OTA request failed.',
                $status,
                $responseHeaders
            );
        }
        if ($status >= 300 && $status < 400) {
            return $this->failure(
                'redirect_not_allowed',
                'Custom OTA request redirects are not allowed.',
                $status,
                $responseHeaders
            );
        }
        if ($status < 200 || $status >= 300) {
            return $this->failure(
                'upstream_http_error',
                'Custom OTA upstream returned a non-success HTTP status.',
                $status,
                $responseHeaders
            );
        }

        $raw = (string)($response['body'] ?? '');
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            return $this->failure(
                'response_too_large',
                'Custom OTA response exceeds the size limit.',
                $status,
                $responseHeaders
            );
        }

        return [
            'success' => true,
            'data' => json_decode($raw, true),
            'raw' => $raw,
            'status' => $status,
            'response_headers' => $responseHeaders,
        ];
    }

    private function isAllowedOtaHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,string> */
    private function parseRequestHeaders(string $headersText): array
    {
        if (strlen($headersText) > self::MAX_HEADER_BYTES) {
            throw new RuntimeException('Custom OTA request headers exceed the size limit.');
        }

        $headers = [];
        foreach (preg_split('/\r\n|\r|\n/', $headersText) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (count($headers) >= 64
                || preg_match("/^([A-Za-z0-9!#$%&'*+.^_`|~-]{1,100}):[ \\t]*(.*)$/D", $line, $matches) !== 1
            ) {
                throw new RuntimeException('Custom OTA request contains an invalid header.');
            }
            $name = strtolower($matches[1]);
            $value = $matches[2];
            if (in_array($name, self::FORBIDDEN_REQUEST_HEADERS, true)
                || strlen($value) > 8192
                || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1
            ) {
                throw new RuntimeException('Custom OTA request contains a forbidden header.');
            }
            $headers[] = $matches[1] . ': ' . $value;
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $target
     * @param array<int,string> $headers
     * @return array<string,mixed>
     */
    private function executeCurl(array $target, string $method, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            return $this->failure('curl_unavailable', 'PHP cURL extension is not enabled.');
        }

        $curl = curl_init((string)$target['url']);
        if ($curl === false) {
            return $this->failure('request_failed', 'Custom OTA request failed.');
        }

        $responseBody = '';
        $responseHeaders = [];
        $responseHeaderBytes = 0;
        $responseTooLarge = false;
        $responseHeadersTooLarge = false;
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => $target['curl_resolve'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (
                &$responseHeaders,
                &$responseHeaderBytes,
                &$responseHeadersTooLarge
            ): int {
                $length = strlen($line);
                $responseHeaderBytes += $length;
                if ($responseHeaderBytes > self::MAX_HEADER_BYTES) {
                    $responseHeadersTooLarge = true;
                    return 0;
                }
                $line = trim($line);
                if ($line !== '') {
                    $responseHeaders[] = $line;
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (
                &$responseBody,
                &$responseTooLarge
            ): int {
                $length = strlen($chunk);
                if (strlen($responseBody) + $length > self::MAX_RESPONSE_BYTES) {
                    $responseTooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return $length;
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($curl, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $executed = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($responseTooLarge) {
            return $this->failure('response_too_large', 'Custom OTA response exceeds the size limit.', $status, $responseHeaders);
        }
        if ($responseHeadersTooLarge) {
            return $this->failure('response_headers_too_large', 'Custom OTA response headers exceed the size limit.', $status);
        }
        if ($executed !== true) {
            return $this->failure('request_failed', 'Custom OTA request failed.', $status, $responseHeaders);
        }

        return [
            'success' => true,
            'status' => $status,
            'response_headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }

    private function normalizeResponseHeaders(mixed $headers): string
    {
        if (is_array($headers)) {
            return implode("\r\n", array_map('strval', $headers));
        }
        return is_scalar($headers) ? (string)$headers : '';
    }

    /** @return array<string,mixed> */
    private function failure(
        string $errorCode,
        string $error,
        int $status = 0,
        mixed $responseHeaders = ''
    ): array {
        return [
            'success' => false,
            'error_code' => $errorCode,
            'error' => $error,
            'status' => max(0, $status),
            'response_headers' => $this->normalizeResponseHeaders($responseHeaders),
        ];
    }
}
