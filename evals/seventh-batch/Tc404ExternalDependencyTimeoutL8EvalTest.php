<?php
declare(strict_types=1);

namespace Tests\Eval\SeventhBatch;

use app\service\LlmClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Local deterministic cURL executor for TC-404. It never opens a socket.
 */
final class Tc404TimeoutLlmClient extends LlmClient
{
    private static int $requestCalls = 0;
    private static int $activeOperations = 0;

    /** @var list<int> */
    private static array $observedTimeouts = [];

    public static function reset(): void
    {
        self::$requestCalls = 0;
        self::$activeOperations = 0;
        self::$observedTimeouts = [];
    }

    public static function requestCalls(): int
    {
        return self::$requestCalls;
    }

    public static function activeOperations(): int
    {
        return self::$activeOperations;
    }

    /** @return list<int> */
    public static function observedTimeouts(): array
    {
        return self::$observedTimeouts;
    }

    /** @param array<int, mixed> $curlOptions */
    protected function performCurlRequest(string $url, array $curlOptions): array
    {
        self::$requestCalls++;
        self::$activeOperations++;

        try {
            self::$observedTimeouts[] = (int)($curlOptions[CURLOPT_TIMEOUT] ?? -1);
            return [
                'response' => false,
                'http_status' => 0,
                'curl_errno' => CURLE_OPERATION_TIMEDOUT,
            ];
        } finally {
            self::$activeOperations--;
        }
    }
}

final class Tc404ExternalDependencyTimeoutL8EvalTest extends TestCase
{
    private const TARGET_URL = 'https://93.184.216.34/v1/chat/completions?api_key=TC404_LOCAL_SECRET&session_id=TC404_LOCAL_SESSION';
    private const REQUESTED_TIMEOUT_SECONDS = 999;
    private const EXPECTED_TIMEOUT_CAP_SECONDS = 60;
    private const REQUESTED_MAX_RETRIES = 99;
    private const EXPECTED_MAX_RETRIES = 5;

    protected function setUp(): void
    {
        parent::setUp();
        Tc404TimeoutLlmClient::reset();
    }

    public function testDx3225AuthorizedCompleteFreshSuccess(): void
    {
        $this->evaluateCase('DX-3225', [
            'actor_scope' => 'authorized',
            'data_completeness' => 'complete',
            'freshness' => 'fresh',
            'upstream_state' => 'success',
        ]);
    }

    public function testDx3226AuthorizedCompleteStaleFailure(): void
    {
        $this->evaluateCase('DX-3226', [
            'actor_scope' => 'authorized',
            'data_completeness' => 'complete',
            'freshness' => 'stale',
            'upstream_state' => 'failure',
        ]);
    }

    public function testDx3227AuthorizedMissingRequiredFreshFailure(): void
    {
        $this->evaluateCase('DX-3227', [
            'actor_scope' => 'authorized',
            'data_completeness' => 'missing_required',
            'freshness' => 'fresh',
            'upstream_state' => 'failure',
        ]);
    }

    public function testDx3228AuthorizedMissingRequiredStaleSuccess(): void
    {
        $this->evaluateCase('DX-3228', [
            'actor_scope' => 'authorized',
            'data_completeness' => 'missing_required',
            'freshness' => 'stale',
            'upstream_state' => 'success',
        ]);
    }

    public function testDx3229RestrictedCompleteFreshFailure(): void
    {
        $this->evaluateCase('DX-3229', [
            'actor_scope' => 'restricted',
            'data_completeness' => 'complete',
            'freshness' => 'fresh',
            'upstream_state' => 'failure',
        ]);
    }

    public function testDx3230RestrictedCompleteStaleSuccess(): void
    {
        $this->evaluateCase('DX-3230', [
            'actor_scope' => 'restricted',
            'data_completeness' => 'complete',
            'freshness' => 'stale',
            'upstream_state' => 'success',
        ]);
    }

    public function testDx3231RestrictedMissingRequiredFreshSuccess(): void
    {
        $this->evaluateCase('DX-3231', [
            'actor_scope' => 'restricted',
            'data_completeness' => 'missing_required',
            'freshness' => 'fresh',
            'upstream_state' => 'success',
        ]);
    }

    public function testDx3232RestrictedMissingRequiredStaleFailure(): void
    {
        $this->evaluateCase('DX-3232', [
            'actor_scope' => 'restricted',
            'data_completeness' => 'missing_required',
            'freshness' => 'stale',
            'upstream_state' => 'failure',
        ]);
    }

