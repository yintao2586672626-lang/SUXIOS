<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/** Persists one immutable evaluation batch and verifies its exact readback. */
final class AiEvaluationRunService
{
    public const TABLE = 'ai_evaluation_runs';
    public const CONTRACT_VERSION = 'ai_evaluation_run.v1';
    public const RESERVATION_CONTRACT_VERSION = 'ai_evaluation_run_reservation.v1';
    private const DEFAULT_LEASE_SECONDS = 900;

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function reserve(
        string $clientRunKey,
        string $evaluationSet,
        string $modelKey,
        array $filters,
        bool $dryRun,
        bool $allowExternalModelCall,
        int $createdBy,
        ?\DateTimeImmutable $now = null,
        int $leaseSeconds = self::DEFAULT_LEASE_SECONDS
    ): array {
        $this->assertReady();
        [$clientRunKey, $evaluationSet, $modelKey, $createdBy] = $this->validatedIdentity(
            $clientRunKey,
            $evaluationSet,
            $modelKey,
            $createdBy
        );
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $claim = $this->newClaim($now, $leaseSeconds, $dryRun, $allowExternalModelCall);
        $payload = $this->runPayload(
            $clientRunKey,
            $evaluationSet,
            $modelKey,
            $filters,
            $dryRun,
            'running',
            [],
            [],
            $claim['result'],
            $createdBy
        );
        $digest = $this->digest($payload);
        $id = 0;
        try {
            $id = (int)Db::name(self::TABLE)->insertGetId([
                'client_run_key' => $clientRunKey,
                'evaluation_set' => $evaluationSet,
                'model_key' => $modelKey,
                'filters_json' => $this->encode($filters),
                'dry_run' => $dryRun ? 1 : 0,
                'status' => 'running',
                'claim_token_hash' => $claim['token_hash'],
                'lease_expires_at' => $claim['lease_expires_at'],
                'summary_json' => $this->encode([]),
                'cases_json' => $this->encode([]),
                'result_json' => $this->encode($claim['result']),
                'result_digest' => $digest,
                'created_by' => $createdBy,
                'readback_verified' => 0,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'completed_at' => null,
            ]);
        } catch (Throwable $error) {
            $existing = Db::name(self::TABLE)->where('client_run_key', $clientRunKey)->find();
            if (!is_array($existing)) {
                throw $error;
            }
            return $this->reuseOrTakeOverReservation(
                $existing,
                $clientRunKey,
                $evaluationSet,
                $modelKey,
                $filters,
                $dryRun,
                $allowExternalModelCall,
                $createdBy,
                $now,
                $leaseSeconds
            );
        }
        if ($id <= 0) {
            throw new RuntimeException('AI评测批次 reservation 保存失败');
        }
        return $this->readClaimedReservation($id, $claim['token'], $digest, true);
    }

