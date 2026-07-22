<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Hotel;
use app\model\KnowledgeChunk;
use app\model\KnowledgeUnit;
use app\service\KnowledgeDocumentTextExtractor;
use app\service\KnowledgeDistillationService;
use app\service\KnowledgeMaterialIngestionService;
use app\service\KnowledgePayloadMapper;
use app\service\OperationManagementService;
use InvalidArgumentException;
use think\exception\ValidateException;
use think\facade\Db;
use think\Response;

class Knowledge extends Base
{
    private const STATUSES = ['pending', 'done', 'error'];
    private const MAX_IMPORT_MATERIALS = 20;
    private const MAX_DOCUMENT_BYTES = 5242880;

    private ?KnowledgePayloadMapper $payloadMapper = null;

    public function unitList(): Response
    {
        try {
            $pagination = $this->getPagination();
            $status = trim((string)$this->request->param('status', ''));
            $source = trim((string)$this->request->param('source', ''));
            $keyword = trim((string)$this->request->param('keyword', ''));
            $hotelId = (int)$this->request->param('hotel_id', 0);
            $tags = $this->normalizeTags($this->request->param('tags', $this->request->param('tag', [])));
            $chunkFilters = [
                'module' => trim((string)$this->request->param('module', '')),
                'role' => trim((string)$this->request->param('role', '')),
                'scene' => trim((string)$this->request->param('scene', '')),
                'platform' => strtolower(trim((string)$this->request->param('platform', ''))),
                'evidence_level' => trim((string)$this->request->param('evidence_level', '')),
                'version' => trim((string)$this->request->param('version', '')),
            ];
            $hasChunkFilters = count(array_filter($chunkFilters, static fn(string $value): bool => $value !== '')) > 0;
            $chunkMatchedUnitIds = ($keyword !== '' || $hasChunkFilters)
                ? $this->knowledgeChunkMatchingUnitIds($keyword, $chunkFilters)
                : [];

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
            if ($hotelId > 0 && $this->knowledgeUnitHasHotelColumn()) {
                $query->where('hotel_id', $hotelId);
            }
            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword, $chunkMatchedUnitIds) {
                    $q->whereLike('name', '%' . $keyword . '%')
                        ->whereOrLike('description', '%' . $keyword . '%');
                    if ($chunkMatchedUnitIds !== []) {
                        $q->whereOr(function ($subQuery) use ($chunkMatchedUnitIds): void {
                            $subQuery->whereIn('unit_id', $chunkMatchedUnitIds);
                        });
                    }
                });
            }
            if ($hasChunkFilters) {
                $chunkMatchedUnitIds === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('unit_id', $chunkMatchedUnitIds);
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
            if (!$this->knowledgeUnitHasHotelColumn()) {
                throw new ValidateException('knowledge hotel scope is unavailable');
            }
            $data['hotel_id'] = $this->resolveKnowledgeImportHotelId((int)($data['hotel_id'] ?? 0));
            $data['source'] = 'manual';
            $data['status'] = 'pending';
            $data['created_by'] = $this->currentUserId();
            $unit = KnowledgeUnit::create($data);

            return $this->ok(['unit' => $this->formatUnitRow($unit->toArray(), 0)], 'created');
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Failed to create knowledge unit: ' . $e->getMessage(), 500);
        }
    }

    public function importMaterials(): Response
    {
        try {
            $data = $this->requestData();
            $mode = mb_substr(strtolower(trim((string)($data['mode'] ?? 'link'))), 0, 40);
            $source = mb_substr(trim((string)($data['source'] ?? $mode)), 0, 50);
            $raw = trim((string)($data['raw'] ?? ''));
            $modelKey = trim((string)($data['model_key'] ?? 'deepseek_chat'));
            $tags = $this->normalizeTags($data['tags'] ?? []);

            if ($mode === '') {
                $mode = 'link';
            }
            if ($source === '') {
                $source = $mode;
            }
            if ($modelKey === '') {
                $modelKey = 'deepseek_chat';
            }
            if ($raw === '') {
                return $this->fail('请输入需要导入的门店资料', 422);
            }

            $hotelId = $this->resolveKnowledgeImportHotelId((int)($data['hotel_id'] ?? 0));
            $hotelName = $this->resolveKnowledgeHotelName($hotelId);
            $userId = $this->currentUserId();
            $service = new KnowledgeMaterialIngestionService();
            $materials = $service->splitRawMaterials($raw, $mode);
            if (empty($materials)) {
                return $this->fail('没有可导入的资料内容', 422);
            }
            if (count($materials) > self::MAX_IMPORT_MATERIALS) {
                return $this->fail('单次最多导入 ' . self::MAX_IMPORT_MATERIALS . ' 条资料，请拆分后重试', 422);
            }

            $created = [];
            $errors = [];
            foreach ($materials as $material) {
                try {
                    $distilled = $service->distillMaterial([
                        'mode' => $mode,
                        'source' => $source,
                        'content' => $material,
                        'hotel_id' => $hotelId,
                        'hotel_name' => $hotelName,
                        'model_key' => $modelKey,
                    ]);
                    $created[] = $this->persistImportedKnowledgeMaterial(
                        $distilled,
                        $material,
                        $mode,
                        $source,
                        $tags,
                        $hotelId,
                        $hotelName,
                        $userId,
                        $modelKey,
                        'done',
                        ''
                    );
                } catch (\Throwable $e) {
                    $message = $this->shortErrorMessage($e->getMessage());
                    $errors[] = $message;
                    $created[] = $this->persistImportedKnowledgeMaterial(
                        [],
                        $material,
                        $mode,
                        $source,
                        $tags,
                        $hotelId,
                        $hotelName,
                        $userId,
                        $modelKey,
                        'error',
                        $message
                    );
                }
            }

            return $this->ok([
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'model_key' => $modelKey,
                'created' => $created,
                'success_count' => count(array_filter($created, static fn(array $item): bool => ($item['unit']['status'] ?? '') === 'done')),
                'error_count' => count($errors),
                'errors' => $errors,
            ], 'imported');
        } catch (ValidateException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Failed to import knowledge materials: ' . $e->getMessage(), 500);
        }
    }

    public function extractDocumentText(): Response
    {
        try {
            $file = $this->request->file('file') ?: $this->request->file('document');
            if (!$file) {
                return $this->fail('请选择要读取的文档', 422);
            }

            $size = method_exists($file, 'getSize') ? (int)$file->getSize() : 0;
            if ($size > self::MAX_DOCUMENT_BYTES) {
                return $this->fail('文档不能超过 5MB', 422);
            }

            $filename = method_exists($file, 'getOriginalName')
                ? (string)$file->getOriginalName()
                : 'document';
            $path = method_exists($file, 'getPathname') ? (string)$file->getPathname() : '';
            $result = (new KnowledgeDocumentTextExtractor())->extractFromPath($path, $filename);

            return $this->ok($result, 'extracted');
        } catch (InvalidArgumentException|ValidateException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('读取文档失败: ' . $e->getMessage(), 500);
        }
    }

    public function addChunk(int $unit_id): Response
    {
        try {
            $unit = $this->findModifiableUnit($unit_id);
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

    public function createExecutionIntent(int $unit_id, int $chunk_id): Response
    {
        try {
            $unit = $this->findAccessibleUnit($unit_id);
            if (!$unit) {
                return $this->fail('Knowledge unit not found', 404);
            }
            $chunk = KnowledgeChunk::where('unit_id', $unit_id)->where('chunk_id', $chunk_id)->find();
            if (!$chunk) {
                return $this->fail('Knowledge chunk not found', 404);
            }
            $content = $chunk->content;
            if (is_string($content)) {
                $decoded = json_decode($content, true);
                $content = is_array($decoded) ? $decoded : [];
            }
            $content = is_array($content) ? $content : [];
            $template = is_array($content['task_template'] ?? null) ? $content['task_template'] : [];
            if (($content['content_type'] ?? '') !== 'sop_card' || $template === []) {
                return $this->fail('This knowledge chunk is not a taskable SOP card', 422);
            }

            $input = $this->requestData();
            $hotelId = $this->resolveKnowledgeImportHotelId((int)($input['hotel_id'] ?? 0));
            $userId = $this->currentUserId();
            $permittedHotelIds = $this->currentUser && method_exists($this->currentUser, 'getPermittedHotelIds')
                ? array_values(array_unique(array_filter(array_map('intval', (array)$this->currentUser->getPermittedHotelIds()))))
                : [$hotelId];
            if ($this->isSuperAdmin() && !in_array($hotelId, $permittedHotelIds, true)) {
                $permittedHotelIds[] = $hotelId;
            }
            $startDate = trim((string)($input['date_start'] ?? date('Y-m-d')));
            $dueDate = trim((string)($input['due_at'] ?? $input['date_end'] ?? date('Y-m-d', strtotime('+7 days'))));
            $assigneeId = (int)($input['assignee_id'] ?? $userId);
            $platform = strtolower(trim((string)($input['platform'] ?? 'ota')));

            $payload = [
                'source_module' => 'knowledge_sop',
                'source_record_id' => $chunk_id,
                'hotel_id' => $hotelId,
                'platform' => $platform !== '' ? $platform : 'ota',
                'object_type' => 'operation_checklist',
                'action_type' => (string)($template['action_type'] ?? 'execute_sop_card'),
                'date_start' => $startDate,
                'date_end' => $dueDate,
                'current_value' => [
                    'status' => 'not_started',
                    'content_key' => (string)($content['content_key'] ?? ''),
                ],
                'target_value' => [
                    'title' => (string)($template['title'] ?? $content['title'] ?? ''),
                    'action_text' => (string)($content['module_name'] ?? 'OTA运营SOP') . ' / ' . (string)($content['title'] ?? ''),
                    'steps' => array_values((array)($template['steps'] ?? [])),
                    'acceptance_criteria' => array_values((array)($template['acceptance_criteria'] ?? [])),
                    'assignee_id' => $assigneeId,
                    'due_at' => $dueDate,
                    'source_version' => (string)($content['seed_version'] ?? ''),
                ],
                'evidence' => [
                    'evidence_refs' => ['knowledge_chunks#' . $chunk_id],
                    'source_policy' => 'reviewed_reference_sop_requires_hotel_context_and_human_approval',
                    'knowledge_unit_id' => $unit_id,
                    'knowledge_chunk_id' => $chunk_id,
                    'content_key' => (string)($content['content_key'] ?? ''),
                    'source_version' => (string)($content['seed_version'] ?? ''),
                    'source_refs' => array_values((array)($content['source_refs'] ?? [])),
                    'evidence_level' => (string)($content['evidence_level'] ?? ''),
                    'auto_write_ota' => false,
                ],
                'expected_metric' => 'sop_completion',
                'expected_delta' => 0,
                'risk_level' => 'low',
                'status' => 'pending_approval',
            ];

            $intent = (new OperationManagementService())->createExecutionIntent(
                $permittedHotelIds,
                $hotelId,
                $payload,
                $userId
            );

            return $this->ok(['execution_intent' => $intent], 'task draft created');
        } catch (ValidateException|InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Failed to create SOP task draft: ' . $e->getMessage(), 500);
        }
    }

    public function update(int $unit_id): Response
    {
        try {
            $unit = $this->findModifiableUnit($unit_id);
            if (!$unit) {
                return $this->fail('Knowledge unit not found', 404);
            }

            $data = $this->normalizeUnitData($this->requestData(), false);
            if (array_key_exists('hotel_id', $data)) {
                $data['hotel_id'] = $this->resolveKnowledgeImportHotelId((int)$data['hotel_id']);
            }
            unset($data['source'], $data['status']);
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
            $unit = $this->findModifiableUnit($unit_id);
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
            $unit = $this->findModifiableUnit($unit_id);
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
            $hotelId = $this->resolveKnowledgeImportHotelId((int)($data['hotel_id'] ?? 0));
            $result = (new KnowledgeDistillationService())->run($mode, $maxBatches);

            if (($result['ok'] ?? false) !== true) {
                return $this->fail('Knowledge distillation training failed', 500, $result);
            }

            $result['knowledge_unit'] = $this->persistDistillationKnowledge(
                $result,
                $this->currentUserId(),
                $hotelId
            );

            return $this->ok($result, 'training completed');
        } catch (InvalidArgumentException|ValidateException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->fail('Failed to run knowledge distillation: ' . $e->getMessage(), 500);
        }
    }

    private function persistDistillationKnowledge(array $result, int $userId, int $hotelId): array
    {
        $content = is_array($result['distilled_content'] ?? null) ? $result['distilled_content'] : [];
        $mode = (string)($result['mode'] ?? 'kd');
        $label = (string)($result['label'] ?? $mode);
        $summary = (string)($content['summary'] ?? '知识蒸馏训练结果');
        $method = (string)($content['method'] ?? ($mode === 'kd' ? 'vanilla_kd' : 'baseline_ce'));
        $name = '知识蒸馏训练结果 - ' . $label . ' - ' . date('Y-m-d H:i');

        $unit = null;
        $chunk = null;
        Db::transaction(function () use (&$unit, &$chunk, $name, $summary, $method, $content, $userId, $hotelId): void {
            $unit = KnowledgeUnit::create([
                'hotel_id' => $hotelId,
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

    /**
     * @param array<string, mixed> $distilled
     * @param array<int, string> $baseTags
     * @return array<string, array<string, mixed>>
     */
    private function persistImportedKnowledgeMaterial(
        array $distilled,
        string $material,
        string $mode,
        string $source,
        array $baseTags,
        int $hotelId,
        string $hotelName,
        int $userId,
        string $modelKey,
        string $status,
        string $errorMessage
    ): array {
        $isDone = $status === 'done';
        $title = $isDone
            ? mb_substr(trim((string)($distilled['title'] ?? '')), 0, 255)
            : $this->defaultImportedKnowledgeTitle($mode, $material);
        if ($title === '') {
            $title = $this->defaultImportedKnowledgeTitle($mode, $material);
        }

        $description = $isDone
            ? trim((string)($distilled['summary'] ?? ''))
            : 'AI读取失败：' . $errorMessage;
        $keywords = is_array($distilled['keywords'] ?? null) ? $distilled['keywords'] : [];
        $tags = $this->mergeKnowledgeTags($baseTags, $keywords, ['AI资料蒸馏', $hotelName]);
        $content = [
            'material_type' => $mode,
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'source' => $source,
            'raw_text' => $material,
            'model_key' => (string)($distilled['model_key'] ?? $modelKey),
            'imported_at' => date('Y-m-d H:i:s'),
        ];
        if ($isDone) {
            $content['ai_distilled'] = $distilled;
            $content['distilled_at'] = (string)($distilled['distilled_at'] ?? '');
        } else {
            $content['ai_error'] = $errorMessage;
        }

        $unit = null;
        $chunk = null;
        Db::transaction(function () use (&$unit, &$chunk, $title, $source, $status, $description, $tags, $hotelId, $userId, $content): void {
            $unitData = [
                'name' => $title,
                'source' => $source,
                'status' => $status,
                'description' => mb_substr($description, 0, 1000),
                'tags' => $tags,
                'created_by' => $userId,
            ];
            if ($this->knowledgeUnitHasHotelColumn()) {
                $unitData['hotel_id'] = $hotelId;
            }

            $unit = KnowledgeUnit::create($unitData);
            $chunk = KnowledgeChunk::create([
                'unit_id' => (int)$unit->unit_id,
                'type' => 'AI资料蒸馏',
                'content' => $content,
                'created_by' => $userId,
            ]);
        });

        if (!$unit || !$chunk) {
            throw new \RuntimeException('Failed to persist imported knowledge material');
        }

        return [
            'unit' => $this->formatUnitRow($unit->toArray(), 1),
            'chunk' => $this->formatChunkRow($chunk->toArray()),
        ];
    }

    private function resolveKnowledgeImportHotelId(int $requestedHotelId): int
    {
        $requestedHotelId = max(0, $requestedHotelId);
        $permittedHotelIds = $this->permittedKnowledgeHotelIds();

        if ($requestedHotelId > 0) {
            if (!$this->isSuperAdmin() && !in_array($requestedHotelId, $permittedHotelIds, true)) {
                throw new ValidateException('无权为该门店导入知识资料');
            }
            return $requestedHotelId;
        }

        if (count($permittedHotelIds) === 1) {
            return (int)$permittedHotelIds[0];
        }

        throw new ValidateException('请选择需要绑定的门店');
    }

    private function resolveKnowledgeHotelName(int $hotelId): string
    {
        if ($hotelId <= 0) {
            return '';
        }
        if (!$this->tableExists('hotels')) {
            return '';
        }

        $query = $this->currentUser && $this->currentUser->isSuperAdmin()
            ? Hotel::withoutTenantScope()
            : Hotel::where([]);
        $hotel = $query->where('id', $hotelId)->where('status', Hotel::STATUS_ENABLED)->find();
        if (!$hotel) {
            throw new ValidateException('选择的门店不存在或未启用');
        }

        return trim((string)($hotel->name ?? ''));
    }

    private function defaultImportedKnowledgeTitle(string $mode, string $material): string
    {
        return $this->payloadMapper()->defaultImportedTitle($mode, $material);
    }

    /**
     * @param array<int, string> ...$tagGroups
     * @return array<int, string>
     */
    private function mergeKnowledgeTags(array ...$tagGroups): array
    {
        return $this->payloadMapper()->mergeTags(...$tagGroups);
    }

    private function shortErrorMessage(string $message): string
    {
        return $this->payloadMapper()->shortErrorMessage($message);
    }

    private function findAccessibleUnit(int $unitId): ?KnowledgeUnit
    {
        $unit = KnowledgeUnit::find($unitId);
        if (!$unit || !$this->canAccessOwnedRow($unit->toArray())) {
            return null;
        }

        return $unit;
    }

    private function findModifiableUnit(int $unitId): ?KnowledgeUnit
    {
        $unit = KnowledgeUnit::find($unitId);
        if (!$unit || !$this->canModifyOwnedRow($unit->toArray())) {
            return null;
        }

        return $unit;
    }

    private function applyOwnerScope($query): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $userId = $this->currentUserId();
        $hasHotelColumn = $this->knowledgeUnitHasHotelColumn();
        $permittedHotelIds = $this->permittedKnowledgeHotelIds();
        $query->where(function ($scope) use ($userId, $hasHotelColumn, $permittedHotelIds): void {
            $scope->where(function ($owned) use ($userId, $hasHotelColumn, $permittedHotelIds): void {
                $owned->where('created_by', $userId);
                if ($hasHotelColumn) {
                    $permittedHotelIds === []
                        ? $owned->whereRaw('1 = 0')
                        : $owned->whereIn('hotel_id', $permittedHotelIds);
                }
            });
            if ($hasHotelColumn) {
                $scope->whereOr(function ($global): void {
                    $global->where('created_by', 0)
                        ->where('hotel_id', 0)
                        ->where('status', 'done');
                });
            }
        });
    }

    private function canAccessOwnedRow(array $row): bool
    {
        if ($this->isSuperAdmin() || $this->isGlobalSystemKnowledgeRow($row)) {
            return true;
        }
        if ((int)($row['created_by'] ?? 0) !== $this->currentUserId()) {
            return false;
        }

        $hotelId = (int)($row['hotel_id'] ?? 0);
        return $hotelId > 0 && in_array($hotelId, $this->permittedKnowledgeHotelIds(), true);
    }

    private function canModifyOwnedRow(array $row): bool
    {
        if ($this->isGlobalSystemKnowledgeRow($row)) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }
        if ((int)($row['created_by'] ?? 0) !== $this->currentUserId()) {
            return false;
        }

        $hotelId = (int)($row['hotel_id'] ?? 0);
        return $hotelId > 0 && in_array($hotelId, $this->permittedKnowledgeHotelIds(), true);
    }

    /** @return array<int, int> */
    private function permittedKnowledgeHotelIds(): array
    {
        if (!$this->currentUser || !method_exists($this->currentUser, 'getPermittedHotelIds')) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', (array)$this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        )));
    }

    private function isGlobalSystemKnowledgeRow(array $row): bool
    {
        return (int)($row['created_by'] ?? 0) === 0
            && (int)($row['hotel_id'] ?? 0) === 0
            && (string)($row['status'] ?? '') === 'done';
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
        $shouldCheckHotelColumn = $creating || array_key_exists('hotel_id', $input);
        return $this->payloadMapper()->normalizeUnitData(
            $input,
            $creating,
            $shouldCheckHotelColumn && $this->knowledgeUnitHasHotelColumn()
        );
    }

    private function normalizeChunkData(array $input, int $unitId): array
    {
        return $this->payloadMapper()->normalizeChunkData($input, $unitId);
    }

    private function normalizeTags($value): array
    {
        return $this->payloadMapper()->normalizeTags($value);
    }

    /**
     * Search structured chunk content so SOP cards can be found by role,
     * scene, module, platform, evidence level and source version.
     *
     * @param array<string,string> $filters
     * @return array<int,int>
     */
    private function knowledgeChunkMatchingUnitIds(string $keyword, array $filters): array
    {
        $query = KnowledgeChunk::field('unit_id')->distinct(true);
        if ($keyword !== '') {
            $query->whereLike('content', '%' . $keyword . '%');
        }

        $scalarPaths = [
            'module' => '$.module_id',
            'evidence_level' => '$.evidence_level',
            'version' => '$.seed_version',
        ];
        foreach ($scalarPaths as $filter => $path) {
            $value = trim((string)($filters[$filter] ?? ''));
            if ($value === '') {
                continue;
            }
            $parameter = 'chunk_' . $filter;
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(`content`, '{$path}')) = :{$parameter}",
                [$parameter => $value]
            );
        }

        foreach (['role' => 'roles', 'scene' => 'scenes', 'platform' => 'platforms'] as $filter => $jsonField) {
            $value = trim((string)($filters[$filter] ?? ''));
            if ($value === '') {
                continue;
            }
            $parameter = 'chunk_' . $filter;
            $query->whereRaw(
                "JSON_SEARCH(`content`, 'one', :{$parameter}, NULL, '$.{$jsonField}[*]') IS NOT NULL",
                [$parameter => $value]
            );
        }

        return array_values(array_unique(array_filter(array_map(
            'intval',
            $query->column('unit_id')
        ), static fn(int $id): bool => $id > 0)));
    }

    private function formatUnitRow(array $row, ?int $chunkCount = null): array
    {
        $formatted = $this->payloadMapper()->formatUnitRow($row, $chunkCount);
        $formatted['system_read_only'] = $this->isGlobalSystemKnowledgeRow($row);
        $formatted['can_edit'] = $this->canModifyOwnedRow($row);
        return $formatted;
    }

    private function formatChunkRow(array $row): array
    {
        return $this->payloadMapper()->formatChunkRow($row);
    }

    private function payloadMapper(): KnowledgePayloadMapper
    {
        return $this->payloadMapper ??= new KnowledgePayloadMapper();
    }

    private function knowledgeUnitHasHotelColumn(): bool
    {
        $columns = $this->tableColumns('knowledge_units');
        return isset($columns['hotel_id']);
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
        }

        return $cache[$table];
    }

    /**
     * @return array<string, bool>
     */
    private function tableColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        if (!$this->tableExists($table)) {
            $cache[$table] = [];
            return [];
        }

        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string)$row['Field']] = true;
            }
        }
        $cache[$table] = $columns;
        return $columns;
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
