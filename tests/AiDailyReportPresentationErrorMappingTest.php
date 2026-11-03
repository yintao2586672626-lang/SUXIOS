<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AiDailyReport;
use app\controller\Base;
use app\service\AiDailyReportService;
use app\service\ApiExceptionMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use think\Request;

final class AiDailyReportPresentationErrorMappingTest extends TestCase
{
    private const ACTIONS = ['savePresentationSpec', 'presentationSpec', 'savePresentationArtifact',
        'presentationReview', 'savePresentationReview', 'presentationArtifact', 'presentationArtifactById'];

    public static function identityFailures(): array
    {
        return [
            'review' => ['presentation_review_hotel_identity_mapping_unverified_rebuild_current_spec'],
            'anonymized_review' => ['presentation_review_scope_or_source_mismatch: hotel_identity_mapping_unverified_rebuild_current_spec'],
            'spec' => ['AI daily report presentation spec exact readback verification failed: hotel_identity_mapping_unverified_rebuild_current_spec'],
            'artifact' => ['AI daily report presentation artifact exact readback verification failed: hotel_identity_mapping_unverified_rebuild_current_spec'],
        ];
    }

    #[DataProvider('identityFailures')]
    public function testExactIdentityFailureReturnsConflictWithAnExecutableRecoveryMessage(string $message): void
    {
        foreach (self::ACTIONS as $action) {
            $response = $this->invokeAction($action, new RuntimeException($message, 409));
            $payload = $response->getData();
            self::assertSame(409, $response->getCode(), $action);
            self::assertSame(409, $payload['code']);
            self::assertSame('presentation_hotel_identity_mapping_unverified', $payload['data']['error_code']);
            self::assertSame('hotel_identity_mapping_unverified_rebuild_current_spec', $payload['data']['reason']);
            self::assertStringContainsString('当前酒店编号', $payload['message']);
            self::assertStringContainsString('重新生成日报和报告规格', $payload['message']);
            self::assertStringContainsString('逐项人工复核', $payload['message']);
            self::assertStringContainsString('旧复核不能用于当前正式导出', $payload['message']);
            self::assertArrayNotHasKey('correlation_id', $payload['data']);
        }
    }

    public function testUnknownOrExtendedRuntimeMessagesCannotUseTheirExceptionCodeToEscapeOpaqueServerError(): void
    {
        foreach (['unknown_presentation_failure', self::identityFailures()['review'][0] . ': private-test-only-detail'] as $message) {
            foreach (self::ACTIONS as $action) {
                $response = $this->invokeAction($action, new RuntimeException($message, 409));
                $payload = $response->getData();
                self::assertSame(500, $response->getCode(), $action);
                self::assertSame(ApiExceptionMapper::INTERNAL_ERROR_CODE, $payload['data']['error_code']);
                self::assertSame(ApiExceptionMapper::INTERNAL_ERROR_CODE, $payload['data']['reason']);
                self::assertArrayHasKey('correlation_id', $payload['data']);
                self::assertStringNotContainsString($message, $response->getContent());
            }
        }
    }

    public function testExistingReviewConflictRetainsItsAllowlistedMapping(): void
    {
        $response = $this->invokeAction('presentationReview', new RuntimeException('presentation_review_stale', 409));
        self::assertSame(409, $response->getCode());
        self::assertSame('presentation_review_stale', $response->getData()['message']);
        self::assertSame('business_error', $response->getData()['data']['error_code']);
    }

    private function invokeAction(string $action, RuntimeException $failure): \think\Response
    {
        $controller = (new ReflectionClass(AiDailyReport::class))->newInstanceWithoutConstructor();
        $request = (new Request())->withGet(['hotel_id' => 70])->withPost(['audience' => 'owner']);
        (new ReflectionProperty(Base::class, 'request'))->setValue($controller, $request);
        (new ReflectionProperty(Base::class, 'currentUser'))->setValue($controller, new class {
            public function getPermittedHotelIds(): array { return [70]; }
        });
        $service = $this->createMock(AiDailyReportService::class);
        $service->expects(self::once())->method('read')->with(88, [70])->willThrowException($failure);
        (new ReflectionProperty(AiDailyReport::class, 'service'))->setValue($controller, $service);
        return $action === 'presentationArtifactById' ? $controller->$action(88, 1) : $controller->$action(88);
    }
}
