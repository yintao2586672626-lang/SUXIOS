<?php
declare(strict_types=1);

namespace tests;

use app\service\OtaDiagnosisPersistenceProofService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OtaDiagnosisPersistenceProofServiceTest extends TestCase
{
    public function testProvesOnlyExactPersistedReadbackAndReturnsRedactedEvidence(): void
    {
        $calls = [];
        $service = new OtaDiagnosisPersistenceProofService(
            function (int $recordId, int $systemHotelId) use (&$calls): array {
                $calls[] = [$recordId, $systemHotelId];
                return $this->validRow($recordId, $systemHotelId);
            }
        );

        $result = $service->verify($this->diagnosis(), $this->scope());

        self::assertTrue($result['proved']);
        self::assertSame('proved', $result['status']);
        self::assertSame(['diagnosis_persistence_readback_proved'], $result['reason_codes']);
        self::assertSame([[210, 80]], $calls);
        self::assertSame('agent_logs', $result['evidence']['source_table']);
        self::assertSame(210, $result['evidence']['record_id']);
        self::assertSame(80, $result['evidence']['system_hotel_id']);
        self::assertTrue($result['evidence']['checks']['snapshot_readback_matched']);
        self::assertTrue($result['evidence']['checks']['diagnosis_content_matched']);
        self::assertTrue($result['evidence']['checks']['summary_matched']);
        self::assertTrue($result['evidence']['checks']['evidence_sources_matched']);
        self::assertTrue($result['evidence']['checks']['data_gaps_matched']);
        self::assertTrue($result['evidence']['checks']['action_items_matched']);
        self::assertTrue($result['evidence']['checks']['decision_status_matched']);
        self::assertArrayNotHasKey('context_data', $result['evidence']);
        self::assertArrayNotHasKey('diagnosis_result', $result['evidence']);
        self::assertStringNotContainsString('sensitive-model-response', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('persisted-summary-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testExternalSavedFlagsCannotProveWithoutDatabaseRow(): void
    {
        $result = (new OtaDiagnosisPersistenceProofService(static fn(): ?array => null))
            ->verify($this->diagnosis(), $this->scope());

        self::assertFalse($result['proved']);
        self::assertSame('unverified', $result['status']);
        self::assertSame(['agent_log_not_found'], $result['reason_codes']);
        self::assertFalse($result['evidence']['checks']['record_found']);
    }

    public function testExternalSavedAndReadbackFlagsMustBothBeStrictTrue(): void
    {
        $loaderCalled = false;
        $service = new OtaDiagnosisPersistenceProofService(
            static function () use (&$loaderCalled): array {
                $loaderCalled = true;
                return [];
            }
        );
        $diagnosis = $this->diagnosis();
        $diagnosis['saved_record']['saved'] = 1;
        $diagnosis['saved_record']['readback_verified'] = false;

        $result = $service->verify($diagnosis, $this->scope());

        self::assertFalse($result['proved']);
        self::assertContains('diagnosis_external_save_unverified', $result['reason_codes']);
        self::assertContains('diagnosis_external_readback_unverified', $result['reason_codes']);
        self::assertFalse($loaderCalled);
    }

    /** @param array<string, mixed> $scope */
    #[DataProvider('invalidScopeProvider')]
    public function testMissingOrInvalidScopeFailsClosedBeforeRead(array $scope, string $reasonCode): void
    {
        $loaderCalled = false;
        $service = new OtaDiagnosisPersistenceProofService(
            static function () use (&$loaderCalled): array {
                $loaderCalled = true;
                return [];
            }
        );

        $result = $service->verify($this->diagnosis(), $scope);

        self::assertFalse($result['proved']);
        self::assertContains($reasonCode, $result['reason_codes']);
        self::assertFalse($loaderCalled);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidScopeProvider(): iterable
    {
        yield 'hotel missing' => [[
            'platform' => 'ctrip',
            'requested_date_range' => ['start_date' => '2026-07-18', 'end_date' => '2026-07-18'],
        ], 'system_hotel_scope_missing'];
        yield 'platform unsupported' => [[
            'system_hotel_id' => 80,
            'platform' => 'other',
            'requested_date_range' => ['start_date' => '2026-07-18', 'end_date' => '2026-07-18'],
        ], 'platform_scope_missing_or_invalid'];
        yield 'date invalid' => [[
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'requested_date_range' => ['start_date' => '2026-07-19', 'end_date' => '2026-07-18'],
        ], 'requested_date_range_missing_or_invalid'];
    }

    public function testTargetDateIsNormalizedToRequestedSingleDayRange(): void
    {
        $service = new OtaDiagnosisPersistenceProofService(
            fn(int $recordId, int $systemHotelId): array => $this->validRow($recordId, $systemHotelId)
        );
        $scope = [
            'system_hotel_id' => 80,
            'platform' => 'CTRIP',
            'target_date' => '2026-07-18',
        ];

        $result = $service->verify($this->diagnosis(), $scope);

        self::assertTrue($result['proved']);
        self::assertSame(
            ['start_date' => '2026-07-18', 'end_date' => '2026-07-18'],
            $result['evidence']['requested_date_range']
        );
    }

    public function testReadExceptionFailsClosedWithoutLeakingExceptionOrContext(): void
    {
        $service = new OtaDiagnosisPersistenceProofService(
            static fn(): array => throw new RuntimeException('database password secret')
        );

        $result = $service->verify($this->diagnosis(), $this->scope());

        self::assertFalse($result['proved']);
        self::assertSame(['agent_logs_read_failed'], $result['reason_codes']);
        self::assertArrayNotHasKey('checks', $result['evidence']);
        self::assertStringNotContainsString('password', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('rowIdentityMismatchProvider')]
    public function testWrongRecordHotelOrActionFailsClosed(array $patch, string $reasonCode): void
    {
        $row = array_replace($this->validRow(), $patch);
        $result = (new OtaDiagnosisPersistenceProofService(static fn(): array => $row))
            ->verify($this->diagnosis(), $this->scope());

        self::assertFalse($result['proved']);
        self::assertContains($reasonCode, $result['reason_codes']);
        self::assertArrayNotHasKey('context_data', $result['evidence']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rowIdentityMismatchProvider(): iterable
    {
        yield 'record id' => [['id' => 211], 'agent_log_record_id_mismatch'];
        yield 'hotel' => [['hotel_id' => 81], 'agent_log_hotel_scope_mismatch'];
        yield 'action' => [['action' => 'another_action'], 'agent_log_action_mismatch'];
    }

    /** @param array<string, mixed> $contextPatch */
    #[DataProvider('contextContractMismatchProvider')]
    public function testContextContractAndScopeMustMatch(array $contextPatch, string $reasonCode): void
    {
        $row = $this->validRow();
        $row['context_data'] = array_replace_recursive($row['context_data'], $contextPatch);
        $result = (new OtaDiagnosisPersistenceProofService(static fn(): array => $row))
            ->verify($this->diagnosis(), $this->scope());

        self::assertFalse($result['proved']);
        self::assertContains($reasonCode, $result['reason_codes']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function contextContractMismatchProvider(): iterable
    {
        yield 'schema' => [['schema_version' => 2], 'agent_log_schema_mismatch'];
        yield 'record type' => [['record_type' => 'other'], 'agent_log_record_type_mismatch'];
        yield 'inactive' => [['record_status' => 'superseded'], 'agent_log_record_inactive'];
        yield 'platform' => [['platform' => 'meituan'], 'agent_log_platform_scope_mismatch'];
        yield 'requested dates' => [[
            'requested_date_range' => ['start_date' => '2026-07-17', 'end_date' => '2026-07-17'],
        ], 'agent_log_requested_date_range_mismatch'];
    }

    /** @param array<string, mixed> $savedRecordPatch */
    #[DataProvider('snapshotMismatchProvider')]
    public function testPersistedSnapshotMustContainMatchingVerifiedSavedRecord(array $savedRecordPatch, string $reasonCode): void
    {
        $row = $this->validRow();
        $row['context_data']['diagnosis_result']['saved_record'] = array_replace(
            $row['context_data']['diagnosis_result']['saved_record'],
            $savedRecordPatch
        );
        $result = (new OtaDiagnosisPersistenceProofService(static fn(): array => $row))
            ->verify($this->diagnosis(), $this->scope());

        self::assertFalse($result['proved']);
        self::assertContains($reasonCode, $result['reason_codes']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function snapshotMismatchProvider(): iterable
    {
        yield 'id mismatch' => [['id' => 211], 'diagnosis_snapshot_record_id_mismatch'];
        yield 'saved false' => [['saved' => false], 'diagnosis_snapshot_save_unverified'];
        yield 'readback false' => [['readback_verified' => false], 'diagnosis_snapshot_readback_unverified'];
    }

    public function testJsonContextIsAcceptedButMalformedJsonFailsClosed(): void
    {
        $validRow = $this->validRow();
        $validRow['context_data'] = json_encode($validRow['context_data'], JSON_THROW_ON_ERROR);
        $valid = (new OtaDiagnosisPersistenceProofService(static fn(): array => $validRow))
            ->verify($this->diagnosis(), $this->scope());
        self::assertTrue($valid['proved']);

        $invalidRow = $this->validRow();
        $invalidRow['context_data'] = '{not-json';
        $invalid = (new OtaDiagnosisPersistenceProofService(static fn(): array => $invalidRow))
            ->verify($this->diagnosis(), $this->scope());
        self::assertFalse($invalid['proved']);
        self::assertContains('agent_log_context_invalid', $invalid['reason_codes']);
    }

    /** @param list<string> $summaryPath */
    #[DataProvider('summaryAliasProvider')]
    public function testSummaryAliasesNormalizeToTheSamePersistedSummary(array $summaryPath): void
    {
        $diagnosis = $this->diagnosis();
        $summary = $diagnosis['summary'];
        unset($diagnosis['summary']);
        if ($summaryPath === ['diagnosis', 'summary']) {
            $diagnosis['diagnosis'] = ['summary' => $summary];
        } else {
            $diagnosis['core_conclusion'] = $summary;
        }

        $result = (new OtaDiagnosisPersistenceProofService(fn(): array => $this->validRow()))
            ->verify($diagnosis, $this->scope());

        self::assertTrue($result['proved']);
        self::assertTrue($result['evidence']['checks']['summary_matched']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function summaryAliasProvider(): iterable
    {
        yield 'diagnosis summary' => [["diagnosis", "summary"]];
        yield 'core conclusion' => [["core_conclusion"]];
    }

    #[DataProvider('contentMismatchProvider')]
    public function testReusedRecordIdCannotProveChangedDiagnosisContent(string $field, string $reasonCode): void
    {
        $diagnosis = $this->diagnosis();
        switch ($field) {
            case 'summary':
                $diagnosis['summary'] = 'replacement-summary-secret';
                break;
            case 'evidence_sources':
                $diagnosis['evidence_sources'][0]['ref'] = 'replacement-evidence-secret';
                break;
            case 'data_gaps':
                $diagnosis['data_gaps'][0]['code'] = 'replacement-gap-secret';
                break;
            case 'action_items':
                $diagnosis['action_items'] = array_reverse($diagnosis['action_items']);
                break;
            case 'decision_status':
                $diagnosis['decision_status'] = 'no_action';
                break;
        }

        self::assertSame(210, $diagnosis['saved_record']['id']);
        $result = (new OtaDiagnosisPersistenceProofService(fn(): array => $this->validRow()))
            ->verify($diagnosis, $this->scope());

        self::assertFalse($result['proved']);
        self::assertContains($reasonCode, $result['reason_codes']);
        self::assertTrue($result['evidence']['checks']['record_found']);
        self::assertFalse($result['evidence']['checks'][$field . '_matched']);
        self::assertFalse($result['evidence']['checks']['diagnosis_content_matched']);
        self::assertArrayNotHasKey('context_data', $result['evidence']);
        self::assertArrayNotHasKey('diagnosis_result', $result['evidence']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('replacement-', $encoded);
        self::assertStringNotContainsString('persisted-summary-secret', $encoded);
    }

    /** @return iterable<string, array{string, string}> */
    public static function contentMismatchProvider(): iterable
    {
        yield 'summary' => ['summary', 'diagnosis_summary_mismatch'];
        yield 'evidence sources' => ['evidence_sources', 'diagnosis_evidence_sources_mismatch'];
        yield 'data gaps' => ['data_gaps', 'diagnosis_data_gaps_mismatch'];
        yield 'action item list order' => ['action_items', 'diagnosis_action_items_mismatch'];
        yield 'decision status' => ['decision_status', 'diagnosis_decision_status_mismatch'];
    }

    #[DataProvider('contentFieldProvider')]
    public function testMissingExternalOrSnapshotContentFieldFailsClosed(string $field): void
    {
        $external = $this->diagnosis();
        $this->removeContentField($external, $field, false);
        $externalResult = (new OtaDiagnosisPersistenceProofService(fn(): array => $this->validRow()))
            ->verify($external, $this->scope());
        self::assertFalse($externalResult['proved']);
        self::assertContains(
            'diagnosis_external_' . $field . '_missing_or_invalid',
            $externalResult['reason_codes']
        );

        $row = $this->validRow();
        $this->removeContentField($row['context_data']['diagnosis_result'], $field, true);
        $snapshotResult = (new OtaDiagnosisPersistenceProofService(static fn(): array => $row))
            ->verify($this->diagnosis(), $this->scope());
        self::assertFalse($snapshotResult['proved']);
        self::assertContains(
            'diagnosis_snapshot_' . $field . '_missing_or_invalid',
            $snapshotResult['reason_codes']
        );
        self::assertFalse($snapshotResult['evidence']['checks']['diagnosis_content_matched']);
    }

    /** @return iterable<string, array{string}> */
    public static function contentFieldProvider(): iterable
    {
        yield 'summary' => ['summary'];
        yield 'evidence sources' => ['evidence_sources'];
        yield 'data gaps' => ['data_gaps'];
        yield 'action items' => ['action_items'];
        yield 'decision status' => ['decision_status'];
    }

    /** @return array<string, mixed> */
    private function diagnosis(): array
    {
        return [
            'summary' => 'persisted-summary-secret',
            'evidence_sources' => [
                [
                    'ref' => 'ota-metric-source-1',
                    'metadata' => ['z_key' => 2, 'a_key' => 1],
                ],
                ['ref' => 'ota-metric-source-2'],
            ],
            'data_gaps' => [
                [
                    'code' => 'traffic_gap',
                    'details' => ['severity' => 'high', 'metric' => 'flow_rate'],
                ],
            ],
            'action_items' => [
                [
                    'id' => 'action-1',
                    'action' => 'review OTA price gap',
                    'status' => 'ready',
                    'evidence_refs' => ['ota-metric-source-1'],
                ],
                [
                    'id' => 'action-2',
                    'action' => 'review OTA traffic gap',
                    'status' => 'ready',
                    'evidence_refs' => ['ota-metric-source-2'],
                ],
            ],
            'decision_status' => 'action_required',
            'saved_record' => [
                'id' => 210,
                'saved' => true,
                'readback_verified' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function scope(): array
    {
        return [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'requested_date_range' => [
                'start_date' => '2026-07-18',
                'end_date' => '2026-07-18',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function validRow(int $recordId = 210, int $systemHotelId = 80): array
    {
        return [
            'id' => $recordId,
            'hotel_id' => $systemHotelId,
            'action' => 'ota_diagnosis',
            'message' => 'must-not-be-returned',
            'context_data' => [
                'schema_version' => 1,
                'record_type' => 'ota_diagnosis',
                'record_status' => 'active',
                'platform' => 'ctrip',
                'requested_date_range' => [
                    'start_date' => '2026-07-18',
                    'end_date' => '2026-07-18',
                ],
                'diagnosis_result' => [
                    'diagnosis' => [
                        'summary' => 'persisted-summary-secret',
                    ],
                    'evidence_sources' => [
                        [
                            'metadata' => ['a_key' => 1, 'z_key' => 2],
                            'ref' => 'ota-metric-source-1',
                        ],
                        ['ref' => 'ota-metric-source-2'],
                    ],
                    'data_gaps' => [
                        [
                            'details' => ['metric' => 'flow_rate', 'severity' => 'high'],
                            'code' => 'traffic_gap',
                        ],
                    ],
                    'action_items' => [
                        [
                            'status' => 'ready',
                            'evidence_refs' => ['ota-metric-source-1'],
                            'action' => 'review OTA price gap',
                            'id' => 'action-1',
                        ],
                        [
                            'evidence_refs' => ['ota-metric-source-2'],
                            'id' => 'action-2',
                            'status' => 'ready',
                            'action' => 'review OTA traffic gap',
                        ],
                    ],
                    'decision_status' => 'action_required',
                    'saved_record' => [
                        'id' => $recordId,
                        'saved' => true,
                        'readback_verified' => true,
                    ],
                    'raw_response' => 'sensitive-model-response',
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $diagnosis */
    private function removeContentField(array &$diagnosis, string $field, bool $snapshot): void
    {
        if ($field === 'summary' && $snapshot) {
            unset($diagnosis['diagnosis']['summary']);
            return;
        }
        unset($diagnosis[$field]);
    }
}
