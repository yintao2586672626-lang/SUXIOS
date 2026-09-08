<?php
declare(strict_types=1);
namespace Tests;

use app\service\AiDailyReportEvidenceService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use Tests\Support\AiEvidenceFixture;
use Tests\Support\OperationTaskWorkflowFixture;
use think\facade\Config;
use think\facade\Db;

/** Synthetic cross-module contract: generated evidence suggestions reach the real workflow store. */
final class LongGoalEvidenceWorkflowIntegrationTest extends TestCase
{
    private string $database;
    private array $config;

    protected function setUp(): void
    {
        $this->config = [];
        foreach (['database', 'cache', 'log'] as $key) $this->config[$key] = Config::get($key, []);
        $this->database = tempnam(sys_get_temp_dir(), 'suxios-integrated-workflow-');
        OperationTaskWorkflowFixture::connect($this->database);
        OperationTaskWorkflowFixture::schema();
        OperationTaskWorkflowFixture::seed();
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        unlink($this->database);
        foreach ($this->config as $key => $value) Config::set($value, $key);
    }

    private function recommendation(): array
    {
        $evidence = new AiDailyReportEvidenceService();
        $closure = AiEvidenceFixture::closure();
        $closure['tenant_id'] = 42;
        $closure['hotel_id'] = 7;
        $pack = $evidence->factPack($closure, $evidence->scope(42, 7, '2026-09-08'));
        $diagnosis = $evidence->diagnose($pack);
        $recommendation = array_values(array_filter($diagnosis['recommendations'], static fn(array $item): bool => $item['platform'] === 'ctrip'))[0];
        self::assertSame('ready_for_task_proposal', $recommendation['handoff_status']);
        self::assertFalse($recommendation['auto_execute']);
        return $recommendation;
    }

    public function testGeneratedAiSuggestionPersistsAndReplaysWithoutApprovalOrDuplicateTasks(): void
    {
        $recommendation = $this->recommendation();
        $tasksBefore = (int) Db::name('operation_execution_tasks')->count();
        $workflow = OperationTaskWorkflowFixture::service();
        $first = $workflow->propose([7], 7, $recommendation, 3);
        $second = $workflow->propose([7], 7, $recommendation, 3);
        self::assertTrue($first['readback_verified']);
        self::assertTrue($second['replayed']);
        self::assertSame($first['intent'], $second['intent']);
        self::assertSame('pending_approval', $first['intent']['status']);
        self::assertSame($tasksBefore, (int) Db::name('operation_execution_tasks')->count());
        self::assertSame(1, (int) Db::name('operation_task_workflow_proposals')->count());
        $restored = (new OperationManagementService())->readExecutionIntent((int)$first['intent']['id'], [7]);
        self::assertSame($recommendation, $restored['evidence']['workflow_proposal']);
        foreach (['tenant_id', 'hotel_id', 'platform', 'date_start', 'date_end'] as $key) {
            self::assertSame((string)$recommendation['scope'][$key], (string)$restored[$key]);
        }
        self::assertSame([], $restored['tasks']);
    }

    public function testGeneratedSuggestionCannotCrossHotel(): void
    {
        $recommendation = $this->recommendation();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('租户或酒店范围不匹配');
        OperationTaskWorkflowFixture::service()->propose([8], 8, $recommendation, 3);
    }

    public function testSameSuggestionIdCannotSilentlyReplaceOriginalEvidence(): void
    {
        $recommendation = $this->recommendation();
        $workflow = OperationTaskWorkflowFixture::service();
        $first = $workflow->propose([7], 7, $recommendation, 3);
        $altered = $recommendation;
        $altered['problem'] .= ' altered synthetic instruction';
        try {
            $workflow->propose([7], 7, $altered, 3);
            self::fail('Changed content must conflict with the saved suggestion.');
        } catch (\RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
        }
        $restored = (new OperationManagementService())->readExecutionIntent((int)$first['intent']['id'], [7]);
        self::assertSame($recommendation, $restored['evidence']['workflow_proposal']);
    }
}
