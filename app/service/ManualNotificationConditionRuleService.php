<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Evaluates persisted notification business rules against the exact candidate
 * facts that are about to be delivered. Observations may be recorded before a
 * send, but the deduplication bucket advances only after an exact successful
 * delivery receipt.
 */
final class ManualNotificationConditionRuleService
{
    public const ALWAYS = 'always';
    public const OCCUPANCY_LADDER = 'occupancy_ladder';
    public const FULL_HOUSE = 'full_house';
    public const STATE_TABLE = 'manual_notification_rule_states';
    public const TRIGGER_CLAIM_LEASE_SECONDS = 180;

    private const SUPPORTED_FACT_TEMPLATES = [
        ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
        ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
        'today_revenue_management',
        'daily_review',
    ];

    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'key' => self::ALWAYS,
                'label' => '到点即发送',
                'description' => '沿用原计划：时间规则命中且数据门禁通过后发送。',
                'threshold_required' => false,
                'step_required' => false,
                'requires_pms_facts' => false,
                'supported_template_types' => [],
            ],
            [
                'key' => self::OCCUPANCY_LADDER,
                'label' => '入住率跨档提醒',
                'description' => '达到起始入住率后，每跨一个新档位发送一次；回落后重回旧档不重复。',
                'threshold_required' => true,
                'step_required' => true,
                'default_threshold' => 20,
                'default_step' => 5,
                'requires_pms_facts' => true,
                'supported_template_types' => self::SUPPORTED_FACT_TEMPLATES,
            ],
            [
                'key' => self::FULL_HOUSE,
                'label' => '满房时提醒',
                'description' => '同一酒店、同一业务日首次确认可售余量归零时发送。',
                'threshold_required' => false,
                'step_required' => false,
                'requires_pms_facts' => true,
                'supported_template_types' => self::SUPPORTED_FACT_TEMPLATES,
            ],
        ];
    }

    public static function supportsTemplate(string $conditionType, string $templateType): bool
    {
        $conditionType = trim($conditionType);
        if ($conditionType === self::ALWAYS) {
            return true;
        }
        return in_array(trim($templateType), self::SUPPORTED_FACT_TEMPLATES, true);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    public function evaluate(
        array $plan,
        array $candidate,
        string $businessDate,
        DateTimeImmutable $observedAt
    ): array {
        $type = $this->conditionType($plan['condition_type'] ?? self::ALWAYS);
        $ruleFingerprint = self::ruleFingerprint($plan);
        $base = [
            'condition_type' => $type,
            'condition_label' => $this->label($type),
            'business_date' => $businessDate,
            'rule_fingerprint' => $ruleFingerprint,
            'observed_at' => $observedAt->format('Y-m-d H:i:s'),
            'matched' => false,
            'state_commit_required' => false,
            'observed_value' => null,
            'trigger_bucket' => null,
            'previous_triggered_bucket' => null,
        ];
        if ($type === self::ALWAYS) {
            return array_replace($base, [
                'status' => 'matched',
                'matched' => true,
                'reason_code' => 'manual_notification_condition_always',
                'message' => '到点即发送。',
            ]);
        }
        if (!self::supportsTemplate($type, (string)($plan['template_type'] ?? ''))) {
            return array_replace($base, [
                'status' => 'blocked',
                'reason_code' => 'manual_notification_condition_template_unsupported',
                'message' => '当前消息模板没有可用于该规则的已核验经营事实。',
            ]);
        }

        $facts = $this->facts($candidate);
        $state = $this->state(
            (int)($plan['id'] ?? 0),
            (int)($plan['tenant_id'] ?? 0),
            (int)($plan['hotel_id'] ?? 0),
            $businessDate,
            $ruleFingerprint
        );
        $previousBucket = $this->numeric($state['highest_triggered_bucket'] ?? null);

        if ($type === self::OCCUPANCY_LADDER) {
            $observed = $this->numeric($facts['occupancy_rate_percent'] ?? null);
            if ($observed === null) {
                return array_replace($base, [
                    'status' => 'blocked',
                    'reason_code' => 'manual_notification_condition_fact_missing',
                    'message' => '本次已核验快照没有入住率，未执行条件推送。',
                ]);
            }
            $start = $this->requiredPercent(
                $plan['condition_threshold'] ?? null,
                'manual_notification_condition_threshold_invalid'
            );
            $step = $this->requiredPercent(
                $plan['condition_step'] ?? null,
                'manual_notification_condition_step_invalid'
            );
            $observed = max(0.0, min(100.0, $observed));
            $detail = array_replace($base, [
                'observed_value' => $observed,
                'threshold' => $start,
                'step' => $step,
                'previous_triggered_bucket' => $previousBucket,
            ]);
            if ($observed + 0.000001 < $start) {
                return array_replace($detail, [
                    'status' => 'not_matched',
                    'reason_code' => 'manual_notification_condition_threshold_not_reached',
                    'message' => sprintf('当前入住率 %.2f%%，尚未达到 %.2f%% 起始档。', $observed, $start),
                ]);
            }
            $bucket = min(
                100.0,
                $start + floor((($observed - $start) + 0.000001) / $step) * $step
            );
            $bucket = round($bucket, 4);
            $detail['trigger_bucket'] = $bucket;
            if ($previousBucket !== null && $bucket <= $previousBucket + 0.000001) {
                return array_replace($detail, [
                    'status' => 'not_matched',
                    'reason_code' => 'manual_notification_condition_level_already_sent',
                    'message' => sprintf('当前仍在 %.2f%% 档；该档已成功提醒，本次不重复发送。', $bucket),
                ]);
            }
            return array_replace($detail, [
                'status' => 'matched',
                'matched' => true,
                'state_commit_required' => true,
                'reason_code' => 'manual_notification_condition_level_crossed',
                'message' => sprintf('入住率跨至 %.2f%% 档（当前 %.2f%%）。', $bucket, $observed),
            ]);
        }

        $remaining = $this->numeric($facts['remaining_sellable_room_nights'] ?? null);
        if ($remaining === null) {
            return array_replace($base, [
                'status' => 'blocked',
                'reason_code' => 'manual_notification_condition_fact_missing',
                'message' => '本次已核验快照没有可售余量，未执行满房推送。',
            ]);
        }
        $full = $remaining <= 0.000001;
        $detail = array_replace($base, [
            'observed_value' => $remaining,
            'observed_metric' => 'remaining_sellable_room_nights',
            'trigger_bucket' => $full ? 1.0 : null,
            'previous_triggered_bucket' => $previousBucket,
        ]);
        if (!$full) {
            return array_replace($detail, [
                'status' => 'not_matched',
                'reason_code' => 'manual_notification_condition_not_full_house',
                'message' => sprintf('当前仍有 %.0f 间夜可售，未达到满房条件。', $remaining),
            ]);
        }
        if ($previousBucket !== null && $previousBucket >= 1.0) {
            return array_replace($detail, [
                'status' => 'not_matched',
                'reason_code' => 'manual_notification_condition_full_house_already_sent',
                'message' => '该业务日满房提醒已成功发送，本次不重复发送。',
            ]);
        }
        return array_replace($detail, [
            'status' => 'matched',
            'matched' => true,
            'state_commit_required' => true,
            'reason_code' => 'manual_notification_condition_full_house_reached',
            'message' => '已确认当前可售归零，触发满房提醒。',
        ]);
    }

    /** @param array<string,mixed> $evaluation */
    public function recordObservation(
        array $plan,
        array $evaluation,
        DateTimeImmutable $observedAt
    ): void {
        if (($evaluation['condition_type'] ?? self::ALWAYS) === self::ALWAYS
            || !is_numeric($evaluation['observed_value'] ?? null)
        ) {
            return;
        }
        $this->assertStateTable();
        $this->upsertState($plan, $evaluation, [
            'last_observed_value' => (float)$evaluation['observed_value'],
            'last_observed_at' => $observedAt->format('Y-m-d H:i:s'),
            'update_time' => $observedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Atomically reserves one business-rule bucket before the external sender
     * is called. Different minute windows share this lease, so they cannot
     * deliver the same rule level concurrently.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $evaluation
     * @return array{allowed:bool,reason_code:string,claimed:bool,pending_dispatch_id?:int,pending_trigger_bucket?:float|null}
     */
    public function claimTrigger(
        array $plan,
        array $evaluation,
        int $dispatchId,
        DateTimeImmutable $claimedAt
    ): array {
        if (($evaluation['state_commit_required'] ?? false) !== true
            || ($evaluation['matched'] ?? false) !== true
        ) {
            return [
                'allowed' => true,
                'reason_code' => 'manual_notification_condition_claim_not_required',
                'claimed' => false,
            ];
        }
        $bucket = $this->numeric($evaluation['trigger_bucket'] ?? null);
        if ($bucket === null || $dispatchId <= 0) {
            throw new \InvalidArgumentException(
                'manual_notification_condition_claim_invalid'
            );
        }
        $this->assertStateTable();
        $this->assertClaimColumns();
        $this->ensureStateRow($plan, $evaluation, $claimedAt);

        return Db::transaction(function () use (
            $plan,
            $evaluation,
            $dispatchId,
            $claimedAt,
            $bucket
        ): array {
            $row = $this->stateQuery($plan, $evaluation)->lock(true)->find();
            if (!is_array($row)) {
                throw new \RuntimeException(
                    'manual_notification_condition_state_missing'
                );
            }
            $sentBucket = $this->sentReceiptBucketForEvaluation(
                $plan,
                $evaluation
            );
            $successBucket = $this->numeric(
                $row['highest_triggered_bucket'] ?? null
            );
            if ($sentBucket !== null
                && ($successBucket === null || $sentBucket > $successBucket)
            ) {
                $successBucket = $sentBucket;
            }
            if ($successBucket !== null
                && $bucket <= $successBucket + 0.000001
            ) {
                return [
                    'allowed' => false,
                    'reason_code' =>
                        'manual_notification_condition_level_already_sent',
                    'claimed' => false,
                    'pending_dispatch_id' => (int)(
                        $row['pending_dispatch_id'] ?? 0
                    ),
                    'pending_trigger_bucket' => $this->numeric(
                        $row['pending_trigger_bucket'] ?? null
                    ),
                ];
            }

            $pendingDispatchId = (int)($row['pending_dispatch_id'] ?? 0);
            $pendingBucket = $this->numeric(
                $row['pending_trigger_bucket'] ?? null
            );
            if ($pendingDispatchId === $dispatchId) {
                return [
                    'allowed' => true,
                    'reason_code' =>
                        'manual_notification_condition_trigger_claim_reused',
                    'claimed' => true,
                    'pending_dispatch_id' => $dispatchId,
                    'pending_trigger_bucket' => $pendingBucket,
                ];
            }
            if ($pendingDispatchId > 0) {
                $pendingStatus = $this->dispatchStatus(
                    $plan,
                    $pendingDispatchId
                );
                $pendingClaimedAt = $this->dateTime(
                    $row['pending_claimed_at'] ?? null
                );
                $leaseActive = $pendingClaimedAt !== null
                    && $pendingClaimedAt->modify(
                        '+' . self::TRIGGER_CLAIM_LEASE_SECONDS . ' seconds'
                    ) > $claimedAt;
                $terminalWithoutDelivery = in_array(
                    $pendingStatus,
                    [
                        null,
                        'failed',
                        'blocked',
                        'skipped',
                        'preparation_failed',
                    ],
                    true
                );
                if ($pendingStatus === 'sent') {
                    return [
                        'allowed' => false,
                        'reason_code' =>
                            'manual_notification_condition_level_already_sent',
                        'claimed' => false,
                        'pending_dispatch_id' => $pendingDispatchId,
                        'pending_trigger_bucket' => $pendingBucket,
                    ];
                }
                if (in_array(
                    $pendingStatus,
                    ['sending', 'outcome_unknown'],
                    true
                ) || ($leaseActive && !$terminalWithoutDelivery)) {
                    return [
                        'allowed' => false,
                        'reason_code' =>
                            'manual_notification_condition_trigger_in_flight',
                        'claimed' => false,
                        'pending_dispatch_id' => $pendingDispatchId,
                        'pending_trigger_bucket' => $pendingBucket,
                    ];
                }
            }

            Db::name(self::STATE_TABLE)
                ->where('id', (int)$row['id'])
                ->update([
                    'pending_trigger_bucket' => $bucket,
                    'pending_dispatch_id' => $dispatchId,
                    'pending_claimed_at' => $claimedAt->format(
                        'Y-m-d H:i:s'
                    ),
                    'update_time' => $claimedAt->format('Y-m-d H:i:s'),
                ]);
            return [
                'allowed' => true,
                'reason_code' =>
                    'manual_notification_condition_trigger_claimed',
                'claimed' => true,
                'pending_dispatch_id' => $dispatchId,
                'pending_trigger_bucket' => $bucket,
            ];
        });
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $evaluation */
    public function releaseTriggerClaim(
        array $plan,
        array $evaluation,
        int $dispatchId,
        DateTimeImmutable $releasedAt
    ): void {
        if (($evaluation['state_commit_required'] ?? false) !== true
            || $dispatchId <= 0
            || !$this->tableExists(self::STATE_TABLE)
        ) {
            return;
        }
        $this->assertClaimColumns();
        Db::transaction(function () use (
            $plan,
            $evaluation,
            $dispatchId,
            $releasedAt
        ): void {
            $row = $this->stateQuery($plan, $evaluation)->lock(true)->find();
            if (!is_array($row)
                || (int)($row['pending_dispatch_id'] ?? 0) !== $dispatchId
            ) {
                return;
            }
            Db::name(self::STATE_TABLE)
                ->where('id', (int)$row['id'])
                ->update([
                    'pending_trigger_bucket' => null,
                    'pending_dispatch_id' => null,
                    'pending_claimed_at' => null,
                    'update_time' => $releasedAt->format('Y-m-d H:i:s'),
                ]);
        });
    }

    /**
     * Fence an expired worker after its dispatch attempt has entered the
     * sending state but before any external sender is called. Once a dispatch
     * is confirmed here, claimTrigger() treats its sending status as
     * non-stealable even when the original lease age exceeds the timeout.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $evaluation
     * @return array{allowed:bool,reason_code:string,confirmed:bool,pending_dispatch_id?:int,pending_trigger_bucket?:float|null}
     */
    public function confirmTriggerClaim(
        array $plan,
        array $evaluation,
        int $dispatchId,
        DateTimeImmutable $confirmedAt
    ): array {
        if (($evaluation['state_commit_required'] ?? false) !== true
            || ($evaluation['matched'] ?? false) !== true
        ) {
            return [
                'allowed' => true,
                'reason_code' =>
                    'manual_notification_condition_claim_confirmation_not_required',
                'confirmed' => false,
            ];
        }
        $bucket = $this->numeric($evaluation['trigger_bucket'] ?? null);
        if ($bucket === null || $dispatchId <= 0) {
            throw new \InvalidArgumentException(
                'manual_notification_condition_claim_invalid'
            );
        }
        $this->assertStateTable();
        $this->assertClaimColumns();

        return Db::transaction(function () use (
            $plan,
            $evaluation,
            $dispatchId,
            $confirmedAt,
            $bucket
        ): array {
            $row = $this->stateQuery($plan, $evaluation)->lock(true)->find();
            $pendingDispatchId = is_array($row)
                ? (int)($row['pending_dispatch_id'] ?? 0)
                : 0;
            $pendingBucket = is_array($row)
                ? $this->numeric($row['pending_trigger_bucket'] ?? null)
                : null;
            if (!is_array($row)
                || $pendingDispatchId !== $dispatchId
                || $pendingBucket === null
                || abs($pendingBucket - $bucket) > 0.000001
            ) {
                return [
                    'allowed' => false,
                    'reason_code' =>
                        'manual_notification_condition_trigger_claim_fenced',
                    'confirmed' => false,
                    'pending_dispatch_id' => $pendingDispatchId,
                    'pending_trigger_bucket' => $pendingBucket,
                ];
            }
            $successBucket = $this->numeric(
                $row['highest_triggered_bucket'] ?? null
            );
            $sentBucket = $this->sentReceiptBucketForEvaluation(
                $plan,
                $evaluation
            );
            if ($sentBucket !== null
                && ($successBucket === null || $sentBucket > $successBucket)
            ) {
                $successBucket = $sentBucket;
            }
            if ($successBucket !== null
                && $bucket <= $successBucket + 0.000001
            ) {
                return [
                    'allowed' => false,
                    'reason_code' =>
                        'manual_notification_condition_level_already_sent',
                    'confirmed' => false,
                    'pending_dispatch_id' => $pendingDispatchId,
                    'pending_trigger_bucket' => $pendingBucket,
                ];
            }
            if ($this->dispatchStatus($plan, $dispatchId) !== 'sending') {
                return [
                    'allowed' => false,
                    'reason_code' =>
                        'manual_notification_condition_trigger_claim_fenced',
                    'confirmed' => false,
                    'pending_dispatch_id' => $pendingDispatchId,
                    'pending_trigger_bucket' => $pendingBucket,
                ];
            }
            Db::name(self::STATE_TABLE)
                ->where('id', (int)$row['id'])
                ->update([
                    'pending_claimed_at' => $confirmedAt->format(
                        'Y-m-d H:i:s'
                    ),
                    'update_time' => $confirmedAt->format('Y-m-d H:i:s'),
                ]);
            return [
                'allowed' => true,
                'reason_code' =>
                    'manual_notification_condition_trigger_claim_confirmed',
                'confirmed' => true,
                'pending_dispatch_id' => $dispatchId,
                'pending_trigger_bucket' => $bucket,
            ];
        });
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $evaluation */
    public function commitSuccessfulDelivery(
        array $plan,
        array $evaluation,
        int $dispatchId,
        DateTimeImmutable $committedAt
    ): void {
        if (($evaluation['state_commit_required'] ?? false) !== true
            || ($evaluation['matched'] ?? false) !== true
        ) {
            return;
        }
        $bucket = $this->numeric($evaluation['trigger_bucket'] ?? null);
        if ($bucket === null || $dispatchId <= 0) {
            throw new \InvalidArgumentException('manual_notification_condition_commit_invalid');
        }
        $this->assertStateTable();
        $this->assertClaimColumns();
        Db::transaction(function () use (
            $plan,
            $evaluation,
            $dispatchId,
            $committedAt,
            $bucket
        ): void {
            $row = $this->stateQuery($plan, $evaluation)->lock(true)->find();
            $pendingBucket = is_array($row)
                ? $this->numeric($row['pending_trigger_bucket'] ?? null)
                : null;
            if (!is_array($row)
                || (int)($row['pending_dispatch_id'] ?? 0) !== $dispatchId
                || $pendingBucket === null
                || abs($pendingBucket - $bucket) > 0.000001
            ) {
                throw new \RuntimeException(
                    'manual_notification_condition_trigger_claim_fenced'
                );
            }
            $current = is_array($row)
                ? $this->numeric($row['highest_triggered_bucket'] ?? null)
                : null;
            $update = [
                'highest_triggered_bucket' => $current === null
                    ? $bucket
                    : max($current, $bucket),
                'last_observed_value' => is_numeric($evaluation['observed_value'] ?? null)
                    ? (float)$evaluation['observed_value']
                    : null,
                'last_observed_at' => $committedAt->format('Y-m-d H:i:s'),
                'last_triggered_at' => $committedAt->format('Y-m-d H:i:s'),
                'last_dispatch_id' => $dispatchId,
                'update_time' => $committedAt->format('Y-m-d H:i:s'),
            ];
            if ((int)($row['pending_dispatch_id'] ?? 0) === $dispatchId) {
                $update['pending_trigger_bucket'] = null;
                $update['pending_dispatch_id'] = null;
                $update['pending_claimed_at'] = null;
            }
            Db::name(self::STATE_TABLE)->where('id', (int)$row['id'])->update($update);
        });
    }

    /** @param array<string,mixed> $plan @return array<string,mixed>|null */
    public function latestStateForPlan(array $plan): ?array
    {
        if (($plan['condition_type'] ?? self::ALWAYS) === self::ALWAYS
            || !$this->tableExists(self::STATE_TABLE)
            || (int)($plan['id'] ?? 0) <= 0
        ) {
            return null;
        }
        $row = Db::name(self::STATE_TABLE)
            ->where('notification_id', (int)$plan['id'])
            ->where('tenant_id', (int)($plan['tenant_id'] ?? 0))
            ->where('hotel_id', (int)($plan['hotel_id'] ?? 0))
            ->where('rule_fingerprint', self::ruleFingerprint($plan))
            ->order('business_date', 'desc')
            ->order('last_observed_at', 'desc')
            ->order('id', 'desc')
            ->find();
        $state = is_array($row) ? $this->presentState($row) : null;
        $receipt = $this->latestSentReceiptStateForPlan($plan);
        if ($receipt === null) {
            return $state;
        }
        if ($state === null
            || (string)$receipt['business_date']
                > (string)$state['business_date']
        ) {
            return $receipt;
        }
        if ((string)$receipt['business_date']
            !== (string)$state['business_date']
        ) {
            return $state;
        }
        $receiptBucket = $this->numeric(
            $receipt['highest_triggered_bucket'] ?? null
        );
        $stateBucket = $this->numeric(
            $state['highest_triggered_bucket'] ?? null
        );
        if ($receiptBucket !== null
            && ($stateBucket === null || $receiptBucket > $stateBucket)
        ) {
            $state['highest_triggered_bucket'] = $receiptBucket;
            $state['last_triggered_at'] = $receipt['last_triggered_at'];
            $state['last_dispatch_id'] = $receipt['last_dispatch_id'];
        }
        return $state;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $evaluation */
    public function decorateCandidate(array $candidate, array $evaluation): array
    {
        if (($evaluation['matched'] ?? false) !== true
            || ($evaluation['condition_type'] ?? self::ALWAYS) === self::ALWAYS
            || !is_array($candidate['payload'] ?? null)
        ) {
            return $candidate;
        }
        $payload = $candidate['payload'];
        $line = '> 自动规则：' . trim((string)($evaluation['message'] ?? '条件已命中'));
        if (isset($payload['markdown']['content'])) {
            $payload['markdown']['content'] = $line . "\n\n"
                . (string)$payload['markdown']['content'];
        } elseif (isset($payload['text']['content'])) {
            $payload['text']['content'] = '【自动规则】'
                . trim((string)($evaluation['message'] ?? '条件已命中'))
                . "\n"
                . (string)$payload['text']['content'];
        }
        $candidate['payload'] = $payload;
        $candidate['payload_fingerprint'] = hash('sha256', $this->json($payload));
        $candidate['condition_evaluation'] = $evaluation;
        return $candidate;
    }

    /** @param array<string,mixed> $plan */
    public static function ruleFingerprint(array $plan): string
    {
        $json = json_encode([
            'condition_type' => trim((string)($plan['condition_type'] ?? self::ALWAYS)),
            'condition_threshold' => self::canonicalNumber($plan['condition_threshold'] ?? null),
            'condition_step' => self::canonicalNumber($plan['condition_step'] ?? null),
            'template_type' => trim((string)($plan['template_type'] ?? '')),
            'source_scope' => trim((string)($plan['source_scope'] ?? '')),
            'target_robot_id' => (int)(
                $plan['target_robot_id']
                ?? $plan['test_robot_id']
                ?? 0
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new \RuntimeException('manual_notification_condition_fingerprint_failed');
        }
        return hash('sha256', $json);
    }

    /**
     * Rebuild the immutable rule evaluation saved on a dispatch so an
     * operator-confirmed retry participates in the same bucket lease.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $dispatch
     * @return array<string,mixed>|null
     */
    public function evaluationFromDispatch(
        array $plan,
        array $dispatch
    ): ?array {
        $fingerprint = trim((string)(
            $dispatch['condition_rule_fingerprint'] ?? ''
        ));
        $bucket = $this->numeric(
            $dispatch['condition_trigger_bucket'] ?? null
        );
        if ($bucket === null) {
            if ($fingerprint === '') {
                return null;
            }
            if (($plan['condition_type'] ?? self::ALWAYS) === self::ALWAYS
                && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
                && hash_equals(self::ruleFingerprint($plan), $fingerprint)
            ) {
                return null;
            }
            throw new \InvalidArgumentException(
                'manual_notification_retry_condition_rule_changed'
            );
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || !hash_equals(self::ruleFingerprint($plan), $fingerprint)
        ) {
            throw new \InvalidArgumentException(
                'manual_notification_retry_condition_rule_changed'
            );
        }
        return [
            'condition_type' => (string)(
                $plan['condition_type'] ?? self::ALWAYS
            ),
            'business_date' => (string)(
                $dispatch['business_date'] ?? ''
            ),
            'rule_fingerprint' => $fingerprint,
            'observed_at' => (string)(
                $dispatch['claimed_at']
                ?? $dispatch['created_at']
                ?? ''
            ),
            'status' => 'matched',
            'matched' => true,
            'state_commit_required' => true,
            'observed_value' => $this->numeric(
                $dispatch['condition_observed_value'] ?? null
            ),
            'trigger_bucket' => $bucket,
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,float|null> */
    private function facts(array $candidate): array
    {
        $daily = is_array($candidate['facts'] ?? null) ? $candidate['facts'] : [];
        $factEnvelope = is_array($candidate['fact_envelope'] ?? null)
            ? $candidate['fact_envelope']
            : [];
        $pmsSelection = (new RevenuePmsFactSelectorService())
            ->select($factEnvelope);
        $envelope = $pmsSelection['data_status'] === 'readback_verified'
            ? (array)$pmsSelection['facts']
            : [];
        $legacyDailyFallback = $factEnvelope === []
            || ($pmsSelection['legacy_fixture'] ?? false) === true;
        $occupancy = $this->numeric(
            ($legacyDailyFallback ? $daily['pms_occupancy'] ?? null : null)
            ?? $envelope['occupancy_rate_percent']
            ?? null
        );
        $remaining = $this->numeric($envelope['remaining_sellable_room_nights'] ?? null);
        if ($remaining === null) {
            $sellable = $this->numeric(
                ($legacyDailyFallback
                    ? $daily['pms_sellable_room_nights'] ?? null
                    : null)
                ?? $envelope['sellable_room_nights']
                ?? null
            );
            $sold = $this->numeric(
                ($legacyDailyFallback
                    ? $daily['pms_sold_room_nights'] ?? null
                    : null)
                ?? $envelope['sold_room_nights']
                ?? null
            );
            if ($sellable !== null && $sold !== null) {
                $remaining = max(0.0, $sellable - $sold);
            }
        }
        return [
            'occupancy_rate_percent' => $occupancy,
            'remaining_sellable_room_nights' => $remaining,
        ];
    }

    private function conditionType(mixed $value): string
    {
        $value = trim((string)$value);
        if (!in_array($value, [self::ALWAYS, self::OCCUPANCY_LADDER, self::FULL_HOUSE], true)) {
            throw new \InvalidArgumentException('manual_notification_condition_type_invalid');
        }
        return $value;
    }

    private function label(string $type): string
    {
        foreach (self::definitions() as $definition) {
            if ($definition['key'] === $type) {
                return (string)$definition['label'];
            }
        }
        return $type;
    }

    private function requiredPercent(mixed $value, string $error): float
    {
        $number = $this->numeric($value);
        if ($number === null || $number <= 0 || $number > 100) {
            throw new \InvalidArgumentException($error);
        }
        return round($number, 4);
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
    }

    private static function canonicalNumber(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float)$value)
            ? round((float)$value, 4)
            : null;
    }

    private function assertStateTable(): void
    {
        if (!$this->tableExists(self::STATE_TABLE)) {
            throw new \RuntimeException('manual_notification_condition_state_schema_missing');
        }
    }

    private function assertClaimColumns(): void
    {
        foreach ([
            'pending_trigger_bucket',
            'pending_dispatch_id',
            'pending_claimed_at',
        ] as $column) {
            if (!$this->tableHasColumn(self::STATE_TABLE, $column)) {
                throw new \RuntimeException(
                    'manual_notification_condition_claim_schema_missing'
                );
            }
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $evaluation */
    private function stateQuery(array $plan, array $evaluation): mixed
    {
        return Db::name(self::STATE_TABLE)
            ->where('notification_id', (int)($plan['id'] ?? 0))
            ->where('tenant_id', (int)($plan['tenant_id'] ?? 0))
            ->where('hotel_id', (int)($plan['hotel_id'] ?? 0))
            ->where('business_date', (string)($evaluation['business_date'] ?? ''))
            ->where('rule_fingerprint', (string)($evaluation['rule_fingerprint'] ?? ''));
    }

    /** @return array<string,mixed>|null */
    private function state(
        int $notificationId,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $ruleFingerprint
    ): ?array {
        $this->assertStateTable();
        $row = Db::name(self::STATE_TABLE)
            ->where('notification_id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->where('rule_fingerprint', $ruleFingerprint)
            ->find();
        $sentBucket = $this->sentReceiptBucket(
            $notificationId,
            $tenantId,
            $hotelId,
            $businessDate,
            $ruleFingerprint
        );
        if (!is_array($row)) {
            return $sentBucket === null
                ? null
                : ['highest_triggered_bucket' => $sentBucket];
        }
        $stateBucket = $this->numeric($row['highest_triggered_bucket'] ?? null);
        if ($sentBucket !== null
            && ($stateBucket === null || $sentBucket > $stateBucket)
        ) {
            $row['highest_triggered_bucket'] = $sentBucket;
        }
        return $row;
    }

    private function sentReceiptBucket(
        int $notificationId,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $ruleFingerprint
    ): ?float {
        $table = 'manual_notification_schedule_dispatches';
        if (!$this->tableExists($table)
            || !$this->tableHasColumn($table, 'condition_rule_fingerprint')
            || !$this->tableHasColumn($table, 'condition_trigger_bucket')
        ) {
            return null;
        }
        $value = Db::name($table)
            ->where('notification_id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->where('condition_rule_fingerprint', $ruleFingerprint)
            ->where('status', 'sent')
            ->max('condition_trigger_bucket');
        return $this->numeric($value);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $evaluation */
    private function sentReceiptBucketForEvaluation(
        array $plan,
        array $evaluation
    ): ?float {
        return $this->sentReceiptBucket(
            (int)($plan['id'] ?? 0),
            (int)($plan['tenant_id'] ?? 0),
            (int)($plan['hotel_id'] ?? 0),
            (string)($evaluation['business_date'] ?? ''),
            (string)($evaluation['rule_fingerprint'] ?? '')
        );
    }

    /** @param array<string,mixed> $plan */
    private function dispatchStatus(array $plan, int $dispatchId): ?string
    {
        if ($dispatchId <= 0
            || !$this->tableExists('manual_notification_schedule_dispatches')
        ) {
            return null;
        }
        $value = Db::name('manual_notification_schedule_dispatches')
            ->where('id', $dispatchId)
            ->where('notification_id', (int)($plan['id'] ?? 0))
            ->where('tenant_id', (int)($plan['tenant_id'] ?? 0))
            ->where('hotel_id', (int)($plan['hotel_id'] ?? 0))
            ->value('status');
        $status = strtolower(trim((string)$value));
        return $status !== '' ? $status : null;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed>|null */
    private function latestSentReceiptStateForPlan(array $plan): ?array
    {
        $table = 'manual_notification_schedule_dispatches';
        if (!$this->tableExists($table)
            || !$this->tableHasColumn($table, 'condition_rule_fingerprint')
            || !$this->tableHasColumn($table, 'condition_trigger_bucket')
        ) {
            return null;
        }
        $row = Db::name($table)
            ->where('notification_id', (int)($plan['id'] ?? 0))
            ->where('tenant_id', (int)($plan['tenant_id'] ?? 0))
            ->where('hotel_id', (int)($plan['hotel_id'] ?? 0))
            ->where(
                'condition_rule_fingerprint',
                self::ruleFingerprint($plan)
            )
            ->where('status', 'sent')
            ->whereNotNull('condition_trigger_bucket')
            ->order('business_date', 'desc')
            ->order('condition_trigger_bucket', 'desc')
            ->order('dispatched_at', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return null;
        }
        return [
            'business_date' => (string)($row['business_date'] ?? ''),
            'condition_type' => (string)(
                $plan['condition_type'] ?? self::ALWAYS
            ),
            'highest_triggered_bucket' => $this->numeric(
                $row['condition_trigger_bucket'] ?? null
            ),
            'pending_trigger_bucket' => null,
            'pending_dispatch_id' => null,
            'pending_claimed_at' => null,
            'last_observed_value' => $this->numeric(
                $row['condition_observed_value'] ?? null
            ),
            'last_observed_at' => $row['dispatched_at'] ?? null,
            'last_triggered_at' => $row['dispatched_at'] ?? null,
            'last_dispatch_id' => (int)($row['id'] ?? 0),
        ];
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            return in_array($column, Db::getTableInfo($table, 'fields'), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $evaluation @param array<string,mixed> $update */
    private function upsertState(array $plan, array $evaluation, array $update): void
    {
        $createdAt = new DateTimeImmutable(
            (string)$evaluation['observed_at'],
            new DateTimeZone('Asia/Shanghai')
        );
        $this->ensureStateRow($plan, $evaluation, $createdAt);
        Db::transaction(function () use ($plan, $evaluation, $update): void {
            $row = $this->stateQuery($plan, $evaluation)->lock(true)->find();
            if (!is_array($row)) {
                throw new \RuntimeException(
                    'manual_notification_condition_state_missing'
                );
            }
            Db::name(self::STATE_TABLE)
                ->where('id', (int)$row['id'])
                ->update($update);
        });
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $evaluation */
    private function ensureStateRow(
        array $plan,
        array $evaluation,
        DateTimeImmutable $createdAt
    ): void {
        if (is_array($this->stateQuery($plan, $evaluation)->find())) {
            return;
        }
        try {
            $this->insertState($plan, $evaluation, [], $createdAt);
        } catch (\Throwable $error) {
            if (is_array($this->stateQuery($plan, $evaluation)->find())) {
                return;
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $evaluation @param array<string,mixed> $update */
    private function insertState(
        array $plan,
        array $evaluation,
        array $update,
        DateTimeImmutable $createdAt
    ): void {
        Db::name(self::STATE_TABLE)->insert([
            'notification_id' => (int)($plan['id'] ?? 0),
            'tenant_id' => (int)($plan['tenant_id'] ?? 0),
            'hotel_id' => (int)($plan['hotel_id'] ?? 0),
            'business_date' => (string)($evaluation['business_date'] ?? ''),
            'condition_type' => (string)($evaluation['condition_type'] ?? self::ALWAYS),
            'rule_fingerprint' => (string)($evaluation['rule_fingerprint'] ?? ''),
            'highest_triggered_bucket' => null,
            'pending_trigger_bucket' => null,
            'pending_dispatch_id' => null,
            'pending_claimed_at' => null,
            'last_observed_value' => null,
            'last_observed_at' => null,
            'last_triggered_at' => null,
            'last_dispatch_id' => null,
            'create_time' => $createdAt->format('Y-m-d H:i:s'),
            'update_time' => $createdAt->format('Y-m-d H:i:s'),
            ...$update,
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentState(array $row): array
    {
        return [
            'business_date' => (string)($row['business_date'] ?? ''),
            'condition_type' => (string)($row['condition_type'] ?? ''),
            'highest_triggered_bucket' => $this->numeric(
                $row['highest_triggered_bucket'] ?? null
            ),
            'pending_trigger_bucket' => $this->numeric(
                $row['pending_trigger_bucket'] ?? null
            ),
            'pending_dispatch_id' => isset($row['pending_dispatch_id'])
                ? (int)$row['pending_dispatch_id']
                : null,
            'pending_claimed_at' => $row['pending_claimed_at'] ?? null,
            'last_observed_value' => $this->numeric($row['last_observed_value'] ?? null),
            'last_observed_at' => $row['last_observed_at'] ?? null,
            'last_triggered_at' => $row['last_triggered_at'] ?? null,
            'last_dispatch_id' => isset($row['last_dispatch_id'])
                ? (int)$row['last_dispatch_id']
                : null,
        ];
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable(
                $value,
                new DateTimeZone('Asia/Shanghai')
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('manual_notification_condition_json_failed');
        }
        return $json;
    }
}
