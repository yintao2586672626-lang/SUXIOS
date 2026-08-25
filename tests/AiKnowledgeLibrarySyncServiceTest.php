<?php
declare(strict_types=1);

namespace tests;

use app\service\AiKnowledgeLibrarySyncService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AiKnowledgeLibrarySyncServiceTest extends TestCase
{
    public function testGeneratedPackValidatesWithoutDatabaseWrite(): void
    {
        $result = (new AiKnowledgeLibrarySyncService())->sync(false);

        self::assertSame('validated', $result['status']);
        self::assertFalse($result['persisted']);
        self::assertSame(336, $result['top_level_file_count']);
        self::assertGreaterThanOrEqual(20, $result['method_entry_count']);
        self::assertSame(3, $result['priority_entry_count']);
        self::assertSame(1, $result['integrated_model_entry_count']);
        self::assertSame($result['method_entry_count'] + 4, $result['total_entry_count']);
        self::assertFalse($result['boundary']['decision_safe']);
        self::assertFalse($result['boundary']['task_draft_safe']);
        self::assertFalse($result['boundary']['external_write_authorized']);
    }

    public function testUnsafePackBoundaryIsRejected(): void
    {
        $root = dirname(__DIR__);
        $manifestPath = $root . '/docs/knowledge/ai-library/source-manifest.json';
        $pack = json_decode((string)file_get_contents($root . '/docs/knowledge/ai-library/method-pack.json'), true);
        self::assertIsArray($pack);
        $pack['boundary']['decision_safe'] = true;
        $temporary = tempnam(sys_get_temp_dir(), 'suxios-ai-pack-');
        self::assertIsString($temporary);
        file_put_contents($temporary, json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsafe_ai_knowledge_boundary:decision_safe');
            (new AiKnowledgeLibrarySyncService($manifestPath, $temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testUnsafeIntegratedModelBoundaryIsRejected(): void
    {
        $root = dirname(__DIR__);
        $model = json_decode((string)file_get_contents($root . '/docs/knowledge/ai-library/integrated-model.json'), true);
        self::assertIsArray($model);
        $model['boundary']['external_write_authorized'] = true;
        $temporary = tempnam(sys_get_temp_dir(), 'suxios-ai-model-');
        self::assertIsString($temporary);
        file_put_contents($temporary, json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsafe_ai_integrated_model_boundary:external_write_authorized');
            (new AiKnowledgeLibrarySyncService(
                $root . '/docs/knowledge/ai-library/source-manifest.json',
                $root . '/docs/knowledge/ai-library/method-pack.json',
                $root . '/docs/knowledge/ai-library/priority-pack.json',
                $temporary
            ))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }
}
