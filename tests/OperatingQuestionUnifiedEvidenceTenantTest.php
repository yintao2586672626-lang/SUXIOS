<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionKnowledgeRetrievalService;
use app\service\OperatingQuestionUnifiedEvidenceService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingQuestionUnifiedEvidenceTenantTest extends TestCase
{
    private static array $databaseConfig = [];
    private static string $sqlitePath = '';
    private const QUESTION = '携程曝光下降怎么复核';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$databaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'unified_evidence_tenant_' . bin2hex(random_bytes(8)) . '.sqlite';
        Config::set(['default' => 'sqlite', 'connections' => ['sqlite' => [
            'type' => 'sqlite', 'database' => self::$sqlitePath, 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE knowledge_units (unit_id INTEGER PRIMARY KEY, hotel_id INTEGER, created_by INTEGER, name TEXT, source TEXT, status TEXT, description TEXT, lifecycle_status TEXT, reviewed_at TEXT, review_due_at TEXT)');
        Db::execute('CREATE TABLE knowledge_chunks (chunk_id INTEGER PRIMARY KEY, unit_id INTEGER, type TEXT, content TEXT, lifecycle_status TEXT)');
        foreach ([1 => 10, 2 => 11] as $id => $tenantId) {
            Db::name('knowledge_units')->insert([
                'unit_id' => $id, 'hotel_id' => 80, 'created_by' => 7,
                'name' => 'synthetic 携程曝光复核', 'source' => 'manual', 'status' => 'done',
                'description' => '曝光来源核对', 'lifecycle_status' => 'active',
                'reviewed_at' => '2026-09-01', 'review_due_at' => '2099-12-31',
            ]);
            Db::name('knowledge_chunks')->insert([
                'chunk_id' => 100 + $id, 'unit_id' => $id, 'type' => '运营SOP', 'lifecycle_status' => 'active',
                'content' => json_encode([
                    'scope' => 'generic_methodology', 'evidence_level' => 'reviewed_method',
                    'source_refs' => ['synthetic://unified-evidence/' . $id], 'platforms' => ['ctrip'],
                    'tenant_ids' => [$tenantId],
                    'steps' => ['携程曝光下降时先核验列表曝光与详情访客口径。'],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect('sqlite')->close();
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath)) unlink(self::$sqlitePath);
    }

    public function testDefaultUnifiedLoaderRetrievesOnlyTheValidatedTenantKnowledge(): void
    {
        foreach ([10 => 101, 11 => 102] as $tenantId => $chunkId) {
            $scope = $this->scope(['tenant_id' => $tenantId]);
            $direct = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
                80, 7, 'ctrip', self::QUESTION, ['tenant_id' => $tenantId]
            );
            self::assertSame(['knowledge_chunks#' . $chunkId], array_column($direct['items'], 'ref'),
                'The real retrieval contract can read the synthetic tenant fixture.');

            $result = (new OperatingQuestionUnifiedEvidenceService())->collectSource('knowledge', $scope, self::QUESTION);
            self::assertSame(['knowledge_chunks#' . $chunkId], $result['evidence_refs'],
                'The default unified loader must preserve the validated tenant scope.');
            self::assertSame('matched', $result['status']);
            self::assertSame($tenantId, $result['scope']['tenant_id']);
            self::assertSame('reference_only', $result['items'][0]['usage_policy']);
            self::assertFalse($result['items'][0]['decision_safe']);
            self::assertFalse($result['boundaries']['hotel_fact_created']);
            self::assertFalse($result['boundaries']['external_write_authorized']);
        }
        self::assertSame(2, Db::name('knowledge_chunks')->count(), 'Retrieval does not write knowledge.');
    }

    public function testDefaultUnifiedLoaderDoesNotBorrowAnotherTenantHotelOrUserKnowledge(): void
    {
        foreach ([['tenant_id' => 12], ['hotel_id' => 81], ['user_id' => 8]] as $outsideScope) {
            $result = (new OperatingQuestionUnifiedEvidenceService())->collectSource(
                'knowledge', $this->scope($outsideScope), self::QUESTION
            );
            self::assertSame('no_match', $result['status']);
            self::assertSame([], $result['items']);
            self::assertSame([], $result['evidence_refs']);
        }
    }

    public function testMissingOrInvalidTrustedIdentityIsRejectedBeforeRetrieval(): void
    {
        foreach (['tenant_id', 'hotel_id', 'user_id'] as $field) {
            foreach ([null, 0, -1] as $value) {
                $scope = $this->scope([$field => $value]);
                if ($value === null) unset($scope[$field]);
                try {
                    (new OperatingQuestionUnifiedEvidenceService())->collectSource('knowledge', $scope, self::QUESTION);
                    self::fail('Missing or invalid ' . $field . ' must not reach default retrieval.');
                } catch (InvalidArgumentException $error) {
                    self::assertSame('统一证据检索范围无效', $error->getMessage());
                }
            }
        }
    }

    private function scope(array $overrides = []): array
    {
        return array_replace([
            'tenant_id' => 10, 'hotel_id' => 80, 'user_id' => 7, 'platform' => 'ctrip',
            'date_start' => '2026-09-07', 'date_end' => '2026-09-07',
        ], $overrides);
    }
}
