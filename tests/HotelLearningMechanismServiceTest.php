<?php
declare(strict_types=1);
use app\service\HotelLearningMechanismService;
use PHPUnit\Framework\TestCase;

final class HotelLearningMechanismServiceTest extends TestCase
{
    public function testEmptyProcurementReferenceIsRejectedBeforeManualTargetNormalization(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('手填目标反求不能采用未经项目接口校验');
        $this->service()->calculate('investment_target', ['scenario' => ['consumables_cost' => ['mode' => 'derived', 'items' => [
            ['id' => 'synthetic-reference', 'name' => '合成耗材', 'enabled' => true, 'unit' => 'piece',
                'usage_basis' => 'occupied_room_night', 'procurement_reference' => []],
        ]]], 'request' => ['solve_for' => 'adr', 'target_payback_months' => 12]]);
    }
    private function service(): HotelLearningMechanismService { return new HotelLearningMechanismService(); }
    private function ota(): array { return ['scene'=>['keyword'=>'测试地区酒店','location'=>'测试城市','device'=>'desktop','login_state'=>'anonymous','sort'=>'推荐','filters'=>'无','observed_at'=>'2026-10-02 12:00','source_ref'=>'synthetic-screen-1','platform_store_id'=>'80','check_in'=>'2026-10-10','check_out'=>'2026-10-11','page_capacity'=>20],'visibility'=>'observed','rank_min'=>3,'rank_max'=>5,'conversion_rate'=>1,'rate_unit'=>'percentage_point','price'=>100,'price_terms'=>['room_type'=>'标准双床','cancellation'=>'免费取消','breakfast'=>'双早','guest_count'=>'2','membership'=>'非会员','tax_basis'=>'含税','payment'=>'预付']]; }
    private function review(): array { $data=['period_start'=>'2026-10-01','period_end'=>'2026-10-31','source_ref'=>'synthetic-finance','basis'=>'whole_hotel_actual_cash','available_room_nights'=>1000,'sold_room_nights'=>800,'revenue'=>100000,'operating_cost'=>70000,'debt_service'=>2000,'project_net_cash'=>28000,'investor_received_cash'=>0]; return ['period_start'=>'2026-10-01','period_end'=>'2026-10-31','actual'=>$data,'plan'=>array_replace($data,['source_ref'=>'synthetic-plan','revenue'=>120000])]; }
    private function market(): array { return ['weights'=>['traffic'=>0.4,'conversion'=>0.3,'revenue'=>0.3],'model_version'=>'manual-v1','sample_ref'=>'synthetic-same-scene','comparison_key'=>'same-window','hotels'=>[['platform_store_id'=>'80','comparison_key'=>'same-window','traffic'=>100,'conversion'=>1,'rate_unit'=>'percentage_point','revenue'=>1000],['platform_store_id'=>'81','comparison_key'=>'same-window','traffic'=>200,'conversion'=>2,'rate_unit'=>'percentage_point','revenue'=>2000]]]; }
    public function testSourceStatusNeverBecomesVerifiedFact(): void { $r=$this->service()->calculate('profile',['fields'=>[['key'=>'rooms','value'=>'60','unit'=>'间','source_ref'=>'synthetic-file','as_of'=>'2026-10-02','quality'=>'operator_attested']]]); self::assertSame('recorded',$r['status']);self::assertFalse($r['contains_verified_hotel_fact']);self::assertFalse($r['decision_safe']);self::assertFalse($r['external_write_authorized']); }
    public function testMissingProvenanceRemainsUnverifiedAndUnknownFieldsAreStripped(): void { $r=$this->service()->calculate('profile',['extra'=>'not retained','fields'=>[['key'=>'rooms','value'=>'60','private_extra'=>'discarded']]]);self::assertSame('partial',$r['status']);self::assertSame('unverified',$r['fields'][0]['status']);self::assertArrayNotHasKey('extra',$r['inputs']);self::assertArrayNotHasKey('private_extra',$r['inputs']['fields'][0]); }
    public function testCredentialFieldsAreRejectedBeforePersistence(): void { $this->expectException(InvalidArgumentException::class); $this->service()->calculate('geo_observation',['nested'=>['cookie'=>'untrusted-placeholder']]); }
    public function testOnePercentagePointIsNeverGuessedAsOneHundredPercent(): void { $r=$this->service()->calculate('ota_scene',$this->ota());self::assertSame(1.0,$r['conversion_percentage_point']);self::assertTrue($r['comparison_ready']);self::assertFalse($r['rank_proves_exposure']); }
    public function testSameSceneAcrossHotelsAndObservationTimesIsComparable(): void { $a=$this->ota();$b=$a;$b['scene']['platform_store_id']='81';$b['scene']['observed_at']='2026-10-02 12:05';$b['scene']['source_ref']='synthetic-screen-2';$first=$this->service()->calculate('ota_scene',$a);$second=$this->service()->calculate('ota_scene',$b);self::assertSame($first['comparison_key'],$second['comparison_key']);self::assertNotSame($first['scene_fingerprint'],$second['scene_fingerprint']); }
    public function testDifferentSalesTermsCannotShareComparisonKey(): void { $a=$this->ota();$b=$a;$b['price_terms']['cancellation']='不可取消';self::assertNotSame($this->service()->calculate('ota_scene',$a)['comparison_key'],$this->service()->calculate('ota_scene',$b)['comparison_key']); }
    public function testMissingPriceTermsBlockComparisonAndPreserveZero(): void { $i=$this->ota();$i['price']=0;$i['price_terms']['breakfast']='';$r=$this->service()->calculate('ota_scene',$i);self::assertSame(0.0,$r['price']);self::assertFalse($r['comparison_ready']);self::assertNull($r['comparison_key']); }
    public function testNotSeenIsBoundedObservationWithNoSyntheticRank(): void { $i=$this->ota();$i['visibility']='not_seen_in_range';$i['observed_through_rank']=100;$r=$this->service()->calculate('ota_scene',$i);self::assertNull($r['rank_min']);self::assertSame(100.0,$r['observed_through_rank']);self::assertFalse($r['comparison_ready']); }
    public function testRateWithoutExplicitUnitIsRejected(): void { $i=$this->ota();unset($i['rate_unit']);$this->expectException(InvalidArgumentException::class);$this->service()->calculate('ota_scene',$i); }
    public function testManualMarketScoreHasExactSampleAndVersionAndNoOfficialGrade(): void { $r=$this->service()->calculate('market_sample',$this->market());self::assertSame(0.0,$r['items'][0]['reference_score']);self::assertSame(100.0,$r['items'][1]['reference_score']);self::assertFalse($r['official_platform_score']);self::assertFalse($r['automatic_grading']); }
    public function testMissingMetricDoesNotBecomeZeroOrNeutralFifty(): void { $i=$this->market();$i['hotels'][0]['conversion']='';$r=$this->service()->calculate('market_sample',$i);self::assertSame('partial',$r['status']);self::assertNull($r['items'][0]['reference_score']);self::assertNull($r['items'][1]['scores']['conversion']); }
    public function testSampleChangeInvalidatesFingerprint(): void { $i=$this->market();$a=$this->service()->calculate('market_sample',$i);$i['hotels'][]=['platform_store_id'=>'82','comparison_key'=>'same-window','traffic'=>300,'conversion'=>3,'rate_unit'=>'percentage_point','revenue'=>3000];$b=$this->service()->calculate('market_sample',$i);self::assertNotSame($a['sample_fingerprint'],$b['sample_fingerprint']);self::assertNotSame($a['items'][1]['reference_score'],$b['items'][1]['reference_score']); }
    public function testMixedScenesAreRejected(): void { $i=$this->market();$i['hotels'][0]['comparison_key']='other-window';$this->expectException(InvalidArgumentException::class);$this->service()->calculate('market_sample',$i); }
    public function testIntermediateSampleMetricChangeAlsoChangesVersionFingerprint(): void { $i=$this->market();$i['hotels'][]=['platform_store_id'=>'82','comparison_key'=>'same-window','traffic'=>300,'conversion'=>3,'rate_unit'=>'percentage_point','revenue'=>3000];$a=$this->service()->calculate('market_sample',$i);$i['hotels'][1]['traffic']=220;$b=$this->service()->calculate('market_sample',$i);self::assertNotSame($a['sample_fingerprint'],$b['sample_fingerprint']); }
    public function testIncompleteSampleDoesNotGradeOtherHotelsAsComplete(): void { $i=$this->market();$i['hotels'][]=['platform_store_id'=>'82','comparison_key'=>'same-window','traffic'=>300,'conversion'=>null,'rate_unit'=>'percentage_point','revenue'=>3000];$r=$this->service()->calculate('market_sample',$i);foreach($r['items'] as $item)self::assertNull($item['reference_score']); }
    public function testActualCostRatioUsesRevenueAndKeepsInvestorCashSeparate(): void { $r=$this->service()->calculate('operating_review',$this->review());self::assertSame('compared',$r['status']);self::assertSame(0.7,$r['actual_cost_ratio']);$rows=array_column($r['rows'],null,'metric');self::assertSame(0.0,$rows['investor_received_cash']['actual']);self::assertFalse($r['annualized_as_actual']);self::assertFalse($r['project_cash_equals_investor_recovery']); }
    public function testPeriodMismatchHasNoComparisonOrCostRatio(): void { $i=$this->review();$i['plan']['period_end']='2026-09-30';$r=$this->service()->calculate('operating_review',$i);self::assertFalse($r['scope_aligned']);self::assertNull($r['actual_cost_ratio']);foreach($r['rows'] as $row)self::assertNull($row['difference']); }
    public function testMissingActualDoesNotUsePlanAsFallback(): void { $i=$this->review();unset($i['actual']['revenue']);$r=$this->service()->calculate('operating_review',$i);$rows=array_column($r['rows'],null,'metric');self::assertNull($rows['revenue']['actual']);self::assertNull($rows['revenue']['difference']);self::assertSame('partial',$r['status']); }
    public function testManualGeoEvidenceMakesNoOrderAttributionClaim(): void { $r=$this->service()->calculate('geo_observation',['question'=>'合成问题','model'=>'manual-record','model_version'=>'v1','region'=>'测试城市','network'=>'合成网络','observed_at'=>'2026-10-02 12:00','response_summary'=>'合成回答摘要','source_ref'=>'synthetic-model-screen','citations'=>[['url'=>'https://example.com/evidence','fact_consistency'=>'unverified']]]);self::assertSame('recorded',$r['status']);self::assertFalse($r['model_called']);self::assertFalse($r['order_attribution_verified']);self::assertFalse($r['marketing_effect_claimed']); }
    public function testNaturalYearContractBoundaryIsIncluded(): void { $r=$this->service()->calculate('contract_review',['as_of'=>'2026-10-01','payback_months'=>12,'constraints'=>['contract_start_on'=>'2026-10-01','contract_end_on'=>'2027-10-01','contract_source'=>'synthetic-lease','contract_confirmed'=>true,'target_payback_months'=>12]]);self::assertSame('evaluated_assumption',$r['status']);self::assertSame('within_limit',$r['calendar']['contract_payback_status']);self::assertSame('2027-10-01',$r['calendar']['forecast_payback_on']); }
    public function testUnvalidatedActualSnapshotCannotEnterManualSolver(): void { $this->expectException(InvalidArgumentException::class);$this->service()->calculate('investment_target',['scenario'=>['cost_evidence_snapshot_id'=>10]]); }

