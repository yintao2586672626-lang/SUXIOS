<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

class KnowledgeDistillationService
{
    /**
     * @var callable|null
     */
    private $processRunner;

    private string $rootPath;

    /**
     * @param callable|null $processRunner
     */
    public function __construct(?string $rootPath = null, ?callable $processRunner = null)
    {
        $resolvedRoot = $rootPath !== null ? realpath($rootPath) : realpath(dirname(__DIR__, 2));
        if (!is_string($resolvedRoot)) {
            throw new RuntimeException('Project root path is unavailable');
        }

        $this->rootPath = $resolvedRoot;
        $this->processRunner = $processRunner;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function options(): array
    {
        return [
            'baseline' => [
                'mode' => 'baseline',
                'label' => 'Baseline 训练',
                'description' => '生成 student.pt，可作为知识蒸馏 teacher checkpoint。',
                'config_path' => 'config/ml_training.example.json',
                'checkpoint_path' => 'runtime/ml_checkpoints/student.pt',
                'distill_enabled' => false,
            ],
            'kd' => [
                'mode' => 'kd',
                'label' => '知识蒸馏训练',
                'description' => '读取 teacher checkpoint，执行 vanilla KD，输出 student_kd.pt。',
                'config_path' => 'config/ml_training_kd.example.json',
                'checkpoint_path' => 'runtime/ml_checkpoints/student_kd.pt',
                'distill_enabled' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $mode, int $maxBatches = 1): array
    {
        $options = $this->options();
        $mode = trim($mode);
        if (!isset($options[$mode])) {
            throw new InvalidArgumentException('Unsupported knowledge distillation mode: ' . $mode);
        }

        $job = $options[$mode];
        $scriptPath = 'scripts/ml/train_distillation.py';
        $configPath = (string)$job['config_path'];
        $this->assertFileExists($scriptPath, 'Training script not found');
        $this->assertFileExists($configPath, 'Training config not found');

        $maxBatches = max(0, min(1000, $maxBatches));
        $command = [$this->pythonBinary(), $scriptPath, '--config', $configPath];
        if ($maxBatches > 0) {
            $command[] = '--max-batches';
            $command[] = (string)$maxBatches;
        }

        $process = $this->runCommand($command, 300);
        $metrics = $this->parseMetrics((string)($process['stdout'] ?? ''));
        $exitCode = (int)($process['exit_code'] ?? 1);
        $ok = $exitCode === 0 && ($process['timed_out'] ?? false) !== true;

        $checkpointPath = (string)($metrics['checkpoint_path'] ?? $job['checkpoint_path']);
        $result = [
            'ok' => $ok,
            'mode' => $mode,
            'label' => $job['label'],
            'config_path' => $configPath,
            'checkpoint_path' => $checkpointPath,
            'distill_enabled' => (bool)$job['distill_enabled'],
            'max_batches' => $maxBatches,
            'exit_code' => $exitCode,
            'metrics' => $metrics,
            'stdout' => $this->tail((string)($process['stdout'] ?? '')),
            'stderr' => $this->tail((string)($process['stderr'] ?? '')),
            'timed_out' => (bool)($process['timed_out'] ?? false),
            'command' => implode(' ', $command),
        ];
        $result['distilled_content'] = $this->buildDistilledContent($mode, $job, $configPath, $checkpointPath, $metrics, $ok);

        return $result;
    }

    private function assertFileExists(string $relativePath, string $message): void
    {
        $path = $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path)) {
            throw new RuntimeException($message . ': ' . $relativePath);
        }
    }

    private function pythonBinary(): string
    {
        $configured = trim((string)getenv('ML_PYTHON_BIN'));
        if ($configured !== '') {
            return $configured;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function buildDistilledContent(
        string $mode,
        array $job,
        string $configPath,
        string $checkpointPath,
        array $metrics,
        bool $ok
    ): array {
        $config = $this->readConfig($configPath);
        $distill = is_array($config['distill'] ?? null) ? $config['distill'] : [];
        $model = is_array($config['model'] ?? null) ? $config['model'] : [];
        $data = is_array($config['data'] ?? null) ? $config['data'] : [];
        $method = $mode === 'kd' ? (string)($distill['method'] ?? 'vanilla_kd') : 'baseline_ce';
        $summary = $mode === 'kd'
            ? '本次训练将 teacher checkpoint 的软标签蒸馏到 student，采用 vanilla KD，将交叉熵与温度缩放 KLDiv 组合为总损失。'
            : '本次 Baseline 训练生成 student checkpoint，可作为后续 vanilla KD 的 teacher checkpoint。';

        return [
            'title' => (string)$job['label'],
            'mode' => $mode,
            'status' => $ok ? 'done' : 'error',
            'summary' => $summary,
            'method' => $method,
            'formula' => $mode === 'kd'
                ? 'loss = ce_weight * CE(student_logits, labels) + kd_weight * T^2 * KLDiv(log_softmax(student_logits/T), softmax(teacher_logits/T))'
                : 'loss = CE(student_logits, labels)',
            'teacher_checkpoint' => (string)($distill['teacher_checkpoint'] ?? ''),
            'checkpoint_path' => $checkpointPath,
            'config_path' => $configPath,
            'model' => [
                'type' => (string)($model['type'] ?? ''),
                'input_dim' => (int)($model['input_dim'] ?? 0),
                'hidden_dim' => (int)($model['hidden_dim'] ?? 0),
                'num_classes' => (int)($model['num_classes'] ?? 0),
            ],
            'data' => [
                'type' => (string)($data['type'] ?? ''),
                'num_samples' => (int)($data['num_samples'] ?? 0),
                'batch_size' => (int)($data['batch_size'] ?? 0),
            ],
            'distill' => [
                'temperature' => (float)($distill['temperature'] ?? 0),
                'ce_weight' => (float)($distill['ce_weight'] ?? 0),
                'kd_weight' => (float)($distill['kd_weight'] ?? 0),
            ],
            'metrics' => [
                'batches' => (int)($metrics['batches'] ?? 0),
                'epochs' => (int)($metrics['epochs'] ?? 0),
                'ce_loss' => (float)($metrics['ce_loss'] ?? 0),
                'kd_loss' => (float)($metrics['kd_loss'] ?? 0),
                'total_loss' => (float)($metrics['total_loss'] ?? 0),
            ],
            'next_steps' => [
                '将 synthetic 数据替换为真实 OTA/经营样本后复训。',
                '在 scripts/ml/distillation.py 扩展 DKD、FitNet 或 feature distillation。',
                '对比 baseline 与 KD checkpoint 的验证集指标后再上线。',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(string $relativePath): array
    {
        $path = $this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $content = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($content)) {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, string> $command
     * @return array<string, mixed>
     */
    private function runCommand(array $command, int $timeoutSeconds): array
    {
        if ($this->processRunner !== null) {
            $runner = $this->processRunner;
            $result = $runner($command, $this->rootPath, $timeoutSeconds);
            return is_array($result) ? $result : [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Invalid process runner result',
                'timed_out' => false,
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->rootPath);
        if (!is_resource($process)) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Failed to start training process',
                'timed_out' => false,
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $startedAt = time();

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if (time() - $startedAt > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }

            usleep(100000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $timedOut ? 124 : $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => $timedOut,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMetrics(string $stdout): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($stdout));
        if (!is_array($lines)) {
            return [];
        }

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = trim((string)$lines[$index]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function tail(string $text, int $limit = 4000): string
    {
        $text = trim($text);
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, -$limit);
    }
}
