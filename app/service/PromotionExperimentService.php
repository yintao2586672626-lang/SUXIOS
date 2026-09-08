<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

final class PromotionExperimentService
{
    public const TABLE = 'promotion_experiment_versions';

    public function save(array $scope, int $actorId, array $request): array
    {
        $scope = PaidTrafficReturnService::scope($scope);
        if ($actorId <= 0) throw new InvalidArgumentException('缺少保存人');
        $key = $this->key($request['experiment_key'] ?? '');
        $idempotency = $this->key($request['idempotency_key'] ?? '');
        $expected = filter_var($request['expected_version'] ?? null, FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 0) throw new InvalidArgumentException('请提供有效的预期版本');
        $input = $request['input'] ?? null;
        if (!is_array($input) || ($input['scope'] ?? null) !== $scope) throw new InvalidArgumentException('输入范围与当前请求不一致');
        $digest = hash('sha256', $this->encode([$key, $expected, $input]));
        $prior = $this->tenantQuery($scope)->where('idempotency_key', $idempotency)->find();
        if ($prior) return $this->replay($scope, $prior, $digest);
        $result = (new PromotionExperimentAssessmentService())->evaluate($input);
        $payload = $this->encode(['schema_version' => PromotionExperimentAssessmentService::VERSION, 'input' => $input, 'result' => $result]);
        if (strlen($payload) > 2000000) throw new InvalidArgumentException('实验记录过大');
        try {
            return Db::transaction(function () use ($scope, $actorId, $key, $idempotency, $expected, $digest, $payload) {
                $latest = $this->tenantQuery($scope)->where('experiment_key', $key)->order('version_no', 'desc')->find();
                if ($latest && $this->read($scope, (int)$latest['id'])['scope'] !== $scope) throw new RuntimeException('实验范围冲突', 409);
                if ((int)($latest['version_no'] ?? 0) !== $expected) throw new RuntimeException('版本已更新，请先回读最新版本后再保存', 409);
                $id = (int)Db::name(self::TABLE)->insertGetId($scope + [
                    'experiment_key' => $key, 'version_no' => $expected + 1, 'idempotency_key' => $idempotency,
                    'request_digest' => $digest, 'payload_digest' => hash('sha256', $payload), 'payload_json' => $payload,
                    'actor_id' => $actorId, 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $readback = $this->read($scope, $id);
                if (!hash_equals(hash('sha256', $payload), $readback['payload_digest'])) throw new RuntimeException('实验保存后回读不一致', 409);
                return $readback + ['idempotent_replay' => false];
            });
        } catch (Throwable $e) {
            // Unique keys serialize concurrent first saves and retries without overwriting versions.
            $prior = $this->tenantQuery($scope)->where('idempotency_key', $idempotency)->find();
            if ($prior) return $this->replay($scope, $prior, $digest);
            $latest = $this->tenantQuery($scope)->where('experiment_key', $key)->max('version_no');
            if ((int)$latest > $expected) throw new RuntimeException('版本已更新，请先回读最新版本后再保存', 409);
            throw $e;
        }
    }

    public function read(array $scope, int $id): array
    {
        $scope = PaidTrafficReturnService::scope($scope);
        $row = $this->scopeQuery($scope)->where('id', $id)->find();
        if (!$row) throw new RuntimeException('当前范围内不存在该实验版本', 404);
        $json = (string)$row['payload_json'];
        if (!hash_equals((string)$row['payload_digest'], hash('sha256', $json))) throw new RuntimeException('实验版本完整性校验失败', 409);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($payload['schema_version'] ?? '') !== PromotionExperimentAssessmentService::VERSION
            || ($payload['input']['scope'] ?? null) != $scope || ($payload['result']['scope'] ?? null) != $scope) throw new RuntimeException('实验版本或范围不受支持', 409);
        return ['id' => (int)$row['id'], 'scope' => $scope, 'experiment_key' => (string)$row['experiment_key'],
            'version_no' => (int)$row['version_no'], 'created_at' => $row['created_at'], 'actor_id' => (int)$row['actor_id'],
            'payload_digest' => $row['payload_digest'], 'readback_status' => 'exact',
            'input' => $payload['input'], 'result' => $payload['result']];
    }

    public function history(array $scope): array
    {
        $scope = PaidTrafficReturnService::scope($scope);
        $rows = $this->scopeQuery($scope)->field('id')->order('id', 'desc')->limit(51)->select()->toArray();
        $items = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            $v = $this->read($scope, (int)$row['id']);
            $items[] = array_intersect_key($v, array_flip(['id', 'experiment_key', 'version_no', 'created_at', 'payload_digest']))
                + ['name' => $v['result']['plan']['name'], 'incrementality_status' => $v['result']['incrementality']['status']];
        }
        return ['scope' => $scope, 'items' => $items, 'truncated' => count($rows) > 50, 'status' => $items ? 'ready' : 'empty'];
    }

    private function replay(array $scope, array $row, string $digest): array
    {
        if (!hash_equals((string)$row['request_digest'], $digest)) throw new RuntimeException('重复提交标识对应不同内容，请回读后重试', 409);
        return $this->read($scope, (int)$row['id']) + ['idempotent_replay' => true];
    }

    private function tenantQuery(array $s): \think\db\Query
    {
        return Db::name(self::TABLE)->where('tenant_id', $s['tenant_id'])->where('system_hotel_id', $s['system_hotel_id']);
    }

    private function scopeQuery(array $s): \think\db\Query { return Db::name(self::TABLE)->where($s); }

    private function key(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $v)) throw new InvalidArgumentException('实验或提交标识无效');
        return $v;
    }

    private function encode(array $value): string
    {
        $sort = function (array $a) use (&$sort): array {
            if (!array_is_list($a)) ksort($a);
            foreach ($a as $k => $v) if (is_array($v)) $a[$k] = $sort($v);
            return $a;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
