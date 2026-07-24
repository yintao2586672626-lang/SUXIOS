<?php
declare(strict_types=1);

namespace app\service;

final class LlmEndpoint
{
    public static function chatCompletionUrl(
        string $baseUrl,
        string $provider,
        ?OutboundUrlGuard $guard = null
    ): string
    {
        return self::chatCompletionTarget($baseUrl, $provider, $guard)['url'];
    }

    /**
     * @return array{
     *     url:string,
     *     host:string,
     *     port:int,
     *     addresses:array<int,string>,
     *     curl_resolve:array<int,string>
     * }
     */
    public static function chatCompletionTarget(
        string $baseUrl,
        string $provider,
        ?OutboundUrlGuard $guard = null
    ): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $provider = strtolower(trim($provider));
        $path = $provider === 'perplexity' ? '/sonar' : '/chat/completions';

        if ($baseUrl === '') {
            $url = $path;
        } elseif (preg_match('#/(chat/completions|sonar)(\?|$)#', $baseUrl)) {
            $url = $baseUrl;
        } else {
            $query = '';
            $queryPos = strpos($baseUrl, '?');
            if ($queryPos !== false) {
                $query = substr($baseUrl, $queryPos);
                $baseUrl = rtrim(substr($baseUrl, 0, $queryPos), '/');
            }
            $url = $baseUrl . $path . $query;
        }

        return ($guard ?? new OutboundUrlGuard())->validate($url);
    }
}
