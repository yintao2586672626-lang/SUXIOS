<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/tests/bootstrap.php';

use Tests\Support\PreciseQuerySyntheticFixture;
use app\service\OperatingQuestionService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\facade\Db;

// Optional snapshot of the L08 contract, copied into THIS tree's test output.
// Never changes application source or any other worktree.
$overlay=$argv[1] ?? '';
if ($overlay!=='') {
    $resolved=realpath($overlay);$expected=realpath(dirname(__DIR__, 2).'/output/long-goal/knowledge-overlay');
    if (!$resolved || $resolved!==$expected) throw new RuntimeException('Unexpected test overlay path');
    foreach (['KnowledgeContentDigestService','KnowledgeRevisionService','KnowledgeApplicabilityService','KnowledgeDecisionGateService','OperatingQuestionKnowledgeRetrievalService'] as $name) require $resolved.'/'.$name.'.php';
}
$db=sys_get_temp_dir().'/l03-synthetic-knowledge-'.getmypid().'.sqlite';
try {
    PreciseQuerySyntheticFixture::connect($db);
    Db::execute('DROP TABLE online_daily_data');
    Db::execute('CREATE TABLE knowledge_units (unit_id INTEGER PRIMARY KEY,hotel_id INTEGER,tenant_id INTEGER,created_by INTEGER,name TEXT,source TEXT,status TEXT,description TEXT,lifecycle_status TEXT,reviewed_at TEXT,review_due_at TEXT)');
    Db::execute('CREATE TABLE knowledge_chunks (chunk_id INTEGER PRIMARY KEY,unit_id INTEGER,type TEXT,content TEXT,lifecycle_status TEXT)');
    foreach ([1=>10,2=>11] as $id=>$tenant) {
        Db::name('knowledge_units')->insert(['unit_id'=>$id,'hotel_id'=>80,'tenant_id'=>$tenant,'created_by'=>7,'name'=>'synthetic携程曝光复核','source'=>'manual','status'=>'done','description'=>'曝光来源核对','lifecycle_status'=>'active','reviewed_at'=>'2026-09-01','review_due_at'=>'2099-12-31']);
        Db::name('knowledge_chunks')->insert(['chunk_id'=>100+$id,'unit_id'=>$id,'type'=>'运营SOP','lifecycle_status'=>'active','content'=>json_encode([
            'scope'=>'generic_methodology','evidence_level'=>'reviewed_method','source_refs'=>['synthetic://knowledge/'.$id],
            'platforms'=>['ctrip'],'steps'=>['携程曝光下降时先核验列表曝光与详情访客口径。'],
        ],JSON_UNESCAPED_UNICODE)]);
    }
    $method=new ReflectionMethod(OperatingQuestionService::class,'loadEvidence');
    $evidence=$method->invoke(new OperatingQuestionService(),10,80,'ctrip','2026-09-07','2026-09-07','携程曝光下降怎么复核',7);
    $refs=array_column($evidence['knowledge'],'ref');
    $newContract=(new ReflectionMethod(OperatingQuestionKnowledgeRetrievalService::class,'retrieve'))->getNumberOfParameters()===5;
    if ($newContract && $refs!==['knowledge_chunks#101']) throw new RuntimeException('Tenant-filtered knowledge refs mismatch: '.json_encode($refs));
    if (!$newContract && !in_array('knowledge_chunks#101',$refs,true)) throw new RuntimeException('Legacy four-argument service compatibility failed');
    if ($evidence['facts']!==[]) throw new RuntimeException('Reference knowledge became operating facts');
    foreach ($evidence['knowledge'] as $item) if (($item['applicability']['fact_safe'] ?? false)!==false) throw new RuntimeException('Knowledge fact boundary violated');
    echo json_encode(['status'=>'passed','mode'=>$newContract?'l08_tenant_contract':'legacy_four_argument_compatibility','server_tenant_id'=>10,'knowledge_refs'=>$refs,'operating_fact_refs'=>[],
        'retrieval_source'=>(new ReflectionClass(OperatingQuestionKnowledgeRetrievalService::class))->getFileName()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
} finally {Db::connect('sqlite')->close();if(is_file($db))unlink($db);}
