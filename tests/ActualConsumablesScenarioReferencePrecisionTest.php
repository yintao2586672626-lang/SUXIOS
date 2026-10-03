<?php
declare(strict_types=1);

use app\service\ActualConsumablesScenarioReferenceService;
use app\service\ConsumablesActualCostService;
use app\service\OperatingEvidenceSnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__ . '/fixtures/hotel-learning/bootstrap.php';

final class ActualConsumablesScenarioReferencePrecisionTest extends TestCase
{
    private static string $path;
    private static array $config;

    public static function setUpBeforeClass(): void
    {
        self::$path = sys_get_temp_dir() . '/hotel-learning-actual-reference-' . bin2hex(random_bytes(6)) . '.sqlite';
        self::$config = HotelLearningSyntheticEnvironment::connect(self::$path);
    }

    protected function setUp(): void
    {
        Db::execute('DELETE FROM hotel_operating_evidence_snapshots');
        Db::execute("DELETE FROM sqlite_sequence WHERE name = 'hotel_operating_evidence_snapshots'");
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$config, 'database');
        Db::connect(null, true);
        if (is_file(self::$path)) unlink(self::$path);
    }

    private function savedInput(float $unitPrice): array
    {
        $inputs = HotelLearningSyntheticEnvironment::inputs('consumables_reconciliation');
        $inputs['items'][0]['opening_quantity'] = 0;
        $inputs['items'][0]['purchased_quantity'] = 1;
        $inputs['items'][0]['closing_quantity'] = 0;
        $inputs['items'][0]['written_off_quantity'] = 0;
        // A real cent-denominated monthly amount can yield a micro per-night cost.
        // Derive that value through the actual calculator without rounding it in the fixture.
        $inputs['occupied_room_nights'] = $unitPrice === 4e-8 ? 1000000 : 1;
        $inputs['items'][0]['unit_price'] = $unitPrice === 4e-8 ? 0.04 : $unitPrice;
        $result = (new ConsumablesActualCostService())->calculate($inputs);
        self::assertSame('calculated', $result['status']);
        self::assertSame('operator_attested', $result['source_quality']);
        self::assertSame($unitPrice, (float)$result['actual_consumables_cost_per_room_night']);

        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(7, [80], 80, '2026-10', 'whole_hotel', 'consumables_actual');
        $saved = $store->save($scope, [
            'inputs' => $result['inputs'],
            'result' => $result,
            'status' => $result['status'],
            'source_quality' => $result['source_quality'],
        ], 'synthetic-actual-reference-' . bin2hex(random_bytes(6)), 7);
        $read = $store->read($scope, $saved['snapshot_id']);
        self::assertTrue($read['readback_verified']);
        self::assertSame($saved['content_digest'], $read['content_digest']);
        self::assertSame($unitPrice, (float)$read['result']['actual_consumables_cost_per_room_night']);
        self::assertSame('synthetic-pms', $read['result']['inputs']['occupied_room_nights_source_ref']);

        return [
            'cost_evidence_snapshot_id' => $read['snapshot_id'],
            'cost_evidence_digest' => $read['content_digest'],
            'cost_evidence_confirmed' => true,
            'consumables_cost' => ['mode' => 'derived', 'items' => [[
                'id' => 'actual-evidence-' . $read['snapshot_id'],
                'enabled' => true,
                'unit' => 'piece',
                'usage_basis' => 'occupied_room_night',
                'package_price' => $read['result']['actual_consumables_cost_per_room_night'],
                'package_quantity' => 1,
                'usage_quantity' => 1,
                'occurrences_per_occupied_night' => 1,
            ]]],
        ];
    }

    public static function changedPrices(): array
    {
        return [
            'nonzero micro cost cannot become zero' => [4e-8, 0],
            'nonzero micro cost cannot be halved' => [4e-8, 2e-8],
            'ordinary cost cannot change within old tolerance' => [2.0, 2.00000005],
        ];
    }

    #[DataProvider('changedPrices')]
    public function testAnyRepresentablePriceChangeRejectsTheSavedVersion(float $savedPrice, int|float $changedPrice): void
    {
        $input = $this->savedInput($savedPrice);
        $input['consumables_cost']['items'][0]['package_price'] = $changedPrice;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        (new ActualConsumablesScenarioReferenceService())->validate(7, 80, $input);
    }

    public static function unchangedPrices(): array
    {
        return [
            'micro float' => [4e-8, 4e-8],
            'micro decimal numeric string' => [4e-8, '0.0000000400000000'],
            'micro scientific numeric string' => [4e-8, '4e-8'],
            'ordinary numeric string' => [2.0, '2.00'],
            'true saved zero' => [0.0, 0],
            'true saved zero numeric string' => [0.0, '0.00'],
        ];
    }

    #[DataProvider('unchangedPrices')]
    public function testExactSavedPriceAndNumericStringsStillAdopt(float $savedPrice, int|float|string $submittedPrice): void
    {
        $input = $this->savedInput($savedPrice);
        $input['cost_evidence_snapshot_id'] = (string)$input['cost_evidence_snapshot_id'];
        $item = &$input['consumables_cost']['items'][0];
        $item['package_price'] = $submittedPrice;
        $item['package_quantity'] = '1.0';
        $item['usage_quantity'] = '1';
        $item['occurrences_per_occupied_night'] = '1e0';
        unset($item);

        $validated = (new ActualConsumablesScenarioReferenceService())->validate(7, 80, $input);
        self::assertSame($submittedPrice, $validated['consumables_cost']['items'][0]['package_price']);
        self::assertSame($savedPrice, (float)$validated['consumables_cost']['items'][0]['package_price']);
        self::assertSame('2026-10-02', $validated['consumables_cost']['items'][0]['as_of']);
        self::assertStringContainsString('#' . $input['cost_evidence_snapshot_id'], $validated['consumables_cost']['items'][0]['source_label']);
    }

    public static function invalidSnapshotIds(): array
    {
        return ['boolean must not select snapshot one' => [true], 'float is not a snapshot identifier' => [1.0]];
    }

    #[DataProvider('invalidSnapshotIds')]
    public function testWrongSnapshotIdentifierTypeCannotSelectSavedVersion(mixed $id): void
    {
        $input = $this->savedInput(2.0);
        self::assertSame(1, $input['cost_evidence_snapshot_id']);
        $input['cost_evidence_snapshot_id'] = $id;
        $this->expectException(InvalidArgumentException::class);
        (new ActualConsumablesScenarioReferenceService())->validate(7, 80, $input);
    }

    public static function invalidCostShapes(): array
    {
        return [
            'cost must be an object-shaped array' => ['cost_string'],
            'items must be an array' => ['items_string'],
            'item scalar must not be silently discarded' => ['scalar_row'],
        ];
    }

    #[DataProvider('invalidCostShapes')]
    public function testMalformedCostShapeIsAnInputError(string $shape): void
    {
        $input = $this->savedInput(2.0);
        if ($shape === 'cost_string') $input['consumables_cost'] = 'derived';
        if ($shape === 'items_string') $input['consumables_cost']['items'] = 'invalid';
        if ($shape === 'scalar_row') $input['consumables_cost']['items'][] = 'invalid';
        $this->expectException(InvalidArgumentException::class);
        (new ActualConsumablesScenarioReferenceService())->validate(7, 80, $input);
    }

    public function testInputWithoutAnActualCostReferenceIsUnchanged(): void
    {
        $input = ['consumables_cost' => ['mode' => 'manual', 'manual_per_occupied_night' => 3]];
        self::assertSame($input, (new ActualConsumablesScenarioReferenceService())->validate(7, 80, $input));
    }
}
