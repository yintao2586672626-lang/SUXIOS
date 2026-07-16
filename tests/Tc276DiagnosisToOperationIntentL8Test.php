<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Tc276DiagnosisToOperationIntentL8Test extends TestCase
{
    private const HOTEL_ID = 7;
    private const CREATOR_ID = 42;
    private const TARGET_DATE = '2026-07-15';
    private const STALE_DATE = '2026-07-01';

    /**
     * Service-boundary evidence only. Restricted variants exercise the real
     * hotel-scope exception, but do not claim an HTTP response or request_id.
     * Stale/failed evidence can legitimately create a pending data-collection
     * remediation intent; the test requires the gap to remain explicit and
     * never treats that intent as approved or executed.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc276BuildsTruthfulOperationIntentFromDiagnosis(
        string $caseId,
        array $factors
    ): void {
        $input = $this->inputForFactors($factors);
        $permittedHotelIds = $factors['actor_scope'] === 'authorized' ? [self::HOTEL_ID] : [8];
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);

        if ($factors['actor_scope'] === 'restricted') {
            try {
                (new OperationManagementService())->buildExecutionIntentPayload(
                    $permittedHotelIds,
                    self::HOTEL_ID,
                    $input,
                    self::CREATOR_ID
                );
                self::fail($message . ' restricted actor unexpectedly built an intent');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('hotel_id is not permitted', $exception->getMessage(), $message);
            }

            $this->assertInputRepresentsFactors($input, $factors, $message);
            return;
        }

        $payload = (new OperationManagementService())->buildExecutionIntentPayload(
            $permittedHotelIds,
            self::HOTEL_ID,
            $input,
            self::CREATOR_ID
        );

        self::assertSame(self::HOTEL_ID, $payload['hotel_id'], $message);
        self::assertSame(self::CREATOR_ID, $payload['created_by'], $message);
        self::assertSame('ota_diagnosis', $payload['source_module'], $message);
        self::assertSame('ctrip', $payload['platform'], $message);
        self::assertSame('data_collection', $payload['object_type'], $message);
        self::assertSame('collect_same_period_ota_data', $payload['action_type'], $message);
        self::assertSame($this->factorDate($factors), $payload['date_start'], $message);
        self::assertSame($this->factorDate($factors), $payload['date_end'], $message);
        self::assertNotContains($payload['status'], ['approved', 'executing', 'executed'], $message);

        if ($factors['data_completeness'] === 'complete') {
            self::assertSame('pending_approval', $payload['status'], $message);
            self::assertSame('', $payload['blocked_reason'], $message);
            self::assertSame('same_day_ota_channel', $payload['target_value']['collection_scope'], $message);
            self::assertSame($this->factorDate($factors), $payload['target_value']['target_date'], $message);
            self::assertSame('ota_data_readiness', $payload['expected_metric'], $message);
            self::assertSame([$caseId . ':diagnosis'], $payload['evidence']['evidence_refs'], $message);
            self::assertSame($caseId . ' OTA diagnosis', $payload['evidence']['diagnosis_summary'], $message);
        } else {
            self::assertSame('blocked', $payload['status'], $message);
            self::assertStringContainsString('target_value missing', $payload['blocked_reason'], $message);
            self::assertSame([], $payload['target_value'], $message);
            self::assertSame('', $payload['expected_metric'], $message);
            self::assertArrayNotHasKey('evidence_refs', $payload['evidence'], $message);
            self::assertArrayNotHasKey('diagnosis_summary', $payload['evidence'], $message);
        }

        $gapCodes = array_column($payload['evidence']['data_gaps'] ?? [], 'code');
        if ($factors['freshness'] === 'stale') {
            self::assertContains('source_evidence_stale', $gapCodes, $message);
            self::assertSame(self::STALE_DATE, $payload['evidence']['source_data_date'], $message);
        } else {
            self::assertNotContains('source_evidence_stale', $gapCodes, $message);
            self::assertSame(self::TARGET_DATE, $payload['evidence']['source_data_date'], $message);
        }

        if ($factors['upstream_state'] === 'failure') {
            self::assertSame('failed', $payload['evidence']['action_item_status'], $message);
            self::assertContains('upstream_diagnosis_failed', $gapCodes, $message);
        } else {
            self::assertSame('ready', $payload['evidence']['action_item_status'], $message);
            self::assertNotContains('upstream_diagnosis_failed', $gapCodes, $message);
        }
    }

    /**
     * @return array<string, array{0:string,1:array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2201 authorized complete fresh success' => ['DX-2201', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-2202 authorized complete stale failure' => ['DX-2202', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-2203 authorized missing fresh failure' => ['DX-2203', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-2204 authorized missing stale success' => ['DX-2204', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-2205 restricted complete fresh failure' => ['DX-2205', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-2206 restricted complete stale success' => ['DX-2206', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-2207 restricted missing fresh success' => ['DX-2207', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-2208 restricted missing stale failure' => ['DX-2208', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    /** @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors */
    private function inputForFactors(array $factors): array
    {
        $date = $this->factorDate($factors);
        $gaps = [];
        if ($factors['freshness'] === 'stale') {
            $gaps[] = ['code' => 'source_evidence_stale', 'message' => 'Diagnosis evidence is older than the target date.'];
        }
        if ($factors['upstream_state'] === 'failure') {
            $gaps[] = ['code' => 'upstream_diagnosis_failed', 'message' => 'The upstream diagnosis did not complete successfully.'];
        }

        $input = [
            'hotel_id' => self::HOTEL_ID,
            'platform' => 'ctrip',
            'source_module' => 'ota_diagnosis',
            'source_record_id' => 276,
            'object_type' => 'data_collection',
            'action_type' => 'collect_same_period_ota_data',
            'date_start' => $date,
            'date_end' => $date,
            'data_gaps' => $gaps,
            'source_policy' => 'database_only_no_synthetic_conclusion',
            'action_item_id' => 'TC-276',
            'action_item_status' => $factors['upstream_state'] === 'failure' ? 'failed' : 'ready',
            'evidence' => ['source_data_date' => $date],
        ];

        if ($factors['data_completeness'] === 'complete') {
            $input['target_value'] = [
                'collection_scope' => 'same_day_ota_channel',
                'target_date' => $date,
                'target_metric' => 'ota_data_readiness',
            ];
            $input['expected_metric'] = 'ota_data_readiness';
            $input['evidence_refs'] = [$this->currentCaseId($factors) . ':diagnosis'];
            $input['diagnosis_summary'] = $this->currentCaseId($factors) . ' OTA diagnosis';
        }

        return $input;
    }

    /**
     * The provider-to-input mapping is deterministic, so the case id can be
     * reconstructed from the unique L8 factor row without mutable test state.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function currentCaseId(array $factors): string
    {
        foreach (self::l8VariantProvider() as [$caseId, $candidate]) {
            if ($candidate === $factors) {
                return $caseId;
            }
        }
        throw new InvalidArgumentException('Unknown TC-276 L8 factor row');
    }

    /** @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors */
    private function factorDate(array $factors): string
    {
        return $factors['freshness'] === 'stale' ? self::STALE_DATE : self::TARGET_DATE;
    }

    /**
     * @param array<string,mixed> $input
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertInputRepresentsFactors(array $input, array $factors, string $message): void
    {
        self::assertSame($this->factorDate($factors), $input['date_start'], $message);
        self::assertSame(
            $factors['upstream_state'] === 'failure' ? 'failed' : 'ready',
            $input['action_item_status'],
            $message
        );
        self::assertSame(
            $factors['data_completeness'] === 'complete',
            array_key_exists('target_value', $input),
            $message
        );
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
