<?php
declare(strict_types=1);

namespace app\service;

use think\exception\ValidateException;

final class KnowledgePayloadMapper
{
    private const STATUSES = ['pending', 'done', 'error'];

    private KnowledgeCenterReadinessService $readinessService;

    public function __construct(?KnowledgeCenterReadinessService $readinessService = null)
    {
        $this->readinessService = $readinessService ?? new KnowledgeCenterReadinessService();
    }

    public function normalizeUnitData(array $input, bool $creating, bool $supportsHotelId): array
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

        if (array_key_exists('hotel_id', $input) && $supportsHotelId) {
            $hotelId = (int)$input['hotel_id'];
            if ($hotelId < 0) {
                throw new ValidateException('hotel_id must be greater than or equal to 0');
            }
            $data['hotel_id'] = $hotelId;
        } elseif ($creating && $supportsHotelId) {
            $data['hotel_id'] = 0;
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

        $truthProfileTouched = false;
        if (array_key_exists('known_knowns', $input)) {
            $data['known_knowns'] = $this->normalizeStatements($input['known_knowns'], 'known_knowns');
            $truthProfileTouched = true;
        }
        if (array_key_exists('known_unknowns', $input)) {
            $data['known_unknowns'] = $this->normalizeStatements($input['known_unknowns'], 'known_unknowns');
            $truthProfileTouched = true;
        }
        if (array_key_exists('truth_profile_version', $input)) {
            $version = trim((string)$input['truth_profile_version']);
            if ($version !== '' && preg_match('/^[A-Za-z0-9._-]{1,32}$/', $version) !== 1) {
                throw new ValidateException('truth_profile_version must use letters, numbers, dot, underscore or hyphen');
            }
            $data['truth_profile_version'] = $version;
            $truthProfileTouched = true;
        }
        if ($truthProfileTouched) {
            $data['reviewed_at'] = date('Y-m-d H:i:s');
        }

        return $data;
    }

    public function normalizeChunkData(array $input, int $unitId): array
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

    public function normalizeTags(mixed $value): array
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

    public function formatUnitRow(array $row, ?int $chunkCount = null): array
    {
        $tags = $row['tags'] ?? [];
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : [];
        }
        $knownKnowns = $this->decodeStatements($row['known_knowns'] ?? []);
        $knownUnknowns = $this->decodeStatements($row['known_unknowns'] ?? []);
        $resolvedChunkCount = $chunkCount ?? (int)($row['chunk_count'] ?? 0);

        return [
            'unit_id' => (int)($row['unit_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'source' => (string)($row['source'] ?? ''),
            'status' => (string)($row['status'] ?? 'pending'),
            'lifecycle_status' => (string)($row['lifecycle_status'] ?? 'active'),
            'lifecycle_reason' => (string)($row['lifecycle_reason'] ?? ''),
            'known_knowns' => $knownKnowns,
            'known_unknowns' => $knownUnknowns,
            'truth_profile_version' => (string)($row['truth_profile_version'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'tags' => array_values(is_array($tags) ? $tags : []),
            'chunk_count' => $resolvedChunkCount,
            'readiness' => $this->readinessService->buildUnitReadiness($row, $resolvedChunkCount),
            'created_by' => (int)($row['created_by'] ?? 0),
            'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStatements(mixed $value, string $field): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE
                ? $decoded
                : preg_split('/\r?\n+/u', $value);
        }
        if (!is_array($value)) {
            throw new ValidateException($field . ' must be an array or newline-separated text');
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $text = mb_substr(trim((string)$item), 0, 255);
            if ($text !== '') {
                $items[$text] = $text;
            }
            if (count($items) >= 20) {
                break;
            }
        }

        return array_values($items);
    }

    /**
     * @return array<int, string>
     */
    private function decodeStatements(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '',
            $value
        ), static fn(string $item): bool => $item !== ''));
    }

    public function formatChunkRow(array $row): array
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

    public function defaultImportedTitle(string $mode, string $material): string
    {
        $firstLine = trim((string)preg_split('/\r?\n/u', $material)[0]);
        if ($firstLine !== '') {
            return mb_substr($firstLine, 0, 80);
        }

        return ($mode !== '' ? $mode : 'text') . '资料蒸馏';
    }

    /**
     * @param array<int, string> ...$tagGroups
     * @return array<int, string>
     */
    public function mergeTags(array ...$tagGroups): array
    {
        $tags = [];
        foreach ($tagGroups as $group) {
            foreach ($group as $tag) {
                $normalized = mb_substr(trim((string)$tag), 0, 50);
                if ($normalized !== '') {
                    $tags[$normalized] = $normalized;
                }
            }
        }

        return array_values($tags);
    }

    public function shortErrorMessage(string $message): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        return mb_substr($message !== '' ? $message : 'AI读取失败', 0, 240);
    }
}
