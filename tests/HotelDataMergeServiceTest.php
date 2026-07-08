<?php
declare(strict_types=1);

use app\service\HotelDataMergeService;
use PHPUnit\Framework\TestCase;

final class HotelDataMergeServiceTest extends TestCase
{
    public function testMigrationPlansMoveSystemHotelScopeButKeepOtaHotelId(): void
    {
        $plans = (new HotelDataMergeService())->migrationPlans();

        $hasOnlineSystemHotelPlan = false;
        foreach ($plans as $plan) {
            if ($plan['table'] === 'online_daily_data' && $plan['column'] === 'system_hotel_id') {
                $hasOnlineSystemHotelPlan = true;
                break;
            }
        }

        $this->assertTrue($hasOnlineSystemHotelPlan, 'online_daily_data must be migrated by system_hotel_id.');

        foreach ($plans as $plan) {
            $this->assertFalse(
                $plan['table'] === 'online_daily_data' && $plan['column'] === 'hotel_id',
                'online_daily_data.hotel_id is an OTA platform hotel id and must not be migrated.'
            );
        }
    }

    public function testConfirmationTextIsStableAndExplicit(): void
    {
        $this->assertSame('MERGE 75 -> 118', (new HotelDataMergeService())->confirmationText(75, 118));
    }

    public function testExecuteSignatureRequiresConfirmationTextBeforeDeactivateFlag(): void
    {
        $method = new ReflectionMethod(HotelDataMergeService::class, 'execute');

        $this->assertSame(4, $method->getNumberOfParameters());
        $this->assertSame('sourceHotelId', $method->getParameters()[0]->getName());
        $this->assertSame('targetHotelId', $method->getParameters()[1]->getName());
        $this->assertSame('confirmationText', $method->getParameters()[2]->getName());
        $this->assertSame('deactivateSource', $method->getParameters()[3]->getName());
    }

    public function testDuplicateUserPermissionConflictsAreMergedBeforeSourceRemoval(): void
    {
        $source = file_get_contents(__DIR__ . '/../app/service/HotelDataMergeService.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('merge_then_remove_source_duplicate_permission', $source);
        $this->assertStringContainsString('duplicatePermissionMergeAssignments', $source);
        $this->assertStringContainsString('GREATEST(COALESCE(t.', $source);
        $this->assertStringContainsString("'merges_duplicate_user_permissions' => true", $source);
        $this->assertStringNotContainsString('skip_source_duplicate_permission', $source);
    }
}
