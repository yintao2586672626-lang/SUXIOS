<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDistillationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class KnowledgeDistillationServiceTest extends TestCase
{
    public function testOptionsExposeOnlySupportedTrainingModes(): void
    {
        $service = new KnowledgeDistillationService($this->root());
        $options = $service->options();

        self::assertArrayHasKey('baseline', $options);
        self::assertArrayHasKey('kd', $options);
        self::assertSame('config/ml_training.example.json', $options['baseline']['config_path']);
        self::assertSame('config/ml_training_kd.example.json', $options['kd']['config_path']);
    }

    public function testRunUsesFixedConfigAndForwardsMaxBatchSmokeLimit(): void
    {
        $captured = [];
        $service = new KnowledgeDistillationService(
            $this->root(),
            function (array $command, string $cwd, int $timeoutSeconds) use (&$captured): array {
                $captured = [
                    'command' => $command,
                    'cwd' => $cwd,
                    'timeoutSeconds' => $timeoutSeconds,
                ];

                return [
                    'exit_code' => 0,
                    'stdout' => '{"distill_enabled":true,"checkpoint_path":"runtime/ml_checkpoints/student_kd.pt"}',
                    'stderr' => '',
                    'timed_out' => false,
                ];
            }
        );

        $result = $service->run('kd', 1);

        self::assertTrue($result['ok']);
        self::assertSame('kd', $result['mode']);
        self::assertSame('config/ml_training_kd.example.json', $result['config_path']);
        self::assertSame($this->root(), $captured['cwd']);
        self::assertContains('scripts/ml/train_distillation.py', $captured['command']);
        self::assertContains('config/ml_training_kd.example.json', $captured['command']);
        self::assertContains('--max-batches', $captured['command']);
        self::assertContains('1', $captured['command']);
    }

    public function testSuccessfulRunReturnsDistilledContentSummary(): void
    {
        $service = new KnowledgeDistillationService(
            $this->root(),
            static fn(array $command, string $cwd, int $timeoutSeconds): array => [
                'exit_code' => 0,
                'stdout' => '{"batches":1,"ce_loss":1.2,"kd_loss":0.03,"total_loss":0.61,"checkpoint_path":"runtime/ml_checkpoints/student_kd.pt","distill_enabled":true}',
                'stderr' => '',
                'timed_out' => false,
            ]
        );

        $result = $service->run('kd', 1);

        self::assertTrue($result['ok']);
        self::assertIsArray($result['distilled_content']);
        self::assertSame('vanilla_kd', $result['distilled_content']['method']);
        self::assertStringContainsString('KLDiv', $result['distilled_content']['formula']);
        self::assertSame('runtime/ml_checkpoints/student_kd.pt', $result['distilled_content']['checkpoint_path']);
        self::assertSame(0.03, $result['distilled_content']['metrics']['kd_loss']);
    }

    public function testUnknownTrainingModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported knowledge distillation mode');

        (new KnowledgeDistillationService($this->root()))->run('shell', 1);
    }

    public function testFailedProcessKeepsDebuggableErrorDetails(): void
    {
        $service = new KnowledgeDistillationService(
            $this->root(),
            static fn(array $command, string $cwd, int $timeoutSeconds): array => [
                'exit_code' => 2,
                'stdout' => '',
                'stderr' => 'teacher checkpoint missing',
                'timed_out' => false,
            ]
        );

        $result = $service->run('kd', 1);

        self::assertFalse($result['ok']);
        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('teacher checkpoint missing', $result['stderr']);
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        return $root;
    }
}
