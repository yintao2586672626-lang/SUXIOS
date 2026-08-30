<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingOpportunityLabService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RouteContractSource;

final class OperatingOpportunityLabServiceTest extends TestCase
{
    public function testOverviewRequiresAnExplicitAuthenticatedOwnerForPersonalization(): void
    {
        $method = new \ReflectionMethod(OperatingOpportunityLabService::class, 'overview');
        self::assertSame(4, $method->getNumberOfRequiredParameters());
        $approvalSource = (string)file_get_contents(
            __DIR__ . '/../app/service/OperatingOpportunityApprovalService.php'
        );
        self::assertStringContainsString(
            '$this->lab->overview($tenantId, $hotelId, $businessDate, $actorId)',
            $approvalSource
        );
        self::assertStringNotContainsString('int $ownerId = 1', (string)file_get_contents(
            __DIR__ . '/../app/service/OperatingOpportunityLabService.php'
        ));
    }

    private const MIGRATION = __DIR__ . '/../database/migrations/20260822_zzz_create_operating_opportunity_runs.sql';

    public function testCatalogExposesFiveUserVisibleFeaturesWithoutExternalWriteAuthority(): void
    {
        $catalog = (new OperatingOpportunityLabService())->catalog();

        self::assertCount(5, $catalog);
        self::assertSame([
            'daily_one_thing',
            'service_promise_risk',
            'promotion_incrementality',
            'bookability_gap',
            'ai_guest_acquisition',
        ], array_column($catalog, 'key'));
        foreach ($catalog as $item) {
            self::assertFalse($item['external_write_allowed']);
            self::assertNotSame('', $item['question']);
            self::assertSame(OperatingOpportunityLabService::CONTRACT_VERSION, $item['contract_version']);
        }
    }

    public function testMigrationIsScopedAppendOnlyAndSupportsExactReadback(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);
        foreach ([
            'operating_opportunity_runs',
            '`tenant_id`',
            '`system_hotel_id`',
            '`feature_key`',
            '`business_date`',
            '`source_quality_status`',
            '`input_digest`',
            '`result_digest`',
            '`idempotency_key`',
            'uniq_operating_opportunity_idempotency',
        ] as $marker) self::assertStringContainsString($marker, $sql);

