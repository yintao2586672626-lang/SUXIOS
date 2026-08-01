<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiModelRoutingService;
use PHPUnit\Framework\TestCase;

final class AiModelRoutingServiceTest extends TestCase
{
    public function testExplicitModelAlwaysWinsOverUsageSceneRouting(): void
    {
        $service = new AiModelRoutingService(static fn(): array => [[
            'id' => 7,
            'model_key' => 'ollama_qwen3_8b',
            'provider' => 'ollama',
            'usage_scene' => 'local_gpu,report',
            'is_default' => 0,
            'is_enabled' => 1,
        ]]);

        $selection = $service->resolve('deepseek_reasoner', 'report', 'deepseek_v4_default', 'ollama');

        self::assertSame('deepseek_reasoner', $selection['model_key']);
        self::assertSame('explicit', $selection['selection_mode']);
        self::assertSame('deepseek_reasoner', $selection['requested_model_key']);
    }

    public function testReportScenePrefersEnabledOllamaCandidate(): void
    {
        $service = new AiModelRoutingService(static fn(): array => [
            [
                'id' => 4,
                'model_key' => 'deepseek_chat',
                'provider' => 'deepseek',
                'usage_scene' => 'ota_diagnosis,report',
                'is_default' => 1,
                'is_enabled' => 1,
            ],
            [
                'id' => 7,
                'model_key' => 'ollama_qwen3_8b',
                'provider' => 'ollama',
                'usage_scene' => 'local_gpu, ota_diagnosis；report',
                'is_default' => 0,
                'is_enabled' => 1,
            ],
        ]);

        $selection = $service->resolve('', 'report', 'deepseek_v4_default', 'ollama');

        self::assertSame('ollama_qwen3_8b', $selection['model_key']);
        self::assertSame('ollama', $selection['provider']);
        self::assertSame('usage_scene', $selection['selection_mode']);
        self::assertSame(7, $selection['config_id']);
    }

    public function testSceneMatchingIsExactAndDisabledModelsAreIgnored(): void
    {
        $service = new AiModelRoutingService(static fn(): array => [
            [
                'id' => 1,
                'model_key' => 'substring_match_must_not_route',
                'provider' => 'ollama',
                'usage_scene' => 'reporting',
                'is_enabled' => 1,
            ],
            [
                'id' => 2,
                'model_key' => 'disabled_report_model',
                'provider' => 'ollama',
                'usage_scene' => 'report',
                'is_enabled' => 0,
            ],
        ]);

        $selection = $service->resolve('', 'report', 'deepseek_v4_default', 'ollama');

        self::assertSame('deepseek_v4_default', $selection['model_key']);
        self::assertSame('fallback', $selection['selection_mode']);
    }

    public function testConfigurationLookupFailureFallsBackTruthfully(): void
    {
        $service = new AiModelRoutingService(static function (): array {
            throw new \RuntimeException('database unavailable');
        });

        $selection = $service->resolve('', 'report', 'deepseek_v4_default', 'ollama');

        self::assertSame('deepseek_v4_default', $selection['model_key']);
        self::assertSame('fallback', $selection['selection_mode']);
        self::assertSame('', $selection['provider']);
    }
}
