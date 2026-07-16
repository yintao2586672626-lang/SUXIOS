<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportService;
use app\service\LlmClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class Tc270LlmPiiMinimizationL8Test extends TestCase
{
    private const HOTEL_ID = 7;
    private const REPORT_DATE = '2026-07-15';
    private const STALE_SOURCE_DATE = '2026-07-01';

    /**
     * TC-270 tests the last production message-construction boundary before an
     * LLM client call. The client is local and capturing: no network, database,
     * real model, OTA, or production state is touched.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc270MinimizesPiiBeforeLlmMessages(
        string $caseId,
        array $factors
    ): void {
        $fakeClient = new Tc270CapturingLlmClient($factors['upstream_state'] === 'failure');
        $service = new AiDailyReportService(null, $fakeClient);
        $snapshot = $this->snapshotForFactors($factors);
        $ruleReport = $this->ruleReportForFactors($factors);
        $permittedHotelIds = $factors['actor_scope'] === 'authorized'
            ? [self::HOTEL_ID]
            : [self::HOTEL_ID + 1];
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);

        $this->assertFixtureFactorsAreExplicit($snapshot, $ruleReport, $factors, $message);
        self::assertSame(
            $factors['upstream_state'] === 'failure',
            $fakeClient->willFail(),
            $message . ' upstream fake mode'
        );

        $resolveHotel = new ReflectionMethod($service, 'resolveSingleHotelId');
        $resolveHotel->setAccessible(true);
        if ($factors['actor_scope'] === 'restricted') {
            try {
                $resolveHotel->invoke($service, $permittedHotelIds, self::HOTEL_ID);
                self::fail($message . ' restricted actor unexpectedly crossed the hotel scope guard');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('hotel_id is not permitted', $exception->getMessage(), $message);
            }

            self::assertSame([], $fakeClient->calls, $message . ' restricted scope must make no LLM call');
            return;
        }

        self::assertSame(
            self::HOTEL_ID,
            $resolveHotel->invoke($service, $permittedHotelIds, self::HOTEL_ID),
            $message
        );

        $enhance = new ReflectionMethod($service, 'tryEnhanceWithLlm');
        $enhance->setAccessible(true);
        $result = $enhance->invoke(
            $service,
            $ruleReport,
            $snapshot,
            'tc270_local_fake_model'
        );

        $inputReady = $factors['data_completeness'] === 'complete'
            && $factors['freshness'] === 'fresh';
        if ($inputReady) {
            self::assertCount(1, $fakeClient->calls, $message . ' ready input should reach the captured boundary once');
        } else {
            self::assertLessThanOrEqual(
                1,
                count($fakeClient->calls),
                $message . ' invalid input may be blocked before the captured boundary'
            );
        }

        $violations = [];
        if ($fakeClient->calls === []) {
            if (($result['model_status'] ?? '') === 'ok') {
                $violations['blocked_input_reported_success'] = $result['model_status'];
            }
            if (is_array($result['report'] ?? null)) {
                $violations['blocked_input_returned_report'] = true;
            }

            self::assertSame(
                [],
                $violations,
                $message . ' blocked input outcome violations=' . json_encode($violations, JSON_UNESCAPED_SLASHES)
            );
            return;
        }

        $capturedMessages = json_encode(
            $fakeClient->calls[0]['messages'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $gapCodes = array_column($ruleReport['data_gaps'], 'code');
        foreach ($gapCodes as $gapCode) {
            self::assertStringContainsString($gapCode, $capturedMessages, $message . ' quality gaps must remain explicit');
        }

        if ($factors['upstream_state'] === 'failure') {
            self::assertSame('failed', $result['model_status'], $message . ' upstream failure must stay failed');
            self::assertNull($result['report'], $message . ' upstream failure must not expose a successful report');
            self::assertStringContainsString('synthetic upstream failure', $result['model_message'], $message);
        } elseif ($inputReady) {
            self::assertSame('ok', $result['model_status'], $message);
            self::assertIsArray($result['report'], $message);
        } else {
            if (($result['model_status'] ?? '') === 'ok') {
                $violations['invalid_input_reported_success'] = [
                    'data_completeness' => $factors['data_completeness'],
                    'freshness' => $factors['freshness'],
                    'actual_model_status' => $result['model_status'],
                ];
            }
            if (is_array($result['report'] ?? null)) {
                $violations['invalid_input_returned_report'] = true;
            }
        }

        $leakedFields = [];
        foreach ($this->syntheticPii() as $field => $rawValue) {
            if (str_contains($capturedMessages, $field) || str_contains($capturedMessages, $rawValue)) {
                $leakedFields[] = $field;
            }
        }
        if ($leakedFields !== []) {
            $violations['pii_fields_in_model_messages'] = $leakedFields;
        }

        self::assertSame(
            [],
            $violations,
            $message . ' outbound privacy/readiness violations=' . json_encode($violations, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string, array{0:string,1:array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2153 authorized complete fresh success' => ['DX-2153', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-2154 authorized complete stale failure' => ['DX-2154', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-2155 authorized missing fresh failure' => ['DX-2155', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-2156 authorized missing stale success' => ['DX-2156', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-2157 restricted complete fresh failure' => ['DX-2157', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-2158 restricted complete stale success' => ['DX-2158', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-2159 restricted missing fresh success' => ['DX-2159', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-2160 restricted missing stale failure' => ['DX-2160', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<string,mixed>
     */
    private function snapshotForFactors(array $factors): array
    {
        $complete = $factors['data_completeness'] === 'complete';
        $fresh = $factors['freshness'] === 'fresh';
        $sourceDate = $fresh ? self::REPORT_DATE : self::STALE_SOURCE_DATE;
        $pii = $this->syntheticPii();
        $gaps = $this->dataGapsForFactors($factors);

        $summary = [
            'data_status' => $complete ? 'ok' : 'missing_required',
            'data_date' => $sourceDate,
            'guest_name' => $pii['guest_name'],
            'phone' => $pii['phone'],
        ];
        $ota = [
            'data_status' => $complete ? 'ok' : 'missing_required',
            'data_date' => $sourceDate,
            'id_card' => $pii['id_card'],
            'order_remark' => $pii['order_remark'],
            'evidence_refs' => [['source_ref' => 'online_daily_data#270']],
        ];
        if ($complete) {
            $summary['revenue'] = 2700;
            $ota['orders'] = 27;
        }

        return [
            'scope' => [
                'hotel_id' => self::HOTEL_ID,
                'report_date' => self::REPORT_DATE,
                'source_data_date' => $sourceDate,
            ],
            'input_trust' => [
                'readback_verified' => true,
                'data_gaps' => $gaps,
            ],
            'source_refs' => [[
                'key' => 'online_daily_data#270',
                'label' => 'TC-270 synthetic OTA fixture',
                'scope' => 'Ctrip OTA channel fact',
                'source' => 'ctrip',
                'platform' => 'Ctrip',
                'data_date' => $sourceDate,
                'validation_status' => $complete ? 'available' : 'partial',
                'metric_keys' => $complete ? ['orders'] : [],
                'readback_verified' => true,
            ]],
            'operation' => [
                'summary' => $summary,
                'ota' => $ota,
                'competitors' => [],
                'abnormal_flags' => [],
            ],
            'root_cause' => [
                'data_status' => $complete ? 'ok' : 'missing_required',
                'root_causes' => [],
            ],
            'execution_flow' => [
                'summary' => [],
                'data_gaps' => $gaps,
            ],
        ];
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<string,mixed>
     */
    private function ruleReportForFactors(array $factors): array
    {
        $complete = $factors['data_completeness'] === 'complete';

        return [
            'summary' => $complete
                ? 'Synthetic local report with required operating metrics.'
                : 'Required operating metrics are missing.',
            'yesterday_result' => [],
            'abnormal_metrics' => [],
            'competitor_changes' => [],
            'recommended_actions' => [],
            'data_gaps' => $this->dataGapsForFactors($factors),
            'source_refs' => [[
                'key' => 'tc270.synthetic.fixture',
                'data_date' => $factors['freshness'] === 'fresh'
                    ? self::REPORT_DATE
                    : self::STALE_SOURCE_DATE,
            ]],
            'report_scope' => [
                'hotel_id' => self::HOTEL_ID,
                'report_date' => self::REPORT_DATE,
            ],
        ];
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return list<array{code:string,message:string}>
     */
    private function dataGapsForFactors(array $factors): array
    {
        $gaps = [];
        if ($factors['data_completeness'] === 'missing_required') {
            $gaps[] = [
                'code' => 'required_operating_metrics_missing',
                'message' => 'Required operating metrics are missing from the snapshot.',
            ];
        }
        if ($factors['freshness'] === 'stale') {
            $gaps[] = [
                'code' => 'source_snapshot_stale',
                'message' => 'The source snapshot is older than the report date.',
            ];
        }

        return $gaps;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $ruleReport
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertFixtureFactorsAreExplicit(
        array $snapshot,
        array $ruleReport,
        array $factors,
        string $message
    ): void {
        self::assertSame(self::HOTEL_ID, $snapshot['scope']['hotel_id'], $message . ' actor scope target');

        $complete = $factors['data_completeness'] === 'complete';
        self::assertSame($complete, array_key_exists('revenue', $snapshot['operation']['summary']), $message . ' completeness summary');
        self::assertSame($complete, array_key_exists('orders', $snapshot['operation']['ota']), $message . ' completeness OTA');

        $fresh = $factors['freshness'] === 'fresh';
        self::assertSame(
            $fresh ? self::REPORT_DATE : self::STALE_SOURCE_DATE,
            $snapshot['scope']['source_data_date'],
            $message . ' freshness'
        );

        $gapCodes = array_column($ruleReport['data_gaps'], 'code');
        self::assertSame(!$complete, in_array('required_operating_metrics_missing', $gapCodes, true), $message);
        self::assertSame(!$fresh, in_array('source_snapshot_stale', $gapCodes, true), $message);

        foreach ($this->syntheticPii() as $field => $value) {
            $container = in_array($field, ['guest_name', 'phone'], true) ? 'summary' : 'ota';
            self::assertSame($value, $snapshot['operation'][$container][$field], $message . ' PII fixture ' . $field);
        }
    }

    /** @return array{guest_name:string,phone:string,id_card:string,order_remark:string} */
    private function syntheticPii(): array
    {
        return [
            'guest_name' => 'TC270_SYNTHETIC_GUEST',
            'phone' => '18800002700',
            'id_card' => '110101199001012700',
            'order_remark' => 'TC270_PRIVATE_ORDER_REMARK',
        ];
    }

    /** @return array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} */
    private static function factors(
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }
}

final class Tc270CapturingLlmClient extends LlmClient
{
    /** @var list<array{messages:array,schema:array,model_key:string}> */
    public array $calls = [];

    public function __construct(private readonly bool $shouldFail)
    {
    }

    public function willFail(): bool
    {
        return $this->shouldFail;
    }

    public function createJsonResponse(
        array $messages,
        array $schema,
        string $modelKey = 'deepseek_v4_default'
    ): array {
        $this->calls[] = [
            'messages' => $messages,
            'schema' => $schema,
            'model_key' => $modelKey,
        ];

        if ($this->shouldFail) {
            throw new RuntimeException('synthetic upstream failure for TC-270');
        }

        return [
            'summary' => 'Synthetic local model response.',
            'recommended_actions' => [],
        ];
    }
}
