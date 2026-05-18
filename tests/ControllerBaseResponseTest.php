<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\ValidateException;
use think\Response;

final class ControllerBaseResponseTest extends TestCase
{
    public function testSuccessAndErrorResponsesKeepStableEnvelope(): void
    {
        $controller = $this->controller();

        $success = $controller->exposeSuccess(['id' => 1], 'ok', 201);
        self::assertSame(200, $success->getCode());
        self::assertSame(201, $this->json($success)['code']);
        self::assertSame(['id' => 1], $this->json($success)['data']);

        $error = $controller->exposeError('invalid', 422, ['field' => 'name']);
        self::assertSame(422, $error->getCode());
        self::assertSame(422, $this->json($error)['code']);
        self::assertSame(['field' => 'name'], $this->json($error)['data']);

        $fallback = $controller->exposeError('domain code', 90001);
        self::assertSame(400, $fallback->getCode());
        self::assertSame(90001, $this->json($fallback)['code']);
    }

    public function testPaginateBuildsConsistentPaginationPayload(): void
    {
        $response = $this->controller()->exposePaginate([['id' => 1]], 25, 2, 10);
        $payload = $this->json($response);

        self::assertSame([['id' => 1]], $payload['data']['list']);
        self::assertSame(25, $payload['data']['pagination']['total']);
        self::assertSame(2, $payload['data']['pagination']['page']);
        self::assertSame(10, $payload['data']['pagination']['page_size']);
        self::assertEquals(3, $payload['data']['pagination']['total_page']);
    }

    public function testValidateThrowsThinkValidateExceptionForInvalidInput(): void
    {
        $this->expectException(ValidateException::class);

        $this->controller()->exposeValidate([], ['name' => 'require']);
    }

    private function controller(): object
    {
        return new class(new App()) extends Base {
            public function exposeSuccess($data = null, string $message = 'ok', int $code = 200): Response
            {
                return $this->success($data, $message, $code);
            }

            public function exposeError(string $message, int $code = 400, $data = null): Response
            {
                return $this->error($message, $code, $data);
            }

            public function exposePaginate(array $list, int $total, int $page, int $pageSize): Response
            {
                return $this->paginate($list, $total, $page, $pageSize);
            }

            public function exposeValidate(array $data, array $rules): void
            {
                $this->validate($data, $rules);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
