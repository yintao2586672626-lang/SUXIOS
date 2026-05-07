<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class OperationLog extends Model
{
    protected $name = 'operation_logs';
    
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;
    
    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'hotel_id' => 'integer',
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 记录日志
     * @param string $module 模块名称
     * @param string $action 操作名称
     * @param string $description 描述
     * @param int|null $userId 用户ID
     * @param int|null $hotelId 酒店ID
     * @param string|null $errorInfo 错误信息
     * @param array $extraData 额外数据
     */
    public static function record(
        string $module, 
        string $action, 
        string $description, 
        int $userId = null, 
        int $hotelId = null,
        string $errorInfo = null,
        array $extraData = []
    ): self
    {
        $log = new self();
        $log->user_id = $userId;
        $log->hotel_id = $hotelId;
        $log->module = $module;
        $log->action = $action;
        $log->description = $description;
        $log->error_info = $errorInfo;
        $log->extra_data = !empty($extraData) ? json_encode($extraData, JSON_UNESCAPED_UNICODE) : null;
        $log->ip = request()->ip();
        $log->user_agent = request()->header('user-agent', '');
        $log->save();
        
        return $log;
    }

    /**
     * 记录错误日志
     */
    public static function error(
        string $module, 
        string $action, 
        string $description, 
        string $errorInfo,
        int $userId = null, 
        int $hotelId = null
    ): self
    {
        return self::record($module, $action, $description, $userId, $hotelId, $errorInfo);
    }
}