    /**
     * @param array{
     *     actor_scope:string,
     *     data_completeness:string,
     *     freshness:string,
     *     upstream_state:string
     * } $factors
     */
    private function evaluateCase(string $caseId, array $factors): void
    {
        $violations = [];
        $fixture = $this->buildGuardFixture($caseId, $factors);

        $this->check(
            $violations,
            $fixture['factors'] === $factors,
            'L8 factors were not preserved exactly.'
        );
        $this->checkGuardEvidence($violations, $fixture, $factors);

        $client = new Tc404TimeoutLlmClient();
        $startedAt = microtime(true);
        $transport = $this->invokeNonPublic($client, 'sendWithRetry', [
            self::TARGET_URL,
            [
                'provider' => 'tc404_fixture',
                'api_key' => 'TC404_LOCAL_CONFIG_KEY',
                'model' => 'tc404-local-model',
                'model_key' => 'tc404_timeout_eval',
                'source' => 'fixture',
            ],
            '{"messages":[{"role":"user","content":"TC-404 local timeout fixture"}]}',
            [
                'timeout' => self::REQUESTED_TIMEOUT_SECONDS,
                'max_retries' => self::REQUESTED_MAX_RETRIES,
                'retry_base_delay_ms' => 0,
                'retry_max_delay_ms' => 0,
                'retry_jitter_ms' => 0,
            ],
        ]);
        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

        $expectedCalls = self::EXPECTED_MAX_RETRIES + 1;
        $this->check(
            $violations,
            Tc404TimeoutLlmClient::requestCalls() === $expectedCalls,
            'Transport calls did not terminate at the bounded retry count.'
        );
        $this->check(
            $violations,
            (int)$transport['max_retries'] === self::EXPECTED_MAX_RETRIES,
            'max_retries was not capped at 5.'
        );
        $this->check(
            $violations,
            (int)$transport['retry_attempts'] === self::EXPECTED_MAX_RETRIES,
            'retry_attempts did not expose the five bounded retries.'
        );
        $this->check(
            $violations,
            ($transport['response'] ?? null) === false
                && ($transport['retryable'] ?? null) === true
                && ($transport['retry_reason'] ?? null) === 'network_error',
            'Timeout was not mapped to a retryable network_error.'
        );

        $observedTimeouts = Tc404TimeoutLlmClient::observedTimeouts();
        $boundedTimeouts = count($observedTimeouts) === $expectedCalls;
        foreach ($observedTimeouts as $timeout) {
            $boundedTimeouts = $boundedTimeouts
                && $timeout > 0
                && $timeout <= self::EXPECTED_TIMEOUT_CAP_SECONDS;
        }
        $this->check(
            $violations,
            $boundedTimeouts,
            'Transport timeout exceeded the 60-second upper bound; observed=' . json_encode($observedTimeouts)
        );
        $this->check(
            $violations,
            Tc404TimeoutLlmClient::activeOperations() === 0 && $elapsedMs < 1000,
            'Local transport operation did not terminate promptly and release its active call.'
        );

        $sanitizedMessage = (string)$this->invokeNonPublic($client, 'sanitize', [
            (string)($transport['error'] ?? ''),
            500,
        ]);
        $retryMeta = [
            'retry_attempts' => (int)$transport['retry_attempts'],
            'max_retries' => (int)$transport['max_retries'],
            'retryable' => (bool)$transport['retryable'],
            'retry_reason' => (string)$transport['retry_reason'],
        ];
        $debug = $this->invokeNonPublic($client, 'debug', [
            'network_error',
            [
                'provider' => 'tc404_fixture',
                'model_key' => 'tc404_timeout_eval',
                'model' => 'tc404-local-model',
                'source' => 'fixture',
            ],
            (int)$transport['http_status'],
            $sanitizedMessage,
            'TC-404 local timeout fixture',
            '',
            $sanitizedMessage,
            $retryMeta,
            71,
        ]);

        $lowerMessage = strtolower($sanitizedMessage);
        $this->check(
            $violations,
            $sanitizedMessage !== ''
                && (str_contains($lowerMessage, 'timeout') || str_contains($lowerMessage, 'timed out'))
                && (str_contains($lowerMessage, 'retry') || str_contains($sanitizedMessage, '重试')),
            'User result lacks an explicit timeout plus retryable action.'
        );
        $this->check(
            $violations,
            !str_contains($sanitizedMessage, 'TC404_LOCAL_SECRET')
                && !str_contains($sanitizedMessage, 'TC404_LOCAL_SESSION')
                && !str_contains($sanitizedMessage, 'TC404_LOCAL_CONFIG_KEY'),
            'User result leaked a fixture secret.'
        );
        $this->check(
            $violations,
            ($debug['error_type'] ?? null) === 'network_error'
                && ($debug['debug']['retry_attempts'] ?? null) === self::EXPECTED_MAX_RETRIES
                && ($debug['debug']['max_retries'] ?? null) === self::EXPECTED_MAX_RETRIES
                && ($debug['debug']['retryable'] ?? null) === true
                && ($debug['debug']['retry_reason'] ?? null) === 'network_error'
                && ($debug['debug']['http_status'] ?? null) === 0,
            'Real LlmClient error mapping did not retain bounded retry evidence.'
        );

        self::assertSame(
            [],
            $violations,
            $caseId . ' contract violations:' . "\n- " . implode("\n- ", $violations)
        );
    }

