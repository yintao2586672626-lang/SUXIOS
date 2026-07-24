<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use think\Request;

final class TenantContext
{
    public function __construct(
        private ?User $currentUser = null,
        private ?Request $currentRequest = null
    ) {
    }

    public function currentUserTenantId(?User $user = null): int
    {
        $user ??= $this->currentUser;
        return $this->positiveInt($user?->tenant_id ?? null);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    public function currentRequestTenantId(?array $params = null): int
    {
        if ($params === null) {
            $params = $this->currentRequest?->param() ?? [];
        }

        return $this->positiveInt($params['tenant_id'] ?? null);
    }

    private function positiveInt($value): int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
    }
}
