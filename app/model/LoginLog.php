<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 登录日志模型
 * 记录用户登录、登出等操作
 */
class LoginLog extends Model
{
    protected $name = 'login_logs';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'created_at';
    protected $updateTime = false;
    
    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'client_info' => 'json',
    ];

    // 操作类型
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_REFRESH = 'refresh';
    
    // 状态
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';

    /**
     * 记录登录日志
     * 
     * @param int|null $userId 用户ID
     * @param string $username 用户名
     * @param string $action 操作类型
     * @param string $status 状态
     * @param string|null $message 消息/失败原因
     * @param string $ip IP地址
     * @param string $userAgent 用户代理
     * @param array $clientInfo 客户端信息
     * @return bool
     */
    public static function record(
        ?int $userId,
        string $username,
        string $action,
        string $status,
        ?string $message = null,
        string $ip = '',
        string $userAgent = '',
        array $clientInfo = []
    ): bool {
        try {
            $log = new self();
            $log->user_id = $userId;
            $log->username = $username;
            $log->action = $action;
            $log->status = $status;
            $log->message = $message;
            $log->ip_address = $ip;
            $log->user_agent = substr($userAgent, 0, 500);
            $log->client_info = $clientInfo ?: null;
            return $log->save();
        } catch (\Exception $e) {
            // 记录日志失败不影响主流程
            return false;
        }
    }

    /**
     * 获取用户最近的登录记录
     * 
     * @param int $userId 用户ID
     * @param int $limit 数量限制
     * @return array
     */
    public static function getRecentByUser(int $userId, int $limit = 10): array
    {
        return self::where('user_id', $userId)
            ->order('created_at', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取用户最后登录记录
     * 
     * @param int $userId 用户ID
     * @return array|null
     */
    public static function getLastLogin(int $userId): ?array
    {
        return self::where('user_id', $userId)
            ->where('action', self::ACTION_LOGIN)
            ->where('status', self::STATUS_SUCCESS)
            ->order('created_at', 'desc')
            ->find()
            ?->toArray();
    }
}