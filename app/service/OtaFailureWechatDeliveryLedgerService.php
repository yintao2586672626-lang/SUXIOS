<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;
use Throwable;

final class OtaFailureWechatDeliveryLedgerService
{
    private const TABLE = 'ota_failure_wecom_deliveries';

    /**
     * Atomically reserve one delivery identity before any external request.
     *
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    public function claim(array $identity): array
    {
        $hotelId = (int)($identity['hotel_id'] ?? 0);
        $tenantId = (int)($identity['tenant_id'] ?? 0);
        $platform = strtolower(trim((string)($identity['platform'] ?? '')));
        $reasonCode = strtolower(trim((string)($identity['reason_code'] ?? '')));
        $dataDate = trim((string)($identity['data_date'] ?? ''));
        $taskId = max(0, (int)($identity['collector_task_id'] ?? 0));
        if ($hotelId <= 0
            || preg_match('/^[a-z0-9_-]{2,32}$/', $platform) !== 1
            || preg_match('/^[a-z0-9_-]{2,64}$/', $reasonCode) !== 1
            || !$this->validDate($dataDate)
        ) {
            throw new RuntimeException('ota_failure_wecom_delivery_identity_invalid');
        }

        $dedupeKey = hash('sha256', implode('|', [
            $tenantId,
            $hotelId,
            $platform,
            $reasonCode,
            $dataDate,
            $taskId,
        ]));
        $claimToken = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');

        try {
            $id = (int)Db::name(self::TABLE)->insertGetId([
                'tenant_id' => $tenantId > 0 ? $tenantId : null,
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'reason_code' => $reasonCode,
                'data_date' => $dataDate,
                'collector_task_id' => $taskId > 0 ? $taskId : null,
                'dedupe_key' => $dedupeKey,
                'status' => 'sending',
                'claim_token' => $claimToken,
                'requested_at' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            if ($id <= 0) {
                throw new RuntimeException('ota_failure_wecom_delivery_claim_not_persisted');
            }
            return [
                'claimed' => true,
                'id' => $id,
                'claim_token' => $claimToken,
                'dedupe_key' => $dedupeKey,
                'status' => 'sending',
            ];
        } catch (Throwable $error) {
            try {
                $existing = Db::name(self::TABLE)->where('dedupe_key', $dedupeKey)->find();
            } catch (Throwable) {
                throw new RuntimeException('ota_failure_wecom_receipt_store_unavailable', 0, $error);
            }
            if (!$existing) {
                throw new RuntimeException('ota_failure_wecom_delivery_claim_failed', 0, $error);
            }
            return array_merge(['claimed' => false], $existing);
        }
    }

    /**
     * Persist the result while the caller still owns the sending claim.
     *
     * @param array<string, mixed> $claim
     * @param array<string, mixed> $delivery
     * @return array<string, mixed>
     */
    public function complete(array $claim, array $delivery): array
    {
        $id = (int)($claim['id'] ?? 0);
        $claimToken = trim((string)($claim['claim_token'] ?? ''));
        if ($id <= 0 || preg_match('/^[a-f0-9]{64}$/', $claimToken) !== 1) {
            throw new RuntimeException('ota_failure_wecom_delivery_claim_invalid');
        }

        $deliveryStatus = strtolower(trim((string)($delivery['delivery_status'] ?? 'failed')));
        $ambiguous = $deliveryStatus === 'outcome_unknown';
        foreach ((array)($delivery['failures'] ?? []) as $failure) {
            if (is_array($failure) && ($failure['ambiguous'] ?? false) === true) {
                $ambiguous = true;
                break;
            }
        }
        $status = $ambiguous
            ? 'outcome_unknown'
            : (in_array($deliveryStatus, ['sent', 'partial'], true) ? $deliveryStatus : 'failed');
        $now = date('Y-m-d H:i:s');
        $responseReference = $this->safeText((string)($delivery['response_reference'] ?? ''), 120);
        $resultCode = $this->safeCode((string)($delivery['reason'] ?? $deliveryStatus));

        $updated = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('claim_token', $claimToken)
            ->where('status', 'sending')
            ->update([
                'status' => $status,
                'robot_count' => max(0, (int)($delivery['robot_count'] ?? 0)),
                'sent_count' => max(0, (int)($delivery['sent_count'] ?? 0)),
                'failed_count' => max(0, (int)($delivery['failed_count'] ?? 0)),
                'response_reference' => $responseReference !== '' ? $responseReference : null,
                'result_code' => $resultCode !== '' ? $resultCode : null,
                'completed_at' => $now,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('ota_failure_wecom_delivery_receipt_not_persisted');
        }

        return array_merge($delivery, [
            'delivery_status' => $status,
            'delivery_ledger_id' => $id,
            'retry_may_duplicate' => $status === 'outcome_unknown',
        ]);
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function replayResult(array $record): array
    {
        $status = strtolower(trim((string)($record['status'] ?? '')));
        $unknown = in_array($status, ['sending', 'outcome_unknown'], true);
        return [
            'delivery_status' => $unknown ? 'outcome_unknown' : 'deduplicated',
            'original_delivery_status' => $status !== '' ? $status : 'unknown',
            'hotel_id' => (int)($record['hotel_id'] ?? 0),
            'robot_count' => max(0, (int)($record['robot_count'] ?? 0)),
            'sent_count' => max(0, (int)($record['sent_count'] ?? 0)),
            'failed_count' => max(0, (int)($record['failed_count'] ?? 0)),
            'delivery_ledger_id' => (int)($record['id'] ?? 0),
            'retry_may_duplicate' => $unknown,
        ];
    }

    private function validDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value;
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, max(1, $maxLength), 'UTF-8');
    }

    private function safeCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?? '';
        return substr(trim($value, '_'), 0, 64);
    }
}