    /** @return array<string,mixed> */
    public function renewReservation(
        int $id,
        string $claimToken,
        ?\DateTimeImmutable $now = null,
        int $leaseSeconds = self::DEFAULT_LEASE_SECONDS
    ): array {
        $this->assertReady();
        if ($id <= 0 || preg_match('/^[a-f0-9]{64}$/D', $claimToken) !== 1) {
            throw new InvalidArgumentException('AI评测批次 claim 无效');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $claimHash = hash('sha256', $claimToken);
        $row = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('status', 'running')
            ->where('claim_token_hash', $claimHash)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('AI评测批次 claim 已失效', 409);
        }
        $reservation = $this->normalize($row);
        $this->assertDigest($reservation);
        $oldLease = trim((string)($row['lease_expires_at'] ?? ''));
        $nowText = $now->format('Y-m-d H:i:s');
        $reservationResult = is_array($reservation['result'] ?? null) ? $reservation['result'] : [];
        if ($oldLease === ''
            || $oldLease <= $nowText
            || ($reservationResult['reservation_contract_version'] ?? '') !== self::RESERVATION_CONTRACT_VERSION
            || (string)($reservationResult['lease_expires_at'] ?? '') !== $oldLease
        ) {
            throw new RuntimeException('AI评测批次 claim 已过期', 409);
        }
        $newLease = $now->modify('+' . $leaseSeconds . ' seconds')->format('Y-m-d H:i:s');
        $reservationResult['lease_expires_at'] = $newLease;
        $payload = $this->runPayload(
            (string)$reservation['client_run_key'],
            (string)$reservation['evaluation_set'],
            (string)$reservation['model_key'],
            (array)$reservation['filters'],
            (bool)$reservation['dry_run'],
            'running',
            [],
            [],
            $reservationResult,
            (int)$reservation['created_by']
        );
        $newDigest = $this->digest($payload);
        $affected = (int)Db::name(self::TABLE)
            ->where('id', $id)
            ->where('status', 'running')
            ->where('claim_token_hash', $claimHash)
            ->where('lease_expires_at', $oldLease)
            ->where('result_digest', (string)$reservation['result_digest'])
            ->update([
                'lease_expires_at' => $newLease,
                'result_json' => $this->encode($reservationResult),
                'result_digest' => $newDigest,
                'readback_verified' => 0,
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('AI评测批次 reservation 续租竞争校验失败', 409);
        }
        return $this->readClaimedReservation($id, $claimToken, $newDigest, false);
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function finalizeReservation(
        int $id,
        string $claimToken,
        array $result,
        ?\DateTimeImmutable $now = null
    ): array {
        $this->assertReady();
        if ($id <= 0 || preg_match('/^[a-f0-9]{64}$/D', $claimToken) !== 1) {
            throw new InvalidArgumentException('AI评测批次 claim 无效');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $now = $now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        $claimHash = hash('sha256', $claimToken);
        $row = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('status', 'running')
            ->where('claim_token_hash', $claimHash)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('AI评测批次 claim 已失效', 409);
        }
        $reservation = $this->normalize($row);
        $this->assertDigest($reservation);
        $leaseExpiresAt = trim((string)($row['lease_expires_at'] ?? ''));
        if ($leaseExpiresAt === '' || $leaseExpiresAt <= $now->format('Y-m-d H:i:s')) {
            throw new RuntimeException('AI评测批次 claim 已过期', 409);
        }
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $cases = is_array($result['cases'] ?? null) ? $result['cases'] : [];
        $dryRun = ($result['dry_run'] ?? true) === true;
        $reservedResult = is_array($reservation['result'] ?? null) ? $reservation['result'] : [];
        $allowExternalModelCall = ($result['allow_external_model_call'] ?? false) === true;
        if ($dryRun !== (bool)$reservation['dry_run']
            || $allowExternalModelCall !== (($reservedResult['allow_external_model_call'] ?? false) === true)
            || (string)($result['evaluation_set'] ?? '') !== (string)$reservation['evaluation_set']
            || (string)($result['model_key'] ?? '') !== (string)$reservation['model_key']
        ) {
            throw new RuntimeException('AI评测批次 finalize 参数与 reservation 不一致', 409);
        }
        $status = $this->status($dryRun, $summary);
        $payload = $this->runPayload(
            (string)$reservation['client_run_key'],
            (string)$reservation['evaluation_set'],
            (string)$reservation['model_key'],
            (array)$reservation['filters'],
            $dryRun,
            $status,
            $summary,
            $cases,
            $result,
            (int)$reservation['created_by']
        );
        $finalDigest = $this->digest($payload);
        $affected = (int)Db::name(self::TABLE)
            ->where('id', $id)
            ->where('status', 'running')
            ->where('claim_token_hash', $claimHash)
            ->where('lease_expires_at', $leaseExpiresAt)
            ->where('result_digest', (string)$reservation['result_digest'])
            ->update([
                'status' => $status,
                'claim_token_hash' => null,
                'lease_expires_at' => null,
                'summary_json' => $this->encode($summary),
                'cases_json' => $this->encode($cases),
                'result_json' => $this->encode($result),
                'result_digest' => $finalDigest,
                'readback_verified' => 0,
                'completed_at' => $now->format('Y-m-d H:i:s'),
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('AI评测批次 finalize 竞争校验失败', 409);
        }
        $readback = $this->ensureReadbackVerified($this->read($id));
        $readback['created'] = true;
        $readback['persistence_status'] = 'readback_verified';
        return $readback;
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $result */
    public function save(
        string $clientRunKey,
        string $evaluationSet,
        string $modelKey,
        array $filters,
        array $result,
        int $createdBy
    ): array {
        $this->assertReady();
        $clientRunKey = trim($clientRunKey);
        $evaluationSet = trim($evaluationSet);
        $modelKey = trim($modelKey);
        if (preg_match('/^[A-Za-z0-9_.:-]{8,80}$/D', $clientRunKey) !== 1) {
            throw new InvalidArgumentException('client_run_key 格式无效');
        }
        if ($evaluationSet === '' || mb_strlen($evaluationSet) > 120) {
            throw new InvalidArgumentException('evaluation_set 必填且不能超过120字');
        }
        if ($modelKey === '' || mb_strlen($modelKey) > 100) {
            throw new InvalidArgumentException('model_key 必填且不能超过100字');
        }

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $cases = is_array($result['cases'] ?? null) ? $result['cases'] : [];
        $dryRun = ($result['dry_run'] ?? true) === true;
        $status = $this->status($dryRun, $summary);
        $createdBy = max(0, $createdBy);
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'client_run_key' => $clientRunKey,
            'evaluation_set' => $evaluationSet,
            'model_key' => $modelKey,
            'filters' => $filters,
            'dry_run' => $dryRun,
            'status' => $status,
            'summary' => $summary,
            'cases' => $cases,
            'result' => $result,
            'created_by' => $createdBy,
        ];
        $digest = $this->digest($payload);
        $existing = Db::name(self::TABLE)->where('client_run_key', $clientRunKey)->find();
        if (is_array($existing)) {
            $readback = $this->normalize($existing);
            if (!hash_equals((string)$readback['result_digest'], $digest)
                || (string)$readback['evaluation_set'] !== $evaluationSet
                || (string)$readback['model_key'] !== $modelKey
            ) {
                throw new RuntimeException('client_run_key 已被不同评测批次占用', 409);
            }
            $readback = $this->ensureReadbackVerified($readback);
            $readback['created'] = false;
            $readback['persistence_status'] = 'readback_verified';
            return $readback;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $id = (int)Db::name(self::TABLE)->insertGetId([
                'client_run_key' => $clientRunKey,
                'evaluation_set' => $evaluationSet,
                'model_key' => $modelKey,
                'filters_json' => $this->encode($filters),
                'dry_run' => $dryRun ? 1 : 0,
                'status' => $status,
                'summary_json' => $this->encode($summary),
                'cases_json' => $this->encode($cases),
                'result_json' => $this->encode($result),
                'result_digest' => $digest,
                'created_by' => $createdBy,
                'readback_verified' => 0,
                'created_at' => $now,
                'completed_at' => $now,
            ]);
        } catch (Throwable $e) {
            $concurrent = Db::name(self::TABLE)->where('client_run_key', $clientRunKey)->find();
            if (!is_array($concurrent)) {
                throw $e;
            }
            $readback = $this->normalize($concurrent);
            if (!hash_equals((string)$readback['result_digest'], $digest)
                || (int)$readback['created_by'] !== $createdBy
            ) {
                throw new RuntimeException('client_run_key 已被不同评测批次占用', 409, $e);
            }
            $readback = $this->ensureReadbackVerified($readback);
            $readback['created'] = false;
            $readback['persistence_status'] = 'readback_verified';
            return $readback;
        }
        if ($id <= 0) {
            throw new RuntimeException('评测批次保存失败');
        }

        $readback = $this->ensureReadbackVerified($this->read($id));
        $readback['created'] = true;
        $readback['persistence_status'] = 'readback_verified';
        return $readback;
    }

    public function read(int $id): array
    {
        $this->assertReady();
        if ($id <= 0) {
            throw new InvalidArgumentException('评测批次ID无效');
        }
        $row = Db::name(self::TABLE)->where('id', $id)->find();
        if (!is_array($row)) {
            throw new RuntimeException('evaluation run not found', 404);
        }
        $readback = $this->normalize($row);
        $this->assertDigest($readback);
        return $readback;
    }

    public function findByClientRunKey(string $clientRunKey): ?array
    {
        $this->assertReady();
        $clientRunKey = trim($clientRunKey);
        if (preg_match('/^[A-Za-z0-9_.:-]{8,80}$/D', $clientRunKey) !== 1) {
            throw new InvalidArgumentException('client_run_key 格式无效');
        }
        $row = Db::name(self::TABLE)->where('client_run_key', $clientRunKey)->find();
        if (!is_array($row)) {
            return null;
        }
        $readback = $this->normalize($row);
        $readback = $this->ensureReadbackVerified($readback);
        $readback['persistence_status'] = 'readback_verified';
        return $readback;
    }

    /** @return array<string,mixed> */
    public function list(string $evaluationSet = '', int $limit = 50): array
    {
        $this->assertReady();
        $query = Db::name(self::TABLE);
        $evaluationSet = trim($evaluationSet);
        if ($evaluationSet !== '') {
            $query->where('evaluation_set', $evaluationSet);
        }
        $rows = $query->order('id', 'desc')->limit(max(1, min(100, $limit)))->select()->toArray();
        $list = [];
        foreach ($rows as $row) {
            $item = $this->normalize($row);
            $this->assertDigest($item);
            $list[] = $item;
        }
        return ['list' => $list, 'count' => count($list)];
    }

    /** @param array<string,mixed> $row */
    private function normalize(array $row): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'id' => (int)($row['id'] ?? 0),
            'client_run_key' => (string)($row['client_run_key'] ?? ''),
            'evaluation_set' => (string)($row['evaluation_set'] ?? ''),
            'model_key' => (string)($row['model_key'] ?? ''),
            'filters' => $this->decode($row['filters_json'] ?? null),
            'dry_run' => (int)($row['dry_run'] ?? 1) === 1,
            'status' => (string)($row['status'] ?? ''),
            'summary' => $this->decode($row['summary_json'] ?? null),
            'cases' => $this->decode($row['cases_json'] ?? null),
            'result' => $this->decode($row['result_json'] ?? null),
            'result_digest' => (string)($row['result_digest'] ?? ''),
            'created_by' => (int)($row['created_by'] ?? 0),
            'readback_verified' => (int)($row['readback_verified'] ?? 0) === 1,
            'lease_expires_at' => isset($row['lease_expires_at'])
                && trim((string)$row['lease_expires_at']) !== ''
                ? (string)$row['lease_expires_at']
                : null,
            'created_at' => (string)($row['created_at'] ?? ''),
            'completed_at' => (string)($row['completed_at'] ?? ''),
        ];
    }

    private function assertDigest(array $readback): void
    {
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'client_run_key' => (string)$readback['client_run_key'],
            'evaluation_set' => (string)$readback['evaluation_set'],
            'model_key' => (string)$readback['model_key'],
            'filters' => (array)$readback['filters'],
            'dry_run' => (bool)$readback['dry_run'],
            'status' => (string)$readback['status'],
            'summary' => (array)$readback['summary'],
            'cases' => (array)$readback['cases'],
            'result' => (array)$readback['result'],
            'created_by' => (int)$readback['created_by'],
        ];
        if (!hash_equals((string)$readback['result_digest'], $this->digest($payload))) {
            throw new RuntimeException('评测批次回读摘要不一致');
        }
    }

