<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDecisionQualityService;
use app\service\RevenueResearchExecutionArtifactService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RevenueResearchExecutionArtifactServiceTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function testArtifactPersistsReadbacksAndBindsAuthoritativeResearchToActorAndHotel(): void
    {
        $service = $this->service();
        $research = $this->readyResearch();

        $artifact = $service->issue($research, 3, 7);

        self::assertSame('available', $artifact['status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $artifact['id']);
        self::assertSame($research, $service->load($artifact['id'], 3, 7));

        try {
            $service->load($artifact['id'], 4, 7);
            self::fail('Artifact must not cross users.');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }

        try {
            $service->load($artifact['id'], 3, 8);
            self::fail('Artifact must not cross hotels.');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }

        self::assertSame($research, $service->consume($artifact['id'], 3, 7));
        try {
            $service->consume($artifact['id'], 3, 7);
            self::fail('An execution artifact must be one-time use.');
        } catch (RuntimeException $e) {
            self::assertSame(410, $e->getCode());
        }
    }

    public function testArtifactRejectsClientLikeAggregateReadyWithoutV2RecommendationGate(): void
    {
        $research = $this->readyResearch();
        unset($research['result']['decision_recommendations'][0]['decision_quality']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->service()->issue($research, 3, 7);
    }

    public function testArtifactReadbackDetectsTamperingAndDeleteIsOneWay(): void
    {
        $service = $this->service();
        $artifact = $service->issue($this->readyResearch(), 3, 7);
        $key = 'revenue_research_execution_artifact:' . $artifact['id'];
        $this->cache[$key]['research']['result']['decision_recommendations'][0]['action'] = '伪造动作';

        try {
            $service->load($artifact['id'], 3, 7);
            self::fail('Tampered cache payload must fail integrity verification.');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }

        self::assertTrue($service->delete($artifact['id']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(410);
        $service->load($artifact['id'], 3, 7);
    }

    private function service(): RevenueResearchExecutionArtifactService
    {
        return new RevenueResearchExecutionArtifactService(
            fn(string $key): mixed => $this->cache[$key] ?? null,
            function (string $key, array $value, int $ttl): bool {
                $this->cache[$key] = $value;
                return $ttl === 1800;
            },
            function (string $key): bool {
                $exists = array_key_exists($key, $this->cache);
                unset($this->cache[$key]);
                return $exists;
            },
            static fn(): int => 1_721_430_000,
            static fn(): string => '0123456789abcdef0123456789abcdef',
            static fn(callable $callback): mixed => $callback()
        );
    }

    /** @return array<string, mixed> */
    private function readyResearch(): array
    {
        return [
            'status' => 'done',
            'product_key' => 'demand-forecast',
            'hotel_scope' => ['hotel_id' => 7, 'hotel_ids' => [7]],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
            ],
            'gaps' => [],
            'result' => [
                'data_gaps' => [],
                'decision_recommendations' => [[
                    'title' => '复核携程需求预测',
                    'action' => '复核未来7天携程订单预测，并记录实际订单偏差率',
                    'can_create_execution_intent' => true,
                    'decision_quality' => [
                        'contract_version' => AiDecisionQualityService::CONTRACT_VERSION,
                        'execution_ready' => true,
                    ],
                ]],
            ],
        ];
    }
}
