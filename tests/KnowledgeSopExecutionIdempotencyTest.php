<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeSopExecutionProvenanceService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class KnowledgeSopExecutionIdempotencyTest extends TestCase
{
    use ReflectionHelper;

    public function testSameSnapshotAndTaskContextProduceOneStableKey(): void
    {
        $service = new OperationManagementService();
        $payload = $this->payload();

        $first = $this->invokeNonPublic(
            $service,
            'knowledgeSopExecutionIntentIdempotencyKey',
            [$payload]
        );
        $second = $this->invokeNonPublic(
            $service,
            'knowledgeSopExecutionIntentIdempotencyKey',
            [$payload]
        );

        self::assertMatchesRegularExpression('/^knowledge_sop_[a-f0-9]{32}$/D', $first);
        self::assertSame($first, $second);
    }

    public function testChangedSnapshotOrTaskContextProducesANewKey(): void
    {
        $service = new OperationManagementService();
        $original = $this->payload();
        $originalKey = $this->invokeNonPublic(
            $service,
            'knowledgeSopExecutionIntentIdempotencyKey',
            [$original]
        );

        $changedSnapshot = $original;
        $changedSnapshot['evidence']['knowledge_provenance']['content_digest'] = str_repeat('c', 64);
        $changedDueDate = $original;
        $changedDueDate['date_end'] = '2026-08-08';
        $changedPlatform = $original;
        $changedPlatform['platform'] = 'meituan';
        $changedPlatform['evidence']['knowledge_provenance']['resolved_platform'] = 'meituan';

        foreach ([$changedSnapshot, $changedDueDate, $changedPlatform] as $changed) {
            self::assertNotSame(
                $originalKey,
                $this->invokeNonPublic(
                    $service,
                    'knowledgeSopExecutionIntentIdempotencyKey',
                    [$changed]
                )
            );
        }
    }

    public function testBrokenHotelOrSourceBindingCannotGenerateAKey(): void
    {
        $payload = $this->payload();
        $payload['evidence']['knowledge_provenance']['target_hotel_id'] = 8;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('provenance is invalid');
        $this->invokeNonPublic(
            new OperationManagementService(),
            'knowledgeSopExecutionIntentIdempotencyKey',
            [$payload]
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'source_module' => 'knowledge_sop',
            'source_record_id' => 42,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'action_type' => 'execute_sop_card',
            'date_start' => '2026-07-30',
            'date_end' => '2026-08-06',
            'target_value' => [
                'assignee_id' => 9,
            ],
            'evidence' => [
                'knowledge_provenance' => [
                    'contract_version' => KnowledgeSopExecutionProvenanceService::CONTRACT_VERSION,
                    'knowledge_unit_id' => 11,
                    'knowledge_chunk_id' => 42,
                    'content_digest' => str_repeat('a', 64),
                    'unit_authority_digest' => str_repeat('b', 64),
                    'target_hotel_id' => 7,
                    'resolved_platform' => 'ctrip',
                ],
            ],
        ];
    }
}
