<?php
declare(strict_types=1);

namespace app\model\base;

use app\model\Hotel;
use app\model\User;
use app\service\TenantContext;
use think\db\BaseQuery;
use think\exception\HttpException;
use think\facade\Db;
use think\Model;
use think\Request;

abstract class BaseTenantModel extends Model
{
    private const TENANT_SCOPE = 'tenant';

    /** @var list<int> */
    private static array $trustedTenantStack = [];

    /**
     * Apply tenant isolation as a concrete query predicate so a later
     * Query::withoutScope() call cannot silently remove it.
     *
     * A missing authenticated user represents an internal system/CLI context.
     * Authenticated normal users are tenant scoped. Authenticated super
     * administrators read across tenants by default; withoutTenantScope()
     * remains an explicitly authorized semantic escape hatch.
     */
    public function db(array|null $scope = []): BaseQuery
    {
        $withoutTenantScope = $this->tenantScopeIsDisabled($scope);
        if ($withoutTenantScope) {
            $this->assertSuperAdminTenantBypass();
        }

        $query = parent::db($scope);
        if (!$withoutTenantScope) {
            $this->applyTenantScope($query);
        }

        return $query;
    }

    public static function withoutTenantScope(): BaseQuery
    {
        return (new static())->db([self::TENANT_SCOPE]);
    }

    /**
     * Keep ThinkORM's generic global-scope escape hatch from bypassing the
     * tenant boundary without the same super-administrator authorization.
     */
    public static function withoutGlobalScope(?array $scope = null): BaseQuery
    {
        return (new static())->db($scope);
    }

