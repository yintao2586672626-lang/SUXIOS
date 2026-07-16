<?php
declare(strict_types=1);

namespace Tests;

use app\command\SyncCtripPublicHotelProfiles;
use PHPUnit\Framework\TestCase;
use think\console\Input;
use think\console\Output;

final class SyncCtripPublicHotelProfilesTest extends TestCase
{
    public function testExplicitInvalidHotelIdFailsBeforeDatabaseQueryOrCollection(): void
    {
        foreach (['abc', '0', '-1', '1.5', ''] as $invalidHotelId) {
            $command = new SyncCtripPublicHotelProfiles();
            $input = new Input(['--hotel-id=' . $invalidHotelId]);
            $input->setInteractive(false);
            $output = new Output('buffer');

            $exitCode = $command->run($input, $output);

            self::assertSame(1, $exitCode, 'hotel-id=' . $invalidHotelId);
            self::assertStringContainsString(
                'hotel-id must be a positive integer.',
                $output->fetch(),
                'hotel-id=' . $invalidHotelId
            );
        }
    }
}
