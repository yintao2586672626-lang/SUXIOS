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
                'api_url' => 'http://127.0.0.1:8080/api/online-data/fetch-ctrip',
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

        $status = $service->readTaskStatus($task['task_id']);
        self::assertSame('queued', $status['status']);
        self::assertSame('created', $status['stage']);
        self::assertFalse($status['done']);
        self::assertStringNotContainsString('test-only-token', (string)file_get_contents($task['status_file']));

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
        self::assertSame([], $service->createTask('ctrip', 7, '2026-07-01', '2026-07-14', [], [
            'authorization' => 'Bearer test-only-token',
            'api_url' => 'https://127.0.0.1.evil.test/api/online-data/fetch-ctrip',
        ]));
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
                'api_url' => 'http://127.0.0.1:8080/api/online-data/fetch-ctrip',
                'authorization' => 'Bearer launch-failure-token',
            ]
        );
        self::assertNotSame([], $task);
        $taskDir = dirname($task['input']);
        $taskId = $task['task_id'];
        $this->createdTaskDirs[] = $taskDir;

        $task['task_id'] = 'invalid-task-id';
        self::assertFalse($service->launchTask($task));
        self::assertFileDoesNotExist($task['input']);
        self::assertDirectoryExists($taskDir);
        self::assertFileExists($taskDir . DIRECTORY_SEPARATOR . 'status.json');
        $status = $service->readTaskStatus($taskId);
        self::assertSame('failed', $status['status']);
        self::assertSame('launch_failed', $status['stage']);
    }

    public function testPlatformVariantsUseReplayCompatibleTaskIds(): void
    {
        $service = new ManualOnlineFetchTaskService();
        $cases = [
            'ctrip_traffic' => 'ctrip',
            'ctrip_ads' => 'ctrip',
            'qunar_traffic' => 'ctrip',
            'meituan_traffic' => 'meituan',
            'meituan_orders' => 'meituan',
        ];

        foreach ($cases as $taskKind => $platform) {
            $task = $service->createTask($taskKind, 7, '2026-07-15', '2026-07-15', [], [
                'user_id' => 5,
                'api_url' => 'http://127.0.0.1:8080/api/online-data/' . ($platform === 'ctrip' ? 'fetch-ctrip' : 'fetch-meituan'),
                'authorization' => 'Bearer variant-token',
            ]);
            self::assertNotSame([], $task, $taskKind);
            $this->createdTaskDirs[] = dirname($task['input']);
            self::assertSame($platform, $task['platform']);
            self::assertSame($taskKind, $task['task_kind']);
            self::assertMatchesRegularExpression('/^manual_' . $platform . '_fetch_7_\d{14}_[a-f0-9]{8}$/', $task['task_id']);
        }
    }

    public function testTaskStatusLifecycleAndPublicPayloadStayTruthfulAndSafe(): void
    {
        $service = new ManualOnlineFetchTaskService();
        $task = $service->createTask('meituan_orders', 7, '2026-07-15', '2026-07-15', [], [
            'user_id' => 5,
            'api_url' => 'http://127.0.0.1:8080/api/online-data/fetch-meituan-orders',
            'authorization' => 'Bearer lifecycle-token',
        ]);
        self::assertNotSame([], $task);
        $this->createdTaskDirs[] = dirname($task['input']);

        $running = $service->markTaskRunning($task['task_id']);
        self::assertSame('running', $running['status']);
        self::assertSame('requesting', $running['stage']);

        $completed = $service->completeTask($task['task_id'], [
            'code' => 200,
            'data' => [
                'saved_count' => 3,
                'persistence_status' => 'readback_verified',
                'database_readback' => ['verified' => true, 'matched_count' => 3],
            ],
        ], '美团订单已完成', true);
        self::assertSame('success', $completed['status']);
        self::assertSame(3, $completed['saved_count']);
        self::assertSame(3, $completed['readback_count']);
        self::assertTrue($completed['readback_verified']);
        self::assertTrue($completed['done']);

        $failedTask = $service->createTask('ctrip', 8, '2026-07-15', '2026-07-15', [], [
            'user_id' => 5,
            'api_url' => 'http://127.0.0.1:8080/api/online-data/fetch-ctrip',
            'authorization' => 'Bearer second-token',
        ]);
        self::assertNotSame([], $failedTask);
        $this->createdTaskDirs[] = dirname($failedTask['input']);
        $failed = $service->markTaskFailed(
            $failedTask['task_id'],
            'Bearer secret-value Cookie=session-secret; token=token-secret',
            'request_failed'
        );
        self::assertSame('failed', $failed['status']);
        self::assertSame('collection_failed', $failed['quality_status']);
        self::assertStringNotContainsString('secret-value', $failed['message']);
        self::assertStringNotContainsString('session-secret', $failed['message']);
        self::assertStringNotContainsString('token-secret', $failed['message']);

        $public = $service->publicTaskStatus($failed + [
            'authorization' => 'Bearer hidden',
            'authorization_env' => 'SECRET_ENV',
            'api_url' => 'http://127.0.0.1/private',
            'input' => 'private-input.json',
            'status_file' => 'private-status.json',
            'log' => 'private.log',
            'body' => ['cookie' => 'hidden'],
        ]);
        foreach (['user_id', 'authorization', 'authorization_env', 'api_url', 'input', 'status_file', 'log', 'body'] as $field) {
            self::assertArrayNotHasKey($field, $public);
        }
    }
}
