<?php
declare(strict_types=1);
namespace app\service;
use app\model\Hotel;
use app\model\OtaCredential;
use RuntimeException;

final class OtaCredentialVault
{
    public function __construct(private readonly ?OtaCredentialEnvelope $envelope=null, private readonly ?string $keyId=null)
    { if ($this->envelope===null) $this->envelope=new OtaCredentialEnvelope((string)env('OTA_CREDENTIAL_KEY_B64',''),(string)env('OTA_CREDENTIAL_KEY_ID','')); }
    private function scope(int $t,int $h,string $p,string $c): string { $this->validate($p,$c); return "tenant:$t:hotel:$h:$p:$c"; }
    private function validate(string $p,string $c): void { if (!in_array($p,['ctrip','meituan'],true) || preg_match('/^[A-Za-z0-9._-]{1,100}$/D',$c)!==1) throw new RuntimeException('Invalid credential locator.'); }
    private function locate(int $t,int $h,string $p,string $c): OtaCredential { $this->scope($t,$h,$p,$c); if (!Hotel::where('id',$h)->where('tenant_id',$t)->find()) throw new RuntimeException('Hotel scope not found.'); $r=OtaCredential::where('tenant_id',$t)->where('system_hotel_id',$h)->where('platform',$p)->where('config_id',$c)->find(); if (!$r) throw new RuntimeException('Credential not found.'); if ((string)$r->credential_status==='revoked') throw new RuntimeException('Credential revoked.'); return $r; }
    public function store(int $tenantId,int $hotelId,string $platform,string $configId,array $payload,int $actorId): array
    { $scope=$this->scope($tenantId,$hotelId,$platform,$configId); if (!Hotel::where('id',$hotelId)->where('tenant_id',$tenantId)->find()) throw new RuntimeException('Hotel scope not found.');  $now=date('Y-m-d H:i:s'); $mask=''; foreach(['cookies','cookie','token','spidertoken','mtgsig'] as $k) if (!empty($payload[$k])) { $s=(string)$payload[$k]; $mask=substr($s,0,2).'****'; break; } $data=['tenant_id'=>$tenantId,'system_hotel_id'=>$hotelId,'platform'=>$platform,'config_id'=>$configId,'encrypted_payload'=>$this->envelope->encrypt($payload,$scope),'payload_version'=>1,'key_id'=>(string)($this->keyId ?? env('OTA_CREDENTIAL_KEY_ID','')),'secret_mask'=>$mask,'credential_status'=>'ready','created_by'=>$actorId,'rotated_at'=>$now,'update_time'=>$now]; $r=OtaCredential::where('tenant_id',$tenantId)->where('system_hotel_id',$hotelId)->where('platform',$platform)->where('config_id',$configId)->find(); if($r){$r->save($data);} else {$data['create_time']=$now; $r=OtaCredential::create($data);} return $this->meta($r); }
    private function meta(OtaCredential $r): array { return ['credential_ref'=>(int)$r->id,'tenant_id'=>(int)$r->tenant_id,'system_hotel_id'=>(int)$r->system_hotel_id,'platform'=>(string)$r->platform,'config_id'=>(string)$r->config_id,'payload_version'=>(int)$r->payload_version,'key_id'=>(string)$r->key_id,'secret_mask'=>(string)$r->secret_mask,'credential_status'=>(string)$r->credential_status,'rotated_at'=>$r->rotated_at,'create_time'=>$r->create_time,'update_time'=>$r->update_time]; }
    public function metadata(int $t,int $h,string $p,string $c): array { return $this->meta($this->locate($t,$h,$p,$c)); }
    public function withPayloadForExecution(int $t,int $h,string $p,string $c,callable $consumer): mixed { $r=$this->locate($t,$h,$p,$c); return $consumer($this->envelope->decrypt((string)$r->encrypted_payload,$this->scope($t,$h,$p,$c))); }
    public function revoke(int $t,int $h,string $p,string $c): array { $r=$this->locate($t,$h,$p,$c); $r->credential_status='revoked'; $r->save(); return $this->meta($r); }
    public function delete(int $t,int $h,string $p,string $c): bool { $r=$this->locate($t,$h,$p,$c); return (bool)$r->delete(); }
}



