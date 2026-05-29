<?php
declare(strict_types=1);

namespace app\service;

final class LlmEndpoint
{
    public static function chatCompletionUrl(string $baseUrl, string $provider): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $provider = strtolower(trim($provider));
        $path = $provider === 'perplexity' ? '/sonar' : '/chat/completions';

        if ($baseUrl === '') {
            return $path;
        }
        if (preg_match('#/(chat/completions|sonar)(\?|$)#', $baseUrl)) {
            return $baseUrl;
        }

        $query = '';
        $queryPos = strpos($baseUrl, '?');
        if ($queryPos !== false) {
            $query = substr($baseUrl, $queryPos);
            $baseUrl = rtrim(substr($baseUrl, 0, $queryPos), '/');
        }

        return $baseUrl . $path . $query;
    }
}
