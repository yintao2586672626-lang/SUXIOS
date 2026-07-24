<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgePayloadMapper;
use PHPUnit\Framework\TestCase;
use think\exception\ValidateException;

final class KnowledgePayloadMapperTest extends TestCase
{
    public function testNormalizeUnitDataPreservesCreationDefaultsAndHotelScope(): void
    {
        $result = (new KnowledgePayloadMapper())->normalizeUnitData([
            'name' => '  前台话术  ',
            'hotel_id' => 80,
            'tags' => '运营, 前台，运营',
        ], true, true);

        self::assertSame('前台话术', $result['name']);
        self::assertSame('', $result['source']);
        self::assertSame(80, $result['hotel_id']);
        self::assertSame('pending', $result['status']);
        self::assertSame('', $result['description']);
        self::assertSame(['运营', '前台'], $result['tags']);
    }

    public function testNormalizeUnitDataRejectsUnsupportedStatus(): void
    {
        $this->expectException(ValidateException::class);
        $this->expectExceptionMessage('status must be pending, done or error');

        (new KnowledgePayloadMapper())->normalizeUnitData([
            'name' => '测试知识',
            'status' => 'published',
        ], true, false);
    }

    public function testNormalizeChunkDataAcceptsJsonAndPlainText(): void
    {
        $mapper = new KnowledgePayloadMapper();

        self::assertSame([
            'unit_id' => 9,
            'type' => 'policy',
            'content' => ['rule' => '18:00后确认'],
        ], $mapper->normalizeChunkData([
            'type' => 'policy',
            'content' => '{"rule":"18:00后确认"}',
        ], 9));

        self::assertSame([
            'unit_id' => 9,
            'type' => 'manual',
            'content' => ['text' => '保留原始文本'],
        ], $mapper->normalizeChunkData(['content' => '保留原始文本'], 9));
    }

    public function testFormattingPreservesKnowledgeResponseShape(): void
    {
        $mapper = new KnowledgePayloadMapper();
        $unit = $mapper->formatUnitRow([
            'unit_id' => '7',
            'hotel_id' => '80',
            'name' => '门店知识',
            'source' => 'manual',
            'status' => 'done',
            'description' => '已审核',
            'tags' => '["前台","运营"]',
            'created_by' => '3',
        ], 2);
        $chunk = $mapper->formatChunkRow([
            'chunk_id' => '11',
            'unit_id' => '7',
            'type' => 'manual',
            'content' => '{"text":"欢迎语"}',
            'created_by' => '3',
        ]);

        self::assertSame(7, $unit['unit_id']);
        self::assertSame(80, $unit['hotel_id']);
        self::assertSame(['前台', '运营'], $unit['tags']);
        self::assertSame(2, $unit['chunk_count']);
        self::assertIsArray($unit['readiness']);
        self::assertSame(['text' => '欢迎语'], $chunk['content']);
        self::assertSame(11, $chunk['chunk_id']);
    }

    public function testImportHelpersKeepExistingNormalizationRules(): void
    {
        $mapper = new KnowledgePayloadMapper();

        self::assertSame('第一行标题', $mapper->defaultImportedTitle('text', "第一行标题\n第二行"));
        self::assertSame('video资料蒸馏', $mapper->defaultImportedTitle('video', "\n\n"));
        self::assertSame(
            ['运营', '前台', '敦煌漠蓝新'],
            $mapper->mergeTags(['运营', '前台'], ['前台'], ['敦煌漠蓝新'])
        );
        self::assertSame('连接失败 请重试', $mapper->shortErrorMessage("连接失败\n请重试"));
    }
}
