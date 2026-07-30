<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmEndpoint;
use app\service\OutboundUrlGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LlmEndpointTest extends TestCase
{
    public function testChatEndpointUrlNormalizesProviderPathsAndQueryStrings(): void
    {
        $guard = new OutboundUrlGuard(static fn(string $host): array => ['93.184.216.34']);

        self::assertSame(
            'https://api.mistral.ai/v1/chat/completions',
            LlmEndpoint::chatCompletionUrl('https://api.mistral.ai/v1', 'mistral', $guard)
        );

        self::assertSame(
            'https://api.perplexity.ai/v1/sonar',
            LlmEndpoint::chatCompletionUrl('https://api.perplexity.ai/v1', 'perplexity', $guard)
        );

        self::assertSame(
            'https://example.services.ai.azure.com/models/chat/completions?api-version=2024-05-01-preview',
            LlmEndpoint::chatCompletionUrl('https://example.services.ai.azure.com/models?api-version=2024-05-01-preview', 'microsoft_phi', $guard)
        );

        self::assertSame(
            'https://api.example.com/v1/chat/completions',
            LlmEndpoint::chatCompletionUrl('https://api.example.com/v1/chat/completions', 'deepseek', $guard)
        );
    }

    public function testChatEndpointRejectsLocalBaseUrlBeforeApiKeyTransport(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LlmEndpoint::chatCompletionUrl(
            'https://127.0.0.1:443/v1',
            'openai',
            new OutboundUrlGuard(static fn(string $host): array => ['93.184.216.34'])
        );
    }
}
