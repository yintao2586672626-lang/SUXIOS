<?php
declare(strict_types=1);

namespace Tests;

use app\service\CanonicalOtaInvestigationDraftService;
use app\service\OtaCanonicalHistoryPromotionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CanonicalOtaInvestigationDraftServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $scope;

    /** @var array<string,mixed> */
    private array $row;

    /** @var array<string,mixed> */
    private array $task;

    private string $tempBase;

    private string $storageRoot;

    protected function setUp(): void
    {
        $this->scope = [
            'tenant_id' => 80,
            'hotel_id' => 80,
            'data_source_id' => 25,
            'task_id' => 3058,
            'row_id' => 81878,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
        ];
        $requiredMetrics = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $traceId = 'trace-81878';
        $urlHash = str_repeat('f', 64);
        $captureSource = 'xhr:business_flow_transform';
        $sourceRow = [
            'hotelId' => 'ctrip-hotel-80',
            'date' => '2026-08-09',
            '_capture_source' => $captureSource,
            '_observed_traffic_metric_keys' => $requiredMetrics,
        ];
        $fieldFacts = [];
        foreach ($requiredMetrics as $metric) {
            $sourceRow[$metric] = 0;
            $fieldFacts[] = [
                'metric_key' => $metric,
                'status' => 'captured',
                'source_key' => $metric,
                'source_path' => 'row.' . $metric,
                'storage_field' => 'online_daily_data.' . $metric,
                'stored_value_present' => true,
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                    'capture_source' => $captureSource,
                ],
            ];
        }
        $this->row = [
            'id' => 81878,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 3058,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'data_type' => 'traffic',
            'dimension' => '',
            'compare_type' => 'self',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'flow_rate' => '0.00',
            'order_filling_num' => 0,
            'order_submit_num' => 0,
            'source_trace_id' => $traceId,
            'snapshot_time' => '2026-08-09 06:30:00',
            'raw_data' => json_encode([
                'endpoint_id' => 'business_flow_transform',
                'date_source' => 'response.data.statDate',
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
                'row' => $sourceRow,
                'field_facts' => $fieldFacts,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        $this->task = $this->validTask();
        $this->tempBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'suxi_canonical_ota_draft_test_'
            . bin2hex(random_bytes(8));
        $this->storageRoot = $this->tempBase . DIRECTORY_SEPARATOR . 'drafts';
    }

    protected function tearDown(): void
    {
        $this->removeTempBase();
    }

    public function testPreflightBuildsFourBlockedInvestigationDraftsWithoutWriting(): void
    {
        $result = $this->service()->preflight($this->scope);

        self::assertSame('ready', $result['status']);
        self::assertFalse($result['execute']);
        self::assertFalse($result['readback_verified']);
        self::assertSame(4, $result['draft_count']);
        self::assertFalse(is_dir($this->storageRoot), 'Preflight must not create the storage root.');
        self::assertSame(
            $this->digest($result['draft_set']),
            $result['draft_set']['content_digest']
        );

        foreach ($result['draft_set']['drafts'] as $draft) {
            self::assertSame('investigation_check', $draft['action_kind']);
            self::assertFalse($draft['causality_claimed']);
            self::assertFalse($draft['outcome_claimed']);
            self::assertNull($draft['assignee']);
            self::assertNull($draft['due']);
            self::assertNull($draft['reviewer']);
            self::assertNull($draft['review_at']);
            self::assertSame('blocked_by_missing_assignee', $draft['assignment_status']);
            self::assertSame('blocked_by_missing_due', $draft['due_status']);
            self::assertSame('blocked_by_missing_reviewer_and_review_at', $draft['review_status']);
            self::assertSame('blocked_by_missing_assignment_due_review', $draft['approval_status']);
            self::assertSame('not_authorized', $draft['execution_status']);
            self::assertSame(81878, $draft['evidence_refs'][0]['row_id']);
            self::assertSame(3058, $draft['evidence_refs'][0]['sync_task_id']);
        }
    }

    public function testPreflightBuildsFourEvidenceBoundDraftsFromAuthoritativeNonzeroRow(): void
    {
        $row = $this->nonzeroRow();
        $result = $this->service($row, $this->validTask($row))->preflight($this->scope);

        self::assertSame('ready', $result['status']);
        self::assertSame('ota_canonical_history_promotion.v3', $result['draft_set']['source_fact']['promotion_version']);
        self::assertSame('nonzero', $result['draft_set']['source_fact']['traffic_value_status']);
        self::assertSame(1, $result['draft_set']['source_fact']['nonzero_required_metric_rows']);
        self::assertSame(0, $result['draft_set']['source_fact']['explicit_zero_confirmed_rows']);
        self::assertSame([
            'check_list_to_detail_mathematical_consistency',
            'investigate_detail_to_order_fill_breakpoint',
            'investigate_fill_to_submit_chain',
            'prepare_same_scope_recollection_and_entry_eligibility_check',
        ], array_column($result['draft_set']['drafts'], 'action_code'));
        foreach ($result['draft_set']['drafts'] as $draft) {
            self::assertFalse($draft['causality_claimed']);
            self::assertFalse($draft['outcome_claimed']);
            self::assertSame('not_authorized', $draft['execution_status']);
            self::assertSame('unknown_requires_investigation', $draft['cause_status']);
        }
        self::assertFalse(is_dir($this->storageRoot));
    }

    public function testPreflightAcceptsExactSelectedMemberOfMultiRowV3Promotion(): void
    {
        $task = $this->validMultiRowTask($this->row);

        $result = $this->service($this->row, $task)->preflight($this->scope);

        self::assertSame('ready', $result['status']);
        $sourceFact = $result['draft_set']['source_fact'];
        self::assertSame($this->authoritativeFactDigest($this->row), $sourceFact['authoritative_fact_digest']);
        self::assertSame(str_repeat('c', 64), $sourceFact['promotion_authoritative_fact_digest']);
        self::assertSame([81871, 81878, 81914], $sourceFact['run_readback_row_ids']);
        self::assertSame(4, $result['draft_count']);
    }

    public function testRejectsMultiRowPromotionWhoseDigestMapMembershipIsNotExact(): void
    {
        $task = $this->validMultiRowTask($this->row);
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($stats['canonical_history_promotion']['authoritative_row_fact_digests'][81914]);
        $stats['canonical_history_promotion']['content_digest'] = $this->digest(
            $stats['canonical_history_promotion']
        );
        $task['stats_json'] = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_promotion_receipt_row_digest_map_invalid');
        $this->service($this->row, $task)->preflight($this->scope);
    }

    public function testRejectsMultiRowPromotionThatSelectsNonDeterministicSecondRow(): void
    {
        $task = $this->validMultiRowTask($this->row);
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $receipt = $stats['canonical_history_promotion'];
        $selection = [
            'version' => $receipt['operation_row_selection_version'],
            'status' => $receipt['operation_row_selection_status'],
            'policy' => $receipt['operation_row_selection_policy'],
            'platform' => 'ctrip',
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 3058,
            'target_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'candidate_row_ids' => [81878, 81914],
            'selected_row_id' => 81914,
            'row_metric_digests' => $receipt['operation_row_metric_digests'],
        ];
        $selection['selection_digest'] = $this->digest($selection);
        $receipt['selected_operation_row_id'] = 81914;
        $receipt['operation_row_selection_digest'] = $selection['selection_digest'];
        $receipt['content_digest'] = $this->digest($receipt);
        $stats['canonical_history_promotion'] = $receipt;
        $task['stats_json'] = json_encode($stats, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_promotion_operation_row_selection_invalid');
        $this->service($this->row, $task)->preflight($this->scope);
    }

    public function testRejectsMultiRowPromotionWhoseAggregateCountsDoNotEqualMembership(): void
    {
        $task = $this->validMultiRowTask($this->row);
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['canonical_history_promotion']['nonzero_required_metric_rows'] = 0;
        $stats['canonical_history_promotion']['explicit_zero_confirmed_rows'] = 1;
        $stats['canonical_history_promotion']['content_digest'] = $this->digest(
            $stats['canonical_history_promotion']
        );
        $task['stats_json'] = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_promotion_receipt_fact_gate_failed');
        $this->service($this->row, $task)->preflight($this->scope);
    }

    public function testExecuteIsAtomicIdempotentAndExactlyReadable(): void
    {
        $first = $this->service()->execute($this->scope);
        $second = $this->service()->execute($this->scope);

        self::assertSame('saved', $first['status']);
        self::assertFalse($first['idempotent']);
        self::assertTrue($first['readback_verified']);
        self::assertTrue($second['idempotent']);
        self::assertTrue($second['readback_verified']);
        self::assertSame($first['path'], $second['path']);
        self::assertSame($first['content_digest'], $second['content_digest']);
        $resolvedStorageRoot = realpath($this->storageRoot);
        self::assertNotFalse($resolvedStorageRoot);
        self::assertStringStartsWith(
            $this->normalizedPath((string)$resolvedStorageRoot),
            $this->normalizedPath($first['path'])
        );

        $raw = file_get_contents($first['path']);
        self::assertNotFalse($raw);
        $readback = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($first['draft_set'], $readback);
        self::assertSame($this->digest($readback), $readback['content_digest']);
        self::assertCount(1, glob(dirname($first['path']) . DIRECTORY_SEPARATOR . '*.json') ?: []);
    }

    public function testRejectsCrossHotelRow(): void
    {
        $scope = $this->scope;
        $scope['hotel_id'] = 81;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_row_scope_mismatch');
        $this->service()->preflight($scope);
    }

    public function testRejectsRowFromAnotherTask(): void
    {
        $row = $this->row;
        $row['sync_task_id'] = 3059;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_row_scope_mismatch');
        $this->service($row)->preflight($this->scope);
    }

    public function testRejectsPartialRow(): void
    {
        $row = $this->row;
        $row['validation_status'] = 'partial';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_row_validation_gate_failed');
        $this->service($row)->preflight($this->scope);
    }

    public function testRejectsRunReadbackTaskMismatch(): void
    {
        $task = $this->task;
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['run_readback']['sync_task_id'] = 3059;
        $task['stats_json'] = json_encode($stats, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_run_readback_scope_mismatch');
        $this->service(null, $task)->preflight($this->scope);
    }

    public function testRejectsRunReadbackThatDoesNotContainCanonicalRow(): void
    {
        $task = $this->task;
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['run_readback']['row_ids'] = [81871, 81872];
        $task['stats_json'] = json_encode($stats, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_run_readback_row_missing');
        $this->service(null, $task)->preflight($this->scope);
    }

    public function testRejectsRunReadbackThatIsNotP0Ready(): void
    {
        $task = $this->task;
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['run_readback']['p0_status'] = 'partial';
        $task['stats_json'] = json_encode($stats, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_run_readback_not_p0_ready');
        $this->service(null, $task)->preflight($this->scope);
    }

    public function testRejectsTamperedPromotionReceipt(): void
    {
        $task = $this->task;
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['canonical_history_promotion']['explicit_zero_confirmed_rows'] = 2;
        $task['stats_json'] = json_encode($stats, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_promotion_receipt_fact_gate_failed');
        $this->service(null, $task)->preflight($this->scope);
    }

    public function testRejectsReceiptContentDigestTamper(): void
    {
        $task = $this->task;
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['canonical_history_promotion']['verified_at'] = '2026-08-09 06:36:00';
        $task['stats_json'] = json_encode($stats, JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_promotion_receipt_content_digest_invalid');
        $this->service(null, $task)->preflight($this->scope);
    }

    public function testRejectsV2PromotionReceiptEvenWhenItsSelfDigestIsValid(): void
    {
        $task = $this->task;
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['canonical_history_promotion']['version'] = 'ota_canonical_history_promotion.v2';
        $stats['canonical_history_promotion']['content_digest'] = $this->digest(
            $stats['canonical_history_promotion']
        );
        $task['stats_json'] = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_promotion_receipt_scope_mismatch');
        $this->service(null, $task)->preflight($this->scope);
    }

    public function testRejectsNonzeroRowWhenV3ReceiptDeclaresExplicitZero(): void
    {
        $row = $this->nonzeroRow();
        $task = $this->validTask($row);
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['canonical_history_promotion']['nonzero_required_metric_rows'] = 0;
        $stats['canonical_history_promotion']['explicit_zero_confirmed_rows'] = 1;
        $stats['canonical_history_promotion']['content_digest'] = $this->digest(
            $stats['canonical_history_promotion']
        );
        $task['stats_json'] = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_promotion_receipt_fact_gate_failed');
        $this->service($row, $task)->preflight($this->scope);
    }

    public function testReadbackRejectsDraftWithoutExplicitAssignmentBlocker(): void
    {
        $saved = $this->service()->execute($this->scope);
        $tampered = $saved['draft_set'];
        unset($tampered['drafts'][0]['assignment_status']);
        $tampered['content_digest'] = $this->digest($tampered);
        file_put_contents(
            $saved['path'],
            json_encode($tampered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_investigation_draft_assignment_contract_invalid');
        $this->service()->execute($this->scope);
    }

    public function testRejectsUnsupportedPlatformEvenWhenEveryMetricIsExplicitZero(): void
    {
        $scope = $this->scope;
        $scope['platform'] = 'qunar';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_scope_platform_invalid');
        $this->service()->preflight($scope);
    }

    public function testMeituanBusinessTaskBuildsFourIndependentDraftsFromOnlyThreeTrafficFacts(): void
    {
        $scope = $this->meituanScope();
        $row = $this->meituanRow($scope);
        $task = $this->validMeituanTask($row, $scope);

        $result = $this->service($row, $task)->preflight($scope);

        self::assertSame('ready', $result['status']);
        self::assertSame(4, $result['draft_count']);
        self::assertSame([
            'detail_exposure',
            'flow_rate',
            'list_exposure',
        ], array_keys($result['draft_set']['source_fact']['traffic_metric_values']));
        $codes = array_column($result['draft_set']['drafts'], 'action_code');
        self::assertSame([
            'check_meituan_list_detail_count_order',
            'calculate_meituan_list_to_detail_rate',
            'check_meituan_observed_flow_rate_alignment',
            'prepare_same_scope_recollection_and_entry_eligibility_check',
        ], $codes);
        $serialized = strtolower(json_encode(
            $result['draft_set']['drafts'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        self::assertStringNotContainsString('ctrip', $serialized);
        self::assertStringNotContainsString('order_filling_num', $serialized);
        self::assertStringNotContainsString('order_submit_num', $serialized);
    }

    public function testRejectsMissingObservedTrafficMetricProvenance(): void
    {
        $row = $this->row;
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        unset($raw['row']['_observed_traffic_metric_keys']);
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('synthetic_normalization_provenance_missing');
        $this->service($row)->preflight($this->scope);
    }

    public function testRejectsObservedTrafficMetricProvenanceWithAnExtraKey(): void
    {
        $row = $this->row;
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $raw['row']['_observed_traffic_metric_keys'][] = 'synthetic_metric';
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('synthetic_normalization_provenance_missing');
        $this->service($row)->preflight($this->scope);
    }

    public function testRejectsObservedTrafficMetricProvenanceThatIsNotStrictSnakeCase(): void
    {
        $row = $this->row;
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $raw['row']['_observed_traffic_metric_keys'][4] = 'order__submit_num';
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('synthetic_normalization_provenance_missing');
        $this->service($row)->preflight($this->scope);
    }

    public function testRejectsTrafficMetricThatIsNotANumericRawFact(): void
    {
        $row = $this->row;
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $raw['row']['detail_exposure'] = 'not-observed';
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_authoritative_metric_fact_invalid:detail_exposure');
        $this->service($row)->preflight($this->scope);
    }

    public function testRejectsRehashedReceiptWithWrongAuthoritativeFactDigest(): void
    {
        $task = $this->task;
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['canonical_history_promotion']['authoritative_fact_digest'] = str_repeat('e', 64);
        $stats['canonical_history_promotion']['content_digest'] = $this->digest(
            $stats['canonical_history_promotion']
        );
        $task['stats_json'] = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_authoritative_fact_digest_mismatch');
        $this->service(null, $task)->preflight($this->scope);
    }

    public function testAuthoritativeFactDigestMatchesCurrentPromotionProducer(): void
    {
        $required = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        sort($required, SORT_STRING);
        $producer = new OtaCanonicalHistoryPromotionService();
        $method = new \ReflectionMethod($producer, 'authoritativeFactProof');
        $proof = $method->invoke(
            $producer,
            [$this->row],
            [
                'platform' => 'ctrip',
                'tenant_id' => 80,
                'system_hotel_id' => 80,
                'data_source_id' => 25,
                'sync_task_id' => 3058,
                'target_date' => '2026-08-09',
                'data_period' => 'realtime_snapshot',
                'required_metric_keys' => $required,
            ]
        );

        self::assertIsArray($proof);
        self::assertSame($proof['digest'], $this->authoritativeFactDigest($this->row));
        self::assertSame(
            $this->authoritativeFactDigest($this->row),
            $proof['row_digests'][$this->row['id']]
        );
        self::assertSame(0, $proof['nonzero_required_metric_rows']);
        self::assertSame(1, $proof['explicit_zero_confirmed_rows']);

        $nonzeroRow = $this->nonzeroRow();
        $nonzeroProof = $method->invoke(
            $producer,
            [$nonzeroRow],
            [
                'platform' => 'ctrip',
                'tenant_id' => 80,
                'system_hotel_id' => 80,
                'data_source_id' => 25,
                'sync_task_id' => 3058,
                'target_date' => '2026-08-09',
                'data_period' => 'realtime_snapshot',
                'required_metric_keys' => $required,
            ]
        );

        self::assertIsArray($nonzeroProof);
        self::assertSame($nonzeroProof['digest'], $this->authoritativeFactDigest($nonzeroRow));
        self::assertSame(1, $nonzeroProof['nonzero_required_metric_rows']);
        self::assertSame(0, $nonzeroProof['explicit_zero_confirmed_rows']);
    }

    public function testRejectsCurrentRawEvidenceDriftAfterPromotion(): void
    {
        $row = $this->row;
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $raw['row']['diagnostic_marker'] = 'changed-after-promotion';
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_authoritative_fact_digest_mismatch');
        $this->service($row)->preflight($this->scope);
    }

    public function testRejectsCurrentPlatformHotelIdentityDrift(): void
    {
        $row = $this->row;
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $raw['row']['hotelId'] = 'ctrip-hotel-81';
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $task = $this->task;
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $stats['canonical_history_promotion']['authoritative_fact_digest'] = $this->authoritativeFactDigest($row);
        $stats['canonical_history_promotion']['content_digest'] = $this->digest(
            $stats['canonical_history_promotion']
        );
        $task['stats_json'] = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_platform_hotel_identity_mismatch');
        $this->service($row, $task)->preflight($this->scope);
    }

    public function testPreflightReportsExistingExactDraftAsIdempotentReadback(): void
    {
        $saved = $this->service()->execute($this->scope);
        $preflight = $this->service()->preflight($this->scope);

        self::assertTrue($saved['readback_verified']);
        self::assertTrue($preflight['idempotent']);
        self::assertTrue($preflight['readback_verified']);
        self::assertFalse($preflight['would_write']);
        self::assertSame($saved['content_digest'], $preflight['content_digest']);
    }

    public function testRejectsSymlinkedAncestorBeforeCreatingOutsideDirectories(): void
    {
        $outside = $this->tempBase . DIRECTORY_SEPARATOR . 'outside';
        self::assertTrue(mkdir($this->storageRoot, 0775, true));
        self::assertTrue(mkdir($outside, 0775, true));
        $link = $this->storageRoot . DIRECTORY_SEPARATOR . '20260809';
        if (!@symlink($outside, $link)) {
            self::markTestSkipped('Directory symlinks are not available in this PHP runtime.');
        }

        try {
            $this->service()->execute($this->scope);
            self::fail('A symlinked storage ancestor must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('canonical_investigation_draft_link_rejected', $exception->getMessage());
        }
        self::assertFalse(is_dir($outside . DIRECTORY_SEPARATOR . 'tenant_80'));
    }

    public function testRejectsSymlinkedStorageRoot(): void
    {
        $outside = $this->tempBase . DIRECTORY_SEPARATOR . 'outside-root';
        self::assertTrue(mkdir($outside, 0775, true));
        if (!@symlink($outside, $this->storageRoot)) {
            self::markTestSkipped('Directory symlinks are not available in this PHP runtime.');
        }

        try {
            $this->service()->preflight($this->scope);
            self::fail('A symlinked storage root must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('canonical_investigation_draft_link_rejected', $exception->getMessage());
        }
        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
    }

    public function testRejectsSymlinkedTargetBeforeReadingOrRenaming(): void
    {
        $planned = $this->service()->preflight($this->scope)['planned_path'];
        self::assertTrue(mkdir(dirname($planned), 0775, true));
        $outsideFile = $this->tempBase . DIRECTORY_SEPARATOR . 'outside-target.json';
        self::assertSame(8, file_put_contents($outsideFile, 'sentinel'));
        if (!@symlink($outsideFile, $planned)) {
            self::markTestSkipped('File symlinks are not available in this PHP runtime.');
        }

        try {
            $this->service()->execute($this->scope);
            self::fail('A symlinked target must be rejected before read or rename.');
        } catch (RuntimeException $exception) {
            self::assertSame('canonical_investigation_draft_link_rejected', $exception->getMessage());
        }
        self::assertSame('sentinel', file_get_contents($outsideFile));
    }

    public function testRejectsSymlinkedLockBeforeOpeningIt(): void
    {
        $planned = $this->service()->preflight($this->scope)['planned_path'];
        self::assertTrue(mkdir(dirname($planned), 0775, true));
        $outsideFile = $this->tempBase . DIRECTORY_SEPARATOR . 'outside-lock-target.txt';
        self::assertSame(8, file_put_contents($outsideFile, 'sentinel'));
        if (!@symlink($outsideFile, $planned . '.lock')) {
            self::markTestSkipped('File symlinks are not available in this PHP runtime.');
        }

        try {
            $this->service()->execute($this->scope);
            self::fail('A symlinked lock must be rejected before fopen.');
        } catch (RuntimeException $exception) {
            self::assertSame('canonical_investigation_draft_link_rejected', $exception->getMessage());
        }
        self::assertSame('sentinel', file_get_contents($outsideFile));
    }

    public function testRejectsWindowsJunctionBeforeCreatingOutsideDirectories(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\'
            || !function_exists('exec')
            || str_contains(strtolower((string)ini_get('disable_functions')), 'exec')
        ) {
            self::markTestSkipped('Windows junction creation is not available in this PHP runtime.');
        }
        $outside = $this->tempBase . DIRECTORY_SEPARATOR . 'junction-outside';
        self::assertTrue(mkdir($this->storageRoot, 0775, true));
        self::assertTrue(mkdir($outside, 0775, true));
        $junction = $this->storageRoot . DIRECTORY_SEPARATOR . '20260809';
        $output = [];
        $exitCode = -1;
        exec(
            'cmd.exe /D /C mklink /J '
                . escapeshellarg($junction)
                . ' '
                . escapeshellarg($outside)
                . ' 2>NUL',
            $output,
            $exitCode
        );
        if ($exitCode !== 0 || !is_dir($junction)) {
            self::markTestSkipped('The Windows junction probe could not be created.');
        }

        try {
            try {
                $this->service()->execute($this->scope);
                self::fail('A junction inside the storage root must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('canonical_investigation_draft_link_rejected', $exception->getMessage());
            }
            self::assertFalse(is_dir($outside . DIRECTORY_SEPARATOR . 'tenant_80'));
        } finally {
            if (is_dir($junction)) {
                rmdir($junction);
            }
        }
    }

    public function testCliAdvertisesBothSupportedPlatformsAndDoesNotEchoRawInvalidArguments(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/scripts/create_canonical_ota_investigation_drafts.php');
        self::assertNotFalse($source);
        self::assertStringContainsString('--platform=ctrip|meituan', (string)$source);
        self::assertStringContainsString('v3 authoritative zero or nonzero traffic row', (string)$source);
        self::assertStringNotContainsString("'invalid_cli_argument:' . \$argument", (string)$source);
        self::assertStringContainsString('canonicalDraftSafeErrorReason($exception)', (string)$source);
    }

    /** @return array<string,mixed> */
    private function meituanScope(): array
    {
        return [
            'tenant_id' => 80,
            'hotel_id' => 80,
            'data_source_id' => 68,
            'task_id' => 6800,
            'row_id' => 6801,
            'platform' => 'meituan',
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
        ];
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function meituanRow(array $scope): array
    {
        $traceId = 'meituan-trace-6801';
        $urlHash = hash('sha256', 'meituan-source-url-6801');
        $sourceKeys = [
            'list_exposure' => 'listExposure',
            'detail_exposure' => 'detailExposure',
            'flow_rate' => 'flowRate',
        ];
        $values = [
            'list_exposure' => 1200,
            'detail_exposure' => 300,
            'flow_rate' => 25,
        ];
        $sourceRow = [
            'date' => $scope['target_date'],
            'poiId' => 'meituan-poi-80',
            '_capture_source' => 'xhr:traffic',
            '_observed_traffic_metric_keys' => array_keys($sourceKeys),
        ];
        $fieldFacts = [];
        foreach ($sourceKeys as $metric => $sourceKey) {
            $sourceRow[$sourceKey] = $values[$metric];
            $fieldFacts[] = [
                'metric_key' => $metric,
                'status' => 'captured',
                'source_key' => $sourceKey,
                'source_path' => 'data.traffic.' . $sourceKey,
                'storage_field' => 'online_daily_data.' . $metric,
                'stored_value_present' => true,
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                    'capture_source' => 'xhr:traffic',
                ],
            ];
        }
        return [
            'id' => $scope['row_id'],
            'tenant_id' => $scope['tenant_id'],
            'system_hotel_id' => $scope['hotel_id'],
            'hotel_id' => 'meituan-poi-80',
            'data_source_id' => $scope['data_source_id'],
            'sync_task_id' => $scope['task_id'],
            'source' => 'meituan',
            'platform' => 'meituan',
            'data_date' => $scope['target_date'],
            'data_period' => $scope['data_period'],
            'data_type' => 'traffic',
            'dimension' => '',
            'compare_type' => 'self',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'list_exposure' => $values['list_exposure'],
            'detail_exposure' => $values['detail_exposure'],
            'flow_rate' => $values['flow_rate'],
            'order_filling_num' => null,
            'order_submit_num' => null,
            'source_trace_id' => $traceId,
            'snapshot_time' => '2026-08-09 05:09:55',
            'raw_data' => json_encode([
                'row' => $sourceRow,
                'captured_at' => '2026-08-08T21:09:55.123456Z',
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
                'date_source' => 'request.payload.statDate',
                'field_facts' => $fieldFacts,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $scope @return array<string,mixed> */
    private function validMeituanTask(array $row, array $scope): array
    {
        $receipt = [
            'version' => 'ota_canonical_history_promotion.v3',
            'tenant_id' => $scope['tenant_id'],
            'system_hotel_id' => $scope['hotel_id'],
            'platform' => 'meituan',
            'target_date' => $scope['target_date'],
            'data_period' => $scope['data_period'],
            'data_source_id' => $scope['data_source_id'],
            'sync_task_id' => $scope['task_id'],
            'row_ids' => [$scope['row_id']],
            'collection_anchor_hash' => str_repeat('1', 64),
            'verifier_report_hash' => str_repeat('2', 64),
            'authoritative_fact_digest' => $this->authoritativeFactDigest($row),
            'platform_hotel_identity_digest' => $this->platformHotelIdentityDigest($row, $scope),
            'nonzero_required_metric_rows' => 1,
            'explicit_zero_confirmed_rows' => 0,
            'observed_traffic_metric_provenance_status' => 'ready',
            'synthetic_normalization_provenance_missing_rows' => 0,
            'verified_at' => '2026-08-09 05:10:00',
            'sensitive_values_exposed' => false,
        ];
        $receipt['content_digest'] = $this->digest($receipt);
        $required = ['detail_exposure', 'flow_rate', 'list_exposure'];
        $stats = [
            'readback_verified' => true,
            'run_readback' => [
                'readback_verified' => true,
                'sync_task_id' => $scope['task_id'],
                'data_source_id' => $scope['data_source_id'],
                'system_hotel_id' => $scope['hotel_id'],
                'platform' => 'meituan',
                'target_date' => $scope['target_date'],
                'data_period' => $scope['data_period'],
                'row_ids' => [$scope['row_id']],
                'p0_status' => 'ready',
                'field_fact_status' => 'ready',
                'page_field_fact_status' => 'ready',
                'platform_hotel_identifier_status' => 'ready',
                'required_traffic_metric_keys' => $required,
                'complete_traffic_metric_keys' => $required,
                'missing_traffic_metric_keys' => [],
            ],
            'canonical_history_promotion' => $receipt,
        ];
        return [
            'id' => $scope['task_id'],
            'tenant_id' => $scope['tenant_id'],
            'system_hotel_id' => $scope['hotel_id'],
            'data_source_id' => $scope['data_source_id'],
            'platform' => 'meituan',
            'data_type' => 'business',
            'status' => 'success',
            'stats_json' => json_encode(
                $stats,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function validTask(?array $row = null): array
    {
        $row ??= $this->row;
        $hasNonzero = $this->rowHasNonzeroTrafficMetric($row);
        $receipt = [
            'version' => 'ota_canonical_history_promotion.v3',
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'data_source_id' => 25,
            'sync_task_id' => 3058,
            'row_ids' => [81878],
            'collection_anchor_hash' => str_repeat('a', 64),
            'verifier_report_hash' => str_repeat('b', 64),
            'authoritative_fact_digest' => $this->authoritativeFactDigest($row),
            'platform_hotel_identity_digest' => $this->platformHotelIdentityDigest(
                $row,
                $this->scope
            ),
            'nonzero_required_metric_rows' => $hasNonzero ? 1 : 0,
            'explicit_zero_confirmed_rows' => $hasNonzero ? 0 : 1,
            'observed_traffic_metric_provenance_status' => 'ready',
            'synthetic_normalization_provenance_missing_rows' => 0,
            'verified_at' => '2026-08-09 06:35:32',
            'sensitive_values_exposed' => false,
        ];
        $receipt['content_digest'] = $this->digest($receipt);
        $required = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $stats = [
            'readback_verified' => true,
            'run_readback' => [
                'readback_verified' => true,
                'sync_task_id' => 3058,
                'data_source_id' => 25,
                'system_hotel_id' => 80,
                'platform' => 'ctrip',
                'target_date' => '2026-08-09',
                'data_period' => 'realtime_snapshot',
                'row_ids' => [81871, 81878],
                'p0_status' => 'ready',
                'field_fact_status' => 'ready',
                'page_field_fact_status' => 'ready',
                'platform_hotel_identifier_status' => 'ready',
                'required_traffic_metric_keys' => $required,
                'complete_traffic_metric_keys' => $required,
                'missing_traffic_metric_keys' => [],
            ],
            'canonical_history_promotion' => $receipt,
        ];
        return [
            'id' => 3058,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'status' => 'success',
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string,mixed> */
    private function validMultiRowTask(array $row): array
    {
        $task = $this->validTask($row);
        $stats = json_decode((string)$task['stats_json'], true, 512, JSON_THROW_ON_ERROR);
        $receipt = $stats['canonical_history_promotion'];
        $receipt['row_ids'] = [81878, 81914];
        $receipt['authoritative_fact_digest'] = str_repeat('c', 64);
        $receipt['authoritative_row_fact_digests'] = [
            81878 => $this->authoritativeFactDigest($row),
            81914 => str_repeat('d', 64),
        ];
        $receipt['platform_hotel_identity_digest'] = str_repeat('e', 64);
        $receipt['authoritative_row_platform_hotel_identity_digests'] = [
            81878 => $this->platformHotelIdentityDigest($row, $this->scope),
            81914 => str_repeat('f', 64),
        ];
        $required = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $metricValues = [];
        foreach ($required as $metric) {
            $metricValues[$metric] = sprintf('%.8F', (float)$row[$metric]);
        }
        ksort($metricValues, SORT_STRING);
        $operationMetricDigest = $this->digest([
            'required_metric_keys' => $required,
            'metric_values' => $metricValues,
            'value_status' => 'explicit_zero',
        ]);
        $selection = [
            'version' => 'ota_operation_row_selection.v1',
            'status' => 'ready',
            'policy' => 'singleton_or_equivalent_required_metrics_min_row_id.v1',
            'platform' => 'ctrip',
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 3058,
            'target_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'candidate_row_ids' => [81878, 81914],
            'selected_row_id' => 81878,
            'row_metric_digests' => [
                81878 => $operationMetricDigest,
                81914 => $operationMetricDigest,
            ],
        ];
        $selection['selection_digest'] = $this->digest($selection);
        $receipt['operation_row_selection_version'] = $selection['version'];
        $receipt['operation_row_selection_status'] = $selection['status'];
        $receipt['operation_row_selection_policy'] = $selection['policy'];
        $receipt['operation_row_candidate_ids'] = $selection['candidate_row_ids'];
        $receipt['selected_operation_row_id'] = $selection['selected_row_id'];
        $receipt['operation_row_metric_digests'] = $selection['row_metric_digests'];
        $receipt['operation_row_selection_digest'] = $selection['selection_digest'];
        $receipt['nonzero_required_metric_rows'] = 0;
        $receipt['explicit_zero_confirmed_rows'] = 2;
        $receipt['content_digest'] = $this->digest($receipt);
        $stats['run_readback']['row_ids'] = [81871, 81878, 81914];
        $stats['canonical_history_promotion'] = $receipt;
        $task['stats_json'] = json_encode(
            $stats,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return $task;
    }

    private function service(?array $row = null, ?array $task = null): CanonicalOtaInvestigationDraftService
    {
        $row ??= $this->row;
        $task ??= $this->task;
        return new CanonicalOtaInvestigationDraftService(
            static fn(array $scope): ?array => $row,
            static fn(array $scope): ?array => $task,
            $this->storageRoot,
            fn(array $currentRow, array $currentScope): string => $this->platformHotelIdentityDigest(
                $currentRow,
                $currentScope
            )
        );
    }

    /** @param array<string,mixed> $row */
    private function authoritativeFactDigest(array $row): string
    {
        $platform = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? '')));
        $required = $platform === 'meituan'
            ? ['list_exposure', 'detail_exposure', 'flow_rate']
            : [
                'list_exposure',
                'detail_exposure',
                'flow_rate',
                'order_filling_num',
                'order_submit_num',
            ];
        $metrics = [];
        foreach ($required as $metric) {
            $metrics[$metric] = sprintf('%.8F', (float)$row[$metric]);
        }
        sort($required, SORT_STRING);
        ksort($metrics, SORT_STRING);
        return $this->digest([
            'required_metric_keys' => $required,
            'rows' => [[
                'id' => (int)$row['id'],
                'source_trace_digest' => hash('sha256', (string)$row['source_trace_id']),
                'raw_data_digest' => hash('sha256', trim((string)$row['raw_data'])),
                'metric_values' => $metrics,
                'observed_traffic_metric_keys' => $required,
                'value_status' => $this->rowHasNonzeroTrafficMetric($row) ? 'nonzero' : 'explicit_zero',
            ]],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function rowHasNonzeroTrafficMetric(array $row): bool
    {
        foreach ([
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ] as $metric) {
            if (abs((float)($row[$metric] ?? 0)) > 0.000001) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function nonzeroRow(): array
    {
        $row = $this->row;
        $values = [
            'list_exposure' => 1000,
            'detail_exposure' => 125,
            'flow_rate' => '0.125',
            'order_filling_num' => 12,
            'order_submit_num' => 5,
        ];
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($values as $metric => $value) {
            $row[$metric] = $value;
            $raw['row'][$metric] = $value;
        }
        $row['raw_data'] = json_encode(
            $raw,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return $row;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $scope */
    private function platformHotelIdentityDigest(array $row, array $scope): string
    {
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $platform = strtolower(trim((string)($scope['platform'] ?? '')));
        $identifier = $platform === 'meituan'
            ? trim((string)($raw['row']['poiId'] ?? ''))
            : trim((string)($raw['row']['hotelId'] ?? ''));
        if ($identifier === '') {
            return '';
        }
        $identifierHash = hash('sha256', $platform . "\0" . $identifier);
        return $this->digest([
            'authority_source_ids' => [(int)$scope['data_source_id']],
            'expected_identifier_digest' => hash('sha256', $identifierHash),
            'profile_scope_digest' => hash(
                'sha256',
                (int)$scope['tenant_id'] . ':' . (int)$scope['hotel_id']
            ),
            'rows' => [[
                'id' => (int)$row['id'],
                'identifier_match_digest' => hash(
                    'sha256',
                    $identifierHash . "\0" . $identifierHash
                ),
            ]],
        ]);
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function normalizedPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    private function removeTempBase(): void
    {
        if (!is_dir($this->tempBase)) {
            return;
        }
        $expectedPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'suxi_canonical_ota_draft_test_';
        if (!str_starts_with($this->normalizedPath($this->tempBase), $this->normalizedPath($expectedPrefix))) {
            throw new RuntimeException('Refusing to remove a non-test directory.');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempBase, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    if (!@rmdir($item->getPathname())) {
                        unlink($item->getPathname());
                    }
                } else {
                    unlink($item->getPathname());
                }
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->tempBase);
    }
}
