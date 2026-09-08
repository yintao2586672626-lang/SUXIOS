<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/** Frozen modules retain authenticated history, but cannot create business work. */
final class RetiredFeatureReadOnly
{
    public function handle(Request $request, Closure $next, string $feature = '历史辅助模块'): Response
    {
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        return json([
            'code' => 410,
            'message' => $feature . '已停用生成与执行，仅保留历史记录查询。请使用经营分析和任务执行与复盘。',
            'data' => [
                'status' => 'retired_read_only',
                'history_preserved' => true,
                'next_entry' => 'ops-track',
            ],
        ], 410);
    }
}
