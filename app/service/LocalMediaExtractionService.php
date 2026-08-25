<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/** Local-only image understanding and audio/video transcription with immutable readback. */
final class LocalMediaExtractionService
{
    public const TABLE = 'local_media_extractions';
    public const CONTRACT_VERSION = 'local_media_extraction.v1';
    private const MAX_IMAGE_BYTES = 20_000_000;
    private const MAX_AUDIO_VIDEO_BYTES = 38_000_000;
    private const ASR_MODEL = 'small';

    /** @return array<string,mixed> */
    public function extract(
        int $tenantId,
        int $hotelId,
        int $createdBy,
        string $sourcePath,
        string $originalName,
        string $declaredMimeType = ''
    ): array {
        $this->assertReady();
        if ($tenantId <= 0 || $hotelId <= 0 || $createdBy <= 0) {
            throw new InvalidArgumentException('本地媒体提取缺少有效租户、酒店或操作人');
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new InvalidArgumentException('上传文件不可读取');
        }
        $size = (int)filesize($sourcePath);
        if ($size <= 0) {
            throw new InvalidArgumentException('上传文件为空');
        }
        $mimeType = $this->detectMimeType($sourcePath, $declaredMimeType);
        $mediaKind = $this->mediaKind($mimeType, $originalName);
        $limit = $mediaKind === 'image' ? self::MAX_IMAGE_BYTES : self::MAX_AUDIO_VIDEO_BYTES;
        if ($size > $limit) {
            throw new InvalidArgumentException('上传文件超过本地提取大小限制');
        }
        $sourceSha = hash_file('sha256', $sourcePath);
        if (!is_string($sourceSha) || strlen($sourceSha) !== 64) {
            throw new RuntimeException('上传文件摘要计算失败');
        }

        $existing = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('created_by', $createdBy)
            ->where('source_sha256', $sourceSha)
            ->find();
        if (is_array($existing) && in_array((string)($existing['extraction_status'] ?? ''), ['ready', 'partial'], true)) {
            $readback = $this->normalize($existing);
            $this->assertDigest($readback);
            $readback['created'] = false;
            $readback['persistence_status'] = 'readback_verified';
            return $readback;
        }

        $extraction = $mediaKind === 'image'
            ? $this->extractImage($sourcePath)
            : $this->extractAudioVideo($sourcePath);
        $record = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'created_by' => $createdBy,
            'media_kind' => $mediaKind,
            'mime_type' => $mimeType,
            'original_name' => mb_substr(basename(trim($originalName)), 0, 255),
            'size_bytes' => $size,
            'source_sha256' => $sourceSha,
            'extraction_status' => (string)$extraction['status'],
            'extraction_method' => (string)$extraction['method'],
            'extractor_version' => (string)$extraction['extractor_version'],
            'extracted_text' => isset($extraction['text']) && is_string($extraction['text']) && trim($extraction['text']) !== ''
                ? mb_substr(trim($extraction['text']), 0, 20000)
                : null,
            'structured' => is_array($extraction['structured'] ?? null) ? $extraction['structured'] : [],
            'confidence' => is_numeric($extraction['confidence'] ?? null)
                ? max(0.0, min(1.0, (float)$extraction['confidence']))
                : null,
            'error_code' => trim((string)($extraction['error_code'] ?? '')) ?: null,
            'source_retention' => 'discarded_after_extraction',
        ];
        $digest = $this->digest($record);
        $write = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'created_by' => $createdBy,
            'media_kind' => $mediaKind,
            'mime_type' => $mimeType,
            'original_name' => $record['original_name'],
            'size_bytes' => $size,
            'source_sha256' => $sourceSha,
            'extraction_status' => $record['extraction_status'],
            'extraction_method' => $record['extraction_method'],
            'extractor_version' => $record['extractor_version'],
            'extracted_text' => $record['extracted_text'],
            'structured_json' => $this->encode($record['structured']),
            'confidence' => $record['confidence'],
            'error_code' => $record['error_code'],
            'content_digest' => $digest,
            'source_retention' => 'discarded_after_extraction',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (is_array($existing)) {
            $id = (int)$existing['id'];
            $updated = Db::name(self::TABLE)->where('id', $id)->update($write);
            if ($updated < 0) {
                throw new RuntimeException('本地媒体提取失败记录重试保存失败');
            }
        } else {
            $write['created_at'] = date('Y-m-d H:i:s');
            try {
                $id = (int)Db::name(self::TABLE)->insertGetId($write);
            } catch (Throwable $e) {
                $concurrent = Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('created_by', $createdBy)
                    ->where('source_sha256', $sourceSha)
                    ->find();
                if (!is_array($concurrent)) {
                    throw $e;
                }
                $existing = $concurrent;
                $id = (int)$concurrent['id'];
            }
        }
        if ($id <= 0) {
            throw new RuntimeException('本地媒体提取结果保存失败');
        }
        $readback = $this->read($id, $tenantId, [$hotelId]);
        $this->assertDigest($readback);
        $readback['created'] = !is_array($existing);
        $readback['retried'] = is_array($existing);
        $readback['persistence_status'] = 'readback_verified';
        return $readback;
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function read(int $id, int $tenantId, array $hotelIds): array
    {
        $this->assertReady();
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        if ($id <= 0 || $hotelIds === []) {
            throw new InvalidArgumentException('本地媒体提取回读范围无效');
        }
        $query = Db::name(self::TABLE)->where('id', $id)->whereIn('hotel_id', $hotelIds);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('local media extraction not found', 404);
        }
        $readback = $this->normalize($row);
        $this->assertDigest($readback);
        return $readback;
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function list(int $tenantId, array $hotelIds, ?int $hotelId = null, int $limit = 20): array
    {
        $this->assertReady();
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        $query = Db::name(self::TABLE)->whereIn('hotel_id', $hotelIds);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
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

    /** @return array<string,mixed> */
    private function extractImage(string $sourcePath): array
    {
        $runtime = (new LocalAiRuntimeService())->capabilities();
        if (($runtime['vision']['ready'] ?? false) !== true) {
            return $this->blocked('ollama_vision_local', 'qwen3-vl:4b', 'vision_model_missing');
        }
        $image = file_get_contents($sourcePath);
        if (!is_string($image) || $image === '') {
            return $this->failed('ollama_vision_local', 'qwen3-vl:4b', 'image_read_failed');
        }
        $schema = [
            'type' => 'object',
            'required' => ['summary', 'visible_text', 'observable_facts', 'uncertainties'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'visible_text' => ['type' => 'array', 'items' => ['type' => 'string']],
                'observable_facts' => ['type' => 'array', 'items' => ['type' => 'string']],
                'uncertainties' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
        $payload = [
            'model' => LocalAiRuntimeService::VISION_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => '你是宿析OS本机图片理解器。只描述图片中可观察内容和清晰可见文字，不推断酒店、日期、平台账号、经营结果或因果。只输出符合schema的简体中文JSON。'],
                ['role' => 'user', 'content' => '提取图片中的可见信息。', 'images' => [base64_encode($image)]],
            ],
            'stream' => false,
            'format' => $schema,
            'options' => ['temperature' => 0],
        ];
        $response = $this->ollama('/api/chat', $payload, 120);
        if (($response['ok'] ?? false) !== true) {
            return $this->failed('ollama_vision_local', 'qwen3-vl:4b', 'vision_model_call_failed');
        }
        $content = trim((string)($response['data']['message']['content'] ?? ''));
        $structured = $this->decode($content);
        if ($structured === [] || !isset($structured['summary'])) {
            return $this->failed('ollama_vision_local', 'qwen3-vl:4b', 'vision_output_invalid');
        }
        $textParts = [(string)($structured['summary'] ?? '')];
        foreach (['visible_text', 'observable_facts'] as $key) {
            foreach ((array)($structured[$key] ?? []) as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $textParts[] = trim($item);
                }
            }
        }
        return [
            'status' => 'ready',
            'method' => 'ollama_vision_local',
            'extractor_version' => 'qwen3-vl:4b',
            'text' => implode("\n", array_values(array_unique(array_filter($textParts)))),
            'structured' => array_merge($structured, [
                'source_retained' => false,
                'fact_use_boundary' => 'observable_image_content_only',
            ]),
            'confidence' => null,
            'error_code' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function extractAudioVideo(string $sourcePath): array
    {
        $python = root_path() . 'storage' . DIRECTORY_SEPARATOR . 'local-ai' . DIRECTORY_SEPARATOR
            . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        $script = root_path() . 'scripts' . DIRECTORY_SEPARATOR . 'local_media_extract.py';
        if (!is_file($python) || !is_file($script)) {
            return $this->blocked('faster_whisper_local', 'faster-whisper/1.2.1:small:cpu-int8', 'asr_runtime_missing');
        }
        $result = $this->runProcess([$python, $script, '--input', $sourcePath, '--model', self::ASR_MODEL], 900);
        $data = $this->decode($result['stdout']);
        if ($data === []) {
            return $this->failed('faster_whisper_local', 'faster-whisper/1.2.1:small:cpu-int8', 'asr_output_invalid');
        }
        return [
            'status' => in_array((string)($data['status'] ?? ''), ['ready', 'partial'], true)
                ? (string)$data['status']
                : (($data['status'] ?? '') === 'blocked_not_configured' ? 'blocked_not_configured' : 'failed'),
            'method' => 'faster_whisper_local',
            'extractor_version' => trim((string)($data['extractor_version'] ?? 'faster-whisper/1.2.1:small:cpu-int8')),
            'text' => isset($data['text']) && is_string($data['text']) ? $data['text'] : null,
            'structured' => is_array($data['structured'] ?? null) ? $data['structured'] : [],
            'confidence' => is_numeric($data['confidence'] ?? null) ? (float)$data['confidence'] : null,
            'error_code' => trim((string)($data['error_code'] ?? '')) ?: null,
        ];
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runProcess(array $command, int $timeoutSeconds): array
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, root_path());
        if (!is_resource($process)) {
            return ['exit_code' => -1, 'stdout' => '', 'stderr' => 'process_start_failed'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                break;
            }
            if ((microtime(true) - $started) > $timeoutSeconds) {
                proc_terminate($process);
                break;
            }
            usleep(100_000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return ['exit_code' => $exit, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
    }

    /** @return array<string,mixed> */
    private function normalize(array $row): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'created_by' => (int)($row['created_by'] ?? 0),
            'media_kind' => (string)($row['media_kind'] ?? ''),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'original_name' => (string)($row['original_name'] ?? ''),
            'size_bytes' => (int)($row['size_bytes'] ?? 0),
            'source_sha256' => (string)($row['source_sha256'] ?? ''),
            'extraction_status' => (string)($row['extraction_status'] ?? ''),
            'extraction_method' => (string)($row['extraction_method'] ?? ''),
            'extractor_version' => (string)($row['extractor_version'] ?? ''),
            'extracted_text' => isset($row['extracted_text']) && is_string($row['extracted_text']) ? $row['extracted_text'] : null,
            'structured' => $this->decode($row['structured_json'] ?? null),
            'confidence' => is_numeric($row['confidence'] ?? null) ? (float)$row['confidence'] : null,
            'error_code' => isset($row['error_code']) && trim((string)$row['error_code']) !== '' ? (string)$row['error_code'] : null,
            'content_digest' => (string)($row['content_digest'] ?? ''),
            'source_retention' => (string)($row['source_retention'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'boundaries' => [
                'local_only' => true,
                'source_file_retained' => false,
                'hotel_fact_created' => false,
                'external_message' => false,
                'automatic_execution' => false,
            ],
        ];
    }

    private function assertDigest(array $row): void
    {
        $record = array_intersect_key($row, array_flip([
            'contract_version', 'tenant_id', 'hotel_id', 'created_by', 'media_kind', 'mime_type',
            'original_name', 'size_bytes', 'source_sha256', 'extraction_status', 'extraction_method',
            'extractor_version', 'extracted_text', 'structured', 'confidence', 'error_code', 'source_retention',
        ]));
        if (!hash_equals((string)$row['content_digest'], $this->digest($record))) {
            throw new RuntimeException('本地媒体提取结果保存后摘要不一致');
        }
    }

    private function detectMimeType(string $path, string $declared): string
    {
        $detected = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $value = finfo_file($finfo, $path);
                finfo_close($finfo);
                $detected = is_string($value) ? strtolower(trim($value)) : '';
            }
        }
        return $detected !== '' ? $detected : strtolower(trim($declared));
    }

    private function mediaKind(string $mimeType, string $originalName): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'], true)) {
            return 'image';
        }
        if (in_array($extension, ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac'], true)) {
            return 'audio';
        }
        if (in_array($extension, ['mp4', 'mov', 'mkv', 'webm', 'avi'], true)) {
            return 'video';
        }
        throw new InvalidArgumentException('仅支持图片、音频或视频文件');
    }

    /** @return array<string,mixed> */
    private function ollama(string $path, array $payload, int $timeoutSeconds): array
    {
        $handle = curl_init(LocalAiRuntimeService::BASE_URL . $path);
        if ($handle === false) {
            return ['ok' => false, 'data' => []];
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $data = is_string($body) ? json_decode($body, true) : null;
        return ['ok' => $status >= 200 && $status < 300 && is_array($data), 'data' => is_array($data) ? $data : []];
    }

    private function blocked(string $method, string $version, string $code): array
    {
        return ['status' => 'blocked_not_configured', 'method' => $method, 'extractor_version' => $version, 'text' => null, 'structured' => [], 'confidence' => null, 'error_code' => $code];
    }

    private function failed(string $method, string $version, string $code): array
    {
        return ['status' => 'failed', 'method' => $method, 'extractor_version' => $version, 'text' => null, 'structured' => [], 'confidence' => null, 'error_code' => $code];
    }

    private function assertReady(): void
    {
        try {
            Db::name(self::TABLE)->limit(1)->select();
        } catch (Throwable) {
            throw new RuntimeException('本地媒体提取表尚未迁移', 503);
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
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
