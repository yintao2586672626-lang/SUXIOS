<?php
declare(strict_types=1);

namespace app\service;

final class OtaReviewRiskPolicyService
{
    public const STATUS_BLOCKED = 'blocked_by_review_privacy_policy';

    /**
     * @param array<int, string> $riskCategories
     * @return array<string, mixed>
     */
    public function blockedOperation(string $operation, array $riskCategories = []): array
    {
        $categories = $riskCategories === []
            ? ['identity_reverse_lookup', 'phone_acquisition', 'anonymous_user_matching', 'review_evasion', 'negative_review_covering']
            : array_values(array_unique(array_filter($riskCategories, static fn(string $item): bool => trim($item) !== '')));

        return [
            'status' => self::STATUS_BLOCKED,
            'message' => '点评身份反查、手机号获取、匿名用户匹配、规避点评和覆盖差评只允许做风险识别，不允许作为宿析OS执行能力。',
            'operation' => $operation,
            'risk_categories' => $categories,
            'allowed_learning_mode' => 'risk_recognition_only',
            'blocked_outputs' => [
                'execution_steps',
                'automation_script',
                'selector_path',
                'enumeration_strategy',
                'bypass_method',
                'identity_resolution',
                'phone_reveal',
            ],
            'safe_redirect' => [
                'evidence_checklist',
                'service_recovery',
                'platform_appeal',
                'review_reply',
                'quality_improvement',
                'score_impact_observation',
            ],
            'data_policy' => [
                'minimum_necessary',
                'desensitized',
                'platform_authorized_only',
            ],
            'scope' => 'ota_channel_review_risk',
            'storage_write' => false,
            'business_metric' => false,
            'source_status' => 'user_provided_unverified',
            'policy_doc' => 'docs/ota_all_channel_review_method_20260701.md',
        ];
    }
}
