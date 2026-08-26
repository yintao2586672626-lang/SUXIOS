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
