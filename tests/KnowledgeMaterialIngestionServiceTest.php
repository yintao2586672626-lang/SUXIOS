<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeMaterialIngestionService;
use app\service\LlmClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KnowledgeMaterialIngestionServiceTest extends TestCase
{
    public function testDistillMaterialBuildsStoreSpecificAuditableKnowledge(): void
    {
        if (!class_exists(KnowledgeMaterialIngestionService::class)) {
            self::fail('KnowledgeMaterialIngestionService is required');
        }

        $client = new class extends LlmClient {
            public array $messages = [];
            public array $schema = [];
            public string $modelKey = '';

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;
                $this->schema = $schema;
                $this->modelKey = $modelKey;

                return [
                    'title' => 'A店差评处理SOP',
                    'summary' => '客诉优先在30分钟内完成首响，涉及卫生问题必须回访。',
                    'hotel_profile' => ['positioning' => '商务客为主'],
                    'facts' => ['30分钟内首响', '卫生问题需要回访'],
                    'analysis_hints' => ['点评分下降时优先检查卫生类差评'],
                    'actions' => ['建立当日差评复盘清单'],
                    'boundaries' => ['资料未提供早餐、房价和库存信息'],
                    'keywords' => ['点评', '卫生', 'SOP'],
                    'confidence_score' => 0.86,
                ];
            }
        };

        $service = new KnowledgeMaterialIngestionService($client);
        $result = $service->distillMaterial([
            'mode' => 'text',
            'source' => 'manual',
            'content' => 'A店近两周卫生差评增加，店长要求30分钟内首响并完成回访。',
            'hotel_id' => 7,
            'hotel_name' => 'A店',
            'model_key' => 'deepseek_chat',
        ]);

        self::assertSame('deepseek_chat', $client->modelKey);
        self::assertStringContainsString('A店', $client->messages[1]['content']);
        self::assertStringContainsString('A店近两周卫生差评增加', $client->messages[1]['content']);
        self::assertStringContainsString('不要编造资料未写明的门店事实', $client->messages[0]['content']);
        self::assertArrayHasKey('properties', $client->schema);
        self::assertSame('A店差评处理SOP', $result['title']);
        self::assertSame(7, $result['hotel_id']);
        self::assertSame('A店', $result['hotel_name']);
        self::assertSame('manual', $result['source']);
        self::assertSame('text', $result['material_type']);
        self::assertSame('A店近两周卫生差评增加，店长要求30分钟内首响并完成回访。', $result['raw_text']);
        self::assertSame(['点评', '卫生', 'SOP'], $result['keywords']);
        self::assertSame(0.86, $result['confidence_score']);
        self::assertSame('资料未提供早餐、房价和库存信息', $result['boundaries'][0]);
    }

    public function testSplitRawMaterialsUsesLinesForLinksAndBlankLinesForText(): void
    {
        if (!class_exists(KnowledgeMaterialIngestionService::class)) {
            self::fail('KnowledgeMaterialIngestionService is required');
        }

        $service = new KnowledgeMaterialIngestionService(new LlmClient());

        self::assertSame(
            ['https://example.com/a', 'https://example.com/b'],
            $service->splitRawMaterials(" https://example.com/a \n\nhttps://example.com/b", 'link')
        );
        self::assertSame(
            ['第一段经验', "第二段\n继续说明"],
            $service->splitRawMaterials("第一段经验\n\n第二段\n继续说明", 'text')
        );
    }

    public function testSplitRawMaterialsKeepsDocumentAsSingleMaterial(): void
    {
        if (!class_exists(KnowledgeMaterialIngestionService::class)) {
            self::fail('KnowledgeMaterialIngestionService is required');
        }

        $service = new KnowledgeMaterialIngestionService(new LlmClient());
        $document = "第一段资料\n\n第二段资料\n\n第三段资料";

        self::assertSame([$document], $service->splitRawMaterials($document, 'document'));
        self::assertSame([$document], $service->splitRawMaterials($document, 'xlsx'));
    }

    public function testEmptyAiSummaryIsARealFailure(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                return [
                    'title' => '房型模板',
                    'summary' => '   ',
                    'facts' => [],
                    'analysis_hints' => [],
                    'actions' => [],
                    'boundaries' => [],
                    'keywords' => [],
                    'confidence_score' => 0.2,
                ];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI summary is empty; knowledge material was not distilled');

        (new KnowledgeMaterialIngestionService($client))->distillMaterial([
            'mode' => 'xlsx',
            'source' => 'manual_template',
            'content' => '工作表：房型模板',
            'hotel_id' => 80,
            'hotel_name' => '授权容器门店',
            'model_key' => 'deepseek_chat',
            'material_classification' => 'manual_template',
            'knowledge_scope' => 'industry_general',
            'verification_status' => 'unverified',
        ]);
    }

    public function testGenericXlsxStaysIndustryGeneralAndUnverified(): void
    {
        $client = new class extends LlmClient {
            public array $messages = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;

                return [
                    'title' => '通用房型梳理模板',
                    'summary' => '该工作簿提供通用房型梳理字段。',
                    'hotel_profile' => ['name' => '模型不应接受的推断门店'],
                    'facts' => ['模板包含房型名称字段'],
                    'analysis_hints' => ['使用前补充本店来源'],
                    'actions' => ['人工核验后再使用'],
                    'boundaries' => [],
                    'keywords' => ['房型', '模板'],
                    'confidence_score' => 0.75,
                ];
            }
        };

        $result = (new KnowledgeMaterialIngestionService($client))->distillMaterial([
            'mode' => 'xlsx',
            'source' => 'manual_template',
            'content' => '工作表：房型模板',
            'hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'model_key' => 'deepseek_chat',
            'material_classification' => 'manual_template',
            'knowledge_scope' => 'industry_general',
            'verification_status' => 'unverified',
        ]);

        self::assertStringContainsString('行业通用、人工模板、未验证资料', $client->messages[0]['content']);
        self::assertStringContainsString('不得把知识资料绑定的授权门店容器当作资料中已经出现的门店事实', $client->messages[0]['content']);
        self::assertStringNotContainsString('敦煌漠蓝新', $client->messages[1]['content']);
        self::assertStringContainsString('仅作授权容器，未作为资料事实提供', $client->messages[1]['content']);
        self::assertSame('manual_template', $result['material_classification']);
        self::assertSame('industry_general', $result['knowledge_scope']);
        self::assertSame('unverified', $result['verification_status']);
        self::assertSame('document_reference_not_hotel_fact', $result['facts_scope']);
        self::assertSame('authorization_container_not_document_fact', $result['hotel_binding_semantics']);
        self::assertSame('not_applicable_industry_general', $result['hotel_profile_status']);
        self::assertTrue($result['hotel_profile_discarded_by_scope']);
        self::assertArrayNotHasKey('hotel_profile', $result);
        self::assertContains(
            '门店ID仅用于授权容器与数据隔离，不代表资料内容属于该门店。',
            $result['boundaries']
        );
    }
}
