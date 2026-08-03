<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class KnowledgePromotionRouteContractTest extends TestCase
{
    public function testControllerExposesRouteReadyReadAndWriteEndpointsWithHotelPermissions(): void
    {
        $controller = file_get_contents(__DIR__ . '/../app/controller/KnowledgePromotion.php');
        self::assertIsString($controller);

        foreach ([
            'createCandidate',
            'candidates',
            'readCandidate',
            'events',
            'createRevision',
            'submit',
            'review',
            'withdraw',
        ] as $method) {
            self::assertMatchesRegularExpression('/public function ' . $method . '\s*\(/', $controller);
        }
        self::assertStringContainsString("accessibleHotels('operation.view')", $controller);
        self::assertStringContainsString("accessibleHotels('operation.execute')", $controller);
        self::assertStringContainsString('writeScopeForCandidate($id)', $controller);
        self::assertStringContainsString('whereIn(\'hotel_id\', $hotelIds)', $controller);
    }

    public function testServiceUsesFormalSopSourceAndAppendOnlyEventWrites(): void
    {
        $service = file_get_contents(__DIR__ . '/../app/service/KnowledgePromotionService.php');
        self::assertIsString($service);

        self::assertStringContainsString("SOURCE_RECORD_TYPE = 'hotel_operating_sop_versions'", $service);
        self::assertStringContainsString('$this->sopService->validateVersion(', $service);
        self::assertStringContainsString("'knowledge_write_before_approval' => false", $service);
        self::assertStringContainsString("'causality_verified' => false", $service);
        self::assertStringContainsString("'automatic_execution' => false", $service);
        self::assertStringContainsString("'ota_write' => false", $service);
        self::assertStringContainsString("'external_message' => false", $service);
        self::assertStringNotContainsString('Phase3OperationEffectLoopService', $service);
        self::assertStringNotContainsString("Db::name(self::EVENT_TABLE)->where('id'", $service);
        self::assertDoesNotMatchRegularExpression(
            '/Db::name\(self::EVENT_TABLE\)[\s\S]{0,160}?->(?:update|delete)\s*\(/',
            $service,
            'Promotion events must only be inserted and read, never updated or deleted.'
        );
    }

    public function testMigrationDeclaresFormalWorkflowAndVersionedKnowledgeContract(): void
    {
        $migration = file_get_contents(__DIR__ . '/../database/migrations/20260803_create_knowledge_promotion_workflow.sql');
        self::assertIsString($migration);

        foreach ([
            'knowledge_candidates',
            'knowledge_candidate_revisions',
            'knowledge_promotion_events',
            'current_revision_id',
            'content_digest',
            'idempotency_key',
            'promoted_sop_version_id',
            'operating_sop_version_id',
            'lifecycle_status',
            'retired_by',
            'retired_at',
        ] as $contractField) {
            self::assertStringContainsString($contractField, $migration);
        }
    }
}
