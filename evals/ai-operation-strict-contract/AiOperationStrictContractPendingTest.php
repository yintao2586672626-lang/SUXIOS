<?php
declare(strict_types=1);

namespace Tests\Pending;

use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Default regression guards for the strict AI -> operation contract.
 * The suite remains in evals/ for audit history and is included by phpunit.xml.
 */
final class AiOperationStrictContractPendingTest extends TestCase
{
    #[DataProvider('invalidPriceIntentProvider')]
    public function testInvalidPriceIntentIsRejectedBeforeItCanBePersisted(
        string $expectedField,
        array $input,
        array $hotelIds = [7],
        ?int $hotelId = 7
    ): void {
        $service = new OperationManagementService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedField);

        // createExecutionIntent() calls this builder before insertGetId().
        // Throwing here is the required no-write boundary.
        $service->buildExecutionIntentPayload($hotelIds, $hotelId, $input, 3);
    }

    public function testValidPriceIntentAcceptsEffectiveDateAndBecomesPendingApproval(): void
    {
        $service = new OperationManagementService();
        $input = self::validPriceIntent();
        unset($input['date_start'], $input['date_end']);
        $input['effective_date'] = '2026-07-20';

        $payload = $service->buildExecutionIntentPayload([7], 7, $input, 3);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame(7, $payload['hotel_id']);
        self::assertSame('2026-07-20', $payload['date_start']);
        self::assertSame('2026-07-20', $payload['date_end']);
        self::assertSame('RT-1001', $payload['target_value']['room_type_key']);
        self::assertSame('BAR', $payload['target_value']['rate_plan_key']);
        self::assertSame(318.0, (float)$payload['target_value']['target_price']);
    }

    public function testValidPriceIntentCannotBypassApprovalByRequestingDraftStatus(): void
    {
        $service = new OperationManagementService();
        $input = self::validPriceIntent();
        $input['status'] = 'draft';

        $payload = $service->buildExecutionIntentPayload([7], 7, $input, 3);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
    }

    public function testNonPriceIntentMayStillRemainDraft(): void
    {
        $service = new OperationManagementService();
        $input = [
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'promotion',
            'status' => 'draft',
            'target_value' => ['campaign_type' => 'conversion_review', 'target_metric' => 'orders'],
            'evidence' => ['reason' => 'test-only campaign evidence'],
        ];

        $payload = $service->buildExecutionIntentPayload([7], 7, $input, 3);

        self::assertSame('draft', $payload['status']);
    }

    public static function invalidPriceIntentProvider(): array
    {
        $platformMissing = self::validPriceIntent();
        $platformMissing['platform'] = '';

        $roomTypeMissing = self::validPriceIntent();
        unset($roomTypeMissing['target_value']['room_type_key']);

        $ratePlanMissing = self::validPriceIntent();
        unset($ratePlanMissing['target_value']['rate_plan_key']);

        $targetPriceMissing = self::validPriceIntent();
        unset($targetPriceMissing['target_value']['target_price']);

        $targetPriceInvalid = self::validPriceIntent();
        $targetPriceInvalid['target_value']['target_price'] = 0;

        $effectiveDateMissing = self::validPriceIntent();
        unset($effectiveDateMissing['date_start'], $effectiveDateMissing['date_end']);

        $evidenceMissing = self::validPriceIntent();
        $evidenceMissing['evidence'] = ['reason' => '   '];

        $ambiguousHotel = self::validPriceIntent();
        unset($ambiguousHotel['hotel_id']);

        return [
            'platform is required' => ['platform', $platformMissing],
            'room type is required' => ['room_type_key', $roomTypeMissing],
            'rate plan is required' => ['rate_plan_key', $ratePlanMissing],
            'target price is required' => ['target_price', $targetPriceMissing],
            'target price must be positive' => ['target_price', $targetPriceInvalid],
            'effective date is required' => ['effective_date', $effectiveDateMissing],
            'non-empty evidence is required' => ['evidence', $evidenceMissing],
            'multiple permitted hotels need an explicit hotel' => ['hotel_id', $ambiguousHotel, [7, 8], null],
        ];
    }

    private static function validPriceIntent(): array
    {
        return [
            'source_module' => 'ai_daily_report',
            'source_record_id' => 15,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-07-20',
            'date_end' => '2026-07-20',
            'current_value' => ['current_price' => 280],
            'target_value' => [
                'room_type_key' => 'RT-1001',
                'rate_plan_key' => 'BAR',
                'target_price' => 318,
            ],
            'evidence' => [
                'reason' => 'verified OTA price opportunity',
                'source_refs' => ['daily_report#15'],
            ],
            'expected_metric' => 'ota_revenue',
            'expected_delta' => 8,
            'risk_level' => 'medium',
        ];
    }
}
