<?php
declare(strict_types=1);

use app\service\CloudDataHealthService;
use PHPUnit\Framework\TestCase;

final class CloudHealthTaskDateAuditTest extends TestCase
{
    private function evaluate(array $tasks, string $sourceStatus = ''): array
    {
        $trace = 'audit-ctrip-20260901';
        $evidence = ['source_trace_id'=>$trace,'source_url_hash'=>str_repeat('a',64)];
        $row = ['id'=>1,'tenant_id'=>2,'system_hotel_id'=>7,'data_date'=>'2026-09-01',
            'source'=>'ctrip','platform'=>'ctrip','data_type'=>'business_overview',
            'data_period'=>'historical_daily','validation_status'=>'normal','validation_flags'=>'[]',
            'data_source_id'=>11,'readback_verified'=>1,'source_trace_id'=>$trace,
            'raw_data'=>json_encode(['source_trace_id'=>$trace,'capture_evidence'=>$evidence,
                'field_facts'=>[['metric_key'=>'order_amount','status'=>'captured','source_path'=>'$.amount',
                    'storage_field'=>'online_daily_data.amount','stored_value_present'=>true,'capture_evidence'=>$evidence]]])];
        return CloudDataHealthService::evaluate(['id'=>7,'tenant_id'=>2,'name'=>'SYNTHETIC AUDIT'],
            '2026-09-01',['ctrip'],[$row],[['id'=>11,'system_hotel_id'=>7,'platform'=>'ctrip','enabled'=>1,'last_sync_status'=>$sourceStatus]],$tasks,true);
    }

    public function testSameDatePartialCollectionStillBlocks(): void
    {
        $result=$this->evaluate([['id'=>2,'system_hotel_id'=>7,'platform'=>'ctrip','data_source_id'=>11,
            'target_date'=>'2026-09-01','data_date'=>'2026-09-01','status'=>'partial_success','finished_at'=>'2026-09-02 10:00:00']]);
        self::assertFalse($result['can_generate_report']);
    }

    public function testAnotherDatePartialCollectionCannotInvalidateVerifiedHistoricalFacts(): void
    {
        $baseline=$this->evaluate([]);
        self::assertTrue($baseline['can_generate_report']);
        self::assertTrue($baseline['readback']['verified']);
        $result=$this->evaluate([['id'=>2,'system_hotel_id'=>7,'platform'=>'ctrip','data_source_id'=>11,
            'target_date'=>'2026-09-25','data_date'=>'2026-09-25','status'=>'partial_success','finished_at'=>'2026-09-26 10:00:00']]);
        self::assertTrue($result['can_generate_report'], json_encode($result['issues'],JSON_UNESCAPED_UNICODE));
        self::assertTrue($result['readback']['verified']);
    }

    public function testPersistedStatsDateKeepsCurrentSourceFailureSeparateFromHistoricalQuality(): void
    {
        $result = $this->evaluate([['id'=>2,'system_hotel_id'=>7,'tenant_id'=>2,'platform'=>'ctrip',
            'data_source_id'=>11,'status'=>'partial_success','finished_at'=>'2026-09-26 10:00:00',
            'stats_json'=>json_encode(['run_readback'=>['target_date'=>'2026-09-25']])]], 'partial_success');
        self::assertTrue($result['can_generate_report']);
        self::assertTrue($result['readback']['verified']);
    }

    public function testLaterOtherDateSuccessDoesNotHideTargetDatePartialTask(): void
    {
        $result = $this->evaluate([
            ['id'=>2,'system_hotel_id'=>7,'tenant_id'=>2,'platform'=>'ctrip','data_source_id'=>11,
                'status'=>'partial_success','target_date'=>'2026-09-01','finished_at'=>'2026-09-02 10:00:00'],
            ['id'=>3,'system_hotel_id'=>7,'tenant_id'=>2,'platform'=>'ctrip','data_source_id'=>11,
                'status'=>'success','target_date'=>'2026-09-25','finished_at'=>'2026-09-26 10:00:00'],
        ]);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('latest_collection_partial', array_column($result['issues'], 'code'));
    }

    public function testWrongHotelTenantAndDataSourceTasksCannotChangeTargetHealth(): void
    {
        foreach ([['system_hotel_id'=>8], ['tenant_id'=>9], ['data_source_id'=>99]] as $mismatch) {
            $task=array_replace(['id'=>2,'system_hotel_id'=>7,'tenant_id'=>2,'platform'=>'ctrip',
                'data_source_id'=>11,'status'=>'partial_success','target_date'=>'2026-09-01'], $mismatch);
            self::assertTrue($this->evaluate([$task])['can_generate_report']);
        }
    }

    public function testUndatedLegacyPartialRemainsUnresolved(): void
    {
        self::assertFalse($this->evaluate([['platform'=>'ctrip','status'=>'partial_success']])['can_generate_report']);
    }

    public function testUndatedSuccessCannotClearExplicitTargetDatePartial(): void
    {
        $result = $this->evaluate([
            ['id'=>2,'system_hotel_id'=>7,'tenant_id'=>2,'platform'=>'ctrip','data_source_id'=>11,
                'status'=>'partial_success','target_date'=>'2026-09-01','finished_at'=>'2026-09-02 10:00:00'],
            ['id'=>3,'system_hotel_id'=>7,'tenant_id'=>2,'platform'=>'ctrip','data_source_id'=>11,
                'status'=>'success','finished_at'=>'2026-09-26 10:00:00'],
        ]);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('latest_collection_partial', array_column($result['issues'], 'code'));
    }

    #[\PHPUnit\Framework\Attributes\Group('mariadb')]
    public function testDatabaseTaskReadPreservesDateInStatsForEvaluation(): void
    {
        if (getenv('SUXI_CLOUDHEALTH_TEST_DATABASE') !== 'hotelx_cloudhealth_20260926_test') {
            self::markTestSkipped('Requires the dedicated MariaDB adapter probe database.');
        }
        $identity=\think\facade\Db::query('SELECT DATABASE() AS name');
        self::assertSame('hotelx_cloudhealth_20260926_test', $identity[0]['name'] ?? '');
        \think\facade\Db::execute('CREATE TABLE platform_data_sync_tasks (id INTEGER PRIMARY KEY, system_hotel_id INTEGER, tenant_id INTEGER, platform TEXT, data_source_id INTEGER, status TEXT, stats_json TEXT)');
        try {
            \think\facade\Db::name('platform_data_sync_tasks')->insert(['id'=>2,'system_hotel_id'=>7,'tenant_id'=>2,
                'platform'=>'ctrip','data_source_id'=>11,'status'=>'partial_success',
                'stats_json'=>json_encode(['collection_quality'=>['target_date'=>'2026-09-25']])]);
            $method=new ReflectionMethod(CloudDataHealthService::class, 'syncTasksForHotel');
            $tasks=$method->invoke(new CloudDataHealthService(), 7);
            self::assertCount(1, $tasks);
            self::assertTrue($this->evaluate($tasks)['can_generate_report']);
        } finally {
            \think\facade\Db::execute('DROP TABLE platform_data_sync_tasks');
        }
    }
}
