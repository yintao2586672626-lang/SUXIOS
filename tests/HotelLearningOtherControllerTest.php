<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/fixtures/hotel-learning/bootstrap.php';

final class HotelLearningOtherControllerTest extends TestCase
{
    private static string $path;
    private static array $config;

    public static function setUpBeforeClass(): void
    {
        self::$path = sys_get_temp_dir() . '/hotel-learning-other-controller-' . bin2hex(random_bytes(6)) . '.sqlite';
        self::$config = HotelLearningSyntheticEnvironment::connect(self::$path);
    }

    public static function tearDownAfterClass(): void
    {
        HotelLearningSyntheticEnvironment::$app->request->user = null;
        Db::connect()->close();
        Config::set(self::$config, 'database');
        Db::connect(null, true);
        if (is_file(self::$path)) unlink(self::$path);
    }

    private function request(string $mode): array
    {
        return HotelLearningSyntheticEnvironment::scope($mode) + [
            'inputs' => HotelLearningSyntheticEnvironment::inputs($mode),
            'idempotency_key' => 'synthetic-other-' . bin2hex(random_bytes(8)),
        ];
    }

    private function data(array $reply): array
    {
        self::assertSame(200, $reply['http_status']);
        self::assertSame(200, $reply['body']['code']);
        return $reply['body']['data'];
    }

    public static function missingSources(): array
    {
        return ['actual source' => ['actual'], 'plan source' => ['plan']];
    }