    public function testMissingPriceCannotCreateComparableSalesScene(): void
    {
        $input = $this->ota();
        unset($input['price']);
        $result = $this->service()->calculate('ota_scene', $input);
        self::assertFalse($result['comparison_ready']);
        self::assertNull($result['comparison_key']);
        self::assertSame('partial', $result['status']);
        self::assertContains('price', $result['missing_items']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fractionalOtaCounts')]
    public function testOtaCountsCannotUseFractionalRankOrPageSize(string $field): void
    {
        $input = $this->ota();
        if ($field === 'page_capacity') $input['scene'][$field] = 20.5;
        else $input[$field] = 3.5;
        $this->expectException(InvalidArgumentException::class);
        $this->service()->calculate('ota_scene', $input);
    }

    public static function fractionalOtaCounts(): array
    {
        return array_map(static fn(string $field): array => [$field], ['page_capacity', 'rank_min', 'rank_max', 'observed_through_rank']);
    }

    public function testWhitespaceOnlyReviewBasisCannotBeCompared(): void
    {
        $input = $this->review();
        $input['actual']['basis'] = $input['plan']['basis'] = '   ';
        $result = $this->service()->calculate('operating_review', $input);
        self::assertFalse($result['scope_aligned']);
        self::assertNull($result['actual_cost_ratio']);
        foreach ($result['rows'] as $row) self::assertNull($row['difference']);
        self::assertSame('', $result['inputs']['actual']['basis']);
    }

    public function testReviewMetadataIsNormalizedBeforeComparisonAndReadback(): void
    {
        $input = $this->review();
        $input['actual']['basis'] .= ' ';
        $input['actual']['source_ref'] = ' synthetic-finance ';
        $result = $this->service()->calculate('operating_review', $input);
        self::assertTrue($result['scope_aligned']);
        self::assertSame('compared', $result['status']);
        self::assertSame('synthetic-finance', $result['inputs']['actual']['source_ref']);
    }
}
