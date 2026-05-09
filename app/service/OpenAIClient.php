<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

class OpenAIClient
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = trim((string) env('OPENAI_API_KEY', ''));
        $this->model = trim((string) env('OPENAI_MODEL', 'gpt-4.1-mini'));
    }

    public function createJsonResponse(array $messages, array $schema): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('未配置 OPENAI_API_KEY，请先在 .env 中配置。');
        }

        $payload = [
            'model' => $this->model,
            'input' => $messages,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'feasibility_report',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        if ($ch === false) {
            throw new RuntimeException('无法初始化 LLM 请求。');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('LLM 请求失败：' . ($error ?: '网络错误'));
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('LLM 返回内容不是有效 JSON。');
        }

        if ($status >= 400) {
            $message = $data['error']['message'] ?? ('LLM 请求失败，HTTP ' . $status);
            throw new RuntimeException($message);
        }

        $text = $data['output_text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            $text = $this->extractOutputText($data);
        }

        $json = json_decode($text, true);
        if (!is_array($json)) {
            throw new RuntimeException('LLM 未返回符合要求的结构化 JSON。');
        }

        return $json;
    }

    private function extractOutputText(array $data): string
    {
        foreach (($data['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    return (string) $content['text'];
                }
            }
        }

        return '';
    }
}
