<?php
declare(strict_types=1);

namespace Tests;

use app\command\ManualFetchOnlineDataOnce;
use app\service\ManualOnlineFetchTaskService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ManualOnlineFetchTaskServiceTest extends TestCase
{
    private string|false $previousPhpCliBinary;

    /** @var array<int, string> */
    private array $createdTaskDirs = [];

    protected function setUp(): void
    {
        $this->previousPhpCliBinary = getenv('PHP_CLI_BINARY');
        putenv('PHP_CLI_BINARY=' . PHP_BINARY);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTaskDirs as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        if ($this->previousPhpCliBinary === false) {
            putenv('PHP_CLI_BINARY');
        } else {
            putenv('PHP_CLI_BINARY=' . $this->previousPhpCliBinary);
        }
    }

    public function testCreateTaskPersistsScopedBackgroundInput(): void
    {
        $service = new ManualOnlineFetchTaskService();
        $task = $service->createTask(
            ' CTRIP ',
            7,
            '2026-07-01',
            '2026-07-14',
            [
                'system_hotel_id' => 999,
                'start_date' => '2000-01-01',
                'end_date' => '2000-01-02',
                'async' => true,
                'data_type' => 'traffic',
            ],
            [
                'user_id' => 5,
                'api_url' => 'http://127.0.0.1:8080/api/online-data/manual-fetch',
                'authorization' => 'Bearer test-only-token',
            ]
        );

        self::assertNotSame([], $task);
        $this->createdTaskDirs[] = dirname($task['input']);

        self::assertMatchesRegularExpression('/^manual_ctrip_fetch_7_\d{14}_[a-f0-9]{8}$/', $task['task_id']);
        self::assertSame('ctrip', $task['platform']);
        self::assertSame(7, $task['hotel_id']);
        self::assertSame(5, $task['user_id']);
        self::assertSame('2026-07-01', $task['start_date']);
        self::assertSame('2026-07-14', $task['end_date']);
        self::assertSame(7, $task['body']['system_hotel_id']);
        self::assertSame('2026-07-01', $task['body']['start_date']);
        self::assertSame('2026-07-14', $task['body']['end_date']);
        self::assertFalse($task['body']['async']);
        self::assertTrue($task['body']['background_task']);
        self::assertSame('traffic', $task['body']['data_type']);
        self::assertFileExists($task['input']);

        $stored = json_decode((string)file_get_contents($task['input']), true);
        self::assertIsArray($stored);
        self::assertSame($task['task_id'], $stored['task_id']);
        self::assertSame($task['body'], $stored['body']);
        self::assertArrayNotHasKey('authorization', $stored);
        self::assertMatchesRegularExpression('/^SUXI_MANUAL_FETCH_AUTH_[A-F0-9]{24}$/', $stored['authorization_env']);
        self::assertStringNotContainsString('Bearer test-only-token', (string)file_get_contents($task['input']));

        putenv($task['authorization_env'] . '=' . $task['authorization']);
        $resolveAuthorization = new ReflectionMethod(new ManualFetchOnlineDataOnce(), 'resolveAuthorization');
        $resolveAuthorization->setAccessible(true);
        self::assertSame('Bearer test-only-token', $resolveAuthorization->invoke(new ManualFetchOnlineDataOnce(), $stored));
        self::assertFalse(getenv($task['authorization_env']));
    }

    public function testInvalidTaskScopeAndMissingLaunchInputAreRejected(): void
    {
        $service = new ManualOnlineFetchTaskService();
        $context = ['authorization' => 'Bearer test-only-token'];

        self::assertSame([], $service->createTask('', 7, '2026-07-01', '2026-07-14', [], $context));
        self::assertSame([], $service->createTask('ctrip', 0, '2026-07-01', '2026-07-14', [], $context));
        self::assertSame([], $service->createTask('ctrip', 7, '2026-07-01', '2026-07-14', [], []));
        self::assertFalse($service->launchTask([]));
        self::assertFalse($service->launchTask([
            'task_id' => 'missing-task',
            'input' => runtime_path() . 'manual_fetch_tasks' . DIRECTORY_SEPARATOR . 'missing.json',
        ]));
    }

    public function testLaunchFailureRemovesPersistedTaskEnvelope(): void
    {
        $service = new ManualOnlineFetchTaskService();
        $task = $service->createTask(
            'ctrip',
            7,
            '2026-07-15',
            '2026-07-15',
            [],
            [
                'user_id' => 5,
                'api_url' => 'http://127.0.0.1:8080/api/online-data/manual-fetch',
                'authorization' => 'Bearer launch-failure-token',
            ]
        );
        self::assertNotSame([], $task);
        $taskDir = dirname($task['input']);
        $this->createdTaskDirs[] = $taskDir;

        $task['task_id'] = 'invalid-task-id';
        self::assertFalse($service->launchTask($task));
        self::assertFileDoesNotExist($task['input']);
        self::assertDirectoryDoesNotExist($taskDir);
    }
}
