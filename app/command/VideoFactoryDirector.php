<?php
declare(strict_types=1);

namespace app\command;

use app\model\AiModelConfig;
use app\service\LlmClient;
use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use Throwable;

/**
 * Internal CLI bridge used by the local Suxi video factory.
 *
 * API keys remain inside the Suxi OS model runtime. The bridge accepts a
 * bounded prompt and JSON schema over stdin and prints one JSON object only.
 */
final class VideoFactoryDirector extends Command
{
    private const MAX_INPUT_BYTES = 2_097_152;
    private const MAX_PROMPT_CHARS = 180_000;
    private const MAX_MODEL_KEY_CHARS = 96;

    protected function configure()
    {
        $this->setName('video-factory:director')
            ->addOption('status', null, Option::VALUE_NONE, 'List enabled model metadata without secrets')
            ->setDescription('Run a structured video director request through the Suxi OS LLM runtime');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            if ((bool)$input->getOption('status')) {
                $this->writeJson($output, [
                    'ok' => true,
                    'models' => $this->enabledModels(),
                ]);
                return 0;
            }

            $raw = stream_get_contents(STDIN, self::MAX_INPUT_BYTES + 1);
            if (!is_string($raw) || $raw === '') {
                throw new RuntimeException('Missing video director stdin payload.');
            }
            if (strlen($raw) > self::MAX_INPUT_BYTES) {
                throw new RuntimeException('Video director payload exceeds 2 MiB.');
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                throw new RuntimeException('Video director payload is not valid JSON.');
            }

            $promptValue = $payload['prompt'] ?? null;
            $schema = $payload['schema'] ?? null;
            $modelKeyValue = $payload['modelKey'] ?? 'deepseek_chat';
            if (!is_string($promptValue)) {
                throw new RuntimeException('Video director prompt must be a string.');
            }
            if (!is_string($modelKeyValue)) {
                throw new RuntimeException('Video director modelKey must be a string.');
            }

            $prompt = trim($promptValue);
            $modelKey = trim($modelKeyValue);
            if ($prompt === '' || mb_strlen($prompt) > self::MAX_PROMPT_CHARS) {
                throw new RuntimeException('Video director prompt is empty or too large.');
            }
            if (!is_array($schema) || $schema === [] || array_is_list($schema)) {
                throw new RuntimeException('Video director JSON schema must be a non-empty object.');
            }
            if ($modelKey === '' || mb_strlen($modelKey) > self::MAX_MODEL_KEY_CHARS) {
                throw new RuntimeException('Video director modelKey is empty or too large.');
            }

            $model = AiModelConfig::where('model_key', $modelKey)->where('is_enabled', 1)->find();
            if (!$model) {
                throw new RuntimeException('Requested Suxi OS model is not enabled: ' . $modelKey);
            }

            $data = (new LlmClient())->createJsonResponse([
                [
                    'role' => 'system',
                    'content' => 'You are the Suxi OS hotel video director. Return schema-valid JSON only.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ], $schema, $modelKey);

            $this->writeJson($output, [
                'ok' => true,
                'provider' => (string)$model->getAttr('provider'),
                'modelKey' => $modelKey,
                'model' => (string)$model->getAttr('model_name'),
                'data' => $data,
            ]);
            return 0;
        } catch (Throwable $e) {
            $this->writeJson($output, [
                'ok' => false,
                'error' => $this->sanitize($e->getMessage()),
            ]);
            return 1;
        }
    }

    private function enabledModels(): array
    {
        return AiModelConfig::where('is_enabled', 1)
            ->field(['model_key', 'provider', 'model_name', 'is_default', 'usage_scene', 'update_time'])
            ->select()
            ->map(static function ($model): array {
                return [
                    'modelKey' => (string)$model->getAttr('model_key'),
                    'provider' => (string)$model->getAttr('provider'),
                    'model' => (string)$model->getAttr('model_name'),
                    'isDefault' => (int)$model->getAttr('is_default') === 1,
                    'usageScene' => (string)($model->getAttr('usage_scene') ?? ''),
                    'updatedAt' => (string)($model->getAttr('update_time') ?? ''),
                ];
            })
            ->toArray();
    }

    private function writeJson(Output $output, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $output->writeln($json === false ? '{"ok":false,"error":"JSON encode failed"}' : $json);
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/\bauthorization\s*[:=]\s*(?:Bearer|Basic)?\s*[^,\s;]+/i', 'authorization=****', $message) ?? $message;
        $message = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ****', $message) ?? $message;
        $message = preg_replace('/sk-[A-Za-z0-9_-]{8,}/', 'sk-****', $message) ?? $message;
        $message = preg_replace('/\b(api[_-]?key|cookie|set-cookie|spidertoken|access[_-]?token|refresh[_-]?token|session[_-]?id|session|password|secret|[a-z0-9_.-]*token[a-z0-9_.-]*)\s*[:=]\s*[^,\s;]+/i', '$1=****', $message) ?? $message;
        return mb_substr(trim($message), 0, 600);
    }
}
