<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

class Cors
{
    /**
     * 处理请求 - CORS 跨域
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->method() === 'OPTIONS') {
            return $this->setCorsHeaders(response('', 204), $request);
        }

        $response = $next($request);
        return $this->setCorsHeaders($response, $request);
    }

    /**
     * 设置 CORS 头
     */
    private function setCorsHeaders(Response $response, Request $request): Response
    {
        $policy = config('cors', []);
        $policy = is_array($policy) ? $policy : [];
        $origin = trim((string)$request->header('origin', ''));
        $allowedOrigins = array_values(array_filter(
            $this->normalizeList($policy['allowed_origins'] ?? []),
            static fn(string $allowedOrigin): bool => $allowedOrigin !== '*'
        ));
        if ($allowedOrigins !== []) {
            $this->varyByOrigin($response);
        }
        if ($origin === '' || !in_array($origin, $allowedOrigins, true)) {
            return $response;
        }

        $allowedMethods = array_values(array_filter(
            $this->normalizeList($policy['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']),
            static fn(string $method): bool => $method !== '*'
        ));
        $allowedHeaders = array_values(array_filter(
            $this->normalizeList($policy['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With']),
            static fn(string $header): bool => $header !== '*'
        ));
        $headers = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', $allowedMethods),
            'Access-Control-Allow-Headers' => implode(', ', $allowedHeaders),
            'Access-Control-Max-Age' => (string)max(0, (int)($policy['max_age'] ?? 600)),
        ];
        if (($policy['allow_credentials'] ?? false) === true) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        $response->header($headers);

        return $response;
    }

    private function varyByOrigin(Response $response): void
    {
        $name = 'Vary';
        $value = '';
        foreach ($response->getHeader() as $headerName => $headerValue) {
            if (strcasecmp((string)$headerName, 'Vary') === 0) {
                $name = (string)$headerName;
                $value = (string)$headerValue;
                break;
            }
        }
        $fields = $this->normalizeList($value);
        foreach ($fields as $field) {
            if ($field === '*' || strcasecmp($field, 'Origin') === 0) {
                return;
            }
        }
        $fields[] = 'Origin';
        $response->header([$name => implode(', ', $fields)]);
    }

    /** @return list<string> */
    private function normalizeList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string)$value);
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            $items
        ), static fn(string $item): bool => $item !== '')));
    }
}
