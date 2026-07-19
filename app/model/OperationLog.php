<?php
declare(strict_types=1);

namespace app\model;

use app\service\OperationAuditSanitizerService;
use think\facade\Db;
use think\Model;

class OperationLog extends Model
{
    protected $name = 'operation_logs';
    
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;
    
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
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
        $sanitizer = new OperationAuditSanitizerService();
        $tenantId = self::resolveTenantId($hotelId, $userId, $extraData);
        $requestContext = self::requestAuditContext($sanitizer);
        $outcome = strtolower(trim((string)($extraData['outcome'] ?? '')));
        if (!in_array($outcome, ['success', 'failed', 'denied', 'partial'], true)) {
            $outcome = $errorInfo !== null && trim($errorInfo) !== '' ? 'failed' : 'success';
        }
        $safeExtraData = $sanitizer->sanitizeArray($extraData, 1000);
        $auditEnvelope = [
            'audit_schema_version' => 1,
            'outcome' => $outcome,
            'actor_user_id' => $userId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
        ];
        if (isset($requestContext['request_id'])) {
            $auditEnvelope['request_id'] = $requestContext['request_id'];
        }
        $safeExtraData = array_replace($safeExtraData, $auditEnvelope);

        $log = new self();
        $log->user_id = $userId;
        $log->hotel_id = $hotelId;
        if (self::hasColumn('tenant_id')) {
            $log->tenant_id = $tenantId;
        }
        $log->module = $sanitizer->sanitizeText($module, 50);
        $log->action = $sanitizer->sanitizeText($action, 50);
        $log->description = $sanitizer->sanitizeText($description, 500);
        $safeErrorInfo = $errorInfo !== null ? $sanitizer->sanitizeText($errorInfo, 2000) : '';
        $log->error_info = $safeErrorInfo !== '' ? $safeErrorInfo : null;
        $log->extra_data = json_encode(
            $safeExtraData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $log->ip = (string)($requestContext['source_ip'] ?? '');
        $log->user_agent = (string)($requestContext['user_agent'] ?? '');
        $log->save();
        
        return $log;
    }

    private static function hasColumn(string $column): bool
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM operation_logs');
            return in_array($column, array_map(
                static fn(array $row): string => (string)($row['Field'] ?? ''),
                $rows
            ), true);
        } catch (\Throwable) {
            try {
                $rows = Db::query('PRAGMA table_info(`operation_logs`)');
                return in_array($column, array_map(
                    static fn(array $row): string => (string)($row['name'] ?? ''),
                    $rows
                ), true);
            } catch (\Throwable) {
                return false;
            }
        }
    }

    /** @param array<string, mixed> $extraData */
    private static function resolveTenantId(?int $hotelId, ?int $userId, array $extraData): ?int
    {
        $explicitTenantId = (int)($extraData['tenant_id'] ?? 0);
        if ($hotelId !== null && $hotelId > 0) {
            try {
                $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
                if ($tenantId > 0) {
                    return $tenantId;
                }
            } catch (\Throwable) {
                // Legacy schemas may not have hotels.tenant_id yet.
            }
            if ($explicitTenantId > 0) {
                return $explicitTenantId;
            }
            return $hotelId;
        }

        if ($explicitTenantId > 0) {
            return $explicitTenantId;
        }

        if ($userId !== null && $userId > 0) {
            try {
                $user = Db::name('users')->where('id', $userId)->find();
                $tenantId = (int)($user['tenant_id'] ?? 0);
                if ($tenantId > 0) {
                    return $tenantId;
                }
                $fallbackHotelId = (int)($user['hotel_id'] ?? 0);
                return $fallbackHotelId > 0 ? $fallbackHotelId : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function requestAuditContext(OperationAuditSanitizerService $sanitizer): array
    {
        try {
            $request = request();
            $requestId = trim((string)($request->request_id ?? ''));
            $context = [
                'source_ip' => $sanitizer->sanitizeText((string)$request->ip(), 50),
                'user_agent' => $sanitizer->sanitizeText((string)$request->header('user-agent', ''), 255),
            ];
            if ($requestId !== '') {
                $context['request_id'] = $sanitizer->sanitizeText($requestId, 80);
            }
            return $context;
        } catch (\Throwable) {
            return ['source_ip' => '', 'user_agent' => ''];
        }
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
