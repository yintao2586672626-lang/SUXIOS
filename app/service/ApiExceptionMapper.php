<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use think\exception\HttpException;
use think\facade\Log;
use think\Response;
use Throwable;

/** Stable API error boundary: explicit business 4xx, opaque unknown 5xx. */
final class ApiExceptionMapper
{
    public const INTERNAL_ERROR_CODE = 'internal_error';
    private const BUSINESS_HTTP_STATUSES = [400, 401, 403, 404, 405, 409, 422, 429];

    /**
     * @param array<string,int> $businessMessages Exact message => HTTP status allowlist.
     */
    public static function response(
        Throwable $exception,
        string $fallback,
        array $businessMessages = []
    ): Response {
        $mapped = self::map($exception, $fallback, $businessMessages);
        $response = json([
            'code' => $mapped['status'],
            'message' => $mapped['message'],
            'data' => $mapped['data'],
            'time' => time(),
        ], $mapped['status']);
        if (isset($mapped['data']['correlation_id'])) {
            $response->header(['X-Correlation-ID' => (string)$mapped['data']['correlation_id']]);
        }
        return $response;
    }

    /**
     * @param array<string,int> $businessMessages Exact message => HTTP status allowlist.
     * @return array{status:int,message:string,data:array<string,mixed>}
     */
    public static function map(
        Throwable $exception,
        string $fallback,
        array $businessMessages = []
    ): array {
        $message = trim($exception->getMessage());
        if ($exception instanceof InvalidArgumentException) {
            return self::business(422, $message !== '' ? $message : '请求参数无效');
        }
        if ($exception instanceof HttpException) {
            $status = (int)$exception->getStatusCode();
            if (in_array($status, self::BUSINESS_HTTP_STATUSES, true)) {
                return self::business($status, $message !== '' ? $message : self::businessMessage($status));
            }
        }
        if ($message !== '' && isset($businessMessages[$message])) {
            $status = (int)$businessMessages[$message];
            if (in_array($status, self::BUSINESS_HTTP_STATUSES, true)) {
                return self::business($status, $message);
            }
        }

        $fallback = trim($fallback);
        $correlationId = self::correlationId();
        self::recordInternalException($exception, $correlationId);
        return [
            'status' => 500,
            'message' => $fallback !== '' ? mb_substr($fallback, 0, 160) : '服务暂时不可用',
            'data' => [
                'error_code' => self::INTERNAL_ERROR_CODE,
                'reason' => self::INTERNAL_ERROR_CODE,
                'correlation_id' => $correlationId,
            ],
        ];
    }

    /** @return array{status:int,message:string,data:array<string,mixed>} */
    private static function business(int $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => mb_substr($message, 0, 300),
            'data' => ['error_code' => 'business_error'],
        ];
    }

    private static function businessMessage(int $status): string
    {
        return [
            400 => '请求无效',
            401 => '未认证',
            403 => '无权访问',
            404 => '记录不存在',
            405 => '请求方法不允许',
            409 => '请求冲突',
            422 => '请求参数无效',
            429 => '请求过于频繁',
        ][$status] ?? '请求失败';
    }

    private static function correlationId(): string
    {
        try {
            return 'err_' . bin2hex(random_bytes(12));
        } catch (Throwable) {
            return 'err_' . substr(hash('sha256', uniqid('', true)), 0, 24);
        }
    }

    private static function recordInternalException(Throwable $exception, string $correlationId): void
    {
        try {
            // Keep diagnostics correlatable without persisting raw exception
            // messages, SQL text, request payloads, credentials, or full paths.
            Log::error('Opaque API internal error.', [
                'correlation_id' => $correlationId,
                'exception_type' => get_debug_type($exception),
                'exception_code' => is_int($exception->getCode()) ? $exception->getCode() : 0,
                'message_digest' => hash('sha256', (string)$exception->getMessage()),
                'source_file' => basename((string)$exception->getFile()),
                'source_line' => max(0, (int)$exception->getLine()),
            ]);
        } catch (Throwable) {
            // Error mapping must remain available even when the logger itself
            // is unavailable; the opaque correlation ID is still returned.
        }
    }
}