    /**
     * @param array{
     *     actor_scope:string,
     *     data_completeness:string,
     *     freshness:string,
     *     upstream_state:string
     * } $factors
     * @return array<string,mixed>
     */
    private function buildGuardFixture(string $caseId, array $factors): array
    {
        $authorized = $factors['actor_scope'] === 'authorized';
        $complete = $factors['data_completeness'] === 'complete';
        $fresh = $factors['freshness'] === 'fresh';
        $upstreamSucceeded = $factors['upstream_state'] === 'success';

        return [
            'case_id' => $caseId,
            'factors' => $factors,
            'guard' => [
                'authorization' => [
                    'allowed' => $authorized,
                    'status' => $authorized ? 'authorized' : 'permission_denied',
                    'foreign_hotel_payload' => null,
                ],
                'completeness' => [
                    'status' => $complete ? 'complete' : 'missing_required',
                    'required_value' => $complete ? 'fixture-present' : null,
                    'fallback_used' => false,
                ],
                'freshness' => [
                    'status' => $fresh ? 'fresh' : 'stale',
                    'data_as_of' => $fresh ? '2026-07-15T08:00:00+08:00' : '2026-07-13T08:00:00+08:00',
                    'freshness_cutoff' => '2026-07-14T08:00:00+08:00',
                    'used_as_realtime' => $fresh,
                ],
                'upstream' => [
                    'status' => $upstreamSucceeded ? 'success' : 'failure',
                    'failure_stage' => $upstreamSucceeded ? null : 'external_dependency_timeout',
                    'formal_snapshot_written' => false,
                ],
            ],
        ];
    }

    /**
     * @param list<string> $violations
     * @param array<string,mixed> $fixture
     * @param array{
     *     actor_scope:string,
     *     data_completeness:string,
     *     freshness:string,
     *     upstream_state:string
     * } $factors
     */
    private function checkGuardEvidence(array &$violations, array $fixture, array $factors): void
    {
        $guard = $fixture['guard'];
        $authorized = $factors['actor_scope'] === 'authorized';
        $complete = $factors['data_completeness'] === 'complete';
        $fresh = $factors['freshness'] === 'fresh';
        $upstreamSucceeded = $factors['upstream_state'] === 'success';

        $this->check(
            $violations,
            $guard['authorization']['allowed'] === $authorized
                && $guard['authorization']['status'] === ($authorized ? 'authorized' : 'permission_denied')
                && $guard['authorization']['foreign_hotel_payload'] === null,
            'Authorization pre-guard is not observable or leaks foreign-hotel data.'
        );
        $this->check(
            $violations,
            $guard['completeness']['status'] === ($complete ? 'complete' : 'missing_required')
                && $guard['completeness']['required_value'] === ($complete ? 'fixture-present' : null)
                && $guard['completeness']['fallback_used'] === false,
            'Missing-required pre-guard is not explicit or used a fallback value.'
        );
        $this->check(
            $violations,
            $guard['freshness']['status'] === ($fresh ? 'fresh' : 'stale')
                && $guard['freshness']['freshness_cutoff'] !== ''
                && $guard['freshness']['used_as_realtime'] === $fresh,
            'Freshness pre-guard is not observable or stale data was treated as real-time.'
        );
        $this->check(
            $violations,
            $guard['upstream']['status'] === ($upstreamSucceeded ? 'success' : 'failure')
                && $guard['upstream']['failure_stage'] === ($upstreamSucceeded ? null : 'external_dependency_timeout')
                && $guard['upstream']['formal_snapshot_written'] === false,
            'Upstream state/failure stage is not preserved truthfully.'
        );
    }

    /** @param list<string> $violations */
    private function check(array &$violations, bool $condition, string $message): void
    {
        if (!$condition) {
            $violations[] = $message;
        }
    }

    /** @param list<mixed> $arguments */
    private function invokeNonPublic(object $target, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(LlmClient::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($target, $arguments);
    }
}
