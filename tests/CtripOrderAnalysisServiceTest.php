<?php
declare(strict_types=1);

use app\service\CtripOrderAnalysisService;
use app\service\CtripOrderExportImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CtripOrderAnalysisServiceTest extends TestCase
{
    public function testV2AnalysisPreservesTotalsChannelsAndDistributionDenominators(): void
    {
        $analysis = (new CtripOrderAnalysisService())->analyzeRows(
            $this->verifiedV2Rows(),
            64,
            '2026-08-08',
            '2026-08-09'
        );

        self::assertSame('available_partial', $analysis['status']);
        self::assertSame('user_provided_unverified', $analysis['quality_status']);
        self::assertSame('verified', $analysis['persistence_readback_status']);
        self::assertSame('ctrip_order_aggregate_v2', $analysis['batch']['import_contract']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$analysis['batch']['dataset_hash']);
        self::assertSame('2026-08-08', $analysis['date_range']['from']);
        self::assertSame('2026-08-09', $analysis['date_range']['to']);

        $summary = $analysis['summary'];
        self::assertSame(5, $summary['gross_orders']);
        self::assertSame(3, $summary['active_orders']);
        self::assertSame(2, $summary['stayed_orders']);
        self::assertSame(1, $summary['cancelled_orders']);
        self::assertSame(1, $summary['unknown_status_orders']);
        self::assertNull($summary['cancel_rate']);
        self::assertSame(9.0, $summary['room_nights']);
        self::assertSame(1400.0, $summary['reference_bottom_price_total']);
        self::assertEqualsWithDelta(1400 / 9, (float)$summary['reference_bottom_price_adr'], 0.000001);
        self::assertSame(2.0, $summary['average_los']);
        self::assertEqualsWithDelta(1 / 3, (float)$summary['single_night_rate'], 0.000001);
        self::assertEqualsWithDelta(13 / 3, (float)$summary['average_booking_lead_days'], 0.000001);
        self::assertNull($summary['amount']);
        self::assertSame('reference_bottom_price_not_confirmed_revenue', $summary['amount_semantics']);
        self::assertSame('reference_bottom_price_not_confirmed_revenue', $analysis['amount_semantics']);

        self::assertCount(2, $analysis['channels']);
        foreach (['gross_orders', 'active_orders', 'cancelled_orders', 'unknown_status_orders', 'room_nights', 'reference_bottom_price_total'] as $metric) {
            self::assertEqualsWithDelta(
                (float)$summary[$metric],
                array_sum(array_map(static fn(array $channel): float => (float)$channel[$metric], $analysis['channels'])),
                0.000001,
                $metric . ' must equal the sum of the channel rows'
            );
        }
        foreach ($analysis['channels'] as $channel) {
            self::assertNull($channel['amount']);
            self::assertSame('reference_bottom_price_not_confirmed_revenue', $channel['amount_semantics']);
        }

        self::assertSame('available', $analysis['classification']['status']);
        self::assertSame(2, $analysis['classification']['stayed_orders']);
        self::assertSame(1, $analysis['classification']['active_not_stayed_orders']);
        self::assertSame(5, array_sum($analysis['classification']['status_family_counts']));

        self::assertSame('available', $analysis['distributions']['los']['status']);
        self::assertSame(3, $this->distributionOrderTotal($analysis['distributions']['los']['buckets']));
        self::assertSame('available', $analysis['distributions']['lead_time']['status']);
        self::assertSame(3, $this->distributionOrderTotal($analysis['distributions']['lead_time']['buckets']));

        self::assertSame('available', $analysis['room_types']['status']);
        self::assertSame(3, array_sum(array_map(
            static fn(array $roomType): int => (int)$roomType['active_orders'],
            $analysis['room_types']['rows']
        )));
        self::assertEqualsWithDelta(9.0, array_sum(array_map(
            static fn(array $roomType): float => (float)$roomType['room_nights'],
            $analysis['room_types']['rows']
        )), 0.000001);

        self::assertSame('evidence_missing', $analysis['exclusions']['status']);
        self::assertSame('unverified_not_applied', $analysis['exclusions']['policy_status']);
        self::assertContains('exclusion_receipt', array_column($analysis['missing_dimensions'], 'key'));
    }

    public function testV1RowsRemainPartialAndDoNotInventMissingDimensions(): void
    {
        $analysis = (new CtripOrderAnalysisService())->analyzeRows([
            $this->verifiedV1Row(),
        ], 64, '2026-08-08', '2026-08-08');

        self::assertSame('available_partial', $analysis['status']);
        self::assertSame('ctrip_order_aggregate_v1', $analysis['batch']['import_contract']);
        self::assertNull($analysis['batch']['dataset_hash']);
        self::assertSame(3, $analysis['summary']['gross_orders']);
        self::assertSame(2, $analysis['summary']['active_orders']);
        self::assertSame(1, $analysis['summary']['cancelled_orders']);
        self::assertSame(4.0, $analysis['summary']['room_nights']);
        self::assertSame(900.0, $analysis['summary']['reference_bottom_price_total']);
        self::assertSame(225.0, $analysis['summary']['reference_bottom_price_adr']);
        self::assertSame(2.0, $analysis['summary']['average_los']);
        self::assertSame(5.0, $analysis['summary']['average_booking_lead_days']);
        self::assertNull($analysis['summary']['stayed_orders']);
        self::assertNull($analysis['summary']['amount']);

        self::assertSame('evidence_missing', $analysis['classification']['status']);
        self::assertSame('evidence_missing', $analysis['distributions']['los']['status']);
        self::assertSame([], $analysis['distributions']['los']['buckets']);
        self::assertSame('evidence_missing', $analysis['distributions']['lead_time']['status']);
        self::assertSame([], $analysis['distributions']['lead_time']['buckets']);
        self::assertSame('evidence_missing', $analysis['room_types']['status']);
        self::assertSame([], $analysis['room_types']['rows']);
        self::assertSame('evidence_missing', $analysis['exclusions']['status']);

        self::assertEqualsCanonicalizing([
            'status_classification',
            'los_distribution',
            'lead_time_distribution',
            'room_type_metrics',
            'exclusion_receipt',
        ], array_column($analysis['missing_dimensions'], 'key'));
        self::assertSame('V1 聚合未保存逐状态分类回执。', $analysis['classification']['reason']);
        $missing = array_column($analysis['missing_dimensions'], null, 'key');
        self::assertSame('旧聚合仅保存平均连住与单晚占比', $missing['los_distribution']['reason']);
        self::assertSame('旧聚合仅保存平均提前天数', $missing['lead_time_distribution']['reason']);
        self::assertSame('旧聚合仅保存每日 Top5，无法恢复完整排名', $missing['room_type_metrics']['reason']);
        self::assertStringNotContainsString('自动补算', $missing['room_type_metrics']['next_action']);
    }

    #[DataProvider('missingV2ReceiptCases')]
    public function testMissingV2ReceiptUsesCurrentContractReasonAndReadbackAction(
        string $receiptKey,
        string $dimensionKey,
        array $resultPath,
        string $expectedReason
    ): void {
        $service = new CtripOrderAnalysisService();
        $rows = $this->verifiedV2Rows();
        $complete = $service->analyzeRows($rows, 64, '2026-08-08', '2026-08-09');
        foreach ($rows as &$row) {
            self::assertArrayHasKey($receiptKey, $row['raw_data']);
            unset($row['raw_data'][$receiptKey]);
        }
        unset($row);
        $before = $rows;
        $analysis = $service->analyzeRows($rows, 64, '2026-08-08', '2026-08-09');
        $missing = array_column($analysis['missing_dimensions'], null, 'key');

        self::assertSame($expectedReason, $missing[$dimensionKey]['reason']);
        self::assertSame(
            '重新导入同一门店的原始携程 XLS，并确认 V2 回执已保存且完成回读。',
            $missing[$dimensionKey]['next_action']
        );
        $evidence = $analysis;
        foreach ($resultPath as $key) {
            $evidence = $evidence[$key];
        }
        self::assertSame('evidence_missing', $evidence['status']);
        self::assertSame($expectedReason, $evidence['reason']);
        self::assertSame('ctrip_order_aggregate_v2', $analysis['batch']['import_contract']);
        self::assertSame($complete['batch']['dataset_hash'], $analysis['batch']['dataset_hash']);
        self::assertSame($complete['status'], $analysis['status']);
        self::assertSame($complete['quality_status'], $analysis['quality_status']);
        self::assertSame($complete['persistence_readback_status'], $analysis['persistence_readback_status']);
        foreach (['gross_orders', 'active_orders', 'cancelled_orders', 'unknown_status_orders', 'room_nights', 'reference_bottom_price_total', 'reference_bottom_price_adr', 'amount', 'amount_semantics'] as $metric) {
            self::assertSame($complete['summary'][$metric], $analysis['summary'][$metric], $metric);
        }
        self::assertSame($before, $rows, 'Missing-evidence explanations must not rewrite saved aggregates.');
    }

    public static function missingV2ReceiptCases(): array
    {
        return [
            'classification' => [
                'classification_receipt', 'status_classification', ['classification'],
                'V2 聚合缺少逐状态分类回执，已入住与未入住计数不可核验。',
            ],
            'los' => [
                'los_distribution', 'los_distribution', ['distributions', 'los'],
                'V2 聚合的连住或提前预订分布回执缺失或不完整，无法核验完整连住分布。',
            ],
            'lead time' => [
                'lead_time_distribution', 'lead_time_distribution', ['distributions', 'lead_time'],
                'V2 聚合的连住或提前预订分布回执缺失或不完整，无法核验完整提前预订分布。',
            ],
            'room types' => [
                'room_type_metrics', 'room_type_metrics', ['room_types'],
                'V2 聚合缺少完整房型回执，无法核验完整排名。',
            ],
        ];
    }

    public function testV2TruncatedRoomReceiptExplainsCurrentLimitWithoutPromisingReuploadRecovery(): void
    {
        $sourceRows = [];
        for ($index = 1; $index <= 101; $index++) {
            $sourceRows[] = [
                '酒店名称' => '匿名酒店（房型上限 fixture）',
                '订单号' => 'ANON-ROOM-LIMIT-' . $index,
                '订单状态' => '已入住',
                '入住日期' => '2026-08-08',
                '离店日期' => '2026-08-09',
                '预订时间' => '2026-08-07 09:00:00',
                '晚数' => '1', '房间数' => '1', '底价' => '100', '币种' => 'CNY',
                '房型名称' => '匿名房型-' . $index, '预订网站' => '携程',
            ];
        }
        $rows = (new CtripOrderExportImportService())->normalizeRows($sourceRows, [
            'system_hotel_id' => 64, 'test_fixture' => true,
        ]);
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['raw_data']['room_type_metrics_truncated']);
        self::assertCount(100, $rows[0]['raw_data']['room_type_metrics']);
        $rows[0]['_readback_verified'] = true;
        $before = $rows;
        $analysis = (new CtripOrderAnalysisService())->analyzeRows($rows, 64, '2026-08-08', '2026-08-08');
        $missing = array_column($analysis['missing_dimensions'], null, 'key');

        self::assertSame('V2 房型聚合已截断，当前保存回执不含全部房型。', $missing['room_type_metrics']['reason']);
        self::assertSame($missing['room_type_metrics']['reason'], $analysis['room_types']['reason']);
        self::assertSame(
            '核对原始文件与房型数量；当前导入每个日期和渠道最多保存 100 个房型，超出部分需扩展保存契约后再导入，同一文件重复上传无法补齐。',
            $missing['room_type_metrics']['next_action']
        );
        self::assertSame('evidence_missing', $analysis['room_types']['status']);
        self::assertSame([], $analysis['room_types']['rows']);
        self::assertSame(101, $analysis['summary']['active_orders']);
        self::assertSame(101.0, $analysis['summary']['room_nights']);
        self::assertSame(10100.0, $analysis['summary']['reference_bottom_price_total']);
        self::assertSame($before, $rows);
    }

    public function testContractlessLegacyReasonsDoNotClaimTheV1Contract(): void
    {
        $row = $this->verifiedV1Row();
        unset($row['raw_data']['import_contract']);
        $row['raw_data']['pii_policy'] = 'guest_name_and_raw_order_id_excluded';
        $row['raw_data']['gross_order_num'] = 3;
        $row['raw_data']['active_order_num'] = 2;
        $row['raw_data']['room_nights'] = 4.0;
        $before = $row;
        $analysis = (new CtripOrderAnalysisService())->analyzeRows([$row], 64, '2026-08-08', '2026-08-08');
        $missing = array_column($analysis['missing_dimensions'], null, 'key');

        self::assertNull($analysis['batch']['import_contract']);
        self::assertSame('ctrip_order_legacy_saved_aggregate', $analysis['batch']['read_adapter']);
        self::assertSame('旧版保存汇总未保存逐状态分类回执。', $analysis['classification']['reason']);
        self::assertSame('旧聚合仅保存平均连住与单晚占比', $missing['los_distribution']['reason']);
        self::assertSame('旧聚合仅保存平均提前天数', $missing['lead_time_distribution']['reason']);
        self::assertSame('旧聚合仅保存每日 Top5，无法恢复完整排名', $missing['room_type_metrics']['reason']);
        self::assertStringNotContainsString('自动补算', $missing['room_type_metrics']['next_action']);
        self::assertSame(2, $analysis['summary']['active_orders']);
        self::assertSame(4.0, $analysis['summary']['room_nights']);
        self::assertSame($before, $row);
    }

    public function testV2DatasetHashConflictIsIndeterminateInsteadOfMixingFacts(): void
    {
        $rows = $this->verifiedV2Rows();
        self::assertGreaterThan(1, count($rows));
        $rows[1]['raw_data']['dataset_receipt']['dataset_hash'] = str_repeat('f', 64);

        $analysis = (new CtripOrderAnalysisService())->analyzeRows(
            $rows,
            64,
            '2026-08-08',
            '2026-08-09'
        );

        self::assertSame('indeterminate', $analysis['status']);
        self::assertSame('indeterminate', $analysis['quality_status']);
        self::assertSame([], $analysis['summary']);
        self::assertSame([], $analysis['channels']);
        self::assertStringContainsString('数据集哈希', $analysis['note']);
    }

    public function testInvalidCalendarDateFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('日期范围无效');

        (new CtripOrderAnalysisService())->analyzeRows([], 64, '2026-02-30', '2026-03-01');
    }

    public function testRangeLongerThan1096DaysFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('最多为 1096 天');

        (new CtripOrderAnalysisService())->analyzeRows([], 64, '2024-01-01', '2027-01-01');
    }

    public function testReadEnvelopeKeepsRequestedDatesSeparateFromNarrowerCoverage(): void
    {
        $analysis = (new CtripOrderAnalysisService())->analyzeRows(
            $this->verifiedV2Rows(),
            64,
            '2026-08-01',
            '2026-08-31'
        );
        self::assertSame(64, $analysis['hotel']['id']);
        self::assertSame('2026-08-01', $analysis['date_range']['requested_from']);
        self::assertSame('2026-08-31', $analysis['date_range']['requested_to']);
        self::assertSame('2026-08-08', $analysis['date_range']['from']);
        self::assertSame('2026-08-09', $analysis['date_range']['to']);
        self::assertNotSame('no_data', $analysis['status']);
    }

    public function testLegitimateNoDataEnvelopeStillEchoesTheHotelAndRequestedRange(): void
    {
        foreach ([['2026-08-01', '2026-08-31'], [null, null]] as [$from, $to]) {
            $analysis = (new CtripOrderAnalysisService())->analyzeRows([], 64, $from, $to);
            self::assertSame('no_data', $analysis['status']);
            self::assertSame(64, $analysis['hotel']['id']);
            self::assertSame($from, $analysis['date_range']['requested_from']);
            self::assertSame($to, $analysis['date_range']['requested_to']);
            self::assertNull($analysis['date_range']['from']);
            self::assertNull($analysis['date_range']['to']);
            self::assertSame([], $analysis['summary']);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function verifiedV2Rows(): array
    {
        $base = [
            '城市' => '桂林',
            '币种' => 'CNY',
            '酒店名称' => '匿名酒店（订单分析测试 fixture）',
            '订单类型' => '新订',
            '离店日期' => '2026-08-10',
            '通知时间' => '2026-08-01 10:05:00',
            '房间数' => '1',
            '卖价' => '0',
            '_source_format' => 'biff_xls',
            '_source_layout' => 'ctrip_order_export_25_columns',
        ];
        $sourceRows = [
            array_replace($base, [
                '订单号' => 'ANON-ANALYSIS-1',
                '订单状态' => '已入住',
                '入住日期' => '2026-08-08',
                '离店日期' => '2026-08-09',
                '预订时间' => '2026-08-08 09:00:00',
                '晚数' => '1',
                '房间数' => '1',
                '底价' => '100',
                '房型名称' => '江景大床房',
                '预订网站' => '携程',
            ]),
            array_replace($base, [
                '订单号' => 'ANON-ANALYSIS-2',
                '订单状态' => '已确认',
                '入住日期' => '2026-08-08',
                '离店日期' => '2026-08-10',
                '预订时间' => '2026-08-05 09:00:00',
                '晚数' => '2',
                '房间数' => '1',
                '底价' => '400',
                '房型名称' => '江景大床房',
                '预订网站' => '携程',
            ]),
            array_replace($base, [
                '订单号' => 'ANON-ANALYSIS-3',
                '订单状态' => '已取消',
                '入住日期' => '2026-08-08',
                '离店日期' => '2026-08-09',
                '预订时间' => '2026-08-07 09:00:00',
                '晚数' => '1',
                '房间数' => '1',
                '底价' => '300',
                '房型名称' => '取消房型不应进入画像',
                '预订网站' => '携程',
            ]),
            array_replace($base, [
                '订单号' => 'ANON-ANALYSIS-4',
                '订单状态' => '已入住',
                '入住日期' => '2026-08-09',
                '离店日期' => '2026-08-12',
                '预订时间' => '2026-07-30 09:00:00',
                '晚数' => '3',
                '房间数' => '2',
                '底价' => '900',
                '房型名称' => '湖景双床房',
                '预订网站' => '去哪儿',
            ]),
            array_replace($base, [
                '订单号' => 'ANON-ANALYSIS-5',
                '订单状态' => '待人工确认的新状态',
                '入住日期' => '2026-08-09',
                '离店日期' => '2026-08-10',
                '预订时间' => '2026-08-01 09:00:00',
                '晚数' => '1',
                '房间数' => '1',
                '底价' => '200',
                '房型名称' => '未知状态房型不应进入画像',
                '预订网站' => '去哪儿',
            ]),
        ];

        $rows = (new CtripOrderExportImportService())->normalizeRows($sourceRows, [
            'system_hotel_id' => 64,
            'hotel_name' => '匿名酒店（订单分析测试 fixture）',
            'test_fixture' => true,
        ]);
        return array_map(static function (array $row): array {
            $row['_readback_verified'] = true;
            return $row;
        }, $rows);
    }

    /** @return array<string, mixed> */
    private function verifiedV1Row(): array
    {
        return [
            'system_hotel_id' => 64,
            'hotel_id' => 'system:64',
            'hotel_name' => '匿名酒店（旧契约 fixture）',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'order',
            'data_date' => '2026-08-08',
            'book_order_num' => 2,
            'gross_order_num' => 3,
            'cancel_order_num' => 1,
            'unknown_status_order_num' => 0,
            'quantity' => 4.0,
            'amount' => null,
            'bottom_price_adr' => 225.0,
            'avg_los' => 2.0,
            'avg_lead_days' => 5.0,
            '_readback_verified' => true,
            'raw_data' => [
                'channel_key' => 'ctrip',
                'channel_label' => '携程主站',
                'bottom_price_sum' => 900.0,
                'bottom_price_room_nights' => 4.0,
                'bottom_price_valid_order_count' => 2,
                'single_night_rate' => 0.5,
                'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
                'import_contract' => 'ctrip_order_aggregate_v1',
                'pii_policy' => 'aggregate_only_no_guest_staff_reservation_notes',
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $buckets */
    private function distributionOrderTotal(array $buckets): int
    {
        return array_sum(array_map(
            static fn(array $bucket): int => (int)($bucket['orders'] ?? 0),
            $buckets
        ));
    }
}
