<?php
declare(strict_types=1);

namespace Tests;

require_once dirname(__DIR__) . '/app/service/AiSuggestionCalibrationService.php';

use app\service\AiSuggestionCalibrationService;
use app\service\DailyOneThingPersonalizationService;
use app\service\DailyOneThingService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class AiSuggestionCalibrationServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_ai_suggestion_calibration_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';

        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);

        Db::execute('CREATE TABLE ai_suggestion_calibration_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            suggestion_key VARCHAR(120) NOT NULL,
            scenario VARCHAR(120) NOT NULL,
            source_key VARCHAR(120) NOT NULL,
            source_version VARCHAR(120) NOT NULL,
            evidence_digest CHAR(64) NOT NULL,
            identity_digest CHAR(64) NOT NULL,
            suggestion_payload_json TEXT NOT NULL,
            confidence DECIMAL(6,5) NULL,
            content_digest CHAR(64) NOT NULL,
            idempotency_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (tenant_id, user_id, hotel_id, suggestion_key),
            UNIQUE (tenant_id, user_id, hotel_id, identity_digest),
            UNIQUE (tenant_id, user_id, hotel_id, idempotency_hash)
        )');
        Db::execute('CREATE TABLE ai_suggestion_calibration_feedback_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            suggestion_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            suggestion_identity_digest CHAR(64) NOT NULL,
            idempotency_hash CHAR(64) NOT NULL,
            feedback_status VARCHAR(30) NOT NULL,
            reason_code VARCHAR(100) NOT NULL DEFAULT \'\',
            reason_note VARCHAR(1000) NOT NULL DEFAULT \'\',
            feedback_payload_json TEXT NOT NULL,
            content_digest CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (tenant_id, user_id, hotel_id, suggestion_id, idempotency_hash)
        )');
        Db::execute('CREATE TABLE ai_suggestion_calibration_observation_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            suggestion_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            suggestion_identity_digest CHAR(64) NOT NULL,
            idempotency_hash CHAR(64) NOT NULL,
            execution_status VARCHAR(30) NULL,
            review_result VARCHAR(30) NULL,
            observed_at DATETIME NOT NULL,
            evidence_digest CHAR(64) NULL,
            evidence_payload_json TEXT NOT NULL,
            content_digest CHAR(64) NOT NULL,
            causal_claim VARCHAR(20) NOT NULL DEFAULT \'none\',
            created_at DATETIME NOT NULL,
            UNIQUE (tenant_id, user_id, hotel_id, suggestion_id, idempotency_hash)
        )');
        Db::execute('CREATE TABLE ai_suggestion_strategy_comparisons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            comparison_key VARCHAR(120) NOT NULL,
            idempotency_hash CHAR(64) NOT NULL,
            mode VARCHAR(20) NOT NULL,
            scenario VARCHAR(120) NOT NULL,
            evaluation_set VARCHAR(120) NOT NULL,
            baseline_version VARCHAR(120) NOT NULL,
            candidate_version VARCHAR(120) NOT NULL,
            evaluation_snapshot_digest CHAR(64) NOT NULL,
            comparison_json TEXT NOT NULL,
            rollback_metadata_json TEXT NOT NULL,
            activation_status VARCHAR(30) NOT NULL,
            decision_effect VARCHAR(20) NOT NULL,
            external_call_status VARCHAR(20) NOT NULL,
            business_write_status VARCHAR(20) NOT NULL,
            causal_claim VARCHAR(20) NOT NULL,
            content_digest CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (tenant_id, user_id, hotel_id, comparison_key),
            UNIQUE (tenant_id, user_id, hotel_id, idempotency_hash)
        )');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        foreach ([
            'corrupt_ai_snapshot_after_insert',
            'corrupt_ai_feedback_after_insert',
            'corrupt_ai_observation_after_insert',
            'corrupt_ai_comparison_after_insert',
            'corrupt_only_new_ai_snapshot_after_insert',
        ] as $trigger) {
            Db::execute('DROP TRIGGER IF EXISTS ' . $trigger);
        }
        Db::name('ai_suggestion_strategy_comparisons')->delete(true);
        Db::name('ai_suggestion_calibration_observation_events')->delete(true);
        Db::name('ai_suggestion_calibration_feedback_events')->delete(true);
        Db::name('ai_suggestion_calibration_snapshots')->delete(true);
    }

    public function testMigrationIsAppendOnlyLearningSchemaWithoutBusinessDataMutation(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260829_z_create_ai_suggestion_calibration.sql'
        );
        self::assertIsString($sql);
        foreach ([
            'ai_suggestion_calibration_snapshots',
            'ai_suggestion_calibration_feedback_events',
            'ai_suggestion_calibration_observation_events',
            'ai_suggestion_strategy_comparisons',
        ] as $table) {
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `' . $table . '`', $sql);
        }
        self::assertStringContainsString("DEFAULT 'not_activated'", $sql);
        self::assertStringContainsString("DEFAULT 'not_called'", $sql);
        self::assertStringContainsString("DEFAULT 'none'", $sql);
        self::assertDoesNotMatchRegularExpression('/^\\s*(ALTER|INSERT|UPDATE|DELETE)\\b/im', $sql);
    }

    public function testEveryCalibrationWriterRollsBackWhenExactReadbackIsCorrupted(): void
    {
        $service = $this->service();
        $corruptDigest = str_repeat('0', 64);

        Db::execute(
            "CREATE TRIGGER corrupt_ai_snapshot_after_insert "
            . "AFTER INSERT ON ai_suggestion_calibration_snapshots BEGIN "
            . "UPDATE ai_suggestion_calibration_snapshots SET content_digest = '$corruptDigest' "
            . "WHERE id = NEW.id; END"
        );
        $this->assertAtomicReadbackRollback(
            fn(): array => $service->freezeSuggestion($this->suggestionInput(1, 0.82)),
            'AI suggestion snapshot integrity verification failed',
            'ai_suggestion_calibration_snapshots'
        );
        Db::execute('DROP TRIGGER corrupt_ai_snapshot_after_insert');

        $service->freezeSuggestion($this->suggestionInput(2, 0.72));
        Db::execute(
            "CREATE TRIGGER corrupt_ai_feedback_after_insert "
            . "AFTER INSERT ON ai_suggestion_calibration_feedback_events BEGIN "
            . "UPDATE ai_suggestion_calibration_feedback_events SET content_digest = '$corruptDigest' "
            . "WHERE id = NEW.id; END"
        );
        $this->assertAtomicReadbackRollback(
            fn(): array => $service->appendFeedback([
                ...$this->scope(),
                'suggestion_key' => 'suggestion-2',
                'feedback_status' => 'accepted',
                'reason_code' => 'bounded_test',
                'reason_note' => 'fault-injected readback',
                'idempotency_key' => 'feedback-atomicity-1',
            ]),
            'AI suggestion feedback integrity verification failed',
            'ai_suggestion_calibration_feedback_events'
        );
        Db::execute('DROP TRIGGER corrupt_ai_feedback_after_insert');

        Db::execute(
            "CREATE TRIGGER corrupt_ai_observation_after_insert "
            . "AFTER INSERT ON ai_suggestion_calibration_observation_events BEGIN "
            . "UPDATE ai_suggestion_calibration_observation_events SET content_digest = '$corruptDigest' "
            . "WHERE id = NEW.id; END"
        );
        $this->assertAtomicReadbackRollback(
            fn(): array => $service->appendExecutionReview([
                ...$this->scope(),
                'suggestion_key' => 'suggestion-2',
                'execution_status' => 'executed',
                'review_result' => 'supported',
                'observed_at' => '2026-08-29 10:00:00',
                'evidence_digest' => hash('sha256', 'observation-atomicity-1'),
                'idempotency_key' => 'observation-atomicity-1',
            ]),
            'AI suggestion observation integrity verification failed',
            'ai_suggestion_calibration_observation_events'
        );
        Db::execute('DROP TRIGGER corrupt_ai_observation_after_insert');

        Db::execute(
            "CREATE TRIGGER corrupt_ai_comparison_after_insert "
            . "AFTER INSERT ON ai_suggestion_strategy_comparisons BEGIN "
            . "UPDATE ai_suggestion_strategy_comparisons SET content_digest = '$corruptDigest' "
            . "WHERE id = NEW.id; END"
        );
        $this->assertAtomicReadbackRollback(
            fn(): array => $service->recordStrategyComparison([
                ...$this->scope(),
                'comparison_key' => 'atomicity-candidate',
                'idempotency_key' => 'comparison-atomicity-1',
                'mode' => 'offline',
                'scenario' => 'daily_one_thing',
                'evaluation_set' => 'bounded-fixture',
                'baseline_version' => 'ranker.v1',
                'candidate_version' => 'ranker.v2-candidate',
                'evaluation_snapshot_digest' => hash('sha256', 'bounded-fixture'),
                'baseline_metrics' => ['acceptance_rate' => 0.4],
                'candidate_metrics' => ['acceptance_rate' => 0.5],
                'rollback_metadata' => [
                    'target_version' => 'ranker.v1',
                    'trigger' => 'readback drift',
                    'procedure' => 'discard candidate',
                ],
            ]),
            'AI strategy comparison integrity verification failed',
            'ai_suggestion_strategy_comparisons'
        );
    }

    public function testCorruptedNewSnapshotRollsBackOnlyThatInsertAndPreservesPriorEvidence(): void
    {
        $service = $this->service();
        $existing = $service->freezeSuggestion($this->suggestionInput(91, 0.81));
        $corruptDigest = str_repeat('0', 64);
        Db::execute(
            "CREATE TRIGGER corrupt_only_new_ai_snapshot_after_insert "
            . "AFTER INSERT ON ai_suggestion_calibration_snapshots BEGIN "
            . "UPDATE ai_suggestion_calibration_snapshots SET content_digest = '$corruptDigest' "
            . "WHERE id = NEW.id; END"
        );

        try {
            $service->freezeSuggestion($this->suggestionInput(92, 0.79));
            self::fail('the corrupted new snapshot must roll back without touching earlier evidence');
        } catch (RuntimeException $error) {
            self::assertSame('AI suggestion snapshot integrity verification failed', $error->getMessage());
        }

        $rows = Db::name('ai_suggestion_calibration_snapshots')->order('id', 'asc')->select()->toArray();
        self::assertCount(1, $rows);
        self::assertSame((int)$existing['id'], (int)$rows[0]['id']);
        self::assertSame('suggestion-91', (string)$rows[0]['suggestion_key']);
        self::assertSame((string)$existing['content_digest'], (string)$rows[0]['content_digest']);
    }

    public function testFrozenSuggestionIsIdempotentAndReadbackIsExactToUserHotelScope(): void
    {
        $service = $this->service();
        $input = $this->suggestionInput(1, 0.82);

        $first = $service->freezeSuggestion($input);
        $replay = $service->freezeSuggestion($input);
        $readback = $service->readExact(7, 11, 80, 'suggestion-1');

        self::assertTrue($first['created']);
        self::assertTrue($first['readback_verified']);
        self::assertFalse($first['idempotent_replay']);
        self::assertFalse($replay['created']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($first['id'], $replay['id']);
        self::assertSame($first['identity_digest'], $readback['identity_digest']);
        self::assertSame('daily_one_thing.v2', $readback['source_version']);
        self::assertSame(0.82, $readback['confidence']);
        self::assertTrue($readback['frozen']);
        self::assertSame([], $readback['feedback_events']);
        self::assertSame([], $readback['observation_events']);
        self::assertNull($service->readExact(7, 12, 80, 'suggestion-1'));
        self::assertNull($service->readExact(7, 11, 81, 'suggestion-1'));
        self::assertSame(1, (int)Db::name('ai_suggestion_calibration_snapshots')->count());
    }

    public function testIdempotencyConflictCannotOverwriteFrozenSuggestion(): void
    {
        $service = $this->service();
        $input = $this->suggestionInput(1, 0.82);
        $service->freezeSuggestion($input);
        $input['suggestion_payload']['title'] = 'changed after freeze';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idempotency conflict');
        $service->freezeSuggestion($input);
    }

    public function testClientIdempotencyValueIsStoredOnlyAsOneWayHash(): void
    {
        $service = $this->service();
        $input = $this->suggestionInput(1, 0.82);
        $input['idempotency_key'] = 'plain-client-retry-marker';
        $saved = $service->freezeSuggestion($input);
        $stored = (string)Db::name('ai_suggestion_calibration_snapshots')
            ->where('id', $saved['id'])
            ->value('idempotency_hash');

        self::assertSame(hash('sha256', 'plain-client-retry-marker'), $stored);
        self::assertSame($stored, $saved['idempotency_hash']);
        self::assertArrayNotHasKey('idempotency_key', $saved);
    }

    public function testCredentialLikeSuggestionAndFeedbackPayloadsAreRejected(): void
    {
        $service = $this->service();
        $input = $this->suggestionInput(1, 0.82);
        $input['suggestion_payload']['note'] = 'token=very-sensitive-value';
        try {
            $service->freezeSuggestion($input);
            self::fail('Credential-like suggestion payload must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('sensitive credential material', $exception->getMessage());
        }

        $valid = $this->suggestionInput(2, 0.62);
        $service->freezeSuggestion($valid);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sensitive credential material');
        $service->appendFeedback([
            ...$this->scope(),
            'suggestion_key' => 'suggestion-2',
            'feedback_status' => 'rejected',
            'reason_code' => 'wrong_focus',
            'reason_note' => 'Cookie=very-sensitive-value',
            'idempotency_key' => 'sensitive-feedback-001',
        ]);
    }

    public function testFeedbackObservationMetricsAndCalibrationStayDescriptive(): void
    {
        $service = $this->service();
        $statuses = [
            'accepted',
            'modified',
            'rejected',
            'needs_more_evidence',
            'deferred',
        ];
        $confidences = [0.90, 0.80, 0.40, 0.70, 0.30];
        foreach ($statuses as $offset => $status) {
            $number = $offset + 1;
            $service->freezeSuggestion($this->suggestionInput($number, $confidences[$offset]));
            $feedbackInput = [
                ...$this->scope(),
                'suggestion_key' => 'suggestion-' . $number,
                'idempotency_key' => 'feedback-' . $number,
                'feedback_status' => $status,
                'reason_code' => 'user_decision',
                'reason_note' => 'bounded test feedback ' . $number,
                'feedback_payload' => ['sequence' => $number],
            ];
            $first = $service->appendFeedback($feedbackInput);
            $replay = $service->appendFeedback($feedbackInput);
            self::assertTrue($first['readback_verified']);
            self::assertTrue($replay['idempotent_replay']);
        }

        $firstObservationInput = [
            ...$this->scope(),
            'suggestion_key' => 'suggestion-1',
            'idempotency_key' => 'observation-1',
            'execution_status' => 'executed',
            'review_result' => 'supported',
            'observed_at' => '2026-08-29 10:00:00',
            'evidence_digest' => hash('sha256', 'review-1'),
            'evidence_payload' => ['review_scope' => 'same_hotel_same_metric'],
        ];
        $firstObservation = $service->appendExecutionReview($firstObservationInput);
        $observationReplay = $service->appendExecutionReview($firstObservationInput);
        self::assertTrue($firstObservation['created']);
        self::assertTrue($observationReplay['idempotent_replay']);
        self::assertSame($firstObservation['id'], $observationReplay['id']);
        $service->appendExecutionReview([
            ...$this->scope(),
            'suggestion_key' => 'suggestion-2',
            'idempotency_key' => 'observation-2',
            'execution_status' => 'executed',
            'review_result' => 'contradicted',
            'observed_at' => '2026-08-29 10:05:00',
            'evidence_digest' => hash('sha256', 'review-2'),
            'evidence_payload' => ['review_scope' => 'same_hotel_same_metric'],
        ]);
        $service->appendExecutionReview([
            ...$this->scope(),
            'suggestion_key' => 'suggestion-3',
            'idempotency_key' => 'observation-3',
            'execution_status' => 'not_executed',
            'observed_at' => '2026-08-29 10:10:00',
        ]);

        $insufficient = $service->summarize($this->scope(), ['minimum_samples' => 6]);
        self::assertSame('insufficient_samples', $insufficient['status']);
        self::assertSame('insufficient_samples', $insufficient['confidence_calibration']['status']);
        self::assertSame(20, $insufficient['feedback_ranking']['minimum_samples_per_topic']);
        self::assertSame(5, $insufficient['counts']['feedback_sample_count']);
        self::assertSame(0.2, $insufficient['rates']['direct_acceptance_rate']);
        self::assertSame(0.2, $insufficient['rates']['modified_acceptance_rate']);
        self::assertSame(0.2, $insufficient['rates']['rejection_rate']);
        self::assertSame(0.2, $insufficient['rates']['insufficient_evidence_rate']);
        self::assertSame(0.4, $insufficient['rates']['execution_rate']);
        self::assertSame(1.0, $insufficient['rates']['observable_review_rate']);
        self::assertSame('none', $insufficient['policy']['causal_claim']);
        self::assertFalse($insufficient['policy']['automatic_activation']);
        self::assertFalse($insufficient['policy']['external_model_calls']);
        self::assertFalse($insufficient['policy']['business_table_writes']);

        $enoughForDescriptiveCalibration = $service->summarize(
            $this->scope(),
            ['minimum_samples' => 2, 'calibration_tolerance' => 0.10]
        );
        self::assertSame('descriptive_only', $enoughForDescriptiveCalibration['status']);
        self::assertSame('over_confident', $enoughForDescriptiveCalibration['confidence_calibration']['status']);
        self::assertSame(2, $enoughForDescriptiveCalibration['confidence_calibration']['sample_count']);
        self::assertSame(0.85, $enoughForDescriptiveCalibration['confidence_calibration']['average_confidence']);
        self::assertSame(0.5, $enoughForDescriptiveCalibration['confidence_calibration']['observed_support_rate']);
        self::assertSame(0.35, $enoughForDescriptiveCalibration['confidence_calibration']['confidence_gap']);
        self::assertSame('none', $enoughForDescriptiveCalibration['confidence_calibration']['causal_claim']);

        $readback = $service->readExact(7, 11, 80, 'suggestion-1');
        self::assertCount(1, $readback['feedback_events']);
        self::assertCount(1, $readback['observation_events']);
        self::assertSame('supported', $readback['observation_events'][0]['review_result']);
        self::assertSame('none', $readback['observation_events'][0]['causal_claim']);
        self::assertSame(5, (int)Db::name('ai_suggestion_calibration_feedback_events')->count());
        self::assertSame(3, (int)Db::name('ai_suggestion_calibration_observation_events')->count());
    }

    public function testFeedbackRankingOnlyOrdersExistingTopicsAfterPerTopicThreshold(): void
    {
        $service = $this->service();
        foreach (range(1, 19) as $number) {
            foreach ([
                ['revenue-report', 'accepted', 'useful', 100],
                ['data-health', 'rejected', 'wrong_focus', 200],
            ] as [$topicKey, $status, $reasonCode, $offset]) {
                $sequence = $offset + $number;
                $input = $this->suggestionInput($sequence, 0.60);
                $input['scenario'] = 'system_guidance_operating_query';
                $input['source_key'] = 'precise_query';
                $input['source_version'] = 'precise_query.v2';
                $input['suggestion_payload']['topic_key'] = $topicKey;
                $service->freezeSuggestion($input);
                $service->appendFeedback([
                    ...$this->scope(),
                    'suggestion_key' => 'suggestion-' . $sequence,
                    'feedback_status' => $status,
                    'reason_code' => $reasonCode,
                    'idempotency_key' => 'ranking-feedback-' . $sequence,
                ]);
            }
        }

        $insufficient = $service->summarize($this->scope(), [
            'minimum_samples' => 3,
            'ranking_minimum_samples' => 20,
        ]);
        self::assertSame('insufficient_samples', $insufficient['feedback_ranking']['status']);
        self::assertSame([], $insufficient['feedback_ranking']['ranked_topic_keys']);
        self::assertSame('none', $insufficient['feedback_ranking']['effect_scope']);

        foreach ([
            [120, 'revenue-report', 'accepted', 'useful'],
            [220, 'data-health', 'rejected', 'wrong_focus'],
        ] as [$number, $topicKey, $status, $reasonCode]) {
            $input = $this->suggestionInput($number, 0.60);
            $input['scenario'] = 'system_guidance_operating_query';
            $input['source_key'] = 'precise_query';
            $input['source_version'] = 'precise_query.v2';
            $input['suggestion_payload']['topic_key'] = $topicKey;
            $service->freezeSuggestion($input);
            $service->appendFeedback([
                ...$this->scope(),
                'suggestion_key' => 'suggestion-' . $number,
                'feedback_status' => $status,
                'reason_code' => $reasonCode,
                'idempotency_key' => 'ranking-feedback-' . $number,
            ]);
        }

        $ready = $service->summarize($this->scope(), [
            'minimum_samples' => 3,
            'ranking_minimum_samples' => 20,
        ]);
        self::assertSame('ready', $ready['feedback_ranking']['status']);
        self::assertSame(
            ['revenue-report', 'data-health'],
            $ready['feedback_ranking']['ranked_topic_keys']
        );
        self::assertSame(
            'existing_quick_suggestion_order_only',
            $ready['feedback_ranking']['effect_scope']
        );
        self::assertSame(1, $ready['feedback_ranking']['items'][0]['adjustment']);
        self::assertSame(-1, $ready['feedback_ranking']['items'][1]['adjustment']);
        self::assertSame(20, $ready['feedback_ranking']['items'][0]['sample_count']);
        self::assertFalse($ready['feedback_ranking']['facts_changed']);
        self::assertFalse($ready['feedback_ranking']['permissions_changed']);
        self::assertFalse($ready['feedback_ranking']['external_write_authorized']);

        foreach (range(1, 20) as $number) {
            $sequence = 300 + $number;
            $input = $this->suggestionInput($sequence, 0.60);
            $input['scenario'] = 'system_guidance_operating_query';
            $input['source_key'] = 'precise_query';
            $input['source_version'] = 'precise_query.v3';
            $input['suggestion_payload']['topic_key'] = 'revenue-report';
            $service->freezeSuggestion($input);
            $service->appendFeedback([
                ...$this->scope(),
                'suggestion_key' => 'suggestion-' . $sequence,
                'feedback_status' => 'rejected',
                'reason_code' => 'wrong_focus',
                'idempotency_key' => 'ranking-feedback-' . $sequence,
            ]);
        }
        $versionConflict = $service->summarize($this->scope(), [
            'minimum_samples' => 3,
            'ranking_minimum_samples' => 20,
        ]);
        $revenueConflict = array_values(array_filter(
            $versionConflict['feedback_ranking']['items'],
            static fn(array $item): bool => $item['topic_key'] === 'revenue-report'
        ))[0];
        self::assertFalse($revenueConflict['eligible']);
        self::assertSame(0, $revenueConflict['adjustment']);
        self::assertSame('conflicting_source_groups', $revenueConflict['resolution_status']);
        self::assertCount(2, $revenueConflict['conflicting_source_groups']);
        self::assertSame(
            ['data-health'],
            $versionConflict['feedback_ranking']['ranked_topic_keys']
        );

        $otherUser = $service->summarize([
            'tenant_id' => 7,
            'user_id' => 12,
            'hotel_id' => 80,
        ], ['minimum_samples' => 3, 'ranking_minimum_samples' => 20]);
        self::assertSame('empty', $otherUser['feedback_ranking']['status']);
        self::assertSame([], $otherUser['feedback_ranking']['items']);
    }

    public function testDailyRankingAdjustmentsRequireTwentyIndependentLatestFeedbackSamples(): void
    {
        $service = $this->service();
        $featureIdentity = hash('sha256', 'daily-feature-ctrip-gap');
        foreach (range(1, 19) as $number) {
            $key = 'daily-preview-' . $number;
            $sampleDate = (new DateTimeImmutable('2026-08-01'))
                ->modify('+' . $number . ' days')
                ->format('Y-m-d');
            $service->freezeSuggestion([
                ...$this->scope(),
                'suggestion_key' => $key,
                'scenario' => 'daily_one_thing_selection',
                'source_key' => 'daily_one_thing_input',
                'source_version' => 'daily_one_thing_input.v1',
                'evidence_digest' => hash('sha256', 'daily-evidence-' . $number),
                'suggestion_payload' => [
                    'feature_key' => 'daily_one_thing',
                    'feature_identity' => $featureIdentity,
                    'feature_dimensions' => [
                        'source_type' => 'explicit_data_gap',
                        'platform' => 'ctrip',
                        'action_type' => 'collect_trusted_ota_facts',
                        'metric_key' => 'strict_fact_count',
                    ],
                    'candidate_key' => 'gap:ctrip:core_facts',
                    'business_date' => $sampleDate,
                ],
                'idempotency_key' => 'freeze-daily-' . $number,
            ]);
            $service->appendFeedback([
                ...$this->scope(),
                'suggestion_key' => $key,
                'feedback_status' => 'accepted',
                'reason_code' => 'useful',
                'idempotency_key' => 'daily-feedback-' . $number,
            ]);
        }

        $nineteen = $service->buildDailyRankingAdjustments($this->scope());
        self::assertSame('insufficient_samples', $nineteen['status']);
        self::assertSame(19, $nineteen['items'][0]['sample_count']);
        self::assertSame(0, $nineteen['items'][0]['adjustment']);
        self::assertFalse($nineteen['items'][0]['eligible']);

        $service->freezeSuggestion([
            ...$this->scope(),
            'suggestion_key' => 'daily-preview-20',
            'scenario' => 'daily_one_thing_selection',
            'source_key' => 'daily_one_thing_input',
            'source_version' => 'daily_one_thing_input.v1',
            'evidence_digest' => hash('sha256', 'daily-evidence-20'),
            'suggestion_payload' => [
                'feature_key' => 'daily_one_thing',
                'feature_identity' => $featureIdentity,
                'feature_dimensions' => [
                    'source_type' => 'explicit_data_gap',
                    'platform' => 'ctrip',
                    'action_type' => 'collect_trusted_ota_facts',
                    'metric_key' => 'strict_fact_count',
                ],
                'candidate_key' => 'gap:ctrip:core_facts',
                'business_date' => '2026-08-21',
            ],
            'idempotency_key' => 'freeze-daily-20',
        ]);
        $service->appendFeedback([
            ...$this->scope(),
            'suggestion_key' => 'daily-preview-20',
            'feedback_status' => 'accepted',
            'reason_code' => 'useful',
            'idempotency_key' => 'daily-feedback-20',
        ]);

        $twenty = $service->buildDailyRankingAdjustments($this->scope());
        self::assertSame('ready', $twenty['status']);
        self::assertTrue($twenty['items'][0]['eligible']);
        self::assertSame(1, $twenty['items'][0]['adjustment']);
        self::assertSame(20, $twenty['items'][0]['sample_count']);
        self::assertCount(20, $twenty['items'][0]['source_refs']);
        self::assertSame(20, $twenty['items'][0]['unique_business_date_count']);
        self::assertSame(0, $twenty['items'][0]['duplicate_sample_count']);
        self::assertFalse($twenty['facts_changed']);
        self::assertFalse($twenty['eligibility_changed']);
        self::assertFalse($twenty['external_write_authorized']);

        foreach ([1, 2] as $duplicate) {
            $key = 'daily-preview-duplicate-' . $duplicate;
            $service->freezeSuggestion([
                ...$this->scope(),
                'suggestion_key' => $key,
                'scenario' => 'daily_one_thing_selection',
                'source_key' => 'daily_one_thing_input',
                'source_version' => 'daily_one_thing_input.v1',
                'evidence_digest' => hash('sha256', 'daily-duplicate-' . $duplicate),
                'suggestion_payload' => [
                    'feature_key' => 'daily_one_thing',
                    'feature_identity' => $featureIdentity,
                    'feature_dimensions' => [
                        'source_type' => 'explicit_data_gap',
                        'platform' => 'ctrip',
                        'action_type' => 'collect_trusted_ota_facts',
                        'metric_key' => 'strict_fact_count',
                    ],
                    'candidate_key' => 'gap:ctrip:core_facts',
                    'business_date' => '2026-08-21',
                ],
                'idempotency_key' => 'freeze-daily-duplicate-' . $duplicate,
            ]);
            $service->appendFeedback([
                ...$this->scope(),
                'suggestion_key' => $key,
                'feedback_status' => 'rejected',
                'reason_code' => 'wrong_focus',
                'idempotency_key' => 'daily-feedback-duplicate-' . $duplicate,
            ]);
        }
        $deduplicated = $service->buildDailyRankingAdjustments($this->scope());
        self::assertSame(20, $deduplicated['items'][0]['sample_count']);
        self::assertSame(20, $deduplicated['items'][0]['unique_business_date_count']);
        self::assertSame(2, $deduplicated['items'][0]['duplicate_sample_count']);
        self::assertSame(2, $deduplicated['duplicate_sample_count']);
        self::assertSame(19, $deduplicated['items'][0]['positive_count']);
        self::assertSame(1, $deduplicated['items'][0]['negative_count']);

        $sameDayIdentity = hash('sha256', 'daily-feature-same-day-repeat');
        foreach (range(1, 20) as $number) {
            $key = 'daily-same-day-' . $number;
            $service->freezeSuggestion([
                ...$this->scope(),
                'suggestion_key' => $key,
                'scenario' => 'daily_one_thing_selection',
                'source_key' => 'daily_one_thing_input',
                'source_version' => 'daily_one_thing_input.v1',
                'evidence_digest' => hash('sha256', 'same-day-' . $number),
                'suggestion_payload' => [
                    'feature_key' => 'daily_one_thing',
                    'feature_identity' => $sameDayIdentity,
                    'feature_dimensions' => [
                        'source_type' => 'explicit_data_gap',
                        'platform' => 'meituan',
                        'action_type' => 'collect_trusted_ota_facts',
                        'metric_key' => 'strict_fact_count',
                    ],
                    'candidate_key' => 'gap:meituan:core_facts',
                    'business_date' => '2026-08-29',
                ],
                'idempotency_key' => 'freeze-same-day-' . $number,
            ]);
            $service->appendFeedback([
                ...$this->scope(),
                'suggestion_key' => $key,
                'feedback_status' => 'accepted',
                'reason_code' => 'useful',
                'idempotency_key' => 'feedback-same-day-' . $number,
            ]);
        }
        $sameDay = $service->buildDailyRankingAdjustments($this->scope());
        $sameDayItem = array_values(array_filter(
            $sameDay['items'],
            static fn(array $item): bool => $item['feature_identity'] === $sameDayIdentity
        ))[0];
        self::assertSame(1, $sameDayItem['sample_count']);
        self::assertSame(1, $sameDayItem['unique_business_date_count']);
        self::assertSame(19, $sameDayItem['duplicate_sample_count']);
        self::assertFalse($sameDayItem['eligible']);
        self::assertSame('insufficient_samples', $sameDayItem['status']);

        $otherUser = $service->buildDailyRankingAdjustments([
            'tenant_id' => 7,
            'user_id' => 12,
            'hotel_id' => 80,
        ]);
        self::assertSame('empty', $otherUser['status']);
        self::assertSame([], $otherUser['items']);
    }

    public function testDailyPreviewFeedbackFreezesExactSelectionWithoutBusinessWriteAuthority(): void
    {
        $calibration = $this->service();
        $personalization = new DailyOneThingPersonalizationService(
            calibration: $calibration,
            preferenceLoader: static fn(): array => ['status' => 'ready', 'items' => []],
            feedbackLoader: static fn(): array => ['status' => 'empty', 'items' => []]
        );
        $selected = (new DailyOneThingService())->select(
            [$this->dailyCandidate()],
            '2026-08-29'
        )['selected'];
        $receipt = [
            'contract_version' => DailyOneThingPersonalizationService::CONTRACT_VERSION,
            'scope' => $this->scope(),
            'status' => 'not_applied',
            'context_digest' => str_repeat('b', 64),
            'decision_digest' => str_repeat('c', 64),
        ];
        $saved = $personalization->recordFeedback(
            7,
            11,
            80,
            '2026-08-29',
            $selected,
            $receipt,
            str_repeat('d', 64),
            'accepted',
            'useful',
            'daily-preview-feedback-one'
        );

        self::assertTrue($saved['readback_verified']);
        self::assertTrue($saved['snapshot']['readback_verified']);
        self::assertTrue($saved['feedback']['readback_verified']);
        self::assertSame('daily_one_thing', $saved['snapshot']['suggestion_payload']['feature_key']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $saved['snapshot']['suggestion_payload']['feature_identity']
        );
        self::assertSame('insufficient_samples', $saved['adjustments']['status']);
        self::assertFalse($saved['facts_changed']);
        self::assertFalse($saved['permissions_changed']);
        self::assertFalse($saved['approval_changed']);
        self::assertFalse($saved['external_write_authorized']);
    }

    public function testDailyPreviewFeedbackUsesOneStableSlotAcrossContextRefreshes(): void
    {
        $calibration = $this->service();
        $personalization = new DailyOneThingPersonalizationService(
            calibration: $calibration,
            preferenceLoader: static fn(): array => [
                'status' => 'ready',
                'tenant_id' => 7,
                'user_id' => 11,
                'hotel_id' => 80,
                'items' => [],
            ],
            feedbackLoader: static fn(): array => [
                'status' => 'empty',
                'scope' => ['tenant_id' => 7, 'user_id' => 11, 'hotel_id' => 80],
                'items' => [],
            ]
        );
        $candidate = $this->dailyCandidate();
        $selected = (new DailyOneThingService())->select([$candidate], '2026-08-29')['selected'];
        $receipt = [
            'contract_version' => DailyOneThingPersonalizationService::CONTRACT_VERSION,
            'scope' => $this->scope(),
            'status' => 'not_applied',
            'context_digest' => str_repeat('b', 64),
            'decision_digest' => str_repeat('c', 64),
        ];

        $first = $personalization->recordFeedback(
            7, 11, 80, '2026-08-29', $selected, $receipt,
            str_repeat('d', 64), 'accepted', 'useful', 'daily-preview-first-attempt'
        );
        $refreshedReceipt = $receipt;
        $refreshedReceipt['context_digest'] = str_repeat('e', 64);
        $refreshedReceipt['decision_digest'] = str_repeat('f', 64);
        $replay = $personalization->recordFeedback(
            7, 11, 80, '2026-08-29', $selected, $refreshedReceipt,
            str_repeat('d', 64), 'accepted', 'useful', 'daily-preview-refreshed-attempt'
        );

        self::assertTrue($first['feedback']['created']);
        self::assertTrue($replay['feedback']['idempotent_replay']);
        self::assertFalse($first['adjustments']['history_truncated']);
        self::assertSame(5000, $first['adjustments']['maximum_snapshot_scan']);
        self::assertSame(1, Db::name('ai_suggestion_calibration_snapshots')->count());
        self::assertSame(1, Db::name('ai_suggestion_calibration_feedback_events')->count());
        self::assertSame(1, $replay['feedback_slot']['maximum_feedback_events']);

        $preview = $personalization->select([$candidate], '2026-08-29', 7, 11, 80);
        self::assertSame('recorded', $preview['personalization_receipt']['current_feedback']['status']);
        self::assertSame('useful', $preview['personalization_receipt']['current_feedback']['reason_code']);
        self::assertTrue($preview['personalization_receipt']['current_feedback']['readback_verified']);

        try {
            $personalization->recordFeedback(
                7, 11, 80, '2026-08-29', $selected, $refreshedReceipt,
                str_repeat('d', 64), 'rejected', 'wrong_focus', 'daily-preview-conflicting-attempt'
            );
            self::fail('the stable daily feedback slot must reject a silent opposite overwrite');
        } catch (RuntimeException $error) {
            self::assertSame(409, $error->getCode());
            self::assertStringContainsString('不允许静默覆盖', $error->getMessage());
        }
        self::assertSame(1, Db::name('ai_suggestion_calibration_snapshots')->count());
        self::assertSame(1, Db::name('ai_suggestion_calibration_feedback_events')->count());
    }

    public function testCandidateStrategyComparisonIsOfflineOrShadowOnlyWithRollbackMetadata(): void
    {
        $service = $this->service();
        $input = [
            ...$this->scope(),
            'comparison_key' => 'daily-one-thing-candidate-v2',
            'idempotency_key' => 'comparison-request-1',
            'mode' => 'shadow',
            'scenario' => 'daily_one_thing',
            'evaluation_set' => 'hotel-80-frozen-feedback-20260829',
            'baseline_version' => 'ranker.v1',
            'candidate_version' => 'ranker.v2-candidate',
            'evaluation_snapshot_digest' => hash('sha256', 'frozen-evaluation-set'),
            'baseline_metrics' => [
                'direct_acceptance_rate' => 0.42,
                'rejection_rate' => 0.30,
            ],
            'candidate_metrics' => [
                'direct_acceptance_rate' => 0.50,
                'rejection_rate' => 0.24,
            ],
            'rollback_metadata' => [
                'target_version' => 'ranker.v1',
                'trigger' => 'candidate comparison becomes contradictory or scope drifts',
                'procedure' => 'discard candidate comparison and keep ranker.v1 unchanged',
            ],
        ];

        $first = $service->recordStrategyComparison($input);
        $replay = $service->recordStrategyComparison($input);
        $readback = $service->readStrategyComparison(7, 11, 80, 'daily-one-thing-candidate-v2');

        self::assertTrue($first['created']);
        self::assertTrue($first['readback_verified']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($first['id'], $replay['id']);
        self::assertSame('shadow', $readback['mode']);
        self::assertSame('not_activated', $readback['activation_status']);
        self::assertSame('none', $readback['decision_effect']);
        self::assertSame('not_called', $readback['external_call_status']);
        self::assertSame('none', $readback['business_write_status']);
        self::assertSame('none', $readback['causal_claim']);
        self::assertSame('ranker.v1', $readback['rollback_metadata']['target_version']);
        self::assertEqualsWithDelta(
            0.08,
            $readback['comparison']['metric_deltas']['direct_acceptance_rate'],
            0.000001
        );
        self::assertNull($service->readStrategyComparison(7, 12, 80, 'daily-one-thing-candidate-v2'));
        self::assertSame(1, (int)Db::name('ai_suggestion_strategy_comparisons')->count());

        try {
            $service->recordStrategyComparison([
                ...$input,
                'comparison_key' => 'forbidden-activation',
                'idempotency_key' => 'forbidden-activation-request',
                'activate' => true,
            ]);
            self::fail('candidate strategy comparison must reject automatic activation');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('not permitted', $error->getMessage());
        }
    }

    private function service(): AiSuggestionCalibrationService
    {
        return new AiSuggestionCalibrationService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-29 09:30:00')
        );
    }

    private function assertAtomicReadbackRollback(
        callable $writer,
        string $expectedMessage,
        string $table
    ): void {
        try {
            $writer();
            self::fail('a corrupted exact readback must roll back its insert');
        } catch (RuntimeException $error) {
            self::assertSame($expectedMessage, $error->getMessage());
        }
        self::assertSame(0, (int)Db::name($table)->count());
    }

    /** @return array{tenant_id:int,user_id:int,hotel_id:int} */
    private function scope(): array
    {
        return [
            'tenant_id' => 7,
            'user_id' => 11,
            'hotel_id' => 80,
        ];
    }

    /** @return array<string,mixed> */
    private function suggestionInput(int $number, float $confidence): array
    {
        return [
            ...$this->scope(),
            'suggestion_key' => 'suggestion-' . $number,
            'scenario' => 'daily_one_thing',
            'source_key' => 'daily_priority_ranker',
            'source_version' => 'daily_one_thing.v2',
            'evidence_digest' => hash('sha256', 'evidence-' . $number),
            'suggestion_payload' => [
                'title' => 'bounded suggestion ' . $number,
                'fact_refs' => ['trusted_fact#' . $number],
            ],
            'confidence' => $confidence,
            'idempotency_key' => 'freeze-request-' . $number,
        ];
    }

    /** @return array<string,mixed> */
    private function dailyCandidate(): array
    {
        return [
            'candidate_key' => 'gap:ctrip:core_facts',
            'source_type' => 'explicit_data_gap',
            'problem' => '携程目标日期核心事实仍缺失',
            'fact_basis' => [[
                'statement' => '当前营业日缺少携程核心事实。',
                'evidence_ref' => 'dual_ota_field_closure#daily',
                'quality_status' => 'gap_readback_verified',
            ]],
            'recommended_action' => [
                'type' => 'collect_trusted_ota_facts',
                'object' => 'ctrip_target_date_strict_facts',
                'title' => '补齐携程事实',
                'description' => '只补齐事实，不执行平台写入。',
                'steps' => ['读取目标日。', '保存并回读。'],
            ],
            'expected_observation_metric' => [
                'key' => 'ctrip_strict_core_fact_count',
                'label' => '携程严格核心事实数',
                'unit' => 'verified_fields',
                'baseline_value' => 0,
                'aggregation' => 'latest',
            ],
            'scope' => [
                'tenant_id' => 7,
                'hotel_id' => 80,
                'platform' => 'ctrip',
                'business_date' => '2026-08-29',
                'metric_scope' => 'ota_channel_data_quality',
                'scope_note' => '仅限携程目标日期数据完整性。',
            ],
            'risk' => [
                'level' => 'low',
                'summary' => '防止误用旧数据。',
                'controls' => ['必须精确回读。'],
                'stop_conditions' => ['酒店或日期不一致时停止。'],
            ],
            'responsibility' => [
                'owner_id' => 11,
                'owner_label' => '当前确认人',
                'due_at' => '2026-08-29 23:00:00',
                'review_at' => '2026-08-30 10:00:00',
            ],
            'ranking' => [
                'impact' => 100,
                'urgency' => 100,
                'evidence_strength' => 100,
                'execution_cost' => 18,
                'reasons' => [],
            ],
            'source' => [
                'record_id' => 0,
                'record_ref' => 'dual_ota_field_closure#daily',
                'snapshot_digest' => str_repeat('a', 64),
                'fact_refs' => ['dual_ota_field_closure#daily'],
                'gap_codes' => ['ctrip_core_facts_missing'],
            ],
            'external_write_boundary' => [
                'automatic_ctrip_write' => false,
                'automatic_meituan_write' => false,
                'automatic_pms_write' => false,
                'automatic_wecom_message' => false,
                'automatic_execution' => false,
            ],
        ];
    }
}
