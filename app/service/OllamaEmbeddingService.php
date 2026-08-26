<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

/** Pinned, loopback-only embedding client. */
final class OllamaEmbeddingService
{
    /** @param list<string> $texts @return array{embeddings:list<list<float>>,model:string,dimension:int} */
    public function embed(array $texts): array
    {
        $texts = array_values(array_filter(array_map(
            static fn(mixed $text): string => mb_substr(trim((string)$text), 0, 800),
            $texts
        ), static fn(string $text): bool => $text !== ''));
        if ($texts === [] || count($texts) > 40) {
            throw new RuntimeException('本地向量输入数量无效');
        }
        $handle = curl_init(LocalAiRuntimeService::BASE_URL . '/api/embed');
        if ($handle === false) {
            throw new RuntimeException('本地向量运行时不可用');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => LocalAiRuntimeService::EMBEDDING_MODEL,
                'input' => $texts,
                'truncate' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $rows = is_array($decoded) && is_array($decoded['embeddings'] ?? null)
            ? $decoded['embeddings']
            : [];
        if ($status < 200 || $status >= 300 || count($rows) !== count($texts)) {
            throw new RuntimeException('本地向量生成失败');
        }
        $dimension = 0;
        $embeddings = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                throw new RuntimeException('本地向量返回格式无效');
            }
            $vector = array_map('floatval', array_values($row));
            $dimension = $dimension ?: count($vector);
            if (count($vector) !== $dimension || $dimension !== 1024) {
                throw new RuntimeException('本地向量维度不一致');
            }
            $embeddings[] = $vector;
        }
        return [
            'embeddings' => $embeddings,
            'model' => LocalAiRuntimeService::EMBEDDING_MODEL,
            'dimension' => $dimension,
        ];
    }
}
