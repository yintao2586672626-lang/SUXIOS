<?php
declare(strict_types=1);

namespace app\controller;

use app\model\KnowledgeChunk;
use app\model\KnowledgeUnit;
use app\service\KnowledgeDistillationService;
use InvalidArgumentException;
use think\exception\ValidateException;
use think\facade\Db;
use think\Response;

class Knowledge extends Base
{
    private const STATUSES = ['pending', 'done', 'error'];

    public function unitList(): Response
    {
        try {
            $pagination = $this->getPagination();
            $status = trim((string)$this->request->param('status', ''));
            $source = trim((string)$this->request->param('source', ''));
            $keyword = trim((string)$this->request->param('keyword', ''));
            $tags = $this->normalizeTags($this->request->param('tags', $this->request->param('tag', [])));

            $query = KnowledgeUnit::order('unit_id', 'desc');
            $this->applyOwnerScope($query);

            if ($status !== '') {
                if (!in_array($status, self::STATUSES, true)) {
                    return $this->fail('status must be pending, done or error', 422);
                }
                $query->where('status', $status);
            }
            if ($source !== '') {
                $query->where('source', $source);
            }
            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->whereLike('name', '%' . $keyword . '%')
                        ->whereOrLike('description', '%' . $keyword . '%');
                });
            }
            foreach ($tags as $tag) {
                $query->whereRaw('JSON_CONTAINS(COALESCE(`tags`, JSON_ARRAY()), JSON_QUOTE(:tag))', ['tag' => $tag]);
            }

            $total = (clone $query)->count();
            $rows = $query->page($pagination['page'], $pagination['page_size'])->select()->toArray();
            $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['unit_id'] ?? 0), $rows)));
            $chunkCounts = [];
            if ($ids) {
                $countRows = KnowledgeChunk::whereIn('unit_id', $ids)
                    ->field('unit_id, COUNT(*) AS total')
                    ->group('unit_id')
                    ->select()
                    ->toArray();
                foreach ($countRows as $countRow) {
                    $chunkCounts[(int)$countRow['unit_id']] = (int)$countRow['total'];
                }
            }

            return $this->ok([
                'list' => array_map(
                    fn(array $row): array => $this->formatUnitRow($row, (int)($chunkCounts[$row['unit_id']] ?? 0)),
                    $rows
                ),
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $pagination['page'],
                    'page_size' => $pagination['page_size'],
                    'total_page' => (int)ceil(((int)$total) / $pagination['page_size']),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Failed to load knowledge units: ' . $e->getMessage(), 500);
        }
    }

    public function detail(int $unit_id): Response
    {
        try {
            $unit = $this->findAccessibleUnit($unit_id);
            if (!$unit) {
                return $this->fail('Knowledge unit not found', 404);
            }

            $chunks = KnowledgeChunk::where('unit_id', $unit_id)->order('chunk_id', 'asc')->select()->toArray();

            return $this->ok([
                'unit' => $this->formatUnitRow($unit->toArray(), count($chunks)),
                'chunks' => array_map(fn(array $row): array => $this->formatChunkRow($row), $chunks),
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Failed to load knowledge unit: ' . $e->getMessage(), 500);
        }
    }

    public function add(): Response
    {
        try {
            $data = $this->normalizeUnitData($this->requestData(), true);
            $data['created_by'] = $this->currentUserId();
            $unit = KnowledgeUnit::create($data);

            return $this->ok(['unit' => $this->formatUnitRow($unit->toArray(), 0)], 'created');
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Failed to create knowledge unit: ' . $e->getMessage(), 500);
        }
    }

    public function addChunk(int $unit_id): Response
    {
        try {
            $unit = $this->findAccessibleUnit($unit_id);
            if (!$unit) {
                return $this->fail('Knowledge unit not found', 404);
            }

            $data = $this->normalizeChunkData($this->requestData(), $unit_id);
            $data['created_by'] = $this->currentUserId();
            $chunk = KnowledgeChunk::create($data);

            return $this->ok(['chunk' => $this->formatChunkRow($chunk->toArray())], 'created');
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Failed to create knowledge chunk: ' . $e->getMessage(), 500);
        }
    }

    public function update(int $unit_id): Response
    {
        try {
            $unit = $this->findAccessibleUnit($unit_id);
            if (!$unit) {
                return $this->fail('Knowledge unit not found', 404);
            }

            $data = $this->normalizeUnitData($this->requestData(), false);
            if (!empty($data)) {
                $unit->save($data);
            }

            $chunkCount = KnowledgeChunk::where('unit_id', $unit_id)->count();
            return $this->ok(['unit' => $this->formatUnitRow($unit->toArray(), (int)$chunkCount)], 'updated');
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Failed to update knowledge unit: ' . $e->getMessage(), 500);
        }
    }

    public function status(int $unit_id): Response
    {
        try {
            $unit = $this->findAccessibleUnit($unit_id);
            if (!$unit) {
                return $this->fail('Knowledge unit not found', 404);
            }

            $status = trim((string)($this->requestData()['status'] ?? ''));
            if (!in_array($status, self::STATUSES, true)) {
                return $this->fail('status must be pending, done or error', 422);
            }

            $unit->save(['status' => $status]);
            $chunkCount = KnowledgeChunk::where('unit_id', $unit_id)->count();

            return $this->ok(['unit' => $this->formatUnitRow($unit->toArray(), (int)$chunkCount)], 'updated');
        } catch (\Throwable $e) {
            return $this->fail('Failed to update knowledge status: ' . $e->getMessage(), 500);
        }
    }

    public function delete(int $unit_id): Response
    {
        try {
            $unit = $this->findAccessibleUnit($unit_id);
            if (!$unit) {
                return $this->fail('Knowledge unit not found', 404);
            }

            Db::transaction(function () use ($unit_id, $unit): void {
                KnowledgeChunk::where('unit_id', $unit_id)->delete();
                $unit->delete();
            });

            return $this->ok(['unit_id' => $unit_id], 'deleted');
        } catch (\Throwable $e) {
            return $this->fail('Failed to delete knowledge unit: ' . $e->getMessage(), 500);
        }
    }

    public function distillationOptions(): Response
    {
        try {
            return $this->ok(['options' => (new KnowledgeDistillationService())->options()]);
        } catch (\Throwable $e) {
            return $this->fail('Failed to load distillation options: ' . $e->getMessage(), 500);
        }
    }

    public function runDistillation(): Response
    {
        try {
            $data = $this->requestData();
            $mode = trim((string)($data['mode'] ?? 'kd'));
            $maxBatches = (int)($data['max_batches'] ?? 1);
            $result = (new KnowledgeDistillationService())->run($mode, $maxBatches);

            if (($result['ok'] ?? false) !== true) {
                return $this->fail('Knowledge distillation training failed', 500, $result);
            }

            $result['knowledge_unit'] = $this->persistDistillationKnowledge($result, $this->currentUserId());

            return $this->ok($result, 'training completed');
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Failed to run knowledge distillation: ' . $e->getMessage(), 500);
        }
    }

    private function persistDistillationKnowledge(array $result, int $userId): array
    {
        $content = is_array($result['distilled_content'] ?? null) ? $result['distilled_content'] : [];
        $mode = (string)($result['mode'] ?? 'kd');
        $label = (string)($result['label'] ?? $mode);
        $summary = (string)($content['summary'] ?? '知识蒸馏训练结果');
        $method = (string)($content['method'] ?? ($mode === 'kd' ? 'vanilla_kd' : 'baseline_ce'));
        $name = '知识蒸馏训练结果 - ' . $label . ' - ' . date('Y-m-d H:i');

        $unit = null;
        $chunk = null;
        Db::transaction(function () use (&$unit, &$chunk, $name, $summary, $method, $content, $userId): void {
            $unit = KnowledgeUnit::create([
                'name' => $name,
                'source' => 'ml_distillation',
                'status' => 'done',
                'description' => $summary,
                'tags' => ['知识蒸馏', '模型训练', $method],
                'created_by' => $userId,
            ]);

            $chunk = KnowledgeChunk::create([
                'unit_id' => (int)$unit->unit_id,
                'type' => '模型蒸馏训练结果',
                'content' => $content,
                'created_by' => $userId,
            ]);
        });

        if (!$unit || !$chunk) {
            throw new \RuntimeException('Failed to persist distillation knowledge content');
        }

        return [
            'unit' => $this->formatUnitRow($unit->toArray(), 1),
            'chunk' => $this->formatChunkRow($chunk->toArray()),
        ];
    }

    private function findAccessibleUnit(int $unitId): ?KnowledgeUnit
    {
        $unit = KnowledgeUnit::find($unitId);
        if (!$unit || !$this->canAccessOwnedRow($unit->toArray())) {
            return null;
        }

        return $unit;
    }

    private function applyOwnerScope($query): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $query->where('created_by', $this->currentUserId());
    }

    private function canAccessOwnedRow(array $row): bool
    {
        return $this->isSuperAdmin() || (int)($row['created_by'] ?? 0) === $this->currentUserId();
    }

    private function currentUserId(): int
    {
        $userId = (int)($this->currentUser->id ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException('Please login');
        }

        return $userId;
    }

    private function isSuperAdmin(): bool
    {
        return $this->currentUser && $this->currentUser->isSuperAdmin();
    }

    private function normalizeUnitData(array $input, bool $creating): array
    {
        $data = [];

        if ($creating || array_key_exists('name', $input)) {
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                throw new ValidateException('name is required');
            }
            $data['name'] = mb_substr($name, 0, 255);
        }

        if (array_key_exists('source', $input)) {
            $data['source'] = mb_substr(trim((string)$input['source']), 0, 50);
        } elseif ($creating) {
            $data['source'] = '';
        }

        if (array_key_exists('status', $input)) {
            $status = trim((string)$input['status']);
            if ($status !== '' && !in_array($status, self::STATUSES, true)) {
                throw new ValidateException('status must be pending, done or error');
            }
            $data['status'] = $status !== '' ? $status : 'pending';
        } elseif ($creating) {
            $data['status'] = 'pending';
        }

        if (array_key_exists('description', $input)) {
            $data['description'] = trim((string)$input['description']);
        } elseif ($creating) {
            $data['description'] = '';
        }

        if (array_key_exists('tags', $input)) {
            $data['tags'] = $this->normalizeTags($input['tags']);
        } elseif ($creating) {
            $data['tags'] = [];
        }

        return $data;
    }

    private function normalizeChunkData(array $input, int $unitId): array
    {
        $type = mb_substr(trim((string)($input['type'] ?? 'manual')), 0, 50);
        $content = $input['content'] ?? null;

        if ($content === null && array_key_exists('text', $input)) {
            $content = ['text' => (string)$input['text']];
        }
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = json_last_error() === JSON_ERROR_NONE ? $decoded : ['text' => $content];
        }
        if (!is_array($content)) {
            throw new ValidateException('content must be a JSON object or array');
        }

        return [
            'unit_id' => $unitId,
            'type' => $type !== '' ? $type : 'manual',
            'content' => $content,
        ];
    }

    private function normalizeTags($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/[,，\s]+/u', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $tag) {
            $normalized = mb_substr(trim((string)$tag), 0, 50);
            if ($normalized !== '') {
                $tags[$normalized] = $normalized;
            }
        }

        return array_values($tags);
    }

    private function formatUnitRow(array $row, ?int $chunkCount = null): array
    {
        $tags = $row['tags'] ?? [];
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : [];
        }

        return [
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'description' => (string)($row['description'] ?? ''),
            'tags' => array_values(is_array($tags) ? $tags : []),
            'chunk_count' => $chunkCount ?? (int)($row['chunk_count'] ?? 0),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function formatChunkRow(array $row): array
    {
        $content = $row['content'] ?? [];
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = is_array($decoded) ? $decoded : ['text' => $content];
        }

        return [
            'chunk_id' => (int)($row['chunk_id'] ?? 0),
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'type' => (string)($row['type'] ?? ''),
            'content' => is_array($content) ? $content : [],
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function ok($data = null, string $msg = ''): Response
    {
        return json(['code' => 0, 'data' => $data, 'msg' => $msg]);
    }

    private function fail(string $msg, int $httpStatus = 400, $data = null): Response
    {
        return json(['code' => $httpStatus, 'data' => $data, 'msg' => $msg], $httpStatus);
    }
}
