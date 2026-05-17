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
        if ($e instanceof ValidateException && str_starts_with((string)$request->pathinfo(), 'api/')) {
            $error = $e->getError();

            return json([
                'code' => 400,
                'message' => is_array($error) ? implode('; ', $error) : (string)$error,
                'data' => null,
                'time' => time(),
            ]);
        }

        return parent::render($request, $e);
    }
}