    /** @param array<string,mixed> $readback @return array<string,mixed> */
    private function ensureReadbackVerified(array $readback): array
    {
        $this->assertDigest($readback);
        if (($readback['readback_verified'] ?? false) !== true) {
            Db::name(self::TABLE)
                ->where('id', (int)$readback['id'])
                ->where('result_digest', (string)$readback['result_digest'])
                ->update(['readback_verified' => 1]);
            $readback = $this->read((int)$readback['id']);
        }
        if (($readback['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('评测批次保存后精确回读失败');
        }
        return $readback;
    }

    /** @return array{0:string,1:string,2:string,3:int} */
    private function validatedIdentity(
        string $clientRunKey,
        string $evaluationSet,
        string $modelKey,
        int $createdBy
    ): array {
        $clientRunKey = trim($clientRunKey);
        $evaluationSet = trim($evaluationSet);
        $modelKey = trim($modelKey);
        if (preg_match('/^[A-Za-z0-9_.:-]{8,80}$/D', $clientRunKey) !== 1) {
            throw new InvalidArgumentException('client_run_key 格式无效');
        }
        if ($evaluationSet === '' || mb_strlen($evaluationSet) > 120) {
            throw new InvalidArgumentException('evaluation_set 必填且不能超过120字');
        }
        if ($modelKey === '' || mb_strlen($modelKey) > 100) {
            throw new InvalidArgumentException('model_key 必填且不能超过100字');
        }
        return [$clientRunKey, $evaluationSet, $modelKey, max(0, $createdBy)];
    }

    /** @param array<string,mixed> $filters @param array<string,mixed> $summary @param array<int,mixed> $cases @param array<string,mixed> $result @return array<string,mixed> */
    private function runPayload(
        string $clientRunKey,
        string $evaluationSet,
        string $modelKey,
        array $filters,
        bool $dryRun,
        string $status,
        array $summary,
        array $cases,
        array $result,
        int $createdBy
    ): array {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'client_run_key' => $clientRunKey,
            'evaluation_set' => $evaluationSet,
            'model_key' => $modelKey,
            'filters' => $filters,
            'dry_run' => $dryRun,
            'status' => $status,
            'summary' => $summary,
            'cases' => $cases,
            'result' => $result,
            'created_by' => $createdBy,
        ];
    }

    /** @return array{token:string,token_hash:string,lease_expires_at:string,result:array<string,mixed>} */
    private function newClaim(
        \DateTimeImmutable $now,
        int $leaseSeconds,
        bool $dryRun,
        bool $allowExternalModelCall
    ): array {
        $token = bin2hex(random_bytes(32));
        $leaseExpiresAt = $now->modify('+' . $leaseSeconds . ' seconds')->format('Y-m-d H:i:s');
        $reservationId = bin2hex(random_bytes(16));
        return [
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'lease_expires_at' => $leaseExpiresAt,
            'result' => [
                'reservation_contract_version' => self::RESERVATION_CONTRACT_VERSION,
                'reservation_id' => $reservationId,
                'lease_expires_at' => $leaseExpiresAt,
                'dry_run' => $dryRun,
                'allow_external_model_call' => $allowExternalModelCall,
            ],
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $filters @return array<string,mixed> */
    private function reuseOrTakeOverReservation(
        array $row,
        string $clientRunKey,
        string $evaluationSet,
        string $modelKey,
        array $filters,
        bool $dryRun,
        bool $allowExternalModelCall,
        int $createdBy,
        \DateTimeImmutable $now,
        int $leaseSeconds
    ): array {
        $readback = $this->normalize($row);
        $this->assertDigest($readback);
        $this->assertReservationIdentity(
            $readback,
            $evaluationSet,
            $modelKey,
            $filters,
            $dryRun,
            $allowExternalModelCall,
            $createdBy
        );
        if ((string)$readback['status'] !== 'running') {
            $readback = $this->ensureReadbackVerified($readback);
            $readback['created'] = false;
            $readback['persistence_status'] = 'readback_verified';
            return ['state' => 'completed', 'claim_token' => null, 'run' => $readback];
        }

        $nowText = $now->format('Y-m-d H:i:s');
        $oldLease = trim((string)($row['lease_expires_at'] ?? ''));
        $oldClaimHash = strtolower(trim((string)($row['claim_token_hash'] ?? '')));
        $reservationResult = is_array($readback['result'] ?? null) ? $readback['result'] : [];
        if (($reservationResult['reservation_contract_version'] ?? '') !== self::RESERVATION_CONTRACT_VERSION
            || (string)($reservationResult['lease_expires_at'] ?? '') !== $oldLease
            || preg_match('/^[a-f0-9]{64}$/D', $oldClaimHash) !== 1
        ) {
            throw new RuntimeException('AI评测批次 reservation 身份损坏', 409);
        }
        if ($oldLease > $nowText) {
            throw new RuntimeException('client_run_key 评测批次正在执行', 409);
        }

        $claim = $this->newClaim($now, $leaseSeconds, $dryRun, $allowExternalModelCall);
        $payload = $this->runPayload(
            $clientRunKey,
            $evaluationSet,
            $modelKey,
            $filters,
            $dryRun,
            'running',
            [],
            [],
            $claim['result'],
            $createdBy
        );
        $digest = $this->digest($payload);
        $affected = (int)Db::name(self::TABLE)
            ->where('id', (int)$readback['id'])
            ->where('status', 'running')
            ->where('claim_token_hash', $oldClaimHash)
            ->where('lease_expires_at', $oldLease)
            ->where('result_digest', (string)$readback['result_digest'])
            ->update([
                'claim_token_hash' => $claim['token_hash'],
                'lease_expires_at' => $claim['lease_expires_at'],
                'result_json' => $this->encode($claim['result']),
                'result_digest' => $digest,
                'readback_verified' => 0,
                'completed_at' => null,
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('client_run_key 评测批次状态已变化，请重试', 409);
        }
        return $this->readClaimedReservation((int)$readback['id'], $claim['token'], $digest, false);
    }

    /** @return array<string,mixed> */
    private function readClaimedReservation(int $id, string $claimToken, string $digest, bool $created): array
    {
        $claimHash = hash('sha256', $claimToken);
        $row = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('status', 'running')
            ->where('claim_token_hash', $claimHash)
            ->where('result_digest', $digest)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('AI评测批次 reservation 精确回读失败', 409);
        }
        $readback = $this->normalize($row);
        $this->assertDigest($readback);
        $reservationResult = is_array($readback['result'] ?? null) ? $readback['result'] : [];
        if (($reservationResult['reservation_contract_version'] ?? '') !== self::RESERVATION_CONTRACT_VERSION
            || (string)($reservationResult['lease_expires_at'] ?? '') !== (string)($row['lease_expires_at'] ?? '')
        ) {
            throw new RuntimeException('AI评测批次 reservation 精确回读身份不一致', 409);
        }
        if (($readback['readback_verified'] ?? false) !== true) {
            $updated = (int)Db::name(self::TABLE)
                ->where('id', $id)
                ->where('status', 'running')
                ->where('claim_token_hash', $claimHash)
                ->where('result_digest', $digest)
                ->update(['readback_verified' => 1]);
            if ($updated !== 1) {
                throw new RuntimeException('AI评测批次 reservation 回读标记竞争校验失败', 409);
            }
            $row = Db::name(self::TABLE)
                ->where('id', $id)
                ->where('status', 'running')
                ->where('claim_token_hash', $claimHash)
                ->where('result_digest', $digest)
                ->find();
            if (!is_array($row)) {
                throw new RuntimeException('AI评测批次 reservation 回读标记后身份丢失', 409);
            }
            $readback = $this->normalize($row);
            $this->assertDigest($readback);
        }
        if (($readback['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('AI评测批次 reservation 未通过精确回读', 409);
        }
        return [
            'state' => 'claimed',
            'reservation_id' => $id,
            'claim_token' => $claimToken,
            'created' => $created,
            'persistence_status' => 'readback_verified',
            'run' => $readback,
        ];
    }

    /** @param array<string,mixed> $readback @param array<string,mixed> $filters */
    private function assertReservationIdentity(
        array $readback,
        string $evaluationSet,
        string $modelKey,
        array $filters,
        bool $dryRun,
        bool $allowExternalModelCall,
        int $createdBy
    ): void {
        if ((string)$readback['evaluation_set'] !== $evaluationSet
            || (string)$readback['model_key'] !== $modelKey
            || (array)$readback['filters'] !== $filters
            || (bool)$readback['dry_run'] !== $dryRun
            || (int)$readback['created_by'] !== $createdBy
            || (($readback['result']['allow_external_model_call'] ?? null) !== $allowExternalModelCall)
        ) {
            throw new RuntimeException('client_run_key 已被不同参数的评测批次占用', 409);
        }
    }

    /** @param array<string,mixed> $summary */
    private function status(bool $dryRun, array $summary): string
    {
        if ($dryRun) {
            return (int)($summary['blocked'] ?? 0) > 0 ? 'planned_with_blockers' : 'planned';
        }
        if ((int)($summary['executed'] ?? 0) <= 0) {
            return 'blocked';
        }
        return (int)($summary['blocked'] ?? 0) > 0 ? 'partial' : 'completed';
    }

    private function assertReady(): void
    {
        $inspection = DatabaseSchemaRequirement::inspectTable(self::TABLE);
        if ($inspection['status'] === DatabaseSchemaRequirement::STATUS_MISSING) {
            throw new RuntimeException('AI评测批次表尚未迁移', 503);
        }
        if ($inspection['status'] !== DatabaseSchemaRequirement::STATUS_PRESENT) {
            throw new RuntimeException('AI评测批次表结构检查失败', 503);
        }
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonical($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }
}
