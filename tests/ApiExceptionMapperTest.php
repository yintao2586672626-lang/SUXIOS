<?php
declare(strict_types=1);

namespace Tests;

use app\service\ApiExceptionMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiExceptionMapperTest extends TestCase
{
    public function testUnknownFailureIsRecordedOnlyAsSanitizedDiagnostics(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/ApiExceptionMapper.php');

        self::assertStringContainsString("Log::error('Opaque API internal error.'", $source);
        self::assertStringContainsString("'message_digest' => hash('sha256'", $source);
        self::assertStringContainsString("'source_file' => basename(", $source);
        self::assertStringNotContainsString("'exception' => \$exception", $source);
    }

    public function testUnknownThrowableIsOpaqueAndCarriesStableMachineCodeAndCorrelationId(): void
    {
        $secret = 'SQLSTATE[HY000] password=do-not-leak C:\\private\\database.sql';
        $mapped = ApiExceptionMapper::map(new RuntimeException($secret), '服务读取失败');

        self::assertSame(500, $mapped['status']);
        self::assertSame('服务读取失败', $mapped['message']);
        self::assertSame(ApiExceptionMapper::INTERNAL_ERROR_CODE, $mapped['data']['error_code']);
        self::assertSame(ApiExceptionMapper::INTERNAL_ERROR_CODE, $mapped['data']['reason']);
        self::assertMatchesRegularExpression('/^err_[a-f0-9]{24}$/D', (string)$mapped['data']['correlation_id']);
        self::assertStringNotContainsString('password', json_encode($mapped, JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString('database.sql', json_encode($mapped, JSON_UNESCAPED_UNICODE));

        $response = ApiExceptionMapper::response(new RuntimeException($secret), '服务读取失败');
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(500, $response->getCode());
        self::assertSame('服务读取失败', $payload['message']);
        self::assertSame($payload['data']['correlation_id'], $response->getHeader('X-Correlation-ID'));
        self::assertStringNotContainsString('password', (string)$response->getContent());
    }

    public function testOnlyExactAllowlistedBusinessRuntimeMessageCanBecomeFourHundredResponse(): void
    {
        $allowlist = ['当前酒店不存在该点评匹配记录' => 404];

        $allowed = ApiExceptionMapper::map(
            new RuntimeException('当前酒店不存在该点评匹配记录'),
            '撤销失败',
            $allowlist
        );
        self::assertSame(404, $allowed['status']);
        self::assertSame('当前酒店不存在该点评匹配记录', $allowed['message']);
        self::assertSame('business_error', $allowed['data']['error_code']);

        $unknown = ApiExceptionMapper::map(
            new RuntimeException('当前酒店不存在该点评匹配记录: SQLSTATE secret', 404),
            '撤销失败',
            $allowlist
        );
        self::assertSame(500, $unknown['status']);
        self::assertSame('撤销失败', $unknown['message']);
        self::assertSame(ApiExceptionMapper::INTERNAL_ERROR_CODE, $unknown['data']['error_code']);
    }

    public function testInvalidArgumentRemainsAnExplicitBusinessFourHundredResponse(): void
    {
        $mapped = ApiExceptionMapper::map(new \InvalidArgumentException('hotel_id 无效'), '请求失败');

        self::assertSame(422, $mapped['status']);
        self::assertSame('hotel_id 无效', $mapped['message']);
        self::assertSame('business_error', $mapped['data']['error_code']);
        self::assertArrayNotHasKey('correlation_id', $mapped['data']);
    }

    public function testNamedApiBoundariesDelegateThrowableMappingWithoutReadingRawMessages(): void
    {
        $root = dirname(__DIR__);
        foreach ([
            'app/controller/AiDailyReport.php',
            'app/controller/AiDailyReportBroadcast.php',
            'app/controller/PreciseQuery.php',
            'app/controller/concern/CtripReviewOrderMatchConcern.php',
            'app/controller/concern/MeituanReviewOrderMatchConcern.php',
        ] as $relativePath) {
            $source = (string)file_get_contents($root . '/' . $relativePath);
            self::assertStringContainsString('ApiExceptionMapper', $source, $relativePath);
            self::assertStringNotContainsString('getMessage()', $source, $relativePath);
        }
    }
}