    #[DataProvider('missingSources')]
    public function testPartialReviewPreservesValuesWithoutSourceUnsupportedComparison(string $side): void
    {
        $payload = $this->request('operating_review');
        $payload['inputs'][$side]['source_ref'] = '';
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        $preview = $this->data(HotelLearningSyntheticEnvironment::dispatch('preview', $payload));
        self::assertSame('partial', $preview['result']['status']);
        self::assertTrue($preview['result']['scope_aligned']);
        self::assertFalse($preview['result']['comparison_ready'] ?? null);
        self::assertSame($side === 'actual' ? null : 0.7, $preview['result']['actual_cost_ratio']);
        foreach ($preview['result']['rows'] as $row) self::assertNull($row['difference']);
        $capturedRows = array_column($preview['result']['rows'], null, 'metric');
        self::assertEquals(100000, $capturedRows['revenue']['actual']);
        self::assertEquals(70000, $capturedRows['operating_cost']['actual']);

        $saved = $this->data(HotelLearningSyntheticEnvironment::dispatch('save', $payload));
        $read = $this->data(HotelLearningSyntheticEnvironment::dispatch('read', $payload, $saved['snapshot_id']));
        self::assertTrue($read['readback_verified']);
        self::assertSame($saved['content_digest'], $read['content_digest']);
        self::assertSame($saved['inputs'], $read['inputs']);
        self::assertFalse($read['result']['comparison_ready']);
        self::assertContains($side . '.source_ref', $read['result']['missing_items']);

        $replayed = $this->data(HotelLearningSyntheticEnvironment::dispatch('save', $payload));
        self::assertSame($saved['snapshot_id'], $replayed['snapshot_id']);
        self::assertSame($before + 1, Db::name('hotel_operating_evidence_snapshots')->count());

        $payload['inputs'][$side]['source_ref'] = 'synthetic-corrected-' . $side;
        $conflict = HotelLearningSyntheticEnvironment::dispatch('save', $payload);
        self::assertSame(409, $conflict['http_status']);
        self::assertSame($before + 1, Db::name('hotel_operating_evidence_snapshots')->count());
        $payload['idempotency_key'] .= '-corrected';
        $corrected = $this->data(HotelLearningSyntheticEnvironment::dispatch('save', $payload));
        self::assertSame('compared', $corrected['result']['status']);
        self::assertTrue($corrected['result']['comparison_ready']);
        self::assertEquals(0, $corrected['result']['rows'][0]['difference']);
        $correctedRead = $this->data(HotelLearningSyntheticEnvironment::dispatch('read', $payload, $corrected['snapshot_id']));
        self::assertSame('synthetic-corrected-' . $side, $correctedRead['inputs'][$side]['source_ref']);
        self::assertSame($corrected['content_digest'], $correctedRead['content_digest']);
        $oldRead = $this->data(HotelLearningSyntheticEnvironment::dispatch('read', $payload, $saved['snapshot_id']));
        self::assertSame('', $oldRead['inputs'][$side]['source_ref']);
        self::assertFalse($oldRead['result']['comparison_ready']);
        self::assertSame($before + 2, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public function testSmallNonzeroReviewDifferenceSurvivesSaveAndExactReadback(): void
    {
        $payload = $this->request('operating_review');
        $payload['inputs']['plan']['revenue'] = 0;
        $payload['inputs']['actual']['revenue'] = 1e-7;
        $payload['inputs']['plan']['operating_cost'] = 0;
        $payload['inputs']['actual']['operating_cost'] = 0;
        $payload['inputs']['plan']['project_net_cash'] = 0;
        $payload['inputs']['actual']['project_net_cash'] = -1e-7;
        $preview = $this->data(HotelLearningSyntheticEnvironment::dispatch('preview', $payload));
        $rows = array_column($preview['result']['rows'], null, 'metric');
        self::assertSame(1e-7, $rows['revenue']['difference']);
        self::assertSame(-1e-7, $rows['project_net_cash']['difference']);
        $saved = $this->data(HotelLearningSyntheticEnvironment::dispatch('save', $payload));
        $read = $this->data(HotelLearningSyntheticEnvironment::dispatch('read', $payload, $saved['snapshot_id']));
        $rows = array_column($read['result']['rows'], null, 'metric');
        self::assertSame(1e-7, $rows['revenue']['difference']);
        self::assertSame(-1e-7, $rows['project_net_cash']['difference']);
        self::assertEquals(0, $rows['operating_cost']['difference']);
        self::assertTrue($read['readback_verified']);
        self::assertSame($saved['content_digest'], $read['content_digest']);
    }

    public function testIncompleteMarketIdentifiesMissingHotelRatherThanKnownValue(): void
    {
        $payload = $this->request('market_sample');
        $payload['inputs']['comparison_attested'] = true;
        $payload['inputs']['weights'] = ['traffic' => 0, 'conversion' => 1, 'revenue' => 0];
        $payload['inputs']['hotels'][0]['conversion'] = 1;
        $payload['inputs']['hotels'][1]['conversion'] = null;
        $missingId = $payload['inputs']['hotels'][1]['platform_store_id'];
        $preview = $this->data(HotelLearningSyntheticEnvironment::dispatch('preview', $payload));
        self::assertSame([$missingId . '.conversion'], $preview['result']['missing_items']);
        self::assertSame(['conversion' => 'incomplete_metric_coverage'], $preview['result']['score_unavailable_reasons']);
        self::assertSame('partial', $preview['result']['status']);
        self::assertSame(1.0, $preview['result']['items'][0]['conversion']);
        foreach ($preview['result']['items'] as $item) self::assertNull($item['reference_score']);
        $saved = $this->data(HotelLearningSyntheticEnvironment::dispatch('save', $payload));
        $read = $this->data(HotelLearningSyntheticEnvironment::dispatch('read', $payload, $saved['snapshot_id']));
        self::assertTrue($read['readback_verified']);
        self::assertSame($saved['content_digest'], $read['content_digest']);
        self::assertSame([$missingId . '.conversion'], $read['result']['missing_items']);
        self::assertNull($read['inputs']['hotels'][1]['conversion']);

        $payload['inputs']['hotels'][1]['conversion'] = 2;
        $payload['idempotency_key'] .= '-corrected';
        $corrected = $this->data(HotelLearningSyntheticEnvironment::dispatch('save', $payload));
        self::assertSame('calculated_reference', $corrected['result']['status']);
        self::assertSame([], $corrected['result']['missing_items']);
        self::assertSame([], $corrected['result']['score_unavailable_reasons']);
        self::assertEquals(0, $corrected['result']['items'][0]['reference_score']);
        self::assertEquals(100, $corrected['result']['items'][1]['reference_score']);
    }

    public function testConstantMarketReportsBenchmarkLimitWithoutInventingMissingNumbers(): void
    {
        $payload = $this->request('market_sample');
        $payload['inputs']['comparison_attested'] = true;
        $payload['inputs']['weights'] = ['traffic' => 1, 'conversion' => 0, 'revenue' => 0];
        foreach ($payload['inputs']['hotels'] as &$hotel) $hotel['traffic'] = 100;
        unset($hotel);
        $saved = $this->data(HotelLearningSyntheticEnvironment::dispatch('save', $payload));
        self::assertSame([], $saved['result']['missing_items']);
        self::assertSame(['traffic' => 'no_metric_variation'], $saved['result']['score_unavailable_reasons']);
        self::assertSame('partial', $saved['result']['status']);
        foreach ($saved['result']['items'] as $item) self::assertNull($item['reference_score']);
        $read = $this->data(HotelLearningSyntheticEnvironment::dispatch('read', $payload, $saved['snapshot_id']));
        self::assertSame($saved['result'], $read['result']);
        self::assertSame($saved['content_digest'], $read['content_digest']);
        self::assertTrue($read['readback_verified']);
    }
}
