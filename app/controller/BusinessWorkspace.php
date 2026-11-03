<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BusinessWorkspaceService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use think\facade\Db;
use Throwable;

final class BusinessWorkspace extends Base
{
    public function overview():Response
    {
        try{$scope=$this->scope($this->request->param(),'operation.view');return $this->success((new BusinessWorkspaceService())->overview($scope));}
        catch(Throwable $error){return $this->failure($error);}
    }
    public function save():Response
    {
        try{$input=$this->requestData();$scope=$this->scope($input,'operation.execute');
            return $this->success((new BusinessWorkspaceService())->save($scope,is_array($input['inputs']??null)?$input['inputs']:[],
                (string)($input['idempotency_key']??''),(int)$this->currentUser->id,(int)($input['expected_snapshot_id']??0)),'新版本已保存并精确回读');}
        catch(Throwable $error){return $this->failure($error);}
    }
    public function read():Response
    {
        try{$scope=$this->scope($this->request->param(),'operation.view');return $this->success((new BusinessWorkspaceService())->read($scope,(int)$this->request->param('id',0)));}
        catch(Throwable $error){return $this->failure($error);}
    }
    public function mappingPreview():Response
    {
        try{$input=$this->requestData();$input['kind']='source_mapping';$scope=$this->scope($input,'operation.view');
            return $this->success((new BusinessWorkspaceService())->mappingPreview($scope,(int)($input['snapshot_id']??0),is_array($input['rows']??null)?$input['rows']:[]));}
        catch(Throwable $error){return $this->failure($error);}
    }
    private function scope(array $input,string $capability):array
    {
        if(!$this->currentUser)throw new RuntimeException('business_workspace_login_required',401);
        $hotel=(int)($input['hotel_id']??0);$permitted=array_map('intval',(array)$this->currentUser->getPermittedHotelIds());
        if($hotel<=0||!in_array($hotel,$permitted,true)||!$this->currentUser->hasHotelPermission($hotel,$capability))throw new RuntimeException('business_workspace_forbidden',403);
        $tenant=(int)($this->currentUser->tenant_id??0);
        if(method_exists($this->currentUser,'isSuperAdmin')&&$this->currentUser->isSuperAdmin())$tenant=(int)Db::name('hotels')->where('id',$hotel)->value('tenant_id');
        return(new BusinessWorkspaceService())->scope($tenant,$permitted,$hotel,(int)$this->currentUser->id,(string)($input['kind']??'configuration'));
    }
    private function failure(Throwable $error):Response
    {
        $code=(int)$error->getCode();if(!in_array($code,[401,403,404,409,422],true))$code=$error instanceof InvalidArgumentException?422:500;
        $reason=preg_match('/^business_[a-z0-9_]+$/D',$error->getMessage())?$error->getMessage():'business_workspace_unavailable';
        $message=match($code){401=>'请先登录',403=>'当前酒店或租户不在你的权限范围',404=>'当前范围没有该版本',409=>'版本已变化或回读冲突，请重新加载后保存',422=>'请检查设置、时间窗、来源与处理证据',default=>'工作区读取或保存失败；检查数据库迁移与服务状态'};
        return$this->error($message,$code,['contract_version'=>BusinessWorkspaceService::CONTRACT,'status'=>'error','reason_code'=>$reason,'external_write_count'=>0]);
    }
}
