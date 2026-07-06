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
    public function blockedOperation(string $operation, array $riskCategories = [], array $context = []): array
    {
        $categories = $riskCategories === []
            ? ['identity_reverse_lookup', 'phone_acquisition', 'anonymous_user_matching', 'review_evasion', 'negative_review_covering']
            : array_values(array_unique(array_filter($riskCategories, static fn(string $item): bool => trim($item) !== '')));
        $governance = $this->reviewGovernanceRuleChecks($context, $categories, $operation);

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
            'governance_policy_doc' => 'docs/ctrip_review_governance_rules_20260705.md',
            'governance_summary_status' => $governance['summary_status'],
            'governance_status_codes' => $governance['status_codes'],
            'governance_rule_checks' => $governance['checks'],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, string> $riskCategories
     * @return array{summary_status:string,status_codes:array<int, string>,checks:array<int, array<string, mixed>>}
     */
    public function reviewGovernanceRuleChecks(array $context = [], array $riskCategories = [], string $operation = ''): array
    {
        $checks = [
            $this->expired90dCheck($context),
            $this->thirdPartyDisplayOnlyCheck($context, $operation),
            $this->privacyNotQueryableCheck($riskCategories, $operation),
            $this->replyContainsContactCheck($context),
        ];
        $statusCodes = [];
        $hasPass = false;
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'active') {
                $statusCodes[] = (string)$check['code'];
            }
            if (($check['status'] ?? '') === 'pass') {
                $hasPass = true;
            }
        }

        return [
            'summary_status' => $statusCodes !== [] ? 'blocked' : ($hasPass ? 'pass' : 'not_evaluated'),
            'status_codes' => array_values(array_unique($statusCodes)),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function expired90dCheck(array $context): array
    {
        $review = $this->contextArray($context, 'review');
        $order = $this->contextArray($context, 'order');
        $departureDate = $this->firstDate([$order, $review, $context], [
            'departureDate',
            'departure_date',
            'checkOutDate',
            'check_out_date',
            'checkoutTime',
            'checkout_time',
            'leaveDate',
            'leave_date',
        ]);
        if ($departureDate === '') {
            return $this->ruleCheck('expired_90d', 'not_evaluated', 'info', 'No checkout/departure date was provided, so the 90-day review/reply window was not evaluated.');
        }

        $referenceDate = $this->firstDate([$context, $review], [
            'reference_date',
            'referenceDate',
            'reply_date',
            'replyDate',
            'review_date',
            'reviewDate',
            'publishTime',
            'publish_time',
            'commentTime',
            'comment_time',
            'date',
        ]);
        if ($referenceDate === '') {
            $referenceDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        }

        $deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $departureDate, new \DateTimeZone('Asia/Shanghai'));
        $reference = \DateTimeImmutable::createFromFormat('!Y-m-d', $referenceDate, new \DateTimeZone('Asia/Shanghai'));
        if ($deadline === false || $reference === false) {
            return $this->ruleCheck('expired_90d', 'not_evaluated', 'info', 'The provided date could not be normalized.');
        }
        $deadline = $deadline->modify('+90 days');
        $expired = $reference > $deadline;

        return $this->ruleCheck(
            'expired_90d',
            $expired ? 'active' : 'pass',
            $expired ? 'warning' : 'info',
            $expired
                ? 'The review/reply window is over 90 days after checkout; do not treat it as a new review or reply window.'
                : 'The checkout-based 90-day window has not expired for the provided reference date.',
            [
                'departure_date' => $departureDate,
                'reference_date' => $referenceDate,
                'window_deadline' => $deadline->format('Y-m-d'),
            ]
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function thirdPartyDisplayOnlyCheck(array $context, string $operation): array
    {
        $review = $this->contextArray($context, 'review');
        $isCtripContext = str_contains(strtolower($operation), 'ctrip')
            || in_array(strtolower($this->firstText([$context, $review], ['platform', 'source_platform', 'sourcePlatform'])), ['ctrip', 'trip'], true);
        if (!$isCtripContext) {
            return $this->ruleCheck('third_party_display_only', 'not_evaluated', 'info', 'This Ctrip/Trip third-party display rule was not evaluated for the current platform context.');
        }

        $origin = strtolower($this->firstText([$review, $context], ['review_origin', 'reviewOrigin', 'origin', 'source_origin', 'sourceOrigin']));
        $mode = strtolower($this->firstText([$review, $context], ['third_party_mode', 'thirdPartyMode', 'score_count_mode', 'scoreCountMode']));
        $sourcePlatform = strtolower($this->firstText([$review, $context], ['source_platform', 'sourcePlatform', 'review_source_platform', 'reviewSourcePlatform']));
        $hasEvidence = $origin !== '' || $mode !== '' || $sourcePlatform !== '';
        if (!$hasEvidence) {
            return $this->ruleCheck('third_party_display_only', 'not_evaluated', 'info', 'No review origin or third-party display mode was provided.');
        }

        $thirdPartySources = ['third_party', 'thirdparty', 'qunar', 'tongcheng', 'elong', 'zhixing', 'other'];
        $active = in_array($origin, ['third_party', 'thirdparty'], true)
            || in_array($mode, ['display_only', 'score_reference'], true)
            || in_array($sourcePlatform, $thirdPartySources, true);

        return $this->ruleCheck(
            'third_party_display_only',
            $active ? 'active' : 'pass',
            $active ? 'warning' : 'info',
            $active
                ? 'The review is third-party display-only; do not force it into Ctrip-owned order matching or Ctrip score attribution.'
                : 'The provided review origin does not indicate third-party display-only.',
            [
                'review_origin' => $origin,
                'third_party_mode' => $mode,
                'source_platform' => $sourcePlatform,
            ]
        );
    }

    /**
     * @param array<int, string> $riskCategories
     * @return array<string, mixed>
     */
    private function privacyNotQueryableCheck(array $riskCategories, string $operation): array
    {
        $privacyRisks = ['identity_reverse_lookup', 'anonymous_user_matching', 'masked_data_reconstruction_risk', 'phone_acquisition'];
        $matched = array_values(array_intersect($privacyRisks, $riskCategories));
        $operationText = strtolower($operation);
        $active = $matched !== []
            || str_contains($operationText, 'identity')
            || str_contains($operationText, 'order_lookup')
            || str_contains($operationText, 'phone');

        return $this->ruleCheck(
            'privacy_not_queryable',
            $active ? 'active' : 'pass',
            $active ? 'blocked' : 'info',
            $active
                ? 'Ctrip does not provide a merchant-side query for who wrote an anonymous or masked review; the system must not expose reverse lookup.'
                : 'No privacy reverse-query operation was detected in this request.',
            ['risk_categories' => $matched]
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function replyContainsContactCheck(array $context): array
    {
        $reply = $this->contextArray($context, 'reply');
        $replyText = $this->firstText([$reply, $context], ['reply_text', 'replyText', 'reply', 'content', 'text', 'message']);
        if ($replyText === '') {
            return $this->ruleCheck('reply_contains_contact', 'not_evaluated', 'info', 'No reply text was provided for contact-information validation.');
        }

        $hasContact = (bool)preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $replyText)
            || (bool)preg_match('/https?:\/\/|www\./i', $replyText)
            || (bool)preg_match('/(?<!\d)(?:\+?\d[\d \-]{6,}\d)(?!\d)/', $replyText)
            || (bool)preg_match('/wechat|weixin|vx|qq|phone|email|mobile|微信|电话|手机号|手机|邮箱|公众号|微博|二维码|联系/ui', $replyText);

        return $this->ruleCheck(
            'reply_contains_contact',
            $hasContact ? 'active' : 'pass',
            $hasContact ? 'blocked' : 'info',
            $hasContact
                ? 'The reply contains contact information and should be revised before use.'
                : 'No contact information was detected in the provided reply text.'
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function contextArray(array $context, string $key): array
    {
        return isset($context[$key]) && is_array($context[$key]) ? $context[$key] : [];
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $fields
     */
    private function firstText(array $sources, array $fields): string
    {
        foreach ($sources as $source) {
            foreach ($fields as $field) {
                if (isset($source[$field]) && trim((string)$source[$field]) !== '') {
                    return trim((string)$source[$field]);
                }
            }
        }
        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $fields
     */
    private function firstDate(array $sources, array $fields): string
    {
        $value = $this->firstText($sources, $fields);
        if ($value === '') {
            return '';
        }
        if (preg_match('/(20\d{2})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function ruleCheck(string $code, string $status, string $severity, string $message, array $evidence = []): array
    {
        return [
            'code' => $code,
            'status' => $status,
            'severity' => $severity,
            'message' => $message,
            'scope' => 'ctrip_ota_channel',
            'source_doc' => 'docs/ctrip_review_governance_rules_20260705.md',
            'evidence' => $evidence,
        ];
    }
}
