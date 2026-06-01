<?php
declare(strict_types=1);

namespace Tests;

use app\model\AiModelCallLog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class AiModelCallLogTest extends TestCase
{
    use ReflectionHelper;

    public function testGovernanceJsonFieldsAreEncodedBeforePersistence(): void
    {
        $reflection = new ReflectionClass(AiModelCallLog::class);
        /** @var AiModelCallLog $log */
        $log = $reflection->newInstanceWithoutConstructor();
        $types = $reflection->getDefaultProperties()['type'] ?? [];

        $knowledgeSources = [
            ['ref' => 'online_daily_data#10', 'title' => 'OTA metric source'],
        ];
        $governance = [
            'decision_impact' => 'none',
            'knowledge_source_count' => 1,
        ];

        $encodedSources = $this->invokeNonPublic($log, 'writeTransform', [
            $knowledgeSources,
            $types['knowledge_sources_json'] ?? null,
        ]);
        $encodedGovernance = $this->invokeNonPublic($log, 'writeTransform', [
            $governance,
            $types['governance_json'] ?? null,
        ]);

        self::assertIsString($encodedSources);
        self::assertSame($knowledgeSources, json_decode($encodedSources, true));
        self::assertIsString($encodedGovernance);
        self::assertSame($governance, json_decode($encodedGovernance, true));
    }
}
