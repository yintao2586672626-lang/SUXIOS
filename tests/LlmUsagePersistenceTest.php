<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AiGovernance;
use app\model\AiModelCallLog;
use app\service\LlmClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class LlmUsagePersistenceTest extends TestCase
{
    private static array $originalDatabaseConfig;
    private static string $sqlitePath;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/suxios_usage_' . bin2hex(random_bytes(8)) . '.sqlite';
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$sqlitePath, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
        $columns = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
        foreach (['request_id', 'module', 'scenario', 'provider', 'model_key', 'model_name', 'prompt_version', 'prompt_hash',
            'prompt_preview', 'status', 'error_type', 'error_message', 'response_hash', 'response_preview',
            'human_confirmation_status', 'knowledge_sources_json', 'evaluation_set', 'eval_case_id', 'governance_json', 'created_at', 'updated_at'] as $name) {
            $columns[] = $name . ' TEXT';
        }
        foreach (['hotel_id', 'user_id', 'prompt_length', 'request_payload_size', 'http_status', 'latency_ms', 'response_length',
            'low_confidence', 'human_confirmation_required'] as $name) {
            $columns[] = $name . ' INTEGER';
        }
        $columns[] = 'confidence_score REAL';
        Db::execute('CREATE TABLE ai_model_call_logs (' . implode(',', $columns) . ')');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect('sqlite')->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        Db::execute('DELETE FROM ai_model_call_logs');
    }

    public function testRetryUsageSavesAndReadsBackExactlyThroughLogApiFormatter(): void
    {
        $client = new UsageRecordingClient([['response' => false, 'http_status' => 0, 'error' => 'test timeout'], self::reply()]);
        $result = $client->chat('synthetic usage test', 'primary', ['hotel_id' => 17, 'user_id' => 23, 'business_date' => '2026-09-30'], ['max_retries' => 1]);
        self::assertTrue($result['ok']);
        $usage = $result['data']['governance']['usage_observation'];
        self::assertSame('partial', $usage['status']);
        self::assertSame(12, $usage['observed_total_tokens']);
        self::assertNull($usage['total_tokens']);
        self::assertSame(2, $usage['dispatched_count']);
        self::assertGreaterThanOrEqual(0, $usage['elapsed_ms']);
        $row = AiModelCallLog::find($result['data']['governance']['call_log_id'])->toArray();
        self::assertSame(17, $row['hotel_id']);
        self::assertSame(23, $row['user_id']);
        self::assertSame('2026-09-30', $row['governance_json']['business_date']);
        self::assertSame($usage, $row['governance_json']['usage_observation']);
        $controller = (new ReflectionClass(AiGovernance::class))->newInstanceWithoutConstructor();
        $format = new ReflectionMethod(AiGovernance::class, 'formatLogRow');
        self::assertSame($usage, $format->invoke($controller, $row, false)['usage_observation']);
        self::assertSame($usage, $format->invoke($controller, $row, true)['usage_observation']);
        $legacy = $format->invoke($controller, ['id' => 99], false);
        self::assertSame('legacy_unknown', $legacy['usage_observation']['status']);
        self::assertNull($legacy['usage_observation']['total_tokens']);
        self::assertNull($legacy['latency_ms']);
    }

    public function testProviderFallbackHasIndependentUsageRowsIncludingFailedResponse(): void
    {
        $client = new UsageRecordingClient([self::reply(503), self::reply()], true);
        $result = $client->chat('synthetic fallback', 'primary', [], ['max_retries' => 0, 'idempotency_enabled' => false]);
        self::assertTrue($result['ok']);
        $rows = AiModelCallLog::order('id')->select()->toArray();
        self::assertCount(2, $rows);
        self::assertSame(['primary', 'fallback'], array_column($rows, 'model_key'));
        self::assertSame(['failed', 'success'], array_column($rows, 'status'));
        foreach ($rows as $row) {
            self::assertSame(12, $row['governance_json']['usage_observation']['total_tokens']);
            self::assertSame(1, $row['governance_json']['usage_observation']['dispatched_count']);
            self::assertNull($row['governance_json']['usage_observation']['cost_amount']);
        }
    }

    public function testIdempotentReplayDoesNotCreateOrBillAnotherLog(): void
    {
        $client = new UsageRecordingClient([self::reply()]);
        $meta = ['request_id' => 'usage-idempotent-test'];
        $first = $client->chat('synthetic replay', 'primary', $meta);
        $second = $client->chat('synthetic replay', 'primary', $meta);
        self::assertTrue($first['ok']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame(1, $client->calls);
        self::assertSame(1, AiModelCallLog::count());
        self::assertSame($first['data']['governance']['call_log_id'], $second['data']['governance']['call_log_id']);
    }

    public function testCachedResponseDoesNotCarryOldTokensIntoNewLog(): void
    {
        $client = new UsageRecordingClient([self::reply(), ['response' => false, 'http_status' => 0, 'error' => 'test timeout']]);
        $options = ['max_retries' => 0, 'idempotency_enabled' => false];
        self::assertTrue($client->chat('synthetic cache', 'primary', [], $options)['ok']);
        $result = $client->chat('synthetic cache', 'primary', [], $options);
        self::assertSame('cache', $result['degradation_mode']);
        $rows = AiModelCallLog::order('id')->select()->toArray();
        self::assertCount(3, $rows);
        self::assertSame('unavailable', $rows[1]['governance_json']['usage_observation']['status']);
        self::assertSame('not_called', $rows[2]['governance_json']['usage_observation']['status']);
        self::assertNull($rows[2]['governance_json']['usage_observation']['total_tokens']);
    }

    public function testInvalidModelContentStillRecordsProviderConsumption(): void
    {
        $client = new UsageRecordingClient([self::reply()]);
        $result = $client->chat('synthetic schema failure', 'primary', [], ['max_retries' => 0, 'idempotency_enabled' => false,
            'json_schema' => ['type' => 'object', 'required' => ['missing'], 'properties' => ['missing' => ['type' => 'number']]]]);
        self::assertFalse($result['ok']);
        $row = AiModelCallLog::order('id')->find()->toArray();
        self::assertSame('failed', $row['status']);
        self::assertSame(12, $row['governance_json']['usage_observation']['total_tokens']);
    }

    private static function reply(int $status = 200): array
    {
        return ['response' => json_encode(['id' => 'synthetic-usage-response', 'model' => 'synthetic-model',
            'choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12, 'prompt_tokens_details' => ['cached_tokens' => 4]]]),
            'http_status' => $status];
    }
}

final class UsageRecordingClient extends LlmClient
{
    public int $calls = 0;
    private array $state = [];
    public function __construct(private array $responses, private bool $fallback = false) {}
    protected function configByModelKey(string $modelKey): array
    {
        return ['ok' => true, 'provider' => 'deepseek', 'model_key' => $modelKey, 'configured_model_key' => $modelKey,
            'model' => 'synthetic-' . $modelKey, 'base_url' => 'https://api.deepseek.com/v1', 'api_key' => 'unit-test-key', 'source' => 'database'];
    }
    protected function providerFallbackConfigs(array $primaryConfig, string $requestedModelKey, array $options): array
    {
        return $this->fallback ? [$this->configByModelKey('fallback')] : [];
    }
    protected function chatCompletionUrl(array $config): string { return 'https://unit.test/v1/chat/completions'; }
    protected function sendOnce(string $url, array $config, string $payloadJson, array $options): array
    {
        $this->calls++;
        return array_shift($this->responses) ?? ['response' => false, 'http_status' => 0];
    }
    protected function sleepMilliseconds(int $delayMs): void {}
    protected function withIdempotencyLock(string $requestId, callable $callback): ?array { return $callback(); }
    protected function stateGet(string $key): mixed { return $this->state[$key] ?? null; }
    protected function stateSet(string $key, mixed $value, int $ttlSeconds): void { $this->state[$key] = $value; }
    protected function stateDelete(string $key): void { unset($this->state[$key]); }
}
