<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCanonicalHistoryPromotionCoordinator;
use app\service\OtaCollectionAnchorService;
use PHPUnit\Framework\TestCase;

final class OtaCanonicalHistoryPromotionCoordinatorTest extends TestCase
{
    public function testSinglePlatformStrictlyVerifiesAndPromotesOnce(): void
    {
        $verifyCalls = [];
        $promoteCalls = [];
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            function (int $hotelId, string $date, array $platforms, string $anchor) use (&$verifyCalls): array {
                $verifyCalls[] = [$hotelId, $date, $platforms, $anchor];
                return $this->verifier($platforms, $anchor);
            },
            function (
                array $collection,
                array $verifier,
                string $platform,
                int $tenantId,
                int $hotelId
            ) use (&$promoteCalls): array {
                $promoteCalls[] = [$collection['hotel_id'], $verifier['required_platforms'], $platform];
                return $this->promotion($platform);
            }
        );

        $result = $coordinator->finalize($this->collection(['ctrip']), 80, 80);

        self::assertSame('verified', $result['status']);
        self::assertTrue($result['canonical_history_complete']);
        self::assertSame(['ctrip'], $result['promoted_platforms']);
        self::assertSame([], $result['blocked_platforms']);
        self::assertCount(1, $verifyCalls);
        self::assertSame([[80, ['ctrip'], 'ctrip']], $promoteCalls);
    }

    public function testFinalizationBudgetBoundsVerifierTimeout(): void
    {
        $verifierTimeouts = [];
        $promotionTimeouts = [];
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            function (
                int $hotelId,
                string $date,
                array $platforms,
                string $anchor,
                int $timeoutSeconds
            ) use (&$verifierTimeouts): array {
                $verifierTimeouts[] = $timeoutSeconds;
                return $this->verifier($platforms, $anchor);
            },
            function (
                array $collection,
                array $verifier,
                string $platform,
                int $tenantId,
                int $hotelId,
                int $timeoutSeconds
            ) use (&$promotionTimeouts): array {
                $promotionTimeouts[] = $timeoutSeconds;
                return $this->promotion($platform);
            }
        );

        $result = $coordinator->finalize($this->collection(['ctrip']), 80, 80, 7);

        self::assertSame('verified', $result['status']);
        self::assertCount(1, $verifierTimeouts);
        self::assertGreaterThanOrEqual(1, $verifierTimeouts[0]);
        self::assertLessThanOrEqual(7, $verifierTimeouts[0]);
        self::assertCount(1, $promotionTimeouts);
        self::assertGreaterThanOrEqual(1, $promotionTimeouts[0]);
        self::assertLessThanOrEqual(5, $promotionTimeouts[0]);
    }

    public function testBudgetedFinalizationRejectsLegacyPromoterWithoutCallingIt(): void
    {
        $promoteCalled = false;
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            fn(int $hotelId, string $date, array $platforms, string $anchor): array =>
                $this->verifier($platforms, $anchor),
            function (
                array $collection,
                array $verifier,
                string $platform,
                int $tenantId,
                int $hotelId
            ) use (&$promoteCalled): array {
                $promoteCalled = true;
                return $this->promotion($platform);
            }
        );

        $result = $coordinator->finalize($this->collection(['ctrip']), 80, 80, 7);

        self::assertFalse($promoteCalled);
        self::assertFalse($result['canonical_history_complete']);
        self::assertSame('blocked', $result['status']);
        self::assertSame(
            'canonical_history_promotion_budget_unsupported',
            $result['platform_results']['ctrip']['promotion']['reason']
        );
    }

    public function testDualPlatformPromotesOnlyIndependentlyVerifiedPlatform(): void
    {
        $verifyCalls = [];
        $promoteCalls = [];
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            function (int $hotelId, string $date, array $platforms, string $anchor) use (&$verifyCalls): array {
                $verifyCalls[] = $platforms;
                if ($platforms === ['ctrip']) {
                    return $this->verifier($platforms, $anchor);
                }
                return $this->verifier($platforms, $anchor, false);
            },
            function (
                array $collection,
                array $verifier,
                string $platform,
                int $tenantId,
                int $hotelId
            ) use (&$promoteCalls): array {
                $promoteCalls[] = $platform;
                return $this->promotion($platform);
            }
        );

        $result = $coordinator->finalize($this->collection(['ctrip', 'meituan']), 80, 80);

        self::assertSame('partial', $result['status']);
        self::assertFalse($result['canonical_history_complete']);
        self::assertSame(['ctrip'], $result['promoted_platforms']);
        self::assertSame(['meituan'], $result['blocked_platforms']);
        self::assertSame([['ctrip', 'meituan'], ['ctrip'], ['meituan']], $verifyCalls);
        self::assertSame(['ctrip'], $promoteCalls);
        self::assertSame(
            'canonical_history_platform_verifier_not_ready',
            $result['platform_results']['meituan']['promotion']['reason']
        );
    }

    public function testMissingMeituanSourceTaskDoesNotBlockStrictCtripPromotion(): void
    {
        $verifyCalls = [];
        $promoteCalls = [];
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            function (int $hotelId, string $date, array $platforms, string $anchor) use (&$verifyCalls): array {
                $verifyCalls[] = $platforms;
                return $this->verifier($platforms, $anchor, $platforms === ['ctrip']);
            },
            function (
                array $collection,
                array $verifier,
                string $platform,
                int $tenantId,
                int $hotelId
            ) use (&$promoteCalls): array {
                $promoteCalls[] = $platform;
                return $this->promotion($platform);
            }
        );
        $collection = $this->collection(['ctrip', 'meituan']);
        $collection['source_tasks'] = array_values(array_filter(
            $collection['source_tasks'],
            static fn(array $task): bool => $task['platform'] === 'ctrip'
        ));
        $collection = $this->reanchor($collection);

        $result = $coordinator->finalize($collection, 80, 80);

        self::assertSame('partial', $result['status']);
        self::assertFalse($result['canonical_history_complete']);
        self::assertSame(['ctrip'], $result['promoted_platforms']);
        self::assertSame(['meituan'], $result['blocked_platforms']);
        self::assertSame([['ctrip']], $verifyCalls);
        self::assertSame(['ctrip'], $promoteCalls);
        self::assertSame([], $result['platform_results']['meituan']['verifier']);
        self::assertSame(
            'canonical_history_platform_source_task_missing',
            $result['platform_results']['meituan']['promotion']['reason']
        );
    }

    public function testMissingCtripSourceTaskDoesNotBlockStrictMeituanPromotion(): void
    {
        $verifyCalls = [];
        $promoteCalls = [];
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            function (int $hotelId, string $date, array $platforms, string $anchor) use (&$verifyCalls): array {
                $verifyCalls[] = $platforms;
                return $this->verifier($platforms, $anchor, $platforms === ['meituan']);
            },
            function (
                array $collection,
                array $verifier,
                string $platform,
                int $tenantId,
                int $hotelId
            ) use (&$promoteCalls): array {
                $promoteCalls[] = [$platform, $tenantId, $hotelId, $verifier['required_platforms'] ?? []];
                return $this->promotion($platform);
            }
        );
        $collection = $this->collection(['ctrip', 'meituan']);
        $collection['source_tasks'] = array_values(array_filter(
            $collection['source_tasks'],
            static fn(array $task): bool => $task['platform'] === 'meituan'
        ));
        $collection = $this->reanchor($collection);

        $result = $coordinator->finalize($collection, 80, 80);

        self::assertSame('partial', $result['status']);
        self::assertFalse($result['canonical_history_complete']);
        self::assertSame(['meituan'], $result['promoted_platforms']);
        self::assertSame(['ctrip'], $result['blocked_platforms']);
        self::assertSame([['meituan']], $verifyCalls);
        self::assertSame([['meituan', 80, 80, ['meituan']]], $promoteCalls);
        self::assertSame([], $result['platform_results']['ctrip']['verifier']);
        self::assertSame(
            'canonical_history_platform_source_task_missing',
            $result['platform_results']['ctrip']['promotion']['reason']
        );
        self::assertSame('verified', $result['platform_results']['meituan']['status']);
    }

    public function testMalformedCollectionScopeFailsWithoutRunningCallbacks(): void
    {
        $called = false;
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            function () use (&$called): array {
                $called = true;
                return [];
            },
            function () use (&$called): array {
                $called = true;
                return [];
            }
        );
        $collection = $this->collection(['ctrip']);
        $collection['collection_anchor_hash'] = 'invalid';

        $result = $coordinator->finalize($collection, 80, 80);

        self::assertSame('blocked', $result['status']);
        self::assertSame('canonical_history_finalization_scope_invalid', $result['reason']);
        self::assertFalse($called);
    }

    public function testCoordinatorRejectsTrafficOnlyAnchorWithoutExactTaskCoreClosure(): void
    {
        $verifyCalled = false;
        $promoteCalled = false;
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            function () use (&$verifyCalled): array {
                $verifyCalled = true;
                return [];
            },
            function () use (&$promoteCalled): array {
                $promoteCalled = true;
                return [];
            }
        );
        $collection = $this->collection(['ctrip']);
        $collection['data_period'] = 'historical_daily';
        $collection['source_tasks'][0]['historical_core_contract_status'] = 'blocked';
        $collection = $this->reanchor($collection);

        $result = $coordinator->finalize($collection, 80, 80);

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['canonical_history_complete']);
        self::assertSame(['ctrip'], $result['blocked_platforms']);
        self::assertSame(
            'canonical_history_platform_core_contract_missing',
            $result['platform_results']['ctrip']['promotion']['reason']
        );
        self::assertFalse($verifyCalled);
        self::assertFalse($promoteCalled);
    }

    public function testCoordinatorRejectsCoreStatusTamperBeforeCallingVerifier(): void
    {
        $called = false;
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            function () use (&$called): array {
                $called = true;
                return [];
            },
            function () use (&$called): array {
                $called = true;
                return [];
            }
        );
        $collection = $this->collection(['ctrip']);
        $collection['data_period'] = 'historical_daily';
        $collection['source_tasks'][0]['historical_core_contract_status'] = 'blocked';
        $collection = $this->reanchor($collection);
        $collection['source_tasks'][0]['historical_core_contract_status'] = 'ready';

        $result = $coordinator->finalize($collection, 80, 80);

        self::assertSame('blocked', $result['status']);
        self::assertSame('canonical_history_finalization_scope_invalid', $result['reason']);
        self::assertFalse($called);
    }

    public function testPromotionReadbackFailureKeepsFinalizationBlocked(): void
    {
        $coordinator = new OtaCanonicalHistoryPromotionCoordinator(
            fn(int $hotelId, string $date, array $platforms, string $anchor): array =>
                $this->verifier($platforms, $anchor),
            static fn(array $collection, array $verifier, string $platform, int $tenantId, int $hotelId): array => [
                'status' => 'verified',
                'readback_verified' => false,
                'system_hotel_id' => 80,
                'platform' => 'ctrip',
                'target_date' => '2026-08-09',
                'sensitive_values_exposed' => false,
            ]
        );

        $result = $coordinator->finalize($this->collection(['ctrip']), 80, 80);

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['canonical_history_complete']);
        self::assertSame(['ctrip'], $result['blocked_platforms']);
    }

    /** @param array<int,string> $platforms @return array<string,mixed> */
    private function collection(array $platforms): array
    {
        sort($platforms, SORT_STRING);
        $tasks = [];
        foreach ($platforms as $index => $platform) {
            $tasks[] = [
                'data_source_id' => $platform === 'ctrip' ? 25 : 68,
                'sync_task_id' => 3001 + $index,
                'platform' => $platform,
                'collection_status' => 'success',
                'p0_status' => 'ready',
                'historical_core_contract_status' => 'not_required',
                'row_ids' => [501 + $index],
            ];
        }
        return [
            'hotel_id' => 80,
            'target_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'required_platforms' => $platforms,
            'source_tasks' => $tasks,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => OtaCollectionAnchorService::hash($tasks),
        ];
    }

    /** @param array<string,mixed> $collection @return array<string,mixed> */
    private function reanchor(array $collection): array
    {
        $collection['collection_anchor_contract_version'] = OtaCollectionAnchorService::CONTRACT_VERSION;
        $collection['collection_anchor_hash'] = OtaCollectionAnchorService::hash(
            $collection['source_tasks'] ?? []
        );
        return $collection;
    }

    /** @param array<int,string> $platforms @return array<string,mixed> */
    private function verifier(array $platforms, string $anchor, bool $ready = true): array
    {
        sort($platforms, SORT_STRING);
        return [
            'verification_source' => 'external_p0_verifier',
            'status' => $ready ? 'passed' : 'incomplete',
            'exit_code' => $ready ? 0 : 2,
            'authority_ready' => $ready,
            'hotel_id' => 80,
            'target_date' => '2026-08-09',
            'required_platforms' => $platforms,
            'verified_platforms' => $ready ? $platforms : [],
            'collection_anchor_hash' => $anchor,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function promotion(string $platform): array
    {
        return [
            'status' => 'verified',
            'readback_verified' => true,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => $platform,
            'target_date' => '2026-08-09',
            'sensitive_values_exposed' => false,
        ];
    }
}
