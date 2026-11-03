<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class BusinessWorkspaceService
{
    public const TABLE='hotel_business_workspace_snapshots';
    public const CONTRACT='business_workspace.v1';
    public function scope(int $tenant,array $permitted,int $hotel,int $user,string $kind):array
    {
        if($tenant<=0||$hotel<=0||$user<=0||!in_array($hotel,array_map('intval',$permitted),true)
            ||!Db::name('hotels')->where('id',$hotel)->where('tenant_id',$tenant)->find()) throw new RuntimeException('business_workspace_forbidden',403);
        if(!in_array($kind,['configuration','weekly_review','manager_review','ota_review','source_mapping'],true)) throw new InvalidArgumentException('business_workspace_kind_invalid');
        return ['tenant_id'=>$tenant,'hotel_id'=>$hotel,'owner_user_id'=>$kind==='configuration'?$user:0,'kind'=>$kind];
    }
    public function overview(array $scope):array
    {
        $rows=Db::name(self::TABLE)->where($scope)->order('id','desc')->limit(30)->select()->toArray();
        return ['contract_version'=>self::CONTRACT,'scope'=>$scope,'status'=>$rows?'ready':'missing',
            'catalog'=>BusinessFeatureCatalog::modules(),'suggested_configuration'=>BusinessFeatureCatalog::defaults(),
            'latest'=>$rows?$this->decode($rows[0]):null,'history'=>array_map(fn(array $r):array=>$this->decode($r),$rows),
            'boundaries'=>['external_write_count'=>0,'settings_change_permissions'=>false,'automatic_execution'=>false]];
    }
    public function save(array $scope,array $input,string $key,int $actor,int $expectedId):array
    {
        if($actor<=0||($scope['kind']==='configuration'&&$scope['owner_user_id']!==$actor)||!preg_match('/^[A-Za-z0-9_-]{8,100}$/D',$key)) throw new InvalidArgumentException('business_workspace_request_invalid');
        $body=$scope['kind']==='configuration'?BusinessFeatureCatalog::normalize($input):$this->note($scope['kind'],$input);
        $payload=['contract_version'=>self::CONTRACT,'scope'=>$scope,'inputs'=>$body,
            'source_quality'=>$scope['kind']==='configuration'?'configuration_only':'manual_unverified','external_write_count'=>0];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($json)>100000) throw new InvalidArgumentException('business_workspace_payload_too_large');
        $digest=hash('sha256',$json);
        return Db::transaction(function()use($scope,$key,$actor,$expectedId,$json,$digest):array{
            if(!Db::name('hotels')->where('id',$scope['hotel_id'])->where('tenant_id',$scope['tenant_id'])->lock(true)->find())throw new RuntimeException('business_workspace_forbidden',403);
            $existing=Db::name(self::TABLE)->where($scope)->where('idempotency_key',$key)->lock(true)->find();
            if($existing){if((int)$existing['created_by']!==$actor||!hash_equals($existing['content_digest'],$digest))throw new RuntimeException('business_workspace_idempotency_conflict',409);return $this->decode($existing)+['idempotent'=>true];}
            $latest=Db::name(self::TABLE)->where($scope)->order('id','desc')->lock(true)->find();
            if((int)($latest['id']??0)!==$expectedId)throw new RuntimeException('business_workspace_version_conflict',409);
            $id=(int)Db::name(self::TABLE)->insertGetId($scope+['source_hotel_id'=>$scope['hotel_id'],'previous_id'=>$expectedId,'created_by'=>$actor,
                'idempotency_key'=>$key,'payload_json'=>$json,'content_digest'=>$digest,
                'created_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s')]);
            $saved=$this->read($scope,$id);
            if(!hash_equals($digest,$saved['content_digest']))throw new RuntimeException('business_workspace_readback_failed',409);
            return $saved+['idempotent'=>false];
        });
    }
    public function read(array $scope,int $id):array
    {
        $row=Db::name(self::TABLE)->where($scope)->where('id',$id)->find();
        if(!$row)throw new RuntimeException('business_workspace_snapshot_not_found',404);
        return $this->decode($row);
    }
    public function mappingPreview(array $scope,int $id,array $rows):array
    {
        if($scope['kind']!=='source_mapping'||!array_is_list($rows)||count($rows)<1||count($rows)>100)throw new InvalidArgumentException('business_workspace_preview_rows_invalid');
        $saved=$this->read($scope,$id);$map=$saved['inputs']['field_mapping'];$result=[];
        foreach(['hotel_id','platform','business_date']as$key)if(!isset($map[$key]))throw new InvalidArgumentException('business_workspace_preview_scope_fields_required');
        foreach($rows as$index=>$row){
            if(!is_array($row)||count($row)>80)throw new InvalidArgumentException('business_workspace_preview_row_invalid');$record=[];
            foreach($map as$key=>$column){$value=$row[$column]??null;if($value!==null&&!is_scalar($value))throw new InvalidArgumentException('business_workspace_preview_value_invalid');$record[$key]=$value;}
            $sourceHotel=$record['hotel_id']??null;
            if((!is_int($sourceHotel)&&!(is_string($sourceHotel)&&preg_match('/^[1-9][0-9]*$/D',$sourceHotel)))||(string)$sourceHotel!==(string)$scope['hotel_id'])throw new RuntimeException('business_workspace_preview_hotel_mismatch',403);
            if(!in_array($record['platform']??null,['ctrip','meituan','whole_hotel'],true))throw new InvalidArgumentException('business_workspace_preview_platform_invalid');
            $this->date((string)($record['business_date']??''));
            if($record['business_date']<$saved['inputs']['period_start']||$record['business_date']>$saved['inputs']['period_end'])throw new InvalidArgumentException('business_workspace_preview_date_outside_period');
            $result[]=['row_no'=>$index+1,'record'=>$record,'source_ref'=>$saved['inputs']['source_ref'],'source_quality'=>'manual_unverified'];
        }
        return ['contract_version'=>'business_source_mapping_preview.v1','scope'=>$scope,'mapping_snapshot_id'=>$id,
            'mapping_digest'=>$saved['content_digest'],'rows'=>$result,'status'=>'reference_only','external_write_count'=>0,
            'ota_fact_created'=>false,'raw_source_rows_retained'=>false];
    }
    private function decode(array $row):array
    {
        $json=(string)$row['payload_json'];
        if(!hash_equals((string)$row['content_digest'],hash('sha256',$json)))throw new RuntimeException('business_workspace_integrity_failed',409);
        $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        foreach(['tenant_id','owner_user_id','kind']as$key)if((string)($payload['scope'][$key]??'')!==(string)$row[$key])throw new RuntimeException('business_workspace_scope_mismatch',409);
        if((int)($payload['scope']['hotel_id']??0)!==(int)($row['source_hotel_id']??$row['hotel_id']))throw new RuntimeException('business_workspace_scope_mismatch',409);
        $currentScope=$payload['scope'];$currentScope['hotel_id']=(int)$row['hotel_id'];
        return array_replace($payload,['scope'=>$currentScope,'source_scope'=>$payload['scope'],
            'snapshot_id'=>(int)$row['id'],'previous_id'=>(int)$row['previous_id'],'created_at'=>$row['created_at'],
            'created_by'=>(int)$row['created_by'],'content_digest'=>$row['content_digest'],'readback_verified'=>true]);
    }
    private function note(string $kind,array $input):array
    {
        $start=$this->date((string)($input['period_start']??''));$end=$this->date((string)($input['period_end']??''));
        if($end<$start||((new DateTimeImmutable($end))->getTimestamp()-(new DateTimeImmutable($start))->getTimestamp())>31*86400)throw new InvalidArgumentException('business_workspace_period_invalid');
        $title=$this->text($input['title']??'',200,true);$body=$this->text($input['review_note']??'',5000,true);
        $source=$this->text($input['source_ref']??'',300,true);
        if(preg_match('/(?:https?:\/\/\S*\?\S*(?:token|password|cookie|secret)=|authorization\s*:|bearer\s+[A-Za-z0-9])/i',$source.$body))throw new InvalidArgumentException('business_workspace_secret_not_allowed');
        $facts=$input['source_references']??[];
        if(!is_array($facts)||!array_is_list($facts)||count($facts)>30)throw new InvalidArgumentException('business_workspace_references_invalid');
        $refs=array_map(fn($r):string=>$this->text($r,160,true),$facts);
        $actions=$input['actions']??[];
        if(!is_array($actions)||!array_is_list($actions)||count($actions)>20)throw new InvalidArgumentException('business_workspace_actions_invalid');
        $items=[];foreach($actions as$action){if(!is_array($action))throw new InvalidArgumentException('business_workspace_action_invalid');$items[]=[
            'measure'=>$this->text($action['measure']??'',500,true),'owner'=>$this->text($action['owner']??'',100,true),
            'due_date'=>$this->date((string)($action['due_date']??'')),'evidence_ref'=>$this->text($action['evidence_ref']??'',300,false),
            'status'=>in_array($action['status']??'', ['planned','in_progress','completed','blocked'],true)?$action['status']:throw new InvalidArgumentException('business_workspace_action_status_invalid')];
            if($items[array_key_last($items)]['status']==='completed'&&$items[array_key_last($items)]['evidence_ref']==='')throw new InvalidArgumentException('business_workspace_completed_action_requires_evidence');}
        $result=['period_start'=>$start,'period_end'=>$end,'title'=>$title,'review_note'=>$body,'source_ref'=>$source,
            'source_references'=>$refs,'actions'=>$items,'human_review_status'=>'pending','facts_independently_verified'=>false];
        if($kind==='source_mapping'){
            $map=$input['field_mapping']??[];if(!is_array($map)||array_is_list($map)||count($map)>50)throw new InvalidArgumentException('business_workspace_mapping_invalid');
            $allowed=['hotel_id','platform','business_date','rooms_available','rooms_sold','room_nights','room_revenue','ad_spend','ad_attributed_revenue','order_gmv','commission','cancellations','settlement_revenue','net_revenue'];
            $normalized=[];foreach($map as$key=>$value){if(!in_array($key,$allowed,true))throw new InvalidArgumentException('business_workspace_mapping_field_invalid');$normalized[$key]=$this->text($value,100,true);}
            $result['field_mapping']=$normalized;$result['adapter_status']='manual_mapping_not_remote_connection';
        }
        return $result;
    }
    private function text(mixed $value,int $max,bool $required):string
    {
        if(!is_string($value)||strlen($value)>$max*4)throw new InvalidArgumentException('business_workspace_text_invalid');
        $value=trim($value);if(($required&&$value==='')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new InvalidArgumentException('business_workspace_text_invalid');return $value;
    }
    private function date(string $value):string
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Shanghai'));
        if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('business_workspace_date_invalid');return $value;
    }
}
