<?php
declare(strict_types=1);
namespace Tests;

use app\controller\Knowledge;
use app\service\KnowledgePayloadMapper;
use app\service\KnowledgeRevisionService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;
use think\Request;
use think\facade\Config;
use think\facade\Db;
use Tests\Support\KnowledgeApplicabilityFixture;

final class KnowledgeRevisionControllerTest extends TestCase
{
    private static array $database;
    private static string $path;
    private array $case;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$database = Config::get('database');
        self::$path = sys_get_temp_dir() . '/l08-synthetic-' . bin2hex(random_bytes(8)) . '.sqlite';
        Config::set(['default' => 'sqlite', 'connections' => ['sqlite' => ['type' => 'sqlite', 'database' => self::$path, 'prefix' => '', 'fields_strict' => false]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE knowledge_units (unit_id INTEGER PRIMARY KEY, hotel_id INTEGER, tenant_id INTEGER, created_by INTEGER, name TEXT, source TEXT, status TEXT, description TEXT, lifecycle_status TEXT, reviewed_at TEXT, review_due_at TEXT, tags TEXT, created_at TEXT, updated_at TEXT)');
        Db::execute('CREATE TABLE knowledge_chunks (chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER, type TEXT, content TEXT, created_by INTEGER, created_at TEXT)');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect('sqlite')->close();
        Config::set(self::$database, 'database'); Db::connect(null, true);
        if (is_file(self::$path)) unlink(self::$path);
    }

    protected function setUp(): void
    {
        Db::execute('DELETE FROM knowledge_chunks'); Db::execute('DELETE FROM knowledge_units');
        $this->case = KnowledgeApplicabilityFixture::cases()[0];
        Db::name('knowledge_units')->insert($this->case['units'][0] + ['tenant_id' => 1, 'tags' => '[]']);
        $row = $this->case['chunks'][0]; $row['content'] = json_encode($row['content'], JSON_UNESCAPED_UNICODE);
        Db::name('knowledge_chunks')->insert($row + ['created_by' => 7]);
    }

    private function controller(array $post = [], array $get = [], int $user = 7): Knowledge
    {
        $ref = new ReflectionClass(Knowledge::class); $controller = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('request')->setValue($controller, (new Request())->withPost($post)->withGet($get + ['platform' => 'ctrip', 'as_of' => '2026-09-08']));
        $ref->getProperty('currentUser')->setValue($controller, new class($user) {
            public int $tenant_id = 1;
            public function __construct(public int $id) {}
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return [80]; }
        });
        return $controller;
    }

    public function testControllerRevisionReadbackHistoryReevaluationAndIdempotentRetry(): void
    {
        $detail = $this->controller([], ['evaluate' => '1', 'question' => $this->case['question']])->detail(1)->getData();
        self::assertSame(0, $detail['code']);
        self::assertCount(32, $detail['data']['evaluation']['questions']);
        self::assertSame([101], array_column($detail['data']['retrieval_preview']['items'], 'chunk_id'));
        $chunk = $detail['data']['chunks'][0];
        $payload = ['type' => 'rule', 'content' => ['text' => '新版曝光规则需重新核验', 'source_refs' => ['synthetic://revision'], 'platforms' => ['ctrip'], 'valid_from' => '2026-09-08', 'valid_until' => '2026-10-08', 'evidence_grade' => 'A', 'decision_safe' => true], 'replaces_chunk_id' => 101, 'expected_digest' => $chunk['revision_digest'], 'request_id' => 'revision-test-01'];
        $response = $this->controller($payload)->addChunk(1); $saved = $response->getData();
        self::assertSame(200, $response->getCode(), json_encode($saved));
        self::assertTrue($saved['data']['readback_verified']); self::assertFalse($saved['data']['replayed']);
        self::assertSame(2, $saved['data']['chunk']['revision_no']);
        self::assertSame('2026-10-08', $saved['data']['chunk']['content']['valid_until']);
        self::assertFalse($saved['data']['chunk']['content']['decision_safe']);
        self::assertGreaterThan(0, $saved['data']['reevaluation']['affected_count']);
        self::assertNotContains('K09', array_column($saved['data']['reevaluation']['affected_questions'], 'id'));
        $retry = $this->controller($payload)->addChunk(1)->getData();
        self::assertTrue($retry['data']['replayed']); self::assertSame($saved['data']['chunk']['chunk_id'], $retry['data']['chunk']['chunk_id']);
        self::assertSame(2, Db::name('knowledge_chunks')->count());
        $readback = $this->controller()->detail(1)->getData()['data']['chunks'];
        self::assertSame($chunk['content'], $readback[0]['content']);
        self::assertTrue($readback[0]['revision_superseded']);
        self::assertSame($saved['data']['chunk']['revision_digest'], $readback[1]['revision_digest']);
        self::assertSame([], (new OperatingQuestionKnowledgeRetrievalService())->retrieve(80, 7, 'ctrip', '曝光', ['tenant_id' => 1, 'as_of' => '2026-09-08'])['items']);
        file_put_contents(dirname(__DIR__) . '/output/long-goal/controller-readback.json', json_encode(['environment' => 'isolated_sqlite_synthetic', 'detail' => $detail['data'], 'detail_after' => $this->controller()->detail(1)->getData()['data'], 'before' => $chunk, 'save' => $saved['data'], 'readback' => $readback], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function testRetryRebuildsTheOriginalRevisionEvaluationEvenAfterLaterVersions(): void
    {
        $chunk = $this->controller()->detail(1)->getData()['data']['chunks'][0];
        $payload = ['content' => ['text' => '新版曝光规则需重新核验', 'source_refs' => ['synthetic://retry']], 'replaces_chunk_id' => 101, 'expected_digest' => $chunk['revision_digest'], 'request_id' => 'revision-publish-retry-01'];
        $saved = $this->controller($payload)->addChunk(1)->getData()['data'];
        self::assertGreaterThan(0, $saved['reevaluation']['affected_count']);
        $retry = $this->controller($payload)->addChunk(1)->getData()['data'];
        self::assertSame($saved['reevaluation'], $retry['reevaluation']);
        self::assertTrue($retry['replayed']);
        self::assertSame(2, Db::name('knowledge_chunks')->count());
        $later = ['content' => ['text' => '后续转化率参考'], 'replaces_chunk_id' => $saved['chunk']['chunk_id'], 'expected_digest' => $saved['chunk']['revision_digest'], 'request_id' => 'revision-publish-retry-02'];
        self::assertSame(200, $this->controller($later)->addChunk(1)->getCode());
        $retry = $this->controller($payload, ['as_of' => '2026-10-08', 'platform' => 'meituan'])->addChunk(1)->getData()['data'];
        self::assertSame($saved['reevaluation'], $retry['reevaluation']);
        self::assertSame($saved['chunk']['revision_digest'], $retry['chunk']['revision_digest']);
        self::assertTrue($retry['readback_verified']);
        self::assertSame(3, Db::name('knowledge_chunks')->count());
    }

    public function testStaleRevisionAndDuplicateKeyConflictAre409AndDoNotWrite(): void
    {
        $chunk = $this->controller()->detail(1)->getData()['data']['chunks'][0];
        $payload = ['content' => ['text' => '曝光版本二'], 'replaces_chunk_id' => 101, 'expected_digest' => $chunk['revision_digest'], 'request_id' => 'revision-test-02'];
        self::assertSame(200, $this->controller($payload)->addChunk(1)->getCode());
        self::assertSame(409, $this->controller(array_replace($payload, ['request_id' => 'revision-test-03']))->addChunk(1)->getCode());
        self::assertSame(409, $this->controller(array_replace($payload, ['content' => ['text' => '改写请求']]))->addChunk(1)->getCode());
        self::assertSame(2, Db::name('knowledge_chunks')->count());
    }

    public function testValidationOwnershipFailureAndRecoveryDoNotChangeSource(): void
    {
        self::assertSame(404, $this->controller(['content' => ['text' => '另一个用户']], [], 8)->addChunk(1)->getCode());
        self::assertSame(422, $this->controller(['content' => ['text' => '非法日期', 'valid_until' => '2026-02-30']])->addChunk(1)->getCode());
        self::assertSame(409, $this->controller(['content' => ['text' => '跨单元父版本'], 'replaces_chunk_id' => 999, 'expected_digest' => str_repeat('a', 64)])->addChunk(1)->getCode());
        self::assertSame(1, Db::name('knowledge_chunks')->count());
        $response = $this->controller(['content' => ['text' => '恢复保存', 'source_refs' => ['synthetic://recovery']]])->addChunk(1);
        self::assertSame(200, $response->getCode()); self::assertTrue($response->getData()['data']['readback_verified']);
    }

    public function testReadbackFailureRollsBackAndRecoversAndTamperedReplayFails(): void
    {
        Db::execute("CREATE TRIGGER l08_corrupt AFTER INSERT ON knowledge_chunks BEGIN UPDATE knowledge_chunks SET content='{}' WHERE chunk_id=NEW.chunk_id; END");
        $payload = ['content' => ['text' => '曝光恢复测试'], 'request_id' => 'revision-readback-01'];
        try {
            self::assertSame(500, $this->controller($payload)->addChunk(1)->getCode());
            self::assertSame(1, Db::name('knowledge_chunks')->count());
        } finally { Db::execute('DROP TRIGGER l08_corrupt'); }
        $response = $this->controller($payload)->addChunk(1)->getData();
        self::assertTrue($response['data']['readback_verified']);
        $chunk = $response['data']['chunk']; $content = $chunk['content']; $content['text'] = 'tampered synthetic';
        Db::name('knowledge_chunks')->where('chunk_id', $chunk['chunk_id'])->update(['content' => json_encode($content)]);
        self::assertSame(500, $this->controller($payload)->addChunk(1)->getCode());
    }
}
