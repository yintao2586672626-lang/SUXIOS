<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

class ExceptionHandle extends Handle
{
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    public function render($request, Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        $path = ltrim((string)$request->pathinfo(), '/');
        $isApiRequest = $path === 'api' || str_starts_with($path, 'api/');
        if ($e instanceof ValidateException && $isApiRequest) {
            $error = $e->getError();
            $message = is_array($error) ? implode('; ', $error) : (string)$error;

            return json([
                'code' => 400,
                'message' => mb_substr($message, 0, 300),
                'data' => null,
                'time' => time(),
            ], 400);
        }

        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        $messages = [
            400 => '请求无效',
            401 => '未认证',
            403 => '无权访问',
            404 => '接口不存在',
            405 => '请求方法不允许',
            409 => '请求冲突',
            422 => '请求参数无效',
            429 => '请求过于频繁',
        ];
        $message = $messages[$status] ?? ($status >= 500 ? '服务暂时不可用' : '请求失败');
        $headers = $e instanceof HttpException ? $e->getHeaders() : [];

        if ($isApiRequest) {
            $reason = $status === 404 ? 'route_not_found' : ($status >= 500 ? 'internal_error' : 'request_rejected');
            return json([
                'code' => $status,
                'message' => $message,
                'data' => [
                    'reason' => $reason,
                ],
                'time' => time(),
            ], $status)->header($headers);
        }

        return response($message, $status)->header(array_merge([
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ], $headers));
    }
}
