<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use app\service\OtaRevenueMetricService;
use PHPUnit\Framework\TestCase;

final class RevenueAiPricingMetricEvidenceGateTest extends TestCase
{
    public function testUnverifiedNumericFactsCannotMarkOtaPricingInputsReady(): void
    {
        $overview = $this->overview($this->dataset());
        self::assertNotSame('verified', $overview['metrics']['ota_room_revenue']['status']);
        self::assertNotSame('verified', $overview['metrics']['ota_room_nights']['status']);
        self::assertEquals(100, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('blocked', $this->gate($overview)['status']);
        self::assertNotEmpty($this->gate($overview)['reason']);
    }

    public function testBareCanonicalReadbackFlagCannotReplaceMetricEvidence(): void
    {
        $overview = $this->overview([], [
            'all_three_sources_readback_verified' => true,
            'facts' => ['ota_channel' => ['combined' => ['revenue' => 100, 'room_nights' => 1]]],
        ]);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('blocked', $this->gate($overview)['status']);
    }

    public function testOldVerifiedMetricCannotBeUsedAsCurrentOtaPricingEvidence(): void
    {
        $overview = $this->overview([], [
            'all_three_sources_readback_verified' => true,
            'facts' => ['ota_channel' => ['combined' => ['revenue' => 100, 'room_nights' => 1]]],
            'analysis_metrics' => ['ota_room_revenue' => [
                'value' => 100, 'status' => 'ok', 'scope' => 'ota_channel', 'date_basis' => 'data_date',
                'source_channels' => ['ctrip'],
                'truth' => ['status' => 'verified', 'hotels' => [['system_hotel_id' => 80]],
                    'platforms' => ['ctrip'], 'date_range' => ['start' => '2026-07-27', 'end' => '2026-07-27']],
            ]],
        ]);
        self::assertSame('metric_scope_mismatch', $overview['metrics']['ota_room_revenue']['reason']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('blocked', $this->gate($overview)['status']);
    }

    public function testEmptyDatasetWithoutCanonicalEvidenceRemainsBlocked(): void
    {
        $overview = $this->overview([]);
        self::assertSame('blocked', $this->gate($overview)['status']);
        self::assertSame('online_daily_data_empty', $this->gate($overview)['reason']);
    }

    public function testVerifiedSameScopeIncomeAndNightsRemainReady(): void
    {
        $dataset = $this->verifiedDataset();
        $overview = $this->overview($dataset, [], ['ctrip' => $dataset]);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
        self::assertSame('ok', $overview['metrics']['ota_room_nights']['status']);
        self::assertSame('ok', $this->gate($overview)['status']);
    }

    public function testVerifiedZeroRevenueWithPositiveNightsRemainsKnownAndReady(): void
    {
        $dataset = $this->verifiedDataset();
        foreach (['room_revenue', 'revenue', 'gross_revenue', 'net_revenue'] as $field) {
            $dataset['fact_ota_daily'][0][$field] = 0;
        }
        $overview = $this->overview($dataset, [], ['ctrip' => $dataset]);
        self::assertSame(0.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
        self::assertSame('ok', $this->gate($overview)['status']);
    }

    public function testCanonicalMetricsWithActualSameScopeProofRemainReadyWithoutTheGlobalFlag(): void
    {
        $dataset = $this->verifiedDataset();
        $layer = $this->canonicalLayer($dataset);
        $layer['all_three_sources_readback_verified'] = false;
        $overview = $this->overview($dataset, $layer, ['ctrip' => $dataset]);
        self::assertSame('verified', $overview['metrics']['ota_room_revenue']['truth']['status']);
        self::assertSame('ok', $this->gate($overview)['status']);
    }

    public function testCanonicalProofFromAnotherHotelOrRequestedChannelCannotOverrideScope(): void
    {
        foreach (['hotel', 'platform'] as $mismatch) {
            $dataset = $this->verifiedDataset();
            $layer = $this->canonicalLayer($dataset);
            foreach ($layer['analysis_metrics'] as &$metric) {
                if ($mismatch === 'hotel') {
                    $metric['truth']['hotels'][0]['system_hotel_id'] = 81;
                } else {
                    $metric['source_channels'] = ['meituan'];
                    $metric['truth']['platforms'] = ['meituan'];
                }
            }
            unset($metric);
            $overview = $this->overview($dataset, $layer, ['ctrip' => $dataset]);
            self::assertSame('blocked', $this->gate($overview)['status'], $mismatch);
            self::assertSame('metric_scope_mismatch', $this->gate($overview)['reason'], $mismatch);
        }
    }

    public function testVerifiedLabelWithoutFullReadbackCannotMarkCanonicalInputReady(): void
    {
        $dataset = $this->verifiedDataset();
        foreach (['missing', 'none', 'partial'] as $readbackCase) {
            $layer = $this->canonicalLayer($dataset);
            foreach ($layer['analysis_metrics'] as &$metric) {
                if ($readbackCase === 'missing') {
                    unset($metric['truth']['persistence']);
                } else {
                    $metric['truth']['persistence']['record_count'] = 2;
                    $metric['truth']['persistence']['stored_count'] = 2;
                    $metric['truth']['persistence']['readback_verified_count'] = $readbackCase === 'none' ? 0 : 1;
                }
            }
            unset($metric);
            $overview = $this->overview($dataset, $layer, ['ctrip' => $dataset]);
            self::assertSame('blocked', $this->gate($overview)['status'], $readbackCase);
            self::assertNotEmpty($this->gate($overview)['reason']);
        }
    }

    public function testPartialOrCollectionFailedMetricEvidenceRemainsBlocked(): void
    {
        foreach (['partial', 'collection_failed'] as $status) {
            $dataset = $this->verifiedDataset();
            $layer = $this->canonicalLayer($dataset);
            $layer['analysis_metrics']['ota_room_revenue']['truth']['status'] = $status;
            $overview = $this->overview($dataset, $layer, ['ctrip' => $dataset]);
            self::assertSame($status, $overview['metrics']['ota_room_revenue']['status']);
            self::assertSame('blocked', $this->gate($overview)['status']);
        }
    }

    public function testWholeHotelOrOtherDateBasisCannotReplaceCurrentOtaInputs(): void
    {
        foreach (['scope' => 'whole_hotel', 'date_basis' => 'stay_date'] as $field => $value) {
            $dataset = $this->verifiedDataset();
            $layer = $this->canonicalLayer($dataset);
            $layer['analysis_metrics']['ota_room_revenue'][$field] = $value;
            $overview = $this->overview($dataset, $layer, ['ctrip' => $dataset]);
            self::assertSame('blocked', $this->gate($overview)['status']);
            self::assertSame('metric_scope_mismatch', $this->gate($overview)['reason']);
        }
    }

    public function testFailedReadCannotMakeVerifiedRemainingFactsLookReady(): void
    {
        $dataset = $this->verifiedDataset();
        $dataset['status'] = 'failed';
        $overview = $this->overview($dataset, [], ['ctrip' => $dataset]);
        self::assertSame('failed', $overview['data_status']);
        self::assertSame('blocked', $this->gate($overview)['status']);
    }

    public function testAChannelMissingVerifiedNightsCannotBeFilledByAnotherChannel(): void
    {
        $ctrip = $this->verifiedDataset();
        $meituan = $this->verifiedDataset('meituan');
        unset($meituan['fact_ota_daily'][0]['source_trace']);
        $overview = $this->overview([], [], ['ctrip' => $ctrip, 'meituan' => $meituan], ['ctrip', 'meituan']);
        self::assertNotSame('ok', $overview['metrics']['ota_room_nights']['status']);
        self::assertSame('blocked', $this->gate($overview)['status']);
    }

    public function testBothRequestedVerifiedChannelsRemainReady(): void
    {
        $overview = $this->overview([], [], ['ctrip' => $this->verifiedDataset(),
            'meituan' => $this->verifiedDataset('meituan')], ['ctrip', 'meituan']);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
        self::assertSame('ok', $overview['metrics']['ota_room_nights']['status']);
        self::assertSame(200.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(2.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertSame('ok', $this->gate($overview)['status']);
    }

    public function testMissingRevenueOrNightsAndZeroNightsRemainBlocked(): void
    {
        foreach (['revenue', 'nights', 'zero_nights'] as $case) {
            $dataset = $this->verifiedDataset();
            if ($case === 'revenue') {
                foreach (['room_revenue', 'revenue', 'gross_revenue', 'net_revenue'] as $field) {
                    $dataset['fact_ota_daily'][0][$field] = null;
                }
            } else {
                $dataset['fact_ota_daily'][0]['room_nights'] = $case === 'nights' ? null : 0;
            }
            $overview = $this->overview($dataset, [], ['ctrip' => $dataset]);
            $gate = $this->gate($overview);
            self::assertSame('blocked', $gate['status'], $case);
            self::assertSame($case === 'zero_nights' ? 'ota_room_nights_zero' : 'ota_revenue_metrics_missing', $gate['reason']);
            self::assertSame('online-data', $gate['target_page']);
            self::assertSame('data-health', $gate['target_tab']);
            self::assertNotEmpty($gate['next_action']);
        }
    }

    public function testVerifiedLabelWithoutSourceTraceOrCollectionTimeCannotReplaceEvidence(): void
    {
        foreach (['trace', 'collection_time', 'saved', 'readback_flag'] as $case) {
            $dataset = $this->verifiedDataset();
            $layer = $this->canonicalLayer($dataset);
            foreach ($layer['analysis_metrics'] as &$metric) {
                if ($case === 'trace') {
                    $metric['truth']['source']['trace_ids'] = [];
                    $metric['truth']['source']['methods'] = [];
                    $metric['truth']['source_methods'] = [];
                } elseif ($case === 'collection_time') {
                    $metric['truth']['collected_at_range'] = [];
                } elseif ($case === 'saved') {
                    $metric['truth']['persistence']['stored'] = false;
                } else {
                    $metric['truth']['persistence']['readback_verified'] = false;
                }
            }
            unset($metric);
            $overview = $this->overview($dataset, $layer, ['ctrip' => $dataset]);
            self::assertSame('blocked', $this->gate($overview)['status'], $case);
            self::assertNotEmpty($this->gate($overview)['reason']);
        }
    }

    private function gate(array $overview): array
    {
        $gates = array_values(array_filter($overview['pricing_readiness']['gates'],
            static fn(array $row): bool => $row['key'] === 'ota_metrics'));
        self::assertCount(1, $gates);
        return $gates[0];
    }

    private function overview(array $dataset, array $revenueFactLayer = [], array $channels = [], array $enabled = ['ctrip']): array
    {
        return (new RevenueAiOverviewService())->buildOverviewFromDataset($dataset, $channels,
            ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-07-28 10:00:00']],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => $enabled,
                'revenue_fact_layer' => $revenueFactLayer]);
    }

    private function dataset(): array
    {
        return ['status' => 'ready', 'fact_ota_daily' => [[
            'date_key' => '2026-07-28', 'hotel_key' => 'system:80', 'platform_key' => 'ctrip',
            'data_type' => 'business', 'metric_scope' => 'ota_channel',
            'calculation_basis' => 'ota_daily_standard_fact', 'room_revenue' => 100,
            'revenue' => 100, 'gross_revenue' => 100, 'net_revenue' => 100,
            'room_nights' => 1, 'available_room_nights' => 10, 'order_count' => 1,
        ]], 'data_quality' => ['input_rows' => 1, 'accepted_rows' => 1, 'rejected_rows' => []]];
    }

    private function verifiedDataset(string $platform = 'ctrip'): array
    {
        $dataset = $this->dataset();
        $dataset['fact_ota_daily'][0]['platform_key'] = $platform;
        $dataset['fact_ota_daily'][0]['source_trace'] = [
            'row_id' => 'synthetic-' . $platform . '-1', 'source_trace_id' => 'synthetic:' . $platform . ':2026-07-28:1',
            'hotel_key' => 'system:80', 'system_hotel_id' => 80, 'platform' => $platform,
            'data_type' => 'business', 'date_key' => '2026-07-28', 'stored' => true,
            'readback_verified' => true, 'saved_success' => true, 'failure_reasons' => [],
            'collected_at' => '2026-07-28 08:00:00', 'updated_at' => '2026-07-28 08:00:00',
        ];
        return $dataset;
    }

    private function canonicalLayer(array $dataset): array
    {
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $metrics = [];
        foreach (['ota_room_revenue' => ['room_revenue', 'CNY'], 'ota_room_nights' => ['room_nights', 'room_nights']] as $key => [$field, $unit]) {
            $metrics[$key] = ['value' => $summary['totals'][$field], 'unit' => $unit, 'status' => 'ok',
                'scope' => 'ota_channel', 'date_basis' => 'data_date', 'source_channels' => ['ctrip'],
                'truth' => $summary['metric_trust']['totals.' . $field]['truth']];
        }
        return ['all_three_sources_readback_verified' => true,
            'facts' => ['ota_channel' => ['combined' => ['revenue' => 100, 'room_nights' => 1]]],
            'analysis_metrics' => $metrics];
    }
}
