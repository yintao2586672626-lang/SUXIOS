<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationActionLifecycleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevenueCockpitSourceIdentityTest extends TestCase
{
    public function testChangedVerifiedBaselineGetsItsOwnActionIdentity(): void
    {
        $service = new OperationActionLifecycleService();
        $old = $this->card(str_repeat('a', 64));
        $new = $this->card(str_repeat('b', 64), [
            'metric_value' => 401,
            'decision_snapshot_id' => 2,
            'decision_snapshot_digest' => str_repeat('2', 64),
        ]);
        self::assertNotSame($service->actionIdentityDigest($old), $service->actionIdentityDigest($new));
        self::assertSame(400.0, $old['metric_contract']['baseline_window']['value']);
        self::assertSame(str_repeat('a', 64), $old['trace']['cockpit_identity_digest']);
        self::assertSame(1, $old['trace']['decision_snapshot_id']);
    }

    public function testSameFactSnapshotRefreshAndEntryStillReuseIdentity(): void
    {
        $service = new OperationActionLifecycleService();
        $first = $this->card(str_repeat('a', 64));
        $refreshed = $this->card(str_repeat('a', 64), [
            'source_record_id' => 902,
            'source_module' => 'operating_question',
            'action_title' => '另一入口复核同一事实',
            'decision_snapshot_id' => 2,
            'decision_snapshot_digest' => str_repeat('2', 64),
            'opportunity_digest' => str_repeat('3', 64),
        ], 8);
        self::assertSame($service->actionIdentityDigest($first), $service->actionIdentityDigest($refreshed));
        self::assertNotSame($first['content_digest'], $refreshed['content_digest']);
    }

    #[DataProvider('legacyDigestValues')]
    public function testMissingOrMalformedDigestDoesNotInventVerifiedFactIdentity(mixed $value): void
    {
        $service = new OperationActionLifecycleService();
        $legacy = $this->card('');
        $candidate = $legacy;
        $candidate['trace']['cockpit_identity_digest'] = $value;
        self::assertSame($service->actionIdentityDigest($legacy), $service->actionIdentityDigest($candidate));
    }

    public static function legacyDigestValues(): array
    {
        return ['missing' => [null], 'empty' => [''], 'unknown' => ['unverified'], 'short' => ['a'],
            'wrong hex' => [str_repeat('g', 64)], 'wrong type array' => [[]], 'wrong type number' => [123]];
    }

    #[DataProvider('scopeChanges')]
    public function testSameFactsNeverCrossScope(array $change): void
    {
        $service = new OperationActionLifecycleService();
        $first = $this->card(str_repeat('a', 64));
        $other = $this->card(str_repeat('a', 64), $change);
        self::assertNotSame($service->actionIdentityDigest($first), $service->actionIdentityDigest($other));
    }

    public static function scopeChanges(): array
    {
        return [
            'tenant' => [['tenant_id' => 11]],
            'hotel' => [['hotel_id' => 21]],
            'platform' => [['platform' => 'ctrip']],
            'business date' => [['business_date' => '2026-08-21']],
            'opportunity' => [['opportunity_key' => 'detail_conversion_shortage']],
        ];
    }

    private function card(string $digest, array $change = [], int $owner = 7): array
    {
        // Only a synthetic, previously attested observation is used here.
        $context = array_replace([
            'tenant_id' => 10, 'hotel_id' => 20, 'source_record_id' => 201,
            'source_module' => 'revenue_cockpit_action', 'platform' => 'meituan',
            'business_date' => '2026-08-20', 'metric_key' => 'detail_exposure',
            'metric_unit' => 'exposure_count', 'metric_value' => 400,
            'metric_rows' => [['ref' => 'online_daily_data#201', 'platform' => 'meituan',
                'business_date' => '2026-08-20', 'metric' => 'detail_exposure', 'value' => 400, 'unit' => 'exposure_count']],
            'fact_refs' => ['online_daily_data#201'], 'fact_snapshot_digest' => $digest,
            'opportunity_key' => 'traffic_entry_shortage', 'action_title' => '核对合成来源',
            'action_object' => 'revenue_cockpit_opportunity:traffic_entry_shortage',
            'action_description' => 'local_synthetic_fixture_only；仅生成待确认卡片，不执行。',
            'decision_snapshot_id' => 1, 'decision_snapshot_digest' => str_repeat('1', 64),
            'opportunity_digest' => str_repeat('4', 64),
        ], $change);
        return (new OperationActionLifecycleService())->buildRevenueCockpitObservationCard($context, $owner);
    }
}
