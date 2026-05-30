<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmEndpoint;
use PHPUnit\Framework\TestCase;

final class LlmEndpointTest extends TestCase
{
    public function testChatEndpointUrlNormalizesProviderPathsAndQueryStrings(): void
    {
        self::assertSame(
            'https://api.mistral.ai/v1/chat/completions',
            LlmEndpoint::chatCompletionUrl('https://api.mistral.ai/v1', 'mistral')
        );

        self::assertSame(
            'https://api.perplexity.ai/v1/sonar',
            LlmEndpoint::chatCompletionUrl('https://api.perplexity.ai/v1', 'perplexity')
        );

        self::assertSame(
            'https://example.services.ai.azure.com/models/chat/completions?api-version=2024-05-01-preview',
            LlmEndpoint::chatCompletionUrl('https://example.services.ai.azure.com/models?api-version=2024-05-01-preview', 'microsoft_phi')
        );

        self::assertSame(
            'https://api.example.com/v1/chat/completions',
            LlmEndpoint::chatCompletionUrl('https://api.example.com/v1/chat/completions', 'deepseek')
        );
    }
}
