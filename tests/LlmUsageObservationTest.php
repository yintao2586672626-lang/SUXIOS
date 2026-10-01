<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmUsageObservation as Usage;
use PHPUnit\Framework\TestCase;

final class LlmUsageObservationTest extends TestCase
{
    public function testOnlyProviderEnvelopeCountsAsUsageAndRealZeroSurvives(): void
    {
        $fakeContent = json_encode(['choices' => [['message' => ['content' => '{"usage":{"total_tokens":1}}']]]]);
        self::assertSame('unavailable', Usage::receipt($fakeContent)['status']);
        $receipt = Usage::receipt(json_encode(['usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0]]));
        $summary = Usage::summarize([$receipt], 0);
        self::assertSame('reported', $summary['status']);
        self::assertSame(0, $summary['total_tokens']);
        self::assertSame(0, $summary['elapsed_ms']);
        self::assertNull($summary['cost_amount']);
        self::assertNull($summary['cached_tokens']);
    }

    public function testMissingMalformedAndInconsistentReceiptsNeverBecomeZero(): void
    {
        foreach ([null, [], ['prompt_tokens' => '10', 'completion_tokens' => 2, 'total_tokens' => 12],
            ['prompt_tokens' => -1, 'completion_tokens' => 2, 'total_tokens' => 1],
            ['prompt_tokens' => true, 'completion_tokens' => 2, 'total_tokens' => 3],
            ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 99],
            ['prompt_tokens' => PHP_INT_MAX, 'completion_tokens' => 2, 'total_tokens' => PHP_INT_MAX],
        ] as $value) {
            $summary = Usage::summarize([Usage::receipt(json_encode(['usage' => $value]))]);
            self::assertSame('unavailable', $summary['status']);
            self::assertNull($summary['total_tokens']);
            self::assertNull($summary['observed_total_tokens']);
        }
    }

    public function testCachedAndReasoningTokensAreSubsetsNotExtraTokens(): void
    {
        $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15,
            'prompt_cache_hit_tokens' => 4, 'completion_tokens_details' => ['reasoning_tokens' => 3]];
        $summary = Usage::summarize([Usage::receipt(json_encode(['usage' => $usage]))]);
        self::assertSame(15, $summary['total_tokens']);
        self::assertSame(4, $summary['cached_tokens']);
        self::assertSame(3, $summary['reasoning_tokens']);
        $usage['prompt_tokens_details']['cached_tokens'] = 5;
        $usage['completion_tokens_details']['reasoning_tokens'] = 6;
        $receipt = Usage::receipt(json_encode(['usage' => $usage]));
        self::assertNull($receipt['cached_tokens']);
        self::assertNull($receipt['reasoning_tokens']);
        self::assertSame(15, $receipt['total_tokens']);
    }

    public function testRetryCoverageKeepsKnownSubtotalSeparateFromUnknownTotal(): void
    {
        $valid = Usage::receipt('{"usage":{"prompt_tokens":10,"completion_tokens":2,"total_tokens":12}}');
        $summary = Usage::summarize([Usage::receipt(false), $valid], 600);
        self::assertSame('partial', $summary['status']);
        self::assertSame(2, $summary['dispatched_count']);
        self::assertSame(1, $summary['reported_count']);
        self::assertNull($summary['total_tokens']);
        self::assertSame(12, $summary['observed_total_tokens']);
        self::assertSame(24, Usage::summarize([$valid, $valid])['total_tokens']);
    }

    public function testNoTransportAndLegacyDataAreDistinct(): void
    {
        $summary = Usage::summarize([Usage::receipt(false, false)]);
        self::assertSame('not_called', $summary['status']);
        self::assertSame(0, $summary['dispatched_count']);
        self::assertNull($summary['total_tokens']);
        self::assertSame('legacy_unknown', Usage::legacy()['status']);
        self::assertNull(Usage::legacy()['dispatched_count']);
    }
}
