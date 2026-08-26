<?php
declare(strict_types=1);

namespace app\service;

/**
 * Compatibility boundary for the removed generic OTA proxy.
 *
 * Credentialed OTA reads must use a named collector whose host, path, method,
 * request schema, hotel scope and readback contract are fixed server-side.
 * Keeping this inert service gives legacy callers a stable failure without
 * retaining any generic outbound-network capability.
 */
final class OtaCustomRequestService
{
    public const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    public const DISABLED_ERROR_CODE = 'custom_request_disabled';

    /**
     * The legacy constructor shape is retained temporarily so an old caller
     * cannot turn a deployment mismatch into an outbound request.
     *
     * @param null|callable(array<string,mixed>,string,array<int,string>,string,int):array<string,mixed> $transport
     */
    public function __construct(?OutboundUrlGuard $urlGuard = null, ?callable $transport = null)
    {
    }

    /** @return array<string,mixed> */
    public function request(string $url, string $method, string $headersText, string $body): array
    {
        return [
            'success' => false,
            'error_code' => self::DISABLED_ERROR_CODE,
            'error' => 'Generic OTA requests are disabled; use a named read-only collector.',
            'status' => 0,
            'response_headers' => '',
        ];
    }

    public static function httpStatusForErrorCode(string $errorCode): int
    {
        return trim($errorCode) === self::DISABLED_ERROR_CODE ? 410 : 500;
    }
}
