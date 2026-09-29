<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingQuestionReadbackIntegrityTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_question_integrity_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        foreach (['hotel_operating_questions', 'hotels'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (80,10,'Hotel 80',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
    }

    public function testReadRejectsTamperedAnswerJson(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'answer_json' => json_encode(['status' => 'evidence_ready', 'summary' => '篡改后的分析'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testReadRejectsTamperedEvidenceReferences(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'fact_refs_json' => json_encode(['online_daily_data#999'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testReadRejectsTamperedMirroredStatus(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'answer_status' => 'answered_by_grounded_ai',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testReadRejectsTamperedDataGapMirror(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'data_gaps_json' => json_encode([['code' => 'fabricated_gap']], JSON_UNESCAPED_UNICODE),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testReadRejectsTamperedScopeIdentity(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'platform' => 'ctrip',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testHistoryReturnsTheSameVerifiedRecordAsExactReadback(): void
    {
        [$service, $id] = $this->savedQuestion();
        $history = $service->list(10, [80], 80);

        self::assertSame('ok', $history['data_status']);
        self::assertSame(1, $history['count']);
        self::assertSame($service->read($id, 10, [80]), $history['list'][0]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('historyDriftCases')]
    public function testHistoryRejectsDriftInsteadOfDisplayingItAsSaved(array $changes): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update($changes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->list(10, [80], 80);
    }

    public static function historyDriftCases(): array
    {
        return [
            'summary' => [['answer_summary' => '未经核验的经营结论']],
            'answer' => [['answer_json' => '{invalid json']],
            'facts' => [['fact_refs_json' => '["online_daily_data#999"]']],
            'scope' => [['platform' => 'ctrip']],
            'quality state' => [['answer_status' => 'answered_by_grounded_ai']],
        ];
    }

    public function testHistoryDoesNotReadCorruptRecordsOutsideTheAuthorizedScope(): void
    {
        [$service, $id] = $this->savedQuestion();
        $foreign = Db::name(OperatingQuestionService::TABLE)->where('id', $id)->find();
        unset($foreign['id']);
        $foreign['tenant_id'] = 11;
        $foreign['hotel_id'] = 81;
        $foreign['answer_json'] = '{invalid json';
        Db::name(OperatingQuestionService::TABLE)->insert($foreign);

        $history = $service->list(10, [80], 80);
        self::assertSame(1, $history['count']);
        self::assertSame($id, $history['list'][0]['id']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conflictingFactCases')]
    public function testConflictingFactCannotBeSavedAsVerifiedEvidence(array $changes): void
    {
        $fact = array_replace($this->verifiedFact(), $changes);
        $modelCalls = 0;
        $service = new OperatingQuestionService(
            static fn(): array => ['facts' => [$fact], 'fact_count' => 1],
            static function () use (&$modelCalls): array {
                $modelCalls++;
                return ['ok' => false, 'status' => 'fixture_model_unavailable'];
            }
        );
        $failure = null;
        try {
            $service->create(10, 80, '当前选择范围最需要复核什么？', 'meituan', '2026-08-23', '2026-08-23', 7);
        } catch (RuntimeException $error) {
            $failure = $error;
        }

        self::assertSame(0, Db::name(OperatingQuestionService::TABLE)->count());
        self::assertSame(0, $modelCalls, 'conflicting source evidence must be rejected before model processing');
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertStringContainsString('operating_question_fact_packet_invalid', $failure->getMessage());
        self::assertSame(422, $failure->getCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conflictingHistoricalFactCases')]
    public function testPreviouslySavedConflictingFactCannotPassExactOrListReadback(array $changes): void
    {
        [$service, $id] = $this->savedQuestion();
        $row = Db::name(OperatingQuestionService::TABLE)->where('id', $id)->find();
        $answer = json_decode($row['answer_json'], true, flags: JSON_THROW_ON_ERROR);
        $answer['fact_samples'][0] = array_replace($this->verifiedFact(), $changes);
        // Reproduce an internally consistent legacy row: the missing check is semantic,
        // so an ordinary stale hash must not be the reason this fixture is rejected.
        $this->saveCoherentAnswer($service, $id, $answer);

        foreach (['read', 'list'] as $method) {
            $failure = null;
            try {
                $method === 'read' ? $service->read($id, 10, [80]) : $service->list(10, [80], 80);
            } catch (RuntimeException $error) {
                $failure = $error;
            }
            self::assertInstanceOf(RuntimeException::class, $failure, $method);
            self::assertStringContainsString('operating_question_fact_packet_invalid', $failure->getMessage());
        }
    }

    public static function conflictingFactCases(): array
    {
        return [
            'wrong tenant' => [['tenant_id' => 11]],
            'wrong hotel' => [['system_hotel_id' => 81]],
            'earlier business date' => [['data_date' => '2026-08-22']],
            'later business date' => [['data_date' => '2026-08-24']],
            'invalid calendar date' => [['data_date' => '2026-02-30']],
            'readback failed' => [['readback_status' => 'readback_failed']],
            'unverified readback flag' => [['readback_verified' => false]],
            'failed history' => [['history_status' => 'failed']],
            'failed validation' => [['validation_status' => 'failed']],
            'failed quality' => [['quality_status' => 'unverified']],
        ];
    }

    public static function conflictingHistoricalFactCases(): array
    {
        return self::conflictingFactCases() + [
            'wrong platform' => [['platform' => 'ctrip']],
            'wrong legacy platform' => [['platform' => '', 'source' => 'ctrip']],
        ];
    }

    public function testExplicitOtherPlatformFactRemainsExcludedAcrossSaveAndReadback(): void
    {
        $fact = array_replace($this->verifiedFact(), ['platform' => 'ctrip']);
        $service = new OperatingQuestionService(static fn(): array => ['facts' => [$fact], 'fact_count' => 1]);
        $created = $service->create(10, 80, '当前选择范围最需要复核什么？', 'meituan', '2026-08-23', '2026-08-23', 7);
        $saved = $created['question'];
        self::assertSame('blocked_by_missing_facts', $saved['answer_status']);
        self::assertSame([], $saved['answer']['fact_samples']);
        self::assertSame([], $saved['fact_refs']);
        self::assertSame($saved, $service->read($saved['id'], 10, [80]));
        self::assertSame($saved, $service->list(10, [80], 80)['list'][0]);
    }

    public function testMissingEvidenceRemainsBlockedAcrossSaveListAndExactReadback(): void
    {
        $service = new OperatingQuestionService(static fn(): array => ['facts' => []]);
        $created = $service->create(10, 80, '当前选择范围最需要复核什么？', 'meituan', '2026-08-23', '2026-08-23', 7);
        $saved = $created['question'];
        self::assertSame('blocked_by_missing_facts', $saved['answer_status']);
        self::assertSame([], $saved['fact_refs']);
        self::assertNotEmpty($saved['data_gaps']);
        self::assertSame($saved, $service->read($saved['id'], 10, [80]));
        self::assertSame($saved, $service->list(10, [80], 80)['list'][0]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedSourceCases')]
    public function testFailedSourcesRemainExplanationOnlyAcrossSaveListAndExactReadback(array $changes): void
    {
        $fact = array_replace($this->verifiedFact(), $changes);
        $modelCalls = 0;
        $service = new OperatingQuestionService(
            static fn(): array => ['facts' => [$fact], 'fact_count' => 1],
            static function () use (&$modelCalls): array { $modelCalls++; return []; },
            static fn(): array => self::blockedFactResult()
        );
        $created = $service->create(10, 80, '美团曝光量是多少？', 'meituan', '2026-08-23', '2026-08-23', 7);
        $saved = $created['question'];
        self::assertSame('blocked_by_canonical_fact_status', $saved['answer_status']);
        self::assertSame('blocked', $saved['analysis_quality_receipt']['claim_status']);
        self::assertFalse($saved['analysis_quality_receipt']['usage_policy']['analysis_claim_allowed']);
        self::assertNull($saved['answer']['precise_result']['value']);
        self::assertSame([$fact], $saved['answer']['fact_samples']);
        self::assertSame([$fact['ref']], $saved['fact_refs']);
        self::assertSame([], $saved['answer']['action_drafts']);
        self::assertSame(0, $modelCalls);
        self::assertSame($saved, $service->read($saved['id'], 10, [80]));
        self::assertSame($saved, $service->list(10, [80], 80)['list'][0]);
    }

    public static function blockedSourceCases(): array
    {
        return [
            'canonical caliber conflict' => [['quality_status' => 'caliber_uncertain']],
            'failed readback' => [['readback_status' => 'readback_failed', 'readback_verified' => false]],
            'failed history' => [['history_status' => 'failed']],
            'failed validation' => [['validation_status' => 'failed']],
            'unverified quality' => [['quality_status' => 'unverified']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeBlockedResultCases')]
    public function testDeterministicAnswerCannotUpgradeFailedSources(array $changes): void
    {
        $fact = array_replace($this->verifiedFact(), ['quality_status' => 'unverified']);
        $result = array_replace_recursive(self::blockedFactResult(), $changes);
        $service = new OperatingQuestionService(
            static fn(): array => ['facts' => [$fact], 'fact_count' => 1],
            null,
            static fn(): array => $result
        );
        try {
            $service->create(10, 80, '美团曝光量是多少？', 'meituan', '2026-08-23', '2026-08-23', 7);
            self::fail('failed source must not support a successful or numeric answer');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('operating_question_fact_packet_invalid', $error->getMessage());
        }
        self::assertSame(0, Db::name(OperatingQuestionService::TABLE)->count());

        $service = new OperatingQuestionService(
            static fn(): array => ['facts' => [$fact], 'fact_count' => 1],
            null,
            static fn(): array => self::blockedFactResult()
        );
        $created = $service->create(10, 80, '美团曝光量是多少？', 'meituan', '2026-08-23', '2026-08-23', 7);
        $id = $created['question']['id'];
        $answer = array_replace_recursive($created['question']['answer'], $changes);
        $this->saveCoherentAnswer($service, $id, $answer);
        foreach (['read', 'list'] as $method) {
            try {
                $method === 'read' ? $service->read($id, 10, [80]) : $service->list(10, [80], 80);
                self::fail($method . ' must reject a blocked explanation upgraded with failed sources');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('operating_question_fact_packet_invalid', $error->getMessage());
            }
        }
    }

    public static function unsafeBlockedResultCases(): array
    {
        return [
            'successful status' => [['status' => 'answered_from_canonical_closure']],
            'numeric value' => [['precise_result' => ['value' => 1422]]],
            'verified result' => [['precise_result' => ['verification_status' => 'verified']]],
            'padded verified result' => [['precise_result' => ['verification_status' => ' VERIFIED ']]],
            'missing reason' => [['precise_result' => ['blocked_reason' => '']]],
        ];
    }

    public function testBlockedExplanationCannotBypassSourceHotelIsolation(): void
    {
        $fact = array_replace($this->verifiedFact(), ['system_hotel_id' => 81, 'quality_status' => 'unverified']);
        $service = new OperatingQuestionService(
            static fn(): array => ['facts' => [$fact], 'fact_count' => 1],
            null,
            static fn(): array => self::blockedFactResult()
        );
        try {
            $service->create(10, 80, '美团曝光量是多少？', 'meituan', '2026-08-23', '2026-08-23', 7);
            self::fail('blocked answers must still reject another hotel source');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('operating_question_fact_packet_invalid', $error->getMessage());
        }
        self::assertSame(0, Db::name(OperatingQuestionService::TABLE)->count());
    }

    private function saveCoherentAnswer(OperatingQuestionService $service, int $id, array $answer): void
    {
        $row = Db::name(OperatingQuestionService::TABLE)->where('id', $id)->find();
        $digest = (new \ReflectionMethod(OperatingQuestionService::class, 'digest'))->invoke($service, [
            'question' => $row['question_text'], 'answer' => $answer,
            'fact_refs' => json_decode($row['fact_refs_json'], true),
            'memory_refs' => json_decode($row['memory_refs_json'], true),
            'knowledge_refs' => json_decode($row['knowledge_refs_json'], true),
            'execution_refs' => json_decode($row['execution_refs_json'], true),
        ]);
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'answer_json' => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'answer_status' => $answer['status'], 'answer_summary' => $answer['summary'],
            'content_digest' => $digest,
        ]);
    }

    private static function blockedFactResult(): array
    {
        return [
            'status' => 'blocked_by_canonical_fact_status',
            'summary' => '事实尚未核验，暂不能提供该经营数值。',
            'precise_result' => [
                'value' => null, 'verification_status' => 'unverified',
                'blocked_reason' => '事实尚未核验',
            ],
            'query_router' => ['contract_version' => 'suxi_precise_query_router.v1'],
            'used_evidence_refs' => ['online_daily_data#102476'],
            'data_gaps' => [['code' => 'source_unverified', 'message' => '事实尚未核验']],
        ];
    }

    private function verifiedFact(): array
    {
        return [
            'ref' => 'online_daily_data#102476', 'tenant_id' => 10, 'system_hotel_id' => 80,
            'data_date' => '2026-08-23', 'platform' => 'meituan', 'data_type' => 'traffic',
            'history_status' => 'success', 'validation_status' => 'verified', 'quality_status' => 'verified',
            'readback_status' => 'readback_verified', 'readback_verified' => true,
            'metric_values' => ['list_exposure' => 1422], 'metric_units' => ['list_exposure' => 'exposure_count'],
        ];
    }

    /** @return array{OperatingQuestionService,int} */
    private function savedQuestion(): array
    {
        $service = new OperatingQuestionService(static fn(): array => [
            'facts' => [[
                'ref' => 'online_daily_data#102476',
                'data_date' => '2026-08-23',
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'history_status' => 'success',
                'validation_status' => 'verified',
                'readback_status' => 'readback_verified',
                'readback_verified' => true,
                'metric_values' => ['list_exposure' => 1422],
                'metric_units' => ['list_exposure' => 'exposure_count'],
            ]],
            'fact_count' => 1,
            'fact_platform_counts' => ['meituan' => 1],
            'fact_platform_dates' => ['meituan' => ['2026-08-23']],
            'memories' => [],
            'diagnoses' => [],
            'knowledge' => [],
            'executions' => [],
        ]);
        $created = $service->create(
            10,
            80,
            '当前选择范围最需要复核什么？',
            'meituan',
            '2026-08-23',
            '2026-08-23',
            7
        );
        self::assertSame('readback_verified', $created['persistence_status']);
        self::assertSame('passed', $created['question']['analysis_quality_receipt']['quality_status']);
        self::assertSame('limited', $created['question']['analysis_quality_receipt']['claim_status']);
        $exact = $service->read((int)$created['question']['id'], 10, [80]);
        self::assertSame(
            $created['question']['analysis_quality_receipt']['receipt_digest'],
            $exact['analysis_quality_receipt']['receipt_digest']
        );
        return [$service, (int)$created['question']['id']];
    }
}
