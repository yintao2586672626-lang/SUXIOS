<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingInputService;
use app\service\DailyOneThingService;
use app\service\OtaReputationDailySignalService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class OtaReputationScoreBoundaryTest extends TestCase
{
    public function testInvalidPreviousScoreCannotBecomeADeclineForEitherPlatform(): void
    {
        foreach (['ctrip','meituan'] as $platform) {
            $result=$this->build([$this->row(2,'2026-09-01',4.8,0,0,$platform),$this->row(1,'2026-08-31',101,0,0,$platform)]);
            self::assertSame([], $result['signals']);
            self::assertSame('invalid',$result['platforms'][$platform]['previous_score_status']);
            self::assertSame('available',$result['platforms'][$platform]['current_score_status']);
        }
    }

    public function testBadScoreDoesNotSuppressIndependentlyUsableCounters(): void
    {
        $result=$this->build([$this->row(2,'2026-09-01',101,3,2),$this->row(1,'2026-08-31',4.8,1,0)]);
        self::assertSame(['unreplied_reviews','bad_reviews_increased'],array_column($result['signals'],'kind'));
        self::assertSame('invalid',$result['platforms']['meituan']['current_score_status']);
        self::assertSame('strict_fact_available',$result['platforms']['meituan']['status']);
        self::assertSame(2,$result['signals'][0]['current_value']);
    }

    public function testInvalidNewestScoreDoesNotFallBackToAnOlderSameDayScore(): void
    {
        foreach (['2026-09-01','2026-08-31'] as $invalidDate) {
            $latest=$this->row(30,$invalidDate,101);
            $latest['snapshot_time']=$invalidDate.' 10:00:00';
            $older=$this->row(20,$invalidDate,$invalidDate==='2026-09-01'?4.7:5.0);
            $older['snapshot_time']=$invalidDate.' 08:00:00';
            $other=$this->row(10,$invalidDate==='2026-09-01'?'2026-08-31':'2026-09-01',4.8);
            $result=$this->build([$older,$other,$latest]);
            self::assertSame([],$result['signals']);
            $prefix=$invalidDate==='2026-09-01'?'current':'previous';
            self::assertSame('online_daily_data#30',$result['platforms']['meituan'][$prefix.'_record_ref']);
            self::assertSame('invalid',$result['platforms']['meituan'][$prefix.'_score_status']);
        }
    }

    public function testAnInvalidScoreAloneIsNotAUsableStrictFact(): void
    {
        $result=$this->build([$this->row(2,'2026-09-01',101,null,null)]);
        self::assertSame('no_current_usable_metrics',$result['platforms']['meituan']['status']);
        self::assertSame('invalid',$result['platforms']['meituan']['current_score_status']);
        self::assertSame('online_daily_data#2',$result['platforms']['meituan']['current_record_ref']);
        self::assertSame([],$result['signals']);
    }

    public function testMissingZeroAndNonFiniteScoresDoNotCreateScoreSignals(): void
    {
        foreach ([null,0,-1,5.01,45,90,INF,NAN] as $score) {
            $rows=[$this->row(2,'2026-09-01',4.8),$this->row(1,'2026-08-31',$score)];
            $result=$this->build($rows);
            self::assertSame([],$result['signals']);
            self::assertSame($score===null||$score===0?'missing':'invalid',$result['platforms']['meituan']['previous_score_status']);
        }
        $valid=$this->build([$this->row(2,'2026-09-01',4.9),$this->row(1,'2026-08-31',5)]);
        self::assertSame('score_declined',$valid['signals'][0]['kind']);
        self::assertSame(5.0,$valid['signals'][0]['previous_value']);
    }

    public function testInvalidScoreCannotEnterTheActualDailyOneThingCandidateFlow(): void
    {
        $rows=[$this->row(2,'2026-09-01',4.8),$this->row(1,'2026-08-31',101)];
        $reputation=$this->build($rows);
        $fields=array_map(static fn($key)=>['key'=>$key,'label'=>$key,
            'status'=>in_array($key,['adr','conversion'],true)?'verified_calculation':'strict_readback',
            'value'=>1,'identity_binding_verified'=>true,'strict_final_gate'=>true],
            ['revenue','order_count','room_nights','adr','exposure','visits','conversion']);
        $closure=['contract_version'=>'dual_ota_field_closure.v1','tenant_id'=>80,'hotel_id'=>80,
            'business_date'=>'2026-09-01','metric_scope'=>'ota_channel_only','closure_digest'=>str_repeat('c',64),
            'platforms'=>array_fill_keys(['ctrip','meituan'],['fields'=>$fields,
                'current_receipt_all_record_refs'=>['online_daily_data#2'],'current_receipt_record_refs'=>['online_daily_data#2']])];
        $input=(new DailyOneThingInputService(static fn():array=>$closure,
            static fn():array=>['data_status'=>'ok','list'=>[]],static fn():?array=>null,
            static fn():DateTimeImmutable=>new DateTimeImmutable('2026-09-01 09:00:00',new DateTimeZone('Asia/Shanghai')),
            static fn():array=>$reputation))->build(80,80,'2026-09-01',7);
        self::assertSame([],array_values(array_filter($input['candidates'],static fn($candidate)=>$candidate['source_type']==='strict_fact_signal')));
        $selected=(new DailyOneThingService())->select($input['candidates'],'2026-09-01');
        self::assertFalse($selected['can_execute']);
        self::assertSame('no_actionable_signal',$input['source_snapshot']['ota_reputation_signal']['status']);
    }

    public function testCapturedScoreNormalizationSurvivesSerializedReadback(): void
    {
        $controller = (new \ReflectionClass(\app\controller\OnlineData::class))->newInstanceWithoutConstructor();
        $capture = new \ReflectionMethod($controller, 'buildMeituanCapturedDailyRows');
        foreach ([101, 45, 90, 5, 0] as $rawScore) {
            $rows = $capture->invoke($controller, [
                'storeId' => 'synthetic-store-80', 'poiId' => 'synthetic-poi-80',
                'defaultDataDate' => '2026-09-01',
                'reviews' => [
                    ['score' => 4.4, 'badReviewCount' => 0, 'dataDate' => '2026-09-01'],
                    ['score' => $rawScore, 'badReviewCount' => 0, 'dataDate' => '2026-08-31'],
                ],
            ], 80);
            self::assertCount(2, $rows);
            foreach ($rows as $index => &$row) {
                $row = array_merge($row, [
                    'id' => $index + 1, 'tenant_id' => 80, 'readback_verified' => 1,
                    'validation_status' => 'normal', 'ingestion_method' => 'synthetic_fixture',
                    'source_trace_id' => 'synthetic-review-' . $index,
                ]);
            }
            unset($row);
            $readback = json_decode(json_encode($rows, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $result = $this->build($readback);
            if (in_array($rawScore, [45, 90, 5], true)) {
                self::assertSame('score_declined', $result['signals'][0]['kind']);
                self::assertSame($rawScore === 5 ? 5.0 : 4.5, $result['signals'][0]['previous_value']);
            } else {
                self::assertSame([], $result['signals']);
                self::assertSame($rawScore === 0 ? 'missing' : 'invalid', $result['platforms']['meituan']['previous_score_status']);
            }
        }
    }

    private function build(array $rows): array
    {
        return (new OtaReputationDailySignalService(static fn():array=>$rows))->build(80,80,'2026-09-01');
    }

    private function row(int $id,string $date,?float $score,?int $bad=0,?int $unreplied=0,string $platform='meituan'): array
    {
        return ['id'=>$id,'tenant_id'=>80,'system_hotel_id'=>80,'hotel_id'=>$platform.'-80',
            'source'=>$platform,'platform'=>$platform,'data_type'=>'review','data_date'=>$date,'comment_score'=>$score,
            'readback_verified'=>1,'validation_status'=>'normal','ingestion_method'=>'browser_profile',
            'source_trace_id'=>$platform.':'.str_repeat('a',64),'update_time'=>$date.' 09:00:00',
            'raw_data'=>json_encode(['metrics'=>['bad_review_count'=>$bad,'comment_unreply_count'=>$unreplied],
                'dimension_values'=>['comment_channel'=>$platform]],JSON_THROW_ON_ERROR)];
    }
}
