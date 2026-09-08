<?php
declare(strict_types=1);

namespace app\service;

final class OtaUpstreamFailureService
{
    /** No response body, Location or credential material is returned. */
    public static function httpFailure(int $status, bool $html = false): ?array
    {
        [$reason, $message] = match (true) {
            $status >= 300 && $status < 400 => ['upstream_redirect', '平台返回了跳转，未取得业务数据；请检查平台页面与请求入口，登录状态尚未确认'],
            $status === 401 => ['upstream_unauthorized', '平台未接受本次身份验证，请检查当前账号登录状态'],
            $status === 403 => ['upstream_forbidden', '平台拒绝访问，请核对账号权限或平台验证要求'],
            $status === 429 => ['upstream_rate_limited', '平台请求频率受限，请稍后重试'],
            $status !== 200 => ['upstream_http_error', '平台请求失败，未取得业务数据'],
            $html => ['upstream_html', '平台返回网页而非业务数据，请检查页面、验证要求和请求入口'],
            default => ['', ''],
        };
        return $reason === '' ? null : ['success' => false, 'reason' => $reason,
            'stage' => 'upstream_request', 'error' => $message, 'http_code' => $status];
    }

    public static function explicitlyRequiresLogin(string $message): bool
    {
        return preg_match('/(?:login_expired|login_required|not logged in|login (?:has )?expired|please (?:re)?login|session expired|未登录|尚未登录|请重新登录|登录(?:态)?(?:已)?(?:过期|失效)|(?:cookie|凭据)(?:已)?(?:过期|失效))/iu', $message) === 1;
    }

    public static function ctripJsonResponse(string $raw, int $httpCode): array
    {
        $empty = ['http_code' => $httpCode, 'raw_response' => '', 'decoded_data' => null, 'error' => ''];
        $failure = self::httpFailure($httpCode, preg_match('/^\s*(?:<!DOCTYPE|<html)/i', $raw) === 1);
        if ($failure !== null) return array_replace($empty, $failure);
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array_replace($empty, ['reason' => 'upstream_invalid_json', 'stage' => 'upstream_request', 'error' => '平台未返回可解析的业务数据']);
        }
        return array_replace($empty, ['raw_response' => $raw, 'decoded_data' => $data]);
    }

    public static function ctripBusinessFailure(array $data, bool $statusIsBusinessCode = true): ?array
    {
        $code = $data['code'] ?? $data['resultCode'] ?? ($statusIsBusinessCode ? ($data['status'] ?? null) : null);
        $codeText = is_scalar($code) ? (string)$code : '';
        $ack = $data['ResponseStatus']['Ack'] ?? null;
        $ackText = is_scalar($ack) ? (string)$ack : '';
        $failed = ($data['success'] ?? null) === false || isset($data['error'])
            || ($code !== null && !in_array($codeText, ['0', '200', 'success', 'SUCCESS'], true))
            || ($ack !== null && !in_array($ackText, ['Success', 'SUCCESS'], true));
        if (!$failed) return null;
        $messages = array_intersect_key($data, array_flip(['message', 'msg', 'errorMessage', 'error_description', 'error']));
        if ($ack !== null && !in_array($ackText, ['Success', 'SUCCESS'], true)) {
            foreach ((is_array($data['ResponseStatus']['Errors'] ?? null) ? $data['ResponseStatus']['Errors'] : []) as $error) {
                if (is_array($error)) $messages[] = $error['Message'] ?? '';
            }
        }
        $loginRequired = $codeText === '401';
        foreach ($messages as $message) {
            if (is_string($message) && self::explicitlyRequiresLogin($message)) $loginRequired = true;
        }
        return [
            'reason' => $loginRequired ? 'login_required' : 'ctrip_api_error',
            'stage' => 'upstream_request', 'business_code' => $code,
            'credential_status' => $loginRequired ? 'login_required' : 'api_error',
            'error' => $loginRequired ? '携程要求重新登录，请重新登录平台后更新授权'
                : '携程接口拒绝本次请求，请核对业务参数和账号权限；登录状态尚未确认',
        ];
    }

    public static function meituanBusinessFailure($code, string $message): array
    {
        $loginRequired = (string)$code === '401' || self::explicitlyRequiresLogin($message);
        return [
            'reason' => $loginRequired ? 'login_required' : 'meituan_api_error',
            'credential_status' => $loginRequired ? 'login_required' : 'api_error',
            'error' => $loginRequired ? '美团要求重新登录，请重新登录平台后更新授权'
                : '美团接口拒绝本次请求，请核对业务参数和账号权限；登录状态尚未确认',
        ];
    }
}
