<?php
declare(strict_types=1);
namespace app\model;
use think\Model;
class OtaCredential extends Model
{
    protected $name='ota_credentials';
    protected $hidden=['encrypted_payload'];
    protected $autoWriteTimestamp=true;
    protected $createTime='create_time'; protected $updateTime='update_time';
    protected $type=['id'=>'integer','tenant_id'=>'integer','system_hotel_id'=>'integer','payload_version'=>'integer','created_by'=>'integer','rotated_at'=>'integer'];
}
