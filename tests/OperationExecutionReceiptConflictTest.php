<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OperationExecutionReceiptConflictTest extends TestCase
{
    public static function contradictoryReceipts(): array
    {
        return [
            'explicit failure flag' => [['execution_failed' => true], 'executed'],
            'error receipt' => [['status' => 'error', 'error_code' => 'synthetic_timeout'], 'executed'],
            'stop receipt' => [['stop_condition_triggered' => true], 'executed'],
            'omitted status' => [['execution_failed' => true], null],
            'nested receipt' => [['receipt' => ['execution_failed' => true]], 'executed'],
            'nested JSON receipt' => [['platform_receipt' => '{"execution_status":"failed"}'], null],
            'mixed success and failure' => [['execution_completed' => true, 'execution_failed' => true], 'executed'],
            'conflicting status aliases' => [['status' => 'completed', 'execution_status' => 'failed'], 'executed'],
            'rejected' => [['status' => 'rejected', 'error_message' => 'synthetic rejection'], 'executed'],
            'stopped' => [['status' => 'stopped', 'failure_reason' => 'synthetic stop'], 'executed'],
            'rolled back' => [['status' => 'rolled_back', 'receipt_id' => 'synthetic-rollback'], null],
        ];
    }

    #[DataProvider('contradictoryReceipts')]
    public function testFailureReceiptNeverBecomesExecuted(array $response, ?string $status): void
    {
        $input = $this->input($response, $status);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不能标记为已执行');
        (new OperationManagementService())->buildExecutionTaskUpdate($this->task(), $this->intent(), $input, 17);
    }

    public function testOmittedStatusCannotSkipManagedEvidenceGuard(): void
    {
        $service = new OperationManagementService();
        $guard = new ReflectionMethod($service, 'assertManagedOperationExecutionEvidence');
        $this->expectException(InvalidArgumentException::class);
        $guard->invoke($service, $this->intent('operating_question'), $this->input(['execution_failed' => true], null), 17);
    }

    public function testLegacyOmittedStatusStillAllowsValidManagedCompletion(): void
    {
        $service = new OperationManagementService();
        $input = $this->input([
            'mode' => 'manual_operation_execution', 'execution_status' => 'executed',
            'executed_by' => 'synthetic operator', 'executed_at' => date('Y-m-d H:i:s'),
            'completed_action' => 'synthetic manual check',
        ], null);
        $input['evidence_type'] = 'manual_operation_execution';
        (new ReflectionMethod($service, 'assertManagedOperationExecutionEvidence'))
            ->invoke($service, $this->intent('operating_question'), $input, 17);
        $result = $service->buildExecutionTaskUpdate($this->task(), $this->intent('operating_question'), $input, 17);
        self::assertSame('executed', $result['task']['status']);
    }

    public function testManagedPreExecutionEvidenceGuardRemainsNonTerminal(): void
    {
        $service = new OperationManagementService();
        $guard = new ReflectionMethod($service, 'assertManagedOperationExecutionEvidence');
        foreach (['pending_execute', 'executing', 'blocked'] as $status) {
            self::assertNull($guard->invoke($service, $this->intent('operating_question'), ['status' => $status], 17), $status);
        }
    }

    public function testManagedGuardKeepsExistingCaseNormalization(): void
    {
        $service = new OperationManagementService();
        $this->expectException(InvalidArgumentException::class);
        (new ReflectionMethod($service, 'assertManagedOperationExecutionEvidence'))
            ->invoke($service, $this->intent('operating_question'), ['status' => ' EXECUTED '], 17);
    }

    public function testValidCompletionFailureAndStopKeepTheirEvidence(): void
    {
        foreach ([
            ['executed', ['execution_completed' => true, 'execution_failed' => false]],
            ['failed', ['execution_failed' => true, 'failure_reason' => 'synthetic_timeout']],
            ['blocked', ['stop_condition_triggered' => true]],
        ] as [$status, $response]) {
            $result = (new OperationManagementService())->buildExecutionTaskUpdate(
                $this->task(), $this->intent(), $this->input($response, $status), 17
            );
            self::assertSame($status, $result['task']['status']);
            self::assertSame($response, $result['evidence']['platform_response']);
            self::assertSame($status !== 'blocked', isset($result['task']['executed_at']));
        }
    }

    public function testTerminalTasksCannotBeRewritten(): void
    {
        foreach (['executed', 'failed'] as $status) {
            try {
                (new OperationManagementService())->buildExecutionTaskUpdate(
                    array_replace($this->task(), ['status' => $status]), $this->intent(),
                    $this->input(['execution_completed' => true], 'executed'), 17
                );
                self::fail('Terminal task was rewritten');
            } catch (InvalidArgumentException $error) {
                self::assertSame('terminal execution task cannot transition', $error->getMessage());
            }
        }
    }

    private function task(): array
    {
        return ['id' => 9001, 'intent_id' => 8001, 'tenant_id' => 31, 'hotel_id' => 80, 'status' => 'pending_execute'];
    }

    private function intent(string $source = 'manual'): array
    {
        return ['id' => 8001, 'tenant_id' => 31, 'hotel_id' => 80, 'source_module' => $source, 'status' => 'approved'];
    }

    private function input(array $response, ?string $status): array
    {
        $input = ['evidence_type' => 'manual', 'evidence' => ['platform_response' => $response]];
        if ($status !== null) $input['status'] = $status;
        return $input;
    }
}
