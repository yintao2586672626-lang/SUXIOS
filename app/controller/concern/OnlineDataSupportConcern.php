<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\OtaReviewRiskPolicyService;
use think\Response;

trait OnlineDataSupportConcern
{
    private function shouldVerifyOtaSsl(): bool
    {
        $value = env('OTA_SSL_VERIFY', true);
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'no', 'off'], true);
    }

    private function shouldLogOtaDebug(): bool
    {
        $value = env('OTA_DEBUG_LOG', false);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function commentCollectionDisabledResponse(): Response
    {
        return $this->error('Comment/review data collection is disabled by policy.', 422, [
            'disabled' => true,
            'scope' => 'ota_comments',
        ]);
    }

    /**
     * @param array<int, string> $riskCategories
     */
    private function reviewRiskPolicyBlockedResponse(string $operation, array $riskCategories = []): Response
    {
        $payload = (new OtaReviewRiskPolicyService())->blockedOperation($operation, $riskCategories);

        return $this->error((string)$payload['message'], 422, $payload);
    }

    private function buildStreamSslOptions(): array
    {
        $verify = $this->shouldVerifyOtaSsl();
        return [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
        ];
    }

    private function safeHttpCode(int $code): int
    {
        return $code >= 400 && $code <= 599 ? $code : 400;
    }

    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        // 非超级管理员必须有关联酒店。
        $this->requireHotel();
    }

    private function checkActionPermission(string $permission): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        if ($this->currentUser->isSuperAdmin()) {
            return;
        }
        if (!$this->currentUser->hasPermission($permission)) {
            abort(403, '无权限操作');
        }
    }
}