        self::assertStringNotContainsString('UPDATE ', strtoupper($sql));
        self::assertStringNotContainsString('DELETE ', strtoupper($sql));
        self::assertStringNotContainsString('operation_execution_intents', $sql);
    }

    public function testUnknownTopLevelSourceQualityFailsClosed(): void
    {
        $method = new \ReflectionMethod(OperatingOpportunityLabService::class, 'sourceQuality');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('数据状态不在允许范围内');
        $method->invoke(new OperatingOpportunityLabService(), 'totally_made_up_quality');
    }

    public function testNestedObservationQualityMustMatchTheSavedTopLevelQuality(): void
    {
        $method = new \ReflectionMethod(
            OperatingOpportunityLabService::class,
            'assertObservationSourceQualityMatches'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('观察证据的数据状态必须与本次数据状态一致');
        $method->invoke(
            new OperatingOpportunityLabService(),
            'ai_guest_acquisition',
            'verified',
            ['observations' => [['source_quality' => 'manual_unverified']]]
        );
    }

    public function testManualInputCannotPromoteItselfToVerifiedFact(): void
    {
        $method = new \ReflectionMethod(
            OperatingOpportunityLabService::class,
            'manualInputSourceQuality'
        );

        self::assertSame(
            'manual_unverified',
            $method->invoke(new OperatingOpportunityLabService(), 'unverified')
        );
        self::assertSame(
            'manual_unverified',
            $method->invoke(new OperatingOpportunityLabService(), 'manual_unverified')
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('人工录入不能自行声明已验证或已回读');
        $method->invoke(new OperatingOpportunityLabService(), 'readback_verified');
    }

    public function testWriteConflictClassifierCoversDuplicateDeadlockAndLockTimeout(): void
    {
        $service = new OperatingOpportunityLabService();
        $duplicate = new \ReflectionMethod(OperatingOpportunityLabService::class, 'isDuplicateKeyConflict');
        $retryable = new \ReflectionMethod(OperatingOpportunityLabService::class, 'isRetryableWriteConflict');

        self::assertTrue($duplicate->invoke($service, new \RuntimeException('Duplicate entry', 23000)));
        self::assertTrue($retryable->invoke($service, new \RuntimeException('Deadlock found', 1213)));
        self::assertTrue($retryable->invoke($service, new \RuntimeException('Lock wait timeout exceeded', 1205)));
        self::assertTrue($retryable->invoke($service, new \RuntimeException('Serialization failure', 40001)));
        self::assertFalse($retryable->invoke($service, new \RuntimeException('ordinary failure', 500)));
    }

    public function testStrictFactSourceFailureBlocksDailySaveAndApproval(): void
    {
        $assert = new \ReflectionMethod(OperatingOpportunityLabService::class, 'assertDailySourceReady');

        try {
            $assert->invoke(new OperatingOpportunityLabService(), [
                'strict_fact_status' => 'source_unavailable',
                'source_errors' => [['code' => 'strict_fact_layer_unavailable']],
            ]);
            self::fail('Strict fact source failures must block daily save and approval.');
        } catch (\RuntimeException $error) {
            self::assertSame(503, $error->getCode());
            self::assertSame('每日事项严格事实来源暂不可用，不能保存或送审', $error->getMessage());
        }
    }

    public function testStoredRunDigestIntegrityRejectsContentTampering(): void
    {
        $service = new OperatingOpportunityLabService();
        $digest = new \ReflectionMethod(OperatingOpportunityLabService::class, 'digest');
        $assert = new \ReflectionMethod(OperatingOpportunityLabService::class, 'assertStoredRunDigestIntegrity');
        $input = ['business_date' => '2026-08-23', 'promised_quantity' => 8];
        $result = ['status' => 'risk_detected', 'shortage_quantity' => 2];
        $run = [
            'input' => $input,
            'result' => $result,
            'input_digest' => $digest->invoke($service, $input),
            'result_digest' => $digest->invoke($service, $result),
        ];
        $assert->invoke($service, $run);

        $run['result']['shortage_quantity'] = 3;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('经营机会记录摘要与保存内容不一致');
        $assert->invoke($service, $run);
    }

    public function testDamagedHistoryIsQuarantinedWithoutHidingVerifiedRows(): void
    {
        $service = new OperatingOpportunityLabService();
        $digest = new \ReflectionMethod(OperatingOpportunityLabService::class, 'digest');
        $project = new \ReflectionMethod(OperatingOpportunityLabService::class, 'projectDailyHistoryRows');
        $input = ['business_date' => '2026-08-28', 'source_digest' => str_repeat('a', 64)];
        $result = ['status' => 'draft', 'selected' => ['problem' => '补齐携程事实']];
        $valid = [
            'id' => 7,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'feature_key' => 'daily_one_thing',
            'business_date' => '2026-08-28',
            'source_quality_status' => 'readback_verified',
            'source_reference' => 'dual_ota_field_closure#abc',
            'input_json' => json_encode($input, JSON_THROW_ON_ERROR),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'input_digest' => $digest->invoke($service, $input),
            'result_digest' => $digest->invoke($service, $result),
            'created_by' => 1,
            'created_at' => '2026-08-29 03:45:18',
        ];
        $damaged = $valid;
        $damaged['id'] = 6;
        $damaged['result_digest'] = str_repeat('0', 64);

        $projection = $project->invoke($service, [$damaged, $valid]);

        self::assertCount(1, $projection['rows']);
        self::assertSame(7, $projection['rows'][0]['id']);
        self::assertSame('readback_verified', $projection['rows'][0]['record_readback_status']);
        self::assertSame([[
            'id' => 6,
            'business_date' => '2026-08-28',
            'status' => 'integrity_failed',
            'reason_code' => 'daily_one_thing_digest_mismatch',
        ]], $projection['errors']);
    }

    public function testManualInputsKeepFormalGateClosedButExposeFourProvisionalCalculations(): void
    {
        $service = new OperatingOpportunityLabService();
        $evaluate = new \ReflectionMethod(OperatingOpportunityLabService::class, 'evaluateFeature');
        $decorate = new \ReflectionMethod(OperatingOpportunityLabService::class, 'withManualEstimate');
        $source = [
            'business_date' => '2026-08-22',
            'source_quality' => 'manual_unverified',
            'source_quality_status' => 'manual_unverified',
            'source_references' => ['user-entry#1'],
        ];

        $payloads = [
            'service_promise_risk' => $source + [
                'benefit_type' => 'breakfast',
                'promised_quantity' => 10,
                'fulfillable_capacity' => 7,
                'breach_cost_per_unit' => 80,
            ],
            'promotion_incrementality' => $source + [
                'promotion_name' => '连住优惠',
                'treated_before' => 100,
                'treated_after' => 140,
                'control_before' => 80,
                'control_after' => 90,
                'treated_before_exposure' => 200,
                'treated_after_exposure' => 200,
                'control_before_exposure' => 200,
                'control_after_exposure' => 200,
                'discount_cost' => 400,
                'contribution_per_incremental_room_night' => 50,
                'design_quality' => 'randomized',
                'pretrend_status' => 'passed',
                'sample_size' => 80,
            ],
            'bookability_gap' => $source + [
                'platform' => 'ctrip',
                'pms_expected_sellable' => 5,
                'real_demand_estimate' => 8,
                'observations' => [[
                    'condition_id' => 'breakfast',
                    'adults' => 2,
                    'children' => 0,
                    'benefits' => ['breakfast'],
                    'search' => 'found',
                    'detail' => 'unavailable',
                    'pre_checkout' => 'not_reached',
                    'observed_at' => '2026-08-22T10:30:00+08:00',
                    'source_quality' => 'manual_unverified',
                    'evidence_ref' => 'user-entry#journey',
                ]],
            ],
            'ai_guest_acquisition' => $source + [
                'observations' => array_map(static fn(int $repeat): array => [
                    'intent' => '上海外滩亲子酒店推荐',
                    'model' => 'manual-observation',
                    'region' => '上海',
                    'observed_at' => sprintf('2026-08-22T09:0%d:00+08:00', $repeat),
                    'repeat_no' => $repeat,
                    'hotel_identified' => true,
                    'facts_checked' => true,
                    'facts_correct' => true,
                    'matched' => true,
                    'bookable_handoff' => true,
                    'source_quality' => 'manual_unverified',
                    'evidence_ref' => 'user-entry#ai-' . $repeat,
                ], [1, 2, 3]),
            ],
        ];

        $results = [];
        foreach ($payloads as $featureKey => $payload) {
            $formal = $evaluate->invoke($service, $featureKey, $payload);
            $result = $decorate->invoke($service, $featureKey, $payload, $formal);
            self::assertSame('provisional_manual_estimate', $result['calculation_status'], $featureKey);
            self::assertSame('manual_estimate', $result['metric_provenance'], $featureKey);
            self::assertTrue($result['manual_estimate'], $featureKey);
            self::assertNull($result['formal_conclusion'], $featureKey);
            self::assertFalse($result['decision_eligible'], $featureKey);
            self::assertFalse($result['can_execute'], $featureKey);
            self::assertNotEmpty($result['provisional_metrics'], $featureKey);
            self::assertStringNotContainsString(
                '"source_quality":"manual_verified"',
                json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $featureKey
            );
            $results[$featureKey] = $result;
        }

        self::assertSame(3, $results['service_promise_risk']['provisional_metrics']['shortage_quantity']);
        self::assertSame(240.0, $results['service_promise_risk']['provisional_metrics']['risk_amount']);
        self::assertEqualsWithDelta(
            30.0,
            $results['promotion_incrementality']['provisional_metrics']['incremental_room_nights'],
            0.000000001
        );
        self::assertSame(1, $results['bookability_gap']['provisional_metrics']['affected_condition_count']);
        self::assertSame(5.0, $results['bookability_gap']['provisional_metrics']['potential_loss']);
        self::assertSame(3, $results['ai_guest_acquisition']['provisional_metrics']['eligible_observation_count']);
        self::assertSame(
            100.0,
            $results['ai_guest_acquisition']['provisional_metrics']['gate_pass_rates']['bookable_handoff']['pass_rate_percent']
        );
    }

    public function testControllerEvaluatePathCallsScopedServiceSaveAndReadback(): void
    {
        $controller = (string)file_get_contents(__DIR__ . '/../app/controller/OperatingOpportunity.php');
        $routes = RouteContractSource::read(dirname(__DIR__));

        self::assertStringContainsString("Route::post('/evaluate', 'OperatingOpportunity/evaluate')", $routes);
        self::assertStringContainsString('resolveSingleHotelScope(', $controller);
        self::assertStringContainsString("'operation.execute'", $controller);
        self::assertStringContainsString('$this->service->evaluateAndSave(', $controller);
        self::assertStringContainsString('计算结果已保存并完成精确回读', $controller);
        self::assertStringContainsString(
            "Route::post('/daily-preview/feedback', 'OperatingOpportunity/dailyPreviewFeedback')",
            $routes
        );
        self::assertStringContainsString('recordDailyPreviewFeedback(', $controller);
        self::assertStringContainsString("'operation.view'", $controller);
        $service = (string)file_get_contents(__DIR__ . '/../app/service/OperatingOpportunityLabService.php');
        self::assertStringContainsString('DailyOneThingPersonalizationService', $service);
        self::assertStringContainsString("'personalized_today_preview' => \$personalizedPriority", $service);
        self::assertStringContainsString("'hotel_shared_daily_item_changed' => false", $service);
        self::assertStringContainsString("'execution_intent_created' => false", $service);
        self::assertStringContainsString("'external_write_count' => 0", $service);
    }

    public function testCanonicalDigestIsStableAcrossObjectKeyOrderAndReadbackRecomputesContent(): void
    {
        $service = new OperatingOpportunityLabService();
        $digest = new \ReflectionMethod(OperatingOpportunityLabService::class, 'digest');
        $assertReadback = new \ReflectionMethod(OperatingOpportunityLabService::class, 'assertReadbackIntegrity');
        $input = ['nested' => ['z' => 1, 'a' => 2], 'business_date' => '2026-08-22'];
        $sameInputDifferentOrder = ['business_date' => '2026-08-22', 'nested' => ['a' => 2, 'z' => 1]];
        $result = ['decision_eligible' => false, 'metrics' => ['b' => 2, 'a' => 1]];
        $inputDigest = $digest->invoke($service, $input);
        $resultDigest = $digest->invoke($service, $result);

        self::assertSame($inputDigest, $digest->invoke($service, $sameInputDifferentOrder));
        $readback = [
            'tenant_id' => 71,
            'system_hotel_id' => 80,
            'feature_key' => 'promotion_incrementality',
            'business_date' => '2026-08-22',
            'source_quality_status' => 'manual_unverified',
            'source_reference' => 'user-entry#promotion',
            'created_by' => 9,
            'input' => $sameInputDifferentOrder,
            'result' => $result,
            'input_digest' => $inputDigest,
            'result_digest' => $resultDigest,
        ];
        $assertReadback->invoke(
            $service,
            $readback,
            71,
            80,
            'promotion_incrementality',
            '2026-08-22',
            'manual_unverified',
            'user-entry#promotion',
            9,
            $inputDigest,
            $resultDigest
        );

        $readback['input']['nested']['a'] = 999;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('经营机会计算保存后精确回读失败');
        $assertReadback->invoke(
            $service,
            $readback,
            71,
            80,
            'promotion_incrementality',
            '2026-08-22',
            'manual_unverified',
            'user-entry#promotion',
            9,
            $inputDigest,
            $resultDigest
        );
    }

    #[DataProvider('readbackMetadataDriftProvider')]
    public function testReadbackMetadataDriftFailsClosed(string $field, mixed $tamperedValue): void
    {
        $service = new OperatingOpportunityLabService();
        $digest = new \ReflectionMethod(OperatingOpportunityLabService::class, 'digest');
        $assertReadback = new \ReflectionMethod(OperatingOpportunityLabService::class, 'assertReadbackIntegrity');
        $input = ['business_date' => '2026-08-22'];
        $result = ['decision_eligible' => false];
        $inputDigest = $digest->invoke($service, $input);
        $resultDigest = $digest->invoke($service, $result);
        $readback = [
            'tenant_id' => 71,
            'system_hotel_id' => 80,
            'feature_key' => 'promotion_incrementality',
            'business_date' => '2026-08-22',
            'source_quality_status' => 'manual_unverified',
            'source_reference' => 'user-entry#promotion',
            'created_by' => 9,
            'input' => $input,
            'result' => $result,
            'input_digest' => $inputDigest,
            'result_digest' => $resultDigest,
        ];
        $readback[$field] = $tamperedValue;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('经营机会计算保存后精确回读失败');
        $assertReadback->invoke(
            $service,
            $readback,
            71,
            80,
            'promotion_incrementality',
            '2026-08-22',
            'manual_unverified',
            'user-entry#promotion',
            9,
            $inputDigest,
            $resultDigest
        );
    }

    /** @return iterable<string,array{0:string,1:mixed}> */
    public static function readbackMetadataDriftProvider(): iterable
    {
        yield 'tenant' => ['tenant_id', 72];
        yield 'hotel' => ['system_hotel_id', 81];
        yield 'feature' => ['feature_key', 'bookability_gap'];
        yield 'business date' => ['business_date', '2026-08-21'];
        yield 'source quality' => ['source_quality_status', 'verified'];
        yield 'source reference' => ['source_reference', 'user-entry#different'];
        yield 'creator' => ['created_by', 10];
    }

    public function testTruncatedOrScalarJsonFailsClosedDuringReadbackDecode(): void
    {
        $decode = new \ReflectionMethod(OperatingOpportunityLabService::class, 'decodeJson');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('经营机会计算记录JSON损坏或被截断');
        $decode->invoke(new OperatingOpportunityLabService(), '{"truncated":');
    }

    public function testScalarJsonFailsClosedDuringReadbackDecode(): void
    {
        $decode = new \ReflectionMethod(OperatingOpportunityLabService::class, 'decodeJson');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('经营机会计算记录JSON必须是对象或数组');
        $decode->invoke(new OperatingOpportunityLabService(), '"not-an-object"');
    }

    public function testInputBudgetAcceptsObservationAndReferenceBoundaries(): void
    {
        $budget = new \ReflectionMethod(OperatingOpportunityLabService::class, 'assertInputBudget');
        $service = new OperatingOpportunityLabService();

        $budget->invoke($service, [
            'observations' => array_fill(0, 100, []),
            'source_references' => array_map(static fn(int $index): string => 'ref#' . $index, range(1, 50)),
            'note' => str_repeat('a', 1000),
        ]);

        self::assertTrue(true);
    }

    public function testInputBudgetRejectsNPlusOneObservationsBeforeSchemaOrSave(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会观察记录不能超过100条');

        (new OperatingOpportunityLabService())->evaluateAndSave(71, 80, 9, [
            'feature_key' => 'ai_guest_acquisition',
            'observations' => array_fill(0, 101, []),
        ]);
    }

    public function testInputBudgetRejectsNPlusOneReferences(): void
    {
        $budget = new \ReflectionMethod(OperatingOpportunityLabService::class, 'assertInputBudget');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会来源引用不能超过50条');
        $budget->invoke(new OperatingOpportunityLabService(), [
            'source_references' => array_map(static fn(int $index): string => 'ref#' . $index, range(1, 51)),
        ]);
    }

    public function testInputBudgetRejectsTextNPlusOneAndOversizedJson(): void
    {
        $budget = new \ReflectionMethod(OperatingOpportunityLabService::class, 'assertInputBudget');
        $service = new OperatingOpportunityLabService();

        try {
            $budget->invoke($service, ['note' => str_repeat('a', 1001)]);
            self::fail('1001-character text must be rejected');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            self::assertInstanceOf(InvalidArgumentException::class, $error->getPrevious() ?? $error);
            self::assertStringContainsString('单条文本不能超过1000字符', $error->getMessage());
        }

        $chunks = [];
        foreach (range(1, 270) as $index) {
            $chunks['chunk_' . $index] = str_repeat('b', 1000);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会输入不能超过256KB');
        $budget->invoke($service, ['chunks' => $chunks]);
    }
}
