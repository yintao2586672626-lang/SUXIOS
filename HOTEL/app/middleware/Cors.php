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
        // 处理预检请求
        if ($request->method() === 'OPTIONS') {
            return $this->setCorsHeaders(response('', 204));
        }

        $response = $next($request);
        return $this->setCorsHeaders($response);
    }

    /**
     * 设置 CORS 头
     */
    private function setCorsHeaders(Response $response): Response
    {
        $response->header([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Max-Age' => '86400',
        ]);

        return $response;
    }
}
