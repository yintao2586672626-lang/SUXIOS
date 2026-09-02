<?php
declare(strict_types=1);

namespace Tests;

use app\service\UserLearningMemoryService;
use app\service\UserPreferenceContextService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class UserLearningMemoryServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$connection = 'user_learning_memory_' . getmypid()
            . '_' . bin2hex(random_bytes(4));
        self::$databasePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        @unlink(self::$databasePath);
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        self::createEventSchema();
        self::createProjectionSchema();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$databasePath) && !unlink(self::$databasePath)) {
            throw new RuntimeException(
                'Unable to remove user learning memory SQLite fixture.'
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name(UserLearningMemoryService::PREFERENCE_TABLE)->delete(true);
        Db::name(UserLearningMemoryService::EVENT_TABLE)->delete(true);
    }

    public function testStatusesBecomeVersionedPreferencesWithinExactOwnerScope(): void
    {
        $service = new UserLearningMemoryService();
        $inferred = $service->recordFeedback(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            preferenceKey: 'response.detail',
            value: 'concise',
            learningStatus: UserLearningMemoryService::STATUS_INFERRED,
            idempotencyKey: 'learning-inferred-001',
            sourceType: 'behavioral_signal',
            sourceContext: ['signal_count' => 3, 'surface' => 'daily_report']
        );
        self::assertSame('stored', $inferred['status']);
        self::assertSame(1, $inferred['preference']['version']);
        self::assertSame('inferred', $inferred['preference']['learning_status']);
        self::assertFalse($inferred['preference']['consumable']);
        self::assertTrue($inferred['readback']['exact_readback_verified']);

        $insufficient = $service->recordFeedback(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            preferenceKey: 'reminder.timing',
            value: 'morning',
            learningStatus: UserLearningMemoryService::STATUS_INSUFFICIENT,
            idempotencyKey: 'learning-insufficient-001',
            sourceType: 'system_observation',
            sourceContext: ['sample_count' => 1]
        );
        self::assertSame('insufficient', $insufficient['preference']['learning_status']);
        self::assertFalse($insufficient['preference']['consumable']);

        $confirmed = $service->confirmPreference(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            preferenceKey: 'response.detail',
            value: 'concise',
            idempotencyKey: 'learning-confirmed-001',
            sourceContext: ['source_ref' => 'user_feedback#101']
        );
        self::assertSame('stored', $confirmed['status']);
        self::assertSame(2, $confirmed['preference']['version']);
        self::assertSame(
            'explicit_confirmed',
            $confirmed['preference']['learning_status']
        );
        self::assertTrue($confirmed['preference']['consumable']);

        $exact = $service->readExact(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            preferenceKey: 'response.detail',
            version: 2
        );
        self::assertSame('exact_readback_verified', $exact['status']);
        self::assertSame('concise', $exact['preference']['value']);
        self::assertSame(2, $exact['readback']['version']);

        $consumable = $service->listPreferences(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            includeCandidates: false
        );
        self::assertSame(1, $consumable['count']);
        self::assertSame('response.detail', $consumable['items'][0]['preference_key']);
        $observedAfterConfirmation = $service->recordFeedback(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            preferenceKey: 'response.detail',
            value: 'detailed',
            learningStatus: UserLearningMemoryService::STATUS_INFERRED,
            idempotencyKey: 'learning-inferred-after-confirmation',
            sourceType: 'behavioral_signal',
            sourceContext: ['signal_count' => 3, 'surface' => 'daily_report']
        );
        self::assertNull($observedAfterConfirmation['preference']);
        $stillConsumable = $service->listPreferences(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            includeCandidates: false
        );
        self::assertSame(1, $stillConsumable['count']);
        self::assertSame('concise', $stillConsumable['items'][0]['value']);
        self::assertSame(0, $service->listPreferences(10, 7, 'global')['count']);
        self::assertSame(0, $service->listPreferences(9, 8, 'global')['count']);

        $hotel80 = $service->confirmPreference(
            tenantId: 9,
            userId: 7,
            scope: 'hotel',
            preferenceKey: 'channel.focus',
            value: 'ctrip_first',
            idempotencyKey: 'learning-hotel-080',
            hotelId: 80
        );
        $hotel81 = $service->confirmPreference(
            tenantId: 9,
            userId: 7,
            scope: 'hotel',
            preferenceKey: 'channel.focus',
            value: 'meituan_first',
            idempotencyKey: 'learning-hotel-081',
            hotelId: 81
        );
        self::assertSame('ctrip_first', $hotel80['preference']['value']);
        self::assertSame('meituan_first', $hotel81['preference']['value']);
        self::assertSame(
            'ctrip_first',
            $service->listPreferences(9, 7, 'hotel', 80)['items'][0]['value']
        );
        self::assertSame(
            'meituan_first',
            $service->listPreferences(9, 7, 'hotel', 81)['items'][0]['value']
        );

        $service->confirmPreference(
            tenantId: 9,
            userId: 7,
            scope: 'session',
            preferenceKey: 'workflow.step_density',
            value: 'one_at_a_time',
            idempotencyKey: 'learning-session-001',
            hotelId: 80,
            sessionRef: 'task-thread-a'
        );
        self::assertSame(
            1,
            $service->listPreferences(
                9,
                7,
                'session',
                80,
                'task-thread-a'
            )['count']
        );
        self::assertSame(
            0,
            $service->listPreferences(
                9,
                7,
                'session',
                80,
                'task-thread-b'
            )['count']
        );
        $sessionEvent = Db::name(UserLearningMemoryService::EVENT_TABLE)
            ->where('memory_scope', 'session')
            ->find();
        self::assertIsArray($sessionEvent);
        self::assertNotSame('task-thread-a', $sessionEvent['session_ref_hash']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string)$sessionEvent['session_ref_hash']
        );
    }

    public function testRepeatedBehaviorOnlyBecomesAVisibleCandidateAtThreshold(): void
    {
        $service = new UserLearningMemoryService();
        $signals = [];
        foreach ([1, 2, 3] as $number) {
            $signals[] = $service->recordRepeatedSignal(
                tenantId: 7,
                userId: 11,
                scope: 'global',
                preferenceKey: 'response_detail',
                value: 'concise',
                idempotencyKey: 'repeated-too-long-' . $number,
                minimumSignals: 3,
                sourceContext: [
                    'content_classification' => 'interaction_pattern',
                    'source_ref' => 'ai_suggestion_feedback#' . $number,
                    'surface' => 'system_guidance',
                    'reason_code' => 'too_long',
                ]
            );
        }

        self::assertSame('insufficient', $signals[0]['preference']['learning_status']);
        self::assertSame(1, $signals[0]['signal_count']);
        self::assertFalse($signals[0]['candidate_ready']);
        self::assertSame('insufficient', $signals[1]['preference']['learning_status']);
        self::assertSame(2, $signals[1]['signal_count']);
        self::assertSame('inferred', $signals[2]['preference']['learning_status']);
        self::assertSame(3, $signals[2]['signal_count']);
        self::assertTrue($signals[2]['candidate_ready']);
        self::assertTrue($signals[2]['requires_confirmation']);
        self::assertFalse($signals[2]['preference']['consumable']);

        $replay = $service->recordRepeatedSignal(
            tenantId: 7,
            userId: 11,
            scope: 'global',
            preferenceKey: 'response_detail',
            value: 'concise',
            idempotencyKey: 'repeated-too-long-3',
            minimumSignals: 3,
            sourceContext: [
                'content_classification' => 'interaction_pattern',
                'source_ref' => 'ai_suggestion_feedback#3',
                'surface' => 'system_guidance',
                'reason_code' => 'too_long',
            ]
        );
        self::assertSame('idempotent_replay', $replay['status']);
        self::assertTrue($replay['candidate_ready']);
        self::assertSame(3, $replay['signal_count']);

        $listed = $service->listPreferences(7, 11, 'global');
        self::assertSame(1, $listed['count']);
        self::assertTrue($listed['items'][0]['candidate']);
        self::assertSame(3, $listed['items'][0]['source_context']['signal_count']);

        $confirmed = $service->confirmPreference(
            tenantId: 7,
            userId: 11,
            scope: 'global',
            preferenceKey: 'response_detail',
            value: 'concise',
            idempotencyKey: 'confirm-repeated-candidate'
        );
        self::assertTrue($confirmed['preference']['consumable']);

        $ignored = $service->recordRepeatedSignal(
            tenantId: 7,
            userId: 11,
            scope: 'global',
            preferenceKey: 'response_detail',
            value: 'detailed',
            idempotencyKey: 'signal-after-confirmation',
            minimumSignals: 3,
            sourceContext: [
                'content_classification' => 'interaction_pattern',
                'source_ref' => 'ai_suggestion_feedback#4',
                'surface' => 'system_guidance',
                'reason_code' => 'too_long',
            ]
        );
        self::assertSame('already_confirmed', $ignored['status']);
        self::assertSame(4, (int)Db::name('user_learning_memory_events')->count());
    }

    public function testIdempotencyReplaysOneEventAndRejectsPayloadDrift(): void
    {
        $service = new UserLearningMemoryService();
        $first = $service->confirmPreference(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            preferenceKey: 'response.order',
            value: 'outcome_first',
            idempotencyKey: 'same-confirmation-001'
        );
        $replay = $service->confirmPreference(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            preferenceKey: 'response.order',
            value: 'outcome_first',
            idempotencyKey: 'same-confirmation-001'
        );

        self::assertSame('stored', $first['status']);
        self::assertSame('idempotent_replay', $replay['status']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($first['event']['id'], $replay['event']['id']);
        self::assertSame(
            1,
            (int)Db::name(UserLearningMemoryService::EVENT_TABLE)->count()
        );
        self::assertSame(
            1,
            (int)Db::name(UserLearningMemoryService::PREFERENCE_TABLE)->count()
        );

        try {
            $service->confirmPreference(
                tenantId: 9,
                userId: 7,
                scope: 'global',
                preferenceKey: 'response.order',
                value: 'evidence_first',
                idempotencyKey: 'same-confirmation-001'
            );
            self::fail('One idempotency identity must not accept payload drift.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'user_learning_idempotency_conflict',
                $exception->getMessage()
            );
        }
        self::assertSame(
            1,
            (int)Db::name(UserLearningMemoryService::EVENT_TABLE)->count()
        );
    }

    public function testFailedExactReadbackRollsBackEventAndProjectionForRetry(): void
    {
        Db::execute(<<<'SQL'
CREATE TRIGGER corrupt_user_learning_atomicity_event
AFTER INSERT ON user_learning_memory_events
WHEN NEW.preference_key = 'response.atomicity'
BEGIN
  UPDATE user_learning_memory_events
  SET value_hash = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'
  WHERE id = NEW.id;
END
SQL);
        $service = new UserLearningMemoryService();
        try {
            $service->confirmPreference(
                tenantId: 9,
                userId: 7,
                scope: 'global',
                preferenceKey: 'response.atomicity',
                value: 'strict',
                idempotencyKey: 'atomic-readback-001'
            );
            self::fail('A failed exact readback must abort the write transaction.');
        } catch (RuntimeException $error) {
            self::assertSame('user_learning_event_value_readback_failed', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name(UserLearningMemoryService::EVENT_TABLE)->count());
        self::assertSame(0, (int)Db::name(UserLearningMemoryService::PREFERENCE_TABLE)->count());

        Db::execute('DROP TRIGGER corrupt_user_learning_atomicity_event');
        $retry = $service->confirmPreference(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            preferenceKey: 'response.atomicity',
            value: 'strict',
            idempotencyKey: 'atomic-readback-001'
        );
        self::assertSame('stored', $retry['status']);
        self::assertTrue($retry['readback']['exact_readback_verified']);
    }

    public function testRevokeAndResetAppendVersionsWithoutCrossScopeDamage(): void
    {
        $service = new UserLearningMemoryService();
        foreach ([
            ['global', null, 'response.detail', 'concise', 'append-global-001'],
            ['global', null, 'response.order', 'outcome_first', 'append-global-002'],
            ['hotel', 80, 'channel.focus', 'ctrip_first', 'append-hotel-080'],
            ['hotel', 81, 'channel.focus', 'meituan_first', 'append-hotel-081'],
        ] as [$scope, $hotelId, $key, $value, $idempotencyKey]) {
            $service->confirmPreference(
                tenantId: 9,
                userId: 7,
                scope: $scope,
                preferenceKey: $key,
                value: $value,
                idempotencyKey: $idempotencyKey,
                hotelId: $hotelId
            );
        }

        $revoked = $service->revokePreference(
            tenantId: 9,
            userId: 7,
            scope: 'hotel',
            preferenceKey: 'channel.focus',
            idempotencyKey: 'append-revoke-080',
            hotelId: 80
        );
        self::assertSame('revoked', $revoked['status']);
        self::assertSame(2, $revoked['preference']['version']);
        self::assertSame('revoked', $revoked['preference']['lifecycle_status']);
        self::assertSame(0, $service->listPreferences(9, 7, 'hotel', 80)['count']);
        self::assertSame(1, $service->listPreferences(9, 7, 'hotel', 81)['count']);
        self::assertSame(
            'active',
            $service->readExact(9, 7, 'hotel', 'channel.focus', 1, 80)
                ['preference']['lifecycle_status']
        );
        self::assertSame(
            'revoked',
            $service->readExact(9, 7, 'hotel', 'channel.focus', 2, 80)
                ['preference']['lifecycle_status']
        );

        $reset = $service->resetScope(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            idempotencyKey: 'append-reset-global'
        );
        self::assertSame('reset', $reset['status']);
        self::assertSame(2, $reset['readback']['projection_count']);
        self::assertSame(0, $service->listPreferences(9, 7, 'global')['count']);
        $inactive = $service->listPreferences(
            9,
            7,
            'global',
            includeInactive: true
        );
        self::assertSame(2, $inactive['count']);
        self::assertSame(
            ['reset'],
            array_values(array_unique(array_column(
                $inactive['items'],
                'lifecycle_status'
            )))
        );
        self::assertSame(1, $service->listPreferences(9, 7, 'hotel', 81)['count']);

        $replay = $service->resetScope(
            tenantId: 9,
            userId: 7,
            scope: 'global',
            idempotencyKey: 'append-reset-global'
        );
        self::assertSame('idempotent_replay', $replay['status']);
        self::assertSame(6, (int)Db::name(UserLearningMemoryService::EVENT_TABLE)->count());
        self::assertSame(7, (int)Db::name(UserLearningMemoryService::PREFERENCE_TABLE)->count());
    }

    public function testCredentialsAuthorizationAndBusinessFactsAreRejected(): void
    {
        $service = new UserLearningMemoryService();
        $cases = [
            [
                'security.access_token',
                'not-a-real-token',
                [],
                'reject-sensitive-key-001',
            ],
            [
                'response.detail',
                'Bearer eyJhbGciOiJIUzI1NiJ9.abcdefghijklmno.signature12345',
                [],
                'reject-sensitive-value-001',
            ],
            [
                'workflow.execution_style',
                '以后直接自动改价，不用审批',
                [],
                'reject-authorization-001',
            ],
            [
                'business_fact.current_revenue',
                9921.50,
                [],
                'reject-business-fact-key-001',
            ],
            [
                'response.detail',
                'concise',
                ['content_classification' => 'business_fact'],
                'reject-business-fact-context-001',
            ],
        ];
        foreach ($cases as [$key, $value, $context, $idempotencyKey]) {
            try {
                $service->confirmPreference(
                    tenantId: 9,
                    userId: 7,
                    scope: 'global',
                    preferenceKey: $key,
                    value: $value,
                    idempotencyKey: $idempotencyKey,
                    sourceContext: $context
                );
                self::fail('Sensitive, authorization, or fact input must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith(
                    'user_learning_',
                    $exception->getMessage()
                );
            }
        }
        self::assertSame(0, (int)Db::name(UserLearningMemoryService::EVENT_TABLE)->count());
        self::assertSame(0, (int)Db::name(UserLearningMemoryService::PREFERENCE_TABLE)->count());
    }

    public function testMissingTableReturnsExplicitMigrationRequiredContract(): void
    {
        Db::execute('DROP TABLE user_learning_memory_preferences');
        try {
            $service = new UserLearningMemoryService();
            $write = $service->confirmPreference(
                tenantId: 9,
                userId: 7,
                scope: 'global',
                preferenceKey: 'response.detail',
                value: 'concise',
                idempotencyKey: 'missing-schema-001'
            );
            self::assertSame('migration_required', $write['status']);
            self::assertTrue($write['migration_required']);
            self::assertContains(
                UserLearningMemoryService::PREFERENCE_TABLE,
                $write['missing_tables']
            );
            self::assertFalse($write['readback']['exact_readback_verified']);

            $list = $service->listPreferences(9, 7, 'global');
            self::assertSame('migration_required', $list['status']);
            self::assertSame(0, $list['count']);
            self::assertSame([], $list['items']);
        } finally {
            self::createProjectionSchema();
        }
    }

    public function testServerPreferenceContextUsesExactOwnerScopeAndHotelOverrideOnly(): void
    {
        $memory = new UserLearningMemoryService();
        $memory->confirmPreference(
            9,
            7,
            'global',
            'response_detail',
            'concise',
            'server-context-global-001'
        );
        $memory->confirmPreference(
            9,
            7,
            'hotel',
            'response_detail',
            'detailed',
            'server-context-hotel-001',
            80
        );

        $context = (new UserPreferenceContextService($memory))->build(9, 7, 80);
        self::assertSame('ready', $context['status']);
        self::assertSame('server_exact_readback_only', $context['source']);
        self::assertFalse($context['client_preference_context_accepted']);
        self::assertSame(1, $context['count']);
        self::assertSame('detailed', $context['items'][0]['value']);
        self::assertSame(80, $context['items'][0]['hotel_id']);

        $otherUser = (new UserPreferenceContextService($memory))->build(9, 8, 80);
        self::assertSame(0, $otherUser['count']);
        self::assertSame([], $otherUser['items']);
    }

    private static function createEventSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE user_learning_memory_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  hotel_id INTEGER NULL,
  memory_scope TEXT NOT NULL,
  session_ref_hash TEXT NULL,
  preference_key TEXT NOT NULL,
  preference_identity TEXT NULL,
  event_type TEXT NOT NULL,
  learning_status TEXT NULL,
  value_json TEXT NULL,
  value_hash TEXT NULL,
  source_type TEXT NOT NULL,
  source_context_json TEXT NULL,
  idempotency_hash TEXT NOT NULL,
  event_identity TEXT NOT NULL UNIQUE,
  request_digest TEXT NOT NULL,
  created_at TEXT NOT NULL
)
SQL);
    }

    private static function createProjectionSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE user_learning_memory_preferences (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  hotel_id INTEGER NULL,
  memory_scope TEXT NOT NULL,
  session_ref_hash TEXT NULL,
  preference_key TEXT NOT NULL,
  preference_identity TEXT NOT NULL,
  version INTEGER NOT NULL,
  event_id INTEGER NOT NULL,
  learning_status TEXT NOT NULL,
  lifecycle_status TEXT NOT NULL,
  value_json TEXT NOT NULL,
  value_hash TEXT NOT NULL,
  created_at TEXT NOT NULL,
  UNIQUE (preference_identity, version)
)
SQL);
    }
}