    /**
     * Run a credential-authenticated internal callback inside one explicit
     * tenant. This never creates an unscoped query and is safe for nested use.
     */
    public static function runInTenantScope(int $tenantId, callable $callback): mixed
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('A positive tenant id is required');
        }

        self::$trustedTenantStack[] = $tenantId;
        try {
            return $callback();
        } finally {
            array_pop(self::$trustedTenantStack);
        }
    }

    protected static function onBeforeWrite(Model $model): void
    {
        if ($model instanceof self) {
            $model->applyTenantWriteContext();
        }
    }

    private function applyTenantScope(BaseQuery $query): void
    {
        $trustedTenantId = $this->trustedTenantId();
        if ($trustedTenantId > 0) {
            $this->applyTenantPredicate($query, $trustedTenantId);
            return;
        }

        [$user, $request] = $this->resolveTenantActor();
        if (!$user instanceof User) {
            if ($request === null || $request->isCli()) {
                return;
            }

            throw new HttpException(403, 'Authenticated tenant context is required');
        }

        // A super administrator is already role-authorized for cross-tenant
        // reads. Leaving the default query unfiltered also keeps ORM relations
        // and AI management paths consistent. Writes remain validated below.
        if ($user->isSuperAdmin()) {
            return;
        }

        $tenantId = (new TenantContext($user, $request))->currentUserTenantId();
        if ($tenantId <= 0) {
            throw new HttpException(403, '当前用户缺少有效租户上下文');
        }

        $this->applyTenantPredicate($query, $tenantId);
    }

    private function assertSuperAdminTenantBypass(): void
    {
        [$user] = $this->resolveTenantActor();
        if (!$user instanceof User || !$user->isSuperAdmin()) {
            throw new HttpException(403, '仅超级管理员可跨租户查询');
        }
    }

    private function applyTenantWriteContext(): void
    {
        [$user, $request] = $this->resolveTenantActor();
        $data = $this->getData();
        $hotelId = $this->tenantHotelId();
        $hasHotelScopeField = array_key_exists('hotel_id', $data) || array_key_exists('system_hotel_id', $data);
        if ($hasHotelScopeField && $hotelId <= 0) {
            throw new HttpException(422, 'A positive hotel scope is required');
        }
        $explicitTenantId = (int)($data['tenant_id'] ?? 0);
        $trustedTenantId = $this->trustedTenantId();
        $originalTenantId = $this->isExists() ? (int)$this->getOrigin('tenant_id') : 0;

        if ($originalTenantId > 0 && $explicitTenantId !== $originalTenantId) {
            throw new HttpException(403, 'Existing records cannot change tenant ownership');
        }

        if ($trustedTenantId > 0) {
            if ($hotelId > 0) {
                $hotelTenantId = $this->resolveHotelTenantId($hotelId, null);
                if ($hotelTenantId <= 0 || $hotelTenantId !== $trustedTenantId) {
                    throw new HttpException(403, 'Hotel does not belong to the trusted tenant context');
                }
            }
            if ($explicitTenantId > 0 && $explicitTenantId !== $trustedTenantId) {
                throw new HttpException(403, 'Explicit tenant does not match trusted tenant context');
            }
            $this->setAttr('tenant_id', $trustedTenantId);
            return;
        }

        if (!$user instanceof User) {
            if ($request !== null && !$request->isCli()) {
                throw new HttpException(403, 'Authenticated tenant context is required');
            }

            if ($hotelId > 0) {
                $hotelTenantId = $this->resolveHotelTenantId($hotelId, null);
                if ($hotelTenantId <= 0 || ($explicitTenantId > 0 && $explicitTenantId !== $hotelTenantId)) {
                    throw new \RuntimeException('Unable to resolve a consistent tenant for model write');
                }
                $this->setAttr('tenant_id', $hotelTenantId);
                return;
            }
            if ($explicitTenantId > 0) {
                $this->setAttr('tenant_id', $explicitTenantId);
                return;
            }

            throw new \RuntimeException('A positive tenant id or authoritative hotel mapping is required');
        }

        $tenantId = (new TenantContext($user, $request))->currentUserTenantId();
        if ($user->isSuperAdmin()) {
            if ($hotelId > 0) {
                $hotelTenantId = $this->resolveHotelTenantId($hotelId, $user);
                if ($hotelTenantId <= 0) {
                    throw new HttpException(422, 'Hotel tenant mapping is required');
                }
                if ($explicitTenantId > 0 && $explicitTenantId !== $hotelTenantId) {
                    throw new HttpException(403, 'Explicit tenant does not match hotel tenant');
                }
                $this->setAttr('tenant_id', $hotelTenantId);
                return;
            }

            if ($explicitTenantId <= 0) {
                throw new HttpException(422, 'A positive tenant id or authoritative hotel mapping is required');
            }

            $this->setAttr('tenant_id', $explicitTenantId);
            return;
        }

        if ($tenantId <= 0) {
            throw new HttpException(403, 'Authenticated tenant context is required');
        }

        if ($hotelId > 0) {
            $hotelTenantId = $this->resolveHotelTenantId($hotelId, $user);
            if ($hotelTenantId <= 0 || $hotelTenantId !== $tenantId) {
                throw new HttpException(403, 'Hotel does not belong to the authenticated tenant');
            }
        }

        // The authenticated user is the source of truth for normal writes.
        $this->setAttr('tenant_id', $tenantId);
    }

    private function tenantHotelId(): int
    {
        $data = $this->getData();
        // OTA rows may carry both fields: system_hotel_id is the SUXIOS hotel
        // identity, while hotel_id can be the platform's external hotel id.
        foreach (['system_hotel_id', 'hotel_id'] as $field) {
            $hotelId = (int)($data[$field] ?? 0);
            if ($hotelId > 0) {
                return $hotelId;
            }
        }

        return 0;
    }

    private function resolveHotelTenantId(int $hotelId, ?User $user): int
    {
        try {
            if (!$user instanceof User) {
                return (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
            }

            $query = $user->isSuperAdmin()
                ? Hotel::withoutTenantScope()
                : Hotel::where([]);

            return (int)$query->where('id', $hotelId)->value('tenant_id');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function trustedTenantId(): int
    {
        if (self::$trustedTenantStack === []) {
            return 0;
        }

        return (int)self::$trustedTenantStack[array_key_last(self::$trustedTenantStack)];
    }

    private function applyTenantPredicate(BaseQuery $query, int $tenantId): void
    {
        $table = $query->getTable(true);
        $tenantField = is_string($table) && $table !== ''
            ? $table . '.tenant_id'
            : 'tenant_id';
        $query->where($tenantField, $tenantId);
    }

    /** @return array{0:?User,1:?Request} */
    private function resolveTenantActor(): array
    {
        try {
            $request = request();
        } catch (\Throwable) {
            return [null, null];
        }

        if (!$request instanceof Request) {
            return [null, null];
        }

        $user = $request->user ?? null;
        return [$user instanceof User ? $user : null, $request];
    }

    private function tenantScopeIsDisabled(?array $scope): bool
    {
        if (!is_array($scope)) {
            return false;
        }

        foreach ($scope as $name) {
            if (strtolower(trim((string)$name)) === self::TENANT_SCOPE) {
                return true;
            }
        }

        return false;
    }
}
