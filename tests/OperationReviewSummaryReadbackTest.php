<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;

/** Pure DTO normalization only: no constructor, application, database or account. */
final class OperationReviewSummaryReadbackTest extends TestCase
{
    private function normalize(array $changes): array
    {
        $service = (new \ReflectionClass(OperationManagementService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'normalizeExecutionTaskRow');
        return $method->invoke($service, array_merge([
            'id' => 11, 'intent_id' => 111, 'hotel_id' => 7, 'tenant_id' => 70,
            'operator_id' => 901, 'status' => 'executed', 'result_status' => 'observing',
        ], $changes));
    }

    public function testSafeStoredTextHasAnExactRawSummaryDigest(): void
    {
        foreach ([
            '继续观察，等待同口径数据',
            '继续观察，等待次日收益或ROI证据',
            '{ "note": "继续观察" }',
            '{ "receipt": 9007199254740993 }',
            '{ "amount": 1.0 }',
            '{}',
        ] as $summary) {
            $task = $this->normalize(['result_summary' => $summary]);
            self::assertSame(hash('sha256', $summary), $task['result_summary_sha256'] ?? null);
            self::assertSame(11, $task['id']);
            self::assertSame(7, $task['hotel_id']);
            self::assertSame(111, $task['intent_id']);
            self::assertSame(70, $task['tenant_id']);
        }
    }

    public function testDisplayNormalizationAndLargeIntegerTextRemainUnchanged(): void
    {
        foreach ([
            '{ "note": "继续观察" }' => '{"note":"继续观察"}',
            '{ "receipt": 9007199254740993 }' => '{"receipt":9007199254740993}',
            '{ "amount": 1.0 }' => '{"amount":1}',
            '{}' => '[]',
        ] as $raw => $display) {
            $task = $this->normalize(['result_summary' => $raw]);
            self::assertSame($display, $task['result_summary']);
            self::assertSame(hash('sha256', $raw), $task['result_summary_sha256']);
            self::assertNotSame(hash('sha256', $display), $task['result_summary_sha256']);
        }
        self::assertNotSame(
            $this->normalize(['result_summary' => '{ "receipt": 9007199254740993 }'])['result_summary_sha256'],
            $this->normalize(['result_summary' => '{ "receipt": 9007199254740992 }'])['result_summary_sha256']
        );
    }

    public function testLegacyCredentialOrChangedFieldValuesExposeNoRawDigest(): void
    {
        // Explicit synthetic sentinels only; never read local credential material.
        foreach ([
            'password=SYNTHETIC-NOT-A-CREDENTIAL',
            '{"password":"SYNTHETIC-NOT-A-CREDENTIAL"}',
            '{"pa\\u0073sword":"SYNTHETIC-NOT-A-CREDENTIAL"}',
            '{"note":"{ \\"password\\": \\"SYNTHETIC-NOT-A-CREDENTIAL\\" }"}',
            '{"token":null}',
        ] as $raw) {
            $task = $this->normalize(['result_summary' => $raw, 'result_summary_sha256' => str_repeat('a', 64)]);
            self::assertArrayNotHasKey('result_summary_sha256', $task);
            self::assertNotSame($raw, $task['result_summary']);
            self::assertStringNotContainsString('SYNTHETIC-NOT-A-CREDENTIAL', $task['result_summary']);
        }
    }

    public function testAbsentOrNonTextSummaryCannotMintOrRetainSuppliedDigest(): void
    {
        foreach ([[], ['result_summary' => null], ['result_summary' => 12]] as $row) {
            $task = $this->normalize($row + ['result_summary_sha256' => str_repeat('b', 64)]);
            self::assertArrayNotHasKey('result_summary_sha256', $task);
        }
    }
}
