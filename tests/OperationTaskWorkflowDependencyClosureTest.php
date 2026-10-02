<?php
declare(strict_types=1);
namespace Tests;

use app\service\OperationTaskWorkflowService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OperationTaskWorkflowFixture as Fixture;
use think\facade\Db;

final class OperationTaskWorkflowDependencyClosureTest extends TestCase
{
    private OperationTaskWorkflowService $service;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $container = new \think\Container();
        \think\Container::setInstance($container);
        $config = new \think\Config();
        $container->instance('config', $config);
        $manager = new \think\Db();
        $manager->setConfig($config);
        $container->instance(\think\DbManager::class, $manager);
        Fixture::connect(':memory:');
        Fixture::schema();
        Fixture::seed();
        $this->service = Fixture::service();
    }

    protected function tearDown(): void { Db::connect()->close(); }

    private function seedTask(int $id): void
    {
        $intent = Db::name('operation_execution_intents')->where('id', 2)->find();
        $intent['id'] = $id;
        Db::name('operation_execution_intents')->insert($intent);
        $task = Db::name('operation_execution_tasks')->where('id', 2)->find();
        $task['id'] = $task['intent_id'] = $id;
        Db::name('operation_execution_tasks')->insert($task);
    }

    private function act(int $id, string $action, array $extra = []): array
    {
        return $this->service->mutate($id, [7], ['request_id' => 'synthetic:closure:' . ++$this->sequence,
            'expected_version' => $this->service->read($id, [7])['version'], 'action' => $action] + $extra, 3);
    }

    private function configure(int $id, array $dependencies = []): void
    {
        $this->act($id, 'configure', ['dependencies' => $dependencies] + Fixture::configure());
    }

    private function finish(int $id): array
    {
        $this->act($id, 'start');
        $this->act($id, 'record', ['record' => Fixture::record()]);
        $this->act($id, 'complete', ['completed_criteria' => Fixture::configure()['completion_criteria']]);
        return $this->act($id, 'verify', ['human_confirmed' => true, 'reason' => 'synthetic manual verification']);
    }

    private function events(): array
    {
        return Db::name(OperationTaskWorkflowService::EVENTS)->order('id')->select()->toArray();
    }

    public static function guardedMutations(): array
    {
        return [
            'start' => ['start', []],
            'complete' => ['complete', ['completed_criteria' => Fixture::configure()['completion_criteria']]],
            'verify' => ['verify', ['human_confirmed' => true, 'reason' => 'synthetic leaf verification']],
            'review' => ['review', ['review' => ['note' => 'synthetic no after-window evidence']]],
        ];
    }

    #[DataProvider('guardedMutations')]
    public function testReopenedAncestorBlocksMutationWithoutNewHistoryAndSameRequestRecovers(string $action, array $extra): void
    {
        $this->seedTask(5);
        $this->configure(5);
        $this->finish(5);
        $this->configure(2, [5]);
        $this->finish(2);
        $this->configure(1, [2]);
        if ($action !== 'start') {
            $this->act(1, 'start');
            $this->act(1, 'record', ['record' => Fixture::record()]);
        }
        if (in_array($action, ['verify', 'review'], true)) {
            $this->act(1, 'complete', ['completed_criteria' => Fixture::configure()['completion_criteria']]);
        }
        if ($action === 'review') {
            $this->act(1, 'verify', ['human_confirmed' => true, 'reason' => 'synthetic previous human verification']);
        }
        $saved = $this->service->read(1, [7]);
        $history = $this->service->read(1, [7], $saved['version']);
        $this->act(5, 'reopen', ['reason' => 'synthetic upstream rework']);
        $blocked = $this->service->read(1, [7]);
        self::assertSame('dependency_blocked', $blocked['next_step']['key']);
        self::assertSame($saved['task_status'], $blocked['task_status']);
        self::assertSame($saved['verification'], $blocked['verification']);
        self::assertSame($saved['execution_records'], $blocked['execution_records']);
        self::assertSame($history, $this->service->read(1, [7], $saved['version']));
        self::assertSame('history', $history['next_step']['key']);
        $input = ['request_id' => 'synthetic:closure:blocked-' . $action,
            'expected_version' => $blocked['version'], 'action' => $action] + $extra;
        $events = $this->events();
        try {
            $this->service->mutate(1, [7], $input, 3);
            self::fail('Indirect reopened ancestor must block ' . $action);
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('#5', $error->getMessage());
        }
        self::assertSame($events, $this->events());
        self::assertSame($blocked, $this->service->read(1, [7]));
        self::assertSame('dependency_blocked', $this->service->read(2, [7])['next_step']['key']);
        self::assertSame('reopened', $this->service->read(5, [7])['task_status']);

        $this->finish(5);
        self::assertNotSame('dependency_blocked', $this->service->read(1, [7])['next_step']['key']);
        $receipt = $this->service->mutate(1, [7], $input, 3);
        self::assertFalse($receipt['replayed']);
        self::assertSame($saved['version'] + 1, $receipt['saved_version']);
        self::assertSame($receipt['workflow'], $this->service->read(1, [7]));
        self::assertSame($saved['task_status'], $this->service->read(1, [7], $saved['version'])['task_status']);

        $newer = $this->act(1, 'reschedule_review', ['review_window' => Fixture::window(), 'reason' => 'synthetic retain valid window']);
        $events = $this->events();
        $replay = $this->service->mutate(1, [7], $input, 3);
        self::assertTrue($replay['replayed']);
        self::assertSame($receipt['saved_version'], $replay['saved_version']);
        self::assertSame($newer['workflow'], $replay['workflow']);
        self::assertSame($events, $this->events());
        try {
            $this->service->mutate(1, [7], ['request_id' => 'synthetic:closure:stale',
                'expected_version' => $saved['version'], 'action' => 'start'], 3);
            self::fail('A different stale request must conflict');
        } catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        self::assertSame($events, $this->events());
        self::assertSame($newer['workflow'], $this->service->read(1, [7]));
    }

    public function testSharedAncestorDagAndDuplicateIdsAreNotCycles(): void
    {
        $this->seedTask(5);
        $this->seedTask(6);
        $this->configure(5);
        $this->finish(5);
        foreach ([2, 6] as $id) {
            $this->configure($id, [5]);
            $this->finish($id);
        }
        $this->configure(1, [6, 2, 6]);
        self::assertSame([2, 6], $this->service->read(1, [7])['dependencies']);
        self::assertSame('start', $this->service->read(1, [7])['next_step']['key']);
        $verified = $this->finish(1)['workflow'];
        self::assertSame('manual_verified', $verified['verification']['status']);
        $this->act(5, 'reopen', ['reason' => 'synthetic shared ancestor rework']);
        self::assertSame('dependency_blocked', $this->service->read(1, [7])['next_step']['key']);
        self::assertSame($verified['verification'], $this->service->read(1, [7])['verification']);
        $this->finish(5);
        self::assertSame('review', $this->service->read(1, [7])['next_step']['key']);
        $receipt = $this->act(1, 'review', ['review' => ['note' => 'synthetic restored DAG review']]);
        self::assertSame($receipt['workflow'], $this->service->read(1, [7]));
    }

    public function testExistingConfigurationCycleRejectionPreservesEvents(): void
    {
        $this->seedTask(5);
        $this->configure(2, [5]);
        $events = $this->events();
        try {
            $this->configure(5, [2]);
            self::fail('Configured dependency cycle must remain rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('循环', $error->getMessage());
        }
        self::assertSame($events, $this->events());
        self::assertSame(0, $this->service->read(5, [7])['version']);
    }

    public static function chainDepths(): array { return ['existing allowed boundary' => [61, true], 'existing excessive boundary' => [62, false]]; }

    #[DataProvider('chainDepths')]
    public function testRuntimeDepthMatchesExistingConfigurationConstraint(int $depth, bool $allowed): void
    {
        $ids = range(10, 9 + $depth);
        foreach ($ids as $id) $this->seedTask($id);
        // A is configured first. Each later child configuration remains legal
        // under its own root, while A's whole path may become one level longer.
        $this->configure(1, [10]);
        foreach (array_reverse($ids) as $id) {
            $this->configure($id, $id === end($ids) ? [] : [$id + 1]);
            $this->finish($id);
        }
        $current = $this->service->read(1, [7]);
        $events = $this->events();
        if ($allowed) {
            self::assertSame('start', $current['next_step']['key']);
            $receipt = $this->act(1, 'start');
            self::assertSame('in_progress', $receipt['workflow']['task_status']);
            self::assertSame($receipt['workflow'], $this->service->read(1, [7]));
        } else {
            self::assertSame('dependency_blocked', $current['next_step']['key']);
            self::assertStringContainsString('层级过深', $current['next_step']['label']);
            try { $this->act(1, 'start'); self::fail('Excessive current path must be blocked'); }
            catch (\InvalidArgumentException $error) { self::assertStringContainsString('层级过深', $error->getMessage()); }
            self::assertSame($events, $this->events());
            self::assertSame($current, $this->service->read(1, [7]));
        }
    }
}
