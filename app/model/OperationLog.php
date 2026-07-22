<?php
declare(strict_types=1);

namespace app\model;

use app\model\base\BaseTenantModel;
use app\service\OperationAuditSanitizerService;
use think\facade\Db;
use think\Model;

class OperationLog extends BaseTenantModel
{
    private static int $auditWriteDepth = 0;

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
        $tenantId = self::resolveTenantId($module, $action, $hotelId, $userId, $extraData);
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
        self::saveAuditRecord($log);
        
        return $log;
    }

    protected static function onBeforeWrite(Model $model): void
    {
        if ($model instanceof self && self::$auditWriteDepth > 0) {
            return;
        }

        parent::onBeforeWrite($model);
    }

    private static function saveAuditRecord(self $log): void
    {
        $tenantId = (int)($log->getData()['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            self::runInTenantScope($tenantId, static function () use ($log): void {
                self::$auditWriteDepth++;
                try {
                    $log->save();
                } finally {
                    self::$auditWriteDepth--;
                }
            });
            return;
        }

        // Global/system audits have no tenant predicate by definition. Keep
        // this narrow raw insert private to OperationLog::record() so an
        // anonymous HTTP request can never obtain a generic unscoped model.
        $payload = $log->getData();
        try {
            $payload = array_intersect_key($payload, Db::getFields('operation_logs'));
        } catch (\Throwable) {
            // Let the insert surface the underlying schema error when fields
            // cannot be inspected.
        }
        if (self::hasColumn('create_time') && empty($payload['create_time'])) {
            $payload['create_time'] = date('Y-m-d H:i:s');
            $log->setAttr('create_time', $payload['create_time']);
        }
        $id = (int)Db::name('operation_logs')->insertGetId($payload);
        if ($id > 0) {
            $log->setAttr('id', $id);
        }
        $log->exists(true);
    }

    /**
     * Narrow pre-authentication audit read used only to decide whether a
     * legacy token predates a password change/reset. It deliberately returns
     * a scalar timestamp instead of exposing an unscoped query builder.
     *
     * @internal
     */
    public static function latestCredentialRevocationTimestamp(
        int $targetUserId,
        int $notBeforeTimestamp
    ): int {
        if ($targetUserId <= 0) {
            return 0;
        }

        $since = date('Y-m-d H:i:s', max(0, $notBeforeTimestamp));
        $directRows = Db::name('operation_logs')
            ->where('user_id', $targetUserId)
            ->whereIn('action', ['change_password', 'reset_password'])
            ->where('create_time', '>=', $since)
            ->field('create_time')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $latest = self::latestTimestampFromRows($directRows);

        // Current reset audits retain the administrator as user_id and put
        // the affected account in extra_data.target_user_id. Scan only reset
        // actions inside the token lifetime so cross-tenant administrator
        // resets still revoke the target without opening a general bypass.
        $resetRows = Db::name('operation_logs')
            ->where('action', 'reset_password')
            ->where('create_time', '>=', $since)
            ->field('create_time,extra_data')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        foreach ($resetRows as $row) {
            $extraData = json_decode((string)($row['extra_data'] ?? ''), true);
            if (!is_array($extraData) || (int)($extraData['target_user_id'] ?? 0) !== $targetUserId) {
                continue;
            }
            $latest = max($latest, self::timestampValue($row['create_time'] ?? null));
        }

        return $latest;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function latestTimestampFromRows(array $rows): int
    {
        $latest = 0;
        foreach ($rows as $row) {
            $latest = max($latest, self::timestampValue($row['create_time'] ?? null));
        }

        return $latest;
    }

    private static function timestampValue(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_numeric($value)) {
            return max(0, (int)$value);
        }

        $timestamp = strtotime(trim((string)$value));
        return $timestamp === false ? 0 : $timestamp;
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
    private static function resolveTenantId(
        string $module,
        string $action,
        ?int $hotelId,
        ?int $userId,
        array $extraData
    ): ?int
    {
        $explicitTenantId = (int)($extraData['tenant_id'] ?? 0);
        if (self::isPrevalidatedSuperAdminHotelDeletion(
            $module,
            $action,
            $hotelId,
            $userId,
            $explicitTenantId,
            $extraData
        )) {
            return $explicitTenantId;
        }

        $tenantCandidates = [];

        if ($hotelId !== null && $hotelId > 0) {
            try {
                $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
                if ($tenantId > 0) {
                    $tenantCandidates[] = $tenantId;
                }
            } catch (\Throwable) {
                // Legacy schemas may not have hotels.tenant_id yet.
            }
        }

        if ($userId !== null && $userId > 0) {
            try {
                $user = Db::name('users')->where('id', $userId)->find();
                $tenantId = (int)($user['tenant_id'] ?? 0);
                if ($tenantId > 0) {
                    $tenantCandidates[] = $tenantId;
                }
            } catch (\Throwable) {
                // Missing user/tenant schema leaves the audit global.
            }
        }

        $tenantCandidates = array_values(array_unique(array_filter(
            array_map('intval', $tenantCandidates),
            static fn(int $tenantId): bool => $tenantId > 0
        )));

        // Live hotel/user mappings are authoritative. Explicit metadata is a
        // consistency constraint for ordinary writes and may bind a deleted
        // resource only when no live fact remains.
        if (count($tenantCandidates) === 1) {
            $resolvedTenantId = $tenantCandidates[0];
            return $explicitTenantId > 0 && $explicitTenantId !== $resolvedTenantId
                ? null
                : $resolvedTenantId;
        }
        if (count($tenantCandidates) > 1) {
            return null;
        }

        return $explicitTenantId > 0 ? $explicitTenantId : null;
    }

    /** @param array<string, mixed> $extraData */
    private static function isPrevalidatedSuperAdminHotelDeletion(
        string $module,
        string $action,
        ?int $hotelId,
        ?int $userId,
        int $explicitTenantId,
        array $extraData
    ): bool {
        if (
            strtolower(trim($module)) !== 'hotel'
            || strtolower(trim($action)) !== 'delete'
            || $hotelId === null
            || $hotelId <= 0
            || $userId === null
            || $userId <= 0
            || $explicitTenantId <= 0
            || (int)($extraData['deleted_hotel_id'] ?? 0) !== $hotelId
        ) {
            return false;
        }

        try {
            $actor = request()->user ?? null;
            return $actor instanceof User
                && (int)$actor->id === $userId
                && $actor->isSuperAdmin();
        } catch (\Throwable) {
            return false;
        }
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
